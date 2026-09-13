// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// The triage panel on a ticket form.
//
// The PHP suite covers eligibility, validation and the metric. What is left is
// the half that only exists in a browser, and it is the half that carries the
// safety claim: chips that a technician clicks, on a form, where the click is
// the only thing that writes.
//
// So the assertions are shaped around that rather than around the markup. The
// panel is checked to appear with the ticket unchanged behind it; applying is
// checked to change the ticket *and* to be attributed to the technician in
// GLPI's own history, not to a plugin; dismissing is checked to change nothing
// but the record of having been asked.
//
// Needs the mock provider inside the container; ai-setup.sh starts it.
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

// Over stdin rather than `php -r`: a shell expands every $variable first.
const php = (code) =>
  execSync('docker exec -i glpi-glpi-1 php', {
    encoding: 'utf8',
    input:
      '<?php require "/var/www/glpi/vendor/autoload.php";'
      + '(new Glpi\\Kernel\\Kernel(Glpi\\Application\\Environment::PRODUCTION->value))->boot();'
      + '(new Auth())->login("glpi","glpi",true);(new Plugin())->init(true);'
      + code,
  }).trim();

const panel = (page) => page.locator('.glpiai-triage');
const chip = (page, field) => page.locator(`[data-glpiai-chip="${field}"]`);

