# media-mage test environment

A reproducible WordPress install + fixture set for testing the duplicate / unused detection. Tear down and re-create as often as you want — the setup script is idempotent and fast (~10s on first run, faster on re-runs).

## Setup

Prerequisites: XAMPP (PHP 8.2+, MySQL on `localhost`), WP-CLI (`wp-cli.phar` at `Claude Code Projects/wp-cli.phar`).

```bash
bash media-mage/test/setup.sh
```

This will:
1. Drop + recreate the `wpmm_test_1` MySQL database.
2. Wipe + recreate the install at `Claude Code Projects/wpmm-test-1/`.
3. `wp core download` the latest WordPress.
4. `wp core install` with the admin user from your gitignored creds file (see `setup.sh`).
5. Copy the current `media-mage/media-mage.php` + `assets/` into the install.
6. Activate the plugin.
7. Seed fixtures (10 attachments, 5 posts, 1 page).

When it finishes you'll see:

```
Admin URL: http://localhost/library/Claude%20Code%20Projects/wpmm-test-1/wp-admin/
Login:     <the WP_ADMIN_USER / WP_ADMIN_PASS from your creds file>
Media Mage: http://localhost/library/Claude%20Code%20Projects/wpmm-test-1/wp-admin/upload.php?page=media-mage
```

To re-seed fixtures without rebuilding the WP install (faster):

```bash
/c/xampp/php/php.exe \
  "/c/xampp/htdocs/library/Claude Code Projects/wp-cli.phar" \
  --path="/c/xampp/htdocs/library/Claude Code Projects/wpmm-test-1" \
  eval-file "/c/xampp/htdocs/library/Claude Code Projects/media-mage/test/seed.php"
```

To verify the plugin classifies the seeded fixtures correctly (CLI smoke test):

```bash
/c/xampp/php/php.exe \
  "/c/xampp/htdocs/library/Claude Code Projects/wp-cli.phar" \
  --path="/c/xampp/htdocs/library/Claude Code Projects/wpmm-test-1" \
  eval-file "/c/xampp/htdocs/library/Claude Code Projects/media-mage/test/verify.php"
```

`verify.php` calls the plugin's internal `wpmj_is_referenced()` against every attachment, computes MD5s for duplicate groups, and prints `PASS` if the classification matches the expected list. Use this to sanity-check the plugin after edits without clicking through the admin UI.

## The harnesses

`verify.php` checks classification. The three harnesses check behaviour, by driving the **real AJAX handlers** the way the browser does - `wp_doing_ajax` is forced true and the ajax die handler is swapped for a throw, so `wp_send_json_*` can be caught and its JSON read back.

```bash
/c/xampp/php/php.exe "…/wp-cli.phar" --path="…/wpmm-test-1" eval-file media-mage/test/harness.php
```

| file | covers | assertions |
|---|---|---|
| `harness.php` | full two-phase scan, chunk coverage, result consistency, duplicate + unused membership cross-checked against independent MD5 and reference passes, idempotency, interrupt-and-resume, the delete allow-list | 45 |
| `harness-resolve.php` | the destructive resolve path against an isolated fixture: keeper survives on disk with its meta intact, references re-point (including size variants and escaped-slash URLs), serialized values round-trip, object-bearing rows are skipped rather than corrupted, a superstring-named decoy is untouched, core options undamaged | 42 |
| `harness-detect.php` | one fixture per reference source (sized-URL-only, block ID, `wp-image-N`, escaped slashes, site logo/icon, term/user/comment meta, comment content, draft, trashed), plus a control group that must come back unreferenced, plus nested-serialization and delete-time re-verification | 28 |

`harness-resolve.php` and `harness-detect.php` build and tear down their own fixtures. `harness.php` reads the seeded set and asserts only about files matching the documented naming convention, so a neighbouring fixture does not fail it.

**Note on idempotency:** `harness.php` asserts a re-scan gives the same answer. That is only true if nothing else is mutating the library while it runs - a concurrent session adding attachments will fail it legitimately.

## Browser QA

The PHP harnesses cannot catch a JavaScript error, and a broken admin page still returns HTTP 200. These drive a real headless browser through the real UI.

```bash
. ~/.wp-local-creds
WPUSER="$WP_ADMIN_USER" WPPASS="$WP_ADMIN_PASS" \
  node ~/.claude/skills/browser-automation/browser.mjs \
  "http://localhost/library/Claude%20Code%20Projects/wpmm-test-1/wp-login.php" \
  --script media-mage/test/browser-qa.mjs
```

- `browser-qa.mjs` - logs in, runs a full scan, and asserts the progress bar reaches 100% with a matching `aria-valuenow`, the badges and savings banner populate, the unused toolbar renders with search/sort/export/ignore/lazy thumbnails, search actually filters, the where-used drill-down renders links, the trash tab loads, and arrow keys move between tabs updating `aria-selected`.
- `browser-destructive.mjs` - exercises the paths that destroy data: the confirmation dialog opens and lists the affected filenames, **Cancel deletes nothing**, **Escape deletes nothing**, Ignore removes a row and decrements the badge, confirming moves a file to trash, the Trash tab lists it, and Restore removes it again.

Both block off-host requests, because this machine has no outbound network and gravatar alone adds minutes.

This is worth running before any release. One bug tonight was invisible to both `php -l` and `node --check`: a code comment containing a literal closing `script` tag closed the block, and everything after it rendered into the page as HTML. Only loading the page showed it.

