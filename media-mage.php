<?php
/**
 * Plugin Name:       Media Mage
 * Plugin URI:        https://github.com/LTracy86/media-mage
 * Description:       Detects duplicate and unused media files. Scan, review, and clean up your media library.
 * Version:           1.0.0
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            Lincoln Tracy
 * Author URI:        https://tracydigitalmedia.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       media-mage
 * Domain Path:       /languages
 *
 * DEPLOY:  Copy this folder to wp-content/plugins/ on any WordPress install and activate.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Guarded so a second copy of the plugin, a fork, or a must-use loader cannot
// emit "Constant already defined" warnings - those print into the AJAX response
// body and turn every JSON parse on the page into a generic failure.
defined( 'WPMJ_VERSION' )              || define( 'WPMJ_VERSION',              '1.0.0' );
defined( 'WPMJ_BATCH_SIZE' )           || define( 'WPMJ_BATCH_SIZE',           50 );
defined( 'WPMJ_RESULTS_TTL' )          || define( 'WPMJ_RESULTS_TTL',          6 * HOUR_IN_SECONDS );
defined( 'WPMJ_PLUGIN_URL' )           || define( 'WPMJ_PLUGIN_URL',           plugin_dir_url( __FILE__ ) );
defined( 'WPMJ_PLUGIN_DIR' )           || define( 'WPMJ_PLUGIN_DIR',           plugin_dir_path( __FILE__ ) );
defined( 'WPMJ_NONCE_ACTION' )         || define( 'WPMJ_NONCE_ACTION',         'wpmj_nonce' );
defined( 'WPMJ_TRANSIENT_RESULTS' )    || define( 'WPMJ_TRANSIENT_RESULTS',    'wpmj_results' );
defined( 'WPMJ_TRANSIENT_SCAN_STATE' ) || define( 'WPMJ_TRANSIENT_SCAN_STATE', 'wpmj_scan_state' );
defined( 'WPMJ_META_HASH' )            || define( 'WPMJ_META_HASH',            '_wpmj_hash' );
defined( 'WPMJ_META_HASH_MTIME' )      || define( 'WPMJ_META_HASH_MTIME',      '_wpmj_hash_mtime' );
defined( 'WPMJ_META_IGNORED' )         || define( 'WPMJ_META_IGNORED',         '_wpmj_ignored' );

// ---------------------------------------------------------------------------
// Lift execution limits for the heavy handlers.
//
// set_time_limit is in disable_functions on plenty of shared hosts. Calling it
// bare emits a warning that lands in the middle of the AJAX response, breaks
// JSON.parse, and surfaces to the user as "Invalid response from server" with
// no clue what happened.
// ---------------------------------------------------------------------------
function wpmj_raise_limits() {
	if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
		@set_time_limit( 0 );
	}
}

// ---------------------------------------------------------------------------
// i18n - load translations from /languages/media-mage-{locale}.mo
// ---------------------------------------------------------------------------
add_action( 'init', function () {
	load_plugin_textdomain( 'media-mage', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

// ---------------------------------------------------------------------------
// Admin menu
// ---------------------------------------------------------------------------
add_action( 'admin_menu', function () {
	$page_title = __( 'Media Mage', 'media-mage' );
	$has_icon   = file_exists( WPMJ_PLUGIN_DIR . 'assets/icon.png' );
	$menu_title = $has_icon
		? '<img src="' . esc_url( WPMJ_PLUGIN_URL . 'assets/icon.png' ) . '" alt="" style="width:14px;height:14px;vertical-align:-3px;margin-right:6px;border-radius:2px"> ' . esc_html( $page_title )
		: $page_title;
	add_media_page( $page_title, $menu_title, 'manage_options', 'media-mage', 'wpmj_render_page' );
} );

// ---------------------------------------------------------------------------
// AJAX registrations
// ---------------------------------------------------------------------------
foreach ( [
	'scan_init', 'scan_chunk', 'get_results',
	'resolve_duplicate', 'delete_unused', 'clear_results',
	'toggle_ignore', 'list_trash', 'restore_trash', 'empty_trash',
] as $a ) {
	add_action( "wp_ajax_wpmj_$a", "wpmj_ajax_$a" );
}

// CSV export runs through admin-post rather than admin-ajax so the browser
// gets a real file download instead of a string in a fetch response.
add_action( 'admin_post_wpmj_export_csv', 'wpmj_export_csv' );

// ---------------------------------------------------------------------------
// Auth guard
// ---------------------------------------------------------------------------
function wpmj_auth() {
	// The third argument stops check_ajax_referer() from dying with a bare "-1",
	// which is not JSON and reaches the user as the generic "Invalid response
	// from server". Nonces expire in 12-24 hours and this page is designed to be
	// left open, so an expired nonce is a routine event and deserves a routine
	// message.
	if ( ! check_ajax_referer( WPMJ_NONCE_ACTION, 'nonce', false ) ) {
		wp_send_json_error( [
			'message' => __( 'Your session expired. Reload this page and try again.', 'media-mage' ),
			'code'    => 'bad_nonce',
		], 403 );
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'media-mage' ) ], 403 );
	}
}

/** Read an int out of $_POST. */
function wpmj_post_int( $key, $default = 0 ) {
	return isset( $_POST[ $key ] ) ? (int) wp_unslash( $_POST[ $key ] ) : $default;
}

/** Read a sanitized string out of $_POST. */
function wpmj_post_text( $key, $default = '' ) {
	return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default;
}

/** Read a comma-separated ID list out of $_POST as a list of positive ints. */
function wpmj_post_ids( $key ) {
	$raw = wpmj_post_text( $key );
	if ( $raw === '' ) return [];
	$ids = array_map( 'intval', explode( ',', $raw ) );
	return array_values( array_unique( array_filter( $ids, function ( $id ) { return $id > 0; } ) ) );
}

