<?php
/**
 * View: sezione API Keys nel profilo utente.
 *
 * Variabili attese:
 *
 * @var WP_User $user
 * @var array   $keys
 * @var string  $just_generated
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h2><?php esc_html_e( 'WP AI Bridge — API Keys & MCP Connector', 'wp-ai-bridge' ); ?></h2>

<?php if ( '' === get_option( 'permalink_structure' ) ) : ?>
<div class="notice notice-warning" style="padding:12px 16px; margin-bottom:16px; border-left-color:#f59e0b;">
	<p style="margin:0; font-size:13px;">
		<strong><?php esc_html_e( 'Permalink non configurati.', 'wp-ai-bridge' ); ?></strong>
		<?php
		printf(
			/* translators: 1: link apertura, 2: link chiusura */
			esc_html__( 'Gli URL REST usano il formato query string (?rest_route=…) invece di /wp-json/…. %1$sAbilita i permalink%2$s per URL più puliti e compatibili con tutti i client AI.', 'wp-ai-bridge' ),
			'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">',
			'</a>'
		);
		?>
	</p>
</div>
<?php endif; ?>

<div id="wpaib_notice_container">
	<?php if ( ! empty( $just_generated ) ) : ?>
		<div class="notice notice-success" style="padding:16px; margin-bottom:20px; border-left-color:#0284c7; background:#f0f9ff;">
			<p style="font-size:15px; color:#0369a1; margin-top:0;"><strong><?php esc_html_e( 'Nuova API key generata con successo!', 'wp-ai-bridge' ); ?></strong></p>
			<p><?php esc_html_e( 'Copia la chiave ora: per motivi di sicurezza non sarà mai più visualizzabile in chiaro.', 'wp-ai-bridge' ); ?></p>
			<p>
				<code style="display:block; padding:12px; background:#fff; border:1px solid #bae6fd; border-radius:6px; font-size:14px; color:#0f172a; word-break:break-all; user-select:all;">
					<?php echo esc_html( $just_generated ); ?>
				</code>
			</p>
			<p style="color:#b45309; font-size:13px;">
				<?php esc_html_e( 'Conservala in un password manager sicuro.', 'wp-ai-bridge' ); ?>
			</p>
			<p style="margin-bottom:0;">
				<button type="button" class="button button-primary wpaib-btn-mcp-new" style="background:#0284c7; border-color:#0369a1;" data-key="<?php echo esc_attr( $just_generated ); ?>"><?php esc_html_e( '✨ Visualizza Configurazione MCP Completa', 'wp-ai-bridge' ); ?></button>
			</p>
		</div>
	<?php endif; ?>
</div>

<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><label for="wpaib_label"><?php esc_html_e( 'Genera nuova chiave', 'wp-ai-bridge' ); ?></label></th>
		<td>
			<div style="display:inline-block;">
				<input type="hidden" id="wpaib_user_id" value="<?php echo esc_attr( $user->ID ); ?>" />
				<input type="text" id="wpaib_label" placeholder="<?php esc_attr_e( 'Etichetta (es. Server MCP, App Mobile)', 'wp-ai-bridge' ); ?>" class="regular-text" maxlength="100" />
				<button type="button" id="wpaib_btn_generate" class="button button-primary"><?php esc_html_e( 'Genera chiave', 'wp-ai-bridge' ); ?></button>
				<span id="wpaib_spinner" class="spinner" style="float:none; margin:0 5px;"></span>
			</div>
			<p class="description">
				<?php esc_html_e( 'Ogni chiave è personale, protetta da crittografia SHA-256 e provvista di rate limiting nativo.', 'wp-ai-bridge' ); ?>
			</p>
		</td>
	</tr>
</table>

<h3 style="margin-top:30px;"><?php esc_html_e( 'Le tue chiavi attive e configurazioni', 'wp-ai-bridge' ); ?></h3>

<p id="wpaib_no_keys_msg" style="<?php echo empty( $keys ) ? '' : 'display:none;'; ?>">
	<?php esc_html_e( 'Nessuna chiave generata al momento.', 'wp-ai-bridge' ); ?>
</p>

