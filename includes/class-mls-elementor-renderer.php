<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integración de render con Elementor.
 *
 * Desde 2.3.0 NO interceptamos `_elementor_data` mediante
 * `get_post_metadata`. Ese filtro era demasiado temprano/global y podía
 * interferir con documentos, templates y caches internos de Elementor.
 *
 * Elementor expone `elementor/frontend/builder_content_data` justo cuando
 * ya tiene el árbol que va a renderizar. Tomamos ESE árbol actual como base
 * y aplicamos únicamente las unidades de texto traducidas.
 *
 * Consecuencia importante: una traducción vieja nunca reemplaza toda la
 * estructura actual por un snapshot viejo. Si el original ganó widgets o
 * secciones nuevas, permanecen presentes; solo sus textos quedan pendientes
 * de traducción hasta la siguiente sincronización.
 */
class MLS_Elementor_Renderer {

	public function __construct() {
		add_filter( 'elementor/frontend/builder_content_data', array( $this, 'filter_builder_content_data' ), 20, 2 );
	}

	/**
	 * @param array $data    Árbol de Elementor que se va a renderizar.
	 * @param int   $post_id Documento/página/template de Elementor.
	 * @return array
	 */
	public function filter_builder_content_data( $data, $post_id ) {
		if ( ! is_array( $data ) || empty( $data ) ) {
			return $data;
		}

		// Invariante principal: una URL fuente jamás recibe traducciones.
		if ( MLS_Language_Context::is_source_request() ) {
			mls_debug_log( 'Elementor renderer post=' . absint( $post_id ) . ': SOURCE -> árbol original.' );
			return $data;
		}

		$lang = MLS_Language_Context::get_current_language();
		if ( ! $lang ) {
			return $data;
		}

		$translation = MLS_DB::get_translation( absint( $post_id ), $lang );
		if ( ! $translation ) {
			// Header/footer/templates sin traducción todavía deben seguir
			// renderizando completos en el idioma fuente, nunca desaparecer.
			mls_debug_log( 'Elementor renderer post=' . absint( $post_id ) . ': sin traducción ' . $lang . ', conserva original.' );
			return $data;
		}

		$units = $this->get_translation_units( $translation );
		if ( empty( $units ) ) {
			mls_debug_log( 'Elementor renderer post=' . absint( $post_id ) . ': traducción sin unidades reutilizables, conserva estructura original.' );
			return $data;
		}

		$translated = MLS_Elementor_Adapter::apply_translations_to_data( $data, $units );
		mls_debug_log(
			'Elementor renderer post=' . absint( $post_id ) . ': lang=' . sanitize_key( $lang ) .
			' unidades=' . count( $units ) . ' aplicadas sobre estructura ORIGINAL ACTUAL.'
		);

		return $translated;
	}

	/**
	 * Obtiene las unidades traducidas. Para filas modernas usa
	 * `translation_units`. Para traducciones antiguas reconstruye el mapa a
	 * partir de `builder_data`, pero aplica esas unidades sobre la estructura
	 * actual; nunca devuelve el snapshot viejo completo.
	 *
	 * @param object $translation
	 * @return array
	 */
	private function get_translation_units( $translation ) {
		if ( ! empty( $translation->translation_units ) ) {
			$units = json_decode( $translation->translation_units, true );
			if ( is_array( $units ) ) {
				return $units;
			}
		}

		if ( ! empty( $translation->builder_data ) ) {
			return MLS_Elementor_Adapter::extract_units( $translation->builder_data );
		}

		return array();
	}
}
