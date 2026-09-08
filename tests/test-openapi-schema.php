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
$GLOBALS['test_options'] = array();

function get_option( $name, $default = false ) {
	return isset( $GLOBALS['test_options'][ $name ] ) ? $GLOBALS['test_options'][ $name ] : $default;
}

function update_option( $name, $value ) {
	$GLOBALS['test_options'][ $name ] = $value;
	return true;
}

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

function __( $text, $domain = 'default' ) {
	return $text;
}

class WP_REST_Server {
	const READABLE = 'GET';
}

require_once WPAIB_TEST_PLUGIN_DIR . '/admin/class-wpaib-admin.php';
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

// Verifica allineamento e conteggio di tutti i 32 tool MCP registrati
$admin_tool_slugs = WPAIB_Admin::get_all_tool_slugs();
wpaib_check( 'WPAIB_Admin registers exactly 32 MCP tools', count( $admin_tool_slugs ), 32 );
wpaib_check( 'all 32 tool slugs are unique', count( array_unique( $admin_tool_slugs ) ), 32 );

// Verifica categorie
$categories = WPAIB_Admin::get_tool_categories();
wpaib_check( 'WPAIB_Admin defines 8 tool categories', count( $categories ), 8 );
wpaib_check( 'categories include Plugin', isset( $categories['Plugin'] ), true );
wpaib_check( 'categories include Aggiornamenti', isset( $categories['Aggiornamenti'] ), true );

// Verifica filtraggio openapi.json quando vengono disabilitati i tool
// Simuliamo la disabilitazione dei tool Plugin e Aggiornamenti (8 tool)
$GLOBALS['test_options']['wpaib_disabled_tools'] = array(
	'get_plugins', 'activate_plugin', 'deactivate_plugin', 'delete_plugin',
	'get_updates', 'get_changelog', 'apply_update', 'bulk_update',
);

$filtered_schema = $controller->get_openapi_schema();
$filtered_paths  = array_keys( $filtered_schema['paths'] );
$filtered_op_ids = array();
foreach ( $filtered_schema['paths'] as $p => $ops ) {
	foreach ( $ops as $m => $op ) {
		if ( ! empty( $op['operationId'] ) ) {
			$filtered_op_ids[] = $op['operationId'];
		}
	}
}

wpaib_check( 'filtered openapi has <= 30 operations for ChatGPT Actions', count( $filtered_op_ids ) <= 30, true );
wpaib_check( 'filtered openapi has exactly 25 operations', count( $filtered_op_ids ), 25 );
wpaib_check( 'filtered openapi does not declare /plugins', in_array( '/plugins', $filtered_paths, true ), false );
wpaib_check( 'filtered openapi does not declare /plugins/activate', in_array( '/plugins/activate', $filtered_paths, true ), false );
wpaib_check( 'filtered openapi does not declare /updates', in_array( '/updates', $filtered_paths, true ), false );
wpaib_check( 'filtered openapi still declares /posts', in_array( '/posts', $filtered_paths, true ), true );

printf( "\n%d operations over %d paths, %d bytes of JSON\n", count( $operation_ids ), count( $paths ), strlen( $json ) );
printf( "Filtered (for ChatGPT): %d operations over %d paths\n", count( $filtered_op_ids ), count( $filtered_paths ) );

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
