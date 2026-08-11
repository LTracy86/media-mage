<?php
/**
 * Media Mage - resolve_duplicate / delete_unused integrity harness.
 *
 * The resolve flow is the most destructive thing this plugin does: it rewrites
 * references across posts, postmeta and options and then permanently deletes
 * files. This builds its own isolated fixture, resolves it, and then asserts
 * the things a user would notice if they broke:
 *
 *   - the keeper's file is still ON DISK afterwards
 *   - the keeper's own _wp_attached_file / _wp_attachment_metadata are intact
 *   - post content that pointed at the duplicate now points at the keeper
 *   - featured images that pointed at the duplicate now point at the keeper
 *   - serialized option / meta values survive the rewrite as valid serialized
 *     data with the right value replaced and nothing else touched
 *   - unrelated attachments with similar names are NOT rewritten
 *
 * Every fixture it creates is tagged and torn down at the end.
 *
 * Usage (from "Claude Code Projects"):
 *   /c/xampp/php/php.exe wp-cli.phar --path=".../wpmm-test-1" \
 *       eval-file media-mage/test/harness-resolve.php
 */

if ( ! defined( 'ABSPATH' ) ) { echo "Run me through wp-cli eval-file.\n"; return; }

class WPMJ_RH_Done extends Exception {}

add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', function () {
	return function () { throw new WPMJ_RH_Done(); };
} );

$GLOBALS['wpmj_rh'] = [ 'pass' => 0, 'fail' => 0, 'failures' => [], 'made' => [] ];

function wpmj_rh_call( $action, $post = [] ) {
	$fn = "wpmj_ajax_$action";
	if ( ! function_exists( $fn ) ) return [ 'success' => false, 'data' => [ 'message' => "no $fn" ] ];
	$_POST = $_REQUEST = $post;
	$_POST['nonce'] = $_REQUEST['nonce'] = wp_create_nonce( WPMJ_NONCE_ACTION );
	ob_start();
	try { $fn(); }
	catch ( WPMJ_RH_Done $e ) {}
	catch ( Throwable $e ) { ob_get_clean(); return [ 'success' => false, 'data' => [ 'message' => 'THREW ' . $e->getMessage() ] ]; }
	$out = ob_get_clean();
	$j = json_decode( $out, true );
	return $j === null ? [ 'success' => false, 'data' => [ 'message' => 'non-JSON: ' . substr( $out, 0, 200 ) ] ] : $j;
}

function wpmj_rh_ok( $label, $cond, $detail = '' ) {
	if ( $cond ) { $GLOBALS['wpmj_rh']['pass']++; echo "  OK    $label\n"; }
	else { $GLOBALS['wpmj_rh']['fail']++; $GLOBALS['wpmj_rh']['failures'][] = "$label" . ( $detail ? " -- $detail" : '' ); echo "  FAIL  $label" . ( $detail ? "  ($detail)" : '' ) . "\n"; }
}

/** Minimal valid PNG of a given solid colour, written without GD. */
function wpmj_rh_png( $r, $g, $b, $size = 16 ) {
	$raw = '';
	for ( $y = 0; $y < $size; $y++ ) {
		$raw .= chr( 0 );
		for ( $x = 0; $x < $size; $x++ ) $raw .= chr( $r ) . chr( $g ) . chr( $b );
	}
	$chunk = function ( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	};
	return "\x89PNG\r\n\x1a\n"
		. $chunk( 'IHDR', pack( 'NNCCCCC', $size, $size, 8, 2, 0, 0, 0 ) )
		. $chunk( 'IDAT', gzcompress( $raw, 9 ) )
		. $chunk( 'IEND', '' );
}

/**
 * Create a real attachment from bytes. Returns the attachment ID.
 *
 * $sizes is a map of size-name => [w, h]; each one gets a real file on disk and
 * an entry in the attachment metadata, so the fixture looks like an image
 * WordPress actually processed rather than a bare row.
 */
