<?php
/**
 * Bootstrap del plugin.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe principale di orchestrazione.
 */
class WPAIB_Plugin {

	/**
	 * Inizializza tutti gli hook necessari.
	 *
	 * @return void
	 */
	public static function init() {
		// Carica le traduzioni del plugin.
		load_plugin_textdomain( 'wp-ai-bridge', false, dirname( plugin_basename( WPAIB_PLUGIN_FILE ) ) . '/languages' );

		// Registra route REST.
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// UI di amministrazione.
		if ( is_admin() ) {
			WPAIB_Admin::init();
		}

		// Forza HTTPS sugli endpoint del plugin.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'enforce_https' ), 10, 3 );
	}

	/**
	 * Registra tutte le route REST del plugin.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$posts_controller    = new WPAIB_Posts_Controller();
		$media_controller    = new WPAIB_Media_Controller();
		$taxonomy_controller = new WPAIB_Taxonomy_Controller();
		$pages_controller    = new WPAIB_Pages_Controller();
		$site_controller     = new WPAIB_Site_Controller();
		$search_controller   = new WPAIB_Search_Controller();
		$mcp_controller      = new WPAIB_MCP_Controller();
		$mcp_http_controller = new WPAIB_MCP_HTTP_Controller();
		$openapi_controller  = new WPAIB_OpenAPI_Controller();

		$posts_controller->register_routes();
		$media_controller->register_routes();
		$taxonomy_controller->register_routes();
		$pages_controller->register_routes();
		$site_controller->register_routes();
		$search_controller->register_routes();
		$mcp_controller->register_routes();
		$mcp_http_controller->register_routes();
		$openapi_controller->register_routes();
	}

	/**
	 * Rifiuta richieste non HTTPS verso gli endpoint del plugin.
	 *
	 * @param mixed           $result  Risultato corrente.
	 * @param WP_REST_Server  $server  Istanza server REST.
	 * @param WP_REST_Request $request Richiesta in arrivo.
	 * @return mixed
	 */
	public static function enforce_https( $result, $server, $request ) {
		$route = $request->get_route();

		// Applica solo agli endpoint di questo plugin.
		if ( strpos( $route, '/' . WPAIB_API_NAMESPACE ) !== 0 ) {
			return $result;
		}

		// Consenti HTTP in ambiente di sviluppo locale per facilitare i test
		$host     = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
		$is_local = in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) || strpos( $host, 'localhost' ) !== false || strpos( $host, '127.0.0.1' ) !== false;

		if ( ! is_ssl() && ! $is_local ) {
			return new WP_Error(
				'wpaib_https_required',
				__( 'HTTPS is required for this endpoint.', 'wp-ai-bridge' ),
				array( 'status' => 403 )
			);
		}

		return $result;
	}
}
