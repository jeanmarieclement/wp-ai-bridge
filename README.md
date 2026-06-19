# WP AI Bridge

[![GitHub Release](https://img.shields.io/github/v/release/jeanmarieclement/wp-ai-bridge?style=flat-square&color=blue)](https://github.com/jeanmarieclement/wp-ai-bridge/releases)
[![Build Status](https://img.shields.io/github/actions/workflow/status/jeanmarieclement/wp-ai-bridge/ci.yml?branch=main&style=flat-square&label=CI)](https://github.com/jeanmarieclement/wp-ai-bridge/actions)
[![WordPress Compatibility](https://img.shields.io/badge/WordPress-6.0%2B-blue?style=flat-square&logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP Compatibility](https://img.shields.io/badge/PHP-%3E%3D%207.4-8892BF?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/github/license/jeanmarieclement/wp-ai-bridge?style=flat-square&color=orange)](LICENSE)

A WordPress plugin that exposes secure REST endpoints for content management via **per-user API keys** or **OAuth2** (Authorization Code flow). Designed for integration with external AI services (Claude.ai, ChatGPT, custom automations).

**Version:** 1.4.0  
**Compatibility:** WordPress 6.0+, PHP 7.4+  
**License:** MIT

---

## What It Does

Exposes a REST API under the `/wp-json/wpaib/v1/` namespace for:

- Listing, creating, reading, updating, and deleting posts and pages
- Uploading images (base64) to the media library
- Listing and creating categories and tags
- Reading site info and full-text search
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
| Claude.ai tools | `https://claude.ai/api/oauth/callback` |

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
| 4 | WordPress capability | Verifies `edit_posts` on the user linked to the credential |
| 5 | Input sanitization | `sanitize_*` + `wp_kses_post` on all data |

**OAuth2 security properties:**
- Authorization codes are one-shot (invalidated immediately after use)
- Client secrets stored as SHA-256 hashes only
- Access and refresh tokens stored as SHA-256 hashes only
- Refresh token rotation on every use
- `redirect_uri` validated against pre-registered list on both GET and POST
- CSRF protection via WP nonce on consent form
- Cross-client revoke prevention: `revoke_token` validates client ownership

**Audit log:** every access (success, auth failure, rate limit, forbidden) is logged to `wp_wpaib_audit_log` with timestamp, IP, user-agent, endpoint, and outcome.

**What the plugin does NOT do (by design):**
- Does not expose endpoints to manage users, roles, or options
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
