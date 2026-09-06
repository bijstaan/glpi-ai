// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// Verifies the front/config.php clamp-warning fix: an out-of-range number must
// be corrected AND the correction must be said out loud after redirect.
//
// The whole glpiai config context is snapshotted first and restored last via
// raw rows (config-guard), because pressing Save on this page rewrites every
// setting from the POST body.
const { chromium } = require('playwright');
const guard = require('./config-guard');

(async () => {
  const snap = guard.snapshot();
  let failed = false;
  const check = (ok, label) => {
    console.log((ok ? '  ok  ' : '  FAIL') + ' ' + label);
    if (!ok) failed = true;
  };
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.goto('http://localhost:8081/');
    await page.fill('input[name="login_name"], #login_name', 'glpi');
    await page.fill('input[type="password"]', 'glpi');
    await page.click('button[type="submit"]');
    await page.waitForURL(/central/);

    await page.goto('http://localhost:8081/plugins/glpiai/front/config.php');
    const timeout = page.locator('input[name="timeout"]');
    await timeout.waitFor();
    await timeout.fill('9999');
    await Promise.all([
      page.waitForURL(/config\.php/),
      page.click('button[name="update"], input[name="update"]'),
    ]);

    const body = await page.textContent('body');
    check(/must be between 5 and 300/.test(body), 'clamp warning is rendered after save');
    check(/9999/.test(body) && /saved as 300/.test(body), 'warning names the posted and saved values');
    const saved = await page.inputValue('input[name="timeout"]');
    check(saved === '300', `timeout field shows the clamped value (got ${saved})`);
  } finally {
    await browser.close();
    guard.restore(snap);
    const ok = guard.snapshot() === snap;
    console.log((ok ? '  ok  ' : '  FAIL') + ' config restored byte-identically');
    if (!ok) failed = true;
  }
  process.exit(failed ? 1 : 0);
})();
