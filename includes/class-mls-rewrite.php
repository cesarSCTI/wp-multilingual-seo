<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Router de las URLs con prefijo de idioma.
 *
 * En lugar de una única regla catch-all, se registran reglas TIPADAS por
 * recurso (home, paginación de home, búsqueda, feed y contenido), de modo
 * que rutas que no son contenido traducido (feeds, endpoints, paginación)
 * no se capturan por accidente y se resuelven como lo haría WordPress.
 *
 * REGLA ABSOLUTA: si la URL no hizo match con la regla de un idioma
 * destino configurado, la petición es SIEMPRE idioma fuente y ningún dato
 * de la tabla de traducciones se usa para sustituir nada. Se marca
 * explícitamente en `mark_source_by_default()` antes de intentar resolver
 * la ruta.
 */
class MLS_Rewrite {

	/** @var bool Una URL /{lang}/... válida cuyo contenido no tiene traducción publicada. */
	private $force_404 = false;

	public function __construct() {
		add_filter( 'request', array( $this, 'mark_source_by_default' ), 1 );

		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_action( 'init', array( $this, 'maybe_flush_rules' ), 999 );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'request', array( $this, 'map_request_to_post' ), 10 );
		add_action( 'wp', array( $this, 'apply_force_404' ) );
		add_filter( 'the_posts', array( $this, 'swap_translated_fields' ), 10, 2 );

