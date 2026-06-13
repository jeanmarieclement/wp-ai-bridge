<?php
/**
 * UI di amministrazione: sezione API Keys nel profilo utente.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestisce la UI WP-admin per le API key.
 */
class WPAIB_Admin {

	/**
	 * Inizializza gli hook admin.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_section' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_section' ) );
		add_action( 'admin_post_wpaib_generate_key', array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_wpaib_revoke_key', array( __CLASS__, 'handle_revoke' ) );
		add_action( 'admin_post_wpaib_save_tools', array( __CLASS__, 'handle_save_tools' ) );
		add_action( 'wp_ajax_wpaib_generate_key', array( __CLASS__, 'ajax_generate_key' ) );
		add_action( 'wp_ajax_wpaib_revoke_key', array( __CLASS__, 'ajax_revoke_key' ) );
		add_action( 'admin_post_wpaib_create_oauth_client', array( __CLASS__, 'handle_create_oauth_client' ) );
		add_action( 'admin_post_wpaib_delete_oauth_client', array( __CLASS__, 'handle_delete_oauth_client' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
	}

	/**
	 * Registra la voce di menu in Impostazioni.
	 *
	 * @return void
	 */
	public static function register_admin_menu() {
		add_options_page(
			'WP AI Bridge — Tool',
			'WP AI Bridge',
			'manage_options',
			'wpaib-tools',
			array( __CLASS__, 'render_tools_page' )
		);
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_styles' ) );
	}

