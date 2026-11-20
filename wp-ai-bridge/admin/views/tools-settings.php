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

$categories     = WPAIB_Admin::get_tool_categories();
$all_tools      = WPAIB_Admin::get_all_tool_slugs();
$total_tools    = count( $all_tools );
$disabled_tools = is_array( $disabled ) ? $disabled : array();
$enabled_tools  = array_diff( $all_tools, $disabled_tools );
$selected_count = count( $enabled_tools );

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
		<a href="<?php echo esc_url( $tab_oauth_url ); ?>" class="nav-tab">
			<?php esc_html_e( 'OAuth2 Clients', 'wp-ai-bridge' ); ?>
		</a>
		<a href="<?php echo esc_url( $tab_conn_url ); ?>"
		   class="nav-tab">
			<?php esc_html_e( 'Connessioni', 'wp-ai-bridge' ); ?>
		</a>
	</nav>

	<p style="margin-top:1em;"><?php esc_html_e( 'Seleziona i tool che vuoi esporre tramite le API. I tool non selezionati vengono rimossi dall\'elenco MCP, bloccati in esecuzione ed esclusi dallo schema OpenAPI.', 'wp-ai-bridge' ); ?></p>

	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Impostazioni salvate con successo.', 'wp-ai-bridge' ); ?></p>
		</div>
	<?php endif; ?>

	<div id="wpaib-tools-summary-box" style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #2271b1; padding:12px 18px; margin:1.2em 0; max-width:760px; border-radius:3px;">
		<div style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:12px;">
			<div>
				<strong style="font-size:14px;"><?php esc_html_e( 'Tool selezionati:', 'wp-ai-bridge' ); ?></strong>
				<span style="font-size:14px; font-weight:600; margin-left:4px;">
					<span id="wpaib-selected-count"><?php echo (int) $selected_count; ?></span>/<span id="wpaib-total-count"><?php echo (int) $total_tools; ?></span>
				</span>
				<span id="wpaib-chatgpt-badge" style="margin-left:12px; display:inline-block;"></span>
			</div>
			<div class="wpaib-quick-actions">
				<button type="button" id="wpaib-btn-select-all" class="button button-secondary"><?php esc_html_e( 'Seleziona tutti', 'wp-ai-bridge' ); ?></button>
				<button type="button" id="wpaib-btn-deselect-all" class="button button-secondary" style="margin-left:4px;"><?php esc_html_e( 'Deseleziona tutti', 'wp-ai-bridge' ); ?></button>
			</div>
		</div>
		<div id="wpaib-chatgpt-notice" style="margin-top:8px; font-size:12px; color:#50575e; display:none;">
			<?php esc_html_e( 'Nota ChatGPT Actions (Custom GPT): OpenAI consente fino a 30 operazioni per schema. Selezionando 30 o meno tool, il file openapi.json potrà essere importato direttamente.', 'wp-ai-bridge' ); ?>
		</div>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wpaib_save_tools">
		<?php wp_nonce_field( 'wpaib_save_tools' ); ?>

		<table class="widefat striped" style="max-width:760px; margin-top:1em;">
			<thead>
				<tr>
					<th style="width:40px; text-align:center;">
						<input type="checkbox" id="wpaib-toggle-all" title="<?php esc_attr_e( 'Inverti selezione globale', 'wp-ai-bridge' ); ?>">
					</th>
					<th><?php esc_html_e( 'Tool', 'wp-ai-bridge' ); ?></th>
					<th><?php esc_html_e( 'Categoria', 'wp-ai-bridge' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $categories as $cat_label => $tools ) : ?>
					<?php
					$cat_slug = sanitize_title( $cat_label );
					?>
					<tr style="background:#f6f7f7; font-weight:600; border-top:1px solid #dcdcde;">
						<td style="text-align:center;">
							<input
								type="checkbox"
								class="wpaib-cat-toggle"
								data-category="<?php echo esc_attr( $cat_slug ); ?>"
								title="<?php /* translators: %s: Nome della categoria di tool */ echo esc_attr( sprintf( __( 'Seleziona/Deseleziona tutti in %s', 'wp-ai-bridge' ), $cat_label ) ); ?>"
							>
						</td>
						<td colspan="2">
							<strong><?php echo esc_html( $cat_label ); ?></strong>
							<span style="font-size:11px; color:#646970; font-weight:normal; margin-left:6px;">
								(<?php echo count( $tools ); ?> tool)
							</span>
						</td>
					</tr>
					<?php foreach ( $tools as $tool_slug ) : ?>
						<?php $checked = ! in_array( $tool_slug, $disabled_tools, true ); ?>
						<tr>
							<td style="text-align:center;">
								<input
									type="checkbox"
									name="wpaib_tools[]"
									value="<?php echo esc_attr( $tool_slug ); ?>"
									class="wpaib-tool-cb"
									data-category="<?php echo esc_attr( $cat_slug ); ?>"
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

