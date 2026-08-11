<?php
/**
 * Media Mage - end-to-end AJAX harness.
 *
 * Drives the REAL ajax handlers (wpmj_ajax_*) the same way the browser does:
 * scan_init -> scan_chunk(hash) loop -> scan_chunk(references) loop ->
 * get_results, then optionally resolve_duplicate / delete_unused.
 *
 * Reading a diff does not tell you whether a chunked scan still terminates,
 * still counts, and still classifies. This does. Run it after every change.
 *
 * Usage (from "Claude Code Projects"):
 *   /c/xampp/php/php.exe wp-cli.phar --path=".../wpmm-test-1" \
 *       eval-file media-mage/test/harness.php [--verbose]
 *
 * Exit status is not used (wp-cli eval-file always returns 0); check for the
 * final "HARNESS: PASS" / "HARNESS: FAIL" line instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run me through wp-cli eval-file.\n";
	return;
}

// ---------------------------------------------------------------------------
// Plumbing: make the ajax handlers callable in-process.
//
// wp_send_json_*() echoes JSON and then wp_die()s. In a CLI context
// wp_doing_ajax() is false, so it would call die() outright and take the whole
// harness with it. Forcing wp_doing_ajax() true routes the exit through the
// filterable ajax die handler, which we replace with a throw so we can catch
// it, keep running, and read the JSON back out of the output buffer.
// ---------------------------------------------------------------------------

class WPMJ_Harness_Done extends Exception {}

add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', function () {
	return function () { throw new WPMJ_Harness_Done(); };
} );

$GLOBALS['wpmj_h'] = [ 'pass' => 0, 'fail' => 0, 'failures' => [] ];
$verbose = in_array( '--verbose', (array) ( $args ?? [] ), true );

/**
 * Call an ajax handler with the given POST payload, return the decoded JSON.
 */
function wpmj_h_call( $action, $post = [] ) {
	$fn = "wpmj_ajax_$action";
	if ( ! function_exists( $fn ) ) {
		return [ 'success' => false, 'data' => [ 'message' => "handler $fn does not exist" ] ];
	}

	$_POST    = $post;
	$_REQUEST = $post;
	$_POST['nonce'] = $_REQUEST['nonce'] = wp_create_nonce( WPMJ_NONCE_ACTION );

	ob_start();
	try {
		$fn();
	} catch ( WPMJ_Harness_Done $e ) {
		// expected: the handler finished via wp_send_json_*
	} catch ( Throwable $e ) {
		$out = ob_get_clean();
		return [ 'success' => false, 'data' => [ 'message' => 'THREW ' . get_class( $e ) . ': ' . $e->getMessage() ] ];
	}
	$out = ob_get_clean();

	$json = json_decode( $out, true );
	if ( $json === null ) {
		return [ 'success' => false, 'data' => [ 'message' => 'non-JSON response: ' . substr( $out, 0, 300 ) ] ];
	}
	return $json;
}

function wpmj_h_ok( $label, $cond, $detail = '' ) {
	if ( $cond ) {
		$GLOBALS['wpmj_h']['pass']++;
		echo "  OK    $label\n";
	} else {
		$GLOBALS['wpmj_h']['fail']++;
		$GLOBALS['wpmj_h']['failures'][] = $label . ( $detail ? " -- $detail" : '' );
		echo "  FAIL  $label" . ( $detail ? "  ($detail)" : '' ) . "\n";
	}
}

function wpmj_h_eq( $label, $actual, $expected ) {
	wpmj_h_ok( $label, $actual === $expected, "got " . var_export( $actual, true ) . ", expected " . var_export( $expected, true ) );
}

/**
 * Run the full two-phase scan exactly as the browser JS does, including the
 * browser's own loop-termination rule (stop when the response says done).
 * Returns [ 'results' => <get_results data>, 'chunks' => n, 'seen' => n ].
 */
