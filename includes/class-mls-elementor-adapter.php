<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Traduce contenido de Elementor sin tocar el original.
 *
 * Elementor guarda toda su estructura en el post_meta `_elementor_data`
 * como un árbol JSON de "elements" -> "settings". Este adaptador:
 *
 *  1. Recorre ese árbol y extrae SOLO los valores de texto humano
 *     (whitelist de claves conocidas + heurística anti-falsos-positivos),
 *     dejando intactos IDs, clases CSS, URLs, colores, tipografías,
 *     configuraciones responsive, dynamic tags, etc.
 *  2. Cada unidad extraída lleva una "ruta" estable (ej.
 *     "root.elements.2.elements.0.settings.title") que identifica
 *     exactamente de dónde vino.
 *  3. Al reconstruir, usa esa misma ruta para reinyectar la traducción
 *     en la posición exacta — nunca reordena ni reescribe estructura.
 *
 * El JSON original en la base de datos de WordPress JAMÁS se modifica;
 * este adaptador siempre trabaja sobre una copia y devuelve un nuevo
 * JSON, que el plugin guarda por separado (ver MLS_DB / columna
 * `builder_data`).
 */
class MLS_Elementor_Adapter {

	/**
	 * Claves de "settings" de Elementor que casi siempre contienen texto
	 * humano, reutilizadas por decenas de widgets distintos (heading,
	 * icon-box, testimonial, accordion, tabs, forms, etc.). Es una lista
	 * genérica por NOMBRE DE CLAVE, no por tipo de widget, para que
	 * funcione con cualquier widget presente o futuro.
	 */
	private static $text_keys = array(
		'title', 'sub_title', 'subtitle', 'description', 'text', 'editor', 'content',
		'html_content', 'button_text', 'btn_text', 'link_text', 'caption', 'title_text',
		'tab_title', 'tab_content', 'item_title', 'item_description',
		'testimonial_content', 'testimonial_name', 'testimonial_job',
		'field_label', 'placeholder', 'before_text', 'after_text',
		'heading_title', 'header_title', 'list_text', 'accordion_title', 'toggle_title',
		'question', 'answer', 'label', 'job_title', 'company', 'quote', 'cta_text',
		'badge_text', 'tooltip_text', 'notice_text', 'alert_text', 'alert_title',
		'alert_description', 'form_name', 'step_next_label', 'step_previous_label',
		'submit_button_text', 'success_message', 'error_message',
	);

	/**
	 * Sufijos genéricos: si una clave termina así, casi siempre es texto
	 * libre aunque el prefijo varíe según el widget o el ítem del repeater
	 * (ej. "faq_question", "slide_title", "price_prefix").
	 */
	private static $text_suffixes = array(
		'_title', '_text', '_content', '_description', '_label', '_caption',
		'_question', '_answer', '_name', '_heading',
	);

	/**
	 * Claves que NUNCA deben tratarse como texto, aunque coincidan con un
	 * sufijo de la lista anterior (ej. "background_image" no es "_image"
	 * en la lista de sufijos, pero por si acaso).
	 */
	private static $blacklist_keys = array(
		'_id', 'id', 'elType', 'widgetType', 'isInner',
		'url', 'link', 'image', 'background_image', 'icon', 'selected_icon',
		'css_classes', '_css_classes', 'custom_css', 'attachment_id', 'source', 'hash',
		'query', 'dynamic', '__dynamic__', 'css_filter', 'shortcode',
		'element_id', '_element_id', 'css_id', 'anchor', 'link_to', 'redirect_url',
		'form_id', 'field_type', 'field_value', 'input_size', 'button_type',
		'__globals__', 'defaultEditingMode',
	);

