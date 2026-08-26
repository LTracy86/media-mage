=== Media Mage ===
Contributors: ltracy
Donate link: https://buymeacoffee.com/lincolntracy
Tags: media library, duplicate images, unused media, cleanup, wp-cli
Requires at least: 5.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find duplicate and unused media, check every reference before deleting, and send removals to the trash so a mistake stays reversible.

== Description ==

Media Mage scans your media library for two kinds of clutter:

* **Duplicate files** - the same image uploaded more than once under different names. Matched on MD5 content hash, so a renamed copy is still caught.
* **Unused media** - attachments that nothing on the site appears to reference.

A tool that deletes files is only as good as the search it runs first, because anything the search misses gets reported as unused and then deleted. Most of the work in this plugin is in that search, and in the guards that sit between the search result and the delete.

= What counts as a reference =

Media Mage checks all of the following before it will call a file unused:

* Post content of any post, page or custom post type, including trashed posts
* Post meta - ACF fields, Elementor data, page-builder payloads, plain custom fields, wherever the value contains the URL
* Term meta, user meta and comment meta, again wherever the value contains the URL
* Comment content
* The options table - theme mods, customizer values, widget areas
* Featured images (`_thumbnail_id`) and the attachment's own post parent
* Site logo and site icon
* WooCommerce product galleries
* Oxygen Builder base64-encoded layout data (`_ct_builder_shortcodes`, `_ct_builder_json`, `ct_style_sheets`, `ct_components_classes`)
* Gutenberg block IDs and `wp-image-N` classes, so a block that carries an ID and no URL still counts

Each of those is checked against every URL the attachment can appear under: the full-size file, every generated size variant, the `-scaled` version and the preserved original. Inserting an image at Medium embeds the medium URL and never the full-size one, so checking only the full-size URL is how a live image ends up on an unused list.

URLs are also matched in their JSON-escaped form (`\/` instead of `/`), which is how Elementor, Divi and Gutenberg block attributes store them.

= What it cannot see =

Media Mage reads the database. It does not read your code. It will not find:

* An image path hardcoded in a theme or plugin PHP file, including a child theme
* URLs rewritten to an external CDN, so what is stored no longer matches what is served
* **An attachment referenced only by its numeric ID in a custom field.** IDs are checked in featured images, WooCommerce galleries, the site logo and icon, and Gutenberg blocks and gallery shortcodes inside post content. Everywhere else the search is for the URL. The common case is an ACF image field set to return the Image ID rather than the URL - that value is a bare number and Media Mage cannot tell it from any other number.
* **Uploads shared or copied between sites on a multisite network.** Each scan sees one site. If site A's image is used on site B, site A reports it unused.
* Anything held in a store the plugin does not know about

If your theme prints `wp-content/uploads/2026/01/hero.jpg` straight out of a template file, that image is reported as unused. Review the list before deleting. There is a `wpmj_is_referenced` filter for protecting files programmatically - see the FAQ.

One more thing worth knowing, because it works the other way: an attachment whose `post_parent` points at a post that still exists counts as referenced. Most images uploaded through the post editor have one, so on a long-lived site the unused list will be shorter than you expect. That is deliberate - it errs toward keeping files - but it is why a scan can come back nearly empty on a site you were certain was full of clutter.

= How it avoids breaking your site =

* **Deleting trashes by default.** Files go to the WordPress trash. The database rows and the files on disk both survive, so a wrong call is recoverable from the Trash tab. Disk space comes back when the trash is emptied, which is a separate action.
* **Every file is re-checked at delete time.** The scan is a snapshot. Scan at 09:00, put one of those images on the homepage at 11:00, click delete at 12:00, and the snapshot is wrong. Each file is checked again immediately before it is touched, and anything back in use is skipped and reported.
* **Deletes are limited to the current scan results.** A delete request for an ID that is not in the results is refused, and expired results fail closed rather than waving the request through.
* **Resolving a duplicate re-points references first.** Post content, post meta, options, theme mods, WooCommerce galleries and Oxygen data are rewritten to the keeper before the duplicate is removed, including every size variant and the JSON-escaped forms.
* **Serialized data is handled properly.** Nested serialized values are rewritten with their length prefixes recomputed at every level. Values containing PHP objects are skipped rather than round-tripped, and any rewrite that will not read back is discarded.
* **Rewrites stay in scope.** Cron, rewrite rules, transients and edit locks are excluded by name. A backup plugin's run log that merely names a file is not evidence, and editing it is its own kind of damage.
* **A duplicate group is only a proposal, and the delete verifies it.** Groups come from cached hashes, and a cache keyed on file timestamps can be wrong - restoring uploads with rsync or a backup tool changes content while leaving timestamps untouched. Both files are re-hashed immediately before a duplicate is removed, and anything no longer byte-identical is kept and reported instead. If either file cannot be read, that counts as unverified, and unverified is never treated as identical.
* **A duplicate whose references could not all be rewritten is kept, not deleted.** Some values cannot be safely rewritten - a serialized PHP object, a structure with an internal back-reference. Those are left alone rather than corrupted, and because leaving them alone means a reference would survive pointing at a deleted file, the deletion is cancelled and the file and rows are named.