function wpmj_rh_attach( $filename, $bytes, $sizes = [] ) {
	$up   = wp_upload_dir();
	$path = trailingslashit( $up['path'] ) . $filename;
	file_put_contents( $path, $bytes );
	$id = wp_insert_attachment( [
		'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
	], $path );

	$base = pathinfo( $filename, PATHINFO_FILENAME );
	$meta_sizes = [];
	foreach ( $sizes as $name => $wh ) {
		$sfile = "{$base}-{$wh[0]}x{$wh[1]}.png";
		file_put_contents( trailingslashit( $up['path'] ) . $sfile, $bytes );
		$meta_sizes[ $name ] = [ 'file' => $sfile, 'width' => $wh[0], 'height' => $wh[1], 'mime-type' => 'image/png' ];
		$GLOBALS['wpmj_rh']['files'][] = trailingslashit( $up['path'] ) . $sfile;
	}

	wp_update_attachment_metadata( $id, [
		'width'  => 16,
		'height' => 16,
		'file'   => _wp_relative_upload_path( $path ),
		'sizes'  => $meta_sizes,
	] );
	update_post_meta( $id, '_wpmj_rh_fixture', 1 );
	$GLOBALS['wpmj_rh']['made'][] = [ 'type' => 'attachment', 'id' => $id ];
	return $id;
}

function wpmj_rh_post( $args ) {
	$id = wp_insert_post( array_merge( [ 'post_status' => 'publish', 'post_type' => 'post' ], $args ) );
	update_post_meta( $id, '_wpmj_rh_fixture', 1 );
	$GLOBALS['wpmj_rh']['made'][] = [ 'type' => 'post', 'id' => $id ];
	return $id;
}

// ---------------------------------------------------------------------------
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) ) {
	$a = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	if ( $a ) wp_set_current_user( (int) $a[0] );
}

global $wpdb;
echo "\n=== Media Mage resolve/delete integrity harness ===\n\n";

// ---------------------------------------------------------------------------
// Fixture: three byte-identical PNGs + one similarly-named unrelated file.
// ---------------------------------------------------------------------------
echo "--- Building fixture ---\n";
$bytes_dup   = wpmj_rh_png( 200, 30, 30 );
$bytes_other = wpmj_rh_png( 30, 200, 30 );

$SIZES  = [ 'thumbnail' => [ 150, 150 ], 'medium' => [ 300, 200 ] ];
$keeper = wpmj_rh_attach( 'rh-shot.png',     $bytes_dup, $SIZES );   // the one to keep
$dup1   = wpmj_rh_attach( 'rh-shot-b.png',   $bytes_dup, $SIZES );   // duplicate
$dup2   = wpmj_rh_attach( 'rh-shot-c.png',   $bytes_dup, $SIZES );   // duplicate
// Decoy: name is a SUPERSTRING of the keeper's name. A sloppy path replace
// that ignores boundaries will corrupt this one.
$decoy  = wpmj_rh_attach( 'rh-shot-b-extra.png', $bytes_other );

$keeper_url = wp_get_attachment_url( $keeper );
$dup1_url   = wp_get_attachment_url( $dup1 );
$dup2_url   = wp_get_attachment_url( $dup2 );
$decoy_url  = wp_get_attachment_url( $decoy );

echo "  keeper #$keeper $keeper_url\n  dup1   #$dup1 $dup1_url\n  dup2   #$dup2 $dup2_url\n  decoy  #$decoy $decoy_url\n";

// Size-variant URLs. Inserting an image at Thumbnail/Medium size embeds THESE,
// not the full-size URL, so on a real site they are the common reference.
$up_url          = wp_upload_dir()['url'];
$dup1_thumb_url  = $up_url . '/rh-shot-b-150x150.png';
$dup1_med_url    = $up_url . '/rh-shot-b-300x200.png';
$keep_thumb_url  = $up_url . '/rh-shot-150x150.png';
$keep_med_url    = $up_url . '/rh-shot-300x200.png';

