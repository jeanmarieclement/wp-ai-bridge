# Changelog

All notable changes to **WP AI Bridge** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.6.0] - 2026-09-04

### Added
- **Cursor pagination (`after_id`)** on `/posts`, `/pages`, `/media`, `/comments`, `/categories`, `/tags` and the new `/users`. Page-number pagination is unstable across a long-running export: content created or edited mid-run shifts the window, so records get skipped or duplicated. With `after_id` the query returns only records whose ID is greater than the cursor, ordered by ID ascending, and the response carries `next_after_id`, `has_more` and `total_remaining`. Implemented with a filter scoped to a custom query var (`posts_where`, `comments_clauses`, `pre_user_query`, `terms_clauses`), added immediately before the query and removed immediately after, so no other query on the request is affected.
- **`content_rendered`** on `/posts` and `/pages` (single and list, on by default, `content_rendered=false` to skip it): `post_content` passed through `apply_filters( 'the_content', … )`. Reusable blocks, query loops, dynamic galleries and shortcodes carry no inner HTML, so a consumer reading only `post_content` gets empty holes. The raw `post_content` is still returned alongside it.
- **Pagination on `/categories` and `/tags`**: `per_page`, `page` and `after_id` are now honoured, and the response carries `total` and `total_pages`. Without any of them the behaviour is unchanged — every term in one response.
- **New fields in existing payloads.** Posts: `comment_status`, `category_ids`, `tag_ids` (term IDs, the only stable basis for a cross-site mapping — names can collide or change), `post_date_gmt`, `post_modified_gmt` and `menu_order`. Pages: the same, plus `template`. Media: `source_url`, `width`, `height`, `filesize`, `alt_text`, `description`, `post_parent`, `author_id`, `post_date_gmt`, `post_modified_gmt`.
- **`status=trash`** on `/posts` and `/pages`. `any` keeps its historical meaning (everything except the trash), so a consumer that wants trashed content must ask for it explicitly. `any` is passed to `WP_Query` verbatim rather than expanded to a fixed list, so posts sitting in a status registered by another plugin still reach a full export.
- **`page` on `/comments`**, alongside `per_page`, for consumers that do not want the cursor; the response then carries `page` and `total_pages`.
- **`locations` and `location_labels` on `/menus`**: a menu can be assigned to more than one theme location at once, and an export has to reproduce every assignment. `location` stays as the first one for convenience.
- **`/comments` is now export-capable**: `after_id`, `per_page`, fixed ordering by comment ID, `post_type` filter, `status` (`approve`, `hold`, `spam`, `trash`, `all`), and `parent`, `author_url`, `user_id`, `status`, `type`, `comment_post_type` in the payload. The existing route was extended rather than shadowed by a second registration on the same path, where behaviour would depend on registration order.
- **`GET /users`** (`list_users`) and **`GET /users/{id}`**: id, login, email, display name, names, slug, url, description, roles, registration date, avatar URL, post count. No passwords and no hashes leave WordPress — a consumer creates local accounts with its own credentials.
- **`GET /menus`** (`edit_theme_options`): registered menus with assigned location, items, hierarchy, and each item's type and target object.
- **`GET /theme`** (`edit_theme_options`): active theme slug, name and version, stylesheet and template URLs, parent theme, block-theme flag, registered sidebars, and representative URLs to capture (home, latest post, a page, an archive).
- **`GET /site/full`** (`manage_options`): title, description, language, timezone, date and time formats, front page and page-for-posts, permalink structure, logo, favicon, comment and registration defaults, content counts, and `site_uuid`.
- **`site_uuid`**: WordPress has no native site identifier, so a consumer cannot recognise "the same source site" across runs and ends up duplicating instead of updating. A UUIDv4 is generated on first request, persisted in `wp_options` as `wpaib_site_uuid`, and exposed in `/site/full`. It is opaque — no host, no path, nothing derived from user data — and survives a domain change.
- **`Retry-After` on 429 responses**, plus `retry_after` in the error payload. A full export is thousands of sequential requests; without the header a client can only guess how long to back off.
- OpenAPI 3.0.3 schema extended with `/pages`, `/comments`, `/users`, `/users/{id}`, `/menus`, `/theme`, `/site`, `/site/full` and the `GET /media` operation, and with the `after_id`, `content_rendered` and `status=trash` parameters on the existing read paths.

### Fixed
- **Sticky posts no longer contaminate `/posts` listings.** `WP_Query` treats a query for the `post` type as the site home, so on page 1 — and, with a cursor, on *every* page, since each one looks like page 1 — it hoisted sticky posts to the front and re-fetched any that fell outside the page. That duplicated them across pages, broke the ID ordering the cursor relies on, and pulled published stickies into a `status=draft` or `status=trash` request, because the re-fetch hardcodes `post_status => 'publish'`. The query now sets `ignore_sticky_posts`.
- **The comment cursor is no longer defeated by a persistent object cache.** `WP_Comment_Query` builds its cache key from its own known query vars and does not hash the SQL, so the custom cursor var was dropped from the key and every `/comments?after_id=…` page could return the first page again on a site running Redis or Memcached. The cursor is now carried in `cache_domain`, a supported query var that does enter the key.
- `counts.attachments` in `/site/full` no longer adds the trash bucket that `wp_count_attachments()` returns alongside the per-MIME totals, which inflated it next to the publish-only post and page counts.
- The timezone fallback in `/site` and `/site/full` used to build strings like `UTC+5.5` for half-hour offsets (India, Newfoundland), which no offset parser accepts. Both now use `wp_timezone_string()`, which returns a valid `+05:30`.

