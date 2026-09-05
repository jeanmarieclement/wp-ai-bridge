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
	 * Tetto alle pagine espanse da un blocco core/page-list.
	 *
	 * /menus non ha paginazione: senza un limite, un sito con molte pagine
	 * caricherebbe l'intero albero in memoria a ogni richiesta.
	 */
	const MAX_PAGE_LIST_ITEMS = 500;

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

		// Mappa term_id => elenco di location slug: un menu classico può essere
		// assegnato a più di una posizione insieme (es. primary e mobile), e
		// array_flip() ne perderebbe tutte tranne l'ultima.
		$assigned = array();
		foreach ( (array) get_nav_menu_locations() as $location_slug => $term_id ) {
			$assigned[ (int) $term_id ][] = $location_slug;
		}
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

			$menu_locations   = isset( $assigned[ $menu_id ] ) ? $assigned[ $menu_id ] : array();
			$location_labels  = array();
			foreach ( $menu_locations as $location_slug ) {
				$location_labels[] = isset( $locations[ $location_slug ] ) ? $locations[ $location_slug ] : '';
			}

			$items[] = array(
				'id'              => $menu_id,
				'name'            => $menu->name,
				'slug'            => $menu->slug,
				'description'     => $menu->description,
				'count'           => (int) $menu->count,
				'type'            => 'nav_menu',
				'locations'       => array_values( $menu_locations ),
				'location_labels' => $location_labels,
				'items'           => $prepared_menu,
			);
		}

		// I temi a blocchi (FSE) non usano la tassonomia nav_menu classica: i
		// menu vivono come post wp_navigation, con le voci serializzate in
		// blocchi invece che in post nav_menu_item. Senza questa parte /menus
		// torna vuoto su ogni tema a blocchi (i temi core dalla Twenty
		// Twenty-Two in poi), perdendo la navigazione dall'export del sito.
		$navigation_posts = get_posts(
			array(
				'post_type'        => 'wp_navigation',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'suppress_filters' => false,
			)
		);

		foreach ( $navigation_posts as $nav_post ) {
			$nav_items = $this->flatten_navigation_blocks( parse_blocks( $nav_post->post_content ) );

			$items[] = array(
				'id'              => (int) $nav_post->ID,
				'name'            => $nav_post->post_title,
				'slug'            => $nav_post->post_name,
				'description'     => '',
				'count'           => count( $nav_items ),
				'type'            => 'wp_navigation',
				'locations'       => array(),
				'location_labels' => array(),
				'items'           => $nav_items,
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
	 * Appiattisce i blocchi di un post wp_navigation in voci di menu.
	 *
	 * I blocchi navigation-link/navigation-submenu non hanno un ID proprio (sono
	 * attributi di blocco, non post), quindi 'id' è un progressivo sintetico
	 * assegnato qui: non è l'ID di nulla nel database — a differenza dei menu
	 * classici, dove è l'ID del post nav_menu_item — ed è valido solo dentro
	 * questo menu, nella stessa risposta. Serve a 'parent', altrimenti la
	 * gerarchia dei sottomenu andrebbe persa nell'appiattimento.
	 *
	 * @param array $blocks   Blocchi, come restituiti da parse_blocks().
	 * @param int   $parent   ID sintetico del genitore (0 per il primo livello).
	 * @param int   $sequence Contatore degli ID sintetici, condiviso per riferimento.
	 * @param int   $order    Contatore dell'ordine di lettura, condiviso per riferimento.
	 * @return array
	 */
	private function flatten_navigation_blocks( array $blocks, $parent = 0, &$sequence = 0, &$order = 0 ) {
		$items = array();

		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			// core/page-list non contiene voci statiche: WordPress lo espande a
			// runtime nell'elenco delle pagine pubblicate. Senza questo caso, il
			// menu di default dei temi a blocchi (Navigazione = Elenco pagine)
			// risulterebbe vuoto anche dopo aver trovato il post wp_navigation.
			if ( 'core/page-list' === $block['blockName'] ) {
				// Il numero di pagine va limitato: senza `number`, un sito con
				// decine di migliaia di pagine caricherebbe l'intero albero in
				// memoria a ogni chiamata di /menus, che non ha paginazione.
				$pages = get_pages(
					array(
						'sort_column' => 'menu_order, post_title',
						'number'      => self::MAX_PAGE_LIST_ITEMS,
					)
				);

				// Le pagine sono gerarchiche: la mappa post_parent => id sintetico
				// serve a non appiattire l'albero che il blocco rende annidato.
				$synthetic = array();
				foreach ( $pages as $page ) {
					$synthetic[ (int) $page->ID ] = ++$sequence;
				}

				foreach ( $pages as $page ) {
					$page_parent = (int) $page->post_parent;

					$items[] = array(
						'id'          => $synthetic[ (int) $page->ID ],
						'title'       => $page->post_title,
						'url'         => get_permalink( $page->ID ),
						'parent'      => isset( $synthetic[ $page_parent ] ) ? $synthetic[ $page_parent ] : $parent,
						'order'       => ++$order,
						'type'        => 'post_type',
						'type_label'  => '',
						'object'      => 'page',
						'object_id'   => (int) $page->ID,
						'target'      => '',
						'classes'     => array(),
						'attr_title'  => '',
						'description' => '',
						'xfn'         => '',
					);
				}
				continue;
			}

			// home-link e loginout sono voci di navigazione a tutti gli effetti nei
			// temi a blocchi, ma non portano label/url negli attributi: vanno
			// risolti qui, altrimenti sparirebbero dall'export in silenzio.
			$is_link = in_array(
				$block['blockName'],
				array( 'core/navigation-link', 'core/navigation-submenu', 'core/home-link', 'core/loginout' ),
				true
			);

			// Le voci annidate dentro un submenu ne diventano figlie; i blocchi
			// contenitore che non sono voci (group, spacer…) lasciano invariato
			// il genitore corrente invece di reimpostarlo alla radice.
			$item_parent = $parent;

			if ( $is_link ) {
				$attrs       = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$item_parent = ++$sequence;

				$label = isset( $attrs['label'] ) ? $attrs['label'] : '';
				$url   = isset( $attrs['url'] ) ? $attrs['url'] : '';

				if ( 'core/home-link' === $block['blockName'] ) {
					$label = '' !== $label ? $label : __( 'Home', 'wp-ai-bridge' );
					$url   = home_url( '/' );
				} elseif ( 'core/loginout' === $block['blockName'] ) {
					$label = '' !== $label ? $label : __( 'Log in', 'wp-ai-bridge' );
					$url   = wp_login_url();
				}

				$items[] = array(
					'id'          => $item_parent,
					'title'       => $label,
					'url'         => $url,
					'parent'      => $parent,
					'order'       => ++$order,
					'type'        => isset( $attrs['kind'] ) ? $attrs['kind'] : 'custom',
					'type_label'  => '',
					'object'      => isset( $attrs['type'] ) ? $attrs['type'] : '',
					'object_id'   => isset( $attrs['id'] ) ? (int) $attrs['id'] : 0,
					'target'      => ! empty( $attrs['opensInNewTab'] ) ? '_blank' : '',
					'classes'     => array(),
					'attr_title'  => '',
					'description' => isset( $attrs['description'] ) ? $attrs['description'] : '',
					'xfn'         => isset( $attrs['rel'] ) ? $attrs['rel'] : '',
				);
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$items = array_merge( $items, $this->flatten_navigation_blocks( $block['innerBlocks'], $item_parent, $sequence, $order ) );
			}
		}

		return $items;
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
