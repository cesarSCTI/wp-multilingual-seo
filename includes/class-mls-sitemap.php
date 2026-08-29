<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integración con el sistema de sitemaps.
 *
 * 1. Proveedor NATIVO para `WP_Sitemaps` (WordPress 5.5+): añade las URLs
 *    traducidas al sitemap del core, con un subtipo por idioma y usando
 *    índices nativos. Es la vía preferida.
 *
 * 2. Sitemap XML propio `/{prefix}-sitemap-{lang}.xml` como respaldo para
 *    sitios donde `wp_sitemaps` está desactivado (lo gestiona MLS_SEO).
 *
 * Ambos listan SOLO traducciones indexables (published / manual, y outdated
 * si así se configura) cuyo post de origen esté publicado y sea público.
 */
class MLS_Sitemap {

	public function __construct() {
		add_action( 'init', array( $this, 'register_native_provider' ), 20 );
	}

	public function register_native_provider() {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) || ! class_exists( 'WP_Sitemaps_Provider' ) ) {
			return; // wp_sitemaps desactivado: se usa el XML propio de MLS_SEO.
		}
		if ( ! apply_filters( 'mls_use_native_sitemaps', true ) ) {
			return;
		}

		require_once MLS_PLUGIN_DIR . 'includes/class-mls-sitemap-provider.php';
		wp_sitemaps_add_provider( 'mls_translations', new MLS_Sitemap_Provider() );
	}

	/**
	 * Filtra las filas de un idioma dejando solo las realmente publicables,
	 * y devuelve [ ['loc'=>url, 'lastmod'=>iso] ].
	 *
	 * @param string   $lang
	 * @param int      $offset
	 * @param int|null $limit
	 * @return array
	 */
	public static function entries_for_lang( $lang, $offset = 0, $limit = null ) {
		$lang          = sanitize_key( $lang );
		$rows          = MLS_DB::get_all_slugs_for_lang( $lang );
		$front_page_id = ( 'page' === get_option( 'show_on_front' ) ) ? (int) get_option( 'page_on_front' ) : 0;
		$entries       = array();

		foreach ( $rows as $row ) {
			if ( ! MLS_DB::is_indexable( $row ) ) {
				continue;
			}
			$post = get_post( (int) $row->post_id );
			if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
				continue;
			}
			$type_obj = get_post_type_object( $post->post_type );
			if ( ! $type_obj || empty( $type_obj->public ) ) {
				continue;
			}

			if ( $front_page_id && (int) $row->post_id === $front_page_id ) {
				$loc = MLS_Url::home( $lang );
			} else {
				$path = ! empty( $row->translated_path ) ? $row->translated_path : $row->post_slug;
				$loc  = trailingslashit( home_url( '/' . $lang . '/' . ltrim( $path, '/' ) ) );
			}

			$entries[] = array(
				'loc'     => $loc,
				'lastmod' => mysql2date( DATE_W3C, $row->updated_at ),
			);
		}

		if ( $offset || null !== $limit ) {
			$entries = array_slice( $entries, (int) $offset, null === $limit ? null : (int) $limit );
		}
		return $entries;
	}

	/**
	 * @param string $lang
	 * @return int
	 */
	public static function count_for_lang( $lang ) {
		return count( self::entries_for_lang( $lang ) );
	}
}
