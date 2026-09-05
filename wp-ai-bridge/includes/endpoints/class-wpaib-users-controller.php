<?php
/**
 * Controller di sola lettura per gli utenti del sito.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint GET /users.
 *
 * Serve a un consumatore che deve ricreare gli autori su un altro sito: nessuna
 * password e nessun hash lasciano WordPress, gli account di destinazione vanno
 * creati con credenziali proprie.
 */
class WPAIB_Users_Controller {

	/**
	 * Registra le route REST.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/users',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_users' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'list_users' ),
					'args'                => array(
						'per_page' => array(
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'after_id' => array(
							'sanitize_callback' => 'absint',
						),
						'role'     => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/users/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_user' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'list_users' ),
				),
			)
		);
	}

	/**
	 * Lista gli utenti registrati.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function list_users( WP_REST_Request $request ) {
		$per_page = WPAIB_Rest_Helper::per_page( $request->get_param( 'per_page' ), 20 );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$after_id = WPAIB_Rest_Helper::after_id( $request->get_param( 'after_id' ) );
		$role     = sanitize_key( (string) $request->get_param( 'role' ) );

		$args = array(
			'number'      => $per_page,
			'count_total' => true,
			'fields'      => 'all',
		);

		if ( null === $after_id ) {
			$args['offset']  = ( $page - 1 ) * $per_page;
			$args['orderby'] = 'ID';
			$args['order']   = 'ASC';
		}

		if ( ! empty( $role ) ) {
			$args['role'] = $role;
		}

		$query = WPAIB_Rest_Helper::query_users( $args, $after_id );

		$items = array();
		foreach ( $query->get_results() as $user ) {
			$items[] = $this->prepare_user( $user );
		}

		$total = (int) $query->get_total();

		$response = array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
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
	 * Recupera un singolo utente.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_user( WP_REST_Request $request ) {
		$user = get_userdata( (int) $request['id'] );

		if ( ! $user ) {
			return new WP_Error( 'wpaib_not_found', __( 'User not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->prepare_user( $user ), 200 );
	}

	/**
	 * Prepara la rappresentazione di un utente.
	 *
	 * Volutamente assenti user_pass e ogni altro materiale crittografico: questo
	 * endpoint descrive chi ha scritto cosa, non permette di impersonare nessuno.
	 *
	 * @param WP_User $user Utente.
	 * @return array
	 */
	private function prepare_user( $user ) {
		$id = (int) $user->ID;

		return array(
			'id'           => $id,
			'login'        => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => get_user_meta( $id, 'first_name', true ),
			'last_name'    => get_user_meta( $id, 'last_name', true ),
			'nickname'     => get_user_meta( $id, 'nickname', true ),
			'slug'         => $user->user_nicename,
			'url'          => $user->user_url,
			'description'  => get_user_meta( $id, 'description', true ),
			'roles'        => array_values( (array) $user->roles ),
			'role'         => ! empty( $user->roles ) ? reset( $user->roles ) : '',
			'registered'   => $user->user_registered,
			'avatar_url'   => get_avatar_url( $id ),
			'posts_count'  => (int) count_user_posts( $id, 'post' ),
			'author_link'  => get_author_posts_url( $id ),
		);
	}
}
