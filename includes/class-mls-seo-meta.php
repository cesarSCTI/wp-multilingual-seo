<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapta title / meta description / Open Graph / Twitter al idioma de la
 * URL, tanto para el core como para Yoast, Rank Math y AIOSEO.
 *
 * Nunca duplica etiquetas: se engancha a los filtros que cada plugin (o el
 * core) YA usa para generarlas. En una petición sin prefijo de idioma todo
 * esto es no-op.
 */
class MLS_SEO_Meta {

	public function __construct() {
		// --- Core -------------------------------------------------------
		add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ), 20 );
		add_filter( 'pre_get_document_title', array( $this, 'filter_pre_title' ), 20 );

		// Open Graph propio SOLO si no hay plugin de SEO que ya lo imprima.
		add_action( 'wp_head', array( $this, 'output_open_graph' ), 4 );

		// --- Yoast ----------------------------------------------------
		add_filter( 'wpseo_title', array( $this, 'filter_text_title' ) );
		add_filter( 'wpseo_metadesc', array( $this, 'filter_text_desc' ) );
		add_filter( 'wpseo_opengraph_title', array( $this, 'filter_text_title' ) );
		add_filter( 'wpseo_opengraph_desc', array( $this, 'filter_text_desc' ) );
		add_filter( 'wpseo_twitter_title', array( $this, 'filter_text_title' ) );
		add_filter( 'wpseo_twitter_description', array( $this, 'filter_text_desc' ) );

		// --- Rank Math ----------------------------------------------
		add_filter( 'rank_math/frontend/title', array( $this, 'filter_text_title' ) );
		add_filter( 'rank_math/frontend/description', array( $this, 'filter_text_desc' ) );
		add_filter( 'rank_math/opengraph/facebook/og_title', array( $this, 'filter_text_title' ) );
		add_filter( 'rank_math/opengraph/facebook/og_description', array( $this, 'filter_text_desc' ) );

		// --- AIOSEO -------------------------------------------------
		add_filter( 'aioseo_title', array( $this, 'filter_text_title' ) );
		add_filter( 'aioseo_description', array( $this, 'filter_text_desc' ) );
	}

	private function translation() {
		if ( MLS_Language_Context::is_source_request() || ! is_singular() ) {
			return null;
		}
		$post_id = MLS_Language_Context::get_requested_post_id();
		if ( ! $post_id ) {
			$post_id = get_queried_object_id();
		}
		if ( ! $post_id ) {
			return null;
		}
		$tr = MLS_DB::get_translation( $post_id, MLS_Language_Context::get_current_language() );
		return MLS_DB::is_servable( $tr ) ? $tr : null;
	}

	private function has_seo_plugin() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' );
	}

	private function translated_title( $fallback = '' ) {
		$tr = $this->translation();
		if ( ! $tr ) {
			return $fallback;
		}
		if ( '' !== (string) $tr->meta_title ) {
			return $tr->meta_title;
		}
		return '' !== (string) $tr->post_title ? $tr->post_title : $fallback;
	}

	private function translated_desc( $fallback = '' ) {
		$tr = $this->translation();
		if ( $tr && '' !== (string) $tr->meta_description ) {
			return $tr->meta_description;
		}
		return $fallback;
	}

	/**
	 * @param array $parts
	 * @return array
	 */
	public function filter_title_parts( $parts ) {
		$t = $this->translated_title();
		if ( '' !== $t ) {
			$parts['title'] = $t;
		}
		return $parts;
	}

	/**
	 * @param string $title
	 * @return string
	 */
	public function filter_pre_title( $title ) {
		// Solo se fuerza si el core iba a construir el título por su cuenta.
		return $title;
	}

	public function filter_text_title( $value ) {
		$t = $this->translated_title( is_string( $value ) ? $value : '' );
		return '' !== $t ? $t : $value;
	}

	public function filter_text_desc( $value ) {
		$d = $this->translated_desc( is_string( $value ) ? $value : '' );
		return '' !== $d ? $d : $value;
	}

	/**
	 * Open Graph mínimo cuando no hay plugin de SEO. Incluye `og:locale`
	 * correcto para el idioma de la URL.
	 */
	public function output_open_graph() {
		$tr = $this->translation();
		if ( ! $tr || $this->has_seo_plugin() ) {
			return;
		}
		$lang    = MLS_Language_Context::get_current_language();
		$post_id = MLS_Language_Context::get_requested_post_id() ? MLS_Language_Context::get_requested_post_id() : get_queried_object_id();
		$title   = $this->translated_title( get_the_title( $post_id ) );
		$desc    = $this->translated_desc();
		$url     = mls_get_translated_url( $post_id, $lang );
		$locale  = str_replace( '-', '_', MLS_Language_Registry::locale( $lang ) );

		printf( '<meta property="og:locale" content="%s" />' . "\n", esc_attr( $locale ) );
		printf( '<meta property="og:type" content="article" />' . "\n" );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
		if ( '' !== $desc ) {
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $desc ) );
			printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $desc ) );
		}
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
		printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $title ) );

		foreach ( MLS_Language_Registry::targets() as $code => $l ) {
			if ( $code === $lang || ! MLS_DB::is_indexable( MLS_DB::get_translation( $post_id, $code ) ) ) {
				continue;
			}
			printf(
				'<meta property="og:locale:alternate" content="%s" />' . "\n",
				esc_attr( str_replace( '-', '_', MLS_Language_Registry::locale( $code ) ) )
			);
		}
	}
}