// ---------------------------------------------------------------------------
// Admin page - HTML + inline CSS + inline JS
// ---------------------------------------------------------------------------
function wpmj_render_page() {
	global $wpdb;

	$total_media = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash'"
	);

	// Check for existing results
	$has_results = (bool) get_transient( WPMJ_TRANSIENT_RESULTS );
	$scan_state  = get_transient( WPMJ_TRANSIENT_SCAN_STATE );
	$has_partial = $scan_state && empty( $scan_state['complete'] );
	?>
	<style>
		.wpmj-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:24px 28px; margin-bottom:16px; }
		.wpmj-card h2 { margin-top:0; font-size:1.3em; }
		.wpmj-info { background:#f0f0f1; padding:10px 16px; border-radius:4px; margin:12px 0; font-size:13px; color:#555; }
		.wpmj-info strong { color:#1d2327; }
		.wpmj-bar-wrap { background:#ddd; border-radius:4px; height:24px; margin:10px 0; overflow:hidden; }
		.wpmj-bar { background:#2271b1; height:100%; width:0; transition:width .3s; border-radius:4px; }
		.wpmj-bar-label { text-align:center; font-size:12px; color:#666; margin-top:2px; }
		.wpmj-log { background:#1d2327; color:#ccc; font-family:monospace; font-size:12px; padding:12px 16px; max-height:200px; overflow-y:auto; border-radius:4px; margin-top:10px; }
		.wpmj-log .head { color:#72aee6; font-weight:bold; }
		.wpmj-log .ok { color:#68de7c; }
		.wpmj-log .warn { color:#f0c33c; }
		.wpmj-log .err { color:#e65054; }
		.wpmj-badge { background:#d63638; color:#fff; font-size:11px; font-weight:600; padding:1px 7px; border-radius:10px; margin-left:4px; }
		.wpmj-badge.clean { background:#00a32a; }
		/* Neutral state. The trash count is informational, not a problem to fix:
		   green would read as "nothing to do" while files still sit on disk, and
		   red would read as an error the user has to clear. */
		.wpmj-badge.info { background:#646970; }
		.wpmj-group { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px 20px; margin-bottom:12px; }
		.wpmj-group h3 { margin:0 0 10px; font-size:14px; color:#1d2327; }
		.wpmj-actions { margin-top:10px; display:flex; gap:8px; align-items:center; }

		/* -------------------------------------------------------------------
		   Results tables.

		   These rules are keyed to the .wpmj-table CLASS, not to each table's
		   id. They used to be written per-id, which meant every table added
		   after the first inherited nothing: #wpmj-trash-table had no styling
		   at all and rendered at browser defaults next to two styled siblings,
		   and the .no-thumb placeholder was scoped to .wpmj-group so it lost
		   its 50px box everywhere except the duplicates panel - visible on
		   exactly the non-image files that fill an unused-media list.

		   thumbHtml() is one component shared by all three tables, so its CSS
		   is one rule. Any future table gets the styling by wearing the class.
		   ------------------------------------------------------------------- */
		.wpmj-table { width:100%; border-collapse:collapse; }
		.wpmj-table th { text-align:left; font-size:12px; color:#646970; padding:6px 8px; border-bottom:2px solid #ddd; }
		.wpmj-table td { padding:6px 8px; border-bottom:1px solid #f0f0f0; font-size:13px; vertical-align:middle; }
		/* Inside a duplicate group the table is nested in a card, so it gets a
		   lighter header rule and tighter padding than a full-panel table. */
		.wpmj-group .wpmj-table th { padding:4px 8px; border-bottom:1px solid #eee; }
		.wpmj-group .wpmj-table td { border-bottom:1px solid #f5f5f5; }

		#wpmj-app .thumb { width:50px; height:50px; object-fit:cover; border-radius:3px; background:#f0f0f1; display:block; }
		#wpmj-app .no-thumb { width:50px; height:50px; display:flex; align-items:center; justify-content:center; background:#f0f0f1; border-radius:3px; color:#646970; font-size:11px; font-weight:600; letter-spacing:.02em; }
		.wpmj-warn { background:#fcf9e8; border:1px solid #dba617; border-radius:4px; padding:10px 16px; font-size:13px; color:#6e4b00; margin-bottom:12px; }
		.wpmj-empty { padding:24px; text-align:center; color:#888; font-size:14px; }
		.wpmj-header { display:flex; align-items:center; gap:12px; margin:9px 0 16px; }
		.wpmj-header img { width:32px; height:32px; border-radius:4px; box-shadow:0 1px 3px rgba(30,34,37,.3); image-rendering:pixelated; }
		.wpmj-header h1 { margin:0; padding:0; line-height:1; }
		.wpmj-version { font-size:.55em; font-weight:400; }
		/* Charcoal to navy - the icon's own plate and body colors, so the two
		   loudest surfaces on the page agree. This was a violet gradient, which
		   clashed with the blue mark and matched nothing else in the UI. */
		.wpmj-savings { background:linear-gradient(135deg,#1E2225 0%,#14569B 100%); color:#fff; border-radius:6px; padding:18px 22px; margin-bottom:16px; box-shadow:0 1px 4px rgba(30,34,37,.15); }
		.wpmj-savings .total { font-size:24px; font-weight:600; margin:0 0 4px; display:flex; align-items:center; gap:10px; }
		/* The glyph is the plugin icon at 26px. Nearest-neighbour keeps a pixel
		   sprite crisp instead of smearing it. */
		.wpmj-savings .total .spark { width:26px; height:26px; border-radius:3px; image-rendering:pixelated; flex:0 0 auto; }
		.wpmj-savings .breakdown { font-size:13px; opacity:.9; }
		.wpmj-savings.clean { background:linear-gradient(135deg,#1d6e3a 0%,#2cb260 100%); }
		.wpmj-meta { font-size:12px; color:#888; margin:8px 0 14px; }
		.wpmj-table tbody tr:hover { background:#f6f7f7; }
		.wpmj-table tbody tr { transition:background .12s ease; }
		/* text-decoration:none matters only for the .no-thumb placeholder. An
		   <img> is an atomic inline so the admin's link underline is never drawn
		   through it, but the text placeholder is not, and it was rendering as
		   blue underlined link text instead of a muted file-type label. */
		#wpmj-app .thumb-link { display:inline-block; line-height:0; text-decoration:none; transition:transform .12s ease; }
		#wpmj-app .thumb-link:hover { transform:scale(1.08); }
		.wpmj-group[data-resolved="true"] { opacity:.55; }
		.wpmj-group[data-resolved="true"] .wpmj-resolve-btn { pointer-events:none; }
		.wpmj-footer { margin:32px 0 16px; padding:14px 18px; background:#fff; border:1px solid #e5e5e8; border-radius:6px; font-size:13px; color:#555; text-align:center; }
		.wpmj-footer a { color:#2271b1; text-decoration:none; }
		.wpmj-footer a:hover { text-decoration:underline; }

		/* Tables get their own scroll container so the PAGE never scrolls
		   sideways on a narrow screen. */
		.wpmj-tablewrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }

		/* Toolbar above each results table: search, sort, bulk actions. */
		.wpmj-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px; }
		.wpmj-toolbar .spacer { flex:1 1 auto; }
		.wpmj-toolbar input[type=search], .wpmj-toolbar select { height:30px; }
		.wpmj-count { font-size:12px; color:#646970; }

		/* Where-is-this-used drill-down. */
		.wpmj-refs { font-size:12px; }
		.wpmj-refs > summary { cursor:pointer; color:#2271b1; list-style:none; }
		.wpmj-refs > summary::-webkit-details-marker { display:none; }
		.wpmj-refs > summary:hover { text-decoration:underline; }
		.wpmj-refs ul { margin:6px 0 2px 0; padding-left:16px; }
		.wpmj-refs li { margin:2px 0; }
		.wpmj-refs .via { color:#646970; }

		.wpmj-pager { display:flex; gap:8px; align-items:center; justify-content:center; margin:12px 0; font-size:13px; }
		.wpmj-danger { color:#b32d2e; }
		.wpmj-trash-note { background:#f0f6fc; border:1px solid #c5d9ed; border-radius:4px; padding:10px 16px; font-size:13px; color:#1d3f5e; margin-bottom:12px; }

		/* Native dialog for destructive confirmation. window.confirm is OS-modal
		   and, after the second one in a run, the browser offers to suppress it -
		   at which point it silently returns false and the user's bulk action
		   stops working with no explanation. */
		.wpmj-dialog { border:none; border-radius:6px; padding:0; max-width:520px; width:calc(100% - 32px); box-shadow:0 8px 40px rgba(0,0,0,.25); }
		.wpmj-dialog::backdrop { background:rgba(0,0,0,.5); }
		.wpmj-dialog .body { padding:22px 24px 8px; }
		.wpmj-dialog h2 { margin:0 0 10px; font-size:1.15em; }
		.wpmj-dialog p { margin:0 0 10px; font-size:13px; line-height:1.6; color:#3c434a; white-space:pre-line; }
		.wpmj-dialog .foot { padding:8px 24px 20px; display:flex; gap:8px; justify-content:flex-end; }
		.wpmj-dialog .filelist { max-height:180px; overflow:auto; background:#f6f7f7; border-radius:4px; padding:8px 12px; font-size:12px; font-family:monospace; margin-bottom:10px; }

		/* Contrast: #888 on white is about 3.5:1 and fails WCAG AA. #646970 is
		   WordPress's own muted grey and passes at 5.9:1. The version number in
		   the page heading was set to #aaa inline, which is 2.3:1 and fails even
		   the relaxed large-text threshold. */
		.wpmj-empty, .wpmj-meta, .wpmj-version { color:#646970; }

		@media (prefers-reduced-motion: reduce) {
			.wpmj-bar, .wpmj-table tbody tr, #wpmj-app .thumb-link { transition:none; }
			#wpmj-app .thumb-link:hover { transform:none; }
		}

		@media screen and (max-width:600px) {
			.wpmj-card { padding:16px 14px; }
			.wpmj-col-optional { display:none; }
			.wpmj-toolbar { flex-direction:column; align-items:stretch; }
		}
	</style>

	<div class="wrap" id="wpmj-app">
		<div class="wpmj-header">
			<?php if ( file_exists( WPMJ_PLUGIN_DIR . 'assets/icon.png' ) ) : ?>
				<img src="<?php echo esc_url( WPMJ_PLUGIN_URL . 'assets/icon.png' ); ?>" alt="">
			<?php endif; ?>
			<h1><?php esc_html_e( 'Media Mage', 'media-mage' ); ?> <span class="wpmj-version">v<?php echo esc_html( WPMJ_VERSION ); ?></span></h1>
		</div>

		<!-- Scan Card -->
		<div class="wpmj-card" id="wpmj-scan-card">
			<h2><?php esc_html_e( 'Scan Media Library', 'media-mage' ); ?></h2>
			<p><?php esc_html_e( 'Scan for duplicate files (same content) and unused media (not referenced anywhere on the site).', 'media-mage' ); ?></p>
			<div class="wpmj-info">
				<?php
				printf(
					/* translators: %s is the formatted count of media library items */
					esc_html__( 'Media library: %s items', 'media-mage' ),
					'<strong>' . esc_html( number_format_i18n( $total_media ) ) . '</strong>'
				);
				?>
			</div>
			<?php if ( $has_partial ) : ?>
				<p class="wpmj-warn">
					<?php
					$done_so_far = (int) ( $scan_state['seen']['references'] ?? 0 ) + (int) ( $scan_state['seen']['hash'] ?? 0 );
					printf(
						/* translators: %s is the number of items already processed */
						esc_html__( 'A previous scan was interrupted after %s item(s). You can carry on from where it stopped, or start again.', 'media-mage' ),
						'<strong>' . esc_html( number_format_i18n( $done_so_far ) ) . '</strong>'
					);
					?>
				</p>
			<?php endif; ?>
			<p>
				<button class="button button-primary button-hero" id="wpmj-scan-btn"><?php esc_html_e( 'Scan Media Library', 'media-mage' ); ?></button>
				<?php if ( $has_partial ) : ?>
					<button class="button button-hero" id="wpmj-resume-btn" style="margin-left:8px"><?php esc_html_e( 'Resume Scan', 'media-mage' ); ?></button>
				<?php endif; ?>
				<button class="button" id="wpmj-clear-btn" style="margin-left:8px;<?php echo $has_results ? '' : 'display:none'; ?>"><?php esc_html_e( 'Clear Results', 'media-mage' ); ?></button>
			</p>
		</div>

		<!-- Progress -->
		<div class="wpmj-card" id="wpmj-progress" style="display:none">
			<h2 id="wpmj-progress-title"><?php esc_html_e( 'Scanning…', 'media-mage' ); ?></h2>
			<div class="wpmj-bar-wrap" id="wpmj-bar-wrap" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
				aria-label="<?php esc_attr_e( 'Scan progress', 'media-mage' ); ?>"><div class="wpmj-bar" id="wpmj-bar"></div></div>
			<div class="wpmj-bar-label" id="wpmj-bar-label">0%</div>
			<?php
			// The log gets one line per attachment. Announcing all of them would
			// queue thousands of utterances and lock a screen reader up, so the
			// log itself is silent and only phase changes reach the status region.
			?>
			<div class="wpmj-log" id="wpmj-log" aria-live="off"></div>
		</div>

		<div id="wpmj-status" role="status" aria-live="polite" class="screen-reader-text"></div>

		<!-- Results -->
		<div id="wpmj-results" style="display:none">
			<div id="wpmj-savings"></div>
			<nav class="nav-tab-wrapper" style="margin-bottom:0" role="tablist" aria-label="<?php esc_attr_e( 'Scan results', 'media-mage' ); ?>">
				<a class="nav-tab nav-tab-active" role="tab" id="wpmj-tab-duplicates" aria-selected="true"
					aria-controls="wpmj-panel-duplicates" data-wpmj-tab="duplicates" href="#wpmj-panel-duplicates"><?php esc_html_e( 'Duplicates', 'media-mage' ); ?> <span class="wpmj-badge" id="wpmj-dup-count">0</span></a>
				<a class="nav-tab" role="tab" id="wpmj-tab-unused" aria-selected="false" tabindex="-1"
					aria-controls="wpmj-panel-unused" data-wpmj-tab="unused" href="#wpmj-panel-unused"><?php esc_html_e( 'Unused Media', 'media-mage' ); ?> <span class="wpmj-badge" id="wpmj-unused-count">0</span></a>
				<a class="nav-tab" role="tab" id="wpmj-tab-trashed" aria-selected="false" tabindex="-1"
					aria-controls="wpmj-panel-trashed" data-wpmj-tab="trashed" href="#wpmj-panel-trashed"><?php esc_html_e( 'Trash', 'media-mage' ); ?> <span class="wpmj-badge clean" id="wpmj-trash-count">0</span></a>
			</nav>
			<div class="wpmj-meta" id="wpmj-meta"></div>

			<div id="wpmj-panel-duplicates" role="tabpanel" tabindex="0" aria-labelledby="wpmj-tab-duplicates"></div>
			<div id="wpmj-panel-unused" role="tabpanel" tabindex="0" aria-labelledby="wpmj-tab-unused" hidden></div>
			<div id="wpmj-panel-trashed" role="tabpanel" tabindex="0" aria-labelledby="wpmj-tab-trashed" hidden></div>
		</div>

		<dialog class="wpmj-dialog" id="wpmj-dialog" aria-labelledby="wpmj-dialog-title">
			<div class="body">
				<h2 id="wpmj-dialog-title"></h2>
				<div id="wpmj-dialog-files" class="filelist" hidden></div>
				<p id="wpmj-dialog-msg"></p>
			</div>
			<div class="foot">
				<button class="button" id="wpmj-dialog-cancel"><?php esc_html_e( 'Cancel', 'media-mage' ); ?></button>
				<button class="button button-primary" id="wpmj-dialog-ok"><?php esc_html_e( 'Continue', 'media-mage' ); ?></button>
			</div>
		</dialog>

		<!-- Footer card: credits + tip jar -->
		<div class="wpmj-footer">
			<?php
			printf(
				/* translators: 1: author link, 2: GitHub link, 3: Buy Me a Coffee link */
				esc_html__( 'Made by %1$s. %2$s · %3$s', 'media-mage' ),
				'<a href="https://tracydigitalmedia.com/" target="_blank" rel="noopener">Lincoln Tracy</a>',
				'<a href="https://github.com/LTracy86/media-mage" target="_blank" rel="noopener">' . esc_html__( 'GitHub', 'media-mage' ) . '</a>',
				'<a href="https://buymeacoffee.com/lincolntracy" target="_blank" rel="noopener">' . esc_html__( 'Buy me a coffee', 'media-mage' ) . '</a>'
			);
			?>
		</div>
	</div>

	<?php
	// Build the JS-side i18n bag. Every translatable string used in JS lives here so
	// translators only have to deal with PHP po/mo files, not JS extraction.
	$i18n = [
		// Scan flow
		'progInit'         => __( 'Initializing scan…', 'media-mage' ),
		'progPhase1'       => __( 'Phase 1: Hashing files…', 'media-mage' ),
		'progPhase1Long'   => __( 'Phase 1: Hashing files for duplicate detection…', 'media-mage' ),
		'progPhase2'       => __( 'Phase 2: Checking references…', 'media-mage' ),
		'progPhase2Long'   => __( 'Phase 2: Checking references for unused detection…', 'media-mage' ),
		'progComplete'     => __( 'Scan complete!', 'media-mage' ),
		'resumeFrom'       => __( 'Resuming the interrupted scan…', 'media-mage' ),
		'resumeHint'       => __( 'Progress was saved. Reload this page and choose Resume Scan to carry on from here.', 'media-mage' ),
		'progLoading'      => __( 'Loading results…', 'media-mage' ),
		'logHashDone'      => __( 'Hashing complete.', 'media-mage' ),
		'logRefDone'       => __( 'Reference scan complete.', 'media-mage' ),
		'logEmpty'         => __( 'Nothing to scan - media library is empty.', 'media-mage' ),
		/* translators: %d is the number of media items found */
		'logFoundFmt'      => __( 'Found %d media items to scan.', 'media-mage' ),
		/* translators: 1: number of duplicate groups, 2: number of unused files */
		'logDoneFmt'       => __( 'Done. %1$d duplicate group(s), %2$d unused file(s).', 'media-mage' ),
		'logUnusedSuffix'  => __( ' - unused', 'media-mage' ),
		'logRefSuffix'     => __( ' - referenced', 'media-mage' ),
		'errPrefix'        => __( 'Error:', 'media-mage' ),
		'errNetwork'       => __( 'Network error - check that your web server is running.', 'media-mage' ),
		/* translators: 1: HTTP status code, 2: HTTP status text */
		'errHttpFmt'       => __( 'Server returned HTTP %1$s %2$s', 'media-mage' ),
		'errInvalidJson'   => __( 'Invalid response from server (check browser console for details).', 'media-mage' ),

		// Results / banners
		'libClean'         => __( 'Library is clean', 'media-mage' ),
		'libCleanSub'      => __( 'No duplicates or unused media found.', 'media-mage' ),
		/* translators: %s is the human-readable byte count, e.g. "234 MB" */
		'reclaimableFmt'   => __( 'Reclaimable: %s', 'media-mage' ),
		/* translators: 1: dup count, 2: dup label, 3: group count, 4: group label */
		'breakdownDupFmt'  => __( '%1$d %2$s across %3$d %4$s', 'media-mage' ),
		/* translators: 1: unused count, 2: unused label */
		'breakdownUnFmt'   => __( '%1$d %2$s', 'media-mage' ),
		/* translators: %d is the total number of items */
		'breakdownTotalFmt'=> __( '%d total', 'media-mage' ),
		'duplicate'        => __( 'duplicate', 'media-mage' ),
		'duplicates'       => __( 'duplicates', 'media-mage' ),
		'group'            => __( 'group', 'media-mage' ),
		'groups'           => __( 'groups', 'media-mage' ),
		'unusedFile'       => __( 'unused file', 'media-mage' ),
		'unusedFiles'      => __( 'unused files', 'media-mage' ),
		/* translators: %s is the timestamp */
		'lastScannedFmt'   => __( 'Last scanned: %s', 'media-mage' ),
		'loadingPrev'      => __( 'Loading previous results…', 'media-mage' ),

		// Duplicates panel
		'noDups'           => __( 'No duplicates found - your media library is clean!', 'media-mage' ),
		'resolveAll'       => __( 'Automatically Resolve All', 'media-mage' ),
		'resolveAllHint'   => __( 'Keeps the copy with the most references in each group', 'media-mage' ),
		/* translators: 1: group number, 2: filename, 3: number of copies, 4: file size each */
		'dupGroupHeadFmt'  => __( 'Duplicate Group #%1$d - %2$s (%3$d copies, %4$s each)', 'media-mage' ),
		'colKeep'          => __( 'Keep', 'media-mage' ),
		'colFile'          => __( 'File', 'media-mage' ),
		'colId'            => __( 'ID', 'media-mage' ),
		'colDimensions'    => __( 'Dimensions', 'media-mage' ),
		'colSize'          => __( 'Size', 'media-mage' ),
		'colReferences'    => __( 'References', 'media-mage' ),
		'colUploaded'      => __( 'Uploaded', 'media-mage' ),
		'colType'          => __( 'Type', 'media-mage' ),
		'btnResolve'       => __( 'Resolve - Keep Selected', 'media-mage' ),
		'btnResolved'      => __( 'Resolved', 'media-mage' ),
		'btnResolving'     => __( 'Resolving…', 'media-mage' ),
		/* translators: 1: current count, 2: total count */
		'btnResolvingFmt'  => __( 'Resolving (%1$d/%2$d)…', 'media-mage' ),
		'btnAllDone'       => __( 'All Done', 'media-mage' ),
		'selectKeeper'     => __( 'Select which file to keep.', 'media-mage' ),
		/* translators: 1: keeper attachment ID, 2: number of duplicates being deleted */
		'confirmResolveFmt'=> __( "Keep #%1\$d and delete %2\$d duplicate(s)?\n\nAll references will be updated to point to the kept file. This cannot be undone.", 'media-mage' ),
		/* translators: %d is the number of duplicate groups */
		'confirmResolveAllFmt' => __( "Automatically resolve all %d duplicate group(s)?\n\nFor each group, the copy with the most references will be kept. All others will be deleted. This cannot be undone.", 'media-mage' ),
		'noUnresolved'     => __( 'No unresolved duplicate groups.', 'media-mage' ),
		/* translators: %s is a comma-separated list of filenames */
		'blockedFmt'       => __( 'Kept %s - some references to it could not be safely rewritten, so deleting it would have left them broken.', 'media-mage' ),
		/* translators: %s is a comma-separated list of filenames */
		'blockedDiffFmt'   => __( 'Kept %s - it is no longer byte-identical to the file you chose to keep, so it is not actually a duplicate any more.', 'media-mage' ),
		/* translators: %s is a comma-separated list of filenames */
		'blockedGoneFmt'   => __( 'Kept %s - either its file or the keeper file is missing or unreadable, so the two could not be compared.', 'media-mage' ),
		/* translators: 1: number of duplicates removed, 2: number of references updated */
		'resolveStatusFmt' => __( '%1$d duplicate(s) removed, %2$d reference(s) updated.', 'media-mage' ),
		/* translators: %d is the number of duplicate groups resolved */
		'resolveAllSummaryFmt'      => __( '%d group(s) resolved.', 'media-mage' ),
		/* translators: 1: number of groups resolved, 2: number of errors */
		'resolveAllSummaryErrorFmt' => __( '%1$d group(s) resolved, %2$d error(s).', 'media-mage' ),

		// Unused panel
		'noUnused'         => __( 'No unused media found - everything is referenced somewhere!', 'media-mage' ),
		'allCleanedUp'     => __( 'All unused media has been cleaned up!', 'media-mage' ),
		'mediaWarn'        => __( 'Media referenced only in theme/plugin PHP files may appear as unused. Review before deleting.', 'media-mage' ),
		'btnCleanupAll'    => __( 'Cleanup Unused Media', 'media-mage' ),
		/* translators: %d is the number of unused files */
		'cleanupAllHintFmt'=> __( 'Permanently delete all %d unused file(s)', 'media-mage' ),
		/* translators: %d is the number of unused files */
		'selectAllFmt'     => __( 'Select all (%d)', 'media-mage' ),
		'btnDeleteSelected'=> __( 'Delete Selected', 'media-mage' ),
		'btnDeleting'      => __( 'Deleting…', 'media-mage' ),
		/* translators: 1: current item, 2: total */
		'btnDeletingFmt'   => __( 'Deleting (%1$d/%2$d)…', 'media-mage' ),
		'noSelection'      => __( 'No items selected.', 'media-mage' ),
		'noUnusedToCleanup'=> __( 'No unused media to clean up.', 'media-mage' ),
		/* translators: %d is the number of files being deleted */
		'confirmDeleteFmt' => __( "Permanently delete %d media file(s)?\n\nThis will remove the files from disk and cannot be undone.", 'media-mage' ),
		/* translators: %d is the number of unused files being deleted */
		'confirmCleanupFmt'=> __( "Permanently delete all %d unused media file(s)?\n\nThis will remove the files from disk and cannot be undone.", 'media-mage' ),
		/* translators: %d is the number of files deleted */
		'filesDeletedFmt'  => __( '%d file(s) deleted.', 'media-mage' ),
		/* translators: 1: deleted, 2: remaining */
		'filesDeletedRemainingFmt' => __( '%1$d file(s) deleted, %2$d remaining.', 'media-mage' ),

		// Trash
		'tabTrash'         => __( 'Trash', 'media-mage' ),
		'trashEmpty'       => __( 'Media Mage has not trashed anything.', 'media-mage' ),
		'trashNote'        => __( 'These files are in the trash. They are still on disk and can be restored. Emptying the trash deletes them permanently and is what actually reclaims the disk space.', 'media-mage' ),
		'btnRestore'       => __( 'Restore', 'media-mage' ),
		'btnRestoreSel'    => __( 'Restore Selected', 'media-mage' ),
		'btnEmptyTrash'    => __( 'Empty Trash (delete permanently)', 'media-mage' ),
		'colTrashedAt'     => __( 'Trashed', 'media-mage' ),
		/* translators: %d is the number of restored files */
		'restoredFmt'      => __( '%d file(s) restored.', 'media-mage' ),
		/* translators: %d is the number of files permanently deleted */
		'emptiedFmt'       => __( '%d file(s) permanently deleted.', 'media-mage' ),
		/* translators: %d is the number of files to permanently delete */
		'confirmEmptyFmt'  => __( 'Permanently delete %d file(s) from the trash? This removes them from disk and cannot be undone.', 'media-mage' ),
		'trashLoading'     => __( 'Loading trash…', 'media-mage' ),

		// Ignore list
		'btnIgnore'        => __( 'Ignore', 'media-mage' ),
		'ignoreTitle'      => __( 'Stop reporting this file as unused', 'media-mage' ),
		/* translators: %d is the number of ignored files */
		'ignoredFmt'       => __( '%d file(s) added to the ignore list.', 'media-mage' ),
		/* translators: %d is the number of files on the ignore list */
		'ignoredNoteFmt'   => __( '%d file(s) on the ignore list are hidden from these results.', 'media-mage' ),
		/* translators: %d is the number of attachments whose file is missing from disk */
		'missingNoteFmt'   => __( '%d attachment(s) have no file on disk and were skipped.', 'media-mage' ),

		// Where used
		/* translators: %d is the number of references */
		'whereUsedFmt'     => __( '%d reference(s)', 'media-mage' ),
		'whereNone'        => __( 'No references found', 'media-mage' ),
		'whereMore'        => __( 'and more…', 'media-mage' ),

		// Toolbar
		'search'           => __( 'Search filenames', 'media-mage' ),
		'sortBy'           => __( 'Sort by', 'media-mage' ),
		'sortLargest'      => __( 'Largest first', 'media-mage' ),
		'sortSmallest'     => __( 'Smallest first', 'media-mage' ),
		'sortNewest'       => __( 'Newest first', 'media-mage' ),
		'sortOldest'       => __( 'Oldest first', 'media-mage' ),
		'sortName'         => __( 'Filename', 'media-mage' ),
		'btnExportCsv'     => __( 'Export CSV', 'media-mage' ),
		'exportHint'       => __( 'Download the full result list before deleting anything', 'media-mage' ),
		/* translators: 1: number shown, 2: number matching */
		'showingFmt'       => __( 'Showing %1$d of %2$d', 'media-mage' ),
		'btnLoadMore'      => __( 'Show more', 'media-mage' ),
		'noMatches'        => __( 'No files match that search.', 'media-mage' ),
		/* translators: 1: unused count, 2: library total */
		'ofLibraryFmt'     => __( '%1$d of %2$d items in your library', 'media-mage' ),

		// Delete mode
		'btnTrashSelected' => __( 'Move Selected to Trash', 'media-mage' ),
		/* translators: %d is the number of unused files */
		'btnTrashAllFmt'   => __( 'Move All Unused to Trash (%d)', 'media-mage' ),
		/* translators: %d is the number of files being trashed */
		'confirmTrashFmt'  => __( 'Move %d file(s) to the trash? They stay on disk and can be restored from the Trash tab.', 'media-mage' ),
		'btnTrashing'      => __( 'Moving to trash…', 'media-mage' ),
		/* translators: %d is the number of files moved to trash */
		'filesTrashedFmt'  => __( '%d file(s) moved to trash.', 'media-mage' ),
		/* translators: %d is the number of files skipped */
		'skippedFmt'       => __( '%d file(s) were skipped because they are referenced again since the scan.', 'media-mage' ),
		'selectAllVisible' => __( 'Select all shown', 'media-mage' ),
		'dialogCancel'     => __( 'Cancel', 'media-mage' ),
		'dialogContinue'   => __( 'Continue', 'media-mage' ),
		'dialogDelete'     => __( 'Delete permanently', 'media-mage' ),

		// Accessibility
		/* translators: %s is a filename */
		'selectFileFmt'    => __( 'Select %s', 'media-mage' ),
		/* translators: %s is a filename */
		'keepFileFmt'      => __( 'Keep %s', 'media-mage' ),
		'colPreview'       => __( 'Preview', 'media-mage' ),
		'colSelect'        => __( 'Select', 'media-mage' ),
		'colActions'       => __( 'Actions', 'media-mage' ),

		// Misc
		'confirmClear'     => __( 'Clear all scan results?', 'media-mage' ),
		'noticeCleared'    => __( 'Results cleared.', 'media-mage' ),
		/* translators: %d is the attachment ID */
		'thumbTitleFmt'    => __( 'Open #%d in Media Library', 'media-mage' ),
	];
	?>
	<script>
	(function() {
		'use strict';

		// One JSON literal rather than a set of quoted interpolations.
		// esc_js() is for values inside an HTML attribute (onclick="...") - it
		// html-encodes quotes, which are NOT decoded inside a script block, so
		// it was the wrong tool here. wp_json_encode also escapes forward
		// slashes, so a closing script tag inside any value cannot break out.
		const WPMJ = <?php echo wp_json_encode( [
			'ajax'       => admin_url( 'admin-ajax.php' ),
			'adminUrl'   => admin_url(),
			'nonce'      => wp_create_nonce( WPMJ_NONCE_ACTION ),
			'hasResults' => (bool) $has_results,
			// The savings banner's glyph. Empty string when the icon is absent,
			// which the banner treats as "render no glyph at all".
			'iconUrl'    => file_exists( WPMJ_PLUGIN_DIR . 'assets/icon.png' ) ? WPMJ_PLUGIN_URL . 'assets/icon.png' : '',
			'resume'     => $has_partial ? [
				'phase'  => (string) ( $scan_state['phase'] ?? 'hash' ),
				'cursor' => [
					'hash'       => (int) ( $scan_state['cursor']['hash'] ?? 0 ),
					'references' => (int) ( $scan_state['cursor']['references'] ?? 0 ),
				],
				'total'  => (int) ( $scan_state['total'] ?? 0 ),
			] : null,
			'i18n'       => $i18n,
		] ); ?>;

		// Translation helper: looks up the key in WPMJ.i18n and substitutes args
		// for placeholders. Supports both sequential (%s, %d) and positional
		// (%1$s, %2$d) sprintf-style markers - same syntax as PHP's sprintf so
		// translators can rely on identical conventions on both sides.
		const I18N = WPMJ.i18n;
		function tr(key, ...args) {
			const template = I18N[key] !== undefined ? I18N[key] : key;
			let seqIndex = 0;
			return template.replace(/%(?:(\d+)\$)?([ds])/g, (m, posStr) => {
				const idx = posStr ? parseInt(posStr, 10) - 1 : seqIndex++;
				return args[idx] !== undefined ? String(args[idx]) : m;
			});
		}

		// ---- Helpers ----
		function $(sel) { return document.querySelector(sel); }
		function $$(sel) { return document.querySelectorAll(sel); }

		// Retries transient failures before giving up.
		//
		// A scan over a large library is hundreds of sequential requests, and
		// losing the whole run to one blip - a dropped connection, a 502 from a
		// host under load - meant starting from zero. Only genuinely transient
		// conditions are retried: a network error, a 5xx, or a gateway timeout.
		// A 4xx is the server saying no, and repeating it changes nothing.
		async function wpmjAjax(action, params, opts) {
			const attempts = (opts && opts.retries !== undefined) ? opts.retries : 3;
			let lastErr = null;

			for (let attempt = 0; attempt < attempts; attempt++) {
				if (attempt > 0) {
					// 600ms, 1800ms, 5400ms
					await new Promise(r => setTimeout(r, 600 * Math.pow(3, attempt - 1)));
				}

				const body = new FormData();
				body.append('action', 'wpmj_' + action);
				body.append('nonce', WPMJ.nonce);
				if (params) Object.entries(params).forEach(([k, v]) => body.append(k, v));

				let resp;
				try {
					resp = await fetch(WPMJ.ajax, { method: 'POST', body });
				} catch (e) {
					lastErr = new Error(tr('errNetwork'));
					continue;
				}

				if (!resp.ok) {
					const err = new Error(tr('errHttpFmt', resp.status, resp.statusText));
					if (resp.status >= 500 || resp.status === 408 || resp.status === 429) {
						lastErr = err;
						continue;
					}
					// 4xx: the server has decided. Surface the JSON body if there
					// is one, so an expired nonce reads as an expired nonce.
					const t = await resp.text();
					try { return JSON.parse(t); } catch (e) { throw err; }
				}

				const text = await resp.text();
				try {
					return JSON.parse(text);
				} catch (e) {
					console.error('WPMJ: non-JSON response:', text.substring(0, 500));
					lastErr = new Error(tr('errInvalidJson'));
					continue;
				}
			}

			throw lastErr || new Error(tr('errNetwork'));
		}

		function setBar(pct) {
			const v = Math.max(0, Math.min(100, Math.round(pct)));
			$('#wpmj-bar').style.width = pct + '%';
			$('#wpmj-bar-label').textContent = v + '%';
			const wrap = $('#wpmj-bar-wrap');
			if (wrap) wrap.setAttribute('aria-valuenow', v);
		}

		function log(msg, cls) {
			const el = $('#wpmj-log');
			const line = document.createElement('div');
			if (cls) line.className = cls;
			line.textContent = msg;
			el.appendChild(line);
			el.scrollTop = el.scrollHeight;
		}

		// wpmjAjax throws once its retries are exhausted, and every destructive
		// click handler awaits it. Without this wrapper a failed request left the
		// button disabled reading "Moving to trash…" forever, with nothing on
		// screen and only an uncaught promise rejection in the console - so the
		// user could not tell whether their files had been deleted or not.
		function guard(fn) {
			return async function (e) {
				try {
					await fn.call(this, e);
				} catch (err) {
					console.error('WPMJ:', err);
					showNotice(err && err.message ? err.message : String(err), 'error');
					// Put every control back the way it was found.
					$$('#wpmj-app button[disabled]').forEach(b => { b.disabled = false; });
					restoreButtonLabels();
				}
			};
		}

		function restoreButtonLabels() {
			const b1 = $('#wpmj-delete-unused-btn');
			if (b1) b1.textContent = tr('btnTrashSelected');
			const b2 = $('#wpmj-cleanup-all-btn');
			if (b2) b2.textContent = tr('btnTrashAllFmt', STATE.unused.length);
			const b3 = $('#wpmj-resolve-all-btn');
			if (b3) b3.textContent = tr('resolveAll');
		}

		// A resolve that blocked everything is NOT resolved. Report it by the
		// actual reason: the server deliberately keeps the group in the results
		// so it can be retried, and marking the group done threw that away.
		function reportBlocked(blocked) {
			if (!blocked || !blocked.length) return;
			const byReason = { 'not-identical': [], 'file-unavailable': [], other: [] };
			blocked.forEach(b => {
				const r = (b.rows && b.rows[0]) || 'other';
				(byReason[r] || byReason.other).push(b.file);
			});
			if (byReason['not-identical'].length) showNotice(tr('blockedDiffFmt', byReason['not-identical'].join(', ')), 'warning');
			if (byReason['file-unavailable'].length) showNotice(tr('blockedGoneFmt', byReason['file-unavailable'].join(', ')), 'warning');
			if (byReason.other.length) showNotice(tr('blockedFmt', byReason.other.join(', ')), 'warning');
		}

		// ---- Screen-reader status ----
		// Only phase transitions and outcomes go here. The scan log next door
		// emits one line per file; announcing those would queue thousands of
		// utterances and make the page unusable with a screen reader.
		function announce(msg) {
			const el = $('#wpmj-status');
			if (el) el.textContent = msg;
		}

		// ---- Confirmation dialog ----
		// Replaces window.confirm for destructive actions. After the second
		// native confirm in a run, browsers offer "prevent this page from
		// creating more dialogs" - and once suppressed, confirm() silently
		// returns false, so a bulk action stops working with no explanation.
		// <dialog> also gives focus trapping and Escape-to-close for free, and
		// can show the user WHICH files are about to go.
		function wpmjConfirm(opts) {
			const dlg = $('#wpmj-dialog');
			if (!dlg || typeof dlg.showModal !== 'function') {
				return Promise.resolve(window.confirm(opts.message || ''));
			}
			$('#wpmj-dialog-title').textContent = opts.title || '';
			$('#wpmj-dialog-msg').textContent = opts.message || '';

			const list = $('#wpmj-dialog-files');
			if (opts.files && opts.files.length) {
				list.textContent = opts.files.slice(0, 200).join('\n')
					+ (opts.files.length > 200 ? '\n' + tr('whereMore') : '');
				list.hidden = false;
			} else {
				list.textContent = '';
				list.hidden = true;
			}

			const ok = $('#wpmj-dialog-ok');
			const cancel = $('#wpmj-dialog-cancel');
			ok.textContent = opts.okLabel || tr('dialogContinue');
			cancel.textContent = tr('dialogCancel');
			ok.className = 'button ' + (opts.destructive ? 'button-primary wpmj-danger-btn' : 'button-primary');

			return new Promise(resolve => {
				function done(val) {
					ok.removeEventListener('click', onOk);
					cancel.removeEventListener('click', onCancel);
					dlg.removeEventListener('close', onClose);
					if (dlg.open) dlg.close();
					resolve(val);
				}
				function onOk() { done(true); }
				function onCancel() { done(false); }
				function onClose() { done(false); }
				ok.addEventListener('click', onOk);
				cancel.addEventListener('click', onCancel);
				dlg.addEventListener('close', onClose);
				dlg.showModal();
				cancel.focus();
			});
		}

		// ---- Tab switching ----
		const TABS = ['duplicates', 'unused', 'trashed'];

		function selectTab(name, focusPanel) {
			TABS.forEach(t => {
				const tab = $('#wpmj-tab-' + t);
				const panel = $('#wpmj-panel-' + t);
				if (!tab || !panel) return;
				const on = t === name;
				tab.classList.toggle('nav-tab-active', on);
				tab.setAttribute('aria-selected', on ? 'true' : 'false');
				tab.tabIndex = on ? 0 : -1;
				panel.hidden = !on;
			});
			if (focusPanel) {
				const p = $('#wpmj-panel-' + name);
				if (p) p.focus();
			}
			if (name === 'trashed') loadTrash();
		}

		$$('[data-wpmj-tab]').forEach(tab => {
			tab.addEventListener('click', function(e) {
				e.preventDefault();
				selectTab(this.dataset.wpmjTab, true);
			});
			// Arrow-key navigation is what makes a tablist a tablist.
			tab.addEventListener('keydown', function(e) {
				const i = TABS.indexOf(this.dataset.wpmjTab);
				let next = null;
				if (e.key === 'ArrowRight') next = TABS[(i + 1) % TABS.length];
				else if (e.key === 'ArrowLeft') next = TABS[(i - 1 + TABS.length) % TABS.length];
				else if (e.key === 'Home') next = TABS[0];
				else if (e.key === 'End') next = TABS[TABS.length - 1];
				if (!next) return;
				e.preventDefault();
				selectTab(next, true);
				$('#wpmj-tab-' + next).focus();
			});
		});

		// ---- Scan flow ----
		async function runScan(btn, resume) {
			btn.disabled = true;
			const other = $(resume ? '#wpmj-scan-btn' : '#wpmj-resume-btn');
			if (other) other.disabled = true;

			$('#wpmj-progress').style.display = 'block';
			$('#wpmj-progress-title').textContent = tr('progInit');
			$('#wpmj-results').style.display = 'none';
			$('#wpmj-log').innerHTML = '';
			setBar(0);
			announce(tr('progInit'));

			try {
				let total, startPhase = 'hash', startCursor = { hash: 0, references: 0 };

				if (resume && WPMJ.resume) {
					total = WPMJ.resume.total;
					startPhase = WPMJ.resume.phase || 'hash';
					startCursor = WPMJ.resume.cursor || startCursor;
					log(tr('resumeFrom'), 'head');
				} else {
					log(tr('progInit'), 'head');
					const init = await wpmjAjax('scan_init');
					if (!init.success) throw new Error(init.data.message);
					total = init.data.total;
				}
				log(tr('logFoundFmt', total), 'ok');

				if (total === 0) {
					log(tr('logEmpty'), 'warn');
					announce(tr('logEmpty'));
					// An empty library is not a failed scan. Hide the progress
					// card rather than leaving a 0% bar looking like a crash.
					$('#wpmj-progress').style.display = 'none';
					showNotice(tr('logEmpty'));
					btn.disabled = false;
					if (other) other.disabled = false;
					return;
				}

				// Phase 1: Hash
				let offset = startCursor.hash || 0;
				let skipHash = (startPhase === 'references');
				$('#wpmj-progress-title').textContent = tr('progPhase1');
				announce(tr('progPhase1'));
				log(tr('progPhase1Long'), 'head');
				while (!skipHash) {
					const r = await wpmjAjax('scan_chunk', { offset: offset, phase: 'hash' });
					if (!r.success) throw new Error(r.data.message);
					const files = r.data.files || [];
					const baseScanned = r.data.scanned - files.length;
					files.forEach(function(f, i) {
						const n = baseScanned + i + 1;
						setBar((n / total * 100) * 0.6);
						log('[' + n + '/' + total + '] ' + f, f.endsWith('(missing)') ? 'warn' : '');
					});
					offset = r.data.next_offset;
					if (r.data.done) break;
				}
				log(tr('logHashDone'), 'ok');

				// Phase 2: References
				$('#wpmj-progress-title').textContent = tr('progPhase2');
				announce(tr('progPhase2'));
				log(tr('progPhase2Long'), 'head');
				offset = (startPhase === 'references') ? (startCursor.references || 0) : 0;
				while (true) {
					const r = await wpmjAjax('scan_chunk', { offset: offset, phase: 'references' });
					if (!r.success) throw new Error(r.data.message);
					const files = r.data.files || [];
					const baseScanned = r.data.scanned - files.length;
					const unusedSet = r.data.unused_in_batch || [];
					for (let i = 0; i < files.length; i++) {
						const n = baseScanned + i + 1;
						setBar(60 + (n / total * 100) * 0.4);
						const isUnused = unusedSet.includes(files[i]);
						log('[' + n + '/' + total + '] ' + files[i] + (isUnused ? tr('logUnusedSuffix') : tr('logRefSuffix')),
							isUnused ? 'warn' : '');
					}
					offset = r.data.next_offset;
					if (r.data.done) break;
				}
				log(tr('logRefDone'), 'ok');

				$('#wpmj-progress-title').textContent = tr('progComplete');
				log(tr('progLoading'), 'head');
				const results = await wpmjAjax('get_results');
				if (!results.success) throw new Error(results.data.message);

				setBar(100);
				const d = results.data;
				log(tr('logDoneFmt', d.duplicate_groups, d.unused_count), 'ok');
				announce(tr('logDoneFmt', d.duplicate_groups, d.unused_count));

				renderResults(d);
				$('#wpmj-clear-btn').style.display = 'inline-block';
				const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
				$('#wpmj-results').scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });

			} catch (err) {
				log(tr('errPrefix') + ' ' + err.message, 'err');
				log(tr('resumeHint'), 'warn');
				announce(tr('errPrefix') + ' ' + err.message);
				showNotice(err.message, 'error');
			}

			btn.disabled = false;
			if (other) other.disabled = false;
		}

		$('#wpmj-scan-btn').addEventListener('click', function() { runScan(this, false); });
		const resumeBtn = $('#wpmj-resume-btn');
		if (resumeBtn) resumeBtn.addEventListener('click', function() { runScan(this, true); });

		// ---- Clear results ----
		$('#wpmj-clear-btn').addEventListener('click', async function() {
			if (!await wpmjConfirm({ title: tr('confirmClear'), message: '' })) return;
			await wpmjAjax('clear_results');
			$('#wpmj-results').style.display = 'none';
			$('#wpmj-savings').innerHTML = '';
			$('#wpmj-meta').textContent = '';
			$('#wpmj-panel-duplicates').innerHTML = '';
			$('#wpmj-panel-unused').innerHTML = '';
			this.style.display = 'none';
			showNotice(tr('noticeCleared'));
		});

		// ---- Render results ----
		let STATE = { unused: [], exportUrl: '', shown: 200, query: '', sort: 'size-desc',
		              libraryTotal: 0, ignoredCount: 0 };

		function renderResults(data) {
			$('#wpmj-results').style.display = 'block';
			STATE.unused = data.unused || [];
			STATE.exportUrl = data.export_url || '';
			STATE.shown = 200;
			STATE.query = '';
			STATE.sort = 'size-desc';

			renderSavings(data);

			// The meta line sits above the panels, so it may only say things that
			// are true on whichever tab is open. The unused-only facts ("6 of 355
			// items in your library", the ignore-list count) used to live here and
			// stayed on screen while the Trash or Duplicates tab was showing,
			// describing a number the user was not looking at. They now render
			// inside the unused panel, next to the list they describe.
			const bits = [];
			if (data.scanned_at) bits.push(tr('lastScannedFmt', data.scanned_at));
			// Attachments whose file is gone from disk are skipped rather than
			// hashed, so say so - otherwise they are silently absent from both
			// the duplicate and unused numbers with no explanation.
			if (data.missing_count) bits.push(tr('missingNoteFmt', data.missing_count));
			$('#wpmj-meta').textContent = bits.join('  ·  ');

			STATE.libraryTotal = data.library_total || 0;
			STATE.ignoredCount = data.ignored_count || 0;

			setBadge('#wpmj-dup-count', data.duplicate_groups);
			setBadge('#wpmj-unused-count', data.unused_count);
			setBadge('#wpmj-trash-count', data.trashed_count || 0, true);

			renderDuplicates(data.duplicates || []);
			renderUnused();
		}

		// Badge colour carries meaning, so it is decided in exactly one place.
		//   green  - nothing to do
		//   grey   - items exist but they are informational, not a problem (trash)
		//   red    - items the user is expected to act on (duplicates, unused)
		// `neutral` used to mean "always green", which painted a trash tab
		// holding 40 files pending permanent deletion as if it were empty.
		function setBadge(sel, n, neutral) {
			const b = $(sel);
			if (!b) return;
			b.textContent = n;
			b.className = 'wpmj-badge' + (n === 0 ? ' clean' : (neutral ? ' info' : ''));
		}

		function renderSavings(data) {
			const el = $('#wpmj-savings');
			const totalItems = (data.duplicate_count || 0) + (data.unused_count || 0);

			// The banner's glyph is the plugin's own icon, not an emoji. Emoji
			// render as a different typeface on every OS, carry no brand, and
			// look like a placeholder next to a drawn mark. WPMJ.iconUrl is an
			// empty string when the file is missing, in which case the banner
			// simply has no glyph rather than a broken image.
			const glyph = WPMJ.iconUrl
				? '<img class="spark" src="' + escHtml(WPMJ.iconUrl) + '" alt="" width="26" height="26"> '
				: '';

			if (totalItems === 0) {
				el.innerHTML =
					'<div class="wpmj-savings clean">' +
						'<div class="total">' + glyph + escHtml(tr('libClean')) + '</div>' +
						'<div class="breakdown">' + escHtml(tr('libCleanSub')) + '</div>' +
					'</div>';
				return;
			}

			const dupGroups = data.duplicate_groups || 0;
			const dupCount  = data.duplicate_count || 0;
			const unused    = data.unused_count || 0;
			const parts = [];
			if (dupCount) {
				parts.push(tr('breakdownDupFmt', dupCount, dupCount === 1 ? tr('duplicate') : tr('duplicates'),
					dupGroups, dupGroups === 1 ? tr('group') : tr('groups')));
			}
			if (unused) parts.push(tr('breakdownUnFmt', unused, unused === 1 ? tr('unusedFile') : tr('unusedFiles')));
			parts.push(tr('breakdownTotalFmt', totalItems));

			el.innerHTML =
				'<div class="wpmj-savings">' +
					'<div class="total">' + glyph + escHtml(tr('reclaimableFmt', data.reclaimable_human || '0 B')) + '</div>' +
					'<div class="breakdown">' + escHtml(parts.join(' · ')) + '</div>' +
				'</div>';
		}

		function showNotice(msg, type) {
			const wrap = document.querySelector('.wrap');
			const notice = document.createElement('div');
			notice.className = 'notice notice-' + (type || 'success') + ' is-dismissible';
			notice.setAttribute('role', 'status');
			notice.style.marginTop = '10px';
			const p = document.createElement('p');
			p.textContent = msg;
			notice.appendChild(p);
			wrap.insertBefore(notice, wrap.firstChild.nextSibling);
			announce(msg);
			// Errors stay put. A message that vanishes after 2.7 seconds is one
			// a keyboard user can never reach and a screen reader may never finish.
			if (type !== 'error') {
				setTimeout(() => {
					notice.style.transition = 'opacity .3s';
					notice.style.opacity = '0';
					setTimeout(() => notice.remove(), 300);
				}, 4000);
			}
		}

		function thumbHtml(item) {
			const editUrl = WPMJ.adminUrl + 'post.php?post=' + item.id + '&action=edit';
			const inner = item.thumb
				? '<img class="thumb" loading="lazy" decoding="async" src="' + escHtml(item.thumb) + '" alt="">'
				: '<div class="no-thumb">' + escHtml(String(item.filename || '').split('.').pop().toUpperCase()) + '</div>';
			return '<a class="thumb-link" href="' + escHtml(editUrl) + '" target="_blank" rel="noopener" title="'
				+ escHtml(tr('thumbTitleFmt', item.id)) + '">' + inner + '</a>';
		}

		function escHtml(str) {
			const d = document.createElement('div');
			d.textContent = (str === undefined || str === null) ? '' : str;
			return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
		}

		// Where-is-this-used drill-down. The reference count is the whole basis
		// on which a user picks a keeper, so it has to be inspectable.
		function refsHtml(item) {
			const n = item.ref_count || 0;
			const posts = item.ref_posts || [];
			if (!n) return '<span class="wpmj-count">' + escHtml(tr('whereNone')) + '</span>';
			if (!posts.length) return String(n);

			let h = '<details class="wpmj-refs"><summary>' + escHtml(tr('whereUsedFmt', n)) + '</summary><ul>';
			posts.forEach(function(p) {
				const label = escHtml(p.title) + ' <span class="via">(' + escHtml(p.via) + ')</span>';
				h += '<li>' + (p.edit ? '<a href="' + escHtml(p.edit) + '" target="_blank" rel="noopener">' + label + '</a>' : label) + '</li>';
			});
			if (n > posts.length) h += '<li class="via">' + escHtml(tr('whereMore')) + '</li>';
			h += '</ul></details>';
			return h;
		}

		function renderDuplicates(groups) {
			const panel = $('#wpmj-panel-duplicates');
			if (!groups.length) {
				panel.innerHTML = '<div class="wpmj-empty">' + escHtml(tr('noDups')) + '</div>';
				return;
			}

			let html = '<div class="wpmj-toolbar">';
			html += '<button class="button button-primary" id="wpmj-resolve-all-btn">' + escHtml(tr('resolveAll')) + '</button>';
			html += '<span class="wpmj-count">' + escHtml(tr('resolveAllHint')) + '</span>';
			html += '<span id="wpmj-resolve-all-status"></span>';
			html += '<span class="spacer"></span>';
			html += exportBtnHtml();
			html += '</div>';

			groups.forEach(function(group, gi) {
				html += '<div class="wpmj-group" data-group="' + gi + '">';
				html += '<h3>' + escHtml(tr('dupGroupHeadFmt', gi + 1, group.items[0].filename, group.items.length, group.items[0].size)) + '</h3>';
				html += '<div class="wpmj-tablewrap"><table class="wpmj-table"><thead><tr>'
					+ '<th><span class="screen-reader-text">' + escHtml(tr('colPreview')) + '</span></th>'
					+ '<th>' + escHtml(tr('colKeep')) + '</th>'
					+ '<th>' + escHtml(tr('colFile')) + '</th>'
					+ '<th>' + escHtml(tr('colId')) + '</th>'
					+ '<th class="wpmj-col-optional">' + escHtml(tr('colDimensions')) + '</th>'
					+ '<th>' + escHtml(tr('colSize')) + '</th>'
					+ '<th>' + escHtml(tr('colReferences')) + '</th>'
					+ '<th class="wpmj-col-optional">' + escHtml(tr('colUploaded')) + '</th>'
					+ '</tr></thead><tbody>';
				group.items.forEach(function(item, ii) {
					html += '<tr>';
					html += '<td>' + thumbHtml(item) + '</td>';
					html += '<td><input type="radio" name="wpmj-keep-' + gi + '" value="' + item.id + '"'
						+ (ii === 0 ? ' checked' : '') + ' aria-label="' + escHtml(tr('keepFileFmt', item.filename)) + '"></td>';
					html += '<td><strong>' + escHtml(item.filename) + '</strong></td>';
					html += '<td>#' + item.id + '</td>';
					html += '<td class="wpmj-col-optional">' + escHtml(item.dimensions || '-') + '</td>';
					html += '<td>' + escHtml(item.size) + '</td>';
					html += '<td>' + refsHtml(item) + '</td>';
					html += '<td class="wpmj-col-optional">' + escHtml(item.date) + '</td>';
					html += '</tr>';
				});
				html += '</tbody></table></div>';
				html += '<div class="wpmj-actions"><button class="button button-primary wpmj-resolve-btn">' + escHtml(tr('btnResolve')) + '</button><span class="wpmj-resolve-status"></span></div>';
				html += '</div>';
			});

			panel.innerHTML = html;
		}

		function exportBtnHtml() {
			if (!STATE.exportUrl) return '';
			return '<a class="button" href="' + escHtml(STATE.exportUrl) + '" title="' + escHtml(tr('exportHint')) + '">'
				+ escHtml(tr('btnExportCsv')) + '</a>';
		}

		// ---- Unused panel: search, sort, paginate ----
		function visibleUnused() {
			const q = String(STATE.query || '').trim().toLowerCase();
			const sort = STATE.sort || 'size-desc';
			let items = STATE.unused.slice();
			if (q) items = items.filter(i => String(i.filename).toLowerCase().indexOf(q) !== -1);
			items.sort(function(a, b) {
				switch (sort) {
					case 'size-asc':  return (a.bytes || 0) - (b.bytes || 0);
					case 'date-desc': return String(b.date).localeCompare(String(a.date));
					case 'date-asc':  return String(a.date).localeCompare(String(b.date));
					case 'name':      return String(a.filename).localeCompare(String(b.filename));
					default:          return (b.bytes || 0) - (a.bytes || 0);
				}
			});
			return items;
		}

		function renderUnused() {
			const panel = $('#wpmj-panel-unused');
			const all = STATE.unused;

			if (!all.length) {
				panel.innerHTML = '<div class="wpmj-empty">' + escHtml(tr('noUnused')) + '</div>';
				return;
			}

			const matching = visibleUnused();
			const items = matching.slice(0, STATE.shown);

			let html = '<div class="wpmj-warn">' + escHtml(tr('mediaWarn')) + '</div>';

			// Unused-only context, kept beside the list it describes.
			const unusedBits = [];
			if (STATE.libraryTotal) unusedBits.push(tr('ofLibraryFmt', all.length, STATE.libraryTotal));
			if (STATE.ignoredCount) unusedBits.push(tr('ignoredNoteFmt', STATE.ignoredCount));
			if (unusedBits.length) html += '<div class="wpmj-meta">' + escHtml(unusedBits.join('  ·  ')) + '</div>';

			html += '<div class="wpmj-toolbar">';
			// Demoted from button-primary and relabelled. It ignores the
			// checkboxes entirely, and sitting above "Delete Selected" as the
			// big blue button it was the one a user reached for after carefully
			// unchecking the files they wanted to keep.
			html += '<button class="button" id="wpmj-cleanup-all-btn">' + escHtml(tr('btnTrashAllFmt', all.length)) + '</button>';
			html += '<span id="wpmj-cleanup-all-status"></span>';
			html += '<span class="spacer"></span>';
			html += '<label class="screen-reader-text" for="wpmj-search">' + escHtml(tr('search')) + '</label>';
			html += '<input type="search" id="wpmj-search" value="' + escHtml(STATE.query) + '" placeholder="' + escHtml(tr('search')) + '">';
			html += '<label class="screen-reader-text" for="wpmj-sort">' + escHtml(tr('sortBy')) + '</label>';
			const sortOpts = [
				['size-desc', tr('sortLargest')], ['size-asc', tr('sortSmallest')],
				['date-desc', tr('sortNewest')],  ['date-asc', tr('sortOldest')],
				['name', tr('sortName')],
			];
			html += '<select id="wpmj-sort">'
				+ sortOpts.map(o => '<option value="' + o[0] + '"'
					+ (STATE.sort === o[0] ? ' selected' : '') + '>' + escHtml(o[1]) + '</option>').join('')
				+ '</select>';
			html += exportBtnHtml();
			html += '</div>';

			if (!matching.length) {
				html += '<div class="wpmj-empty">' + escHtml(tr('noMatches')) + '</div>';
				panel.innerHTML = html;
				wireUnusedToolbar();
				return;
			}

			html += '<div style="margin-bottom:10px"><label><input type="checkbox" id="wpmj-select-all"> '
				+ escHtml(tr('selectAllVisible')) + '</label> <span class="wpmj-count">'
				+ escHtml(tr('showingFmt', items.length, matching.length)) + '</span></div>';

			html += '<div class="wpmj-tablewrap"><table id="wpmj-unused-table" class="wpmj-table"><thead><tr>'
				+ '<th><span class="screen-reader-text">' + escHtml(tr('colSelect')) + '</span></th>'
				+ '<th><span class="screen-reader-text">' + escHtml(tr('colPreview')) + '</span></th>'
				+ '<th>' + escHtml(tr('colFile')) + '</th>'
				+ '<th>' + escHtml(tr('colId')) + '</th>'
				+ '<th class="wpmj-col-optional">' + escHtml(tr('colType')) + '</th>'
				+ '<th>' + escHtml(tr('colSize')) + '</th>'
				+ '<th class="wpmj-col-optional">' + escHtml(tr('colUploaded')) + '</th>'
				+ '<th><span class="screen-reader-text">' + escHtml(tr('colActions')) + '</span></th>'
				+ '</tr></thead><tbody>';
			items.forEach(function(item) {
				html += '<tr data-id="' + item.id + '">';
				html += '<td><input type="checkbox" class="wpmj-unused-cb" value="' + item.id + '" aria-label="'
					+ escHtml(tr('selectFileFmt', item.filename)) + '"></td>';
				html += '<td>' + thumbHtml(item) + '</td>';
				html += '<td><strong>' + escHtml(item.filename) + '</strong></td>';
				html += '<td>#' + item.id + '</td>';
				html += '<td class="wpmj-col-optional">' + escHtml(item.mime || '-') + '</td>';
				html += '<td>' + escHtml(item.size) + '</td>';
				html += '<td class="wpmj-col-optional">' + escHtml(item.date) + '</td>';
				html += '<td><button class="button button-small wpmj-ignore-btn" data-id="' + item.id + '" title="'
					+ escHtml(tr('ignoreTitle')) + '">' + escHtml(tr('btnIgnore')) + '</button></td>';
				html += '</tr>';
			});
			html += '</tbody></table></div>';

			if (matching.length > items.length) {
				html += '<div class="wpmj-pager"><button class="button" id="wpmj-more-btn">' + escHtml(tr('btnLoadMore'))
					+ '</button><span class="wpmj-count">' + escHtml(tr('showingFmt', items.length, matching.length)) + '</span></div>';
			}

			html += '<div class="wpmj-actions"><button class="button button-primary" id="wpmj-delete-unused-btn">'
				+ escHtml(tr('btnTrashSelected')) + '</button><span id="wpmj-delete-status"></span></div>';

			panel.innerHTML = html;
			wireUnusedToolbar();
		}

		function wireUnusedToolbar() {
			const search = $('#wpmj-search');
			if (search) {
				let t = null;
				search.addEventListener('input', function() {
					clearTimeout(t);
					t = setTimeout(function() {
						const pos = search.selectionStart;
						STATE.query = search.value;
						STATE.shown = 200;
						renderUnused();
						const s2 = $('#wpmj-search');
						if (s2) { s2.focus(); s2.setSelectionRange(pos, pos); }
					}, 200);
				});
			}
			const sort = $('#wpmj-sort');
			if (sort) sort.addEventListener('change', function() {
				STATE.sort = sort.value;
				STATE.shown = 200;
				renderUnused();
			});

			const more = $('#wpmj-more-btn');
			if (more) more.addEventListener('click', function() { STATE.shown += 200; renderUnused(); });

			// "Select all shown" means exactly that - the rows currently
			// rendered, not every match hidden behind pagination.
			const all = $('#wpmj-select-all');
			if (all) all.addEventListener('change', function() {
				$$('.wpmj-unused-cb').forEach(cb => { cb.checked = all.checked; });
			});
		}

		// ---- Trash panel ----
		async function loadTrash() {
			const panel = $('#wpmj-panel-trashed');
			if (panel.dataset.loading === '1') return;
			panel.dataset.loading = '1';
			if (!panel.innerHTML) panel.innerHTML = '<div class="wpmj-empty">' + escHtml(tr('trashLoading')) + '</div>';

			let r;
			try {
				r = await wpmjAjax('list_trash');
			} catch (err) {
				// The flag MUST come off on the error path too. Leaving it set
				// made every later click on the tab return early in silence.
				panel.dataset.loading = '0';
				panel.innerHTML = '<div class="wpmj-empty">' + escHtml(err.message) + '</div>';
				return;
			}
			panel.dataset.loading = '0';
			if (!r.success) { panel.innerHTML = '<div class="wpmj-empty">' + escHtml(r.data.message || '') + '</div>'; return; }

			const items = r.data.items || [];
			setBadge('#wpmj-trash-count', r.data.count || 0, true);

			if (!items.length) {
				panel.innerHTML = '<div class="wpmj-empty">' + escHtml(tr('trashEmpty')) + '</div>';
				return;
			}

			let html = '<div class="wpmj-trash-note">' + escHtml(tr('trashNote')) + '</div>';
			html += '<div class="wpmj-toolbar">';
			html += '<button class="button" id="wpmj-restore-sel-btn">' + escHtml(tr('btnRestoreSel')) + '</button>';
			html += '<button class="button" id="wpmj-empty-trash-btn">' + escHtml(tr('btnEmptyTrash')) + '</button>';
			html += '<span id="wpmj-trash-status"></span>';
			html += '<span class="spacer"></span>';
			html += '<span class="wpmj-count">' + escHtml(tr('reclaimableFmt', r.data.bytes_human)) + '</span>';
			html += '</div>';
			html += '<div class="wpmj-tablewrap"><table id="wpmj-trash-table" class="wpmj-table"><thead><tr>'
				+ '<th><span class="screen-reader-text">' + escHtml(tr('colSelect')) + '</span></th>'
				+ '<th><span class="screen-reader-text">' + escHtml(tr('colPreview')) + '</span></th>'
				+ '<th>' + escHtml(tr('colFile')) + '</th>'
				+ '<th>' + escHtml(tr('colId')) + '</th>'
				+ '<th>' + escHtml(tr('colSize')) + '</th>'
				+ '<th class="wpmj-col-optional">' + escHtml(tr('colTrashedAt')) + '</th>'
				+ '<th><span class="screen-reader-text">' + escHtml(tr('colActions')) + '</span></th>'
				+ '</tr></thead><tbody>';
			items.forEach(function(item) {
				html += '<tr data-id="' + item.id + '">';
				html += '<td><input type="checkbox" class="wpmj-trash-cb" value="' + item.id + '" aria-label="'
					+ escHtml(tr('selectFileFmt', item.filename)) + '"></td>';
				html += '<td>' + thumbHtml(item) + '</td>';
				html += '<td><strong>' + escHtml(item.filename) + '</strong></td>';
				html += '<td>#' + item.id + '</td>';
				html += '<td>' + escHtml(item.size) + '</td>';
				html += '<td class="wpmj-col-optional">' + escHtml(item.trashed_at || '') + '</td>';
				html += '<td><button class="button button-small wpmj-restore-btn" data-id="' + item.id + '">'
					+ escHtml(tr('btnRestore')) + '</button></td>';
				html += '</tr>';
			});
			html += '</tbody></table></div>';
			panel.innerHTML = html;
		}

		// ---- Resolve one duplicate group ----
		document.addEventListener('click', guard(async function(e) {
			if (!e.target.classList.contains('wpmj-resolve-btn')) return;
			const group = e.target.closest('.wpmj-group');
			const keeper = group.querySelector('input[type=radio]:checked');
			if (!keeper) { showNotice(tr('selectKeeper'), 'error'); return; }

			const keeperId = keeper.value;
			const dupIds = [];
			group.querySelectorAll('input[type=radio]').forEach(function(r) {
				if (r.value !== keeperId) dupIds.push(r.value);
			});
			if (!dupIds.length) return;

			if (!await wpmjConfirm({
				title: tr('btnResolve'),
				message: tr('confirmResolveFmt', keeperId, dupIds.length),
				destructive: true,
				okLabel: tr('dialogDelete')
			})) return;

			const btn = e.target;
			const status = group.querySelector('.wpmj-resolve-status');
			btn.disabled = true;
			btn.textContent = tr('btnResolving');
			status.textContent = '';

			const r = await wpmjAjax('resolve_duplicate', { keeper_id: keeperId, duplicate_ids: dupIds.join(',') });

			if (r.success) {
				const blocked = r.data.blocked || [];
				const done = (r.data.deleted > 0) && !blocked.length;

				status.textContent = tr('resolveStatusFmt', r.data.deleted, r.data.refs_updated);
				status.style.color = done ? '#00a32a' : '#dba617';
				announce(status.textContent);
				reportBlocked(blocked);

				if (done) {
					group.dataset.resolved = 'true';
					btn.textContent = tr('btnResolved');
					btn.disabled = true;
				} else {
					// Leave the group live and retryable. The server keeps it in
					// the results transient precisely so a retry can work once
					// the offending row or file is dealt with.
					btn.disabled = false;
					btn.textContent = tr('btnResolve');
					return;
				}
				// Go through setBadge rather than reimplementing it. The copy
				// that used to live here only ever added the clean class and
				// never removed it, so a badge that reached zero and then went
				// back up stayed green.
				setBadge('#wpmj-dup-count', document.querySelectorAll('.wpmj-group:not([data-resolved="true"])').length);
			} else {
				btn.disabled = false;
				btn.textContent = tr('btnResolve');
				status.textContent = tr('errPrefix') + ' ' + r.data.message;
				status.style.color = '#d63638';
				announce(status.textContent);
			}
		}));

		// ---- Trash / delete selected unused ----
		document.addEventListener('click', guard(async function(e) {
			if (e.target.id !== 'wpmj-delete-unused-btn') return;
			const checked = document.querySelectorAll('.wpmj-unused-cb:checked');
			if (!checked.length) { showNotice(tr('noSelection'), 'error'); return; }

			const ids = [];
			const names = [];
			checked.forEach(function(cb) {
				ids.push(cb.value);
				const row = cb.closest('tr');
				const strong = row ? row.querySelector('strong') : null;
				if (strong) names.push(strong.textContent);
			});

			if (!await wpmjConfirm({
				title: tr('btnTrashSelected'),
				message: tr('confirmTrashFmt', ids.length),
				files: names
			})) return;

			const btn = e.target;
			const status = $('#wpmj-delete-status');
			btn.disabled = true;
			btn.textContent = tr('btnTrashing');
			status.textContent = '';

			const r = await wpmjAjax('delete_unused', { ids: ids.join(','), mode: 'trash' });

			if (r.success) {
				const skipped = r.data.skipped || [];
				const skippedIds = skipped.map(s => String(s.id));
				const gone = ids.filter(id => skippedIds.indexOf(String(id)) === -1);
				STATE.unused = STATE.unused.filter(i => gone.indexOf(String(i.id)) === -1);
				renderUnused();
				setBadge('#wpmj-unused-count', STATE.unused.length);
				loadTrash();

				let msg = tr('filesTrashedFmt', r.data.deleted);
				if (skipped.length) msg += ' ' + tr('skippedFmt', skipped.length);
				showNotice(msg, skipped.length ? 'warning' : 'success');
			} else {
				status.textContent = tr('errPrefix') + ' ' + r.data.message;
				status.style.color = '#d63638';
				showNotice(r.data.message, 'error');
				btn.disabled = false;
				btn.textContent = tr('btnTrashSelected');
			}
		}));

		// ---- Ignore ----
		document.addEventListener('click', guard(async function(e) {
			if (!e.target.classList.contains('wpmj-ignore-btn')) return;
			const id = e.target.dataset.id;
			e.target.disabled = true;
			const r = await wpmjAjax('toggle_ignore', { ids: id, ignore: '1' });
			if (r.success) {
				STATE.unused = STATE.unused.filter(i => String(i.id) !== String(id));
				renderUnused();
				setBadge('#wpmj-unused-count', STATE.unused.length);
				showNotice(tr('ignoredFmt', 1));
			} else {
				e.target.disabled = false;
				showNotice(r.data.message, 'error');
			}
		}));

		// ---- Restore from trash ----
		document.addEventListener('click', guard(async function(e) {
			const single = e.target.classList.contains('wpmj-restore-btn');
			const bulk = e.target.id === 'wpmj-restore-sel-btn';
			if (!single && !bulk) return;

			let ids = [];
			if (single) ids = [e.target.dataset.id];
			else document.querySelectorAll('.wpmj-trash-cb:checked').forEach(cb => ids.push(cb.value));
			if (!ids.length) { showNotice(tr('noSelection'), 'error'); return; }

			e.target.disabled = true;
			const r = await wpmjAjax('restore_trash', { ids: ids.join(',') });
			e.target.disabled = false;
			if (r.success) {
				showNotice(tr('restoredFmt', r.data.restored));
				loadTrash();
			} else {
				showNotice(r.data.message, 'error');
			}
		}));

		// ---- Empty trash ----
		document.addEventListener('click', guard(async function(e) {
			if (e.target.id !== 'wpmj-empty-trash-btn') return;
			const ids = [];
			const names = [];
			document.querySelectorAll('.wpmj-trash-cb:checked').forEach(function(cb) {
				ids.push(cb.value);
				const row = cb.closest('tr');
				const strong = row ? row.querySelector('strong') : null;
				if (strong) names.push(strong.textContent);
			});
			const count = ids.length || document.querySelectorAll('.wpmj-trash-cb').length;
			if (!count) return;

			if (!await wpmjConfirm({
				title: tr('btnEmptyTrash'),
				message: tr('confirmEmptyFmt', count),
				files: names,
				destructive: true,
				okLabel: tr('dialogDelete')
			})) return;

			e.target.disabled = true;
			const r = await wpmjAjax('empty_trash', ids.length ? { ids: ids.join(',') } : {});
			e.target.disabled = false;
			if (r.success) {
				showNotice(tr('emptiedFmt', r.data.deleted));
				loadTrash();
			} else {
				showNotice(r.data.message, 'error');
			}
		}));

		// ---- Resolve All duplicates ----
		document.addEventListener('click', guard(async function(e) {
			if (e.target.id !== 'wpmj-resolve-all-btn') return;

			const groups = document.querySelectorAll('.wpmj-group');
			const active = [...groups].filter(g => g.dataset.resolved !== 'true');
			if (!active.length) { showNotice(tr('noUnresolved'), 'error'); return; }

			if (!await wpmjConfirm({
				title: tr('resolveAll'),
				message: tr('confirmResolveAllFmt', active.length),
				destructive: true,
				okLabel: tr('dialogDelete')
			})) return;

			const btn = e.target;
			const status = $('#wpmj-resolve-all-status');
			btn.disabled = true;
			btn.textContent = tr('btnResolving');
			status.textContent = '';

			let resolved = 0, errors = 0, lastError = '', allBlocked = [];

			for (const group of active) {
				const keeper = group.querySelector('input[type=radio]:checked');
				if (!keeper) continue;
				const keeperId = keeper.value;
				const dupIds = [];
				group.querySelectorAll('input[type=radio]').forEach(function(r) {
					if (r.value !== keeperId) dupIds.push(r.value);
				});
				if (!dupIds.length) continue;

				btn.textContent = tr('btnResolvingFmt', resolved + 1, active.length);
				const r = await wpmjAjax('resolve_duplicate', { keeper_id: keeperId, duplicate_ids: dupIds.join(',') });

				if (r.success) {
					const gBlocked = r.data.blocked || [];
					gBlocked.forEach(b => allBlocked.push(b));
					if (!(r.data.deleted > 0) || gBlocked.length) {
						// Blocked groups stay live and retryable.
						errors++;
						continue;
					}
					group.dataset.resolved = 'true';
					const gb = group.querySelector('.wpmj-resolve-btn');
					const gs = group.querySelector('.wpmj-resolve-status');
					if (gb) { gb.textContent = tr('btnResolved'); gb.disabled = true; }
					if (gs) {
						gs.textContent = tr('resolveStatusFmt', r.data.deleted, r.data.refs_updated);
						gs.style.color = '#00a32a';
					}
					resolved++;
				} else {
					errors++;
					lastError = r.data.message || '';
					// A stale results transient fails every remaining group the
					// same way, so stop rather than firing N more doomed calls.
					if (r.data.code === 'results_expired' || r.data.code === 'bad_nonce') break;
				}
			}

			const remaining = document.querySelectorAll('.wpmj-group:not([data-resolved="true"])').length;
			setBadge('#wpmj-dup-count', remaining);

			if (remaining === 0 && !errors) {
				btn.textContent = tr('btnAllDone');
				btn.disabled = true;
			} else {
				btn.disabled = false;
				btn.textContent = tr('resolveAll');
			}
			status.textContent = errors ? tr('resolveAllSummaryErrorFmt', resolved, errors) : tr('resolveAllSummaryFmt', resolved);
			status.style.color = errors ? '#dba617' : '#00a32a';
			if (errors && lastError) showNotice(lastError, 'error');
			reportBlocked(allBlocked);
			announce(status.textContent);
		}));

		// ---- Trash All unused ----
		document.addEventListener('click', guard(async function(e) {
			if (e.target.id !== 'wpmj-cleanup-all-btn') return;

			const allIds = STATE.unused.map(i => String(i.id));
			if (!allIds.length) { showNotice(tr('noUnusedToCleanup'), 'error'); return; }

			if (!await wpmjConfirm({
				title: tr('btnTrashAllFmt', allIds.length),
				message: tr('confirmTrashFmt', allIds.length),
				files: STATE.unused.map(i => i.filename)
			})) return;

			const btn = e.target;
			const status = $('#wpmj-cleanup-all-status');
			btn.disabled = true;
			status.textContent = '';

			let done = 0, skipped = 0, failed = '';

			for (let i = 0; i < allIds.length; i += 25) {
				const batch = allIds.slice(i, i + 25);
				btn.textContent = tr('btnDeletingFmt', Math.min(i + 25, allIds.length), allIds.length);

				const r = await wpmjAjax('delete_unused', { ids: batch.join(','), mode: 'trash' });
				if (r.success) {
					done += r.data.deleted;
					skipped += (r.data.skipped || []).length;
					const skippedIds = (r.data.skipped || []).map(s => String(s.id));
					const gone = batch.filter(id => skippedIds.indexOf(id) === -1);
					STATE.unused = STATE.unused.filter(it => gone.indexOf(String(it.id)) === -1);
				} else {
					// Previously this had no else branch at all: an expired
					// transient made every batch fail, nothing was removed, and
					// the button simply reset with no message.
					failed = r.data.message || '';
					break;
				}
			}

			renderUnused();
			setBadge('#wpmj-unused-count', STATE.unused.length);
			loadTrash();
			btn.disabled = false;
			btn.textContent = tr('btnTrashAllFmt', STATE.unused.length);

			if (failed) {
				showNotice(failed, 'error');
			} else {
				let msg = tr('filesTrashedFmt', done);
				if (skipped) msg += ' ' + tr('skippedFmt', skipped);
				showNotice(msg, skipped ? 'warning' : 'success');
			}
		}));

		// ---- Auto-load existing results on page load ----
		if (WPMJ.hasResults) {
			$('#wpmj-results').style.display = 'block';
			$('#wpmj-savings').innerHTML =
				'<div class="wpmj-savings" style="background:#f0f0f1;color:#666;box-shadow:none">' +
					'<div class="total" style="font-size:16px;font-weight:400">' + escHtml(tr('loadingPrev')) + '</div>' +
				'</div>';
			(async function() {
				const results = await wpmjAjax('get_results');
				if (results.success) {
					renderResults(results.data);
				} else {
					$('#wpmj-savings').innerHTML = '';
					$('#wpmj-results').style.display = 'none';
				}
			})();
		}

	})();
	</script>
	<?php
}

// ===========================================================================
// AJAX HANDLERS
// ===========================================================================

// ---------------------------------------------------------------------------
// scan_init - count attachments, set up scan state transient
// ---------------------------------------------------------------------------
function wpmj_ajax_scan_init() {
	wpmj_auth();
	wpmj_raise_limits();
	global $wpdb;

	$total = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash'"
	);

	// Refuse to start on top of a scan that is still running.
	//
	// Scan state is a single shared transient, so two people scanning at once
	// (or one person with two tabs open) interleave their cursors and counters
	// and both get wrong answers. A scan whose last chunk landed within the
	// last two minutes is treated as live; anything older is assumed abandoned
	// and can be taken over, which is what makes this a guard rather than a
	// lock someone can get permanently stuck behind.
	$existing = get_transient( WPMJ_TRANSIENT_SCAN_STATE );
	$force    = wpmj_post_text( 'force' ) === '1';
	if ( $existing && empty( $existing['complete'] ) && ! $force ) {
		$last = (int) ( $existing['touched'] ?? 0 );
		if ( $last && ( time() - $last ) < 2 * MINUTE_IN_SECONDS ) {
			wp_send_json_error( [
				'message' => __( 'Another scan is already running. Wait for it to finish, or reload this page to resume it.', 'media-mage' ),
				'code'    => 'scan_in_progress',
			] );
		}
	}

	// Clear any previous scan state
	delete_transient( WPMJ_TRANSIENT_SCAN_STATE );
	delete_transient( WPMJ_TRANSIENT_RESULTS );

	$state = [
		'total'    => $total,
		'touched'  => time(),
		'seen'     => [ 'hash' => 0, 'references' => 0 ],
		'unused'   => [],
		'missing'  => [],
		'complete' => false,
	];
	set_transient( WPMJ_TRANSIENT_SCAN_STATE, $state, WPMJ_RESULTS_TTL );

	wp_send_json_success( [ 'total' => $total ] );
}

// ---------------------------------------------------------------------------
// scan_chunk - process a batch (hash phase or reference phase)
// ---------------------------------------------------------------------------
function wpmj_ajax_scan_chunk() {
	wpmj_auth();
	wpmj_raise_limits();
	global $wpdb;

	$offset = max( 0, wpmj_post_int( 'offset' ) );
	$phase  = wpmj_post_text( 'phase', 'hash' );
	// Clamped below, once the state is loaded: a stale or invented offset that
	// jumps past the recorded cursor would return an empty batch and end the
	// phase, storing a scan that examined a fraction of the library as complete.
	if ( ! in_array( $phase, [ 'hash', 'references' ], true ) ) {
		wp_send_json_error( [ 'message' => __( 'Unknown scan phase.', 'media-mage' ) ] );
	}

	$state = get_transient( WPMJ_TRANSIENT_SCAN_STATE );
	if ( ! $state ) wp_send_json_error( [ 'message' => __( 'No scan in progress. Run scan_init first.', 'media-mage' ) ] );

	// A phase always starts at ID 0, so 0 is the correct ceiling for its first
	// chunk. Falling back to PHP_INT_MAX meant no clamp at all there, and one
	// request with a bogus offset ended the phase and stored a scan that had
	// examined nothing as complete.
	$offset = min( $offset, (int) ( $state['cursor'][ $phase ] ?? 0 ) );

	$total = $state['total'];
	if ( $total === 0 ) {
		wp_send_json_success( [ 'scanned' => 0, 'total' => 0, 'percent' => 100, 'next_offset' => 0, 'done' => true, 'skipped' => 0 ] );
	}

	// Reference phase is much heavier (multiple LIKE queries per attachment), use smaller batches
	$batch = ( $phase === 'references' ) ? 25 : WPMJ_BATCH_SIZE;

	// Keyset pagination, not LIMIT/OFFSET.
	//
	// Offsets address positions in a result set that can move. If anything
	// deletes attachments while a scan runs - another admin, a second tab, a
	// scheduled job - every later row shifts left and exactly that many live
	// files are stepped over and never scanned. "offset" stays the wire name
	// for compatibility with the client loop, but it carries the last ID seen.
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_type = 'attachment' AND post_status != 'trash' AND ID > %d
		 ORDER BY ID ASC LIMIT %d",
		$offset, $batch
	) );

	$skipped = 0;
	$batch_files = [];

	if ( $phase === 'hash' ) {
		$missing = $state['missing'] ?? [];

		foreach ( $ids as $att_id ) {
			$file = get_attached_file( $att_id );
			$fname = $file ? basename( $file ) : '(unknown)';

			if ( ! $file || ! file_exists( $file ) ) {
				// Clear the cached hash. Duplicate groups are derived from this
				// meta, so a stale hash left on an attachment whose file is gone
				// put a GHOST into a group - and because the keeper is chosen by
				// reference count, which does not know the file is missing, the
				// ghost could win the tie and the only real copy on disk got
				// force-deleted.
				wpmj_forget_hash( $att_id );

				$missing[] = (int) $att_id;
				$batch_files[] = $fname . ' (missing)';
				$skipped++;
				continue;
			}

			$batch_files[] = $fname;

			// Check cached hash
			$hash = wpmj_hash_attachment( $att_id, $file );

			// Nothing accumulates in the scan state here. The hash is in
			// postmeta, which is where the groups are derived from at finalize.
			unset( $hash );
		}

		$state['missing'] = $missing;

	} elseif ( $phase === 'references' ) {
		$unused = $state['unused'] ?? [];
		$unused_in_batch = [];

		foreach ( $ids as $att_id ) {
			$file = get_attached_file( $att_id );
			$fname = $file ? basename( $file ) : '#' . $att_id;
			$batch_files[] = $fname;

			if ( ! wpmj_is_referenced( $att_id ) ) {
				$unused[] = (int) $att_id;
				$unused_in_batch[] = $fname;
			}
		}

		// Deduped as a backstop: a replayed chunk must not be able to list the
		// same file twice, whatever the cursor bookkeeping does.
		$state['unused'] = array_values( array_unique( $unused ) );
	}

	// Progress is a running count kept in the scan state, because a cursor ID is
	// not a position and cannot be turned into one. Done means the keyset query
	// came back short - the only reliable end-of-set signal.
	// A chunk is not idempotent, and the client retries on a lost response - so
	// a batch that completed server-side and then dropped its reply gets
	// replayed at the same cursor. Without this, the replay appends the same
	// IDs a second time: the file shows up twice in the unused table and its
	// bytes are counted twice in the headline reclaimable figure.
	$is_replay = $offset < (int) ( $state['cursor'][ $phase ] ?? -1 );

	$seen = (int) ( $state['seen'][ $phase ] ?? 0 );
	if ( ! $is_replay ) {
		$seen += count( $ids );
		$state['seen'][ $phase ] = $seen;
	}

	$cursor = $ids ? (int) end( $ids ) : $offset;
	if ( $is_replay ) {
		// Never walk the cursor backwards on a replay.
		$cursor = max( $cursor, (int) $state['cursor'][ $phase ] );
	}

	// A short batch is the end-of-set signal, but on its own it also fires for a
	// request whose offset ran past the real cursor - which ended the phase and
	// stored an incomplete scan as complete. Coverage has to agree.
	$max_id = (int) $wpdb->get_var(
		"SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash'"
	);

	// Remember where each phase got to, so an interrupted scan can pick up from
	// here instead of starting over. Hundreds of sequential requests is a long
	// time to ask someone to not close a tab.
	$state['cursor'][ $phase ] = $cursor;
	$state['phase']            = $phase;
	$state['touched']          = time();
	$scanned = min( $seen, max( $total, $seen ) );
	$done    = ( count( $ids ) < $batch ) && ( $seen >= $total || $cursor >= $max_id );

	// If this is the last chunk of the references phase, finalize results
	if ( $phase === 'references' && $done ) {
		$state['complete'] = true;
		wpmj_finalize_results( $state );
	}

	// Never let a failed write pass as success. set_transient returns false when
	// the value exceeds max_allowed_packet, or memcached's hard 1 MB item limit,
	// and the old code ignored it - so the state froze, the cursor kept
	// advancing, and everything scanned past that point vanished from the
	// results while the UI showed a stuck progress bar and no error at all.
	if ( ! set_transient( WPMJ_TRANSIENT_SCAN_STATE, $state, WPMJ_RESULTS_TTL ) ) {
		wp_send_json_error( [
			'message' => __( 'Could not save scan progress - the scan state is too large for this site to store. Run "wp media-mage scan" on the command line instead, which does not have this limit.', 'media-mage' ),
			'code'    => 'state_write_failed',
		] );
	}

	wp_send_json_success( [
		'scanned'        => $scanned,
		'total'          => $total,
		'percent'        => $total > 0 ? min( 100, round( $scanned / $total * 100, 1 ) ) : 100,
		'next_offset'    => $cursor,
		'done'           => $done,
		'skipped'        => $skipped,
		'files'          => $batch_files,
		'unused_in_batch' => $unused_in_batch ?? [],
	] );
}

// ---------------------------------------------------------------------------
// Oxygen Builder deep scan - decode base64 builder data and search for refs
// ---------------------------------------------------------------------------
function wpmj_in_oxygen_data( $url_path ) {
	if ( ! $url_path ) return false;

	// Match the full URL path, not the bare basename. Matching "logo.png" also
	// matched "site-logo.png", and made two files with the same basename in
	// different year folders indistinguishable. That over-reports references,
	// which is safe for deletion but feeds wpmj_count_references() - and that
	// count decides which copy "Resolve All" keeps without asking. An inflated
	// count on the wrong copy keeps the wrong file.
	$blob = wpmj_oxygen_cache();
	if ( $blob === '' ) return false;

	if ( strpos( $blob, $url_path ) !== false ) return true;

	// Oxygen stores plenty of URLs with JSON-escaped slashes.
	$escaped = str_replace( '/', '\/', $url_path );
	return $escaped !== $url_path && strpos( $blob, $escaped ) !== false;
}

/**
 * The decoded Oxygen blob, built once per request.
 *
 * Held in a static so a batch scan does not re-query and re-decode per
 * attachment, and resettable because the blob goes stale the moment an
 * attachment is deleted during a resolve.
 */
function wpmj_oxygen_cache( $reset = false ) {
	static $cache = null;
	if ( $reset ) { $cache = null; return ''; }
	if ( $cache === null ) $cache = wpmj_build_oxygen_cache();
	return $cache;
}

/**
 * Decode all Oxygen Builder data into one searchable string.
 * Called once per PHP request and cached in static var via wpmj_in_oxygen_data().
 */
function wpmj_build_oxygen_cache() {
	global $wpdb;
	$parts = [];

	// 1. Oxygen postmeta: _ct_builder_shortcodes and _ct_builder_json
	//    Base64-encoded builder data containing image URLs.
	$rows = $wpdb->get_results(
		"SELECT meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key IN ('_ct_builder_shortcodes','_ct_builder_json')
		   AND meta_value != ''"
	);
	foreach ( $rows as $row ) {
		$decoded = base64_decode( $row->meta_value, true );
		if ( $decoded !== false ) {
			$parts[] = $decoded;
		} else {
			// Not base64 - use raw value
			$parts[] = $row->meta_value;
		}
	}

	// 2. Oxygen stylesheets (ct_style_sheets) - serialized array with base64 CSS
	$sheets = get_option( 'ct_style_sheets' );
	if ( is_array( $sheets ) ) {
		foreach ( $sheets as $sheet ) {
			$css = base64_decode( $sheet['css'] ?? '', true );
			if ( $css ) $parts[] = $css;
		}
	}

	// 3. Oxygen component classes (ct_components_classes) - serialized with URLs.
	// get_option() rather than a raw query so this hits the object cache.
	$classes = get_option( 'ct_components_classes' );
	if ( $classes ) {
		$parts[] = is_scalar( $classes ) ? (string) $classes : wp_json_encode( $classes );
	}

	return implode( "\n", $parts );
}

/**
 * The cache key a stored hash is valid for.
 *
 * mtime alone is not enough - rsync -t and cp -p preserve it while replacing
 * content - so size is part of the key. Both scan paths MUST derive it the same
 * way: when they disagreed, each one invalidated the other's cache and every
 * scan re-hashed the whole library.
 */
function wpmj_hash_key( $file ) {
	return filemtime( $file ) . ':' . filesize( $file );
}

/**
 * Hash an attachment's file, reusing the cached value when it is still valid.
 */
function wpmj_hash_attachment( $att_id, $file ) {
	$key    = wpmj_hash_key( $file );
	$cached = get_post_meta( $att_id, WPMJ_META_HASH, true );

	if ( $cached && (string) get_post_meta( $att_id, WPMJ_META_HASH_MTIME, true ) === (string) $key ) {
		return $cached;
	}

	$hash = md5_file( $file );
	update_post_meta( $att_id, WPMJ_META_HASH, $hash );
	update_post_meta( $att_id, WPMJ_META_HASH_MTIME, $key );
	return $hash;
}

/**
 * Forget an attachment's cached hash.
 *
 * Duplicate groups are derived from this meta, so a stale hash left on an
 * attachment whose file has gone puts a ghost into a group - and the ghost can
 * win the keeper tie and get the only real file deleted.
 */
function wpmj_forget_hash( $att_id ) {
	delete_post_meta( $att_id, WPMJ_META_HASH );
	delete_post_meta( $att_id, WPMJ_META_HASH_MTIME );
}

// ---------------------------------------------------------------------------
// Every URL path an attachment can be referenced by
// ---------------------------------------------------------------------------
/**
 * An attachment is not one URL. It is the full-size file, one file per
 * registered size, and - for anything above the big-image threshold - a
 * -scaled variant plus the preserved original.
 *
 * Matching only the full-size URL was a FALSE POSITIVE generator, and a false
 * positive in a delete tool means deleting a file the site is using. Inserting
 * an image at Thumbnail/Medium/Large embeds the sized URL, and for a large
 * upload wp_get_attachment_url() returns the -scaled name while the content
 * may well reference the original.
 *
 * These are full URL paths (they include the uploads directory), which is what
 * keeps them from matching the attachment's own _wp_attached_file row.
 */
function wpmj_attachment_paths( $att_id ) {
	$url = wp_get_attachment_url( $att_id );
	if ( ! $url ) return [];

	$main = wp_parse_url( $url, PHP_URL_PATH );
	if ( ! $main ) return [];

	$paths = [ $main ];
	$meta  = wp_get_attachment_metadata( $att_id );

	// Siblings are built from the attachment's OWN relative path against the
	// real uploads base, not by borrowing the directory out of its URL.
	//
	// Pairing the URL's directory with a metadata basename synthesises a path
	// that, after a migration or under a CDN/offload filter that rewrites
	// attachment URLs, is a real file belonging to a DIFFERENT attachment. That
	// borrowed reference then inflates the other file's reference count - and
	// that count is what decides which copy "Resolve All" keeps.
	$uploads  = wp_get_upload_dir();
	$base_url = ! empty( $uploads['baseurl'] ) ? trailingslashit( $uploads['baseurl'] ) : '';
	$rel_dir  = '';
	if ( ! empty( $meta['file'] ) ) {
		$rel     = ltrim( str_replace( '\\', '/', $meta['file'] ), '/' );
		$rel_dir = ( dirname( $rel ) === '.' ) ? '' : trailingslashit( dirname( $rel ) );

		// The -scaled sibling itself, in case the stored URL is the original.
		if ( $base_url ) {
			$sib = wp_parse_url( $base_url . $rel, PHP_URL_PATH );
			if ( $sib ) $paths[] = $sib;
		}
	}

	// Siblings are emitted at BOTH bases when the two differ.
	//
	// Preferring the metadata base alone regressed every CDN and offload site:
	// when a plugin filters wp_get_attachment_url, the full-size path becomes
	// the CDN path while the sizes were still built at the local uploads base,
	// so an image inserted at Medium - referenced by its CDN URL - became
	// invisible and was reported unused. Emitting both only ever over-reports
	// references, which keeps a dead file; emitting one can delete a live one.
	$bases = [];
	if ( $base_url && ! empty( $meta['file'] ) ) {
		$meta_base = wp_parse_url( $base_url . $rel_dir, PHP_URL_PATH );
		if ( $meta_base ) $bases[] = trailingslashit( $meta_base );
	}
	$bases[] = trailingslashit( dirname( $main ) );
	$bases   = array_values( array_unique( array_filter( $bases ) ) );

	foreach ( $bases as $dir ) {
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) $paths[] = $dir . $size['file'];
			}
		}
		// The pre-scale original kept beside a -scaled file.
		if ( ! empty( $meta['original_image'] ) ) {
			$paths[] = $dir . $meta['original_image'];
		}
	}

	return array_values( array_unique( array_filter( $paths ) ) );
}

/**
 * Is this attachment the custom logo of ANY installed theme?
 *
 * custom_logo lives in theme mods, which are stored per theme in an option
 * named theme_mods_{stylesheet}. Checking only the active theme would report
 * another theme's logo as unused, and switching back to that theme would find
 * its logo deleted.
 */
function wpmj_is_theme_logo( $att_id ) {
	global $wpdb;
	$att_id = (int) $att_id;

	if ( (int) get_theme_mod( 'custom_logo' ) === $att_id ) return true;

	$rows = $wpdb->get_col( $wpdb->prepare(
		"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'theme_mods_' ) . '%'
	) );
	foreach ( $rows as $raw ) {
		$mods = maybe_unserialize( $raw );
		if ( is_array( $mods ) && isset( $mods['custom_logo'] ) && (int) $mods['custom_logo'] === $att_id ) {
			return true;
		}
	}
	return false;
}

/**
 * Build "col LIKE %s OR col LIKE %s ..." plus the matching argument list.
 * One query with N OR'd predicates costs about one table scan, not N of them,
 * so covering every size variant does not multiply the scan cost.
 */
function wpmj_paths_like_clause( $col, $paths ) {
	global $wpdb;
	$where = [];
	$args  = [];
	foreach ( $paths as $p ) {
		$where[] = "{$col} LIKE %s";
		$args[]  = '%' . $wpdb->esc_like( $p ) . '%';
		// The JSON-escaped form used inside block attributes and JSON meta.
		$esc = str_replace( '/', '\/', $p );
		if ( $esc !== $p ) {
			$where[] = "{$col} LIKE %s";
			$args[]  = '%' . $wpdb->esc_like( $esc ) . '%';
		}
	}
	return [ '( ' . implode( ' OR ', $where ) . ' )', $args ];
}

// ---------------------------------------------------------------------------
// Reference checking - is an attachment used anywhere?
// ---------------------------------------------------------------------------
function wpmj_is_referenced( $att_id ) {
	global $wpdb;

	// 1. Featured image (_thumbnail_id)
	$thumb_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1",
		$att_id
	) );
	if ( $thumb_count > 0 ) return true;

	// 2. Post parent (directly attached to a post)
	$parent = (int) get_post_field( 'post_parent', $att_id );
	if ( $parent > 0 ) {
		$parent_exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
			$parent
		) );
		if ( $parent_exists > 0 ) return true;
	}

	// 3. Site icon and site logo, both of which hold a bare attachment ID.
	//
	// They are stored DIFFERENTLY and that difference matters: site_icon is a
	// real option, but custom_logo is a THEME MOD. get_option('custom_logo')
	// returns false on every WordPress install ever made, so reading it that way
	// meant the site's logo was never recognised and would be reported unused
	// and trashed. Theme mods are also per-theme, so every installed theme's
	// logo is checked, not just the active one - switching themes back must not
	// find the old logo deleted.
	if ( (int) get_option( 'site_icon' ) === (int) $att_id ) return true;
	if ( wpmj_is_theme_logo( $att_id ) ) return true;

	// 4. ID-based references in post content. Gutenberg writes
	// <!-- wp:image {"id":123} --> and class="wp-image-123" with no inline URL,
	// and galleries and shortcodes carry bare IDs. Scoped to post_content on
	// purpose - matching an ID pattern across all of postmeta produces false
	// "referenced" results on unrelated numeric meta.
	// Each pattern is anchored on the character that must follow the ID.
	// Leaving them open-ended made "wp-image-217" match "wp-image-21708", so an
	// attachment was reported referenced because of a completely different one -
	// and that gets steadily more likely as IDs grow.
	$aid = (int) $att_id;

	// The requirement is simply "this ID, not a longer one that starts with it",
	// so express that directly with a boundary rather than enumerating the
	// characters that may follow.
	//
	// An earlier version listed the delimiters explicitly - quote, space,
	// comma, brace. That fixed prefix matching (wp-image-217 no longer matching
	// wp-image-21708) but silently created the worse bug in the other
	// direction: a newline or tab after the class, a pretty-printed "id": 123,
	// or an unquoted attribute all stopped matching, and a MISSED reference
	// deletes a live image, where a false positive merely keeps a dead one.
	//
	// REGEXP costs about what the LIKE scans beside it already cost, since
	// neither can use an index on a longtext column.
	$id_where = [];
	$id_args  = [];

	// class="… wp-image-123 …" in any quoting or whitespace.
	$id_where[] = 'post_content REGEXP %s';
	$id_args[]  = 'wp-image-' . $aid . '([^0-9]|$)';

	// Block attributes and any JSON: {"id":123}, {"id": 123, …}, pretty-printed.
	$id_where[] = 'post_content REGEXP %s';
	$id_args[]  = '"id"[[:space:]]*:[[:space:]]*' . $aid . '([^0-9]|$)';

	// Gallery, playlist and any id list, with the ID at ANY position.
	//
	// Accepts = and :, an optional closing quote after the key, and [ as an
	// opening delimiter, because the Gutenberg gallery block serializes as
	// {"ids":[45,123]} - no equals sign, no URL anywhere, and it was invisible.
	// The leading boundary stops attribute names that merely END in "ids"
	// (data-ids, category_ids, author_ids) from counting as media references.
	//
	// WordPress' shortcode parser accepts double quotes, single quotes, no
	// quotes at all, whitespace around the equals sign, and the documented
	// "include" attribute alongside "ids" - and all of those render a live
	// image. Hardcoding ids=" missed four of the five real forms, and a gallery
	// is the one reference that carries NO URL, so this regex is the only thing
	// standing between it and deletion.
	//
	// ([0-9]+,)* consumes whole earlier IDs, so a match can only begin right
	// after the opening delimiter or right after a comma - which keeps
	// ids="9123" from matching 123, and keeps a thousands-separated number in
	// ordinary prose out of it entirely. The closing class accepts ] and
	// whitespace too, because [gallery ids=1645] ends with a bracket.
	$id_where[] = 'post_content REGEXP %s';
	$id_args[]  = '(^|[^a-zA-Z0-9_-])(ids|include)["\']?[[:space:]]*[=:][[:space:]]*["\'[]?[[:space:]]*'
		. '([0-9]+[[:space:]]*,[[:space:]]*)*' . $aid . '[[:space:]]*[],"\'[:space:]]';
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $id_where holds only literal fragments ending in %s placeholders; every value goes through prepare() in $id_args.
	$in_ids = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT 1 FROM {$wpdb->posts}
		 WHERE post_type NOT IN ('revision','attachment')
		   AND post_status != 'auto-draft'
		   AND ( " . implode( ' OR ', $id_where ) . ' ) LIMIT 1',
		$id_args
	) );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	// A query error returns null, which casts to 0, which reads as "no reference"
	// and continues toward deletion. Because all three patterns are OR'd into
	// one query, a single failure would switch off ID detection for the entire
	// scan. An unanswerable question about a reference resolves to referenced.
	if ( $wpdb->last_error ) return true;
	if ( $in_ids > 0 ) return true;

	// Every URL path this attachment can be referenced by.
	$paths = wpmj_attachment_paths( $att_id );
	if ( ! $paths ) {
		// Still give the filter its say. Returning early here switched off the
		// plugin's own escape hatch for exactly the attachments whose URLs will
		// not resolve - the ones most likely to need protecting by hand.
		return (bool) apply_filters( 'wpmj_is_referenced', false, $att_id, [] );
	}

	// 5. Post content
	list( $clause, $args ) = wpmj_paths_like_clause( 'post_content', $paths );
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $clause comes from wpmj_paths_like_clause(), which emits only "col LIKE %s" with a hardcoded column; every value is escaped and passed through prepare() in $args.
	$in_content = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT 1 FROM {$wpdb->posts}
		 WHERE post_type NOT IN ('revision','attachment')
		   AND post_status != 'auto-draft'
		   AND {$clause} LIMIT 1",
		$args
	) );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	if ( $in_content > 0 ) return true;

	// 6. Postmeta values (ACF, Elementor, plain-text builders, etc.).
	// The attachment's own rows are excluded so it cannot reference itself.
	list( $clause, $args ) = wpmj_paths_like_clause( 'meta_value', $paths );
	$args[] = (int) $att_id;
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $clause comes from wpmj_paths_like_clause(), which emits only "col LIKE %s" with a hardcoded column; every value is escaped and passed through prepare() in $args.
	$in_meta = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT 1 FROM {$wpdb->postmeta} WHERE {$clause} AND post_id != %d LIMIT 1",
		$args
	) );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	if ( $in_meta > 0 ) return true;

	// 7. Term meta, user meta and comment meta - category images, author
	// avatars, profile fields and comment attachments all live here.
	foreach ( [ $wpdb->termmeta, $wpdb->usermeta, $wpdb->commentmeta ] as $meta_table ) {
		list( $clause, $args ) = wpmj_paths_like_clause( 'meta_value', $paths );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $clause comes from wpmj_paths_like_clause(), which emits only "col LIKE %s" with a hardcoded column; every value is escaped and passed through prepare() in $args.
		$hit = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$meta_table} WHERE {$clause} LIMIT 1",
			$args
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( $hit > 0 ) return true;
	}

	// 8. Comment content
	list( $clause, $args ) = wpmj_paths_like_clause( 'comment_content', $paths );
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $clause comes from wpmj_paths_like_clause(), which emits only "col LIKE %s" with a hardcoded column; every value is escaped and passed through prepare() in $args.
	$in_comments = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT 1 FROM {$wpdb->comments} WHERE {$clause} LIMIT 1",
		$args
	) );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	if ( $in_comments > 0 ) return true;

	// 9. Oxygen Builder - base64-encoded shortcodes/JSON in postmeta + options
	foreach ( $paths as $p ) {
		if ( wpmj_in_oxygen_data( $p ) ) return true;
	}

	// 10. WooCommerce product gallery (comma-separated IDs)
	$in_gallery = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT 1 FROM {$wpdb->postmeta}
		 WHERE meta_key = '_product_image_gallery'
		   AND FIND_IN_SET(%s, meta_value) > 0
		 LIMIT 1",
		$att_id
	) );
	if ( $in_gallery > 0 ) return true;

	// 11. Options table (widgets, theme mods, customizer)
	list( $clause, $args ) = wpmj_paths_like_clause( 'option_value', $paths );
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $clause comes from wpmj_paths_like_clause(), which emits only "col LIKE %s" with a hardcoded column; every value is escaped and passed through prepare() in $args.
	$in_options = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT 1 FROM {$wpdb->options} WHERE {$clause} LIMIT 1",
		$args
	) );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	if ( $in_options > 0 ) return true;

	/**
	 * Last word on whether an attachment counts as referenced.
	 *
	 * Media Mage cannot see references in theme or plugin PHP, in external
	 * CDN rewrites, or in any store it does not know about. This filter is how
	 * a site protects those without editing the plugin.
	 *
	 * @param bool $referenced False - nothing above matched.
	 * @param int  $att_id     Attachment ID.
	 * @param array $paths     Every URL path checked for this attachment.
	 */
	return (bool) apply_filters( 'wpmj_is_referenced', false, $att_id, $paths );
}

// ---------------------------------------------------------------------------
// Finalize scan results into the results transient
// ---------------------------------------------------------------------------
function wpmj_finalize_results( $state ) {
	global $wpdb;

	// Duplicate groups come out of postmeta with one GROUP BY, rather than out
	// of a hash map carried through the whole scan.
	//
	// That map WAS the scan state. At 20,000 attachments it reached 1.06 MB and
	// was re-serialized and re-written on every one of ~1,200 chunks - about
	// 1.17 GB of writes through a single wp_options row. Worse, once it crossed
	// max_allowed_packet (or memcached's hard 1 MB item limit, at roughly 18,800
	// attachments on ANY host), set_transient simply returned false: the state
	// froze, the cursor kept advancing, and every attachment scanned past that
	// point was silently discarded from both the duplicate groups and the unused
	// list, with the progress bar stuck and no error anywhere.
	//
	// The hash is already written to postmeta for caching, so carrying it twice
	// bought nothing. The state is now a few counters and two ID lists.
	// Two queries rather than one GROUP_CONCAT.
	//
	// GROUP_CONCAT silently truncates at group_concat_max_len - 1024 bytes by
	// default on MySQL, about 170 attachment IDs - and it truncates mid-value,
	// so a large duplicate group would not merely lose members, it would hand a
	// DELETE operation a number cut in half: a different, unrelated attachment.
	// Finding the duplicated hashes and then fetching their IDs has no limit.
	$missing_ids = array_map( 'intval', (array) ( $state['missing'] ?? [] ) );

	$dup_hashes = $wpdb->get_col( $wpdb->prepare(
		"SELECT m.meta_value
		 FROM {$wpdb->postmeta} m
		 JOIN {$wpdb->posts} p ON p.ID = m.post_id
		 WHERE m.meta_key = %s
		   AND p.post_type = 'attachment'
		   AND p.post_status != 'trash'
		 GROUP BY m.meta_value
		 HAVING COUNT(*) >= 2",
		WPMJ_META_HASH
	) );

	$duplicates = [];
	foreach ( (array) $dup_hashes as $hash ) {
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT m.post_id
			 FROM {$wpdb->postmeta} m
			 JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_key = %s AND m.meta_value = %s
			   AND p.post_type = 'attachment'
			   AND p.post_status != 'trash'
			 ORDER BY m.post_id ASC",
			WPMJ_META_HASH,
			$hash
		) ) );
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		// Anything this scan reported as missing is dropped, whatever postmeta
		// still says. A group is only meaningful for files that exist.
		if ( $missing_ids ) $ids = array_values( array_diff( $ids, $missing_ids ) );
		if ( count( $ids ) >= 2 ) {
			$duplicates[] = [ 'hash' => (string) $hash, 'ids' => $ids ];
		}
	}

	// Anything on the ignore list never reaches the results.
	$ignored = wpmj_ignored_ids();
	$unused  = array_values( array_unique( array_map( 'intval', (array) ( $state['unused'] ?? [] ) ) ) );
	$unused  = array_values( array_diff( $unused, $ignored ) );

	$results = [
		'duplicates'    => $duplicates,
		'unused'        => $unused,
		'missing'       => $state['missing'] ?? [],
		'ignored_count' => count( $ignored ),
		'scanned_at'    => current_time( 'mysql' ),
	];

	set_transient( WPMJ_TRANSIENT_RESULTS, $results, WPMJ_RESULTS_TTL );
}

// ---------------------------------------------------------------------------
// get_results - return enriched scan results for display
// ---------------------------------------------------------------------------
function wpmj_ajax_get_results() {
	wpmj_auth();
	// The one heavy handler that was still bound by max_execution_time. It
	// enriches every result item, so on a large unused list it hit the limit and
	// returned an HTML fatal-error page - which is not JSON, so the user saw
	// "Invalid response from server" after a scan that may have run for hours.
	wpmj_raise_limits();

	$results = get_transient( WPMJ_TRANSIENT_RESULTS );
	if ( ! $results ) wp_send_json_error( [ 'message' => __( 'No scan results. Run a scan first.', 'media-mage' ) ] );

	// Prime the post and meta caches for everything about to be enriched.
	// Without this, each item costs its own get_post + get_post_meta round
	// trip; with it, WordPress fetches all of them in two queries and every
	// later lookup is served from memory.
	$prime_ids = array_map( 'intval', (array) ( $results['unused'] ?? [] ) );
	foreach ( (array) $results['duplicates'] as $group ) {
		foreach ( (array) $group['ids'] as $gid ) $prime_ids[] = (int) $gid;
	}
	$prime_ids = array_values( array_unique( array_filter( $prime_ids ) ) );
	if ( $prime_ids && function_exists( '_prime_post_caches' ) ) {
		// Private WordPress API, stable since 3.4 and used throughout core, but
		// guarded because this is an optimisation - losing it should cost speed,
		// not throw a fatal on a future WordPress.
		_prime_post_caches( $prime_ids, false, true );
	}

	// Enrich duplicates
	$dup_groups = [];
	foreach ( $results['duplicates'] as $group ) {
		$items = [];
		foreach ( $group['ids'] as $att_id ) {
			$items[] = wpmj_enrich_attachment( $att_id );
		}
		// Sort: most references first (suggest keeping the most-referenced one)
		usort( $items, function( $a, $b ) { return $b['ref_count'] - $a['ref_count']; } );
		$dup_groups[] = [ 'hash' => $group['hash'], 'items' => $items ];
	}

	// Enrich unused.
	//
	// Deliberately WITHOUT reference details. An item is on this list precisely
	// because the reference phase found nothing pointing at it, so its count is
	// zero by definition and recomputing it costs several unindexable LIKE
	// scans per row for an answer already known. On a library with thousands of
	// unused files that recomputation was the whole cost of this request.
	$unused = [];
	foreach ( $results['unused'] as $att_id ) {
		$unused[] = wpmj_enrich_attachment( $att_id, false );
	}

	// Calculate reclaimable disk space
	// Duplicates: (count - 1) * bytes per group (keeper stays, rest go)
	// Unused: sum of all bytes
	$reclaimable = 0;
	$dup_count = 0;
	foreach ( $dup_groups as $group ) {
		$n = count( $group['items'] );
		if ( $n < 2 ) continue;
		$reclaimable += ( $n - 1 ) * (int) $group['items'][0]['bytes'];
		$dup_count   += ( $n - 1 );
	}
	foreach ( $unused as $u ) {
		$reclaimable += (int) $u['bytes'];
	}

	global $wpdb;
	$trashed_count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
		 WHERE p.post_type = 'attachment' AND p.post_status = 'trash'",
		'_wpmj_trashed_at'
	) );

	wp_send_json_success( [
		'duplicates'             => $dup_groups,
		'duplicate_groups'       => count( $dup_groups ),
		'duplicate_count'        => $dup_count,
		'unused'                 => $unused,
		'unused_count'           => count( $unused ),
		'missing_count'          => count( $results['missing'] ?? [] ),
		'ignored_count'          => (int) ( $results['ignored_count'] ?? 0 ),
		'trashed_count'          => $trashed_count,
		'library_total'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash'" ),
		'scanned_at'             => $results['scanned_at'],
		'reclaimable_bytes'      => $reclaimable,
		'reclaimable_human'      => size_format( $reclaimable, 1 ) ?: '0 B',
		// NOT wp_nonce_url(): that runs the result through esc_html(), so the
		// separator comes back as "&#038;". This URL is handed to JavaScript as
		// JSON and escaped again on its way into an href, at which point the
		// entity survives as literal text and the nonce parameter arrives named
		// "#038;_wpnonce" - so the export 403s. Build it raw and let the one
		// escaping step at render time do the work.
		'export_url'             => add_query_arg(
			[
				'action'   => 'wpmj_export_csv',
				'_wpnonce' => wp_create_nonce( WPMJ_NONCE_ACTION ),
			],
			admin_url( 'admin-post.php' )
		),
	] );
}

// ---------------------------------------------------------------------------
// Enrich an attachment with display data
// ---------------------------------------------------------------------------
function wpmj_enrich_attachment( $att_id, $with_refs = true ) {
	$meta     = wp_get_attachment_metadata( $att_id );
	$file     = get_attached_file( $att_id );
	$filesize = 0;

	if ( ! empty( $meta['filesize'] ) ) {
		$filesize = $meta['filesize'];
	} elseif ( $file && file_exists( $file ) ) {
		$filesize = filesize( $file );
	}

	$dimensions = '';
	if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
		$dimensions = $meta['width'] . ' x ' . $meta['height'];
	}

	$refs = $with_refs ? wpmj_reference_details( $att_id ) : [ 'count' => 0, 'posts' => [] ];

	return [
		'id'         => (int) $att_id,
		'title'      => get_the_title( $att_id ),
		'filename'   => $file ? basename( $file ) : '(unknown)',
		'url'        => wp_get_attachment_url( $att_id ),
		'thumb'      => wp_get_attachment_image_url( $att_id, 'thumbnail' ) ?: '',
		'size'       => size_format( $filesize ),
		'bytes'      => (int) $filesize,
		'dimensions' => $dimensions,
		'date'       => get_the_date( 'Y-m-d', $att_id ),
		'mime'       => get_post_mime_type( $att_id ),
		'ref_count'  => (int) $refs['count'],
		'ref_posts'  => $refs['posts'],
		'ignored'    => (bool) get_post_meta( $att_id, WPMJ_META_IGNORED, true ),
		'parent'     => (int) wp_get_post_parent_id( $att_id ),
	];
}

// ---------------------------------------------------------------------------
// Count references to an attachment (for display)
// ---------------------------------------------------------------------------
/**
 * Where is this file actually used?
 *
 * The queries that produce a reference COUNT already know which rows matched.
 * Returning only the integer threw that away, and the count is the whole basis
 * on which a user decides which copy of a duplicate to keep. Returning the
 * posts turns "trust me, this is unused" into evidence the user can check.
 *
 * @return array{count:int,posts:array<int,array{id:int,title:string,type:string,via:string,edit:string}>}
 */
function wpmj_reference_details( $att_id ) {
	global $wpdb;

	$count = 0;
	$posts = [];
	$seen  = [];

	$add = function ( $rows, $via ) use ( &$posts, &$seen, &$count ) {
		foreach ( (array) $rows as $row ) {
			$count++;
			$id = (int) $row->ID;
			if ( isset( $seen[ $id ] ) || count( $posts ) >= 10 ) continue;
			$seen[ $id ] = true;
			$posts[] = [
				'id'    => $id,
				'title' => $row->post_title !== '' ? $row->post_title : sprintf( '#%d', $id ),
				'type'  => (string) $row->post_type,
				'via'   => $via,
				'edit'  => get_edit_post_link( $id, 'raw' ) ?: '',
			];
		}
	};

	// Featured images
	$add( $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, p.post_title, p.post_type FROM {$wpdb->postmeta} m
		 JOIN {$wpdb->posts} p ON p.ID = m.post_id
		 WHERE m.meta_key = '_thumbnail_id' AND m.meta_value = %s LIMIT 25",
		$att_id
	) ), __( 'featured image', 'media-mage' ) );

	$paths = wpmj_attachment_paths( $att_id );

	// Content references, across every size variant.
	if ( $paths ) {
		list( $clause, $args ) = wpmj_paths_like_clause( 'post_content', $paths );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $clause comes from wpmj_paths_like_clause(), which emits only "col LIKE %s" with a hardcoded column; every value is escaped and passed through prepare() in $args.
		$add( $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_type FROM {$wpdb->posts}
			 WHERE post_type NOT IN ('revision','attachment')
			   AND post_status != 'auto-draft'
			   AND {$clause} LIMIT 25",
			$args
		) ), __( 'content', 'media-mage' ) );
	}

	// Post meta (ACF, builders, custom fields).
	if ( $paths ) {
		list( $clause, $args ) = wpmj_paths_like_clause( 'm.meta_value', $paths );
		$args[] = (int) $att_id;
		$add( $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_title, p.post_type FROM {$wpdb->postmeta} m
			 JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE {$clause} AND m.post_id != %d
			   AND p.post_type NOT IN ('revision','attachment') LIMIT 25",
			$args
		) ), __( 'custom field', 'media-mage' ) );
	}

	// WooCommerce gallery
	$add( $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, p.post_title, p.post_type FROM {$wpdb->postmeta} m
		 JOIN {$wpdb->posts} p ON p.ID = m.post_id
		 WHERE m.meta_key = '_product_image_gallery'
		   AND FIND_IN_SET(%s, m.meta_value) > 0 LIMIT 25",
		$att_id
	) ), __( 'product gallery', 'media-mage' ) );

	// Oxygen Builder (base64-encoded data). No row to link to, so it counts
	// without contributing a post.
	foreach ( $paths as $p ) {
		if ( wpmj_in_oxygen_data( $p ) ) { $count++; break; }
	}

	// Site identity. custom_logo is a theme mod, not an option - see
	// wpmj_is_theme_logo(). Reading it with get_option() here had the same bug
	// as the detection path, so the two would have disagreed either way.
	if ( (int) get_option( 'site_icon' ) === (int) $att_id ) {
		$count++;
		if ( count( $posts ) < 10 ) {
			$posts[] = [ 'id' => 0, 'title' => __( 'site icon', 'media-mage' ), 'type' => 'option', 'via' => __( 'site icon', 'media-mage' ), 'edit' => '' ];
		}
	}
	if ( wpmj_is_theme_logo( $att_id ) ) {
		$count++;
		if ( count( $posts ) < 10 ) {
			$posts[] = [ 'id' => 0, 'title' => __( 'site logo', 'media-mage' ), 'type' => 'option', 'via' => __( 'site logo', 'media-mage' ), 'edit' => '' ];
		}
	}

	return [ 'count' => $count, 'posts' => $posts ];
}

