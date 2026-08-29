<?php
// Si no se llama desde WordPress al desinstalar, salir.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$settings = get_option( 'mls_settings', array() );

// Por defecto se CONSERVAN los datos: desinstalar el plugin no debe destruir
// traducciones que costaron tiempo y dinero generar. Solo se borra todo si el
// administrador lo activó explícitamente en Ajustes.
if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

foreach ( array( 'mls_translations', 'mls_meta_translations', 'mls_term_translations' ) as $t ) {
	$table = $wpdb->prefix . $t;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

delete_option( 'mls_settings' );
delete_option( 'mls_db_version' );
delete_option( 'mls_runtime_version' );
delete_option( 'mls_flush_rewrite_rules' );
