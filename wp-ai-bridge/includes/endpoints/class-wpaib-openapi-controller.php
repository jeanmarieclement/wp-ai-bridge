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
					'OAuth2' => array(
						'type'  => 'oauth2',
						'flows' => array(
							'authorizationCode' => array(
								'authorizationUrl' => home_url( '/wpaib/oauth/authorize' ),
								'tokenUrl'         => rest_url( WPAIB_API_NAMESPACE . '/oauth/token' ),
								'scopes'           => array(
									'edit_posts' => 'Crea e modifica post, pagine, media, categorie e tag',
								),
							),
						),
					),
				),
			),
			'security' => array(
				array( 'ApiKeyAuth' => array() ),
				array( 'OAuth2'     => array( 'edit_posts' ) ),
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
				'/updates' => array(
					'get' => array(
						'summary'     => 'Panoramica aggiornamenti disponibili',
						'description' => 'Restituisce tutti gli aggiornamenti disponibili per core WordPress, plugin e temi installati.',
						'operationId' => 'getAllUpdates',
						'parameters'  => array(
							array(
								'name'        => 'force_check',
								'in'          => 'query',
								'required'    => false,
								'description' => 'Se true forza un nuovo controllo presso i server di aggiornamento.',
								'schema'      => array( 'type' => 'boolean', 'default' => false ),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Panoramica aggiornamenti restituita.',
								'content'     => array(
									'application/json' => array(
										'schema' => array(
											'type'       => 'object',
											'properties' => array(
												'core'    => array( 'type' => 'object', 'nullable' => true ),
												'plugins' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
												'themes'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
												'total'   => array( 'type' => 'integer' ),
											),
										),
									),
								),
							),
						),
					),
				),
				'/updates/core' => array(
					'get' => array(
						'summary'     => 'Stato aggiornamento WordPress core',
						'description' => 'Verifica se è disponibile un aggiornamento del core WordPress.',
						'operationId' => 'getCoreUpdates',
						'parameters'  => array(
							array(
								'name'     => 'force_check',
								'in'       => 'query',
								'required' => false,
								'schema'   => array( 'type' => 'boolean', 'default' => false ),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Stato aggiornamento core.',
								'content'     => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ),
							),
						),
					),
				),
				'/updates/plugins' => array(
					'get' => array(
						'summary'     => 'Lista aggiornamenti plugin',
						'description' => 'Elenca tutti i plugin installati per cui è disponibile un aggiornamento, con versione corrente, nuova versione e changelog URL.',
						'operationId' => 'getPluginUpdates',
						'parameters'  => array(
							array(
								'name'     => 'force_check',
								'in'       => 'query',
								'required' => false,
								'schema'   => array( 'type' => 'boolean', 'default' => false ),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Lista aggiornamenti plugin.',
								'content'     => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ),
							),
						),
					),
				),
				'/updates/themes' => array(
					'get' => array(
						'summary'     => 'Lista aggiornamenti temi',
						'description' => 'Elenca tutti i temi installati per cui è disponibile un aggiornamento.',
						'operationId' => 'getThemeUpdates',
						'parameters'  => array(
							array(
								'name'     => 'force_check',
								'in'       => 'query',
								'required' => false,
								'schema'   => array( 'type' => 'boolean', 'default' => false ),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Lista aggiornamenti temi.',
								'content'     => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ),
							),
						),
					),
				),
				'/updates/changelog/{type}/{slug}' => array(
					'get' => array(
						'summary'     => 'Changelog di plugin, tema o core',
						'description' => 'Recupera il changelog dell\'aggiornamento disponibile per un plugin, tema o il core WordPress da wordpress.org.',
						'operationId' => 'getChangelog',
						'parameters'  => array(
							array(
								'name'     => 'type',
								'in'       => 'path',
								'required' => true,
								'schema'   => array( 'type' => 'string', 'enum' => array( 'plugin', 'theme', 'core' ) ),
							),
							array(
								'name'        => 'slug',
								'in'          => 'path',
								'required'    => true,
								'description' => 'Slug del plugin o tema (es. akismet). Per il core usa "wordpress".',
								'schema'      => array( 'type' => 'string' ),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Changelog restituito.',
								'content'     => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ),
							),
							'404' => array( 'description' => 'Componente non trovato su wordpress.org.' ),
						),
					),
				),
				'/updates/apply' => array(
					'post' => array(
						'summary'     => 'Applica un singolo aggiornamento',
						'description' => 'Aggiorna un plugin, tema o il core WordPress alla versione più recente disponibile.',
						'operationId' => 'applyUpdate',
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'required'   => array( 'type' ),
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
									),
								),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Risultato aggiornamento.',
								'content'     => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ),
							),
						),
					),
				),
				'/updates/bulk' => array(
					'post' => array(
						'summary'     => 'Aggiornamento multiplo in un\'unica chiamata',
						'description' => 'Aggiorna più plugin, temi e/o il core WordPress in un\'unica richiesta. Restituisce il risultato per ciascun elemento.',
						'operationId' => 'bulkUpdate',
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'required'   => array( 'items' ),
										'properties' => array(
											'items' => array(
												'type'        => 'array',
												'description' => 'Lista di aggiornamenti da applicare.',
												'items'       => array(
													'type'       => 'object',
													'required'   => array( 'type' ),
													'properties' => array(
														'type' => array(
															'type' => 'string',
															'enum' => array( 'plugin', 'theme', 'core' ),
														),
														'slug' => array(
															'type'        => 'string',
															'description' => 'Slug del plugin o tema.',
														),
													),
												),
											),
										),
									),
								),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Risultati aggiornamento multiplo.',
								'content'     => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ),
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
				'/plugins' => array(
					'get'    => array(
						'summary'     => 'Elenca i plugin installati',
						'description' => 'Restituisce tutti i plugin WordPress installati con nome, versione, descrizione e stato. Richiede activate_plugins (amministratore).',
						'operationId' => 'listPlugins',
						'responses'   => array(
							'200' => array(
								'description' => 'Lista plugin restituita.',
								'content'     => array(
									'application/json' => array(
										'schema' => array(
											'type'  => 'array',
											'items' => array(
												'type'       => 'object',
												'properties' => array(
													'plugin'      => array( 'type' => 'string' ),
													'name'        => array( 'type' => 'string' ),
													'version'     => array( 'type' => 'string' ),
													'description' => array( 'type' => 'string' ),
													'author'      => array( 'type' => 'string' ),
													'status'      => array( 'type' => 'string', 'enum' => array( 'active', 'inactive' ) ),
												),
											),
										),
									),
								),
							),
							'403' => array( 'description' => 'Permessi insufficienti.' ),
						),
					),
					'delete' => array(
						'summary'     => 'Elimina un plugin',
						'description' => 'Elimina definitivamente un plugin dal filesystem dopo averlo disattivato. Non può eliminare WP AI Bridge. Richiede delete_plugins.',
						'operationId' => 'deletePlugin',
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'required'   => array( 'plugin' ),
										'properties' => array(
											'plugin' => array(
												'type'        => 'string',
												'description' => 'Percorso del plugin (es. akismet/akismet.php)',
											),
										),
									),
								),
							),
						),
						'responses'   => array(
							'200' => array( 'description' => 'Plugin eliminato.' ),
							'400' => array( 'description' => 'Parametro non valido o tentativo di eliminare WP AI Bridge.' ),
							'403' => array( 'description' => 'Permessi insufficienti.' ),
							'404' => array( 'description' => 'Plugin non trovato.' ),
						),
					),
				),
				'/plugins/activate' => array(
					'post' => array(
						'summary'     => 'Attiva un plugin',
						'description' => 'Attiva un plugin WordPress installato. Richiede activate_plugins.',
						'operationId' => 'activatePlugin',
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'required'   => array( 'plugin' ),
										'properties' => array(
											'plugin' => array(
												'type'        => 'string',
												'description' => 'Percorso del plugin (es. akismet/akismet.php)',
											),
										),
									),
								),
							),
						),
						'responses'   => array(
							'200' => array( 'description' => 'Plugin attivato (o già attivo).' ),
							'400' => array( 'description' => 'Parametro non valido.' ),
							'403' => array( 'description' => 'Permessi insufficienti.' ),
							'404' => array( 'description' => 'Plugin non trovato.' ),
						),
					),
				),
				'/plugins/deactivate' => array(
					'post' => array(
						'summary'     => 'Disattiva un plugin',
						'description' => 'Disattiva un plugin WordPress attivo. Non può disattivare WP AI Bridge. Richiede activate_plugins.',
						'operationId' => 'deactivatePlugin',
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'required'   => array( 'plugin' ),
										'properties' => array(
											'plugin' => array(
												'type'        => 'string',
												'description' => 'Percorso del plugin (es. akismet/akismet.php)',
											),
										),
									),
								),
							),
						),
						'responses'   => array(
							'200' => array( 'description' => 'Plugin disattivato (o già inattivo).' ),
							'400' => array( 'description' => 'Parametro non valido o tentativo di disattivare WP AI Bridge.' ),
							'403' => array( 'description' => 'Permessi insufficienti.' ),
							'404' => array( 'description' => 'Plugin non trovato.' ),
						),
					),
				),
			),
		);

		return rest_ensure_response( $schema );
	}
}
