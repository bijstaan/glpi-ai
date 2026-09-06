// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// Whole-page screenshots of GLPI, for documentation.
//
// A tightly-cropped form is a bad illustration: it shows a reader the fields
// and not where to find them. What a doc screenshot needs is the chrome — the
// sidebar, the breadcrumb, the section the page sits in — so the picture
// answers "where am I" as well as "what do I fill in".
//
// Playwright's fullPage capture does that badly on GLPI out of the box: the
// sidebar is `position: fixed`, so on a page taller than the viewport it is
// painted once, at whatever scroll offset the capture happened to be at, and
// lands in the middle of the image. Pinning it to absolute for the duration of
// the shot is the whole fix.
'use strict';

/**
 * Capture the entire page, navigation included.
 *
 * @param {import('playwright').Page} page
 * @param {string} path        where to write the PNG
 * @param {object} [opts]
 * @param {string} [opts.highlight]  a selector to outline, for pointing at one
 *                                   control on an otherwise busy page
 * @param {boolean} [opts.keepToasts] leave GLPI's flash messages in place.
 *                                    Off by default: a toast floats over the
 *                                    form and hides whatever is behind it, and
 *                                    "Item successfully added" is not what the
 *                                    screenshot is about. Turn it on when the
 *                                    message *is* the subject.
 */
async function fullPage(page, path, opts = {}) {
  const style = await page.addStyleTag({
    content: `
      /* The sidebar, unpinned so fullPage paints it down the whole document. */
      aside.navbar-vertical {
        position: absolute !important;
        top: 0 !important;
        bottom: auto !important;
      }
      /* Animations mid-flight make captures non-deterministic. */
      *, *::before, *::after {
        animation-duration: 0s !important;
        transition-duration: 0s !important;
      }
    `,
  });

  // The sidebar needs a real height now that it is not viewport-anchored, or it
  // stops at its content and leaves a bare strip beside the rest of the page.
  await page.evaluate(() => {
    const aside = document.querySelector('aside.navbar-vertical');
    if (aside) {
      aside.style.minHeight = document.documentElement.scrollHeight + 'px';
    }
  });

  if (!opts.keepToasts) {
    await page.evaluate(() => {
      document.querySelectorAll('.toast, #messages_after_redirect').forEach((t) => t.remove());
    });
  }

  if (opts.highlight) {
    await page.evaluate((selector) => {
      const el = document.querySelector(selector);
      if (el) {
        el.style.outline = '3px solid #f59f00';
        el.style.outlineOffset = '2px';
        el.style.borderRadius = '4px';
      }
    }, opts.highlight);
  }

  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(250);
  await page.screenshot({ path, fullPage: true });

  // Undo, so a later assertion is not looking at a page this function altered.
  await page.evaluate((id) => {
    document.getElementById(id)?.remove();
    const aside = document.querySelector('aside.navbar-vertical');
    if (aside) {
      aside.style.minHeight = '';
    }
  }, await style.evaluate((node) => (node.id = node.id || 'glpi-shot-style')));

  if (opts.highlight) {
    await page.evaluate((selector) => {
      const el = document.querySelector(selector);
      if (el) {
        el.style.outline = '';
        el.style.outlineOffset = '';
      }
    }, opts.highlight);
  }
}

module.exports = { fullPage };