// A post embedding dup1 by URL, at full size AND at both generated sizes.
$post_embed = wpmj_rh_post( [
	'post_title'   => 'RH embed post',
	'post_content' => 'Before <img src="' . $dup1_url . '" alt="x"> after. '
		. 'Thumb: <img src="' . $dup1_thumb_url . '"> '
		. 'Medium: <img src="' . $dup1_med_url . '"> '
		. 'Decoy: <img src="' . $decoy_url . '">',
] );

// A Gutenberg-style block whose JSON attributes carry the URL with escaped
// slashes - the form a plain string replace on the raw URL never matches.
// wp_insert_post() unslashes what it is given, so the backslashes have to be
// doubled on the way in or the escaped form never reaches the database.
$block_body = '<!-- wp:cover {"url":"' . str_replace( '/', '\/', $dup1_url ) . '","id":' . $dup1 . '} -->'
	. '<div class="wp-block-cover"></div><!-- /wp:cover -->';
$post_block = wpmj_rh_post( [
	'post_title'   => 'RH block post',
	'post_content' => wp_slash( $block_body ),
] );
wpmj_rh_ok( 'block fixture really stored escaped slashes',
	strpos( get_post_field( 'post_content', $post_block ), str_replace( '/', '\/', $dup1_url ) ) !== false,
	get_post_field( 'post_content', $post_block ) );

// A post whose featured image is dup2.
$post_feat = wpmj_rh_post( [ 'post_title' => 'RH featured post', 'post_content' => 'nothing inline' ] );
set_post_thumbnail( $post_feat, $dup2 );

// A serialized postmeta value holding dup1's URL alongside other data.
$serialized_meta = [ 'hero' => $dup1_url, 'keepme' => 'do not touch', 'n' => 42, 'nested' => [ 'img' => $dup1_url ] ];
update_post_meta( $post_embed, '_rh_serialized', $serialized_meta );

// A serialized OPTION holding dup1's URL.
update_option( '_rh_option', [ 'logo' => $dup1_url, 'other' => $decoy_url, 'flag' => true ] );

// A plain-string option.
update_option( '_rh_plain', 'url is ' . $dup1_url . ' end' );

// A serialized value containing an OBJECT. Unserializing this without the class
// loaded yields __PHP_Incomplete_Class, and re-serializing writes that over the
// user's data. The plugin must leave it strictly alone rather than corrupt it.
$obj = new stdClass();
$obj->img  = $decoy_url;   // deliberately NOT dup1 - see the blocked-delete test
$obj->note = 'object payload';
update_option( '_rh_object', [ 'wrapped' => $obj, 'plain' => 'x' ] );
$obj_option_raw_before = $wpdb->get_var( $wpdb->prepare(
	"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_rh_object'
) );

// Oxygen stores its layout base64-encoded. Detection decodes it, so these files
// are correctly seen as in use - but the REWRITE used to run a plain LIKE
// against the encoded blob, which cannot match, so resolving deleted a file the
// layout still pointed at. On the exact use case the plugin was built for.
$oxy_plain  = '[ct_section][ct_image src="' . $dup1_url . '"][/ct_section]';
$oxy_post   = wpmj_rh_post( [ 'post_title' => 'RH oxygen page', 'post_content' => '' ] );
update_post_meta( $oxy_post, '_ct_builder_shortcodes', base64_encode( $oxy_plain ) );

$oxy_css_plain = '.hero{background-image:url(' . $dup1_url . ');}';
$prev_sheets   = get_option( 'ct_style_sheets' );
update_option( 'ct_style_sheets', [ [ 'name' => 'rh', 'css' => base64_encode( $oxy_css_plain ) ] ] );

wpmj_rh_ok( 'oxygen fixture really is base64 encoded',
	base64_decode( get_post_meta( $oxy_post, '_ct_builder_shortcodes', true ), true ) === $oxy_plain );

$keeper_file = get_attached_file( $keeper );
$dup1_file   = get_attached_file( $dup1 );
$dup2_file   = get_attached_file( $dup2 );
$decoy_file  = get_attached_file( $decoy );
$keeper_relpath_before = get_post_meta( $keeper, '_wp_attached_file', true );
$keeper_meta_before    = wp_get_attachment_metadata( $keeper );