function wpmj_h_full_scan( $verbose = false ) {
	$init = wpmj_h_call( 'scan_init' );
	if ( empty( $init['success'] ) ) {
		return [ 'error' => 'scan_init failed: ' . ( $init['data']['message'] ?? '?' ) ];
	}
	$total = (int) $init['data']['total'];

	$stats = [ 'total' => $total, 'chunks' => 0, 'files_hash' => 0, 'files_ref' => 0 ];

	foreach ( [ 'hash', 'references' ] as $phase ) {
		$offset = 0;
		$guard  = 0;
		while ( true ) {
			// Runaway guard: a correct scan can never need more chunks than items.
			if ( ++$guard > $total + 50 ) {
				$stats['runaway'] = $phase;
				break;
			}
			$r = wpmj_h_call( 'scan_chunk', [ 'offset' => $offset, 'phase' => $phase ] );
			if ( empty( $r['success'] ) ) {
				$stats['error'] = "$phase chunk at offset $offset: " . ( $r['data']['message'] ?? '?' );
				break;
			}
			$stats['chunks']++;
			$n = count( $r['data']['files'] ?? [] );
			$stats[ $phase === 'hash' ? 'files_hash' : 'files_ref' ] += $n;
			if ( $verbose ) {
				echo "    [$phase] offset=$offset files=$n scanned={$r['data']['scanned']} next={$r['data']['next_offset']} done=" . var_export( $r['data']['done'], true ) . "\n";
			}
			$offset = (int) $r['data']['next_offset'];
			if ( ! empty( $r['data']['done'] ) ) break;
		}
	}

	$res = wpmj_h_call( 'get_results' );
	$stats['results'] = empty( $res['success'] ) ? null : $res['data'];
	if ( empty( $res['success'] ) ) {
		$stats['error'] = ( $stats['error'] ?? '' ) . ' get_results: ' . ( $res['data']['message'] ?? '?' );
	}
	return $stats;
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) ) {
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	if ( $admins ) wp_set_current_user( (int) $admins[0] );
}

global $wpdb;
$att_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );

echo "\n=== Media Mage harness ===\n";
echo "Attachments in library: $att_total\n";
echo "Plugin version: " . ( defined( 'WPMJ_VERSION' ) ? WPMJ_VERSION : '??' ) . "\n";
echo "Batch size: " . ( defined( 'WPMJ_BATCH_SIZE' ) ? WPMJ_BATCH_SIZE : '??' ) . "\n\n";

echo "--- Constants load correctly ---\n";
wpmj_h_eq( 'WPMJ_TRANSIENT_RESULTS', defined( 'WPMJ_TRANSIENT_RESULTS' ) ? WPMJ_TRANSIENT_RESULTS : null, 'wpmj_results' );
wpmj_h_eq( 'WPMJ_TRANSIENT_SCAN_STATE', defined( 'WPMJ_TRANSIENT_SCAN_STATE' ) ? WPMJ_TRANSIENT_SCAN_STATE : null, 'wpmj_scan_state' );
wpmj_h_eq( 'WPMJ_META_HASH', defined( 'WPMJ_META_HASH' ) ? WPMJ_META_HASH : null, '_wpmj_hash' );
wpmj_h_eq( 'WPMJ_META_HASH_MTIME', defined( 'WPMJ_META_HASH_MTIME' ) ? WPMJ_META_HASH_MTIME : null, '_wpmj_hash_mtime' );

echo "\n--- Auth guard ---\n";
$saved_user = get_current_user_id();
wp_set_current_user( 0 );
$r = wpmj_h_call( 'scan_init', [] );
wpmj_h_ok( 'scan_init rejects a logged-out caller', empty( $r['success'] ), 'response: ' . wp_json_encode( $r ) );
wp_set_current_user( $saved_user );

// Start from a clean slate. A previous aborted run can leave live-looking scan
// state behind, and scan_init now refuses to start on top of that.
delete_transient( WPMJ_TRANSIENT_SCAN_STATE );
delete_transient( WPMJ_TRANSIENT_RESULTS );

