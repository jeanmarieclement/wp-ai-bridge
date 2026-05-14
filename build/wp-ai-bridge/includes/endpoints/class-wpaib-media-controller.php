<?php
/**
 * Controller per upload media.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint /media.
 */
class WPAIB_Media_Controller {

	/**
	 * Tipi MIME consentiti per l'upload.
	 *
	 * @var array
	 */
	private $allowed_mimes = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
	);

	/**
	 * Dimensione massima upload in byte (5 MB).
	 *
	 * @var int
	 */
	private $max_size = 5242880;

	/**
	 * Registra le route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/media',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'upload_files' ),
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/media',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_media' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
					'args'                => array(
						'per_page'  => array(
							'default'           => 10,
							'sanitize_callback' => 'absint',
						),
						'page'      => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'mime_type' => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/media/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_media' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'delete_posts' ),
				),
			)
		);
	}

	/**
	 * Lista i file nella libreria media.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function list_media( WP_REST_Request $request ) {
		$per_page  = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$page      = max( 1, (int) $request->get_param( 'page' ) );
		$mime_type = sanitize_text_field( $request->get_param( 'mime_type' ) );

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
		);

		if ( ! empty( $mime_type ) ) {
			if ( ! preg_match( '/^[a-z]+\/[a-z0-9.+\-]+$/', $mime_type ) ) {
				$mime_type = '';
			} else {
				$args['post_mime_type'] = $mime_type;
			}
		}

		$query = new WP_Query( $args );

		$items = array();
		foreach ( $query->posts as $attachment ) {
			$items[] = array(
				'id'        => (int) $attachment->ID,
				'title'     => $attachment->post_title,
				'url'       => wp_get_attachment_url( $attachment->ID ),
				'mime_type' => $attachment->post_mime_type,
				'date'      => $attachment->post_date_gmt,
				'alt'       => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
				'caption'   => $attachment->post_excerpt,
			);
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
	 * Elimina o cestina un file dalla libreria media.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_media( WP_REST_Request $request ) {
		$id         = (int) $request['id'];
		$force      = (bool) $request->get_param( 'force' );
		$attachment = get_post( $id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'wpaib_not_found', __( 'Media not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Cannot delete this media.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		$result = wp_delete_attachment( $id, $force );
		if ( ! $result ) {
			return new WP_Error( 'wpaib_delete_failed', __( 'Could not delete media.', 'wp-ai-bridge' ), array( 'status' => 500 ) );
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
	 * Carica un file nella media library.
	 * Accetta upload multipart (campo "file") oppure JSON con "image_base64" + "filename".
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Modalità 1: multipart/form-data con campo "file".
		$files = $request->get_file_params();
		if ( ! empty( $files['file'] ) ) {
			return $this->handle_multipart_upload( $files['file'] );
		}

		// Modalità 2: JSON base64.
		$params = $request->get_json_params();
		if ( ! empty( $params['image_base64'] ) && ! empty( $params['filename'] ) ) {
			return $this->handle_base64_upload( $params['image_base64'], $params['filename'] );
		}

		return new WP_Error( 'wpaib_no_file', __( 'No file provided.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
	}

	/**
	 * Gestisce upload multipart standard.
	 *
	 * @param array $file Array $_FILES.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_multipart_upload( $file ) {
		if ( ! isset( $file['size'] ) || $file['size'] > $this->max_size ) {
			return new WP_Error( 'wpaib_file_too_large', __( 'File too large.', 'wp-ai-bridge' ), array( 'status' => 413 ) );
		}

		$overrides = array(
			'test_form' => false,
			'mimes'     => $this->allowed_mimes,
		);

		$uploaded = wp_handle_upload( $file, $overrides );
		if ( isset( $uploaded['error'] ) ) {
			return new WP_Error( 'wpaib_upload_error', $uploaded['error'], array( 'status' => 400 ) );
		}

		return $this->create_attachment( $uploaded['file'], $uploaded['url'], $uploaded['type'] );
	}

	/**
	 * Gestisce upload da base64.
	 *
	 * @param string $base64   Contenuto codificato.
	 * @param string $filename Nome file.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_base64_upload( $base64, $filename ) {
		$filename = sanitize_file_name( $filename );
		if ( empty( $filename ) ) {
			return new WP_Error( 'wpaib_invalid_filename', __( 'Invalid filename.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		// Rimuove eventuale prefisso data: URI.
		if ( preg_match( '/^data:[^;]+;base64,(.+)$/', $base64, $m ) ) {
			$base64 = $m[1];
		}

		$decoded = base64_decode( $base64, true );
		if ( false === $decoded ) {
			return new WP_Error( 'wpaib_invalid_base64', __( 'Invalid base64 content.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		if ( strlen( $decoded ) > $this->max_size ) {
			return new WP_Error( 'wpaib_file_too_large', __( 'File too large.', 'wp-ai-bridge' ), array( 'status' => 413 ) );
		}

		// Verifica MIME effettivo dal contenuto (non si fida dell'estensione).
		$finfo = new finfo( FILEINFO_MIME_TYPE );
		$mime  = $finfo->buffer( $decoded );
		if ( ! isset( $this->allowed_mimes[ $mime ] ) ) {
			return new WP_Error( 'wpaib_mime_not_allowed', __( 'File type not allowed.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		// Forza estensione coerente col MIME rilevato.
		$ext           = $this->allowed_mimes[ $mime ];
		$filename_base = pathinfo( $filename, PATHINFO_FILENAME );
		$filename      = sanitize_file_name( $filename_base . '.' . $ext );

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'wpaib_upload_dir_error', $upload_dir['error'], array( 'status' => 500 ) );
		}

		$file_path = trailingslashit( $upload_dir['path'] ) . wp_unique_filename( $upload_dir['path'], $filename );
		$file_url  = trailingslashit( $upload_dir['url'] ) . basename( $file_path );

		if ( false === file_put_contents( $file_path, $decoded ) ) {
			return new WP_Error( 'wpaib_write_failed', __( 'Could not write file.', 'wp-ai-bridge' ), array( 'status' => 500 ) );
		}

		return $this->create_attachment( $file_path, $file_url, $mime );
	}

	/**
	 * Registra il file come attachment nella media library.
	 *
	 * @param string $file_path Percorso file.
	 * @param string $file_url  URL file.
	 * @param string $mime      Tipo MIME.
	 * @return WP_REST_Response|WP_Error
	 */
	private function create_attachment( $file_path, $file_url, $mime ) {
		$attachment = array(
			'post_mime_type' => $mime,
			'post_title'     => sanitize_file_name( pathinfo( $file_path, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $file_path );
		if ( is_wp_error( $attach_id ) || 0 === $attach_id ) {
			@unlink( $file_path );
			return new WP_Error( 'wpaib_attach_failed', __( 'Could not create attachment.', 'wp-ai-bridge' ), array( 'status' => 500 ) );
		}

		$metadata = wp_generate_attachment_metadata( $attach_id, $file_path );
		wp_update_attachment_metadata( $attach_id, $metadata );

		return new WP_REST_Response(
			array(
				'id'        => (int) $attach_id,
				'url'       => $file_url,
				'mime_type' => $mime,
			),
			201
		);
	}
}
