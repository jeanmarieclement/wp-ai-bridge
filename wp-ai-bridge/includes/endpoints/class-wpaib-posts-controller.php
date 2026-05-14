<?php
/**
 * Controller per gestione post.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint /posts (lista, crea, leggi, aggiorna, cestina).
 */
class WPAIB_Posts_Controller {

	/**
	 * Registra le route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/posts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_posts' ),
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
					'callback'            => array( $this, 'create_post' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/posts/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_post' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_post' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'delete_posts' ),
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/posts/(?P<id>\d+)/comments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_comments' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_comment' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/comments/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'moderate_comment' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'moderate_comments' ),
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/comments/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_moderate_comments' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'moderate_comments' ),
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/comments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_comments' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/posts/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_update_posts' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
			)
		);
	}

	/**
	 * Lista post.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function list_posts( WP_REST_Request $request ) {
		$status   = sanitize_key( $request->get_param( 'status' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$allowed_statuses = array( 'any', 'publish', 'draft', 'pending', 'private', 'future' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'any';
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => $status,
				'posts_per_page' => $per_page,
				'paged'          => $page,
			)
		);

		$items = array();
		foreach ( $query->posts as $p ) {
			$items[] = $this->prepare_post( $p );
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
	 * Crea un post.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_post( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_Error( 'wpaib_invalid_body', __( 'Invalid JSON body.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$title   = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';
		$content = isset( $params['content'] ) ? wp_kses_post( $params['content'] ) : '';
		$excerpt = isset( $params['excerpt'] ) ? sanitize_text_field( $params['excerpt'] ) : '';
		$slug    = isset( $params['slug'] ) ? sanitize_title( $params['slug'] ) : '';
		$status  = isset( $params['status'] ) ? sanitize_key( $params['status'] ) : 'draft';

		$allowed_statuses = array( 'draft', 'pending', 'publish', 'private', 'future' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'draft';
		}

		// Per pubblicare serve publish_posts; altrimenti retrocede a draft.
		if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) {
			$status = 'draft';
		}

		if ( empty( $title ) ) {
			return new WP_Error( 'wpaib_missing_title', __( 'Title is required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$post_arr = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $status,
			'post_type'    => 'post',
			'post_author'  => get_current_user_id(),
		);
		if ( ! empty( $slug ) ) {
			$post_arr['post_name'] = $slug;
		}

		if ( ! empty( $params['date'] ) ) {
			$timestamp = strtotime( sanitize_text_field( $params['date'] ) );
			if ( false === $timestamp ) {
				return new WP_Error( 'wpaib_invalid_date', __( 'Invalid date format. Use ISO 8601.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
			}
			$post_arr['post_date']     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) );
			$post_arr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
			if ( $timestamp > time() && 'publish' !== $post_arr['post_status'] ) {
				$post_arr['post_status'] = 'future';
			}
		}

		$post_id = wp_insert_post( $post_arr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Categorie.
		if ( ! empty( $params['categories'] ) && is_array( $params['categories'] ) ) {
			$cat_ids = array_map( 'absint', $params['categories'] );
			wp_set_post_categories( $post_id, $cat_ids );
		}

		// Tag.
		if ( ! empty( $params['tags'] ) && is_array( $params['tags'] ) ) {
			$tags = array_map( 'sanitize_text_field', $params['tags'] );
			wp_set_post_tags( $post_id, $tags );
		}

		// Immagine in evidenza.
		if ( ! empty( $params['featured_media'] ) ) {
			set_post_thumbnail( $post_id, (int) $params['featured_media'] );
		}

		return new WP_REST_Response( $this->prepare_post( get_post( $post_id ) ), 201 );
	}

	/**
	 * Recupera un post.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_post( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'wpaib_not_found', __( 'Post not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Cannot read this post.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		return new WP_REST_Response( $this->prepare_post( $post ), 200 );
	}

	/**
	 * Aggiorna un post.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_post( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'wpaib_not_found', __( 'Post not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Cannot edit this post.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
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
		if ( isset( $params['excerpt'] ) ) {
			$update['post_excerpt'] = sanitize_text_field( $params['excerpt'] );
		}
		if ( isset( $params['slug'] ) ) {
			$update['post_name'] = sanitize_title( $params['slug'] );
		}
		if ( isset( $params['status'] ) ) {
			$status           = sanitize_key( $params['status'] );
			$allowed_statuses = array( 'draft', 'pending', 'publish', 'private', 'future' );
			if ( in_array( $status, $allowed_statuses, true ) ) {
				if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) {
					$status = 'draft';
				}
				$update['post_status'] = $status;
			}
		}

		if ( isset( $params['date'] ) ) {
			$timestamp = strtotime( sanitize_text_field( $params['date'] ) );
			if ( false === $timestamp ) {
				return new WP_Error( 'wpaib_invalid_date', __( 'Invalid date format. Use ISO 8601.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
			}
			$update['post_date']     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) );
			$update['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
			if ( $timestamp > time() && empty( $update['post_status'] ) ) {
				$update['post_status'] = 'future';
			}
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $params['categories'] ) && is_array( $params['categories'] ) ) {
			$cat_ids = array_map( 'absint', $params['categories'] );
			wp_set_post_categories( $id, $cat_ids );
		}
		if ( isset( $params['tags'] ) && is_array( $params['tags'] ) ) {
			$tags = array_map( 'sanitize_text_field', $params['tags'] );
			wp_set_post_tags( $id, $tags );
		}
		if ( isset( $params['featured_media'] ) ) {
			set_post_thumbnail( $id, (int) $params['featured_media'] );
		}

		return new WP_REST_Response( $this->prepare_post( get_post( $id ) ), 200 );
	}

	/**
	 * Cestina (o elimina) un post.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_post( WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$force = (bool) $request->get_param( 'force' );

		$post = get_post( $id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'wpaib_not_found', __( 'Post not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Cannot delete this post.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		$result = wp_delete_post( $id, $force );
		if ( ! $result ) {
			return new WP_Error( 'wpaib_delete_failed', __( 'Could not delete post.', 'wp-ai-bridge' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id, 'force' => $force ), 200 );
	}

	/**
	 * Aggiorna lo stato di più post in blocco.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_update_posts( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		if ( empty( $params['ids'] ) || ! is_array( $params['ids'] ) || empty( $params['status'] ) ) {
			return new WP_Error( 'wpaib_missing_params', __( 'IDs array and status are required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$status           = sanitize_key( $params['status'] );
		$allowed_statuses = array( 'publish', 'draft', 'pending', 'private' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			return new WP_Error( 'wpaib_invalid_status', __( 'Invalid status.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}
		if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		$updated = array();
		$failed  = array();

		foreach ( $params['ids'] as $id ) {
			$id   = absint( $id );
			$post = get_post( $id );

			if ( ! $post || 'post' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
				$failed[] = array( 'id' => $id, 'error' => 'not_found_or_forbidden' );
				continue;
			}

			$result = wp_update_post( array( 'ID' => $id, 'post_status' => $status ), true );
			if ( is_wp_error( $result ) ) {
				$failed[] = array( 'id' => $id, 'error' => $result->get_error_message() );
			} else {
				$updated[] = $id;
			}
		}

		return new WP_REST_Response( array( 'updated' => $updated, 'failed' => $failed ), 200 );
	}

	/**
	 * Prepara la rappresentazione di un post per la risposta.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function prepare_post( $post ) {
		return array(
			'id'             => (int) $post->ID,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'excerpt'        => $post->post_excerpt,
			'content'        => $post->post_content,
			'author'         => (int) $post->post_author,
			'date'           => $post->post_date_gmt,
			'modified'       => $post->post_modified_gmt,
			'categories'     => wp_get_post_categories( $post->ID ),
			'tags'           => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
			'featured_media' => (int) get_post_thumbnail_id( $post->ID ),
			'link'           => get_permalink( $post->ID ),
		);
	}

	/**
	 * Lista i commenti di un post.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_comments( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$status  = ! empty( $request['status'] ) ? sanitize_key( $request['status'] ) : 'approve';

		$args = array(
			'status' => $status,
		);

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'wpaib_not_found', __( 'Post not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
			}
			$args['post_id'] = $post_id;
		}

		$comments = get_comments( $args );

		$items = array();
		foreach ( $comments as $comment ) {
			$items[] = array(
				'id'      => (int) $comment->comment_ID,
				'author'  => $comment->comment_author,
				'content' => $comment->comment_content,
				'date'    => $comment->comment_date_gmt,
				'post_id' => (int) $comment->comment_post_ID,
			);
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * Crea un commento per un post.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_comment( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$params = $request->get_json_params();

		if ( empty( $params['content'] ) ) {
			return new WP_Error( 'wpaib_missing_content', __( 'Comment content is required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'wpaib_not_found', __( 'Post not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}

		$user = wp_get_current_user();

		$comment_data = array(
			'comment_post_ID'      => $id,
			'comment_content'      => wp_kses_post( $params['content'] ),
			'comment_type'         => 'comment',
			'user_id'              => $user->ID,
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_approved'     => 1, // Approvato automaticamente poiché inviato tramite API protetta.
		);

		$comment_id = wp_insert_comment( $comment_data );

		if ( ! $comment_id ) {
			return new WP_Error( 'wpaib_comment_failed', __( 'Could not create comment.', 'wp-ai-bridge' ), array( 'status' => 500 ) );
		}

		$comment = get_comment( $comment_id );

		return new WP_REST_Response(
			array(
				'id'      => (int) $comment->comment_ID,
				'author'  => $comment->comment_author,
				'content' => $comment->comment_content,
				'date'    => $comment->comment_date_gmt,
			),
			201
		);
	}

	/**
	 * Modera un commento.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function moderate_comment( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$params = $request->get_json_params();

		if ( empty( $params['status'] ) ) {
			return new WP_Error( 'wpaib_missing_status', __( 'Status is required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$status = sanitize_key( $params['status'] );
		$allowed = array( 'approve', 'hold', 'spam', 'trash' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'wpaib_invalid_status', __( 'Invalid status.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		if ( ! wp_set_comment_status( $id, $status ) ) {
			return new WP_Error( 'wpaib_moderate_failed', __( 'Could not update comment status.', 'wp-ai-bridge' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'id' => $id, 'status' => $status ), 200 );
	}

	/**
	 * Modera più commenti in blocco.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_moderate_comments( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		if ( empty( $params['ids'] ) || ! is_array( $params['ids'] ) || empty( $params['status'] ) ) {
			return new WP_Error( 'wpaib_missing_params', __( 'IDs array and status are required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$status = sanitize_key( $params['status'] );
		$allowed = array( 'approve', 'hold', 'spam', 'trash' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'wpaib_invalid_status', __( 'Invalid status.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$results = array(
			'success' => array(),
			'failed'  => array(),
		);

		foreach ( $params['ids'] as $id ) {
			if ( wp_set_comment_status( (int) $id, $status ) ) {
				$results['success'][] = (int) $id;
			} else {
				$results['failed'][] = (int) $id;
			}
		}

		return new WP_REST_Response( $results, 200 );
	}
}
