<?php
/**
 * Run the media-mage scan logic against the seeded fixtures and print
 * what the plugin would detect. Compare against the expected list to confirm
 * the fixture set is exercising every code path correctly.
 *
 *     wp eval-file test/verify.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "verify.php must be run via `wp eval-file`\n" );
	exit( 1 );
}

if ( ! function_exists( 'wpmj_is_referenced' ) ) {
	fwrite( STDERR, "media-mage plugin not loaded - activate it first.\n" );
	exit( 1 );
}

$attachments = get_posts( [
	'post_type'   => 'attachment',
	'post_status' => 'any',
	'numberposts' => -1,
	'fields'      => 'ids',
	'orderby'     => 'ID',
	'order'       => 'ASC',
] );

echo "=== Per-attachment classification ===\n";
$hashes = [];
$unused = [];
foreach ( $attachments as $att_id ) {
	$file = get_attached_file( $att_id );
	$name = $file ? basename( $file ) : '(unknown)';
	$is_ref = wpmj_is_referenced( $att_id );
	$hash = md5_file( $file );
	$hashes[ $hash ][] = [ 'id' => $att_id, 'name' => $name ];

	$status_ref = $is_ref ? 'referenced  ' : 'UNUSED      ';

	$expected_unused = ( strpos( $name, 'unreferenced-' ) === 0 );
	$ok = ( $is_ref !== $expected_unused );
	$check = $ok ? 'OK ' : 'XX ';

	echo sprintf( "  %s #%-3d %s %s\n", $check, $att_id, $status_ref, $name );
	if ( ! $is_ref ) $unused[] = $name;
}

echo "\n=== Duplicate groups ===\n";
$dup_groups = 0;
foreach ( $hashes as $hash => $items ) {
	if ( count( $items ) < 2 ) continue;
	$dup_groups++;
	echo "  Group (md5 " . substr( $hash, 0, 8 ) . "...) - " . count( $items ) . " copies:\n";
	foreach ( $items as $it ) echo "    - #{$it['id']} {$it['name']}\n";
}

echo "\n=== Summary ===\n";
echo "  Duplicate groups: " . $dup_groups . " (expected: 2)\n";
echo "  Unused files:     " . count( $unused ) . " (expected: 5)\n";

$expected_unused_files = [
	'unreferenced-unique-1.png',
	'unreferenced-unique-2.png',
	'unreferenced-dupB-1.png',
	'unreferenced-dupB-2.png',
	'unreferenced-dupB-3.png',
];
$missing = array_diff( $expected_unused_files, $unused );
$extra   = array_diff( $unused, $expected_unused_files );

if ( empty( $missing ) && empty( $extra ) && $dup_groups === 2 ) {
	echo "\n  PASS - all fixtures classified as expected.\n";
} else {
	echo "\n  FAIL\n";
	if ( $missing ) echo "    Expected unused, got referenced: " . implode( ', ', $missing ) . "\n";
	if ( $extra   ) echo "    Got unused, expected referenced: " . implode( ', ', $extra ) . "\n";
}
