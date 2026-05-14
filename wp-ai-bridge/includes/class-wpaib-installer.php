<?php
/**
 * Installer del plugin: crea le tabelle DB all'attivazione.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestisce attivazione e disattivazione del plugin.
 */
class WPAIB_Installer {

	/**
	 * Hook di attivazione: crea le tabelle.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$keys_table      = $wpdb->prefix . 'wpaib_api_keys';
		$log_table       = $wpdb->prefix . 'wpaib_audit_log';

		$sql_keys = "CREATE TABLE {$keys_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			key_hash CHAR(64) NOT NULL,
			label VARCHAR(100) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			last_used_at DATETIME DEFAULT NULL,
			last_used_ip VARCHAR(45) DEFAULT NULL,
			revoked_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY key_hash (key_hash),
			KEY user_id (user_id)
		) {$charset_collate};";

		$sql_log = "CREATE TABLE {$log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp DATETIME NOT NULL,
			api_key_id BIGINT UNSIGNED DEFAULT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			endpoint VARCHAR(255) NOT NULL DEFAULT '',
			method VARCHAR(10) NOT NULL DEFAULT '',
			status_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			outcome VARCHAR(30) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY api_key_id (api_key_id),
			KEY timestamp (timestamp)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_keys );
		dbDelta( $sql_log );

		add_option( 'wpaib_db_version', WPAIB_VERSION );
	}

	/**
	 * Hook di disattivazione: pulisce transient e cron.
	 * Non rimuove le tabelle (lo fa uninstall.php).
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Pulisce eventuali transient di rate limiting.
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpaib_rl_%' OR option_name LIKE '_transient_timeout_wpaib_rl_%'"
		);
	}
}
