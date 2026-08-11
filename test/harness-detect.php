<?php
/**
 * Media Mage - reference-detection harness.
 *
 * One fixture per reference source, each a real attachment referenced exactly
 * one way, asserted against wpmj_is_referenced() directly.
 *
 * A false positive here (a referenced file reported unused) is the failure that
 * deletes a user's in-use images, so every case that SHOULD be referenced is
 * worth more than the ones that should not. The orphan and trashed-post cases
 * are the control group: without them, a detector that simply returned true
 * would pass everything else.
 *
 * Usage (from "Claude Code Projects"):
 *   /c/xampp/php/php.exe wp-cli.phar --path=".../wpmm-test-1" \
 *       eval-file media-mage/test/harness-detect.php
 */

if ( ! defined( 'ABSPATH' ) ) { echo "Run me through wp-cli eval-file.\n"; return; }

$GLOBALS['wpmj_d'] = [ 'pass' => 0, 'fail' => 0, 'failures' => [], 'made' => [], 'files' => [] ];

function wpmj_d_ok( $label, $cond, $detail = '' ) {
	$H =& $GLOBALS['wpmj_d'];
	if ( $cond ) { $H['pass']++; echo "  OK    $label\n"; }
	else { $H['fail']++; $H['failures'][] = $label . ( $detail ? " -- $detail" : '' ); echo "  FAIL  $label" . ( $detail ? "  ($detail)" : '' ) . "\n"; }
}

function wpmj_d_png( $seed ) {
	$s = 16; $raw = '';
	for ( $y = 0; $y < $s; $y++ ) {
		$raw .= chr( 0 );
		for ( $x = 0; $x < $s; $x++ ) $raw .= chr( $seed % 251 ) . chr( ( $seed * 7 ) % 251 ) . chr( ( $seed * 13 ) % 251 );
	}
	$ch = function ( $t, $d ) { return pack( 'N', strlen( $d ) ) . $t . $d . pack( 'N', crc32( $t . $d ) ); };
	return "\x89PNG\r\n\x1a\n" . $ch( 'IHDR', pack( 'NNCCCCC', $s, $s, 8, 2, 0, 0, 0 ) ) . $ch( 'IDAT', gzcompress( $raw, 9 ) ) . $ch( 'IEND', '' );
}

/** Attachment with real size files so the variant paths exist. */
function wpmj_d_attach( $name, $seed, $sizes = [ 'thumbnail' => [ 150, 150 ], 'medium' => [ 300, 200 ] ] ) {
	$H =& $GLOBALS['wpmj_d'];
	$up   = wp_upload_dir();
	$file = "$name.png";
	$path = trailingslashit( $up['path'] ) . $file;
	file_put_contents( $path, wpmj_d_png( $seed ) );
	$id = wp_insert_attachment( [ 'post_title' => $name, 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ], $path );

	$meta_sizes = [];
	foreach ( $sizes as $sn => $wh ) {
		$sf = "$name-{$wh[0]}x{$wh[1]}.png";
		file_put_contents( trailingslashit( $up['path'] ) . $sf, wpmj_d_png( $seed ) );
		$meta_sizes[ $sn ] = [ 'file' => $sf, 'width' => $wh[0], 'height' => $wh[1], 'mime-type' => 'image/png' ];
		$H['files'][] = trailingslashit( $up['path'] ) . $sf;
	}
	wp_update_attachment_metadata( $id, [ 'width' => 16, 'height' => 16, 'file' => _wp_relative_upload_path( $path ), 'sizes' => $meta_sizes ] );
	$H['made'][] = $id;
	$H['files'][] = $path;
	return $id;
}

function wpmj_d_post( $args ) {
	$H =& $GLOBALS['wpmj_d'];
	$id = wp_insert_post( array_merge( [ 'post_status' => 'publish', 'post_type' => 'post' ], $args ) );
	$H['made'][] = $id;
	return $id;
}

wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) ) {
	$a = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	if ( $a ) wp_set_current_user( (int) $a[0] );
}

global $wpdb;
$up_url = wp_upload_dir()['url'];
echo "\n=== Media Mage reference-detection harness ===\n\n";

// ---------------------------------------------------------------------------
// Cases that MUST come back referenced
// ---------------------------------------------------------------------------
echo "--- Should be REFERENCED ---\n";

