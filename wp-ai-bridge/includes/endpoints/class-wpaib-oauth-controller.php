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

		$client = WPAIB_OAuth_Client_Manager::validate( $client_id, $client_secret );
		if ( ! $client ) {
			return $this->oauth_error( 'invalid_client', 401 );
		}

		if ( 'authorization_code' === $grant_type ) {
			return $this->handle_authorization_code( $request, $client );
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

		if ( ! WPAIB_OAuth_Client_Manager::validate( $client_id, $client_secret ) ) {
			return $this->oauth_error( 'invalid_client', 401 );
		}

		if ( ! empty( $token ) ) {
			WPAIB_OAuth_Server::revoke_token( $token );
		}

		return rest_ensure_response( array() );
	}

	private function handle_authorization_code( WP_REST_Request $request, $client ) {
		$code         = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$redirect_uri = esc_url_raw( (string) $request->get_param( 'redirect_uri' ) );

		if ( empty( $code ) || empty( $redirect_uri ) ) {
			return $this->oauth_error( 'invalid_request', 400 );
		}

		$data = WPAIB_OAuth_Server::consume_auth_code( $code, $client->client_id, $redirect_uri );
		if ( ! $data ) {
			return $this->oauth_error( 'invalid_grant', 400 );
		}

		$tokens = WPAIB_OAuth_Server::create_token_pair( $client->client_id, $data['user_id'], $data['scope'] );
		if ( is_wp_error( $tokens ) ) {
			return $this->oauth_error( 'server_error', 500 );
		}

		return rest_ensure_response( $tokens );
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
