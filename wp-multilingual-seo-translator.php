<?php
/**
 * Plugin Name:       Multilingual SEO Translator (Google API)
 * Plugin URI:        https://example.com
 * Description:       Traduce automáticamente tus contenidos usando la API de Google Cloud Translation, genera URLs por idioma (dominio.com/en/, dominio.com/fr/...), redirige visitantes según el idioma del navegador y sigue buenas prácticas de SEO multilenguaje (hreflang, canonical, sitemap por idioma).
 * Version:           3.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Tu Sitio
 * License:           GPL v2 or later
 * Text Domain:       mls
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

define( 'MLS_VERSION', '3.0.0' );
define( 'MLS_DB_VERSION', '2.4.0' );
define( 'MLS_PLUGIN_FILE', __FILE__ );
define( 'MLS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MLS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MLS_TABLE_TRANSLATIONS', 'mls_translations' );

/**
 * Carga de clases del plugin.
 */
require_once MLS_PLUGIN_DIR . 'includes/class-mls-db.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-language-registry.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-language-context.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-locale.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-url.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-links.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-registry.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-fields.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-terms.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-woocommerce.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-content-blocks.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-classic-adapter.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-elementor-adapter.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-gutenberg-adapter.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-content-resolver.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-elementor-cache.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-elementor-renderer.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-activator.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-translation-provider.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-translator.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-queue.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-rewrite.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-seo.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-seo-meta.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-sitemap.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-redirect.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-switcher.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-debug.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-admin.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-admin-terms.php';

/**
 * Activación / desactivación.
 */
register_activation_hook( __FILE__, array( 'MLS_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MLS_Activator', 'deactivate' ) );

/**
 * Migración silenciosa de base de datos: si el plugin se actualiza por
 * FTP/subida de archivos (sin desactivar/reactivar), esto detecta que la
 * versión de esquema instalada quedó atrás y la pone al día sola, sin
 * intervención manual y sin tocar los datos ya guardados.
 */
function mls_maybe_upgrade_db() {
	$installed = get_option( 'mls_db_version', '1.0.0' );
	if ( version_compare( $installed, MLS_DB_VERSION, '<' ) ) {
		MLS_Activator::upgrade_db();
	}
}
add_action( 'plugins_loaded', 'mls_maybe_upgrade_db', 5 );

/**
 * Arranque del plugin.
 */
function mls_init_plugin() {
	load_plugin_textdomain( 'mls', false, dirname( plugin_basename( MLS_PLUGIN_FILE ) ) . '/languages' );

	new MLS_Rewrite();
	new MLS_Locale();
	new MLS_Links();
	new MLS_Fields();
	new MLS_Terms();
	new MLS_Queue();

	// Los campos/recursos traducibles de serie + el hook para terceros.
	add_action( 'init', array( 'MLS_Registry', 'register_defaults' ), 5 );
	new MLS_Elementor_Cache();
	new MLS_Elementor_Renderer();
	new MLS_SEO();
	new MLS_SEO_Meta();
	new MLS_Sitemap();
	new MLS_Redirect();
	new MLS_Switcher();
	new MLS_Debug();

	if ( MLS_WooCommerce::is_active() ) {
		new MLS_WooCommerce();
	}

	if ( is_admin() ) {
		new MLS_Admin();
		new MLS_Admin_Terms();
	}

	add_action( 'wp_enqueue_scripts', function () {
		wp_enqueue_style( 'mls-style', MLS_PLUGIN_URL . 'assets/style.css', array(), MLS_VERSION );
	} );

	// Traducción automática al guardar/publicar contenido. El evento
	// `mls_translate_post_event` lo procesa MLS_Queue (ver su constructor).
	add_action( 'save_post', array( 'MLS_Translator', 'maybe_schedule_translation' ), 20, 3 );

	// Integridad: al borrar definitivamente un contenido, se eliminan SOLO
	// las filas propias del plugin para ese post. No se toca nada más.
	add_action( 'before_delete_post', 'mls_cleanup_translations_on_delete' );
}

/**
 * @param int $post_id
 */
function mls_cleanup_translations_on_delete( $post_id ) {
	$post_id = absint( $post_id );
	if ( class_exists( 'MLS_DB' ) ) {
		MLS_DB::delete_translations_for_post( $post_id );
	}
	if ( class_exists( 'MLS_Fields' ) ) {
		MLS_Fields::delete_for_post( $post_id );
	}
}
add_action( 'plugins_loaded', 'mls_init_plugin' );

