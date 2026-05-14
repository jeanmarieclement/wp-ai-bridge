# WP AI Bridge — Nuovi Endpoint: Design Spec

**Data:** 2026-05-13  
**Stato:** Approvato  
**Approccio:** C — Estendi controller esistenti + nuovi file per dominio

---

## Contesto

Il plugin WP AI Bridge espone un set di tool MCP per permettere agli agenti AI di interagire con WordPress. Questa spec aggiunge 11 nuovi tool per coprire i gap principali: pagine, ricerca cross-content, info sito, gestione media avanzata, creazione tag, bulk operations e pianificazione post.

---

## Scope

### Nuovi tool (11)

| Tool | Dominio |
|------|---------|
| `get_pages` | Pages |
| `get_page` | Pages |
| `create_page` | Pages |
| `update_page` | Pages |
| `delete_page` | Pages |
| `create_tag` | Taxonomy |
| `get_media` | Media |
| `delete_media` | Media |
| `bulk_update_posts` | Posts |
| `get_site_info` | Site |
| `search` | Search |

### Estensioni tool esistenti

- `create_post` → aggiunge param `date` (ISO 8601) per pianificazione
- `update_post` → aggiunge param `date` (ISO 8601) per pianificazione

---

## Architettura

### Nuovi file

```
includes/endpoints/class-wpaib-pages-controller.php
includes/endpoints/class-wpaib-site-controller.php
includes/endpoints/class-wpaib-search-controller.php
```

### File modificati

```
includes/endpoints/class-wpaib-taxonomy-controller.php  ← + create_tag
includes/endpoints/class-wpaib-media-controller.php     ← + get_media, delete_media
includes/endpoints/class-wpaib-posts-controller.php     ← + bulk_update_posts, date param
includes/endpoints/class-wpaib-mcp-controller.php       ← + 11 case + 11 tool definitions
wp-ai-bridge.php                                        ← autoload 3 nuovi controller
```

Il MCP controller rimane orchestratore puro: ogni case del switch istanzia il controller di dominio e delega.

---

## Schemi Tool

### Pages

```
get_pages(
  status?   : enum[any, publish, draft, pending, private]  default: any
  per_page? : integer  default: 10, max: 100
  page?     : integer  default: 1
) → {
  items: [{id, title, slug, status, content, excerpt, author,
           date, modified, parent, featured_media, link}],
  total, total_pages, page
}

get_page(id: integer) → stessa struttura singolo item

create_page(
  title     : string  required
  content?  : string
  status?   : enum[publish, draft, pending, private]  default: draft
  parent_id?: integer
) → post object

update_page(
  id        : integer  required
  title?    : string
  content?  : string
  status?   : enum[publish, draft, pending, private]
  parent_id?: integer
) → post object

delete_page(
  id    : integer  required
  force?: boolean  default: false
) → {deleted: bool, id: integer}
```

### Taxonomy

```
create_tag(
  name        : string  required
  slug?       : string
  description?: string
) → {id, name, slug, description, count}
```

### Media

```
get_media(
  per_page?  : integer  default: 10, max: 100
  page?      : integer  default: 1
  mime_type? : string   es. "image/jpeg"
) → {
  items: [{id, title, url, mime_type, date, alt, caption}],
  total, total_pages, page
}

delete_media(
  id    : integer  required
  force?: boolean  default: false
) → {deleted: bool, id: integer}
```

### Posts (estensione)

```
create_post(... + date?: string ISO 8601)
  Se date è futura e status non specificato → status auto = "future"

update_post(... + date?: string ISO 8601)
  Stessa logica

bulk_update_posts(
  ids    : integer[]  required
  status : enum[publish, draft, pending, private]  required
) → {
  updated: integer[],
  failed:  [{id, error}]
}
```

### Site

```
get_site_info() → {
  name        : string,
  tagline     : string,
  url         : string,
  language    : string,     // es. "it_IT"
  timezone    : string,     // es. "Europe/Rome"
  admin_email : string,
  wp_version  : string,
  active_theme: string,
  posts_count : integer,
  pages_count : integer,
  users_count : integer
}
```

### Search

```
search(
  query    : string    required
  types?   : array     enum items: posts, pages, media, comments, terms
             default: [posts, pages]
  per_page?: integer   default: 10, max: 50
) → {
  results: [{
    type    : string,
    id      : integer,
    title   : string,
    url     : string,
    excerpt?: string
  }]
  total: integer
}
```

---

## Error Handling

| Codice | Trigger |
|--------|---------|
| `400 wpaib_missing_id` | ID richiesto assente |
| `400 wpaib_missing_query` | `search` senza `query` |
| `400 wpaib_invalid_date` | `date` non parsabile come ISO 8601 |
| `400 wpaib_missing_params` | Parametri required assenti |
| `403 wpaib_forbidden` | Permessi insufficienti (generico, anti-enumeration) |
| `404` | Risorsa non trovata (da WP core) |

`bulk_update_posts`: errori parziali non bloccano. Risposta sempre `200` con `{updated[], failed[]}`.

---

## Permessi

| Tool | Capability WP |
|------|---------------|
| `get_pages`, `get_page` | `edit_posts` |
| `create_page`, `update_page` | `edit_pages` |
| `delete_page` | `delete_pages` |
| `create_tag` | `manage_categories` |
| `get_media` | `edit_posts` |
| `delete_media` | `delete_posts` |
| `bulk_update_posts` | `edit_posts` |
| `get_site_info` | `edit_posts` |
| `search` | `edit_posts` |

---

## Convenzioni (coerenza con codebase)

- Prefisso classi: `WPAIB_`
- File: `class-wpaib-*.php` kebab-case
- Ogni method pubblico ha `@param` e `@return` PHPDoc
- Sanitizzazione input: `absint()` per ID, `sanitize_text_field()` per stringhe, `sanitize_key()` per enum
- Ogni case in `execute_tool()` istanzia il controller, crea `WP_REST_Request`, delega
- Nessuna dipendenza esterna
