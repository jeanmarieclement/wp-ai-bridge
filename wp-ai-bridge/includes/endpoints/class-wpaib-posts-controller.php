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
						'status'           => array(
							'default'           => 'any',
							'sanitize_callback' => 'sanitize_key',
						),
						'per_page'         => array(
							'default'           => 10,
							'sanitize_callback' => 'absint',
						),
						'page'             => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'after_id'         => array(
							'sanitize_callback' => 'absint',
						),
						'content_rendered' => array(
							'default'           => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
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
					'args'                => array(
						'content_rendered' => array(
							'default'           => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
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

		// Route unica per /comments: estesa per l'export invece di registrarne una
		// seconda sullo stesso path, dove il comportamento dipenderebbe dall'ordine
		// di registrazione. Gli stati diversi da `approve` richiedono
		// moderate_comments, verificata dentro la callback.
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/comments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_comments' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
					'args'                => array(
						'status'    => array(
							'default'           => 'approve',
							'sanitize_callback' => 'sanitize_key',
						),
						'per_page'  => array(
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'page'      => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'after_id'  => array(
							'sanitize_callback' => 'absint',
						),
						'post_type' => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
					),
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
		$per_page = WPAIB_Rest_Helper::per_page( $request->get_param( 'per_page' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$after_id = WPAIB_Rest_Helper::after_id( $request->get_param( 'after_id' ) );
		$rendered = (bool) $request->get_param( 'content_rendered' );

		$query = WPAIB_Rest_Helper::query_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => WPAIB_Rest_Helper::post_status( $request->get_param( 'status' ) ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
			),
			$after_id
		);

		$items = array();
		foreach ( $query->posts as $p ) {
			$items[] = $this->prepare_post( $p, $rendered );
		}

		$response = array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		);

		if ( null !== $after_id ) {
			// Con il cursore la paginazione per pagina non ha significato: il
			// client continua passando next_after_id finché has_more è false.
			// Il conteggio è quello dei record che restano dal cursore in poi,
			// non il totale della collezione, e viene nominato di conseguenza.
			$response['total_remaining'] = $response['total'];
			unset( $response['total'], $response['total_pages'], $response['page'] );
			$response['after_id']      = $after_id;
			$response['next_after_id'] = WPAIB_Rest_Helper::next_cursor( $items );
			$response['has_more']      = $response['total_remaining'] > count( $items );
		}

		return new WP_REST_Response( $response, 200 );
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

		return new WP_REST_Response( $this->prepare_post( $post, (bool) $request->get_param( 'content_rendered' ) ), 200 );
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
	 * @param WP_Post $post     Post.
	 * @param bool    $rendered Se includere anche il contenuto renderizzato.
	 * @return array
	 */
	private function prepare_post( $post, $rendered = true ) {
		$category_ids = array_map( 'intval', wp_get_post_categories( $post->ID ) );
		$tags         = wp_get_post_tags( $post->ID );
		if ( is_wp_error( $tags ) ) {
			$tags = array();
		}

		$data = array(
			'id'                => (int) $post->ID,
			'title'             => $post->post_title,
			'slug'              => $post->post_name,
			'status'            => $post->post_status,
			'excerpt'           => $post->post_excerpt,
			'content'           => $post->post_content,
			'author'            => (int) $post->post_author,
			'date'              => $post->post_date_gmt,
			'modified'          => $post->post_modified_gmt,
			// Date esplicite in UTC, per un consumatore che deve ricostruirle altrove.
			'post_date_gmt'     => $post->post_date_gmt,
			'post_modified_gmt' => $post->post_modified_gmt,
			'comment_status'    => $post->comment_status,
			'menu_order'        => (int) $post->menu_order,
			// Sempre 0 per gli articoli, ma presente come su pagine, CPT e media:
			// un client di export legge lo stesso campo per ogni tipo.
			'parent'            => (int) $post->post_parent,
			'categories'        => $category_ids,
			'tags'              => wp_list_pluck( $tags, 'name' ),
			// Gli ID sono l'unica base stabile per una mappatura fra siti: i nomi
			// dei termini possono collidere o cambiare.
			'category_ids'      => $category_ids,
			'tag_ids'           => array_map( 'intval', wp_list_pluck( $tags, 'term_id' ) ),
			'featured_media'    => (int) get_post_thumbnail_id( $post->ID ),
			'link'              => get_permalink( $post->ID ),
		);

		if ( $rendered ) {
			$data['content_rendered'] = WPAIB_Rest_Helper::render_content( $post );
		}

		return $data;
	}

	/**
	 * Lista i commenti, di un singolo post o dell'intero sito.
	 *
	 * Serve sia /posts/{id}/comments sia /comments. Senza per_page restituisce
	 * tutti i commenti, come faceva prima; con after_id passa alla paginazione
	 * a cursore, ordinata per comment_ID crescente.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_comments( WP_REST_Request $request ) {
		$post_id   = (int) $request['id'];
		$status    = ! empty( $request['status'] ) ? sanitize_key( $request['status'] ) : 'approve';
		$after_id  = WPAIB_Rest_Helper::after_id( $request->get_param( 'after_id' ) );
		$per_page  = absint( $request->get_param( 'per_page' ) );
		$page      = max( 1, (int) $request->get_param( 'page' ) );
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );

		$allowed_statuses = array( 'approve', 'hold', 'spam', 'trash', 'all' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			return new WP_Error( 'wpaib_invalid_status', __( 'Invalid status.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		// I commenti non approvati — e i dati personali di chi li ha scritti —
		// restano fuori dalla portata della sola capability edit_posts.
		$can_moderate = current_user_can( 'moderate_comments' );
		if ( 'approve' !== $status && ! $can_moderate ) {
			return new WP_Error(
				'wpaib_forbidden',
				__( 'Insufficient permissions.', 'wp-ai-bridge' ),
				array( 'status' => 403 )
			);
		}

		$args = array(
			'status'                    => $status,
			// Prepara la cache dei post in una query sola: senza, ogni commento
			// ne farebbe una per risalire al proprio post_type.
			'update_comment_post_cache' => true,
		);

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'wpaib_not_found', __( 'Post not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
			}
			$args['post_id'] = $post_id;
		}

		if ( ! empty( $post_type ) ) {
			$args['post_type'] = $post_type;
		}

		// Un limite serve sempre. Senza, WP_Comment_Query gira senza LIMIT e
		// carica in memoria ogni commento del sito: su un sito con decine di
		// migliaia di commenti la richiesta esaurisce la memoria di PHP.
		if ( $per_page < 1 ) {
			$per_page = WPAIB_Rest_Helper::MAX_PER_PAGE;
		}
		if ( $per_page > 0 ) {
			$args['number'] = WPAIB_Rest_Helper::per_page( $per_page );
			$per_page       = $args['number'];

			// Fuori dalla modalità cursore, per_page senza offset lascerebbe il
			// client fermo sulla prima pagina per sempre.
			if ( null === $after_id && $page > 1 ) {
				$args['offset'] = ( $page - 1 ) * $per_page;
			}
		}

		$comments = WPAIB_Rest_Helper::query_comments( $args, $after_id );

		$items = array();
		foreach ( $comments as $comment ) {
			$items[] = $this->prepare_comment( $comment, $can_moderate );
		}

		$count_args = array_merge(
			$args,
			array(
				'count'        => true,
				'number'       => 0,
				'offset'       => 0,
				'cache_domain' => 'core',
			)
		);

		if ( null !== $after_id ) {
			// Il conteggio deve rispettare lo stesso cursore della query, altrimenti
			// riporta l'intera collezione invece dei soli record rimasti da leggere.
			// La clausola arriva da un filtro, che non entra nella chiave di cache
			// di WP_Comment_Query: senza un cache_domain distinto, con un object
			// cache persistente questo conteggio tornerebbe quello di un'altra pagina.
			$count_args['wpaib_after_id'] = $after_id;
			$count_args['cache_domain']   = 'wpaib_count_after_' . $after_id;
			add_filter( 'comments_clauses', array( 'WPAIB_Rest_Helper', 'filter_comments_clauses' ), 10, 2 );
		}

		$total = (int) ( new WP_Comment_Query() )->query( $count_args );

		if ( null !== $after_id ) {
			remove_filter( 'comments_clauses', array( 'WPAIB_Rest_Helper', 'filter_comments_clauses' ), 10 );
		}

		$response = array(
			'items' => $items,
			'total' => $total,
		);

		if ( null !== $after_id ) {
			// Con il cursore il conteggio è quello dei record rimasti dopo di
			// esso, non il totale della collezione: nominato di conseguenza.
			$response['total_remaining'] = $response['total'];
			unset( $response['total'] );
			$response['after_id']      = $after_id;
			$response['next_after_id'] = WPAIB_Rest_Helper::next_cursor( $items );
			$response['has_more']      = $response['total_remaining'] > count( $items );
		} elseif ( $per_page > 0 ) {
			$response['page']        = $page;
			$response['total_pages'] = (int) ceil( $total / $per_page );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Prepara la rappresentazione di un commento per la risposta.
	 *
	 * @param WP_Comment $comment      Commento.
	 * @param bool       $can_moderate Se l'utente può moderare (sblocca i dati personali).
	 * @return array
	 */
	private function prepare_comment( $comment, $can_moderate ) {
		$parent_post = get_post( (int) $comment->comment_post_ID );

		$data = array(
			'id'                => (int) $comment->comment_ID,
			'author'            => $comment->comment_author,
			'content'           => $comment->comment_content,
			'date'              => $comment->comment_date_gmt,
			'post_id'           => (int) $comment->comment_post_ID,
			'parent'            => (int) $comment->comment_parent,
			'author_url'        => $comment->comment_author_url,
			'user_id'           => (int) $comment->user_id,
			'status'            => wp_get_comment_status( $comment ),
			'type'              => $comment->comment_type,
			'comment_post_type' => $parent_post ? $parent_post->post_type : '',
		);

		// Email e IP sono dati personali: fuori portata senza moderate_comments.
		if ( $can_moderate ) {
			$data['author_email'] = $comment->comment_author_email;
			$data['author_ip']    = $comment->comment_author_IP;
		}

		return $data;
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
