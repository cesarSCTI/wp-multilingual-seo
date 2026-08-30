<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aislamiento de caché de Elementor por idioma.
 *
 * Elementor >= 3.22 puede convertir elementos en shortcodes cacheables y
 * expone `elementor/element_cache/unique_id` como discriminador del caché.
 * Como MLS reutiliza el mismo post/element IDs en todos los idiomas, el
 * idioma DEBE formar parte de ese discriminador o un render EN puede ser
 * reutilizado en la URL ES.
 */
class MLS_Elementor_Cache {

	public function __construct() {
		add_filter( 'option_elementor_element_cache_ttl', array( $this, 'disable_document_cache_for_translation' ), 999 );
		add_filter( 'elementor/element_cache/unique_id', array( $this, 'add_language_discriminator' ), 20 );
	}


	/**
	 * Elementor guarda tambien un cache de documento completo por post_id.
	 * Ese cache no usa el discriminador unique_id y puede devolver el HTML
	 * fuente en /en/. Se desactiva solo para URLs de traduccion; el idioma
	 * fuente conserva exactamente la configuracion global de Elementor.
	 *
	 * @param mixed $ttl
	 * @return mixed
	 */
	public function disable_document_cache_for_translation( $ttl ) {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return $ttl;
		}

		if ( MLS_Language_Context::is_translation_request() ) {
			return 'disable';
		}

		// Elementor puede leer la opcion antes de que WordPress termine de
		// resolver query_vars. La URL real sigue siendo una senal segura.
		foreach ( array_keys( MLS_Language_Registry::targets() ) as $lang ) {
			if ( MLS_Language_Context::request_matches_language_prefix( $lang ) ) {
				return 'disable';
			}
		}

		return $ttl;
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

		$version = defined( 'MLS_VERSION' ) ? sanitize_key( MLS_VERSION ) : 'current';
		$mls_id  = 'mls-' . $lang . '-' . $version;
		return $unique_id ? $unique_id . '|' . $mls_id : $mls_id;
	}
}