echo "\n--- Concurrent-scan guard ---\n";
// A scan whose last chunk landed seconds ago is live; starting another would
// interleave two sets of cursors in one shared transient and corrupt both.
set_transient( WPMJ_TRANSIENT_SCAN_STATE, [
	'total' => 999, 'touched' => time(), 'complete' => false,
	'hashes' => [], 'unused' => [], 'missing' => [], 'seen' => [],
], WPMJ_RESULTS_TTL );
$r = wpmj_h_call( 'scan_init' );
wpmj_h_ok( 'scan_init refuses to start on top of a live scan', empty( $r['success'] ), wp_json_encode( $r ) );
wpmj_h_ok( 'and says why', ( $r['data']['code'] ?? '' ) === 'scan_in_progress', wp_json_encode( $r['data'] ?? [] ) );

// force=1 is the escape hatch.
$r = wpmj_h_call( 'scan_init', [ 'force' => '1' ] );
wpmj_h_ok( 'force=1 takes over a live scan', ! empty( $r['success'] ) );

// An abandoned scan (stale stamp) can be taken over without force, or the
// guard would be a lock somebody could get permanently stuck behind.
set_transient( WPMJ_TRANSIENT_SCAN_STATE, [
	'total' => 999, 'touched' => time() - 3600, 'complete' => false,
	'hashes' => [], 'unused' => [], 'missing' => [], 'seen' => [],
], WPMJ_RESULTS_TTL );
$r = wpmj_h_call( 'scan_init' );
wpmj_h_ok( 'an abandoned scan can be taken over without force', ! empty( $r['success'] ), wp_json_encode( $r ) );

delete_transient( WPMJ_TRANSIENT_SCAN_STATE );
delete_transient( WPMJ_TRANSIENT_RESULTS );

echo "\n--- Full two-phase scan ---\n";
$t0 = microtime( true );
$scan = wpmj_h_full_scan( $verbose );
$elapsed = round( microtime( true ) - $t0, 1 );

if ( ! empty( $scan['error'] ) ) {
	wpmj_h_ok( 'scan completed without error', false, $scan['error'] );
}
wpmj_h_ok( 'scan did not run away', empty( $scan['runaway'] ), 'runaway in phase ' . ( $scan['runaway'] ?? '' ) );

echo "  scan took {$elapsed}s in {$scan['chunks']} chunks\n";
echo "  files reported: hash={$scan['files_hash']} references={$scan['files_ref']} (library={$scan['total']})\n";

// THE coverage assertion: every attachment must be visited in BOTH phases.
// An off-by-one in the done/offset logic shows up here and nowhere else.
wpmj_h_eq( 'hash phase visited every attachment', $scan['files_hash'], $scan['total'] );
wpmj_h_eq( 'reference phase visited every attachment', $scan['files_ref'], $scan['total'] );

$res = $scan['results'] ?? null;
wpmj_h_ok( 'get_results returned data', is_array( $res ) );