/** Back-compat wrapper - the integer on its own. */
function wpmj_count_references( $att_id ) {
	$d = wpmj_reference_details( $att_id );
	return (int) $d['count'];
}

// ---------------------------------------------------------------------------
// resolve_duplicate - re-point refs from duplicates to keeper, then delete
// ---------------------------------------------------------------------------
function wpmj_ajax_resolve_duplicate() {
	wpmj_auth();
	wpmj_raise_limits();
	global $wpdb;

	$keeper_id = wpmj_post_int( 'keeper_id' );
	$dup_ids   = wpmj_post_ids( 'duplicate_ids' );

	if ( ! $keeper_id || empty( $dup_ids ) ) {
		wp_send_json_error( [ 'message' => __( 'Missing keeper_id or duplicate_ids.', 'media-mage' ) ] );
	}

	// Verify keeper exists
	if ( get_post_type( $keeper_id ) !== 'attachment' ) {
		wp_send_json_error( [
			'message' => sprintf(
				/* translators: %d is the attachment ID that could not be found */
				__( 'Keeper attachment #%d not found.', 'media-mage' ),
				$keeper_id
			),
		] );
	}

	// Validate against the stored results, exactly as delete_unused does.
	//
	// This handler force-deletes attachments AND runs a site-wide search and
	// replace across posts, postmeta and options, so it must not act on a set
	// of IDs it has not just verified. Keeper and duplicates have to live in
	// the same scanned group - that is what proves they are byte-identical.
	$results = get_transient( WPMJ_TRANSIENT_RESULTS );
	if ( ! $results || empty( $results['duplicates'] ) ) {
		wp_send_json_error( [
			'message' => __( 'Scan results have expired. Re-scan before resolving duplicates.', 'media-mage' ),
			'code'    => 'results_expired',
		] );
	}

	$group_ids = [];
	foreach ( $results['duplicates'] as $group ) {
		$ids = array_map( 'intval', (array) $group['ids'] );
		if ( in_array( $keeper_id, $ids, true ) ) { $group_ids = $ids; break; }
	}
	if ( ! $group_ids ) {
		wp_send_json_error( [
			'message' => __( 'That keeper is not in the current duplicate results. Re-scan and try again.', 'media-mage' ),
			'code'    => 'not_in_results',
		] );
	}

	$dup_ids = array_values( array_intersect( $dup_ids, $group_ids ) );
	$dup_ids = array_values( array_diff( $dup_ids, [ $keeper_id ] ) );
	if ( empty( $dup_ids ) ) {
		wp_send_json_error( [
			'message' => __( 'None of those duplicates are in the keeper\'s group. Re-scan and try again.', 'media-mage' ),
			'code'    => 'not_in_results',
		] );
	}

	$keeper_url   = wp_get_attachment_url( $keeper_id );
	$refs_updated = 0;
	$deleted      = 0;
	$touched_posts = [];

	$blocked = [];

	foreach ( $dup_ids as $dup_id ) {
		if ( get_post_type( $dup_id ) !== 'attachment' ) continue;

		// Verify the pair really is identical before rewriting or deleting.
		// The group is a proposal built from cached hashes, and a cache keyed on
		// timestamps can be wrong about content restored from a backup - rsync
		// -t and cp -p preserve mtime, and a same-size replacement defeats a
		// size check too. Hashing two files costs nothing next to permanently
		// deleting the wrong one.
		if ( ! wpmj_files_identical( $keeper_id, $dup_id ) ) {
			// Report which of the two it was. "References could not be
			// rewritten" is simply untrue here - the references were fine, the
			// files are not the same any more, or could not be read at all.
			$kf     = get_attached_file( $keeper_id );
			$df     = get_attached_file( $dup_id );
			$gone   = ! $kf || ! $df || ! is_readable( (string) $kf ) || ! is_readable( (string) $df );
			$blocked[] = [
				'id'   => (int) $dup_id,
				'file' => basename( (string) $df ),
				'rows' => [ $gone ? 'file-unavailable' : 'not-identical' ],
			];
			continue;
		}

		// wpmj_repoint_references() resets and populates the guard itself.
		$refs_updated += wpmj_repoint_references( $dup_id, $keeper_id, $touched_posts );

		// Deleting now would leave a live reference pointing at a file that no
		// longer exists, and report success while doing it. That is the same
		// half-migrated-in-silence failure the rest of this handler exists to
		// avoid, so the duplicate stays and the user is told which rows to look
		// at. Its references have already been re-pointed where that was
		// possible, which is safe: they now point at an identical file.
		if ( ! empty( $GLOBALS['wpmj_unrewritable'] ) ) {
			$blocked[] = [
				'id'   => (int) $dup_id,
				'file' => basename( (string) get_attached_file( $dup_id ) ),
				'rows' => array_values( array_unique( $GLOBALS['wpmj_unrewritable'] ) ),
			];
			continue;
		}

		wp_delete_attachment( $dup_id, true );
		$deleted++;

		// The Oxygen blob was built from data that included this attachment.
		// Resolve All processes several groups in one request, so a stale blob
		// would have later groups matching against a file that no longer exists.
		wpmj_oxygen_cache( true );
	}

	// Invalidate only what was touched.
	//
	// wp_cache_flush() empties the ENTIRE object cache. On Redis or Memcached
	// hosting, "Resolve All" over 40 groups flushed every other plugin's and
	// every other visitor's cached data 40 times over. Targeted invalidation
	// costs nothing and stays inside this plugin's blast radius.
	clean_post_cache( $keeper_id );
	foreach ( $dup_ids as $dup_id ) {
		clean_post_cache( $dup_id );
	}
	foreach ( array_unique( $touched_posts ) as $post_id ) {
		clean_post_cache( $post_id );
	}
	// Option values were rewritten by direct query, so the options cache is now
	// stale regardless of which rows changed.
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );

	// Update results transient
	$results = get_transient( WPMJ_TRANSIENT_RESULTS );
	if ( $results ) {
		// Blocked duplicates were not deleted, so they must stay in the results -
		// stripping them made the group vanish and every retry answer
		// not_in_results until a full re-scan.
		$dup_ids = array_values( array_diff( $dup_ids, wp_list_pluck( $blocked, 'id' ) ) );

		$results['duplicates'] = array_values( array_filter( $results['duplicates'], function( $group ) use ( $dup_ids ) {
			$remaining = array_diff( $group['ids'], $dup_ids );
			return count( $remaining ) >= 2;
		} ) );
		// Also update IDs in remaining groups
		foreach ( $results['duplicates'] as &$group ) {
			$group['ids'] = array_values( array_diff( $group['ids'], $dup_ids ) );
		}
		set_transient( WPMJ_TRANSIENT_RESULTS, $results, WPMJ_RESULTS_TTL );
	}

	wp_send_json_success( [
		'deleted'      => $deleted,
		'refs_updated' => $refs_updated,
		'keeper'       => $keeper_id,
		'blocked'      => $blocked,
	] );
}

