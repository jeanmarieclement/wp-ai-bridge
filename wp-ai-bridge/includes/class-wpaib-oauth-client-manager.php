<?php
/**
 * Gestione client OAuth2 (registrazione, validazione, revoca).
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Genera, valida e revoca client OAuth2.
 * I secret sono salvati nel DB solo come hash SHA-256.
 */
class WPAIB_OAuth_Client_Manager {

	/**
	 * Crea un nuovo client OAuth2.
	 *
	 * @param string $name           Nome del client (es. "ChatGPT Integration").
	 * @param string $redirect_uris  Redirect URI separati da newline.
	 * @return array|WP_Error Array con 'client_id' e 'client_secret' (in chiaro, UNA SOLA VOLTA), oppure errore.
	 */
	public static function create( $name, $redirect_uris ) {
		global $wpdb;

		$name = substr( sanitize_text_field( $name ), 0, 100 );
		if ( empty( $name ) ) {
			return new WP_Error( 'wpaib_invalid_name', __( 'Client name required.', 'wp-ai-bridge' ) );
		}

		$uris = self::parse_redirect_uris( $redirect_uris );
		if ( empty( $uris ) ) {
			return new WP_Error( 'wpaib_invalid_redirect', __( 'At least one redirect URI required.', 'wp-ai-bridge' ) );
		}

		try {
			$id_bytes     = random_bytes( 16 );
			$secret_bytes = random_bytes( 32 );
		} catch ( Exception $e ) {
			return new WP_Error( 'wpaib_random_failed', __( 'Cannot generate secure random values.', 'wp-ai-bridge' ) );
		}

		$client_id     = 'wpaib_c_' . bin2hex( $id_bytes );
		$client_secret = 'wpaib_s_' . bin2hex( $secret_bytes );
		$secret_hash   = hash( 'sha256', $client_secret );

		$result = $wpdb->insert(
			$wpdb->prefix . 'wpaib_oauth_clients',
			array(
				'client_id'          => $client_id,
				'client_secret_hash' => $secret_hash,
				'name'               => $name,
				'redirect_uris'      => wp_json_encode( $uris ),
				'created_at'         => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'wpaib_db_error', __( 'Unable to save OAuth2 client.', 'wp-ai-bridge' ) );
		}

		return array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);
	}

	/**
	 * Valida un client ID e client secret in chiaro.
	 * Confronto in tempo costante per evitare timing attacks.
	 *
	 * @param string $client_id     Client ID fornito.
	 * @param string $client_secret Client secret fornito.
	 * @return object|false Record DB oppure false se non valido.
	 */
	public static function validate( $client_id, $client_secret ) {
		global $wpdb;

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return false;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wpaib_oauth_clients WHERE client_id = %s LIMIT 1",
				$client_id
			)
		);

		if ( ! $row ) {
			return false;
		}

		$expected_hash = hash( 'sha256', $client_secret );
		if ( ! hash_equals( $row->client_secret_hash, $expected_hash ) ) {
			return false;
		}

		return $row;
	}

	/**
	 * Valida client_id senza secret (per PKCE public clients).
	 * La verifica della identità avviene tramite code_verifier al token endpoint.
	 *
	 * @param string $client_id Client ID.
	 * @return object|false Record DB oppure false se non trovato.
	 */
	public static function get_public( $client_id ) {
		if ( empty( $client_id ) ) {
			return false;
		}
		$row = self::get( $client_id );
		return $row ?: false;
	}

	/**
	 * Recupera un client OAuth2 per ID (senza secret).
	 *
	 * @param string $client_id Client ID.
	 * @return object|null Record DB oppure null se non trovato.
	 */
	public static function get( $client_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, client_id, name, redirect_uris, created_at FROM {$wpdb->prefix}wpaib_oauth_clients WHERE client_id = %s LIMIT 1",
				$client_id
			)
		);
	}

	/**
	 * Valida una redirect URI per un client.
	 *
	 * @param string $client_id     Client ID.
	 * @param string $redirect_uri  Redirect URI da validare.
	 * @return bool true se la URI è registrata per il client.
	 */
	public static function validate_redirect_uri( $client_id, $redirect_uri ) {
		$client = self::get( $client_id );
		if ( ! $client ) {
			return false;
		}

		$uris = json_decode( $client->redirect_uris, true );
		return in_array( $redirect_uri, (array) $uris, true );
	}

	/**
	 * Elimina un client OAuth2.
	 *
	 * @param string $client_id Client ID.
	 * @return bool true se eliminato con successo.
	 */
	public static function delete( $client_id ) {
		global $wpdb;

		return (bool) $wpdb->delete(
			$wpdb->prefix . 'wpaib_oauth_clients',
			array( 'client_id' => $client_id ),
			array( '%s' )
		);
	}

	/**
	 * Recupera tutti i client OAuth2 (senza secret).
	 *
	 * @return array Lista di client.
	 */
	public static function get_all() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT id, client_id, name, redirect_uris, created_at FROM {$wpdb->prefix}wpaib_oauth_clients ORDER BY created_at DESC"
		);

		return $rows ? $rows : array();
	}

	/**
	 * Analizza le redirect URI da testo multilinea.
	 * Accetta solo HTTPS (o localhost/127.0.0.1 in development).
	 *
	 * @param string $raw_text Testo con URI, uno per linea.
	 * @return array Array di URI univoche e validate.
	 */
	private static function parse_redirect_uris( $raw_text ) {
		$lines = explode( "\n", str_replace( "\r", '', $raw_text ) );
		$uris  = array();

		foreach ( $lines as $line ) {
			$uri = trim( $line );
			if ( empty( $uri ) ) {
				continue;
			}
			if ( preg_match( '#^https://#i', $uri ) || preg_match( '#^http://localhost#i', $uri ) || preg_match( '#^http://127\.0\.0\.1#i', $uri ) ) {
				$uris[] = esc_url_raw( $uri );
			}
		}

		return array_values( array_unique( $uris ) );
	}
}
