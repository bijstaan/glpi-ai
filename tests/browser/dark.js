// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// The dark pass, for checks that did not have one.
//
// Nine of the suite's browser checks grew a dark-palette section, each with its
// own copy of the same contrast probe and the same dark-user boilerplate. The
// other nine had dark screenshots in docs/ that no script produced — captured by
// hand once, never retaken, and still carrying pre-rename branding a year later.
// This is that boilerplate, written once, so adding a dark pass to a check is a
// dozen lines rather than a hundred.
//
// Copied into each plugin's tests/browser/ the way shot.js is, so a public
// checkout can run its own checks without this directory.
//
//   const { ensureDarkUser, openDark, audit } = require('./dark');
//
//   const dark = await openDark(browser, { plugin: 'glpipalette' });
//   const bad  = await audit(dark, 'glpipalette-');
'use strict';

const { execSync } = require('child_process');

const BASE = process.env.GLPI_BASE || 'http://localhost:8081';
const CONTAINER = process.env.GLPI_CONTAINER || 'glpi-glpi-1';

/**
 * `midnight` rather than one of GLPI's other two dark palettes.
 *
 * It is the one whose body is literally `#000` with `#e6e6e6` text, which is
 * what the house dark-theme rules are written against — and what makes the
 * near-white-panel trap (`--tblr-bg-surface-secondary` resolving to `#e6e6e6`
 * unchanged) visible at all. `darker` and `auror_dark` shade their body instead
 * of blacking it out, so the same mistake is far harder to see.
 */
const PALETTE = 'midnight';

function php(code) {
  return execSync(`docker exec -i ${CONTAINER} php`, {
    encoding: 'utf8',
    input:
      '<?php require "/var/www/glpi/vendor/autoload.php";'
      + '(new Glpi\\Kernel\\Kernel(Glpi\\Application\\Environment::PRODUCTION->value))->boot();'
      + '(new Auth())->login("glpi","glpi",true);(new Plugin())->init(true);'
      + code,
  }).trim();
}

/** The account a plugin's dark pass runs as. Never `glpi`, never a real user. */
function credentials(plugin) {
  return { user: `${plugin}-dark`, password: `${plugin}Dark!1`, palette: PALETTE };
}

/**
 * Create the dark-pass account, or put an existing one back on the palette.
 *
 * Its own account because a palette is a *user* preference: running the dark
 * pass as `glpi` would leave whoever uses that account next looking at a dark
 * instance, and would leave every later light capture dark. That has happened.
 */
function ensureDarkUser(plugin) {
  const { user, password, palette } = credentials(plugin);
  php(`
    global $DB;
    $existing = $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['name' => '${user}']])->current();
    if ($existing) {
        $DB->update('glpi_users', ['palette' => '${palette}', 'is_active' => 1], ['id' => $existing['id']]);
        $id = (int) $existing['id'];
    } else {
        $u  = new User();
        $id = (int) $u->add([
            'name' => '${user}', 'password' => '${password}', 'password2' => '${password}',
            '_profiles_id' => 4, 'entities_id' => 0, 'palette' => '${palette}', 'is_active' => 1,
        ]);
    }
    $pu = $DB->request(['FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $id]])->current();
    if ($pu && (int) $pu['is_recursive'] !== 1) {
        $DB->update('glpi_profiles_users', ['is_recursive' => 1], ['id' => $pu['id']]);
    } elseif (!$pu) {
        (new Profile_User())->add([
            'users_id' => $id, 'profiles_id' => 4, 'entities_id' => 0, 'is_recursive' => 1,
        ]);
    }
    echo $id;
  `);
  return { user, password, palette };
}

/**
 * A second browser context, signed in on the dark palette.
 *
 * A context of its own rather than the light pass's: GLPI keeps the active tab
 * and the last-visited entity in the session, and reusing one session for both
 * passes makes each capture depend on where the other one had been.
 */
async function openDark(browser, { plugin, viewport = { width: 1500, height: 1100 } } = {}) {
  const { user, password, palette } = ensureDarkUser(plugin);

  const ctx = await browser.newContext({ viewport });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => page.__darkErrors.push(e.message));
  page.__darkErrors = [];

  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.fill('#login_name', user);
  await page.fill('input[type=password]', password);
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');

  const theme = await page.evaluate(() => ({
    theme: document.documentElement.getAttribute('data-glpi-theme'),
    isDark: document.documentElement.getAttribute('data-glpi-theme-dark'),
  }));
  if (theme.theme !== palette || theme.isDark !== '1') {
    throw new Error(
      `the dark account is not on the dark palette: ${JSON.stringify(theme)} (wanted ${palette})`,
    );
  }

  return page;
}

/**
 * What a dark palette actually gets wrong, measured rather than eyeballed.
 *
 * Two failures, both of which have happened and neither of which is visible in
 * a light-mode test:
 *
 *   - a panel that keeps a near-white background because its colour came from a
 *     Tabler variable the dark palette does not redefine, leaving dark body text
 *     on white inside an otherwise black page;
 *   - muted text (`.form-text`, `.text-muted`) whose grey was chosen against a
 *     white ground and drops below 4.5:1 against a black one.
 *
 * Contrast is computed against the first ancestor with a real background, not
 * against the element's own transparent one.
 */
function audit(page, prefix) {
  return page.evaluate((sel) => {
    const lum = (rgb) => {
      const [r, g, b] = rgb.map((v) => {
        const c = v / 255;
        return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
      });
      return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const contrast = (a, b) => {
      const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
      return (hi + 0.05) / (lo + 0.05);
    };
    const parse = (str) => {
      const m = (str || '').match(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\)/);
      return m ? { rgb: [+m[1], +m[2], +m[3]], a: m[4] === undefined ? 1 : +m[4] } : null;
    };
    const effectiveBg = (el) => {
      for (let node = el; node; node = node.parentElement) {
        const p = parse(getComputedStyle(node).backgroundColor);
        if (p && p.a > 0.5) return p.rgb;
      }
      return [0, 0, 0];
    };

    const whiteBg = [];
    document.querySelectorAll(`[class*="${sel}"]`).forEach((el) => {
      const bg = parse(getComputedStyle(el).backgroundColor);
      if (!bg || bg.a < 0.4) return;
      if (bg.rgb.every((c) => c > 207)) {
        whiteBg.push({ cls: String(el.className).slice(0, 60), rgb: bg.rgb.join(',') });
      }
    });

    const lowContrast = [];
    document
      .querySelectorAll(`[class*="${sel}"] .form-text, [class*="${sel}"] .text-muted`)
      .forEach((el) => {
        const fg = parse(getComputedStyle(el).color);
        if (!fg || el.textContent.trim() === '') return;
        const ratio = contrast(fg.rgb, effectiveBg(el));
        if (ratio < 4.5) {
          lowContrast.push({
            text: el.textContent.trim().slice(0, 50),
            ratio: Math.round(ratio * 100) / 100,
          });
        }
      });

    return { whiteBg, lowContrast };
  }, prefix);
}

module.exports = { BASE, PALETTE, php, credentials, ensureDarkUser, openDark, audit };
