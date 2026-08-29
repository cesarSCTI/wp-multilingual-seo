<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capa de acceso a datos para la tabla de traducciones.
 */
class MLS_DB {

	/**
	 * Caché en memoria por petición: evita repetir la misma consulta
	 * SQL decenas de veces cuando algo (como Elementor) pide la misma
	 * traducción varias veces durante el mismo render.
	 *
	 * @var array<string, object|false>
	 */
	private static $request_cache = array();

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . MLS_TABLE_TRANSLATIONS;
	}

	/**
	 * Obtiene la traducción de un post en un idioma concreto.
	 * Resuelve una sola vez por post_id+idioma en toda la petición.
	 *
	 * @param int    $post_id
	 * @param string $lang
	 * @return object|null
	 */
	public static function get_translation( $post_id, $lang ) {
		$cache_key = $post_id . '|' . $lang;
		if ( array_key_exists( $cache_key, self::$request_cache ) ) {
			return self::$request_cache[ $cache_key ] ?: null;
		}

		global $wpdb;
		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d AND language = %s LIMIT 1",
				$post_id,
				$lang
			)
		);

		self::$request_cache[ $cache_key ] = $row ? $row : false;
		return $row;
	}

	/**
	 * Limpia la caché en memoria de un post+idioma concreto (o todo, si
	 * no se especifica) — se usa después de guardar/actualizar para que
	 * el resto de la misma petición vea el dato fresco.
	 *
	 * @param int|null    $post_id
	 * @param string|null $lang
	 */
	public static function clear_cache( $post_id = null, $lang = null ) {
		if ( null === $post_id ) {
			self::$request_cache = array();
			return;
		}
		if ( null === $lang ) {
			foreach ( array_keys( self::$request_cache ) as $key ) {
				if ( 0 === strpos( $key, $post_id . '|' ) ) {
					unset( self::$request_cache[ $key ] );
				}
			}
			return;
		}
		unset( self::$request_cache[ $post_id . '|' . $lang ] );
	}

	/**
	 * Devuelve todas las traducciones de un post (una fila por idioma).
	 *
	 * @param int $post_id
	 * @return array
	 */
	public static function get_translations_for_post( $post_id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id )
		);
	}

	/**
	 * Busca el post_id original a partir de un slug traducido + idioma.
	 *
	 * @param string $slug
	 * @param string $lang
	 * @return int|null
	 */
	public static function get_post_id_by_translated_slug( $slug, $lang ) {
		global $wpdb;
		$table = self::table();

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$table} WHERE post_slug = %s AND language = %s LIMIT 1",
				$slug,
				$lang
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * Inserta o actualiza (upsert) una traducción.
	 *
	 * @param array $data Debe incluir post_id y language.
	 * @return int|false ID de la fila afectada.
	 */
	public static function save_translation( array $data ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );

		$existing = self::get_translation( $data['post_id'], $data['language'] );

		$row = array(
			'post_id'            => (int) $data['post_id'],
			'language'           => sanitize_key( $data['language'] ),
			'post_title'         => isset( $data['post_title'] ) ? wp_kses_post( $data['post_title'] ) : '',
			'post_content'       => isset( $data['post_content'] ) ? wp_kses_post( $data['post_content'] ) : '',
			'content_blocks'     => isset( $data['content_blocks'] ) ? $data['content_blocks'] : '',
			'post_excerpt'       => isset( $data['post_excerpt'] ) ? wp_kses_post( $data['post_excerpt'] ) : '',
			'post_slug'          => isset( $data['post_slug'] ) ? sanitize_title( $data['post_slug'] ) : '',
			'meta_title'         => isset( $data['meta_title'] ) ? sanitize_text_field( $data['meta_title'] ) : '',
			'meta_description'   => isset( $data['meta_description'] ) ? sanitize_text_field( $data['meta_description'] ) : '',
			'status'             => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'auto',
			'builder'            => isset( $data['builder'] ) ? sanitize_key( $data['builder'] ) : 'classic',
			'builder_data'       => isset( $data['builder_data'] ) ? $data['builder_data'] : '',
			'translation_units'  => isset( $data['translation_units'] ) ? $data['translation_units'] : '',
			'source_hash'        => isset( $data['source_hash'] ) ? sanitize_text_field( $data['source_hash'] ) : '',
			'error_message'      => isset( $data['error_message'] ) ? sanitize_text_field( $data['error_message'] ) : '',
			'updated_at'         => $now,
		);

		self::clear_cache( $data['post_id'], $data['language'] );

		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => $existing->id ) );
			$saved_id = (int) $existing->id;
		} else {
			$row['created_at'] = $now;
			$wpdb->insert( $table, $row );
			$saved_id = (int) $wpdb->insert_id;
		}

		// La traducción y el source comparten post/element IDs. Invalidamos
		// solo al guardar, nunca en cada request, para impedir HTML obsoleto.
		self::clear_cache( $data['post_id'], $data['language'] );
		if ( function_exists( 'mls_purge_translation_caches' ) ) {
			mls_purge_translation_caches( $data['post_id'], $data['language'] );
		}

		return $saved_id;
	}

	/**
	 * Calcula un hash del estado actual del contenido original (título,
	 * cuerpo, extracto y, si aplica, datos de Elementor). Se usa para
	 * detectar cuándo una traducción quedó "desactualizada" porque el
	 * original cambió después de traducirse.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public static function compute_source_hash( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$parts = array( $post->post_title, $post->post_content, $post->post_excerpt );

		if ( class_exists( 'MLS_Elementor_Adapter' ) && MLS_Elementor_Adapter::is_elementor_post( $post_id ) ) {
			$parts[] = get_post_meta( $post_id, '_elementor_data', true );
		}

		return md5( implode( '|', $parts ) );
	}

	/**
	 * ¿La traducción quedó desatrasada respecto al contenido original?
	 * No sobrescribe nada: solo informa, para que el admin decida.
	 *
	 * @param object $translation
	 * @return bool
	 */
	public static function is_outdated( $translation ) {
		if ( ! $translation || empty( $translation->source_hash ) ) {
			return false; // Traducciones antiguas sin hash guardado: no se marcan como desactualizadas.
		}
		$current_hash = self::compute_source_hash( $translation->post_id );
		return $current_hash !== $translation->source_hash;
	}

	/**
	 * Genera un slug único (dentro de un idioma) a partir de un slug base.
	 *
	 * @param string $slug
	 * @param string $lang
	 * @param int    $post_id Post que ya puede tener este slug reservado (se ignora en el chequeo).
	 * @return string
	 */
	public static function generate_unique_slug( $slug, $lang, $post_id ) {
		global $wpdb;
		$table    = self::table();
		$base     = sanitize_title( $slug );
		$new_slug = $base;
		$suffix   = 2;

		while ( true ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$table} WHERE post_slug = %s AND language = %s AND post_id != %d LIMIT 1",
					$new_slug,
					$lang,
					$post_id
				)
			);
			if ( ! $exists ) {
				break;
			}
			$new_slug = $base . '-' . $suffix;
			$suffix++;
		}

		return $new_slug;
	}

	/**
	 * Devuelve los bloques editables de una traducción. Si aún no tiene
	 * bloques guardados (traducciones creadas antes de esta función),
	 * los calcula al vuelo a partir del HTML guardado.
	 *
	 * @param object|null $translation
	 * @return array
	 */
	public static function get_blocks_for_translation( $translation ) {
		if ( $translation && ! empty( $translation->content_blocks ) ) {
			$decoded = json_decode( $translation->content_blocks, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		$html = $translation ? $translation->post_content : '';
		return MLS_Content_Blocks::parse_html_to_blocks( $html );
	}

	/**
	 * Elimina todas las traducciones de un post (por ejemplo, al borrarlo).
	 *
	 * @param int $post_id
	 */
	public static function delete_translations_for_post( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => $post_id ) );
	}

	/**
	 * Lista todas las URLs traducidas de un idioma (usado por el sitemap).
	 *
	 * @param string $lang
	 * @return array
	 */
	public static function get_all_slugs_for_lang( $lang ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, post_slug, updated_at FROM {$table} WHERE language = %s",
				$lang
			)
		);
	}
}
