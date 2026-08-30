<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Traducción de las etiquetas de los menús de navegación (los "textos de las
 * opciones del menú"): el texto visible del ítem, su atributo `title` (tooltip)
 * y su descripción.
 *
 * Cubre lo que `MLS_Links::localize_menu_items()` no puede: enlaces
 * personalizados ("Custom Links"), etiquetas de navegación editadas a mano en
 * el menú, y cualquier ítem que apunte a contenido sin traducir.
 *
 * Almacenamiento propio (`mls_menu_translations`); el menú original NUNCA se
 * modifica. En el frontend traducido se sustituyen las propiedades del objeto
 * de menú al vuelo, DESPUÉS de `MLS_Links` (prioridad 20 > 10), de modo que una
 * traducción explícita de la etiqueta gana sobre la deducida del título del post.
 */
class MLS_Menus {

	public function __construct() {
		// Prioridad 20: corre después de MLS_Links::localize_menu_items (10).
		add_filter( 'wp_nav_menu_objects', array( $this, 'localize' ), 20 );

		// Traducción automática al editar un menú, si "auto_translate" está activo.
		add_action( 'wp_update_nav_menu', array( $this, 'on_menu_saved' ) );
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mls_menu_translations';
	}

	private function active() {
		return ! is_admin() && class_exists( 'MLS_Language_Context' ) && MLS_Language_Context::is_translation_request();
	}

	/**
	 * @param array $items
	 * @return array
	 */
	public function localize( $items ) {
		if ( ! $this->active() || empty( $items ) ) {
			return $items;
		}
		$lang = MLS_Language_Context::get_current_language();

		foreach ( $items as $item ) {
			if ( empty( $item->ID ) ) {
				continue;
			}
			$row = self::get_row( (int) $item->ID, $lang );
			if ( ! $row || ! in_array( (string) $row->status, MLS_DB::servable_statuses(), true ) ) {
				continue;
			}
			if ( '' !== (string) $row->title ) {
				$item->title = $row->title;
			}
			if ( '' !== (string) $row->attr_title ) {
				$item->attr_title = $row->attr_title;
			}
			if ( '' !== (string) $row->description ) {
				$item->description = $row->description;
			}
		}

		return $items;
	}

	/**
	 * @param int $menu_id
	 */
	public function on_menu_saved( $menu_id ) {
		$settings = mls_get_settings();
		if ( empty( $settings['auto_translate'] ) || empty( $settings['api_key'] ) ) {
			return;
		}
		foreach ( array_keys( MLS_Language_Registry::targets() ) as $lang ) {
			self::translate_menu( (int) $menu_id, $lang );
		}
	}

	/**
	 * Etiqueta visible + textos de un ítem de menú ya "montado".
	 *
	 * @param object $item
	 * @return array{title:string,attr_title:string,description:string}
	 */
	public static function item_strings( $item ) {
		return array(
			'title'       => isset( $item->title ) ? (string) $item->title : '',
			'attr_title'  => isset( $item->attr_title ) ? (string) $item->attr_title : '',
			'description' => isset( $item->description ) ? (string) $item->description : '',
		);
	}

	/**
	 * @param object $item
	 * @return string
	 */
	public static function source_hash( $item ) {
		$s = self::item_strings( $item );
		return md5( $s['title'] . '|' . $s['attr_title'] . '|' . $s['description'] );
	}

	/**
	 * Traduce automáticamente todas las etiquetas pendientes de un menú a un
	 * idioma. Los ítems marcados 'manual' no se tocan salvo $force.
	 *
	 * @param int    $menu_id
	 * @param string $lang
	 * @param bool   $force
	 * @return int Número de ítems traducidos.
	 */
	public static function translate_menu( $menu_id, $lang, $force = false ) {
		$items = wp_get_nav_menu_items( $menu_id );
		if ( empty( $items ) ) {
			return 0;
		}

		$source = MLS_Language_Registry::source();
		$count  = 0;

		foreach ( $items as $item ) {
			$existing = self::get_row( (int) $item->ID, $lang );
			if ( $existing && ! $force ) {
				// Manual: nunca se pisa automáticamente.
				if ( MLS_DB::STATUS_MANUAL === (string) $existing->status ) {
					continue;
				}
				// Automática y el original no cambió: nada que hacer.
				if ( (string) $existing->source_hash === self::source_hash( $item ) ) {
					continue;
				}
			}
			if ( self::translate_item( $item, $lang, $source['code'], $force ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * @param object $item
	 * @param string $lang
	 * @param string $source_code
	 * @param bool   $force
	 * @return bool
	 */
	private static function translate_item( $item, $lang, $source_code, $force = false ) {
		$src = self::item_strings( $item );

		$out = array( 'title' => '', 'attr_title' => '', 'description' => '' );
		foreach ( $out as $key => $_ ) {
			if ( '' === trim( $src[ $key ] ) ) {
				continue;
			}
			$t = MLS_Translator::translate_text( $src[ $key ], $source_code, $lang, 'text' );
			$out[ $key ] = is_wp_error( $t ) ? $src[ $key ] : $t;
		}

		if ( '' === $out['title'] && '' === $out['attr_title'] && '' === $out['description'] ) {
			return false;
		}

		return self::save( array(
			'menu_item_id' => (int) $item->ID,
			'language'     => $lang,
			'title'        => $out['title'],
			'attr_title'   => $out['attr_title'],
			'description'  => $out['description'],
			'status'       => $force ? MLS_DB::STATUS_MANUAL : MLS_DB::STATUS_PUBLISHED,
			'source_hash'  => self::source_hash( $item ),
		) );
	}

	/**
	 * @param array $data
	 * @return bool
	 */
	public static function save( array $data ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );

		$existing = self::get_row( $data['menu_item_id'], $data['language'] );
		$row      = array(
			'menu_item_id' => absint( $data['menu_item_id'] ),
			'language'     => sanitize_key( $data['language'] ),
			'title'        => sanitize_text_field( isset( $data['title'] ) ? $data['title'] : '' ),
			'attr_title'   => sanitize_text_field( isset( $data['attr_title'] ) ? $data['attr_title'] : '' ),
			'description'  => sanitize_textarea_field( isset( $data['description'] ) ? $data['description'] : '' ),
			'status'       => sanitize_key( isset( $data['status'] ) ? $data['status'] : MLS_DB::STATUS_MANUAL ),
			'source_hash'  => sanitize_text_field( isset( $data['source_hash'] ) ? $data['source_hash'] : '' ),
			'updated_at'   => $now,
		);

		if ( $existing ) {
			return false !== $wpdb->update( $table, $row, array( 'id' => $existing->id ) );
		}
		$row['created_at'] = $now;
		return false !== $wpdb->insert( $table, $row );
	}

	/**
	 * @param int    $menu_item_id
	 * @param string $lang
	 * @return object|null
	 */
	public static function get_row( $menu_item_id, $lang ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE menu_item_id = %d AND language = %s LIMIT 1",
				absint( $menu_item_id ),
				sanitize_key( $lang )
			)
		);
	}

	/**
	 * Limpia las traducciones de un ítem borrado del menú.
	 *
	 * @param int $menu_item_id
	 */
	public static function delete_for_item( $menu_item_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'menu_item_id' => absint( $menu_item_id ) ) );
	}
}
