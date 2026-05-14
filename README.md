# WP AI Bridge

A WordPress plugin that exposes secure REST endpoints for content management via **per-user API keys**. Designed for integration with external AI services (Claude, ChatGPT, custom automations).

**Version:** 1.0.0  
**Compatibility:** WordPress 6.0+, PHP 7.4+  
**License:** MIT

---

## What It Does

Exposes a REST API under the `/wp-json/wpaib/v1/` namespace for:

- Listing, creating, reading, updating, and deleting posts
- Uploading images (multipart or base64) to the media library
- Listing and creating categories and tags
- MCP/function-calling tool execution (`/tools`, `/tools/execute`)
- Dynamic OpenAPI 3.0.3 schema (`/openapi.json`) — import-ready for ChatGPT, Gemini, Claude

Every request is authenticated with a personal API key shown **only once** at generation time and stored in the database as a SHA-256 hash only.

---

## Installation

1. Upload the `wp-ai-bridge` folder to `wp-content/plugins/`
2. Go to **WordPress → Plugins** and activate "WP AI Bridge"
3. Go to your **User Profile** (Users → Profile)
4. Scroll down to the **"WP AI Bridge — API Keys"** section
5. Enter a label (e.g. `Home laptop`) and click **Generate key**
6. **Copy the key immediately** — it will not be shown again

---

## Security Architecture

Every request passes 5 cascading checks:

| # | Check | What it does |
|---|-------|-------------|
| 1 | HTTPS check | Rejects plain HTTP requests |
| 2 | Rate limiter | Max 60 requests/min per key |
| 3 | API key validation | SHA-256 hash against DB, regex format pre-check |
| 4 | WordPress capability | Verifies permissions of the user linked to the key |
| 5 | Input sanitization | `sanitize_*` + `wp_kses_post` on all data |

**Audit log:** every access (success, auth failure, rate limit, forbidden) is logged to `wp_wpaib_audit_log` with timestamp, IP, user-agent, endpoint, and outcome.

**What the plugin does NOT do (by design):**
- Does not expose endpoints to manage users, roles, options, plugins, or themes
- Does not allow arbitrary code execution
- Does not serve files from the server
- Does not trust proxy headers unless explicitly configured

---

## API Examples

All requests require the `X-API-Key` header.

### Create a draft post

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

### Update a post

```bash
curl -X PUT https://your-site.com/wp-json/wpaib/v1/posts/123 \
  -H "Content-Type: application/json" \
  -H "X-API-Key: wpaib_xxxx..." \
  -d '{ "status": "publish" }'
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
| 401 | Missing or invalid API key |
| 403 | Insufficient capability or HTTPS required |
| 404 | Resource not found |
| 413 | File too large |
| 429 | Rate limit exceeded |
| 500 | Server error |

For security reasons, 401 errors do not distinguish between a missing, invalid, or revoked key (anti-enumeration).

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
- [ ] Revoke API keys that are no longer in use

---

## Uninstallation

Deactivating the plugin leaves data in the database. **To remove everything** (keys, logs, options), use **Delete** from the Plugins page: the `uninstall.php` script will clean up everything.

---

## Roadmap

- v1.1: outgoing webhooks for events (new post created via API, etc.)
- v1.2: optional key expiration (TTL)
- v1.3: per-key granular scopes (e.g. "read-only")
- v2.0: native embedded MCP server

---

## Known Limitations

- No at-rest encryption for audit logs (log queries are in plaintext in the DB, but contain no sensitive data)
- Rate limiter uses WordPress transients: benefits automatically from an external object cache (Redis/Memcached), otherwise falls back to DB
- Upload limited to 5 MB; supported types: jpg, png, gif, webp

---

## Contributing

See [CONTRIBUTORS.md](CONTRIBUTORS.md).

## License

This project is licensed under the [MIT License](LICENSE).