### Security
- Comments with a status other than `approve` now require `moderate_comments`, and the author's email and IP address — personal data — are omitted from the payload unless the credential holds that capability. `edit_posts` alone reaches approved comments only.
- `/users` exposes no password material of any kind. `/menus` and `/theme` require `edit_theme_options`, `/site/full` requires `manage_options`: the same capabilities WordPress uses to protect that data in the backend.

### Changed
- `GET /posts` and `GET /pages` without an explicit `per_page` now return 10 records instead of 1. The old value came from clamping an absent parameter to the minimum, not from a deliberate default.

---

## [1.5.1] - 2026-08-22

### Fixed
- **Documented redirect URI for Claude.ai custom connectors**. The admin guide, the redirect URI field placeholder, and the README all advertised `https://claude.ai/api/oauth/callback`, but Claude.ai custom connectors (MCP) send `https://claude.ai/api/mcp/auth_callback`. Since redirect URIs are matched as exact strings, a client registered from those instructions always failed the authorize step with `OAuth2 Error: invalid client or redirect URI.` Both callbacks are now documented, and the strict-match requirement is stated explicitly.

---

## [1.4.0] - 2026-06-19

### Added
- **MCP tools for update management**, so a WordPress site can be fully managed over MCP: `get_updates` (overview, optional `force_check`), `get_changelog` (plugin/theme/core), `apply_update` (single), and `bulk_update` (multiple). Each tool is exposed only to users holding the matching WordPress capability (`update_core`, `update_plugins`, `update_themes`), mirroring the `/updates` REST routes.

---

## [1.3.0] - 2026-06-19

### Added
- **Plugin management** REST endpoints (`/plugins`): list, activate, deactivate, and delete installed plugins. Gated by the matching WordPress capability (`activate_plugins`, `delete_plugins`); WP AI Bridge cannot deactivate or delete itself via its own API.
- **Update management** REST endpoints (`/updates`): overview and per-type checks for core, plugins, and themes (`/updates/core`, `/updates/plugins`, `/updates/themes`), changelog retrieval (`/updates/changelog/{type}/{slug}`), single apply (`/updates/apply`), and bulk apply (`/updates/bulk`).
- Corresponding MCP tools (`get_plugins`, `activate_plugin`, `deactivate_plugin`, `delete_plugin`) exposed only to users with the required capability.
- OpenAPI 3.0.3 schema extended with the `/plugins` and `/updates` paths.

### Security
- Update endpoints use the specific WordPress capabilities (`update_core`, `update_plugins`, `update_themes`) instead of the broader `manage_options`, honoring `DISALLOW_FILE_MODS` and multisite super-admin restrictions.

### Fixed
- `bulk_update` deduplicates `type=core` items so the core upgrade runs at most once per request, and normalizes each result entry to always include `type` and `slug`.
- `list_plugins` uses `is_plugin_active()` so network-activated plugins are reported correctly on multisite.

---

## [1.2.1] - 2026-06-18

### Fixed
- Security audit corrections for the OAuth2 server and authentication middleware.
- General cleanup of internal authorization flows.

---

## [1.2.0] - 2026-05-19

### Added
- Standardized **GitHub Workflows**:
  - `ci.yml` for automated PHP syntax linting.
  - `release.yml` for automatic plugin packaging (`build.sh`) and drafting GitHub Releases on tag pushes.
- Standardized **GitHub Issue & Pull Request Templates** (`bug_report.md`, `feature_request.md`, `pull_request_template.md`).
- Formalized **Security Policy** (`SECURITY.md`) for private disclosure procedures.
- Localization (i18n) support with ready-to-translate POT file template (`wp-ai-bridge.pot`).
- Premium status/compatibility badges on the primary `README.md` file.

### Fixed
- Resolved `dbDelta` database upgrade crashes during system migrations.
- Corrected infinite sliding window bug in transient refreshes in `WPAIB_Rate_Limiter`.
- Cleaned up development caches and localized debug settings from version control.

---

## [1.1.0] - 2026-05-13

### Added
- Complete **OAuth2 Authorization Server** implementation:
  - Supports standard Authorization Code flow.
  - Interchangeable access using `Authorization: Bearer wpaib_at_...` on all REST endpoints.
  - Strict PKCE validation for improved client-side security.
  - Token refresh capabilities with rotation on every request.
  - Token revocation endpoint supporting RFC 7009 specification.
- Administrative **OAuth2 Clients management UI** in the WordPress admin panel.
- Enhanced **Security Architecture**:
  - Timing-safe hash comparisons (`hash_equals`) for all authentication lookups.
  - One-way SHA-256 storage for client secrets, access tokens, refresh tokens, and codes.
  - Cross-client token revoke prevention.
  - CSRF protection via nonces on the OAuth consent pages.
- Exposed **OAuth2 Security Schemes** dynamically inside the `/openapi.json` endpoint.

---

## [1.0.0] - 2026-05-13

### Added
- Secure **REST API** (`wpaib/v1`) for complete WordPress content management:
  - Posts and Pages CRUD operations.
  - Base64 image uploads to the Media Library.
  - Custom taxonomies (Categories and Tags) CRUD management.
  - Site information telemetry and full-text search capability.
- Native **Model Context Protocol (MCP)** server standard support for Claude Desktop, ChatGPT Custom Actions, and IDE extensions (Cursor, Roo Code).
- **Per-User API Keys** generation, encryption, and validation under the user profiles screen.
- Robust **Rate Limiter** restricting calls to a default of 300 requests/minute per credential.
- Dedicated **Audit Log** (`wp_wpaib_audit_log`) keeping track of request outcomes, status codes, IPs, and user agents.
