<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra las rewrite rules para servir el contenido en:
 *   dominio.com/en/mi-post
 *   dominio.com/en/            (home en inglés)
 * y resuelve, a través de MLS_Language_Context, qué post y qué idioma
 * corresponden a la petición actual.
 *
 * REGLA ABSOLUTA: si la URL no hizo match con la regla de un idioma
 * destino configurado, la petición es SIEMPRE idioma fuente, y ningún
 * dato de la tabla de traducciones se usa para sustituir nada. Esto se
 * marca explícitamente en `mark_source_by_default()`, antes incluso de
 * intentar resolver la ruta — así el estado por defecto nunca queda
 * ambiguo.
 */
class MLS_Rewrite {

	public function __construct() {
		// Se marca "fuente" lo antes posible (prioridad muy alta en
		// 'request', que es el primer filtro de esta clase en ejecutarse).
		// Si más adelante la URL hace match con un idioma destino,
		// map_request_to_post() lo actualiza explícitamente.
		add_filter( 'request', array( $this, 'mark_source_by_default' ), 1 );

		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_action( 'init', array( $this, 'maybe_flush_rules' ), 999 );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'request', array( $this, 'map_request_to_post' ), 10 );
		add_filter( 'the_posts', array( $this, 'swap_translated_fields' ), 10, 2 );