	/**
	 * Aggiunge stili inline per le pagine del plugin.
	 *
	 * @param string $hook Identificatore pagina admin corrente.
	 * @return void
	 */
	public static function enqueue_admin_styles( $hook ) {
		if ( 'settings_page_wpaib-tools' !== $hook ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', '.wpaib-other-user { background-color: #fffbe6 !important; }' );
	}

	/**
	 * Renderizza la pagina di configurazione dei tool.
	 *
	 * @return void
	 */
	public static function render_tools_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'wp-ai-bridge' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'tools';

		if ( 'connections' === $tab ) {
			self::render_connections_page();
			return;
		}

		if ( 'oauth' === $tab ) {
			self::render_oauth_page();
			return;
		}

		$saved    = isset( $_GET['wpaib_saved'] ) ? (int) $_GET['wpaib_saved'] : 0;
		$disabled = get_option( 'wpaib_disabled_tools', array() );

		$view_file = WPAIB_PLUGIN_DIR . 'admin/views/tools-settings.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		}
	}

	/**
	 * Renderizza la pagina di connessioni.
	 *
	 * @return void
	 */
	public static function render_connections_page() {
		$view_file = WPAIB_PLUGIN_DIR . 'admin/views/connections.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		}
	}

	/**
	 * Renderizza la pagina OAuth2 Clients.
	 *
	 * @return void
	 */
	public static function render_oauth_page() {
		$view_file = WPAIB_PLUGIN_DIR . 'admin/views/oauth-clients.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		}
	}

	/**
	 * Handler per creare un nuovo OAuth2 client.
	 *
	 * @return void
	 */
	public static function handle_create_oauth_client() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'wp-ai-bridge' ) );
		}

		check_admin_referer( 'wpaib_create_oauth_client' );

		$name          = isset( $_POST['client_name'] )          ? sanitize_text_field( wp_unslash( $_POST['client_name'] ) )          : '';
		$redirect_uris = isset( $_POST['client_redirect_uris'] ) ? sanitize_textarea_field( wp_unslash( $_POST['client_redirect_uris'] ) ) : '';

		$result = WPAIB_OAuth_Client_Manager::create( $name, $redirect_uris );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg(
				array( 'page' => 'wpaib-tools', 'tab' => 'oauth', 'wpaib_oauth_error' => urlencode( $result->get_error_message() ) ),
				admin_url( 'options-general.php' )
			) );
			exit;
		}

		// Il client_secret in chiaro NON va in querystring: salvato in un transient
		// one-shot user-scoped, letto e cancellato al render della vista.
		set_transient(
			'wpaib_new_client_' . get_current_user_id(),
			array(
				'client_id'     => $result['client_id'],
				'client_secret' => $result['client_secret'],
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( add_query_arg(
			array(
				'page'                 => 'wpaib-tools',
				'tab'                  => 'oauth',
				'wpaib_client_created' => 1,
			),
			admin_url( 'options-general.php' )
		) );
		exit;
	}

	/**
	 * Handler per eliminare un OAuth2 client.
	 *
	 * @return void
	 */
	public static function handle_delete_oauth_client() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'wp-ai-bridge' ) );
		}

		check_admin_referer( 'wpaib_delete_oauth_client' );

		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		WPAIB_OAuth_Client_Manager::delete( $client_id );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'wpaib-tools', 'tab' => 'oauth' ),
			admin_url( 'options-general.php' )
		) );
		exit;
	}

	/**
	 * Handler per salvare le impostazioni dei tool.
	 *
	 * @return void
	 */
	public static function handle_save_tools() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'wp-ai-bridge' ) );
		}

		check_admin_referer( 'wpaib_save_tools' );

		$all_tools = array(
			'get_posts', 'get_post', 'create_post', 'update_post', 'delete_post', 'bulk_update_posts',
			'get_pages', 'get_page', 'create_page', 'update_page', 'delete_page',
			'get_media', 'upload_media', 'delete_media',
			'get_comments', 'add_comment', 'moderate_comment', 'bulk_moderate_comments',
			'get_categories', 'create_category', 'get_tags', 'create_tag',
			'get_site_info', 'search',
		);

		$enabled  = isset( $_POST['wpaib_tools'] ) && is_array( $_POST['wpaib_tools'] )
			? array_map( 'sanitize_key', $_POST['wpaib_tools'] )
			: array();

		$disabled = array_values( array_diff( $all_tools, $enabled ) );
		update_option( 'wpaib_disabled_tools', $disabled );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'wpaib-tools', 'wpaib_saved' => 1 ),
			admin_url( 'options-general.php' )
		) );
		exit;
	}

	/**
	 * Renderizza la sezione nel profilo utente.
	 *
	 * @param WP_User $user Utente in edit.
	 * @return void
	 */
	public static function render_profile_section( $user ) {
		// Solo l'utente stesso o un admin vedono questa sezione.
		if ( get_current_user_id() !== (int) $user->ID && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$keys = WPAIB_API_Key_Manager::get_user_keys( $user->ID );

		// Mostra chiave appena generata: letta dal transient one-shot e subito rimossa,
		// così non resta esposta in URL/log/history. Il flag in querystring è innocuo.
		$just_generated = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['wpaib_key_generated'] ) ) {
			$transient_key  = 'wpaib_new_key_' . (int) $user->ID;
			$just_generated = (string) get_transient( $transient_key );
			delete_transient( $transient_key );
		}

		$view_file = WPAIB_PLUGIN_DIR . 'admin/views/api-keys-section.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		}
	}

	/**
	 * Handler per generare una nuova chiave via AJAX.
	 *
	 * @return void
	 */
	public static function ajax_generate_key() {
		check_ajax_referer( 'wpaib_admin_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( esc_html__( 'Unauthorized.', 'wp-ai-bridge' ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		// Solo per sé stessi o admin.
		if ( $user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Forbidden.', 'wp-ai-bridge' ) );
		}

		$result = WPAIB_API_Key_Manager::generate( $user_id, $label );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array(
			'key'        => $result['key'],
			'id'         => $result['id'],
			'label'      => $label ? $label : '—',
			'created_at' => current_time( 'mysql', true ),
		) );
	}

	/**
	 * Handler per revocare una chiave via AJAX.
	 *
	 * @return void
	 */
	public static function ajax_revoke_key() {
		check_ajax_referer( 'wpaib_admin_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( esc_html__( 'Unauthorized.', 'wp-ai-bridge' ) );
		}

		$key_id  = isset( $_POST['key_id'] ) ? (int) $_POST['key_id'] : 0;
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : get_current_user_id();

		// Solo per sé stessi o admin.
		if ( $user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'Forbidden.', 'wp-ai-bridge' ) );
		}

		$result = WPAIB_API_Key_Manager::revoke( $key_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array(
			'revoked_at' => current_time( 'mysql', true ),
		) );
	}

	/**
	 * Handler per generare una nuova chiave (fallback standard POST).
	 *
	 * @return void
	 */
	public static function handle_generate() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Unauthorized.', 'wp-ai-bridge' ) );
		}

		check_admin_referer( 'wpaib_generate_key' );

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		// Solo per sé stessi o admin.
		if ( $user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'wp-ai-bridge' ) );
		}

		$result = WPAIB_API_Key_Manager::generate( $user_id, $label );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		// La chiave in chiaro NON va in querystring (finirebbe in log/history/Referer).
		// La salviamo in un transient one-shot user-scoped, letto e cancellato al render.
		set_transient( 'wpaib_new_key_' . $user_id, $result['key'], MINUTE_IN_SECONDS );

		$redirect = add_query_arg(
			array( 'wpaib_key_generated' => 1 ),
			get_edit_user_link( $user_id )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handler per revocare una chiave (fallback standard POST).
	 *
	 * @return void
	 */
	public static function handle_revoke() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Unauthorized.', 'wp-ai-bridge' ) );
		}

		check_admin_referer( 'wpaib_revoke_key' );

		$key_id  = isset( $_POST['key_id'] ) ? (int) $_POST['key_id'] : 0;
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : get_current_user_id();

		$result = WPAIB_API_Key_Manager::revoke( $key_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		wp_safe_redirect( get_edit_user_link( $user_id ) );
		exit;
	}
}