= Other things it does =

* **Trash view.** Everything Media Mage trashed is listed on its own tab, with restore and a separate empty-the-trash action. It lists what this plugin trashed, not everything ever trashed on the site.
* **Ignore list.** Mark a file as fine and it stops appearing in the unused list. Without this, the handful of files the plugin structurally cannot see references for sit at the top of every scan forever.
* **Where is this used?** Each file can show the posts that reference it, with edit links and how the match was made. The queries behind the reference count already knew which posts matched, and that count is the whole basis for deciding which copy of a duplicate to keep, so it is worth seeing.
* **CSV export** of the scan results, so there is a record of what was found before anything is deleted.
* **WP-CLI commands** for scanning, listing, resolving, deleting, exporting, the ignore list and the trash, with dry runs. Resolving shares one implementation with the admin screen, so the two cannot drift apart. See the FAQ.
* Two-phase scan with per-file progress, sized so each request finishes well inside a shared host's PHP timeout
* MD5 hashes cached against file modification time and size, so re-scans are fast
* Reclaimable-bytes total, plus library total, ignored count and trashed count, so the numbers have a denominator
* Attachments whose file is missing from disk are counted separately rather than silently hashed as identical
* Two filters, `wpmj_is_referenced` and `wpmj_replace_query`, for sites that need the last word
* Uninstall removes every row the plugin wrote, on single sites and across a multisite network

= Free, and staying that way =

