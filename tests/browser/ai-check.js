// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// The glpiai settings page, driven the way an administrator drives it.
//
// The PHP suites cover the wire formats and the policy layer; what is left is
// the part only a browser reaches. Two things here are worth a test rather than
// a glance:
//
//   - the connection test is a fetch() against a GLPI 11 endpoint, and GLPI only
//     honours a header CSRF token when the request also declares itself as XHR.
//     Get that wrong and the button silently reports failure forever.
//   - a stored API key is rendered as a placeholder, and posting the placeholder
//     back has to mean "unchanged". If it does not, every unrelated save on this
//     page quietly wipes every credential on it — and the page still looks
//     entirely correct afterwards.
//
// The provider under test points at the mock vendor API from
// glpi-ai/tests/mock-provider.php, which must be listening inside the GLPI
// container. ai-setup.sh starts it.
const { chromium } = require('playwright');
const { fullPage } = require('./shot');
const guard = require('./config-guard');

// Module scope, so the catch below can put the configuration back too: a check
// that threw is exactly the one that must not leave the plugin reset.
let saved = null;

const BASE = 'http://localhost:8081';
const SHOTS = process.env.SHOT_DIR || '.';
const CONFIG = `${BASE}/plugins/glpiai/front/config.php`;

// Where the mock listens, as seen from inside the container running GLPI.
const MOCK = 'http://127.0.0.1:9099';
// The mock MCP server, also inside the container. Loopback, which is the one
// case where an http endpoint is allowed.
const MCP = 'http://127.0.0.1:9098/mcp';
const KEY = 'sk-browser-test-key';

