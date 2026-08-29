<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Traduce contenido Gutenberg preservando la estructura de bloques.
 *
 * A diferencia de tratar el contenido como HTML genérico, usamos las
 * funciones nativas de WordPress `parse_blocks()` / `serialize_blocks()`,
 * que entienden los comentarios `<!-- wp:heading -->` y no los rompen.
 */
class MLS_Gutenberg_Adapter {

	/**
	 * @param string $post_content
	 * @return bool
	 */
	public static function is_gutenberg_content( $post_content ) {
		return function_exists( 'has_blocks' ) && has_blocks( $post_content );
	}

	/**
	 * @param string $post_content
	 * @return array Lista de ['path' => string, 'text' => string, 'type' => 'html'|'attr']
	 */
	public static function extract_units( $post_content ) {
		$blocks = parse_blocks( $post_content );
		$units  = array();
		self::walk_extract( $blocks, '0', $units );
		return $units;
	}

	/**
	 * @param string $post_content       Contenido original (solo para volver a parsear su estructura).
	 * @param array  $translated_units   Lista de ['path' => string, 'text' => string]
	 * @return string
	 */
	public static function apply_translations( $post_content, array $translated_units ) {
		$blocks = parse_blocks( $post_content );

		$by_path = array();
		foreach ( $translated_units as $u ) {
			if ( isset( $u['path'] ) ) {
				$by_path[ $u['path'] ] = isset( $u['text'] ) ? $u['text'] : '';
			}
		}

		self::walk_apply( $blocks, '0', $by_path );

		return serialize_blocks( $blocks );
	}

	private static function walk_extract( array $blocks, $path_prefix, array &$units ) {
		foreach ( $blocks as $i => $block ) {
			$path = $path_prefix . '.' . $i;

			if ( ! empty( $block['innerHTML'] ) && '' !== trim( wp_strip_all_tags( $block['innerHTML'] ) ) ) {
				$units[] = array(
					'path' => $path . '.innerHTML',
					'text' => $block['innerHTML'],
					'type' => 'html',
				);
			}

			// Algunos bloques (core/button, core/quote cite, etc.) guardan
			// texto en attrs en vez de (o además de) innerHTML.
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				foreach ( array( 'content', 'text', 'title', 'value', 'placeholder', 'citation' ) as $key ) {
					if ( isset( $block['attrs'][ $key ] ) && is_string( $block['attrs'][ $key ] ) && '' !== trim( $block['attrs'][ $key ] ) ) {
						$units[] = array(
							'path' => $path . '.attrs.' . $key,
							'text' => $block['attrs'][ $key ],
							'type' => 'attr',
						);
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::walk_extract( $block['innerBlocks'], $path . '.inner', $units );
			}
		}
	}

	private static function walk_apply( array &$blocks, $path_prefix, array $by_path ) {
		foreach ( $blocks as $i => &$block ) {
			$path = $path_prefix . '.' . $i;

			if ( isset( $by_path[ $path . '.innerHTML' ] ) ) {
				$new_html = $by_path[ $path . '.innerHTML' ];
				if ( '' !== trim( $new_html ) ) {
					$block['innerHTML'] = $new_html;
					// innerContent normalmente es [innerHTML] en bloques simples sin
					// hijos; lo sincronizamos para que serialize_blocks() lo use bien.
					if ( ! empty( $block['innerContent'] ) && 1 === count( $block['innerContent'] ) ) {
						$block['innerContent'] = array( $new_html );
					}
				}
			}

			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				foreach ( $block['attrs'] as $key => $val ) {
					$attr_path = $path . '.attrs.' . $key;
					if ( isset( $by_path[ $attr_path ] ) && '' !== trim( $by_path[ $attr_path ] ) ) {
						$block['attrs'][ $key ] = $by_path[ $attr_path ];
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::walk_apply( $block['innerBlocks'], $path . '.inner', $by_path );
			}
		}
		unset( $block );
	}
}
