<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Traduce contenido usando la API de Google Cloud Translation (v2, REST)
 * y guarda el resultado en la tabla de traducciones.
 */
class MLS_Translator {

	/**
	 * Enganchado en save_post: en lugar de traducir en el momento (lo que
	 * ralentizaría el guardado del editor), programamos un evento de
	 * WP-Cron por cada idioma destino.
	 */
	public static function maybe_schedule_translation( $post_id, $post, $update ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$settings = mls_get_settings();

		if ( empty( $settings['auto_translate'] ) || empty( $settings['api_key'] ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! in_array( $post->post_type, (array) $settings['post_types'], true ) ) {
			return;
		}

		foreach ( (array) $settings['target_langs'] as $lang ) {
			$lang = sanitize_key( $lang );
			if ( ! $lang || $lang === $settings['source_lang'] ) {
				continue;
			}
			if ( ! wp_next_scheduled( 'mls_translate_post_event', array( $post_id, $lang ) ) ) {
				wp_schedule_single_event( time() + 5, 'mls_translate_post_event', array( $post_id, $lang ) );
			}
		}

		// Forzamos a WP-Cron a despertar de inmediato en vez de esperar a que
		// alguien visite el sitio (comportamiento por defecto de WordPress).
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Traduce un post a un idioma concreto y guarda el resultado.
	 * Detecta con qué constructor está hecho el contenido (Elementor,
	 * Gutenberg o clásico) y delega en el flujo correspondiente — cada
	 * uno sabe cómo extraer y reinyectar texto sin romper su estructura.
	 *
	 * @param int    $post_id
	 * @param string $lang
	 * @return true|WP_Error
	 */
	public static function translate_and_save( $post_id, $lang, $force = false ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'mls_no_post', __( 'El post no existe.', 'mls' ) );
		}

		// Si el usuario ya editó esta traducción a mano, no la pisamos
		// con una nueva traducción automática (salvo que se pida explícitamente).
		$existing = MLS_DB::get_translation( $post_id, $lang );
		if ( $existing && 'manual' === $existing->status && ! $force ) {
			return true;
		}

		$builder = MLS_Content_Resolver::detect_builder( $post_id );

		if ( MLS_Content_Resolver::BUILDER_ELEMENTOR === $builder ) {
			return self::translate_elementor( $post_id, $lang, $post );
		}

		if ( MLS_Content_Resolver::BUILDER_GUTENBERG === $builder ) {
			return self::translate_units_based( $post_id, $lang, $post, $builder );
		}

		return self::translate_classic( $post_id, $lang, $post );
	}

	/**
	 * Flujo clásico (HTML sin bloques Gutenberg): se mantiene el enfoque
	 * anterior de traducir el HTML completo de una sola vez con Google.
	 */
	private static function translate_classic( $post_id, $lang, $post ) {
		$settings = mls_get_settings();
		$source   = $settings['source_lang'];

		list( $content_placeholder, $shortcodes ) = self::protect_shortcodes( $post->post_content );

		$translated_title = self::call_api( $post->post_title, $lang, $source, 'text' );
		if ( is_wp_error( $translated_title ) ) {
			return $translated_title;
		}

		$translated_content = self::call_api( $content_placeholder, $lang, $source, 'html' );
		if ( is_wp_error( $translated_content ) ) {
			return $translated_content;
		}
		$translated_content = self::restore_shortcodes( $translated_content, $shortcodes );

		$source_excerpt     = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 );
		$translated_excerpt = self::call_api( $source_excerpt, $lang, $source, 'text' );
		if ( is_wp_error( $translated_excerpt ) ) {
			$translated_excerpt = '';
		}

		$slug              = MLS_DB::generate_unique_slug( $translated_title, $lang, $post_id );
		$meta_description  = wp_trim_words( wp_strip_all_tags( $translated_excerpt ? $translated_excerpt : $translated_content ), 25 );
		$blocks            = MLS_Content_Blocks::parse_html_to_blocks( $translated_content );

		MLS_DB::save_translation( array(
			'post_id'          => $post_id,
			'language'         => $lang,
			'post_title'       => $translated_title,
			'post_content'     => $translated_content,
			'content_blocks'   => wp_json_encode( $blocks ),
			'post_excerpt'     => $translated_excerpt,
			'post_slug'        => $slug,
			'meta_title'       => $translated_title,
			'meta_description' => $meta_description,
			'status'           => 'auto',
			'builder'          => 'classic',
			'source_hash'      => MLS_DB::compute_source_hash( $post_id ),
		) );