const fail = [];
function check(name, cond, detail) {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + detail : ''}`);
  if (!cond) fail.push(name);
}

async function login(browser, user, pass) {
  const ctx = await browser.newContext({ viewport: { width: 1500, height: 1200 } });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.fill('#login_name', user);
  await page.fill('input[type=password]', pass);
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');
  if (await page.locator('#login_name').count()) {
    throw new Error(`login failed for ${user}`);
  }
  return page;
}

const card = (page, provider) =>
  page.locator('.card').filter({ has: page.locator(`[data-glpiai-test="${provider}"]`) });

const field = (page, provider, name) => page.locator(`[name="p[${provider}][${name}]"]`);

const result = (page, provider) => page.locator(`[data-glpiai-result="${provider}"]`);

/** Click a provider's Test connection button and wait for the verdict to land. */
async function runTest(page, provider) {
  await page.click(`[data-glpiai-test="${provider}"]`);
  const box = result(page, provider);
  await box.waitFor({ state: 'visible' });
  await page.waitForFunction(
    (id) => {
      const el = document.querySelector(`[data-glpiai-result="${id}"]`);
      return el && !/Testing/.test(el.textContent);
    },
    provider,
    { timeout: 20000 }
  );
  return { text: (await box.innerText()).trim(), ok: await box.evaluate((el) => el.classList.contains('alert-success')) };
}

async function save(page) {
  await page.click('button[name=update]');
  await page.waitForLoadState('networkidle');
}

(async () => {
  // Snapshot first, reset second. The assertions below describe an as-installed
  // instance, so this has to reset — and this development instance has a real
  // provider configured against a real host, so it has to put it back. See
  // config-guard.js.
  saved = guard.snapshot();
  guard.reset();
  require('child_process').execSync('docker exec -i glpi-glpi-1 php', {
    input:
      '<?php require "/var/www/glpi/vendor/autoload.php";'
      + '(new Glpi\\Kernel\\Kernel(Glpi\\Application\\Environment::PRODUCTION->value))->boot();'
      + 'global $DB; $DB->delete(GlpiPlugin\\Glpiai\\Mcp\\Server::getTable(), [1]);',
  });

  const browser = await chromium.launch();
  const page = await login(browser, 'glpi', 'glpi');

  const jsErrors = [];
  page.on('pageerror', (e) => jsErrors.push(e.message));

  await page.goto(CONFIG, { waitUntil: 'networkidle' });

  // ------------------------------------------------------------ as installed

  let body = await page.evaluate(() => document.body.innerText);
  check('the settings page renders', /Which entities may use AI/.test(body));
  check('it opens inactive, not enabled-by-default', /AI features are not active/.test(body));
  check('every registered provider gets a card',
    (await page.locator('[data-glpiai-test]').count()) === 4,
    String(await page.locator('[data-glpiai-test]').count()));
  check('an unconfigured provider says so',
    /incomplete/.test(await card(page, 'openai').innerText()));

  check('the Azure card offers a service principal by default',
    (await field(page, 'azure', 'auth_mode').inputValue()) === 'service_principal');
  check('the Azure card asks for a deployment style',
    (await field(page, 'azure', 'api_style').count()) === 1);

  await fullPage(page, `${SHOTS}/ai-02-entity-gate.png`, { highlight: '.glpiai-config .card:nth-of-type(2)' });
  await fullPage(page, `${SHOTS}/ai-04-azure.png`, { highlight: '.card:has([data-glpiai-test="azure"])' });

  // Testing before anything is filled in should say what is missing rather
  // than attempt a call and report a confusing transport failure.
  let verdict = await runTest(page, 'anthropic');
  check('testing an empty provider asks for the fields first',
    !verdict.ok && /Fill in the required fields/.test(verdict.text), verdict.text);

  // ----------------------------------------------------------- configuring it

  await field(page, 'openai', 'api_key').fill(KEY);
  await field(page, 'openai', 'base_url').fill(MOCK);
  await field(page, 'openai', 'model_fast').fill('mock-fast');
  await field(page, 'openai', 'model_quality').fill('mock-quality');
  await page.check('input[name=enabled]');
  await page.selectOption('select[name=provider]', 'openai');
  await page.check('input[name=entity_mode][value=all]');
  await save(page);

  body = await page.evaluate(() => document.body.innerText);
  check('saving reports success', /Settings saved/.test(body));
  check('the banner flips to active once a provider is configured',
    /AI features are enabled and a provider is configured/.test(body));
  check('the configured provider is badged active and configured',
    /active/.test(await card(page, 'openai').innerText())
     && /configured/.test(await card(page, 'openai').innerText()));
  check('the other providers stay incomplete',
    /incomplete/.test(await card(page, 'gemini').innerText()));

  const shown = await field(page, 'openai', 'api_key').inputValue();
  check('the stored key is never rendered back to the browser', shown !== KEY, shown);
  check('a placeholder stands in for it', shown.length > 0 && /[•*]/.test(shown), shown);
  check('non-secret fields are rendered as saved',
    (await field(page, 'openai', 'model_fast').inputValue()) === 'mock-fast');

  // ------------------------------------------------------- the connection test

  verdict = await runTest(page, 'openai');
  check('the connection test reaches the provider', verdict.ok, verdict.text);
  check('it reports which model answered', /gpt-mock/.test(verdict.text), verdict.text);
  check('it reports what the call cost', /tokens/.test(verdict.text), verdict.text);

  await fullPage(page, `${SHOTS}/ai-01-general.png`, { highlight: '.glpiai-config .card:nth-of-type(1)' });
  await fullPage(page, `${SHOTS}/ai-03-connected.png`, { highlight: '[data-glpiai-result="openai"]' });

  // The whole point of the placeholder: an unrelated edit must not cost the key.
  await field(page, 'openai', 'model_quality').fill('mock-quality-2');
  await save(page);
  check('an unrelated save keeps the credential',
    (await runTest(page, 'openai')).ok);
  check('the unrelated edit was actually saved',
    (await field(page, 'openai', 'model_quality').inputValue()) === 'mock-quality-2');

  // A provider that cannot be reached must say so in the provider's own words,
  // which is what distinguishes a wrong endpoint from a wrong credential.
  await field(page, 'openai', 'base_url').fill('http://127.0.0.1:9');
  await save(page);
  verdict = await runTest(page, 'openai');
  check('an unreachable endpoint fails visibly', !verdict.ok, verdict.text);
  check('the failure names the cause rather than "something went wrong"',
    /reach|connect|refused/i.test(verdict.text), verdict.text);

  await fullPage(page, `${SHOTS}/ai-05-test-failure.png`, { highlight: '[data-glpiai-result="openai"]' });

  await field(page, 'openai', 'base_url').fill(MOCK);
  await save(page);

  // ----------------------------------------------------------- the entity gate

  await page.check('input[name=entity_mode][value=allowlist]');
  await save(page);
  check('the entity policy round-trips',
    await page.locator('input[name=entity_mode][value=allowlist]').isChecked());
  check('the page states what an empty allowlist means',
    /An empty allowlist permits nothing/.test(await page.evaluate(() => document.body.innerText)));

  // The gate fails closed only if "nothing selected" really stores nothing.
  // GLPI posts an empty string alongside an empty multi-select, and an empty
  // string cast to an int is 0 — the root entity, which permits every entity
  // beneath it. Saving with no selection must not quietly permit the install.
  check('saving an empty allowlist selects no entity',
    (await page.locator('select[name="entities[]"] option').count()) === 0,
    await page.locator('select[name="entities[]"]').innerHTML());

  await field(page, 'openai', 'model_fast').fill('mock-fast');
  await save(page);
  check('an unrelated save does not add one either',
    (await page.locator('select[name="entities[]"] option').count()) === 0);


  // ------------------------------------------------------------ tool calling

  await page.goto(CONFIG, { waitUntil: 'networkidle' });
  body = await page.evaluate(() => document.body.innerText);

  check('the tool-calling section lists the native tools',
    /search_tickets/.test(body) && /read_ticket/.test(body)
     && /search_knowledge/.test(body) && /find_asset/.test(body));
  check('write tools are off as installed',
    !(await page.locator('input[name=allow_write_tools]').isChecked()));
  check('the turn budget is shown and editable',
    (await page.locator('input[name=max_tool_turns]').inputValue()) === '6');

  await fullPage(page, `${SHOTS}/ai-06-tools.png`, { highlight: '.card:has(input[name=max_tool_turns])' });

  await page.fill('input[name=max_tool_turns]', '4');
  await page.check('input[name=allow_write_tools]');
  await save(page);
  check('the tool settings round-trip',
    (await page.locator('input[name=max_tool_turns]').inputValue()) === '4'
     && (await page.locator('input[name=allow_write_tools]').isChecked()));
  await page.uncheck('input[name=allow_write_tools]');
  await page.fill('input[name=max_tool_turns]', '6');
  await save(page);

  // ------------------------------------------------------------ MCP servers

  await page.click('a[href*="mcp/server.php"]');
  await page.waitForLoadState('networkidle');
  // Asserted on the document title rather than the body: with no Setup-menu
  // entry of its own the breadcrumb reads Setup > Plugins, so the page's own
  // name appears in the tab and not in the page.
  check('the MCP server list opens from the settings page',
    /MCP servers/i.test(await page.title())
     && page.url().includes('mcp/server.php'), await page.title());

  // http is refused rather than warned about: a bearer token on a plaintext
  // connection is a token that has been given away. Asserted on the outcome —
  // an error, and no record — rather than on the toast text, which GLPI renders
  // asynchronously and races with the page's own XHRs.
  await page.goto(`${BASE}/plugins/glpiai/front/mcp/server.form.php`, { waitUntil: 'networkidle' });
  await page.fill('input[name=name]', 'Browser test server');
  await page.fill('input[name=url]', 'http://mcp.example.com/mcp');
  await page.click('button[name=add]');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(600);
  check('an http endpoint raises an error rather than being accepted',
    (await page.locator('.toast').filter({ hasText: /error/i }).count()) > 0);

  await page.goto(`${BASE}/plugins/glpiai/front/mcp/server.php`, { waitUntil: 'networkidle' });
  check('and no server was created',
    !/Browser test server/.test(await page.evaluate(() => document.body.innerText)));

  await page.goto(`${BASE}/plugins/glpiai/front/mcp/server.form.php`, { waitUntil: 'networkidle' });
  await page.fill('input[name=name]', 'Browser test server');
  await page.fill('input[name=url]', MCP);
  await page.selectOption('select[name=auth_type]', 'bearer');
  await page.fill('input[name=auth_token]', 'browser-secret');
  await page.selectOption('select[name=is_active]', '1');
  await page.click('button[name=add]');
  await page.waitForLoadState('networkidle');

  check('a loopback endpoint is accepted',
    /Browser test server/.test(await page.evaluate(() => document.body.innerText)));
  check('the stored token is never rendered back',
    (await page.locator('input[name=auth_token]').inputValue()) !== 'browser-secret');

  await page.click('button[name=discover]');
  await page.waitForLoadState('networkidle');
  body = await page.evaluate(() => document.body.innerText);
  check('discovery reports what it found', /2 tools discovered/.test(body), body.slice(0, 200));
  check('the discovered tools are listed', /get_incident/.test(body));
  check('the name the model will see is shown alongside the remote one',
    /mcp__Browser_test_server__get_incident/.test(body));

  await fullPage(page, `${SHOTS}/ai-07-mcp-server.png`);

  await page.goto(CONFIG, { waitUntil: 'networkidle' });
  check('the MCP tools appear in the settings page inventory',
    /mcp__Browser_test_server__get_incident/.test(await page.evaluate(() => document.body.innerText)));

  // The record is left for ai-setup.sh to clear. Driving GLPI's own delete
  // confirmation from here was tried and abandoned: it is core's dialog
  // machinery, not this plugin's, and the plugin's side of deletion is already
  // covered where it belongs — tests/tools.php creates and purges servers
  // through the same API the button calls.

  // ------------------------------------------------------------------ the menu
  //
  // Reachable from the Plugins list and *only* from there. A Setup-menu entry
  // as well would be a second link to this same page, which is what made that
  // menu unusable once several plugins each added one.

  await page.goto(`${BASE}/front/plugin.php`, { waitUntil: 'networkidle' });
  check('the settings page is reachable from the Plugins list',
    (await page.locator('a[href*="plugins/glpiai/front/config.php"]').count()) > 0);
  check('and is not duplicated into the sidebar',
    (await page.locator('.dropdown-item[href*="plugins/glpiai/"]').count()) === 0);

  check('no JavaScript errors on the settings page', jsErrors.length === 0, jsErrors.join(' | '));

  await browser.close();
  guard.restore(saved);

  console.log(`\n${fail.length ? `FAILED: ${fail.join(', ')}` : 'all checks passed'}`);
  process.exit(fail.length ? 1 : 0);
})().catch((e) => {
  guard.restore(saved);
  console.error('ERROR', e);
  process.exit(1);
});
