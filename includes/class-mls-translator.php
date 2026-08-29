<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orquesta la traducción de un post: detecta el constructor, extrae las
 * unidades de texto con el adaptador adecuado, las traduce en lotes a
 * través del proveedor activo y guarda el resultado en la tabla propia.
 *
 * El proveedor (Google por defecto) es intercambiable con el filtro
 * `mls_translation_provider`.
 */
class MLS_Translator {

	/** @var MLS_Translation_Provider|null */
	private static $provider = null;

	/**
	 * Proveedor de traducción activo.
	 *
	 * @return MLS_Translation_Provider
	 */
	public static function provider() {
		if ( null === self::$provider ) {
			/**
			 * @param MLS_Translation_Provider $provider
			 */
			self::$provider = apply_filters( 'mls_translation_provider', new MLS_Google_Provider() );
		}
		return self::$provider;
	}

	/**
	 * Enganchado en save_post: encola (no traduce en el acto, para no
	 * ralentizar el editor) la traducción a cada idioma destino.
	 */
	public static function maybe_schedule_translation( $post_id, $post, $update ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$settings = mls_get_settings();

		if ( ! in_array( $post->post_type, (array) $settings['post_types'], true ) ) {
			return;
		}

		// Aunque no se traduzca automáticamente, sí marcamos como
		// "desactualizadas" las traducciones existentes cuyo origen cambió.
		if ( 'publish' === $post->post_status ) {
			MLS_DB::flag_outdated_for_post( $post_id );
		}

		if ( empty( $settings['auto_translate'] ) || empty( $settings['api_key'] ) || 'publish' !== $post->post_status ) {
			return;
		}

		foreach ( array_keys( MLS_Language_Registry::targets() ) as $lang ) {
			MLS_Queue::enqueue( $post_id, $lang, 5 );
		}
	}