/**
 * Al actualizar una versión que cambia el modelo de routing, se reconstruyen
 * las rewrite rules UNA sola vez. No se purga ninguna caché de terceros de
 * forma global: el aislamiento por idioma del Element Cache de Elementor ya
 * lo hace `MLS_Elementor_Cache` mediante su filtro público, y una purga
 * global rompería el principio de "no intervenir en el sitio original".
 */
function mls_maybe_schedule_runtime_cache_purge() {
	$installed = get_option( 'mls_runtime_version', '0.0.0' );
	if ( version_compare( $installed, MLS_VERSION, '<' ) ) {
		update_option( 'mls_runtime_version', MLS_VERSION, false );
		update_option( 'mls_flush_rewrite_rules', 1, false );
	}
}
add_action( 'plugins_loaded', 'mls_maybe_schedule_runtime_cache_purge', 6 );

/**
 * Invalida ÚNICAMENTE los caches propios/relacionados con un post concreto al
 * guardar su traducción. Nunca purga nada de forma global ni borra opciones
 * de terceros.
 *
 * - `clean_post_cache()` es del core y afecta solo a este post.
 * - `litespeed_purge_url` es la API pública por-URL de LiteSpeed y solo se
 *   dispara si LiteSpeed está activo escuchándola; afecta solo a esas 2 URLs.
 * - La limpieza de la caché de render de Elementor para este post es opt-in
 *   (filtro `mls_clear_elementor_cache_on_save`, desactivada por defecto),
 *   porque el discriminador de idioma del Element Cache ya evita la mezcla.
 */
function mls_purge_translation_caches( $post_id, $lang ) {
	$post_id = absint( $post_id );
	$lang    = sanitize_key( $lang );
	if ( ! $post_id ) {
		return;
	}

	clean_post_cache( $post_id );

	$urls = array_filter( array_unique( array(
		get_permalink( $post_id ),
		$lang ? mls_get_translated_url( $post_id, $lang ) : '',
	) ) );
	foreach ( $urls as $url ) {
		do_action( 'litespeed_purge_url', $url );
	}
}

/**
 * Helper global: obtener opciones del plugin.
 *
 * @return array
 */
function mls_get_settings() {
	$defaults = array(
		'api_key'                => '',
		'source_lang'            => substr( get_locale(), 0, 2 ),
		'target_langs'           => array(),
		'auto_translate'         => 0,
		'auto_redirect'          => 0,
		'ignore_redirect_admins' => 1,
		'debug_mode'             => 0,
		'post_types'             => array( 'post', 'page' ),
		'taxonomies'             => array( 'category', 'post_tag' ),
		'delete_data_on_uninstall' => 0,
		// 'source' = si no hay traducción publicada, el enlace /{lang}/ cae al
		// slug de origen (comportamiento histórico). '404' = política estricta:
		// una URL traducida sin traducción publicada devuelve 404.
		'translation_fallback'   => 'source',
	);
	$settings = get_option( 'mls_settings', array() );
	return wp_parse_args( $settings, $defaults );
}

/**
 * Log de depuración del plugin. Solo escribe algo cuando "Debug mode"
 * está activo en los ajustes (o se fuerza explícitamente con $force,
 * usado por el bypass ?mls_debug=1). Nunca registra la API key ni datos
 * sensibles — solo contexto de idioma/routing.
 *
 * @param string $message
 * @param bool   $force
 */
function mls_debug_log( $message, $force = false ) {
	if ( ! $force ) {
		$settings = mls_get_settings();
		if ( empty( $settings['debug_mode'] ) ) {
			return;
		}
	}
	error_log( '[MLS] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Helper global: URL traducida de un post en un idioma dado.
 * Delega en MLS_Url, la fuente única de URLs localizadas.
 *
 * @param int    $post_id
 * @param string $lang
 * @return string
 */
function mls_get_translated_url( $post_id, $lang ) {
	return MLS_Url::localize_post( $post_id, $lang );
}

/**
 * Alias con el nombre "objetivo" del plan. Idéntico a mls_get_translated_url().
 *
 * @param int    $post_id
 * @param string $lang
 * @return string
 */
function mls_get_localized_url( $post_id, $lang ) {
	return MLS_Url::localize_post( $post_id, $lang );
}
