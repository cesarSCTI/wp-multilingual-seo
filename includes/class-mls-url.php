<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fuente única para construir y resolver URLs localizadas.
 *
 * Toda URL con prefijo de idioma que genere el plugin pasa por aquí, y el
 * router (MLS_Rewrite) resuelve el camino inverso con `resolve_path()`.
 *
 * Para páginas jerárquicas se usa un `translated_path` completo
 * (`seccion-en/subpagina-en`) en vez de un slug plano, de modo que
 * /en/seccion-en/subpagina-en/ funcione y los enlaces internos conserven la
 * jerarquía traducida.
 */
class MLS_Url {

	/**
	 * URL localizada de un post en un idioma.
	 *
	 * @param int    $post_id
	 * @param string $lang
	 * @return string
	 */
	public static function localize_post( $post_id, $lang ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( $lang );
		$source  = MLS_Language_Registry::source();

		if ( ! $post_id || $lang === $source['code'] || ! MLS_Language_Registry::is_target( $lang ) ) {
			return get_permalink( $post_id );
		}

		// La front page vive siempre en /{lang}/.
		if ( 'page' === get_option( 'show_on_front' ) && $post_id === (int) get_option( 'page_on_front' ) ) {
			return self::home( $lang );
		}

		$translation = MLS_DB::get_translation( $post_id, $lang );
		if ( ! MLS_DB::is_servable( $translation ) ) {
			$translation = null; // pending/failed: para URLs cuenta como "sin traducción".
		}

		if ( $translation && ! empty( $translation->translated_path ) ) {
			$path = $translation->translated_path;
		} elseif ( $translation && ! empty( $translation->post_slug ) ) {
			$path = self::compute_path( $post_id, $lang, $translation->post_slug );
		} else {
			// Sin traducción: política de reserva configurable.
			$settings = mls_get_settings();
			if ( '404' === ( isset( $settings['translation_fallback'] ) ? $settings['translation_fallback'] : 'source' ) ) {
				return get_permalink( $post_id );
			}
			$path = self::compute_path( $post_id, $lang, get_post_field( 'post_name', $post_id ) );
		}

		return trailingslashit( home_url( '/' . $lang . '/' . ltrim( $path, '/' ) ) );
	}

	/**
	 * Home de un idioma.
	 *
	 * @param string $lang
	 * @return string
	 */
	public static function home( $lang ) {
		$lang   = sanitize_key( $lang );
		$source = MLS_Language_Registry::source();
		if ( $lang === $source['code'] || ! $lang ) {
			return home_url( '/' );
		}
		return trailingslashit( home_url( '/' . $lang ) );
	}

	/**
	 * Construye el path jerárquico traducido de un post: para cada ancestro
	 * usa su slug traducido si existe, o su slug original si no.
	 *
	 * @param int    $post_id
	 * @param string $lang
	 * @param string $own_slug Slug traducido (o el que se va a usar) de este post.
	 * @return string  Sin barras al inicio/fin (ej. "acerca-en/equipo-en").
	 */
	public static function compute_path( $post_id, $lang, $own_slug ) {
		$post_id  = absint( $post_id );
		$own_slug = trim( (string) $own_slug, '/' );
		$segments = array( $own_slug );

		$post = get_post( $post_id );
		if ( $post && is_post_type_hierarchical( $post->post_type ) ) {
			$ancestor_id = (int) $post->post_parent;
			$guard       = 0;
			while ( $ancestor_id && $guard < 20 ) {
				$guard++;
				$a_translation = MLS_DB::get_translation( $ancestor_id, $lang );
				$a_slug        = ( $a_translation && ! empty( $a_translation->post_slug ) )
					? $a_translation->post_slug
					: get_post_field( 'post_name', $ancestor_id );
				array_unshift( $segments, trim( (string) $a_slug, '/' ) );
				$ancestor_id = (int) get_post_field( 'post_parent', $ancestor_id );
			}
		}

		return implode( '/', array_filter( $segments ) );
	}

	/**
	 * Resuelve un path (relativo al prefijo de idioma) a un post_id.
	 * Devuelve [ 'post_id' => int, 'matched' => 'path'|'translated_slug'|'source_path' ]
	 * o null si no hay contenido traducido para ese path.
	 *
	 * @param string $path
	 * @param string $lang
	 * @return array|null
	 */
	public static function resolve_path( $path, $lang ) {
		$path = trim( rawurldecode( (string) $path ), '/' );
		$lang = sanitize_key( $lang );
		if ( '' === $path ) {
			return null;
		}

		// 1) Path traducido completo (jerárquico).
		$post_id = MLS_DB::get_post_id_by_translated_path( $path, $lang );
		if ( $post_id ) {
			return array( 'post_id' => $post_id, 'matched' => 'path' );
		}

		// 2) Último segmento como slug traducido plano (compatibilidad).
		$last = substr( strrchr( '/' . $path, '/' ), 1 );
		$post_id = MLS_DB::get_post_id_by_translated_slug( $last, $lang );
		if ( $post_id ) {
			return array( 'post_id' => $post_id, 'matched' => 'translated_slug' );
		}

		// 3) Path de ORIGEN (jerárquico) cuando ya existe traducción real:
		//    cubre enlaces heredados y traducciones que conservan el slug fuente.
		$settings   = mls_get_settings();
		$post_types = array_values( array_filter( (array) $settings['post_types'], 'post_type_exists' ) );
		if ( empty( $post_types ) ) {
			$post_types = array( 'page', 'post' );
		}
		$source_post = get_page_by_path( $path, OBJECT, $post_types );
		if ( $source_post && MLS_DB::is_servable( MLS_DB::get_translation( $source_post->ID, $lang ) ) ) {
			return array( 'post_id' => (int) $source_post->ID, 'matched' => 'source_path' );
		}

		return null;
	}

