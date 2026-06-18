<?php
/**
 * Controller per la gestione degli aggiornamenti di WordPress, plugin e temi.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint /updates — controllo e applicazione aggiornamenti core, plugin, temi.
 */
class WPAIB_Updates_Controller {

	/**
	 * Registra tutte le route REST.
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /updates — panoramica di tutti gli aggiornamenti disponibili.
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/updates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_all_updates' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'manage_options' ),
					'args'                => array(
						'force_check' => array(
							'required'    => false,
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Se true forza un nuovo controllo presso i server di aggiornamento.',
						),
					),
				),
			)
		);

		// GET /updates/core.
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/updates/core',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_core_updates' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'manage_options' ),
					'args'                => array(
						'force_check' => array(
							'required' => false,
							'type'     => 'boolean',
							'default'  => false,
						),
					),
				),
			)
		);

		// GET /updates/plugins.
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/updates/plugins',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_plugin_updates' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'manage_options' ),
					'args'                => array(
						'force_check' => array(
							'required' => false,
							'type'     => 'boolean',
							'default'  => false,
						),
					),
				),
			)
		);

		// GET /updates/themes.
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/updates/themes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_theme_updates' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'manage_options' ),
					'args'                => array(
						'force_check' => array(
							'required' => false,
							'type'     => 'boolean',
							'default'  => false,
						),
					),
				),
			)
		);

		// GET /updates/changelog/{type}/{slug}.
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/updates/changelog/(?P<type>plugin|theme|core)/(?P<slug>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_changelog' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'manage_options' ),
					'args'                => array(
						'type' => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'plugin', 'theme', 'core' ),
						),
						'slug' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		// POST /updates/apply — singolo aggiornamento.
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/updates/apply',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'apply_update' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'manage_options' ),
					'args'                => array(
						'type' => array(
							'required'          => true,
							'type'              => 'string',
							'enum'              => array( 'plugin', 'theme', 'core' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'slug' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// POST /updates/bulk — aggiornamento multiplo.
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/updates/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_update' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'manage_options' ),
				),
			)
		);
	}

	// =========================================================================
	// Endpoint GET
	// =========================================================================

	/**
	 * Restituisce la panoramica di tutti gli aggiornamenti disponibili.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function get_all_updates( WP_REST_Request $request ) {
		if ( $request->get_param( 'force_check' ) ) {
			$this->run_update_checks();
		}

		$this->require_plugin_functions();

		$core    = $this->fetch_core_update();
		$plugins = $this->fetch_plugin_updates();
		$themes  = $this->fetch_theme_updates();

		return new WP_REST_Response(
			array(
				'core'    => $core,
				'plugins' => $plugins,
				'themes'  => $themes,
				'total'   => ( $core ? 1 : 0 ) + count( $plugins ) + count( $themes ),
			),
			200
		);
	}

	/**
	 * Restituisce info sull'aggiornamento del core WordPress.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function get_core_updates( WP_REST_Request $request ) {
		if ( $request->get_param( 'force_check' ) ) {
			wp_version_check();
		}

		$core = $this->fetch_core_update();

		return new WP_REST_Response(
			array(
				'update_available' => ! is_null( $core ),
				'current_version'  => get_bloginfo( 'version' ),
				'update'           => $core,
			),
			200
		);
	}

	/**
	 * Restituisce la lista dei plugin con aggiornamenti disponibili.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function get_plugin_updates( WP_REST_Request $request ) {
		$this->require_plugin_functions();

		if ( $request->get_param( 'force_check' ) ) {
			wp_update_plugins();
		}

		$updates = $this->fetch_plugin_updates();

		return new WP_REST_Response(
			array(
				'updates' => $updates,
				'total'   => count( $updates ),
			),
			200
		);
	}

	/**
	 * Restituisce la lista dei temi con aggiornamenti disponibili.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function get_theme_updates( WP_REST_Request $request ) {
		if ( $request->get_param( 'force_check' ) ) {
			wp_update_themes();
		}

		$updates = $this->fetch_theme_updates();

		return new WP_REST_Response(
			array(
				'updates' => $updates,
				'total'   => count( $updates ),
			),
			200
		);
	}

	/**
	 * Restituisce il changelog di un plugin, tema o del core WordPress.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_changelog( WP_REST_Request $request ) {
		$type = $request->get_param( 'type' );
		$slug = $request->get_param( 'slug' );

		switch ( $type ) {
			case 'plugin':
				return $this->fetch_plugin_changelog( $slug );
			case 'theme':
				return $this->fetch_theme_changelog( $slug );
			default:
				return $this->fetch_core_changelog();
		}
	}

	// =========================================================================
	// Endpoint POST
	// =========================================================================

	/**
	 * Applica un singolo aggiornamento (plugin, tema o core).
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_update( WP_REST_Request $request ) {
		$type = $request->get_param( 'type' );
		$slug = $request->get_param( 'slug' );

		if ( 'core' !== $type && empty( $slug ) ) {
			return new WP_Error(
				'wpaib_missing_slug',
				__( '"slug" è obbligatorio per aggiornamenti di plugin e tema.', 'wp-ai-bridge' ),
				array( 'status' => 400 )
			);
		}

		$this->load_upgrader_deps();

		$fs = $this->init_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		switch ( $type ) {
			case 'plugin':
				return $this->upgrade_plugin( $slug );
			case 'theme':
				return $this->upgrade_theme( $slug );
			default:
				return $this->upgrade_core();
		}
	}

	/**
	 * Applica aggiornamenti multipli in un'unica chiamata.
	 *
	 * Body JSON atteso: { "items": [ {"type": "plugin", "slug": "akismet"}, ... ] }
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_update( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$items  = isset( $params['items'] ) ? $params['items'] : array();

		if ( empty( $items ) || ! is_array( $items ) ) {
			return new WP_Error(
				'wpaib_invalid_params',
				__( '"items" deve essere un array non vuoto.', 'wp-ai-bridge' ),
				array( 'status' => 400 )
			);
		}

		$this->load_upgrader_deps();

		$fs = $this->init_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$results   = array();
		$succeeded = 0;

		foreach ( $items as $item ) {
			$type = isset( $item['type'] ) ? sanitize_text_field( $item['type'] ) : '';
			$slug = isset( $item['slug'] ) ? sanitize_text_field( $item['slug'] ) : '';

			if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
				$results[] = array(
					'type'    => $type,
					'slug'    => $slug,
					'success' => false,
					'message' => sprintf( "Tipo '%s' non valido.", $type ),
				);
				continue;
			}

			switch ( $type ) {
				case 'plugin':
					$res = $this->upgrade_plugin( $slug );
					break;
				case 'theme':
					$res = $this->upgrade_theme( $slug );
					break;
				default:
					$res = $this->upgrade_core();
					break;
			}

			if ( is_wp_error( $res ) ) {
				$results[] = array(
					'type'    => $type,
					'slug'    => $slug,
					'success' => false,
					'message' => $res->get_error_message(),
				);
			} else {
				$data = $res->get_data();
				if ( ! empty( $data['success'] ) ) {
					++$succeeded;
				}
				$results[] = $data;
			}
		}

		return new WP_REST_Response(
			array(
				'results'   => $results,
				'total'     => count( $results ),
				'succeeded' => $succeeded,
				'failed'    => count( $results ) - $succeeded,
			),
			200
		);
	}

	// =========================================================================
	// Helper privati — recupero dati
	// =========================================================================

	/**
	 * Forza un controllo aggiornamenti completo su tutti i componenti.
	 *
	 * @return void
	 */
	private function run_update_checks() {
		wp_version_check();
		$this->require_plugin_functions();
		wp_update_plugins();
		wp_update_themes();
	}

