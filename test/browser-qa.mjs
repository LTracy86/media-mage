const BASE = 'http://localhost/library/Claude%20Code%20Projects/wpmm-test-1';
const PAGE = BASE + '/wp-admin/upload.php?page=media-mage';

export default async function run(page, ui) {
  const out = {};

  // This box has no outbound network, so gravatar and the dashboard RSS widget
  // hang for minutes and swamp the real timings. Kill anything off-host.
  await page.route('**/*', route => {
    const u = route.request().url();
    if (u.startsWith('http://localhost')) return route.continue();
    return route.abort();
  });

  // Log in.
  await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', process.env.WPUSER);
  await page.fill('#user_pass', process.env.WPPASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.click('#wp-submit'),
  ]);
  out.loggedIn = page.url().includes('wp-admin');

  // Open the plugin page.
  await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1200);

  out.title = await page.title();
  out.hasApp = await page.locator('#wpmj-app').count();
  out.tabs = await page.locator('[role="tab"]').allTextContents();
  out.scanBtnEnabled = await page.locator('#wpmj-scan-btn').isEnabled();

  // Does the config object actually exist at runtime?
  out.runtime = await page.evaluate(() => {
    const el = document.getElementById('wpmj-scan-btn');
    return {
      scanBtn: !!el,
      dialogSupported: typeof document.getElementById('wpmj-dialog')?.showModal === 'function',
      progressRole: document.getElementById('wpmj-bar-wrap')?.getAttribute('role') || null,
      statusRole: document.getElementById('wpmj-status')?.getAttribute('role') || null,
    };
  });

  // Drive a real scan. This is the whole point - the chunk loop and the
  // rendering only fail in a browser.
  await page.click('#wpmj-scan-btn');
  try {
    await page.waitForSelector('#wpmj-results', { state: 'visible', timeout: 180000 });
    await page.waitForFunction(
      () => document.querySelector('#wpmj-scan-btn') && !document.querySelector('#wpmj-scan-btn').disabled,
      { timeout: 180000 }
    );
  } catch (e) {
    out.scanError = String(e).slice(0, 160);
    out.scanDebug = await page.evaluate(() => ({
      resultsDisplay: document.getElementById('wpmj-results')?.style.display,
      btnDisabled: document.getElementById('wpmj-scan-btn')?.disabled,
      bar: document.getElementById('wpmj-bar-label')?.textContent,
      logLines: document.querySelectorAll('#wpmj-log div').length,
      lastLog: [...document.querySelectorAll('#wpmj-log div')].slice(-6).map(d => d.textContent),
      errLog: [...document.querySelectorAll('#wpmj-log div.err')].map(d => d.textContent),
    }));
  }
  await page.waitForTimeout(800);

  out.afterScan = await page.evaluate(() => ({
    barPct: document.getElementById('wpmj-bar-label')?.textContent,
    ariaNow: document.getElementById('wpmj-bar-wrap')?.getAttribute('aria-valuenow'),
    dupBadge: document.getElementById('wpmj-dup-count')?.textContent,
    unusedBadge: document.getElementById('wpmj-unused-count')?.textContent,
    trashBadge: document.getElementById('wpmj-trash-count')?.textContent,
    meta: document.getElementById('wpmj-meta')?.textContent,
    savings: document.querySelector('.wpmj-savings .total')?.textContent,
    lastLog: [...document.querySelectorAll('#wpmj-log div')].slice(-2).map(d => d.textContent),
  }));

  if (out.scanError) return out;

  // Unused tab: toolbar, search, ignore button, lazy thumbnails.
  await page.click('#wpmj-tab-unused');
  await page.waitForTimeout(400);
  out.unusedTab = await page.evaluate(() => ({
    rows: document.querySelectorAll('#wpmj-unused-table tbody tr').length,
    hasSearch: !!document.getElementById('wpmj-search'),
    hasSort: !!document.getElementById('wpmj-sort'),
    hasExport: !!document.querySelector('#wpmj-panel-unused a.button'),
    ignoreButtons: document.querySelectorAll('.wpmj-ignore-btn').length,
    lazyThumbs: document.querySelectorAll('#wpmj-unused-table img[loading="lazy"]').length,
    trashAllLabel: document.getElementById('wpmj-cleanup-all-btn')?.textContent,
    trashAllIsPrimary: document.getElementById('wpmj-cleanup-all-btn')?.classList.contains('button-primary'),
    deleteSelLabel: document.getElementById('wpmj-delete-unused-btn')?.textContent,
  }));

  // The CSV export link must actually download a CSV. Checking only that the
  // button exists missed a real bug: wp_nonce_url() html-escapes the separator
  // to "&#038;", which survived escaping into the href as literal text, so the
  // nonce arrived named "#038;_wpnonce" and the export 403'd.
  out.csvExport = await page.evaluate(async () => {
    const a = document.querySelector('#wpmj-panel-unused a.button');
    if (!a) return { error: 'no export link' };
    const href = a.getAttribute('href');
    const resp = await fetch(href, { credentials: 'same-origin' });
    const text = await resp.text();
    return {
      hrefHasEntity: href.indexOf('&#038;') !== -1,
      status: resp.status,
      contentType: resp.headers.get('content-type'),
      firstLine: text.replace(/^﻿/, '').split('\n')[0].slice(0, 90),
      lines: text.trim().split('\n').length,
    };
  });

  // Search actually filters.
  if (out.unusedTab.hasSearch) {
    await page.fill('#wpmj-search', 'dupB');
    await page.waitForTimeout(600);
    out.searchFiltered = await page.evaluate(() =>
      document.querySelectorAll('#wpmj-unused-table tbody tr').length);
    await page.fill('#wpmj-search', '');
    await page.waitForTimeout(600);
  }

  // Duplicates tab: where-used drill-down.
  await page.click('#wpmj-tab-duplicates');
  await page.waitForTimeout(300);
  out.dupTab = await page.evaluate(() => {
    const det = document.querySelector('#wpmj-panel-duplicates details.wpmj-refs');
    return {
      groups: document.querySelectorAll('.wpmj-group').length,
      hasWhereUsed: !!det,
      whereUsedSummary: det ? det.querySelector('summary')?.textContent : null,
      whereUsedLinks: det ? det.querySelectorAll('li').length : 0,
      keeperRadiosLabelled: [...document.querySelectorAll('#wpmj-panel-duplicates input[type=radio]')]
        .every(r => !!r.getAttribute('aria-label')),
    };
  });

  // Trash tab loads.
  await page.click('#wpmj-tab-trashed');
  await page.waitForTimeout(1200);
  out.trashTab = await page.evaluate(() => ({
    hidden: document.getElementById('wpmj-panel-trashed')?.hidden,
    text: (document.getElementById('wpmj-panel-trashed')?.textContent || '').slice(0, 120),
  }));

  // Keyboard: arrow keys must move between tabs.
  await page.focus('#wpmj-tab-duplicates');
  await page.keyboard.press('ArrowRight');
  await page.waitForTimeout(250);
  out.keyboardNav = await page.evaluate(() => ({
    focused: document.activeElement?.id,
    selected: document.getElementById('wpmj-tab-unused')?.getAttribute('aria-selected'),
  }));

  return out;
}