wpmj_rh_ok( 'fixture files exist on disk', file_exists( $keeper_file ) && file_exists( $dup1_file ) && file_exists( $dup2_file ) && file_exists( $decoy_file ) );
wpmj_rh_ok( 'keeper and dups are byte-identical', md5_file( $keeper_file ) === md5_file( $dup1_file ) && md5_file( $keeper_file ) === md5_file( $dup2_file ) );

// ---------------------------------------------------------------------------
// Scan so the results transient exists, then resolve.
// ---------------------------------------------------------------------------
echo "\n--- Scan, then resolve keeper<-dup1,dup2 ---\n";
$init = wpmj_rh_call( 'scan_init' );
$total = (int) ( $init['data']['total'] ?? 0 );
foreach ( [ 'hash', 'references' ] as $phase ) {
	$offset = 0; $guard = 0;
	while ( true ) {
		if ( ++$guard > $total + 50 ) break;
		$r = wpmj_rh_call( 'scan_chunk', [ 'offset' => $offset, 'phase' => $phase ] );
		if ( empty( $r['success'] ) ) break;
		$offset = (int) $r['data']['next_offset'];
		if ( ! empty( $r['data']['done'] ) ) break;
	}
}

$r = wpmj_rh_call( 'resolve_duplicate', [ 'keeper_id' => $keeper, 'duplicate_ids' => "$dup1,$dup2" ] );
wpmj_rh_ok( 'resolve_duplicate succeeded', ! empty( $r['success'] ), wp_json_encode( $r['data'] ?? [] ) );
echo "  deleted={$r['data']['deleted']} refs_updated={$r['data']['refs_updated']}\n";

// ---------------------------------------------------------------------------
// THE assertions
// ---------------------------------------------------------------------------
echo "\n--- Keeper survived intact ---\n";
clean_post_cache( $keeper );
wpmj_rh_ok( 'keeper attachment row still exists', get_post_type( $keeper ) === 'attachment' );
wpmj_rh_ok( "keeper file still on disk ($keeper_file)", file_exists( $keeper_file ) );
wpmj_rh_ok( 'keeper _wp_attached_file unchanged',
	get_post_meta( $keeper, '_wp_attached_file', true ) === $keeper_relpath_before,
	'before=' . var_export( $keeper_relpath_before, true ) . ' after=' . var_export( get_post_meta( $keeper, '_wp_attached_file', true ), true ) );
$keeper_meta_after = wp_get_attachment_metadata( $keeper );
wpmj_rh_ok( 'keeper attachment metadata still an array with its file key',
	is_array( $keeper_meta_after ) && ! empty( $keeper_meta_after['file'] ),
	'after=' . var_export( $keeper_meta_after, true ) );
wpmj_rh_ok( 'keeper URL still resolves', (bool) wp_get_attachment_url( $keeper ) );

echo "\n--- Duplicates are gone ---\n";
wpmj_rh_ok( 'dup1 row deleted', get_post_type( $dup1 ) === false );
wpmj_rh_ok( 'dup2 row deleted', get_post_type( $dup2 ) === false );
wpmj_rh_ok( 'dup1 file removed from disk', ! file_exists( $dup1_file ) );
wpmj_rh_ok( 'dup2 file removed from disk', ! file_exists( $dup2_file ) );

echo "\n--- References were re-pointed ---\n";
$content_after = get_post_field( 'post_content', $post_embed );
wpmj_rh_ok( 'post content no longer references dup1', strpos( $content_after, $dup1_url ) === false, $content_after );
wpmj_rh_ok( 'post content now references the keeper', strpos( $content_after, $keeper_url ) !== false, $content_after );
wpmj_rh_ok( 'featured image re-pointed to the keeper',
	(int) get_post_thumbnail_id( $post_feat ) === (int) $keeper,
	'thumb id = ' . get_post_thumbnail_id( $post_feat ) );

