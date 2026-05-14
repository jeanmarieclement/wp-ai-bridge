# Connections Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere tab "Connessioni" alla pagina admin WP AI Bridge che mostra l'audit log con filtri e highlight delle righe da altri utenti.

**Architecture:** Tab switching via URL param `?tab=connections`. `render_tools_page()` dispatcha alla view appropriata. Nuova view `connections.php` esegue query JOIN con filtri GET server-side e paginazione 20 righe/pagina.

**Tech Stack:** PHP 7.4+, WordPress Options/DB API (`$wpdb`), HTML/CSS WP admin nativo.

**Spec:** `docs/superpowers/specs/2026-05-14-mcp-connections-tab-design.md`

---

### Task 1: Sistema a tab nella pagina admin

**Files:**
- Modify: `wp-ai-bridge/admin/class-wpaib-admin.php` — dispatch su `$_GET['tab']` in `render_tools_page()`
- Modify: `wp-ai-bridge/admin/views/tools-settings.php` — aggiunge nav tab HTML

- [ ] **Step 1: Modifica `render_tools_page()` per dispatch su tab**

In `admin/class-wpaib-admin.php`, sostituisci il metodo `render_tools_page()`:

```php
public static function render_tools_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Accesso non consentito.', 'wp-ai-bridge' ) );
    }

    $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'tools';

    if ( 'connections' === $tab ) {
        self::render_connections_page();
        return;
    }

    $saved    = isset( $_GET['wpaib_saved'] ) ? (int) $_GET['wpaib_saved'] : 0;
    $disabled = get_option( 'wpaib_disabled_tools', array() );

    $view_file = WPAIB_PLUGIN_DIR . 'admin/views/tools-settings.php';
    if ( file_exists( $view_file ) ) {
        include $view_file;
    }
}
```

- [ ] **Step 2: Aggiungi stub `render_connections_page()`**

Aggiunge metodo alla classe `WPAIB_Admin` (prima della chiusura `}`):

```php
public static function render_connections_page() {
    $view_file = WPAIB_PLUGIN_DIR . 'admin/views/connections.php';
    if ( file_exists( $view_file ) ) {
        include $view_file;
    }
}
```

- [ ] **Step 3: Aggiungi tab nav in `tools-settings.php`**

Inserisci il blocco nav **prima** del `<div class="wrap">` esistente (che diventa il contenuto del tab):

```php
<?php
$current_tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'tools';
$tab_tools_url   = admin_url( 'options-general.php?page=wpaib-tools' );
$tab_conn_url    = admin_url( 'options-general.php?page=wpaib-tools&tab=connections' );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'WP AI Bridge', 'wp-ai-bridge' ); ?></h1>
    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url( $tab_tools_url ); ?>"
           class="nav-tab<?php echo ( 'tools' === $current_tab ) ? ' nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Tool MCP', 'wp-ai-bridge' ); ?>
        </a>
        <a href="<?php echo esc_url( $tab_conn_url ); ?>"
           class="nav-tab<?php echo ( 'connections' === $current_tab ) ? ' nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Connessioni', 'wp-ai-bridge' ); ?>
        </a>
    </nav>
```

Rimuovi il `<div class="wrap">` e il `<h1>` originali dal file (ora sono nel nav). Chiudi il `</div>` del wrap alla fine del file.

- [ ] **Step 4: Verifica visiva**

Aprire `http://localhost:8085/wp-admin/options-general.php?page=wpaib-tools`.
Atteso: due tab "Tool MCP" e "Connessioni". Click su "Connessioni" → pagina vuota (stub). Click su "Tool MCP" → contenuto originale.

- [ ] **Step 5: Commit**

```bash
git add wp-ai-bridge/admin/class-wpaib-admin.php wp-ai-bridge/admin/views/tools-settings.php
git commit -m "feat(admin): add tab navigation to WP AI Bridge settings page"
```

---

### Task 2: Vista connessioni — tabella audit log base

**Files:**
- Create: `wp-ai-bridge/admin/views/connections.php`

- [ ] **Step 1: Crea `connections.php` con query base e tabella**