	/**
	 * Restituisce i dati dell'aggiornamento core disponibile, o null.
	 *
	 * @return array|null
	 */
	private function fetch_core_update() {
		$transient = get_site_transient( 'update_core' );
		if ( empty( $transient->updates ) ) {
			return null;
		}
		foreach ( $transient->updates as $candidate ) {
			if ( 'upgrade' === $candidate->response ) {
				return array(
					'current_version' => get_bloginfo( 'version' ),
					'new_version'     => $candidate->version,
					'locale'          => $candidate->locale,
					'package'         => $candidate->package,
				);
			}
		}
		return null;
	}

	/**
	 * Restituisce la lista di plugin con aggiornamenti disponibili.
	 *
	 * @return array
	 */
	private function fetch_plugin_updates() {
		$this->require_plugin_functions();

		$transient = get_site_transient( 'update_plugins' );
		if ( empty( $transient->response ) ) {
			return array();
		}

		$all_plugins = get_plugins();
		$items       = array();

		foreach ( $transient->response as $plugin_file => $data ) {
			$installed = isset( $all_plugins[ $plugin_file ] ) ? $all_plugins[ $plugin_file ] : array();
			$items[]   = array(
				'slug'            => $data->slug,
				'plugin_file'     => $plugin_file,
				'name'            => isset( $installed['Name'] ) ? $installed['Name'] : $data->slug,
				'current_version' => isset( $installed['Version'] ) ? $installed['Version'] : '',
				'new_version'     => $data->new_version,
				'changelog_url'   => isset( $data->url ) ? $data->url : '',
				'requires_wp'     => isset( $data->requires ) ? $data->requires : '',
				'requires_php'    => isset( $data->requires_php ) ? $data->requires_php : '',
				'upgrade_notice'  => isset( $data->upgrade_notice ) ? wp_strip_all_tags( $data->upgrade_notice ) : '',
			);
		}

		return $items;
	}

