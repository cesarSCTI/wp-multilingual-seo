<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Buenas prácticas SEO para sitios multilenguaje:
 *  - Etiquetas hreflang (incluye x-default) en cada versión de idioma.
 *  - Canonical correcto por idioma (evita contenido duplicado).
 *  - Meta description solo si no hay ya un plugin de SEO activo (evita duplicados).
 *  - Sitemap XML independiente por idioma + referencia en robots.txt.
 */
class MLS_SEO {

	public function __construct() {
		add_action( 'wp_head', array( $this, 'output_hreflang_tags' ), 5 );

		// Si hay un plugin de SEO activo (Yoast, RankMath, AIOSEO), ese plugin
		// ya imprime su propio <link rel="canonical">. En vez de duplicarlo,
		// nos enganchamos a su filtro para que respete el idioma actual.
		// Si no hay ninguno, quitamos el canonical por defecto de WP core
		// y ponemos el nuestro.
		if ( $this->has_active_seo_plugin() ) {
			add_filter( 'wpseo_canonical', array( $this, 'filter_seo_plugin_canonical' ) );
			add_filter( 'rank_math/frontend/canonical', array( $this, 'filter_seo_plugin_canonical' ) );
			add_filter( 'aioseo_canonical_url', array( $this, 'filter_seo_plugin_canonical' ) );
		} else {
			remove_action( 'wp_head', 'rel_canonical' );
			add_action( 'wp_head', array( $this, 'output_canonical_tag' ), 5 );
		}

		add_action( 'wp_head', array( $this, 'output_meta_description' ), 6 );

		// <html lang="en"> correcto en vez del idioma original del sitio.
		add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ) );

		add_action( 'init', array( $this, 'register_sitemap_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'register_sitemap_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_sitemap' ) );
		add_filter( 'robots_txt', array( $this, 'add_sitemaps_to_robots' ), 10, 1 );
	}

	/**
	 * Detecta si hay un plugin de SEO conocido activo, para no duplicar
	 * etiquetas (canonical, meta description) que ese plugin ya genera.
	 */
	private function has_active_seo_plugin() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' );
	}

	/**
	 * Ajusta el canonical que ya imprime Yoast/RankMath/AIOSEO para que
	 * apunte a la URL del idioma actual, en lugar de duplicar la etiqueta.
	 */
	public function filter_seo_plugin_canonical( $canonical ) {
		if ( MLS_Language_Context::is_source_request() ) {
			return $canonical; // El plugin de SEO ya apunta correctamente al idioma origen.
		}
		$lang = MLS_Language_Context::get_current_language();

		if ( is_singular() ) {
			$post_id = $this->current_post_id();
			if ( $post_id ) {
				return mls_get_translated_url( $post_id, $lang );
			}
		} elseif ( is_front_page() || is_home() ) {
			return trailingslashit( home_url( '/' . $lang ) );
		}

		return $canonical;
	}

	private function current_post_id() {
		$requested = MLS_Language_Context::get_requested_post_id();
		if ( $requested ) {
			return $requested;
		}
		return is_singular() ? get_queried_object_id() : 0;
	}

	/**
	 * <link rel="alternate" hreflang="xx" href="..."> por cada idioma
	 * disponible, más x-default apuntando al idioma origen.
	 * Cubre tanto posts/páginas individuales como la home de cada idioma
	 * (dominio.com/en/), que también es contenido indexable y necesita
	 * sus propias anotaciones hreflang.
	 */
	public function output_hreflang_tags() {
		if ( is_singular() ) {
			$this->output_hreflang_for_post( $this->current_post_id() );
		} elseif ( is_front_page() || is_home() ) {
			$this->output_hreflang_for_home();
		}
	}

	private function output_hreflang_for_post( $post_id ) {
		if ( ! $post_id ) {
			return;
		}
		$settings = mls_get_settings();

		printf(
			'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
			esc_attr( $settings['source_lang'] ),
			esc_url( get_permalink( $post_id ) )
		);
		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( get_permalink( $post_id ) )
		);

		foreach ( (array) $settings['target_langs'] as $lang ) {
			$lang = sanitize_key( $lang );
			if ( ! MLS_DB::get_translation( $post_id, $lang ) ) {
				continue; // Solo anunciamos idiomas que ya tienen traducción real.
			}
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $lang ),
				esc_url( mls_get_translated_url( $post_id, $lang ) )
			);
		}
	}

	private function output_hreflang_for_home() {
		$settings = mls_get_settings();

		printf(
			'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
			esc_attr( $settings['source_lang'] ),
			esc_url( home_url( '/' ) )
		);
		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( home_url( '/' ) )
		);

		foreach ( (array) $settings['target_langs'] as $lang ) {
			$lang = sanitize_key( $lang );
			if ( ! $lang ) {
				continue;
			}
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $lang ),
				esc_url( trailingslashit( home_url( '/' . $lang ) ) )
			);
		}
	}

	/**
	 * Canonical que respeta el idioma actual (evita duplicados entre
	 * dominio.com/mi-post y dominio.com/en/mi-post). Cubre posts/páginas
	 * y también la home de cada idioma.
	 */
	public function output_canonical_tag() {
		$settings = mls_get_settings();
		$lang     = MLS_Language_Context::get_current_language();

		if ( is_singular() ) {
			$post_id = $this->current_post_id();
			if ( ! $post_id ) {
				return;
			}
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( mls_get_translated_url( $post_id, $lang ) ) );
			return;
		}

		if ( is_front_page() || is_home() ) {
			$url = ( $lang === $settings['source_lang'] ) ? home_url( '/' ) : trailingslashit( home_url( '/' . $lang ) );
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
		}
	}

	/**
	 * Meta description traducida — solo si no detectamos Yoast/RankMath/AIOSEO,
	 * para no duplicar la etiqueta.
	 */
	public function output_meta_description() {
		if ( ! is_singular() || MLS_Language_Context::is_source_request() ) {
			return;
		}
		if ( $this->has_active_seo_plugin() ) {
			return; // Dejamos que el plugin de SEO existente maneje esto.
		}

		$post_id     = $this->current_post_id();
		$lang        = MLS_Language_Context::get_current_language();
		$translation = MLS_DB::get_translation( $post_id, $lang );

		if ( $translation && $translation->meta_description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $translation->meta_description ) );
		}
	}

	/**
	 * Sitemap XML propio por idioma: dominio.com/mls-sitemap-en.xml
	 */
	public function register_sitemap_rewrite() {
		add_rewrite_rule(
			'^mls-sitemap-([a-z]{2})\.xml$',
			'index.php?mls_sitemap_lang=$matches[1]',
			'top'
		);
	}

	public function register_sitemap_query_var( $vars ) {
		$vars[] = 'mls_sitemap_lang';
		return $vars;
	}

	public function maybe_render_sitemap() {
		$lang = get_query_var( 'mls_sitemap_lang' );
		if ( ! $lang ) {
			return;
		}

		$settings = mls_get_settings();
		if ( ! in_array( $lang, array_map( 'sanitize_key', (array) $settings['target_langs'] ), true ) ) {
			status_header( 404 );
			exit;
		}

		$rows = MLS_DB::get_all_slugs_for_lang( $lang );

		header( 'Content-Type: application/xml; charset=UTF-8' );
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $rows as $row ) {
			$url  = trailingslashit( home_url( '/' . $lang . '/' . $row->post_slug ) );
			$date = mysql2date( 'c', $row->updated_at );
			echo "\t<url>\n";
			echo "\t\t<loc>" . esc_url( $url ) . "</loc>\n";
			echo "\t\t<lastmod>" . esc_html( $date ) . "</lastmod>\n";
			echo "\t</url>\n";
		}

		echo '</urlset>';
		exit;
	}

	public function add_sitemaps_to_robots( $output ) {
		$settings = mls_get_settings();
		foreach ( (array) $settings['target_langs'] as $lang ) {
			$lang    = sanitize_key( $lang );
			$output .= 'Sitemap: ' . home_url( '/mls-sitemap-' . $lang . '.xml' ) . "\n";
		}
		return $output;
	}

	/**
	 * Corrige <html lang="..."> para que refleje el idioma que se está
	 * mostrando (ej. "en") en vez del idioma configurado del sitio en
	 * WordPress. Usa el filtro nativo `language_attributes` en vez de
	 * manipular el HTML ya generado.
	 */
	public function filter_language_attributes( $output ) {
		if ( MLS_Language_Context::is_source_request() ) {
			return $output;
		}
		$lang = esc_attr( MLS_Language_Context::get_current_language() );
		if ( preg_match( '/\blang="[^"]*"/', $output ) ) {
			return preg_replace( '/\blang="[^"]*"/', 'lang="' . $lang . '"', $output );
		}
		return $output . ' lang="' . $lang . '"';
	}
}
