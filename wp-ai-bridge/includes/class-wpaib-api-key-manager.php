<?php
/**
 * Gestore delle API key.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Genera, valida, revoca le API key.
 * Le chiavi sono salvate nel DB solo come hash SHA-256.
 */
class WPAIB_API_Key_Manager {

	/**
	 * Genera una nuova API key per l'utente.
	 *
	 * @param int    $user_id ID utente.
	 * @param string $label   Etichetta descrittiva (es. "Laptop casa").
	 * @return array|WP_Error Array con 'key' (in chiaro, UNA SOLA VOLTA) e 'id', oppure errore.
	 */
	public static function generate( $user_id, $label = '' ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'wpaib_invalid_user', __( 'Invalid user.', 'wp-ai-bridge' ) );
		}

		try {
			$random_bytes = random_bytes( WPAIB_KEY_LENGTH_BYTES );
		} catch ( Exception $e ) {
			return new WP_Error( 'wpaib_random_failed', __( 'Cannot generate secure random key.', 'wp-ai-bridge' ) );
		}

		// Prefisso "wpaib_" per identificare visivamente la chiave + hex del random.
		$plain_key = 'wpaib_' . bin2hex( $random_bytes );
		$key_hash  = hash( 'sha256', $plain_key );

		$result = $wpdb->insert(
			$wpdb->prefix . 'wpaib_api_keys',
			array(
				'user_id'    => $user_id,
				'key_hash'   => $key_hash,
				'label'      => substr( sanitize_text_field( $label ), 0, 100 ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'wpaib_db_error', __( 'Unable to save API key.', 'wp-ai-bridge' ) );
		}

		return array(
			'id'  => (int) $wpdb->insert_id,
			'key' => $plain_key,
		);
	}

	/**
	 * Valida una chiave in chiaro contro il DB.
	 * Confronto in tempo costante per evitare timing attacks.
	 *
	 * @param string $plain_key Chiave fornita dal client.
	 * @return object|false Record DB oppure false se non valida.
	 */
	public static function validate( $plain_key ) {
		global $wpdb;

		if ( empty( $plain_key ) || ! is_string( $plain_key ) ) {
			return false;
		}

		// Sanity check sul formato per scartare subito input malformati.
		if ( ! preg_match( '/^wpaib_[a-f0-9]{64}$/', $plain_key ) ) {
			return false;
		}

		$key_hash = hash( 'sha256', $plain_key );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, revoked_at FROM {$wpdb->prefix}wpaib_api_keys WHERE key_hash = %s LIMIT 1",
				$key_hash
			)
		);

		if ( ! $row ) {
			return false;
		}

		if ( null !== $row->revoked_at ) {
			return false;
		}

		// Aggiorna last_used.
		$wpdb->update(
			$wpdb->prefix . 'wpaib_api_keys',
			array(
				'last_used_at' => current_time( 'mysql', true ),
				'last_used_ip' => WPAIB_Logger::get_client_ip(),
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return $row;
	}

	/**
	 * Revoca una chiave.
	 * Solo il proprietario della chiave (o admin) può revocarla.
	 *
	 * @param int $key_id  ID chiave.
	 * @param int $user_id ID utente che richiede la revoca.
	 * @return bool|WP_Error
	 */
	public static function revoke( $key_id, $user_id ) {
		global $wpdb;

		$key_id  = (int) $key_id;
		$user_id = (int) $user_id;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id FROM {$wpdb->prefix}wpaib_api_keys WHERE id = %d LIMIT 1",
				$key_id
			)
		);

		if ( ! $row ) {
			return new WP_Error( 'wpaib_key_not_found', __( 'API key not found.', 'wp-ai-bridge' ) );
		}

		// Solo il proprietario o un admin può revocare.
		if ( (int) $row->user_id !== $user_id && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Not allowed to revoke this key.', 'wp-ai-bridge' ) );
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'wpaib_api_keys',
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array( 'id' => $key_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Restituisce tutte le chiavi (anche revocate) dell'utente.
	 *
	 * @param int $user_id ID utente.
	 * @return array
	 */
	public static function get_user_keys( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, label, created_at, last_used_at, last_used_ip, revoked_at
				 FROM {$wpdb->prefix}wpaib_api_keys
				 WHERE user_id = %d
				 ORDER BY created_at DESC",
				$user_id
			)
		);

		return $rows ? $rows : array();
	}
}
