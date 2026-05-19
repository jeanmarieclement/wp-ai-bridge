# Changelog

All notable changes to **WP AI Bridge** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
