<?php
/**
 * Run only against the development WordPress stack:
 * docker exec -i wpaib-wordpress php < tests/review-regressions.php
 * Fixtures and temporary credentials are rolled back; no write tools execute.
 */
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
$_SERVER['HTTP_HOST'] = 'localhost';
define( 'DISABLE_WP_CRON', true );
require ( getenv( 'WPAIB_TEST_WP_ROOT' ) ?: '/var/www/html' ) . '/wp-load.php';

function review_check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
	++$GLOBALS['review_checks'];
}
function review_request( $method, $path, $params, $credential, $api_key = false ) {
	$request = new WP_REST_Request( $method, '/wpaib/v1' . $path );
	$request->set_header( $api_key ? 'X-API-Key' : 'Authorization', $api_key ? $credential : 'Bearer ' . $credential );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
	}
	return rest_do_request( $request );
}
function review_fixture( $status, $author, $password = '', $type = 'post' ) {
	global $wpdb;
	review_check( false !== $wpdb->insert( $wpdb->posts, array(
		'post_type' => $type, 'post_status' => $status, 'post_author' => $author,
		'post_title' => 'WPAIB review fixture', 'post_password' => $password,
		'post_date' => '2026-09-01 12:00:00', 'post_date_gmt' => '2026-09-01 12:00:00',
	) ), 'insert fixture post' );
	$post_id = (int) $wpdb->insert_id;
	$GLOBALS['review_posts'][] = $post_id;
	review_check( false !== $wpdb->insert( $wpdb->comments, array(
		'comment_post_ID' => $post_id, 'comment_content' => 'WPAIB confidential fixture',
		'comment_approved' => '1', 'comment_author' => 'Fixture',
		'comment_author_email' => 'fixture@example.invalid', 'comment_author_IP' => '192.0.2.1',
		'comment_date' => '2026-09-01 12:00:00', 'comment_date_gmt' => '2026-09-01 12:00:00',
	) ), 'insert fixture comment' );
	$comment_id = (int) $wpdb->insert_id;
	$GLOBALS['review_comments'][] = $comment_id;
	return array( $post_id, $comment_id );
}

