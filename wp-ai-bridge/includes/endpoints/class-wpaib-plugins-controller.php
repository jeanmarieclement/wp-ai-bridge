<?php
/**
 * Controller per la gestione dei plugin WordPress.
 *
 * Richiede la capability WordPress `activate_plugins` (amministratori).
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint /plugins: lista, attiva, disattiva ed elimina plugin.
 */
class WPAIB_Plugins_Controller {

	/**
	 * Carica le funzioni WP per la gestione plugin se non ancora disponibili.
	 *
	 * @return void
	 */
	private function maybe_load_plugin_functions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'delete_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
	}

	/**
	 * Registra le route REST.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/plugins',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_plugins' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'activate_plugins' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_plugin' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'delete_plugins' ),
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/plugins/activate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'activate_plugin_handler' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'activate_plugins' ),
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/plugins/deactivate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'deactivate_plugin_handler' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'activate_plugins' ),
				),
			)
		);
	}

	/**
	 * Valida il percorso del plugin dalla richiesta.
	 *
	 * Verifica che il valore non contenga traversal di path e che corrisponda
	 * a un plugin effettivamente installato (unico controllo di sicurezza decisivo).
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return string|WP_Error Plugin path validato o WP_Error.
	 */
	private function resolve_plugin( WP_REST_Request $request ) {
		$plugin = $request->get_param( 'plugin' );

		if ( empty( $plugin ) || ! is_string( $plugin ) ) {
			return new WP_Error(
				'wpaib_missing_plugin',
				__( 'Plugin path is required.', 'wp-ai-bridge' ),
				array( 'status' => 400 )
			);
		}

		$plugin = sanitize_text_field( $plugin );

		if ( false !== strpos( $plugin, '..' ) ) {
			return new WP_Error(
				'wpaib_invalid_plugin',
				__( 'Invalid plugin path.', 'wp-ai-bridge' ),
				array( 'status' => 400 )
			);
		}

		if ( '.php' !== substr( $plugin, -4 ) ) {
			return new WP_Error(
				'wpaib_invalid_plugin',
				__( 'Invalid plugin path.', 'wp-ai-bridge' ),
				array( 'status' => 400 )
			);
		}

		$this->maybe_load_plugin_functions();
		$all_plugins = get_plugins();

		if ( ! isset( $all_plugins[ $plugin ] ) ) {
			return new WP_Error(
				'wpaib_plugin_not_found',
				__( 'Plugin not found.', 'wp-ai-bridge' ),
				array( 'status' => 404 )
			);
		}

		return $plugin;
	}

	/**
	 * Elenca tutti i plugin installati con il loro stato.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function list_plugins( WP_REST_Request $request ) {
		$this->maybe_load_plugin_functions();

		$all_plugins = get_plugins();
		$result      = array();

		foreach ( $all_plugins as $plugin_file => $data ) {
			$result[] = array(
				'plugin'      => $plugin_file,
				'name'        => $data['Name'],
				'version'     => $data['Version'],
				'description' => wp_strip_all_tags( $data['Description'] ),
				'author'      => wp_strip_all_tags( $data['Author'] ),
				'status'      => is_plugin_active( $plugin_file ) ? 'active' : 'inactive',
			);
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Attiva un plugin.
	 *
	 * @param WP_REST_Request $request Richiesta con campo `plugin`.
	 * @return WP_REST_Response|WP_Error
	 */
	public function activate_plugin_handler( WP_REST_Request $request ) {
		$plugin = $this->resolve_plugin( $request );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}

		if ( is_plugin_active( $plugin ) ) {
			return new WP_REST_Response(
				array(
					'plugin'  => $plugin,
					'status'  => 'active',
					'message' => __( 'Plugin is already active.', 'wp-ai-bridge' ),
				),
				200
			);
		}

		$result = activate_plugin( $plugin );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'plugin'  => $plugin,
				'status'  => 'active',
				'message' => __( 'Plugin activated successfully.', 'wp-ai-bridge' ),
			),
			200
		);
	}

	/**
	 * Disattiva un plugin.
	 *
	 * Non è possibile disattivare WP AI Bridge tramite la propria API.
	 *
	 * @param WP_REST_Request $request Richiesta con campo `plugin`.
	 * @return WP_REST_Response|WP_Error
	 */
	public function deactivate_plugin_handler( WP_REST_Request $request ) {
		$plugin = $this->resolve_plugin( $request );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}

		if ( plugin_basename( WPAIB_PLUGIN_FILE ) === $plugin ) {
			return new WP_Error(
				'wpaib_cannot_deactivate_self',
				__( 'Cannot deactivate WP AI Bridge via its own API.', 'wp-ai-bridge' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_plugin_active( $plugin ) ) {
			return new WP_REST_Response(
				array(
					'plugin'  => $plugin,
					'status'  => 'inactive',
					'message' => __( 'Plugin is already inactive.', 'wp-ai-bridge' ),
				),
				200
			);
		}

		deactivate_plugins( $plugin );

		return new WP_REST_Response(
			array(
				'plugin'  => $plugin,
				'status'  => 'inactive',
				'message' => __( 'Plugin deactivated successfully.', 'wp-ai-bridge' ),
			),
			200
		);
	}

	/**
	 * Elimina un plugin dal filesystem.
	 *
	 * Il plugin viene disattivato prima dell'eliminazione se necessario.
	 * Non è possibile eliminare WP AI Bridge tramite la propria API.
	 *
	 * @param WP_REST_Request $request Richiesta con campo `plugin`.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_plugin( WP_REST_Request $request ) {
		$plugin = $this->resolve_plugin( $request );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}

		if ( plugin_basename( WPAIB_PLUGIN_FILE ) === $plugin ) {
			return new WP_Error(
				'wpaib_cannot_delete_self',
				__( 'Cannot delete WP AI Bridge via its own API.', 'wp-ai-bridge' ),
				array( 'status' => 400 )
			);
		}

		if ( is_plugin_active( $plugin ) ) {
			deactivate_plugins( $plugin );
		}

		$result = delete_plugins( array( $plugin ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			return new WP_Error(
				'wpaib_delete_failed',
				__( 'Plugin deletion failed. Check filesystem permissions.', 'wp-ai-bridge' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'plugin'  => $plugin,
				'status'  => 'deleted',
				'message' => __( 'Plugin deleted successfully.', 'wp-ai-bridge' ),
			),
			200
		);
	}
}