if ( is_array( $res ) ) {
	echo "\n--- Results shape ---\n";
	foreach ( [ 'duplicates', 'duplicate_groups', 'duplicate_count', 'unused', 'unused_count', 'reclaimable_bytes', 'reclaimable_human', 'scanned_at' ] as $k ) {
		wpmj_h_ok( "results carry '$k'", array_key_exists( $k, $res ) );
	}
	echo "  duplicate groups: {$res['duplicate_groups']}, duplicate copies to remove: {$res['duplicate_count']}\n";
	echo "  unused: {$res['unused_count']}, reclaimable: {$res['reclaimable_human']}\n";

	// Every duplicate group must have >= 2 members, and all members must share
	// the group's hash. A group of 1 means the grouping logic leaked.
	$bad_groups = 0;
	foreach ( (array) $res['duplicates'] as $g ) {
		if ( count( $g['items'] ) < 2 ) $bad_groups++;
	}
	wpmj_h_eq( 'no duplicate group has fewer than 2 members', $bad_groups, 0 );

	// An item can be both a duplicate and unused, but no item may appear twice
	// within the unused list or twice inside one duplicate group.
	$unused_ids = array_column( (array) $res['unused'], 'id' );
	wpmj_h_eq( 'unused list has no repeats', count( $unused_ids ), count( array_unique( $unused_ids ) ) );

	$dup_ids = [];
	foreach ( (array) $res['duplicates'] as $g ) {
		foreach ( $g['items'] as $it ) $dup_ids[] = $it['id'];
	}
	wpmj_h_eq( 'duplicate items appear once across all groups', count( $dup_ids ), count( array_unique( $dup_ids ) ) );

	// Reclaimable bytes must be internally consistent with the item lists.
	$expect_bytes = 0;
	foreach ( (array) $res['duplicates'] as $g ) {
		$n = count( $g['items'] );
		if ( $n >= 2 ) $expect_bytes += ( $n - 1 ) * (int) $g['items'][0]['bytes'];
	}
	foreach ( (array) $res['unused'] as $u ) $expect_bytes += (int) $u['bytes'];
	wpmj_h_eq( 'reclaimable_bytes matches the item lists', (int) $res['reclaimable_bytes'], $expect_bytes );

	// Cross-check duplicate detection against an independent MD5 pass. The
	// plugin must not miss a group that a naive hash of every file finds.
	echo "\n--- Independent MD5 cross-check ---\n";
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID ASC" );
	$by_hash = [];
	foreach ( $ids as $id ) {
		$f = get_attached_file( $id );
		if ( ! $f || ! file_exists( $f ) ) continue;
		$by_hash[ md5_file( $f ) ][] = (int) $id;
	}
	$truth_groups = 0;
	$truth_ids    = [];
	foreach ( $by_hash as $h => $g ) {
		if ( count( $g ) >= 2 ) { $truth_groups++; foreach ( $g as $i ) $truth_ids[] = $i; }
	}
	wpmj_h_eq( 'duplicate group count matches an independent MD5 pass', (int) $res['duplicate_groups'], $truth_groups );
	sort( $truth_ids );
	$plugin_ids = $dup_ids;
	sort( $plugin_ids );
	wpmj_h_eq( 'duplicate membership matches an independent MD5 pass', $plugin_ids, $truth_ids );

	// Cross-check unused against a direct call to the detection function.
	echo "\n--- Independent reference cross-check ---\n";
	$truth_unused = [];
	foreach ( $ids as $id ) {
		if ( ! wpmj_is_referenced( $id ) ) $truth_unused[] = (int) $id;
	}
	sort( $truth_unused );
	$plugin_unused = $unused_ids;
	sort( $plugin_unused );
	wpmj_h_eq( 'unused count matches a direct wpmj_is_referenced() pass', count( $plugin_unused ), count( $truth_unused ) );
	wpmj_h_ok( 'unused membership matches a direct wpmj_is_referenced() pass', $plugin_unused === $truth_unused,
		'plugin=' . count( $plugin_unused ) . ' direct=' . count( $truth_unused ) );

	// Fixture expectations from test/README.md - the small seed set.
	echo "\n--- Fixture expectations (small seed set) ---\n";
	// Only the small seed set participates here. The install is shared with the
	// bulk fixtures and with whatever ad-hoc probes are in flight, so this
	// asserts about files that follow the documented naming convention and
	// ignores everything else rather than failing on a neighbour's fixture.
	$named_unused = [];
	foreach ( (array) $res['unused'] as $u ) {
		if ( preg_match( '/^(un)?referenced-(unique|dup[A-Z])-\d+\.png$/', $u['filename'] ) ) {
			$named_unused[] = $u['filename'];
		}
	}
	sort( $named_unused );
	$expect_unused = [
		'unreferenced-dupB-1.png', 'unreferenced-dupB-2.png', 'unreferenced-dupB-3.png',
		'unreferenced-unique-1.png', 'unreferenced-unique-2.png',
	];
	wpmj_h_ok( 'small-seed unused set is exactly the expected 5', $named_unused === $expect_unused,
		'got: ' . implode( ',', $named_unused ) );

	// Nothing named "referenced-*" may ever be reported unused. This is the
	// assertion that catches a false positive, which is the failure mode that
	// deletes a user's in-use images.
	$false_positives = [];
	foreach ( (array) $res['unused'] as $u ) {
		if ( strpos( $u['filename'], 'referenced-' ) === 0 || strpos( $u['filename'], 'bulk-referenced-' ) === 0 ) {
			$false_positives[] = $u['filename'];
		}
	}
	wpmj_h_ok( 'no referenced-* file was reported unused', empty( $false_positives ),
		'false positives: ' . implode( ',', array_slice( $false_positives, 0, 10 ) ) );
}