<script>
(function() {
	function updateUI() {
		var toolCheckboxes = document.querySelectorAll('.wpaib-tool-cb');
		var selectedCount = 0;
		var totalCount = toolCheckboxes.length;

		toolCheckboxes.forEach(function(cb) {
			if (cb.checked) {
				selectedCount++;
			}
		});

		var countEl = document.getElementById('wpaib-selected-count');
		if (countEl) {
			countEl.textContent = selectedCount;
		}

		var badgeEl = document.getElementById('wpaib-chatgpt-badge');
		var noticeEl = document.getElementById('wpaib-chatgpt-notice');
		var summaryBox = document.getElementById('wpaib-tools-summary-box');

		if (badgeEl) {
			if (selectedCount <= 30) {
				badgeEl.innerHTML = '<span style="background:#d1e7dd; color:#0f5132; padding:3px 9px; border-radius:12px; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">✓ Compatibile con ChatGPT Actions (max 30)</span>';
				if (noticeEl) {
					noticeEl.style.display = 'block';
					noticeEl.style.color = '#0f5132';
					noticeEl.textContent = 'Ottimo: con ' + selectedCount + ' tool selezionati, lo schema openapi.json rispetta il limite massimo di 30 operazioni di ChatGPT Actions.';
				}
				if (summaryBox) {
					summaryBox.style.borderLeftColor = '#00a32a';
				}
			} else {
				var diff = selectedCount - 30;
				badgeEl.innerHTML = '<span style="background:#fff3cd; color:#664d03; padding:3px 9px; border-radius:12px; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">⚠️ Supera il limite ChatGPT (' + selectedCount + '/30)</span>';
				if (noticeEl) {
					noticeEl.style.display = 'block';
					noticeEl.style.color = '#8a6d3b';
					noticeEl.textContent = 'Attenzione: hai ' + selectedCount + ' tool selezionati. ChatGPT Actions ammette al massimo 30 operazioni per schema. Deseleziona almeno ' + diff + ' tool non necessari se desideri importare openapi.json in un Custom GPT.';
				}
				if (summaryBox) {
					summaryBox.style.borderLeftColor = '#dba617';
				}
			}
		}

		// Aggiorna stato checkbox categorie
		var catToggles = document.querySelectorAll('.wpaib-cat-toggle');
		catToggles.forEach(function(catToggle) {
			var catSlug = catToggle.getAttribute('data-category');
			var catCbs = document.querySelectorAll('.wpaib-tool-cb[data-category="' + catSlug + '"]');
			var catChecked = 0;
			catCbs.forEach(function(cb) {
				if (cb.checked) {
					catChecked++;
				}
			});
			if (catChecked === 0) {
				catToggle.checked = false;
				catToggle.indeterminate = false;
			} else if (catChecked === catCbs.length) {
				catToggle.checked = true;
				catToggle.indeterminate = false;
			} else {
				catToggle.checked = false;
				catToggle.indeterminate = true;
			}
		});

		// Aggiorna checkbox globale
		var globalToggle = document.getElementById('wpaib-toggle-all');
		if (globalToggle) {
			if (selectedCount === 0) {
				globalToggle.checked = false;
				globalToggle.indeterminate = false;
			} else if (selectedCount === totalCount) {
				globalToggle.checked = true;
				globalToggle.indeterminate = false;
			} else {
				globalToggle.checked = false;
				globalToggle.indeterminate = true;
			}
		}
	}

	// Listener per cambi su singoli tool
	document.addEventListener('change', function(e) {
		if (e.target && e.target.classList.contains('wpaib-tool-cb')) {
			updateUI();
		}
	});

	// Toggle di categoria
	document.addEventListener('change', function(e) {
		if (e.target && e.target.classList.contains('wpaib-cat-toggle')) {
			var catSlug = e.target.getAttribute('data-category');
			var isChecked = e.target.checked;
			var catCbs = document.querySelectorAll('.wpaib-tool-cb[data-category="' + catSlug + '"]');
			catCbs.forEach(function(cb) {
				cb.checked = isChecked;
			});
			updateUI();
		}
	});

	// Toggle globale
	var globalToggle = document.getElementById('wpaib-toggle-all');
	if (globalToggle) {
		globalToggle.addEventListener('change', function() {
			var isChecked = this.checked;
			document.querySelectorAll('.wpaib-tool-cb').forEach(function(cb) {
				cb.checked = isChecked;
			});
			updateUI();
		});
	}

	// Pulsante Seleziona tutti
	var btnSelectAll = document.getElementById('wpaib-btn-select-all');
	if (btnSelectAll) {
		btnSelectAll.addEventListener('click', function() {
			document.querySelectorAll('.wpaib-tool-cb').forEach(function(cb) {
				cb.checked = true;
			});
			updateUI();
		});
	}

	// Pulsante Deseleziona tutti
	var btnDeselectAll = document.getElementById('wpaib-btn-deselect-all');
	if (btnDeselectAll) {
		btnDeselectAll.addEventListener('click', function() {
			document.querySelectorAll('.wpaib-tool-cb').forEach(function(cb) {
				cb.checked = false;
			});
			updateUI();
		});
	}

	// Inizializza UI
	updateUI();
})();
</script>