To stress-test the admin UI with a realistic site size (500 images, 12 dup groups, 130 unused, 38 posts embedding 375 images):

```bash
/c/xampp/php/php.exe \
  "/c/xampp/htdocs/library/Claude Code Projects/wp-cli.phar" \
  --path="/c/xampp/htdocs/library/Claude Code Projects/wpmm-test-1" \
  eval-file "/c/xampp/htdocs/library/Claude Code Projects/media-mage/test/seed-bulk.php"
```

`seed-bulk.php` is independent from `seed.php` - they use different postmeta tags (`_wpmj_test_seed=1` vs `_wpmj_test_bulk=1`) so you can run either or both without one wiping the other. Use the small `seed.php` set for correctness verification, the bulk set for UI feel-testing (long unused tables, many dup groups, perceptible scan time, scrolling, etc).

Tunable constants at the top of `seed-bulk.php`:

```php
const BULK_REFERENCED_UNIQUE   = 350;  // most of the library - posts content
const BULK_UNREFERENCED_UNIQUE = 100;  // orphans -> Unused panel
const BULK_DUP_GROUPS_REF      = 5;    // dup groups, all copies referenced
const BULK_DUP_GROUPS_ORPHAN   = 5;    // dup groups, all copies orphan
const BULK_DUP_GROUP_SIZE      = 5;    // copies per dup group
const BULK_IMAGES_PER_POST     = 10;
```

Bump those for a heavier load (1000+ images) if you want to feel where the UI starts to break.

## Filename scheme

Every test image's expected behavior is encoded in its filename:

```
{ref-status}-{dup-status}-{n}.png

ref-status:    referenced     - linked from a post body, featured image, or postmeta
               unreferenced   - orphan, not linked anywhere

dup-status:    unique         - one-of-a-kind content (unique MD5)
               dupA, dupB ... - shares content with other files in the same group
                                 (identical bytes, different filenames -> same MD5)
```

Quick read:

| Filename starts with | Should appear in Duplicates? | Should appear in Unused? |
|---|---|---|
| `referenced-unique-` | no | no |
| `referenced-dupX-`   | yes (group X) | no |
| `unreferenced-unique-` | no | yes |
| `unreferenced-dupX-` | yes (group X) | yes (in BOTH panels) |

## Fixture set

10 attachments, 5 posts, 1 page:

| File | Bytes | Reference | Expected Dup | Expected Unused |
|---|---|---|---|---|
| `referenced-unique-1.png` | unique | embedded in Test Post 1 body | - | - |
| `referenced-unique-2.png` | unique | featured image of Test Post 2 | - | - |
| `referenced-unique-3.png` | unique | URL stored in `_extra_image` postmeta of Test Post 3 | - | - |
| `referenced-dupA-1.png` | red 128px (group A) | embedded in Test Post 4 body | yes | - |
| `referenced-dupA-2.png` | red 128px (group A) | embedded in Test Post 5 body | yes | - |
| `unreferenced-unique-1.png` | unique | none | - | yes |
| `unreferenced-unique-2.png` | unique | none | - | yes |
| `unreferenced-dupB-1.png` | green 128px (group B) | none | yes | yes |
| `unreferenced-dupB-2.png` | green 128px (group B) | none | yes | yes |
| `unreferenced-dupB-3.png` | green 128px (group B) | none | yes | yes |

## Expected scan output

After running the seed, opening Media Mage and clicking "Scan Media Library" should produce:

**Duplicates panel: 2 groups**
- Group A: 2 copies (`referenced-dupA-1`, `referenced-dupA-2`)
- Group B: 3 copies (`unreferenced-dupB-1/2/3`)

**Unused panel: 5 files**
- `unreferenced-unique-1`, `unreferenced-unique-2`
- `unreferenced-dupB-1`, `unreferenced-dupB-2`, `unreferenced-dupB-3`

**Savings banner:** non-zero reclaimable bytes; clicking each group/row should expand and show the keeper choice.

## What this catches

- **Hash-based dup detection:** group A (referenced + dup) and group B (unreferenced + dup).
- **Reference detection paths exercised:**
  - `post_content` (img tag URL)
  - `_thumbnail_id` (featured image)
  - `postmeta` value containing the URL (custom field)
- **Cross-over case:** unreferenced-dupB-* files appear in BOTH panels - exercises the resolve flow on items that are also unused.
- **Idempotent re-seed:** all fixtures are tagged with `_wpmj_test_seed=1` postmeta so seed.php can find and delete them on a re-run.

## What it does NOT catch (yet)

- Oxygen Builder base64 detection (no Oxygen plugin installed in the test env).
- WooCommerce gallery references.
- Elementor / Gutenberg block-attribute ID-based references.
- Multisite scenarios.
- Very large media libraries (10k+ items - perf testing).

These are documented in the project review's "What's missing in reference detection" section. Add scenarios here as they're needed.

## Tear down

The setup script is destructive on its own scope only - it drops the `wpmm_test_1` DB and removes the `wpmm-test-1/` directory. It does not touch other XAMPP installs or other databases.

To remove the test env entirely:

```bash
"/c/xampp/mysql/bin/mysql.exe" -u root -e "DROP DATABASE IF EXISTS wpmm_test_1;"
rm -rf "/c/xampp/htdocs/library/Claude Code Projects/wpmm-test-1"
```