echo "\n--- Idempotency: a second scan gives the same answer ---\n";
$scan2 = wpmj_h_full_scan( false );
$res2  = $scan2['results'] ?? null;
if ( is_array( $res ) && is_array( $res2 ) ) {
	wpmj_h_eq( 're-scan finds the same duplicate group count', (int) $res2['duplicate_groups'], (int) $res['duplicate_groups'] );
	wpmj_h_eq( 're-scan finds the same unused count', (int) $res2['unused_count'], (int) $res['unused_count'] );
	wpmj_h_eq( 're-scan reclaims the same bytes', (int) $res2['reclaimable_bytes'], (int) $res['reclaimable_bytes'] );
} else {
	wpmj_h_ok( 're-scan produced results', false, 'second scan returned nothing' );
}

// ---------------------------------------------------------------------------
// Interrupted scan can be resumed from where it stopped
// ---------------------------------------------------------------------------
echo "\n--- Resume after an interrupted scan ---\n";

$init = wpmj_h_call( 'scan_init' );
$total = (int) ( $init['data']['total'] ?? 0 );

// Run exactly two hash chunks, then walk away as if the tab was closed.
$offset = 0;
for ( $i = 0; $i < 2; $i++ ) {
	$r = wpmj_h_call( 'scan_chunk', [ 'offset' => $offset, 'phase' => 'hash' ] );
	if ( empty( $r['success'] ) ) break;
	$offset = (int) $r['data']['next_offset'];
}

$state = get_transient( WPMJ_TRANSIENT_SCAN_STATE );
wpmj_h_ok( 'interrupted scan left state behind', is_array( $state ) );
wpmj_h_ok( 'state records which phase it was in', ( $state['phase'] ?? '' ) === 'hash', 'phase=' . ( $state['phase'] ?? 'none' ) );
wpmj_h_ok( 'state records a cursor to resume from', ( $state['cursor']['hash'] ?? 0 ) > 0, 'cursor=' . ( $state['cursor']['hash'] ?? 0 ) );
wpmj_h_ok( 'state is not marked complete', empty( $state['complete'] ) );

$seen_before   = (int) ( $state['seen']['hash'] ?? 0 );
$cursor_before = (int) ( $state['cursor']['hash'] ?? 0 );
wpmj_h_ok( 'partial progress is less than the library', $seen_before > 0 && $seen_before < $total,
	"seen=$seen_before total=$total" );

// Resume: continue the hash phase from the stored cursor WITHOUT re-running
// scan_init (which would wipe the state), then finish the reference phase.
$offset = $cursor_before;
$guard  = 0;
while ( true ) {
	if ( ++$guard > $total + 50 ) break;
	$r = wpmj_h_call( 'scan_chunk', [ 'offset' => $offset, 'phase' => 'hash' ] );
	if ( empty( $r['success'] ) ) break;
	$offset = (int) $r['data']['next_offset'];
	if ( ! empty( $r['data']['done'] ) ) break;
}
$state = get_transient( WPMJ_TRANSIENT_SCAN_STATE );
wpmj_h_eq( 'resumed hash phase covers every attachment exactly once', (int) ( $state['seen']['hash'] ?? 0 ), $total );

