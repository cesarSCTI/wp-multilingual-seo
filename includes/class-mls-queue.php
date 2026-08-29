<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cola de traducción en segundo plano.
 *
 * Separa "traducir" de "publicar": una traducción automática entra como
 * `pending`, pasa a `translating` mientras se procesa (con lock) y solo
 * llega a `published` si se completó entera. Si falla, queda `failed` y se
 * reintenta con backoff hasta un máximo de intentos — sin dejar nunca una
 * traducción publicada a medias.
 *
 * Usa Action Scheduler si está disponible (lo trae WooCommerce y muchos
 * plugins); si no, cae a un único evento de WP-Cron por trabajo. En ningún
 * caso se llama a `spawn_cron()` en cada guardado.
 */
class MLS_Queue {

	const HOOK = 'mls_translate_post_event';

	public function __construct() {
		add_action( self::HOOK, array( __CLASS__, 'process' ), 10, 3 );
	}

	/**
	 * @return int Máximo de intentos por trabajo.
	 */
	public static function max_attempts() {
		return (int) apply_filters( 'mls_translation_max_attempts', 3 );
	}

	/**
	 * Encola (o re-encola) la traducción de un post a un idioma.
	 *
	 * @param int    $post_id
	 * @param string $lang
	 * @param int    $delay Segundos de retraso.
	 * @param int    $attempt
	 */
	public static function enqueue( $post_id, $lang, $delay = 5, $attempt = 1 ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( $lang );
		if ( ! $post_id || ! $lang ) {
			return;
		}

		// No se pisa una traducción manual ni una que ya está en curso.
		$existing = MLS_DB::get_translation( $post_id, $lang );
		if ( $existing && in_array( (string) $existing->status, array( MLS_DB::STATUS_MANUAL, MLS_DB::STATUS_TRANSLATING ), true ) ) {
			return;
		}

		if ( 1 === $attempt ) {
			MLS_DB::set_status( $post_id, $lang, MLS_DB::STATUS_PENDING );
		}

		$args = array( $post_id, $lang, $attempt );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, 'mls' ) ) {
				return;
			}
			as_schedule_single_action( time() + max( 0, (int) $delay ), self::HOOK, $args, 'mls' );
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), self::HOOK, $args );
		}
	}

	/**
	 * Ejecuta un trabajo de la cola.
	 *
	 * @param int    $post_id
	 * @param string $lang
	 * @param int    $attempt
	 */
	public static function process( $post_id, $lang, $attempt = 1 ) {
		$post_id = absint( $post_id );
		$lang    = sanitize_key( $lang );
		$attempt = max( 1, (int) $attempt );

		if ( ! $post_id || ! $lang || ! get_post( $post_id ) ) {
			return;
		}

		$existing = MLS_DB::get_translation( $post_id, $lang );
		if ( $existing && MLS_DB::STATUS_MANUAL === (string) $existing->status ) {
			return; // Manual: intocable.
		}

		if ( ! MLS_DB::acquire_lock( $post_id, $lang ) ) {
			// Otro proceso lo tiene: se reintenta más tarde, sin contar intento.
			self::enqueue( $post_id, $lang, 120, $attempt );
			return;
		}

		MLS_DB::set_status( $post_id, $lang, MLS_DB::STATUS_TRANSLATING );

		try {
			$result = MLS_Translator::translate_and_save( $post_id, $lang );
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'mls_exception', $e->getMessage() );
		}

		MLS_DB::release_lock( $post_id, $lang );

		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			mls_debug_log( sprintf( 'Traducción post=%d lang=%s intento=%d falló: %s', $post_id, $lang, $attempt, $msg ), true );

			if ( $attempt < self::max_attempts() ) {
				MLS_DB::set_status( $post_id, $lang, MLS_DB::STATUS_PENDING, $msg );
				self::enqueue( $post_id, $lang, self::backoff_delay( $attempt ), $attempt + 1 );
			} else {
				MLS_DB::set_status( $post_id, $lang, MLS_DB::STATUS_FAILED, $msg );
			}
			return;
		}

		// translate_and_save() ya guardó la fila con status 'published'
		// (o 'manual' si estaba protegida). Nada más que hacer.
	}

	/**
	 * Backoff exponencial suave: 60s, 300s, 900s...
	 *
	 * @param int $attempt
	 * @return int
	 */
	private static function backoff_delay( $attempt ) {
		$table = array( 1 => 60, 2 => 300, 3 => 900 );
		return isset( $table[ $attempt ] ) ? $table[ $attempt ] : 1800;
	}
}
