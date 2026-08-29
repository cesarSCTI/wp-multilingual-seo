<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capa de acceso a datos para la tabla de traducciones.
 */
class MLS_DB {

	/** Encolada, aún sin traducir. */
	const STATUS_PENDING = 'pending';
	/** En curso (protegida por un lock). */
	const STATUS_TRANSLATING = 'translating';
	/** Traducción automática completa y servible. */
	const STATUS_PUBLISHED = 'published';
	/** Editada a mano: no se sobrescribe con traducción automática. */
	const STATUS_MANUAL = 'manual';
	/** Falló; `error_message` explica por qué. */
	const STATUS_FAILED = 'failed';
	/** El contenido de origen cambió después de traducirse (informativo). */
	const STATUS_OUTDATED = 'outdated';

	/**
	 * Estados que se pueden SERVIR en el frontend (una URL /{lang}/ los
	 * muestra; cualquier otro estado → 404).
	 *
	 * @return string[]
	 */
	public static function servable_statuses() {
		return array( self::STATUS_PUBLISHED, self::STATUS_MANUAL, self::STATUS_OUTDATED );
	}

	/**
	 * @param object|null $translation
	 * @return bool ¿Se puede servir esta traducción en el frontend?
	 */
	public static function is_servable( $translation ) {
		return $translation && in_array( (string) $translation->status, self::servable_statuses(), true );
	}

	/**
	 * @param object|null $translation
	 * @return bool ¿Debe aparecer en hreflang / sitemap? (igual que servible
	 *              pero excluyendo `outdated` si así se configura).
	 */
	public static function is_indexable( $translation ) {
		if ( ! self::is_servable( $translation ) ) {
			return false;
		}
		if ( self::STATUS_OUTDATED === (string) $translation->status ) {
			return (bool) apply_filters( 'mls_index_outdated_translations', true );
		}
		return true;
	}

	/**
	 * Etiqueta legible de un estado, para el admin.
	 *
	 * @param string $status
	 * @return string
	 */
	public static function status_label( $status ) {
		$map = array(
			self::STATUS_PENDING     => __( 'Pendiente', 'mls' ),
			self::STATUS_TRANSLATING => __( 'Traduciendo…', 'mls' ),
			self::STATUS_PUBLISHED   => __( 'Traducida', 'mls' ),
			self::STATUS_MANUAL      => __( 'Manual', 'mls' ),
			self::STATUS_FAILED      => __( 'Falló', 'mls' ),
			self::STATUS_OUTDATED    => __( 'Desactualizada', 'mls' ),
		);
		$status = (string) $status;
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}

	/**
	 * Lock cooperativo por post+idioma para que dos procesos (cron + botón
	 * "Traducir ahora", dos crons solapados...) no traduzcan a la vez y
	 * generen slugs duplicados o pisen una traducción manual.
	 *
	 * @param int    $post_id
	 * @param string $lang
	 * @return bool True si se adquirió; False si ya estaba tomado.
	 */
	public static function acquire_lock( $post_id, $lang ) {
		$key = self::lock_key( $post_id, $lang );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, time(), 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * @param int    $post_id
	 * @param string $lang
	 */
	public static function release_lock( $post_id, $lang ) {
		delete_transient( self::lock_key( $post_id, $lang ) );
	}

	private static function lock_key( $post_id, $lang ) {
		return 'mls_lock_' . absint( $post_id ) . '_' . sanitize_key( $lang );
	}

	/**
	 * Caché en memoria por petición: evita repetir la misma consulta
	 * SQL decenas de veces cuando algo (como Elementor) pide la misma
	 * traducción varias veces durante el mismo render.
	 *
	 * @var array<string, object|false>
	 */
	private static $request_cache = array();

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . MLS_TABLE_TRANSLATIONS;
	}

