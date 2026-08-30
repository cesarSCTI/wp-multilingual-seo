<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aislamiento de la caché de Elementor por idioma.
 *
 * Elementor cachea el HTML renderizado del documento en el postmeta
 * `_elementor_element_cache`, indexado por post_id y SIN distinguir idioma.
 * Como este plugin sirve el mismo post_id en varios idiomas (sin duplicar),
 * esa caché es incompatible: devolvería el HTML de un idioma en la URL de
 * otro, y una vez escrita se "envenena" para todos.
 *
 * Solución: mientras haya idiomas destino configurados, se desactiva esa
 * caché de HTML documental en el FRONTEND (filtro `mls_disable_elementor_document_cache`
 * para revertir). El resto de cachés de Elementor (CSS, assets) no dependen
 * del idioma y siguen igual. La caché de PÁGINA de LiteSpeed/servidor sigue
 * funcionando y cachea cada URL por separado, así que el impacto real es
 * pequeño (solo el primer render de cada URL y los usuarios logueados).
 *
 * Además, para la caché de elementos como shortcode (Elementor >= 3.22) se
 * añade el idioma al discriminador `elementor/element_cache/unique_id`.
 */
class MLS_Elementor_Cache {

	public function __construct() {
		add_filter( 'option_elementor_element_cache_ttl', array( $this, 'maybe_disable_document_cache' ), 999 );
		add_filter( 'default_option_elementor_element_cache_ttl', array( $this, 'maybe_disable_document_cache' ), 999 );
		add_filter( 'elementor/element_cache/unique_id', array( $this, 'add_language_discriminator' ), 20 );
	}

	/**
	 * @param mixed $ttl
	 * @return mixed  'disable' para apagar la caché de HTML documental.
	 */
	public function maybe_disable_document_cache( $ttl ) {
		if ( is_admin() || wp_doing_cron() ) {
			return $ttl;
		}
		if ( ! class_exists( 'MLS_Language_Registry' ) ) {
			return $ttl;
		}
		// Solo si el plugin está realmente traduciendo algo.
		if ( empty( MLS_Language_Registry::targets() ) ) {
			return $ttl;
		}
		/**
		 * Permite volver a activar la caché de HTML documental de Elementor
		 * (por defecto desactivada mientras haya traducción) si el sitio
		 * gestiona el keying por idioma por su cuenta.
		 */
		if ( ! apply_filters( 'mls_disable_elementor_document_cache', true ) ) {
			return $ttl;
		}
		return 'disable';
	}

	/**
	 * @param string $unique_id Identificador aportado por Elementor/terceros.
	 * @return string
	 */
	public function add_language_discriminator( $unique_id ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $unique_id;
		}

		$lang = class_exists( 'MLS_Language_Context' )
			? MLS_Language_Context::get_current_language()
			: substr( get_locale(), 0, 2 );

		$lang = sanitize_key( $lang );
		if ( ! $lang ) {
			$lang = 'source';
		}

		$version = defined( 'MLS_ELEMENTOR_CACHE_SCHEMA_VERSION' ) ? sanitize_key( MLS_ELEMENTOR_CACHE_SCHEMA_VERSION ) : '1';
		$mls_id  = 'mls-' . $lang . '-v' . $version;
		return $unique_id ? $unique_id . '|' . $mls_id : $mls_id;
	}
}