// 1. Referenced only by its medium-size URL. This is the common real-world
//    case: inserting at Medium embeds the sized url, never the full-size one.
$a_sized = wpmj_d_attach( 'det-sized', 1 );
wpmj_d_post( [ 'post_title' => 'det sized', 'post_content' => '<img src="' . $up_url . '/det-sized-300x200.png">' ] );
wpmj_d_ok( 'referenced ONLY by its medium-size URL', wpmj_is_referenced( $a_sized ) );

// 2. Referenced only by its thumbnail URL.
$a_thumb = wpmj_d_attach( 'det-thumb', 2 );
wpmj_d_post( [ 'post_title' => 'det thumb', 'post_content' => '<img src="' . $up_url . '/det-thumb-150x150.png">' ] );
wpmj_d_ok( 'referenced ONLY by its thumbnail URL', wpmj_is_referenced( $a_thumb ) );

// 3. Gutenberg block that carries the ID and no URL at all.
$a_blockid = wpmj_d_attach( 'det-blockid', 3 );
wpmj_d_post( [
	'post_title'   => 'det blockid',
	'post_content' => '<!-- wp:image {"id":' . $a_blockid . ',"sizeSlug":"large"} --><figure class="wp-block-image"></figure><!-- /wp:image -->',
] );
wpmj_d_ok( 'referenced by Gutenberg block id only, no URL', wpmj_is_referenced( $a_blockid ) );

// 4. The wp-image-N class an inserted image carries.
$a_class = wpmj_d_attach( 'det-class', 4 );
wpmj_d_post( [ 'post_title' => 'det class', 'post_content' => '<img class="wp-image-' . $a_class . '" src="/elsewhere/cdn-rewritten.png">' ] );
wpmj_d_ok( 'referenced by wp-image-N class only', wpmj_is_referenced( $a_class ) );

// 5. JSON-escaped slashes inside block attributes.
$a_json = wpmj_d_attach( 'det-json', 5 );
wpmj_d_post( [
	'post_title'   => 'det json',
	'post_content' => wp_slash( '<!-- wp:cover {"url":"' . str_replace( '/', '\/', $up_url . '/det-json.png' ) . '"} --><div></div><!-- /wp:cover -->' ),
] );
wpmj_d_ok( 'referenced by a JSON-escaped URL in block attributes', wpmj_is_referenced( $a_json ) );

// 6. Site logo. This is a THEME MOD, not an option - and the original version
//    of this test set it with update_option(), exactly the same mistake the
//    code was making, so the test passed against a function that could never
//    work on a real site. Set it the way WordPress actually does.
$a_logo = wpmj_d_attach( 'det-logo', 6 );
$prev_logo = get_theme_mod( 'custom_logo' );
set_theme_mod( 'custom_logo', $a_logo );
wpmj_d_ok( 'referenced as the site logo (set as a theme mod, the way core does)',
	wpmj_is_referenced( $a_logo ) );
wpmj_d_ok( 'and the where-used path agrees about the logo',
	wpmj_reference_details( $a_logo )['count'] > 0 );

// A logo belonging to a DIFFERENT, inactive theme must also count - otherwise
// switching themes back finds its logo deleted.
$a_other_logo = wpmj_d_attach( 'det-logo-othertheme', 21 );
$other_mods = get_option( 'theme_mods_wpmj-detect-fixture-theme' );
update_option( 'theme_mods_wpmj-detect-fixture-theme', [ 'custom_logo' => $a_other_logo ] );
wpmj_d_ok( 'an inactive theme\'s logo counts as referenced too',
	wpmj_is_referenced( $a_other_logo ) );

// 7. Site icon.
$a_icon = wpmj_d_attach( 'det-icon', 7 );
$prev_icon = get_option( 'site_icon' );
update_option( 'site_icon', $a_icon );
wpmj_d_ok( 'referenced as the site icon', wpmj_is_referenced( $a_icon ) );

// 8. Term meta (category image).
$a_term = wpmj_d_attach( 'det-term', 8 );
$term = wp_insert_term( 'wpmj-det-term-' . wp_rand( 1000, 9999 ), 'category' );
if ( ! is_wp_error( $term ) ) {
	update_term_meta( $term['term_id'], 'wpmj_det_img', $up_url . '/det-term.png' );
	wpmj_d_ok( 'referenced from term meta', wpmj_is_referenced( $a_term ) );
	$GLOBALS['wpmj_d']['term'] = $term['term_id'];
}

// 9. User meta (profile image).
$a_user = wpmj_d_attach( 'det-user', 9 );
update_user_meta( get_current_user_id(), 'wpmj_det_img', $up_url . '/det-user.png' );
wpmj_d_ok( 'referenced from user meta', wpmj_is_referenced( $a_user ) );