	/**
	 * ¿Este post usa el editor visual de Elementor?
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function is_elementor_post( $post_id ) {
		$mode = get_post_meta( $post_id, '_elementor_edit_mode', true );
		return 'builder' === $mode;
	}

	/**
	 * Limpia la caché de render/CSS de Elementor usando su propia API
	 * pública (la misma que Elementor usa internamente en su botón
	 * "Regenerar CSS"). Se llama solo después de GUARDAR una traducción
	 * — no en cada petición — como mitigación ante la posibilidad de que
	 * Elementor cachee HTML/CSS renderizado por post_id sin distinguir
	 * idioma. No es un flush global de todo el sitio: apunta a la propia
	 * caché de archivos de Elementor.
	 */
	public static function clear_elementor_render_cache() {
		// Desactivado por defecto: `files_manager->clear_cache()` regenera el
		// CSS de TODO el sitio, lo que contradice el aislamiento del plugin.
		// El aislamiento del Element Cache por idioma (MLS_Elementor_Cache) ya
		// impide que un render EN se sirva en la URL ES. Quien de verdad lo
		// necesite puede reactivarlo con este filtro.
		if ( ! apply_filters( 'mls_clear_elementor_cache_on_save', false ) ) {
			return;
		}
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}
		try {
			$instance = \Elementor\Plugin::$instance;
			if ( $instance && isset( $instance->files_manager ) && method_exists( $instance->files_manager, 'clear_cache' ) ) {
				$instance->files_manager->clear_cache();
			}
		} catch ( \Throwable $e ) {
			// No dejamos que un cambio interno de la API de Elementor rompa el guardado de la traducción.
		}
	}

	/**
	 * Extrae las unidades de texto traducibles del JSON de _elementor_data.
	 *
	 * Cada unidad lleva un "unit_id" ESTABLE (basado en el ID propio del
	 * elemento de Elementor + la clave del setting, y el _id del ítem de
	 * repeater cuando aplica) además de un "path" posicional. El unit_id
	 * es lo que se usa para reinyectar la traducción — sobrevive a que el
	 * usuario reordene widgets en Elementor, porque no depende de la
	 * posición dentro del árbol. El "path" queda solo como referencia
	 * técnica visible en el editor y como respaldo para traducciones
	 * guardadas antes de que existiera unit_id.
	 *
	 * @param string $elementor_data_json
	 * @return array Lista de ['unit_id'=>, 'path'=>, 'key'=>, 'text'=>]
	 */
	public static function extract_units( $elementor_data_json ) {
		$decoded = json_decode( (string) $elementor_data_json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$units = array();
		self::walk_extract( $decoded, 'root', $units, '' );
		return $units;
	}

	/**
	 * Aplica unidades traducidas sobre el ARRAY ACTUAL que Elementor va a
	 * renderizar. Esta es la estrategia preferida desde 2.3.0: el original
	 * actual siempre es la base estructural y solo se sustituyen textos.
	 *
	 * Así, si el editor agregó una sección/widget después de crear la
	 * traducción, esa nueva estructura NO desaparece en /en/. Quedará en el
	 * idioma fuente hasta que se sincronice, pero la página permanece completa.
	 *
	 * @param array $data Elementor builder content data actual.
	 * @param array $translated_units Unidades traducidas persistidas.
	 * @return array
	 */
	public static function apply_translations_to_data( array $data, array $translated_units ) {
		$by_unit_id = array();
		$by_path    = array();

		foreach ( $translated_units as $unit ) {
			$text = isset( $unit['text'] ) ? $unit['text'] : '';
			if ( isset( $unit['unit_id'] ) && '' !== $unit['unit_id'] ) {
				$by_unit_id[ $unit['unit_id'] ] = $text;
			}
			if ( isset( $unit['path'] ) && '' !== $unit['path'] ) {
				$by_path[ $unit['path'] ] = $text;
			}
		}

		self::walk_apply( $data, 'root', $by_unit_id, $by_path, '' );
		return $data;
	}

	/**
	 * Reconstruye el JSON completo sustituyendo cada unidad por su
	 * traducción. Empareja primero por "unit_id" (estable); si una
	 * traducción antigua solo tiene "path" (formato previo), lo usa como
	 * respaldo. Cualquier parte del árbol que no corresponda a una unidad
	 * traducible queda intacta.
	 *
	 * @param string $elementor_data_json JSON original (nunca se modifica el original en BD).
	 * @param array  $translated_units    Lista de ['unit_id'=>, 'path'=>, 'text'=>]
	 * @return string Nuevo JSON, listo para guardarse como traducción.
	 */
	public static function apply_translations( $elementor_data_json, array $translated_units ) {
		$decoded = json_decode( (string) $elementor_data_json, true );
		if ( ! is_array( $decoded ) ) {
			return $elementor_data_json;
		}

		$by_unit_id = array();
		$by_path    = array();
		foreach ( $translated_units as $u ) {
			$text = isset( $u['text'] ) ? $u['text'] : '';
			if ( ! empty( $u['unit_id'] ) ) {
				$by_unit_id[ $u['unit_id'] ] = $text;
			}
			if ( ! empty( $u['path'] ) ) {
				$by_path[ $u['path'] ] = $text;
			}
		}

		self::walk_apply( $decoded, 'root', $by_unit_id, $by_path, '' );

		return wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param mixed  $node
	 * @param string $path        Ruta posicional acumulada (solo para referencia/depuración).
	 * @param array  $units       Acumulador de resultado.
	 * @param string $element_id  ID estable acumulado (id del elemento de Elementor
	 *                            más reciente encontrado, y/o _id de ítem de repeater).
	 */
	private static function walk_extract( $node, $path, array &$units, $element_id ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		// Si este nodo es un elemento de Elementor (tiene su propio "id")
		// o un ítem de repeater (tiene "_id"), lo anclamos como el ID
		// estable para todo lo que cuelgue de él.
		if ( isset( $node['id'] ) && is_string( $node['id'] ) && '' !== $node['id'] ) {
			$element_id = $node['id'];
		} elseif ( isset( $node['_id'] ) && is_string( $node['_id'] ) && '' !== $node['_id'] ) {
			$element_id = $element_id ? $element_id . '.' . $node['_id'] : $node['_id'];
		}

		foreach ( $node as $key => $value ) {
			if ( in_array( $key, self::keys( 'blacklist' ), true ) ) {
				continue; // Se salta tanto si es texto suelto como si es un array anidado (ej. selected_icon.value).
			}
			$current_path = $path . '.' . $key;

			if ( is_string( $value ) ) {
				// Se traduce si (a) la clave está en la whitelist y el valor
				// parece texto, o (b) el valor parece claramente una frase
				// humana aunque la clave no esté en la whitelist (cubre
				// widgets de terceros y claves poco comunes).
				$translate = ( self::is_translatable_key( $key ) && self::looks_like_text( $value ) )
					|| self::looks_like_phrase( $key, $value );

				if ( $translate ) {
					$unit_id = $element_id ? $element_id . '.' . $key : $current_path;
					$units[] = array(
						'unit_id' => $unit_id,
						'path'    => $current_path,
						'key'     => $key,
						'text'    => $value,
					);
				}
			} elseif ( is_array( $value ) ) {
				self::walk_extract( $value, $current_path, $units, $element_id );
			}
		}
	}

	private static function walk_apply( &$node, $path, array $by_unit_id, array $by_path, $element_id ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['id'] ) && is_string( $node['id'] ) && '' !== $node['id'] ) {
			$element_id = $node['id'];
		} elseif ( isset( $node['_id'] ) && is_string( $node['_id'] ) && '' !== $node['_id'] ) {
			$element_id = $element_id ? $element_id . '.' . $node['_id'] : $node['_id'];
		}

		foreach ( $node as $key => &$value ) {
			if ( in_array( $key, self::keys( 'blacklist' ), true ) ) {
				continue;
			}
			$current_path = $path . '.' . $key;

			if ( is_string( $value ) ) {
				$unit_id = $element_id ? $element_id . '.' . $key : $current_path;
				if ( array_key_exists( $unit_id, $by_unit_id ) && '' !== trim( $by_unit_id[ $unit_id ] ) ) {
					$value = $by_unit_id[ $unit_id ];
				} elseif ( array_key_exists( $current_path, $by_path ) && '' !== trim( $by_path[ $current_path ] ) ) {
					$value = $by_path[ $current_path ];
				}
			} elseif ( is_array( $value ) ) {
				self::walk_apply( $value, $current_path, $by_unit_id, $by_path, $element_id );
			}
		}
		unset( $value );
	}

	/**
	 * Listas efectivas de claves, filtrables para dar soporte a widgets de
	 * terceros sin tocar el plugin:
	 *   - mls_elementor_text_keys      (claves exactas que SÍ son texto)
	 *   - mls_elementor_text_suffixes  (sufijos que marcan texto)
	 *   - mls_elementor_blacklist_keys (claves que NUNCA se traducen)
	 *
	 * @param string $which
	 * @return string[]
	 */
	private static function keys( $which ) {
		switch ( $which ) {
			case 'text':
				return (array) apply_filters( 'mls_elementor_text_keys', self::$text_keys );
			case 'suffixes':
				return (array) apply_filters( 'mls_elementor_text_suffixes', self::$text_suffixes );
			case 'blacklist':
			default:
				return (array) apply_filters( 'mls_elementor_blacklist_keys', self::$blacklist_keys );
		}
	}

	private static function is_translatable_key( $key ) {
		if ( in_array( $key, self::keys( 'blacklist' ), true ) ) {
			return false;
		}
		if ( in_array( $key, self::keys( 'text' ), true ) ) {
			return true;
		}
		foreach ( self::keys( 'suffixes' ) as $suffix ) {
			if ( strlen( $key ) > strlen( $suffix ) && substr( $key, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sufijos de clave claramente TÉCNICOS: si la clave termina así, su
	 * valor no se traduce aunque parezca una frase.
	 */
	private static $technical_suffixes = array(
		'_id', '_key', '_type', '_size', '_width', '_height', '_align', '_position',
		'_color', '_bg', '_background', '_url', '_link', '_class', '_classes',
		'_animation', '_delay', '_duration', '_icon', '_image', '_src', '_target',
		'_tag', '_zoom', '_lat', '_lng', '_order', '_ratio', '_opacity', '_offset',
		'_gap', '_radius', '_border', '_shadow', '_filter', '_selector', '_hover',
		'_status', '_state', '_mode', '_layout', '_style', '_effect', '_repeat',
		'_direction', '_blend', '_clip', '_unit', '_value', '_name_type',
	);

	/**
	 * ¿El VALOR parece una frase humana real? (clave no técnica + contiene
	 * espacios o termina en puntuación de frase + tiene letras).
	 *
	 * @param string $key
	 * @param string $value
	 * @return bool
	 */
	private static function looks_like_phrase( $key, $value ) {
		if ( in_array( $key, self::keys( 'blacklist' ), true ) ) {
			return false;
		}
		$lk = strtolower( (string) $key );
		foreach ( self::$technical_suffixes as $suffix ) {
			if ( strlen( $lk ) >= strlen( $suffix ) && substr( $lk, -strlen( $suffix ) ) === $suffix ) {
				return false;
			}
		}

		$t = trim( wp_strip_all_tags( (string) $value ) );
		if ( '' === $t || strlen( $t ) > 8000 ) {
			return false;
		}
		if ( preg_match( '#^(https?://|/|\#|\[|\{|globals://|data:|rgba?\(|var\(|\d)#i', $t ) ) {
			return false; // URL, ruta, shortcode, JSON, color, número...
		}
		if ( ! preg_match( '/\p{L}{2,}/u', $t ) ) {
			return false; // Sin al menos una "palabra" con 2+ letras.
		}
		// Frase: tiene espacio interno, o termina en signo de puntuación.
		return (bool) preg_match( '/\S\s+\S/u', $t ) || (bool) preg_match( '/[.!?…:,;]$/u', $t );
	}

	/**
	 * Heurística de seguridad adicional: descarta valores que, aunque su
	 * clave "parezca" texto, claramente no lo son (URLs, colores hex,
	 * slugs técnicos cortos sin espacios).
	 */
	private static function looks_like_text( $value ) {
		$trimmed = trim( (string) $value );
		if ( '' === $trimmed ) {
			return false;
		}
		if ( strlen( $trimmed ) > 8000 ) {
			return false; // Demasiado largo para ser un campo de texto de UI normal.
		}
		if ( preg_match( '/^https?:\/\//i', $trimmed ) ) {
			return false;
		}
		if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $trimmed ) ) {
			return false;
		}
		// Token corto sin espacios ni mayúsculas: probablemente un slug/ID técnico
		// (ej. "primary-button", "col-md-6"), no una frase real.
		if ( strlen( $trimmed ) < 30 && ! preg_match( '/\s/', $trimmed ) && preg_match( '/^[a-z0-9\-_]+$/', $trimmed ) ) {
			return false;
		}
		return true;
	}
}