	/**
	 * Obtiene la traducción de un post en un idioma concreto.
	 * Resuelve una sola vez por post_id+idioma en toda la petición.
	 *
	 * @param int    $post_id
	 * @param string $lang
	 * @return object|null
	 */
	public static function get_translation( $post_id, $lang ) {
		$cache_key = $post_id . '|' . $lang;
		if ( array_key_exists( $cache_key, self::$request_cache ) ) {
			return self::$request_cache[ $cache_key ] ?: null;
		}

		global $wpdb;
		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d AND language = %s LIMIT 1",
				$post_id,
				$lang
			)
		);

		self::$request_cache[ $cache_key ] = $row ? $row : false;
		return $row;
	}

	/**
	 * Limpia la caché en memoria de un post+idioma concreto (o todo, si
	 * no se especifica) — se usa después de guardar/actualizar para que
	 * el resto de la misma petición vea el dato fresco.
	 *
	 * @param int|null    $post_id
	 * @param string|null $lang
	 */
	public static function clear_cache( $post_id = null, $lang = null ) {
		if ( null === $post_id ) {
			self::$request_cache = array();
			return;
		}
		if ( null === $lang ) {
			foreach ( array_keys( self::$request_cache ) as $key ) {
				if ( 0 === strpos( $key, $post_id . '|' ) ) {
					unset( self::$request_cache[ $key ] );
				}
			}
			return;
		}
		unset( self::$request_cache[ $post_id . '|' . $lang ] );
	}

	/**
	 * Devuelve todas las traducciones de un post (una fila por idioma).
	 *
	 * @param int $post_id
	 * @return array
	 */
	public static function get_translations_for_post( $post_id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id )
		);
	}

	/**
	 * Busca el post_id original a partir de un slug traducido + idioma.
	 *
	 * @param string $slug
	 * @param string $lang
	 * @return int|null
	 */
	public static function get_post_id_by_translated_slug( $slug, $lang ) {
		global $wpdb;
		$table = self::table();
		$in    = self::servable_placeholders();

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$table} WHERE post_slug = %s AND language = %s AND status IN ($in) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $slug, $lang ), self::servable_statuses() )
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * Busca el post_id original a partir de un path traducido completo
	 * (jerárquico), ej. "acerca-en/equipo-en". Solo devuelve una traducción
	 * servible (published / manual / outdated).
	 *
	 * @param string $path
	 * @param string $lang
	 * @return int|null
	 */
	public static function get_post_id_by_translated_path( $path, $lang ) {
		global $wpdb;
		$table = self::table();
		$path  = trim( (string) $path, '/' );
		$in    = self::servable_placeholders();

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$table} WHERE translated_path = %s AND language = %s AND status IN ($in) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $path, $lang ), self::servable_statuses() )
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * Placeholders `%s, %s, ...` para el número de estados servibles.
	 *
	 * @return string
	 */
	private static function servable_placeholders() {
		return implode( ', ', array_fill( 0, count( self::servable_statuses() ), '%s' ) );
	}

	/**
	 * Inserta o actualiza (upsert) una traducción.
	 *
	 * @param array $data Debe incluir post_id y language.
	 * @return int|false ID de la fila afectada.
	 */
	public static function save_translation( array $data ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );

		$existing = self::get_translation( $data['post_id'], $data['language'] );

		// El path traducido nunca debe quedar vacío en una fila real: si el
		// llamador no lo pasó, se deriva del slug (+ jerarquía) o se conserva
		// el que ya había.
		if ( ! isset( $data['translated_path'] ) || '' === trim( (string) $data['translated_path'], '/' ) ) {
			$slug = isset( $data['post_slug'] ) ? $data['post_slug'] : ( $existing ? $existing->post_slug : '' );
			if ( $slug && class_exists( 'MLS_Url' ) ) {
				$data['translated_path'] = MLS_Url::compute_path( $data['post_id'], $data['language'], $slug );
			} elseif ( $existing && ! empty( $existing->translated_path ) ) {
				$data['translated_path'] = $existing->translated_path;
			}
		}

		$row = array(
			'post_id'            => (int) $data['post_id'],
			'language'           => sanitize_key( $data['language'] ),
			'post_title'         => isset( $data['post_title'] ) ? wp_kses_post( $data['post_title'] ) : '',
			'post_content'       => isset( $data['post_content'] ) ? wp_kses_post( $data['post_content'] ) : '',
			'content_blocks'     => isset( $data['content_blocks'] ) ? $data['content_blocks'] : '',
			'post_excerpt'       => isset( $data['post_excerpt'] ) ? wp_kses_post( $data['post_excerpt'] ) : '',
			'post_slug'          => isset( $data['post_slug'] ) ? sanitize_title( $data['post_slug'] ) : '',
			'translated_path'    => isset( $data['translated_path'] ) ? self::sanitize_path( $data['translated_path'] ) : '',
			'meta_title'         => isset( $data['meta_title'] ) ? sanitize_text_field( $data['meta_title'] ) : '',
			'meta_description'   => isset( $data['meta_description'] ) ? sanitize_text_field( $data['meta_description'] ) : '',
			'status'             => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : self::STATUS_PUBLISHED,
			'builder'            => isset( $data['builder'] ) ? sanitize_key( $data['builder'] ) : 'classic',
			'builder_data'       => isset( $data['builder_data'] ) ? $data['builder_data'] : '',
			'translation_units'  => isset( $data['translation_units'] ) ? $data['translation_units'] : '',
			'source_hash'        => isset( $data['source_hash'] ) ? sanitize_text_field( $data['source_hash'] ) : '',
			'adapter_version'    => isset( $data['adapter_version'] ) ? sanitize_text_field( $data['adapter_version'] ) : ( defined( 'MLS_VERSION' ) ? MLS_VERSION : '' ),
			'error_message'      => isset( $data['error_message'] ) ? sanitize_text_field( $data['error_message'] ) : '',
			'updated_at'         => $now,
		);

		self::clear_cache( $data['post_id'], $data['language'] );

		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => $existing->id ) );
			$saved_id = (int) $existing->id;
		} else {
			$row['created_at'] = $now;
			$wpdb->insert( $table, $row );
			$saved_id = (int) $wpdb->insert_id;
		}

		// La traducción y el source comparten post/element IDs. Invalidamos
		// solo al guardar, nunca en cada request, para impedir HTML obsoleto.
		self::clear_cache( $data['post_id'], $data['language'] );

		// Si esta página tiene hijos, sus paths traducidos dependen del slug
		// recién guardado: se recalculan.
		self::refresh_descendant_paths( (int) $data['post_id'], sanitize_key( $data['language'] ) );

		if ( function_exists( 'mls_purge_translation_caches' ) ) {
			mls_purge_translation_caches( $data['post_id'], $data['language'] );
		}

		/**
		 * Se dispara tras guardar (insertar o actualizar) una traducción.
		 *
		 * @param int    $post_id
		 * @param string $lang
		 * @param string $status
		 */
		do_action( 'mls_translation_saved', (int) $data['post_id'], sanitize_key( $data['language'] ), $row['status'] );

		return $saved_id;
	}

	/**
	 * Cambia solo el estado (y opcionalmente el mensaje de error) de una
	 * traducción, sin tocar su contenido. Para transiciones de la cola:
	 * pending → translating → published / failed.
	 *
	 * @param int    $post_id
	 * @param string $lang
	 * @param string $status
	 * @param string $error
	 * @return bool
	 */
	public static function set_status( $post_id, $lang, $status, $error = '' ) {
		global $wpdb;
		$post_id = absint( $post_id );
		$lang    = sanitize_key( $lang );
		$status  = sanitize_key( $status );

		$existing = self::get_translation( $post_id, $lang );
		$data     = array(
			'status'        => $status,
			'error_message' => sanitize_text_field( $error ),
			'updated_at'    => current_time( 'mysql' ),
		);

		self::clear_cache( $post_id, $lang );

		if ( $existing ) {
			$ok = (bool) $wpdb->update( self::table(), $data, array( 'id' => $existing->id ) );
		} else {
			// Fila mínima: las columnas NOT NULL sin default se rellenan vacías
			// (MySQL en modo estricto rechazaría el INSERT si no).
			$data['post_id']      = $post_id;
			$data['language']     = $lang;
			$data['post_title']   = '';
			$data['post_content'] = '';
			$data['post_slug']    = '';
			$data['created_at']   = $data['updated_at'];
			$ok                   = (bool) $wpdb->insert( self::table(), $data );
		}

		self::clear_cache( $post_id, $lang );
		return $ok;
	}

	/**
	 * Marca como `outdated` toda traducción cuyo `source_hash` ya no cuadre
	 * con el contenido actual del post. No borra ni re-traduce nada.
	 *
	 * @param int $post_id
	 * @return int Número de filas marcadas.
	 */
	public static function flag_outdated_for_post( $post_id ) {
		$post_id = absint( $post_id );
		$rows    = self::get_translations_for_post( $post_id );
		$current = self::compute_source_hash( $post_id );
		$count   = 0;

		foreach ( (array) $rows as $row ) {
			if ( in_array( (string) $row->status, array( self::STATUS_PENDING, self::STATUS_TRANSLATING, self::STATUS_FAILED ), true ) ) {
				continue;
			}
			if ( $row->source_hash && $row->source_hash !== $current && self::STATUS_OUTDATED !== (string) $row->status ) {
				self::set_status( $post_id, $row->language, self::STATUS_OUTDATED );
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Recalcula el `translated_path` de las páginas hijas de un post en un
	 * idioma, tras cambiar el slug del padre. Solo toca filas propias.
	 *
	 * @param int    $parent_id
	 * @param string $lang
	 */
	public static function refresh_descendant_paths( $parent_id, $lang ) {
		if ( ! $parent_id || ! class_exists( 'MLS_Url' ) ) {
			return;
		}
		$parent = get_post( $parent_id );
		if ( ! $parent || ! is_post_type_hierarchical( $parent->post_type ) ) {
			return;
		}

		$children = get_posts( array(
			'post_type'        => $parent->post_type,
			'post_parent'      => $parent_id,
			'posts_per_page'   => 200,
			'post_status'      => 'any',
			'fields'           => 'ids',
			'suppress_filters' => true,
		) );

		global $wpdb;
		$table = self::table();

		foreach ( $children as $child_id ) {
			$child_tr = self::get_translation( $child_id, $lang );
			if ( ! $child_tr ) {
				continue;
			}
			$new_path = MLS_Url::compute_path( $child_id, $lang, $child_tr->post_slug ? $child_tr->post_slug : get_post_field( 'post_name', $child_id ) );
			if ( $new_path !== $child_tr->translated_path ) {
				$wpdb->update( $table, array( 'translated_path' => self::sanitize_path( $new_path ) ), array( 'id' => $child_tr->id ) );
				self::clear_cache( $child_id, $lang );
			}
			// Recursivo: nietos.
			self::refresh_descendant_paths( (int) $child_id, $lang );
		}
	}

	/**
	 * Sanea un path traducido segmento a segmento, conservando las barras.
	 *
	 * @param string $path
	 * @return string
	 */
	public static function sanitize_path( $path ) {
		$segments = array_filter( explode( '/', (string) $path ), 'strlen' );
		$clean    = array_map( 'sanitize_title', $segments );
		return implode( '/', array_filter( $clean, 'strlen' ) );
	}

	/**
	 * Calcula un hash del estado actual del contenido original (título,
	 * cuerpo, extracto y, si aplica, datos de Elementor). Se usa para
	 * detectar cuándo una traducción quedó "desactualizada" porque el
	 * original cambió después de traducirse.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public static function compute_source_hash( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$parts = array( $post->post_title, $post->post_content, $post->post_excerpt );

		if ( class_exists( 'MLS_Elementor_Adapter' ) && MLS_Elementor_Adapter::is_elementor_post( $post_id ) ) {
			$parts[] = get_post_meta( $post_id, '_elementor_data', true );
		}

		return md5( implode( '|', $parts ) );
	}

	/**
	 * ¿La traducción quedó desatrasada respecto al contenido original?
	 * No sobrescribe nada: solo informa, para que el admin decida.
	 *
	 * @param object $translation
	 * @return bool
	 */
	public static function is_outdated( $translation ) {
		if ( ! $translation || empty( $translation->source_hash ) ) {
			return false; // Traducciones antiguas sin hash guardado: no se marcan como desactualizadas.
		}
		$current_hash = self::compute_source_hash( $translation->post_id );
		return $current_hash !== $translation->source_hash;
	}

	/**
	 * Genera un slug único (dentro de un idioma) a partir de un slug base.
	 *
	 * @param string $slug
	 * @param string $lang
	 * @param int    $post_id Post que ya puede tener este slug reservado (se ignora en el chequeo).
	 * @return string
	 */
	public static function generate_unique_slug( $slug, $lang, $post_id ) {
		global $wpdb;
		$table    = self::table();
		$base     = sanitize_title( $slug );
		$new_slug = $base;
		$suffix   = 2;

		while ( true ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$table} WHERE post_slug = %s AND language = %s AND post_id != %d LIMIT 1",
					$new_slug,
					$lang,
					$post_id
				)
			);
			if ( ! $exists ) {
				break;
			}
			$new_slug = $base . '-' . $suffix;
			$suffix++;
		}

		return $new_slug;
	}

	/**
	 * Devuelve los bloques editables de una traducción. Si aún no tiene
	 * bloques guardados (traducciones creadas antes de esta función),
	 * los calcula al vuelo a partir del HTML guardado.
	 *
	 * @param object|null $translation
	 * @return array
	 */
	public static function get_blocks_for_translation( $translation ) {
		if ( $translation && ! empty( $translation->content_blocks ) ) {
			$decoded = json_decode( $translation->content_blocks, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		$html = $translation ? $translation->post_content : '';
		return MLS_Content_Blocks::parse_html_to_blocks( $html );
	}

	/**
	 * Elimina todas las traducciones de un post (por ejemplo, al borrarlo).
	 *
	 * @param int $post_id
	 */
	public static function delete_translations_for_post( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => $post_id ) );
	}

	/**
	 * ¿Existe al menos una traducción real para este idioma? Se usa para no
	 * anunciar en hreflang una home de idioma que todavía no tiene contenido.
	 *
	 * @param string $lang
	 * @return bool
	 */
	public static function lang_has_translations( $lang ) {
		global $wpdb;
		$table = self::table();
		$in    = self::servable_placeholders();
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE language = %s AND status IN ($in) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( sanitize_key( $lang ) ), self::servable_statuses() )
			)
		);
		return (bool) $count;
	}

	/**
	 * Lista todas las URLs traducidas de un idioma (usado por el sitemap).
	 *
	 * @param string $lang
	 * @return array
	 */
	public static function get_all_slugs_for_lang( $lang ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, post_slug, translated_path, updated_at, status, error_message FROM {$table} WHERE language = %s",
				$lang
			)
		);
	}
}
