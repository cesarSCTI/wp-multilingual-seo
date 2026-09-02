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

		// Canonical NO invasivo: nunca se quita `rel_canonical` del core ni se
		// desregistra nada de otros plugins. En una petición sin prefijo de
		// idioma todos estos filtros son no-ops y el sitio se comporta igual
		// que sin el plugin. Solo en una URL traducida ajustamos la URL que
		// el core (o el plugin de SEO) ya iba a imprimir.
		add_filter( 'get_canonical_url', array( $this, 'filter_core_canonical_url' ), 10, 2 );
		add_filter( 'wpseo_canonical', array( $this, 'filter_seo_plugin_canonical' ) );
		add_filter( 'rank_math/frontend/canonical', array( $this, 'filter_seo_plugin_canonical' ) );
		add_filter( 'aioseo_canonical_url', array( $this, 'filter_seo_plugin_canonical' ) );

		// El core solo imprime canonical en is_singular(); la home traducida
		// (/en/) también necesita el suyo y no lo cubre ni el core ni este
		// filtro, así que lo emitimos aparte — y solo en peticiones traducidas.
		add_action( 'wp_head', array( $this, 'output_front_page_canonical' ), 5 );

		add_action( 'wp_head', array( $this, 'output_meta_description' ), 6 );

		// <html lang="en"> correcto en vez del idioma original del sitio.
		add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ) );

		// Sitemap XML propio `/mls-sitemap-{lang}.xml`. La ruta y el render se
		// registran SIEMPRE (son inocuos si además está el nativo); solo las
		// piezas que podrían duplicar algo (robots.txt) se deciden en runtime,
		// cuando ya sabemos si Yoast/Rank Math desactivaron el sitemap nativo.
		add_action( 'init', array( $this, 'register_sitemap_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'register_sitemap_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_sitemap' ) );
		add_filter( 'robots_txt', array( $this, 'add_sitemaps_to_robots' ), 10, 1 );
		add_filter( 'wpseo_sitemap_index', array( $this, 'add_to_yoast_sitemap_index' ) );
	}

	/**
	 * ¿Está activo el sitemap NATIVO de WordPress (WP_Sitemaps)? Se evalúa en
	 * runtime, nunca en el constructor: Yoast/Rank Math registran su filtro
	 * `wp_sitemaps_enabled` durante `plugins_loaded`, quizá después que este
	 * plugin.
	 */
	private function native_sitemaps_active() {
		return function_exists( 'wp_sitemaps_add_provider' )
			&& (bool) apply_filters( 'mls_use_native_sitemaps', true )
			&& (bool) apply_filters( 'wp_sitemaps_enabled', true );
	}

	/**
	 * Añade `/mls-sitemap-{lang}.xml` al índice de sitemaps de Yoast.
	 *
	 * @param string $xml
	 * @return string
	 */
	public function add_to_yoast_sitemap_index( $xml ) {
		foreach ( MLS_Language_Registry::targets() as $code => $lang ) {
			if ( ! MLS_DB::lang_has_translations( $code ) ) {
				continue;
			}
			$xml .= "\t<sitemap>\n";
			$xml .= "\t\t<loc>" . esc_url( home_url( '/mls-sitemap-' . $code . '.xml' ) ) . "</loc>\n";
			$xml .= "\t\t<lastmod>" . esc_html( gmdate( 'c' ) ) . "</lastmod>\n";
			$xml .= "\t</sitemap>\n";
		}
		return $xml;
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
		// El hreflang del idioma fuente (y x-default) se construye con
		// `get_permalink()`, que en una petición /en/ MLS_Links reescribiría a
		// /en/. Se suspende esa localización mientras se generan las etiquetas
		// para que la URL del idioma fuente sea la real.
		$suspend = class_exists( 'MLS_Links' );
		$prev    = $suspend ? MLS_Links::$suspended : false;
		if ( $suspend ) {
			MLS_Links::$suspended = true;
		}

		if ( is_singular() ) {
			$this->output_hreflang_for_post( $this->current_post_id() );
		} elseif ( is_front_page() || is_home() ) {
			$this->output_hreflang_for_home();
		}

		if ( $suspend ) {
			MLS_Links::$suspended = $prev;
		}
	}

	private function output_hreflang_for_post( $post_id ) {
		if ( ! $post_id ) {
			return;
		}
		$source = MLS_Language_Registry::source();

		printf(
			'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
			esc_attr( $source['hreflang'] ),
			esc_url( get_permalink( $post_id ) )
		);
		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( get_permalink( $post_id ) )
		);

		foreach ( MLS_Language_Registry::targets() as $code => $lang ) {
			if ( ! MLS_DB::is_indexable( MLS_DB::get_translation( $post_id, $code ) ) ) {
				continue; // Solo anunciamos idiomas con una traducción publicada.
			}
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $lang['hreflang'] ),
				esc_url( mls_get_translated_url( $post_id, $code ) )
			);
		}
	}

	private function output_hreflang_for_home() {
		$source = MLS_Language_Registry::source();

		printf(
			'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
			esc_attr( $source['hreflang'] ),
			esc_url( home_url( '/' ) )
		);
		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( home_url( '/' ) )
		);

		foreach ( MLS_Language_Registry::targets() as $code => $lang ) {
			if ( ! MLS_DB::lang_has_translations( $code ) ) {
				continue; // Coherente con el hreflang de post: solo idiomas con contenido real.
			}
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $lang['hreflang'] ),
				esc_url( MLS_Url::home( $code ) )
			);
		}
	}

	/**
	 * Ajusta la URL canónica que el core ya iba a imprimir (`wp_get_canonical_url()`
	 * / `rel_canonical`) para que apunte a la versión del idioma actual. En
	 * peticiones de idioma fuente devuelve el valor sin tocar.
	 *
	 * @param string       $canonical_url
	 * @param WP_Post|null  $post
	 * @return string
	 */
	public function filter_core_canonical_url( $canonical_url, $post ) {
		if ( MLS_Language_Context::is_source_request() ) {
			return $canonical_url;
		}
		$lang    = MLS_Language_Context::get_current_language();
		$post_id = $post ? (int) $post->ID : $this->current_post_id();

		return $post_id ? mls_get_translated_url( $post_id, $lang ) : $canonical_url;
	}

	/**
	 * Canonical de la home traducida (/en/). Solo se ejecuta en peticiones
	 * traducidas y solo si ningún plugin de SEO va a imprimir el suyo.
	 */
	public function output_front_page_canonical() {
		if ( MLS_Language_Context::is_source_request() ) {
			return;
		}
		if ( ! is_front_page() && ! is_home() ) {
			return;
		}
		if ( $this->has_active_seo_plugin() ) {
			return; // Su filtro aioseo/wpseo/rank_math ya lo cubre.
		}
		$lang = MLS_Language_Context::get_current_language();
		printf(
			'<link rel="canonical" href="%s" />' . "\n",
			esc_url( trailingslashit( home_url( '/' . $lang ) ) )
		);
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

		$rows           = MLS_DB::get_all_slugs_for_lang( $lang );
		$front_page_id  = ( 'page' === get_option( 'show_on_front' ) ) ? (int) get_option( 'page_on_front' ) : 0;

		header( 'Content-Type: application/xml; charset=UTF-8' );
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $rows as $row ) {
			// Solo se indexa una traducción cuyo post de origen esté publicado
			// y sea públicamente accesible, y cuya propia traducción no haya
			// fallado. Nunca se listan borradores, privados, protegidos con
			// contraseña, papelera ni traducciones incompletas.
			if ( ! MLS_DB::is_indexable( $row ) ) {
				continue;
			}
			$post = get_post( (int) $row->post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			if ( '' !== (string) $post->post_password ) {
				continue;
			}
			if ( 'post' !== $post->post_type && 'page' !== $post->post_type ) {
				$type_obj = get_post_type_object( $post->post_type );
				if ( ! $type_obj || empty( $type_obj->public ) ) {
					continue;
				}
			}

			// La front page vive en /{lang}/, no en /{lang}/{slug}/.
			if ( $front_page_id && (int) $row->post_id === $front_page_id ) {
				$url = trailingslashit( home_url( '/' . $lang ) );
			} else {
				$path = ! empty( $row->translated_path ) ? $row->translated_path : $row->post_slug;
				$url  = trailingslashit( home_url( '/' . $lang . '/' . ltrim( $path, '/' ) ) );
			}

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
		// Si el sitemap nativo está activo, ya se añade allí; si Yoast maneja
		// el sitemap, lo referencia él. Solo se añade a robots.txt cuando este
		// XML propio es la única fuente.
		if ( $this->native_sitemaps_active() || $this->has_active_seo_plugin() ) {
			return $output;
		}
		foreach ( MLS_Language_Registry::targets() as $code => $lang ) {
			if ( ! MLS_DB::lang_has_translations( $code ) ) {
				continue;
			}
			$output .= 'Sitemap: ' . home_url( '/mls-sitemap-' . $code . '.xml' ) . "\n";
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
		$lang = esc_attr( MLS_Language_Registry::hreflang( MLS_Language_Context::get_current_language() ) );
		if ( preg_match( '/\blang="[^"]*"/', $output ) ) {
			return preg_replace( '/\blang="[^"]*"/', 'lang="' . $lang . '"', $output );
		}
		return $output . ' lang="' . $lang . '"';
	}
}