// 10. Comment content.
$a_comment = wpmj_d_attach( 'det-comment', 10 );
$host_post = wpmj_d_post( [ 'post_title' => 'det comment host', 'post_content' => 'host' ] );
$cid = wp_insert_comment( [
	'comment_post_ID' => $host_post,
	'comment_content' => 'See <img src="' . $up_url . '/det-comment.png">',
	'comment_approved' => 1,
] );
wpmj_d_ok( 'referenced from comment content', wpmj_is_referenced( $a_comment ) );

// 11. Comment meta.
$a_cmeta = wpmj_d_attach( 'det-cmeta', 11 );
if ( $cid ) update_comment_meta( $cid, 'wpmj_det_img', $up_url . '/det-cmeta.png' );
wpmj_d_ok( 'referenced from comment meta', $cid ? wpmj_is_referenced( $a_cmeta ) : false );

// 12. A draft post still counts - it is going to be published.
$a_draft = wpmj_d_attach( 'det-draft', 12 );
wpmj_d_post( [ 'post_title' => 'det draft', 'post_status' => 'draft', 'post_content' => '<img src="' . $up_url . '/det-draft.png">' ] );
wpmj_d_ok( 'referenced from a draft post', wpmj_is_referenced( $a_draft ) );

// 13. A TRASHED post still counts. Trash is restorable, and deleting the images
//     a trashed post uses breaks that restore permanently. Erring toward
//     "referenced" is the only safe direction for a tool whose mistakes cannot
//     be undone.
$a_trashed = wpmj_d_attach( 'det-trashed', 15 );
$tp = wpmj_d_post( [ 'post_title' => 'det trashed', 'post_content' => '<img src="' . $up_url . '/det-trashed.png">' ] );
wp_trash_post( $tp );
wpmj_d_ok( 'a reference from a restorable trashed post still counts', wpmj_is_referenced( $a_trashed ) );

// 14. The escape-hatch filter.
$a_filter = wpmj_d_attach( 'det-filter', 13 );
wpmj_d_ok( 'unreferenced before the filter is added', ! wpmj_is_referenced( $a_filter ) );
add_filter( 'wpmj_is_referenced', function ( $ref, $id ) use ( $a_filter ) {
	return $id === $a_filter ? true : $ref;
}, 10, 2 );
wpmj_d_ok( 'the wpmj_is_referenced filter can protect a file', wpmj_is_referenced( $a_filter ) );

// ---------------------------------------------------------------------------
// Control group: these MUST come back unreferenced, or the detector is just
// answering true to everything.
// ---------------------------------------------------------------------------
echo "\n--- Should be UNREFERENCED (control group) ---\n";

$a_orphan = wpmj_d_attach( 'det-orphan', 14 );
wpmj_d_ok( 'a genuine orphan is unreferenced', ! wpmj_is_referenced( $a_orphan ) );

// A near-miss name: det-orphan2 must not be rescued by det-orphan's reference.
$a_near = wpmj_d_attach( 'det-orphan-extra', 16 );
wpmj_d_post( [ 'post_title' => 'det near', 'post_content' => '<img src="' . $up_url . '/det-orphan.png">' ] );
wpmj_d_ok( 'a similarly-named file is not rescued by its neighbour', ! wpmj_is_referenced( $a_near ) );

// An attachment must not reference ITSELF through its own postmeta rows.
$a_self = wpmj_d_attach( 'det-self', 17 );
wpmj_d_ok( 'an attachment does not reference itself via its own meta', ! wpmj_is_referenced( $a_self ) );

// ---------------------------------------------------------------------------
// Double-serialized values must survive a deep replace intact.
//
// maybe_serialize() re-serializes any string that already looks serialized, so
// double-serialized meta is routine. A plain str_replace through the inner
// payload changes string lengths without updating the s:N: prefixes that
// declare them; serialize() then repairs only the OUTER layer, so the value
// passes a shallow check and is written back permanently unreadable.
// ---------------------------------------------------------------------------
echo "\n--- Nested serialization survives a deep replace ---\n";
$inner_before = serialize( [ 'img' => 'https://example.com/a-very-long-filename.png', 'note' => 'keep me' ] );
$nested       = [ 'payload' => $inner_before, 'flag' => true ];
$nested_after = wpmj_deep_replace( 'https://example.com/a-very-long-filename.png', 'https://example.com/short.png', $nested );

