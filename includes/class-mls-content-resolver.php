<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Punto único de decisión: dado un post, ¿qué "adaptador" de contenido
 * debe usarse para extraer/reconstruir texto traducible?
 *
 * Mantiene separado el "qué constructor de página es" del "cómo se
 * traduce" (eso vive en cada Adapter) y del "cómo se enruta la URL"
 * (eso vive en MLS_Rewrite).
 */
class MLS_Content_Resolver {

	const BUILDER_ELEMENTOR = 'elementor';
	const BUILDER_GUTENBERG = 'gutenberg';
	const BUILDER_CLASSIC   = 'classic';

	/**
	 * @param int $post_id
	 * @return string 'elementor' | 'gutenberg' | 'classic'
	 */
	public static function detect_builder( $post_id ) {
		if ( class_exists( 'MLS_Elementor_Adapter' ) && MLS_Elementor_Adapter::is_elementor_post( $post_id ) ) {
			return self::BUILDER_ELEMENTOR;
		}

		$post = get_post( $post_id );
		if ( $post && class_exists( 'MLS_Gutenberg_Adapter' ) && MLS_Gutenberg_Adapter::is_gutenberg_content( $post->post_content ) ) {
			return self::BUILDER_GUTENBERG;
		}

		return self::BUILDER_CLASSIC;
	}

	/**
	 * Nombre legible para mostrar en el admin.
	 *
	 * @param string $builder
	 * @return string
	 */
	public static function label( $builder ) {
		switch ( $builder ) {
			case self::BUILDER_ELEMENTOR:
				return __( 'Elementor', 'mls' );
			case self::BUILDER_GUTENBERG:
				return __( 'Gutenberg', 'mls' );
			default:
				return __( 'Clásico', 'mls' );
		}
	}
}
