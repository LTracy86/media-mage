<?php
/**
 * Media Mage - bulk fixture seeder.
 *
 * Generates a large media library so you can see how the admin UI handles
 * realistic site sizes (long unused tables, many duplicate groups, big
 * reclaimable totals). Run with WP-CLI inside the test install:
 *
 *     wp eval-file test/seed-bulk.php
 *
 * Naming follows the same convention as seed.php so behavior is obvious
 * from the filename alone:
 *     bulk-referenced-unique-NNN.png    (in a post body)
 *     bulk-unreferenced-unique-NNN.png  (orphan)
 *     bulk-referenced-dupGG-N.png       (in a post + part of dup group GG)
 *     bulk-unreferenced-dupGG-N.png     (orphan + part of dup group GG)
 *
 * Re-runnable: tears down its own previous output (anything tagged with
 * postmeta _wpmj_test_bulk=1) before re-creating.
 *
 * Tunables - bump these to scale up/down.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "seed-bulk.php must be run via `wp eval-file`\n" );
	exit( 1 );
}

// ---- Config ---------------------------------------------------------------
const BULK_REFERENCED_UNIQUE  = 350;   // most of the library - normal images in posts
const BULK_UNREFERENCED_UNIQUE = 100;  // orphans
const BULK_DUP_GROUPS_REF     = 5;     // dup groups where every copy is in a post
const BULK_DUP_GROUPS_ORPHAN  = 5;     // dup groups where every copy is orphan
const BULK_DUP_GROUP_SIZE     = 5;     // copies per dup group
const BULK_IMAGES_PER_POST    = 10;    // how many referenced images embed per post

echo "Media Mage bulk seed - starting\n";
echo "  config: " . BULK_REFERENCED_UNIQUE . " ref-unique + " . BULK_UNREFERENCED_UNIQUE . " orphans + "
   . ( ( BULK_DUP_GROUPS_REF + BULK_DUP_GROUPS_ORPHAN ) * BULK_DUP_GROUP_SIZE ) . " duplicates ("
   . ( BULK_DUP_GROUPS_REF + BULK_DUP_GROUPS_ORPHAN ) . " groups)\n";

// ---------------------------------------------------------------------------
// 1. Tear down previous bulk run (idempotent)
// ---------------------------------------------------------------------------
$existing = get_posts( [
	'post_type'   => 'any',
	'post_status' => 'any',
	'numberposts' => -1,
	'meta_key'    => '_wpmj_test_bulk',
	'meta_value'  => '1',
	'fields'      => 'ids',
] );
$existing_atts = get_posts( [
	'post_type'   => 'attachment',
	'post_status' => 'any',
	'numberposts' => -1,
	'meta_key'    => '_wpmj_test_bulk',
	'meta_value'  => '1',
	'fields'      => 'ids',
] );
foreach ( $existing as $pid )      wp_delete_post( $pid, true );
foreach ( $existing_atts as $aid ) wp_delete_attachment( $aid, true );
echo "  cleaned " . count( $existing ) . " posts + " . count( $existing_atts ) . " attachments from prior bulk run\n";

// ---------------------------------------------------------------------------
// 2. Pure-PHP PNG writer (no GD - identical args -> identical bytes)
// ---------------------------------------------------------------------------
function wpmj_make_png( $w, $h, $r, $g, $b ) {
	$out = "\x89PNG\r\n\x1a\n";
	$ihdr  = pack( 'NN', $w, $h ) . chr( 8 ) . chr( 2 ) . chr( 0 ) . chr( 0 ) . chr( 0 );
	$out  .= wpmj_png_chunk( 'IHDR', $ihdr );
	$row   = chr( 0 ) . str_repeat( chr( $r ) . chr( $g ) . chr( $b ), $w );
	$out  .= wpmj_png_chunk( 'IDAT', gzcompress( str_repeat( $row, $h ), 9 ) );
	$out  .= wpmj_png_chunk( 'IEND', '' );
	return $out;
}
function wpmj_png_chunk( $type, $data ) {
	$crc = crc32( $type . $data );
	return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', $crc );
}
function wpmj_unique_color( $seed ) {
	// Knuth multiplicative hash spread across 24-bit color space.
	// Maps sequential seeds (1, 2, 3, ...) to well-distributed unique RGB
	// triples - collision probability is ~0 for the seed counts we use.
	// Earlier `% 200` had period 200, so seeds 201+ collided with 1-200.
	$h = ( $seed * 2654435761 ) & 0xFFFFFF;
	return [
		( $h >> 16 ) & 0xFF,
		( $h >>  8 ) & 0xFF,
		$h & 0xFF,
	];
}

// ---------------------------------------------------------------------------
// 3. Upload helper - calls wp_upload_dir() each time so it works correctly
// inside `wp eval-file` (where outer-scope vars aren't visible to functions).
// ---------------------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/image.php';

function wpmj_bulk_upload( $filename, $bytes ) {
	static $uploads = null;
	if ( $uploads === null ) $uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		fwrite( STDERR, "wp_upload_dir error: {$uploads['error']}\n" );
		return 0;
	}

	$dest = trailingslashit( $uploads['path'] ) . $filename;
	$wrote = @file_put_contents( $dest, $bytes );
	if ( $wrote === false ) {
		fwrite( STDERR, "  FAILED to write {$dest}\n" );
		return 0;
	}

	$att_id = wp_insert_attachment( [
		'post_mime_type' => 'image/png',
		'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
		'post_status'    => 'inherit',
	], $dest );
	if ( is_wp_error( $att_id ) || ! $att_id ) return 0;

	$metadata = wp_generate_attachment_metadata( $att_id, $dest );
	wp_update_attachment_metadata( $att_id, $metadata );
	update_post_meta( $att_id, '_wpmj_test_bulk', '1' );
	return $att_id;
}

// ---------------------------------------------------------------------------
// 4. Generate referenced + unique images, queue them for posts
// ---------------------------------------------------------------------------
$ref_queue = []; // attachment URLs to embed in posts

$start = microtime( true );
for ( $i = 1; $i <= BULK_REFERENCED_UNIQUE; $i++ ) {
	$rgb = wpmj_unique_color( $i );
	$bytes = wpmj_make_png( 32, 32, $rgb[0], $rgb[1], $rgb[2] );
	$name  = sprintf( 'bulk-referenced-unique-%04d.png', $i );
	$id = wpmj_bulk_upload( $name, $bytes );
	if ( $id ) $ref_queue[] = wp_get_attachment_url( $id );
	if ( $i % 50 === 0 ) echo "  uploaded {$i} ref-unique\n";
}
echo "  uploaded " . count( $ref_queue ) . " referenced unique images in " . round( microtime( true ) - $start, 1 ) . "s\n";

// ---------------------------------------------------------------------------
// 5. Generate unreferenced + unique orphans
// ---------------------------------------------------------------------------
$orphan_count = 0;
for ( $i = 1; $i <= BULK_UNREFERENCED_UNIQUE; $i++ ) {
	$rgb = wpmj_unique_color( $i + 100000 ); // offset to avoid color collisions with ref set
	$bytes = wpmj_make_png( 32, 32, $rgb[0], $rgb[1], $rgb[2] );
	$name  = sprintf( 'bulk-unreferenced-unique-%04d.png', $i );
	if ( wpmj_bulk_upload( $name, $bytes ) ) $orphan_count++;
	if ( $i % 50 === 0 ) echo "  uploaded {$i} orphans\n";
}
echo "  uploaded {$orphan_count} unreferenced unique orphans\n";

// ---------------------------------------------------------------------------
// 6. Generate duplicate groups (referenced)
// ---------------------------------------------------------------------------
$dup_letters_ref = range( 'A', chr( ord( 'A' ) + BULK_DUP_GROUPS_REF - 1 ) );
foreach ( $dup_letters_ref as $idx => $letter ) {
	$rgb = wpmj_unique_color( 200000 + $idx );
	$bytes = wpmj_make_png( 64, 64, $rgb[0], $rgb[1], $rgb[2] );  // larger so dup savings show real bytes
	for ( $n = 1; $n <= BULK_DUP_GROUP_SIZE; $n++ ) {
		$name = sprintf( 'bulk-referenced-dup%s-%d.png', $letter, $n );
		$id = wpmj_bulk_upload( $name, $bytes );
		if ( $id ) $ref_queue[] = wp_get_attachment_url( $id );
	}
}
echo "  uploaded " . ( BULK_DUP_GROUPS_REF * BULK_DUP_GROUP_SIZE ) . " referenced duplicates ("
   . BULK_DUP_GROUPS_REF . " groups of " . BULK_DUP_GROUP_SIZE . ")\n";

// ---------------------------------------------------------------------------
// 7. Generate duplicate groups (orphan)
// ---------------------------------------------------------------------------
$dup_letters_orphan = range( chr( ord( 'A' ) + BULK_DUP_GROUPS_REF ), chr( ord( 'A' ) + BULK_DUP_GROUPS_REF + BULK_DUP_GROUPS_ORPHAN - 1 ) );
foreach ( $dup_letters_orphan as $idx => $letter ) {
	$rgb = wpmj_unique_color( 300000 + $idx );
	$bytes = wpmj_make_png( 64, 64, $rgb[0], $rgb[1], $rgb[2] );
	for ( $n = 1; $n <= BULK_DUP_GROUP_SIZE; $n++ ) {
		$name = sprintf( 'bulk-unreferenced-dup%s-%d.png', $letter, $n );
		wpmj_bulk_upload( $name, $bytes );
	}
}
echo "  uploaded " . ( BULK_DUP_GROUPS_ORPHAN * BULK_DUP_GROUP_SIZE ) . " orphan duplicates ("
   . BULK_DUP_GROUPS_ORPHAN . " groups of " . BULK_DUP_GROUP_SIZE . ")\n";

// ---------------------------------------------------------------------------
// 8. Embed all referenced URLs into posts (BULK_IMAGES_PER_POST per post)
// ---------------------------------------------------------------------------
$post_count = 0;
$chunks = array_chunk( $ref_queue, BULK_IMAGES_PER_POST );
foreach ( $chunks as $idx => $urls ) {
	$body  = '<p>Bulk fixture post #' . ( $idx + 1 ) . " - embeds " . count( $urls ) . " images.</p>\n";
	foreach ( $urls as $url ) {
		$body .= '<p><img src="' . esc_url( $url ) . '" alt=""></p>' . "\n";
	}
	$pid = wp_insert_post( [
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'Bulk Fixture Post ' . ( $idx + 1 ),
		'post_content' => $body,
	] );
	if ( $pid && ! is_wp_error( $pid ) ) {
		update_post_meta( $pid, '_wpmj_test_bulk', '1' );
		$post_count++;
	}
}
echo "  created {$post_count} posts embedding " . count( $ref_queue ) . " referenced images\n";

// ---------------------------------------------------------------------------
// 9. Summary
// ---------------------------------------------------------------------------
$total_atts = BULK_REFERENCED_UNIQUE + BULK_UNREFERENCED_UNIQUE
            + ( BULK_DUP_GROUPS_REF + BULK_DUP_GROUPS_ORPHAN ) * BULK_DUP_GROUP_SIZE;
$expected_dup_groups = BULK_DUP_GROUPS_REF + BULK_DUP_GROUPS_ORPHAN;
$expected_unused = BULK_UNREFERENCED_UNIQUE + BULK_DUP_GROUPS_ORPHAN * BULK_DUP_GROUP_SIZE;

echo "\nExpected scan results:\n";
echo "  Total attachments: {$total_atts}\n";
echo "  Duplicate groups:  {$expected_dup_groups} (5 ref + 5 orphan)\n";
echo "  Unused files:      {$expected_unused}\n";
echo "  Posts created:     {$post_count}\n";

echo "\nBulk seed complete in " . round( microtime( true ) - $start, 1 ) . "s.\n";
echo "Open the admin Media Mage page and run a scan to see how the UI handles this many items.\n";
