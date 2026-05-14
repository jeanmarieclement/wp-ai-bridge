# WP AI Bridge — Nuovi Endpoint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere 11 nuovi tool MCP al plugin WP AI Bridge: CRUD pages, create_tag, get_media, delete_media, bulk_update_posts, get_site_info, search cross-content, e supporto `date` su create/update_post.

**Architecture:** Approccio C — estensione controller esistenti + 3 nuovi file per dominio. Il MCP controller rimane orchestratore puro. Ogni controller ha una responsabilità singola.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, WP REST API, zero dipendenze esterne.

**Test:** Tutti i test sono curl contro `http://localhost:8085/?rest_route=/wpaib/v1/tools/execute` con `X-API-Key` da `.env.local`.

---

## Variabili usate in tutti i test

```bash
API_KEY="wpaib_ed80b0ea1c10a363b2c30e13e924066ebc2d486a2afb6d92f492b880998677f7"
BASE="http://localhost:8085/?rest_route=/wpaib/v1/tools/execute"
```

---

## Task 1: Autoload 3 nuovi controller in `wp-ai-bridge.php`

**Files:**
- Modify: `wp-ai-bridge/wp-ai-bridge.php:38-44`

- [ ] **Step 1: Aggiungere i 3 require_once**

In `wp-ai-bridge.php`, dopo la riga `require_once WPAIB_PLUGIN_DIR . 'includes/endpoints/class-wpaib-taxonomy-controller.php';` aggiungere:

```php
require_once WPAIB_PLUGIN_DIR . 'includes/endpoints/class-wpaib-pages-controller.php';
require_once WPAIB_PLUGIN_DIR . 'includes/endpoints/class-wpaib-site-controller.php';
require_once WPAIB_PLUGIN_DIR . 'includes/endpoints/class-wpaib-search-controller.php';
```

- [ ] **Step 2: Verificare che WP non dia fatal error**

```bash
curl -s "http://localhost:8085/?rest_route=/wpaib/v1/tools" \
  -H "X-API-Key: $API_KEY" | python3 -c "import sys,json; d=json.load(sys.stdin); print('OK, tools:', len(d['tools']))"
```

Expected: `OK, tools: 13` (13 esistenti; diventerà 24 dopo Task 7)

- [ ] **Step 3: Commit**

```bash
git add wp-ai-bridge/wp-ai-bridge.php
git commit -m "chore: autoload pages, site, search controllers"
```

---

## Task 2: Creare `class-wpaib-pages-controller.php`

**Files:**
- Create: `wp-ai-bridge/includes/endpoints/class-wpaib-pages-controller.php`

- [ ] **Step 1: Creare il file**

```php
<?php
/**
 * Controller per gestione pagine WordPress.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint /pages (lista, crea, leggi, aggiorna, elimina).
 */
class WPAIB_Pages_Controller {

	/**
	 * Registra le route REST.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/pages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_pages' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
					'args'                => array(
						'status'   => array(
							'default'           => 'any',
							'sanitize_callback' => 'sanitize_key',
						),
						'per_page' => array(
							'default'           => 10,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_page' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_pages' ),
				),
			)
		);

		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/pages/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_page' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_page' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_pages' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_page' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'delete_pages' ),
				),
			)
		);
	}

	/**
	 * Lista pagine.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public function list_pages( WP_REST_Request $request ) {
		$status   = sanitize_key( $request->get_param( 'status' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$allowed_statuses = array( 'any', 'publish', 'draft', 'pending', 'private', 'future' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'any';
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => $status,
				'posts_per_page' => $per_page,
				'paged'          => $page,
			)
		);

		$items = array();
		foreach ( $query->posts as $p ) {
			$items[] = $this->prepare_page( $p );
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
			),
			200
		);
	}

	/**
	 * Recupera una singola pagina.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_page( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$page = get_post( $id );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'wpaib_not_found', __( 'Page not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Cannot read this page.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		return new WP_REST_Response( $this->prepare_page( $page ), 200 );
	}

	/**
	 * Crea una nuova pagina.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_page( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_Error( 'wpaib_invalid_body', __( 'Invalid JSON body.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$title = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';
		if ( empty( $title ) ) {
			return new WP_Error( 'wpaib_missing_title', __( 'Title is required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$content  = isset( $params['content'] ) ? wp_kses_post( $params['content'] ) : '';
		$status   = isset( $params['status'] ) ? sanitize_key( $params['status'] ) : 'draft';
		$parent   = isset( $params['parent_id'] ) ? absint( $params['parent_id'] ) : 0;

		$allowed_statuses = array( 'draft', 'pending', 'publish', 'private' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'draft';
		}
		if ( 'publish' === $status && ! current_user_can( 'publish_pages' ) ) {
			$status = 'draft';
		}

		$page_arr = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_type'    => 'page',
			'post_author'  => get_current_user_id(),
			'post_parent'  => $parent,
		);

		$page_id = wp_insert_post( $page_arr, true );
		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		return new WP_REST_Response( $this->prepare_page( get_post( $page_id ) ), 201 );
	}

	/**
	 * Aggiorna una pagina esistente.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_page( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$page = get_post( $id );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'wpaib_not_found', __( 'Page not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Cannot edit this page.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_Error( 'wpaib_invalid_body', __( 'Invalid JSON body.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		$update = array( 'ID' => $id );

		if ( isset( $params['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $params['title'] );
		}
		if ( isset( $params['content'] ) ) {
			$update['post_content'] = wp_kses_post( $params['content'] );
		}
		if ( isset( $params['status'] ) ) {
			$status           = sanitize_key( $params['status'] );
			$allowed_statuses = array( 'draft', 'pending', 'publish', 'private' );
			if ( in_array( $status, $allowed_statuses, true ) ) {
				if ( 'publish' === $status && ! current_user_can( 'publish_pages' ) ) {
					$status = 'draft';
				}
				$update['post_status'] = $status;
			}
		}
		if ( isset( $params['parent_id'] ) ) {
			$update['post_parent'] = absint( $params['parent_id'] );
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $this->prepare_page( get_post( $id ) ), 200 );
	}

	/**
	 * Elimina o cestina una pagina.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_page( WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$force = (bool) $request->get_param( 'force' );
		$page  = get_post( $id );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error( 'wpaib_not_found', __( 'Page not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'wpaib_forbidden', __( 'Cannot delete this page.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
		}

		$result = wp_delete_post( $id, $force );
		if ( ! $result ) {
			return new WP_Error( 'wpaib_delete_failed', __( 'Could not delete page.', 'wp-ai-bridge' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id, 'force' => $force ), 200 );
	}

	/**
	 * Prepara la rappresentazione di una pagina per la risposta.
	 *
	 * @param WP_Post $page Pagina.
	 * @return array
	 */
	private function prepare_page( $page ) {
		return array(
			'id'             => (int) $page->ID,
			'title'          => $page->post_title,
			'slug'           => $page->post_name,
			'status'         => $page->post_status,
			'content'        => $page->post_content,
			'excerpt'        => $page->post_excerpt,
			'author'         => (int) $page->post_author,
			'date'           => $page->post_date_gmt,
			'modified'       => $page->post_modified_gmt,
			'parent'         => (int) $page->post_parent,
			'featured_media' => (int) get_post_thumbnail_id( $page->ID ),
			'link'           => get_permalink( $page->ID ),
		);
	}
}
```

