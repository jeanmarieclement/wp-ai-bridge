#!/usr/bin/env php
<?php
/**
 * WP AI Bridge — MCP Stdio Connector in PHP nativo
 *
 * Fa da ponte sul protocollo JSON-RPC 2.0 (stdio) tra i client/IDE locali 
 * (Cursor, Roo Code, Claude Desktop, Antigravity) e l'istanza WordPress locale o remota.
 * 
 * Vantaggi principali:
 * 1. Zero dipendenze esterne.
 * 2. Zero Node.js richiesto (sfrutta l'interprete PHP nativo preesistente).
 * 3. Supporto nativo integrato in WordPress.
 */

if ( count( $argv ) < 3 ) {
	fwrite( STDERR, "Errore: Parametri mancanti.\nUso: php mcp-connector.php <BASE_REST_URL> <API_KEY>\n" );
	exit( 1 );
}

$base_url = rtrim( $argv[1], '/' );
$api_key  = $argv[2];

/**
 * Invia una risposta formattata in JSON-RPC 2.0 su stdout.
 *
 * @param array $response Array associativo della risposta.
 */
function send_mcp_response( $response ) {
	echo json_encode( $response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
}

/**
 * Verifica se un base URL risponde sull'endpoint /tools (HTTP non-404).
 *
 * @param string $url     Base URL da testare.
 * @param string $api_key Chiave API.
 * @return bool
 */
function probe_url( $url, $api_key ) {
	$ctx = stream_context_create( array(
		'http' => array(
			'method'        => 'GET',
			'header'        => "X-API-Key: {$api_key}\r\n",
			'timeout'       => 5,
			'ignore_errors' => true,
		),
		'ssl'  => array( 'verify_peer' => false, 'verify_peer_name' => false ),
	) );
	$res = @file_get_contents( $url . '/tools', false, $ctx );
	if ( false === $res ) {
		return false;
	}
	$status = 0;
	if ( isset( $http_response_header[0] ) ) {
		preg_match( '/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m );
		$status = isset( $m[1] ) ? (int) $m[1] : 0;
	}
	return 0 !== $status && 404 !== $status;
}

/**
 * Converte un base URL tra formato pretty permalink e query string.
 * Ritorna null se la conversione non è possibile.
 *
 * @param string $url Base URL corrente.
 * @return string|null
 */
function fallback_url( $url ) {
	// Query string → pretty permalink.
	if ( strpos( $url, '?rest_route=' ) !== false ) {
		$parts = parse_url( $url );
		$base  = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$base .= ':' . $parts['port'];
		}
		parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );
		$route = isset( $query['rest_route'] ) ? $query['rest_route'] : '';
		return rtrim( $base . '/wp-json' . $route, '/' );
	}

	// Pretty permalink → query string.
	$pos = strpos( $url, '/wp-json/' );
	if ( false !== $pos ) {
		$base  = substr( $url, 0, $pos );
		$route = substr( $url, $pos + strlen( '/wp-json' ) );
		return $base . '/?rest_route=' . $route;
	}

	return null;
}

// Verifica URL e applica fallback automatico se necessario.
if ( ! probe_url( $base_url, $api_key ) ) {
	$alt = fallback_url( $base_url );
	if ( null !== $alt && probe_url( $alt, $api_key ) ) {
		fwrite( STDERR, "Info: URL fallback attivo → {$alt}\n" );
		$base_url = $alt;
	}
}

