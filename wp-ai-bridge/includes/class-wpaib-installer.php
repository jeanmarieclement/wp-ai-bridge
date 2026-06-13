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
	 * Hook di attivazione: crea le tabelle e svuota le rewrite rules.
	 * Questo hook gira con WP completamente avviato (admin), quindi
	 * add_rewrite_rule() e flush_rewrite_rules() sono sicuri.
	 *
	 * @return void
	 */
	public static function activate() {
		self::run_db_delta();
		WPAIB_OAuth_Authorize::add_rewrite_rule();
		WPAIB_OAuth_Discovery::add_rewrite_rules();
		flush_rewrite_rules();
		update_option( 'wpaib_db_version', WPAIB_VERSION );

		// Pulizia giornaliera di codici/token OAuth2 scaduti.
		if ( ! wp_next_scheduled( 'wpaib_cleanup_expired' ) ) {
			wp_schedule_event( time(), 'daily', 'wpaib_cleanup_expired' );
		}
	}

	/**
	 * Esegue dbDelta se la versione DB è inferiore alla versione corrente.
	 * Chiamato a plugins_loaded (priority 5): $wp_rewrite non è ancora pronto,
	 * quindi rewrite rules e flush vengono schedulati su init.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'wpaib_db_version' ) === WPAIB_VERSION ) {
			return;
		}
		self::run_db_delta();
		update_option( 'wpaib_db_version', WPAIB_VERSION );
		add_action( 'init', 'flush_rewrite_rules', 20 );
	}

	/**
	 * Crea/aggiorna le tabelle tramite dbDelta.
	 *
	 * @return void
	 */
	private static function run_db_delta() {
		global $wpdb;

		$charset_collate     = $wpdb->get_charset_collate();
		$keys_table          = $wpdb->prefix . 'wpaib_api_keys';
		$log_table           = $wpdb->prefix . 'wpaib_audit_log';
		$oauth_clients_table = $wpdb->prefix . 'wpaib_oauth_clients';
		$oauth_codes_table   = $wpdb->prefix . 'wpaib_oauth_codes';
		$oauth_tokens_table  = $wpdb->prefix . 'wpaib_oauth_tokens';

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

		// TEXT/BLOB columns cannot have DEFAULT values in MySQL — omit DEFAULT.
		$sql_oauth_clients = "CREATE TABLE {$oauth_clients_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			client_id VARCHAR(64) NOT NULL,
			client_secret_hash CHAR(64) NOT NULL,
			name VARCHAR(100) NOT NULL DEFAULT '',
			redirect_uris TEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY client_id (client_id)
		) {$charset_collate};";

		$sql_oauth_codes = "CREATE TABLE {$oauth_codes_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code_hash CHAR(64) NOT NULL,
			client_id VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			redirect_uri TEXT NOT NULL,
			scope VARCHAR(255) NOT NULL DEFAULT '',
			code_challenge VARCHAR(128) DEFAULT NULL,
			code_challenge_method VARCHAR(10) DEFAULT NULL,
			expires_at DATETIME NOT NULL,
			used_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY code_hash (code_hash),
			KEY client_id (client_id),
			KEY user_id (user_id)
		) {$charset_collate};";

		$sql_oauth_tokens = "CREATE TABLE {$oauth_tokens_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			access_token_hash CHAR(64) NOT NULL,
			refresh_token_hash CHAR(64) NOT NULL,
			client_id VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			scope VARCHAR(255) NOT NULL DEFAULT '',
			expires_at DATETIME NOT NULL,
			revoked_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY access_token_hash (access_token_hash),
			UNIQUE KEY refresh_token_hash (refresh_token_hash),
			KEY client_id (client_id),
			KEY user_id (user_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_keys );
		dbDelta( $sql_log );
		dbDelta( $sql_oauth_clients );
		dbDelta( $sql_oauth_codes );
		dbDelta( $sql_oauth_tokens );
	}

	/**
	 * Hook di disattivazione: pulisce transient e cron.
	 * Non rimuove le tabelle (lo fa uninstall.php).
	 *
	 * @return void
	 */
	public static function deactivate() {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpaib_rl_%' OR option_name LIKE '_transient_timeout_wpaib_rl_%'"
		);

		wp_clear_scheduled_hook( 'wpaib_cleanup_expired' );
	}
}