/**
 * Are these two attachments byte-identical RIGHT NOW?
 *
 * A duplicate group is a proposal built from cached hashes, and that cache is
 * keyed on the filesystem timestamp. Timestamps lie: restoring uploads with
 * rsync -t or cp -p changes content while leaving mtime untouched, and a
 * same-size replacement defeats a size check too. Hashing two files costs
 * nothing next to permanently deleting the wrong one, so the delete paths
 * verify rather than trusting the proposal.
 */
function wpmj_files_identical( $a_id, $b_id ) {
	$a = get_attached_file( $a_id );
	$b = get_attached_file( $b_id );

	// Readability, not just existence. filesize() is stat-based and succeeds
	// without read permission, so an unreadable pair used to sail past it.
	if ( ! $a || ! $b || ! is_readable( $a ) || ! is_readable( $b ) ) return false;

	$size_a = @filesize( $a );
	$size_b = @filesize( $b );
	if ( $size_a === false || $size_b === false || $size_a !== $size_b ) return false;

	$hash_a = @md5_file( $a );
	$hash_b = @md5_file( $b );

	// md5_file() returns false on a file it cannot open. Comparing the results
	// directly made false === false evaluate TRUE, so a pair of unreadable
	// files was declared identical and the duplicate was force-deleted - the
	// guard approving a delete in precisely the case it exists to refuse.
	// Unverifiable is not identical.
	if ( $hash_a === false || $hash_b === false ) return false;

	return $hash_a === $hash_b;
}