```php
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

$current_tab   = 'connections';
$tab_tools_url = admin_url( 'options-general.php?page=wpaib-tools' );
$tab_conn_url  = admin_url( 'options-general.php?page=wpaib-tools&tab=connections' );

$per_page = 20;
$paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
$offset   = ( $paged - 1 ) * $per_page;

$table_log  = $wpdb->prefix . 'wpaib_audit_log';
$table_keys = $wpdb->prefix . 'wpaib_api_keys';
$table_user = $wpdb->users;

$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_log}" );

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT l.id, l.timestamp, l.ip, l.endpoint, l.method, l.status_code, l.outcome,
            k.label AS key_label, k.user_id AS key_user_id,
            u.display_name
     FROM {$table_log} l
     LEFT JOIN {$table_keys} k ON l.api_key_id = k.id
     LEFT JOIN {$table_user} u ON k.user_id    = u.ID
     ORDER BY l.timestamp DESC
     LIMIT %d OFFSET %d",
    $per_page,
    $offset
) );

$total_pages = (int) ceil( $total / $per_page );
$current_uid = get_current_user_id();
?>
<div class="wrap">
    <h1><?php esc_html_e( 'WP AI Bridge', 'wp-ai-bridge' ); ?></h1>
    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url( $tab_tools_url ); ?>" class="nav-tab">
            <?php esc_html_e( 'Tool MCP', 'wp-ai-bridge' ); ?>
        </a>
        <a href="<?php echo esc_url( $tab_conn_url ); ?>" class="nav-tab nav-tab-active">
            <?php esc_html_e( 'Connessioni', 'wp-ai-bridge' ); ?>
        </a>
    </nav>

    <style>
        .wpaib-other-user { background-color: #fffbe6 !important; }
        .wpaib-filter-form { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin:1em 0; }
        .wpaib-filter-form label { display:flex; flex-direction:column; font-size:12px; font-weight:600; }
        .wpaib-filter-form input,
        .wpaib-filter-form select { margin-top:2px; }
    </style>

    <?php if ( empty( $rows ) ) : ?>
        <p><?php esc_html_e( 'Nessuna connessione registrata.', 'wp-ai-bridge' ); ?></p>
    <?php else : ?>
        <p><?php printf( esc_html__( '%d connessioni totali.', 'wp-ai-bridge' ), $total ); ?></p>
        <table class="widefat striped" style="margin-top:1em;">
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
                    <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>">
                        &larr; <?php esc_html_e( 'Precedente', 'wp-ai-bridge' ); ?>
                    </a>
                <?php endif; ?>
                <span style="margin:0 8px;">
                    <?php printf( esc_html__( 'Pagina %1$d di %2$d', 'wp-ai-bridge' ), $paged, $total_pages ); ?>
                </span>
                <?php if ( $paged < $total_pages ) : ?>
                    <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>">
                        <?php esc_html_e( 'Successiva', 'wp-ai-bridge' ); ?> &rarr;
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
```

- [ ] **Step 2: Verifica**

Aprire `http://localhost:8085/wp-admin/options-general.php?page=wpaib-tools&tab=connections`.
Atteso: tabella con voci audit log, paginazione se > 20 righe, messaggio vuoto se log è vuoto.

- [ ] **Step 3: Commit**

```bash
git add wp-ai-bridge/admin/views/connections.php
git commit -m "feat(admin): add connections tab with audit log table"
```

---

### Task 3: Filtri server-side

**Files:**
- Modify: `wp-ai-bridge/admin/views/connections.php`

- [ ] **Step 1: Sostituisci il blocco della query con versione filtrata**

Sostituisci il blocco PHP dalla riga `$total = ...` fino a `$current_uid = get_current_user_id();` con:

