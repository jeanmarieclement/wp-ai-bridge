<?php
/**
 * Controller MCP Streamable HTTP — espone un endpoint JSON-RPC 2.0 remoto
 * compatibile con Claude Desktop, Cursor, Claude Code e qualsiasi client MCP.
 *
 * Endpoint: POST /wp-json/wpaib/v1/mcp
 * Auth:     header X-API-Key (non richiesta su initialize/ping/notifications)
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implementa MCP Streamable HTTP transport (protocollo 2024-11-05).
 */
class WPAIB_MCP_HTTP_Controller {

	/**
	 * Header CORS fissi aggiunti a ogni risposta MCP.
	 *
	 * @var array
	 */
	private static $cors = array(
		'Access-Control-Allow-Methods' => 'POST, OPTIONS',
		'Access-Control-Allow-Headers' => 'Content-Type, X-API-Key, Authorization, Mcp-Session-Id',
		'Content-Type'                 => 'application/json',
	);

	/**
	 * Registra le route REST e il filtro CORS per questo endpoint.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/mcp',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'OPTIONS',
					'callback'            => array( $this, 'handle_preflight' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Priority 20: gira dopo rest_send_cors_headers di WordPress (priority 10)
		// così possiamo sovrascrivere l'header che WP imposta incondizionatamente.
		add_filter( 'rest_pre_serve_request', array( $this, 'override_cors_headers' ), 20, 3 );
	}

	/**
	 * Sovrascrive gli header CORS di WordPress per le sole route /mcp.
	 * WordPress riflette qualsiasi Origin indiscriminatamente; qui applichiamo
	 * la policy corretta (allowlist in produzione, wildcard in dev).
	 *
	 * @param bool             $served  Passato così com'è.
	 * @param WP_REST_Response $result  Risposta REST.
	 * @param WP_REST_Request  $request Richiesta REST.
	 * @return bool
	 */
	public function override_cors_headers( $served, $result, $request ) {
		if ( false === strpos( $request->get_route(), '/wpaib/v1/mcp' ) ) {
			return $served;
		}

		$origin = self::get_allowed_origin();

		if ( empty( $origin ) ) {
			header_remove( 'Access-Control-Allow-Origin' );
		} else {
			header( 'Access-Control-Allow-Origin: ' . $origin );
			if ( '*' !== $origin ) {
				header( 'Vary: Origin', false );
			}
		}

		// API key auth — nessun cookie, credentials non necessarie.
		header_remove( 'Access-Control-Allow-Credentials' );

		return $served;
	}

	/**
	 * Risponde al preflight CORS OPTIONS.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_preflight() {
		return $this->build_response( null, null, 204 );
	}

	/**
	 * Entry point principale: smista il messaggio JSON-RPC al metodo corretto.
	 *
	 * @param WP_REST_Request $request Richiesta REST.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) || ! isset( $body['method'] ) ) {
			return $this->rpc_error( null, -32600, 'Invalid Request.' );
		}

		$method = (string) $body['method'];
		$id     = array_key_exists( 'id', $body ) ? $body['id'] : null;
		$params = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();

		// Le notifiche non hanno id e non richiedono risposta (202 No Content).
		$is_notification = ! array_key_exists( 'id', $body );

		switch ( $method ) {

			case 'initialize':
				return $this->build_response( $id, array(
					'protocolVersion' => '2024-11-05',
					'capabilities'    => array( 'tools' => new stdClass() ),
					'serverInfo'      => array(
						'name'    => 'wp-ai-bridge',
						'version' => WPAIB_VERSION,
					),
				) );

			case 'notifications/initialized':
			case 'notifications/cancelled':
				return $this->build_response( null, null, 202 );

			case 'ping':
				return $this->build_response( $id, new stdClass() );

			case 'tools/list':
				$auth = WPAIB_Auth::authorize( $request, 'edit_posts' );
				if ( is_wp_error( $auth ) ) {
					return $this->wp_error_to_rpc( $id, $auth );
				}
				$mcp    = new WPAIB_MCP_Controller();
				$result = $mcp->get_tools()->get_data();
				return $this->build_response( $id, $result );

			case 'tools/call':
				$auth = WPAIB_Auth::authorize( $request, 'edit_posts' );
				if ( is_wp_error( $auth ) ) {
					return $this->wp_error_to_rpc( $id, $auth );
				}
				return $this->build_response( $id, $this->call_tool( $params ) );

			default:
				if ( $is_notification ) {
					return $this->build_response( null, null, 202 );
				}
				return $this->rpc_error( $id, -32601, 'Method not found: ' . esc_html( $method ) );
		}
	}

	/**
	 * Esegue un tool delegando a WPAIB_MCP_Controller::execute_tool().
	 *
	 * @param array $params Parametri MCP (name, arguments).
	 * @return array Risultato MCP content block.
	 */
	private function call_tool( array $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$sub_req = new WP_REST_Request( 'POST', '/wpaib/v1/tools/execute' );
		$sub_req->set_header( 'Content-Type', 'application/json' );
		$sub_req->set_body( wp_json_encode( array( 'tool' => $name, 'arguments' => $args ) ) );

		$mcp    = new WPAIB_MCP_Controller();
		$result = $mcp->execute_tool( $sub_req );

		if ( is_wp_error( $result ) ) {
			return array(
				'content' => array( array( 'type' => 'text', 'text' => $result->get_error_message() ) ),
				'isError' => true,
			);
		}

		$data = $result->get_data();
		$text = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return array(
			'content' => array( array( 'type' => 'text', 'text' => (string) $text ) ),
			'isError' => false,
		);
	}

