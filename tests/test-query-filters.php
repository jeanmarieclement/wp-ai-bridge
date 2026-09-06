<?php
/**
 * I filtri SQL che WPAIB_Rest_Helper aggancia alle query di WordPress.
 *
 * Due meccanismi, entrambi verificati sull'SQL prodotto:
 *
 * 1. il cursore after_id, che deve toccare solo la query marcata e sganciarsi
 *    subito dopo;
 * 2. la restrizione di visibilità, che impedisce a un elenco di restituire i
 *    contenuti non pubblicati altrui — quelli che la rotta singola nega con un
 *    403. Qui la parentesizzazione è tutto: `AND status IN (...) OR author = N`
 *    senza parentesi restituirebbe l'intero database.
 *
 * WordPress non serve: un sistema di hook e un $wpdb finti bastano a leggere
 * l'SQL generato.
 *
 * @package WPAIBridge
 */

require_once __DIR__ . '/bootstrap.php';

// --- sistema di hook minimale ------------------------------------------------
$GLOBALS['hooks'] = array();

function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['hooks'][ $tag ][] = $cb;
}

function add_action( $tag, $cb, $prio = 10, $args = 1 ) {
	add_filter( $tag, $cb, $prio, $args );
}

function remove_filter( $tag, $cb, $prio = 10 ) {
	if ( empty( $GLOBALS['hooks'][ $tag ] ) ) {
		return;
	}

	foreach ( $GLOBALS['hooks'][ $tag ] as $i => $existing ) {
		if ( $existing === $cb ) {
			unset( $GLOBALS['hooks'][ $tag ][ $i ] );
		}
	}

	$GLOBALS['hooks'][ $tag ] = array_values( $GLOBALS['hooks'][ $tag ] );
}

function remove_action( $tag, $cb, $prio = 10 ) {
	remove_filter( $tag, $cb, $prio );
}

function apply_filters( $tag, $value, ...$rest ) {
	foreach ( (array) ( $GLOBALS['hooks'][ $tag ] ?? array() ) as $cb ) {
		$value = $cb( $value, ...$rest );
	}
	return $value;
}

function do_action( $tag, ...$args ) {
	foreach ( (array) ( $GLOBALS['hooks'][ $tag ] ?? array() ) as $cb ) {
		$cb( ...$args );
	}
}

function wpaib_hook_count( $tag ) {
	return count( (array) ( $GLOBALS['hooks'][ $tag ] ?? array() ) );
}

// --- capability e stati, pilotabili dal test ---------------------------------
$GLOBALS['caps']    = array();
$GLOBALS['user_id'] = 0;

function current_user_can( $cap ) {
	return ! empty( $GLOBALS['caps'][ $cap ] );
}

function get_current_user_id() {
	return $GLOBALS['user_id'];
}

function get_post_stati( $args = array() ) {
	$all = array(
		'publish' => array( 'public' => true ),
		'private' => array( 'public' => false ),
		'draft'   => array( 'public' => false ),
	);

	$out = array();
	foreach ( $all as $name => $flags ) {
		foreach ( $args as $k => $v ) {
			if ( ! array_key_exists( $k, $flags ) || $flags[ $k ] !== $v ) {
				continue 2;
			}
		}
		$out[ $name ] = $name;
	}

	return $out;
}

function get_post_type_object( $type ) {
	return (object) array(
		'cap' => (object) array( 'edit_others_posts' => 'edit_others_' . $type . 's' ),
	);
}

// --- wpdb minimale -----------------------------------------------------------
class WPAIB_Fake_WPDB {
	public $posts    = 'wp_posts';
	public $comments = 'wp_comments';
	public $users    = 'wp_users';

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		foreach ( $args as $a ) {
			$replacement = is_int( $a ) ? (string) $a : "'" . $a . "'";
			$sql         = preg_replace( '/%[ds]/', $replacement, $sql, 1 );
		}

		return $sql;
	}
}

$GLOBALS['wpdb'] = new WPAIB_Fake_WPDB();

// --- query object finti, che fanno girare i filtri come fa WordPress ---------
class WP_Query {
	public $query_vars;
	public $posts         = array();
	public $found_posts   = 0;
	public $max_num_pages = 0;
	public $where         = '';

	public function __construct( $args ) {
		$this->query_vars = $args;
		$this->where      = apply_filters( 'posts_where', ' AND 1=1', $this );
	}

	public function get( $k ) {
		return $this->query_vars[ $k ] ?? '';
	}
}

class WP_Comment_Query {
	public $query_vars;
	public $clauses;

