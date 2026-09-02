<?php
/**
 * Bootstrap de las pruebas unitarias.
 *
 * Estas pruebas NO cargan WordPress: usan Brain Monkey para simular las
 * funciones del core que tocan las unidades de lógica pura del plugin
 * (registro de idiomas, saneado de paths, elección de formato de
 * traducción...).
 *
 * Las pruebas de integración con WordPress (routing real, parse_blocks,
 * Elementor) requieren la WordPress test suite y viven aparte; se añadirán
 * en tests/Integration cuando haya un entorno con `wp-env` o
 * `wp-phpunit/wp-phpunit`.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
	fwrite( STDERR, "\nFalta vendor/. Ejecuta:  composer install\n\n" );
	exit( 1 );
}
require $autoload;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wp/' );
}
if ( ! defined( 'MLS_TABLE_TRANSLATIONS' ) ) {
	define( 'MLS_TABLE_TRANSLATIONS', 'mls_translations' );
}

require_once __DIR__ . '/../includes/class-mls-language-registry.php';
require_once __DIR__ . '/../includes/class-mls-language-context.php';
require_once __DIR__ . '/../includes/class-mls-url.php';
require_once __DIR__ . '/../includes/class-mls-db.php';
require_once __DIR__ . '/../includes/class-mls-switcher.php';