	/**
	 * Costruisce una WP_REST_Response JSON-RPC con header CORS.
	 *
	 * @param mixed    $id     ID della richiesta JSON-RPC (null per notifiche).
	 * @param mixed    $result Payload result.
	 * @param int      $status HTTP status code.
	 * @return WP_REST_Response
	 */
	private function build_response( $id, $result, $status = 200 ) {
		if ( 202 === $status || 204 === $status ) {
			$body = null;
		} else {
			$body = array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			);
		}

		$response = new WP_REST_Response( $body, $status );
		foreach ( self::$cors as $header => $value ) {
			$response->header( $header, $value );
		}

		$origin = self::get_allowed_origin();
		if ( ! empty( $origin ) ) {
			$response->header( 'Access-Control-Allow-Origin', $origin );
			if ( '*' !== $origin ) {
				$response->header( 'Vary', 'Origin' );
			}
		}

		return $response;
	}

	/**
	 * Restituisce il valore corretto per Access-Control-Allow-Origin.
	 *
	 * In local/development: wildcard (*).
	 * In produzione: riflette l'Origin solo se presente nella allowlist
	 * configurabile tramite il filtro `wpaib_allowed_origins`.
	 *
	 * @return string Origin consentito, oppure stringa vuota (nessun header).
	 */
	private static function get_allowed_origin() {
		if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return '*';
		}

		$request_origin = isset( $_SERVER['HTTP_ORIGIN'] )
			? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) )
			: '';

		if ( empty( $request_origin ) ) {
			return '';
		}

		/**
		 * Allowlist degli Origin cross-site autorizzati all'endpoint MCP.
		 * In produzione la lista è vuota di default: nessuna richiesta cross-origin.
		 *
		 * Esempio in wp-config.php:
		 *   add_filter( 'wpaib_allowed_origins', fn($o) => array_merge($o, ['https://app.esempio.it']) );
		 *
		 * @param string[] $origins Array di URL di origine autorizzati.
		 */
		$allowed = apply_filters( 'wpaib_allowed_origins', array() );

		if ( in_array( $request_origin, $allowed, true ) ) {
			return $request_origin;
		}

		return '';
	}

	/**
	 * Crea una risposta JSON-RPC di errore.
	 *
	 * @param mixed  $id      ID richiesta.
	 * @param int    $code    Codice errore JSON-RPC.
	 * @param string $message Messaggio errore.
	 * @return WP_REST_Response
	 */
	private function rpc_error( $id, $code, $message ) {
		$response = new WP_REST_Response( array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array( 'code' => $code, 'message' => $message ),
		), 200 );
		foreach ( self::$cors as $header => $value ) {
			$response->header( $header, $value );
		}
		return $response;
	}

	/**
	 * Converte un WP_Error in risposta JSON-RPC di errore.
	 *
	 * @param mixed    $id    ID richiesta.
	 * @param WP_Error $error Errore WP.
	 * @return WP_REST_Response
	 */
	private function wp_error_to_rpc( $id, WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 401;
		$code   = 429 === $status ? -32000 : -32001;
		return $this->rpc_error( $id, $code, $error->get_error_message() );
	}
}
