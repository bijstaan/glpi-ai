// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// Solution and article drafting, in a browser.
//
// The PHP suite covers the evidence, the schemas and the visibility of a
// created article. What only a browser reaches is the placement decision, and
// it is the part carrying the safety argument for this feature:
//
//   - the drafts tab, where a draft is produced and read;
//   - the strip inside GLPI's *own* solution editor, whose Insert button puts
//     text into the editor and not into the database.
//
// So the central assertion is that after clicking Insert, the editor holds the
// draft and the ticket still has no solution on it. The technician's Save is
// the only thing that publishes, and this checks that it still is.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const { fullPage } = require('./shot');
const guard = require('./config-guard');

const BASE = 'http://localhost:8081';
const SHOTS = process.env.SHOT_DIR || '.';
const MOCK = 'http://127.0.0.1:9099';

const fail = [];
function check(name, cond, detail) {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + String(detail).slice(0, 200) : ''}`);
  if (!cond) fail.push(name);
}

const php = (code) =>
  execSync('docker exec -i glpi-glpi-1 php', {
    encoding: 'utf8',
    input:
      '<?php require "/var/www/glpi/vendor/autoload.php";'
      + '(new Glpi\\Kernel\\Kernel(Glpi\\Application\\Environment::PRODUCTION->value))->boot();'
      + '(new Auth())->login("glpi","glpi",true);(new Plugin())->init(true);'
      + code,
  }).trim();

(async () => {
  // Snapshot the plugin configuration before touching it, and put it back in
  // the finally below. This instance has a real provider configured against a
  // real host; a test run is not allowed to cost it. See config-guard.js.
  const saved = guard.snapshot();

  const fixtures = JSON.parse(php(`
    Config::setConfigurationValues("plugin:glpiai", [
      "enabled" => "1", "provider" => "openai", "entity_mode" => "all", "draft_enabled" => "1",
    ]);
    GlpiPlugin\\Glpiai\\Settings::saveProvider("openai", [
      "api_key" => "sk-browser", "base_url" => "${MOCK}",
      "model_fast" => "mock-fast", "model_quality" => "mock-quality",
    ]);
    $e = new Entity();
    $entity = (int) $e->add(["name" => "glpiai-draft-browser", "entities_id" => 0]);
    $t = new Ticket();
    $ticket = (int) $t->add([
      "name" => "Shared drive disappears from Explorer mid-morning",
      "content" => "It vanishes for the whole finance team and comes back after a reboot.",
      "entities_id" => $entity, "urgency" => 3, "impact" => 3,
    ]);
    $f = new ITILFollowup();
    $f->add(["itemtype" => "Ticket", "items_id" => $ticket, "is_private" => 0,
             "content" => "Confirmed it affects three machines, not one."]);
    $f->add(["itemtype" => "Ticket", "items_id" => $ticket, "is_private" => 1,
             "content" => "The DFS referral is stale on the branch DC."]);
    (new TicketTask())->add(["tickets_id" => $ticket, "is_private" => 0,
             "content" => "Flushed the referral cache and re-registered the namespace."]);
    echo json_encode(["entity" => $entity, "ticket" => $ticket]);
  `));

  const cleanup = () => php(`
    global $DB;
    foreach ($DB->request(["FROM" => "glpi_plugin_glpiai_drafts",
                           "WHERE" => ["knowbaseitems_id" => [">", 0]]]) as $r) {
      (new KnowbaseItem())->delete(["id" => (int) $r["knowbaseitems_id"]], true);
    }
    (new Ticket())->delete(["id" => ${fixtures.ticket}], true);
    (new Entity())->delete(["id" => ${fixtures.entity}], true);
    $DB->delete("glpi_plugin_glpiai_drafts", [1]);
    // The configuration is put back by config-guard, not reset to
    // defaults: this instance has a real provider configured.
    echo "cleaned";
  `);

  const solutionCount = () => parseInt(php(`
    global $DB; echo count(iterator_to_array($DB->request([
      "FROM" => "glpi_itilsolutions",
      "WHERE" => ["itemtype" => "Ticket", "items_id" => ${fixtures.ticket}],
    ])));
  `), 10);

  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1600, height: 1100 } });
  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));

  try {
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await page.fill('#login_name', 'glpi');
    await page.fill('input[type=password]', 'glpi');
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');

    const form = `${BASE}/front/ticket.form.php?id=${fixtures.ticket}`;
    const tab = `${form}&forcetab=GlpiPlugin%5CGlpiai%5CDraft%5CTab$1`;

    // --- 1. The tab ----------------------------------------------------
    await page.goto(tab, { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);

    check('the drafts tab renders', await page.locator('.glpiai-drafts').count() === 1);
    check('with a card for each kind',
      await page.locator('[data-glpiai-draft-kind]').count() === 2);
    check('and nothing drafted yet',
      (await page.locator('.glpiai-drafts').innerText()).includes('Nothing drafted yet'));

    await fullPage(page, `${SHOTS}/ai-draft-01-empty.png`);

    // --- 2. Draft the solution -----------------------------------------
    await page.click('[data-glpiai-draft-run="solution"]');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);

    const solutionCard = page.locator('[data-glpiai-draft-kind="solution"]');
    check('a solution draft appears',
      await solutionCard.locator('[data-glpiai-draft-text]').count() === 1);

    const text = await solutionCard.locator('[data-glpiai-draft-text]').innerText();
    check('written from the whole timeline', text.includes('2 notes') && text.includes('1 tasks'), text);
    check('the private note reached it, marked', text.includes('(1 internal)'));
    check('what it was built from is shown',
      (await solutionCard.locator('.glpiai-draft-meta').innerText()).includes('followup'));
    check('and what the evidence does not establish is shown too',
      await solutionCard.locator('.glpiai-draft-gaps').count() === 1);

    check('the ticket still has no solution', solutionCount() === 0);

    // --- 3. Draft the article ------------------------------------------
    await page.click('[data-glpiai-draft-run="article"]');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);

    const articleCard = page.locator('[data-glpiai-draft-kind="article"]');
    check('an article draft appears with its own title',
      await articleCard.locator('.glpiai-draft-title').count() === 1);
    check('and offers to create it unpublished',
      (await articleCard.innerText()).includes('unpublished'));

    await fullPage(page, `${SHOTS}/ai-draft-02-drafted.png`);

    await page.click('[data-glpiai-draft-article]');
    // Waits for the card to come back showing the created article rather than
    // sleeping: the click fires a request and then reloads, and a fixed pause
    // is a race that passes on a fast machine.
    await page.waitForSelector('[data-glpiai-draft-kind="article"] a.btn', { timeout: 20000 });

    const created = JSON.parse(php(`
      global $DB;
      // Reindexed with false: GLPI's iterator keys rows by their id, so [0]
      // is almost never the first row and usually does not exist at all.
      $row = iterator_to_array($DB->request([
        "FROM" => "glpi_plugin_glpiai_drafts",
        "WHERE" => ["tickets_id" => ${fixtures.ticket}, "kind" => "article"],
      ]), false)[0] ?? [];
      $kb = (int) ($row["knowbaseitems_id"] ?? 0);
      $vis = 0;
      foreach (["glpi_knowbaseitems_users","glpi_groups_knowbaseitems",
                "glpi_knowbaseitems_profiles","glpi_entities_knowbaseitems"] as $tbl) {
        $vis += count(iterator_to_array($DB->request([
          "FROM" => $tbl, "WHERE" => ["knowbaseitems_id" => $kb],
        ])));
      }
      echo json_encode(["kb" => $kb, "visibility" => $vis, "outcome" => $row["outcome"] ?? ""]);
    `));

    check('the article is created', created.kb > 0, JSON.stringify(created));
    check('with no visibility, so only its author can see it', created.visibility === 0);
    check('and the draft records that it was used', created.outcome === 'used');

    // --- 4. The solution editor ----------------------------------------
    // The placement decision. Insert must fill the editor and nothing else.
    //
    // `forcetab=Ticket$main` is not decoration: GLPI remembers the last active
    // tab per itemtype, so arriving here after the drafts tab shows the drafts
    // tab again and the timeline is never rendered at all.
    await page.goto(`${form}&forcetab=Ticket$main`, { waitUntil: 'networkidle' });
    await page.waitForSelector('a.action-solution', { state: 'attached', timeout: 20000 });

    // Clicked through the DOM rather than by Playwright: the action lives in a
    // closed dropdown, and GLPI's handler is delegated, so dispatching the
    // click is both sufficient and less brittle than driving the menu open.
    await page.evaluate(() => document.querySelector('a.action-solution').click());
    // The strip, not the textarea: TinyMCE hides the original field, so waiting
    // for that to be *visible* never succeeds and there are three of them on
    // the page anyway — one per composer.
    await page.waitForSelector('[data-glpiai-solution-draft]', { timeout: 20000 });
    await page.waitForTimeout(800);

    const strip = page.locator('[data-glpiai-solution-draft]');
    check('the solution editor offers the draft', await strip.count() === 1,
      await page.locator('.itil-left-side, form[name=asset_form]').first().innerText().catch(() => ''));
    check('with an insert button, because a draft already exists',
      await page.locator('[data-glpiai-solution-insert]').count() === 1);

    await fullPage(page, `${SHOTS}/ai-draft-03-solution-editor.png`);

    await page.click('[data-glpiai-solution-insert]');
    await page.waitForTimeout(900);

    // Read from the solution form specifically. There are three composers on a
    // ticket — followup, task, solution — each with a textarea named content,
    // and the first one in the document is not the one being tested.
    const editorText = await page.evaluate(() => {
      var strip = document.querySelector('[data-glpiai-solution-draft]');
      var form = strip ? strip.closest('form') : null;
      var field = form ? form.querySelector('textarea[name="content"]') : null;
      if (!field) return null;
      if (window.tinymce) {
        var ed = window.tinymce.get(field.id);
        if (ed) return ed.getContent({ format: 'text' });
      }
      return field.value;
    });

    check('inserting fills the editor', (editorText || '').includes('Evidence seen'),
      String(editorText).slice(0, 120));
    check('and the ticket STILL has no solution written to it', solutionCount() === 0);
    check('using it is recorded', php(`
      global $DB;
      echo iterator_to_array($DB->request([
        "FROM" => "glpi_plugin_glpiai_drafts",
        "WHERE" => ["tickets_id" => ${fixtures.ticket}, "kind" => "solution"],
      ]), false)[0]["outcome"] ?? "";
    `) === 'used');

    check('no JavaScript errors', errs.length === 0, errs.join(' | '));
  } finally {
    await browser.close();
    cleanup();
    guard.restore(saved);
  }

  console.log(fail.length ? `\nFAILED: ${fail.join('; ')}` : '\nall checks passed');
  process.exit(fail.length ? 1 : 0);
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