(async () => {
  // Snapshot the plugin configuration before touching it, and put it back in
  // the finally below. This instance has a real provider configured against a
  // real host; a test run is not allowed to cost it. See config-guard.js.
  const saved = guard.snapshot();

  // An entity with its own taxonomy, and a ticket that reads like one
  // somebody emailed in.
  const fixtures = JSON.parse(php(`
    Config::setConfigurationValues("plugin:glpiai", [
      "enabled" => "1", "provider" => "openai", "entity_mode" => "all",
      "triage_enabled" => "1", "triage_skip_central" => "0", "triage_batch" => "10",
    ]);
    GlpiPlugin\\Glpiai\\Settings::saveProvider("openai", [
      "api_key" => "sk-browser", "base_url" => "${MOCK}", "model_fast" => "mock-fast",
    ]);
    $e = new Entity();
    $entity = (int) $e->add(["name" => "glpiai-triage-browser", "entities_id" => 0]);
    $c = new ITILCategory();
    $cat = (int) $c->add(["name" => "Remote access", "entities_id" => $entity,
      "is_incident" => 1, "is_request" => 1,
      "comment" => "VPN and anything else about getting in from outside"]);
    // Reindexed with false: GLPI's iterator keys rows by id, so [0] is not
    // the first row. The fallback below was hiding that, by being right.
    $mail = (int) (iterator_to_array($GLOBALS["DB"]->request([
      "SELECT" => ["id"], "FROM" => "glpi_requesttypes", "WHERE" => ["is_mail_default" => 1],
    ]), false)[0]["id"] ?? 0);
    Config::setConfigurationValues("plugin:glpiai", ["triage_request_types" => (string) $mail]);
    GlpiPlugin\\Glpiai\\Triage\\Taxonomy::forget();
    $t = new Ticket();
    $ticket = (int) $t->add([
      "name" => "Remote access is down", "content" => "Nobody can get in. Urgent, everyone is affected.",
      "entities_id" => $entity, "requesttypes_id" => $mail, "urgency" => 3, "impact" => 3,
    ]);
    echo json_encode(["entity" => $entity, "cat" => $cat, "ticket" => $ticket]);
  `));

  const cleanup = () => php(`
    (new Ticket())->delete(["id" => ${fixtures.ticket}], true);
    (new ITILCategory())->delete(["id" => ${fixtures.cat}], true);
    (new Entity())->delete(["id" => ${fixtures.entity}], true);
    $GLOBALS["DB"]->delete("glpi_plugin_glpiai_triages", [1]);
    // The configuration is put back by config-guard, not reset to
    // defaults: this instance has a real provider configured.
    echo "cleaned";
  `);

  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1500, height: 1100 } });
  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));

  try {
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await page.fill('#login_name', 'glpi');
    await page.fill('input[type=password]', 'glpi');
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');

    const form = `${BASE}/front/ticket.form.php?id=${fixtures.ticket}`;

    // --- 1. Queued, and offering to run --------------------------------
    check('the ticket was queued on creation',
      php(`echo GlpiPlugin\\Glpiai\\Triage\\Suggestion::forTicket(${fixtures.ticket})["state"];`) === 'pending');

    await page.goto(form, { waitUntil: 'networkidle' });
    check('the form shows the queued notice', await panel(page).count() === 1);
    check('with a way to run it now', await page.locator('[data-glpiai-triage-run]').count() === 1);

    await fullPage(page, `${SHOTS}/ai-triage-01-queued.png`);

    // --- 2. Run it from the panel --------------------------------------
    await page.click('[data-glpiai-triage-run]');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);

    check('chips appear', await chip(page, 'itilcategories_id').count() === 1,
      await panel(page).innerText().catch(() => ''));
    check('the category it chose is the one the words point at',
      (await chip(page, 'itilcategories_id').innerText()).includes('Remote access'));
    check('urgency and impact are offered too',
      await chip(page, 'urgency').count() === 1 && await chip(page, 'impact').count() === 1);
    check('with a sentence saying why',
      (await page.locator('.glpiai-triage-why').innerText()).length > 5);

    // The whole safety claim: a suggestion exists, and nothing has been written.
    const before = JSON.parse(php(`
      $t = new Ticket(); $t->getFromDB(${fixtures.ticket});
      echo json_encode(["cat" => (int) $t->fields["itilcategories_id"],
                        "urgency" => (int) $t->fields["urgency"]]);
    `));
    check('and the ticket is still untouched', before.cat === 0 && before.urgency === 3,
      JSON.stringify(before));

    await fullPage(page, `${SHOTS}/ai-triage-02-chips.png`);

    // --- 3. Dismiss changes nothing but the record ---------------------
    await page.click('[data-glpiai-dismiss="urgency"]');
    await page.waitForTimeout(600);

    check('dismissing removes only that chip', await chip(page, 'urgency').count() === 0);
    check('and leaves the others', await chip(page, 'itilcategories_id').count() === 1);

    const afterDismiss = JSON.parse(php(`
      $t = new Ticket(); $t->getFromDB(${fixtures.ticket});
      $s = GlpiPlugin\\Glpiai\\Triage\\Suggestion::forTicket(${fixtures.ticket});
      echo json_encode(["urgency" => (int) $t->fields["urgency"],
                        "outcome" => $s["urgency_outcome"]]);
    `));
    check('the ticket urgency did not move', afterDismiss.urgency === 3);
    check('but the rejection was recorded', afterDismiss.outcome === 'dismissed',
      afterDismiss.outcome);

    // --- 4. Applying writes, under the technician's name ---------------
    await page.click('[data-glpiai-apply="itilcategories_id"]');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(600);

    const applied = JSON.parse(php(`
      $t = new Ticket(); $t->getFromDB(${fixtures.ticket});
      $s = GlpiPlugin\\Glpiai\\Triage\\Suggestion::forTicket(${fixtures.ticket});
      $log = iterator_to_array($GLOBALS["DB"]->request([
        "FROM" => "glpi_logs",
        "WHERE" => ["itemtype" => "Ticket", "items_id" => ${fixtures.ticket}],
        "ORDER" => "id DESC", "LIMIT" => 5,
      ]));
      echo json_encode([
        "cat" => (int) $t->fields["itilcategories_id"],
        "outcome" => $s["category_outcome"],
        "decided_by" => (int) $s["users_id_decided"],
        "me" => (int) Session::getLoginUserID(),
        "history" => array_values(array_map(fn($r) => $r["user_name"], $log)),
      ]);
    `));

    check('applying sets the category on the ticket', applied.cat === fixtures.cat,
      String(applied.cat));
    check('the outcome is recorded as accepted', applied.outcome === 'accepted');
    check('attributed to the technician who clicked', applied.decided_by === applied.me);
    check("and GLPI's own history says a person did it, not a plugin",
      applied.history.some((u) => u && u.toLowerCase().includes('glpi')),
      JSON.stringify(applied.history));

    // --- 5. The panel retires once there is nothing left to decide -----
    await page.goto(form, { waitUntil: 'networkidle' });
    check('the applied chip is gone', await chip(page, 'itilcategories_id').count() === 0);
    check('the dismissed one has not come back', await chip(page, 'urgency').count() === 0);

    await page.click('[data-glpiai-dismiss="impact"]');
    await page.waitForTimeout(600);
    await page.goto(form, { waitUntil: 'networkidle' });
    check('and with every chip decided the panel disappears entirely',
      await panel(page).count() === 0);

    await fullPage(page, `${SHOTS}/ai-triage-03-done.png`);

    // --- 6. Who can see it ---------------------------------------------
    // The panel is for technicians, and technicians are precisely the people
    // who do not hold the plugin's configuration right. Gating it on that right
    // would make the feature invisible to everybody it was built for, so this
    // signs in as an ordinary Technician and checks.
    php(`
      $t = new Ticket(); $t->getFromDB(${fixtures.ticket});
      $t->update(["id" => ${fixtures.ticket}, "_users_id_assign" => 0]);
      GlpiPlugin\\Glpiai\\Triage\\Suggestion::store(
        (int) GlpiPlugin\\Glpiai\\Triage\\Suggestion::forTicket(${fixtures.ticket})["id"],
        ["urgency_outcome" => "", "impact_outcome" => ""]
      );
      echo "reopened";
    `);

    const tech = await browser.newContext({ viewport: { width: 1500, height: 1100 } });
    const techPage = await tech.newPage();
    await techPage.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await techPage.fill('#login_name', 'tech');
    await techPage.fill('input[type=password]', 'tech');
    await techPage.click('button[type=submit]');
    await techPage.waitForLoadState('networkidle');

    if (await techPage.locator('#login_name').count()) {
      check('a technician can sign in', false, 'login failed for tech/tech');
    } else {
      await techPage.goto(form, { waitUntil: 'networkidle' });
      check('a technician sees the panel without the configuration right',
        await techPage.locator('.glpiai-triage').count() === 1);
      check('and is offered the apply button, because they can edit the ticket',
        await techPage.locator('[data-glpiai-apply]').count() > 0);
    }
    await tech.close();

    // A requester must never see a model's opinion about their own ticket.
    const guest = await browser.newContext({ viewport: { width: 1200, height: 900 } });
    const guestPage = await guest.newPage();
    await guestPage.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await guestPage.fill('#login_name', 'post-only');
    await guestPage.fill('input[type=password]', 'postonly');
    await guestPage.click('button[type=submit]');
    await guestPage.waitForLoadState('networkidle');

    if (!(await guestPage.locator('#login_name').count())) {
      await guestPage.goto(form, { waitUntil: 'networkidle' });
      check('a self-service user never sees it',
        await guestPage.locator('.glpiai-triage').count() === 0);
    }
    await guest.close();

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
