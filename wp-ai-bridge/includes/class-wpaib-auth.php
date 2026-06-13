<?php
/**
 * Middleware di autenticazione per gli endpoint del plugin.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifica permessi per ogni richiesta REST del plugin.
 * Imposta l'utente corrente di WordPress se la chiave è valida.
 */
class WPAIB_Auth {

	/**
	 * Controlla rate limit, valida API key, verifica capability.
	 *
	 * @param WP_REST_Request $request   Richiesta REST.
	 * @param string          $capability Capability WP richiesta (es. 'edit_posts').
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request, $capability ) {
		$endpoint = $request->get_route();
		$method   = $request->get_method();

		// Prova prima l'Authorization: Bearer header (OAuth2).
		$bearer = self::extract_bearer( $request );
		if ( null !== $bearer ) {
			// Rate limit anche sul path Bearer, keyed sull'hash del token.
			$rate_check = self::enforce_rate_limit( hash( 'sha256', $bearer ), $endpoint, $method );
			if ( is_wp_error( $rate_check ) ) {
				return $rate_check;
			}
			return self::authorize_bearer( $bearer, $capability, $endpoint, $method );
		}

		// Estrae la chiave dall'header.
		$plain_key = $request->get_header( 'x_api_key' );
		if ( empty( $plain_key ) ) {
			$plain_key = $request->get_header( 'X-API-Key' );
		}

		if ( empty( $plain_key ) ) {
			WPAIB_Logger::log(
				array(
					'endpoint'    => $endpoint,
					'method'      => $method,
					'status_code' => 401,
					'outcome'     => 'auth_missing',
				)
			);
			return self::generic_auth_error();
		}

		// Rate limit basato sull'hash della chiave per non rivelare la chiave nel transient.
		$rate_check = self::enforce_rate_limit( hash( 'sha256', $plain_key ), $endpoint, $method );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// Valida la chiave.
		$key_record = WPAIB_API_Key_Manager::validate( $plain_key );
		if ( ! $key_record ) {
			WPAIB_Logger::log(
				array(
					'endpoint'    => $endpoint,
					'method'      => $method,
					'status_code' => 401,
					'outcome'     => 'auth_failed',
				)
			);
			return self::generic_auth_error();
		}

		// Imposta utente corrente di WordPress.
		wp_set_current_user( (int) $key_record->user_id );

		// Verifica capability.
		if ( ! current_user_can( $capability ) ) {
			WPAIB_Logger::log(
				array(
					'api_key_id'  => (int) $key_record->id,
					'endpoint'    => $endpoint,
					'method'      => $method,
					'status_code' => 403,
					'outcome'     => 'forbidden',
				)
			);
			return new WP_Error(
				'wpaib_forbidden',
				__( 'Insufficient permissions.', 'wp-ai-bridge' ),
				array( 'status' => 403 )
			);
		}

		// Log successo.
		WPAIB_Logger::log(
			array(
				'api_key_id'  => (int) $key_record->id,
				'endpoint'    => $endpoint,
				'method'      => $method,
				'status_code' => 200,
				'outcome'     => 'success',
			)
		);

		return true;
	}

	/**
	 * Applica il rate limit per un identificatore e logga il blocco.
	 *
	 * @param string $rate_id  Hash dell'identificatore (chiave o token).
	 * @param string $endpoint Endpoint REST.
	 * @param string $method   Metodo HTTP.
	 * @return true|WP_Error True se permesso, WP_Error 429 se bloccato.
	 */
	private static function enforce_rate_limit( $rate_id, $endpoint, $method ) {
		if ( WPAIB_Rate_Limiter::check( $rate_id ) ) {
			return true;
		}

		WPAIB_Logger::log(
			array(
				'endpoint'    => $endpoint,
				'method'      => $method,
				'status_code' => 429,
				'outcome'     => 'rate_limited',
			)
		);
		return new WP_Error(
			'wpaib_rate_limited',
			__( 'Too many requests.', 'wp-ai-bridge' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Estrae il Bearer token dall'header Authorization, oppure null.
	 *
	 * @param WP_REST_Request $request Richiesta REST.
	 * @return string|null
	 */
	private static function extract_bearer( WP_REST_Request $request ) {
		$auth = $request->get_header( 'authorization' );
		if ( empty( $auth ) ) {
			return null;
		}
		if ( 0 !== strncasecmp( 'Bearer ', $auth, 7 ) ) {
			return null;
		}
		$token = trim( substr( $auth, 7 ) );
		return $token !== '' ? $token : null;
	}

	/**
	 * Autentica tramite OAuth2 Bearer token.
	 *
	 * @param string $plain_token Token in chiaro.
	 * @param string $capability  Capability WP richiesta dall'endpoint.
	 * @param string $endpoint    Endpoint REST.
	 * @param string $method      Metodo HTTP.
	 * @return true|WP_Error
	 */
	private static function authorize_bearer( $plain_token, $capability, $endpoint, $method ) {
		$data = WPAIB_OAuth_Server::validate_access_token( $plain_token );

		if ( ! $data ) {
			WPAIB_Logger::log( array(
				'endpoint'    => $endpoint,
				'method'      => $method,
				'status_code' => 401,
				'outcome'     => 'bearer_invalid',
			) );
			return self::generic_auth_error();
		}

		wp_set_current_user( $data['user_id'] );

		if ( ! current_user_can( $capability ) ) {
			WPAIB_Logger::log( array(
				'endpoint'    => $endpoint,
				'method'      => $method,
				'status_code' => 403,
				'outcome'     => 'bearer_forbidden',
			) );
			return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		WPAIB_Logger::log( array(
			'endpoint'    => $endpoint,
			'method'      => $method,
			'status_code' => 200,
			'outcome'     => 'bearer_ok',
		) );

		return true;
	}

	/**
	 * Errore generico di autenticazione, non rivela quale controllo è fallito.
	 *
	 * @return WP_Error
	 */
	private static function generic_auth_error() {
		return new WP_Error(
			'wpaib_unauthorized',
			__( 'Authentication required.', 'wp-ai-bridge' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Helper per generare il callback permission_callback su una capability.
	 *
	 * @param string $capability Capability richiesta.
	 * @return callable
	 */
	public static function require_cap( $capability ) {
		return function ( WP_REST_Request $request ) use ( $capability ) {
			return WPAIB_Auth::authorize( $request, $capability );
		};
	}
}
