# Changelog

All notable changes to **WP AI Bridge** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.6.0] - 2026-09-04

### Added
- **Cursor pagination (`after_id`)** on `/posts`, `/pages`, `/media`, `/comments`, `/categories`, `/tags`, `/cpt/{type}` and the new `/users`. Page-number pagination is unstable across a long-running export: content created or edited mid-run shifts the window, so records get skipped or duplicated. With `after_id` the query returns only records whose ID is greater than the cursor, ordered by ID ascending, and the response carries `next_after_id`, `has_more` and `total_remaining`. Implemented with a filter scoped to a custom query var (`posts_where`, `comments_clauses`, `pre_user_query`, `terms_clauses`), added immediately before the query and removed immediately after, so no other query on the request is affected. It is the *presence* of the parameter that selects cursor mode, not its value: `after_id=0` is the legitimate opening cursor of an export starting from scratch, and is distinct from omitting the parameter.
- **`content_rendered`** on `/posts` and `/pages` (single and list, on by default, `content_rendered=false` to skip it): `post_content` passed through `apply_filters( 'the_content', … )`. Reusable blocks, query loops, dynamic galleries and shortcodes carry no inner HTML, so a consumer reading only `post_content` gets empty holes. The raw `post_content` is still returned alongside it.
- **Pagination on `/categories` and `/tags`**: `per_page`, `page` and `after_id` are now honoured, and the response carries `total` and `total_pages`. Without any of them the behaviour is unchanged — every term in one response.
- **New fields in existing payloads.** Posts: `comment_status`, `category_ids`, `tag_ids` (term IDs, the only stable basis for a cross-site mapping — names can collide or change), `post_date_gmt`, `post_modified_gmt` and `menu_order`. Pages: the same, plus `template`. Media: `source_url`, `width`, `height`, `filesize`, `alt_text`, `description`, `post_parent`, `author_id`, `post_date_gmt`, `post_modified_gmt`.
- **`status=trash`** on `/posts`, `/pages` and `/cpt/{type}`. `any` keeps its historical meaning (everything except the trash), so a consumer that wants trashed content must ask for it explicitly. `any` is expanded to the list of non-internal statuses rather than handed to `WP_Query` as the literal `any`: core's `any` excludes every status flagged `exclude_from_search`, which is how several editorial plugins declare their reserved workflow statuses, and those records would vanish from a "full export" without a word.
- **`page` on `/comments`**, alongside `per_page`, for consumers that do not want the cursor; the response then carries `page` and `total_pages`.
- **`locations` and `location_labels` on `/menus`**: a menu can be assigned to more than one theme location at once, and an export has to reproduce every assignment. `location` stays as the first one for convenience.
- **`/comments` is now export-capable**: `after_id`, `per_page`, fixed ordering by comment ID, `post_type` filter, `status` (`approve`, `hold`, `spam`, `trash`, `all`), and `parent`, `author_url`, `user_id`, `status`, `type`, `comment_post_type` in the payload. The existing route was extended rather than shadowed by a second registration on the same path, where behaviour would depend on registration order.
- **`GET /users`** (`list_users`) and **`GET /users/{id}`**: id, login, email, display name, names, slug, url, description, roles, registration date, avatar URL, post count. No passwords and no hashes leave WordPress — a consumer creates local accounts with its own credentials.
- **`GET /menus`** (`edit_theme_options`): registered menus with assigned location, items, hierarchy, and each item's type and target object. **Block themes are covered too**: since Twenty Twenty-Two the core themes no longer use the classic `nav_menu` taxonomy — navigation lives in `wp_navigation` posts with the entries serialised as blocks — so a classic-only implementation returns an empty list on every FSE site. `wp_navigation` menus are read alongside the classic ones (`type` says which), `core/page-list` is expanded into the pages it resolves to at render time, and `core/home-link` and `core/loginout`, which carry no label or URL of their own, are resolved rather than dropped.
- **`GET /theme`** (`edit_theme_options`): active theme slug, name and version, stylesheet and template URLs, parent theme, block-theme flag, registered sidebars, and representative URLs to capture (home, latest post, a page, an archive).
- **`GET /site/full`** (`manage_options`): title, description, language, timezone, date and time formats, front page and page-for-posts, permalink structure, logo, favicon, comment and registration defaults, content counts, and `site_uuid`.
- **`site_uuid`**: WordPress has no native site identifier, so a consumer cannot recognise "the same source site" across runs and ends up duplicating instead of updating. A UUIDv4 is generated on first request, persisted in `wp_options` as `wpaib_site_uuid`, and exposed in `/site/full`. It is opaque — no host, no path, nothing derived from user data — and survives a domain change.
- **`Retry-After` on 429 responses**, plus `retry_after` in the error payload. A full export is thousands of sequential requests; without the header a client can only guess how long to back off.
- OpenAPI 3.0.3 schema extended with `/pages`, `/comments`, `/users`, `/users/{id}`, `/menus`, `/theme`, `/site`, `/site/full` and the `GET /media` operation, and with the `after_id`, `content_rendered` and `status=trash` parameters on the existing read paths.

