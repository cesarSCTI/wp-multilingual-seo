<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registro normalizado de idiomas.
 *
 * Un idioma tiene tres representaciones y este es el único sitio que las
 * traduce entre sí:
 *
 *   - code:     el prefijo de URL y la clave interna  (ej. "en")
 *   - locale:   el locale de WordPress para .mo/.json (ej. "en_US")
 *   - hreflang: el código BCP-47 para <link hreflang> (ej. "en-US")
 *
 * La configuración base sigue viviendo en `mls_settings` (source_lang +
 * target_langs, códigos de 2 letras). Este registro los enriquece con
 * locale/hreflang a partir de un mapa por defecto, y permite sobreescribir
 * cualquier campo (o añadir variantes regionales como "es-MX") mediante el
 * filtro `mls_languages`.
 */
class MLS_Language_Registry {

	/** @var array<string,array>|null Caché por petición. */
	private static $cache = null;

	/**
	 * Mapa por defecto código -> [locale, hreflang, label]. Cubre los
	 * idiomas más comunes; cualquier código no listado cae a un locale
	 * "xx_XX" y un hreflang igual al código.
	 */
	private static function defaults() {
		return array(
			'es' => array( 'es_ES', 'es', 'Español' ),
			'en' => array( 'en_US', 'en', 'English' ),
			'fr' => array( 'fr_FR', 'fr', 'Français' ),
			'de' => array( 'de_DE', 'de', 'Deutsch' ),
			'it' => array( 'it_IT', 'it', 'Italiano' ),
			'pt' => array( 'pt_PT', 'pt', 'Português' ),
			'nl' => array( 'nl_NL', 'nl', 'Nederlands' ),
			'ru' => array( 'ru_RU', 'ru', 'Русский' ),
			'ja' => array( 'ja', 'ja', '日本語' ),
			'zh' => array( 'zh_CN', 'zh-Hans', '中文' ),
			'ar' => array( 'ar', 'ar', 'العربية' ),
			'ko' => array( 'ko_KR', 'ko', '한국어' ),
			'pl' => array( 'pl_PL', 'pl', 'Polski' ),
			'tr' => array( 'tr_TR', 'tr', 'Türkçe' ),
			'sv' => array( 'sv_SE', 'sv', 'Svenska' ),
		);
	}

	/**
	 * Devuelve todos los idiomas configurados, indexados por código de URL.
	 * Cada entrada: [code, locale, hreflang, label, is_source, active].
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$settings = mls_get_settings();
		$defaults = self::defaults();

		$source = sanitize_key( $settings['source_lang'] );
		$codes  = array_merge( array( $source ), array_map( 'sanitize_key', (array) $settings['target_langs'] ) );
		$codes  = array_values( array_unique( array_filter( $codes ) ) );

		$languages = array();
		foreach ( $codes as $code ) {
			$def = isset( $defaults[ $code ] ) ? $defaults[ $code ] : array( $code . '_' . strtoupper( $code ), $code, strtoupper( $code ) );
			$languages[ $code ] = array(
				'code'      => $code,
				'locale'    => $def[0],
				'hreflang'  => $def[1],
				'label'     => $def[2],
				'is_source' => ( $code === $source ),
				'active'    => true,
			);
		}

		/**
		 * Permite corregir locale/hreflang/label, desactivar un idioma
		 * (`active => false`) o añadir variantes regionales.
		 *
		 * @param array $languages Indexado por código de URL.
		 */
		$languages = apply_filters( 'mls_languages', $languages );

		self::$cache = is_array( $languages ) ? $languages : array();
		return self::$cache;
	}

	/**
	 * @param string $code
	 * @return array|null
	 */
	public static function get( $code ) {
		$all  = self::all();
		$code = sanitize_key( $code );
		return isset( $all[ $code ] ) ? $all[ $code ] : null;
	}

	/**
	 * @return array El idioma fuente.
	 */
	public static function source() {
		foreach ( self::all() as $lang ) {
			if ( ! empty( $lang['is_source'] ) ) {
				return $lang;
			}
		}
		$settings = mls_get_settings();
		return array(
			'code'      => $settings['source_lang'],
			'locale'    => get_locale(),
			'hreflang'  => $settings['source_lang'],
			'label'     => strtoupper( $settings['source_lang'] ),
			'is_source' => true,
			'active'    => true,
		);
	}

	/**
	 * Idiomas destino activos (sin el idioma fuente).
	 *
	 * @return array<string,array>
	 */
	public static function targets() {
		$targets = array();
		foreach ( self::all() as $code => $lang ) {
			if ( empty( $lang['is_source'] ) && ! empty( $lang['active'] ) ) {
				$targets[ $code ] = $lang;
			}
		}
		return $targets;
	}

	/**
	 * @param string $code
	 * @return bool True si es un idioma destino activo y configurado.
	 */
	public static function is_target( $code ) {
		$code = sanitize_key( $code );
		return array_key_exists( $code, self::targets() );
	}

	/**
	 * @param string $code
	 * @return string Locale de WordPress (ej. "en_US"). Cae al locale del
	 *                sitio si el código no está registrado.
	 */
	public static function locale( $code ) {
		$lang = self::get( $code );
		return $lang ? $lang['locale'] : get_locale();
	}

	/**
	 * @param string $code
	 * @return string Código hreflang BCP-47 (ej. "en-US").
	 */
	public static function hreflang( $code ) {
		$lang = self::get( $code );
		return $lang ? $lang['hreflang'] : sanitize_key( $code );
	}

	/**
	 * @param string $code
	 * @return string Nombre legible del idioma.
	 */
	public static function label( $code ) {
		$lang = self::get( $code );
		return $lang ? $lang['label'] : strtoupper( sanitize_key( $code ) );
	}

	/**
	 * Reinicia la caché (pruebas / tras guardar ajustes en la misma petición).
	 */
	public static function flush_cache() {
		self::$cache = null;
	}
}
