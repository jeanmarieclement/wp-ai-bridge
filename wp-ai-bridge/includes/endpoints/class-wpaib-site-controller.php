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

		// Configurazione completa: front page, permalink, logo, identificativo
		// del sito. Sono impostazioni globali, quindi manage_options.
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/site/full',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_full_site_info' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'manage_options' ),
				),
			)
		);
	}

	/**
	 * Identificativo opaco e stabile del sito.
	 *
	 * WordPress non ne ha uno nativo: senza, un consumatore non può riconoscere
	 * "lo stesso sito di origine" fra due esecuzioni e finisce per duplicare
	 * invece di aggiornare. Generato alla prima richiesta e conservato in
	 * wp_options, sopravvive a un cambio di dominio. Non deriva da host, path o
	 * dati degli utenti.
	 *
	 * @return string UUIDv4.
	 */
	public static function get_site_uuid() {
		$uuid = get_option( 'wpaib_site_uuid' );

		if ( ! is_string( $uuid ) || ! wp_is_uuid( $uuid, 4 ) ) {
			$uuid = wp_generate_uuid4();
			update_option( 'wpaib_site_uuid', $uuid, true );
		}

		return $uuid;
	}

	/**
	 * Restituisce la configurazione completa del sito.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function get_full_site_info( WP_REST_Request $request ) {
		$logo_id    = (int) get_theme_mod( 'custom_logo' );
		$icon_id    = (int) get_option( 'site_icon' );
		$front_id   = (int) get_option( 'page_on_front' );
		$posts_id   = (int) get_option( 'page_for_posts' );
		$tz_string  = get_option( 'timezone_string' );
		$gmt_offset = get_option( 'gmt_offset' );

		if ( empty( $tz_string ) ) {
			$sign      = $gmt_offset >= 0 ? '+' : '-';
			$tz_string = 'UTC' . $sign . abs( $gmt_offset );
		}

		$data = array(
			'site_uuid'              => self::get_site_uuid(),
			'title'                  => get_option( 'blogname' ),
			'description'            => get_option( 'blogdescription' ),
			'url'                    => home_url(),
			'wp_url'                 => site_url(),
			'admin_email'            => get_option( 'admin_email' ),
			'language'               => get_bloginfo( 'language' ),
			'locale'                 => get_locale(),
			'timezone'               => $tz_string,
			'gmt_offset'             => (float) $gmt_offset,
			'date_format'            => get_option( 'date_format' ),
			'time_format'            => get_option( 'time_format' ),
			'start_of_week'          => (int) get_option( 'start_of_week' ),
			'permalink_structure'    => get_option( 'permalink_structure' ),
			'show_on_front'          => get_option( 'show_on_front' ),
			'page_on_front'          => $front_id,
			'page_for_posts'         => $posts_id,
			'posts_per_page'         => (int) get_option( 'posts_per_page' ),
			'default_category'       => (int) get_option( 'default_category' ),
			'default_comment_status' => get_option( 'default_comment_status' ),
			'comment_registration'   => (bool) get_option( 'comment_registration' ),
			'comment_moderation'     => (bool) get_option( 'comment_moderation' ),
			'users_can_register'     => (bool) get_option( 'users_can_register' ),
			'default_role'           => get_option( 'default_role' ),
			'blog_public'            => (int) get_option( 'blog_public' ),
			'wp_version'             => get_bloginfo( 'version' ),
			'site_logo'              => array(
				'id'  => $logo_id,
				'url' => $logo_id ? wp_get_attachment_url( $logo_id ) : '',
			),
			'site_icon'              => array(
				'id'  => $icon_id,
				'url' => $icon_id ? wp_get_attachment_url( $icon_id ) : '',
			),
			'counts'                 => array(
				'posts'       => (int) wp_count_posts( 'post' )->publish,
				'pages'       => (int) wp_count_posts( 'page' )->publish,
				'attachments' => (int) array_sum( (array) wp_count_attachments() ),
				'users'       => (int) count_users()['total_users'],
				'comments'    => (int) wp_count_comments()->total_comments,
			),
		);

		return new WP_REST_Response( $data, 200 );
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
