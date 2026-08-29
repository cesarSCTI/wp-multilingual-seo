<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Se encarga de crear/actualizar la tabla personalizada y las opciones
 * por defecto. `upgrade_db()` es idempotente y se puede llamar en
 * cualquier momento (activación O actualización silenciosa en
 * `plugins_loaded`), así que el plugin nunca depende de que el usuario
 * desactive/reactive manualmente para aplicar cambios de esquema.
 */
class MLS_Activator {

	public static function activate() {
		self::upgrade_db();

		if ( false === get_option( 'mls_settings' ) ) {
			add_option( 'mls_settings', array(
				'api_key'        => '',
				'source_lang'    => substr( get_locale(), 0, 2 ),
				'target_langs'   => array(),
				'auto_translate' => 0,
				'auto_redirect'  => 0,
				'post_types'     => array( 'post', 'page' ),
			) );
		}

		// Aseguramos que las rewrite rules se generen y se limpien correctamente.
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Crea la tabla si no existe, o la actualiza (agrega columnas nuevas)
	 * si ya existía de una versión anterior del plugin. dbDelta() es
	 * seguro de volver a ejecutar: compara el SQL contra la tabla real y
	 * solo aplica las diferencias, sin borrar datos existentes.
	 */
	public static function upgrade_db() {
		global $wpdb;

		$table_name      = $wpdb->prefix . MLS_TABLE_TRANSLATIONS;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			language varchar(10) NOT NULL,
			post_title text NOT NULL,
			post_content longtext NOT NULL,
			content_blocks longtext NULL,
			post_excerpt text NULL,
			post_slug varchar(200) NOT NULL,
			meta_title varchar(255) NULL,
			meta_description varchar(255) NULL,
			status varchar(20) NOT NULL DEFAULT 'auto',
			builder varchar(20) NOT NULL DEFAULT 'classic',
			builder_data longtext NULL,
			translation_units longtext NULL,
			source_hash varchar(64) NULL,
			source_modified_at datetime NULL,
			error_message text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_lang (post_id, language),
			KEY slug_lang (post_slug(191), language),
			KEY builder_idx (builder)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'mls_db_version', MLS_DB_VERSION );
	}
}