		return true;
	}

	/**
	 * Flujo para Gutenberg: extrae unidades con parse_blocks(), traduce
	 * todas de una vez (lote) y reconstruye con serialize_blocks(), así
	 * que los comentarios <!-- wp:... --> quedan intactos.
	 */
	private static function translate_units_based( $post_id, $lang, $post, $builder ) {
		$settings = mls_get_settings();
		$source   = $settings['source_lang'];

		$units = MLS_Gutenberg_Adapter::extract_units( $post->post_content );

		$translated_title = self::call_api( $post->post_title, $lang, $source, 'text' );
		if ( is_wp_error( $translated_title ) ) {
			return $translated_title;
		}

		$translated_units = self::translate_units_batch( $units, $lang, $source );
		if ( is_wp_error( $translated_units ) ) {
			return $translated_units;
		}

		$translated_content = MLS_Gutenberg_Adapter::apply_translations( $post->post_content, $translated_units );

		$source_excerpt     = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 );
		$translated_excerpt = self::call_api( $source_excerpt, $lang, $source, 'text' );
		if ( is_wp_error( $translated_excerpt ) ) {
			$translated_excerpt = '';
		}

		$slug             = MLS_DB::generate_unique_slug( $translated_title, $lang, $post_id );
		$meta_description = wp_trim_words( wp_strip_all_tags( $translated_excerpt ? $translated_excerpt : $translated_content ), 25 );
		$blocks           = MLS_Content_Blocks::parse_html_to_blocks( $translated_content );

		MLS_DB::save_translation( array(
			'post_id'           => $post_id,
			'language'          => $lang,
			'post_title'        => $translated_title,
			'post_content'      => $translated_content,
			'content_blocks'    => wp_json_encode( $blocks ),
			'post_excerpt'      => $translated_excerpt,
			'post_slug'         => $slug,
			'meta_title'        => $translated_title,
			'meta_description'  => $meta_description,
			'status'            => 'auto',
			'builder'           => $builder,
			'translation_units' => wp_json_encode( $translated_units ),
			'source_hash'       => MLS_DB::compute_source_hash( $post_id ),
		) );

		return true;
	}

	/**
	 * Flujo para Elementor: extrae las unidades de texto de
	 * `_elementor_data`, las traduce en lote, y guarda un JSON traducido
	 * COMPLETO por separado (nunca se toca el `_elementor_data` original
	 * del post en español). El frontend lo sirve mediante el filtro
	 * `elementor/frontend/builder_content_data` (ver MLS_Elementor_Renderer).
	 */
	private static function translate_elementor( $post_id, $lang, $post ) {
		$raw_json = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! $raw_json ) {
			return new WP_Error( 'mls_no_elementor_data', __( 'La página no tiene datos de Elementor todavía (¿se guardó al menos una vez desde el editor?).', 'mls' ) );
		}

		$settings = mls_get_settings();
		$source   = $settings['source_lang'];

		$units = MLS_Elementor_Adapter::extract_units( $raw_json );

		$translated_title = self::call_api( $post->post_title, $lang, $source, 'text' );
		if ( is_wp_error( $translated_title ) ) {
			return $translated_title;
		}

		$translated_units = self::translate_units_batch( $units, $lang, $source );
		if ( is_wp_error( $translated_units ) ) {
			return $translated_units;
		}

		$translated_json = MLS_Elementor_Adapter::apply_translations( $raw_json, $translated_units );

		$slug = MLS_DB::generate_unique_slug( $translated_title, $lang, $post_id );

		$sample_text      = implode( ' ', wp_list_pluck( $translated_units, 'text' ) );
		$meta_description = wp_trim_words( wp_strip_all_tags( $sample_text ), 25 );

		MLS_DB::save_translation( array(
			'post_id'           => $post_id,
			'language'          => $lang,
			'post_title'        => $translated_title,
			'post_content'      => '', // Elementor no usa post_content para renderizar.
			'post_excerpt'      => $post->post_excerpt ? self::maybe_translate_text( $post->post_excerpt, $lang, $source ) : '',
			'post_slug'         => $slug,
			'meta_title'        => $translated_title,
			'meta_description'  => $meta_description,
			'status'            => 'auto',
			'builder'           => 'elementor',
			'builder_data'      => $translated_json,
			'translation_units' => wp_json_encode( $translated_units ),
			'source_hash'       => MLS_DB::compute_source_hash( $post_id ),
		) );

		MLS_Elementor_Adapter::clear_elementor_render_cache();

		return true;
	}

	private static function maybe_translate_text( $text, $lang, $source ) {
		$result = self::call_api( $text, $lang, $source, 'text' );
		return is_wp_error( $result ) ? '' : $result;
	}

	/**
	 * Traduce una lista de unidades ['path'=>, 'text'=>, ...] en una sola
	 * llamada (o pocas, si hay que dividir en lotes por límites de la
	 * API), preservando el orden y el "path" de cada una.
	 *
	 * @param array  $units
	 * @param string $lang
	 * @param string $source
	 * @return array|WP_Error
	 */
	private static function translate_units_batch( array $units, $lang, $source ) {
		if ( empty( $units ) ) {
			return array();
		}

		$translated = array();

		foreach ( array_chunk( $units, 100, true ) as $chunk ) {
			$texts  = wp_list_pluck( $chunk, 'text' );
			$result = self::call_api_batch( $texts, $lang, $source );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$i = 0;
			foreach ( $chunk as $unit ) {
				$translated[] = array(
					'unit_id' => isset( $unit['unit_id'] ) ? $unit['unit_id'] : '',
					'path'    => isset( $unit['path'] ) ? $unit['path'] : '',
					'key'     => isset( $unit['key'] ) ? $unit['key'] : '',
					'text'    => isset( $result[ $i ] ) ? $result[ $i ] : $unit['text'],
				);
				$i++;
			}
		}

		return $translated;
	}

	/**
	 * Llamada REST a Google Cloud Translation API v2.
	 *
	 * @param string $text
	 * @param string $target
	 * @param string $source
	 * @param string $format 'text' o 'html'
	 * @return string|WP_Error
	 */
	private static function call_api( $text, $target, $source, $format = 'text' ) {
		$settings = mls_get_settings();
		$api_key  = $settings['api_key'];

		if ( empty( $api_key ) ) {
			return new WP_Error( 'mls_no_key', __( 'Falta configurar la API key de Google en los ajustes del plugin.', 'mls' ) );
		}

		if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
			return $text;
		}

		$endpoint = 'https://translation.googleapis.com/language/translate/v2?key=' . rawurlencode( $api_key );

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 30,
			'body'    => array(
				'q'      => $text,
				'source' => $source,
				'target' => $target,
				'format' => $format,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $body['data']['translations'][0]['translatedText'] ) ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Error desconocido al llamar a la API de Google Translate.', 'mls' );
			return new WP_Error( 'mls_api_error', $message );
		}

		return $body['data']['translations'][0]['translatedText'];
	}

	/**
	 * Traduce varios textos en una sola llamada a la API de Google
	 * (parámetro "q" repetido), en vez de una llamada por texto — mucho
	 * más rápido y barato para páginas con decenas de unidades de texto
	 * (ej. una página de Elementor con muchos widgets).
	 *
	 * @param array  $texts
	 * @param string $target
	 * @param string $source
	 * @return array|WP_Error Traducciones en el mismo orden que $texts.
	 */
	private static function call_api_batch( array $texts, $target, $source ) {
		if ( empty( $texts ) ) {
			return array();
		}

		$settings = mls_get_settings();
		$api_key  = $settings['api_key'];

		if ( empty( $api_key ) ) {
			return new WP_Error( 'mls_no_key', __( 'Falta configurar la API key de Google en los ajustes del plugin.', 'mls' ) );
		}

		$endpoint = 'https://translation.googleapis.com/language/translate/v2?key=' . rawurlencode( $api_key );

		// Construimos el cuerpo a mano (en vez de pasar un array a wp_remote_post)
		// para garantizar el formato exacto que espera Google: parámetro "q"
		// repetido una vez por texto, no "q[0]", "q[1]"...
		$pairs = array();
		foreach ( $texts as $text ) {
			$pairs[] = 'q=' . rawurlencode( (string) $text );
		}
		$pairs[]     = 'source=' . rawurlencode( $source );
		$pairs[]     = 'target=' . rawurlencode( $target );
		$pairs[]     = 'format=text';
		$body_string = implode( '&', $pairs );

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 60,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => $body_string,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $body['data']['translations'] ) ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Error desconocido al llamar a la API de Google Translate.', 'mls' );
			return new WP_Error( 'mls_api_error', $message );
		}

		$out = array();
		foreach ( $body['data']['translations'] as $t ) {
			$out[] = isset( $t['translatedText'] ) ? $t['translatedText'] : '';
		}
		return $out;
	}

	/**
	 * Sustituye los shortcodes de WordPress por marcadores de posición
	 * antes de enviar el texto a traducir, para no romper su sintaxis.
	 *
	 * @param string $content
	 * @return array [contenido_con_marcadores, mapa_de_shortcodes]
	 */
	private static function protect_shortcodes( $content ) {
		$pattern    = get_shortcode_regex();
		$shortcodes = array();
		$index      = 0;

		$replaced = preg_replace_callback( "/{$pattern}/", function ( $matches ) use ( &$shortcodes, &$index ) {
			$token                = '[[[MLS_SHORTCODE_' . $index . ']]]';
			$shortcodes[ $token ] = $matches[0];
			$index++;
			return $token;
		}, $content );

		return array( $replaced, $shortcodes );
	}

	/**
	 * Restaura los shortcodes originales tras la traducción.
	 *
	 * @param string $content
	 * @param array  $shortcodes
	 * @return string
	 */
	private static function restore_shortcodes( $content, $shortcodes ) {
		if ( empty( $shortcodes ) ) {
			return $content;
		}
		return strtr( $content, $shortcodes );
	}
}