	/**
	 * Taxonomías públicas con base de reescritura, indexadas por su base.
	 *
	 * @return array<string,WP_Taxonomy>
	 */
	private static function rewritable_taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( empty( $tax->rewrite['slug'] ) ) {
				continue;
			}
			$out[ trim( $tax->rewrite['slug'], '/' ) ] = $tax;
		}
		return $out;
	}

	/**
	 * ¿Este path bajo /{lang}/ es el archivo de un término traducido?
	 * Devuelve query vars que hacen que WordPress muestre ese archivo, o null.
	 *
	 * @param string $path
	 * @param string $lang
	 * @return array|null  [ 'query_vars' => [...], 'term_id' => int, 'taxonomy' => string ]
	 */
	public static function resolve_term_path( $path, $lang ) {
		$path = trim( rawurldecode( (string) $path ), '/' );
		$lang = sanitize_key( $lang );
		if ( '' === $path ) {
			return null;
		}

		foreach ( self::rewritable_taxonomies() as $base => $tax ) {
			if ( 0 !== strpos( $path . '/', $base . '/' ) ) {
				continue;
			}
			$term_path = trim( substr( $path, strlen( $base ) ), '/' );
			if ( '' === $term_path ) {
				continue;
			}
			$last = substr( strrchr( '/' . $term_path, '/' ), 1 );

			$row = MLS_Terms::get_row_by_translated_slug( $last, $tax->name, $lang );
			if ( ! $row || ! in_array( (string) $row->status, MLS_DB::servable_statuses(), true ) ) {
				continue;
			}
			$term = get_term( (int) $row->term_id, $tax->name );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			// Se sirve el archivo usando la referencia de ORIGEN del término.
			$source_full = self::source_term_path( $term );
			$qv          = array();
			if ( 'category' === $tax->name ) {
				$qv['category_name'] = $source_full;
			} elseif ( 'post_tag' === $tax->name ) {
				$qv['tag'] = $term->slug;
			} elseif ( ! empty( $tax->query_var ) ) {
				$qv[ $tax->query_var ] = is_taxonomy_hierarchical( $tax->name ) ? $source_full : $term->slug;
			} else {
				$qv['taxonomy'] = $tax->name;
				$qv['term']     = $term->slug;
			}

			return array( 'query_vars' => $qv, 'term_id' => (int) $term->term_id, 'taxonomy' => $tax->name );
		}

		return null;
	}

	/**
	 * Path de origen (jerárquico) de un término: "padre/hijo".
	 *
	 * @param WP_Term $term
	 * @return string
	 */
	private static function source_term_path( $term ) {
		$segments = array( $term->slug );
		$parent   = (int) $term->parent;
		$guard    = 0;
		while ( $parent && $guard < 20 ) {
			$guard++;
			$p = get_term( $parent, $term->taxonomy );
			if ( ! $p || is_wp_error( $p ) ) {
				break;
			}
			array_unshift( $segments, $p->slug );
			$parent = (int) $p->parent;
		}
		return implode( '/', $segments );
	}

	/**
	 * URL localizada del archivo de un término.
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @param string $lang
	 * @return string|null
	 */
	public static function localize_term( $term_id, $taxonomy, $lang ) {
		$lang   = sanitize_key( $lang );
		$source = MLS_Language_Registry::source();
		if ( $lang === $source['code'] || ! MLS_Language_Registry::is_target( $lang ) ) {
			return null;
		}

		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || empty( $tax->rewrite['slug'] ) ) {
			return null;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		// Path jerárquico con el slug traducido de cada nivel si existe.
		$segments = array();
		$chain    = array( $term );
		$parent   = (int) $term->parent;
		$guard    = 0;
		while ( $parent && $guard < 20 ) {
			$guard++;
			$p = get_term( $parent, $taxonomy );
			if ( ! $p || is_wp_error( $p ) ) {
				break;
			}
			array_unshift( $chain, $p );
			$parent = (int) $p->parent;
		}
		foreach ( $chain as $t ) {
			$row        = MLS_Terms::get_row( $t->term_id, $lang );
			$segments[] = ( $row && '' !== (string) $row->slug ) ? $row->slug : $t->slug;
		}

		$base = trim( $tax->rewrite['slug'], '/' );
		return trailingslashit( home_url( '/' . $lang . '/' . $base . '/' . implode( '/', $segments ) ) );
	}
}
