# Media Mage

[![License: GPL v2 or later](https://img.shields.io/badge/License-GPLv2%20or%20later-blue.svg)](LICENSE)

A WordPress plugin that finds duplicate and unused media, checks every reference before it deletes anything, and sends removals to the trash so a mistake stays reversible.

> The plugin directory listing is `readme.txt`. This file is the GitHub-facing version of the same information.

## What it does

**Duplicates.** Every file in the media library is hashed with MD5, so the same image uploaded three times under three names is found as one group regardless of filename. You choose which copy to keep. Media Mage rewrites every reference to the keeper before it removes the rest.

**Unused media.** An attachment is only reported unused when nothing in the database references it. The search covers:

- Post content of any post, page or custom post type, including trashed posts
- Post meta - ACF, Elementor, Divi, page-builder payloads, plain custom fields
- Term meta, user meta, comment meta and comment content
- The options table - theme mods, customizer values, widget areas
- Featured images and the attachment's own post parent
- Site logo and site icon
- WooCommerce product galleries
- Oxygen Builder base64-encoded layout data
- Gutenberg block IDs and `wp-image-N` classes, for blocks that carry an ID and no URL

Each check runs against every URL the attachment can appear under: the full-size file, every generated size variant, the `-scaled` version and the preserved original. It also matches the JSON-escaped form (`\/`), which is how block attributes and JSON meta store URLs.

Checking only the full-size URL is the classic way these tools go wrong. An image inserted at Medium embeds the medium URL and never the full-size one, so a full-size-only search reports a live image as unused.

## Install

Requires WordPress 5.5+ and PHP 7.4+.

**From a release zip**

1. Build or download `media-mage-<version>.zip`.
2. In WordPress, go to **Plugins > Add New > Upload Plugin** and upload it.
3. Activate, then go to **Media > Media Mage**.

**From a clone**

```bash
git clone https://github.com/LTracy86/media-mage.git wp-content/plugins/media-mage
```

Then activate it on the Plugins screen. Note that a clone includes `test/`, which is a local development harness and should never be present on a production server. Package with `build.sh` instead of copying the directory:

```bash
bash media-mage/build.sh
```

That writes a clean zip to `dist/` in the parent directory, with `test/` and all packaging metadata excluded.

Take a database and uploads backup before the first scan on a site that matters.

## How it stays safe

A tool that deletes media is one bad search away from destroying a live site. Four things sit between the scan and the delete.

**Deletes go to the trash.** Unused media is trashed rather than removed. The post row and the file on disk both survive, so a wrong call can be undone from the Trash tab. Disk space is reclaimed when the trash is emptied, which is a separate and explicit action. Permanent removal is never the default. The Trash tab lists what Media Mage trashed rather than everything ever trashed on the site, and restoring puts the attachment status back to `inherit`, without which it would vanish from the media library.

**Every file is re-verified at delete time.** The scan produces a snapshot, and a snapshot goes stale. Scan at 09:00, put one of those images on the homepage at 11:00, click delete at 12:00, and the snapshot is lying. Media Mage runs the full reference search again against each file immediately before it touches it. Anything back in use is skipped and reported instead of deleted.

**Deletes are restricted to an allow-list.** A delete request is intersected with the IDs in the current scan results, and anything outside that set is refused. Expired results fail closed - a missing results transient rejects the request rather than skipping the check. The same validation applies to duplicate resolution, which additionally requires the keeper and the duplicates to belong to the same scanned group.

**Rewrites are conservative.** Resolving a duplicate re-points post content, post meta, options, theme mods, WooCommerce galleries and Oxygen data to the keeper before anything is deleted, including every size variant and every JSON-escaped form. Nested serialized values are rewritten with their length prefixes recomputed at each level, values containing PHP objects are skipped rather than round-tripped, and a rewrite that will not read back is discarded. Cron, rewrite rules, transients and edit locks are excluded by name, because a backup plugin's run log that happens to mention a filename is not a reference and editing it is its own kind of damage.

## Also in the box

- **Trash tab** with restore and a separate empty action.
- **Ignore list** for files the plugin structurally cannot see references for, so they stop returning to the top of every scan.
- **"Where is this used?"** - the posts referencing a file, with edit links and how the match was made.
- **CSV export** of the scan results, so there is a record of what was found before anything is deleted.
- **Two filters**, `wpmj_is_referenced` and `wpmj_replace_query`.
- **Uninstall** removes every row the plugin wrote, on single sites and across a multisite network.

## WP-CLI

Every operation the admin screen performs is on the command line. `resolve` and `delete` both take `--dry-run`, which reports exactly what would happen and changes nothing.

```bash
wp media-mage scan
wp media-mage duplicates --format=json
wp media-mage unused
wp media-mage where <id>              # which posts reference this attachment

wp media-mage resolve --dry-run       # report, change nothing
wp media-mage delete --dry-run
wp media-mage delete --permanent --yes

wp media-mage export --file=report.csv
wp media-mage ignore add <id>...
wp media-mage ignore list
wp media-mage trash list
wp media-mage trash restore <id>...
wp media-mage trash empty --yes
```

`resolve` and `delete` both take `--dry-run`. Use it first.

## Limitations

Media Mage reads the database. It does not read your code.

- **Theme and plugin PHP is invisible.** An image path hardcoded in a template, a child theme, or a plugin file will not be found, and that image will be listed as unused.
- **CDN rewrites are invisible.** If URLs are rewritten at serve time so the stored value no longer matches what the browser requests, the stored value is what gets searched.
- **Resolving a duplicate cannot be undone.** The references are rewritten to the keeper and the duplicate is gone. Deleting unused media is the reversible one, until the trash is emptied.
- Read the unused list before acting on it. That advice is not boilerplate.

For references the plugin cannot see, `wpmj_is_referenced` runs after every built-in check has come up empty:

```php
add_filter( 'wpmj_is_referenced', function ( $referenced, $att_id, $paths ) {
	if ( in_array( $att_id, [ 42, 108 ], true ) ) {
		return true; // hardcoded in the theme, hands off
	}
	return $referenced;
}, 10, 3 );
```

`wpmj_replace_query` gives the same control over which rows a rewrite is allowed to touch.

## Repository layout

| Path | Purpose |
|---|---|
| `media-mage.php` | The plugin. Single file. |
| `uninstall.php` | Removes every row the plugin wrote, single site and multisite. |
| `readme.txt` | WordPress.org plugin directory readme. |
| `languages/media-mage.pot` | Translation template. |
| `assets/icon.png` | Admin menu icon. |
| `test/` | Local development harness. Destructive. Never ships - see `.distignore`. |
| `build.sh` | Release packager. Writes to `dist/` outside the plugin directory. |

`test/setup.sh` drops a MySQL database and deletes a directory, and the seed scripts delete attachments. Do not run anything in `test/` against an install you care about.

## License

GPLv2 or later. See [LICENSE](LICENSE).

## Author

Lincoln Tracy, [Tracy Digital Media LLC](https://tracydigitalmedia.com/).

Free, GPLv2, no pro tier and no license key. If it saved you an afternoon, there is a tip jar: [buymeacoffee.com/lincolntracy](https://buymeacoffee.com/lincolntracy). Never required.
