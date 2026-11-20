<?php
/**
 * Vista: tab Connessioni — audit log.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$tab_tools_url = admin_url( 'options-general.php?page=wpaib-tools' );
$tab_conn_url  = admin_url( 'options-general.php?page=wpaib-tools&tab=connections' );
$tab_oauth_url = admin_url( 'options-general.php?page=wpaib-tools&tab=oauth' );

$per_page = 20;
$paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
$offset   = ( $paged - 1 ) * $per_page;

$table_log  = $wpdb->prefix . 'wpaib_audit_log';
$table_keys = $wpdb->prefix . 'wpaib_api_keys';
$table_user = $wpdb->users;

// Leggi e sanifica filtri.
$filter_key       = isset( $_GET['filter_key'] )       ? (int) $_GET['filter_key']                                   : 0;
$filter_endpoint  = isset( $_GET['filter_endpoint'] )  ? sanitize_text_field( wp_unslash( $_GET['filter_endpoint'] ) ) : '';
$filter_outcome   = isset( $_GET['filter_outcome'] )   ? sanitize_key( $_GET['filter_outcome'] )                       : '';
$allowed_methods  = array( 'GET', 'POST', 'PUT', 'DELETE', 'PATCH' );
$filter_method    = strtoupper( sanitize_text_field( wp_unslash( isset( $_GET['filter_method'] ) ? $_GET['filter_method'] : '' ) ) );
$filter_method    = in_array( $filter_method, $allowed_methods, true ) ? $filter_method : '';
$filter_date_from = isset( $_GET['filter_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_date_from'] ) ) : '';
$filter_date_to   = isset( $_GET['filter_date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['filter_date_to'] ) )   : '';

// Valida formato YYYY-MM-DD; scarta se malformato.
$filter_date_from = ( $filter_date_from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_date_from ) ) ? $filter_date_from : '';
$filter_date_to   = ( $filter_date_to   && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_date_to )   ) ? $filter_date_to   : '';

// Swap silenzioso se le date sono invertite.
if ( $filter_date_from && $filter_date_to && $filter_date_from > $filter_date_to ) {
	list( $filter_date_from, $filter_date_to ) = array( $filter_date_to, $filter_date_from );
}

// Filtri in un'unica query statica: ogni condizione si disattiva quando il
// filtro è vuoto, così tutto passa da un solo $wpdb->prepare().
$endpoint_like = $filter_endpoint ? '%' . $wpdb->esc_like( $filter_endpoint ) . '%' : '';
$date_from     = ( $filter_date_from ? $filter_date_from : '1000-01-01' ) . ' 00:00:00';
$date_to       = ( $filter_date_to ? $filter_date_to : '9999-12-31' ) . ' 23:59:59';

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom audit table, admin-only view.
$total = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM %i l
		 WHERE ( %d = 0 OR l.api_key_id = %d )
		   AND ( %s = '' OR l.endpoint LIKE %s )
		   AND ( %s = '' OR l.outcome = %s )
		   AND ( %s = '' OR l.method = %s )
		   AND l.timestamp BETWEEN %s AND %s",
		$table_log,
		$filter_key,
		$filter_key,
		$endpoint_like,
		$endpoint_like,
		$filter_outcome,
		$filter_outcome,
		$filter_method,
		$filter_method,
		$date_from,
		$date_to
	)
);

$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT l.id, l.timestamp, l.ip, l.endpoint, l.method, l.status_code, l.outcome,
		        k.label AS key_label, k.user_id AS key_user_id,
		        u.display_name
		 FROM %i l
		 LEFT JOIN %i k ON l.api_key_id = k.id
		 LEFT JOIN %i u ON k.user_id = u.ID
		 WHERE ( %d = 0 OR l.api_key_id = %d )
		   AND ( %s = '' OR l.endpoint LIKE %s )
		   AND ( %s = '' OR l.outcome = %s )
		   AND ( %s = '' OR l.method = %s )
		   AND l.timestamp BETWEEN %s AND %s
		 ORDER BY l.timestamp DESC
		 LIMIT %d OFFSET %d",
		$table_log,
		$table_keys,
		$table_user,
		$filter_key,
		$filter_key,
		$endpoint_like,
		$endpoint_like,
		$filter_outcome,
		$filter_outcome,
		$filter_method,
		$filter_method,
		$date_from,
		$date_to,
		$per_page,
		$offset
	)
);

$total_pages = max( 1, (int) ceil( $total / $per_page ) );
$current_uid = get_current_user_id();

// Dati per i select del form filtri.
$all_keys = $wpdb->get_results(
	$wpdb->prepare(
		'SELECT k.id, k.label, u.display_name
		 FROM %i k
		 LEFT JOIN %i u ON k.user_id = u.ID
		 ORDER BY u.display_name, k.label',
		$table_keys,
		$table_user
	)
);
$all_outcomes = $wpdb->get_col(
	$wpdb->prepare( "SELECT DISTINCT outcome FROM %i WHERE outcome != '' ORDER BY outcome", $table_log )
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery
?>
<div class="wrap">
	<h1><?php esc_html_e( 'WP AI Bridge', 'wp-ai-bridge' ); ?></h1>
	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $tab_tools_url ); ?>" class="nav-tab">
			<?php esc_html_e( 'Tool MCP', 'wp-ai-bridge' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_oauth_url ); ?>" class="nav-tab">
			<?php esc_html_e( 'OAuth2 Clients', 'wp-ai-bridge' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_conn_url ); ?>" class="nav-tab nav-tab-active">
			<?php esc_html_e( 'Connessioni', 'wp-ai-bridge' ); ?>
		</a>
	</nav>

	<form method="get" action="" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin:1em 0;">
		<input type="hidden" name="page" value="wpaib-tools">
		<input type="hidden" name="tab"  value="connections">

		<label style="display:flex;flex-direction:column;font-size:12px;font-weight:600;">
			<?php esc_html_e( 'Utente / Chiave', 'wp-ai-bridge' ); ?>
			<select name="filter_key" style="margin-top:2px;">
				<option value=""><?php esc_html_e( '— Tutte —', 'wp-ai-bridge' ); ?></option>
				<?php foreach ( $all_keys as $k ) : ?>
					<option value="<?php echo esc_attr( $k->id ); ?>"
						<?php selected( $filter_key, $k->id ); ?>>
						<?php echo esc_html( ( $k->display_name ?: '?' ) . ' — ' . ( $k->label ?: esc_html__( 'senza etichetta', 'wp-ai-bridge' ) ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label style="display:flex;flex-direction:column;font-size:12px;font-weight:600;">
			<?php esc_html_e( 'Endpoint', 'wp-ai-bridge' ); ?>
			<input type="text" name="filter_endpoint" value="<?php echo esc_attr( $filter_endpoint ); ?>"
				placeholder="/wpaib/v1/..." style="margin-top:2px;width:180px;">
		</label>

		<label style="display:flex;flex-direction:column;font-size:12px;font-weight:600;">
			<?php esc_html_e( 'Outcome', 'wp-ai-bridge' ); ?>
			<select name="filter_outcome" style="margin-top:2px;">
				<option value=""><?php esc_html_e( '— Tutti —', 'wp-ai-bridge' ); ?></option>
				<?php foreach ( $all_outcomes as $oc ) : ?>
					<option value="<?php echo esc_attr( $oc ); ?>" <?php selected( $filter_outcome, $oc ); ?>>
						<?php echo esc_html( $oc ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label style="display:flex;flex-direction:column;font-size:12px;font-weight:600;">
			<?php esc_html_e( 'Metodo', 'wp-ai-bridge' ); ?>
			<select name="filter_method" style="margin-top:2px;">
				<option value=""><?php esc_html_e( '— Tutti —', 'wp-ai-bridge' ); ?></option>
				<?php foreach ( array( 'GET', 'POST', 'PUT', 'DELETE', 'PATCH' ) as $m ) : ?>
					<option value="<?php echo esc_attr( $m ); ?>" <?php selected( strtoupper( $filter_method ), $m ); ?>>
						<?php echo esc_html( $m ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<label style="display:flex;flex-direction:column;font-size:12px;font-weight:600;">
			<?php esc_html_e( 'Da', 'wp-ai-bridge' ); ?>
			<input type="date" name="filter_date_from" value="<?php echo esc_attr( $filter_date_from ); ?>" style="margin-top:2px;">
		</label>

		<label style="display:flex;flex-direction:column;font-size:12px;font-weight:600;">
			<?php esc_html_e( 'A', 'wp-ai-bridge' ); ?>
			<input type="date" name="filter_date_to" value="<?php echo esc_attr( $filter_date_to ); ?>" style="margin-top:2px;">
		</label>

		<?php submit_button( __( 'Filtra', 'wp-ai-bridge' ), 'secondary', 'filter_submit', false ); ?>

		<?php if ( $filter_key || $filter_endpoint || $filter_outcome || $filter_method || $filter_date_from || $filter_date_to ) : ?>
			<a class="button" href="<?php echo esc_url( $tab_conn_url ); ?>">
				<?php esc_html_e( 'Reset', 'wp-ai-bridge' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $rows ) ) : ?>
		<p style="margin-top:1em;"><?php esc_html_e( 'Nessuna connessione registrata.', 'wp-ai-bridge' ); ?></p>
	<?php else : ?>
		<p style="margin-top:1em;">
			<?php
			/* translators: %d: number of connections */
			printf( esc_html__( '%d connessioni totali.', 'wp-ai-bridge' ), (int) $total );
			?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Timestamp', 'wp-ai-bridge' ); ?></th>
					<th><?php esc_html_e( 'Utente', 'wp-ai-bridge' ); ?></th>
					<th><?php esc_html_e( 'Chiave', 'wp-ai-bridge' ); ?></th>
					<th><?php esc_html_e( 'Endpoint', 'wp-ai-bridge' ); ?></th>
					<th><?php esc_html_e( 'Metodo', 'wp-ai-bridge' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-ai-bridge' ); ?></th>
					<th><?php esc_html_e( 'Outcome', 'wp-ai-bridge' ); ?></th>
					<th><?php esc_html_e( 'IP', 'wp-ai-bridge' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $is_other = ( ! empty( $row->key_user_id ) && (int) $row->key_user_id !== $current_uid ); ?>
					<tr<?php echo $is_other ? ' class="wpaib-other-user"' : ''; ?>>
						<td><?php echo esc_html( $row->timestamp ); ?></td>
						<td><?php echo esc_html( $row->display_name ?: '—' ); ?></td>
						<td><?php echo esc_html( $row->key_label ?: '—' ); ?></td>
						<td><code><?php echo esc_html( $row->endpoint ); ?></code></td>
						<td><?php echo esc_html( $row->method ); ?></td>
						<td><?php echo esc_html( $row->status_code ); ?></td>
						<td><?php echo esc_html( $row->outcome ); ?></td>
						<td><?php echo esc_html( $row->ip ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div style="margin-top:1em;">
				<?php if ( $paged > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array_filter( array(
						'page'             => 'wpaib-tools',
						'tab'              => 'connections',
						'filter_key'       => $filter_key > 0 ? $filter_key : null,
						'filter_endpoint'  => $filter_endpoint ?: null,
						'filter_outcome'   => $filter_outcome ?: null,
						'filter_method'    => $filter_method ?: null,
						'filter_date_from' => $filter_date_from ?: null,
						'filter_date_to'   => $filter_date_to ?: null,
						'paged'            => $paged - 1,
					) ) ) ); ?>">
						&larr; <?php esc_html_e( 'Precedente', 'wp-ai-bridge' ); ?>
					</a>
				<?php endif; ?>
				<span style="margin:0 8px;">
					<?php
					printf(
						/* translators: 1: current page, 2: total pages */
						esc_html__( 'Pagina %1$d di %2$d', 'wp-ai-bridge' ),
						(int) $paged,
						(int) $total_pages
					);
					?>
				</span>
				<?php if ( $paged < $total_pages ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array_filter( array(
						'page'             => 'wpaib-tools',
						'tab'              => 'connections',
						'filter_key'       => $filter_key > 0 ? $filter_key : null,
						'filter_endpoint'  => $filter_endpoint ?: null,
						'filter_outcome'   => $filter_outcome ?: null,
						'filter_method'    => $filter_method ?: null,
						'filter_date_from' => $filter_date_from ?: null,
						'filter_date_to'   => $filter_date_to ?: null,
						'paged'            => $paged + 1,
					) ) ) ); ?>">
						<?php esc_html_e( 'Successiva', 'wp-ai-bridge' ); ?> &rarr;
					</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