// ---------------------------------------------------------------------------
// Re-point every reference from a duplicate to the keeper
// ---------------------------------------------------------------------------
/**
 * One implementation, used by both the admin resolve handler and WP-CLI.
 *
 * These were two separate copies and they had already drifted: the CLI version
 * had quietly lost the WooCommerce gallery re-point, so resolving on a Woo site
 * from the command line left product galleries pointing at a deleted
 * attachment. A single function is the only way that stays fixed.
 *
 * @param int   $dup_id        Attachment being replaced.
 * @param int   $keeper_id     Attachment to point everything at.
 * @param array $touched_posts Collects post IDs whose caches need clearing.
 * @return int Number of references rewritten.
 */
function wpmj_repoint_references( $dup_id, $keeper_id, &$touched_posts = null ) {
	global $wpdb;

	$dup_id    = (int) $dup_id;
	$keeper_id = (int) $keeper_id;

	// Reset here, not in the caller. It used to be the caller's job and the
	// WP-CLI path never did it, so entries accumulated across a whole run: the
	// first duplicate that hit an unrewritable row blocked every later one and
	// blamed that same unrelated row. A guard a caller can forget is not a guard.
	$GLOBALS['wpmj_unrewritable'] = [];

	if ( ! $dup_id || ! $keeper_id || $dup_id === $keeper_id ) return 0;

	$pairs = wpmj_build_replacement_pairs( $dup_id, $keeper_id );
	if ( ! $pairs ) return 0;

	if ( ! is_array( $touched_posts ) ) $touched_posts = [];
	$updated = 0;

	// 1. Featured images.
	$updated += (int) $wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = '_thumbnail_id' AND meta_value = %s",
		$keeper_id, $dup_id
	) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

	// 2. Post content. Pairs are ordered longest-first so a shorter string can
	// never consume part of a longer one. Affected IDs are collected before the
	// UPDATE because a direct write leaves WordPress serving stale content.
	foreach ( $pairs as $pair ) {
		$like = '%' . $wpdb->esc_like( $pair['old'] ) . '%';

		$hit_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s",
			$like
		) );
		if ( ! $hit_ids ) continue;

		$updated += (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
			$pair['old'], $pair['new'], $like
		) );
		foreach ( $hit_ids as $hid ) $touched_posts[] = (int) $hid;
	}

	// 3 and 4. Post meta and options, serialization-aware.
	$updated += wpmj_replace_in_meta( $wpdb->postmeta, 'meta_value', $pairs, $dup_id, $touched_posts );
	$updated += wpmj_replace_in_meta( $wpdb->options, 'option_value', $pairs );

	// Options were rewritten by direct query, so the options cache is stale NOW -
	// before the Oxygen step, which reads ct_style_sheets with get_option().
	// Invalidating afterwards meant that step read the pre-rewrite value, fired
	// its guard on a row that was already correct in the database, and then
	// wrote the whole stale array back, UNDOING the rewrite immediately above.
	// It also lives here rather than in the AJAX handler because the CLI path
	// calls this same function.
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );

	// 5. Oxygen Builder, which stores its layout base64-encoded.
	$updated += wpmj_replace_in_oxygen( $pairs, $touched_posts );

	// 6. WooCommerce product galleries, which hold comma-separated IDs.
	$galleries = $wpdb->get_results( $wpdb->prepare(
		"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key = '_product_image_gallery' AND FIND_IN_SET(%s, meta_value) > 0",
		$dup_id
	) );
	foreach ( $galleries as $row ) {
		$ids = array_map( 'trim', explode( ',', $row->meta_value ) );
		$ids = array_map( function ( $id ) use ( $dup_id, $keeper_id ) {
			return (int) $id === $dup_id ? (string) $keeper_id : $id;
		}, $ids );
		// A gallery that already contained the keeper would end up listing it
		// twice, which WooCommerce renders as a duplicated slide.
		$ids = array_values( array_unique( $ids ) );
		$wpdb->update( $wpdb->postmeta, [ 'meta_value' => implode( ',', $ids ) ], [ 'meta_id' => $row->meta_id ] );
		$touched_posts[] = (int) $row->post_id;
		$updated++;
	}

	return $updated;
}

