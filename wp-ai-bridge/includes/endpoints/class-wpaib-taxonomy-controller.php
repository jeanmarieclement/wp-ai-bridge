<?php
/**
 * Controller per categorie e tag.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint /categories e /tags.
 */
class WPAIB_Taxonomy_Controller {

	/**
	 * Registra le route.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Categorie.
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/categories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_categories' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_category' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'manage_categories' ),
				),
			)
		);

		// Tag.
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/tags',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_tags' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_tag' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'manage_categories' ),
				),
			)
		);
	}

	/**
	 * Lista categorie.
	 *
	 * @return WP_REST_Response
	 */
	public function list_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return new WP_REST_Response( array( 'items' => array() ), 200 );
		}

		$items = array();
		foreach ( $terms as $t ) {
			$items[] = $this->prepare_term( $t );
		}
		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * Crea una categoria.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_category( WP_REST_Request $request ) {
		return $this->create_term( $request, 'category' );
	}

	/**
	 * Lista tag.
	 *
	 * @return WP_REST_Response
	 */
	public function list_tags() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return new WP_REST_Response( array( 'items' => array() ), 200 );
		}

		$items = array();
		foreach ( $terms as $t ) {
			$items[] = $this->prepare_term( $t );
		}
		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * Crea un tag.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_tag( WP_REST_Request $request ) {
		return $this->create_term( $request, 'post_tag' );
	}

	/**
	 * Logica condivisa di creazione termine.
	 *
	 * @param WP_REST_Request $request  Richiesta.
	 * @param string          $taxonomy Tassonomia.
	 * @return WP_REST_Response|WP_Error
	 */
	private function create_term( WP_REST_Request $request, $taxonomy ) {
		$params = $request->get_json_params();
		if ( empty( $params['name'] ) ) {
			return new WP_Error( 'wpaib_missing_name', __( 'Name is required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$name        = sanitize_text_field( $params['name'] );
		$slug        = isset( $params['slug'] ) ? sanitize_title( $params['slug'] ) : '';
		$description = isset( $params['description'] ) ? sanitize_textarea_field( $params['description'] ) : '';
		$parent      = isset( $params['parent'] ) ? (int) $params['parent'] : 0;

		$args = array(
			'description' => $description,
		);
		if ( ! empty( $slug ) ) {
			$args['slug'] = $slug;
		}
		if ( $parent > 0 && 'category' === $taxonomy ) {
			$args['parent'] = $parent;
		}

		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $result['term_id'], $taxonomy );
		return new WP_REST_Response( $this->prepare_term( $term ), 201 );
	}

	/**
	 * Prepara la rappresentazione di un termine.
	 *
	 * @param WP_Term $term Termine.
	 * @return array
	 */
	private function prepare_term( $term ) {
		return array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'count'       => (int) $term->count,
			'parent'      => (int) $term->parent,
		);
	}
}