echo "\n--- Size variants were re-pointed (the common real-world reference) ---\n";
wpmj_rh_ok( 'thumbnail URL no longer points at the duplicate',
	strpos( $content_after, $dup1_thumb_url ) === false, $content_after );
wpmj_rh_ok( 'thumbnail URL now points at the keeper thumbnail',
	strpos( $content_after, $keep_thumb_url ) !== false, $content_after );
wpmj_rh_ok( 'medium URL no longer points at the duplicate',
	strpos( $content_after, $dup1_med_url ) === false, $content_after );
wpmj_rh_ok( 'medium URL now points at the keeper medium',
	strpos( $content_after, $keep_med_url ) !== false, $content_after );

echo "\n--- JSON-escaped slashes in block attributes were re-pointed ---\n";
$block_after = get_post_field( 'post_content', $post_block );
wpmj_rh_ok( 'block JSON no longer carries the duplicate URL',
	strpos( $block_after, str_replace( '/', '\/', $dup1_url ) ) === false, $block_after );
wpmj_rh_ok( 'block JSON now carries the keeper URL',
	strpos( $block_after, str_replace( '/', '\/', $keeper_url ) ) !== false, $block_after );

echo "\n--- Object-bearing serialized data was left alone, not corrupted ---\n";
$obj_raw_after = $wpdb->get_var( $wpdb->prepare(
	"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_rh_object'
) );
wpmj_rh_ok( 'object-bearing option is byte-identical (skipped, not rewritten)',
	$obj_raw_after === $obj_option_raw_before,
	'len before=' . strlen( (string) $obj_option_raw_before ) . ' after=' . strlen( (string) $obj_raw_after ) );
$obj_after = get_option( '_rh_object' );
wpmj_rh_ok( 'object-bearing option still unserializes to a real object',
	is_array( $obj_after ) && isset( $obj_after['wrapped'] ) && is_object( $obj_after['wrapped'] )
		&& ! ( $obj_after['wrapped'] instanceof __PHP_Incomplete_Class ),
	var_export( $obj_after, true ) );

echo "\n--- Oxygen's base64 layout data was rewritten, not orphaned ---\n";
$oxy_after_raw = get_post_meta( $oxy_post, '_ct_builder_shortcodes', true );
$oxy_after     = base64_decode( $oxy_after_raw, true );
wpmj_rh_ok( 'oxygen builder data is still valid base64 after the rewrite',
	$oxy_after !== false && base64_encode( $oxy_after ) === $oxy_after_raw );
wpmj_rh_ok( 'oxygen builder data no longer names the deleted duplicate',
	is_string( $oxy_after ) && strpos( $oxy_after, $dup1_url ) === false, (string) $oxy_after );
wpmj_rh_ok( 'oxygen builder data now points at the keeper',
	is_string( $oxy_after ) && strpos( $oxy_after, $keeper_url ) !== false, (string) $oxy_after );
wpmj_rh_ok( 'the rest of the oxygen shortcode survived intact',
	is_string( $oxy_after ) && strpos( $oxy_after, '[ct_section]' ) === 0 );

$sheets_after = get_option( 'ct_style_sheets' );
$css_after    = is_array( $sheets_after ) && isset( $sheets_after[0]['css'] )
	? base64_decode( $sheets_after[0]['css'], true ) : false;
wpmj_rh_ok( 'oxygen stylesheet css is still valid base64',
	$css_after !== false && base64_encode( $css_after ) === $sheets_after[0]['css'] );
wpmj_rh_ok( 'oxygen stylesheet css was re-pointed to the keeper',
	is_string( $css_after ) && strpos( $css_after, $keeper_url ) !== false
		&& strpos( $css_after, $dup1_url ) === false, (string) $css_after );

echo "\n--- The decoy was not collateral damage ---\n";
wpmj_rh_ok( 'decoy attachment still exists', get_post_type( $decoy ) === 'attachment' );
wpmj_rh_ok( 'decoy file still on disk', file_exists( $decoy_file ) );
wpmj_rh_ok( 'decoy URL still referenced in the post unchanged',
	strpos( $content_after, $decoy_url ) !== false,
	'decoy_url=' . $decoy_url . ' content=' . $content_after );

