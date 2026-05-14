<?php
/**
 * Template pagina di consenso OAuth2.
 *
 * Variabili disponibili (iniettate da WPAIB_OAuth_Authorize::render_consent_page()):
 * @var object  $client Record client OAuth2 (->name, ->client_id).
 * @var WP_User $user   Utente WP loggato.
 * @var array   $params ['client_id', 'redirect_uri', 'scope', 'state'].
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$site_name   = get_bloginfo( 'name' );
$nonce_value = wp_create_nonce( 'wpaib_oauth_consent_' . $client->client_id );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php printf( esc_html__( 'Autorizza %s — %s', 'wp-ai-bridge' ), esc_html( $client->name ), esc_html( $site_name ) ); ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f0f1; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
.card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 2rem; max-width: 420px; width: 100%; }
h1 { font-size: 1.25rem; margin-bottom: 1rem; color: #1d2327; }
.app-name { font-weight: 700; color: #2271b1; }
.user-info { font-size: 0.875rem; color: #50575e; margin-bottom: 1.5rem; padding: 0.75rem; background: #f6f7f7; border-radius: 4px; }
.permissions { margin-bottom: 1.5rem; }
.permissions p { font-size: 0.875rem; color: #1d2327; margin-bottom: 0.5rem; font-weight: 600; }
.permissions ul { list-style: none; padding-left: 0; }
.permissions li { font-size: 0.875rem; color: #50575e; padding: 0.25rem 0 0.25rem 1.25rem; position: relative; }
.permissions li::before { content: "✓"; position: absolute; left: 0; color: #00a32a; font-size: 0.75rem; top: 0.3rem; }
.actions { display: flex; gap: 0.75rem; }
.btn { padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer; font-size: 0.875rem; font-weight: 600; }
.btn-primary { background: #2271b1; color: #fff; flex: 1; }
.btn-primary:hover { background: #135e96; }
.btn-secondary { background: #f6f7f7; color: #1d2327; border: 1px solid #dcdcde; }
.btn-secondary:hover { background: #dcdcde; }
.footer { margin-top: 1.5rem; font-size: 0.75rem; color: #8c8f94; text-align: center; }
</style>
</head>
<body>
<div class="card">
	<h1>
		<span class="app-name"><?php echo esc_html( $client->name ); ?></span>
		<?php esc_html_e( 'vuole accedere al tuo account', 'wp-ai-bridge' ); ?>
	</h1>

	<div class="user-info">
		<?php printf(
			/* translators: 1: user display name, 2: user email */
			esc_html__( 'Stai autorizzando come: %1$s (%2$s)', 'wp-ai-bridge' ),
			'<strong>' . esc_html( $user->display_name ) . '</strong>',
			esc_html( $user->user_email )
		); ?>
	</div>

	<div class="permissions">
		<p><?php esc_html_e( "L'applicazione potrà:", 'wp-ai-bridge' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Leggere e creare post e pagine', 'wp-ai-bridge' ); ?></li>
			<li><?php esc_html_e( 'Caricare immagini nella media library', 'wp-ai-bridge' ); ?></li>
			<li><?php esc_html_e( 'Gestire categorie e tag', 'wp-ai-bridge' ); ?></li>
			<li><?php esc_html_e( 'Leggere le informazioni del sito', 'wp-ai-bridge' ); ?></li>
		</ul>
	</div>

	<form method="post" action="<?php echo esc_url( home_url( '/wpaib/oauth/authorize' ) ); ?>">
		<input type="hidden" name="_wpnonce"     value="<?php echo esc_attr( $nonce_value ); ?>">
		<input type="hidden" name="client_id"    value="<?php echo esc_attr( $params['client_id'] ); ?>">
		<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $params['redirect_uri'] ); ?>">
		<input type="hidden" name="state"        value="<?php echo esc_attr( $params['state'] ); ?>">
		<input type="hidden" name="scope"        value="<?php echo esc_attr( $params['scope'] ); ?>">

		<div class="actions">
			<button type="submit" name="decision" value="allow" class="btn btn-primary">
				<?php esc_html_e( 'Autorizza', 'wp-ai-bridge' ); ?>
			</button>
			<button type="submit" name="decision" value="deny" class="btn btn-secondary">
				<?php esc_html_e( 'Nega', 'wp-ai-bridge' ); ?>
			</button>
		</div>
	</form>

	<p class="footer">
		<?php printf(
			/* translators: %s: site name */
			esc_html__( 'Sito: %s', 'wp-ai-bridge' ),
			esc_html( $site_name )
		); ?>
	</p>
</div>
</body>
</html>