```php
// Leggi filtri.
$filter_key      = isset( $_GET['filter_key'] )      ? (int) $_GET['filter_key']                            : 0;
$filter_endpoint = isset( $_GET['filter_endpoint'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_endpoint'] ) ) : '';
$filter_outcome  = isset( $_GET['filter_outcome'] )  ? sanitize_key( $_GET['filter_outcome'] )               : '';
$filter_method   = isset( $_GET['filter_method'] )   ? sanitize_key( $_GET['filter_method'] )                : '';
$filter_date_from = isset( $_GET['filter_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_date_from'] ) ) : '';
$filter_date_to   = isset( $_GET['filter_date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['filter_date_to'] ) )   : '';

// Swap silenzioso date invertite.
if ( $filter_date_from && $filter_date_to && $filter_date_from > $filter_date_to ) {
    [ $filter_date_from, $filter_date_to ] = [ $filter_date_to, $filter_date_from ];
}

// Costruisci WHERE.
$where  = 'WHERE 1=1';
$params = [];

if ( $filter_key ) {
    $where   .= ' AND l.api_key_id = %d';
    $params[] = $filter_key;
}
if ( $filter_endpoint ) {
    $where   .= ' AND l.endpoint LIKE %s';
    $params[] = '%' . $wpdb->esc_like( $filter_endpoint ) . '%';
}
if ( $filter_outcome ) {
    $where   .= ' AND l.outcome = %s';
    $params[] = $filter_outcome;
}
if ( $filter_method ) {
    $where   .= ' AND l.method = %s';
    $params[] = strtoupper( $filter_method );
}
if ( $filter_date_from ) {
    $where   .= ' AND l.timestamp >= %s';
    $params[] = $filter_date_from . ' 00:00:00';
}
if ( $filter_date_to ) {
    $where   .= ' AND l.timestamp <= %s';
    $params[] = $filter_date_to . ' 23:59:59';
}

// Count totale con filtri.
$count_sql = "SELECT COUNT(*) FROM {$table_log} l {$where}";
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$total = empty( $params ) ? (int) $wpdb->get_var( $count_sql ) : (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore

// Righe paginate.
$data_params   = array_merge( $params, [ $per_page, $offset ] );
$data_sql      = "SELECT l.id, l.timestamp, l.ip, l.endpoint, l.method, l.status_code, l.outcome,
                         k.label AS key_label, k.user_id AS key_user_id,
                         u.display_name
                  FROM {$table_log} l
                  LEFT JOIN {$table_keys} k ON l.api_key_id = k.id
                  LEFT JOIN {$table_user} u ON k.user_id    = u.ID
                  {$where}
                  ORDER BY l.timestamp DESC
                  LIMIT %d OFFSET %d";
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ) ); // phpcs:ignore

$total_pages = (int) ceil( max( 1, $total ) / $per_page );
$current_uid = get_current_user_id();

// Dati per i select filtro.
$all_keys    = $wpdb->get_results( "SELECT k.id, k.label, u.display_name FROM {$table_keys} k LEFT JOIN {$table_user} u ON k.user_id = u.ID ORDER BY u.display_name, k.label" );
$all_outcomes = $wpdb->get_col( "SELECT DISTINCT outcome FROM {$table_log} WHERE outcome != '' ORDER BY outcome" );
```

- [ ] **Step 2: Aggiungi form filtri prima della tabella**

Inserisci dopo `<nav class="nav-tab-wrapper">...</nav>` e prima del blocco `<?php if ( empty( $rows ) ) :`:

```php
<form method="get" action="" class="wpaib-filter-form">
    <input type="hidden" name="page" value="wpaib-tools">
    <input type="hidden" name="tab"  value="connections">

    <label>
        <?php esc_html_e( 'Utente / Chiave', 'wp-ai-bridge' ); ?>
        <select name="filter_key">
            <option value=""><?php esc_html_e( '— Tutte —', 'wp-ai-bridge' ); ?></option>
            <?php foreach ( $all_keys as $k ) : ?>
                <option value="<?php echo esc_attr( $k->id ); ?>"
                    <?php selected( $filter_key, $k->id ); ?>>
                    <?php echo esc_html( ( $k->display_name ?: '?' ) . ' — ' . ( $k->label ?: 'senza etichetta' ) ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        <?php esc_html_e( 'Endpoint', 'wp-ai-bridge' ); ?>
        <input type="text" name="filter_endpoint" value="<?php echo esc_attr( $filter_endpoint ); ?>" placeholder="/wpaib/v1/..." style="width:180px;">
    </label>

    <label>
        <?php esc_html_e( 'Outcome', 'wp-ai-bridge' ); ?>
        <select name="filter_outcome">
            <option value=""><?php esc_html_e( '— Tutti —', 'wp-ai-bridge' ); ?></option>
            <?php foreach ( $all_outcomes as $oc ) : ?>
                <option value="<?php echo esc_attr( $oc ); ?>" <?php selected( $filter_outcome, $oc ); ?>>
                    <?php echo esc_html( $oc ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        <?php esc_html_e( 'Metodo', 'wp-ai-bridge' ); ?>
        <select name="filter_method">
            <option value=""><?php esc_html_e( '— Tutti —', 'wp-ai-bridge' ); ?></option>
            <?php foreach ( [ 'GET', 'POST', 'PUT', 'DELETE', 'PATCH' ] as $m ) : ?>
                <option value="<?php echo esc_attr( $m ); ?>" <?php selected( strtoupper( $filter_method ), $m ); ?>>
                    <?php echo esc_html( $m ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        <?php esc_html_e( 'Da', 'wp-ai-bridge' ); ?>
        <input type="date" name="filter_date_from" value="<?php echo esc_attr( $filter_date_from ); ?>">
    </label>

    <label>
        <?php esc_html_e( 'A', 'wp-ai-bridge' ); ?>
        <input type="date" name="filter_date_to" value="<?php echo esc_attr( $filter_date_to ); ?>">
    </label>

    <?php submit_button( __( 'Filtra', 'wp-ai-bridge' ), 'secondary', 'filter_submit', false ); ?>

    <?php if ( $filter_key || $filter_endpoint || $filter_outcome || $filter_method || $filter_date_from || $filter_date_to ) : ?>
        <a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=wpaib-tools&tab=connections' ) ); ?>">
            <?php esc_html_e( 'Reset', 'wp-ai-bridge' ); ?>
        </a>
    <?php endif; ?>
</form>
```

