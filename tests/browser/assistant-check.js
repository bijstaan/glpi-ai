// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// The troubleshooting assistant panel.
//
// The PHP suite covers the tools, the thread and the rights. What only a
// browser reaches is the thing that makes this a slide-over rather than a page:
// it opens over whatever you are looking at, and it knows what that is.
//
// So the assertions are about context and continuity. Open it on a ticket and
// the ticket is the context; navigate to a computer and the conversation is a
// different one; come back to the ticket and the earlier conversation is still
// there. Plus the two that matter everywhere in this plugin: it never appears
// in the helpdesk interface, and it is off until somebody turns it on.
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

async function login(browser, user, pass) {
  const ctx = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.fill('#login_name', user);
  await page.fill('input[type=password]', pass);
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');
  return { ctx, page, ok: !(await page.locator('#login_name').count()) };
}

const openPanel = async (page) => {
  await page.click('.glpiai-assistant-launch');
  await page.waitForSelector('.glpiai-assistant:not([hidden])', { timeout: 10000 });
  await page.waitForTimeout(900);
};

const ask = async (page, text) => {
  await page.fill('.glpiai-assistant-input', text);
  await page.press('.glpiai-assistant-input', 'Enter');
  await page.waitForFunction(
    () => !document.querySelector('.glpiai-assistant-note--waiting'),
    { timeout: 30000 }
  );
  await page.waitForTimeout(400);
};