wpmj_d_ok( 'outer structure survives', is_array( $nested_after ) && isset( $nested_after['payload'] ) );
$inner_after = @unserialize( $nested_after['payload'] );
wpmj_d_ok( 'INNER serialized payload still unserializes',
	is_array( $inner_after ), 'got ' . var_export( $inner_after, true ) );
wpmj_d_ok( 'inner URL was actually replaced',
	is_array( $inner_after ) && ( $inner_after['img'] ?? '' ) === 'https://example.com/short.png',
	var_export( $inner_after['img'] ?? null, true ) );
wpmj_d_ok( 'inner untouched key intact',
	is_array( $inner_after ) && ( $inner_after['note'] ?? '' ) === 'keep me' );
wpmj_d_ok( 'non-string sibling intact', ( $nested_after['flag'] ?? null ) === true );

// Triple nesting, because the recursion has to hold at depth.
$deep_before = serialize( [ 'lvl2' => serialize( [ 'u' => 'https://example.com/a-very-long-filename.png' ] ) ] );
$deep_after  = wpmj_deep_replace( 'https://example.com/a-very-long-filename.png', 'https://example.com/short.png', $deep_before );
$d1 = @unserialize( $deep_after );
$d2 = is_array( $d1 ) ? @unserialize( $d1['lvl2'] ) : false;
wpmj_d_ok( 'triple-nested serialization round-trips',
	is_array( $d2 ) && ( $d2['u'] ?? '' ) === 'https://example.com/short.png',
	var_export( $d2, true ) );

// ---------------------------------------------------------------------------
// delete_unused must re-check at delete time, not trust the stored snapshot.
// ---------------------------------------------------------------------------
echo "\n--- delete_unused re-verifies before deleting ---\n";

class WPMJ_Det_Done extends Exception {}
add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', function () { return function () { throw new WPMJ_Det_Done(); }; } );

$a_race = wpmj_d_attach( 'det-race', 18 );
wpmj_d_ok( 'race fixture starts genuinely unused', ! wpmj_is_referenced( $a_race ) );

// A scan result that lists it as unused - the stale snapshot.
set_transient( WPMJ_TRANSIENT_RESULTS, [
	'duplicates' => [], 'unused' => [ (int) $a_race ], 'missing' => [],
	'scanned_at' => current_time( 'mysql' ),
], WPMJ_RESULTS_TTL );

// Meanwhile the admin puts it on a page.
wpmj_d_post( [ 'post_title' => 'det race user', 'post_content' => '<img src="' . $up_url . '/det-race.png">' ] );
wpmj_d_ok( 'race fixture is now genuinely referenced', wpmj_is_referenced( $a_race ) );

$_POST = $_REQUEST = [ 'ids' => (string) $a_race, 'nonce' => wp_create_nonce( WPMJ_NONCE_ACTION ) ];
ob_start();
try { wpmj_ajax_delete_unused(); } catch ( WPMJ_Det_Done $e ) {} catch ( Throwable $e ) {}
$resp = json_decode( ob_get_clean(), true );

wpmj_d_ok( 'the now-referenced file was NOT deleted', get_post_type( $a_race ) === 'attachment',
	'response: ' . wp_json_encode( $resp ) );
wpmj_d_ok( 'it was reported as skipped rather than silently ignored',
	! empty( $resp['data']['skipped'] ), 'response: ' . wp_json_encode( $resp ) );
delete_transient( WPMJ_TRANSIENT_RESULTS );

// ---------------------------------------------------------------------------
// ID matching: every real delimiter must match, prefixes must not.
//
// Anchoring these patterns cuts both ways. Too loose and "wp-image-217" matches
// "wp-image-21708", hiding a genuinely unused file. Too strict and a real
// reference is missed, which DELETES a live image - much the worse failure. So
// both directions are pinned here.
// ---------------------------------------------------------------------------
echo "\n--- Attachment-ID reference matching ---\n";

$a_ids = wpmj_d_attach( 'det-idmatch', 20, [] );
$id_host = wpmj_d_post( [ 'post_title' => 'det idmatch host', 'post_content' => 'placeholder' ] );

