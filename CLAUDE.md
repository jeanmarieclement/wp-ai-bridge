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
- `POST /media` (base64, max 5 MB, tipi: jpg/png/gif/webp)
- `GET/POST /categories`, `GET/POST /tags`
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
| `includes/class-wpaib-rate-limiter.php` | `WPAIB_Rate_Limiter` | 300 req/min via transient WP, keyed sull'hash |
| `includes/class-wpaib-logger.php` | `WPAIB_Logger` | Audit log su `wp_wpaib_audit_log` |
| `includes/class-wpaib-installer.php` | `WPAIB_Installer` | `dbDelta()` per le 5 tabelle, rewrite rule OAuth2 |
| `includes/class-wpaib-oauth-client-manager.php` | `WPAIB_OAuth_Client_Manager` | CRUD client OAuth2 (client_id, secret hash, redirect_uris) |
| `includes/class-wpaib-oauth-server.php` | `WPAIB_OAuth_Server` | Codici auth, token pair, refresh, revoca, cleanup |
| `includes/class-wpaib-oauth-authorize.php` | `WPAIB_OAuth_Authorize` | Rewrite rule + template_redirect per pagina consenso |
| `includes/endpoints/class-wpaib-oauth-controller.php` | `WPAIB_OAuth_Controller` | REST: POST /oauth/token e POST /oauth/revoke |
| `includes/endpoints/class-wpaib-posts-controller.php` | `WPAIB_Posts_Controller` | CRUD articoli |
| `includes/endpoints/class-wpaib-media-controller.php` | `WPAIB_Media_Controller` | Upload immagini |
| `includes/endpoints/class-wpaib-taxonomy-controller.php` | `WPAIB_Taxonomy_Controller` | Categorie e tag |
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
