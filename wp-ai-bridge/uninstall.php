<?php
/**
 * Script di disinstallazione: rimuove tabelle e opzioni del plugin.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'wpaib_api_keys',
	$wpdb->prefix . 'wpaib_audit_log',
	$wpdb->prefix . 'wpaib_oauth_clients',
	$wpdb->prefix . 'wpaib_oauth_codes',
	$wpdb->prefix . 'wpaib_oauth_tokens',
);

foreach ( $tables as $table ) {
	// I nomi tabella sono costruiti da costanti, safe.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

delete_option( 'wpaib_db_version' );
delete_option( 'wpaib_disabled_tools' );

// Rimuove il cron di pulizia OAuth2 schedulato.
wp_clear_scheduled_hook( 'wpaib_cleanup_expired' );

// Pulizia transient di rate limiting.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpaib_rl_%' OR option_name LIKE '_transient_timeout_wpaib_rl_%'"
);
