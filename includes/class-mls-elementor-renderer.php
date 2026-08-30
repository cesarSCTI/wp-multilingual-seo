<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integración de render con Elementor.
 *
 * Estrategia principal (3.0.2): en una petición de frontend traducida se
 * intercepta `get_post_metadata` para `_elementor_data` y se devuelve el
 * JSON con los textos ya traducidos. Es la misma técnica que usan WPML y
 * Polylang y tiene ventajas decisivas:
 *
 *   - Funciona para CUALQUIER documento de Elementor: página, entrada,
 *     cabecera, pie, plantillas del Theme Builder, popups.
 *   - Elementor renderiza (y cachea) a partir de ese valor, así que el HTML
 *     y el CSS cacheados por idioma quedan correctos.
 *   - No depende de un filtro concreto del ciclo de render (que cambia entre
 *     versiones de Elementor).
 *
 * El `_elementor_data` ORIGINAL en la base de datos nunca se modifica: el
 * intercambio ocurre solo en memoria, solo en `!is_admin()` y solo cuando
 * `MLS_Language_Context` confirma que la URL lleva prefijo de idioma.
 *
 * Como respaldo se mantiene el filtro `elementor/frontend/builder_content_data`
 * por si algún flujo lee el árbol sin pasar por `get_post_meta`.
 */
class MLS_Elementor_Renderer {

	/** Reentrada: evita bucle infinito al pedir el meta original dentro del filtro. */
	private static $reentry = false;

	/** post_id => true cuando el meta swap ya tradujo ese documento. */
	private $swapped = array();

	/**
	 * Diagnóstico de esta petición: documento Elementor => nº de unidades
	 * aplicadas. Lo lee la barra de depuración.
	 *
	 * @var array<int,int>
	 */
	public static $applied = array();

	public function __construct() {
		add_filter( 'get_post_metadata', array( $this, 'swap_elementor_data' ), 10, 4 );
		add_filter( 'elementor/frontend/builder_content_data', array( $this, 'filter_render_tree' ), 20, 2 );

		// En /en/ Elementor NO debe ESCRIBIR su caché de HTML documental: si
		// lo hiciera, guardaría el render en inglés bajo el mismo post_id y
		// la página fuente / acabaría sirviéndolo. Se cancela esa escritura.
		add_filter( 'update_post_metadata', array( $this, 'block_element_cache_write' ), 10, 3 );
		add_filter( 'add_post_metadata', array( $this, 'block_element_cache_write' ), 10, 3 );
	}

	/**
	 * @param mixed  $check
	 * @param int    $object_id
	 * @param string $meta_key
	 * @return mixed  false cancela la escritura; null la deja seguir.
	 */
	public function block_element_cache_write( $check, $object_id, $meta_key ) {
		if ( '_elementor_element_cache' === $meta_key && $this->active() ) {
			return false;
		}
		return $check;
	}

	/**
	 * ¿Debe traducirse el render en esta petición?
	 */
	private function active() {
		if ( self::$reentry || is_admin() ) {
			return false;
		}
		if ( wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		// El preview del editor de Elementor se sirve desde la URL de origen,
		// así que is_translation_request() ya es false ahí.
		return MLS_Language_Context::is_translation_request();
	}

	/**
	 * @param mixed  $value
	 * @param int    $object_id
	 * @param string $meta_key
	 * @param bool   $single
	 * @return mixed
	 */
	public function swap_elementor_data( $value, $object_id, $meta_key, $single ) {
		if ( '_elementor_data' !== $meta_key && '_elementor_element_cache' !== $meta_key ) {
			return $value;
		}
		if ( ! $this->active() ) {
			return $value;
		}

		// En una petición traducida, Elementor NO debe usar su caché de HTML
		// documental (`_elementor_element_cache`): esa meta es por post_id, no
		// distingue idioma, y devolvería el HTML fuente (o uno envenenado) en
		// /en/. Se le devuelve "sin caché" para que renderice fresco a partir
		// del `_elementor_data` ya traducido.
		if ( '_elementor_element_cache' === $meta_key ) {
			return $single ? '' : array();
		}

		$post_id = (int) $object_id;
		$lang    = MLS_Language_Context::get_current_language();
		$json    = $this->translated_json( $post_id, $lang );

		if ( null === $json ) {
			return $value;
		}

		$this->swapped[ $post_id ]  = true;
		self::$applied[ $post_id ]  = count( $this->units_for( $post_id, $lang ) );
		mls_debug_log( 'Elementor: _elementor_data intercambiado para post=' . $post_id . ' lang=' . sanitize_key( $lang ) . ' unidades=' . self::$applied[ $post_id ] );

		// El filtro get_post_metadata: devolver un array de valores; para
		// $single WordPress toma el [0].
		return array( $json );
	}

	/**
	 * Respaldo: si algo obtuvo el árbol sin pasar por `get_post_meta`, se
	 * traduce aquí sobre la estructura viva.
	 *
	 * @param array $data
	 * @param int   $post_id
	 * @return array
	 */
	public function filter_render_tree( $data, $post_id ) {
		if ( ! is_array( $data ) || empty( $data ) || ! $this->active() ) {
			return $data;
		}
		if ( ! empty( $this->swapped[ (int) $post_id ] ) ) {
			return $data; // Ya venía traducido por el meta swap.
		}

		$lang  = MLS_Language_Context::get_current_language();
		$units = $this->units_for( (int) $post_id, $lang );
		if ( empty( $units ) ) {
			return $data;
		}

		self::$applied[ (int) $post_id ] = count( $units );
		mls_debug_log( 'Elementor (builder_content_data): post=' . (int) $post_id . ' unidades=' . count( $units ) );
		return MLS_Elementor_Adapter::apply_translations_to_data( $data, $units );
	}

	/**
	 * Devuelve el JSON de `_elementor_data` con los textos traducidos, o null
	 * si no hay traducción servible para este documento.
	 *
	 * @param int    $post_id
	 * @param string $lang
	 * @return string|null
	 */
	private function translated_json( $post_id, $lang ) {
		$units = $this->units_for( $post_id, $lang );
		if ( empty( $units ) ) {
			return null;
		}

		self::$reentry = true;
		$source = get_post_meta( $post_id, '_elementor_data', true );
		self::$reentry = false;

		if ( ! is_string( $source ) || '' === trim( $source ) ) {
			return null;
		}

		$translated = MLS_Elementor_Adapter::apply_translations( $source, $units );

		// Seguridad: si el resultado no es JSON válido, se conserva el original.
		if ( ! is_string( $translated ) || null === json_decode( $translated ) ) {
			mls_debug_log( 'Elementor: JSON traducido inválido para post=' . $post_id . ', se conserva el original.', true );
			return null;
		}

		return $translated;
	}

	/**
	 * Unidades traducidas de un documento Elementor (o vacío).
	 *
	 * @param int    $post_id
	 * @param string $lang
	 * @return array
	 */
	private function units_for( $post_id, $lang ) {
		$tr = MLS_DB::get_translation( $post_id, $lang );
		if ( ! MLS_DB::is_servable( $tr ) ) {
			return array();
		}

		if ( ! empty( $tr->translation_units ) ) {
			$u = json_decode( $tr->translation_units, true );
			if ( is_array( $u ) && ! empty( $u ) ) {
				return $u;
			}
		}
		if ( ! empty( $tr->builder_data ) ) {
			return MLS_Elementor_Adapter::extract_units( $tr->builder_data );
		}
		return array();
	}
}
