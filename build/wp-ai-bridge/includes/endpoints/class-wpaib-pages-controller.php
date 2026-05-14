<?php
/**
 * Controller per gestione pagine WordPress.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint /pages (lista, crea, leggi, aggiorna, elimina).
 */
class WPAIB_Pages_Controller {

	/**
	 * Registra le route REST.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/pages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_pages' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
					'args'                => array(
						'status'   => array(
							'default'           => 'any',
							'sanitize_callback' => 'sanitize_key',
						),
						'per_page' => array(
							'default'           => 10,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_page' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_pages' ),
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/pages/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_page' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_page' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_pages' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_page' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'delete_pages' ),
				),
			)
		);
	}

	/**
	 * Lista pagine.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function list_pages( WP_REST_Request $request ) {
		$status   = sanitize_key( $request->get_param( 'status' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$allowed_statuses = array( 'any', 'publish', 'draft', 'pending', 'private', 'future' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'any';
		}

		if ( 'any' === $status ) {
			$status = array( 'publish', 'draft', 'pending', 'private', 'future' );
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => $status,
				'posts_per_page' => $per_page,
				'paged'          => $page,
			)
		);

		$items = array();
		foreach ( $query->posts as $p ) {
			$items[] = $this->prepare_page( $p );
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
			),
			200
		);
	}

	/**
	 * Recupera una singola pagina.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_page( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$page = get_post( $id );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'wpaib_not_found', __( 'Page not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Cannot read this page.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		return new WP_REST_Response( $this->prepare_page( $page ), 200 );
	}

	/**
	 * Crea una nuova pagina.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_page( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_Error( 'wpaib_invalid_body', __( 'Invalid JSON body.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$title = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';
		if ( empty( $title ) ) {
			return new WP_Error( 'wpaib_missing_title', __( 'Title is required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$content  = isset( $params['content'] ) ? wp_kses_post( $params['content'] ) : '';
		$status   = isset( $params['status'] ) ? sanitize_key( $params['status'] ) : 'draft';
		$parent   = isset( $params['parent_id'] ) ? absint( $params['parent_id'] ) : 0;

		$allowed_statuses = array( 'draft', 'pending', 'publish', 'private' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'draft';
		}
		if ( 'publish' === $status && ! current_user_can( 'publish_pages' ) ) {
			$status = 'draft';
		}

		$page_arr = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_type'    => 'page',
			'post_author'  => get_current_user_id(),
			'post_parent'  => $parent,
		);

		$page_id = wp_insert_post( $page_arr, true );
		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		return new WP_REST_Response( $this->prepare_page( get_post( $page_id ) ), 201 );
	}

	/**
	 * Aggiorna una pagina esistente.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_page( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$page = get_post( $id );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'wpaib_not_found', __( 'Page not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Cannot edit this page.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_Error( 'wpaib_invalid_body', __( 'Invalid JSON body.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$update = array( 'ID' => $id );

		if ( isset( $params['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $params['title'] );
		}
		if ( isset( $params['content'] ) ) {
			$update['post_content'] = wp_kses_post( $params['content'] );
		}
		if ( isset( $params['status'] ) ) {
			$status           = sanitize_key( $params['status'] );
			$allowed_statuses = array( 'draft', 'pending', 'publish', 'private' );
			if ( in_array( $status, $allowed_statuses, true ) ) {
				if ( 'publish' === $status && ! current_user_can( 'publish_pages' ) ) {
					$status = 'draft';
				}
				$update['post_status'] = $status;
			}
		}
		if ( isset( $params['parent_id'] ) ) {
			$update['post_parent'] = absint( $params['parent_id'] );
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $this->prepare_page( get_post( $id ) ), 200 );
	}

	/**
	 * Elimina o cestina una pagina.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_page( WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$force = (bool) $request->get_param( 'force' );
		$page  = get_post( $id );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'wpaib_not_found', __( 'Page not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Cannot delete this page.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		$result = wp_delete_post( $id, $force );
		if ( ! $result ) {
			return new WP_Error( 'wpaib_delete_failed', __( 'Could not delete page.', 'wp-ai-bridge' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'deleted' => $force,
				'trashed' => ! $force,
				'id'      => $id,
			),
			200
		);
	}

	/**
	 * Prepara la rappresentazione di una pagina per la risposta.
	 *
	 * @param WP_Post $page Pagina.
	 * @return array
	 */
	private function prepare_page( $page ) {
		return array(
			'id'             => (int) $page->ID,
			'title'          => $page->post_title,
			'slug'           => $page->post_name,
			'status'         => $page->post_status,
			'content'        => $page->post_content,
			'excerpt'        => $page->post_excerpt,
			'author'         => (int) $page->post_author,
			'date'           => $page->post_date_gmt,
			'modified'       => $page->post_modified_gmt,
			'parent'         => (int) $page->post_parent,
			'featured_media' => (int) get_post_thumbnail_id( $page->ID ),
			'link'           => get_permalink( $page->ID ),
		);
	}
}