- [ ] **Step 3: Aggiorna i link di paginazione per preservare i filtri**

Sostituisci `add_query_arg( 'paged', $paged - 1 )` con:

```php
add_query_arg( array_merge(
    array_filter( [
        'page'            => 'wpaib-tools',
        'tab'             => 'connections',
        'filter_key'      => $filter_key ?: null,
        'filter_endpoint' => $filter_endpoint ?: null,
        'filter_outcome'  => $filter_outcome ?: null,
        'filter_method'   => $filter_method ?: null,
        'filter_date_from'=> $filter_date_from ?: null,
        'filter_date_to'  => $filter_date_to ?: null,
    ] ),
    [ 'paged' => $paged - 1 ]
) )
```

Stessa cosa per il link "Successiva" con `$paged + 1`.

- [ ] **Step 4: Verifica filtri**

Per ogni filtro:
1. Seleziona un valore → submit → tabella mostra solo righe corrispondenti
2. Click "Reset" → tutti i filtri azzerati, tabella completa
3. Filtro endpoint con testo parziale → LIKE funziona
4. Date invertite → risultati corretti (swap silenzioso)

- [ ] **Step 5: Commit**

```bash
git add wp-ai-bridge/admin/views/connections.php
git commit -m "feat(admin): add server-side filters to connections tab"
```

---

### Task 4: Verifica highlight e edge cases

**Files:**
- No file changes — verifica comportamento già implementato nei task precedenti.

- [ ] **Step 1: Verifica highlight altra chiave**

1. Crea API key per un secondo utente WP (o usa una chiave esistente di altro user)
2. Fai una richiesta API con quella chiave: `curl -H "X-API-Key: <altra_chiave>" http://localhost:8085/wp-json/wpaib/v1/tools`
3. Apri tab Connessioni → riga deve avere sfondo giallo (`#fffbe6`)
4. Riga da propria chiave → nessun sfondo speciale

- [ ] **Step 2: Verifica key orfana**

```sql
-- In phpMyAdmin (http://localhost:8088): inserisci riga con api_key_id inesistente
INSERT INTO wp_wpaib_audit_log (timestamp, api_key_id, ip, user_agent, endpoint, method, status_code, outcome)
VALUES (NOW(), 99999, '127.0.0.1', 'test', '/wpaib/v1/test', 'GET', 404, 'not_found');
```

Atteso: riga in tabella con Utente "—" e Chiave "—", nessun PHP warning.

- [ ] **Step 3: Verifica log vuoto**

Svuota tabella temporaneamente (`TRUNCATE wp_wpaib_audit_log`) → tab mostra "Nessuna connessione registrata." senza errori PHP.  
Ripristina con `git checkout` o re-insert se necessario.

- [ ] **Step 4: Commit (se necessario)**

Se sono emerse correzioni nei passi precedenti:

```bash
git add wp-ai-bridge/admin/views/connections.php
git commit -m "fix(admin): handle orphan keys and empty log in connections tab"
```
