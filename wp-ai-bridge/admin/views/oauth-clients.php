<?php
/**
 * Vista: tab OAuth2 Clients.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tab_tools_url = admin_url( 'options-general.php?page=wpaib-tools' );
$tab_oauth_url = admin_url( 'options-general.php?page=wpaib-tools&tab=oauth' );
$tab_conn_url  = admin_url( 'options-general.php?page=wpaib-tools&tab=connections' );

$clients       = WPAIB_OAuth_Client_Manager::get_all();
$new_client_id = isset( $_GET['wpaib_new_client'] ) ? sanitize_text_field( wp_unslash( $_GET['wpaib_new_client'] ) ) : '';
$new_secret    = isset( $_GET['wpaib_new_secret'] )  ? sanitize_text_field( wp_unslash( $_GET['wpaib_new_secret'] ) )  : '';
$oauth_error   = isset( $_GET['wpaib_oauth_error'] )  ? sanitize_text_field( wp_unslash( $_GET['wpaib_oauth_error'] ) )  : '';

$auth_url  = home_url( '/wpaib/oauth/authorize' );
$token_url = rest_url( WPAIB_API_NAMESPACE . '/oauth/token' );
$mcp_url   = rest_url( WPAIB_API_NAMESPACE . '/mcp' );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'WP AI Bridge', 'wp-ai-bridge' ); ?></h1>
	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $tab_tools_url ); ?>" class="nav-tab">
			<?php esc_html_e( 'Tool MCP', 'wp-ai-bridge' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_oauth_url ); ?>" class="nav-tab nav-tab-active">
			<?php esc_html_e( 'OAuth2 Clients', 'wp-ai-bridge' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_conn_url ); ?>" class="nav-tab">
			<?php esc_html_e( 'Connessioni', 'wp-ai-bridge' ); ?>
		</a>
	</nav>

	<?php if ( $oauth_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $oauth_error ); ?></p></div>
	<?php endif; ?>

	<?php if ( $new_client_id && $new_secret ) : ?>
		<div class="notice notice-success">
			<p><strong><?php esc_html_e( 'Client creato. Salva subito queste credenziali — il secret non verrà più mostrato.', 'wp-ai-bridge' ); ?></strong></p>
			<table class="widefat" style="max-width:640px;margin-top:8px;">
				<tr><th><?php esc_html_e( 'Client ID', 'wp-ai-bridge' ); ?></th><td><code><?php echo esc_html( $new_client_id ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Client Secret', 'wp-ai-bridge' ); ?></th><td><code><?php echo esc_html( $new_secret ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Authorization URL', 'wp-ai-bridge' ); ?></th><td><code><?php echo esc_html( $auth_url ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Token URL', 'wp-ai-bridge' ); ?></th><td><code><?php echo esc_html( $token_url ); ?></code></td></tr>
			</table>
		</div>
	<?php endif; ?>

	<!-- ===== GUIDA CONFIGURAZIONE ===== -->
	<h2 style="margin-top:1.5em;"><?php esc_html_e( 'Come configurare i client AI', 'wp-ai-bridge' ); ?></h2>
	<p><?php esc_html_e( 'WP AI Bridge funge da Authorization Server OAuth2. Segui i passaggi per la piattaforma che vuoi collegare.', 'wp-ai-bridge' ); ?></p>

	<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:1em;">

		<!-- Claude.ai -->
		<div style="flex:1;min-width:280px;max-width:480px;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;">
			<h3 style="margin-top:0;">Claude.ai <span style="font-size:12px;font-weight:normal;color:#646970;">(connettore MCP)</span></h3>
			<ol style="margin:0;padding-left:1.4em;line-height:1.8;">
				<li><?php esc_html_e( 'Crea un client qui sotto con:', 'wp-ai-bridge' ); ?><br>
					<?php esc_html_e( 'Redirect URI:', 'wp-ai-bridge' ); ?> <code>https://claude.ai/api/oauth/callback</code>
				</li>
				<li><?php esc_html_e( 'In Claude.ai → Impostazioni → Connettori → Aggiungi connettore personalizzato', 'wp-ai-bridge' ); ?></li>
				<li><?php esc_html_e( 'Compila i campi:', 'wp-ai-bridge' ); ?>
					<table style="margin-top:6px;border-collapse:collapse;width:100%;">
						<tr><td style="padding:2px 6px 2px 0;white-space:nowrap;color:#646970;"><?php esc_html_e( 'URL server MCP', 'wp-ai-bridge' ); ?></td><td><code style="font-size:11px;"><?php echo esc_html( $mcp_url ); ?></code></td></tr>
						<tr><td style="padding:2px 6px 2px 0;white-space:nowrap;color:#646970;">OAuth Client ID</td><td><code style="font-size:11px;">wpaib_c_…</code></td></tr>
						<tr><td style="padding:2px 6px 2px 0;white-space:nowrap;color:#646970;">Client Secret</td><td><code style="font-size:11px;">wpaib_s_…</code></td></tr>
					</table>
				</li>
				<li><?php esc_html_e( 'Clicca Aggiungi → accedi a WP → Autorizza. Fine.', 'wp-ai-bridge' ); ?></li>
			</ol>
		</div>

		<!-- ChatGPT -->
		<div style="flex:1;min-width:280px;max-width:480px;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;">
			<h3 style="margin-top:0;">ChatGPT <span style="font-size:12px;font-weight:normal;color:#646970;">(Custom Actions / GPT)</span></h3>
			<ol style="margin:0;padding-left:1.4em;line-height:1.8;">
				<li><?php esc_html_e( 'In ChatGPT → Esplora GPT → Crea GPT → Configura → Aggiungi azioni', 'wp-ai-bridge' ); ?></li>
				<li><?php esc_html_e( 'Importa lo schema OpenAPI da:', 'wp-ai-bridge' ); ?><br>
					<code style="font-size:11px;"><?php echo esc_html( rest_url( WPAIB_API_NAMESPACE . '/openapi.json' ) ); ?></code>
				</li>
				<li><?php esc_html_e( 'In Autenticazione scegli OAuth → annota la Callback URL mostrata da ChatGPT (es. ', 'wp-ai-bridge' ); ?><code>https://chat.openai.com/aip/g-xxx/oauth/callback</code>)</li>
				<li><?php esc_html_e( 'Crea un client qui sotto con quella Redirect URI esatta', 'wp-ai-bridge' ); ?></li>
				<li><?php esc_html_e( 'Torna in ChatGPT e compila:', 'wp-ai-bridge' ); ?>
					<table style="margin-top:6px;border-collapse:collapse;width:100%;">
						<tr><td style="padding:2px 6px 2px 0;white-space:nowrap;color:#646970;">Client ID</td><td><code style="font-size:11px;">wpaib_c_…</code></td></tr>
						<tr><td style="padding:2px 6px 2px 0;white-space:nowrap;color:#646970;">Client Secret</td><td><code style="font-size:11px;">wpaib_s_…</code></td></tr>
						<tr><td style="padding:2px 6px 2px 0;white-space:nowrap;color:#646970;">Authorization URL</td><td><code style="font-size:11px;"><?php echo esc_html( $auth_url ); ?></code></td></tr>
						<tr><td style="padding:2px 6px 2px 0;white-space:nowrap;color:#646970;">Token URL</td><td><code style="font-size:11px;"><?php echo esc_html( $token_url ); ?></code></td></tr>
						<tr><td style="padding:2px 6px 2px 0;white-space:nowrap;color:#646970;">Scope</td><td><code style="font-size:11px;">edit_posts</code></td></tr>
					</table>
				</li>
			</ol>
		</div>

		<!-- Gemini -->
		<div style="flex:1;min-width:280px;max-width:480px;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;">
			<h3 style="margin-top:0;">Gemini / Google AI Studio <span style="font-size:12px;font-weight:normal;color:#646970;">(API Key)</span></h3>
			<p style="margin-top:0;color:#646970;font-size:13px;"><?php esc_html_e( 'Gemini non supporta ancora OAuth2 per le integrazioni esterne. Usa l\'autenticazione tramite API Key.', 'wp-ai-bridge' ); ?></p>
			<ol style="margin:0;padding-left:1.4em;line-height:1.8;">
				<li><?php esc_html_e( 'Vai su Utenti → Il tuo profilo → WP AI Bridge — API Keys → Genera chiave', 'wp-ai-bridge' ); ?></li>
				<li><?php esc_html_e( 'Importa lo schema OpenAPI in Google AI Studio o Gemini Gems:', 'wp-ai-bridge' ); ?><br>
					<code style="font-size:11px;"><?php echo esc_html( rest_url( WPAIB_API_NAMESPACE . '/openapi.json' ) ); ?></code>
				</li>
				<li><?php esc_html_e( 'Imposta l\'header di autenticazione:', 'wp-ai-bridge' ); ?><br>
					<code style="font-size:11px;">X-API-Key: wpaib_…</code>
				</li>
			</ol>
		</div>

	</div><!-- /card grid -->

	<!-- URL di riferimento -->
	<h3 style="margin-top:2em;"><?php esc_html_e( 'URL OAuth2 di questo sito', 'wp-ai-bridge' ); ?></h3>
	<table class="widefat" style="max-width:640px;">
		<tr><th style="width:180px;"><?php esc_html_e( 'Authorization URL', 'wp-ai-bridge' ); ?></th><td><code><?php echo esc_html( $auth_url ); ?></code></td></tr>
		<tr><th><?php esc_html_e( 'Token URL', 'wp-ai-bridge' ); ?></th><td><code><?php echo esc_html( $token_url ); ?></code></td></tr>
		<tr><th><?php esc_html_e( 'MCP Server URL', 'wp-ai-bridge' ); ?></th><td><code><?php echo esc_html( $mcp_url ); ?></code></td></tr>
		<tr><th><?php esc_html_e( 'OpenAPI Schema', 'wp-ai-bridge' ); ?></th><td><code><?php echo esc_html( rest_url( WPAIB_API_NAMESPACE . '/openapi.json' ) ); ?></code></td></tr>
	</table>

	<!-- ===== CLIENT REGISTRATI ===== -->
	<h2 style="margin-top:2em;"><?php esc_html_e( 'Client registrati', 'wp-ai-bridge' ); ?></h2>

	<?php if ( empty( $clients ) ) : ?>
		<p><?php esc_html_e( 'Nessun client OAuth2 registrato.', 'wp-ai-bridge' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:800px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Nome', 'wp-ai-bridge' ); ?></th>
					<th><?php esc_html_e( 'Client ID', 'wp-ai-bridge' ); ?></th>
					<th><?php esc_html_e( 'Redirect URI', 'wp-ai-bridge' ); ?></th>
					<th><?php esc_html_e( 'Creato', 'wp-ai-bridge' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $clients as $client ) :
					$uris = json_decode( $client->redirect_uris, true );
				?>
				<tr>
					<td><?php echo esc_html( $client->name ); ?></td>
					<td><code><?php echo esc_html( $client->client_id ); ?></code></td>
					<td>
						<?php foreach ( (array) $uris as $uri ) : ?>
							<div><small><?php echo esc_html( $uri ); ?></small></div>
						<?php endforeach; ?>
					</td>
					<td><?php echo esc_html( $client->created_at ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						      onsubmit="return confirm('<?php esc_attr_e( 'Eliminare questo client?', 'wp-ai-bridge' ); ?>');">
							<input type="hidden" name="action"    value="wpaib_delete_oauth_client">
							<input type="hidden" name="client_id" value="<?php echo esc_attr( $client->client_id ); ?>">
							<?php wp_nonce_field( 'wpaib_delete_oauth_client' ); ?>
							<button type="submit" class="button button-small button-link-delete">
								<?php esc_html_e( 'Elimina', 'wp-ai-bridge' ); ?>
							</button>
						</form>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<!-- ===== AGGIUNGI CLIENT ===== -->
	<h2 style="margin-top:2em;"><?php esc_html_e( 'Aggiungi client', 'wp-ai-bridge' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:500px;">
		<input type="hidden" name="action" value="wpaib_create_oauth_client">
		<?php wp_nonce_field( 'wpaib_create_oauth_client' ); ?>

		<table class="form-table">
			<tr>
				<th><label for="client_name"><?php esc_html_e( 'Nome client', 'wp-ai-bridge' ); ?></label></th>
				<td>
					<input type="text" id="client_name" name="client_name" class="regular-text"
					       placeholder="<?php esc_attr_e( 'es. Claude.ai', 'wp-ai-bridge' ); ?>" required>
				</td>
			</tr>
			<tr>
				<th><label for="client_redirect_uris"><?php esc_html_e( 'Redirect URI', 'wp-ai-bridge' ); ?></label></th>
				<td>
					<textarea id="client_redirect_uris" name="client_redirect_uris" rows="3" class="large-text" required
					          placeholder="https://claude.ai/api/oauth/callback"></textarea>
					<p class="description"><?php esc_html_e( 'Una URI per riga. Devono iniziare con https:// (oppure http://localhost per test locali).', 'wp-ai-bridge' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Crea client', 'wp-ai-bridge' ) ); ?>
	</form>
</div>
