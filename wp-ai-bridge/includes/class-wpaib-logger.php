<?php
/**
 * Logger per audit trail.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestisce la scrittura dell'audit log.
 */
class WPAIB_Logger {

	/**
	 * Registra un evento nell'audit log.
	 *
	 * @param array $args Dati dell'evento.
	 * @return void
	 */
	public static function log( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'api_key_id'  => null,
			'endpoint'    => '',
			'method'      => '',
			'status_code' => 0,
			'outcome'     => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$wpdb->insert(
			$wpdb->prefix . 'wpaib_audit_log',
			array(
				'timestamp'   => current_time( 'mysql', true ),
				'api_key_id'  => $args['api_key_id'] ? (int) $args['api_key_id'] : null,
				'ip'          => self::get_client_ip(),
				'user_agent'  => substr( self::get_user_agent(), 0, 255 ),
				'endpoint'    => substr( sanitize_text_field( $args['endpoint'] ), 0, 255 ),
				'method'      => substr( sanitize_text_field( $args['method'] ), 0, 10 ),
				'status_code' => (int) $args['status_code'],
				'outcome'     => substr( sanitize_text_field( $args['outcome'] ), 0, 30 ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Restituisce l'IP del client in modo sicuro.
	 * Prende sempre REMOTE_ADDR per non fidarsi di header spoofabili (X-Forwarded-For).
	 * Se sei dietro un reverse proxy fidato, modifica wp-config.php con WPAIB_TRUST_PROXY.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		if ( defined( 'WPAIB_TRUST_PROXY' ) && WPAIB_TRUST_PROXY && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$parts     = explode( ',', $forwarded );
			$ip        = trim( $parts[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Restituisce lo user-agent del client.
	 *
	 * @return string
	 */
	public static function get_user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}
}