- [ ] **Step 2: Registrare le route in `class-wpaib-plugin.php`**

Aprire `wp-ai-bridge/includes/class-wpaib-plugin.php` e aggiungere dopo la registrazione di `WPAIB_Taxonomy_Controller`:

```php
( new WPAIB_Pages_Controller() )->register_routes();
```

- [ ] **Step 3: Testare `get_pages`**

```bash
curl -s -X POST "$BASE" \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"tool":"get_pages","arguments":{}}'
```

Expected: `{"items":[...],"total":...,"total_pages":...,"page":1}`

- [ ] **Step 4: Testare `create_page`**

```bash
curl -s -X POST "$BASE" \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"tool":"create_page","arguments":{"title":"Pagina Test AI","content":"Contenuto generato da AI.","status":"draft"}}'
```

Expected: `{"id":...,"title":"Pagina Test AI","status":"draft",...}`

Salvare l'ID restituito come `PAGE_ID`.

- [ ] **Step 5: Testare `update_page`**

```bash
curl -s -X POST "$BASE" \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d "{\"tool\":\"update_page\",\"arguments\":{\"id\":$PAGE_ID,\"title\":\"Pagina Aggiornata\",\"status\":\"publish\"}}"
```

Expected: `{"id":...,"title":"Pagina Aggiornata","status":"publish",...}`

- [ ] **Step 6: Testare `delete_page`**

```bash
curl -s -X POST "$BASE" \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d "{\"tool\":\"delete_page\",\"arguments\":{\"id\":$PAGE_ID,\"force\":true}}"
```

Expected: `{"deleted":true,"id":...,"force":true}`

- [ ] **Step 7: Commit**

```bash
git add wp-ai-bridge/includes/endpoints/class-wpaib-pages-controller.php \
        wp-ai-bridge/includes/class-wpaib-plugin.php
git commit -m "feat: add pages controller with full CRUD"
```

---

## Task 3: Creare `class-wpaib-site-controller.php`

**Files:**
- Create: `wp-ai-bridge/includes/endpoints/class-wpaib-site-controller.php`

- [ ] **Step 1: Creare il file**

```php
<?php
/**
 * Controller per informazioni del sito WordPress.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint /site (info generali sito).
 */
class WPAIB_Site_Controller {

	/**
	 * Registra le route REST.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/site',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_site_info' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
				),
			)
		);
	}

	/**
	 * Restituisce informazioni complete sul sito.
	 *
	 * @return WP_REST_Response
	 */
	public function get_site_info() {
		$theme = wp_get_theme();

		return new WP_REST_Response(
			array(
				'name'         => get_bloginfo( 'name' ),
				'tagline'      => get_bloginfo( 'description' ),
				'url'          => get_bloginfo( 'url' ),
				'language'     => get_bloginfo( 'language' ),
				'timezone'     => get_option( 'timezone_string' ) ?: 'UTC+' . ( get_option( 'gmt_offset' ) >= 0 ? '' : '' ) . get_option( 'gmt_offset' ),
				'admin_email'  => get_bloginfo( 'admin_email' ),
				'wp_version'   => get_bloginfo( 'version' ),
				'active_theme' => $theme->get( 'Name' ),
				'posts_count'  => (int) wp_count_posts( 'post' )->publish,
				'pages_count'  => (int) wp_count_posts( 'page' )->publish,
				'users_count'  => (int) count_users()['total_users'],
			),
			200
		);
	}
}
```

