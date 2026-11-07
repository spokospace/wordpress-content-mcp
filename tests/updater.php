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
	'downloaded' => null,
	'version'    => '1.3.0',
);

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}
function add_filter() {}
function add_action() {}
function plugin_basename( $file ) { return 'polo-blocks-mcp/polo-blocks-mcp.php'; }
function get_file_data( $file, $headers ) { return array( 'Version' => $GLOBALS['t']['version'] ); }
function get_transient( $k ) { return $GLOBALS['t']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['t']['transients'][ $k ] = $v; $GLOBALS['t']['ttl'][ $k ] = $ttl; return true; }
function delete_transient( $k ) { unset( $GLOBALS['t']['transients'][ $k ] ); return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['t']['requests'][] = array( $url, $args );
	return call_user_func( $GLOBALS['t']['http'], $url, $args );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? ''; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function wp_remote_retrieve_header( $r, $h ) { return $r['headers'][ $h ] ?? ''; }
function download_url( $url ) { $GLOBALS['t']['downloaded'] = $url; return '/tmp/pkg.zip'; }

require __DIR__ . '/../updater.php';
Polo_Blocks_MCP_Updater::init( '/wp/plugins/polo-blocks-mcp/polo-blocks-mcp.php' );

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
				'html_url'     => 'https://github.com/spokospace/spoko-blocks-mcp/releases/tag/' . $tag,
				'body'         => "Changes <b>here</b>",
				'published_at' => '2026-11-07T12:00:00Z',
				'assets'       => array(
					array( 'name' => 'other.zip', 'url' => 'https://api.github.com/x', 'browser_download_url' => 'https://github.com/x' ),
					array(
						'name'                 => 'polo-blocks-mcp.zip',
						'url'                  => 'https://api.github.com/repos/spokospace/spoko-blocks-mcp/releases/assets/42',
						'browser_download_url' => 'https://github.com/spokospace/spoko-blocks-mcp/releases/download/' . $tag . '/polo-blocks-mcp.zip',
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
	$tr->checked = array( 'polo-blocks-mcp/polo-blocks-mcp.php' => $GLOBALS['t']['version'] );
	return Polo_Blocks_MCP_Updater::inject( $tr );
}
$key = 'polo-blocks-mcp/polo-blocks-mcp.php';

// Newer release -> offered as an update, public download URL.
serve( release_json( 'v1.4.0' ) );
$tr = check_update();
check( 'newer release is offered', isset( $tr->response[ $key ] ) && '1.4.0' === $tr->response[ $key ]->new_version );
check( 'package is the public zip asset', 'https://github.com/spokospace/spoko-blocks-mcp/releases/download/v1.4.0/polo-blocks-mcp.zip' === $tr->response[ $key ]->package );
check( 'slug and plugin set', 'polo-blocks-mcp' === $tr->response[ $key ]->slug && $key === $tr->response[ $key ]->plugin );
check( 'no token -> no Authorization header', ! isset( $GLOBALS['t']['requests'][0][1]['headers']['Authorization'] ) );

// Cached: a second check makes no request.
$GLOBALS['t']['requests'] = array();
check_update();
check( 'release is cached', 0 === count( $GLOBALS['t']['requests'] ) );

// Same version -> no_update.
serve( release_json( '1.3.0' ) );
$tr = check_update();
check( 'same version -> no_update, no response', isset( $tr->no_update[ $key ] ) && ! isset( $tr->response[ $key ] ) );

// Older version -> no update.
serve( release_json( 'v1.2.1' ) );
$tr = check_update();
check( 'older version not offered', ! isset( $tr->response[ $key ] ) );

// API failure (private repo without token = 404) -> nothing, cached for an hour.
serve( '{"message":"Not Found"}', 404 );
$tr = check_update();
check( '404 -> nothing offered', empty( $tr->response ) && empty( $tr->no_update ) );
check( 'failure cached for FAIL_TTL', array() === $GLOBALS['t']['transients']['polo_blocks_mcp_release'] && 3600 === $GLOBALS['t']['ttl']['polo_blocks_mcp_release'] );
$GLOBALS['t']['requests'] = array();
check_update();
check( 'cached failure makes no request', 0 === count( $GLOBALS['t']['requests'] ) );

// Release without the zip asset, draft, prerelease, bad tag -> ignored.
serve( release_json( 'v9.0.0', array( 'assets' => array() ) ) );
check( 'no zip asset -> ignored', empty( check_update()->response ) );
serve( release_json( 'v9.0.0', array( 'prerelease' => true ) ) );
check( 'prerelease ignored', empty( check_update()->response ) );
serve( release_json( 'latest' ) );
check( 'non-numeric tag ignored', empty( check_update()->response ) );

// Empty checked list (WordPress's first pass) -> untouched.
$tr = new stdClass();
check( 'transient without checked untouched', $tr === Polo_Blocks_MCP_Updater::inject( $tr ) && ! isset( $tr->response ) );

// Details popup.
serve( release_json( 'v1.4.0' ) );
$info = Polo_Blocks_MCP_Updater::info( false, 'plugin_information', (object) array( 'slug' => 'polo-blocks-mcp' ) );
check( 'info for our slug', is_object( $info ) && '1.4.0' === $info->version && false !== strpos( $info->sections['changelog'], '&lt;b&gt;' ) );
check( 'info for other slug untouched', 'x' === Polo_Blocks_MCP_Updater::info( 'x', 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );

// download(): without a token WordPress downloads itself.
check( 'download without token -> false', false === Polo_Blocks_MCP_Updater::download( false, 'https://api.github.com/repos/spokospace/spoko-blocks-mcp/releases/assets/42' ) );

// With a token: API asset URL as package, redirect followed without the token.
define( 'POLO_BLOCKS_MCP_GITHUB_TOKEN', 'test-token' );
serve( release_json( 'v1.4.0' ) );
$tr = check_update();
check( 'token -> Authorization on API call', 'Bearer test-token' === ( $GLOBALS['t']['requests'][0][1]['headers']['Authorization'] ?? '' ) );
check( 'token -> package is the API asset URL', 'https://api.github.com/repos/spokospace/spoko-blocks-mcp/releases/assets/42' === $tr->response[ $key ]->package );

$GLOBALS['t']['http']     = function ( $url, $args ) {
	return array( 'code' => 302, 'headers' => array( 'location' => 'https://release-assets.githubusercontent.com/signed?x=1' ) );
};
$GLOBALS['t']['requests'] = array();
$file                     = Polo_Blocks_MCP_Updater::download( false, 'https://api.github.com/repos/spokospace/spoko-blocks-mcp/releases/assets/42' );
$req                      = $GLOBALS['t']['requests'][0][1];
check( 'download asks for the file without following redirects', 0 === $req['redirection'] && 'application/octet-stream' === $req['headers']['Accept'] && 'Bearer test-token' === $req['headers']['Authorization'] );
check( 'download fetches the signed URL', '/tmp/pkg.zip' === $file && 'https://release-assets.githubusercontent.com/signed?x=1' === $GLOBALS['t']['downloaded'] );
check( 'download ignores other packages', false === Polo_Blocks_MCP_Updater::download( false, 'https://downloads.wordpress.org/plugin/akismet.zip' ) );

$GLOBALS['t']['http'] = function () { return array( 'code' => 404 ); };
check( 'download error without redirect', Polo_Blocks_MCP_Updater::download( false, 'https://api.github.com/repos/spokospace/spoko-blocks-mcp/releases/assets/42' ) instanceof WP_Error );

// Source folder: already right -> unchanged; other plugins untouched.
check( 'source folder kept when right', '/tmp/up/polo-blocks-mcp/' === Polo_Blocks_MCP_Updater::source_folder( '/tmp/up/polo-blocks-mcp/', '/tmp/up', null, array( 'plugin' => $key ) ) );
check( 'source of other plugin untouched', '/tmp/up/x/' === Polo_Blocks_MCP_Updater::source_folder( '/tmp/up/x/', '/tmp/up', null, array( 'plugin' => 'akismet/akismet.php' ) ) );

// Flush after a plugin update.
$GLOBALS['t']['transients']['polo_blocks_mcp_release'] = array( 'version' => '1' );
Polo_Blocks_MCP_Updater::flush( null, array( 'type' => 'plugin' ) );
check( 'cache flushed after plugin update', ! isset( $GLOBALS['t']['transients']['polo_blocks_mcp_release'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
