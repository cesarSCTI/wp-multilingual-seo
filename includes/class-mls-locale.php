<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cambio real de locale en el frontend traducido.
 *
 * Sin esto, una URL /en/ muestra el cuerpo traducido pero conserva los
 * textos fijos del tema y de los plugins en el idioma del sitio (botones
 * "Leer más", mensajes de formularios, etiquetas de WooCommerce, etc.),
 * porque esos textos vienen de archivos .mo/.json cargados según
 * `get_locale()`.
 *
 * Reglas:
 *   - Solo actúa en peticiones de frontend marcadas como traducción por
 *     MLS_Language_Context (que ya exige el prefijo /{lang}/ en la URL real).
 *   - NUNCA cambia el locale en admin, REST, AJAX o cron: esas peticiones
 *     siguen en el idioma original del sitio.
 *   - El cambio se revierte al final de la petición.
 */
class MLS_Locale {

	/** @var string|null Locale al que se cambió, para revertir. */
	private static $switched_to = null;

	public function __construct() {
		// `locale` es el filtro que consulta WordPress cada vez que necesita
		// resolver el idioma activo. Cubrirlo garantiza que cualquier carga
		// de textdomain hecha después de este punto use el idioma correcto.
		add_filter( 'locale', array( $this, 'filter_locale' ) );

		// En cuanto el contexto está resuelto (tras 'wp'), forzamos la
		// recarga de los textdomains ya cargados con switch_to_locale().
		add_action( 'wp', array( $this, 'maybe_switch' ), 1 );
		add_action( 'shutdown', array( $this, 'restore' ) );
	}

	private function should_localize() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		return MLS_Language_Context::is_translation_request();
	}

	/**
	 * @param string $locale
	 * @return string
	 */
	public function filter_locale( $locale ) {
		if ( ! $this->should_localize() ) {
			return $locale;
		}
		$target = MLS_Language_Registry::locale( MLS_Language_Context::get_current_language() );
		return $target ? $target : $locale;
	}

	public function maybe_switch() {
		if ( ! $this->should_localize() || null !== self::$switched_to ) {
			return;
		}
		$target = MLS_Language_Registry::locale( MLS_Language_Context::get_current_language() );
		if ( ! $target || $target === get_locale() ) {
			return;
		}
		if ( function_exists( 'switch_to_locale' ) && switch_to_locale( $target ) ) {
			self::$switched_to = $target;
			mls_debug_log( 'Locale cambiado a ' . $target . ' para esta petición traducida.' );
		}
	}

	public function restore() {
		if ( null !== self::$switched_to && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
			self::$switched_to = null;
		}
	}
}
