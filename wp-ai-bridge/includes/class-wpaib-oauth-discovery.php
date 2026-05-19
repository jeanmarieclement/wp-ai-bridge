<?php
/**
 * OAuth 2.0 Authorization Server Metadata (RFC 8414) e endpoint /token shortcut.
 *
 * Espone:
 *  - /.well-known/oauth-authorization-server  (discovery metadata)
 *  - /.well-known/oauth-protected-resource    (resource metadata per MCP)
 *  - /token                                   (alias per /wp-json/wpaib/v1/oauth/token)
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIB_OAuth_Discovery {

	public static function init_hooks() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule( '^\.well-known/oauth-authorization-server$', 'index.php?wpaib_discovery=oauth-authorization-server', 'top' );
		add_rewrite_rule( '^\.well-known/oauth-protected-resource$', 'index.php?wpaib_discovery=oauth-protected-resource', 'top' );
		add_rewrite_rule( '^token/?$', 'index.php?wpaib_discovery=token', 'top' );
	}

	public static function register_query_var( $vars ) {
		$vars[] = 'wpaib_discovery';
		return $vars;
	}

	public static function handle() {
		$action = get_query_var( 'wpaib_discovery' );
		if ( ! $action ) {
			return;
		}

		nocache_headers();
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key' );

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			http_response_code( 204 );
			exit;
		}

		switch ( $action ) {
			case 'oauth-authorization-server':
				self::serve_authorization_server_metadata();
				break;
			case 'oauth-protected-resource':
				self::serve_protected_resource_metadata();
				break;
			case 'token':
				self::handle_token();
				break;
		}

		exit;
	}

	private static function serve_json( array $data ) {
		header( 'Content-Type: application/json' );
		echo wp_json_encode( $data );
	}

	private static function serve_authorization_server_metadata() {
		self::serve_json( array(
			'issuer'                                => home_url( '/' ),
			'authorization_endpoint'                => home_url( '/authorize' ),
			'token_endpoint'                        => home_url( '/token' ),
			'revocation_endpoint'                   => rest_url( WPAIB_API_NAMESPACE . '/oauth/revoke' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post' ),
		) );
	}

	private static function serve_protected_resource_metadata() {
		self::serve_json( array(
			'resource'                 => rest_url( WPAIB_API_NAMESPACE . '/mcp' ),
			'authorization_servers'    => array( home_url( '/' ) ),
			'bearer_methods_supported' => array( 'header' ),
		) );
	}

	private static function handle_token() {
		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) ) : '';

		if ( false !== strpos( $content_type, 'application/json' ) ) {
			$raw    = file_get_contents( 'php://input' );
			$params = json_decode( $raw, true );
			if ( ! is_array( $params ) ) {
				$params = array();
			}
		} else {
			$params = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		$request = new WP_REST_Request( 'POST', '/' . WPAIB_API_NAMESPACE . '/oauth/token' );
		foreach ( $params as $key => $value ) {
			$request->set_param( sanitize_key( $key ), wp_unslash( (string) $value ) );
		}

		$controller = new WPAIB_OAuth_Controller();
		$response   = $controller->token( $request );

		$status = $response->get_status();
		http_response_code( $status );
		header( 'Content-Type: application/json' );
		header( 'Cache-Control: no-store' );
		header( 'Pragma: no-cache' );
		echo wp_json_encode( $response->get_data() );
	}
}
