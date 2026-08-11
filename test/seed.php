<?php
/**
 * Media Mage - test fixture seeder.
 *
 * Run with WP-CLI inside the test install:
 *     wp eval-file test/seed.php
 *
 * Generates 10 small PNGs via GD and creates posts that reference some of them
 * so the plugin's duplicate + unused detection has predictable inputs.
 *
 * Filename scheme:  {ref-status}-{dup-status}-{n}.png
 *   ref-status: referenced | unreferenced
 *   dup-status: unique | dupA | dupB | ...
 *
 * Run twice safely - it tears down its own fixtures (posts tagged with
 * meta _wpmj_test_seed=1 and attachments uploaded into the same year/month
 * with the seed naming) before re-creating.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "seed.php must be run via `wp eval-file`\n" );
	exit( 1 );
}

echo "Media Mage seed - starting\n";

/**
 * Build a minimal valid PNG of $w x $h pixels filled with one RGB color.
 * Pure-PHP, no GD required. Returns raw PNG bytes.
 *
 * Output is deterministic - identical args produce identical bytes (and
 * therefore identical MD5 hashes), which is exactly what duplicate
 * detection needs to test.
 */
function wpmj_make_png( $w, $h, $r, $g, $b ) {
	// PNG signature
	$out = "\x89PNG\r\n\x1a\n";

	// IHDR chunk (13 bytes payload)
	$ihdr  = pack( 'NN', $w, $h );  // width, height (big-endian uint32)
	$ihdr .= chr( 8 );              // bit depth: 8 bits
	$ihdr .= chr( 2 );              // color type: 2 = truecolor RGB
	$ihdr .= chr( 0 );              // compression: deflate
	$ihdr .= chr( 0 );              // filter: standard
	$ihdr .= chr( 0 );              // interlace: none
	$out .= wpmj_png_chunk( 'IHDR', $ihdr );

	// IDAT: filter byte (0) + RGB triples, repeated per row
	$row = chr( 0 ) . str_repeat( chr( $r ) . chr( $g ) . chr( $b ), $w );
	$raw = str_repeat( $row, $h );
	$out .= wpmj_png_chunk( 'IDAT', gzcompress( $raw, 9 ) );

	// IEND
	$out .= wpmj_png_chunk( 'IEND', '' );
	return $out;
}

function wpmj_png_chunk( $type, $data ) {
	$crc = crc32( $type . $data );
	return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', $crc );
}

// ---------------------------------------------------------------------------
// 1. Tear down existing seed data (idempotent re-runs)
// ---------------------------------------------------------------------------
$existing = get_posts( [
	'post_type'   => 'any',
	'post_status' => 'any',
	'numberposts' => -1,
	'meta_key'    => '_wpmj_test_seed',
	'meta_value'  => '1',
	'fields'      => 'ids',
] );
foreach ( $existing as $pid ) {
	wp_delete_post( $pid, true );
}
$existing_atts = get_posts( [
	'post_type'   => 'attachment',
	'post_status' => 'any',
	'numberposts' => -1,
	'meta_key'    => '_wpmj_test_seed',
	'meta_value'  => '1',
	'fields'      => 'ids',
] );
foreach ( $existing_atts as $aid ) {
	wp_delete_attachment( $aid, true );
}
echo "  cleaned " . count( $existing ) . " posts + " . count( $existing_atts ) . " attachments\n";

// ---------------------------------------------------------------------------
// 2. Generate test PNG bytes via GD
// ---------------------------------------------------------------------------
function wpmj_test_png_unique( $seed ) {
	// Unique 64x64 PNG: solid color derived from seed - no two seeds collide
	$r = ( $seed * 73 )  % 200 + 30;
	$g = ( $seed * 131 ) % 200 + 30;
	$b = ( $seed * 197 ) % 200 + 30;
	return wpmj_make_png( 64, 64, $r, $g, $b );
}

function wpmj_test_png_solid( $r, $g, $b ) {
	// Deterministic 128x128 solid PNG - identical args produce identical bytes
	return wpmj_make_png( 128, 128, $r, $g, $b );
}

// Cache bytes for duplicate groups so all members share an identical MD5
$dupA_bytes = wpmj_test_png_solid( 220, 50, 50 );    // red
$dupB_bytes = wpmj_test_png_solid( 50, 180, 80 );    // green

// ---------------------------------------------------------------------------
// 3. Define the fixture set
// ---------------------------------------------------------------------------
$fixtures = [
	// referenced + unique (3 different ref types)
	[ 'name' => 'referenced-unique-1.png', 'bytes' => wpmj_test_png_unique( 1 ), 'ref' => 'content',  'expect_dup' => false, 'expect_unused' => false ],
	[ 'name' => 'referenced-unique-2.png', 'bytes' => wpmj_test_png_unique( 2 ), 'ref' => 'featured', 'expect_dup' => false, 'expect_unused' => false ],
	[ 'name' => 'referenced-unique-3.png', 'bytes' => wpmj_test_png_unique( 3 ), 'ref' => 'meta',     'expect_dup' => false, 'expect_unused' => false ],

	// referenced + dup group A (2 copies)
	[ 'name' => 'referenced-dupA-1.png',   'bytes' => $dupA_bytes,               'ref' => 'content',  'expect_dup' => true,  'expect_unused' => false, 'group' => 'A' ],
	[ 'name' => 'referenced-dupA-2.png',   'bytes' => $dupA_bytes,               'ref' => 'content',  'expect_dup' => true,  'expect_unused' => false, 'group' => 'A' ],

	// unreferenced + unique
	[ 'name' => 'unreferenced-unique-1.png', 'bytes' => wpmj_test_png_unique( 4 ), 'ref' => 'none',  'expect_dup' => false, 'expect_unused' => true ],
	[ 'name' => 'unreferenced-unique-2.png', 'bytes' => wpmj_test_png_unique( 5 ), 'ref' => 'none',  'expect_dup' => false, 'expect_unused' => true ],

	// unreferenced + dup group B (3 copies)
	[ 'name' => 'unreferenced-dupB-1.png',   'bytes' => $dupB_bytes,               'ref' => 'none',  'expect_dup' => true,  'expect_unused' => true, 'group' => 'B' ],
	[ 'name' => 'unreferenced-dupB-2.png',   'bytes' => $dupB_bytes,               'ref' => 'none',  'expect_dup' => true,  'expect_unused' => true, 'group' => 'B' ],
	[ 'name' => 'unreferenced-dupB-3.png',   'bytes' => $dupB_bytes,               'ref' => 'none',  'expect_dup' => true,  'expect_unused' => true, 'group' => 'B' ],
];

