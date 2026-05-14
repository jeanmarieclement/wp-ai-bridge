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
);

foreach ( $tables as $table ) {
	// I nomi tabella sono costruiti da costanti, safe.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

delete_option( 'wpaib_db_version' );

// Pulizia transient di rate limiting.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpaib_rl_%' OR option_name LIKE '_transient_timeout_wpaib_rl_%'"
);