// Ciclo di ascolto infinito sullo standard input (canale stdio MCP)
while ( ( $line = fgets( STDIN ) ) !== false ) {
	$line = trim( $line );
	if ( '' === $line ) {
		continue;
	}

	$req = json_decode( $line, true );
	if ( ! is_array( $req ) || ! isset( $req['method'] ) ) {
		continue;
	}

	$id     = isset( $req['id'] ) ? $req['id'] : null;
	$method = $req['method'];

	// 1. Handshake di Inizializzazione (initialize)
	if ( 'initialize' === $method ) {
		send_mcp_response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => array(
					'protocolVersion' => '2024-11-05',
					'capabilities'    => array(
						'tools' => new stdClass(),
					),
					'serverInfo'      => array(
						'name'    => 'wp-ai-bridge',
						'version' => '1.0.0',
					),
				),
			)
		);
		continue;
	}

	// 2. Conferma di inizializzazione completata
	if ( 'notifications/initialized' === $method ) {
		continue;
	}

	// 3. Discovery dei Tool (tools/list)
	if ( 'tools/list' === $method ) {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'GET',
					'header'        => "X-API-Key: " . $api_key . "\r\n",
					'timeout'       => 15,
					'ignore_errors' => true,
				),
				'ssl'  => array(
					'verify_peer'      => false,
					'verify_peer_name' => false,
				),
			)
		);

		$res = @file_get_contents( $base_url . '/tools', false, $context );
		$http_status = 0;
		if ( isset( $http_response_header[0] ) ) {
			preg_match( '/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m );
			$http_status = isset( $m[1] ) ? (int) $m[1] : 0;
		}

		if ( false === $res || $http_status >= 400 ) {
			$err_body = ( false !== $res ) ? json_decode( $res, true ) : null;
			$err_msg  = ( is_array( $err_body ) && isset( $err_body['message'] ) )
				? $err_body['message']
				: 'Errore di connessione al server WordPress durante il recupero dei tool. (HTTP ' . $http_status . ')';
			send_mcp_response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => -32603,
						'message' => $err_msg,
					),
				)
			);
			continue;
		}

		$data = json_decode( $res, true );
		if ( ! is_array( $data ) || ! isset( $data['tools'] ) ) {
			$err_msg = ( is_array( $data ) && isset( $data['message'] ) ) ? $data['message'] : 'Risposta non valida dal server WordPress.';
			send_mcp_response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => -32603,
						'message' => 'Errore WP REST: ' . $err_msg,
					),
				)
			);
			continue;
		}

		send_mcp_response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => array(
					'tools' => $data['tools'],
				),
			)
		);
		continue;
	}

	// 4. Esecuzione del singolo Tool (tools/call)
	if ( 'tools/call' === $method ) {
		$name = isset( $req['params']['name'] ) ? $req['params']['name'] : '';
		$args = isset( $req['params']['arguments'] ) ? $req['params']['arguments'] : array();

		$payload = json_encode(
			array(
				'tool'      => $name,
				'arguments' => $args,
			)
		);

		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'POST',
					'header'        => "Content-Type: application/json\r\n" .
								 "X-API-Key: " . $api_key . "\r\n" .
								 "Content-Length: " . strlen( $payload ) . "\r\n",
					'content'       => $payload,
					'timeout'       => 30,
					'ignore_errors' => true,
				),
				'ssl'  => array(
					'verify_peer'      => false,
					'verify_peer_name' => false,
				),
			)
		);

		$res = @file_get_contents( $base_url . '/tools/execute', false, $context );
		$http_status = 0;
		if ( isset( $http_response_header[0] ) ) {
			preg_match( '/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m );
			$http_status = isset( $m[1] ) ? (int) $m[1] : 0;
		}
		$is_error     = ( false === $res || $http_status >= 400 );
		$content_text = ( false === $res ) ? 'Errore di connessione durante l\'esecuzione del tool.' : $res;

		// Formatta output JSON in modo leggibile se la risposta è valida
		$decoded = @json_decode( $content_text, true );
		if ( null !== $decoded ) {
			$content_text = json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		send_mcp_response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => (string) $content_text,
						),
					),
					'isError' => $is_error,
				),
			)
		);
		continue;
	}

	// Metodo JSON-RPC non supportato
	if ( null !== $id ) {
		send_mcp_response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => -32601,
					'message' => 'Metodo MCP non supportato dal bridge PHP.',
				),
			)
		);
	}
}
