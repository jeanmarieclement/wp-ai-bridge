<?php
/**
 * Endpoint REST OAuth2: /oauth/token e /oauth/revoke.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIB_OAuth_Controller {

	public function register_routes() {
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/oauth/token',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'token' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/oauth/revoke',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'revoke' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	public function token( WP_REST_Request $request ) {
		$grant_type    = sanitize_key( $request->get_param( 'grant_type' ) );
		$client_id     = sanitize_text_field( (string) $request->get_param( 'client_id' ) );
		$client_secret = sanitize_text_field( (string) $request->get_param( 'client_secret' ) );
		$code_verifier = sanitize_text_field( (string) $request->get_param( 'code_verifier' ) );

		// Confidential client: richiede client_secret.
		// Public client (PKCE): nessun secret, verifica tramite code_verifier.
		if ( ! empty( $client_secret ) ) {
			$client = WPAIB_OAuth_Client_Manager::validate( $client_id, $client_secret );
		} elseif ( ! empty( $code_verifier ) && 'authorization_code' === $grant_type ) {
			$client = WPAIB_OAuth_Client_Manager::get( $client_id );
		} else {
			$client = false;
		}

		if ( ! $client ) {
			return $this->oauth_error( 'invalid_client', 401 );
		}

		// Traccia se il client è stato autenticato col secret (confidential)
		// o solo via PKCE (public). Serve a impedire che un code senza
		// code_challenge venga riscattato senza secret.
		$authed_with_secret = ! empty( $client_secret );

		if ( 'authorization_code' === $grant_type ) {
			return $this->handle_authorization_code( $request, $client, $code_verifier, $authed_with_secret );
		}

		if ( 'refresh_token' === $grant_type ) {
			return $this->handle_refresh_token( $request, $client );
		}

		return $this->oauth_error( 'unsupported_grant_type', 400 );
	}

	public function revoke( WP_REST_Request $request ) {
		$token         = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$client_id     = sanitize_text_field( (string) $request->get_param( 'client_id' ) );
		$client_secret = sanitize_text_field( (string) $request->get_param( 'client_secret' ) );

		$client = WPAIB_OAuth_Client_Manager::validate( $client_id, $client_secret );
		if ( ! $client ) {
			return $this->oauth_error( 'invalid_client', 401 );
		}

		if ( ! empty( $token ) ) {
			WPAIB_OAuth_Server::revoke_token( $token, $client->client_id );
		}

		return rest_ensure_response( array() );
	}

	private function handle_authorization_code( WP_REST_Request $request, $client, $code_verifier = '', $authed_with_secret = false ) {
		$code         = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$redirect_uri = esc_url_raw( (string) $request->get_param( 'redirect_uri' ) );

		if ( empty( $code ) || empty( $redirect_uri ) ) {
			return $this->oauth_error( 'invalid_request', 400 );
		}

		$data = WPAIB_OAuth_Server::consume_auth_code( $code, $client->client_id, $redirect_uri );
		if ( ! $data ) {
			return $this->oauth_error( 'invalid_grant', 400 );
		}

		// Se il client non si è autenticato col secret, il code DEVE avere un
		// code_challenge da verificare via PKCE. Senza questo vincolo un code di
		// un client confidenziale (emesso senza challenge) sarebbe riscattabile
		// da chiunque conosca il client_id passando un code_verifier qualsiasi.
		if ( ! $authed_with_secret && empty( $data['code_challenge'] ) ) {
			return $this->oauth_error( 'invalid_grant', 400 );
		}

		if ( ! empty( $data['code_challenge'] ) &&
			( empty( $code_verifier ) || ! $this->verify_pkce( $code_verifier, $data['code_challenge'], $data['code_challenge_method'] ) ) ) {
			return $this->oauth_error( 'invalid_grant', 400 );
		}

		$tokens = WPAIB_OAuth_Server::create_token_pair( $client->client_id, $data['user_id'], $data['scope'] );
		if ( is_wp_error( $tokens ) ) {
			return $this->oauth_error( 'server_error', 500 );
		}

		return rest_ensure_response( $tokens );
	}

	/**
	 * Verifica PKCE code_verifier contro code_challenge (RFC 7636).
	 *
	 * @param string $verifier   Verifier in chiaro dal client.
	 * @param string $challenge  Challenge salvato all'authorize.
	 * @param string $method     'S256' o 'plain'.
	 * @return bool
	 */
	private function verify_pkce( $verifier, $challenge, $method ) {
		if ( 'plain' === $method ) {
			return hash_equals( $challenge, $verifier );
		}
		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		return hash_equals( $challenge, $computed );
	}

	private function handle_refresh_token( WP_REST_Request $request, $client ) {
		$refresh_token = sanitize_text_field( (string) $request->get_param( 'refresh_token' ) );

		if ( empty( $refresh_token ) ) {
			return $this->oauth_error( 'invalid_request', 400 );
		}

		$tokens = WPAIB_OAuth_Server::consume_refresh_token( $refresh_token, $client->client_id );
		if ( ! $tokens || is_wp_error( $tokens ) ) {
			return $this->oauth_error( 'invalid_grant', 400 );
		}

		return rest_ensure_response( $tokens );
	}

	private function oauth_error( $error, $status ) {
		$response = new WP_REST_Response( array( 'error' => $error ), $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}
}
