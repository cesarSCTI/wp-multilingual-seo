<?php
/**
 * Plugin Name:       Multilingual SEO Translator (Google API)
 * Plugin URI:        https://example.com
 * Description:       Traduce automáticamente tus contenidos usando la API de Google Cloud Translation, genera URLs por idioma (dominio.com/en/, dominio.com/fr/...), redirige visitantes según el idioma del navegador y sigue buenas prácticas de SEO multilenguaje (hreflang, canonical, sitemap por idioma).
 * Version:           2.3.0
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

define( 'MLS_VERSION', '2.3.0' );
define( 'MLS_DB_VERSION', '2.1.0' );
define( 'MLS_PLUGIN_FILE', __FILE__ );
define( 'MLS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MLS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MLS_TABLE_TRANSLATIONS', 'mls_translations' );

/**
 * Carga de clases del plugin.
 */
require_once MLS_PLUGIN_DIR . 'includes/class-mls-db.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-language-context.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-content-blocks.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-elementor-adapter.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-gutenberg-adapter.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-content-resolver.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-elementor-cache.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-elementor-renderer.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-activator.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-translator.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-rewrite.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-seo.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-redirect.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-switcher.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-debug.php';
require_once MLS_PLUGIN_DIR . 'includes/class-mls-admin.php';

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
	new MLS_Elementor_Cache();
	new MLS_Elementor_Renderer();
	new MLS_SEO();
	new MLS_Redirect();
	new MLS_Switcher();
	new MLS_Debug();

	if ( is_admin() ) {
		new MLS_Admin();
	}

	add_action( 'wp_enqueue_scripts', function () {
		wp_enqueue_style( 'mls-style', MLS_PLUGIN_URL . 'assets/style.css', array(), MLS_VERSION );
	} );

	// Traducción automática al guardar/publicar contenido.
	add_action( 'save_post', array( 'MLS_Translator', 'maybe_schedule_translation' ), 20, 3 );
	add_action( 'mls_translate_post_event', array( 'MLS_Translator', 'translate_and_save' ), 10, 3 );
}
add_action( 'plugins_loaded', 'mls_init_plugin' );

/**
 * Al actualizar una versión que cambia el modelo de render/caché, purgamos
 * UNA sola vez las cachés que podrían conservar HTML generado con reglas
 * anteriores. No se ejecuta en cada request.
 */
function mls_maybe_schedule_runtime_cache_purge() {
	$installed = get_option( 'mls_runtime_version', '0.0.0' );
	if ( version_compare( $installed, MLS_VERSION, '<' ) ) {
		update_option( 'mls_runtime_version', MLS_VERSION, false );
		update_option( 'mls_runtime_cache_purge', 1, false );
		// Cada cambio importante de routing debe reconstruir reglas una vez.
		update_option( 'mls_flush_rewrite_rules', 1, false );
	}
}
add_action( 'plugins_loaded', 'mls_maybe_schedule_runtime_cache_purge', 6 );

function mls_run_scheduled_runtime_cache_purge() {
	if ( ! get_option( 'mls_runtime_cache_purge' ) ) {
		return;
	}
	delete_option( 'mls_runtime_cache_purge' );

	// Elementor: elimina el buster del element cache y sus archivos generados.
	delete_option( 'elementor_element_cache_unique_id' );
	if ( class_exists( '\\Elementor\\Plugin' ) ) {
		try {
			$elementor = \Elementor\Plugin::$instance;
			if ( $elementor && isset( $elementor->files_manager ) && method_exists( $elementor->files_manager, 'clear_cache' ) ) {
				$elementor->files_manager->clear_cache();
			}
		} catch ( \Throwable $e ) {
			// Una API interna distinta no debe bloquear WordPress.
		}
	}

	// LiteSpeed escucha esta acción cuando el plugin está activo.
	do_action( 'litespeed_purge_all' );
}
add_action( 'init', 'mls_run_scheduled_runtime_cache_purge', 1000 );

/**
 * Invalida únicamente los caches relacionados con una traducción al guardar.
 * Las URLs source/target son distintas, pero ambas pueden contener componentes
 * Elementor compartidos, por eso se purgan las dos URLs concretas.
 */
function mls_purge_translation_caches( $post_id, $lang ) {
	$post_id = absint( $post_id );
	$lang    = sanitize_key( $lang );
	if ( ! $post_id ) {
		return;
	}

	clean_post_cache( $post_id );
	delete_option( 'elementor_element_cache_unique_id' );

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
 * Si no existe traducción todavía, cae al slug original (mejor que un 404).
 *
 * @param int    $post_id
 * @param string $lang
 * @return string
 */
function mls_get_translated_url( $post_id, $lang ) {
	$settings = mls_get_settings();
	$post_id  = absint( $post_id );
	$lang     = sanitize_key( $lang );

	if ( $lang === $settings['source_lang'] ) {
		return get_permalink( $post_id );
	}

	// La página configurada como front page siempre vive en /{lang}/,
	// independientemente de que su slug interno sea home, inicio, etc.
	if ( 'page' === get_option( 'show_on_front' ) && $post_id === (int) get_option( 'page_on_front' ) ) {
		return trailingslashit( home_url( '/' . $lang ) );
	}

	$translation = MLS_DB::get_translation( $post_id, $lang );
	$slug        = $translation && ! empty( $translation->post_slug ) ? $translation->post_slug : get_post_field( 'post_name', $post_id );

	return trailingslashit( home_url( '/' . $lang . '/' . ltrim( $slug, '/' ) ) );
}
