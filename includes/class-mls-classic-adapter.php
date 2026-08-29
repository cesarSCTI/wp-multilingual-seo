<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adaptador para contenido "clásico" (HTML sin bloques Gutenberg).
 *
 * En lugar de mandar todo el HTML a traducir de una vez (lo que escapa
 * etiquetas, traduce atributos técnicos y puede superar el límite de
 * caracteres del proveedor), se parsea el HTML con DOM y solo se extraen:
 *
 *   - los nodos de TEXTO con contenido real, y
 *   - un puñado de atributos que siempre son texto humano
 *     (alt, title, aria-label, placeholder, aria-description).
 *
 * Todo lo demás —etiquetas, clases, IDs, URLs, `<script>`, `<style>`,
 * `data-*`, atributos técnicos— se conserva intacto. Se respetan también
 * `translate="no"` y la clase `notranslate`.
 */
class MLS_Classic_Adapter {

	/** Atributos que se traducen. */
	private static $text_attrs = array( 'alt', 'title', 'aria-label', 'aria-description', 'placeholder' );

	/** Elementos cuyo contenido NUNCA se traduce. */
	private static $skip_tags = array( 'script', 'style', 'code', 'pre', 'kbd', 'samp', 'var' );

	/**
	 * @param string $html
	 * @return array Lista de ['path'=>, 'text'=>, 'type'=>'text'|'attr']
	 */
	public static function extract_units( $html ) {
		$dom = self::load( $html );
		if ( ! $dom ) {
			return array();
		}
		$root  = $dom->getElementById( 'mls-root' );
		$units = array();
		if ( $root ) {
			self::walk_extract( $root->childNodes, '0', $units );
		}
		return $units;
	}

	/**
	 * @param string $html
	 * @param array  $translated_units Lista de ['path'=>, 'text'=>]
	 * @return string
	 */
	public static function apply_translations( $html, array $translated_units ) {
		$dom = self::load( $html );
		if ( ! $dom ) {
			return $html;
		}
		$root = $dom->getElementById( 'mls-root' );
		if ( ! $root ) {
			return $html;
		}

		$by_path = array();
		foreach ( $translated_units as $u ) {
			if ( isset( $u['path'] ) ) {
				$by_path[ $u['path'] ] = isset( $u['text'] ) ? (string) $u['text'] : '';
			}
		}

		self::walk_apply( $root->childNodes, '0', $by_path );

		return self::inner_html( $root );
	}

	/**
	 * @param string $html
	 * @return DOMDocument|null
	 */
	private static function load( $html ) {
		$html = trim( (string) $html );
		if ( '' === $html || ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		// El prefijo XML fuerza UTF-8 en DOMDocument (si no, se corrompen tildes y ñ).
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?><div id="mls-root">' . $html . '</div>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		return $dom;
	}

	private static function walk_extract( $nodes, $prefix, array &$units ) {
		$i = -1;
		foreach ( $nodes as $node ) {
			$i++;
			$path = $prefix . '.' . $i;

			if ( XML_TEXT_NODE === $node->nodeType ) {
				if ( '' !== trim( $node->textContent ) ) {
					$units[] = array( 'path' => $path . '.text', 'text' => $node->textContent, 'type' => 'text' );
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				continue;
			}

			$tag = strtolower( $node->nodeName );
			if ( in_array( $tag, self::$skip_tags, true ) || self::is_no_translate( $node ) ) {
				continue;
			}

			foreach ( self::$text_attrs as $attr ) {
				if ( $node->hasAttribute( $attr ) ) {
					$val = $node->getAttribute( $attr );
					if ( '' !== trim( $val ) ) {
						$units[] = array( 'path' => $path . '.attr:' . $attr, 'text' => $val, 'type' => 'attr' );
					}
				}
			}

			if ( $node->hasChildNodes() ) {
				self::walk_extract( $node->childNodes, $path, $units );
			}
		}
	}

	private static function walk_apply( $nodes, $prefix, array $by_path ) {
		// Se materializa la lista: modificar nodeValue no altera el número de
		// hijos, pero iterar un DOMNodeList vivo mientras se cambia es frágil.
		$list = array();
		foreach ( $nodes as $node ) {
			$list[] = $node;
		}

		$i = -1;
		foreach ( $list as $node ) {
			$i++;
			$path = $prefix . '.' . $i;

			if ( XML_TEXT_NODE === $node->nodeType ) {
				if ( isset( $by_path[ $path . '.text' ] ) && '' !== trim( $node->textContent ) ) {
					$node->nodeValue = self::preserve_edge_space( $node->textContent, $by_path[ $path . '.text' ] );
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				continue;
			}

			$tag = strtolower( $node->nodeName );
			if ( in_array( $tag, self::$skip_tags, true ) || self::is_no_translate( $node ) ) {
				continue;
			}

			foreach ( self::$text_attrs as $attr ) {
				$attr_path = $path . '.attr:' . $attr;
				if ( $node->hasAttribute( $attr ) && isset( $by_path[ $attr_path ] ) && '' !== trim( $by_path[ $attr_path ] ) ) {
					$node->setAttribute( $attr, $by_path[ $attr_path ] );
				}
			}

			if ( $node->hasChildNodes() ) {
				self::walk_apply( $node->childNodes, $path, $by_path );
			}
		}
	}

	/**
	 * Conserva el espacio inicial/final del nodo original (que suele separar
	 * palabras de etiquetas vecinas) alrededor de la traducción.
	 */
	private static function preserve_edge_space( $original, $translated ) {
		$lead  = ( preg_match( '/^\s+/', $original, $m ) ) ? $m[0] : '';
		$trail = ( preg_match( '/\s+$/', $original, $m ) ) ? $m[0] : '';
		return $lead . trim( $translated ) . $trail;
	}

	private static function is_no_translate( $node ) {
		if ( $node->hasAttribute( 'translate' ) && 'no' === strtolower( $node->getAttribute( 'translate' ) ) ) {
			return true;
		}
		$class = $node->getAttribute( 'class' );
		return $class && preg_match( '/\bnotranslate\b/', $class );
	}

	private static function inner_html( DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}
}
