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
	 * Los kits guardan ajustes globales de Elementor, no contenido que se
	 * renderice como una pagina o plantilla.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function is_elementor_kit( $post_id ) {
		return 'kit' === (string) get_post_meta( $post_id, '_elementor_template_type', true );
	}

	/**
	 * Comprueba que un documento Elementor tenga JSON valido y al menos una
	 * unidad de texto antes de enviarlo al proveedor de traduccion.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function has_elementor_text( $post_id ) {
		if ( self::is_elementor_kit( $post_id ) ) {
			return false;
		}
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		return is_string( $raw ) && '' !== trim( $raw ) && is_array( json_decode( $raw, true ) )
			&& ! empty( MLS_Elementor_Adapter::extract_units( $raw ) );
	}

	/**
	 * Tipos de post que Elementor usa para sus "documentos" (cabecera, pie,
	 * plantillas del Theme Builder, popups, landing pages...). Filtrable.
	 *
	 * @return string[]
	 */
	public static function elementor_document_types() {
		return (array) apply_filters( 'mls_elementor_document_types', array(
			'elementor_library',
			'e-landing-page',
			'elementor-hf',        // Elementor Header & Footer Builder (plugin gratuito)
			'elementor_font',
		) );
	}

	/**
	 * ¿Es este post un documento de Elementor (aunque su tipo no sea público
	 * ni esté en la lista de tipos traducibles)?
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function is_elementor_document( $post_id ) {
		if ( ! class_exists( 'MLS_Elementor_Adapter' ) || ! MLS_Elementor_Adapter::is_elementor_post( $post_id ) ) {
			return false;
		}
		$type = get_post_type( $post_id );
		return in_array( $type, self::elementor_document_types(), true )
			|| ! empty( get_post_meta( $post_id, '_elementor_template_type', true ) );
	}

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