/**
 * Does this raw value mention any of the strings being replaced?
 *
 * Used to decide whether a row the rewrite could not process actually matters.
 * A row it cannot touch AND does not reference is not a problem; one it cannot
 * touch but DOES reference has to block the delete.
 */
function wpmj_value_contains_pair( $value, $pairs ) {
	if ( ! is_string( $value ) || $value === '' ) return false;
	foreach ( (array) $pairs as $p ) {
		if ( isset( $p['old'] ) && $p['old'] !== '' && strpos( $value, $p['old'] ) !== false ) {
			return true;
		}
	}
	return false;
}

// ---------------------------------------------------------------------------
// Oxygen Builder stores its layout base64-encoded, so a plain replace misses it
// ---------------------------------------------------------------------------
/**
 * Decode Oxygen's builder data, rewrite URLs inside it, and re-encode.
 *
 * Detection already decodes these keys, which is why an Oxygen site's images
 * were correctly recognised as in use. The REWRITE did not: it ran a
 * LIKE '%<plain url>%' against a base64 blob, which cannot match. So resolving
 * a duplicate on an Oxygen site deleted a file the layout still pointed at -
 * on the exact use case the plugin was written for.
 *
 * A value is only rewritten when it round-trips through base64 exactly, so
 * anything that merely looks like base64 is left alone.
 */
