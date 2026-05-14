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
Auth header: `X-API-Key: <chiave>`

Endpoint disponibili:
- `GET/POST /posts`, `GET/POST/DELETE /posts/{id}`
- `POST /media` (base64, max 5 MB, tipi: jpg/png/gif/webp)
- `GET/POST /categories`, `GET/POST /tags`
- `GET /tools`, `POST /tools/execute` (MCP / function calling)
- `GET /openapi.json` (pubblico, no auth — per import in ChatGPT/Gemini/Claude)

## Architettura

Plugin PHP puro, zero dipendenze esterne (no Composer). Autoloader manuale in `wp-ai-bridge.php`.

**Flusso di una richiesta:**
1. `WPAIB_Plugin::enforce_https()` — rifiuta HTTP (escluso localhost/development)
2. `WPAIB_Auth::authorize()` — rate limit → hash SHA-256 → capability WP
3. Controller specifico gestisce la logica

**Classi principali:**
| File | Classe | Responsabilità |
|------|--------|----------------|
| `includes/class-wpaib-auth.php` | `WPAIB_Auth` | Middleware auth per tutti gli endpoint |
| `includes/class-wpaib-api-key-manager.php` | `WPAIB_API_Key_Manager` | Generazione, validazione (hash), revoca chiavi |
| `includes/class-wpaib-rate-limiter.php` | `WPAIB_Rate_Limiter` | 60 req/min via transient WP, keyed sull'hash |
| `includes/class-wpaib-logger.php` | `WPAIB_Logger` | Audit log su `wp_wpaib_audit_log` |
| `includes/class-wpaib-installer.php` | `WPAIB_Installer` | `dbDelta()` per le due tabelle, pulizia transient |
| `includes/endpoints/class-wpaib-posts-controller.php` | `WPAIB_Posts_Controller` | CRUD articoli |
| `includes/endpoints/class-wpaib-media-controller.php` | `WPAIB_Media_Controller` | Upload immagini |
| `includes/endpoints/class-wpaib-taxonomy-controller.php` | `WPAIB_Taxonomy_Controller` | Categorie e tag |
| `includes/endpoints/class-wpaib-mcp-controller.php` | `WPAIB_MCP_Controller` | Endpoint `/tools` e `/tools/execute` |
| `includes/endpoints/class-wpaib-openapi-controller.php` | `WPAIB_OpenAPI_Controller` | Schema OpenAPI 3.0.3 dinamico |
| `admin/class-wpaib-admin.php` | `WPAIB_Admin` | UI profilo utente per gestire le chiavi |

**Tabelle DB:**
- `wp_wpaib_api_keys` — id, user_id, key_hash (SHA-256), label, created_at, last_used_at, revoked_at
- `wp_wpaib_audit_log` — timestamp, api_key_id, ip, user_agent, endpoint, method, status_code, outcome

## Convenzioni

- Prefisso classi: `WPAIB_` — file: `class-wpaib-*.php` (kebab-case)
- Costante namespace REST: `WPAIB_API_NAMESPACE = 'wpaib/v1'`
- Ogni endpoint usa `WPAIB_Auth::require_cap('edit_posts')` come `permission_callback`
- L'errore 401 è sempre generico (anti-enumeration): non distingue chiave mancante/errata/revocata
- Rate limiter usa come chiave l'hash della plain key, non la plain key stessa
- HTTPS bypass automatico se `wp_get_environment_type()` è `local`/`development` o host è `localhost`/`127.0.0.1`
