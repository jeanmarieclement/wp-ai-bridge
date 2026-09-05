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
					'args'                => array(
						'per_page' => array(
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'after_id' => array(
							'sanitize_callback' => 'absint',
						),
					),
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
					'args'                => array(
						'per_page' => array(
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'after_id' => array(
							'sanitize_callback' => 'absint',
						),
					),
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
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function list_categories( WP_REST_Request $request ) {
		return $this->list_terms( $request, 'category' );
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
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function list_tags( WP_REST_Request $request ) {
		return $this->list_terms( $request, 'post_tag' );
	}

	/**
	 * Logica condivisa di elenco termini, con paginazione.
	 *
	 * Senza per_page né after_id il comportamento resta quello storico: tutti i
	 * termini in un'unica risposta, ordinati per nome.
	 *
	 * @param WP_REST_Request $request  Richiesta.
	 * @param string          $taxonomy Tassonomia.
	 * @return WP_REST_Response
	 */
	private function list_terms( WP_REST_Request $request, $taxonomy ) {
		$per_page = absint( $request->get_param( 'per_page' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$after_id = WPAIB_Rest_Helper::after_id( $request->get_param( 'after_id' ) );

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		);

		// Con il cursore un limite serve sempre, altrimenti la prima pagina è già tutta.
		if ( $per_page < 1 && null !== $after_id ) {
			$per_page = WPAIB_Rest_Helper::MAX_PER_PAGE;
		}
		if ( $per_page > 0 ) {
			$per_page       = WPAIB_Rest_Helper::per_page( $per_page );
			$args['number'] = $per_page;
			if ( null === $after_id ) {
				$args['offset'] = ( $page - 1 ) * $per_page;
			}
		}

		$terms = WPAIB_Rest_Helper::query_terms( $args, $after_id );

		if ( is_wp_error( $terms ) ) {
			return new WP_REST_Response( array( 'items' => array(), 'total' => 0 ), 200 );
		}

		$items = array();
		foreach ( $terms as $t ) {
			$items[] = $this->prepare_term( $t );
		}

		$count_args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		);

		if ( null !== $after_id ) {
			// Il conteggio deve rispettare lo stesso cursore della query, altrimenti
			// riporta l'intera collezione invece dei soli termini rimasti da leggere.
			$count_args['wpaib_after_id'] = $after_id;
			add_filter( 'terms_clauses', array( 'WPAIB_Rest_Helper', 'filter_terms_clauses' ), 10, 3 );
		}

		$total = (int) wp_count_terms( $count_args );

		if ( null !== $after_id ) {
			remove_filter( 'terms_clauses', array( 'WPAIB_Rest_Helper', 'filter_terms_clauses' ), 10 );
		}

		$response = array(
			'items' => $items,
			'total' => $total,
		);

		if ( $per_page > 0 ) {
			$response['total_pages'] = (int) ceil( $total / $per_page );
		}

		if ( null !== $after_id ) {
			// Con il cursore il conteggio è quello dei termini rimasti dopo di
			// esso, non il totale della collezione: nominato di conseguenza.
			unset( $response['total_pages'] );
			$response['total_remaining'] = $response['total'];
			unset( $response['total'] );
			$response['after_id']      = $after_id;
			$response['next_after_id'] = WPAIB_Rest_Helper::next_cursor( $items );
			$response['has_more']      = count( $items ) === $per_page;
		} elseif ( $per_page > 0 ) {
			$response['page'] = $page;
		}

		return new WP_REST_Response( $response, 200 );
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