		add_filter( 'the_title', array( $this, 'filter_the_title' ), 10, 2 );
		add_filter( 'the_content', array( $this, 'filter_the_content' ), 9 );
		add_filter( 'the_excerpt', array( $this, 'filter_the_excerpt' ) );
	}

	/**
	 * Fija el estado por defecto en "idioma fuente" lo antes posible.
	 */
	public function mark_source_by_default( $query_vars ) {
		MLS_Language_Context::mark_source_request();
		return $query_vars;
	}

	public function maybe_flush_rules() {
		if ( get_option( 'mls_flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
			delete_option( 'mls_flush_rewrite_rules' );
		}
	}

	/**
	 * Idiomas destino activos configurados.
	 *
	 * @return string[]
	 */
	private function get_target_langs() {
		return array_keys( MLS_Language_Registry::targets() );
	}

	public function register_rewrite_rules() {
		$feeds = '(feed|rdf|rss|rss2|atom)';

		// `add_rewrite_rule( ..., 'top' )` ANTEPONE cada regla, así que la
		// ÚLTIMA añadida queda evaluada primero. Por eso se añaden de MENOS
		// a MÁS específica: el catch-all de contenido primero y las reglas
		// exactas de home/feed/búsqueda al final.
		foreach ( $this->get_target_langs() as $lang ) {
			$l = preg_quote( $lang, '#' );
			$q = 'index.php?mls_lang=' . $lang;

			// Contenido (de más general a más específico).
			add_rewrite_rule( '^' . $l . '/(.+?)/?$', $q . '&mls_path=$matches[1]', 'top' );
			add_rewrite_rule( '^' . $l . '/(.+?)/page/([0-9]{1,})/?$', $q . '&mls_path=$matches[1]&mls_paged=$matches[2]', 'top' );
			add_rewrite_rule( '^' . $l . '/(.+?)/' . $feeds . '/?$', $q . '&mls_path=$matches[1]&feed=$matches[2]', 'top' );
			add_rewrite_rule( '^' . $l . '/(.+?)/feed/' . $feeds . '/?$', $q . '&mls_path=$matches[1]&feed=$matches[2]', 'top' );

			// Home del idioma y sus variantes (más específicas: van al final).
			add_rewrite_rule( '^' . $l . '/?$', $q . '&mls_home=1', 'top' );
			add_rewrite_rule( '^' . $l . '/page/([0-9]{1,})/?$', $q . '&mls_home=1&mls_paged=$matches[1]', 'top' );
			add_rewrite_rule( '^' . $l . '/search/(.+)/?$', $q . '&mls_search=$matches[1]', 'top' );
			add_rewrite_rule( '^' . $l . '/' . $feeds . '/?$', $q . '&mls_home=1&feed=$matches[1]', 'top' );
			add_rewrite_rule( '^' . $l . '/feed/' . $feeds . '/?$', $q . '&mls_home=1&feed=$matches[1]', 'top' );
		}
	}

	public function register_query_vars( $vars ) {
		$vars[] = 'mls_lang';
		$vars[] = 'mls_path';
		$vars[] = 'mls_slug'; // Compatibilidad con reglas cacheadas de versiones previas.
		$vars[] = 'mls_home';
		$vars[] = 'mls_paged';
		$vars[] = 'mls_search';
		return $vars;
	}

	/**
	 * Traduce los query vars mls_* al recurso original, reutilizando la
	 * maquinaria normal de WordPress (p / page_id / s / paged).
	 */
	public function map_request_to_post( $query_vars ) {
		if ( empty( $query_vars['mls_lang'] ) ) {
			return $query_vars; // Idioma fuente: no se toca el contexto.
		}

		$lang = sanitize_key( $query_vars['mls_lang'] );
		unset( $query_vars['mls_lang'] );

		if ( ! MLS_Language_Registry::is_target( $lang ) ) {
			mls_debug_log( 'mls_lang="' . $lang . '" no es un idioma destino activo — se ignora, sigue siendo fuente.' );
			return $query_vars;
		}

		$paged = isset( $query_vars['mls_paged'] ) ? max( 1, (int) $query_vars['mls_paged'] ) : 0;
		unset( $query_vars['mls_paged'] );

		// --- Home / blog del idioma -------------------------------------
		if ( ! empty( $query_vars['mls_home'] ) ) {
			unset( $query_vars['mls_home'] );
			if ( $paged > 1 ) {
				$query_vars['paged'] = $paged;
			}
			MLS_Language_Context::set_translation_context( $lang, null );
			$this->assert_context_matches_url( $lang );
			return $query_vars;
		}

		// --- Búsqueda del idioma --------------------------------------
		if ( ! empty( $query_vars['mls_search'] ) ) {
			$query_vars['s'] = sanitize_text_field( rawurldecode( $query_vars['mls_search'] ) );
			unset( $query_vars['mls_search'] );
			if ( $paged > 1 ) {
				$query_vars['paged'] = $paged;
			}
			MLS_Language_Context::set_translation_context( $lang, null );
			$this->assert_context_matches_url( $lang );
			return $query_vars;
		}

		// --- Contenido individual -------------------------------------
		$raw_path = '';
		if ( ! empty( $query_vars['mls_path'] ) ) {
			$raw_path = $query_vars['mls_path'];
		} elseif ( ! empty( $query_vars['mls_slug'] ) ) {
			$raw_path = $query_vars['mls_slug']; // Regla cacheada previa.
		}
		unset( $query_vars['mls_path'], $query_vars['mls_slug'] );

		if ( '' === $raw_path ) {
			return $query_vars;
		}

		$resolved = MLS_Url::resolve_path( $raw_path, $lang );

		if ( ! $resolved ) {
			// ¿Es el archivo de un término traducido? (/en/category/…)
			$term = MLS_Url::resolve_term_path( $raw_path, $lang );
			if ( $term ) {
				foreach ( $term['query_vars'] as $k => $v ) {
					$query_vars[ $k ] = $v;
				}
				if ( $paged > 1 ) {
					$query_vars['paged'] = $paged;
				}
				MLS_Language_Context::set_translation_context( $lang, null );
				$this->assert_context_matches_url( $lang );
				mls_debug_log( 'Contexto: lang=' . $lang . ' archivo de término ' . $term['taxonomy'] . ' #' . $term['term_id'] );
				return $query_vars;
			}

			// URL con prefijo de idioma válido pero sin traducción publicada
			// para ese contenido: NUNCA se sirve el original en su lugar
			// (evita mezclar idiomas en una misma URL). Se fuerza un 404.
			mls_debug_log( 'Path traducido "' . $raw_path . '" sin traducción publicada para lang=' . $lang . ' — 404.' );
			$this->force_404 = true;
			return $query_vars;
		}

		$post_id   = (int) $resolved['post_id'];
		$post_type = get_post_type( $post_id );

		if ( 'page' === $post_type ) {
			$query_vars['page_id'] = $post_id;
		} else {
			$query_vars['p']         = $post_id;
			$query_vars['post_type'] = $post_type;
		}
		if ( $paged > 1 ) {
			$query_vars['page'] = $paged; // Paginación <!--nextpage--> de un singular.
		}

		MLS_Language_Context::set_translation_context( $lang, $post_id );
		mls_debug_log( 'Contexto: lang=' . $lang . ' post_id=' . $post_id . ' (match=' . $resolved['matched'] . ', path="' . $raw_path . '")' );
		$this->assert_context_matches_url( $lang );

		return $query_vars;
	}

	/**
	 * Convierte en 404 real una URL /{lang}/... sin traducción publicada.
	 */
	public function apply_force_404() {
		if ( ! $this->force_404 ) {
			return;
		}
		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Red de seguridad: si el contexto se iba a marcar como traducción
	 * pero la URL real no empieza con el prefijo de ese idioma, se revierte
	 * a idioma FUENTE.
	 */
	private function assert_context_matches_url( $expected_lang ) {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$request_path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		$relative     = MLS_Language_Context::get_request_relative_path();

		$matches_prefix = ( $relative === $expected_lang ) || ( 0 === strpos( $relative, $expected_lang . '/' ) );

		if ( ! $matches_prefix ) {
			mls_debug_log( 'INCONSISTENCIA: contexto "' . $expected_lang . '" pero REQUEST_URI ("' . $request_path . '") no empieza con /' . $expected_lang . '/. Se revierte a FUENTE.', true );
			MLS_Language_Context::mark_source_request();
		}
	}

	/**
	 * Sustituye título / contenido / excerpt en una COPIA del WP_Post.
	 */
	public function swap_translated_fields( $posts, $query ) {
		if ( MLS_Language_Context::is_source_request() || empty( $posts ) ) {
			return $posts;
		}

		$lang   = MLS_Language_Context::get_current_language();
		$result = array();

		foreach ( $posts as $single_post ) {
			$translation = MLS_DB::get_translation( $single_post->ID, $lang );

			if ( ! MLS_DB::is_servable( $translation ) ) {
				$result[] = $single_post;
				continue;
			}

			$translated_post = clone $single_post;

			if ( '' !== $translation->post_title ) {
				$translated_post->post_title = $translation->post_title;
			}
			if ( '' !== $translation->post_content ) {
				$translated_post->post_content = $translation->post_content;
			}
			if ( '' !== $translation->post_excerpt ) {
				$translated_post->post_excerpt = $translation->post_excerpt;
			}

			$result[] = $translated_post;
		}

		return $result;
	}

	public function filter_the_title( $title, $post_id = null ) {
		if ( MLS_Language_Context::is_source_request() || ! $post_id ) {
			return $title;
		}
		$translation = MLS_DB::get_translation( $post_id, MLS_Language_Context::get_current_language() );
		return ( MLS_DB::is_servable( $translation ) && '' !== $translation->post_title ) ? $translation->post_title : $title;
	}

	/**
	 * Sustituye el cuerpo tambien en el punto de salida. Algunos temas y
	 * constructores vuelven a cargar el WP_Post original despues de
	 * `the_posts`; sin este respaldo el titulo se traducia, pero el cuerpo no.
	 *
	 * Elementor se excluye: sus textos viven en `_elementor_data` y los
	 * intercambia MLS_Elementor_Renderer antes de generar el HTML.
	 *
	 * @param string $content
	 * @return string
	 */
	public function filter_the_content( $content ) {
		if ( MLS_Language_Context::is_source_request() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			$post_id = MLS_Language_Context::get_requested_post_id();
		}
		if ( ! $post_id ) {
			return $content;
		}

		$translation = MLS_DB::get_translation( $post_id, MLS_Language_Context::get_current_language() );
		if ( ! MLS_DB::is_servable( $translation ) || '' === (string) $translation->post_content ) {
			return $content;
		}

		if ( class_exists( 'MLS_Elementor_Adapter' ) && MLS_Elementor_Adapter::is_elementor_post( $post_id ) ) {
			return $content;
		}

		return $translation->post_content;
	}

	public function filter_the_excerpt( $excerpt ) {
		if ( MLS_Language_Context::is_source_request() || ! in_the_loop() ) {
			return $excerpt;
		}
		$post_id     = get_the_ID();
		$translation = $post_id ? MLS_DB::get_translation( $post_id, MLS_Language_Context::get_current_language() ) : null;
		return ( MLS_DB::is_servable( $translation ) && '' !== $translation->post_excerpt ) ? $translation->post_excerpt : $excerpt;
	}

	/**
	 * Compatibilidad: la interceptación de `_elementor_data` vía
	 * `get_post_metadata` se retiró en 2.3.0 (era demasiado global).
	 */
	public function filter_elementor_meta( $value, $object_id, $meta_key, $single ) {
		return $value;
	}
}
