<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Traducción de términos (categorías, etiquetas, taxonomías personalizadas):
 * nombre y descripción.
 *
 * Almacenamiento propio (`mls_term_translations`); el término original nunca
 * se modifica. En el frontend traducido se sustituyen `name` y `description`
 * al vuelo mediante los filtros del core.
 *
 * NOTA: los ARCHIVOS de término traducidos (`/en/category/...`) todavía no
 * están enrutados; esto cubre la aparición de los nombres de término en
 * listados, breadcrumbs, widgets y metadatos.
 */
class MLS_Terms {

	public function __construct() {
		add_filter( 'get_term', array( $this, 'filter_term' ), 10, 2 );
		add_filter( 'get_terms', array( $this, 'filter_terms' ), 10, 2 );

		// Traducción automática al crear/editar un término (si auto_translate).
		add_action( 'created_term', array( $this, 'on_term_saved' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_term_saved' ), 10, 3 );
		add_action( 'pre_delete_term', array( $this, 'on_term_deleted' ), 10, 2 );
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mls_term_translations';
	}

	private function active() {
		return ! is_admin() && MLS_Language_Context::is_translation_request();
	}

	/**
	 * @param WP_Term|mixed $term
	 * @param string        $taxonomy
	 * @return mixed
	 */
	public function filter_term( $term, $taxonomy ) {
		if ( ! $this->active() || ! ( $term instanceof WP_Term ) ) {
			return $term;
		}
		$row = self::get_row( $term->term_id, MLS_Language_Context::get_current_language() );
		if ( ! $row || ! in_array( (string) $row->status, MLS_DB::servable_statuses(), true ) ) {
			return $term;
		}
		if ( '' !== (string) $row->name ) {
			$term->name = $row->name;
		}
		if ( null !== $row->description && '' !== (string) $row->description ) {
			$term->description = $row->description;
		}
		return $term;
	}

	/**
	 * @param array $terms
	 * @param array $taxonomies
	 * @return array
	 */
	public function filter_terms( $terms, $taxonomies ) {
		if ( ! $this->active() || empty( $terms ) ) {
			return $terms;
		}
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term ) {
				$this->filter_term( $term, $term->taxonomy );
			}
		}
		return $terms;
	}

	/**
	 * @param int    $term_id
	 * @param int    $tt_id
	 * @param string $taxonomy
	 */
	/**
	 * Taxonomías que el plugin traduce (configurable en Ajustes).
	 *
	 * @return string[]
	 */
	public static function enabled_taxonomies() {
		$settings = mls_get_settings();
		$list     = isset( $settings['taxonomies'] ) ? (array) $settings['taxonomies'] : array( 'category', 'post_tag' );
		return array_values( array_filter( array_map( 'sanitize_key', $list ) ) );
	}

	public function on_term_saved( $term_id, $tt_id, $taxonomy ) {
		$settings = mls_get_settings();
		if ( empty( $settings['auto_translate'] ) || empty( $settings['api_key'] ) ) {
			return;
		}
		if ( ! in_array( $taxonomy, self::enabled_taxonomies(), true ) ) {
			return;
		}
		foreach ( array_keys( MLS_Language_Registry::targets() ) as $lang ) {
			self::translate_term( $term_id, $taxonomy, $lang );
		}
	}

	/**
	 * @param int    $term_id
	 * @param string $taxonomy
	 */
	public function on_term_deleted( $term_id, $taxonomy ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'term_id' => absint( $term_id ) ) );
	}

	/**
	 * Traduce un término a un idioma y guarda el resultado.
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @param string $lang
	 * @param bool   $force
	 * @return true|WP_Error
	 */
	public static function translate_term( $term_id, $taxonomy, $lang, $force = false ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'mls_no_term', __( 'El término no existe.', 'mls' ) );
		}

		$existing = self::get_row( $term_id, $lang );
		if ( $existing && MLS_DB::STATUS_MANUAL === (string) $existing->status && ! $force ) {
			return true;
		}

		$source = MLS_Language_Registry::source();

		$name = MLS_Translator::translate_text( $term->name, $source['code'], $lang, 'text' );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		$desc = '';
		if ( '' !== (string) $term->description ) {
			$d    = MLS_Translator::translate_text( $term->description, $source['code'], $lang, 'html' );
			$desc = is_wp_error( $d ) ? '' : $d;
		}

		return self::save( array(
			'term_id'     => $term_id,
			'taxonomy'    => $taxonomy,
			'language'    => $lang,
			'name'        => $name,
			'description' => $desc,
			'slug'        => sanitize_title( $name ),
			'status'      => $force ? MLS_DB::STATUS_MANUAL : MLS_DB::STATUS_PUBLISHED,
			'source_hash' => md5( $term->name . '|' . $term->description ),
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

		$existing = self::get_row( $data['term_id'], $data['language'] );
		$row      = array(
			'term_id'     => absint( $data['term_id'] ),
			'taxonomy'    => substr( sanitize_key( $data['taxonomy'] ), 0, 32 ),
			'language'    => sanitize_key( $data['language'] ),
			'name'        => sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' ),
			'description' => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
			'slug'        => sanitize_title( isset( $data['slug'] ) ? $data['slug'] : '' ),
			'status'      => sanitize_key( isset( $data['status'] ) ? $data['status'] : MLS_DB::STATUS_PUBLISHED ),
			'source_hash' => sanitize_text_field( isset( $data['source_hash'] ) ? $data['source_hash'] : '' ),
			'updated_at'  => $now,
		);

		if ( $existing ) {
			return false !== $wpdb->update( $table, $row, array( 'id' => $existing->id ) );
		}
		$row['created_at'] = $now;
		return false !== $wpdb->insert( $table, $row );
	}

	/**
	 * @param int    $term_id
	 * @param string $lang
	 * @return object|null
	 */
	public static function get_row( $term_id, $lang ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE term_id = %d AND language = %s LIMIT 1",
				absint( $term_id ),
				sanitize_key( $lang )
			)
		);
	}

	/**
	 * Busca una traducción de término por su slug traducido.
	 *
	 * @param string $slug
	 * @param string $taxonomy
	 * @param string $lang
	 * @return object|null
	 */
	public static function get_row_by_translated_slug( $slug, $taxonomy, $lang ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE slug = %s AND taxonomy = %s AND language = %s LIMIT 1",
				sanitize_title( $slug ),
				substr( sanitize_key( $taxonomy ), 0, 32 ),
				sanitize_key( $lang )
			)
		);
	}
}