### Fixed
- **MCP now enforces the scope of each operation**, including page/media reads, plugin management and updates. Bearer identity is forwarded across both internal request hops; API-key permissions and outer request rate accounting are preserved. Field-level checks for comment moderation/PII and the site administrator email also respect OAuth scopes.
- **Comments cannot expose inaccessible parent content** through per-post lists, global lists or search. Parent visibility uses the capabilities of each registered post type before pagination, applies to counts too, and partitions comment caches by visibility and cursor.
- **OpenAPI declares operation-specific OAuth requirements**, keeping API-key authentication as an alternative and documenting the extra moderation scope needed for non-approved comments and personal data.
- **Multipart uploads use WordPress's extension-to-MIME mapping**, including JPG/JPEG, PNG, GIF and WebP; the base64 MIME lookup is unchanged.
- Added `tests/review-regressions.php`, an integration suite for REST/MCP authorization, comment visibility/pagination/cache, schema scopes and multipart MIME validation against the development WordPress stack.
- **Sticky posts no longer contaminate `/posts` listings.** `WP_Query` treats a query for the `post` type as the site home, so on page 1 — and, with a cursor, on *every* page, since each one looks like page 1 — it hoisted sticky posts to the front and re-fetched any that fell outside the page. That duplicated them across pages, broke the ID ordering the cursor relies on, and pulled published stickies into a `status=draft` or `status=trash` request, because the re-fetch hardcodes `post_status => 'publish'`. The query now sets `ignore_sticky_posts`.
- **The comment cursor is no longer defeated by a persistent object cache.** `WP_Comment_Query` builds its cache key from its own known query vars and does not hash the SQL, so the custom cursor var was dropped from the key and every `/comments?after_id=…` page could return the first page again on a site running Redis or Memcached. The cursor is now carried in `cache_domain`, a supported query var that does enter the key.
- `counts.attachments` in `/site/full` no longer adds the trash bucket that `wp_count_attachments()` returns alongside the per-MIME totals, which inflated it next to the publish-only post and page counts.
- The timezone fallback in `/site` and `/site/full` used to build strings like `UTC+5.5` for half-hour offsets (India, Newfoundland), which no offset parser accepts. Both now use `wp_timezone_string()`, which returns a valid `+05:30`.
- **An explicit `after_id=0` no longer falls back to page-number pagination.** The cursor was selected by testing the value rather than the presence of the parameter, so the documented first request of an export ran an ordinary date-ordered query and returned `total`/`page`/`total_pages` instead of the cursor metadata. The second batch then switched to ID ascending, skipping or duplicating records across the seam. An omitted parameter and an explicit `0` are now distinguished, and the OpenAPI schema no longer declares a `default` for `after_id` — a generated client materialising that default would have silently forced every request into cursor mode.
- **`total_remaining` on `/comments`, `/categories` and `/tags` counted the whole collection.** Those endpoints count with a separate query, which ran after the cursor filter had been removed, so a client could not tell how much was actually left to read. The count now applies the same cursor clause. On `/comments` it also varies `cache_domain` per cursor: the clause comes from a filter, which `WP_Comment_Query` does not put in its cache key, so on a site with a persistent object cache the filtered count would otherwise be served from another page's entry.
- **`has_more` no longer costs an extra empty request.** It was computed as "the page came back full", which is indistinguishable from "the remaining records happen to be exactly `per_page`". Since `total_remaining` is computed alongside it, it is now exact.
- **Block-theme menu hierarchy is preserved.** The block flattener never propagated the parent through its recursive call and gave every entry `id: 0`, so submenus came back as root-level items. Entries now carry a synthetic per-menu id — block links have no post ID of their own — and the real parent, and `order` reflects reading order instead of being fixed at 0. `core/page-list` likewise keeps the pages' own `post_parent` hierarchy, and is capped so a site with a large page tree does not load all of it into memory on an endpoint that has no pagination.
- **`location_labels` on `/menus` has a stable JSON type.** It was a slug-to-label map for menus with an assigned location but an empty PHP array otherwise, which encodes as `[]` rather than `{}` — two different types on one field, enough to break a typed consumer.
- **`/cpt/{type}` reads on the same terms as everything else.** It built its `WP_Query` by hand, with its own hardcoded five-status list and no cursor, so custom post types — often the bulk of a site's content — could not be walked the way posts and pages can. It now goes through the shared helper. The OpenAPI schema, which described that path with hand-written parameters of its own, was missing `after_id` and listed a `status` enum without `future` and `trash`; it reuses the shared pagination parameters now, so schema and controller agree.
- The `Retry-After` filter added on a 429 attached a closure to `rest_post_dispatch` that was never removed. Harmless within a single request, but it would have leaked onto any sub-request sharing the PHP process. It now removes itself after the dispatch it was added for.
- **`GET /comments` ran without a `LIMIT`.** Called with neither `per_page` nor `after_id` — the documented way to fetch a post's comments — `WP_Comment_Query` loaded every comment on the site into memory. A response is now always bounded, defaulting to 100 records.
- **`GET /users` cost one query per user.** `count_user_posts()` is neither batched nor memoised, so a page of 100 users meant 100 extra `SELECT COUNT(*)` round trips. The counts arrive from a single grouped query now: measured on the local stack, a 21-user page went from 40 queries to 20, and the per-user slope from 1.0 to ~0.1.
- **`site_uuid` could be handed out without being stored.** The first two concurrent `/site/full` requests each generated a UUID and both called `update_option()`; the one that lost returned an identifier that was never persisted, so a consumer importing that site could record two different source identities. Initialisation now goes through `add_option()`, which is atomic, and re-reads the stored value when it loses the race.
- `prepare_attachment()` resolved the attachment URL and the alt text twice per record — 200 redundant calls on a 100-item page.
- **The response contract now matches across endpoints.** Media gained `link`, `modified`, `parent` and `author` (keeping `post_parent` and `author_id` so existing consumers keep working); CPT items gained `post_date_gmt`, `post_modified_gmt`, `comment_status`, `menu_order` and `content_rendered`; posts gained `parent`, always 0 but present so one client can read one field name for every type.

