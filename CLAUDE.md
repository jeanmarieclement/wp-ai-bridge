# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Environment

```bash
# Avvia WordPress + MySQL + phpMyAdmin
docker compose up -d

# Ferma e rimuovi container
docker compose down
```

- WordPress: http://localhost:8085
- phpMyAdmin: http://localhost:8088 (root/root)
- La cartella `wp-ai-bridge/` è montata direttamente come plugin attivo

La variabile `WPAIB_KEY` in `.env.local` contiene una API key di test pronta all'uso.

## API REST

Base URL: `http://localhost:8085/wp-json/wpaib/v1/`  
Auth header: `X-API-Key: <chiave>` oppure `Authorization: Bearer wpaib_at_...`

Endpoint disponibili:
- `GET/POST /posts`, `GET/POST/DELETE /posts/{id}`
- `GET/POST /pages`, `GET/POST/DELETE /pages/{id}`
- `GET/POST /media` (upload base64, max 5 MB, tipi: jpg/png/gif/webp)
- `GET/POST /categories`, `GET/POST /tags`
- `GET /comments`, `GET/POST /posts/{id}/comments`, `POST /comments/{id}`, `POST /comments/bulk`
- `GET /cpt` (elenco CPT registrati), `GET/POST /cpt/{type}`, `GET/POST/DELETE /cpt/{type}/{id}`
- `GET /users`, `GET /users/{id}` (sola lettura, `list_users`)
- `GET /menus`, `GET /theme` (sola lettura, `edit_theme_options`)
- `GET /site`, `GET /site/full` (sola lettura, `manage_options` per `/site/full`)
- `GET /tools`, `POST /tools/execute` (MCP / function calling)
- `GET /openapi.json` (pubblico, no auth — per import in ChatGPT/Gemini/Claude)
- `GET/POST /wpaib/oauth/authorize` (browser — pagina consenso OAuth2, rewrite rule WP)
- `POST /oauth/token` (scambio code → token, refresh)
- `POST /oauth/revoke` (revoca access o refresh token)

## Architettura

Plugin PHP puro, zero dipendenze esterne (no Composer). Autoloader manuale in `wp-ai-bridge.php`.

**Flusso di una richiesta REST:**
1. `WPAIB_Plugin::enforce_https()` — rifiuta HTTP (escluso localhost/development)
2. `WPAIB_Auth::authorize()` — controlla prima `Authorization: Bearer` (OAuth2), poi `X-API-Key`
3. Rate limit → hash SHA-256 → capability WP
4. Controller specifico gestisce la logica

**Flusso OAuth2 (Authorization Code):**
1. Client → `GET /wpaib/oauth/authorize?response_type=code&client_id=...&redirect_uri=...&state=...`
2. WP login (se non autenticato) → pagina consenso
3. Utente clicca Autorizza → `POST /wpaib/oauth/authorize` → redirect con `code=wpaib_ac_...`
4. Client → `POST /oauth/token` con il code → riceve `access_token` + `refresh_token`
5. Client usa `Authorization: Bearer wpaib_at_...` su ogni endpoint

**Classi principali:**
| File | Classe | Responsabilità |
|------|--------|----------------|
| `includes/class-wpaib-auth.php` | `WPAIB_Auth` | Middleware auth: Bearer (OAuth2) poi API Key |
| `includes/class-wpaib-api-key-manager.php` | `WPAIB_API_Key_Manager` | Generazione, validazione (hash), revoca chiavi |
| `includes/class-wpaib-rate-limiter.php` | `WPAIB_Rate_Limiter` | 300 req/min via transient WP, keyed sull'hash; espone i secondi per `Retry-After` |
| `includes/class-wpaib-rest-helper.php` | `WPAIB_Rest_Helper` | Paginazione a cursore `after_id` e rendering di `the_content` |
| `includes/class-wpaib-logger.php` | `WPAIB_Logger` | Audit log su `wp_wpaib_audit_log` |
| `includes/class-wpaib-installer.php` | `WPAIB_Installer` | `dbDelta()` per le 5 tabelle, rewrite rule OAuth2 |
| `includes/class-wpaib-oauth-client-manager.php` | `WPAIB_OAuth_Client_Manager` | CRUD client OAuth2 (client_id, secret hash, redirect_uris) |
| `includes/class-wpaib-oauth-server.php` | `WPAIB_OAuth_Server` | Codici auth, token pair, refresh, revoca, cleanup |
| `includes/class-wpaib-oauth-authorize.php` | `WPAIB_OAuth_Authorize` | Rewrite rule + template_redirect per pagina consenso |
| `includes/endpoints/class-wpaib-oauth-controller.php` | `WPAIB_OAuth_Controller` | REST: POST /oauth/token e POST /oauth/revoke |
| `includes/endpoints/class-wpaib-posts-controller.php` | `WPAIB_Posts_Controller` | CRUD articoli |
| `includes/endpoints/class-wpaib-media-controller.php` | `WPAIB_Media_Controller` | Upload immagini |
| `includes/endpoints/class-wpaib-taxonomy-controller.php` | `WPAIB_Taxonomy_Controller` | Categorie e tag |
| `includes/endpoints/class-wpaib-users-controller.php` | `WPAIB_Users_Controller` | `/users` in sola lettura (mai password né hash) |
| `includes/endpoints/class-wpaib-appearance-controller.php` | `WPAIB_Appearance_Controller` | `/menus` e `/theme` in sola lettura |
| `includes/endpoints/class-wpaib-site-controller.php` | `WPAIB_Site_Controller` | `/site`, `/site/full` e generazione di `site_uuid` |
| `includes/endpoints/class-wpaib-mcp-controller.php` | `WPAIB_MCP_Controller` | Endpoint `/tools` e `/tools/execute` |
| `includes/endpoints/class-wpaib-openapi-controller.php` | `WPAIB_OpenAPI_Controller` | Schema OpenAPI 3.0.3 dinamico (include OAuth2) |
| `admin/class-wpaib-admin.php` | `WPAIB_Admin` | UI admin: API key profilo utente + gestione OAuth2 client |

