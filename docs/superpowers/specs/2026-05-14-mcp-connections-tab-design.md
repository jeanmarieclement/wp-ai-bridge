# Design: Tab "Connessioni" nella pagina admin WP AI Bridge

**Data:** 2026-05-14  
**Stato:** Approvato

---

## Contesto

La pagina `Impostazioni > WP AI Bridge` mostra attualmente solo la gestione dei tool MCP (abilitazione/disabilitazione). L'utente vuole una seconda scheda che mostri le voci dell'audit log (`wp_wpaib_audit_log`) con filtri e con evidenziazione delle righe provenienti da API key di altri utenti WP.

---

## Architettura

Tab switching via URL param: `?page=wpaib-tools&tab=connections`.  
`WPAIB_Admin::render_tools_page()` legge `$_GET['tab']` e dispatcha alla view appropriata.  
Il nav tab HTML è incluso in entrambe le view.

### File modificati / creati

| File | Azione |
|------|--------|
| `admin/class-wpaib-admin.php` | `render_tools_page()` dispatcha su tab; nuovo metodo `render_connections_page()` con query |
| `admin/views/tools-settings.php` | Aggiunge tab nav HTML in cima |
| `admin/views/connections.php` | Nuovo: form filtri + tabella audit log paginata |

---

## Dati

### Query base

```sql
SELECT
    l.id, l.timestamp, l.ip, l.user_agent,
    l.endpoint, l.method, l.status_code, l.outcome,
    k.label   AS key_label,
    k.user_id AS key_user_id,
    u.display_name
FROM wp_wpaib_audit_log l
LEFT JOIN wp_wpaib_api_keys k ON l.api_key_id = k.id
LEFT JOIN wp_users          u ON k.user_id    = u.ID
WHERE 1=1
  [+ filtri via $wpdb->prepare()]
ORDER BY l.timestamp DESC
LIMIT 20 OFFSET %d
```

Tutti i valori dei filtri passano via `$wpdb->prepare()` — nessuna interpolazione diretta.

### Colonne tabella

| Colonna | Fonte |
|---------|-------|
| Timestamp | `audit_log.timestamp` |
| Utente | `wp_users.display_name` (o "—" se key orfana/NULL) |
| Chiave | `api_keys.label` (o "—") |
| Endpoint | `audit_log.endpoint` |
| Metodo | `audit_log.method` |
| Status | `audit_log.status_code` |
| Outcome | `audit_log.outcome` |
| IP | `audit_log.ip` |

---

## Filtri

Form GET (server-side), tutti opzionali:

| Filtro | UI | WHERE clause |
|--------|----|--------------|
| Utente/chiave | `<select>` — lista da `api_keys JOIN users` | `l.api_key_id = %d` |
| Endpoint | `<input type="text">` | `l.endpoint LIKE %s` |
| Outcome | `<select>` — valori distinti da DB | `l.outcome = %s` |
| Data da | `<input type="date">` | `l.timestamp >= %s` |
| Data a | `<input type="date">` | `l.timestamp <= %s` |
| Metodo HTTP | `<select>` GET/POST/PUT/DELETE/PATCH | `l.method = %s` |

---

## Highlight

Righe con `key_user_id != get_current_user_id()` ricevono `<tr class="wpaib-other-user">`.  
CSS inline: `background-color: #fffbe6` (giallo tenue).

---

## Edge cases

- Log vuoto → messaggio "Nessuna connessione registrata"
- `api_key_id` NULL (richiesta anonima fallita) → Utente "—", Chiave "—"
- Key orfana (chiave eliminata) → LEFT JOIN restituisce NULL, mostra "—"
- Date from > to → swap silenzioso lato PHP prima della query

---

## Paginazione

20 righe per pagina. Link prev/next via `?paged=N`. COUNT(*) separata per calcolare total pages.

---

## Verifica end-to-end

1. `http://localhost:8085/wp-admin/options-general.php?page=wpaib-tools` — tab "Connessioni" visibile
2. Fare richieste API → ricaricare → voci compaiono in tabella
3. Applicare ogni filtro singolarmente → risultati filtrati correttamente
4. Riga da altra API key → sfondo giallo
5. Log vuoto → messaggio vuoto (non errore PHP)
6. Paginazione → pagina 2+ funziona
