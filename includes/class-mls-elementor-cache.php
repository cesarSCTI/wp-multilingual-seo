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
		add_filter( 'elementor/element_cache/unique_id', array( $this, 'add_language_discriminator' ), 20 );
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

		$mls_id = 'mls-' . $lang;
		return $unique_id ? $unique_id . '|' . $mls_id : $mls_id;
	}
}