(async () => {
  // Snapshot the plugin configuration before touching it, and put it back in
  // the finally below. This instance has a real provider configured against a
  // real host; a test run is not allowed to cost it. See config-guard.js.
  const saved = guard.snapshot();

  const fixtures = JSON.parse(php(`
    // Clear leftovers first. The cleanup below runs in a finally, which does
    // not survive the process being killed — and the entity name is unique, so
    // one interrupted run otherwise blocks every run after it with a duplicate
    // key that looks nothing like the real cause.
    global $DB;
    foreach ([["glpi_tickets","Ticket"], ["glpi_computers","Computer"],
              ["glpi_entities","Entity"]] as [$table, $class]) {
        foreach ($DB->request(["SELECT" => ["id"], "FROM" => $table,
                               "WHERE" => ["name" => ["LIKE", "glpiai-assist-%"]]]) as $row) {
            (new $class())->delete(["id" => (int) $row["id"]], true);
        }
    }

    Config::setConfigurationValues("plugin:glpiai", [
      "enabled" => "1", "provider" => "openai", "entity_mode" => "all",
      "assistant_enabled" => "0",
    ]);
    GlpiPlugin\\Glpiai\\Settings::saveProvider("openai", [
      "api_key" => "sk-browser", "base_url" => "${MOCK}",
      "model_fast" => "mock-fast", "model_quality" => "mock-quality",
    ]);
    $e = new Entity();
    $entity = (int) $e->add(["name" => "glpiai-assist-browser", "entities_id" => 0]);
    $t = new Ticket();
    $ticket = (int) $t->add(["name" => "Back office network keeps dropping",
      "content" => "Three desks lose the network every few minutes.",
      "entities_id" => $entity]);
    $c = new Computer();
    $computer = (int) $c->add(["name" => "glpiai-assist-laptop", "entities_id" => $entity]);
    echo json_encode(["entity" => $entity, "ticket" => $ticket, "computer" => $computer]);
  `));

  const cleanup = () => php(`
    global $DB;
    (new Ticket())->delete(["id" => ${fixtures.ticket}], true);
    (new Computer())->delete(["id" => ${fixtures.computer}], true);
    (new Entity())->delete(["id" => ${fixtures.entity}], true);
    // Only this run's conversations. This used to be an unscoped delete of the
    // whole table, which took every technician's real threads with it — and
    // now that the panel lists past conversations, those are something people
    // go back to.
    $DB->delete("glpi_plugin_glpiai_threads", [
        "OR" => [
            ["itemtype" => "Ticket",   "items_id" => ${fixtures.ticket}],
            ["itemtype" => "Computer", "items_id" => ${fixtures.computer}],
        ],
    ]);
    // The configuration is put back by config-guard, not reset to
    // defaults: this instance has a real provider configured.
    echo "cleaned";
  `);

  const browser = await chromium.launch();
  const { page } = await login(browser, 'glpi', 'glpi');
  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));

  const ticketUrl = `${BASE}/front/ticket.form.php?id=${fixtures.ticket}&forcetab=Ticket$main`;
  const computerUrl = `${BASE}/front/computer.form.php?id=${fixtures.computer}`;

  try {
    // --- 1. Off until switched on -------------------------------------
    await page.goto(ticketUrl, { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);

    check('the launcher is present on every page',
      await page.locator('.glpiai-assistant-launch').count() === 1);

    // Where it is, not just that it exists. It was a floating button in the
    // bottom-right corner first, which is where a GLPI form puts Save — so it
    // covered the one control on the page people press most. Asserted as
    // geometry rather than as a selector, because the bug was an overlap and
    // any future layout that reintroduces one should fail here too.
    check('it lives in the header rather than floating over the page',
      await page.locator('.header-container .glpiai-assistant-launch').count() === 1);

    const overlaps = await page.evaluate(() => {
      const launcher = document.querySelector('.glpiai-assistant-launch');
      if (!launcher) return 'no launcher';

      const a = launcher.getBoundingClientRect();
      const hit = [];

      document.querySelectorAll('button, a.btn, input[type=submit]').forEach((el) => {
        if (el === launcher || !el.offsetParent) return;
        const b = el.getBoundingClientRect();
        if (b.width === 0 || b.height === 0) return;
        if (a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top) {
          hit.push((el.textContent || el.value || el.className).trim().slice(0, 30));
        }
      });

      return hit;
    });

    check('and covers no other control on the page',
      Array.isArray(overlaps) && overlaps.length === 0, JSON.stringify(overlaps));

    await fullPage(page, `${SHOTS}/ai-assistant-00-launcher.png`);

    await page.click('.glpiai-assistant-launch');
    await page.waitForTimeout(1200);
    check('but with the feature off it says so rather than answering',
      (await page.locator('.glpiai-assistant-log').innerText()).length > 0
      && !(await page.locator('.glpiai-assistant-log').innerText()).includes('Ask about'),
      await page.locator('.glpiai-assistant-log').innerText());

    php(`GlpiPlugin\\Glpiai\\Settings::save(["assistant_enabled" => "1"]); echo "on";`);

    // --- 2. Context comes from the page --------------------------------
    await page.goto(ticketUrl, { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    await openPanel(page);

    check('the panel opens over the page',
      await page.locator('.glpiai-assistant:not([hidden])').count() === 1);
    check('and knows which ticket is open',
      (await page.locator('.glpiai-assistant-context').innerText()).includes('Back office network'),
      await page.locator('.glpiai-assistant-context').innerText());

    await fullPage(page, `${SHOTS}/ai-assistant-01-open.png`);

    // --- 3. Asking -----------------------------------------------------
    await ask(page, 'Why does the back office keep dropping?');

    check('the question appears in the log',
      await page.locator('.glpiai-assistant-turn--user').count() === 1);
    check('and an answer comes back',
      await page.locator('.glpiai-assistant-turn--assistant').count() === 1);
    check('with the tools it reached for shown, not hidden',
      await page.locator('.glpiai-assistant-tool').count() >= 1,
      await page.locator('.glpiai-assistant-tools').innerText().catch(() => ''));

    // Models write markdown whether or not the prompt asks for it, so the panel
    // renders it. Asserted on the DOM rather than on the text: the failure this
    // guards against is asterisks and backticks being shown literally, which
    // reads as a broken feature.
    check('an assistant turn is rendered rather than shown as source',
      await page.locator('.glpiai-assistant-turn--assistant .glpiai-assistant-md').count() === 1);
    check('and the technician\'s own words are not put through a parser',
      await page.locator('.glpiai-assistant-turn--user .glpiai-assistant-md').count() === 0);

    await fullPage(page, `${SHOTS}/ai-assistant-02-answered.png`);

    // --- 4. Continuity across navigation -------------------------------
    await page.goto(computerUrl, { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    await openPanel(page);

    check('a different record gets its own conversation',
      await page.locator('.glpiai-assistant-turn--user').count() === 0,
      await page.locator('.glpiai-assistant-log').innerText());
    check('and the context follows the page',
      (await page.locator('.glpiai-assistant-context').innerText()).includes('glpiai-assist-laptop'));

    await page.goto(ticketUrl, { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    await openPanel(page);

    check('coming back to the ticket resumes what was said there',
      await page.locator('.glpiai-assistant-turn--user').count() === 1,
      await page.locator('.glpiai-assistant-log').innerText());

    // --- 5. Past conversations -----------------------------------------
    //
    // The case the panel could not serve until it grew a list: the question you
    // want back was asked on a page you are no longer on. So this is asserted
    // from the *computer*, whose own conversation is empty, reaching the
    // ticket's.
    await page.goto(computerUrl, { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    await openPanel(page);

    await page.click('.glpiai-assistant-history-toggle');
    await page.waitForSelector('.glpiai-assistant-history-list, .glpiai-assistant-history-empty',
      { timeout: 10000 });
    await page.waitForTimeout(400);

    const rows = page.locator('.glpiai-assistant-history-row');
    check('the history lists a conversation that was had elsewhere',
      await rows.count() >= 1, `${await rows.count()} rows`);

    // A thread is created by opening the panel, asked in or not. Listing the
    // empty ones would make this list mostly page visits.
    check('and not the empty thread this page just made',
      !(await page.locator('.glpiai-assistant-history-list').innerText())
        .includes('glpiai-assist-laptop'),
      await page.locator('.glpiai-assistant-history-list').innerText());

    check('the transcript is swapped out rather than covered',
      !(await page.locator('.glpiai-assistant-log').isVisible()));
    check('and the composer goes with it, so a stray Enter cannot ask anything',
      !(await page.locator('.glpiai-assistant-form').isVisible()));

    await rows.first().click();
    await page.waitForSelector('.glpiai-assistant-history', { state: 'hidden', timeout: 10000 });
    await page.waitForTimeout(600);

    check('picking one brings its transcript back',
      await page.locator('.glpiai-assistant-turn--user').count() === 1,
      await page.locator('.glpiai-assistant-log').innerText());
    check('and the header names the conversation, not the page it was opened on',
      (await page.locator('.glpiai-assistant-context').innerText()).includes('Back office network'),
      await page.locator('.glpiai-assistant-context').innerText());

    await fullPage(page, `${SHOTS}/ai-assistant-03-history.png`);

    // Escape belongs to the innermost thing that is open.
    await page.click('.glpiai-assistant-history-toggle');
    await page.waitForSelector('.glpiai-assistant-history', { state: 'visible', timeout: 5000 });
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    check('Escape closes the list before it closes the panel',
      await page.locator('.glpiai-assistant-history[hidden]').count() === 1
        && await page.locator('.glpiai-assistant:not([hidden])').count() === 1);

    // Back to the ticket for the rest: clearing is asserted against the
    // conversation that has something in it.
    await page.goto(ticketUrl, { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    await openPanel(page);

    // --- 6. Clearing ---------------------------------------------------
    await page.click('.glpiai-assistant-clear');
    await page.waitForTimeout(900);
    check('clearing empties the log',
      await page.locator('.glpiai-assistant-turn').count() === 0);

    // --- 7. Escape closes ----------------------------------------------
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    check('Escape closes the panel',
      await page.locator('.glpiai-assistant:not([hidden])').count() === 0);

    // --- 8. Never in the helpdesk interface ----------------------------
    const guest = await login(browser, 'post-only', 'postonly');
    if (guest.ok) {
      await guest.page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
      await guest.page.waitForTimeout(800);
      check('a self-service user never sees the launcher',
        await guest.page.locator('.glpiai-assistant-launch').count() === 0);
    }
    await guest.ctx.close();

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