- [ ] **Step 2: Registrare le route in `class-wpaib-plugin.php`**

```php
( new WPAIB_Site_Controller() )->register_routes();
```

- [ ] **Step 3: Testare `get_site_info`**

```bash
curl -s -X POST "$BASE" \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"tool":"get_site_info","arguments":{}}'
```

Expected: `{"name":"...","tagline":"...","url":"http://localhost:8085","language":"...","timezone":"...","wp_version":"...","active_theme":"...","posts_count":...,"pages_count":...,"users_count":...}`

- [ ] **Step 4: Commit**

```bash
git add wp-ai-bridge/includes/endpoints/class-wpaib-site-controller.php \
        wp-ai-bridge/includes/class-wpaib-plugin.php
git commit -m "feat: add site controller with get_site_info"
```

---

## Task 4: Creare `class-wpaib-search-controller.php`

**Files:**
- Create: `wp-ai-bridge/includes/endpoints/class-wpaib-search-controller.php`

- [ ] **Step 1: Creare il file**

```php
<?php
/**
 * Controller per ricerca cross-content.
 *
 * @package WPAIBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint /search (ricerca su post, pagine, media, commenti, termini).
 */
class WPAIB_Search_Controller {

	/**
	 * Registra le route REST.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WPAIB_API_NAMESPACE,
			'/search',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'search' ),
					'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
					'args'                => array(
						'query'    => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'types'    => array(
							'default' => array( 'posts', 'pages' ),
						),
						'per_page' => array(
							'default'           => 10,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Esegue ricerca cross-content.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search( WP_REST_Request $request ) {
		$query    = sanitize_text_field( $request->get_param( 'query' ) );
		$per_page = min( 50, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$types    = $request->get_param( 'types' );

		if ( empty( $query ) ) {
			return new WP_Error( 'wpaib_missing_query', __( 'Search query is required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
		}

		if ( ! is_array( $types ) ) {
			$types = array( 'posts', 'pages' );
		}

		$allowed_types = array( 'posts', 'pages', 'media', 'comments', 'terms' );
		$types         = array_intersect( $types, $allowed_types );
		if ( empty( $types ) ) {
			$types = array( 'posts', 'pages' );
		}

		$results = array();

		if ( in_array( 'posts', $types, true ) ) {
			$results = array_merge( $results, $this->search_post_type( $query, 'post', $per_page ) );
		}
		if ( in_array( 'pages', $types, true ) ) {
			$results = array_merge( $results, $this->search_post_type( $query, 'page', $per_page ) );
		}
		if ( in_array( 'media', $types, true ) ) {
			$results = array_merge( $results, $this->search_post_type( $query, 'attachment', $per_page ) );
		}
		if ( in_array( 'comments', $types, true ) ) {
			$results = array_merge( $results, $this->search_comments( $query, $per_page ) );
		}
		if ( in_array( 'terms', $types, true ) ) {
			$results = array_merge( $results, $this->search_terms( $query, $per_page ) );
		}

		return new WP_REST_Response(
			array(
				'results' => $results,
				'total'   => count( $results ),
			),
			200
		);
	}

	/**
	 * Cerca in un post_type specifico.
	 *
	 * @param string $query     Query di ricerca.
	 * @param string $post_type Tipo di post.
	 * @param int    $per_page  Numero risultati.
	 * @return array
	 */
	private function search_post_type( $query, $post_type, $per_page ) {
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				's'              => $query,
				'posts_per_page' => $per_page,
			)
		);

		$results = array();
		foreach ( $posts as $post ) {
			$type = 'post';
			if ( 'page' === $post->post_type ) {
				$type = 'page';
			} elseif ( 'attachment' === $post->post_type ) {
				$type = 'media';
			}

			$results[] = array(
				'type'    => $type,
				'id'      => (int) $post->ID,
				'title'   => $post->post_title,
				'url'     => get_permalink( $post->ID ),
				'excerpt' => wp_trim_words( $post->post_content, 20 ),
			);
		}

		return $results;
	}

	/**
	 * Cerca nei commenti.
	 *
	 * @param string $query    Query di ricerca.
	 * @param int    $per_page Numero risultati.
	 * @return array
	 */
	private function search_comments( $query, $per_page ) {
		$comments = get_comments(
			array(
				'search'  => $query,
				'number'  => $per_page,
				'status'  => 'approve',
			)
		);

		$results = array();
		foreach ( $comments as $comment ) {
			$results[] = array(
				'type'    => 'comment',
				'id'      => (int) $comment->comment_ID,
				'title'   => wp_trim_words( $comment->comment_content, 10 ),
				'url'     => get_comment_link( $comment->comment_ID ),
				'excerpt' => wp_trim_words( $comment->comment_content, 20 ),
			);
		}

		return $results;
	}

	/**
	 * Cerca in categorie e tag.
	 *
	 * @param string $query    Query di ricerca.
	 * @param int    $per_page Numero risultati.
	 * @return array
	 */
	private function search_terms( $query, $per_page ) {
		$terms = get_terms(
			array(
				'taxonomy'   => array( 'category', 'post_tag' ),
				'search'     => $query,
				'number'     => $per_page,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$results = array();
		foreach ( $terms as $term ) {
			$results[] = array(
				'type'  => 'term',
				'id'    => (int) $term->term_id,
				'title' => $term->name,
				'url'   => get_term_link( $term ),
			);
		}

		return $results;
	}
}
```

