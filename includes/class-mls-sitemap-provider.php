<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proveedor de sitemap nativo (`WP_Sitemaps`) para las URLs traducidas.
 *
 * Este archivo solo se carga cuando `WP_Sitemaps_Provider` existe (ver
 * MLS_Sitemap::register_native_provider), por eso puede extenderla sin
 * comprobaciones.
 *
 * Cada idioma destino es un "subtipo"; el core pagina automáticamente
 * usando `get_max_num_pages()`.
 */
class MLS_Sitemap_Provider extends WP_Sitemaps_Provider {

	public function __construct() {
		$this->name        = 'mls_translations';
		$this->object_type = 'mls_translations';
	}

	/**
	 * @return array<string,array> Subtipos: uno por idioma destino con contenido.
	 */
	public function get_object_subtypes() {
		$subtypes = array();
		foreach ( MLS_Language_Registry::targets() as $code => $lang ) {
			if ( MLS_DB::lang_has_translations( $code ) ) {
				$subtypes[ $code ] = (object) array(
					'name'  => $code,
					'label' => $lang['label'],
				);
			}
		}
		return $subtypes;
	}

	/**
	 * @param int    $page_num
	 * @param string $object_subtype Código de idioma.
	 * @return array<int,array>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		if ( '' === $object_subtype ) {
			return array();
		}
		$per_page = wp_sitemaps_get_max_urls( $this->object_type );
		$offset   = ( max( 1, (int) $page_num ) - 1 ) * $per_page;

		$entries = MLS_Sitemap::entries_for_lang( $object_subtype, $offset, $per_page );

		/** Mismo filtro que aplica el core a los demás proveedores. */
		return apply_filters( 'wp_sitemaps_posts_url_list', $entries, 'mls_translations', $object_subtype );
	}

	/**
	 * @param string $object_subtype Código de idioma.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		if ( '' === $object_subtype ) {
			return 0;
		}
		$per_page = wp_sitemaps_get_max_urls( $this->object_type );
		$total    = MLS_Sitemap::count_for_lang( $object_subtype );
		return (int) ceil( $total / max( 1, $per_page ) );
	}
}