function wpmj_replace_in_oxygen( $pairs, &$touched_posts = null ) {
	global $wpdb;
	if ( empty( $pairs ) ) return 0;

	$updated = 0;

	$apply = function ( $decoded ) use ( $pairs ) {
		foreach ( $pairs as $p ) {
			$decoded = str_replace( $p['old'], $p['new'], $decoded );
		}
		return $decoded;
	};

	// 1. Post meta: _ct_builder_shortcodes and _ct_builder_json.
	$rows = $wpdb->get_results(
		"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key IN ('_ct_builder_shortcodes','_ct_builder_json') AND meta_value != ''"
	);
	foreach ( $rows as $row ) {
		$raw = (string) $row->meta_value;
		// Compared against the whitespace-stripped value, because PHP's decoder
		// ignores whitespace even in strict mode. Requiring a byte-identical
		// re-encode of the RAW value meant any row with MIME line wrapping, a
		// trailing newline or a leading space decoded fine for detection and was
		// silently skipped by the rewrite - and the skip did not reach the
		// blocked guard, so the duplicate was deleted anyway.
		$compact = preg_replace( '/\s+/', '', $raw );
		$decoded = base64_decode( $compact, true );

		if ( $decoded !== false && base64_encode( $decoded ) === $compact ) {
			$new = $apply( $decoded );
			if ( $new !== $decoded ) {
				$wpdb->update( $wpdb->postmeta, [ 'meta_value' => base64_encode( $new ) ], [ 'meta_id' => $row->meta_id ] );
				if ( is_array( $touched_posts ) ) $touched_posts[] = (int) $row->post_id;
				$updated++;
			}
		} elseif ( wpmj_value_contains_pair( $raw, $pairs ) ) {
			// Not decodable, but it names a file being re-pointed. Anything this
			// pass cannot rewrite has to reach the guard, or the duplicate gets
			// deleted out from under a live reference.
			$GLOBALS['wpmj_unrewritable'][] = "oxygen-meta#{$row->meta_id}";
		}
	}

	// Backstop: re-read the builder rows and flag any that still name a file
	// being re-pointed. Whatever the reason - an encoding this pass does not
	// understand, a value shaped unexpectedly - it must reach the guard rather
	// than let the duplicate be deleted out from under it.
	$check = $wpdb->get_results(
		"SELECT meta_id, meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key IN ('_ct_builder_shortcodes','_ct_builder_json') AND meta_value != ''"
	);
	foreach ( $check as $row ) {
		$raw     = (string) $row->meta_value;
		$decoded = base64_decode( preg_replace( '/\s+/', '', $raw ), true );
		if ( wpmj_value_contains_pair( $raw, $pairs )
			|| ( $decoded !== false && wpmj_value_contains_pair( $decoded, $pairs ) ) ) {
			$GLOBALS['wpmj_unrewritable'][] = "oxygen-meta#{$row->meta_id}";
		}
	}

	// 2. Stylesheets: a serialized array of sheets, each with base64 CSS.
	$sheets = get_option( 'ct_style_sheets' );
	if ( is_array( $sheets ) ) {
		$changed = false;
		foreach ( $sheets as $i => $sheet ) {
			if ( ! is_array( $sheet ) || empty( $sheet['css'] ) || ! is_string( $sheet['css'] ) ) continue;
			$raw = $sheet['css'];

			// Same whitespace-tolerant decode as the builder rows above. This
			// branch was missed when that was fixed, so a MIME-wrapped
			// stylesheet - which is what chunk_split and plenty of export
			// tooling produces - was silently skipped AND never reached the
			// blocked guard, so the duplicate was deleted while the site's CSS
			// still pointed at it.
			$compact = preg_replace( '/\s+/', '', $raw );
			$decoded = base64_decode( $compact, true );
			if ( $decoded === false || base64_encode( $decoded ) !== $compact ) {
				if ( wpmj_value_contains_pair( $raw, $pairs ) ) {
					$GLOBALS['wpmj_unrewritable'][] = "oxygen-sheet#{$i}";
				}
				continue;
			}

			$new = $apply( $decoded );
			if ( $new !== $decoded ) {
				$sheets[ $i ]['css'] = base64_encode( $new );
				$changed = true;
			} elseif ( wpmj_value_contains_pair( $decoded, $pairs ) ) {
				// Decoded fine but the replace did not clear it.
				$GLOBALS['wpmj_unrewritable'][] = "oxygen-sheet#{$i}";
			}
		}
		if ( $changed ) {
			update_option( 'ct_style_sheets', $sheets );
			$updated++;
		}
	}

	return $updated;
}

// ---------------------------------------------------------------------------
// Build every old->new string pair needed to re-point a duplicate at a keeper
// ---------------------------------------------------------------------------
/**
 * An attachment is referenced by more than one string. Beyond its full-size
 * URL there is one URL per generated size ("hero-300x200.jpg"), the -scaled
 * sibling WordPress makes for big uploads, and the path-only and
 * JSON-escaped-slash forms of all of the above. Rewriting only the full-size
 * URL leaves the majority of real-world references pointing at a file that is
 * about to be deleted.
 *
 * Returns a list of [ 'old' => string, 'new' => string ] ordered longest-old
 * first, so a shorter string can never eat part of a longer one.
 */
function wpmj_build_replacement_pairs( $dup_id, $keeper_id ) {
	$dup_url    = wp_get_attachment_url( $dup_id );
	$keeper_url = wp_get_attachment_url( $keeper_id );
	if ( ! $dup_url || ! $keeper_url ) return [];

	$dup_meta    = wp_get_attachment_metadata( $dup_id );
	$keeper_meta = wp_get_attachment_metadata( $keeper_id );
	$dup_base    = trailingslashit( dirname( $dup_url ) );
	$keeper_base = trailingslashit( dirname( $keeper_url ) );

	$map = [ $dup_url => $keeper_url ];

	// One pair per generated size. Prefer the keeper's same-named size; fall
	// back to a keeper size with identical dimensions (themes register sizes
	// under different names); last resort the keeper's full-size file, which
	// renders correctly even though it is heavier than the original request.
	if ( ! empty( $dup_meta['sizes'] ) && is_array( $dup_meta['sizes'] ) ) {
		foreach ( $dup_meta['sizes'] as $size_name => $size ) {
			if ( empty( $size['file'] ) ) continue;

			$new = '';
			if ( ! empty( $keeper_meta['sizes'][ $size_name ]['file'] ) ) {
				$new = $keeper_base . $keeper_meta['sizes'][ $size_name ]['file'];
			} elseif ( ! empty( $keeper_meta['sizes'] ) && is_array( $keeper_meta['sizes'] ) ) {
				foreach ( $keeper_meta['sizes'] as $ks ) {
					if ( empty( $ks['file'] ) ) continue;
					if ( (int) ( $ks['width'] ?? -1 ) === (int) ( $size['width'] ?? -2 )
						&& (int) ( $ks['height'] ?? -1 ) === (int) ( $size['height'] ?? -2 ) ) {
						$new = $keeper_base . $ks['file'];
						break;
					}
				}
			}
			if ( ! $new ) $new = $keeper_url;

			$map[ $dup_base . $size['file'] ] = $new;
		}
	}

	// The pre-scale original WordPress keeps for oversized uploads.
	if ( ! empty( $dup_meta['original_image'] ) ) {
		$map[ $dup_base . $dup_meta['original_image'] ] = ! empty( $keeper_meta['original_image'] )
			? $keeper_base . $keeper_meta['original_image']
			: $keeper_url;
	}

	// Expand each URL pair into the forms references actually take: the
	// absolute URL, the path-only form (protocol- and domain-agnostic
	// references, and anything stored after a search-replace migration), and
	// the JSON-escaped-slash form used inside Gutenberg block attributes and
	// any other JSON blob stored in meta.
	$pairs = [];
	foreach ( $map as $old => $new ) {
		if ( $old === '' || $new === '' || $old === $new ) continue;

		$forms = [ [ $old, $new ] ];

		$old_path = wp_parse_url( $old, PHP_URL_PATH );
		$new_path = wp_parse_url( $new, PHP_URL_PATH );
		if ( $old_path && $new_path && $old_path !== $new_path ) {
			$forms[] = [ $old_path, $new_path ];
		}

		foreach ( $forms as $f ) {
			$pairs[] = [ 'old' => $f[0], 'new' => $f[1] ];
			$esc_old = str_replace( '/', '\/', $f[0] );
			$esc_new = str_replace( '/', '\/', $f[1] );
			if ( $esc_old !== $f[0] ) {
				$pairs[] = [ 'old' => $esc_old, 'new' => $esc_new ];
			}
		}
	}

	// Longest old-string first, then drop duplicates by old-string.
	usort( $pairs, function ( $a, $b ) { return strlen( $b['old'] ) - strlen( $a['old'] ); } );
	$seen = [];
	$out  = [];
	foreach ( $pairs as $p ) {
		if ( isset( $seen[ $p['old'] ] ) ) continue;
		$seen[ $p['old'] ] = true;
		$out[] = $p;
	}
	return $out;
}

// ---------------------------------------------------------------------------
// Serialization-aware URL replacement in a DB table
// ---------------------------------------------------------------------------
/**
 * Apply every replacement pair to every row of $table that contains any of the
 * old strings.
 *
 * $skip_post_id excludes the duplicate's OWN postmeta rows. Those rows are
 * about to be removed with the attachment, and rewriting them to point at the
 * keeper's file before wp_delete_attachment() runs would hand WordPress the
 * keeper's path to delete.
 */
function wpmj_replace_in_meta( $table, $value_col, $pairs, $skip_post_id = 0, &$touched_posts = null ) {
	global $wpdb;
	$updated = 0;

	if ( empty( $pairs ) ) return 0;

	$is_options = ( $table === $wpdb->options );
	$pk_col     = $is_options ? 'option_id' : 'meta_id';
	$id_col     = $is_options ? 'option_id' : 'post_id';

	$where = [];
	$args  = [];
	foreach ( $pairs as $p ) {
		$where[] = "{$value_col} LIKE %s";
		$args[]  = '%' . $wpdb->esc_like( $p['old'] ) . '%';
	}

	$sql = "SELECT {$pk_col} as pk, {$id_col} as owner, {$value_col} as val FROM {$table} WHERE ( " . implode( ' OR ', $where ) . ' )';
	if ( $skip_post_id && ! $is_options ) {
		$sql   .= ' AND post_id != %d';
		$args[] = $skip_post_id;
	}

	// Bound the blast radius.
	//
	// "Every row in the database that contains this substring" is too wide. A
	// backup plugin's run log that merely NAMES the file, a cron array, the
	// rewrite rules, a transient - none of those render an image, and silently
	// editing a historical log to name a file the job never touched is its own
	// kind of data loss. Excluded by name rather than guessed at.
	if ( $is_options ) {
		$sql .= " AND option_name NOT LIKE %s AND option_name NOT LIKE %s
		          AND option_name NOT IN ('cron','rewrite_rules','recently_edited','wpmj_results','wpmj_scan_state')";
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table and the column names are $wpdb->postmeta / $wpdb->options and literals from the two call sites; $where holds only %s placeholders and every value goes through prepare().
		$args[] = $wpdb->esc_like( '_transient_' ) . '%';
		$args[] = $wpdb->esc_like( '_site_transient_' ) . '%';
	} else {
		$sql .= " AND meta_key NOT IN ('_edit_lock','_edit_last','_wp_old_slug','_wp_old_date')
		          AND meta_key NOT LIKE %s";
		$args[] = $wpdb->esc_like( '_wpmj_' ) . '%';
	}

	/**
	 * Filter the rows a reference rewrite is allowed to touch.
	 *
	 * @param string $sql   The SELECT being built.
	 * @param array  $args  Its prepare() arguments.
	 * @param string $table The table being rewritten.
	 */
	// A filter returning the wrong shape used to be accepted silently: $args
	// became null, the query ran unprepared, and the PHP warnings printed
	// straight into the AJAX response body, which breaks JSON.parse on the
	// other end. Anything that is not a [ string, array ] pair is ignored.
	$filtered = apply_filters( 'wpmj_replace_query', [ $sql, $args ], $table );
	if ( is_array( $filtered ) && count( $filtered ) === 2
		&& is_string( $filtered[0] ) && is_array( $filtered[1] ) ) {
		list( $sql, $args ) = $filtered;
	} elseif ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log( 'WPMJ: wpmj_replace_query filter returned an unusable shape; ignoring it' );
	}

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

	foreach ( $rows as $row ) {
		$val = $row->val;

		if ( is_serialized( $val ) ) {
			// A serialized value carrying an OBJECT is left alone. Unserializing
			// it requires the class to be loaded - if it is not, PHP hands back
			// __PHP_Incomplete_Class and re-serializing writes that placeholder
			// over the user's data. Classes with __wakeup()/__unserialize() can
			// also mutate on the round trip. Neither is worth risking to rewrite
			// an image URL, so these rows are reported and skipped.
			if ( wpmj_serialized_has_object( $val ) ) {
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( "WPMJ: skipped object-bearing serialized row {$table}.{$pk_col}={$row->pk}" );
				}
				$GLOBALS['wpmj_unrewritable'][] = "{$table}#{$row->pk}";
				continue;
			}

			$data = @unserialize( $val, [ 'allowed_classes' => false ] );
			if ( $data === false && $val !== 'b:0;' ) {
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( "WPMJ: skipped unserializable row {$table}.{$pk_col}={$row->pk}" );
				}
				$GLOBALS['wpmj_unrewritable'][] = "{$table}#{$row->pk}";
				continue;
			}

			foreach ( $pairs as $p ) {
				$data = wpmj_deep_replace( $p['old'], $p['new'], $data );
			}
			$new_val = serialize( $data );

			// Never write back something that will not read back.
			if ( ! is_serialized( $new_val ) || @unserialize( $new_val, [ 'allowed_classes' => false ] ) === false && $new_val !== 'b:0;' ) {
				$GLOBALS['wpmj_unrewritable'][] = "{$table}#{$row->pk}";
				continue;
			}
		} else {
			$new_val = $val;
			foreach ( $pairs as $p ) {
				$new_val = str_replace( $p['old'], $p['new'], $new_val );
			}
		}

		if ( $new_val !== $val ) {
			$wpdb->update( $table, [ $value_col => $new_val ], [ $pk_col => $row->pk ] );
			$updated++;
			if ( ! $is_options && is_array( $touched_posts ) ) {
				$touched_posts[] = (int) $row->owner;
			}
		}
	}

	return $updated;
}

// ---------------------------------------------------------------------------
// Does a serialized string contain an object (O:) or custom-serialized (C:)?
// ---------------------------------------------------------------------------
function wpmj_serialized_has_object( $val ) {
	if ( ! is_string( $val ) ) return false;
	// A type marker only ever appears at the very start of the value or
	// immediately after a ; or { separator, which keeps a literal "O:12:" sitting
	// inside somebody's string content from tripping this.
	//
	// R: and r: are back-references. Unserializing one produces a genuinely
	// cyclic structure, and walking that recursively never terminates - so they
	// are refused here alongside objects rather than handled downstream.
	return (bool) preg_match( '/(^|[;{])([OC]:\d+:"|[Rr]:\d+;)/', $val );
}

// ---------------------------------------------------------------------------
// Deep string replacement in nested arrays/objects (for serialized data)
// ---------------------------------------------------------------------------
function wpmj_deep_replace( $search, $replace, $data, $depth = 0 ) {
	// Hard depth cap. The skip gate refuses back-references, but a filter, a
	// future caller, or a structure nested past anything reasonable must not be
	// able to turn a URL rewrite into an out-of-memory fatal. A real options or
	// meta payload is a handful of levels deep; 32 is far past that, and
	// stopping returns the branch untouched rather than corrupting it.
	if ( $depth > 32 ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'WPMJ: wpmj_deep_replace hit the depth cap; leaving this branch unmodified' );
		}
		$GLOBALS['wpmj_unrewritable'][] = 'depth-cap';
		return $data;
	}

	if ( is_string( $data ) ) {
		// A string that is ITSELF serialized has to be decoded, replaced and
		// re-serialized, not string-replaced through. maybe_serialize() double-
		// serializes routinely, and a plain str_replace on the inner payload
		// changes the string lengths without touching the s:N: prefixes that
		// declare them. serialize() then fixes only the OUTER layer, so the row
		// passes a shallow validity check and is written back permanently
		// broken - the owning plugin's get_option/get_post_meta returns false.
		if ( is_serialized( $data ) && ! wpmj_serialized_has_object( $data ) ) {
			$inner = @unserialize( $data, [ 'allowed_classes' => false ] );
			if ( $inner !== false || $data === 'b:0;' ) {
				return serialize( wpmj_deep_replace( $search, $replace, $inner, $depth + 1 ) );
			}
		}
		return str_replace( $search, $replace, $data );
	}
	if ( is_array( $data ) ) {
		foreach ( $data as $key => $val ) {
			$data[ $key ] = wpmj_deep_replace( $search, $replace, $val, $depth + 1 );
		}
		return $data;
	}
	if ( is_object( $data ) ) {
		foreach ( get_object_vars( $data ) as $key => $val ) {
			$data->$key = wpmj_deep_replace( $search, $replace, $val, $depth + 1 );
		}
		return $data;
	}
	return $data;
}

// ---------------------------------------------------------------------------
// delete_unused - bulk delete selected unused attachments
// ---------------------------------------------------------------------------
function wpmj_ajax_delete_unused() {
	wpmj_auth();
	wpmj_raise_limits();

	$ids = wpmj_post_ids( 'ids' );
	if ( empty( $ids ) ) wp_send_json_error( [ 'message' => __( 'No IDs provided.', 'media-mage' ) ] );

	// Validate against stored results.
	//
	// This is the ONLY thing standing between a delete request and permanent
	// removal of arbitrary attachments, so it fails closed. A missing results
	// transient used to skip the check entirely, which meant the 6-hour TTL
	// expiring under an open results page turned the guard off silently and
	// deleted whatever IDs the stale page still submitted.
	$results = get_transient( WPMJ_TRANSIENT_RESULTS );
	if ( ! $results || ! isset( $results['unused'] ) ) {
		wp_send_json_error( [
			'message' => __( 'Scan results have expired. Re-scan before deleting - Media Mage will not delete files it has not just verified as unused.', 'media-mage' ),
			'code'    => 'results_expired',
		] );
	}

	$allowed = array_map( 'intval', (array) $results['unused'] );
	$ids     = array_values( array_intersect( $ids, $allowed ) );

	if ( empty( $ids ) ) {
		wp_send_json_error( [
			'message' => __( 'None of the selected files are in the current unused list. Re-scan and try again.', 'media-mage' ),
			'code'    => 'not_in_results',
		] );
	}

	// Trash by default, permanent only when explicitly asked for.
	//
	// wp_trash_post() works on attachments whatever MEDIA_TRASH is set to: the
	// row goes to post_status=trash and the files stay on disk, so a mistake is
	// recoverable. Disk space is not reclaimed until the trash is emptied,
	// which is exactly what "trash" should mean.
	$mode = wpmj_post_text( 'mode', 'trash' ) === 'permanent' ? 'permanent' : 'trash';

	// Re-verify at delete time.
	//
	// The allow-list only proves an item was unused WHEN THE SCAN RAN, and that
	// snapshot is valid for six hours. Scan at 09:00, use one of the listed
	// images on the homepage at 11:00, click Delete at 12:00, and the live image
	// was destroyed. Checking again here costs one query per file and is the
	// difference between a stale list and a wrong deletion.
	$deleted = 0;
	$skipped = [];
	foreach ( $ids as $att_id ) {
		if ( get_post_type( $att_id ) !== 'attachment' ) continue;

		if ( wpmj_is_referenced( $att_id ) ) {
			$skipped[] = [
				'id'       => (int) $att_id,
				'filename' => basename( (string) get_attached_file( $att_id ) ),
			];
			continue;
		}

		if ( $mode === 'permanent' ) {
			wp_delete_attachment( $att_id, true );
		} else {
			// Remember what Media Mage trashed, so the Trashed tab shows this
			// plugin's work rather than everything ever trashed on the site.
			update_post_meta( $att_id, '_wpmj_trashed_at', current_time( 'mysql' ) );
			wp_trash_post( $att_id );
		}
		$deleted++;
	}

	// Anything now in use drops out of the stored results too, so a second
	// click cannot retry it.
	if ( $skipped ) {
		$skipped_ids       = wp_list_pluck( $skipped, 'id' );
		$results['unused'] = array_values( array_diff( (array) $results['unused'], $skipped_ids ) );
		$ids               = array_values( array_diff( $ids, $skipped_ids ) );
	}

	// Update results transient
	$results['unused'] = array_values( array_diff( (array) $results['unused'], $ids ) );
	set_transient( WPMJ_TRANSIENT_RESULTS, $results, WPMJ_RESULTS_TTL );

	wp_send_json_success( [
		'deleted' => $deleted,
		'skipped' => $skipped,
		'mode'    => $mode,
	] );
}

// ---------------------------------------------------------------------------
// Ignore list - files the user has told Media Mage to stop flagging
// ---------------------------------------------------------------------------
/**
 * The plugin cannot see references in theme or plugin PHP, so some files will
 * always come back as false positives. Without a way to say "this one is
 * fine", the same handful sit at the top of the unused list after every scan
 * forever and the user has to re-remember which ones were wrong.
 */
function wpmj_ajax_toggle_ignore() {
	wpmj_auth();

	$ids    = wpmj_post_ids( 'ids' );
	$ignore = wpmj_post_text( 'ignore', '1' ) !== '0';
	if ( empty( $ids ) ) wp_send_json_error( [ 'message' => __( 'No IDs provided.', 'media-mage' ) ] );

	$changed = 0;
	foreach ( $ids as $id ) {
		if ( get_post_type( $id ) !== 'attachment' ) continue;
		if ( $ignore ) update_post_meta( $id, WPMJ_META_IGNORED, 1 );
		else delete_post_meta( $id, WPMJ_META_IGNORED );
		$changed++;
	}

	// Drop them from the current results so the table matches immediately.
	$results = get_transient( WPMJ_TRANSIENT_RESULTS );
	if ( $results && $ignore ) {
		$results['unused'] = array_values( array_diff( (array) $results['unused'], $ids ) );
		set_transient( WPMJ_TRANSIENT_RESULTS, $results, WPMJ_RESULTS_TTL );
	}

	wp_send_json_success( [ 'changed' => $changed, 'ignored' => $ignore ] );
}

/** Attachment IDs currently on the ignore list. */
function wpmj_ignored_ids() {
	global $wpdb;
	// Joined to posts so a trashed attachment does not inflate the ignore count
	// the UI reports - every other query already excludes them.
	return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
		"SELECT m.post_id FROM {$wpdb->postmeta} m
		 JOIN {$wpdb->posts} p ON p.ID = m.post_id
		 WHERE m.meta_key = %s AND p.post_type = 'attachment' AND p.post_status != 'trash'",
		WPMJ_META_IGNORED
	) ) );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

// ---------------------------------------------------------------------------
// Trash - list, restore, empty
// ---------------------------------------------------------------------------
function wpmj_ajax_list_trash() {
	wpmj_auth();
	global $wpdb;

	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
		 WHERE p.post_type = 'attachment' AND p.post_status = 'trash'
		 ORDER BY p.ID DESC",
		'_wpmj_trashed_at'
	) ) );

	$items = [];
	$bytes = 0;
	foreach ( $ids as $id ) {
		$item = wpmj_enrich_attachment( $id, false );
		$item['trashed_at'] = get_post_meta( $id, '_wpmj_trashed_at', true );
		$items[] = $item;
		$bytes  += (int) $item['bytes'];
	}

	wp_send_json_success( [
		'items'         => $items,
		'count'         => count( $items ),
		'bytes'         => $bytes,
		'bytes_human'   => size_format( $bytes, 1 ) ?: '0 B',
	] );
}

function wpmj_ajax_restore_trash() {
	wpmj_auth();
	wpmj_raise_limits();

	$ids      = wpmj_post_ids( 'ids' );
	$restored = 0;
	foreach ( $ids as $id ) {
		if ( get_post_type( $id ) !== 'attachment' ) continue;
		if ( get_post_status( $id ) !== 'trash' ) continue;
		// Only restore what this plugin trashed.
		if ( ! get_post_meta( $id, '_wpmj_trashed_at', true ) ) continue;
		wp_untrash_post( $id );
		// wp_untrash_post can leave an attachment as 'draft'; attachments belong
		// in 'inherit' or they vanish from the media library.
		if ( get_post_status( $id ) !== 'inherit' ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => 'inherit' ] );
		}
		delete_post_meta( $id, '_wpmj_trashed_at' );
		$restored++;
	}

	wp_send_json_success( [ 'restored' => $restored ] );
}

