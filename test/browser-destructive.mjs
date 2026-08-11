const BASE = 'http://localhost/library/Claude%20Code%20Projects/wpmm-test-1';
const PAGE = BASE + '/wp-admin/upload.php?page=media-mage';

const ok = (out, name, cond, detail) => {
  out.checks.push({ name, pass: !!cond, ...(cond ? {} : { detail }) });
};

export default async function run(page, ui) {
  const out = { checks: [] };

  await page.route('**/*', r =>
    r.request().url().startsWith('http://localhost') ? r.continue() : r.abort());

  await page.goto(BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', process.env.WPUSER);
  await page.fill('#user_pass', process.env.WPPASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.click('#wp-submit'),
  ]);

  await page.goto(PAGE, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);

  // Scan.
  await page.click('#wpmj-scan-btn');
  await page.waitForSelector('#wpmj-results', { state: 'visible', timeout: 240000 });
  await page.waitForFunction(() => !document.querySelector('#wpmj-scan-btn').disabled, { timeout: 240000 });
  await page.waitForTimeout(600);

  await page.click('#wpmj-tab-unused');
  await page.waitForTimeout(400);

  const startRows = await page.locator('#wpmj-unused-table tbody tr').count();
  out.startRows = startRows;
  ok(out, 'unused rows present to work with', startRows >= 3, `rows=${startRows}`);
  if (startRows < 3) return out;

  // ---- 1. Cancelling the dialog must NOT delete anything ----
  await page.locator('#wpmj-unused-table tbody tr').first().locator('.wpmj-unused-cb').check();
  await page.click('#wpmj-delete-unused-btn');
  await page.waitForTimeout(500);
  out.dialogOpenedOnDelete = await page.evaluate(() => document.getElementById('wpmj-dialog')?.open === true);
  ok(out, 'destructive action opens the dialog', out.dialogOpenedOnDelete);

  out.dialogListsFiles = await page.evaluate(() => {
    const f = document.getElementById('wpmj-dialog-files');
    return f && !f.hidden && f.textContent.trim().length > 0;
  });
  ok(out, 'dialog lists the affected filenames', out.dialogListsFiles);

  await page.click('#wpmj-dialog-cancel');
  await page.waitForTimeout(700);
  const afterCancel = await page.locator('#wpmj-unused-table tbody tr').count();
  ok(out, 'cancelling the dialog deletes nothing', afterCancel === startRows,
    `before=${startRows} after=${afterCancel}`);

  // ---- 2. Escape key also cancels ----
  await page.click('#wpmj-delete-unused-btn');
  await page.waitForTimeout(400);
  await page.keyboard.press('Escape');
  await page.waitForTimeout(600);
  const afterEsc = await page.locator('#wpmj-unused-table tbody tr').count();
  ok(out, 'Escape cancels the dialog and deletes nothing', afterEsc === startRows,
    `after=${afterEsc}`);

  // ---- 3. Ignore removes a row and drops the badge ----
  const ignoredName = await page.locator('#wpmj-unused-table tbody tr').first().locator('strong').textContent();
  const badgeBefore = parseInt(await page.locator('#wpmj-unused-count').textContent(), 10);
  await page.locator('#wpmj-unused-table tbody tr').first().locator('.wpmj-ignore-btn').click();
  await page.waitForTimeout(1200);
  const rowsAfterIgnore = await page.locator('#wpmj-unused-table tbody tr').count();
  const badgeAfterIgnore = parseInt(await page.locator('#wpmj-unused-count').textContent(), 10);
  out.ignored = { name: ignoredName, badgeBefore, badgeAfterIgnore, rowsAfterIgnore };
  ok(out, 'Ignore removes the row', rowsAfterIgnore === startRows - 1, `rows=${rowsAfterIgnore}`);
  ok(out, 'Ignore decrements the unused badge', badgeAfterIgnore === badgeBefore - 1,
    `${badgeBefore} -> ${badgeAfterIgnore}`);

  // ---- 4. Trash one file, confirming this time ----
  const trashName = await page.locator('#wpmj-unused-table tbody tr').first().locator('strong').textContent();
  await page.locator('#wpmj-unused-table tbody tr').first().locator('.wpmj-unused-cb').check();
  await page.click('#wpmj-delete-unused-btn');
  await page.waitForTimeout(500);
  await page.click('#wpmj-dialog-ok');
  await page.waitForTimeout(2500);

  const rowsAfterTrash = await page.locator('#wpmj-unused-table tbody tr').count();
  const trashBadge = await page.locator('#wpmj-trash-count').textContent();
  out.trashed = { name: trashName, rowsAfterTrash, trashBadge };
  ok(out, 'confirming the dialog removes the row', rowsAfterTrash === rowsAfterIgnore - 1,
    `rows=${rowsAfterTrash}`);
  ok(out, 'trash badge incremented', parseInt(trashBadge, 10) >= 1, `badge=${trashBadge}`);

  // ---- 5. The trashed file appears in the Trash tab ----
  await page.click('#wpmj-tab-trashed');
  await page.waitForTimeout(2000);
  out.trashTabRows = await page.locator('#wpmj-trash-table tbody tr').count();
  out.trashTabHasFile = await page.evaluate(n =>
    (document.getElementById('wpmj-panel-trashed')?.textContent || '').includes(n), trashName);
  ok(out, 'trashed file is listed in the Trash tab', out.trashTabHasFile, `looking for ${trashName}`);

  // ---- 6. Restore puts it back ----
  if (out.trashTabRows > 0) {
    await page.locator('#wpmj-trash-table tbody tr').first().locator('.wpmj-restore-btn').click();
    await page.waitForTimeout(2500);
    out.trashRowsAfterRestore = await page.locator('#wpmj-trash-table tbody tr').count();
    out.restoredGone = await page.evaluate(n =>
      !(document.getElementById('wpmj-panel-trashed')?.textContent || '').includes(n), trashName);
    ok(out, 'restore removes it from the Trash tab', out.restoredGone,
      `rows now ${out.trashRowsAfterRestore}`);
  }

  out.consoleClean = true;
  return out;
}
