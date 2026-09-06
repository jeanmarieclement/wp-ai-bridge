<?php
/**
 * Logica pura di WPAIB_Rest_Helper, senza WordPress.
 *
 * Copre i punti che durante la review si sono rivelati fragili: il limite per
 * pagina, la distinzione fra "nessun cursore" e "cursore all'inizio", e
 * l'espansione di status=any.
 *
 * @package WPAIBridge
 */

require_once __DIR__ . '/bootstrap.php';

// Stati come li registra WordPress, più due di un ipotetico plugin editoriale.
// 'pitch' è dichiarato con exclude_from_search: è il caso in cui il letterale
// 'any' di WP_Query fa sparire i record senza dirlo.
$GLOBALS['post_stati'] = array(
	'publish'    => array( 'internal' => false, 'exclude_from_search' => false ),
	'future'     => array( 'internal' => false, 'exclude_from_search' => false ),
	'draft'      => array( 'internal' => false, 'exclude_from_search' => false ),
	'pending'    => array( 'internal' => false, 'exclude_from_search' => false ),
	'private'    => array( 'internal' => false, 'exclude_from_search' => false ),
	'pitch'      => array( 'internal' => false, 'exclude_from_search' => true ),
	'assigned'   => array( 'internal' => false, 'exclude_from_search' => false ),
	'trash'      => array( 'internal' => true,  'exclude_from_search' => true ),
	'auto-draft' => array( 'internal' => true,  'exclude_from_search' => true ),
	'inherit'    => array( 'internal' => true,  'exclude_from_search' => false ),
);

/**
 * Stub di get_post_stati(): filtra per i flag passati, come fa WordPress.
 *
 * @param array $args Flag da confrontare.
 * @return array
 */
function get_post_stati( $args = array() ) {
	$out = array();

	foreach ( $GLOBALS['post_stati'] as $name => $flags ) {
		foreach ( $args as $k => $v ) {
			if ( ! array_key_exists( $k, $flags ) || $flags[ $k ] !== $v ) {
				continue 2;
			}
		}
		$out[ $name ] = $name;
	}

	return $out;
}

/**
 * Stub di sanitize_key().
 *
 * @param string $k Chiave.
 * @return string
 */
function sanitize_key( $k ) {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $k ) );
}

require_once WPAIB_TEST_PLUGIN_DIR . '/includes/class-wpaib-rest-helper.php';

// --- per_page ---------------------------------------------------------------
wpaib_check( 'per_page(null) falls back to the default', WPAIB_Rest_Helper::per_page( null ), 10 );
wpaib_check( 'per_page(0) falls back to the default', WPAIB_Rest_Helper::per_page( 0 ), 10 );
wpaib_check( 'per_page(0, 20) honours a custom default', WPAIB_Rest_Helper::per_page( 0, 20 ), 20 );
wpaib_check( 'per_page(50) passes through', WPAIB_Rest_Helper::per_page( 50 ), 50 );
wpaib_check( 'per_page(9999) is clamped to the maximum', WPAIB_Rest_Helper::per_page( 9999 ), 100 );
wpaib_check( 'per_page(-5) falls back to the default', WPAIB_Rest_Helper::per_page( -5 ), 10 );

// --- after_id ---------------------------------------------------------------
// È la presenza del parametro a scegliere la modalità, non il valore: assente
// significa paginazione classica, 0 esplicito significa cursore dall'inizio.
// Distinguerli richiede null, non 0.
wpaib_check( 'after_id(null) is null, i.e. no cursor', WPAIB_Rest_Helper::after_id( null ), null );
wpaib_check( 'after_id(0) is a cursor at the start', WPAIB_Rest_Helper::after_id( 0 ), 0 );
wpaib_check( 'after_id("412") is cast to int', WPAIB_Rest_Helper::after_id( '412' ), 412 );
wpaib_check( 'after_id(-3) is floored at 0', WPAIB_Rest_Helper::after_id( -3 ), 0 );

// --- post_status ------------------------------------------------------------
// Il letterale 'any' di WP_Query esclude ogni stato con exclude_from_search,
// inclusi quelli registrati dai plugin: 'pitch' sparirebbe da un export che si
// dichiara completo. Serve la lista esplicita degli stati non interni.
$expected_any = array( 'publish', 'future', 'draft', 'pending', 'private', 'pitch', 'assigned' );

wpaib_check( 'post_status(any) lists the non-internal stati', WPAIB_Rest_Helper::post_status( 'any' ), $expected_any );
wpaib_check( 'post_status(any) keeps an exclude_from_search plugin status', in_array( 'pitch', WPAIB_Rest_Helper::post_status( 'any' ), true ), true );
wpaib_check( 'post_status(any) drops trash', in_array( 'trash', WPAIB_Rest_Helper::post_status( 'any' ), true ), false );
wpaib_check( 'post_status(any) drops inherit', in_array( 'inherit', WPAIB_Rest_Helper::post_status( 'any' ), true ), false );
wpaib_check( 'post_status(trash) passes through', WPAIB_Rest_Helper::post_status( 'trash' ), 'trash' );
wpaib_check( 'post_status(draft) passes through', WPAIB_Rest_Helper::post_status( 'draft' ), 'draft' );
wpaib_check( 'post_status(bogus) falls back to any', WPAIB_Rest_Helper::post_status( 'bogus' ), $expected_any );

// --- next_cursor ------------------------------------------------------------
wpaib_check( 'next_cursor([]) is null', WPAIB_Rest_Helper::next_cursor( array() ), null );
wpaib_check( 'next_cursor takes the last id', WPAIB_Rest_Helper::next_cursor( array( array( 'id' => 7 ), array( 'id' => 12 ) ) ), 12 );

wpaib_finish( 'rest-helper' );