<table id="wpaib_keys_table" class="wp-list-table widefat striped" style="<?php echo empty( $keys ) ? 'display:none;' : ''; ?>">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Etichetta', 'wp-ai-bridge' ); ?></th>
			<th><?php esc_html_e( 'Creata il', 'wp-ai-bridge' ); ?></th>
			<th><?php esc_html_e( 'Ultimo utilizzo', 'wp-ai-bridge' ); ?></th>
			<th><?php esc_html_e( 'IP', 'wp-ai-bridge' ); ?></th>
			<th><?php esc_html_e( 'Stato', 'wp-ai-bridge' ); ?></th>
			<th><?php esc_html_e( 'Azioni & MCP', 'wp-ai-bridge' ); ?></th>
		</tr>
	</thead>
	<tbody id="wpaib_keys_tbody">
		<?php if ( ! empty( $keys ) ) : ?>
			<?php foreach ( $keys as $k ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $k->label ? $k->label : '—' ); ?></strong></td>
					<td><?php echo esc_html( $k->created_at ); ?></td>
					<td><?php echo esc_html( $k->last_used_at ? $k->last_used_at : '—' ); ?></td>
					<td><?php echo esc_html( $k->last_used_ip ? $k->last_used_ip : '—' ); ?></td>
					<td class="wpaib-key-status">
						<?php if ( null === $k->revoked_at ) : ?>
							<span style="color:#16a34a; font-weight:600;">● <?php esc_html_e( 'Attiva', 'wp-ai-bridge' ); ?></span>
						<?php else : ?>
							<span style="color:#94a3b8;">○ <?php echo esc_html__( 'Revocata', 'wp-ai-bridge' ) . ' ' . esc_html( $k->revoked_at ); ?></span>
						<?php endif; ?>
					</td>
					<td class="wpaib-key-actions">
						<?php if ( null === $k->revoked_at ) : ?>
							<button type="button" class="button button-secondary wpaib-btn-mcp" style="margin-right:6px; border-color:#cbd5e1;" data-label="<?php echo esc_attr( $k->label ); ?>"><?php esc_html_e( '🌐 Integrazione AI (OpenAPI)', 'wp-ai-bridge' ); ?></button>
							<button type="button" class="button button-link-delete wpaib-btn-revoke" data-key-id="<?php echo esc_attr( $k->id ); ?>"><?php esc_html_e( 'Revoca', 'wp-ai-bridge' ); ?></button>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
	</tbody>
</table>