echo "\n--- Serialized data survived ---\n";
$meta_after = get_post_meta( $post_embed, '_rh_serialized', true );
wpmj_rh_ok( 'serialized postmeta unserializes to an array', is_array( $meta_after ), var_export( $meta_after, true ) );
if ( is_array( $meta_after ) ) {
	wpmj_rh_ok( 'serialized postmeta hero re-pointed to keeper', ( $meta_after['hero'] ?? '' ) === $keeper_url, var_export( $meta_after['hero'] ?? null, true ) );
	wpmj_rh_ok( 'serialized postmeta nested value re-pointed', ( $meta_after['nested']['img'] ?? '' ) === $keeper_url, var_export( $meta_after['nested'] ?? null, true ) );
	wpmj_rh_ok( 'serialized postmeta untouched keys intact', ( $meta_after['keepme'] ?? '' ) === 'do not touch' && ( $meta_after['n'] ?? null ) === 42 );
}

$opt_after = get_option( '_rh_option' );
wpmj_rh_ok( 'serialized option unserializes to an array', is_array( $opt_after ), var_export( $opt_after, true ) );
if ( is_array( $opt_after ) ) {
	wpmj_rh_ok( 'serialized option logo re-pointed to keeper', ( $opt_after['logo'] ?? '' ) === $keeper_url, var_export( $opt_after['logo'] ?? null, true ) );
	wpmj_rh_ok( 'serialized option decoy url untouched', ( $opt_after['other'] ?? '' ) === $decoy_url, var_export( $opt_after['other'] ?? null, true ) );
	wpmj_rh_ok( 'serialized option non-string value intact', ( $opt_after['flag'] ?? null ) === true );
}

$plain_after = get_option( '_rh_plain' );
wpmj_rh_ok( 'plain option re-pointed to keeper', $plain_after === 'url is ' . $keeper_url . ' end', var_export( $plain_after, true ) );

echo "\n--- Core WP data was not damaged ---\n";
// A wholesale postmeta/options rewrite is exactly the kind of thing that can
// scribble on rows it was never meant to touch. Spot-check the critical ones.
foreach ( [ 'siteurl', 'home', 'active_plugins', 'cron' ] as $opt ) {
	$v = get_option( $opt );
	wpmj_rh_ok( "option '$opt' still readable and non-empty", ! empty( $v ) );
}
$broken_meta = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND meta_value NOT LIKE 'a:%' AND meta_value != ''"
);
wpmj_rh_ok( 'no _wp_attachment_metadata row was left non-serialized', $broken_meta === 0, "$broken_meta bad rows" );
$broken_att = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
	 JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type='attachment'
	 WHERE pm.meta_key='_wp_attached_file' AND (pm.meta_value = '' OR pm.meta_value IS NULL)"
);
wpmj_rh_ok( 'no attachment lost its _wp_attached_file', $broken_att === 0, "$broken_att bad rows" );

// ---------------------------------------------------------------------------
// Teardown
// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// A duplicate whose references could NOT all be rewritten must not be deleted.
//
// Rows carrying a PHP object are skipped rather than corrupted - that is
// correct - but deleting the duplicate anyway left a live reference pointing at
// a file that no longer existed, and reported plain success while doing it.
// That is the same half-migrated-in-silence failure the rest of this handler
// exists to prevent.
// ---------------------------------------------------------------------------
echo "\n--- Resolve refuses to delete into a reference it could not rewrite ---\n";

$b_keeper = wpmj_rh_attach( 'rh-blk.png',   wpmj_rh_png( 12, 34, 56 ) );
$b_dup    = wpmj_rh_attach( 'rh-blk-b.png', wpmj_rh_png( 12, 34, 56 ) );
$b_dup_url = wp_get_attachment_url( $b_dup );

// An option holding an OBJECT that names the duplicate. Unrewritable by design.
$b_obj = new stdClass();
$b_obj->img = $b_dup_url;
update_option( '_rh_blocked', [ 'wrapped' => $b_obj ] );