		// Defensa en profundidad: además de sustituir los campos del
		// WP_Post en 'the_posts', filtramos también las funciones de
		// salida directamente. Esto cubre casos donde algo (un tema, un
		// widget, una consulta secundaria) lea el post antes de que
		// 'the_posts' haya corrido, o vuelva a consultarlo por su cuenta.
		// Todos, igual que swap_translated_fields, solo actúan cuando
		// MLS_Language_Context::is_translation_request() es verdadero.
		add_filter( 'the_title', array( $this, 'filter_the_title' ), 10, 2 );
		add_filter( 'the_excerpt', array( $this, 'filter_the_excerpt' ) );

	}

	/**
	 * Se ejecuta antes que cualquier otra cosa: fija el estado por
	 * defecto en "idioma fuente". Solo map_request_to_post() (que corre
	 * después) puede cambiarlo, y solo si confirma un match real.
	 */
	public function mark_source_by_default( $query_vars ) {
		MLS_Language_Context::mark_source_request();
		return $query_vars;
	}

	/**
	 * El flush real de las rewrite rules se aplaza a esta función, que
	 * corre con prioridad muy baja en 'init'. Si lo hiciéramos justo al
	 * guardar los ajustes, se regenerarían con la configuración ANTERIOR
	 * (la que ya estaba cargada al inicio de esa misma petición), no con
	 * la recién guardada — por eso la regla del idioma nuevo a veces no
	 * quedaba registrada. Aquí, en cambio, ya se leyó la configuración
	 * actualizada desde el principio de esta petición.
	 */
	public function maybe_flush_rules() {
		if ( get_option( 'mls_flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
			delete_option( 'mls_flush_rewrite_rules' );
		}
	}

	/**
	 * Idiomas destino configurados (sin incluir el idioma de origen).
	 */
	private function get_target_langs() {
		$settings = mls_get_settings();
		return array_filter( array_map( 'sanitize_key', (array) $settings['target_langs'] ) );
	}

	public function register_rewrite_rules() {
		foreach ( $this->get_target_langs() as $lang ) {
			// Home del idioma: dominio.com/en/
			add_rewrite_rule(
				'^' . $lang . '/?$',
				'index.php?mls_lang=' . $lang . '&mls_home=1',
				'top'
			);
			// Contenido individual: dominio.com/en/mi-post-traducido/
			add_rewrite_rule(
				'^' . $lang . '/(.+?)/?$',
				'index.php?mls_lang=' . $lang . '&mls_slug=$matches[1]',
				'top'
			);
		}
	}

	public function register_query_vars( $vars ) {
		$vars[] = 'mls_lang';
		$vars[] = 'mls_slug';
		$vars[] = 'mls_home';
		return $vars;
	}

	/**
	 * Traduce los query vars mls_lang/mls_slug al post original
	 * (por ID) usando el slug traducido guardado en nuestra tabla.
	 * Así reutilizamos toda la maquinaria normal de WordPress.
	 *
	 * Si NO hay mls_lang en la URL (caso normal: idioma fuente), esta
	 * función no hace nada — el contexto ya quedó marcado como "fuente"
	 * por mark_source_by_default(), y así se queda.
	 */
	public function map_request_to_post( $query_vars ) {
		mls_debug_log( 'map_request_to_post() — REQUEST_URI=' . ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '(desconocido)' ) . ' mls_lang_query_var=' . ( isset( $query_vars['mls_lang'] ) ? $query_vars['mls_lang'] : '(ninguno)' ) );

		if ( empty( $query_vars['mls_lang'] ) ) {
			return $query_vars; // Idioma fuente: no se toca el contexto.
		}

		$lang = sanitize_key( $query_vars['mls_lang'] );
		if ( ! in_array( $lang, $this->get_target_langs(), true ) ) {
			mls_debug_log( 'mls_lang="' . $lang . '" no está entre los idiomas destino configurados — se ignora, sigue siendo fuente.' );
			return $query_vars; // Idioma no configurado: se deja como está (404 natural), sigue siendo "fuente".
		}

		if ( ! empty( $query_vars['mls_home'] ) ) {
			MLS_Language_Context::set_translation_context( $lang, null );
			unset( $query_vars['mls_lang'], $query_vars['mls_home'] );
			$this->assert_context_matches_url( $lang );
			return $query_vars; // Muestra la home/blog normal, ya traducida.
		}

		if ( ! empty( $query_vars['mls_slug'] ) ) {
			$slug    = trim( untrailingslashit( rawurldecode( $query_vars['mls_slug'] ) ), '/' );
			$post_id = MLS_DB::get_post_id_by_translated_slug( $slug, $lang );

			// Compatibilidad y robustez: también aceptamos /{lang}/{slug-original}/
			// cuando existe una traducción real para ese contenido. Esto evita 404
			// en enlaces heredados o páginas cuya traducción conserva el slug fuente.
			// Elementor/SEO seguirán usando la misma traducción; no se duplica el post.
			if ( ! $post_id ) {
				$post_id = $this->find_translated_post_by_source_path( $slug, $lang );
			}

			unset( $query_vars['mls_lang'], $query_vars['mls_slug'] );

			if ( $post_id ) {
				$post_type = get_post_type( $post_id );

				if ( 'page' === $post_type ) {
					$query_vars['page_id'] = $post_id;
				} else {
					$query_vars['p']         = $post_id;
					$query_vars['post_type'] = $post_type;
				}

				MLS_Language_Context::set_translation_context( $lang, $post_id );
				mls_debug_log( 'Contexto establecido: lang=' . $lang . ' post_id=' . $post_id . ' (slug traducido="' . $slug . '")' );
				$this->assert_context_matches_url( $lang );
			} else {
				mls_debug_log( 'Slug traducido "' . $slug . '" no encontrado para lang=' . $lang . ' — sigue siendo fuente, WordPress mostrará 404.' );
			}
			// Si no hay traducción para ese slug, el contexto sigue en
			// "fuente" (nunca se llamó set_translation_context) y
			// WordPress mostrará 404 de forma natural.
		}

		return $query_vars;
	}

	/**
	 * Fallback de routing por path original. `get_page_by_path()` soporta
	 * jerarquías (padre/hijo), por lo que /en/seccion/pagina/ puede resolver
	 * el post fuente aunque el slug traducido todavía no se haya regenerado.
	 * Solo se acepta si YA existe una traducción para el idioma solicitado.
	 */
	private function find_translated_post_by_source_path( $path, $lang ) {
		$settings   = mls_get_settings();
		$post_types = array_values( array_filter( (array) $settings['post_types'], 'post_type_exists' ) );

		if ( empty( $post_types ) ) {
			$post_types = array( 'page', 'post' );
		}

		$source_post = get_page_by_path( $path, OBJECT, $post_types );
		if ( ! $source_post ) {
			return null;
		}

		return MLS_DB::get_translation( $source_post->ID, $lang ) ? (int) $source_post->ID : null;
	}

	/**
	 * Verificación de seguridad adicional (defensa en profundidad): si
	 * por cualquier motivo el contexto quedó marcado como "traducción"
	 * pero la URL real de esta petición NO empieza con el prefijo de ese
	 * idioma, es una inconsistencia — y ante la duda, se revierte a
	 * idioma FUENTE. Nunca al revés: preferimos mostrar de más el
	 * original a arriesgarnos a mostrar una traducción en la URL
	 * incorrecta.
	 *
	 * En el flujo normal esto nunca debería dispararse (el contexto solo
	 * se establece dentro de esta misma función, y solo después de que
	 * WordPress ya hizo match con la regla de reescritura del idioma),
	 * pero queda como red de seguridad explícita y queda registrado en
	 * el log si alguna vez ocurre.
	 */
	private function assert_context_matches_url( $expected_lang ) {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$request_path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		$home_path    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		$relative = $request_path;
		if ( $home_path && 0 === strpos( $request_path, $home_path ) ) {
			$relative = substr( $request_path, strlen( $home_path ) );
		}
		$relative = ltrim( $relative, '/' );

		$matches_prefix = ( $relative === $expected_lang ) || ( 0 === strpos( $relative, $expected_lang . '/' ) );

		if ( ! $matches_prefix ) {
			mls_debug_log( 'INCONSISTENCIA DETECTADA: el contexto se iba a marcar como "' . $expected_lang . '" pero REQUEST_URI ("' . $request_path . '") no empieza con /' . $expected_lang . '/. Se revierte a idioma FUENTE por seguridad.', true );
			MLS_Language_Context::mark_source_request();
		}
	}

	/**
	 * Sustituye título / contenido / excerpt en una COPIA del WP_Post,
	 * nunca en el objeto original — WordPress puede reutilizar la misma
	 * instancia de WP_Post en varios puntos durante la misma petición
	 * (menús, widgets, consultas secundarias), y mutarla directamente
	 * arriesga filtrar el idioma traducido hacia código que esperaba el
	 * post original dentro de esa misma petición.
	 */
	public function swap_translated_fields( $posts, $query ) {
		if ( MLS_Language_Context::is_source_request() || empty( $posts ) ) {
			return $posts;
		}

		$lang   = MLS_Language_Context::get_current_language();
		$result = array();

		foreach ( $posts as $single_post ) {
			$translation = MLS_DB::get_translation( $single_post->ID, $lang );

			if ( ! $translation ) {
				$result[] = $single_post;
				continue;
			}

			$translated_post = clone $single_post;

			// Para Elementor, post_content no es lo que se renderiza
			// (ver filter_elementor_meta), pero igual lo dejamos
			// consistente por si algún widget/tema lo lee directo.
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

	/**
	 * Refuerzo directo sobre el título (además de swap_translated_fields),
	 * por si algo lee get_the_title()/the_title() antes de que 'the_posts'
	 * haya sustituido el objeto post, o vuelve a construirlo aparte.
	 */
	public function filter_the_title( $title, $post_id = null ) {
		if ( MLS_Language_Context::is_source_request() || ! $post_id ) {
			return $title;
		}
		$translation = MLS_DB::get_translation( $post_id, MLS_Language_Context::get_current_language() );
		return ( $translation && '' !== $translation->post_title ) ? $translation->post_title : $title;
	}

	public function filter_the_excerpt( $excerpt ) {
		if ( MLS_Language_Context::is_source_request() || ! in_the_loop() ) {
			return $excerpt;
		}
		$post_id     = get_the_ID();
		$translation = $post_id ? MLS_DB::get_translation( $post_id, MLS_Language_Context::get_current_language() ) : null;
		return ( $translation && '' !== $translation->post_excerpt ) ? $translation->post_excerpt : $excerpt;
	}

	/**
	 * Compatibilidad con integraciones que pudieran invocar el método de la
	 * versión 2.2.0. Ya NO se registra como filtro: interceptar
	 * `get_post_metadata` era demasiado global y podía afectar el ciclo interno
	 * de documentos/templates de Elementor. El render traducido se hace ahora
	 * mediante `elementor/frontend/builder_content_data`.
	 */
	public function filter_elementor_meta( $value, $object_id, $meta_key, $single ) {
		return $value;
	}

}