<!-- Popup / Modal Configurazione Universale AI (OpenAPI & MCP Nativo) -->
<div id="wpaib_mcp_modal" style="display:none; position:fixed; z-index:999999; left:0; top:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); justify-content:center; align-items:center;">
	<div style="background:#fff; width:90%; max-width:760px; border-radius:12px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); overflow:hidden; display:flex; flex-direction:column; border:1px solid #e2e8f0;">
		<div style="background:#0f172a; color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center;">
			<div style="display:flex; align-items:center; gap:10px;">
				<span style="font-size:20px;">⚡</span>
				<h3 style="margin:0; color:#fff; font-size:18px; font-weight:600;">Integrazione Universale AI (Cloud & IDE Locali)</h3>
			</div>
			<button type="button" id="wpaib_mcp_modal_close" style="background:none; border:none; color:#94a3b8; font-size:26px; cursor:pointer; line-height:1; padding:0; margin:0;">&times;</button>
		</div>
		
		<!-- Selettore Schede (Tab) -->
		<div style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:0 24px; display:flex; gap:16px;">
			<button type="button" id="wpaib_tab_local" class="wpaib-tab-btn" style="padding:14px 4px; background:none; border:none; border-bottom:2px solid #0284c7; color:#0f172a; font-weight:600; cursor:pointer; font-size:14px;">💻 IDE & Client Locali (MCP nativo)</button>
			<button type="button" id="wpaib_tab_cloud" class="wpaib-tab-btn" style="padding:14px 4px; background:none; border:none; border-bottom:2px solid transparent; color:#64748b; font-weight:500; cursor:pointer; font-size:14px;">🌐 Piattaforme Cloud AI (OpenAPI)</button>
		</div>

		<div style="padding:24px; overflow-y:auto; max-height:calc(100vh - 230px);">
			
			<!-- CONTENUTO TAB 1: CLOUD AI -->
			<div id="wpaib_content_cloud" style="display:none;">
				<div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:16px; border-radius:8px; margin-bottom:20px;">
					<h4 style="margin:0 0 8px 0; color:#166534; font-size:14px; display:flex; align-items:center; gap:6px;">
						<span>✨</span> Zero installazioni! Standard OpenAPI 3.0.3
					</h4>
					<p style="margin:0; color:#15803d; font-size:13px; line-height:1.5;">
						Connetti il tuo sito a <strong>ChatGPT (Custom Actions)</strong>, <strong>Google Gemini (Estensioni)</strong> o <strong>Claude.ai</strong> semplicemente copiando l'URL sottostante. L'intelligenza artificiale importerà istantaneamente tutti gli strumenti disponibili.
					</p>
				</div>
				
				<div style="margin-bottom:16px;">
					<div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:600; margin-bottom:4px;">URL Specifiche OpenAPI (Copia e incolla nel portale AI)</div>
					<div style="display:flex; gap:8px;">
						<input type="text" readonly id="wpaib_openapi_url" class="large-text" style="font-family:monospace; font-size:13px; background:#f1f5f9; border-color:#cbd5e1; color:#0f172a; margin:0; flex-grow:1;" value="<?php echo esc_attr( rest_url( 'wpaib/v1/openapi.json' ) ); ?>" onclick="this.select();" />
						<button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText(document.getElementById('wpaib_openapi_url').value); this.textContent='✔ Copiato!'; setTimeout(()=>this.textContent='Copia URL', 2000);">Copia URL</button>
					</div>
				</div>

				<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:20px;">
					<div style="background:#f8fafc; padding:12px 16px; border-radius:8px; border:1px solid #e2e8f0;">
						<div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:600; margin-bottom:4px;">Tipo Autenticazione</div>
						<div style="font-size:14px; color:#0f172a; font-weight:600;">API Key (Custom Header)</div>
					</div>
					<div style="background:#f8fafc; padding:12px 16px; border-radius:8px; border:1px solid #e2e8f0;">
						<div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:600; margin-bottom:4px;">Nome Header Richiesto</div>
						<code style="font-size:14px; color:#0f172a; background:none; padding:0;">X-API-Key</code>
					</div>
				</div>

				<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
					<h4 style="font-size:13px; text-transform:uppercase; letter-spacing:0.5px; margin:0; color:#0f172a;">Schema OpenAPI JSON Integrale (Opzionale)</h4>
					<button type="button" id="wpaib_mcp_copy_json" class="button button-small" style="font-size:11px;">Copia JSON OpenAPI</button>
				</div>
				
				<div style="position:relative;">
					<pre id="wpaib_mcp_json_code" style="background:#0f172a; color:#38bdf8; padding:16px; border-radius:8px; overflow-x:auto; font-size:13px; line-height:1.45; user-select:all; margin:0; tab-size:2;"></pre>
				</div>
			</div>

			<!-- CONTENUTO TAB 2: IDE & CLIENT LOCALI -->
			<div id="wpaib_content_local" style="display:block;">
				<div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:16px; border-radius:8px; margin-bottom:20px;">
					<h4 style="margin:0 0 6px 0; color:#166534; font-size:14px;">⚡ MCP Remoto Nativo — Zero installazioni</h4>
					<p style="margin:0; color:#15803d; font-size:13px; line-height:1.5;">
						Connetti il tuo sito WordPress come <strong>server MCP remoto</strong> direttamente da <strong>Cursor, Claude Desktop, Claude Code, VS Code</strong> e qualsiasi client MCP compatibile. Nessun file da scaricare, nessun PHP locale richiesto.
					</p>
				</div>

				<div style="margin-bottom:16px;">
					<div style="font-size:12px; color:#64748b; text-transform:uppercase; font-weight:600; margin-bottom:4px;">Endpoint MCP</div>
					<div style="display:flex; gap:8px;">
						<input type="text" readonly id="wpaib_mcp_endpoint_url" class="large-text" style="font-family:monospace; font-size:13px; background:#f1f5f9; border-color:#cbd5e1; color:#0f172a; margin:0; flex-grow:1;" value="<?php echo esc_attr( rest_url( 'wpaib/v1/mcp' ) ); ?>" onclick="this.select();" />
						<button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText(document.getElementById('wpaib_mcp_endpoint_url').value); this.textContent='✔ Copiato!'; setTimeout(()=>this.textContent='Copia URL',2000);">Copia URL</button>
					</div>
				</div>

				<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
					<h4 style="font-size:13px; text-transform:uppercase; letter-spacing:0.5px; margin:0; color:#0f172a;">Configurazione Client MCP (JSON)</h4>
					<button type="button" id="wpaib_mcp_copy_local" class="button button-small" style="font-size:11px;">Copia Configurazione</button>
				</div>

				<div style="position:relative;">
					<pre id="wpaib_mcp_local_code" style="background:#0f172a; color:#22c55e; padding:16px; border-radius:8px; overflow-x:auto; font-size:13px; line-height:1.45; user-select:all; margin:0; tab-size:2;"></pre>
				</div>
			</div>

			<p id="wpaib_mcp_modal_key_warning" style="margin:16px 0 0 0; font-size:13px; color:#b45309; background:#fef3c7; padding:10px 14px; border-radius:8px; border:1px solid #f59e0b; display:flex; gap:8px; align-items:center;">
				<span style="font-size:16px;">🔒</span>
				<span>Per sicurezza, la chiave in chiaro non è salvata sul server. Sostituisci <strong>INSERISCI_QUI_LA_TUA_CHIAVE</strong> con la chiave copiata al momento della generazione.</span>
			</p>
		</div>
		<div style="padding:14px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end;">
			<button type="button" id="wpaib_mcp_modal_btn_close" class="button button-primary" style="background:#0f172a; border-color:#0f172a;">Chiudi Pannello</button>
		</div>
	</div>