// A plain post reference too, so we can prove the rewrite still happened.
$b_post = wpmj_rh_post( [ 'post_title' => 'RH blocked post', 'post_content' => '<img src="' . $b_dup_url . '">' ] );

wpmj_rh_call( 'scan_init' );
foreach ( [ 'hash', 'references' ] as $phase ) {
	$offset = 0; $guard = 0;
	while ( true ) {
		if ( ++$guard > 500 ) break;
		$r = wpmj_rh_call( 'scan_chunk', [ 'offset' => $offset, 'phase' => $phase ] );
		if ( empty( $r['success'] ) ) break;
		$offset = (int) $r['data']['next_offset'];
		if ( ! empty( $r['data']['done'] ) ) break;
	}
}

$r = wpmj_rh_call( 'resolve_duplicate', [ 'keeper_id' => $b_keeper, 'duplicate_ids' => (string) $b_dup ] );
wpmj_rh_ok( 'the resolve call itself still succeeds', ! empty( $r['success'] ), wp_json_encode( $r['data'] ?? [] ) );
wpmj_rh_ok( 'the duplicate was NOT deleted', get_post_type( $b_dup ) === 'attachment',
	'deleted=' . ( $r['data']['deleted'] ?? '?' ) );
wpmj_rh_ok( 'it is reported as blocked rather than silently skipped',
	! empty( $r['data']['blocked'] ), wp_json_encode( $r['data']['blocked'] ?? [] ) );
wpmj_rh_ok( 'the blocked report names the file',
	! empty( $r['data']['blocked'][0]['file'] ), wp_json_encode( $r['data']['blocked'][0] ?? [] ) );
wpmj_rh_ok( 'rewritable references were still re-pointed to the keeper',
	strpos( get_post_field( 'post_content', $b_post ), wp_get_attachment_url( $b_keeper ) ) !== false,
	get_post_field( 'post_content', $b_post ) );
$b_obj_after = get_option( '_rh_blocked' );
wpmj_rh_ok( 'the object-bearing option is untouched and still an object',
	is_array( $b_obj_after ) && is_object( $b_obj_after['wrapped'] ?? null )
		&& ( $b_obj_after['wrapped']->img ?? '' ) === $b_dup_url );

delete_option( '_rh_blocked' );

// ---------------------------------------------------------------------------
// A duplicate group is a PROPOSAL. The delete path must verify it.
//
// The group is built from hashes cached against the filesystem timestamp, and
// timestamps lie: rsync -t and cp -p preserve mtime while changing content, and
// a same-size replacement defeats a size check too. Without a check at delete
// time, two files that are no longer identical get "resolved" and one of them
// is permanently deleted.
// ---------------------------------------------------------------------------
echo "\n--- Resolve verifies the files are still identical ---\n";

$v_bytes = wpmj_rh_png( 7, 99, 140 );
$v_keep  = wpmj_rh_attach( 'rh-ver-a.png', $v_bytes );
$v_dup   = wpmj_rh_attach( 'rh-ver-b.png', $v_bytes );
$v_file  = get_attached_file( $v_dup );

wpmj_rh_call( 'scan_init' );
foreach ( [ 'hash', 'references' ] as $phase ) {
	$offset = 0; $guard = 0;
	while ( true ) {
		if ( ++$guard > 500 ) break;
		$r = wpmj_rh_call( 'scan_chunk', [ 'offset' => $offset, 'phase' => $phase ] );
		if ( empty( $r['success'] ) ) break;
		$offset = (int) $r['data']['next_offset'];
		if ( ! empty( $r['data']['done'] ) ) break;
	}
}

// Change the content behind a preserved timestamp, exactly as a backup restore
// or an rsync -t would.
$v_mtime = filemtime( $v_file );
file_put_contents( $v_file, wpmj_rh_png( 200, 10, 10 ) );
touch( $v_file, $v_mtime );
clearstatcache();

wpmj_rh_ok( 'the two files really do differ now',
	md5_file( get_attached_file( $v_keep ) ) !== md5_file( $v_file ) );

