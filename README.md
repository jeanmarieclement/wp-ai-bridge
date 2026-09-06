# WP AI Bridge

[![GitHub Release](https://img.shields.io/github/v/release/jeanmarieclement/wp-ai-bridge?style=flat-square&color=blue)](https://github.com/jeanmarieclement/wp-ai-bridge/releases)
[![Build Status](https://img.shields.io/github/actions/workflow/status/jeanmarieclement/wp-ai-bridge/ci.yml?branch=main&style=flat-square&label=CI)](https://github.com/jeanmarieclement/wp-ai-bridge/actions)
[![WordPress Compatibility](https://img.shields.io/badge/WordPress-6.0%2B-blue?style=flat-square&logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP Compatibility](https://img.shields.io/badge/PHP-%3E%3D%207.4-8892BF?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/github/license/jeanmarieclement/wp-ai-bridge?style=flat-square&color=orange)](LICENSE)

A WordPress plugin that exposes secure REST endpoints for content management via **per-user API keys** or **OAuth2** (Authorization Code flow). Designed for integration with external AI services (Claude.ai, ChatGPT, custom automations).

**Version:** 1.6.0  
**Compatibility:** WordPress 6.0+, PHP 7.4+  
**License:** MIT

---

## What It Does

Exposes a REST API under the `/wp-json/wpaib/v1/` namespace for:

- Listing, creating, reading, updating, and deleting posts and pages
- **Custom Post Types** — discover and CRUD any registered CPT (WooCommerce products, portfolios, events, reviews…) via `/cpt`
- Uploading images (base64) to the media library
- Listing and creating categories and tags
- Reading site info and full-text search (includes CPTs)
- **Reading a whole site for export** — stable cursor pagination, rendered content, comments, users, menus, theme, and full site configuration (see [Full site export](#full-site-export))
- Managing plugins — list, activate, deactivate, delete (`/plugins`, admin-only)
- Managing updates — check and apply core, plugin, and theme updates (`/updates`, admin-only)
- MCP/function-calling tool execution (`/tools`, `/tools/execute`)
- Dynamic OpenAPI 3.0.3 schema (`/openapi.json`) — import-ready for ChatGPT, Gemini, Claude.ai

Two authentication methods are supported and can be used interchangeably on any endpoint:

| Method | Header | Use when |
|--------|--------|----------|
| **API Key** | `X-API-Key: wpaib_...` | Scripts, automations, direct integrations |
| **OAuth2 Bearer** | `Authorization: Bearer wpaib_at_...` | ChatGPT Custom Actions, Claude.ai tools, user-facing flows |

---

## Installation

1. Upload the `wp-ai-bridge` folder to `wp-content/plugins/`
2. Go to **WordPress → Plugins** and activate "WP AI Bridge"

> **Troubleshooting:** If OAuth2 endpoints (`/authorize`, `/token`) or the MCP endpoint return 404 after installation or update, go to **Settings → Permalinks** and click **Save Changes** to flush WordPress rewrite rules.

---

## Authentication — API Key

1. Go to **Users → Profile** (or edit any user as admin)
2. Scroll down to **"WP AI Bridge — API Keys"**
3. Enter a label (e.g. `Home laptop`) and click **Generate key**
4. **Copy the key immediately** — it will not be shown again

```bash
curl https://your-site.com/wp-json/wpaib/v1/posts \
  -H "X-API-Key: wpaib_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
```

---

## Authentication — OAuth2

WP AI Bridge acts as an **OAuth2 Authorization Server** (Authorization Code flow). Use this when connecting via ChatGPT Custom Actions, Claude.ai tools, or any OAuth2-capable client.

### Setup

1. Go to **Settings → WP AI Bridge → OAuth2 Clients**
2. Click **Aggiungi client**, enter a name and the redirect URI(s) provided by the platform
3. Copy the `client_id` and `client_secret` shown immediately (secret not shown again)

### Endpoints

| Role | URL |
|------|-----|
| **Authorization** | `https://your-site.com/wpaib/oauth/authorize` |
| **Token** | `https://your-site.com/wp-json/wpaib/v1/oauth/token` |
| **Revoke** | `https://your-site.com/wp-json/wpaib/v1/oauth/revoke` |
| **OpenAPI schema** | `https://your-site.com/wp-json/wpaib/v1/openapi.json` |

### Redirect URIs by platform

| Platform | Redirect URI |
|----------|-------------|
| ChatGPT Custom Actions | `https://chat.openai.com/aip/g-<id>/oauth/callback` |
| Claude.ai custom connector (MCP) | `https://claude.ai/api/mcp/auth_callback` |
| Claude.ai tools (generic OAuth) | `https://claude.ai/api/oauth/callback` |

The redirect URI is compared with a strict string match, so it must be registered
exactly as the client sends it. Claude.ai custom connectors use the MCP callback
above — registering only `oauth/callback` makes the authorize request fail with
`OAuth2 Error: invalid client or redirect URI.`

### Flow summary

```
Client → GET /wpaib/oauth/authorize?response_type=code&client_id=...&redirect_uri=...&state=...
       ← WordPress login (if not logged in)
       ← Consent page (Autorizza / Nega)
       → POST /wpaib/oauth/authorize (user clicks Autorizza)
       ← redirect_uri?code=wpaib_ac_...&state=...

Client → POST /wp-json/wpaib/v1/oauth/token
         grant_type=authorization_code&code=...&client_id=...&client_secret=...&redirect_uri=...
       ← { "access_token": "wpaib_at_...", "refresh_token": "wpaib_rt_...", "token_type": "Bearer", "expires_in": 3600 }

Client → GET /wp-json/wpaib/v1/posts
         Authorization: Bearer wpaib_at_...
       ← posts JSON
```

### Token lifetimes

| Token | Lifetime |
|-------|---------|
| Authorization code | 10 minutes (one-shot) |
| Access token | 1 hour |
| Refresh token | 30 days (rotated on each use) |

### Token refresh

```bash
curl -X POST https://your-site.com/wp-json/wpaib/v1/oauth/token \
  -d "grant_type=refresh_token&refresh_token=wpaib_rt_...&client_id=...&client_secret=..."
```

### Token revoke

```bash
curl -X POST https://your-site.com/wp-json/wpaib/v1/oauth/revoke \
  -d "token=wpaib_at_...&client_id=...&client_secret=..."
```

Returns `{}` with 200 even if the token did not exist (RFC 7009).

---

## Security Architecture

Every REST request passes these cascading checks:

| # | Check | What it does |
|---|-------|-------------|
| 1 | HTTPS check | Rejects plain HTTP (bypassed for localhost/dev) |
| 2 | Rate limiter | Max 300 requests/min per key/token |
| 3 | Auth — Bearer | Validates OAuth2 access token: hash lookup + expiry + revocation |
| 3 | Auth — API Key | SHA-256 hash against DB, regex format pre-check |
| 4 | OAuth2 scope | For Bearer tokens: the capability the route needs must be named in the token's scope |
| 5 | WordPress capability | Verifies the capability that route requires on the user linked to the credential |
| 6 | Per-record visibility | Lists return public content plus the user's own; other people's drafts and private records need `edit_others_posts` |
| 7 | Input sanitization | `sanitize_*` + `wp_kses_post` on all data |

**OAuth2 security properties:**
- Token scopes are enforced: a token reaches only the capabilities its scope names, whatever the authorising user could otherwise do
- Authorization codes are one-shot (invalidated immediately after use)
- Client secrets stored as SHA-256 hashes only
- Access and refresh tokens stored as SHA-256 hashes only
- Refresh token rotation on every use
- `redirect_uri` validated against pre-registered list on both GET and POST
- CSRF protection via WP nonce on consent form
- Cross-client revoke prevention: `revoke_token` validates client ownership

**Audit log:** every access (success, auth failure, rate limit, forbidden) is logged to `wp_wpaib_audit_log` with timestamp, IP, user-agent, endpoint, and outcome.

**What the plugin does NOT do (by design):**
- Does not expose endpoints to **manage** users, roles, or options. `GET /users` is read-only and requires `list_users`; it never returns passwords or password hashes. `GET /site/full` is read-only and requires `manage_options`
- Plugin, theme, and update management require the matching WordPress capability (`activate_plugins`, `delete_plugins`, `update_plugins`, `update_themes`, `update_core`) on the credential's user — administrators only
- Does not allow arbitrary code execution
- Does not serve files from the server
- Does not trust proxy headers unless explicitly configured

---

## API Examples

Both auth methods work on all endpoints.

### Create a draft post (API key)

```bash
curl -X POST https://your-site.com/wp-json/wpaib/v1/posts \
  -H "Content-Type: application/json" \
  -H "X-API-Key: wpaib_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
  -d '{
    "title": "My test post",
    "content": "<p>HTML content of the post.</p>",
    "excerpt": "Short summary",
    "status": "draft",
    "categories": [5],
    "tags": ["ai", "wordpress", "automation"]
  }'
```

### Create a draft post (OAuth2 Bearer)

```bash
curl -X POST https://your-site.com/wp-json/wpaib/v1/posts \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer wpaib_at_xxxxxxxx..." \
  -d '{ "title": "Post via OAuth2", "status": "draft" }'
```

### Upload an image (base64)

```bash
curl -X POST https://your-site.com/wp-json/wpaib/v1/media \
  -H "Content-Type: application/json" \
  -H "X-API-Key: wpaib_xxxx..." \
  -d '{
    "filename": "cover.png",
    "image_base64": "iVBORw0KGgoAAAANS..."
  }'
```

### List categories

```bash
curl https://your-site.com/wp-json/wpaib/v1/categories \
  -H "X-API-Key: wpaib_xxxx..."
```

### Python example

```python
import requests, base64

API_BASE = "https://your-site.com/wp-json/wpaib/v1"
HEADERS = {"X-API-Key": "wpaib_xxxx...", "Content-Type": "application/json"}

# 1. Upload featured image
with open("cover.png", "rb") as f:
    b64 = base64.b64encode(f.read()).decode()

media = requests.post(f"{API_BASE}/media", headers=HEADERS, json={
    "filename": "cover.png",
    "image_base64": b64,
}).json()

# 2. Create draft with featured image
post = requests.post(f"{API_BASE}/posts", headers=HEADERS, json={
    "title": "Post from my script",
    "content": "<p>Content.</p>",
    "status": "draft",
    "featured_media": media["id"],
    "tags": ["automation"],
}).json()

print(f"Draft created: {post['link']}")
```

### Custom Post Types

```bash
# 1. Discover available CPTs
curl https://your-site.com/wp-json/wpaib/v1/cpt \
  -H "X-API-Key: wpaib_xxxx..."
# → { "items": [ {"slug": "review", "name": "Reviews", ...}, {"slug": "product", ...} ], "total": 2 }

# 2. List items of a CPT
curl https://your-site.com/wp-json/wpaib/v1/cpt/review?status=publish&per_page=5 \
  -H "X-API-Key: wpaib_xxxx..."

# 3. Create a new CPT item
curl -X POST https://your-site.com/wp-json/wpaib/v1/cpt/review \
  -H "Content-Type: application/json" \
  -H "X-API-Key: wpaib_xxxx..." \
  -d '{
    "title": "Great product",
    "content": "<p>Highly recommended!</p>",
    "status": "publish",
    "taxonomies": { "review_category": ["electronics"], "review_tag": [3, 7] }
  }'

# 4. Update a CPT item
curl -X PUT https://your-site.com/wp-json/wpaib/v1/cpt/review/42 \
  -H "Content-Type: application/json" \
  -H "X-API-Key: wpaib_xxxx..." \
  -d '{ "status": "draft" }'

# 5. Delete a CPT item
curl -X DELETE https://your-site.com/wp-json/wpaib/v1/cpt/review/42?force=true \
  -H "X-API-Key: wpaib_xxxx..."
```

> **Note:** Only CPTs registered with `public = true` and `show_in_rest = true` are exposed. Built-in types (post, page, attachment) are excluded — use the dedicated `/posts` and `/pages` endpoints instead.

---

## Full Site Export

Every read endpoint is designed so a consumer can walk an entire site — content, media, users, comments, menus, settings — in a single resumable pass.

### Stable pagination

Page numbers shift when content is created or edited mid-run, so records get skipped or duplicated. Pass `after_id` instead: the endpoint returns only records with a greater ID, ordered by ID ascending.

```bash
# First batch
curl "https://your-site.com/wp-json/wpaib/v1/posts?after_id=0&per_page=100&status=any" \
  -H "X-API-Key: wpaib_..."

# → { "items": [...], "next_after_id": 412, "has_more": true, "total_remaining": 830 }

# Next batch: feed next_after_id back in, until has_more is false
curl "https://your-site.com/wp-json/wpaib/v1/posts?after_id=412&per_page=100&status=any" \
  -H "X-API-Key: wpaib_..."
```

Available on `/posts`, `/pages`, `/media`, `/comments`, `/categories`, `/tags`, `/users` and `/cpt/{type}`. With a cursor, `total_remaining` counts the records left from the cursor onward and replaces `total`, `page` and `total_pages`.

It is the presence of `after_id` that selects cursor mode, not its value. `after_id=0` is the opening cursor of an export starting from scratch and returns cursor metadata; omitting the parameter entirely gives you classic page-number pagination with `total`, `page` and `total_pages`. `has_more` is exact — when it is `false` there is nothing left, so the walk above never spends a final empty request to find that out.

### Rendered content

`post_content` alone is not enough: reusable blocks, query loops, dynamic galleries and shortcodes carry no inner HTML. `/posts` and `/pages` return `content_rendered` — the output of `apply_filters( 'the_content', … )` — alongside the raw content. Pass `content_rendered=false` to skip it when you only need the source.

### Trashed content and custom statuses

`status=any` means everything except the trash, as it always has. Ask for `status=trash` explicitly to carry the trash over.

`any` covers every registered non-internal status, not just the five core ones. That matters on sites running an editorial or e-commerce plugin: those declare reserved workflow statuses with `exclude_from_search`, which `WP_Query`'s own `'any'` drops silently — content that would simply go missing from an export claiming to be complete.

### What a credential can actually read

A read endpoint returns what the same user would see in wp-admin, never more. Public content is everyone's; drafts, pending, private and trashed content is returned only to the user who authored it, unless the credential holds `edit_others_posts` (or `edit_others_pages`, or the equivalent for a custom post type) — which an Editor and an Administrator do, so a real export is unaffected.

Each endpoint asks for the capability WordPress itself asks for: `/posts` and `/cpt/{type}` want `edit_posts`, `/pages` wants `edit_pages`, `/media` wants `upload_files`, `/users` wants `list_users`, `/menus` and `/theme` want `edit_theme_options`, `/site/full` wants `manage_options`.

With OAuth2 the token's scope is a second gate, applied before the capability: a token names the capabilities it was granted and reaches nothing else, even when the person who authorised it is an administrator. Scopes are listed in `/openapi.json`; a client that needs the full export surface must request them explicitly (for example `edit_posts edit_pages upload_files list_users edit_theme_options manage_options`). A token issued without a scope gets `edit_posts`.

### Read-only endpoints for a full migration

| Endpoint | Capability | Returns |
|----------|-----------|---------|
| `GET /users` | `list_users` | id, login, email, display name, roles, description, avatar URL. **No passwords, no hashes** |
| `GET /users/{id}` | `list_users` | The same for a single user |
| `GET /menus` | `edit_theme_options` | Classic and block-theme menus, assigned locations, items, hierarchy, item type and target object |
| `GET /media` | `upload_files` | Attachments with source URL, dimensions, size, alt text, parent and author |
| `GET /theme` | `edit_theme_options` | Active theme (slug, name, version), stylesheet and template URLs, sidebars, representative URLs to capture |
| `GET /site/full` | `manage_options` | Title, description, language, timezone, front page and page-for-posts, logo, favicon, permalink structure, `site_uuid` |
| `GET /comments` | `edit_posts` / `moderate_comments` | Comments with parent, author URL, user id, status, type and parent post type |

`/comments` accepts `after_id` for an export, or `per_page` + `page` for simple paging. It returns approved comments with `edit_posts`. Any other `status` (`hold`, `spam`, `trash`, `all`) requires `moderate_comments`, which also unlocks the author's email and IP address — personal data that stays out of reach of the lower capability.

`/menus` covers both menu systems. Classic themes keep their menus in the `nav_menu` taxonomy; block themes (every core theme since Twenty Twenty-Two) keep them in `wp_navigation` posts with the entries serialised as blocks. Both are returned, with `type` saying which is which, so an FSE site does not export an empty navigation. A `core/page-list` block is expanded into the pages it resolves to at render time, and `core/home-link` and `core/loginout` are resolved to their real label and URL. Block entries carry a synthetic `id` — they have no post of their own — used to express `parent`, so submenu nesting survives; classic entries keep their real `nav_menu_item` ID. A menu assigned to several theme locations reports all of them in `locations`, with `location` kept as the first for convenience.

### Recognising the same source site

WordPress has no native site identifier, so a consumer cannot tell whether it is looking at a site it has already imported. `/site/full` returns `site_uuid`: a UUIDv4 generated on first request, stored in `wp_options` as `wpaib_site_uuid`, opaque (nothing derived from host, path, or user data) and stable across a domain change.

---

## Response Codes

| Code | Meaning |
|------|---------|
| 200 | OK |
| 201 | Resource created |
| 400 | Invalid body or missing parameters |
| 401 | Missing or invalid credential (API key or Bearer token) |
| 403 | Insufficient capability or HTTPS required |
| 404 | Resource not found |
| 413 | File too large |
| 429 | Rate limit exceeded |
| 500 | Server error |

For security reasons, 401 errors do not distinguish between missing, invalid, expired, or revoked credentials (anti-enumeration).

A 429 response carries a `Retry-After` header (and `retry_after` in the error data) with the number of seconds left in the current rate-limit window, so a client running a long export can back off for exactly as long as it needs to.

---

## Advanced Configuration (wp-config.php)

```php
// Only if your site is behind a TRUSTED reverse proxy/CDN (Cloudflare, etc.)
// and you want the logged IP to be the real client IP.
define( 'WPAIB_TRUST_PROXY', true );
```

**Do not enable** `WPAIB_TRUST_PROXY` if the site is exposed directly: `X-Forwarded-For` headers are spoofable.

---

## Post-Installation Hardening Checklist

The plugin does its part, but security is a chain. Also verify:

- [ ] Valid HTTPS enforced site-wide (Let's Encrypt, HSTS active)
- [ ] WordPress, plugins, and theme always up to date
- [ ] Strong admin passwords + 2FA on high-capability accounts
- [ ] Security plugin active (Wordfence, Solid Security, etc.)
- [ ] Automated daily off-site backups (UpdraftPlus, BackWPup)
- [ ] Login attempt limiting (brute-force protection)
- [ ] `wp-config.php` permissions set to 600
- [ ] XML-RPC disabled if unused
- [ ] Monitor `wp_wpaib_audit_log` periodically
- [ ] Revoke API keys and OAuth2 clients that are no longer in use
- [ ] Rotate OAuth2 client secrets periodically

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `wp_wpaib_api_keys` | Per-user API keys (SHA-256 hash, label, timestamps) |
| `wp_wpaib_audit_log` | Audit log of all API requests |
| `wp_wpaib_oauth_clients` | Registered OAuth2 clients (name, secret hash, redirect URIs) |
| `wp_wpaib_oauth_codes` | Authorization codes (one-shot, 10-min TTL) |
| `wp_wpaib_oauth_tokens` | Access + refresh token pairs (hash, expiry, revocation) |

---

## Uninstallation

Deactivating the plugin leaves data in the database. **To remove everything** (keys, OAuth2 clients, tokens, logs, options), use **Delete** from the Plugins page: the `uninstall.php` script will clean up everything.

---

## Known Limitations

- No at-rest encryption for audit logs (log queries are in plaintext in the DB, but contain no sensitive data)
- Rate limiter uses WordPress transients: benefits automatically from an external object cache (Redis/Memcached), otherwise falls back to DB
- Upload limited to 5 MB; supported types: jpg, png, gif, webp
- OAuth2 token endpoint does not yet appear in the Connections audit log tab

---

## Contributing

See [CONTRIBUTORS.md](CONTRIBUTORS.md).

## License

This project is licensed under the [MIT License](LICENSE).
