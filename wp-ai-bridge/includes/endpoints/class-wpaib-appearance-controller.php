<?php
/**
 * Controller di sola lettura per menu di navigazione e tema attivo.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint GET /menus e GET /theme.
 *
 * Entrambi richiedono edit_theme_options, la capability con cui WordPress
 * protegge la stessa informazione nel backend.
 */
class WPAIB_Appearance_Controller {

	/**
	 * Registra le route REST.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/menus',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_menus' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_theme_options' ),
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/theme',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_theme' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_theme_options' ),
				),
			)
		);
	}

	/**
	 * Elenca i menu registrati, con voci e gerarchia.
	 *
	 * @return WP_REST_Response
	 */
	public function list_menus() {
		$menus = wp_get_nav_menus();
		if ( is_wp_error( $menus ) ) {
			$menus = array();
		}

		// Mappa term_id => location slug, per sapere dove ogni menu è assegnato.
		$assigned  = array_flip( array_map( 'intval', (array) get_nav_menu_locations() ) );
		$locations = get_registered_nav_menus();

		$items = array();
		foreach ( $menus as $menu ) {
			$menu_id       = (int) $menu->term_id;
			$menu_items    = wp_get_nav_menu_items( $menu_id, array( 'update_post_term_cache' => false ) );
			$prepared_menu = array();

			if ( is_array( $menu_items ) ) {
				foreach ( $menu_items as $item ) {
					$prepared_menu[] = $this->prepare_menu_item( $item );
				}
			}

			$location = isset( $assigned[ $menu_id ] ) ? $assigned[ $menu_id ] : '';

			$items[] = array(
				'id'             => $menu_id,
				'name'           => $menu->name,
				'slug'           => $menu->slug,
				'description'    => $menu->description,
				'count'          => (int) $menu->count,
				'location'       => $location,
				'location_label' => isset( $locations[ $location ] ) ? $locations[ $location ] : '',
				'items'          => $prepared_menu,
			);
		}

		return new WP_REST_Response(
			array(
				'items'                => $items,
				'total'                => count( $items ),
				'registered_locations' => $locations,
			),
			200
		);
	}

	/**
	 * Prepara una voce di menu.
	 *
	 * @param WP_Post $item Voce di menu (post del tipo nav_menu_item).
	 * @return array
	 */
	private function prepare_menu_item( $item ) {
		return array(
			'id'          => (int) $item->ID,
			'title'       => $item->title,
			'url'         => $item->url,
			'parent'      => (int) $item->menu_item_parent,
			'order'       => (int) $item->menu_order,
			// type/object/object_id dicono a cosa punta la voce (post, pagina,
			// termine, link personalizzato): senza, il collegamento non è ricostruibile.
			'type'        => $item->type,
			'type_label'  => $item->type_label,
			'object'      => $item->object,
			'object_id'   => (int) $item->object_id,
			'target'      => $item->target,
			'classes'     => array_values( array_filter( (array) $item->classes ) ),
			'attr_title'  => $item->attr_title,
			'description' => $item->description,
			'xfn'         => $item->xfn,
		);
	}

	/**
	 * Restituisce il tema attivo e gli URL rappresentativi da catturare.
	 *
	 * @return WP_REST_Response
	 */
	public function get_theme() {
		$theme  = wp_get_theme();
		$parent = $theme->parent();

		$data = array(
			'slug'           => $theme->get_stylesheet(),
			'name'           => $theme->get( 'Name' ),
			'version'        => $theme->get( 'Version' ),
			'author'         => $theme->get( 'Author' ),
			'description'    => $theme->get( 'Description' ),
			'stylesheet'     => $theme->get_stylesheet(),
			'stylesheet_url' => get_stylesheet_uri(),
			'stylesheet_dir' => get_stylesheet_directory_uri(),
			'template'       => $theme->get_template(),
			'template_dir'   => get_template_directory_uri(),
			'is_child_theme' => is_child_theme(),
			'parent_theme'   => $parent ? $parent->get_stylesheet() : '',
			'is_block_theme' => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
			'supports'       => array(
				'post_thumbnails' => (bool) current_theme_supports( 'post-thumbnails' ),
				'custom_logo'     => (bool) current_theme_supports( 'custom-logo' ),
				'menus'           => (bool) current_theme_supports( 'menus' ),
				'widgets'         => (bool) current_theme_supports( 'widgets' ),
			),
			'sidebars'       => $this->registered_sidebars(),
			'capture_urls'   => $this->capture_urls(),
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Elenca le sidebar registrate dal tema.
	 *
	 * @return array
	 */
	private function registered_sidebars() {
		global $wp_registered_sidebars;

		$sidebars = array();
		foreach ( (array) $wp_registered_sidebars as $sidebar ) {
			$sidebars[] = array(
				'id'          => isset( $sidebar['id'] ) ? $sidebar['id'] : '',
				'name'        => isset( $sidebar['name'] ) ? $sidebar['name'] : '',
				'description' => isset( $sidebar['description'] ) ? $sidebar['description'] : '',
			);
		}

		return $sidebars;
	}

	/**
	 * URL rappresentativi del sito, uno per tipo di template.
	 *
	 * Servono a un consumatore che vuole catturare l'aspetto del tema senza
	 * indovinare la struttura dei permalink.
	 *
	 * @return array
	 */
	private function capture_urls() {
		$urls = array(
			'home' => home_url( '/' ),
		);

		$latest_post = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'suppress_filters' => false,
			)
		);
		if ( ! empty( $latest_post ) ) {
			$urls['post']    = get_permalink( $latest_post[0]->ID );
			$categories      = wp_get_post_categories( $latest_post[0]->ID );
			$category_link   = ! empty( $categories ) ? get_category_link( (int) $categories[0] ) : '';
			$urls['archive'] = $category_link ? $category_link : get_post_type_archive_link( 'post' );
		}

		$latest_page = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'suppress_filters' => false,
			)
		);
		if ( ! empty( $latest_page ) ) {
			$urls['page'] = get_permalink( $latest_page[0]->ID );
		}

		if ( empty( $urls['archive'] ) ) {
			$archive         = get_post_type_archive_link( 'post' );
			$urls['archive'] = $archive ? $archive : '';
		}

		return array_filter( $urls );
	}
}