**Tabelle DB:**
- `wp_wpaib_api_keys` — id, user_id, key_hash (SHA-256), label, created_at, last_used_at, revoked_at
- `wp_wpaib_audit_log` — timestamp, api_key_id, ip, user_agent, endpoint, method, status_code, outcome
- `wp_wpaib_oauth_clients` — id, client_id, client_secret_hash, name, redirect_uris (JSON), created_at
- `wp_wpaib_oauth_codes` — id, code_hash, client_id, user_id, redirect_uri, scope, expires_at, used_at, created_at
- `wp_wpaib_oauth_tokens` — id, access_token_hash, refresh_token_hash, client_id, user_id, scope, expires_at, revoked_at, created_at

## Convenzioni

- Prefisso classi: `WPAIB_` — file: `class-wpaib-*.php` (kebab-case)
- Costante namespace REST: `WPAIB_API_NAMESPACE = 'wpaib/v1'`
- Endpoint protetti usano `WPAIB_Auth::require_cap('edit_posts')` come `permission_callback`
- Endpoint OAuth2 (`/oauth/token`, `/oauth/revoke`) usano `'__return_true'` e gestiscono auth internamente
- L'errore 401 è sempre generico (anti-enumeration): non distingue chiave mancante/errata/revocata/scaduta
- Rate limiter usa come chiave l'hash della plain key, non la plain key stessa
- HTTPS bypass automatico se `wp_get_environment_type()` è `local`/`development` o host è `localhost`/`127.0.0.1`
- Segreti OAuth2 (client_secret, auth code, access/refresh token) mai in chiaro nel DB — solo SHA-256
- `hash_equals()` per tutti i confronti di segreti (timing-safe)
- Refresh token rotation ad ogni utilizzo: il vecchio viene revocato, nuovo pair emesso

## Lettura per export completo (dalla 1.6.0)

- Paginazione a cursore: `after_id` su `/posts`, `/pages`, `/media`, `/comments`, `/categories`, `/tags`, `/users`, `/cpt/{type}`. La paginazione per numero di pagina non è stabile mentre il contenuto cambia
- È la **presenza** del parametro a scegliere la modalità, non il valore: `after_id` assente (`null`) = paginazione classica, `after_id=0` = cursore iniziale. Per questo gli arg REST non dichiarano `default` per `after_id`, e i controller confrontano con `null ===` / `null !==`, mai con `> 0`
- Ogni cursore è implementato con un filtro (`posts_where`, `comments_clauses`, `pre_user_query`, `terms_clauses`) agganciato a una query var custom `wpaib_after_id`, aggiunto subito prima della query e rimosso subito dopo: nessun'altra query della richiesta viene toccata
- La query di conteggio va filtrata con lo stesso cursore, altrimenti `total_remaining` conta l'intera collezione. Su `WP_Comment_Query` serve anche un `cache_domain` che varia col cursore: la chiave di cache non include l'SQL, quindi con un object cache persistente il conteggio filtrato tornerebbe da un'altra pagina
- In modalità cursore la risposta porta `next_after_id`, `has_more` e `total_remaining` al posto di `total`/`page`/`total_pages`. `has_more` è `total_remaining > count(items)`, esatto: nessuna richiesta finale a vuoto
- `content_rendered` (attivo di default su `/posts` e `/pages`) è `apply_filters( 'the_content', ... )`: solo così blocchi riutilizzabili, query loop, gallerie dinamiche e shortcode hanno HTML
- `status=any` resta "tutto tranne il cestino"; `trash` va chiesto esplicitamente. `any` viene espanso via `get_post_stati( array( 'internal' => false ) )`, non passato letterale a `WP_Query`: il suo `'any'` scarta anche gli stati registrati con `exclude_from_search`, cioè gli stati di workflow riservati dei plugin editoriali
- `/menus` copre entrambi i sistemi: `nav_menu` (temi classici) e post `wp_navigation` con voci serializzate in blocchi (temi a blocchi, i core dalla Twenty Twenty-Two). Le voci a blocchi hanno un `id` sintetico, non un post reale, usato per esprimere `parent`
- Gli elenchi non possono chiamare `current_user_can( 'edit_post', $id )`: senza restrizione restituirebbero le bozze e i privati altrui, cioè quello che la rotta singola nega con un 403. `WPAIB_Rest_Helper::restrict_to_visible()` aggiunge `wpaib_visible_for` e il filtro limita a "stati pubblici OR post_author = utente" per chi non ha `edit_others_posts`. `perm => 'readable'` di WP_Query non basta: filtra i privati ma lascia passare le bozze
- Ogni rotta usa la capability che userebbe WordPress: `/pages` → `edit_pages`, `/media` → `upload_files`, non `edit_posts`
- Lo scope OAuth2 è un secondo cancello prima della capability: `WPAIB_OAuth_Server::scope_allows()`. Il vocabolario è `WPAIB_OAuth_Server::SCOPES`, lo scope viene normalizzato all'emissione del code e uno scope vuoto ricade su `edit_posts`
- I commenti non approvati e i dati personali dell'autore (email, IP) richiedono `moderate_comments`, non basta `edit_posts`
- `wpaib_site_uuid` in `wp_options`: UUIDv4 opaco generato alla prima richiesta di `/site/full`, sopravvive a un cambio di dominio
- Il 429 porta l'header `Retry-After` con i secondi che restano nella finestra