- [ ] **Step 2: Registrare le route in `class-wpaib-plugin.php`**

```php
( new WPAIB_Search_Controller() )->register_routes();
```

- [ ] **Step 3: Testare `search`**

```bash
curl -s -X POST "$BASE" \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"tool":"search","arguments":{"query":"MCP","types":["posts","pages","terms"]}}'
```

Expected: `{"results":[...],"total":...}` con almeno 1 risultato (articolo 11 contiene "MCP")

- [ ] **Step 4: Commit**

```bash
git add wp-ai-bridge/includes/endpoints/class-wpaib-search-controller.php \
        wp-ai-bridge/includes/class-wpaib-plugin.php
git commit -m "feat: add search controller for cross-content search"
```

---

## Task 5: Aggiungere `get_media` e `delete_media` a `class-wpaib-media-controller.php`

**Files:**
- Modify: `wp-ai-bridge/includes/endpoints/class-wpaib-media-controller.php`

- [ ] **Step 1: Aggiungere route GET e DELETE in `register_routes()`**

Nel metodo `register_routes()`, dopo la route `/media` POST esistente, aggiungere:

```php
register_rest_route(
    WPAIB_API_NAMESPACE,
    '/media',
    array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'list_media' ),
            'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
            'args'                => array(
                'per_page'  => array(
                    'default'           => 10,
                    'sanitize_callback' => 'absint',
                ),
                'page'      => array(
                    'default'           => 1,
                    'sanitize_callback' => 'absint',
                ),
                'mime_type' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ),
    )
);

register_rest_route(
    WPAIB_API_NAMESPACE,
    '/media/(?P<id>\d+)',
    array(
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => array( $this, 'delete_media' ),
            'permission_callback' => WPAIB_Auth::require_cap( 'delete_posts' ),
        ),
    )
);
```

- [ ] **Step 2: Aggiungere il metodo `list_media()`**

Prima di `create_attachment()` aggiungere:

```php
/**
 * Lista i file nella libreria media.
 *
 * @param WP_REST_Request $request Richiesta.
 * @return WP_REST_Response
 */
public function list_media( WP_REST_Request $request ) {
    $per_page  = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
    $page      = max( 1, (int) $request->get_param( 'page' ) );
    $mime_type = sanitize_text_field( $request->get_param( 'mime_type' ) );

    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => $per_page,
        'paged'          => $page,
    );

    if ( ! empty( $mime_type ) ) {
        $args['post_mime_type'] = $mime_type;
    }

    $query = new WP_Query( $args );

    $items = array();
    foreach ( $query->posts as $attachment ) {
        $items[] = array(
            'id'        => (int) $attachment->ID,
            'title'     => $attachment->post_title,
            'url'       => wp_get_attachment_url( $attachment->ID ),
            'mime_type' => $attachment->post_mime_type,
            'date'      => $attachment->post_date_gmt,
            'alt'       => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
            'caption'   => $attachment->post_excerpt,
        );
    }

    return new WP_REST_Response(
        array(
            'items'       => $items,
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'page'        => $page,
        ),
        200
    );
}
```

- [ ] **Step 3: Aggiungere il metodo `delete_media()`**

```php
/**
 * Elimina o cestina un file dalla libreria media.
 *
 * @param WP_REST_Request $request Richiesta.
 * @return WP_REST_Response|WP_Error
 */
public function delete_media( WP_REST_Request $request ) {
    $id         = (int) $request['id'];
    $force      = (bool) $request->get_param( 'force' );
    $attachment = get_post( $id );

    if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
        return new WP_Error( 'wpaib_not_found', __( 'Media not found.', 'wp-ai-bridge' ), array( 'status' => 404 ) );
    }
    if ( ! current_user_can( 'delete_post', $id ) ) {
        return new WP_Error( 'wpaib_forbidden', __( 'Cannot delete this media.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
    }

    $result = wp_delete_attachment( $id, $force );
    if ( ! $result ) {
        return new WP_Error( 'wpaib_delete_failed', __( 'Could not delete media.', 'wp-ai-bridge' ), array( 'status' => 500 ) );
    }

    return new WP_REST_Response( array( 'deleted' => true, 'id' => $id, 'force' => $force ), 200 );
}
```

- [ ] **Step 4: Testare `get_media`**

```bash
curl -s -X POST "$BASE" \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"tool":"get_media","arguments":{"per_page":5}}'
```

Expected: `{"items":[...],"total":...}`

