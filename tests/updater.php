<?php
/**
 * Tests for updater.php with stubbed WordPress functions. No WordPress install needed.
 *
 * Run: php tests/updater.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t'] = array(
	'transients' => array(),
	'http'       => null,  // callable( $url, $args ) => response array | WP_Error
	'requests'   => array(),
	'version'    => '2.0.0',
);

class WP_Error {}
function add_filter() {}
function add_action() {}
function plugin_basename( $file ) { return 'spoko-content-mcp/spoko-content-mcp.php'; }
function get_file_data( $file, $headers ) {
	return array( 'Name' => 'Spoko Content MCP', 'Author' => 'spoko.space', 'RequiresPHP' => '7.4' );
}
function get_transient( $k ) { return $GLOBALS['t']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['t']['transients'][ $k ] = $v; $GLOBALS['t']['ttl'][ $k ] = $ttl; return true; }
function delete_transient( $k ) { unset( $GLOBALS['t']['transients'][ $k ] ); return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['t']['requests'][] = array( $url, $args );
	return call_user_func( $GLOBALS['t']['http'], $url, $args );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? ''; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

require __DIR__ . '/../updater.php';
Spoko_Content_MCP_Updater::init( '/wp/plugins/spoko-content-mcp/spoko-content-mcp.php' );

$pass = 0;
$fail = 0;
function check( $name, $ok ) {
	global $pass, $fail;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	$ok ? $pass++ : $fail++;
}
function release_json( $tag, $extra = array() ) {
	return json_encode(
		array_merge(
			array(
				'tag_name'     => $tag,
				'draft'        => false,
				'prerelease'   => false,
				'html_url'     => 'https://github.com/spokospace/wordpress-content-mcp/releases/tag/' . $tag,
				'body'         => 'Changes <b>here</b>',
				'published_at' => '2026-11-07T12:00:00Z',
				'assets'       => array(
					array( 'name' => 'other.zip', 'browser_download_url' => 'https://github.com/x' ),
					array(
						'name'                 => 'spoko-content-mcp.zip',
						'browser_download_url' => 'https://github.com/spokospace/wordpress-content-mcp/releases/download/' . $tag . '/spoko-content-mcp.zip',
					),
				),
			),
			$extra
		)
	);
}
function serve( $body, $code = 200 ) {
	$GLOBALS['t']['http']       = function () use ( $body, $code ) { return array( 'code' => $code, 'body' => $body ); };
	$GLOBALS['t']['transients'] = array();
	$GLOBALS['t']['requests']   = array();
}
function check_update() {
	$tr          = new stdClass();
	$tr->checked = array( 'spoko-content-mcp/spoko-content-mcp.php' => $GLOBALS['t']['version'] );
	return Spoko_Content_MCP_Updater::inject( $tr );
}
$key = 'spoko-content-mcp/spoko-content-mcp.php';

// Newer release -> offered as an update.
serve( release_json( 'v2.1.0' ) );
$tr = check_update();
check( 'newer release is offered', isset( $tr->response[ $key ] ) && '2.1.0' === $tr->response[ $key ]->new_version );
check( 'package is the zip asset', 'https://github.com/spokospace/wordpress-content-mcp/releases/download/v2.1.0/spoko-content-mcp.zip' === $tr->response[ $key ]->package );
check( 'slug, plugin and PHP requirement set', 'spoko-content-mcp' === $tr->response[ $key ]->slug && $key === $tr->response[ $key ]->plugin && '7.4' === $tr->response[ $key ]->requires_php );
check( 'one GitHub API request', 1 === count( $GLOBALS['t']['requests'] ) && 'https://api.github.com/repos/spokospace/wordpress-content-mcp/releases/latest' === $GLOBALS['t']['requests'][0][0] );

// Cached: a second check makes no request.
$GLOBALS['t']['requests'] = array();
check_update();
check( 'release is cached', 0 === count( $GLOBALS['t']['requests'] ) );

// Same version -> no_update.
serve( release_json( '2.0.0' ) );
$tr = check_update();
check( 'same version -> no_update, no response', isset( $tr->no_update[ $key ] ) && ! isset( $tr->response[ $key ] ) );

// Older version -> no update.
serve( release_json( 'v1.2.1' ) );
check( 'older version not offered', ! isset( check_update()->response[ $key ] ) );

// API failure -> nothing, cached for an hour.
serve( '{"message":"Not Found"}', 404 );
$tr = check_update();
check( '404 -> nothing offered', empty( $tr->response ) && empty( $tr->no_update ) );
check( 'failure cached for FAIL_TTL', array() === $GLOBALS['t']['transients']['spoko_content_mcp_release'] && 3600 === $GLOBALS['t']['ttl']['spoko_content_mcp_release'] );
$GLOBALS['t']['requests'] = array();
check_update();
check( 'cached failure makes no request', 0 === count( $GLOBALS['t']['requests'] ) );

// "Check again" skips the cache.
$_GET['force-check'] = '1';
check_update();
check( 'force-check asks GitHub again', 1 === count( $GLOBALS['t']['requests'] ) );
unset( $_GET['force-check'] );

// Release without the zip asset, prerelease, bad tag -> ignored.
serve( release_json( 'v9.0.0', array( 'assets' => array() ) ) );
check( 'no zip asset -> ignored', empty( check_update()->response ) );
serve( release_json( 'v9.0.0', array( 'prerelease' => true ) ) );
check( 'prerelease ignored', empty( check_update()->response ) );
serve( release_json( 'latest' ) );
check( 'non-numeric tag ignored', empty( check_update()->response ) );

// Check without this plugin in "checked" (WordPress's first pass) -> untouched, no request.
serve( release_json( 'v9.0.0' ) );
$tr = new stdClass();
check( 'transient without checked untouched', $tr === Spoko_Content_MCP_Updater::inject( $tr ) && ! isset( $tr->response ) && ! $GLOBALS['t']['requests'] );

// Details popup.
serve( release_json( 'v2.1.0' ) );
$info = Spoko_Content_MCP_Updater::info( false, 'plugin_information', (object) array( 'slug' => 'spoko-content-mcp' ) );
check( 'info for our slug', is_object( $info ) && '2.1.0' === $info->version && 'Spoko Content MCP' === $info->name && false !== strpos( $info->sections['changelog'], '&lt;b&gt;' ) );
check( 'info for other slug untouched', 'x' === Spoko_Content_MCP_Updater::info( 'x', 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );

// Flush after a plugin update.
$GLOBALS['t']['transients']['spoko_content_mcp_release'] = array( 'version' => '1' );
Spoko_Content_MCP_Updater::flush( null, array( 'type' => 'plugin' ) );
check( 'cache flushed after plugin update', ! isset( $GLOBALS['t']['transients']['spoko_content_mcp_release'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