	/**
	 * Restituisce la lista di temi con aggiornamenti disponibili.
	 *
	 * @return array
	 */
	private function fetch_theme_updates() {
		$transient = get_site_transient( 'update_themes' );
		if ( empty( $transient->response ) ) {
			return array();
		}

		$items = array();
		foreach ( $transient->response as $slug => $data ) {
			$theme   = wp_get_theme( $slug );
			$items[] = array(
				'slug'            => $slug,
				'name'            => $theme->get( 'Name' ),
				'current_version' => $theme->get( 'Version' ),
				'new_version'     => isset( $data['new_version'] ) ? $data['new_version'] : '',
				'changelog_url'   => isset( $data['url'] ) ? $data['url'] : '',
				'requires_wp'     => isset( $data['requires'] ) ? $data['requires'] : '',
				'requires_php'    => isset( $data['requires_php'] ) ? $data['requires_php'] : '',
			);
		}

		return $items;
	}

	/**
	 * Recupera il changelog di un plugin da wordpress.org.
	 *
	 * @param string $slug Slug del plugin.
	 * @return WP_REST_Response|WP_Error
	 */
	private function fetch_plugin_changelog( $slug ) {
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections'          => true,
					'short_description' => false,
					'screenshots'       => false,
					'tags'              => false,
					'versions'          => false,
				),
			)
		);

		if ( is_wp_error( $api ) ) {
			return new WP_Error( 'wpaib_changelog_not_found', $api->get_error_message(), array( 'status' => 404 ) );
		}

		$changelog_html = isset( $api->sections['changelog'] ) ? $api->sections['changelog'] : '';

		return new WP_REST_Response(
			array(
				'slug'           => $slug,
				'type'           => 'plugin',
				'name'           => $api->name,
				'latest_version' => $api->version,
				'changelog_html' => $changelog_html,
				'changelog'      => wp_strip_all_tags( $changelog_html ),
			),
			200
		);
	}

	/**
	 * Recupera il changelog di un tema da wordpress.org.
	 *
	 * @param string $slug Slug del tema.
	 * @return WP_REST_Response|WP_Error
	 */
	private function fetch_theme_changelog( $slug ) {
		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		$api = themes_api(
			'theme_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections'    => true,
					'description' => false,
					'screenshots' => false,
					'tags'        => false,
					'versions'    => false,
				),
			)
		);

		if ( is_wp_error( $api ) ) {
			return new WP_Error( 'wpaib_changelog_not_found', $api->get_error_message(), array( 'status' => 404 ) );
		}

		$changelog_html = isset( $api->sections['changelog'] ) ? $api->sections['changelog'] : '';

		return new WP_REST_Response(
			array(
				'slug'           => $slug,
				'type'           => 'theme',
				'name'           => $api->name,
				'latest_version' => $api->version,
				'changelog_html' => $changelog_html,
				'changelog'      => wp_strip_all_tags( $changelog_html ),
			),
			200
		);
	}

	/**
	 * Restituisce info sul changelog/release del core WordPress.
	 *
	 * @return WP_REST_Response
	 */
	private function fetch_core_changelog() {
		$current   = get_bloginfo( 'version' );
		$transient = get_site_transient( 'update_core' );
		$new_ver   = null;

		if ( ! empty( $transient->updates ) ) {
			foreach ( $transient->updates as $candidate ) {
				if ( 'upgrade' === $candidate->response ) {
					$new_ver = $candidate->version;
					break;
				}
			}
		}

		$release_url = 'https://wordpress.org/news/category/releases/';

		return new WP_REST_Response(
			array(
				'slug'              => 'wordpress',
				'type'              => 'core',
				'name'              => 'WordPress',
				'current_version'   => $current,
				'latest_version'    => $new_ver ? $new_ver : $current,
				'update_available'  => ! is_null( $new_ver ),
				'release_notes_url' => $release_url,
				'changelog'         => $new_ver
					? sprintf( 'Aggiornamento disponibile: WordPress %s → %s. Note di rilascio: %s', $current, $new_ver, $release_url )
					: sprintf( 'WordPress %s è aggiornato.', $current ),
			),
			200
		);
	}

	// =========================================================================
	// Helper privati — aggiornamento
	// =========================================================================

	/**
	 * Carica le dipendenze necessarie per eseguire aggiornamenti.
	 *
	 * @return void
	 */
	private function load_upgrader_deps() {
		$this->require_plugin_functions();

		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}
	}

	/**
	 * Inizializza il filesystem WP. Restituisce WP_Error se non è possibile.
	 *
	 * @return true|WP_Error
	 */
	private function init_filesystem() {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			return true;
		}

		if ( ! WP_Filesystem() ) {
			return new WP_Error(
				'wpaib_filesystem_error',
				__( 'Impossibile inizializzare il filesystem. Verifica i permessi del server.', 'wp-ai-bridge' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Risolve lo slug di un plugin nel relativo plugin_file (es. akismet/akismet.php).
	 *
	 * @param string $slug Slug o plugin file.
	 * @return string|null
	 */
	private function resolve_plugin_file( $slug ) {
		$this->require_plugin_functions();

		// È già un plugin file (contiene /).
		if ( strpos( $slug, '/' ) !== false ) {
			return $slug;
		}

		// Cerca nel transient degli aggiornamenti (ha già la mappatura slug→file).
		$transient = get_site_transient( 'update_plugins' );
		foreach ( array( 'response', 'no_update' ) as $key ) {
			if ( empty( $transient->$key ) ) {
				continue;
			}
			foreach ( $transient->$key as $plugin_file => $data ) {
				if ( isset( $data->slug ) && $data->slug === $slug ) {
					return $plugin_file;
				}
			}
		}

		// Fallback: scansiona i plugin installati per nome directory.
		foreach ( get_plugins() as $plugin_file => $info ) {
			if ( dirname( $plugin_file ) === $slug || $plugin_file === $slug . '.php' ) {
				return $plugin_file;
			}
		}

		return null;
	}

	/**
	 * Esegue l'aggiornamento di un singolo plugin.
	 *
	 * @param string $slug Slug o plugin file del plugin.
	 * @return WP_REST_Response|WP_Error
	 */
	private function upgrade_plugin( $slug ) {
		$plugin_file = $this->resolve_plugin_file( $slug );

		if ( ! $plugin_file ) {
			return new WP_Error(
				'wpaib_plugin_not_found',
				sprintf( __( 'Plugin "%s" non trovato tra i plugin installati.', 'wp-ai-bridge' ), $slug ),
				array( 'status' => 404 )
			);
		}

		$transient = get_site_transient( 'update_plugins' );

		if ( empty( $transient->response[ $plugin_file ] ) ) {
			return new WP_REST_Response(
				array(
					'success'     => false,
					'type'        => 'plugin',
					'slug'        => $slug,
					'plugin_file' => $plugin_file,
					'message'     => sprintf( "Nessun aggiornamento disponibile per il plugin '%s'.", $slug ),
				),
				200
			);
		}

		$new_version = $transient->response[ $plugin_file ]->new_version;

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'wpaib_update_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}

		if ( false === $result ) {
			$errors = $skin->get_errors();
			$msg    = $errors->has_errors()
				? implode( '; ', $errors->get_error_messages() )
				: __( 'Aggiornamento plugin fallito.', 'wp-ai-bridge' );
			return new WP_Error( 'wpaib_update_failed', $msg, array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success'     => true,
				'type'        => 'plugin',
				'slug'        => $slug,
				'plugin_file' => $plugin_file,
				'new_version' => $new_version,
				'message'     => sprintf( "Plugin '%s' aggiornato alla versione %s.", $slug, $new_version ),
			),
			200
		);
	}

	/**
	 * Esegue l'aggiornamento di un singolo tema.
	 *
	 * @param string $slug Slug del tema.
	 * @return WP_REST_Response|WP_Error
	 */
	private function upgrade_theme( $slug ) {
		$transient = get_site_transient( 'update_themes' );

		if ( empty( $transient->response[ $slug ] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'type'    => 'theme',
					'slug'    => $slug,
					'message' => sprintf( "Nessun aggiornamento disponibile per il tema '%s'.", $slug ),
				),
				200
			);
		}

		$new_version = $transient->response[ $slug ]['new_version'];

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $slug );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'wpaib_update_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}

		if ( false === $result ) {
			$errors = $skin->get_errors();
			$msg    = $errors->has_errors()
				? implode( '; ', $errors->get_error_messages() )
				: __( 'Aggiornamento tema fallito.', 'wp-ai-bridge' );
			return new WP_Error( 'wpaib_update_failed', $msg, array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success'     => true,
				'type'        => 'theme',
				'slug'        => $slug,
				'new_version' => $new_version,
				'message'     => sprintf( "Tema '%s' aggiornato alla versione %s.", $slug, $new_version ),
			),
			200
		);
	}

	/**
	 * Esegue l'aggiornamento del core WordPress.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private function upgrade_core() {
		$transient = get_site_transient( 'update_core' );
		$target    = null;

		if ( ! empty( $transient->updates ) ) {
			foreach ( $transient->updates as $candidate ) {
				if ( 'upgrade' === $candidate->response ) {
					$target = $candidate;
					break;
				}
			}
		}

		if ( ! $target ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'type'    => 'core',
					'message' => __( 'Nessun aggiornamento core disponibile.', 'wp-ai-bridge' ),
				),
				200
			);
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $target );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'wpaib_update_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success'     => true,
				'type'        => 'core',
				'new_version' => $target->version,
				'message'     => sprintf( __( 'WordPress aggiornato alla versione %s.', 'wp-ai-bridge' ), $target->version ),
			),
			200
		);
	}

	/**
	 * Assicura che le funzioni dei plugin WP admin siano disponibili.
	 *
	 * @return void
	 */
	private function require_plugin_functions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
