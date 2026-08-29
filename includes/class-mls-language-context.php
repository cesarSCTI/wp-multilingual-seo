<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Única autoridad para responder: "¿en qué idioma se está sirviendo esta
 * petición, y a qué post corresponde?"
 *
 * Regla absoluta: por defecto, TODA petición es una petición de idioma
 * FUENTE (source). Solo se convierte en petición TRADUCIDA cuando
 * MLS_Rewrite confirma, dentro de `map_request_to_post()`, que la URL
 * hizo match con la regla de un idioma destino configurado. No hay
 * ningún otro camino para activar el modo "traducción" — ni cookies, ni
 * cabecera Accept-Language, ni el idioma de una visita anterior.
 *
 * El resto del plugin NUNCA debe leer $GLOBALS['mls_current_lang'] por
 * su cuenta: todo pasa por esta clase, así hay un solo lugar donde
 * auditar la lógica.
 */
class MLS_Language_Context {

	private static $current_lang    = null;
	private static $current_post_id = null;
	private static $resolved        = false;

	/**
	 * Marca explícitamente esta petición como idioma FUENTE. Se llama muy
	 * temprano (antes de que se resuelva la ruta) para que el estado por
	 * defecto sea siempre "fuente", nunca un valor ambiguo/sin definir.
	 */
	public static function mark_source_request() {
		self::$current_lang    = null;
		self::$current_post_id = null;
		self::$resolved        = true;

		unset( $GLOBALS['mls_current_lang'], $GLOBALS['mls_current_post_id'] );
	}

	/**
	 * Se llama SOLO desde MLS_Rewrite, y solo cuando la URL realmente
	 * hizo match con la regla de un idioma destino configurado.
	 *
	 * @param string   $lang
	 * @param int|null $post_id
	 */
	public static function set_translation_context( $lang, $post_id = null ) {
		$lang = sanitize_key( $lang );

		// Defensa fuerte: en frontend una traducción solo puede activarse si
		// la URL REAL contiene el prefijo del idioma. Cookies, globals o
		// llamadas secundarias nunca pueden convertir /pagina/ en inglés.
		if ( ! is_admin() && ! self::request_matches_language_prefix( $lang ) ) {
			self::mark_source_request();
			mls_debug_log( 'Contexto traducido rechazado: REQUEST_URI no corresponde al prefijo /' . $lang . '/.', true );
			return false;
		}

		self::$current_lang    = $lang;
		self::$current_post_id = $post_id ? (int) $post_id : null;
		self::$resolved        = true;

		// Se mantiene el global por compatibilidad con código de terceros
		// que pudiera leerlo, pero la fuente de verdad real es esta clase.
		$GLOBALS['mls_current_lang'] = $lang;
		if ( $post_id ) {
			$GLOBALS['mls_current_post_id'] = (int) $post_id;
		}
		return true;
	}

	/**
	 * @return bool True si esta petición corresponde a un idioma destino.
	 */
	public static function is_translation_request() {
		if ( ! self::$resolved || null === self::$current_lang ) {
			return false;
		}

		// Revalida contra la URL en cada lectura. Es deliberadamente
		// redundante: una URL sin prefijo jamás puede consumir traducciones.
		if ( ! is_admin() && ! self::request_matches_language_prefix( self::$current_lang ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @return bool True si esta petición corresponde al idioma fuente
	 *              (incluye el caso por defecto, cuando aún no se resolvió nada).
	 */
	public static function is_source_request() {
		return ! self::is_translation_request();
	}

	/**
	 * Idioma que se debe mostrar en esta petición. Nunca ambiguo:
	 * si es petición traducida, el idioma destino; si no, el idioma fuente.
	 *
	 * @return string
	 */
	public static function get_current_language() {
		if ( self::is_translation_request() ) {
			return self::$current_lang;
		}
		return self::get_source_language();
	}

	/**
	 * @return string Código del idioma fuente configurado (ej. "es").
	 */
	public static function get_source_language() {
		$settings = mls_get_settings();
		return $settings['source_lang'];
	}

	/**
	 * @return int|null El post_id resuelto para esta URL traducida (si aplica).
	 */
	public static function get_requested_post_id() {
		return self::$current_post_id;
	}


	/**
	 * Comprueba el path HTTP real, relativo al home de WordPress, contra
	 * un prefijo de idioma. No depende de query vars ni de cookies.
	 *
	 * @param string $lang
	 * @return bool
	 */
	public static function request_matches_language_prefix( $lang ) {
		$lang = sanitize_key( $lang );
		if ( ! $lang || empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$request_path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		$home_path    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$relative     = $request_path;

		if ( $home_path && '/' !== $home_path && 0 === strpos( $request_path, $home_path ) ) {
			$relative = substr( $request_path, strlen( $home_path ) );
		}

		$relative = trim( $relative, '/' );
		return $relative === $lang || 0 === strpos( $relative, $lang . '/' );
	}

	/**
	 * Reinicia el estado (uso exclusivo de pruebas/depuración).
	 */
	public static function reset() {
		self::$current_lang    = null;
		self::$current_post_id = null;
		self::$resolved        = false;
	}
}