</div>

<script>
var wpaibL10n = {
	copyError:      <?php echo wp_json_encode( __( 'Impossibile copiare il testo automaticamente.', 'wp-ai-bridge' ) ); ?>,
	generateError:  <?php echo wp_json_encode( __( 'Impossibile generare la chiave.', 'wp-ai-bridge' ) ); ?>,
	generateNetErr: <?php echo wp_json_encode( __( 'Errore di rete durante la generazione della chiave.', 'wp-ai-bridge' ) ); ?>,
	revokeError:    <?php echo wp_json_encode( __( 'Impossibile revocare la chiave.', 'wp-ai-bridge' ) ); ?>,
	revokeNetErr:   <?php echo wp_json_encode( __( 'Errore di rete durante la revoca della chiave.', 'wp-ai-bridge' ) ); ?>,
	errorPrefix:    <?php echo wp_json_encode( __( 'Errore: ', 'wp-ai-bridge' ) ); ?>
};
document.addEventListener('DOMContentLoaded', function() {
	const btnGenerate = document.getElementById('wpaib_btn_generate');
	const spinner = document.getElementById('wpaib_spinner');
	const labelInput = document.getElementById('wpaib_label');
	const userIdEl = document.getElementById('wpaib_user_id');
	const userId = userIdEl ? userIdEl.value : 0;
	const keysTbody = document.getElementById('wpaib_keys_tbody');
	const noKeysMsg = document.getElementById('wpaib_no_keys_msg');
	const keysTable = document.getElementById('wpaib_keys_table');
	const noticeContainer = document.getElementById('wpaib_notice_container');

	const modal = document.getElementById('wpaib_mcp_modal');
	const modalCloseX = document.getElementById('wpaib_mcp_modal_close');
	const modalCloseBtn = document.getElementById('wpaib_mcp_modal_btn_close');
	const jsonCodeEl = document.getElementById('wpaib_mcp_json_code');
	const localCodeEl = document.getElementById('wpaib_mcp_local_code');
	const keyWarningEl = document.getElementById('wpaib_mcp_modal_key_warning');
	const copyJsonBtn = document.getElementById('wpaib_mcp_copy_json');
	const copyLocalBtn = document.getElementById('wpaib_mcp_copy_local');

	const tabCloudBtn = document.getElementById('wpaib_tab_cloud');
	const tabLocalBtn = document.getElementById('wpaib_tab_local');
	const contentCloud = document.getElementById('wpaib_content_cloud');
	const contentLocal = document.getElementById('wpaib_content_local');

	// Gestione Tabs
	if (tabCloudBtn && tabLocalBtn) {
		tabCloudBtn.addEventListener('click', function() {
			tabCloudBtn.style.borderBottomColor = '#0284c7';
			tabCloudBtn.style.color = '#0f172a';
			tabCloudBtn.style.fontWeight = '600';
			
			tabLocalBtn.style.borderBottomColor = 'transparent';
			tabLocalBtn.style.color = '#64748b';
			tabLocalBtn.style.fontWeight = '500';

			if (contentCloud) contentCloud.style.display = 'block';
			if (contentLocal) contentLocal.style.display = 'none';
		});

		tabLocalBtn.addEventListener('click', function() {
			tabLocalBtn.style.borderBottomColor = '#0284c7';
			tabLocalBtn.style.color = '#0f172a';
			tabLocalBtn.style.fontWeight = '600';
			
			tabCloudBtn.style.borderBottomColor = 'transparent';
			tabCloudBtn.style.color = '#64748b';
			tabCloudBtn.style.fontWeight = '500';

			if (contentLocal) contentLocal.style.display = 'block';
			if (contentCloud) contentCloud.style.display = 'none';
		});
	}

	// Genera template JSON OpenAPI 3.0.3 per Cloud AI
	function getOpenApiJsonTemplate(serverName) {
		const baseUrl = <?php echo json_encode( untrailingslashit( rest_url( 'wpaib/v1' ) ) ); ?>;
		
		const openapiObj = {
			"openapi": "3.0.3",
			"info": {
				"title": "WP AI Bridge Connector (" + (serverName || "Sito WP") + ")",
				"description": "API REST standard per connettere agenti AI in modo sicuro al sito WordPress per la gestione integrata di articoli, media e tassonomie.",
				"version": "1.0.0"
			},
			"servers": [
				{
					"url": baseUrl,
					"description": "Endpoint REST WP AI Bridge"
				}
			],
			"components": {
				"securitySchemes": {
					"ApiKeyAuth": {
						"type": "apiKey",
						"in": "header",
						"name": "X-API-Key"
					}
				}
			},
			"security": [
				{ "ApiKeyAuth": [] }
			],
			"paths": {
				"/posts": {
					"get": {
						"summary": "Elenca articoli",
						"operationId": "listPosts",
						"parameters": [
							{ "name": "status", "in": "query", "schema": { "type": "string", "default": "any" } },
							{ "name": "per_page", "in": "query", "schema": { "type": "integer", "default": 10 } },
							{ "name": "page", "in": "query", "schema": { "type": "integer", "default": 1 } }
						],
						"responses": { "200": { "description": "Successo" } }
					},
					"post": {
						"summary": "Crea articolo",
						"operationId": "createPost",
						"requestBody": {
							"required": true,
							"content": {
								"application/json": {
									"schema": {
										"type": "object",
										"required": ["title"],
										"properties": {
											"title": { "type": "string" },
											"content": { "type": "string" },
											"status": { "type": "string", "default": "draft" }
										}
									}
								}
							}
						},
						"responses": { "200": { "description": "Creato" } }
					}
				},
				"/posts/{id}": {
					"get": {
						"summary": "Dettagli articolo",
						"operationId": "getPost",
						"parameters": [{ "name": "id", "in": "path", "required": true, "schema": { "type": "integer" } }],
						"responses": { "200": { "description": "Successo" } }
					},
					"post": {
						"summary": "Aggiorna articolo",
						"operationId": "updatePost",
						"parameters": [{ "name": "id", "in": "path", "required": true, "schema": { "type": "integer" } }],
						"requestBody": {
							"required": true,
							"content": { "application/json": { "schema": { "type": "object" } } }
						},
						"responses": { "200": { "description": "Aggiornato" } }
					},
					"delete": {
						"summary": "Elimina articolo",
						"operationId": "deletePost",
						"parameters": [{ "name": "id", "in": "path", "required": true, "schema": { "type": "integer" } }],
						"responses": { "200": { "description": "Eliminato" } }
					}
				},
				"/media": {
					"post": {
						"summary": "Carica media base64",
						"operationId": "uploadMedia",
						"requestBody": {
							"required": true,
							"content": {
								"application/json": {
									"schema": {
										"type": "object",
										"required": ["filename", "image_base64"],
										"properties": {
											"filename": { "type": "string" },
											"image_base64": { "type": "string" }
										}
									}
								}
							}
						},
						"responses": { "200": { "description": "Caricato" } }
					}
				},
				"/categories": {
					"get": { "summary": "Elenco categorie", "operationId": "listCategories", "responses": { "200": { "description": "Successo" } } },
					"post": { "summary": "Crea categoria", "operationId": "createCategory", "requestBody": { "required": true, "content": { "application/json": { "schema": { "type": "object", "required": ["name"], "properties": { "name": { "type": "string" } } } } } }, "responses": { "200": { "description": "Successo" } } }
				},
				"/tags": {
					"get": { "summary": "Elenco tag", "operationId": "listTags", "responses": { "200": { "description": "Successo" } } },
					"post": { "summary": "Crea tag", "operationId": "createTag", "requestBody": { "required": true, "content": { "application/json": { "schema": { "type": "object", "required": ["name"], "properties": { "name": { "type": "string" } } } } } }, "responses": { "200": { "description": "Successo" } } }
				}
			}
		};

		return JSON.stringify(openapiObj, null, 2);
	}

	// Genera la configurazione JSON per client MCP remoti.
	function getLocalMcpTemplate(apiKey, serverName) {
		const keyStr = apiKey || 'INSERISCI_QUI_LA_TUA_CHIAVE';
		const nameStr = (serverName || 'wp_ai_bridge').replace(/[^a-zA-Z0-9_-]/g, '_').toLowerCase();
		const mcpUrl = <?php echo json_encode( rest_url( 'wpaib/v1/mcp' ) ); ?>;

		const configObj = { "mcpServers": {} };
		configObj.mcpServers[nameStr] = {
			"url": mcpUrl,
			"headers": {
				"X-API-Key": keyStr
			}
		};

		return JSON.stringify(configObj, null, 2);
	}

	// Aggiorna il blocco JSON MCP.
	function updateLocalMcpCode() {
		if (localCodeEl) {
			localCodeEl.textContent = getLocalMcpTemplate(
				localCodeEl.getAttribute('data-api-key'),
				localCodeEl.getAttribute('data-label')
			);
		}
	}

	// Copia codice JSON locale negli appunti
	if (copyLocalBtn) {
		copyLocalBtn.addEventListener('click', function() {
			if (!localCodeEl) return;
			const textToCopy = localCodeEl.textContent;
			navigator.clipboard.writeText(textToCopy).then(() => {
				const originalText = copyLocalBtn.textContent;
				copyLocalBtn.textContent = '✔ Copiato!';
				setTimeout(() => { copyLocalBtn.textContent = originalText; }, 2000);
			}).catch(() => {
				alert(wpaibL10n.copyError);
			});
		});
	}

	// Funzione per aprire il Modal Universale
	function openMcpModal(apiKey, label) {
		if (!modal) return;

		if (jsonCodeEl) jsonCodeEl.textContent = getOpenApiJsonTemplate(label);

		// Passa api key e label al pre per il live update.
		if (localCodeEl) {
			localCodeEl.setAttribute('data-api-key', apiKey || '');
			localCodeEl.setAttribute('data-label', label || '');
		}
		updateLocalMcpCode();

		if (apiKey) {
			if (keyWarningEl) keyWarningEl.style.display = 'none';
		} else {
			if (keyWarningEl) keyWarningEl.style.display = 'flex';
		}

		// Resetta sempre al tab IDE all'apertura.
		if (tabLocalBtn) {
			tabLocalBtn.style.borderBottomColor = '#0284c7';
			tabLocalBtn.style.color = '#0f172a';
			tabLocalBtn.style.fontWeight = '600';
		}
		if (tabCloudBtn) {
			tabCloudBtn.style.borderBottomColor = 'transparent';
			tabCloudBtn.style.color = '#64748b';
			tabCloudBtn.style.fontWeight = '500';
		}
		if (contentLocal) contentLocal.style.display = 'block';
		if (contentCloud) contentCloud.style.display = 'none';

		modal.style.display = 'flex';
	}

	// Chiudi Modal
	function closeMcpModal() {
		if (modal) modal.style.display = 'none';
	}

	if (modalCloseX) modalCloseX.addEventListener('click', closeMcpModal);
	if (modalCloseBtn) modalCloseBtn.addEventListener('click', closeMcpModal);

	// Chiudi cliccando fuori dal modale
	window.addEventListener('click', function(e) {
		if (e.target === modal) {
			closeMcpModal();
		}
	});

	// Copia codice JSON negli appunti
	if (copyJsonBtn) {
		copyJsonBtn.addEventListener('click', function() {
			const textToCopy = jsonCodeEl.textContent;
			navigator.clipboard.writeText(textToCopy).then(() => {
				const originalText = copyJsonBtn.textContent;
				copyJsonBtn.textContent = '✔ Copiato!';
				setTimeout(() => { copyJsonBtn.textContent = originalText; }, 2000);
			}).catch(() => {
				alert(wpaibL10n.copyError);
			});
		});
	}

	// Ascolto bottoni Config MCP presenti e futuri
	document.addEventListener('click', function(e) {
		// Bottone Config MCP da lista chiavi (senza chiave in chiaro)
		if (e.target && e.target.closest('.wpaib-btn-mcp')) {
			e.preventDefault();
			const btn = e.target.closest('.wpaib-btn-mcp');
			const label = btn.getAttribute('data-label') || 'wp-ai-bridge';
			openMcpModal(null, label);
		}

		// Bottone Config MCP da nuova chiave generata (con chiave in chiaro)
		if (e.target && e.target.closest('.wpaib-btn-mcp-new')) {
			e.preventDefault();
			const btn = e.target.closest('.wpaib-btn-mcp-new');
			const key = btn.getAttribute('data-key');
			openMcpModal(key, 'wp-ai-bridge');
		}
	});

	if (btnGenerate) {
		btnGenerate.addEventListener('click', function(e) {
			e.preventDefault();

			btnGenerate.disabled = true;
			spinner.classList.add('is-active');

			const formData = new URLSearchParams();
			formData.append('action', 'wpaib_generate_key');
			formData.append('nonce', <?php echo json_encode( wp_create_nonce( 'wpaib_admin_nonce' ) ); ?>);
			formData.append('user_id', userId);
			formData.append('label', labelInput ? labelInput.value.trim() : '');

			fetch(ajaxurl, {
				method: 'POST',
				body: formData,
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded'
				}
			})
			.then(response => response.json())
			.then(res => {
				btnGenerate.disabled = false;
				spinner.classList.remove('is-active');

				if (res.success) {
					// Mostra la notifica con la chiave e il bottone MCP
					noticeContainer.innerHTML = `
						<div class="notice notice-success" style="padding:16px; margin-bottom:20px; border-left-color:#0284c7; background:#f0f9ff;">
							<p style="font-size:15px; color:#0369a1; margin-top:0;"><strong><?php esc_html_e( 'Nuova API key generata con successo!', 'wp-ai-bridge' ); ?></strong></p>
							<p><?php esc_html_e( 'Copia la chiave ora: per motivi di sicurezza non sarà mai più visualizzabile in chiaro.', 'wp-ai-bridge' ); ?></p>
							<p>
								<code style="display:block; padding:12px; background:#fff; border:1px solid #bae6fd; border-radius:6px; font-size:14px; color:#0f172a; word-break:break-all; user-select:all;">${res.data.key}</code>
							</p>
							<p style="color:#b45309; font-size:13px;">
								<?php esc_html_e( 'Conservala in un password manager sicuro.', 'wp-ai-bridge' ); ?>
							</p>
							<p style="margin-bottom:0;">
								<button type="button" class="button button-primary wpaib-btn-mcp-new" style="background:#0284c7; border-color:#0369a1;" data-key="${res.data.key}"><?php esc_html_e( '✨ Guida Integrazione OpenAPI', 'wp-ai-bridge' ); ?></button>
							</p>
						</div>
					`;

					// Pulisci input
					if (labelInput) {
						labelInput.value = '';
					}

					// Rimuovi eventuale flag dirty
					const profileForm = document.getElementById('your-profile');
					if (profileForm && typeof jQuery !== 'undefined') {
						jQuery(profileForm).removeClass('dirty');
					}

					// Aggiorna tabella
					if (noKeysMsg) noKeysMsg.style.display = 'none';
					if (keysTable) keysTable.style.display = '';

					const tr = document.createElement('tr');
					tr.innerHTML = `
						<td><strong>${escHtml(res.data.label)}</strong></td>
						<td>${escHtml(res.data.created_at)}</td>
						<td>—</td>
						<td>—</td>
						<td class="wpaib-key-status"><span style="color:#16a34a; font-weight:600;">● <?php esc_html_e( 'Attiva', 'wp-ai-bridge' ); ?></span></td>
						<td class="wpaib-key-actions">
							<button type="button" class="button button-secondary wpaib-btn-mcp" style="margin-right:6px; border-color:#cbd5e1;" data-label="${escHtml(res.data.label)}"><?php esc_html_e( '🌐 Integrazione AI (OpenAPI)', 'wp-ai-bridge' ); ?></button>
							<button type="button" class="button button-link-delete wpaib-btn-revoke" data-key-id="${res.data.id}"><?php esc_html_e( 'Revoca', 'wp-ai-bridge' ); ?></button>
						</td>
					`;
					if (keysTbody) {
						keysTbody.insertBefore(tr, keysTbody.firstChild);
					}
				} else {
					alert(wpaibL10n.errorPrefix + (res.data || wpaibL10n.generateError));
				}
			})
			.catch(err => {
				btnGenerate.disabled = false;
				spinner.classList.remove('is-active');
				alert(wpaibL10n.generateNetErr);
			});
		});
	}

	function escHtml(str) {
		if (!str) return '';
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	// Revoca
	document.addEventListener('click', function(e) {
		if (e.target && e.target.classList.contains('wpaib-btn-revoke')) {
			e.preventDefault();
			if (!confirm('<?php echo esc_js( __( 'Confermi la revoca? Non sarà più reversibile.', 'wp-ai-bridge' ) ); ?>')) {
				return;
			}

			const btnRevoke = e.target;
			const keyId = btnRevoke.getAttribute('data-key-id');
			btnRevoke.disabled = true;

			const formData = new URLSearchParams();
			formData.append('action', 'wpaib_revoke_key');
			formData.append('nonce', <?php echo json_encode( wp_create_nonce( 'wpaib_admin_nonce' ) ); ?>);
			formData.append('key_id', keyId);
			formData.append('user_id', userId);

			fetch(ajaxurl, {
				method: 'POST',
				body: formData,
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded'
				}
			})
			.then(response => response.json())
			.then(res => {
				if (res.success) {
					const tr = btnRevoke.closest('tr');
					const statusTd = tr.querySelector('.wpaib-key-status');
					const actionsTd = tr.querySelector('.wpaib-key-actions');
					
					if (statusTd) {
						statusTd.innerHTML = `<span style="color:#94a3b8;">○ <?php esc_html_e( 'Revocata', 'wp-ai-bridge' ); ?> ${escHtml(res.data.revoked_at)}</span>`;
					}
					if (actionsTd) {
						actionsTd.innerHTML = '—';
					}
				} else {
					btnRevoke.disabled = false;
					alert(wpaibL10n.errorPrefix + (res.data || wpaibL10n.revokeError));
				}
			})
			.catch(err => {
				btnRevoke.disabled = false;
				alert(wpaibL10n.revokeNetErr);
			});
		}
	});

	if (labelInput) {
		labelInput.addEventListener('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				if (btnGenerate) btnGenerate.click();
			}
		});
	}
});
</script>
