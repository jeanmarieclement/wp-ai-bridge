<?php
/**
 * Controller per Custom Post Types (CPT).
 *
 * Espone endpoint CRUD generici per qualsiasi CPT registrato
 * nel sito con public=true e show_in_rest=true.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint /cpt (discovery), /cpt/{type} (lista, crea), /cpt/{type}/{id} (leggi, aggiorna, elimina).
 */
class WPAIB_CPT_Controller {

	/**
	 * Post types built-in già gestiti da altri controller.
	 *
	 * @var array
	 */
	private static $excluded_types = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'wp_pattern' );

	/**
	 * Registra le route REST.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Discovery: elenca i CPT disponibili.
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/cpt',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_post_types' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
			)
		);

		// Lista e crea items di un CPT.
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/cpt/(?P<type>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
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
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
			)
		);

		// Leggi, aggiorna, elimina un singolo item.
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/cpt/(?P<type>[a-z0-9_-]+)/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'delete_posts' ),
				),
			)
		);
	}

	/**
	 * Restituisce i CPT custom disponibili.
	 *
	 * @return WP_REST_Response
	 */
	public function list_post_types() {
		$cpt_objects = $this->get_available_cpts();
		$items       = array();

		foreach ( $cpt_objects as $slug => $obj ) {
			$taxonomies = get_object_taxonomies( $slug, 'objects' );
			$tax_list   = array();
			foreach ( $taxonomies as $tax_slug => $tax_obj ) {
				$tax_list[] = array(
					'slug'         => $tax_slug,
					'name'         => $tax_obj->labels->name,
					'hierarchical' => $tax_obj->hierarchical,
				);
			}

			$items[] = array(
				'slug'        => $slug,
				'name'        => $obj->labels->name,
				'singular'    => $obj->labels->singular_name,
				'description' => $obj->description,
				'hierarchical'=> $obj->hierarchical,
				'supports'    => get_all_post_type_supports( $slug ),
				'taxonomies'  => $tax_list,
				'has_archive' => (bool) $obj->has_archive,
				'count'       => (int) wp_count_posts( $slug )->publish,
			);
		}

		return new WP_REST_Response( array( 'items' => $items, 'total' => count( $items ) ), 200 );
	}

	/**
	 * Lista paginata di items di un CPT.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_items( WP_REST_Request $request ) {
		$type = sanitize_key( $request['type'] );
		$validation = $this->validate_post_type( $type );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

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
				'post_type'      => $type,
				'post_status'    => $status,
				'posts_per_page' => $per_page,
				'paged'          => $page,
			)
		);

		$items = array();
		foreach ( $query->posts as $p ) {
			$items[] = $this->prepare_item( $p );
		}

		return new WP_REST_Response(
			array(
				'post_type'   => $type,
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
			),
			200
		);
	}

	/**
	 * Recupera un singolo item.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ) {
		$type = sanitize_key( $request['type'] );
		$validation = $this->validate_post_type( $type );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$id   = (int) $request['id'];
		$post = get_post( $id );

		if ( ! $post || $type !== $post->post_type ) {
			return new WP_Error( 'wpaib_not_found', __( 'Item not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Cannot read this item.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		return new WP_REST_Response( $this->prepare_item( $post ), 200 );
	}

	/**
	 * Crea un nuovo item di un CPT.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( WP_REST_Request $request ) {
		$type = sanitize_key( $request['type'] );
		$validation = $this->validate_post_type( $type );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$pt_object = get_post_type_object( $type );

		// Verifica capability specifica del CPT per la creazione.
		if ( ! current_user_can( $pt_object->cap->create_posts ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Cannot create items of this type.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_Error( 'wpaib_invalid_body', __( 'Invalid JSON body.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$title = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';
		if ( empty( $title ) ) {
			return new WP_Error( 'wpaib_missing_title', __( 'Title is required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$content = isset( $params['content'] ) ? wp_kses_post( $params['content'] ) : '';
		$excerpt = isset( $params['excerpt'] ) ? sanitize_text_field( $params['excerpt'] ) : '';
		$slug    = isset( $params['slug'] ) ? sanitize_title( $params['slug'] ) : '';
		$status  = isset( $params['status'] ) ? sanitize_key( $params['status'] ) : 'draft';

		$allowed_statuses = array( 'draft', 'pending', 'publish', 'private' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'draft';
		}
		if ( 'publish' === $status && ! current_user_can( $pt_object->cap->publish_posts ) ) {
			$status = 'draft';
		}

		$post_arr = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $status,
			'post_type'    => $type,
			'post_author'  => get_current_user_id(),
		);
		if ( ! empty( $slug ) ) {
			$post_arr['post_name'] = $slug;
		}

		// Supporto parent per CPT gerarchici.
		if ( $pt_object->hierarchical && isset( $params['parent_id'] ) ) {
			$post_arr['post_parent'] = absint( $params['parent_id'] );
		}

		$post_id = wp_insert_post( $post_arr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Tassonomie: accetta un oggetto { "taxonomy_slug": [term_id, ...] | ["term_name", ...] }.
		if ( ! empty( $params['taxonomies'] ) && is_array( $params['taxonomies'] ) ) {
			$this->set_item_taxonomies( $post_id, $type, $params['taxonomies'] );
		}

		// Immagine in evidenza.
		if ( ! empty( $params['featured_media'] ) ) {
			set_post_thumbnail( $post_id, (int) $params['featured_media'] );
		}

		return new WP_REST_Response( $this->prepare_item( get_post( $post_id ) ), 201 );
	}

	/**
	 * Aggiorna un item esistente.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( WP_REST_Request $request ) {
		$type = sanitize_key( $request['type'] );
		$validation = $this->validate_post_type( $type );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$id   = (int) $request['id'];
		$post = get_post( $id );

		if ( ! $post || $type !== $post->post_type ) {
			return new WP_Error( 'wpaib_not_found', __( 'Item not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Cannot edit this item.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_Error( 'wpaib_invalid_body', __( 'Invalid JSON body.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$pt_object = get_post_type_object( $type );
		$update    = array( 'ID' => $id );

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
			$allowed_statuses = array( 'draft', 'pending', 'publish', 'private' );
			if ( in_array( $status, $allowed_statuses, true ) ) {
				if ( 'publish' === $status && ! current_user_can( $pt_object->cap->publish_posts ) ) {
					$status = 'draft';
				}
				$update['post_status'] = $status;
			}
		}
		if ( $pt_object->hierarchical && isset( $params['parent_id'] ) ) {
			$update['post_parent'] = absint( $params['parent_id'] );
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Tassonomie.
		if ( isset( $params['taxonomies'] ) && is_array( $params['taxonomies'] ) ) {
			$this->set_item_taxonomies( $id, $type, $params['taxonomies'] );
		}

		// Immagine in evidenza.
		if ( isset( $params['featured_media'] ) ) {
			set_post_thumbnail( $id, (int) $params['featured_media'] );
		}

		return new WP_REST_Response( $this->prepare_item( get_post( $id ) ), 200 );
	}

	/**
	 * Cestina o elimina un item.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( WP_REST_Request $request ) {
		$type = sanitize_key( $request['type'] );
		$validation = $this->validate_post_type( $type );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$id    = (int) $request['id'];
		$force = (bool) $request->get_param( 'force' );
		$post  = get_post( $id );

		if ( ! $post || $type !== $post->post_type ) {
			return new WP_Error( 'wpaib_not_found', __( 'Item not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Cannot delete this item.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		$result = wp_delete_post( $id, $force );
		if ( ! $result ) {
			return new WP_Error( 'wpaib_delete_failed', __( 'Could not delete item.', 'wp-ai-bridge' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'deleted'   => $force,
				'trashed'   => ! $force,
				'id'        => $id,
				'post_type' => $type,
			),
			200
		);
	}

	// ─── Metodi privati ───────────────────────────────────────────────────

	/**
	 * Restituisce i CPT custom disponibili come array di WP_Post_Type.
	 *
	 * @return array<string, WP_Post_Type>
	 */
	private function get_available_cpts() {
		$all = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			),
			'objects'
		);

		foreach ( self::$excluded_types as $builtin ) {
			unset( $all[ $builtin ] );
		}

		return $all;
	}

	/**
	 * Valida che un post type sia un CPT disponibile.
	 *
	 * @param string $type Slug del post type.
	 * @return true|WP_Error
	 */
	private function validate_post_type( $type ) {
		$available = $this->get_available_cpts();

		if ( ! isset( $available[ $type ] ) ) {
			return new WP_Error(
				'wpaib_invalid_post_type',
				/* translators: %s: post type slug */
				sprintf( __( 'Custom post type "%s" not found or not accessible.', 'wp-ai-bridge' ), $type ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Prepara la rappresentazione di un item per la risposta.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function prepare_item( $post ) {
		$item = array(
			'id'             => (int) $post->ID,
			'post_type'      => $post->post_type,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'author'         => (int) $post->post_author,
			'date'           => $post->post_date_gmt,
			'modified'       => $post->post_modified_gmt,
			'parent'         => (int) $post->post_parent,
			'featured_media' => (int) get_post_thumbnail_id( $post->ID ),
			'link'           => get_permalink( $post->ID ),
		);

		// Tassonomie associate al CPT.
		$taxonomies      = get_object_taxonomies( $post->post_type, 'objects' );
		$item_taxonomies = array();

		foreach ( $taxonomies as $tax_slug => $tax_obj ) {
			$terms = wp_get_post_terms( $post->ID, $tax_slug );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$term_list = array();
			foreach ( $terms as $term ) {
				$term_list[] = array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			}
			$item_taxonomies[ $tax_slug ] = $term_list;
		}

		$item['taxonomies'] = $item_taxonomies;

		return $item;
	}

	/**
	 * Assegna tassonomie a un item.
	 *
	 * Accetta un oggetto con chiave = slug tassonomia e valore = array di
	 * ID (interi) o nomi (stringhe) dei termini.
	 *
	 * @param int    $post_id    ID del post.
	 * @param string $post_type  Slug del post type.
	 * @param array  $taxonomies Dati tassonomie.
	 * @return void
	 */
	private function set_item_taxonomies( $post_id, $post_type, $taxonomies ) {
		$valid_taxonomies = get_object_taxonomies( $post_type );

		foreach ( $taxonomies as $tax_slug => $terms ) {
			$tax_slug = sanitize_key( $tax_slug );
			if ( ! in_array( $tax_slug, $valid_taxonomies, true ) ) {
				continue;
			}
			if ( ! is_array( $terms ) ) {
				continue;
			}

			// Determina se sono ID (int) o nomi (string).
			$term_ids = array();
			foreach ( $terms as $term_value ) {
				if ( is_int( $term_value ) || ctype_digit( (string) $term_value ) ) {
					$term_ids[] = (int) $term_value;
				} else {
					// Cerca o crea il termine per nome.
					$term_name = sanitize_text_field( $term_value );
					$existing  = get_term_by( 'name', $term_name, $tax_slug );
					if ( $existing ) {
						$term_ids[] = (int) $existing->term_id;
					} else {
						$new_term = wp_insert_term( $term_name, $tax_slug );
						if ( ! is_wp_error( $new_term ) ) {
							$term_ids[] = (int) $new_term['term_id'];
						}
					}
				}
			}

			wp_set_object_terms( $post_id, $term_ids, $tax_slug );
		}
	}

	/**
	 * Restituisce gli slug dei CPT disponibili.
	 *
	 * Utile per l'integrazione con il Search Controller.
	 *
	 * @return array<string>
	 */
	public function get_available_cpt_slugs() {
		return array_keys( $this->get_available_cpts() );
	}
}
