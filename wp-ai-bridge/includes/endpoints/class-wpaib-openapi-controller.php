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
	 * Parametri di paginazione condivisi dagli endpoint di lettura.
	 *
	 * @param int $per_page_default Default di per_page per l'endpoint.
	 * @return array
	 */
	private function pagination_params( $per_page_default = 10 ) {
		return array(
			array(
				'name'        => 'per_page',
				'in'          => 'query',
				'required'    => false,
				'description' => 'Numero di record per pagina (max 100)',
				'schema'      => array(
					'type'    => 'integer',
					'default' => $per_page_default,
					'maximum' => 100,
				),
			),
			array(
				'name'        => 'page',
				'in'          => 'query',
				'required'    => false,
				'description' => 'Numero di pagina per l\'impaginazione classica',
				'schema'      => array(
					'type'    => 'integer',
					'default' => 1,
				),
			),
			array(
				'name'        => 'after_id',
				'in'          => 'query',
				'required'    => false,
				// Nessun default nello schema: è la presenza stessa del parametro
				// a scegliere la modalità. Un default lo farebbe materializzare ai
				// client generati da questo schema, che passerebbero senza volerlo
				// alla paginazione a cursore.
				'description' => 'Paginazione a cursore: restituisce solo i record con ID maggiore di questo, ordinati per ID crescente. Stabile durante un export lungo, a differenza della paginazione per numero di pagina. Omesso: paginazione classica per pagina, con total/page/total_pages. Presente (anche a 0, cursore iniziale di un export che parte da capo): la risposta contiene after_id, next_after_id, has_more e total_remaining.',
				'schema'      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
		);
	}

	/**
	 * Risposta 200 generica con corpo JSON.
	 *
	 * @param string $description Descrizione della risposta.
	 * @return array
	 */
	private function ok_response( $description ) {
		return array(
			'200' => array(
				'description' => $description,
				'content'     => array(
					'application/json' => array(
						'schema' => array( 'type' => 'object' ),
					),
				),
			),
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
								// Uno scope per capability: il token concede solo ciò che
								// elenca, quindi un client che deve leggere utenti, menu o
								// configurazione del sito deve chiederli esplicitamente.
								'scopes'           => array(
									'edit_posts'         => 'Legge e scrive gli articoli, e legge categorie, tag e CPT',
									'edit_pages'         => 'Legge e scrive le pagine',
									'upload_files'       => 'Legge la libreria media e carica file',
									'delete_posts'       => 'Cestina o elimina articoli e media',
									'delete_pages'       => 'Cestina o elimina pagine',
									'manage_categories'  => 'Crea categorie e tag',
									'moderate_comments'  => 'Legge i commenti non approvati, con email e IP, e li modera',
									'list_users'         => 'Legge l\'elenco degli utenti (mai password né hash)',
									'edit_theme_options' => 'Legge menu di navigazione e tema attivo',
									'manage_options'     => 'Legge la configurazione completa del sito',
									'activate_plugins'   => 'Elenca, attiva e disattiva i plugin',
									'delete_plugins'     => 'Elimina plugin',
									'update_core'        => 'Aggiorna il core di WordPress',
									'update_plugins'     => 'Aggiorna i plugin',
									'update_themes'      => 'Aggiorna i temi',
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
						'parameters'  => array_merge(
							array(
								array(
									'name'        => 'status',
									'in'          => 'query',
									'required'    => false,
									'description' => 'Stato di pubblicazione da filtrare. `any` copre tutto tranne il cestino; `trash` va richiesto esplicitamente.',
									'schema'      => array(
										'type'    => 'string',
										'enum'    => array( 'any', 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
										'default' => 'any',
									),
								),
							),
							$this->pagination_params( 10 ),
							array(
								array(
									'name'        => 'content_rendered',
									'in'          => 'query',
									'required'    => false,
									'description' => 'Include content_rendered, cioè post_content passato attraverso the_content: risolve blocchi riutilizzabili, query loop, gallerie dinamiche e shortcode, che in post_content non hanno HTML interno.',
									'schema'      => array(
										'type'    => 'boolean',
										'default' => true,
									),
								),
							)
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
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'delete_posts' ) ) ),
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
					'get' => array(
						'summary'     => 'Elenca i file della Media Library',
						'description' => 'Lista paginata degli allegati, con URL sorgente, dimensioni, peso in byte, testo alternativo, descrizione, post di appartenenza e autore.',
						'operationId' => 'listMedia',
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'upload_files' ) ) ),
						'parameters'  => array_merge(
							$this->pagination_params( 10 ),
							array(
								array(
									'name'        => 'mime_type',
									'in'          => 'query',
									'required'    => false,
									'description' => 'Filtra per tipo MIME (es. image/jpeg)',
									'schema'      => array( 'type' => 'string' ),
								),
							)
						),
						'responses'   => $this->ok_response( 'Media recuperati.' ),
					),
					'post' => array(
						'summary'     => 'Carica un file multimediale',
						'description' => 'Carica un\'immagine base64 nella Media Library di WordPress per l\'inclusione o per essere usata come immagine in evidenza.',
						'operationId' => 'uploadMedia',
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'upload_files' ) ) ),
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
						'parameters'  => $this->pagination_params( 0 ),
						'responses'   => $this->ok_response( 'Categorie recuperate. Senza per_page né after_id restituisce tutti i termini.' ),
					),
					'post' => array(
						'summary'     => 'Crea una categoria',
						'description' => 'Aggiunge una nuova categoria per la classificazione dei post.',
						'operationId' => 'createCategory',
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'manage_categories' ) ) ),
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
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'update_core' ) ) ),
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
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'update_core' ) ) ),
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
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'update_plugins' ) ) ),
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
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'update_themes' ) ) ),
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
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'update_plugins' ) ) ),
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
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'update_core' ) ) ),
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
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'update_core' ) ) ),
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
						'parameters'  => $this->pagination_params( 0 ),
						'responses'   => $this->ok_response( 'Tag recuperati. Senza per_page né after_id restituisce tutti i termini.' ),
					),
					'post' => array(
						'summary'     => 'Crea un tag',
						'description' => 'Aggiunge un nuovo tag descrittivo per gli articoli.',
						'operationId' => 'createTag',
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'manage_categories' ) ) ),
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
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'activate_plugins' ) ) ),
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
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'delete_plugins' ) ) ),
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
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'activate_plugins' ) ) ),
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
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'activate_plugins' ) ) ),
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
				'/pages' => array(
					'get' => array(
						'summary'     => 'Elenca le pagine',
						'description' => 'Lista paginata delle pagine, con gerarchia, menu_order, template e contenuto renderizzato.',
						'operationId' => 'listPages',
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'edit_pages' ) ) ),
						'parameters'  => array_merge(
							array(
								array(
									'name'        => 'status',
									'in'          => 'query',
									'required'    => false,
									'description' => 'Stato di pubblicazione da filtrare. `any` copre tutto tranne il cestino; `trash` va richiesto esplicitamente.',
									'schema'      => array(
										'type'    => 'string',
										'enum'    => array( 'any', 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
										'default' => 'any',
									),
								),
							),
							$this->pagination_params( 10 ),
							array(
								array(
									'name'        => 'content_rendered',
									'in'          => 'query',
									'required'    => false,
									'description' => 'Include content_rendered, cioè post_content passato attraverso the_content.',
									'schema'      => array(
										'type'    => 'boolean',
										'default' => true,
									),
								),
							)
						),
						'responses'   => $this->ok_response( 'Pagine recuperate.' ),
					),
				),
				'/comments' => array(
					'get' => array(
						'summary'     => 'Elenca i commenti del sito',
						'operationId' => 'listComments',
						'description' => 'Richiede edit_posts. Per status diverso da approve, e per includere email e IP, richiede anche lo scope OAuth2 e la capability moderate_comments. Sono visibili solo i commenti di contenuti accessibili all’utente.',
						'parameters'  => array_merge(
							array(
								array(
									'name'        => 'status',
									'in'          => 'query',
									'required'    => false,
									'description' => 'Stato dei commenti da restituire',
									'schema'      => array(
										'type'    => 'string',
										'enum'    => array( 'approve', 'hold', 'spam', 'trash', 'all' ),
										'default' => 'approve',
									),
								),
								array(
									'name'        => 'post_type',
									'in'          => 'query',
									'required'    => false,
									'description' => 'Restituisce solo i commenti sui contenuti di questo tipo',
									'schema'      => array( 'type' => 'string' ),
								),
							),
							$this->pagination_params( 0 )
						),
						'responses'   => array_merge(
							$this->ok_response( 'Commenti recuperati.' ),
							array( '403' => array( 'description' => 'Stato diverso da approve senza la capability moderate_comments.' ) )
						),
					),
				),
				'/users' => array(
					'get' => array(
						'summary'     => 'Elenca gli utenti registrati',
						'description' => 'Sola lettura, richiede la capability list_users. Nessuna password e nessun hash lasciano WordPress: gli account di destinazione vanno creati con credenziali proprie.',
						'operationId' => 'listUsers',
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'list_users' ) ) ),
						'parameters'  => array_merge(
							$this->pagination_params( 20 ),
							array(
								array(
									'name'        => 'role',
									'in'          => 'query',
									'required'    => false,
									'description' => 'Filtra per ruolo WordPress (es. author)',
									'schema'      => array( 'type' => 'string' ),
								),
							)
						),
						'responses'   => $this->ok_response( 'Utenti recuperati.' ),
					),
				),
				'/users/{id}' => array(
					'get' => array(
						'summary'     => 'Recupera un singolo utente',
						'operationId' => 'getUser',
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'list_users' ) ) ),
						'parameters'  => array(
							array( 'name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'integer' ) ),
						),
						'responses'   => array_merge(
							$this->ok_response( 'Utente recuperato.' ),
							array( '404' => array( 'description' => 'Utente non trovato.' ) )
						),
					),
				),
				'/menus' => array(
					'get' => array(
						'summary'     => 'Elenca i menu di navigazione',
						'description' => 'Menu registrati con voci, gerarchia, tipo e oggetto di destinazione. Richiede edit_theme_options.',
						'operationId' => 'listMenus',
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'edit_theme_options' ) ) ),
						'responses'   => $this->ok_response( 'Menu recuperati.' ),
					),
				),
				'/theme' => array(
					'get' => array(
						'summary'     => 'Tema attivo e URL rappresentativi',
						'description' => 'Slug, nome e versione del tema attivo, URL di stylesheet e template, sidebar registrate e URL rappresentativi da catturare (home, ultimo articolo, una pagina, un archivio). Richiede edit_theme_options.',
						'operationId' => 'getTheme',
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'edit_theme_options' ) ) ),
						'responses'   => $this->ok_response( 'Tema recuperato.' ),
					),
				),
				'/site' => array(
					'get' => array(
						'summary'     => 'Informazioni generali sul sito',
						'operationId' => 'getSiteInfo',
						'responses'   => $this->ok_response( 'Informazioni recuperate.' ),
					),
				),
				'/site/full' => array(
					'get' => array(
						'summary'     => 'Configurazione completa del sito',
						'description' => 'Titolo, descrizione, lingua, fuso orario, front page e pagina degli articoli, logo, favicon, struttura dei permalink e site_uuid. Richiede manage_options.',
						'operationId' => 'getFullSiteInfo',
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'manage_options' ) ) ),
						'responses'   => $this->ok_response( 'Configurazione recuperata.' ),
					),
				),
				'/cpt' => array(
					'get' => array(
						'summary'     => 'Elenca i Custom Post Types disponibili',
						'description' => 'Restituisce la lista di tutti i Custom Post Types pubblici registrati sul sito (es. product, portfolio, event), con le loro tassonomie associate, supporti e conteggio. Non include i tipi built-in (post, page, attachment).',
						'operationId' => 'listCustomPostTypes',
						'responses'   => array(
							'200' => array(
								'description' => 'Lista CPT restituita con successo.',
								'content'     => array(
									'application/json' => array(
										'schema' => array(
											'type'       => 'object',
											'properties' => array(
												'items' => array(
													'type'  => 'array',
													'items' => array(
														'type'       => 'object',
														'properties' => array(
															'slug'        => array( 'type' => 'string', 'description' => 'Slug identificativo del CPT' ),
															'name'        => array( 'type' => 'string', 'description' => 'Nome plurale del CPT' ),
															'singular'    => array( 'type' => 'string', 'description' => 'Nome singolare del CPT' ),
															'description' => array( 'type' => 'string' ),
															'hierarchical'=> array( 'type' => 'boolean' ),
															'taxonomies'  => array(
																'type'  => 'array',
																'items' => array(
																	'type'       => 'object',
																	'properties' => array(
																		'slug'         => array( 'type' => 'string' ),
																		'name'         => array( 'type' => 'string' ),
																		'hierarchical' => array( 'type' => 'boolean' ),
																	),
																),
															),
															'count' => array( 'type' => 'integer', 'description' => 'Numero di items pubblicati' ),
														),
													),
												),
												'total' => array( 'type' => 'integer' ),
											),
										),
									),
								),
							),
						),
					),
				),
				'/cpt/{type}' => array(
					'get' => array(
						'summary'     => 'Elenca gli items di un Custom Post Type',
						'description' => 'Recupera una lista paginata di items di un CPT specifico, filtrabili per stato. Usa il valore "slug" restituito da /cpt come parametro {type}.',
						'operationId' => 'listCPTItems',
						// Stessi parametri di paginazione degli altri endpoint di
						// lettura: il controller passa da WPAIB_Rest_Helper, quindi
						// accetta il cursore after_id e gli stessi stati.
						'parameters'  => array_merge(
							array(
								array(
									'name'        => 'type',
									'in'          => 'path',
									'required'    => true,
									'description' => 'Slug del Custom Post Type (restituito da GET /cpt)',
									'schema'      => array( 'type' => 'string' ),
								),
								array(
									'name'        => 'status',
									'in'          => 'query',
									'required'    => false,
									'description' => 'Stato di pubblicazione da filtrare. `any` copre tutto tranne il cestino; `trash` va richiesto esplicitamente.',
									'schema'      => array(
										'type'    => 'string',
										'enum'    => array( 'any', 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
										'default' => 'any',
									),
								),
							),
							$this->pagination_params( 10 )
						),
						'responses'   => array(
							'200' => array(
								'description' => 'Lista items restituita.',
								'content'     => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ),
							),
							'404' => array( 'description' => 'Custom Post Type non trovato o non accessibile.' ),
						),
					),
					'post' => array(
						'summary'     => 'Crea un nuovo item in un Custom Post Type',
						'description' => 'Crea un nuovo item nel CPT specificato. Supporta titolo, contenuto, excerpt, stato, tassonomie e immagine in evidenza.',
						'operationId' => 'createCPTItem',
						'parameters'  => array(
							array(
								'name'     => 'type',
								'in'       => 'path',
								'required' => true,
								'schema'   => array( 'type' => 'string' ),
							),
						),
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'required'   => array( 'title' ),
										'properties' => array(
											'title'          => array( 'type' => 'string', 'description' => 'Titolo dell\'item' ),
											'content'        => array( 'type' => 'string', 'description' => 'Contenuto HTML' ),
											'excerpt'        => array( 'type' => 'string', 'description' => 'Riassunto' ),
											'slug'           => array( 'type' => 'string', 'description' => 'Slug URL personalizzato' ),
											'status'         => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private' ), 'default' => 'draft' ),
											'featured_media' => array( 'type' => 'integer', 'description' => 'ID media in evidenza' ),
											'taxonomies'     => array(
												'type'        => 'object',
												'description' => 'Oggetto con chiave = slug tassonomia, valore = array di ID o nomi dei termini. Es: {"product_cat": [5, 12], "product_tag": ["nuovo", "offerta"]}',
											),
										),
									),
								),
							),
						),
						'responses'   => array(
							'201' => array(
								'description' => 'Item creato con successo.',
								'content'     => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ),
							),
							'400' => array( 'description' => 'Dati non validi.' ),
							'404' => array( 'description' => 'Custom Post Type non trovato.' ),
						),
					),
				),
				'/cpt/{type}/{id}' => array(
					'get' => array(
						'summary'     => 'Recupera un singolo item di un CPT',
						'operationId' => 'getCPTItem',
						'parameters'  => array(
							array( 'name' => 'type', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'string' ) ),
							array( 'name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'integer' ) ),
						),
						'responses'   => array(
							'200' => array( 'description' => 'Item recuperato.', 'content' => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ) ),
							'404' => array( 'description' => 'Item o CPT non trovato.' ),
						),
					),
					'post' => array(
						'summary'     => 'Aggiorna un item di un CPT',
						'operationId' => 'updateCPTItem',
						'parameters'  => array(
							array( 'name' => 'type', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'string' ) ),
							array( 'name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'integer' ) ),
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
											'excerpt'    => array( 'type' => 'string' ),
											'slug'       => array( 'type' => 'string' ),
											'status'     => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'pending', 'private' ) ),
											'taxonomies' => array( 'type' => 'object' ),
										),
									),
								),
							),
						),
						'responses'   => array(
							'200' => array( 'description' => 'Item aggiornato.', 'content' => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ) ),
							'404' => array( 'description' => 'Item o CPT non trovato.' ),
						),
					),
					'delete' => array(
						'summary'     => 'Elimina un item di un CPT',
						'operationId' => 'deleteCPTItem',
						'security'   => array( array( 'ApiKeyAuth' => array() ), array( 'OAuth2' => array( 'delete_posts' ) ) ),
						'parameters'  => array(
							array( 'name' => 'type', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'string' ) ),
							array( 'name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'integer' ) ),
							array( 'name' => 'force', 'in' => 'query', 'required' => false, 'schema' => array( 'type' => 'boolean' ), 'description' => 'Se true elimina definitivamente' ),
						),
						'responses'   => array(
							'200' => array( 'description' => 'Item eliminato.', 'content' => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ) ),
							'404' => array( 'description' => 'Item o CPT non trovato.' ),
						),
					),
				),
			),
		);

		return rest_ensure_response( $schema );
	}
}