	public function query( $args ) {
		$this->query_vars             = $args;
		$GLOBALS['last_comment_args'] = $args;
		$this->clauses                = apply_filters( 'comments_clauses', array( 'where' => ' AND 1=1' ), $this );
		return array();
	}
}

class WP_User_Query {
	public $query_vars;
	public $query_where = ' AND 1=1';

	public function __construct( $args ) {
		$this->query_vars = $args;
		do_action( 'pre_user_query', $this );
	}

	public function get_results() {
		return array();
	}

	public function get_total() {
		return 0;
	}
}

function get_terms( $args ) {
	$clauses                       = apply_filters( 'terms_clauses', array( 'where' => ' AND 1=1' ), array( $args['taxonomy'] ), $args );
	$GLOBALS['last_terms_where']   = $clauses['where'];
	return array();
}

require_once WPAIB_TEST_PLUGIN_DIR . '/includes/class-wpaib-rest-helper.php';

// Di default la suite gira come Editore: nessuna restrizione di visibilità, così
// le asserzioni sul cursore misurano solo il cursore.
$GLOBALS['caps']    = array( 'edit_others_posts' => true );
$GLOBALS['user_id'] = 7;

// --- cursore su WP_Query -----------------------------------------------------
$query = WPAIB_Rest_Helper::query_posts( array( 'post_type' => 'post', 'posts_per_page' => 10, 'paged' => 3 ), 412 );
wpaib_check( 'posts: the WHERE carries the cursor', $query->where, ' AND 1=1 AND wp_posts.ID > 412' );
wpaib_check( 'posts: ordering is forced to ID ASC', array( $query->get( 'orderby' ), $query->get( 'order' ) ), array( 'ID', 'ASC' ) );
wpaib_check( 'posts: paged is dropped under a cursor', isset( $query->query_vars['paged'] ), false );
// Senza questo ogni pagina del cursore è "pagina 1" e WP_Query rimette gli
// sticky in testa: duplicati su ogni pagina e ordine per ID rotto.
wpaib_check( 'posts: sticky posts are ignored under a cursor', $query->get( 'ignore_sticky_posts' ), true );
wpaib_check( 'posts: the filter is removed after the query', wpaib_hook_count( 'posts_where' ), 0 );

$query = WPAIB_Rest_Helper::query_posts( array( 'post_type' => 'post', 'paged' => 3 ), null );
wpaib_check( 'posts: no cursor leaves the WHERE alone', $query->where, ' AND 1=1' );
wpaib_check( 'posts: no cursor keeps paged', $query->get( 'paged' ), 3 );

// Una query non marcata non va toccata nemmeno mentre il filtro è attivo.
add_filter( 'posts_where', array( 'WPAIB_Rest_Helper', 'filter_posts_where' ), 10, 2 );
$other = new WP_Query( array( 'post_type' => 'page' ) );
wpaib_check( 'posts: an unmarked query is untouched', $other->where, ' AND 1=1' );
remove_filter( 'posts_where', array( 'WPAIB_Rest_Helper', 'filter_posts_where' ), 10 );

// after_id=0 è un cursore valido, "dall'inizio". La clausola ID > 0 non viene
// aggiunta perché sarebbe un no-op: gli ID dei post partono da 1.
$query = WPAIB_Rest_Helper::query_posts( array( 'post_type' => 'post', 'paged' => 3 ), 0 );
wpaib_check( 'posts: an explicit 0 still orders by ID ASC', array( $query->get( 'orderby' ), $query->get( 'order' ) ), array( 'ID', 'ASC' ) );
wpaib_check( 'posts: an explicit 0 still drops paged', isset( $query->query_vars['paged'] ), false );

// Il cursore passa sempre da un cast a intero.
$query = WPAIB_Rest_Helper::query_posts( array( 'post_type' => 'post' ), '5; DROP TABLE wp_posts' );
wpaib_check( 'posts: a non-numeric cursor is coerced to int', $query->where, ' AND 1=1 AND wp_posts.ID > 5' );

// --- cursore su WP_Comment_Query ---------------------------------------------
WPAIB_Rest_Helper::query_comments( array( 'status' => 'all', 'number' => 50 ), 99 );
wpaib_check( 'comments: the filter is removed after the query', wpaib_hook_count( 'comments_clauses' ), 0 );
// WP_Comment_Query costruisce la chiave di cache dalle sole query var note e
// senza l'SQL, quindi il cursore deve entrarci via cache_domain: altrimenti,
// con un object cache persistente, ogni pagina restituisce di nuovo la prima.
wpaib_check( 'comments: the cursor reaches the cache key', $GLOBALS['last_comment_args']['cache_domain'] ?? null, 'wpaib_after_99' );

