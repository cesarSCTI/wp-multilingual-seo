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
			translated_path varchar(255) NULL,
			meta_title varchar(255) NULL,
			meta_description varchar(255) NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			builder varchar(20) NOT NULL DEFAULT 'classic',
			builder_data longtext NULL,
			translation_units longtext NULL,
			source_hash varchar(64) NULL,
			source_modified_at datetime NULL,
			adapter_version varchar(20) NULL,
			error_message text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_lang (post_id, language),
			KEY slug_lang (post_slug(191), language),
			KEY path_lang (translated_path(191), language),
			KEY lang_status (language, status),
			KEY builder_idx (builder)
		) {$charset_collate};";

		$meta_table = $wpdb->prefix . 'mls_meta_translations';
		$sql_meta   = "CREATE TABLE {$meta_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			language varchar(10) NOT NULL,
			meta_key varchar(255) NOT NULL,
			meta_value longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'published',
			source_hash varchar(64) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_lang_key (post_id, language, meta_key(150)),
			KEY lang_key (language, meta_key(150))
		) {$charset_collate};";

		$menu_table = $wpdb->prefix . 'mls_menu_translations';
		$sql_menu   = "CREATE TABLE {$menu_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			menu_item_id bigint(20) unsigned NOT NULL,
			language varchar(10) NOT NULL,
			title text NULL,
			attr_title text NULL,
			description text NULL,
			status varchar(20) NOT NULL DEFAULT 'manual',
			source_hash varchar(64) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY item_lang (menu_item_id, language),
			KEY lang_status (language, status)
		) {$charset_collate};";

		$term_table = $wpdb->prefix . 'mls_term_translations';
		$sql_term   = "CREATE TABLE {$term_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			term_id bigint(20) unsigned NOT NULL,
			taxonomy varchar(32) NOT NULL,
			language varchar(10) NOT NULL,
			name varchar(200) NOT NULL DEFAULT '',
			description longtext NULL,
			slug varchar(200) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'published',
			source_hash varchar(64) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY term_lang (term_id, language),
			KEY tax_lang (taxonomy, language),
			KEY slug_lang (slug(191), language)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		dbDelta( $sql_meta );
		dbDelta( $sql_menu );
		dbDelta( $sql_term );

		self::migrate_data();

		update_option( 'mls_db_version', MLS_DB_VERSION );
	}

	/**
	 * Migraciones de DATOS (dbDelta solo migra el esquema). Idempotentes.
	 */
	private static function migrate_data() {
		global $wpdb;
		$table = $wpdb->prefix . MLS_TABLE_TRANSLATIONS;

		// 2.4.0: el estado 'auto' pasa a llamarse 'published'.
		$wpdb->query( "UPDATE {$table} SET status = 'published' WHERE status = 'auto'" ); // phpcs:ignore WordPress.DB

		// Traducciones sin path jerárquico calculado todavía: se rellena a
		// partir del slug plano (sin jerarquía; se completará al re-traducir).
		if ( class_exists( 'MLS_DB' ) ) {
			$rows = $wpdb->get_results( "SELECT id, post_slug FROM {$table} WHERE translated_path IS NULL OR translated_path = ''" ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $r ) {
				if ( '' !== (string) $r->post_slug ) {
					$wpdb->update( $table, array( 'translated_path' => MLS_DB::sanitize_path( $r->post_slug ) ), array( 'id' => $r->id ) );
				}
			}
		}
	}
}
