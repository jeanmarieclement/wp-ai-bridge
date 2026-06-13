<?php
/**
 * Controller per informazioni del sito WordPress.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint /site (info generali sito).
 */
class WPAIB_Site_Controller {

	/**
	 * Registra le route REST.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/site',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_site_info' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
			)
		);
	}

	/**
	 * Restituisce informazioni complete sul sito.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function get_site_info( WP_REST_Request $request ) {
		$theme      = wp_get_theme();
		$tz_string  = get_option( 'timezone_string' );
		$gmt_offset = get_option( 'gmt_offset' );

		if ( empty( $tz_string ) ) {
			$sign       = $gmt_offset >= 0 ? '+' : '-';
			$tz_string  = 'UTC' . $sign . abs( $gmt_offset );
		}

		$data = array(
			'name'         => get_bloginfo( 'name' ),
			'tagline'      => get_bloginfo( 'description' ),
			'url'          => get_bloginfo( 'url' ),
			'language'     => get_bloginfo( 'language' ),
			'timezone'     => $tz_string,
			'wp_version'   => get_bloginfo( 'version' ),
			'active_theme' => $theme->get( 'Name' ),
			'posts_count'  => (int) wp_count_posts( 'post' )->publish,
			'pages_count'  => (int) wp_count_posts( 'page' )->publish,
			'users_count'  => (int) count_users()['total_users'],
		);

		// L'email dell'admin è un dato sensibile: esposta solo agli amministratori.
		if ( current_user_can( 'manage_options' ) ) {
			$data['admin_email'] = get_bloginfo( 'admin_email' );
		}

		return new WP_REST_Response( $data, 200 );
	}
}