function wpmj_ajax_empty_trash() {
	wpmj_auth();
	wpmj_raise_limits();

	$ids = wpmj_post_ids( 'ids' );
	if ( empty( $ids ) ) {
		global $wpdb;
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			 WHERE p.post_type = 'attachment' AND p.post_status = 'trash'",
			'_wpmj_trashed_at'
		) ) );
	}

	$deleted = 0;
	foreach ( $ids as $id ) {
		if ( get_post_type( $id ) !== 'attachment' ) continue;
		if ( get_post_status( $id ) !== 'trash' ) continue;
		if ( ! get_post_meta( $id, '_wpmj_trashed_at', true ) ) continue;
		wp_delete_attachment( $id, true );
		$deleted++;
	}

	wp_send_json_success( [ 'deleted' => $deleted ] );
}

// ---------------------------------------------------------------------------
// CSV export - a record of what the scan found, before anything is deleted
// ---------------------------------------------------------------------------
function wpmj_export_csv() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Unauthorized.', 'media-mage' ), 403 );
	}
	check_admin_referer( WPMJ_NONCE_ACTION );
	wpmj_raise_limits();

	// This streams after the headers are sent, so a timeout part-way through
	// hands the browser a silently truncated CSV at HTTP 200 - and this file is
	// the plugin's own "keep a record before deleting anything" safety net.
	if ( headers_sent() ) {
		wp_die( esc_html__( 'Could not start the download - output had already begun.', 'media-mage' ) );
	}
	while ( ob_get_level() > 0 ) { ob_end_clean(); }

	$results = get_transient( WPMJ_TRANSIENT_RESULTS );
	if ( ! $results ) {
		wp_die( esc_html__( 'No scan results to export. Run a scan first.', 'media-mage' ) );
	}

	$filename = 'media-mage-' . gmdate( 'Y-m-d-His' ) . '.csv';
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	// WP_Filesystem is not usable here. It writes whole files to disk and has no
	// streaming API, and this is an HTTP download being streamed to the client
	// through php://output. fputcsv() also needs a real stream resource.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download to php://output, not touching the filesystem.
	$out = fopen( 'php://output', 'w' );
	// BOM so Excel opens UTF-8 filenames correctly.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above, php://output stream.
	fwrite( $out, "\xEF\xBB\xBF" );
	fputcsv( $out, [ 'category', 'group', 'attachment_id', 'filename', 'url', 'size_bytes', 'size_human', 'dimensions', 'mime', 'uploaded', 'reference_count' ] );

	// Duplicates carry a real reference count, because that number is what the
	// user weighs up when choosing which copy to keep. Unused rows are zero by
	// construction - they are on the list precisely because nothing referenced
	// them - so they are written as 0 rather than paying a full-table scan per
	// row to rediscover it.
	$g = 0;
	foreach ( (array) $results['duplicates'] as $group ) {
		$g++;
		foreach ( (array) $group['ids'] as $id ) {
			$i = wpmj_enrich_attachment( $id, false );
			fputcsv( $out, [ 'duplicate', $g, $i['id'], $i['filename'], $i['url'], $i['bytes'], $i['size'], $i['dimensions'], $i['mime'], $i['date'], wpmj_count_references( $id ) ] );
		}
	}
	foreach ( (array) $results['unused'] as $id ) {
		$i = wpmj_enrich_attachment( $id, false );
		fputcsv( $out, [ 'unused', '', $i['id'], $i['filename'], $i['url'], $i['bytes'], $i['size'], $i['dimensions'], $i['mime'], $i['date'], 0 ] );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream opened above.
	fclose( $out );
	exit;
}

// ---------------------------------------------------------------------------
// clear_results - purge all scan transients
// ---------------------------------------------------------------------------
function wpmj_ajax_clear_results() {
	wpmj_auth();
	delete_transient( WPMJ_TRANSIENT_SCAN_STATE );
	delete_transient( WPMJ_TRANSIENT_RESULTS );
	wp_send_json_success();
}
// ---------------------------------------------------------------------------
// WP-CLI
//
// Every competitor that offers a command line puts it behind a paid tier -
// Media Cleaner Pro, Media Hygiene Pro, Media Deduper Pro. It is a few hundred
// lines over the same functions the admin screen already calls, so gating it is
// a pricing decision rather than an engineering one. It ships free here, and it
// is also the only sane way to run this against a large library.
// ---------------------------------------------------------------------------
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Find and clean up duplicate and unused media.
	 */
	class WPMJ_CLI {

		/**
		 * Scan the media library for duplicates and unused files.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format. Accepts table, json, csv, yaml, count.
		 * ---
		 * default: table
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp media-mage scan
		 *     wp media-mage scan --format=json
		 */
		public function scan( $args, $assoc ) {
			global $wpdb;
			wpmj_raise_limits();

			$ids = array_map( 'intval', (array) $wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash' ORDER BY ID ASC"
			) );
			$total = count( $ids );
			if ( ! $total ) {
				WP_CLI::success( 'Media library is empty - nothing to scan.' );
				return;
			}

			$hashes  = [];
			$missing = [];
			$unused  = [];
			$ignored = wpmj_ignored_ids();

			$bar = WP_CLI\Utils\make_progress_bar( 'Hashing', $total );
			foreach ( $ids as $id ) {
				$file = get_attached_file( $id );
				if ( ! $file || ! file_exists( $file ) ) {
					// Same as the admin path: forget the hash, or this ghost
					// joins a duplicate group and can win the keeper tie.
					wpmj_forget_hash( $id );
					$missing[] = $id;
					$bar->tick();
					continue;
				}
				$hash = wpmj_hash_attachment( $id, $file );
				$hashes[ $hash ][] = $id;
				$bar->tick();
			}
			$bar->finish();

			$bar = WP_CLI\Utils\make_progress_bar( 'Checking references', $total );
			foreach ( $ids as $id ) {
				if ( in_array( $id, $ignored, true ) ) {
					$bar->tick();
					continue;
				}
				if ( ! wpmj_is_referenced( $id ) ) $unused[] = $id;
				$bar->tick();
			}
			$bar->finish();

			$duplicates = [];
			foreach ( $hashes as $hash => $group ) {
				if ( count( $group ) >= 2 ) $duplicates[] = [ 'hash' => $hash, 'ids' => array_values( $group ) ];
			}

			set_transient( WPMJ_TRANSIENT_RESULTS, [
				'duplicates'    => $duplicates,
				'unused'        => $unused,
				'missing'       => $missing,
				'ignored_count' => count( $ignored ),
				'scanned_at'    => current_time( 'mysql' ),
			], WPMJ_RESULTS_TTL );

			$reclaim = 0;
			foreach ( $duplicates as $g ) {
				$f = get_attached_file( $g['ids'][0] );
				if ( $f && file_exists( $f ) ) $reclaim += ( count( $g['ids'] ) - 1 ) * filesize( $f );
			}
			foreach ( $unused as $id ) {
				$f = get_attached_file( $id );
				if ( $f && file_exists( $f ) ) $reclaim += filesize( $f );
			}

			$rows = [ [
				'scanned'          => $total,
				'duplicate_groups' => count( $duplicates ),
				'unused'           => count( $unused ),
				'missing_files'    => count( $missing ),
				'ignored'          => count( $ignored ),
				'reclaimable'      => size_format( $reclaim, 1 ) ?: '0 B',
			] ];
			WP_CLI\Utils\format_items(
				isset( $assoc['format'] ) ? $assoc['format'] : 'table',
				$rows,
				[ 'scanned', 'duplicate_groups', 'unused', 'missing_files', 'ignored', 'reclaimable' ]
			);
		}

		/**
		 * List duplicate groups from the last scan.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format. Accepts table, json, csv, yaml, count.
		 * ---
		 * default: table
		 * ---
		 */
		public function duplicates( $args, $assoc ) {
			$results = $this->results_or_die();
			$rows = [];
			$g = 0;
			foreach ( (array) $results['duplicates'] as $group ) {
				$g++;
				foreach ( (array) $group['ids'] as $id ) {
					$file = get_attached_file( $id );
					$rows[] = [
						'group'      => $g,
						'id'         => $id,
						'filename'   => $file ? basename( $file ) : '(unknown)',
						'size'       => ( $file && file_exists( $file ) ) ? size_format( filesize( $file ) ) : '-',
						'references' => wpmj_count_references( $id ),
					];
				}
			}
			if ( ! $rows ) {
				WP_CLI::success( 'No duplicates found.' );
				return;
			}
			WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, [ 'group', 'id', 'filename', 'size', 'references' ] );
		}

		/**
		 * List unused files from the last scan.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format. Accepts table, json, csv, yaml, count.
		 * ---
		 * default: table
		 * ---
		 */
		public function unused( $args, $assoc ) {
			$results = $this->results_or_die();
			$rows = [];
			foreach ( (array) $results['unused'] as $id ) {
				$file = get_attached_file( $id );
				$rows[] = [
					'id'       => $id,
					'filename' => $file ? basename( $file ) : '(unknown)',
					'size'     => ( $file && file_exists( $file ) ) ? size_format( filesize( $file ) ) : '-',
					'uploaded' => get_the_date( 'Y-m-d', $id ),
				];
			}
			if ( ! $rows ) {
				WP_CLI::success( 'No unused media found.' );
				return;
			}
			WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, [ 'id', 'filename', 'size', 'uploaded' ] );
		}

		/**
		 * Resolve duplicate groups, keeping the most-referenced copy in each.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Report what would happen without changing anything.
		 *
		 * [--yes]
		 * : Skip the confirmation prompt.
		 */
		public function resolve( $args, $assoc ) {
			global $wpdb;
			wpmj_raise_limits();
			$results = $this->results_or_die();
			$groups  = (array) $results['duplicates'];
			if ( ! $groups ) {
				WP_CLI::success( 'No duplicates to resolve.' );
				return;
			}

			$dry = isset( $assoc['dry-run'] );
			if ( ! $dry ) {
				WP_CLI::confirm( sprintf( 'Resolve %d duplicate group(s)? Extra copies will be permanently deleted.', count( $groups ) ), $assoc );
			}

			$resolved    = 0;
			$removed     = 0;
			$blocked_cli = 0;
			foreach ( $groups as $group ) {
				$ids = array_map( 'intval', (array) $group['ids'] );
				$ids = array_values( array_filter( $ids, function ( $i ) {
					return get_post_type( $i ) === 'attachment';
				} ) );
				if ( count( $ids ) < 2 ) continue;

				usort( $ids, function ( $a, $b ) {
					return wpmj_count_references( $b ) - wpmj_count_references( $a );
				} );
				$keeper = array_shift( $ids );

				if ( $dry ) {
					WP_CLI::log( sprintf( 'would keep #%d, delete %s', $keeper, implode( ', ', array_map( function ( $i ) {
						return '#' . $i;
					}, $ids ) ) ) );
					$resolved++;
					$removed += count( $ids );
					continue;
				}

				$group_removed = 0;
				foreach ( $ids as $dup ) {
					// The group is a proposal built from cached hashes. Verify
					// before touching anything - a cache keyed on timestamps can
					// be wrong about content restored from a backup.
					if ( ! wpmj_files_identical( $keeper, $dup ) ) {
						$blocked_cli++;
						WP_CLI::warning( sprintf( 'kept #%d - it is no longer byte-identical to #%d', $dup, $keeper ) );
						continue;
					}

					// Same code path as the admin screen. This used to be a
					// second copy and it had already lost the WooCommerce
					// gallery re-point, so resolving from the command line on a
					// Woo site left galleries pointing at a deleted attachment.
					$touched = [];
					wpmj_repoint_references( $dup, $keeper, $touched );
					foreach ( array_unique( $touched ) as $tp ) clean_post_cache( $tp );

					// The admin screen refuses to delete a duplicate whose
					// references it could not fully rewrite. So does this.
					if ( ! empty( $GLOBALS['wpmj_unrewritable'] ) ) {
						$blocked_cli++;
						WP_CLI::warning( sprintf(
							'kept #%d - %d reference(s) could not be safely rewritten: %s',
							$dup,
							count( array_unique( $GLOBALS['wpmj_unrewritable'] ) ),
							implode( ', ', array_slice( array_unique( $GLOBALS['wpmj_unrewritable'] ), 0, 5 ) )
						) );
						continue;
					}

					wp_delete_attachment( $dup, true );
					wpmj_oxygen_cache( true );
					$removed++;
					$group_removed++;
				}
				clean_post_cache( $keeper );
				$resolved++;
				// Report what was actually removed, not what was proposed - with the
				// blocked guard in play those differ, and reporting the proposal
				// would claim deletions that never happened.
				WP_CLI::log( sprintf( 'kept #%d, removed %d of %d duplicate(s)', $keeper, $group_removed, count( $ids ) ) );
			}

			// Only clear the results when everything went through. Wiping them
			// after a block destroys the one thing that lets the user retry.
			if ( ! $dry && ! $blocked_cli ) delete_transient( WPMJ_TRANSIENT_RESULTS );
			if ( $blocked_cli ) {
				WP_CLI::warning( sprintf( '%d duplicate(s) kept because their references could not be safely rewritten.', $blocked_cli ) );
			}
			WP_CLI::success( sprintf( '%s%d group(s), %d file(s).', $dry ? 'Dry run - would resolve ' : 'Resolved ', $resolved, $removed ) );
		}

		/**
		 * Delete unused media found by the last scan.
		 *
		 * Trashes rather than permanently deleting unless told otherwise, and
		 * re-checks every file immediately before touching it, so anything that
		 * came back into use since the scan is skipped.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Report what would happen without changing anything.
		 *
		 * [--permanent]
		 * : Delete permanently instead of moving to trash.
		 *
		 * [--yes]
		 * : Skip the confirmation prompt.
		 */
		public function delete( $args, $assoc ) {
			wpmj_raise_limits();
			$results = $this->results_or_die();
			$ids     = array_map( 'intval', (array) $results['unused'] );
			if ( ! $ids ) {
				WP_CLI::success( 'No unused media to delete.' );
				return;
			}

			$dry       = isset( $assoc['dry-run'] );
			$permanent = isset( $assoc['permanent'] );

			if ( ! $dry ) {
				WP_CLI::confirm( sprintf(
					'%s %d unused file(s)?',
					$permanent ? 'PERMANENTLY delete' : 'Trash',
					count( $ids )
				), $assoc );
			}

			$done    = 0;
			$skipped = 0;
			$label   = $dry ? 'Checking' : ( $permanent ? 'Deleting' : 'Trashing' );
			$bar     = WP_CLI\Utils\make_progress_bar( $label, count( $ids ) );
			foreach ( $ids as $id ) {
				$bar->tick();
				if ( get_post_type( $id ) !== 'attachment' ) continue;
				if ( wpmj_is_referenced( $id ) ) {
					$skipped++;
					continue;
				}
				if ( $dry ) {
					$done++;
					continue;
				}
				if ( $permanent ) {
					wp_delete_attachment( $id, true );
				} else {
					update_post_meta( $id, '_wpmj_trashed_at', current_time( 'mysql' ) );
					wp_trash_post( $id );
				}
				$done++;
			}
			$bar->finish();

			if ( $skipped ) {
				WP_CLI::warning( sprintf( '%d file(s) skipped - referenced again since the scan.', $skipped ) );
			}
			WP_CLI::success( sprintf(
				'%s%d file(s).',
				$dry ? 'Dry run - would affect ' : ( $permanent ? 'Deleted ' : 'Trashed ' ),
				$done
			) );
		}

		/**
		 * Export the last scan's results as CSV to STDOUT or a file.
		 *
		 * ## OPTIONS
		 *
		 * [--file=<path>]
		 * : Write to this path instead of STDOUT.
		 */
		public function export( $args, $assoc ) {
			$results = $this->results_or_die();
			$path    = isset( $assoc['file'] ) ? $assoc['file'] : 'php://stdout';
			// Defaults to php://stdout, a stream WP_Filesystem cannot write to, and
			// fputcsv() requires a stream resource in either case. WP-CLI context.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CLI stream output; WP_Filesystem has no streaming API.
			$out     = fopen( $path, 'w' );
			if ( ! $out ) WP_CLI::error( 'Could not open ' . $path . ' for writing.' );

			fputcsv( $out, [ 'category', 'group', 'attachment_id', 'filename', 'url', 'size_bytes', 'uploaded', 'reference_count' ] );
			$g = 0;
			foreach ( (array) $results['duplicates'] as $group ) {
				$g++;
				foreach ( (array) $group['ids'] as $id ) {
					$i = wpmj_enrich_attachment( $id, false );
					fputcsv( $out, [ 'duplicate', $g, $i['id'], $i['filename'], $i['url'], $i['bytes'], $i['date'], wpmj_count_references( $id ) ] );
				}
			}
			foreach ( (array) $results['unused'] as $id ) {
				$i = wpmj_enrich_attachment( $id, false );
				fputcsv( $out, [ 'unused', '', $i['id'], $i['filename'], $i['url'], $i['bytes'], $i['date'], 0 ] );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the CLI stream opened above.
			fclose( $out );
			if ( $path !== 'php://stdout' ) WP_CLI::success( 'Wrote ' . $path );
		}

		/**
		 * Show where a given attachment is referenced.
		 *
		 * ## OPTIONS
		 *
		 * <id>
		 * : The attachment ID.
		 *
		 * [--format=<format>]
		 * : Output format. Accepts table, json, csv, yaml, count.
		 * ---
		 * default: table
		 * ---
		 */
		public function where( $args, $assoc ) {
			$id = (int) $args[0];
			if ( get_post_type( $id ) !== 'attachment' ) WP_CLI::error( "#$id is not an attachment." );

			$d = wpmj_reference_details( $id );
			if ( ! $d['count'] ) {
				WP_CLI::success( "#$id is not referenced anywhere Media Mage can see." );
				return;
			}
			if ( ! $d['posts'] ) {
				WP_CLI::success( sprintf( '#%d has %d reference(s), none of them a linkable post.', $id, $d['count'] ) );
				return;
			}
			WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $d['posts'], [ 'id', 'title', 'type', 'via' ] );
		}

		/**
		 * Add to, remove from, or show the ignore list.
		 *
		 * ## OPTIONS
		 *
		 * <action>
		 * : add, remove, or list.
		 *
		 * [<id>...]
		 * : Attachment IDs.
		 */
		public function ignore( $args, $assoc ) {
			$action = array_shift( $args );
			if ( $action === 'list' ) {
				$rows = [];
				foreach ( wpmj_ignored_ids() as $id ) {
					$f = get_attached_file( $id );
					$rows[] = [ 'id' => $id, 'filename' => $f ? basename( $f ) : '(unknown)' ];
				}
				if ( ! $rows ) {
					WP_CLI::success( 'Ignore list is empty.' );
					return;
				}
				WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'filename' ] );
				return;
			}
			if ( ! in_array( $action, [ 'add', 'remove' ], true ) ) {
				WP_CLI::error( 'Action must be add, remove or list.' );
			}
			$n = 0;
			foreach ( $args as $id ) {
				$id = (int) $id;
				if ( get_post_type( $id ) !== 'attachment' ) continue;
				if ( $action === 'add' ) update_post_meta( $id, WPMJ_META_IGNORED, 1 );
				else delete_post_meta( $id, WPMJ_META_IGNORED );
				$n++;
			}
			WP_CLI::success( sprintf( '%s %d attachment(s).', $action === 'add' ? 'Ignored' : 'Un-ignored', $n ) );
		}

		/**
		 * List, restore or permanently empty media that Media Mage trashed.
		 *
		 * ## OPTIONS
		 *
		 * <action>
		 * : list, restore, or empty.
		 *
		 * [<id>...]
		 * : Attachment IDs. Omit to act on everything Media Mage trashed.
		 *
		 * [--yes]
		 * : Skip the confirmation prompt.
		 */
		public function trash( $args, $assoc ) {
			global $wpdb;
			$action = array_shift( $args );
			$ids    = array_map( 'intval', $args );

			if ( ! $ids ) {
				$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
					 WHERE p.post_type = 'attachment' AND p.post_status = 'trash'",
					'_wpmj_trashed_at'
				) ) );
			}

			if ( $action === 'list' ) {
				$rows = [];
				foreach ( $ids as $id ) {
					$f = get_attached_file( $id );
					$rows[] = [
						'id'         => $id,
						'filename'   => $f ? basename( $f ) : '(unknown)',
						'size'       => ( $f && file_exists( $f ) ) ? size_format( filesize( $f ) ) : '-',
						'trashed_at' => get_post_meta( $id, '_wpmj_trashed_at', true ),
					];
				}
				if ( ! $rows ) {
					WP_CLI::success( 'Media Mage has not trashed anything.' );
					return;
				}
				WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'filename', 'size', 'trashed_at' ] );
				return;
			}

			if ( ! $ids ) {
				WP_CLI::success( 'Nothing to do.' );
				return;
			}

			if ( $action === 'restore' ) {
				$n = 0;
				$skipped = 0;
				foreach ( $ids as $id ) {
					if ( ! $this->owns_trashed( $id ) ) { $skipped++; continue; }
					wp_untrash_post( $id );
					if ( get_post_status( $id ) !== 'inherit' ) {
						wp_update_post( [ 'ID' => $id, 'post_status' => 'inherit' ] );
					}
					delete_post_meta( $id, '_wpmj_trashed_at' );
					$n++;
				}
				if ( $skipped ) {
					WP_CLI::warning( sprintf( '%d id(s) skipped - not attachments Media Mage trashed.', $skipped ) );
				}
				WP_CLI::success( sprintf( 'Restored %d attachment(s).', $n ) );
				return;
			}

			if ( $action === 'empty' ) {
				$ids = array_values( array_filter( $ids, [ $this, 'owns_trashed' ] ) );
				if ( ! $ids ) {
					WP_CLI::success( 'Nothing to delete - no trashed attachments belonging to Media Mage.' );
					return;
				}
				WP_CLI::confirm( sprintf( 'Permanently delete %d trashed file(s)?', count( $ids ) ), $assoc );
				$n = 0;
				foreach ( $ids as $id ) {
					wp_delete_attachment( $id, true );
					$n++;
				}
				WP_CLI::success( sprintf( 'Permanently deleted %d attachment(s).', $n ) );
				return;
			}

			WP_CLI::error( 'Action must be list, restore or empty.' );
		}

		/**
		 * Is this ID a trashed attachment that MEDIA MAGE put in the trash?
		 *
		 * Restore and empty both take explicit IDs, and without these three
		 * checks they act on whatever they are handed. Restoring a trashed page
		 * force-set post_status to 'inherit', which is meaningful only for
		 * attachments - the page then vanished from the admin list, from every
		 * WP_Query and from the front end, with the content still in the
		 * database and no way back through the UI. Empty was worse: it hard
		 * deleted media the user had trashed by hand and meant to restore.
		 */
		private function owns_trashed( $id ) {
			$id = (int) $id;
			if ( get_post_type( $id ) !== 'attachment' ) return false;
			if ( get_post_status( $id ) !== 'trash' ) return false;
			return (bool) get_post_meta( $id, '_wpmj_trashed_at', true );
		}

		private function results_or_die() {
			$results = get_transient( WPMJ_TRANSIENT_RESULTS );
			if ( ! $results ) {
				WP_CLI::error( 'No scan results. Run "wp media-mage scan" first.' );
			}
			return $results;
		}
	}

	WP_CLI::add_command( 'media-mage', 'WPMJ_CLI' );
}
