<?php
/**
 * Coerenza del documento OpenAPI generato da WPAIB_OpenAPI_Controller.
 *
 * Lo schema è costruito a mano come array PHP annidato: è facile che un path
 * nuovo resti fuori, che un operationId venga duplicato copiando un blocco, o
 * che un parametro perda il nome. Nessuna di queste cose fa fallire PHP, ma
 * tutte rompono l'import su ChatGPT, Gemini o Claude.
 *
 * @package WPAIBridge
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * Stub minimi: al controller serve solo poter costruire URL e registrare rotte.
 */
function untrailingslashit( $s ) {
	return rtrim( $s, '/' );
}

function rest_url( $p = '' ) {
	return 'https://example.test/wp-json/' . $p;
}

function home_url( $p = '' ) {
	return 'https://example.test' . $p;
}

function rest_ensure_response( $d ) {
	return $d;
}

function register_rest_route() {}

class WP_REST_Server {
	const READABLE = 'GET';
}

require_once WPAIB_TEST_PLUGIN_DIR . '/includes/endpoints/class-wpaib-openapi-controller.php';

$controller = new WPAIB_OpenAPI_Controller();
$schema     = $controller->get_openapi_schema();
$json       = wp_json_encode_compat( $schema );

wpaib_check( 'the schema serialises to JSON', json_last_error(), JSON_ERROR_NONE );

// Ogni rotta di lettura che l'export attraversa deve essere documentata: è il
// documento che i builder AI importano, e ciò che non c'è non è raggiungibile.
$paths    = array_keys( $schema['paths'] );
$required = array(
	'/posts',
	'/pages',
	'/media',
	'/comments',
	'/users',
	'/users/{id}',
	'/menus',
	'/theme',
	'/site',
	'/site/full',
	'/categories',
	'/tags',
	'/cpt/{type}',
);

foreach ( $required as $path ) {
	wpaib_check( "the schema declares {$path}", in_array( $path, $paths, true ), true );
}

$operation_ids = array();

foreach ( $schema['paths'] as $path => $operations ) {
	foreach ( $operations as $method => $operation ) {
		if ( empty( $operation['operationId'] ) ) {
			wpaib_fail( "{$method} {$path} has no operationId" );
		} else {
			$operation_ids[] = $operation['operationId'];
		}

		if ( empty( $operation['responses'] ) || ! is_array( $operation['responses'] ) ) {
			wpaib_fail( "{$method} {$path} has no responses" );
		} else {
			if ( array_keys( $operation['responses'] ) === range( 0, count( $operation['responses'] ) - 1 ) ) {
				wpaib_fail( "{$method} {$path} responses is a sequential array (list) instead of a status-code keyed map" );
			}
			foreach ( $operation['responses'] as $status_code => $resp ) {
				if ( ! is_array( $resp ) || empty( $resp['description'] ) ) {
					wpaib_fail( "{$method} {$path} response '{$status_code}' is malformed or missing description" );
				}
			}
		}

		if ( ! isset( $operation['parameters'] ) ) {
			continue;
		}

		foreach ( $operation['parameters'] as $i => $parameter ) {
			if ( ! is_array( $parameter ) || empty( $parameter['name'] ) || empty( $parameter['in'] ) ) {
				wpaib_fail( "{$method} {$path} parameter #{$i} is malformed" );
			}
		}
	}
}

// Verifica che nel JSON finale ogni responses sia un oggetto e non una lista.
$decoded_json = json_decode( $json );
foreach ( $decoded_json->paths as $path => $operations ) {
	foreach ( $operations as $method => $operation ) {
		if ( isset( $operation->responses ) && is_array( $operation->responses ) ) {
			wpaib_fail( "JSON responses for {$method} {$path} serialized as an array instead of an object" );
		}
	}
}

wpaib_check(
	'operationIds are unique',
	count( $operation_ids ) === count( array_unique( $operation_ids ) ),
	true
);

printf( "\n%d operations over %d paths, %d bytes of JSON\n", count( $operation_ids ), count( $paths ), strlen( $json ) );

wpaib_finish( 'openapi-schema' );

/**
 * json_encode() isolato, così json_last_error() riflette solo questa chiamata.
 *
 * @param mixed $data Dati da serializzare.
 * @return string
 */
function wp_json_encode_compat( $data ) {
	return (string) json_encode( $data );
}
