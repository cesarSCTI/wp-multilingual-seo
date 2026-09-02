<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mantiene los enlaces internos dentro del idioma de la URL.
 *
 * En una página /en/ los permalinks que genera WordPress (`get_permalink`,
 * menús, "entradas relacionadas", paginación de la home...) siguen
 * apuntando al idioma fuente. Aquí los reescribimos a su versión /en/
 * cuando existe traducción.
 *
 * Todo esto SOLO actúa en peticiones marcadas como traducción por
 * MLS_Language_Context. En una URL sin prefijo, cada filtro devuelve su
 * valor sin tocar y el sitio se comporta igual que sin el plugin.
 */
class MLS_Links {

	/**
	 * Suspensión temporal de TODOS los filtros de localización de enlaces.
	 *
	 * Lo usa el selector de idioma para obtener el permalink REAL del idioma
	 * fuente: en una petición /en/ estos filtros reescribirían
	 * `get_permalink()` a /en/, y entonces la opción "ES" del selector
	 * apuntaría de vuelta a la página en inglés.
	 *
	 * @var bool
	 */
	public static $suspended = false;

	public function __construct() {
		add_filter( 'post_link', array( $this, 'localize_post_link' ), 10, 2 );
		add_filter( 'page_link', array( $this, 'localize_page_link' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'localize_post_link' ), 10, 2 );
		add_filter( 'term_link', array( $this, 'localize_term_link' ), 10, 3 );

		// Paginación de la home / archivos: /page/2/ -> /en/page/2/.
		add_filter( 'paginate_links', array( $this, 'localize_generic_url' ) );
		add_filter( 'get_pagination_link', array( $this, 'localize_generic_url' ) );

		// Elementos de menú que apuntan a un post traducido.
		add_filter( 'wp_nav_menu_objects', array( $this, 'localize_menu_items' ) );

		// Enlaces internos incrustados en el propio contenido traducido.
		add_filter( 'the_content', array( $this, 'localize_content_links' ), 20 );
	}

	private function active() {
		return ! self::$suspended && ! is_admin() && MLS_Language_Context::is_translation_request();
	}

	/**
	 * @param string       $permalink
	 * @param WP_Post|int  $post
	 * @return string
	 */
	public function localize_post_link( $permalink, $post ) {
		if ( ! $this->active() ) {
			return $permalink;
		}
		$post_id = is_object( $post ) ? (int) $post->ID : (int) $post;
		if ( ! $post_id ) {
			return $permalink;
		}
		$lang = MLS_Language_Context::get_current_language();
		if ( ! MLS_DB::is_servable( MLS_DB::get_translation( $post_id, $lang ) ) ) {
			return $permalink; // Sin traducción publicada: se deja el enlace de origen.
		}
		return MLS_Url::localize_post( $post_id, $lang );
	}

	/**
	 * `page_link` pasa el ID (int), no el objeto.
	 *
	 * @param string $permalink
	 * @param int    $post_id
	 * @return string
	 */
	public function localize_page_link( $permalink, $post_id ) {
		return $this->localize_post_link( $permalink, (int) $post_id );
	}

	/**
	 * @param string  $url
	 * @param WP_Term $term
	 * @param string  $taxonomy
	 * @return string
	 */
	public function localize_term_link( $url, $term, $taxonomy ) {
		if ( ! $this->active() || ! ( $term instanceof WP_Term ) ) {
			return $url;
		}
		$lang = MLS_Language_Context::get_current_language();
		$row  = MLS_Terms::get_row( $term->term_id, $lang );
		if ( ! $row || ! in_array( (string) $row->status, MLS_DB::servable_statuses(), true ) ) {
			return $url;
		}
		$localized = MLS_Url::localize_term( $term->term_id, $taxonomy, $lang );
		return $localized ? $localized : $url;
	}

	/**
	 * Reescribe una URL absoluta del propio sitio para que quede bajo el
	 * prefijo del idioma actual (sin resolver a un post concreto).
	 *
	 * @param string $url
	 * @return string
	 */
	public function localize_generic_url( $url ) {
		if ( ! $this->active() || ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		$lang = MLS_Language_Context::get_current_language();
		$home = home_url( '/' );

		if ( 0 !== strpos( $url, $home ) ) {
			return $url;
		}
		$rest = substr( $url, strlen( $home ) );
		if ( '' === $rest || 0 === strpos( $rest, $lang . '/' ) || $rest === $lang ) {
			return $url;
		}
		return $home . $lang . '/' . ltrim( $rest, '/' );
	}

	/**
	 * @param array $items
	 * @return array
	 */
	public function localize_menu_items( $items ) {
		if ( ! $this->active() || empty( $items ) ) {
			return $items;
		}
		$lang = MLS_Language_Context::get_current_language();

		foreach ( $items as $item ) {
			// Etiqueta de un ítem de taxonomía traducido (categoría, etc.).
			if ( 'taxonomy' === $item->type && ! empty( $item->object_id ) ) {
				$term_row = MLS_Terms::get_row( (int) $item->object_id, $lang );
				if ( $term_row && '' !== (string) $term_row->name && in_array( (string) $term_row->status, MLS_DB::servable_statuses(), true ) ) {
					if ( $item->title === $item->post_title || '' === (string) $item->post_title ) {
						$item->title = $term_row->name;
					}
				}
				continue;
			}

			if ( empty( $item->object_id ) || 'post_type' !== $item->type ) {
				continue;
			}
			$post_id = (int) $item->object_id;
			$tr      = MLS_DB::get_translation( $post_id, $lang );
			if ( ! MLS_DB::is_servable( $tr ) ) {
				continue;
			}
			$item->url = MLS_Url::localize_post( $post_id, $lang );

			// Etiqueta: solo se traduce si NO estaba personalizada en el menú
			// (es decir, si coincide con el título del post de origen).
			$source_title = get_the_title( $post_id );
			if ( '' !== (string) $tr->post_title && ( $item->title === $source_title || '' === (string) $item->post_title ) ) {
				$item->title = $tr->post_title;
			}
		}
		return $items;
	}

	/**
	 * Reescribe los href internos incrustados en el HTML del contenido
	 * traducido: si el destino es un post del sitio con traducción, se
	 * apunta a su versión localizada.
	 *
	 * @param string $html
	 * @return string
	 */
	public function localize_content_links( $html ) {
		if ( ! $this->active() || false === strpos( $html, 'href=' ) ) {
			return $html;
		}
		$lang = MLS_Language_Context::get_current_language();
		$home = home_url( '/' );

		return preg_replace_callback(
			'/href=(["\'])(' . preg_quote( $home, '/' ) . '[^"\']*)\1/i',
			function ( $m ) use ( $lang, $home ) {
				$url  = $m[2];
				$path = wp_parse_url( $url, PHP_URL_PATH );
				if ( ! $path ) {
					return $m[0];
				}
				$post_id = url_to_postid( $url );
				if ( $post_id && MLS_DB::is_servable( MLS_DB::get_translation( $post_id, $lang ) ) ) {
					return 'href=' . $m[1] . MLS_Url::localize_post( $post_id, $lang ) . $m[1];
				}
				return $m[0];
			},
			$html
		);
	}
}