$review_checks = 0;
$review_posts = $review_comments = array();
$failed = false;
global $wpdb;
$wpdb->query( 'START TRANSACTION' );
try {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	review_check( ! empty( $admins ), 'development administrator exists' );
	$user = wp_set_current_user( $admins[0]->ID );
	$admin_caps = $user->allcaps;
	$limited = WPAIB_OAuth_Server::create_token_pair( 'review-fixture', $user->ID, 'edit_posts' );
	$full = WPAIB_OAuth_Server::create_token_pair( 'review-fixture', $user->ID, implode( ' ', WPAIB_OAuth_Server::SCOPES ) );
	$key = WPAIB_API_Key_Manager::generate( $user->ID, 'review-fixture' );
	review_check( ! is_wp_error( $limited ) && ! is_wp_error( $full ) && ! is_wp_error( $key ), 'temporary credentials' );
	$limited = $limited['access_token'];
	$full = $full['access_token'];
	$key = $key['key'];

	review_check( 403 === review_request( 'GET', '/plugins', array(), $limited )->get_status(), 'REST rejects insufficient scope' );
	foreach ( array( '/tools/execute', '/mcp/execute' ) as $path ) {
		foreach ( array( 'get_plugins', 'get_pages', 'get_media', 'delete_plugin', 'activate_plugin', 'upload_media', 'delete_post', 'moderate_comment', 'apply_update' ) as $tool ) {
			$args = array( 'id' => 99999999, 'plugin' => 'review-does-not-exist.php', 'status' => 'approve', 'type' => 'invalid' );
			review_check( 403 === review_request( 'POST', $path, array( 'tool' => $tool, 'arguments' => $args ), $limited )->get_status(), "$path rejects $tool outside scope" );
		}
		review_check( 200 === review_request( 'POST', $path, array( 'tool' => 'get_plugins' ), $full )->get_status(), "$path accepts granted scope" );
		review_check( 200 === review_request( 'POST', $path, array( 'tool' => 'get_plugins' ), $key, true )->get_status(), "$path preserves API keys" );
	}
	$rpc = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'get_plugins' ) );
	review_check( true === review_request( 'POST', '/mcp', $rpc, $limited )->get_data()['result']['isError'], 'MCP HTTP rejects scope bypass' );
	review_check( false === review_request( 'POST', '/mcp', $rpc, $full )->get_data()['result']['isError'], 'MCP HTTP permits granted scope' );
	review_check( false === review_request( 'POST', '/mcp', $rpc, $key, true )->get_data()['result']['isError'], 'MCP HTTP API-key control' );
	review_check( isset( rest_do_request( new WP_REST_Request( 'GET', '/wpaib/v1/tools' ) )->get_data()['code'] ), 'tools still requires authentication' );

	list( $private_post, $private_comment ) = review_fixture( 'private', 0 );
	list( $public_post, $public_comment ) = review_fixture( 'publish', 0 );
	list( $own_post, $own_comment ) = review_fixture( 'draft', $user->ID );
	list( $protected_post, $protected_comment ) = review_fixture( 'publish', 0, 'fixture-password' );
	list( $draft_post, $draft_comment ) = review_fixture( 'draft', 0 );
	register_post_type( 'review_private', array( 'public' => true, 'capability_type' => array( 'review_item', 'review_items' ), 'map_meta_cap' => true ) );
	list( $cpt_post, $cpt_comment ) = review_fixture( 'private', 0, '', 'review_private' );
	$params = array( 'after_id' => $private_comment - 1, 'per_page' => 100 );
	$approved = review_request( 'GET', '/posts/' . $public_post . '/comments', array(), $limited )->get_data();
	review_check( ! isset( $approved['items'][0]['author_email'] ) && ! isset( $approved['items'][0]['author_ip'] ), 'limited OAuth redacts comment PII' );
	review_check( 403 === review_request( 'GET', '/comments', array( 'status' => 'all' ), $limited )->get_status(), 'non-approved comments require OAuth scope' );
	$approved = review_request( 'GET', '/posts/' . $public_post . '/comments', array(), $full )->get_data();
	review_check( isset( $approved['items'][0]['author_email'], $approved['items'][0]['author_ip'] ), 'granted moderation scope includes PII' );
	$rpc['params'] = array( 'name' => 'get_comments', 'arguments' => array( 'status' => 'all' ) );
	review_check( true === review_request( 'POST', '/mcp', $rpc, $limited )->get_data()['result']['isError'], 'MCP propagates comment scope' );
	review_check( false === review_request( 'POST', '/mcp', $rpc, $full )->get_data()['result']['isError'], 'MCP moderation control' );

	// Warm the same query cache as admin, then reuse the user ID with fewer
	// capabilities in memory. Persistent caches must not leak admin results.
	review_request( 'GET', '/comments', $params, $full );
	$user->allcaps = array( 'read' => true, 'edit_posts' => true );
	review_check( 403 === review_request( 'GET', '/posts/' . $private_post, array(), $limited )->get_status(), 'private post denied' );
	foreach ( array( $private_post, $protected_post, $draft_post, $cpt_post ) as $id ) {
		review_check( 403 === review_request( 'GET', '/posts/' . $id . '/comments', array(), $limited )->get_status(), 'inaccessible parent comments denied' );
	}
	$data = review_request( 'GET', '/comments', $params, $limited )->get_data();
	review_check( array( $public_comment, $own_comment ) === array_column( $data['items'], 'id' ), 'cursor only includes public and owned comments' );
	review_check( 2 === $data['total_remaining'] && false === $data['has_more'], 'filtered count matches filtered data' );
	$params['per_page'] = 1;
	$data = review_request( 'GET', '/comments', $params, $limited )->get_data();
	review_check( $public_comment === $data['next_after_id'] && true === $data['has_more'], 'first visible cursor page' );
	$params['after_id'] = $data['next_after_id'];
	$data = review_request( 'GET', '/comments', $params, $limited )->get_data();
	review_check( $own_comment === $data['next_after_id'] && 1 === $data['total_remaining'] && false === $data['has_more'], 'last visible cursor page' );
	$data = review_request( 'GET', '/comments', array( 'per_page' => 100 ), $limited )->get_data();
	review_check( ! in_array( $private_comment, array_column( $data['items'], 'id' ), true ), 'classic pagination hides private comments' );
	$data = review_request( 'GET', '/search', array( 'query' => 'WPAIB confidential fixture', 'types' => array( 'comments' ), 'per_page' => 50 ), $limited )->get_data();
	review_check( ! in_array( $private_comment, array_column( $data['results'], 'id' ), true ), 'search cannot bypass private comment visibility' );
	$user->allcaps = $admin_caps;
	review_check( 200 === review_request( 'GET', '/posts/' . $private_post . '/comments', array(), $full )->get_status(), 'admin private comment control' );
	$params['after_id'] = $private_comment - 1;
	$params['per_page'] = 100;
	$data = review_request( 'GET', '/comments', $params, $full )->get_data();
	review_check( in_array( $private_comment, array_column( $data['items'], 'id' ), true ), 'admin visibility restored after limited request' );
	$data = review_request( 'GET', '/site', array(), $limited )->get_data();
	review_check( ! isset( $data['admin_email'] ), 'site email respects manage_options scope' );

	list( $private_attachment, $private_attachment_comment ) = review_fixture( 'inherit', 0, '', 'attachment' );
	list( $public_attachment, $public_attachment_comment ) = review_fixture( 'inherit', 0, '', 'attachment' );
	$wpdb->update( $wpdb->posts, array( 'post_parent' => $private_post ), array( 'ID' => $private_attachment ) );
	$wpdb->update( $wpdb->posts, array( 'post_parent' => $public_post ), array( 'ID' => $public_attachment ) );
	$user->allcaps = array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true );
	review_check( ! current_user_can( 'read_post', $private_attachment ), 'core denies attachment inheriting private status' );
	review_check( 403 === review_request( 'GET', '/posts/' . $private_attachment . '/comments', array(), $limited )->get_status(), 'private attachment comments denied' );
	$user->allcaps = array( 'read' => true, 'edit_posts' => true );
	review_check( current_user_can( 'read_post', $public_attachment ), 'core permits public attachment' );
	review_check( 200 === review_request( 'GET', '/posts/' . $public_attachment . '/comments', array(), $limited )->get_status(), 'public attachment comment control' );
	$data = review_request( 'GET', '/comments', array( 'after_id' => $private_attachment_comment - 1 ), $limited )->get_data();
	review_check( array( $public_attachment_comment ) === array_column( $data['items'], 'id' ), 'attachment cursor filters inherited visibility' );
	review_check( 1 === $data['total_remaining'], 'attachment cursor count respects visibility' );
	list( $nested_private, $nested_private_comment ) = review_fixture( 'inherit', 0, '', 'attachment' );
	list( $nested_public, $nested_public_comment ) = review_fixture( 'inherit', 0, '', 'attachment' );
	$wpdb->update( $wpdb->posts, array( 'post_parent' => $private_attachment ), array( 'ID' => $nested_private ) );
	$wpdb->update( $wpdb->posts, array( 'post_parent' => $public_attachment ), array( 'ID' => $nested_public ) );
	review_check( 403 === review_request( 'GET', '/posts/' . $nested_private . '/comments', array(), $limited )->get_status(), 'nested private attachment denied' );
	review_check( 200 === review_request( 'GET', '/posts/' . $nested_public . '/comments', array(), $limited )->get_status(), 'nested public attachment preserved' );
	$user->allcaps = $admin_caps;
	review_check( 200 === review_request( 'GET', '/posts/' . $private_attachment . '/comments', array(), $full )->get_status(), 'admin private attachment control' );

	$user->allcaps = array( 'read' => true, 'edit_posts' => true );
	$cache_params = array( 'after_id' => $public_comment - 1, 'post_type' => 'post' );
	$data = review_request( 'GET', '/comments', $cache_params, $limited )->get_data();
	review_check( in_array( $public_comment, array_column( $data['items'], 'id' ), true ), 'warm public parent comment cache' );
	$wpdb->update( $wpdb->posts, array( 'post_status' => 'private' ), array( 'ID' => $public_post ) );
	clean_post_cache( $public_post );
	$data = review_request( 'GET', '/comments', $cache_params, $limited )->get_data();
	review_check( ! in_array( $public_comment, array_column( $data['items'], 'id' ), true ), 'making a parent private invalidates comment cache' );
	review_check( 1 === $data['total_remaining'], 'parent status change invalidates count cache' );
	$user->allcaps = $admin_caps;

	$schema = ( new WPAIB_OpenAPI_Controller() )->get_openapi_schema()->get_data();
	foreach ( array( '/users' => 'list_users', '/pages' => 'edit_pages', '/media' => 'upload_files', '/site/full' => 'manage_options', '/menus' => 'edit_theme_options' ) as $path => $scope ) {
		review_check( array( $scope ) === $schema['paths'][$path]['get']['security'][1]['OAuth2'], "$path advertises its required scope" );
	}
	// Inspect the exact overrides passed to core, without moving/uploading a file.
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$capture = function ( $overrides ) {
		foreach ( array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ) as $ext ) {
			review_check( false !== wp_check_filetype( 'fixture.' . $ext, $overrides['mimes'] )['type'], "multipart allows $ext" );
		}
		review_check( false === wp_check_filetype( 'fixture.php', $overrides['mimes'] )['type'], 'multipart rejects PHP' );
		return $overrides;
	};
	add_filter( 'wp_handle_upload_overrides', $capture );
	$method = new ReflectionMethod( WPAIB_Media_Controller::class, 'handle_multipart_upload' );
	$method->setAccessible( true );
	$method->invoke( new WPAIB_Media_Controller(), array( 'name' => 'fixture.jpg', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '' ) );
	remove_filter( 'wp_handle_upload_overrides', $capture );
} catch ( Throwable $error ) {
	$failed = true;
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
} finally {
	$wpdb->query( 'ROLLBACK' );
	foreach ( $review_posts as $id ) {
		clean_post_cache( $id );
	}
	foreach ( $review_comments as $id ) {
		clean_comment_cache( $id );
	}
}
echo ( $failed ? 'FAILED' : 'PASS' ) . ": $review_checks checks; database fixtures rolled back.\n";
exit( $failed ? 1 : 0 );