- [ ] **Step 5: Testare `delete_media`** (usare l'ID 14 del test.png caricato prima, o caricare un nuovo file di test)

```bash
curl -s -X POST "$BASE" \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"tool":"delete_media","arguments":{"id":14,"force":true}}'
```

Expected: `{"deleted":true,"id":14,"force":true}`

- [ ] **Step 6: Commit**

```bash
git add wp-ai-bridge/includes/endpoints/class-wpaib-media-controller.php
git commit -m "feat: add list_media and delete_media to media controller"
```

---

## Task 6: Aggiungere `bulk_update_posts` e `date` param a `class-wpaib-posts-controller.php`

**Files:**
- Modify: `wp-ai-bridge/includes/endpoints/class-wpaib-posts-controller.php`

- [ ] **Step 1: Aggiungere route `/posts/bulk` in `register_routes()`**

Dopo l'ultima `register_rest_route` esistente aggiungere:

```php
register_rest_route(
    WPAIB_API_NAMESPACE,
    '/posts/bulk',
    array(
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'bulk_update_posts' ),
            'permission_callback' => WPAIB_Auth::require_cap( 'edit_posts' ),
        ),
    )
);
```

- [ ] **Step 2: Aggiungere il metodo `bulk_update_posts()`**

Dopo `delete_post()` e prima di `prepare_post()` aggiungere:

```php
/**
 * Aggiorna lo stato di più post in blocco.
 *
 * @param WP_REST_Request $request Richiesta.
 * @return WP_REST_Response|WP_Error
 */
public function bulk_update_posts( WP_REST_Request $request ) {
    $params = $request->get_json_params();

    if ( empty( $params['ids'] ) || ! is_array( $params['ids'] ) || empty( $params['status'] ) ) {
        return new WP_Error( 'wpaib_missing_params', __( 'IDs array and status are required.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
    }

    $status          = sanitize_key( $params['status'] );
    $allowed_statuses = array( 'publish', 'draft', 'pending', 'private' );
    if ( ! in_array( $status, $allowed_statuses, true ) ) {
        return new WP_Error( 'wpaib_invalid_status', __( 'Invalid status.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
    }
    if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) {
        return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
    }

    $updated = array();
    $failed  = array();

    foreach ( $params['ids'] as $id ) {
        $id   = absint( $id );
        $post = get_post( $id );

        if ( ! $post || 'post' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
            $failed[] = array( 'id' => $id, 'error' => 'not_found_or_forbidden' );
            continue;
        }

        $result = wp_update_post( array( 'ID' => $id, 'post_status' => $status ), true );
        if ( is_wp_error( $result ) ) {
            $failed[] = array( 'id' => $id, 'error' => $result->get_error_message() );
        } else {
            $updated[] = $id;
        }
    }

    return new WP_REST_Response( array( 'updated' => $updated, 'failed' => $failed ), 200 );
}
```

- [ ] **Step 3: Aggiungere supporto `date` in `create_post()`**

Nel metodo `create_post()`, dopo la riga `'post_author' => get_current_user_id(),` nella costruzione di `$post_arr`, aggiungere:

```php
// Pianificazione: se date è in futuro e status non impostato → future.
if ( ! empty( $params['date'] ) ) {
    $timestamp = strtotime( sanitize_text_field( $params['date'] ) );
    if ( false === $timestamp ) {
        return new WP_Error( 'wpaib_invalid_date', __( 'Invalid date format. Use ISO 8601.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
    }
    $post_arr['post_date']     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) );
    $post_arr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
    if ( $timestamp > time() && 'publish' !== $status ) {
        $post_arr['post_status'] = 'future';
    }
}
```

- [ ] **Step 4: Aggiungere supporto `date` in `update_post()`**

Nel metodo `update_post()`, dopo il blocco `if ( isset( $params['status'] ) )`, aggiungere:

```php
if ( isset( $params['date'] ) ) {
    $timestamp = strtotime( sanitize_text_field( $params['date'] ) );
    if ( false === $timestamp ) {
        return new WP_Error( 'wpaib_invalid_date', __( 'Invalid date format. Use ISO 8601.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
    }
    $update['post_date']     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) );
    $update['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
    if ( $timestamp > time() && empty( $update['post_status'] ) ) {
        $update['post_status'] = 'future';
    }
}
```

- [ ] **Step 5: Testare `bulk_update_posts`**

```bash
curl -s -X POST "$BASE" \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"tool":"bulk_update_posts","arguments":{"ids":[1,11],"status":"draft"}}'
```

Expected: `{"updated":[1,11],"failed":[]}`

Ripristinare dopo il test:

```bash
curl -s -X POST "$BASE" \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"tool":"bulk_update_posts","arguments":{"ids":[1,11],"status":"publish"}}'
```

- [ ] **Step 6: Testare `create_post` con `date` futuro**

```bash
curl -s -X POST "$BASE" \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"tool":"create_post","arguments":{"title":"Post Pianificato","content":"Testo.","date":"2027-01-01T10:00:00Z"}}'
```

Expected: `{"id":...,"status":"future","date":"2027-01-01 10:00:00",...}` — poi eliminare il post con `delete_post`.

- [ ] **Step 7: Commit**

```bash
git add wp-ai-bridge/includes/endpoints/class-wpaib-posts-controller.php
git commit -m "feat: add bulk_update_posts and date scheduling to posts controller"
```

---

## Task 7: Aggiungere tool definitions in `class-wpaib-mcp-controller.php`

**Files:**
- Modify: `wp-ai-bridge/includes/endpoints/class-wpaib-mcp-controller.php:54-315` (metodo `get_tools()`)

- [ ] **Step 1: Aggiungere 11 tool definitions nell'array restituito da `get_tools()`**

Aggiungere prima di `return new WP_REST_Response( array( 'tools' => $tools ), 200 );`:

```php
// Pages.
array(
    'name'        => 'get_pages',
    'description' => 'Recupera una lista di pagine WordPress filtrabili per stato e impaginazione.',
    'inputSchema' => array(
        'type'       => 'object',
        'properties' => array(
            'status'   => array(
                'type'        => 'string',
                'enum'        => array( 'any', 'publish', 'draft', 'pending', 'private' ),
                'description' => 'Stato delle pagine (default: any)',
            ),
            'per_page' => array(
                'type'        => 'integer',
                'description' => 'Numero per pagina (default: 10, max: 100)',
            ),
            'page'     => array(
                'type'        => 'integer',
                'description' => 'Pagina (default: 1)',
            ),
        ),
    ),
),
array(
    'name'        => 'get_page',
    'description' => 'Recupera i dettagli di una singola pagina tramite ID.',
    'inputSchema' => array(
        'type'       => 'object',
        'properties' => array(
            'id' => array(
                'type'        => 'integer',
                'description' => 'ID della pagina',
            ),
        ),
        'required'   => array( 'id' ),
    ),
),
array(
    'name'        => 'create_page',
    'description' => 'Crea una nuova pagina WordPress.',
    'inputSchema' => array(
        'type'       => 'object',
        'properties' => array(
            'title'     => array(
                'type'        => 'string',
                'description' => 'Titolo della pagina',
            ),
            'content'   => array(
                'type'        => 'string',
                'description' => 'Contenuto HTML della pagina',
            ),
            'status'    => array(
                'type'        => 'string',
                'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
                'description' => 'Stato (default: draft)',
            ),
            'parent_id' => array(
                'type'        => 'integer',
                'description' => 'ID della pagina genitore (opzionale)',
            ),
        ),
        'required'   => array( 'title' ),
    ),
),
array(
    'name'        => 'update_page',
    'description' => 'Aggiorna una pagina esistente.',
    'inputSchema' => array(
        'type'       => 'object',
        'properties' => array(
            'id'        => array(
                'type'        => 'integer',
                'description' => 'ID della pagina da aggiornare',
            ),
            'title'     => array(
                'type'        => 'string',
                'description' => 'Nuovo titolo',
            ),
            'content'   => array(
                'type'        => 'string',
                'description' => 'Nuovo contenuto HTML',
            ),
            'status'    => array(
                'type'        => 'string',
                'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
                'description' => 'Nuovo stato',
            ),
            'parent_id' => array(
                'type'        => 'integer',
                'description' => 'Nuovo ID genitore',
            ),
        ),
        'required'   => array( 'id' ),
    ),
),
array(
    'name'        => 'delete_page',
    'description' => 'Elimina o cestina una pagina.',
    'inputSchema' => array(
        'type'       => 'object',
        'properties' => array(
            'id'    => array(
                'type'        => 'integer',
                'description' => 'ID della pagina',
            ),
            'force' => array(
                'type'        => 'boolean',
                'description' => 'Se true elimina definitivamente (default: false)',
            ),
        ),
        'required'   => array( 'id' ),
    ),
),
// Tag.
array(
    'name'        => 'create_tag',
    'description' => 'Crea un nuovo tag WordPress.',
    'inputSchema' => array(
        'type'       => 'object',
        'properties' => array(
            'name'        => array(
                'type'        => 'string',
                'description' => 'Nome del tag',
            ),
            'slug'        => array(
                'type'        => 'string',
                'description' => 'Slug (opzionale)',
            ),
            'description' => array(
                'type'        => 'string',
                'description' => 'Descrizione (opzionale)',
            ),
        ),
        'required'   => array( 'name' ),
    ),
),
// Media.
array(
    'name'        => 'get_media',
    'description' => 'Recupera la lista dei file nella libreria media WordPress.',
    'inputSchema' => array(
        'type'       => 'object',
        'properties' => array(
            'per_page'  => array(
                'type'        => 'integer',
                'description' => 'Numero per pagina (default: 10, max: 100)',
            ),
            'page'      => array(
                'type'        => 'integer',
                'description' => 'Pagina (default: 1)',
            ),
            'mime_type' => array(
                'type'        => 'string',
                'description' => 'Filtra per MIME type (es. image/jpeg)',
            ),
        ),
    ),
),
array(
    'name'        => 'delete_media',
    'description' => 'Elimina o cestina un file dalla libreria media.',
    'inputSchema' => array(
        'type'       => 'object',
        'properties' => array(
            'id'    => array(
                'type'        => 'integer',
                'description' => 'ID del file media',
            ),
            'force' => array(
                'type'        => 'boolean',
                'description' => 'Se true elimina definitivamente (default: false)',
            ),
        ),
        'required'   => array( 'id' ),
    ),
),
// Posts bulk.
array(
    'name'        => 'bulk_update_posts',
    'description' => 'Cambia lo stato di più articoli contemporaneamente.',
    'inputSchema' => array(
        'type'       => 'object',
        'properties' => array(
            'ids'    => array(
                'type'        => 'array',
                'items'       => array( 'type' => 'integer' ),
                'description' => 'Array di ID articoli',
            ),
            'status' => array(
                'type'        => 'string',
                'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
                'description' => 'Nuovo stato da applicare a tutti',
            ),
        ),
        'required'   => array( 'ids', 'status' ),
    ),
),
// Site.
array(
    'name'        => 'get_site_info',
    'description' => 'Recupera informazioni complete sul sito WordPress (nome, URL, tema, versione, statistiche).',
    'inputSchema' => array(
        'type'       => 'object',
        'properties' => array(),
    ),
),
// Search.
array(
    'name'        => 'search',
    'description' => 'Cerca contenuti nel sito WordPress su post, pagine, media, commenti e termini.',
    'inputSchema' => array(
        'type'       => 'object',
        'properties' => array(
            'query'    => array(
                'type'        => 'string',
                'description' => 'Testo da cercare',
            ),
            'types'    => array(
                'type'        => 'array',
                'items'       => array(
                    'type' => 'string',
                    'enum' => array( 'posts', 'pages', 'media', 'comments', 'terms' ),
                ),
                'description' => 'Tipi di contenuto (default: [posts, pages])',
            ),
            'per_page' => array(
                'type'        => 'integer',
                'description' => 'Massimo risultati per tipo (default: 10, max: 50)',
            ),
        ),
        'required'   => array( 'query' ),
    ),
),
```

- [ ] **Step 2: Verificare che i tool siano esposti**

```bash
curl -s "http://localhost:8085/?rest_route=/wpaib/v1/tools" \
  -H "X-API-Key: $API_KEY" | python3 -c "
import sys,json
d=json.load(sys.stdin)
names=[t['name'] for t in d['tools']]
print('Totale:', len(names))
for n in names: print(' -', n)
"
```

Expected: 24 tool totali (13 esistenti + 11 nuovi), inclusi `get_pages`, `create_page`, `update_page`, `delete_page`, `get_page`, `create_tag`, `get_media`, `delete_media`, `bulk_update_posts`, `get_site_info`, `search`.

- [ ] **Step 3: Commit**

```bash
git add wp-ai-bridge/includes/endpoints/class-wpaib-mcp-controller.php
git commit -m "feat: add 11 new tool definitions to MCP controller"
```

---

## Task 8: Aggiungere `execute_tool()` cases in `class-wpaib-mcp-controller.php`

**Files:**
- Modify: `wp-ai-bridge/includes/endpoints/class-wpaib-mcp-controller.php:335-463` (metodo `execute_tool()`)

- [ ] **Step 1: Aggiungere i case prima del `default:`**

Aggiungere prima di `default:` nel switch:

```php
case 'get_pages':
    $controller = new WPAIB_Pages_Controller();
    $sub_req    = new WP_REST_Request( 'GET', '/wpaib/v1/pages' );
    foreach ( $args as $k => $v ) {
        $sub_req->set_param( $k, $v );
    }
    return $controller->list_pages( $sub_req );

case 'get_page':
    if ( empty( $args['id'] ) ) {
        return new WP_Error( 'wpaib_missing_id', __( 'Missing page ID.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
    }
    $controller = new WPAIB_Pages_Controller();
    $sub_req    = new WP_REST_Request( 'GET', '/wpaib/v1/pages/' . (int) $args['id'] );
    $sub_req->set_param( 'id', (int) $args['id'] );
    return $controller->get_page( $sub_req );

case 'create_page':
    if ( ! current_user_can( 'edit_pages' ) ) {
        return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
    }
    $controller = new WPAIB_Pages_Controller();
    $sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/pages' );
    $sub_req->set_header( 'Content-Type', 'application/json' );
    $sub_req->set_body( wp_json_encode( $args ) );
    return $controller->create_page( $sub_req );

case 'update_page':
    if ( empty( $args['id'] ) ) {
        return new WP_Error( 'wpaib_missing_id', __( 'Missing page ID.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
    }
    if ( ! current_user_can( 'edit_pages' ) ) {
        return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
    }
    $controller = new WPAIB_Pages_Controller();
    $sub_req    = new WP_REST_Request( 'PUT', '/wpaib/v1/pages/' . (int) $args['id'] );
    $sub_req->set_param( 'id', (int) $args['id'] );
    $sub_req->set_header( 'Content-Type', 'application/json' );
    $sub_req->set_body( wp_json_encode( $args ) );
    return $controller->update_page( $sub_req );

case 'delete_page':
    if ( empty( $args['id'] ) ) {
        return new WP_Error( 'wpaib_missing_id', __( 'Missing page ID.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
    }
    if ( ! current_user_can( 'delete_pages' ) ) {
        return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
    }
    $controller = new WPAIB_Pages_Controller();
    $sub_req    = new WP_REST_Request( 'DELETE', '/wpaib/v1/pages/' . (int) $args['id'] );
    $sub_req->set_param( 'id', (int) $args['id'] );
    if ( isset( $args['force'] ) ) {
        $sub_req->set_param( 'force', $args['force'] );
    }
    return $controller->delete_page( $sub_req );

case 'create_tag':
    if ( ! current_user_can( 'manage_categories' ) ) {
        return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
    }
    $controller = new WPAIB_Taxonomy_Controller();
    $sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/tags' );
    $sub_req->set_header( 'Content-Type', 'application/json' );
    $sub_req->set_body( wp_json_encode( $args ) );
    return $controller->create_tag( $sub_req );

case 'get_media':
    $controller = new WPAIB_Media_Controller();
    $sub_req    = new WP_REST_Request( 'GET', '/wpaib/v1/media' );
    foreach ( $args as $k => $v ) {
        $sub_req->set_param( $k, $v );
    }
    return $controller->list_media( $sub_req );

case 'delete_media':
    if ( empty( $args['id'] ) ) {
        return new WP_Error( 'wpaib_missing_id', __( 'Missing media ID.', 'wp-ai-bridge' ), array( 'status' => 400 ) );
    }
    if ( ! current_user_can( 'delete_posts' ) ) {
        return new WP_Error( 'wpaib_forbidden', __( 'Insufficient permissions.', 'wp-ai-bridge' ), array( 'status' => 403 ) );
    }
    $controller = new WPAIB_Media_Controller();
    $sub_req    = new WP_REST_Request( 'DELETE', '/wpaib/v1/media/' . (int) $args['id'] );
    $sub_req->set_param( 'id', (int) $args['id'] );
    if ( isset( $args['force'] ) ) {
        $sub_req->set_param( 'force', $args['force'] );
    }
    return $controller->delete_media( $sub_req );

case 'bulk_update_posts':
    $controller = new WPAIB_Posts_Controller();
    $sub_req    = new WP_REST_Request( 'POST', '/wpaib/v1/posts/bulk' );
    $sub_req->set_header( 'Content-Type', 'application/json' );
    $sub_req->set_body( wp_json_encode( $args ) );
    return $controller->bulk_update_posts( $sub_req );

case 'get_site_info':
    $controller = new WPAIB_Site_Controller();
    return $controller->get_site_info();

case 'search':
    $controller = new WPAIB_Search_Controller();
    $sub_req    = new WP_REST_Request( 'GET', '/wpaib/v1/search' );
    foreach ( $args as $k => $v ) {
        $sub_req->set_param( $k, $v );
    }
    return $controller->search( $sub_req );
```

- [ ] **Step 2: Testare tutti i nuovi tool via MCP**

```bash
# get_pages
curl -s -X POST "$BASE" -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"tool":"get_pages","arguments":{}}' | python3 -c "import sys,json; d=json.load(sys.stdin); print('get_pages OK, total:', d.get('total',d))"

# create_tag
curl -s -X POST "$BASE" -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"tool":"create_tag","arguments":{"name":"AI-Bridge","description":"Tag per contenuti AI"}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('create_tag OK, id:', d.get('id'))"

# get_media
curl -s -X POST "$BASE" -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"tool":"get_media","arguments":{"per_page":5}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('get_media OK, total:', d.get('total'))"

# get_site_info
curl -s -X POST "$BASE" -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"tool":"get_site_info","arguments":{}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('get_site_info OK, name:', d.get('name'), 'theme:', d.get('active_theme'))"

# search
curl -s -X POST "$BASE" -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"tool":"search","arguments":{"query":"MCP","types":["posts","terms"]}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('search OK, results:', d.get('total'))"

# bulk_update_posts (draft poi ripristina)
curl -s -X POST "$BASE" -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"tool":"bulk_update_posts","arguments":{"ids":[1,11],"status":"draft"}}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('bulk OK, updated:', d.get('updated'), 'failed:', d.get('failed'))"
curl -s -X POST "$BASE" -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"tool":"bulk_update_posts","arguments":{"ids":[1,11],"status":"publish"}}' > /dev/null
```

Expected per ogni tool: risposta valida senza `"code":"wpaib_unknown_tool"`.

- [ ] **Step 3: Commit finale**

```bash
git add wp-ai-bridge/includes/endpoints/class-wpaib-mcp-controller.php
git commit -m "feat: wire all 11 new tools into execute_tool() MCP dispatcher"
```

---

## Task 9: Registrare i nuovi controller in `class-wpaib-plugin.php`

**Note:** Verificare che tutti i controller abbiano `register_routes()` chiamato. Se in Task 2-4 non è stato fatto, completare ora.

**Files:**
- Modify: `wp-ai-bridge/includes/class-wpaib-plugin.php`

- [ ] **Step 1: Leggere il file e verificare i register_routes esistenti**

```bash
grep "register_routes" wp-ai-bridge/includes/class-wpaib-plugin.php
```

- [ ] **Step 2: Aggiungere i 3 nuovi controller se mancanti**

```php
( new WPAIB_Pages_Controller() )->register_routes();
( new WPAIB_Site_Controller() )->register_routes();
( new WPAIB_Search_Controller() )->register_routes();
```

- [ ] **Step 3: Test finale — tutti i 24 tool esposti**

```bash
curl -s "http://localhost:8085/?rest_route=/wpaib/v1/tools" \
  -H "X-API-Key: $API_KEY" | python3 -c "
import sys,json
d=json.load(sys.stdin)
names=[t['name'] for t in d['tools']]
print('Totale tool:', len(names))
expected={'get_pages','get_page','create_page','update_page','delete_page',
          'create_tag','get_media','delete_media','bulk_update_posts',
          'get_site_info','search'}
missing=expected-set(names)
if missing: print('MANCANTI:', missing)
else: print('Tutti i nuovi tool presenti OK')
"
```

- [ ] **Step 4: Commit**

```bash
git add wp-ai-bridge/includes/class-wpaib-plugin.php
git commit -m "chore: register routes for pages, site, search controllers"
```
