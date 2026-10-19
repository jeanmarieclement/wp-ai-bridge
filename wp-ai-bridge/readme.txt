=== AI Bridge ===
Contributors: jmclement
Donate link: https://jmclement.net/
Tags: ai, rest-api, mcp, claude, chatgpt
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your site to external AI services (Claude, ChatGPT, MCP clients, automations) via secure per-user API keys or OAuth2.

== Description ==

AI Bridge turns your site into an AI-ready CMS by exposing secure, high-performance REST and Model Context Protocol (MCP) endpoints. Connect ChatGPT, Claude.ai, Anthropic models, Cursor, Roo Code, n8n, Make, or custom automation pipelines with granular permissions and enterprise-grade security.

= Highlights =

* **Dual Authentication**: Support for both per-user API keys (`X-API-Key`) and OAuth2 Authorization Code flow (`Authorization: Bearer`).
* **Full MCP (Model Context Protocol) Support**: 32 built-in tools for posts, pages, custom post types, categories, tags, comments, media, menus, theme, users, site settings, plugins, and updates.
* **Native MCP Stdio Connector**: Embedded lightweight PHP script (`mcp-connector.php`) allowing zero-Node.js, zero-dependency connection to local MCP clients (Cursor, Claude Desktop, Antigravity).
* **ChatGPT Custom GPTs / Actions Ready**: Dynamic OpenAPI 3.0.3 specification (`/wp-json/wpaib/v1/openapi.json`) with interactive tool filtering and automatic operation capping (<= 30 actions) to comply with ChatGPT action limits.
* **Full Site Export & Migration**: Stable cursor pagination (`after_id`), rendered HTML blocks (`content_rendered`), full comment trees, and media assets without offset memory drift.
* **Fine-Grained Security**: Capabilities mapped directly to WordPress core roles (`edit_posts`, `edit_pages`, `upload_files`, `moderate_comments`, `manage_options`).
* **Zero Dependencies**: Pure native PHP, no Composer required, minimal footprint.
* **Audit Logging & Rate Limiting**: Built-in 300 requests/minute rate limiter with `Retry-After` headers and complete database audit log.

= Available Endpoints & Tools =

* **Posts & Pages**: Full CRUD operations, categories, tags, featured images, rendered block content.
* **Custom Post Types (CPT)**: Auto-discovery and full CRUD for registered CPTs (WooCommerce products, portfolios, custom collections).
* **Media Library**: Upload images (base64 or multipart) with mime-type validation and metadata attachment.
* **Taxonomies**: Categories and tags management with hierarchical support.
* **Comments**: View, approve, mark as spam, reply, and bulk moderate comments.
* **Users & Appearance**: Read-only access to user profiles (passwords and hashes are never exposed), navigation menus (classic & FSE block themes), and active theme metadata.
* **Site Configuration**: Core site settings, timezone, permalinks, and persistent `site_uuid`.
* **Plugin & Update Management**: Check for updates, inspect changelogs, apply updates, and manage plugins (Administrator only).

== Installation ==

1. Upload the `wp-ai-bridge` folder to your `/wp-content/plugins/` directory, or install the plugin directly from the WordPress Admin plugin search.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. If using OAuth2 or MCP rewrite endpoints, visit **Settings -> Permalinks** and click **Save Changes** once to flush rewrite rules.

= API Key Quickstart =

1. In WordPress Admin, navigate to **Users -> Profile** (or edit any user).
2. Scroll to the **WP AI Bridge — API Keys** section.
3. Enter a label for the key (e.g. `Claude Assistant`) and click **Generate Key**.
4. Copy the generated key immediately (it is hashed with SHA-256 and will not be displayed again).
5. Use the key in your requests:
   `curl -H "X-API-Key: wpaib_..." https://your-site.com/wp-json/wpaib/v1/posts`

= OAuth2 Quickstart =

1. In WordPress Admin, navigate to **Settings -> WP AI Bridge -> OAuth2 Clients**.
2. Click **Create New Client**, provide a name and redirect URI (e.g. `https://claude.ai/api/mcp/auth_callback` or ChatGPT Actions callback).
3. Copy the Client ID and Client Secret to configure your external application.

== Frequently Asked Questions ==

= Does this plugin send data to external third-party servers? =
No. WP AI Bridge runs entirely on your WordPress server. It exposes endpoints and tools for external clients to connect to your site. It never "phones home" or transmits any data without your explicit invocation.

= Are API keys stored securely? =
Yes. API keys are generated with 32 cryptographically secure random bytes and stored in the database hashed using SHA-256. The plaintext key is shown only once upon creation.

= How do I connect WP AI Bridge to Claude.ai or ChatGPT? =
* **For ChatGPT Custom GPTs**: Go to **Settings -> WP AI Bridge**, filter the active tools (keeping <= 30 operations), copy the `/wp-json/wpaib/v1/openapi.json` URL, and paste it into ChatGPT Actions Schema.
* **For Claude Desktop / Cursor (Local MCP)**: Use the included `mcp-connector.php`:
  `php wp-content/plugins/wp-ai-bridge/mcp-connector.php https://your-site.com/wp-json/wpaib/v1 wpaib_your_key`

= What happens if I deactivate or uninstall the plugin? =
Deactivating the plugin simply stops the REST and MCP endpoints. Uninstalling the plugin via WordPress Admin removes the database tables (`wp_wpaib_*`), scheduled crons, and saved options cleanly.

== Screenshots ==

1. API Keys generation and management within the WordPress user profile.
2. Tool permissions, MCP connections, and real-time ChatGPT Actions operation counter.
3. OAuth2 client applications configuration and redirect URIs.

== Changelog ==

= 1.6.1 =
* Fixed: OpenAPI response status code map keys for ChatGPT Actions.
* Added: Dynamic MCP tools counter and ChatGPT compatibility badge in settings.
* Added: Bulk selection actions and category toggles in tool settings.
* Added: Dynamic openapi.json filtering based on active tools.

= 1.6.0 =
* Added: Cursor pagination (`after_id`) across all resource endpoints for drift-free exports.
* Added: `content_rendered` field with core block filter evaluation.
* Added: Read-only `/users`, `/menus` (classic & block FSE), `/theme`, and `/site/full` endpoints.
* Added: Persistent `site_uuid` generator.
* Added: Exact `Retry-After` header on 429 rate limit responses.
* Security: Enforced strict OAuth2 scopes and capability checking per post type.

= 1.5.1 =
* Fixed: Documented Claude.ai custom connector MCP auth callback URI.

= 1.4.0 =
* Added: MCP tools for WordPress update management (`get_updates`, `get_changelog`, `apply_update`, `bulk_update`).

= 1.3.0 =
* Added: Plugin management endpoints and MCP tools (`/plugins`, `get_plugins`, `activate_plugin`, `deactivate_plugin`, `delete_plugin`).
* Added: Update management endpoints (`/updates`).

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.6.1 =
Improves OpenAPI compatibility with ChatGPT Custom Actions and adds dynamic tool filtering in settings.
