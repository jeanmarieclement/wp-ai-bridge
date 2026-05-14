<?php
/**
 * Vista: configurazione tool MCP.
 *
 * Variabili disponibili: $disabled (array di slug disabilitati), $saved (int).
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$categories = array(
	__( 'Post', 'wp-ai-bridge' )           => array( 'get_posts', 'get_post', 'create_post', 'update_post', 'delete_post', 'bulk_update_posts' ),
	__( 'Pagine', 'wp-ai-bridge' )          => array( 'get_pages', 'get_page', 'create_page', 'update_page', 'delete_page' ),
	__( 'Media', 'wp-ai-bridge' )           => array( 'get_media', 'upload_media', 'delete_media' ),
	__( 'Commenti', 'wp-ai-bridge' )        => array( 'get_comments', 'add_comment', 'moderate_comment', 'bulk_moderate_comments' ),
	__( 'Tassonomie', 'wp-ai-bridge' )      => array( 'get_categories', 'create_category', 'get_tags', 'create_tag' ),
	__( 'Sito & Ricerca', 'wp-ai-bridge' )  => array( 'get_site_info', 'search' ),
);

$tab_tools_url = admin_url( 'options-general.php?page=wpaib-tools' );
$tab_conn_url  = admin_url( 'options-general.php?page=wpaib-tools&tab=connections' );
$tab_oauth_url = admin_url( 'options-general.php?page=wpaib-tools&tab=oauth' );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'WP AI Bridge', 'wp-ai-bridge' ); ?></h1>
	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $tab_tools_url ); ?>"
		   class="nav-tab nav-tab-active">
			<?php esc_html_e( 'Tool MCP', 'wp-ai-bridge' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_conn_url ); ?>"
		   class="nav-tab">
			<?php esc_html_e( 'Connessioni', 'wp-ai-bridge' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_oauth_url ); ?>" class="nav-tab">
			<?php esc_html_e( 'OAuth2 Clients', 'wp-ai-bridge' ); ?>
		</a>
	</nav>

	<p style="margin-top:1em;"><?php esc_html_e( 'Seleziona i tool che vuoi esporre tramite le API. I tool non selezionati vengono rimossi dall\'elenco e bloccati in esecuzione.', 'wp-ai-bridge' ); ?></p>

	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Impostazioni salvate.', 'wp-ai-bridge' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wpaib_save_tools">
		<?php wp_nonce_field( 'wpaib_save_tools' ); ?>

		<table class="widefat striped" style="max-width:700px;margin-top:1em;">
			<thead>
				<tr>
					<th style="width:40px;"><?php esc_html_e( 'Attivo', 'wp-ai-bridge' ); ?></th>
					<th><?php esc_html_e( 'Tool', 'wp-ai-bridge' ); ?></th>
					<th><?php esc_html_e( 'Categoria', 'wp-ai-bridge' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $categories as $cat_label => $tools ) : ?>
					<?php foreach ( $tools as $tool_slug ) : ?>
						<?php $checked = ! in_array( $tool_slug, $disabled, true ); ?>
						<tr>
							<td style="text-align:center;">
								<input
									type="checkbox"
									name="wpaib_tools[]"
									value="<?php echo esc_attr( $tool_slug ); ?>"
									<?php checked( $checked ); ?>
								>
							</td>
							<td><code><?php echo esc_html( $tool_slug ); ?></code></td>
							<td><?php echo esc_html( $cat_label ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="submit">
			<?php submit_button( __( 'Salva impostazioni', 'wp-ai-bridge' ), 'primary', 'submit', false ); ?>
		</p>
	</form>
</div>