$id_cases = [
	// label                        content                                                 must match?
	[ 'gallery, id first',          '[gallery ids="%d,900,901"]',                            true ],
	[ 'gallery, id in the middle',  '[gallery ids="900,%d,901"]',                            true ],
	[ 'gallery, id last',           '[gallery ids="900,901,%d"]',                            true ],
	[ 'gallery, id alone',          '[gallery ids="%d"]',                                    true ],
	[ 'class, double quoted',       '<img class="size-full wp-image-%d">',                   true ],
	[ 'class, followed by another', '<img class="wp-image-%d alignleft">',                   true ],
	[ 'block id with more keys',    '<!-- wp:image {"id":%d,"sizeSlug":"large"} -->',        true ],
	[ 'block id as the only key',   '<!-- wp:image {"id":%d} -->',                           true ],
	[ 'block id with a space',      '<!-- wp:image {"id": %d} -->',                          true ],
	// These are the forms an enumerated list of following characters missed.
	// A false negative here deletes a live image, so each one is pinned.
	[ 'class followed by a newline',  "<img class=\"foo\nwp-image-%d\nbar\">",                true ],
	[ 'class followed by a tab',      "<img class=\"foo\twp-image-%d\tbar\">",                true ],
	[ 'unquoted class attribute',     '<img class=wp-image-%d>',                             true ],
	[ 'class at the very end',        'trailing class wp-image-%d',                          true ],
	[ 'pretty-printed id, space',     '{"id": %d , "x":1}',                                  true ],
	[ 'pretty-printed id, newline',   "{\"id\": %d\n}",                                       true ],
	[ 'id closing a JSON array',      '[{"id":%d}]',                                         true ],
	// The gallery forms the media modal writes once any option is set.
	[ 'gallery with columns first',   '[gallery columns="3" ids="%d"]',                      true ],
	[ 'gallery with link first',      '[gallery link="file" ids="9,%d"]',                    true ],
	[ 'playlist shortcode',           '[playlist ids="9,%d,4"]',                             true ],
	// WordPress' shortcode parser accepts all of these and each renders a live
	// image. A gallery carries no URL, so this regex is the only thing between
	// it and deletion - four of these five were invisible until an audit
	// rendered them and checked.
	[ 'gallery, single quotes',       "[gallery ids='%d']",                                  true ],
	[ 'gallery, single-quoted list',  "[gallery ids='9,%d,4']",                              true ],
	[ 'gallery, no quotes',           '[gallery ids=%d]',                                    true ],
	[ 'gallery, include attribute',   '[gallery include="%d"]',                              true ],
	[ 'gallery, spaced equals',       '[gallery ids = "%d"]',                                true ],
	[ 'gallery, longer id (reject)',  '[gallery ids="%d7"]',                                 false ],
	// The Gutenberg gallery block has no equals sign and no URL at all.
	[ 'gutenberg gallery block',      '<!-- wp:gallery {"ids":[45,%d]} -->',                 true ],
	[ 'gutenberg gallery, alone',     '<!-- wp:gallery {"ids":[%d]} -->',                    true ],
	[ 'json ids array in content',    '{"ids":[9,%d,4]}',                                    true ],
	// Attribute names that merely END in "ids" are not media references.
	[ 'data-ids attribute (reject)',  '<div data-ids="%d">x</div>',                          false ],
	[ 'category_ids attr (reject)',   '[news category_ids="%d"]',                            false ],
	[ 'author_ids attr (reject)',     '[team author_ids="%d"]',                              false ],
	[ 'excluded_ids attr (reject)',   '[slider excluded_ids="%d"]',                          false ],
	// Must NOT match:
	[ 'a thousands-separated number in prose', 'The figure was 1,%d,456 last year.',         false ],
	[ 'an id this one is a prefix of',         '<img class="wp-image-%d7">',                 false ],
	[ 'a longer id in a block',                '<!-- wp:image {"id":%d7} -->',               false ],
];

foreach ( $id_cases as $case ) {
	list( $label, $tpl, $want ) = $case;
	wp_update_post( [ 'ID' => $id_host, 'post_content' => sprintf( $tpl, $a_ids ) ] );
	clean_post_cache( $id_host );
	$got = wpmj_is_referenced( $a_ids );
	wpmj_d_ok(
		( $want ? 'matches: ' : 'does not match: ' ) . $label,
		$got === $want,
		'got ' . ( $got ? 'referenced' : 'unreferenced' )
	);
}
wp_update_post( [ 'ID' => $id_host, 'post_content' => 'placeholder' ] );
clean_post_cache( $id_host );

// ---------------------------------------------------------------------------
// A file this plugin already trashed must not be offered up again.
//
// The attachment row still exists and is still unreferenced, so without an
// explicit exclusion the very next scan lists it as unused all over again and
// invites the user to delete what they already dealt with.
// ---------------------------------------------------------------------------
echo "\n--- Trashed attachments are out of scope for the scan ---\n";

