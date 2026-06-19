<?php
/**
 * Controller per integrazione nativa con AI (Model Context Protocol / Function Calling).
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint /tools e /tools/execute.
 */
class WPAIB_MCP_Controller {

	/**
	 * Registra le route.
	 *
	 * @return void
	 */
	public function register_routes() {
		$tools_args = array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tools' ),
				'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
			),
		);

		$execute_args = array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'execute_tool' ),
				'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
			),
		);

		register_rest_route( WPAIB_API_NAMESPACE, '/tools', $tools_args );
		register_rest_route( WPAIB_API_NAMESPACE, '/mcp', $tools_args );

		register_rest_route( WPAIB_API_NAMESPACE, '/tools/execute', $execute_args );
		register_rest_route( WPAIB_API_NAMESPACE, '/mcp/execute', $execute_args );
	}

	/**
	 * Restituisce la definizione di tutti i tool disponibili per l'AI.
	 *
	 * @return WP_REST_Response
	 */
	public function get_tools() {
		$tools = array(
			array(
				'name'         => 'get_posts',
				'description'  => 'Recupera una lista di articoli dal blog WordPress filtrabili per stato e impaginazione.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array(
							'type'        => 'string',
							'enum'        => array( 'any', 'publish', 'draft', 'pending', 'private' ),
							'description' => 'Stato degli articoli da cercare (default: any)',
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Numero di articoli per pagina (default: 10, max: 100)',
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => 'Numero di pagina per impaginazione (default: 1)',
						),
					),
				),
			),
			array(
				'name'         => 'get_post',
				'description'  => 'Recupera i dettagli completi di un singolo articolo tramite il suo ID.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'ID univoco del post da recuperare',
						),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => 'create_post',
				'description'  => 'Crea un nuovo articolo sul blog WordPress.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'title'          => array(
							'type'        => 'string',
							'description' => 'Titolo del post',
						),
						'content'        => array(
							'type'        => 'string',
							'description' => 'Contenuto HTML del post',
						),
						'excerpt'        => array(
							'type'        => 'string',
							'description' => 'Breve riassunto o sottotitolo',
						),
						'status'         => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
							'description' => 'Stato di pubblicazione (default: draft)',
						),
						'categories'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Array di ID delle categorie da associare',
						),
						'tags'           => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Array di stringhe con i nomi dei tag da associare',
						),
						'featured_media' => array(
							'type'        => 'integer',
							'description' => 'ID dell\'immagine in evidenza (recuperato tramite upload_media)',
						),
					),
					'required'   => array( 'title' ),
				),
			),
			array(
				'name'         => 'update_post',
				'description'  => 'Aggiorna un articolo esistente su WordPress.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'id'             => array(
							'type'        => 'integer',
							'description' => 'ID dell\'articolo da modificare',
						),
						'title'          => array(
							'type'        => 'string',
							'description' => 'Nuovo titolo',
						),
						'content'        => array(
							'type'        => 'string',
							'description' => 'Nuovo contenuto HTML',
						),
						'status'         => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
							'description' => 'Nuovo stato',
						),
						'categories'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
						),
						'tags'           => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
						),
						'featured_media' => array(
							'type'        => 'integer',
							'description' => 'ID dell\'immagine in evidenza (recuperato tramite upload_media)',
						),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => 'delete_post',
				'description'  => 'Sposta nel cestino o elimina definitivamente un articolo.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array(
							'type'        => 'integer',
							'description' => 'ID dell\'articolo da eliminare',
						),
						'force' => array(
							'type'        => 'boolean',
							'description' => 'Se true elimina definitivamente saltando il cestino',
						),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => 'upload_media',
				'description'  => 'Carica un\'immagine o un file multimediale codificato in base64 nella libreria media di WordPress.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'filename'     => array(
							'type'        => 'string',
							'description' => 'Nome del file comprensivo di estensione (es. cover.png)',
						),
						'image_base64' => array(
							'type'        => 'string',
							'description' => 'Stringa del file codificata in base64',
						),
					),
					'required'   => array( 'filename', 'image_base64' ),
				),
			),
			array(
				'name'         => 'get_categories',
				'description'  => 'Restituisce l\'elenco di tutte le categorie disponibili sul sito.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'empty' => array( 'type' => 'boolean', 'description' => 'Segnaposto opzionale' ),
					),
				),
			),
			array(
				'name'         => 'create_category',
				'description'  => 'Crea una nuova categoria per gli articoli.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'name'        => array( 'type' => 'string', 'description' => 'Nome della categoria' ),
						'description' => array( 'type' => 'string', 'description' => 'Descrizione opzionale' ),
						'parent'      => array( 'type' => 'integer', 'description' => 'ID della categoria padre opzionale' ),
					),
					'required'   => array( 'name' ),
				),
			),
			array(
				'name'         => 'get_tags',
				'description'  => 'Restituisce l\'elenco di tutti i tag disponibili sul sito.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'empty' => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'         => 'get_comments',
				'description'  => 'Recupera i commenti da WordPress (approvati o in moderazione).',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array(
							'type'        => 'integer',
							'description' => 'ID dell\'articolo (opzionale, se omesso recupera tutti i commenti)',
						),
						'status' => array(
							'type'        => 'string',
							'enum'        => array( 'approve', 'hold', 'spam', 'trash' ),
							'description' => 'Stato dei commenti (default: approve)',
						),
					),
				),
			),
			array(
				'name'         => 'add_comment',
				'description'  => 'Aggiunge un nuovo commento a un articolo specifico.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => 'ID dell\'articolo a cui aggiungere il commento',
						),
						'content' => array(
							'type'        => 'string',
							'description' => 'Testo del commento',
						),
					),
					'required'   => array( 'id', 'content' ),
				),
			),
			array(
				'name'         => 'moderate_comment',
				'description'  => 'Cambia lo stato di un commento (es. approva, hold, spam, trash).',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array(
							'type'        => 'integer',
							'description' => 'ID del commento da moderare',
						),
						'status' => array(
							'type'        => 'string',
							'enum'        => array( 'approve', 'hold', 'spam', 'trash' ),
							'description' => 'Nuovo stato del commento',
						),
					),
					'required'   => array( 'id', 'status' ),
				),
			),
			array(
				'name'         => 'bulk_moderate_comments',
				'description'  => 'Modera più commenti contemporaneamente.',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'ids'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Array di ID dei commenti da moderare',
						),
						'status' => array(
							'type'        => 'string',
							'enum'        => array( 'approve', 'hold', 'spam', 'trash' ),
							'description' => 'Nuovo stato dei commenti',
						),
					),
					'required'   => array( 'ids', 'status' ),
				),
			),
			// Pages.
			array(
				'name'        => 'get_pages',
				'description' => 'Recupera una lista di pagine WordPress filtrabili per stato e impaginazione.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array(
							'type'        => 'string',
							'enum'        => array( 'any', 'publish', 'draft', 'pending', 'private' ),
							'description' => 'Stato delle pagine (default: any)',
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Numero per pagina (default: 10, max: 100)',
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => 'Pagina (default: 1)',
						),
					),
				),
			),
			array(
				'name'        => 'get_page',
				'description' => 'Recupera i dettagli di una singola pagina tramite ID.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'ID della pagina',
						),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'        => 'create_page',
				'description' => 'Crea una nuova pagina WordPress.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'     => array(
							'type'        => 'string',
							'description' => 'Titolo della pagina',
						),
						'content'   => array(
							'type'        => 'string',
							'description' => 'Contenuto HTML della pagina',
						),
						'status'    => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
							'description' => 'Stato (default: draft)',
						),
						'parent_id' => array(
							'type'        => 'integer',
							'description' => 'ID della pagina genitore (opzionale)',
						),
					),
					'required'   => array( 'title' ),
				),
			),
			array(
				'name'        => 'update_page',
				'description' => 'Aggiorna una pagina esistente.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array(
							'type'        => 'integer',
							'description' => 'ID della pagina da aggiornare',
						),
						'title'     => array(
							'type'        => 'string',
							'description' => 'Nuovo titolo',
						),
						'content'   => array(
							'type'        => 'string',
							'description' => 'Nuovo contenuto HTML',
						),
						'status'    => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
							'description' => 'Nuovo stato',
						),
						'parent_id' => array(
							'type'        => 'integer',
							'description' => 'Nuovo ID genitore',
						),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'        => 'delete_page',
				'description' => 'Elimina o cestina una pagina.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array(
							'type'        => 'integer',
							'description' => 'ID della pagina',
						),
						'force' => array(
							'type'        => 'boolean',
							'description' => 'Se true elimina definitivamente (default: false)',
						),
					),
					'required'   => array( 'id' ),
				),
			),
			// Tag.
			array(
				'name'        => 'create_tag',
				'description' => 'Crea un nuovo tag WordPress.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name'        => array(
							'type'        => 'string',
							'description' => 'Nome del tag',
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => 'Slug (opzionale)',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Descrizione (opzionale)',
						),
					),
					'required'   => array( 'name' ),
				),
			),
			// Media.
			array(
				'name'        => 'get_media',
				'description' => 'Recupera la lista dei file nella libreria media WordPress.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'per_page'  => array(
							'type'        => 'integer',
							'description' => 'Numero per pagina (default: 10, max: 100)',
						),
						'page'      => array(
							'type'        => 'integer',
							'description' => 'Pagina (default: 1)',
						),
						'mime_type' => array(
							'type'        => 'string',
							'description' => 'Filtra per MIME type (es. image/jpeg)',
						),
					),
				),
			),
			array(
				'name'        => 'delete_media',
				'description' => 'Elimina o cestina un file dalla libreria media.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array(
							'type'        => 'integer',
							'description' => 'ID del file media',
						),
						'force' => array(
							'type'        => 'boolean',
							'description' => 'Se true elimina definitivamente (default: false)',
						),
					),
					'required'   => array( 'id' ),
				),
			),
			// Posts bulk.
			array(
				'name'        => 'bulk_update_posts',
				'description' => 'Cambia lo stato di più articoli contemporaneamente.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'ids'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Array di ID articoli',
						),
						'status' => array(
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
							'description' => 'Nuovo stato da applicare a tutti',
						),
					),
					'required'   => array( 'ids', 'status' ),
				),
			),
			// Site.
			array(
				'name'        => 'get_site_info',
				'description' => 'Recupera informazioni complete sul sito WordPress (nome, URL, tema, versione, statistiche).',
				'inputSchema' => array(
					'type' => 'object',
				),
			),
			// Search.
			array(
				'name'        => 'search',
				'description' => 'Cerca o lista contenuti WordPress (post, pagine, media, commenti, termini). Senza query lista tutti i contenuti del tipo richiesto.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'    => array(
							'type'        => 'string',
							'description' => 'Testo da cercare (opzionale — ometti per listare tutti)',
						),
						'types'    => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'posts', 'pages', 'media', 'comments', 'terms' ),
							),
							'description' => 'Tipi di contenuto (default: [posts, pages])',
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Massimo risultati per tipo (default: 10, max: 50)',
						),
					),
					'required'   => array(),
				),
			),
		);

		// Plugin management — esposto solo agli amministratori con activate_plugins.
		if ( current_user_can( 'activate_plugins' ) ) {
			$tools[] = array(
				'name'        => 'get_plugins',
				'description' => 'Elenca tutti i plugin WordPress installati con nome, versione, stato (active/inactive) e descrizione.',
				'inputSchema' => array(
					'type' => 'object',
				),
			);
			$tools[] = array(
				'name'        => 'activate_plugin',
				'description' => 'Attiva un plugin WordPress tramite il suo percorso (es. akismet/akismet.php).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => 'Percorso del plugin relativo alla cartella plugins (es. akismet/akismet.php)',
						),
					),
					'required'   => array( 'plugin' ),
				),
			);
			$tools[] = array(
				'name'        => 'deactivate_plugin',
				'description' => 'Disattiva un plugin WordPress. Non può essere usato per disattivare WP AI Bridge.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => 'Percorso del plugin relativo alla cartella plugins (es. akismet/akismet.php)',
						),
					),
					'required'   => array( 'plugin' ),
				),
			);

			if ( current_user_can( 'delete_plugins' ) ) {
				$tools[] = array(
					'name'        => 'delete_plugin',
					'description' => 'Elimina definitivamente un plugin dal filesystem. Il plugin viene prima disattivato. Non può essere usato per eliminare WP AI Bridge.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'plugin' => array(
								'type'        => 'string',
								'description' => 'Percorso del plugin relativo alla cartella plugins (es. akismet/akismet.php)',
							),
						),
						'required'   => array( 'plugin' ),
					),
				);
			}
		}

		// Update management — esposto agli amministratori con capability update_*.
		if ( current_user_can( 'update_core' ) || current_user_can( 'update_plugins' ) || current_user_can( 'update_themes' ) ) {
			$tools[] = array(
				'name'        => 'get_updates',
				'description' => 'Restituisce la panoramica di tutti gli aggiornamenti disponibili per core WordPress, plugin e temi, con conteggio totale.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'force_check' => array(
							'type'        => 'boolean',
							'description' => 'Se true forza un nuovo controllo presso i server di aggiornamento.',
						),
					),
				),
			);
			$tools[] = array(
				'name'        => 'get_changelog',
				'description' => 'Recupera il changelog dell\'aggiornamento disponibile per un plugin, tema o il core WordPress da wordpress.org.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'type' => array(
							'type'        => 'string',
							'enum'        => array( 'plugin', 'theme', 'core' ),
							'description' => 'Tipo di componente.',
						),
						'slug' => array(
							'type'        => 'string',
							'description' => 'Slug del plugin o tema (es. akismet). Per il core usa "wordpress".',
						),
					),
					'required'   => array( 'type', 'slug' ),
				),
			);

			if ( current_user_can( 'update_core' ) ) {
				$tools[] = array(
					'name'        => 'apply_update',
					'description' => 'Aggiorna un singolo plugin, tema o il core WordPress alla versione più recente disponibile.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'type' => array(
								'type'        => 'string',
								'enum'        => array( 'plugin', 'theme', 'core' ),
								'description' => 'Tipo di componente da aggiornare.',
							),
							'slug' => array(
								'type'        => 'string',
								'description' => 'Slug del plugin o tema. Non richiesto per type=core.',
							),
						),
						'required'   => array( 'type' ),
					),
				);
				$tools[] = array(
					'name'        => 'bulk_update',
					'description' => 'Aggiorna più plugin, temi e/o il core WordPress in un\'unica richiesta. Restituisce il risultato per ciascun elemento.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'items' => array(
								'type'        => 'array',
								'description' => 'Lista di aggiornamenti da applicare.',
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'type' => array(
											'type' => 'string',
											'enum' => array( 'plugin', 'theme', 'core' ),
										),
										'slug' => array(
											'type'        => 'string',
											'description' => 'Slug del plugin o tema. Non richiesto per type=core.',
										),
									),
									'required'   => array( 'type' ),
								),
							),
						),
						'required'   => array( 'items' ),
					),
				);
			}
		}

		$disabled = get_option( 'wpaib_disabled_tools', array() );
		if ( ! empty( $disabled ) ) {
			$tools = array_values( array_filter( $tools, function ( $t ) use ( $disabled ) {
				return ! in_array( $t['name'], $disabled, true );
			} ) );
		}

		return new WP_REST_Response( array( 'tools' => $tools ), 200 );
	}

	/**
	 * Esegue un singolo tool richiesto dall'AI.
	 *
	 * @param WP_REST_Request $request Richiesta contenente 'tool' e 'arguments'.
	 * @return WP_REST_Response|WP_Error
	 */
	public function execute_tool( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( empty( $params['tool'] ) || ! is_string( $params['tool'] ) ) {
			return new WP_Error( 'wpaib_missing_tool', __( 'Tool name is required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$tool = sanitize_key( $params['tool'] );
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$disabled = get_option( 'wpaib_disabled_tools', array() );
		if ( in_array( $tool, $disabled, true ) ) {
			return new WP_Error( 'wpaib_tool_disabled', __( 'Tool not available.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		switch ( $tool ) {
			case 'get_posts':
				$controller = new WPAIB_Posts_Controller();
				$sub_req    = new WP_REST_Request( 'GET', '/wpaib/v1/posts' );
				foreach ( $args as $k => $v ) {
					$sub_req->set_param( $k, $v );
				}
				return $controller->list_posts( $sub_req );

			case 'get_post':
				if ( empty( $args['id'] ) ) {
					return new WP_Error( 'wpaib_missing_id', __( 'Missing post ID.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				$controller = new WPAIB_Posts_Controller();
				$sub_req    = new WP_REST_Request( 'GET', '/wpaib/v1/posts/' . (int) $args['id'] );
				$sub_req->set_param( 'id', (int) $args['id'] );
				return $controller->get_post( $sub_req );

			case 'create_post':
				$controller = new WPAIB_Posts_Controller();
				$sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/posts' );
				$sub_req->set_header( 'Content-Type', 'application/json' );
				$sub_req->set_body( wp_json_encode( $args ) );
				return $controller->create_post( $sub_req );

			case 'update_post':
				if ( empty( $args['id'] ) ) {
					return new WP_Error( 'wpaib_missing_id', __( 'Missing post ID.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				$controller = new WPAIB_Posts_Controller();
				$sub_req    = new WP_REST_Request( 'PUT', '/wpaib/v1/posts/' . (int) $args['id'] );
				$sub_req->set_param( 'id', (int) $args['id'] );
				$sub_req->set_header( 'Content-Type', 'application/json' );
				$sub_req->set_body( wp_json_encode( $args ) );
				return $controller->update_post( $sub_req );

			case 'delete_post':
				if ( empty( $args['id'] ) ) {
					return new WP_Error( 'wpaib_missing_id', __( 'Missing post ID.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				if ( ! current_user_can( 'delete_posts' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions to delete posts.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Posts_Controller();
				$sub_req    = new WP_REST_Request( 'DELETE', '/wpaib/v1/posts/' . (int) $args['id'] );
				$sub_req->set_param( 'id', (int) $args['id'] );
				if ( isset( $args['force'] ) ) {
					$sub_req->set_param( 'force', $args['force'] );
				}
				return $controller->delete_post( $sub_req );

			case 'upload_media':
				if ( ! current_user_can( 'upload_files' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions to upload files.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Media_Controller();
				$sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/media' );
				$sub_req->set_header( 'Content-Type', 'application/json' );
				$sub_req->set_body( wp_json_encode( $args ) );
				return $controller->upload( $sub_req );

			case 'get_categories':
				$controller = new WPAIB_Taxonomy_Controller();
				return $controller->list_categories();

			case 'create_category':
				if ( ! current_user_can( 'manage_categories' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions to manage categories.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Taxonomy_Controller();
				$sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/categories' );
				$sub_req->set_header( 'Content-Type', 'application/json' );
				$sub_req->set_body( wp_json_encode( $args ) );
				return $controller->create_category( $sub_req );

			case 'get_tags':
				$controller = new WPAIB_Taxonomy_Controller();
				return $controller->list_tags();

			case 'get_comments':
				$controller = new WPAIB_Posts_Controller();
				$post_id    = ! empty( $args['id'] ) ? (int) $args['id'] : 0;
				$status     = ! empty( $args['status'] ) ? sanitize_key( $args['status'] ) : 'approve';

				$path = $post_id > 0 ? '/wpaib/v1/posts/' . $post_id . '/comments' : '/wpaib/v1/comments';
				$sub_req = new WP_REST_Request( 'GET', $path );

				if ( $post_id > 0 ) {
					$sub_req->set_param( 'id', $post_id );
				}
				$sub_req->set_param( 'status', $status );

				return $controller->list_comments( $sub_req );

			case 'add_comment':
				if ( empty( $args['id'] ) || empty( $args['content'] ) ) {
					return new WP_Error( 'wpaib_missing_params', __( 'Post ID and content are required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				$controller = new WPAIB_Posts_Controller();
				$sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/posts/' . (int) $args['id'] . '/comments' );
				$sub_req->set_param( 'id', (int) $args['id'] );
				$sub_req->set_header( 'Content-Type', 'application/json' );
				$sub_req->set_body( wp_json_encode( array( 'content' => $args['content'] ) ) );
				return $controller->create_comment( $sub_req );

			case 'moderate_comment':
				if ( empty( $args['id'] ) || empty( $args['status'] ) ) {
					return new WP_Error( 'wpaib_missing_params', __( 'Comment ID and status are required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				if ( ! current_user_can( 'moderate_comments' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Posts_Controller();
				$sub_req    = new WP_REST_Request( 'PUT', '/wpaib/v1/comments/' . (int) $args['id'] );
				$sub_req->set_param( 'id', (int) $args['id'] );
				$sub_req->set_header( 'Content-Type', 'application/json' );
				$sub_req->set_body( wp_json_encode( array( 'status' => $args['status'] ) ) );
				return $controller->moderate_comment( $sub_req );

			case 'bulk_moderate_comments':
				if ( empty( $args['ids'] ) || ! is_array( $args['ids'] ) || empty( $args['status'] ) ) {
					return new WP_Error( 'wpaib_missing_params', __( 'Comment IDs and status are required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				if ( ! current_user_can( 'moderate_comments' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Posts_Controller();
				$sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/comments/bulk' );
				$sub_req->set_header( 'Content-Type', 'application/json' );
				$sub_req->set_body( wp_json_encode( array( 'ids' => $args['ids'], 'status' => $args['status'] ) ) );
				return $controller->bulk_moderate_comments( $sub_req );

			case 'get_pages':
				$controller = new WPAIB_Pages_Controller();
				$sub_req    = new WP_REST_Request( 'GET', '/wpaib/v1/pages' );
				foreach ( $args as $k => $v ) {
					$sub_req->set_param( $k, $v );
				}
				return $controller->list_pages( $sub_req );

			case 'get_page':
				if ( empty( $args['id'] ) ) {
					return new WP_Error( 'wpaib_missing_id', __( 'Missing page ID.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				$controller = new WPAIB_Pages_Controller();
				$sub_req    = new WP_REST_Request( 'GET', '/wpaib/v1/pages/' . (int) $args['id'] );
				$sub_req->set_param( 'id', (int) $args['id'] );
				return $controller->get_page( $sub_req );

			case 'create_page':
				if ( ! current_user_can( 'edit_pages' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Pages_Controller();
				$sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/pages' );
				$sub_req->set_header( 'Content-Type', 'application/json' );
				$sub_req->set_body( wp_json_encode( $args ) );
				return $controller->create_page( $sub_req );

			case 'update_page':
				if ( empty( $args['id'] ) ) {
					return new WP_Error( 'wpaib_missing_id', __( 'Missing page ID.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				if ( ! current_user_can( 'edit_pages' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Pages_Controller();
				$sub_req    = new WP_REST_Request( 'PUT', '/wpaib/v1/pages/' . (int) $args['id'] );
				$sub_req->set_param( 'id', (int) $args['id'] );
				$sub_req->set_header( 'Content-Type', 'application/json' );
				$sub_req->set_body( wp_json_encode( $args ) );
				return $controller->update_page( $sub_req );

			case 'delete_page':
				if ( empty( $args['id'] ) ) {
					return new WP_Error( 'wpaib_missing_id', __( 'Missing page ID.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				if ( ! current_user_can( 'delete_pages' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Pages_Controller();
				$sub_req    = new WP_REST_Request( 'DELETE', '/wpaib/v1/pages/' . (int) $args['id'] );
				$sub_req->set_param( 'id', (int) $args['id'] );
				if ( isset( $args['force'] ) ) {
					$sub_req->set_param( 'force', $args['force'] );
				}
				return $controller->delete_page( $sub_req );

			case 'create_tag':
				if ( ! current_user_can( 'manage_categories' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Taxonomy_Controller();
				$sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/tags' );
				$sub_req->set_header( 'Content-Type', 'application/json' );
				$sub_req->set_body( wp_json_encode( $args ) );
				return $controller->create_tag( $sub_req );

			case 'get_media':
				$controller = new WPAIB_Media_Controller();
				$sub_req    = new WP_REST_Request( 'GET', '/wpaib/v1/media' );
				foreach ( $args as $k => $v ) {
					$sub_req->set_param( $k, $v );
				}
				return $controller->list_media( $sub_req );

			case 'delete_media':
				if ( empty( $args['id'] ) ) {
					return new WP_Error( 'wpaib_missing_id', __( 'Missing media ID.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				if ( ! current_user_can( 'delete_posts' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Media_Controller();
				$sub_req    = new WP_REST_Request( 'DELETE', '/wpaib/v1/media/' . (int) $args['id'] );
				$sub_req->set_param( 'id', (int) $args['id'] );
				if ( isset( $args['force'] ) ) {
					$sub_req->set_param( 'force', $args['force'] );
				}
				return $controller->delete_media( $sub_req );

			case 'bulk_update_posts':
				if ( ! current_user_can( 'edit_posts' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Posts_Controller();
				$sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/posts/bulk' );
				$sub_req->set_header( 'Content-Type', 'application/json' );
				$sub_req->set_body( wp_json_encode( $args ) );
				return $controller->bulk_update_posts( $sub_req );

			case 'get_site_info':
				$controller = new WPAIB_Site_Controller();
				$sub_req    = new WP_REST_Request( 'GET', '/wpaib/v1/site' );
				return $controller->get_site_info( $sub_req );

			case 'search':
				$controller = new WPAIB_Search_Controller();
				$sub_req    = new WP_REST_Request( 'GET', '/wpaib/v1/search' );
				foreach ( $args as $k => $v ) {
					$sub_req->set_param( $k, $v );
				}
				return $controller->search( $sub_req );

			case 'get_plugins':
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions to manage plugins.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Plugins_Controller();
				$sub_req    = new WP_REST_Request( 'GET', '/wpaib/v1/plugins' );
				return $controller->list_plugins( $sub_req );

			case 'activate_plugin':
				if ( empty( $args['plugin'] ) ) {
					return new WP_Error( 'wpaib_missing_params', __( 'Plugin path is required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions to activate plugins.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Plugins_Controller();
				$sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/plugins/activate' );
				$sub_req->set_param( 'plugin', $args['plugin'] );
				return $controller->activate_plugin_handler( $sub_req );

			case 'deactivate_plugin':
				if ( empty( $args['plugin'] ) ) {
					return new WP_Error( 'wpaib_missing_params', __( 'Plugin path is required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions to deactivate plugins.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Plugins_Controller();
				$sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/plugins/deactivate' );
				$sub_req->set_param( 'plugin', $args['plugin'] );
				return $controller->deactivate_plugin_handler( $sub_req );

			case 'delete_plugin':
				if ( empty( $args['plugin'] ) ) {
					return new WP_Error( 'wpaib_missing_params', __( 'Plugin path is required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				if ( ! current_user_can( 'delete_plugins' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions to delete plugins.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Plugins_Controller();
				$sub_req    = new WP_REST_Request( 'DELETE', '/wpaib/v1/plugins' );
				$sub_req->set_param( 'plugin', $args['plugin'] );
				return $controller->delete_plugin( $sub_req );

			case 'get_updates':
				if ( ! current_user_can( 'update_core' ) && ! current_user_can( 'update_plugins' ) && ! current_user_can( 'update_themes' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions to manage updates.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Updates_Controller();
				$sub_req    = new WP_REST_Request( 'GET', '/wpaib/v1/updates' );
				if ( isset( $args['force_check'] ) ) {
					$sub_req->set_param( 'force_check', (bool) $args['force_check'] );
				}
				return $controller->get_all_updates( $sub_req );

			case 'get_changelog':
				if ( empty( $args['type'] ) || empty( $args['slug'] ) ) {
					return new WP_Error( 'wpaib_missing_params', __( 'Parameters "type" and "slug" are required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				if ( ! current_user_can( 'update_core' ) && ! current_user_can( 'update_plugins' ) && ! current_user_can( 'update_themes' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions to manage updates.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Updates_Controller();
				$sub_req    = new WP_REST_Request( 'GET', '/wpaib/v1/updates/changelog' );
				$sub_req->set_param( 'type', $args['type'] );
				$sub_req->set_param( 'slug', $args['slug'] );
				return $controller->get_changelog( $sub_req );

			case 'apply_update':
				if ( empty( $args['type'] ) ) {
					return new WP_Error( 'wpaib_missing_params', __( 'Parameter "type" is required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				if ( ! current_user_can( 'update_core' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions to apply updates.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Updates_Controller();
				$sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/updates/apply' );
				$sub_req->set_param( 'type', $args['type'] );
				if ( isset( $args['slug'] ) ) {
					$sub_req->set_param( 'slug', $args['slug'] );
				}
				return $controller->apply_update( $sub_req );

			case 'bulk_update':
				if ( empty( $args['items'] ) || ! is_array( $args['items'] ) ) {
					return new WP_Error( 'wpaib_missing_params', __( 'Parameter "items" must be a non-empty array.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
				}
				if ( ! current_user_can( 'update_core' ) ) {
					return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions to apply updates.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
				}
				$controller = new WPAIB_Updates_Controller();
				$sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/updates/bulk' );
				$sub_req->set_header( 'Content-Type', 'application/json' );
				$sub_req->set_body( wp_json_encode( array( 'items' => $args['items'] ) ) );
				return $controller->bulk_update( $sub_req );

			default:
				return new WP_Error( 'wpaib_unknown_tool', sprintf( __( 'Tool "%s" is not supported.', 'wp-ai-bridge' ), esc_html( $tool ) ), array( 'status' => 404 ) );
		}
	}
}