// ---------------------------------------------------------------------------
// 4. Upload attachments
// ---------------------------------------------------------------------------
$uploads = wp_upload_dir();
require_once ABSPATH . 'wp-admin/includes/image.php';

$created = [];

foreach ( $fixtures as $f ) {
	$dest_path = trailingslashit( $uploads['path'] ) . $f['name'];
	file_put_contents( $dest_path, $f['bytes'] );

	$att_id = wp_insert_attachment( [
		'post_mime_type' => 'image/png',
		'post_title'     => pathinfo( $f['name'], PATHINFO_FILENAME ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	], $dest_path );

	if ( is_wp_error( $att_id ) || ! $att_id ) {
		fwrite( STDERR, "  FAILED to insert attachment for {$f['name']}\n" );
		continue;
	}

	$metadata = wp_generate_attachment_metadata( $att_id, $dest_path );
	wp_update_attachment_metadata( $att_id, $metadata );
	update_post_meta( $att_id, '_wpmj_test_seed', '1' );

	$created[] = [
		'fixture' => $f,
		'att_id'  => $att_id,
		'url'     => wp_get_attachment_url( $att_id ),
	];

	echo "  uploaded #{$att_id}  {$f['name']}\n";
}

// ---------------------------------------------------------------------------
// 5. Create posts that reference attachments per their 'ref' kind
// ---------------------------------------------------------------------------
function wpmj_create_post( $title, $content, $featured_id = 0, $meta = [] ) {
	$pid = wp_insert_post( [
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_content' => $content,
	] );
	if ( is_wp_error( $pid ) ) return 0;
	update_post_meta( $pid, '_wpmj_test_seed', '1' );
	if ( $featured_id ) set_post_thumbnail( $pid, $featured_id );
	foreach ( $meta as $k => $v ) update_post_meta( $pid, $k, $v );
	return $pid;
}

// Map: filename -> attachment record
$by_name = [];
foreach ( $created as $rec ) $by_name[ $rec['fixture']['name'] ] = $rec;

$post_n = 1;
foreach ( $created as $rec ) {
	$f = $rec['fixture'];
	if ( $f['ref'] === 'content' ) {
		$body = '<p>Test post embedding ' . esc_html( $f['name'] ) . '.</p>'
		      . '<p><img src="' . esc_url( $rec['url'] ) . '" alt=""></p>';
		$pid = wpmj_create_post( "Test Post {$post_n} (content ref)", $body );
		echo "  created post #{$pid} embedding {$f['name']}\n";
		$post_n++;
	} elseif ( $f['ref'] === 'featured' ) {
		$pid = wpmj_create_post( "Test Post {$post_n} (featured)", '<p>Has a featured image.</p>', $rec['att_id'] );
		echo "  created post #{$pid} with featured image {$f['name']}\n";
		$post_n++;
	} elseif ( $f['ref'] === 'meta' ) {
		$pid = wpmj_create_post(
			"Test Post {$post_n} (meta ref)",
			'<p>References an image through custom postmeta.</p>',
			0,
			[ '_extra_image' => $rec['url'] ]
		);
		echo "  created post #{$pid} with meta-ref {$f['name']}\n";
		$post_n++;
	}
}

// One plain page with no images
$page_id = wp_insert_post( [
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'About (test)',
	'post_content' => '<p>A page with no images.</p>',
] );
update_post_meta( $page_id, '_wpmj_test_seed', '1' );
echo "  created page #{$page_id} (no images)\n";

// ---------------------------------------------------------------------------
// 6. Print expected scan summary
// ---------------------------------------------------------------------------
echo "\nExpected scan results:\n";
echo "  Duplicates panel - 2 groups\n";
echo "    Group A: 2 copies (referenced-dupA-1, referenced-dupA-2)\n";
echo "    Group B: 3 copies (unreferenced-dupB-1/2/3)\n";
echo "  Unused panel - 5 files\n";
echo "    unreferenced-unique-1, unreferenced-unique-2\n";
echo "    unreferenced-dupB-1, unreferenced-dupB-2, unreferenced-dupB-3\n";
echo "\nReclaimable bytes (approx):\n";
echo "  dup A: 1 redundant copy of " . strlen( $dupA_bytes ) . " bytes\n";
echo "  dup B: 2 redundant copies of " . strlen( $dupB_bytes ) . " bytes (3 - 1 keeper)\n";
echo "  unused-unique: " . ( strlen( $by_name['unreferenced-unique-1.png']['fixture']['bytes'] ?? '' ) + strlen( $by_name['unreferenced-unique-2.png']['fixture']['bytes'] ?? '' ) ) . " bytes\n";
echo "  (unused-dupB bytes counted in dup B above to avoid double-count)\n";

echo "\nSeed complete - " . count( $created ) . " attachments + " . ( $post_n - 1 ) . " posts + 1 page.\n";