$a_trashed_att = wpmj_d_attach( 'det-already-trashed', 19 );
update_post_meta( $a_trashed_att, '_wpmj_trashed_at', current_time( 'mysql' ) );
wp_trash_post( $a_trashed_att );
wpmj_d_ok( 'fixture really is trashed', get_post_status( $a_trashed_att ) === 'trash' );

$_POST = $_REQUEST = [ 'nonce' => wp_create_nonce( WPMJ_NONCE_ACTION ) ];
ob_start();
try { wpmj_ajax_scan_init(); } catch ( WPMJ_Det_Done $e ) {} catch ( Throwable $e ) {}
$init = json_decode( ob_get_clean(), true );
$total = (int) ( $init['data']['total'] ?? 0 );

$live = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status!='trash'" );
wpmj_d_ok( 'scan total excludes trashed attachments', $total === $live, "total=$total live=$live" );

// Walk the whole scan and confirm the trashed ID is never handed to a chunk.
$seen_trashed = false;
foreach ( [ 'hash', 'references' ] as $ph ) {
	$off = 0; $g = 0;
	while ( true ) {
		if ( ++$g > $total + 50 ) break;
		$_POST = $_REQUEST = [ 'offset' => $off, 'phase' => $ph, 'nonce' => wp_create_nonce( WPMJ_NONCE_ACTION ) ];
		ob_start();
		try { wpmj_ajax_scan_chunk(); } catch ( WPMJ_Det_Done $e ) {} catch ( Throwable $e ) {}
		$r = json_decode( ob_get_clean(), true );
		if ( empty( $r['success'] ) ) break;
		foreach ( (array) ( $r['data']['files'] ?? [] ) as $fn ) {
			if ( strpos( $fn, 'det-already-trashed' ) !== false ) $seen_trashed = true;
		}
		$off = (int) $r['data']['next_offset'];
		if ( ! empty( $r['data']['done'] ) ) break;
	}
}
wpmj_d_ok( 'the scan never visits a trashed attachment', ! $seen_trashed );

$results = get_transient( WPMJ_TRANSIENT_RESULTS );
wpmj_d_ok( 'a trashed attachment is not re-reported as unused',
	is_array( $results ) && ! in_array( (int) $a_trashed_att, array_map( 'intval', (array) $results['unused'] ), true ),
	'unused list: ' . implode( ',', array_slice( (array) ( $results['unused'] ?? [] ), 0, 20 ) ) );

delete_transient( WPMJ_TRANSIENT_RESULTS );
delete_transient( WPMJ_TRANSIENT_SCAN_STATE );

// ---------------------------------------------------------------------------
// Teardown
// ---------------------------------------------------------------------------
echo "\n--- Teardown ---\n";
remove_all_filters( 'wpmj_is_referenced' );
if ( $prev_logo ) set_theme_mod( 'custom_logo', $prev_logo ); else remove_theme_mod( 'custom_logo' );
if ( $other_mods !== false ) update_option( 'theme_mods_wpmj-detect-fixture-theme', $other_mods );
else delete_option( 'theme_mods_wpmj-detect-fixture-theme' );
if ( $prev_icon ) update_option( 'site_icon', $prev_icon ); else delete_option( 'site_icon' );
delete_user_meta( get_current_user_id(), 'wpmj_det_img' );
if ( ! empty( $GLOBALS['wpmj_d']['term'] ) ) wp_delete_term( $GLOBALS['wpmj_d']['term'], 'category' );
if ( ! empty( $cid ) ) wp_delete_comment( $cid, true );
$n = 0;
foreach ( $GLOBALS['wpmj_d']['made'] as $id ) {
	if ( get_post_type( $id ) === false ) continue;
	if ( get_post_type( $id ) === 'attachment' ) wp_delete_attachment( $id, true ); else wp_delete_post( $id, true );
	$n++;
}
foreach ( $GLOBALS['wpmj_d']['files'] as $f ) if ( file_exists( $f ) ) @unlink( $f );
echo "  removed $n fixture objects\n";

$H = $GLOBALS['wpmj_d'];
echo "\n=== Summary ===\n  passed: {$H['pass']}\n  failed: {$H['fail']}\n";
if ( $H['failures'] ) { echo "\n  Failures:\n"; foreach ( $H['failures'] as $f ) echo "    - $f\n"; }
echo "\nDETECT HARNESS: " . ( $H['fail'] === 0 ? 'PASS' : 'FAIL' ) . "\n\n";
