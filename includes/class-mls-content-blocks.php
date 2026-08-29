<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convierte el HTML del contenido en una lista de "bloques" editables
 * (encabezado, párrafo, imagen, elemento de lista, HTML sin clasificar)
 * y viceversa, para que el admin pueda editar texto por texto en vez
 * de un solo campo gigante.
 */
class MLS_Content_Blocks {

	/**
	 * @param string $html
	 * @return array
	 */
	public static function parse_html_to_blocks( $html ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return array();
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		// El prefijo XML fuerza a DOMDocument a interpretar el HTML como
		// UTF-8; sin esto, las tildes y la "ñ" se corrompen.
		$dom->loadHTML( '<?xml encoding="utf-8" ?><div id="mls-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		$root = $dom->getElementById( 'mls-root' );
		if ( ! $root ) {
			return array( array( 'type' => 'raw', 'html' => $html ) );
		}

		$blocks = array();
		self::walk_nodes( $root->childNodes, $blocks );
		return $blocks;
	}

	private static function walk_nodes( $nodes, array &$blocks ) {
		foreach ( $nodes as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				$text = trim( $node->textContent );
				if ( '' !== $text ) {
					$blocks[] = array( 'type' => 'paragraph', 'text' => $text );
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				continue;
			}

			$tag = strtolower( $node->tagName );

			if ( preg_match( '/^h[1-6]$/', $tag ) ) {
				$blocks[] = array(
					'type'  => 'heading',
					'level' => (int) substr( $tag, 1 ),
					'text'  => trim( self::inner_html( $node ) ),
				);
			} elseif ( 'p' === $tag ) {
				$text = trim( self::inner_html( $node ) );
				if ( '' !== $text ) {
					$blocks[] = array( 'type' => 'paragraph', 'text' => $text );
				}
			} elseif ( 'blockquote' === $tag ) {
				$blocks[] = array( 'type' => 'blockquote', 'text' => trim( self::inner_html( $node ) ) );
			} elseif ( 'img' === $tag ) {
				$blocks[] = array(
					'type' => 'image',
					'src'  => $node->getAttribute( 'src' ),
					'alt'  => $node->getAttribute( 'alt' ),
				);
			} elseif ( in_array( $tag, array( 'ul', 'ol' ), true ) ) {
				$list_id = 'list_' . substr( md5( microtime() . wp_rand() ), 0, 8 );
				foreach ( $node->childNodes as $li ) {
					if ( XML_ELEMENT_NODE === $li->nodeType && 'li' === strtolower( $li->tagName ) ) {
						$blocks[] = array(
							'type'    => 'list_item',
							'list_id' => $list_id,
							'ordered' => ( 'ol' === $tag ),
							'text'    => trim( self::inner_html( $li ) ),
						);
					}
				}
			} elseif ( in_array( $tag, array( 'div', 'section', 'article', 'figure', 'main', 'header', 'footer' ), true ) ) {
				// Contenedores puramente estructurales: seguimos separando
				// lo que hay adentro en vez de tratarlos como un bloque.
				self::walk_nodes( $node->childNodes, $blocks );
			} else {
				// Tablas, iframes, formularios, etc.: se conservan tal cual
				// para no perder información, editables como HTML avanzado.
				$outer = $node->ownerDocument->saveHTML( $node );
				if ( trim( (string) $outer ) !== '' ) {
					$blocks[] = array( 'type' => 'raw', 'html' => $outer );
				}
			}
		}
	}

	private static function inner_html( DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Reconstruye el HTML completo a partir de los bloques (ya editados).
	 *
	 * @param array $blocks
	 * @return string
	 */
	public static function blocks_to_html( array $blocks ) {
		$html      = '';
		$open_list = null;

		foreach ( $blocks as $block ) {
			$type = isset( $block['type'] ) ? $block['type'] : 'paragraph';

			if ( 'list_item' === $type ) {
				$ordered = ! empty( $block['ordered'] );
				$list_id = isset( $block['list_id'] ) ? $block['list_id'] : '';

				if ( ! $open_list || $open_list['list_id'] !== $list_id ) {
					if ( $open_list ) {
						$html .= $open_list['ordered'] ? "</ol>\n" : "</ul>\n";
					}
					$html     .= $ordered ? "<ol>\n" : "<ul>\n";
					$open_list = array(
						'ordered' => $ordered,
						'list_id' => $list_id,
					);
				}
				$html .= '<li>' . wp_kses_post( isset( $block['text'] ) ? $block['text'] : '' ) . "</li>\n";
				continue;
			}

			if ( $open_list ) {
				$html     .= $open_list['ordered'] ? "</ol>\n" : "</ul>\n";
				$open_list = null;
			}

			switch ( $type ) {
				case 'heading':
					$level = isset( $block['level'] ) ? max( 1, min( 6, (int) $block['level'] ) ) : 2;
					$html .= '<h' . $level . '>' . wp_kses_post( isset( $block['text'] ) ? $block['text'] : '' ) . '</h' . $level . ">\n";
					break;
				case 'blockquote':
					$html .= '<blockquote>' . wp_kses_post( isset( $block['text'] ) ? $block['text'] : '' ) . "</blockquote>\n";
					break;
				case 'image':
					$html .= '<img src="' . esc_url( isset( $block['src'] ) ? $block['src'] : '' ) . '" alt="' . esc_attr( isset( $block['alt'] ) ? $block['alt'] : '' ) . "\" />\n";
					break;
				case 'raw':
					$html .= wp_kses_post( isset( $block['html'] ) ? $block['html'] : '' ) . "\n";
					break;
				case 'paragraph':
				default:
					$html .= '<p>' . wp_kses_post( isset( $block['text'] ) ? $block['text'] : '' ) . "</p>\n";
					break;
			}
		}

		if ( $open_list ) {
			$html .= $open_list['ordered'] ? "</ol>\n" : "</ul>\n";
		}

		return trim( $html );
	}
}