	/**
	 * Traduce un post a un idioma y guarda el resultado. Devuelve true si se
	 * guardó una traducción completa, o WP_Error si algo falló (en cuyo caso
	 * NO se ha publicado nada a medias).
	 *
	 * @param int    $post_id
	 * @param string $lang
	 * @param bool   $force  Sobrescribir incluso una traducción manual.
	 * @return true|WP_Error
	 */
	public static function translate_and_save( $post_id, $lang, $force = false ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'mls_no_post', __( 'El post no existe.', 'mls' ) );
		}

		$existing = MLS_DB::get_translation( $post_id, $lang );
		if ( $existing && MLS_DB::STATUS_MANUAL === (string) $existing->status && ! $force ) {
			return true;
		}

		$builder = MLS_Content_Resolver::detect_builder( $post_id );

		if ( MLS_Content_Resolver::BUILDER_ELEMENTOR === $builder ) {
			return self::translate_elementor( $post_id, $lang, $post, $force );
		}
		if ( MLS_Content_Resolver::BUILDER_GUTENBERG === $builder ) {
			return self::translate_units_based( $post_id, $lang, $post, $builder, $force );
		}
		return self::translate_classic( $post_id, $lang, $post, $force );
	}

	private static function final_status( $force ) {
		return $force ? MLS_DB::STATUS_MANUAL : MLS_DB::STATUS_PUBLISHED;
	}

	/**
	 * Flujo clásico: se parsea el HTML a nodos de texto con el adaptador DOM
	 * y solo se traducen esos nodos (y atributos seguros), preservando
	 * shortcodes, scripts, estilos, URLs, IDs y clases.
	 */
	private static function translate_classic( $post_id, $lang, $post, $force = false ) {
		$source = MLS_Language_Registry::source();
		$src    = $source['code'];

		$title = self::translate_text( $post->post_title, $src, $lang, 'text' );
		if ( is_wp_error( $title ) ) {
			return $title;
		}

		list( $protected, $shortcodes ) = self::protect_shortcodes( $post->post_content );

		$units            = MLS_Classic_Adapter::extract_units( $protected );
		$translated_units = self::translate_units_batch( $units, $lang, $src );
		if ( is_wp_error( $translated_units ) ) {
			return $translated_units;
		}
		$translated_content = MLS_Classic_Adapter::apply_translations( $protected, $translated_units );
		$translated_content = self::restore_shortcodes( $translated_content, $shortcodes );

		$src_excerpt = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 );
		$excerpt     = self::translate_text( $src_excerpt, $src, $lang, 'text' );
		if ( is_wp_error( $excerpt ) ) {
			$excerpt = '';
		}

		$slug   = MLS_DB::generate_unique_slug( $title, $lang, $post_id );
		$blocks = MLS_Content_Blocks::parse_html_to_blocks( $translated_content );

		MLS_DB::save_translation( array(
			'post_id'           => $post_id,
			'language'          => $lang,
			'post_title'        => $title,
			'post_content'      => $translated_content,
			'content_blocks'    => wp_json_encode( $blocks ),
			'post_excerpt'      => $excerpt,
			'post_slug'         => $slug,
			'translated_path'   => MLS_Url::compute_path( $post_id, $lang, $slug ),
			'meta_title'        => $title,
			'meta_description'  => wp_trim_words( wp_strip_all_tags( $excerpt ? $excerpt : $translated_content ), 25 ),
			'status'            => self::final_status( $force ),
			'builder'           => 'classic',
			'translation_units' => wp_json_encode( $translated_units ),
			'source_hash'       => MLS_DB::compute_source_hash( $post_id ),
		) );

		return true;
	}

	/**
	 * Flujo Gutenberg: unidades vía parse_blocks(), traducción por lotes,
	 * reconstrucción con serialize_blocks() y validación del resultado.
	 */
	private static function translate_units_based( $post_id, $lang, $post, $builder, $force = false ) {
		$source = MLS_Language_Registry::source();
		$src    = $source['code'];

		$units = MLS_Gutenberg_Adapter::extract_units( $post->post_content );

		$title = self::translate_text( $post->post_title, $src, $lang, 'text' );
		if ( is_wp_error( $title ) ) {
			return $title;
		}

		$translated_units = self::translate_units_batch( $units, $lang, $src );
		if ( is_wp_error( $translated_units ) ) {
			return $translated_units;
		}

		$translated_content = MLS_Gutenberg_Adapter::apply_translations( $post->post_content, $translated_units );

		// Validación: el HTML reconstruido debe seguir parseando a bloques.
		if ( function_exists( 'parse_blocks' ) ) {
			$reparsed = parse_blocks( $translated_content );
			if ( empty( $reparsed ) ) {
				return new WP_Error( 'mls_gutenberg_invalid', __( 'El contenido Gutenberg traducido no se pudo reconstruir de forma válida.', 'mls' ) );
			}
		}

		$src_excerpt = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 );
		$excerpt     = self::translate_text( $src_excerpt, $src, $lang, 'text' );
		if ( is_wp_error( $excerpt ) ) {
			$excerpt = '';
		}

		$slug   = MLS_DB::generate_unique_slug( $title, $lang, $post_id );
		$blocks = MLS_Content_Blocks::parse_html_to_blocks( $translated_content );

		MLS_DB::save_translation( array(
			'post_id'           => $post_id,
			'language'          => $lang,
			'post_title'        => $title,
			'post_content'      => $translated_content,
			'content_blocks'    => wp_json_encode( $blocks ),
			'post_excerpt'      => $excerpt,
			'post_slug'         => $slug,
			'translated_path'   => MLS_Url::compute_path( $post_id, $lang, $slug ),
			'meta_title'        => $title,
			'meta_description'  => wp_trim_words( wp_strip_all_tags( $excerpt ? $excerpt : $translated_content ), 25 ),
			'status'            => self::final_status( $force ),
			'builder'           => $builder,
			'translation_units' => wp_json_encode( $translated_units ),
			'source_hash'       => MLS_DB::compute_source_hash( $post_id ),
		) );

		return true;
	}

	/**
	 * Flujo Elementor: unidades de `_elementor_data`, traducción por lotes,
	 * y guardado de un JSON traducido separado. El `_elementor_data`
	 * original NUNCA se toca.
	 */
	private static function translate_elementor( $post_id, $lang, $post, $force = false ) {
		$raw_json = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! $raw_json ) {
			return new WP_Error( 'mls_no_elementor_data', __( 'La página no tiene datos de Elementor todavía (¿se guardó al menos una vez desde el editor?).', 'mls' ) );
		}

		$source = MLS_Language_Registry::source();
		$src    = $source['code'];

		$units = MLS_Elementor_Adapter::extract_units( $raw_json );

		$title = self::translate_text( $post->post_title, $src, $lang, 'text' );
		if ( is_wp_error( $title ) ) {
			return $title;
		}

		$translated_units = self::translate_units_batch( $units, $lang, $src );
		if ( is_wp_error( $translated_units ) ) {
			return $translated_units;
		}

		$translated_json = MLS_Elementor_Adapter::apply_translations( $raw_json, $translated_units );
		if ( ! is_string( $translated_json ) || null === json_decode( $translated_json ) ) {
			return new WP_Error( 'mls_elementor_invalid', __( 'El JSON de Elementor traducido no es válido.', 'mls' ) );
		}

		$slug = MLS_DB::generate_unique_slug( $title, $lang, $post_id );

		$excerpt = '';
		if ( $post->post_excerpt ) {
			$e       = self::translate_text( $post->post_excerpt, $src, $lang, 'text' );
			$excerpt = is_wp_error( $e ) ? '' : $e;
		}

		$sample = implode( ' ', wp_list_pluck( $translated_units, 'text' ) );

		MLS_DB::save_translation( array(
			'post_id'           => $post_id,
			'language'          => $lang,
			'post_title'        => $title,
			'post_content'      => '',
			'post_excerpt'      => $excerpt,
			'post_slug'         => $slug,
			'translated_path'   => MLS_Url::compute_path( $post_id, $lang, $slug ),
			'meta_title'        => $title,
			'meta_description'  => wp_trim_words( wp_strip_all_tags( $sample ), 25 ),
			'status'            => self::final_status( $force ),
			'builder'           => 'elementor',
			'builder_data'      => $translated_json,
			'translation_units' => wp_json_encode( $translated_units ),
			'source_hash'       => MLS_DB::compute_source_hash( $post_id ),
		) );

		MLS_Elementor_Adapter::clear_elementor_render_cache();

		return true;
	}

	/**
	 * Traduce un único texto.
	 *
	 * @return string|WP_Error
	 */
	public static function translate_text( $text, $source, $target, $format = 'text' ) {
		if ( '' === trim( wp_strip_all_tags( (string) $text ) ) ) {
			return (string) $text;
		}
		$out = self::provider()->translate_batch( array( (string) $text ), $source, $target, $format );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		return isset( $out[0] ) ? $out[0] : (string) $text;
	}

	/**
	 * Traduce una lista de unidades `['text'=>, 'path'=>, ...]` preservando
	 * el orden y las claves de identificación. Divide en lotes por número de
	 * segmentos Y por número de caracteres (lo que se alcance primero), y
	 * separa las unidades HTML de las de texto plano para no escapar el
	 * marcado.
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

		$provider  = self::provider();
		$max_items = max( 1, $provider->max_items_per_request() );
		$max_chars = max( 500, $provider->max_chars_per_request() );

		// Índice -> traducción.
		$result = array();

		// Se agrupan por formato y se trocea cada grupo por límites.
		$groups = array( 'text' => array(), 'html' => array() );
		foreach ( $units as $idx => $unit ) {
			$groups[ self::unit_format( $unit ) ][ $idx ] = (string) $unit['text'];
		}

		foreach ( $groups as $format => $indexed ) {
			if ( empty( $indexed ) ) {
				continue;
			}

			$batch_idx   = array();
			$batch_texts = array();
			$batch_chars = 0;

			$flush = function () use ( &$batch_idx, &$batch_texts, &$batch_chars, &$result, $provider, $source, $lang, $format ) {
				if ( empty( $batch_texts ) ) {
					return null;
				}
				$out = $provider->translate_batch( $batch_texts, $source, $lang, $format );
				if ( is_wp_error( $out ) ) {
					return $out;
				}
				foreach ( array_values( $batch_idx ) as $offset => $orig_idx ) {
					$result[ $orig_idx ] = isset( $out[ $offset ] ) ? $out[ $offset ] : $batch_texts[ $offset ];
				}
				$batch_idx   = array();
				$batch_texts = array();
				$batch_chars = 0;
				return null;
			};

			foreach ( $indexed as $orig_idx => $text ) {
				$len = strlen( $text );
				if ( ! empty( $batch_texts ) && ( count( $batch_texts ) >= $max_items || $batch_chars + $len > $max_chars ) ) {
					$err = $flush();
					if ( is_wp_error( $err ) ) {
						return $err;
					}
				}
				$batch_idx[]   = $orig_idx;
				$batch_texts[] = $text;
				$batch_chars  += $len;
			}
			$err = $flush();
			if ( is_wp_error( $err ) ) {
				return $err;
			}
		}

		$translated = array();
		foreach ( $units as $idx => $unit ) {
			$translated[] = array(
				'unit_id' => isset( $unit['unit_id'] ) ? $unit['unit_id'] : '',
				'path'    => isset( $unit['path'] ) ? $unit['path'] : '',
				'key'     => isset( $unit['key'] ) ? $unit['key'] : '',
				'text'    => array_key_exists( $idx, $result ) ? $result[ $idx ] : $unit['text'],
			);
		}

		return $translated;
	}

	/**
	 * Elige el formato ('html' | 'text') de una unidad. HTML si el adaptador
	 * lo declaró (`type => 'html'`) o si el valor lleva marcado/entidades.
	 *
	 * @param array $unit
	 * @return string
	 */
	private static function unit_format( $unit ) {
		if ( isset( $unit['type'] ) && 'html' === $unit['type'] ) {
			return 'html';
		}
		$text = isset( $unit['text'] ) ? (string) $unit['text'] : '';
		if ( $text !== wp_strip_all_tags( $text ) || preg_match( '/&(#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', $text ) ) {
			return 'html';
		}
		return 'text';
	}

	/**
	 * Sustituye los shortcodes por marcadores antes de traducir.
	 *
	 * @param string $content
	 * @return array [contenido_con_marcadores, mapa]
	 */
	private static function protect_shortcodes( $content ) {
		if ( ! function_exists( 'get_shortcode_regex' ) ) {
			return array( $content, array() );
		}
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
	 * @param string $content
	 * @param array  $shortcodes
	 * @return string
	 */
	private static function restore_shortcodes( $content, $shortcodes ) {
		return empty( $shortcodes ) ? $content : strtr( $content, $shortcodes );
	}
}