$r = wpmj_rh_call( 'resolve_duplicate', [ 'keeper_id' => $v_keep, 'duplicate_ids' => (string) $v_dup ] );
wpmj_rh_ok( 'resolve refuses to delete a file that is no longer identical',
	get_post_type( $v_dup ) === 'attachment', 'deleted=' . ( $r['data']['deleted'] ?? '?' ) );
wpmj_rh_ok( 'and reports it as blocked with a reason',
	( $r['data']['blocked'][0]['rows'][0] ?? '' ) === 'not-identical',
	wp_json_encode( $r['data']['blocked'] ?? [] ) );

// ---------------------------------------------------------------------------
// The identity guard must fail CLOSED.
//
// md5_file() returns false on a file it cannot open. Comparing the two results
// directly made false === false evaluate true, so a pair of unreadable files
// was declared identical and the duplicate force-deleted - the guard approving
// a delete in exactly the case it exists to refuse. filesize() is stat-based
// and succeeds without read permission, so it does not catch this either.
// ---------------------------------------------------------------------------
echo "\n--- The identity guard fails closed, not open ---\n";

$u_bytes_a = str_repeat( 'A', 4096 );
$u_bytes_b = str_repeat( 'B', 4096 );   // same SIZE, different content
$u_a = wpmj_rh_attach( 'rh-unread-a.png', $u_bytes_a );
$u_b = wpmj_rh_attach( 'rh-unread-b.png', $u_bytes_b );

wpmj_rh_ok( 'same-size different content is not identical', ! wpmj_files_identical( $u_a, $u_b ) );

// Same bytes -> genuinely identical, so the guard must still say yes.
file_put_contents( get_attached_file( $u_b ), $u_bytes_a );
clearstatcache();
wpmj_rh_ok( 'genuinely identical files are still recognised', wpmj_files_identical( $u_a, $u_b ) );

// Point both at something md5_file cannot read while filesize still works.
update_post_meta( $u_a, '_wp_attached_file', '.' );
update_post_meta( $u_b, '_wp_attached_file', '.' );
clearstatcache();
wpmj_rh_ok( 'unreadable files are NOT reported identical',
	! wpmj_files_identical( $u_a, $u_b ),
	'md5_file gave ' . var_export( @md5_file( get_attached_file( $u_a ) ), true ) );

echo "\n--- Teardown ---\n";
delete_option( '_rh_option' );
delete_option( '_rh_plain' );
delete_option( '_rh_object' );
if ( $prev_sheets !== false ) update_option( 'ct_style_sheets', $prev_sheets ); else delete_option( 'ct_style_sheets' );
foreach ( (array) ( $GLOBALS['wpmj_rh']['files'] ?? [] ) as $f ) { if ( file_exists( $f ) ) @unlink( $f ); }
$removed = 0;
foreach ( $GLOBALS['wpmj_rh']['made'] as $m ) {
	if ( get_post_type( $m['id'] ) === false ) continue;
	if ( $m['type'] === 'attachment' ) wp_delete_attachment( $m['id'], true );
	else wp_delete_post( $m['id'], true );
	$removed++;
}
// Belt and braces: anything still tagged as a fixture.
$leftover = get_posts( [ 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_wpmj_rh_fixture' ] );
foreach ( $leftover as $id ) { wp_delete_post( $id, true ); $removed++; }
echo "  removed $removed fixture objects\n";
delete_transient( WPMJ_TRANSIENT_RESULTS );
delete_transient( WPMJ_TRANSIENT_SCAN_STATE );

$h = $GLOBALS['wpmj_rh'];
echo "\n=== Summary ===\n  passed: {$h['pass']}\n  failed: {$h['fail']}\n";
if ( $h['failures'] ) { echo "\n  Failures:\n"; foreach ( $h['failures'] as $f ) echo "    - $f\n"; }
echo "\nRESOLVE HARNESS: " . ( $h['fail'] === 0 ? 'PASS' : 'FAIL' ) . "\n\n";