### Security
- **List endpoints no longer hand out content the single-record route refuses.** `GET /posts/{id}` has always checked `edit_post` on the requested ID, but a list has no ID to check, so `GET /posts?status=draft` returned every other author's drafts and private posts in full — the same records the single route answered with `403 Cannot read this post`. Lists now apply WordPress's own rule: public statuses are everyone's, everything else only if you are its author. A credential holding `edit_others_posts` for that content type — Editor, Administrator, and so any real export — is unaffected. Applies to `/posts`, `/pages`, `/media` and `/cpt/{type}`.
- **`/pages` and `GET /media` were gated on the wrong capability.** Pages were behind `edit_posts` and the media library behind `edit_posts`, while WordPress protects them with `edit_pages` and `upload_files`: a Contributor could read, over the API, screens the admin UI does not even show them. Both now require the capability WordPress requires.
- **OAuth2 scopes are enforced.** The scope travelled from the client to the token and was never compared against anything: a token issued for `edit_posts` reached `/users`, `/site/full`, `/menus` and the plugin and update routes, as long as the person who authorised it was an administrator. A token now grants only the capabilities its scope names. The scope is normalised against a fixed vocabulary at issue time — a client cannot invent permissions — and an empty scope falls back to `edit_posts`. The vocabulary is published in the OpenAPI document. **Existing tokens keep working for `edit_posts`-level calls and must be re-authorised with a wider scope to reach the rest.** The check also covers `/tools/execute`: MCP builds its sub-requests by calling controller methods directly rather than through `rest_do_request()`, so the scope check had to be forwarded into each of them explicitly (`WPAIB_Auth::request_can()`), and the `Authorization` header now travels with every sub-request — without it a scope-restricted token could reach the same capability through a tool call that a direct REST request to the same route would refuse.
- **`/comments` no longer inherits the wrong visibility.** It filtered by the capability of whoever is asking, but never checked whether that person can see the *parent post* — an Author's key could read comments left on another author's draft, and a comment attached to a password-protected post's media file leaked because WordPress stores the password on the container post, not on the attachment row itself (which is always empty). Comment visibility now walks to the parent post — including through `attachment`'s `inherit` status — and applies uniformly to `/comments`, per-post comment lists, comment counts, and comment search.
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
