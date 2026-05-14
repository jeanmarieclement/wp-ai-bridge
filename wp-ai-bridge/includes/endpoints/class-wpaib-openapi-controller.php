<?php
/**
 * Controller per esporre la Spec OpenAPI 3.0.3 nativa per ChatGPT, Gemini e Claude.ai.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe per l'endpoint /openapi.json.
 */
class WPAIB_OpenAPI_Controller {

	/**
	 * Registra la route REST /openapi.json.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/openapi\.json',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_openapi_schema' ),
					'permission_callback' => '__return_true', // Accessibile senza chiave per permettere l'importazione via URL sui builder AI
				),
			)
		);
	}

	/**
	 * Genera lo schema OpenAPI 3.0.3 per tutti i tool disponibili nel bridge.
	 *
	 * @return WP_REST_Response
	 */
	public function get_openapi_schema() {
		$base_url = untrailingslashit( rest_url( WPAIB_API_NAMESPACE ) );

		$schema = array(
			'openapi' => '3.0.3',
			'info'    => array(
				'title'       => 'WP AI Bridge Connector API',
				'description' => 'API REST standard per l\'integrazione diretta e agentica del sito WordPress all\'interno di Custom Actions di ChatGPT, Estensioni di Google Gemini e Custom Tools di Claude.ai.',
				'version'     => WPAIB_VERSION,
			),
			'servers' => array(
				array(
					'url'         => $base_url,
					'description' => 'Endpoint REST di WP AI Bridge',
				),
			),
			'components' => array(
				'securitySchemes' => array(
					'ApiKeyAuth' => array(
						'type' => 'apiKey',
						'in'   => 'header',
						'name' => 'X-API-Key',
					),
				),
			),
			'security' => array(
				array(
					'ApiKeyAuth' => array(),
				),
			),
			'paths' => array(
				'/posts' => array(
					'get' => array(
						'summary'     => 'Elenca gli articoli del blog',
						'description' => 'Recupera una lista paginata di articoli filtrabili per stato di pubblicazione.',
						'operationId' => 'listPosts',
						'parameters'  => array(
							array(
								'name'        => 'status',
								'in'          => 'query',
								'required'    => false,
								'description' => 'Stato di pubblicazione da filtrare',
								'schema'      => array(
									'type'    => 'string',
									'enum'    => array( 'any', 'publish', 'draft', 'pending', 'private' ),
									'default' => 'any',
								),
							),
							array(
								'name'        => 'per_page',
								'in'          => 'query',
								'required'    => false,
								'description' => 'Numero di articoli per pagina',
								'schema'      => array(
									'type'    => 'integer',
									'default' => 10,
								),
							),
							array(
								'name'        => 'page',
								'in'          => 'query',
								'required'    => false,
								'description' => 'Numero di pagina per l\'impaginazione',
								'schema'      => array(
									'type'    => 'integer',
									'default' => 1,
								),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Lista di articoli restituita con successo.',
								'content'     => array(
									'application/json' => array(
										'schema' => array(
											'type' => 'object',
										),
									),
								),
							),
						),
					),
					'post' => array(
						'summary'     => 'Crea un nuovo articolo',
						'description' => 'Crea e salva un articolo (post) su WordPress, associando opzionalmente categorie, tag e immagine in evidenza.',
						'operationId' => 'createPost',
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'required'   => array( 'title' ),
										'properties' => array(
											'title'          => array(
												'type'        => 'string',
												'description' => 'Titolo del post',
											),
											'content'        => array(
												'type'        => 'string',
												'description' => 'Contenuto HTML dell\'articolo',
											),
											'excerpt'        => array(
												'type'        => 'string',
												'description' => 'Riassunto o sottotitolo',
											),
											'status'         => array(
												'type'        => 'string',
												'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
												'default'     => 'draft',
												'description' => 'Stato di pubblicazione',
											),
											'categories'     => array(
												'type'        => 'array',
												'items'       => array( 'type' => 'integer' ),
												'description' => 'Array di ID delle categorie',
											),
											'tags'           => array(
												'type'        => 'array',
												'items'       => array( 'type' => 'string' ),
												'description' => 'Array di stringhe rappresentanti i tag',
											),
											'featured_media' => array(
												'type'        => 'integer',
												'description' => 'ID del file media in evidenza',
											),
										),
									),
								),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Articolo creato con successo.',
								'content'     => array(
									'application/json' => array(
										'schema' => array( 'type' => 'object' ),
									),
								),
							),
						),
					),
				),
				'/posts/{id}' => array(
					'get' => array(
						'summary'     => 'Recupera un singolo articolo',
						'description' => 'Ottieni i dettagli completi di un articolo tramite il suo ID univoco.',
						'operationId' => 'getPost',
						'parameters'  => array(
							array(
								'name'        => 'id',
								'in'          => 'path',
								'required'    => true,
								'description' => 'ID dell\'articolo',
								'schema'      => array( 'type' => 'integer' ),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Articolo recuperato con successo.',
								'content'     => array(
									'application/json' => array(
										'schema' => array( 'type' => 'object' ),
									),
								),
							),
						),
					),
					'post' => array(
						'summary'     => 'Aggiorna un articolo esistente',
						'description' => 'Modifica il titolo, contenuto o stato di un articolo su WordPress.',
						'operationId' => 'updatePost',
						'parameters'  => array(
							array(
								'name'        => 'id',
								'in'          => 'path',
								'required'    => true,
								'description' => 'ID dell\'articolo da aggiornare',
								'schema'      => array( 'type' => 'integer' ),
							),
						),
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'properties' => array(
											'title'      => array( 'type' => 'string' ),
											'content'    => array( 'type' => 'string' ),
											'status'     => array(
												'type' => 'string',
												'enum' => array( 'draft', 'publish', 'pending', 'private' ),
											),
											'categories' => array(
												'type'  => 'array',
												'items' => array( 'type' => 'integer' ),
											),
											'tags'       => array(
												'type'  => 'array',
												'items' => array( 'type' => 'string' ),
											),
										),
									),
								),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Articolo aggiornato.',
								'content'     => array(
									'application/json' => array(
										'schema' => array( 'type' => 'object' ),
									),
								),
							),
						),
					),
					'delete' => array(
						'summary'     => 'Elimina un articolo',
						'description' => 'Sposta nel cestino o elimina definitivamente l\'articolo.',
						'operationId' => 'deletePost',
						'parameters'  => array(
							array(
								'name'        => 'id',
								'in'          => 'path',
								'required'    => true,
								'description' => 'ID dell\'articolo',
								'schema'      => array( 'type' => 'integer' ),
							),
							array(
								'name'        => 'force',
								'in'          => 'query',
								'required'    => false,
								'description' => 'Se true elimina definitivamente saltando il cestino',
								'schema'      => array( 'type' => 'boolean' ),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Articolo rimosso.',
								'content'     => array(
									'application/json' => array(
										'schema' => array( 'type' => 'object' ),
									),
								),
							),
						),
					),
				),
				'/media' => array(
					'post' => array(
						'summary'     => 'Carica un file multimediale',
						'description' => 'Carica un\'immagine base64 nella Media Library di WordPress per l\'inclusione o per essere usata come immagine in evidenza.',
						'operationId' => 'uploadMedia',
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'required'   => array( 'filename', 'image_base64' ),
										'properties' => array(
											'filename'     => array(
												'type'        => 'string',
												'description' => 'Nome del file comprensivo di estensione (es. cover.png)',
											),
											'image_base64' => array(
												'type'        => 'string',
												'description' => 'Il contenuto del file codificato in stringa Base64',
											),
										),
									),
								),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Media caricato con successo.',
								'content'     => array(
									'application/json' => array(
										'schema' => array( 'type' => 'object' ),
									),
								),
							),
						),
					),
				),
				'/categories' => array(
					'get' => array(
						'summary'     => 'Elenca le categorie',
						'description' => 'Restituisce la lista di tutte le categorie articoli configurate sul sito.',
						'operationId' => 'listCategories',
						'responses'   => array(
							'200' => array(
								'description' => 'Categorie recuperate.',
								'content'     => array(
									'application/json' => array(
										'schema' => array( 'type' => 'object' ),
									),
								),
							),
						),
					),
					'post' => array(
						'summary'     => 'Crea una categoria',
						'description' => 'Aggiunge una nuova categoria per la classificazione dei post.',
						'operationId' => 'createCategory',
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'required'   => array( 'name' ),
										'properties' => array(
											'name'        => array(
												'type'        => 'string',
												'description' => 'Nome della categoria',
											),
											'description' => array(
												'type'        => 'string',
												'description' => 'Descrizione facoltativa',
											),
											'parent'      => array(
												'type'        => 'integer',
												'description' => 'ID della categoria padre',
											),
										),
									),
								),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Categoria creata.',
								'content'     => array(
									'application/json' => array(
										'schema' => array( 'type' => 'object' ),
									),
								),
							),
						),
					),
				),
				'/tags' => array(
					'get' => array(
						'summary'     => 'Elenca i tag',
						'description' => 'Restituisce l\'elenco di tutti i tag presenti.',
						'operationId' => 'listTags',
						'responses'   => array(
							'200' => array(
								'description' => 'Tag recuperati.',
								'content'     => array(
									'application/json' => array(
										'schema' => array( 'type' => 'object' ),
									),
								),
							),
						),
					),
					'post' => array(
						'summary'     => 'Crea un tag',
						'description' => 'Aggiunge un nuovo tag descrittivo per gli articoli.',
						'operationId' => 'createTag',
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'required'   => array( 'name' ),
										'properties' => array(
											'name' => array(
												'type'        => 'string',
												'description' => 'Nome del tag',
											),
										),
									),
								),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Tag creato.',
								'content'     => array(
									'application/json' => array(
										'schema' => array( 'type' => 'object' ),
									),
								),
							),
						),
					),
				),
			),
		);

		return rest_ensure_response( $schema );
	}
}