$offset = 0;
$guard  = 0;
while ( true ) {
	if ( ++$guard > $total + 50 ) break;
	$r = wpmj_h_call( 'scan_chunk', [ 'offset' => $offset, 'phase' => 'references' ] );
	if ( empty( $r['success'] ) ) break;
	$offset = (int) $r['data']['next_offset'];
	if ( ! empty( $r['data']['done'] ) ) break;
}

$resumed = wpmj_h_call( 'get_results' );
wpmj_h_ok( 'a resumed scan produces results', ! empty( $resumed['success'] ) );
if ( ! empty( $resumed['success'] ) && is_array( $res ) ) {
	wpmj_h_eq( 'resumed scan finds the same duplicate groups as an uninterrupted one',
		(int) $resumed['data']['duplicate_groups'], (int) $res['duplicate_groups'] );
	wpmj_h_eq( 'resumed scan finds the same unused count as an uninterrupted one',
		(int) $resumed['data']['unused_count'], (int) $res['unused_count'] );
}

// ---------------------------------------------------------------------------
// A replayed chunk must not double-count
//
// Chunks are not idempotent and the client retries on a lost response, so a
// batch that completed server-side and then dropped its reply gets replayed at
// the same cursor. Left alone that appends the same IDs twice: the file shows
// up in the table twice and its bytes are counted twice in the headline
// reclaimable figure the user makes the delete decision on.
// ---------------------------------------------------------------------------
echo "\n--- Replayed chunk does not double-count ---\n";

wpmj_h_call( 'scan_init' );
wpmj_h_call( 'scan_chunk', [ 'offset' => 0, 'phase' => 'references' ] );
$st1    = get_transient( WPMJ_TRANSIENT_SCAN_STATE );
$seen_a = (int) ( $st1['seen']['references'] ?? 0 );
$un_a   = count( (array) ( $st1['unused'] ?? [] ) );

// Replay the identical request, exactly as the retry loop would.
wpmj_h_call( 'scan_chunk', [ 'offset' => 0, 'phase' => 'references' ] );
$st2    = get_transient( WPMJ_TRANSIENT_SCAN_STATE );
$seen_b = (int) ( $st2['seen']['references'] ?? 0 );
$un_b   = count( (array) ( $st2['unused'] ?? [] ) );

wpmj_h_eq( 'a replayed chunk does not advance the seen counter', $seen_b, $seen_a );
wpmj_h_eq( 'a replayed chunk does not re-append unused ids', $un_b, $un_a );
$state_unused = (array) ( $st2['unused'] ?? [] );
wpmj_h_eq( 'scan state holds no repeated unused ids',
	count( $state_unused ), count( array_unique( $state_unused ) ) );

delete_transient( WPMJ_TRANSIENT_SCAN_STATE );
delete_transient( WPMJ_TRANSIENT_RESULTS );

// ---------------------------------------------------------------------------
// Serialized values that cannot be walked safely are refused, not followed
// ---------------------------------------------------------------------------
echo "\n--- Cyclic and deeply nested data cannot hang a rewrite ---\n";

wpmj_h_ok( 'a back-reference (R:) is refused by the skip gate',
	wpmj_serialized_has_object( 'a:2:{s:3:"url";s:5:"a.png";s:4:"self";R:1;}' ) );
wpmj_h_ok( 'a lowercase back-reference (r:) is refused too',
	wpmj_serialized_has_object( 'a:2:{s:1:"a";s:1:"b";s:1:"c";r:1;}' ) );
