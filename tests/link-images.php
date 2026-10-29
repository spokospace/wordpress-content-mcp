<?php
// Harness for Polo_Blocks_MCP::link_images using WordPress's real block parser/serializer.
// argv[1] = WordPress root, argv[2] = plugin file.
define( 'ABSPATH', $argv[1] . '/' );
define( 'WPINC', 'wp-includes' );
function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function do_action() {}
function esc_url( $u ) { return $u; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_slash( $s ) { return $s; }
function current_user_can() { return true; }
function _deprecated_function() {}
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
class WP_Error { public $code; public $message; function __construct( $c, $m ) { $this->code = $c; $this->message = $m; } }
class WP_Post { public $ID; public $post_type = 'post'; public $post_content; }
require ABSPATH . WPINC . '/class-wp-block-parser-block.php';
require ABSPATH . WPINC . '/class-wp-block-parser-frame.php';
require ABSPATH . WPINC . '/class-wp-block-parser.php';
require ABSPATH . WPINC . '/blocks.php';

$GLOBALS['posts'] = array();
$GLOBALS['saved'] = array();
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function wp_update_post( $arr ) { $GLOBALS['posts'][ $arr['ID'] ]->post_content = $arr['post_content']; $GLOBALS['saved'][] = $arr['ID']; return $arr['ID']; }
function wp_get_post_revisions() { return array( 999 => true ); }
// Attachment URLs as the media library stores them: the -scaled original on img.polo.blue.
function wp_get_attachment_url( $id ) { return 24399 === $id ? false : "https://img.polo.blue/2021/06/att-$id-scaled.jpg"; }

require $argv[2];

function img( $id, $attrs, $inner ) {
	$json = json_encode( array_merge( $id ? array( 'id' => $id ) : array(), array( 'sizeSlug' => 'large' ), $attrs ) );
	return "<!-- wp:image $json -->\n$inner\n<!-- /wp:image -->";
}
$src = 'https://blog.polo.blue/images/2026/10/x-%d-1200x900.jpg';
$content = implode( "\n\n", array(
	"<!-- wp:paragraph -->\n<p>Intro</p>\n<!-- /wp:paragraph -->",
	"<!-- wp:gallery {\"linkTo\":\"media\"} -->\n<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\">"
	// unlinked (as in 24450 gallery 2)
	. img( 24412, array( 'linkDestination' => 'none' ), '<figure class="wp-block-image size-large"><img src="' . sprintf( $src, 1 ) . '" alt="" class="wp-image-24412"/></figure>' ) . "\n\n"
	// already linked (24404)
	. img( 24404, array( 'linkDestination' => 'media' ), '<figure class="wp-block-image size-large"><a href="https://img.polo.blue/2021/06/linked-scaled-1.jpg"><img src="' . sprintf( $src, 2 ) . '" alt="" class="wp-image-24404"/></a></figure>' ) . "\n\n"
	// linkDestination media but no <a> (24401)
	. img( 24401, array( 'linkDestination' => 'media' ), '<figure class="wp-block-image size-large"><img src="' . sprintf( $src, 3 ) . '" alt="" class="wp-image-24401"/></figure>' )
	. "</figure>\n<!-- /wp:gallery -->",
	// columns > column > gallery > image (24450 path 16)
	"<!-- wp:columns -->\n<div class=\"wp-block-columns\"><!-- wp:column -->\n<div class=\"wp-block-column\"><!-- wp:gallery {\"linkTo\":\"none\"} -->\n<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\">"
	. img( 24394, array( 'linkDestination' => 'none' ), '<figure class="wp-block-image size-large"><img src="' . sprintf( $src, 4 ) . '" alt="" class="wp-image-24394"/></figure>' )
	. "</figure>\n<!-- /wp:gallery --></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns -->",
	// opt-out
	img( 500, array( 'className' => 'no-lightbox' ), '<figure class="wp-block-image size-large no-lightbox"><img src="' . sprintf( $src, 5 ) . '" alt="" class="wp-image-500"/></figure>' ),
	// no attachment URL, size suffix present -> strip it
	img( 24399, array(), '<figure class="wp-block-image size-large"><img src="https://blog.polo.blue/images/2021/06/coolbox-1200x900.jpg" alt=""/></figure>' ),
	// no id, no suffix -> skipped
	img( 0, array(), '<figure class="wp-block-image"><img src="https://example.com/plain.jpg" alt=""/></figure>' ),
	// link only in caption -> image still gets linked
	img( 600, array(), '<figure class="wp-block-image"><img src="' . sprintf( $src, 6 ) . '" alt="" class="wp-image-600"/><figcaption class="wp-element-caption"><a href="/y">cap</a></figcaption></figure>' ),
) );

$post = new WP_Post(); $post->ID = 1; $post->post_content = $content;
$GLOBALS['posts'][1] = $post;
$hash = md5( $content );

$ok = true;
function check( $label, $cond ) { global $ok; $ok = $ok && $cond; echo ( $cond ? 'PASS ' : 'FAIL ' ), $label, "\n"; }

// 1. stale hash refused
$r = Polo_Blocks_MCP::link_images( array( 'post_id' => 1, 'expected_hash' => 'nope' ) );
check( 'stale hash refused', is_wp_error( $r ) && 'stale_hash' === $r->code );

// 2. dry run: reports, does not save
$r = Polo_Blocks_MCP::link_images( array( 'post_id' => 1, 'expected_hash' => $hash, 'dry_run' => true ) );
echo json_encode( array( 'linked' => $r['linked'], 'skipped' => $r['skipped'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
check( 'dry run does not save', ! $GLOBALS['saved'] && $GLOBALS['posts'][1]->post_content === $content && true === $r['dry_run'] );
check( 'dry run links 5 images', 5 === count( $r['linked'] ) );
check( 'linked paths', array( '2.0', '2.2', '4.0.0.0', '8', '12' ) === array_column( $r['linked'], 'path' ) );
check( 'skips opt-out and unknown file', array( array( 'path' => '6', 'reason' => 'no-lightbox' ), array( 'path' => '10', 'reason' => 'full file unknown' ) ) === $r['skipped'] );

// 3. real save
$r   = Polo_Blocks_MCP::link_images( array( 'post_id' => 1, 'expected_hash' => $hash ) );
$new = $GLOBALS['posts'][1]->post_content;
check( 'saved once', array( 1 ) === $GLOBALS['saved'] && true === $r['saved'] && md5( $new ) === $r['content_hash'] );
check( 'href = attachment URL', false !== strpos( $new, '<a href="https://img.polo.blue/2021/06/att-24412-scaled.jpg"><img src="' . sprintf( $src, 1 ) . '"' ) );
check( 'linkDestination media in attrs', false !== strpos( $new, '"id":24412,"sizeSlug":"large","linkDestination":"media"' ) );
check( 'already linked untouched, no double <a>', 1 === substr_count( $new, 'linked-scaled-1.jpg' ) && 0 === substr_count( $new, '<a href="https://img.polo.blue/2021/06/att-24404' ) );
check( 'nested in columns linked', false !== strpos( $new, 'att-24394-scaled.jpg' ) );
check( 'fallback strips -WxH', false !== strpos( $new, '<a href="https://blog.polo.blue/images/2021/06/coolbox.jpg">' ) );
check( 'opt-out untouched', false === strpos( $new, 'att-500' ) );
check( 'caption link kept, image linked', false !== strpos( $new, '<a href="https://img.polo.blue/2021/06/att-600-scaled.jpg"><img' ) && false !== strpos( $new, '<a href="/y">cap</a>' ) );
check( 'img count unchanged', substr_count( $content, '<img' ) === substr_count( $new, '<img' ) );
check( 'everything outside images byte-identical', preg_replace( '#<a href="[^"]*">(<img[^>]*>)</a>#', '$1', str_replace( ',"linkDestination":"media"', '', str_replace( '"linkDestination":"none"', '"linkDestination":"media"', $new ) ) ) === preg_replace( '#<a href="[^"]*">(<img[^>]*>)</a>#', '$1', str_replace( ',"linkDestination":"media"', '', str_replace( '"linkDestination":"none"', '"linkDestination":"media"', $content ) ) ) );

// 4. idempotent: second run links nothing and does not save
$GLOBALS['saved'] = array();
$r = Polo_Blocks_MCP::link_images( array( 'post_id' => 1, 'expected_hash' => md5( $new ) ) );
check( 'second run is a no-op', ! $r['linked'] && false === $r['saved'] && ! $GLOBALS['saved'] );

echo $ok ? "\nALL PASS\n" : "\nFAILURES\n";
exit( $ok ? 0 : 1 );