No pro tier, no license key, no upsell. If it saved you an afternoon you can [buy me a coffee](https://buymeacoffee.com/lincolntracy). Never required.

== Installation ==

1. Upload the `media-mage` folder to `/wp-content/plugins/`, or install the zip through **Plugins > Add New > Upload Plugin**.
2. Activate it on the **Plugins** screen.
3. Go to **Media > Media Mage**.
4. Click **Scan Media Library**.

= Before your first scan =

* Take a database and uploads backup. Emptying the trash is not reversible.
* If you have a staging copy, run it there first.

== Frequently Asked Questions ==

= Will this break my site? =

It is built not to, and the design assumes it will be run on a site nobody has a recent backup of.

Deleting unused media moves files to the trash rather than removing them, and every file is re-checked against the whole reference search immediately before it is touched. Resolving a duplicate rewrites every reference to the keeper - full-size URL, every size variant, the `-scaled` and original siblings, and the JSON-escaped forms - before the duplicate is removed.

Take a backup anyway. Any tool that can delete media can delete the wrong media.

= What does it miss? =

References that live in code rather than in the database. Theme PHP, plugin PHP, child-theme templates, and anything served through a CDN URL that does not match what is stored. Media Mage cannot see those and does not pretend to. Read the unused list before acting on it.

= How big a media library can it handle? =

Comfortably up to a couple of thousand attachments in the browser. Past that, use WP-CLI.

The honest version: finding duplicates is cheap and scales linearly. Finding *unused* media is not. Every attachment is checked against post content, post meta, term/user/comment meta, options and comments, and those are substring searches that no database index can help with. The cost of each check grows with the size of the library, so the total grows faster than the number of files does.

Measured on a test fixture (2 KB images, 3,000 posts, 70% of the library referenced), on a developer machine rather than production hardware:

* 1,000 attachments - about 90 seconds
* 5,000 attachments - roughly 40 minutes
* 20,000 attachments - many hours

Your numbers will differ, and larger image files make the hashing step slower without affecting the rest. Treat these as the shape of the curve, not a promise.

What this means in practice: on a small or medium site, scan from the admin screen. On a large one, run `wp media-mage scan` from the command line, where there is no request timeout, and then use `wp media-mage unused` and `wp media-mage delete --dry-run` to review before acting. Making the unused scan fast on very large libraries is the main thing on the list for the next version.

= How do I protect a file it keeps reporting as unused? =

Add it to the ignore list. Ignored files stay in the media library and stop appearing in the unused results.

For anything you want handled in code, the `wpmj_is_referenced` filter runs last, after every built-in check has come up empty, and receives the attachment ID and every URL path that was checked:

`add_filter( 'wpmj_is_referenced', function ( $referenced, $att_id, $paths ) {
    if ( in_array( $att_id, [ 42, 108 ], true ) ) {
        return true;
    }
    return $referenced;
}, 10, 3 );`

= Does it work with Oxygen Builder? =

Yes, and this is why the plugin exists. Oxygen stores layouts as base64-encoded post meta and options. Read those values as plain text and no image reference in them is visible, so an Oxygen site looks like it has hundreds of unused images. Media Mage decodes that data and searches inside it, matching on full paths rather than bare filenames so `logo.png` does not match `site-logo.png`.

= Does it work with Elementor, Divi, Gutenberg and ACF? =

Yes. All four store their data in post content or post meta, where the URL search reaches, including the JSON-escaped slashes that block attributes and JSON meta use. Gutenberg blocks that carry an attachment ID and no URL are matched on the ID and on the `wp-image-N` class.

= What about images used only in trashed posts? =

They count as referenced. Trash is restorable, and deleting the images out from under a trashed post breaks the restore.

= Can I undo a duplicate resolution? =

No. Resolving rewrites the references to the keeper and then removes the duplicate for good. The site keeps rendering, because every reference now points at the keeper, but the file is gone. Deleting unused media is the reversible one, up until the trash is emptied.

= How fast is it? =

The hash phase runs 50 attachments per request, the reference phase 25, and hashes are cached against file mtime so a second scan skips the hashing work for anything that has not changed. On a test fixture of 500 attachments across 38 posts, a full first scan takes roughly 30 to 40 seconds. Larger libraries scale roughly linearly.

= Is there a command line interface? =

Yes. Every operation the admin screen performs is available under `wp media-mage`:

`wp media-mage scan
wp media-mage duplicates --format=json
wp media-mage unused
wp media-mage where <id>
wp media-mage resolve --dry-run
wp media-mage delete --dry-run
wp media-mage delete --permanent --yes
wp media-mage export --file=report.csv
wp media-mage ignore add <id>...
wp media-mage ignore list
wp media-mage trash list
wp media-mage trash restore <id>...
wp media-mage trash empty --yes`

`resolve` and `delete` both take `--dry-run`, which reports exactly what would happen and changes nothing. Use it first.

= Is there a Pro version? =

No. This plugin is free, GPLv2, and intentionally has no premium tier. If it's useful to you, [a coffee](https://buymeacoffee.com/lincolntracy) is a kind way to say thanks.

= Does it work on multisite? =

The scan runs per site. Uninstall cleans up its data on every site in the network.

== Screenshots ==

1. Duplicate groups, matched on file content rather than filename. The reference count is there because it is the number you weigh up when choosing which copy to keep.
2. The unused list. Every file here was checked against twelve places first, and the notice is a standing reminder that a database scan cannot read your theme's PHP.
3. Nothing is deleted without naming the files first. Cancel leaves the library untouched.
4. Removals go to the trash and stay on disk. Restore puts a file back; emptying the trash is the separate step that actually reclaims the space.
5. A scan in progress. The log names every file as it goes, so a run that misses something is visible rather than silent.

== Changelog ==

= 1.0.0 - 2026-08-04 =

First public release. Everything below is relative to 0.3.0.

**Fixed - would not activate**

* Fixed a fatal error that made the plugin impossible to activate. A refactor replaced the string literals on both sides of four `define()` calls, so each constant was defined as itself. PHP 8 throws on the undefined constant and activation dies. If you have a 0.3.0 copy that white-screens or refuses to activate, this is why. Replace it with this version.

**Fixed - data loss**

* Reference detection now checks every generated size variant and the `-scaled` / original siblings. It previously checked only the full-size URL, which is the URL a real site is least likely to have embedded. Images inserted at Thumbnail, Medium or Large were reported unused and deleted while still on the page.
* Reference detection now matches JSON-escaped slashes. Elementor, Divi and Gutenberg block attributes all store URLs that way, so references from all three were invisible.
* Reference detection now covers Gutenberg block IDs and `wp-image-N` classes, the site logo, the site icon, term meta, user meta, comment meta and comment content. None of those were checked before.
* Trashed posts now count as references. Deleting an image used only by a trashed post broke the restore.
* Deletion re-verifies every file immediately before removing it. A results snapshot was valid for six hours, so a file put back into use after the scan was deleted anyway. Anything back in use is now skipped and reported.
* Deletes now trash by default instead of removing files permanently. Permanent removal is a separate, explicit action.
* Delete requests fail closed when the scan results have expired. A missing results transient used to skip validation entirely, which turned the guard off silently and deleted whatever IDs a stale page still held.
* `resolve_duplicate` now validates its IDs against the stored scan results, and requires the keeper and the duplicates to belong to the same scanned group. It force-deletes attachments and runs a site-wide search and replace, so it must not act on IDs it has not just verified.
* Duplicate resolution now re-points size-variant URLs and JSON-escaped URLs. Resolving used to leave every sized reference pointing at a file it then deleted.
* Serialized replacement now recurses into nested serialization and recomputes the inner `s:N:` length prefixes. Rewriting the outer layer only produced rows that passed validation and were permanently unreadable, so the owning plugin's `get_option()` returned false.
* Serialized values containing PHP objects are detected and skipped. Unserializing with the class not loaded writes `__PHP_Incomplete_Class` over user data, and `__wakeup()` can mutate on the round trip. Any rewrite that does not read back is discarded.
* The duplicate's own post meta is excluded from the rewrite, so `_wp_attached_file` can never be re-pointed at the keeper's file just before `wp_delete_attachment()` deletes what that meta names.
* Rewrites no longer touch every row that merely mentions a path. Cron, rewrite rules, transients and edit locks are excluded by name.
* Oxygen matching uses full paths instead of bare filenames. `logo.png` was matching `site-logo.png`, and that count decides which copy an automatic resolve keeps.
* Scanning uses keyset pagination instead of `LIMIT`/`OFFSET`, which was stepping over live attachments whenever anything deleted rows mid-scan.

**Fixed - reliability and security**

* An expired nonce returned a bare `-1`, which is not JSON and reached the user as a generic "Invalid response from server". It now returns a real message and a `bad_nonce` code.
* All `$_POST` reads go through `wp_unslash()` and a sanitizer. The scan phase is validated against a whitelist.
* The inline JS config is emitted with `wp_json_encode()`. It was using `esc_js()`, which HTML-encodes quotes that are never decoded inside a script block, and the nonce was echoed raw.
* Resolving a duplicate no longer calls `wp_cache_flush()`. Emptying the entire object cache on every resolve is a site-wide performance event; only the affected posts and option keys are cleared now.
* `set_time_limit()` is guarded. It is in `disable_functions` on many shared hosts, and the warning lands inside the AJAX response and breaks the JSON parse.
* Plugin constants are `defined()` guarded for the same reason.
* HTML entities in translatable strings rendered literally - a heading really did read `Scanning&hellip;`.
* The JS HTML escaper now escapes quotes as well as angle brackets. Its output lands inside double-quoted attributes, and translated strings are a trust boundary.
* Existence checks use `SELECT 1 ... LIMIT 1` instead of `COUNT(*)`, so they stop at the first hit.
* Size-variant checks are OR'd into one query per table, so covering every variant costs about one table scan rather than one per variant.

**Added**

* Trash view with restore and a separate empty action, listing what Media Mage trashed rather than everything ever trashed on the site. Restore puts the attachment status back to `inherit`, without which it disappears from the media library.
* Ignore list, so files the plugin structurally cannot see references for can be marked as fine instead of returning to the top of every scan.
* "Where is this used?" - the posts referencing a file, with edit links and how the match was made. The reference count already ran those queries and discarded the results.
* CSV export of scan results, delivered through `admin-post` so the browser gets a real download.
* WP-CLI: `scan`, `duplicates`, `unused`, `resolve`, `delete`, `export`, `where`, `ignore` and `trash`. `resolve` and `delete` both take `--dry-run`.
* Results now report library total, ignored count and trashed count alongside reclaimable bytes.
* `wpmj_is_referenced` filter - the last word on whether an attachment counts as referenced, for references the plugin cannot see.
* `wpmj_replace_query` filter - control over which rows a rewrite is allowed to touch.
* Attachments whose file is missing from disk are tracked separately instead of being hashed together as identical.
* Thumbnails load lazily.
* Translation template at `languages/media-mage.pot`.

**Fixed - found by later adversarial passes over the same day's work**

* A serialized value containing a PHP back-reference (`R:`) sent the replacement walk into infinite recursion and exhausted memory. It fired part-way through resolving a duplicate, after post content had been rewritten and before the duplicate was removed, leaving the site half-migrated with no error. Back-references are now refused alongside objects, and the walk has a depth cap.
* A retried scan chunk counted its files twice. A chunk that finished on the server and lost its reply was replayed at the same cursor, so a file appeared twice in the unused list and its size was counted twice in the reclaimable total. Replays are now detected, and the lists dedupe as a backstop.
* `wp media-mage trash restore` accepted any post ID and forced its status to `inherit`, which is meaningful only for attachments. A trashed page restored this way vanished from the admin list, from every query and from the front end. It now checks the post type, the trash status, and that Media Mage was what trashed it.
* `wp media-mage trash empty` permanently deleted trashed media that Media Mage never trashed, including files a user had trashed by hand. Same three checks now apply.
* Files Media Mage had already trashed were re-reported as unused on the next scan, inviting the user to delete them again. Trashed attachments are now out of scope for scanning, matching what the WP-CLI path already did.
* The CSV export link never worked. `wp_nonce_url()` HTML-escapes the query separator, which survived into the link as literal text, so the nonce arrived under the wrong parameter name and the download failed as an expired link.
* Attachment ID matching was open-ended, so `wp-image-217` also matched `wp-image-21708` and an image could be reported as referenced because of a different one. Patterns are now anchored.
* Failed requests in the admin left buttons stuck in their in-progress state with nothing on screen, and a failed trash listing disabled the Trash tab for the rest of the page's life.
* Search text and sort order were lost whenever the results table re-rendered, so a filter could silently drop and bring back every row.
* The `reference_count` column in the exported CSV was always zero, including for duplicates, where that number is the entire basis for choosing which copy to keep.

**Added - reliability**

* Interrupted scans can be resumed. Failed requests retry with backoff, progress is recorded as the scan goes, and the scan card offers to carry on from where it stopped rather than starting over.
* A second scan cannot start while one is running. Scan state is a single shared record, so two people scanning at once produced two wrong answers. An abandoned scan can still be taken over.
* An empty media library reports itself as empty instead of leaving a stalled progress bar that looks like a crash.
* Results now report the library total, the ignored count, the trashed count and the number of attachments whose file is missing from disk, so the numbers have a denominator.
* Loading results is dramatically cheaper. Unused items are no longer re-checked for references they cannot have, and post and meta caches are primed in one pass. On a 300-item result this went from over 600 queries to four.


= 0.3.0 - 2026-04-26 =
* Savings dashboard showing total reclaimable bytes at the top of the results.
* Plugin icon in the admin menu and page header.
* "Last scanned" timestamp under the tab nav.
* Row hover highlight and clickable thumbnails that open the attachment in the media library.
* Loading state when auto-loading cached results.
* "Clear Results" shows a dismissible admin notice.
* Bulk actions end as a disabled "All Done" button when nothing remains.
* Smooth scroll to results after a fresh scan.

= 0.2.0 - 2026-04-08 =
* Oxygen Builder deep scan: decodes base64-encoded `_ct_builder_shortcodes`, `_ct_builder_json`, `ct_style_sheets` and `ct_components_classes` before checking for image references.
* Fixes false positives on Oxygen sites where references were invisible to plain LIKE queries.

= 0.1.0 - 2026-04-06 =
* Initial release: scan, detect, resolve, delete.
* MD5-based duplicate detection.
* Reference detection across post content, post meta, options, WooCommerce galleries and featured images.
* Per-file scan progress.
* "Automatically Resolve All" and "Cleanup Unused Media" bulk actions.

== Upgrade Notice ==

= 1.0.0 =
First public release. Deleting sends media to the trash rather than removing it, every file is re-checked against the full reference search immediately before it is touched, and duplicate resolution re-points every reference - including size variants - before anything is deleted.