wpmj_h_ok( 'a serialized object is still refused',
	wpmj_serialized_has_object( 'a:1:{s:1:"a";O:8:"stdClass":0:{}}' ) );
wpmj_h_ok( 'a literal O: inside ordinary text is NOT refused',
	! wpmj_serialized_has_object( 'I said O:8:"stdClass" out loud' ) );

$deep = 'needle';
for ( $i = 0; $i < 60; $i++ ) $deep = [ $deep ];
$t0 = microtime( true );
$walked = wpmj_deep_replace( 'needle', 'replaced', $deep );
$elapsed = microtime( true ) - $t0;
wpmj_h_ok( 'a 60-level structure returns instead of exhausting memory',
	$elapsed < 2, sprintf( 'took %.2fs', $elapsed ) );
wpmj_h_ok( 'the depth cap leaves the over-deep branch intact rather than mangling it',
	is_array( $walked ) );

echo "\n--- clear_results ---\n";
$r = wpmj_h_call( 'clear_results' );
wpmj_h_ok( 'clear_results succeeds', ! empty( $r['success'] ) );
wpmj_h_ok( 'results transient is gone after clear', get_transient( WPMJ_TRANSIENT_RESULTS ) === false );
wpmj_h_ok( 'scan-state transient is gone after clear', get_transient( WPMJ_TRANSIENT_SCAN_STATE ) === false );
$r = wpmj_h_call( 'get_results' );
wpmj_h_ok( 'get_results errors cleanly with no scan', empty( $r['success'] ) );

echo "\n--- delete_unused refuses unvetted IDs ---\n";
// With no results transient there is nothing to validate against. Deleting an
// arbitrary attachment ID here would be a serious hole, so assert it cannot.
// Use a throwaway attachment as the probe so a regression here cannot destroy
// a real fixture (an earlier run of this test ate one).
$victim = wp_insert_post( [
	'post_title'     => 'wpmj-harness-canary',
	'post_type'      => 'attachment',
	'post_status'    => 'inherit',
	'post_mime_type' => 'image/png',
] );
if ( $victim && ! is_wp_error( $victim ) ) {
	$r     = wpmj_h_call( 'delete_unused', [ 'ids' => (string) $victim ] );
	$after = get_post_type( $victim );
	wpmj_h_ok( 'delete_unused refuses IDs when there are no scan results to vet them against',
		$after === 'attachment', 'canary post_type after=' . var_export( $after, true ) .
		'; deleted=' . ( $r['data']['deleted'] ?? '?' ) . '; msg=' . ( $r['data']['message'] ?? '' ) );

	// And with results present, an ID that is NOT in the unused list must also
	// be refused - otherwise the allow-list is decorative.
	set_transient( WPMJ_TRANSIENT_RESULTS, [
		'duplicates' => [], 'unused' => [ 999999999 ], 'missing' => [],
		'scanned_at' => current_time( 'mysql' ),
	], WPMJ_RESULTS_TTL );
	$r     = wpmj_h_call( 'delete_unused', [ 'ids' => (string) $victim ] );
	$after = get_post_type( $victim );
	wpmj_h_ok( 'delete_unused refuses an ID absent from the unused allow-list',
		$after === 'attachment', 'canary post_type after=' . var_export( $after, true ) .
		'; deleted=' . ( $r['data']['deleted'] ?? '?' ) );
	delete_transient( WPMJ_TRANSIENT_RESULTS );

	wp_delete_post( $victim, true );
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$h = $GLOBALS['wpmj_h'];
echo "\n=== Summary ===\n";
echo "  passed: {$h['pass']}\n";
echo "  failed: {$h['fail']}\n";
if ( $h['failures'] ) {
	echo "\n  Failures:\n";
	foreach ( $h['failures'] as $f ) echo "    - $f\n";
}
echo "\nHARNESS: " . ( $h['fail'] === 0 ? 'PASS' : 'FAIL' ) . "\n\n";
