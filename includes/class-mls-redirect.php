<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirección automática de visitantes según el idioma de su navegador.
 *
 * Reglas para no perjudicar el SEO (siguiendo la recomendación de Google
 * de no bloquear el acceso de los rastreadores a todas las versiones
 * de idioma mediante redirects automáticos):
 *   - Nunca se redirige a bots/crawlers conocidos.
 *   - Nunca se redirige en /wp-admin, REST API, AJAX o cron.
 *   - Se usa un redirect 302 (temporal), no 301, porque es una preferencia
 *     del visitante y no un cambio permanente de la URL.
 *   - Se guarda una cookie para no volver a redirigir si el usuario
 *     decide cambiar de idioma manualmente (evita bucles molestos).
 *   - Solo actúa sobre la primera visita a una URL "de origen" (sin
 *     prefijo de idioma); nunca interfiere si ya se está en /en/, /fr/, etc.
 */
class MLS_Redirect {

	const COOKIE_NAME = 'mls_lang_pref';

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	public function maybe_redirect() {
		$settings = mls_get_settings();

		if ( empty( $settings['auto_redirect'] ) ) {
			return;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Un administrador probando URLs manualmente no debería recibir
		// redirects sorpresa — hace muy confuso comprobar /pagina/ vs
		// /en/pagina/. Activado por defecto; se puede desactivar en Ajustes.
		if ( ! empty( $settings['ignore_redirect_admins'] ) && current_user_can( 'manage_options' ) ) {
			mls_debug_log( 'Redirect omitido: usuario administrador con "ignorar redirect para admins" activo.' );
			return;
		}

		// Si ya estamos en una URL con prefijo de idioma, no hacemos nada.
		if ( MLS_Language_Context::is_translation_request() ) {
			return;
		}

		// Si el visitante ya eligió idioma antes, respetamos su elección.
		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return;
		}

		if ( $this->is_known_bot() ) {
			return; // Los rastreadores deben poder ver siempre la versión "origen".
		}

		$preferred = $this->detect_preferred_language( $settings );
		if ( ! $preferred || $preferred === $settings['source_lang'] ) {
			return;
		}

		$target_url = $this->build_redirect_url( $preferred );
		if ( ! $target_url ) {
			return;
		}

		setcookie( self::COOKIE_NAME, $preferred, time() + MONTH_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );

		// Impide que LiteSpeed Cache, un CDN o cualquier caché de página
		// guarde esta redirección y se la sirva luego a OTRO visitante que
		// no tenía esa preferencia — una redirección cacheada por error es
		// una de las formas más comunes en que una URL de idioma fuente
		// termina mandando (a todo el mundo) hacia la versión traducida.
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}

		wp_safe_redirect( $target_url, 302 );
		exit;
	}

	/**
	 * Compara el header Accept-Language del navegador contra los idiomas
	 * configurados en el plugin y devuelve el de mayor prioridad que coincida.
	 */
	private function detect_preferred_language( $settings ) {
		if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			return null;
		}

		$available = array_map( 'sanitize_key', (array) $settings['target_langs'] );
		$available[] = sanitize_key( $settings['source_lang'] );

		$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );
		$parts  = explode( ',', $header );

		foreach ( $parts as $part ) {
			$code = strtolower( substr( trim( explode( ';', $part )[0] ), 0, 2 ) );
			if ( in_array( $code, $available, true ) ) {
				return $code;
			}
		}

		return null;
	}

	/**
	 * Construye la URL de destino equivalente en el idioma detectado,
	 * reutilizando la traducción del post actual si existe, o la home
	 * del idioma si no estamos en un post/página concreto.
	 */
	private function build_redirect_url( $lang ) {
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			if ( ! MLS_DB::is_servable( MLS_DB::get_translation( $post_id, $lang ) ) ) {
				return null; // Mejor no redirigir que mandar a una traducción inexistente/incompleta.
			}
			return mls_get_translated_url( $post_id, $lang );
		}

		if ( is_front_page() || is_home() ) {
			return trailingslashit( home_url( '/' . $lang ) );
		}

		return null; // Archivos, categorías, etc. no están cubiertos por el MVP.
	}

	private function is_known_bot() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return false;
		}
		$ua = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) );

		$bots = array( 'bot', 'crawl', 'spider', 'slurp', 'googlebot', 'bingbot', 'yandex', 'baiduspider', 'facebookexternalhit' );
		foreach ( $bots as $needle ) {
			if ( false !== strpos( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
