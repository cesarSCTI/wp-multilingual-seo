<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Traducción de campos personalizados (postmeta) declarados con
 * `mls_register_translatable_field()`.
 *
 * Almacenamiento propio: tabla `mls_meta_translations`. El postmeta
 * original NUNCA se modifica.
 *
 * En el frontend traducido se intercepta `get_post_metadata` SOLO para las
 * claves registradas (nunca de forma global), de modo que no interfiere con
 * Elementor, ACF ni el ciclo interno de WordPress.
 */
class MLS_Fields {

	public function __construct() {
		add_filter( 'get_post_metadata', array( $this, 'filter_meta' ), 10, 4 );
		add_action( 'mls_translation_saved', array( $this, 'on_translation_saved' ), 10, 3 );
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mls_meta_translations';
	}

	/**
	 * @param mixed  $value
	 * @param int    $object_id
	 * @param string $meta_key
	 * @param bool   $single
	 * @return mixed
	 */
	public function filter_meta( $value, $object_id, $meta_key, $single ) {
		if ( '' === (string) $meta_key || is_admin() || MLS_Language_Context::is_source_request() ) {
			return $value;
		}
		if ( ! MLS_Registry::field( $meta_key, get_post_type( $object_id ) ) ) {
			return $value;
		}

		$lang = MLS_Language_Context::get_current_language();
		$row  = self::get_row( $object_id, $lang, $meta_key );
		if ( ! $row || ! in_array( (string) $row->status, MLS_DB::servable_statuses(), true ) ) {
			return $value;
		}

		// `get_post_metadata` espera: null = "sigue tú"; cualquier otra cosa
		// se devuelve tal cual. Para $single, WP toma [0]; para no-single,
		// espera un array de valores.
		return $single ? $row->meta_value : array( $row->meta_value );
	}

	/**
	 * Se dispara tras guardar la traducción principal de un post: traduce
	 * también sus campos registrados al mismo idioma.
	 *
	 * @param int    $post_id
	 * @param string $lang
	 * @param string $status
	 */
	public function on_translation_saved( $post_id, $lang, $status ) {
		if ( ! in_array( $status, array( MLS_DB::STATUS_PUBLISHED, MLS_DB::STATUS_MANUAL ), true ) ) {
			return;
		}
		self::translate_for( $post_id, $lang );
	}

	/**
	 * Traduce (automáticamente) todos los campos registrados de un post a un
	 * idioma. Los que ya estén marcados como 'manual' no se tocan.
	 *
	 * @param int    $post_id
	 * @param string $lang
	 * @return void
	 */
	public static function translate_for( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( $lang );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$fields = MLS_Registry::fields_for_post_type( $post->post_type );
		if ( empty( $fields ) ) {
			return;
		}

		$source = MLS_Language_Registry::source();

		foreach ( $fields as $key => $def ) {
			$existing = self::get_row( $post_id, $lang, $key );
			if ( $existing && MLS_DB::STATUS_MANUAL === (string) $existing->status ) {
				continue;
			}

			$raw = get_post_meta( $post_id, $key, true );
			if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
				continue;
			}

			$translated = MLS_Translator::translate_text( $raw, $source['code'], $lang, $def['format'] );
			if ( is_wp_error( $translated ) ) {
				mls_debug_log( 'Campo "' . $key . '" post=' . $post_id . ' lang=' . $lang . ' no traducido: ' . $translated->get_error_message() );
				continue;
			}

			self::save( $post_id, $lang, $key, $translated, MLS_DB::STATUS_PUBLISHED, md5( $raw ) );
		}
	}

	/**
	 * @param int    $post_id
	 * @param string $lang
	 * @param string $meta_key
	 * @param string $value
	 * @param string $status
	 * @param string $source_hash
	 * @return bool
	 */
	public static function save( $post_id, $lang, $meta_key, $value, $status = 'published', $source_hash = '' ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );

		$existing = self::get_row( $post_id, $lang, $meta_key );
		$data     = array(
			'post_id'     => absint( $post_id ),
			'language'    => sanitize_key( $lang ),
			'meta_key'    => (string) $meta_key,
			'meta_value'  => (string) $value,
			'status'      => sanitize_key( $status ),
			'source_hash' => sanitize_text_field( $source_hash ),
			'updated_at'  => $now,
		);

		if ( $existing ) {
			return false !== $wpdb->update( $table, $data, array( 'id' => $existing->id ) );
		}
		$data['created_at'] = $now;
		return false !== $wpdb->insert( $table, $data );
	}

	/**
	 * @param int    $post_id
	 * @param string $lang
	 * @param string $meta_key
	 * @return object|null
	 */
	public static function get_row( $post_id, $lang, $meta_key ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d AND language = %s AND meta_key = %s LIMIT 1",
				absint( $post_id ),
				sanitize_key( $lang ),
				(string) $meta_key
			)
		);
	}

	/**
	 * @param int $post_id
	 */
	public static function delete_for_post( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => absint( $post_id ) ) );
	}
}
