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
$tab_conn_url  = admin_url( 'options-general.php?page=wpaib-tools&tab=connections' );
$tab_oauth_url = admin_url( 'options-general.php?page=wpaib-tools&tab=oauth' );

$clients       = WPAIB_OAuth_Client_Manager::get_all();
$new_client_id = isset( $_GET['wpaib_new_client'] ) ? sanitize_text_field( wp_unslash( $_GET['wpaib_new_client'] ) ) : '';
$new_secret    = isset( $_GET['wpaib_new_secret'] )  ? sanitize_text_field( wp_unslash( $_GET['wpaib_new_secret'] ) )  : '';
$oauth_error   = isset( $_GET['wpaib_oauth_error'] )  ? sanitize_text_field( wp_unslash( $_GET['wpaib_oauth_error'] ) )  : '';
?>
<div class="wrap">
	<h1><?php esc_html_e( 'WP AI Bridge', 'wp-ai-bridge' ); ?></h1>
	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $tab_tools_url ); ?>" class="nav-tab">
			<?php esc_html_e( 'Tool MCP', 'wp-ai-bridge' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_conn_url ); ?>" class="nav-tab">
			<?php esc_html_e( 'Connessioni', 'wp-ai-bridge' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_oauth_url ); ?>" class="nav-tab nav-tab-active">
			<?php esc_html_e( 'OAuth2 Clients', 'wp-ai-bridge' ); ?>
		</a>
	</nav>

	<?php if ( $oauth_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $oauth_error ); ?></p></div>
	<?php endif; ?>

	<?php if ( $new_client_id && $new_secret ) : ?>
		<div class="notice notice-success">
			<p><strong><?php esc_html_e( 'Client creato. Salva subito queste credenziali — il secret non verrà più mostrato.', 'wp-ai-bridge' ); ?></strong></p>
			<table class="widefat" style="max-width:600px;margin-top:8px;">
				<tr><th><?php esc_html_e( 'Client ID', 'wp-ai-bridge' ); ?></th><td><code><?php echo esc_html( $new_client_id ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Client Secret', 'wp-ai-bridge' ); ?></th><td><code><?php echo esc_html( $new_secret ); ?></code></td></tr>
				<tr>
					<th><?php esc_html_e( 'Authorization URL', 'wp-ai-bridge' ); ?></th>
					<td><code><?php echo esc_html( home_url( '/wpaib/oauth/authorize' ) ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Token URL', 'wp-ai-bridge' ); ?></th>
					<td><code><?php echo esc_html( rest_url( WPAIB_API_NAMESPACE . '/oauth/token' ) ); ?></code></td>
				</tr>
			</table>
		</div>
	<?php endif; ?>

	<h2 style="margin-top:1.5em;"><?php esc_html_e( 'Client registrati', 'wp-ai-bridge' ); ?></h2>

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

	<h2 style="margin-top:2em;"><?php esc_html_e( 'Aggiungi client', 'wp-ai-bridge' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:500px;">
		<input type="hidden" name="action" value="wpaib_create_oauth_client">
		<?php wp_nonce_field( 'wpaib_create_oauth_client' ); ?>

		<table class="form-table">
			<tr>
				<th><label for="client_name"><?php esc_html_e( 'Nome client', 'wp-ai-bridge' ); ?></label></th>
				<td>
					<input type="text" id="client_name" name="client_name" class="regular-text"
					       placeholder="<?php esc_attr_e( 'es. ChatGPT', 'wp-ai-bridge' ); ?>" required>
				</td>
			</tr>
			<tr>
				<th><label for="client_redirect_uris"><?php esc_html_e( 'Redirect URI', 'wp-ai-bridge' ); ?></label></th>
				<td>
					<textarea id="client_redirect_uris" name="client_redirect_uris" rows="3" class="large-text" required
					          placeholder="https://chat.openai.com/aip/g-xxx/oauth/callback"></textarea>
					<p class="description"><?php esc_html_e( 'Una URI per riga. Devono iniziare con https://.', 'wp-ai-bridge' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Crea client', 'wp-ai-bridge' ) ); ?>
	</form>
</div>