WPAIB_Rest_Helper::query_comments( array( 'status' => 'all' ), null );
wpaib_check( 'comments: no cursor leaves cache_domain alone', isset( $GLOBALS['last_comment_args']['cache_domain'] ), false );

$comment_query = new WP_Comment_Query();
add_filter( 'comments_clauses', array( 'WPAIB_Rest_Helper', 'filter_comments_clauses' ), 10, 2 );
$comment_query->query( array( 'wpaib_after_id' => 99 ) );
wpaib_check( 'comments: the WHERE carries the cursor', $comment_query->clauses['where'], ' AND 1=1 AND wp_comments.comment_ID > 99' );
$comment_query->query( array() );
wpaib_check( 'comments: an unmarked query is untouched', $comment_query->clauses['where'], ' AND 1=1' );
remove_filter( 'comments_clauses', array( 'WPAIB_Rest_Helper', 'filter_comments_clauses' ), 10 );

// --- cursore su WP_User_Query ------------------------------------------------
$user_query = WPAIB_Rest_Helper::query_users( array( 'number' => 20 ), 7 );
wpaib_check( 'users: the WHERE carries the cursor', $user_query->query_where, ' AND 1=1 AND wp_users.ID > 7' );
wpaib_check( 'users: the filter is removed after the query', wpaib_hook_count( 'pre_user_query' ), 0 );

$user_query = WPAIB_Rest_Helper::query_users( array( 'number' => 20 ), null );
wpaib_check( 'users: no cursor leaves the WHERE alone', $user_query->query_where, ' AND 1=1' );

// --- cursore sui termini -----------------------------------------------------
WPAIB_Rest_Helper::query_terms( array( 'taxonomy' => 'category', 'number' => 10 ), 55 );
wpaib_check( 'terms: the WHERE carries the cursor', $GLOBALS['last_terms_where'], ' AND 1=1 AND t.term_id > 55' );
wpaib_check( 'terms: the filter is removed after the query', wpaib_hook_count( 'terms_clauses' ), 0 );

WPAIB_Rest_Helper::query_terms( array( 'taxonomy' => 'category', 'number' => 10 ), null );
wpaib_check( 'terms: no cursor leaves the WHERE alone', $GLOBALS['last_terms_where'], ' AND 1=1' );

// --- visibilità ---------------------------------------------------------------
// Senza edit_others_posts la query va limitata agli stati pubblici o ai propri
// contenuti, altrimenti /posts?status=draft consegna le bozze altrui — le
// stesse che GET /posts/{id} nega con un 403.
$GLOBALS['caps']    = array();
$GLOBALS['user_id'] = 42;
$query              = WPAIB_Rest_Helper::query_posts( array( 'post_type' => 'post' ), null );
wpaib_check(
	'author: the list is restricted to public or own content',
	$query->where,
	" AND 1=1 AND ( wp_posts.post_status IN ( 'publish' ) OR wp_posts.post_author = 42 )"
);

// Un utente non autenticato non possiede nulla: mai un OR su post_author = 0.
$GLOBALS['user_id'] = 0;
$query              = WPAIB_Rest_Helper::query_posts( array( 'post_type' => 'post' ), null );
wpaib_check(
	'anonymous: public statuses only, with no author branch',
	$query->where,
	" AND 1=1 AND ( wp_posts.post_status IN ( 'publish' ) )"
);

// Editore e Amministratore non vengono toccati: un export completo resta completo.
$GLOBALS['caps']    = array( 'edit_others_posts' => true );
$GLOBALS['user_id'] = 7;
$query              = WPAIB_Rest_Helper::query_posts( array( 'post_type' => 'post' ), null );
wpaib_check( 'editor: no visibility restriction', $query->where, ' AND 1=1' );

// La capability è quella del post type interrogato, non quella fissa dei post.
$GLOBALS['caps'] = array( 'edit_others_pages' => true );
$query           = WPAIB_Rest_Helper::query_posts( array( 'post_type' => 'page' ), null );
wpaib_check( 'the capability is resolved per post type', $query->where, ' AND 1=1' );

$query = WPAIB_Rest_Helper::query_posts( array( 'post_type' => 'post' ), null );
wpaib_check( 'a page capability does not unlock posts', str_contains( $query->where, 'post_author = 7' ), true );

wpaib_finish( 'query-filters' );
