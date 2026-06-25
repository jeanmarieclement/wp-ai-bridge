<?php
/**
 * Controller per ricerca cross-content.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint /search (ricerca su post, pagine, media, commenti, termini).
 */
class WPAIB_Search_Controller {

	/**
	 * Registra le route REST.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/search',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'search' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
					'args'                => array(
						'query'    => array(
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'types'    => array(
							'default' => array( 'posts', 'pages' ),
						),
						'per_page' => array(
							'default'           => 10,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Esegue ricerca cross-content.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search( WP_REST_Request $request ) {
		$query    = sanitize_text_field( (string) $request->get_param( 'query' ) );
		$per_page = min( 50, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$types    = $request->get_param( 'types' );

		if ( ! is_array( $types ) ) {
			$types = array( 'posts', 'pages' );
		}

		$allowed_types = array( 'posts', 'pages', 'media', 'comments', 'terms' );

		// Aggiungi dinamicamente i CPT disponibili ai tipi consentiti.
		$cpt_controller = new WPAIB_CPT_Controller();
		$cpt_slugs      = $cpt_controller->get_available_cpt_slugs();
		$allowed_types  = array_merge( $allowed_types, $cpt_slugs );

		$types = array_intersect( $types, $allowed_types );
		if ( empty( $types ) ) {
			$types = array( 'posts', 'pages' );
		}

		$results = array();

		if ( in_array( 'posts', $types, true ) ) {
			$results = array_merge( $results, $this->search_post_type( $query, 'post', $per_page ) );
		}
		if ( in_array( 'pages', $types, true ) ) {
			$results = array_merge( $results, $this->search_post_type( $query, 'page', $per_page ) );
		}
		if ( in_array( 'media', $types, true ) ) {
			$results = array_merge( $results, $this->search_post_type( $query, 'attachment', $per_page ) );
		}
		if ( in_array( 'comments', $types, true ) ) {
			$results = array_merge( $results, $this->search_comments( $query, $per_page ) );
		}
		if ( in_array( 'terms', $types, true ) ) {
			$results = array_merge( $results, $this->search_terms( $query, $per_page ) );
		}

		// Cerca nei Custom Post Types richiesti.
		foreach ( $cpt_slugs as $cpt_slug ) {
			if ( in_array( $cpt_slug, $types, true ) ) {
				$results = array_merge( $results, $this->search_post_type( $query, $cpt_slug, $per_page ) );
			}
		}

		return new WP_REST_Response(
			array(
				'results' => $results,
				'total'   => count( $results ),
			),
			200
		);
	}

	/**
	 * Cerca in un post_type specifico.
	 *
	 * @param string $query     Query di ricerca.
	 * @param string $post_type Tipo di post.
	 * @param int    $per_page  Numero risultati.
	 * @return array
	 */
	private function search_post_type( $query, $post_type, $per_page ) {
		$statuses = current_user_can( 'edit_others_posts' )
			? array( 'publish', 'draft', 'pending', 'private', 'future' )
			: array( 'publish' );

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $statuses,
			'posts_per_page' => $per_page,
		);
		if ( ! empty( $query ) ) {
			$args['s'] = $query;
		}
		$posts = get_posts( $args );

		$results = array();
		foreach ( $posts as $post ) {
			switch ( $post->post_type ) {
				case 'page':
					$type = 'page';
					break;
				case 'attachment':
					$type = 'media';
					break;
				case 'post':
					$type = 'post';
					break;
				default:
					$type = $post->post_type; // CPT: usa lo slug come tipo.
					break;
			}

			$results[] = array(
				'type'    => $type,
				'id'      => (int) $post->ID,
				'title'   => $post->post_title,
				'url'     => get_permalink( $post->ID ),
				'excerpt' => wp_trim_words( $post->post_content, 20 ),
			);
		}

		return $results;
	}

	/**
	 * Cerca nei commenti.
	 *
	 * @param string $query    Query di ricerca.
	 * @param int    $per_page Numero risultati.
	 * @return array
	 */
	private function search_comments( $query, $per_page ) {
		$comments = get_comments(
			array(
				'search'  => $query,
				'number'  => $per_page,
				'status'  => 'approve',
			)
		);

		$results = array();
		foreach ( $comments as $comment ) {
			$results[] = array(
				'type'    => 'comment',
				'id'      => (int) $comment->comment_ID,
				'title'   => wp_trim_words( $comment->comment_content, 10 ),
				'url'     => get_comment_link( $comment->comment_ID ),
				'excerpt' => wp_trim_words( $comment->comment_content, 20 ),
			);
		}

		return $results;
	}

	/**
	 * Cerca in categorie e tag.
	 *
	 * @param string $query    Query di ricerca.
	 * @param int    $per_page Numero risultati.
	 * @return array
	 */
	private function search_terms( $query, $per_page ) {
		$terms = get_terms(
			array(
				'taxonomy'   => array( 'category', 'post_tag' ),
				'search'     => $query,
				'number'     => $per_page,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$results = array();
		foreach ( $terms as $term ) {
			$results[] = array(
				'type'  => 'term',
				'id'    => (int) $term->term_id,
				'title' => $term->name,
				'url'   => get_term_link( $term ),
			);
		}

		return $results;
	}
}
