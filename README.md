# GLPI AI

A vendor-neutral model layer for GLPI 11, and the AI features built on it.

The substrate came first: one normalised request vocabulary, four provider
adapters, tool calling with a registry other plugins extend, a per-entity gate on
whether data may leave at all, and a record of what was asked, what answered and
what the model went looking for.

On top sit the features a technician sees: triage suggestions, solution and
article drafting, reply review, and **HEIMDALL**, a troubleshooting agent that
can go and look.

Depends on nothing outside GLPI's own vendor tree.

![General settings](docs/screenshots/ai-01-general.png)

## Features

- **One API for four vendors** — Anthropic, OpenAI, Google Gemini and Azure AI
  Foundry, each reachable at a custom endpoint. Callers build a `Prompt` and get
  a `Completion`; the differences live in the adapters.
- **Credentials encrypted at rest** by GLPI with GLPI's key, so
  `glpi:security:changekey` rotates them and the change history masks them.
- **A tenant gate.** Which entities may have their data sent to a provider is
  configuration, and the default permits nothing.
- **Tool calling** on all four vendors: 29 native tools over GLPI's own data, 22
  reads and 7 writes.
  - Reads cover the *record* (a ticket, problem or change and what was said on
    it), the *promise* (which SLA and OLA apply, working time left, what has
    breached), the *entity* (who they are, what is agreed, which contracts
    cover what, how much they raise), the *estate* (assets, installed software
    and licence position, warranties, consumable stock) and the *people* (who is
    in a group, whose diary is full, who was asked to approve something, whether
    the requester was actually emailed).
  - Writes are an internal note on a ticket, problem or change; a task; the
    ticket's filing; a link between tickets; a machine attached to a ticket; and
    an unpublished knowledge article. They are off until an administrator turns
    them on, none is requester-facing, and none deletes anything.
  - Around them is a registry 18 other plugins add to — some sixty more tools,
    from backup state and on-call rotas to cloud drift, vulnerability exposure
    and post-incident actions.
  - Past eight registered tools the model searches for them rather than being
    handed all of them, which keeps a hundred-tool instance sending about thirty
    schemas on a request.
- **An MCP client** for external servers, including OAuth 2.1 discovered from the
  server's own URL with client-credentials or authorization-code grants,
  authenticating either as the instance or as each technician's own account —
  which they connect from *My settings → AI connections*.
- **Triage suggestions** on tickets that arrived as prose: a proposed category,
  urgency, impact and procedure, as chips above the ticket fields. Nothing is
  applied without a click, and every accept and dismiss is recorded, which is
  what turns "the AI seems decent" into a number per field.
- **Solution and article drafting** from the evidence on a worked ticket — the
  followups, the tasks, the checks a procedure recorded. The solution is offered
  inside GLPI's own editor and inserted only on a click; the article is created
  unpublished.
- **HEIMDALL** — Helpdesk Endpoint Inspection, Monitoring, Diagnostics And Live
  Lookup — a troubleshooting agent in a panel over any page. It knows what the
  technician has open and can reach every registered tool, including
  `glpiosquery`'s live queries and `glpinetscan`'s SNMP findings, and reaches
  exactly what that technician could have found by hand.

  The run narrates itself: each tool as it is called with its arguments, what
  came back, the model's reasoning where the provider sends it, and the answer a
  word at a time, with a switch between keeping every step on screen and keeping
  only the one running. An answer that hits the output ceiling is carried on
  rather than cut off. Past conversations are listed and resume in place.
- **Reply review** — the one place a model here touches requester-facing text, and
  it does so by reading. A technician can ask for a second read before sending:
  internal content carried across, an unexplained term, no statement of what
  happens next, the wrong tone. Remarks, never a rewrite; Save is never blocked.
  Off by default, since to spot a leak it has to see the internal notes.
- **A usage log** — provider, model, tier, token counts, duration and a prompt
  fingerprint, per entity and per user — plus a separate audit trail of every
  tool call.

## Scope

- **It does not talk to a requester.** Every feature is technician-facing. The
  one requester-adjacent case is a model *reviewing* a reply a human wrote, which
  returns remarks rather than text.
- **It does not fall back between vendors.** One provider is active at a time. A
  fallback chain sounds like resilience and means a request can land at a vendor
  the entity policy was never written for.
- **It does nothing on install.** Master switch off, no provider selected, empty
  allowlist.

## Setting it up

**Setup → AI.**

1. Fill in a provider's card — API key, model names, and an endpoint if you are
   going through a gateway. Save.
2. Press **Test connection**. Having the fields filled in is a different claim
   from the credentials being valid, the deployment existing, and the endpoint
   being reachable through whatever proxy sits in the way.
3. Choose the active provider and switch **Enable AI features** on.
4. Decide which entities are permitted.

![A successful connection test](docs/screenshots/ai-03-connected.png)

### Tools

![Tool calling](docs/screenshots/ai-06-tools.png)

Tools run with the rights and entity restriction of the signed-in user, so a
model can only reach what the technician driving it could reach by hand. Tools
that change data are off by default and switched on separately from the master
switch. External MCP servers are connected under Setup → AI → MCP servers.

### The entity gate

![The entity gate](docs/screenshots/ai-02-entity-gate.png)

This plugin sends ticket content to a third party. That is a policy question
before a technical one, and some organisations forbid it outright, so it is
configuration rather than an assumption.

Permitting an entity permits those beneath it, which is how you would model an
organisation with a sub-entity per site. An empty allowlist permits nothing, including
the root entity: the failure this guards against is precisely somebody not having
considered the question yet.

The entity is a required argument to `Client::complete()` rather than something
read from the session, because background work — cron, the mail collector, a
queue worker — has no session, and a gate that quietly resolved to entity 0 there
would be no gate at all.

## Calling it from another plugin

```php
use GlpiPlugin\Glpiai\Client;
use GlpiPlugin\Glpiai\Prompt;

$prompt = Prompt::make(
    "Ticket: {$ticket->fields['name']}\n\n{$ticket->fields['content']}",
    'You are triaging helpdesk tickets. Answer only with the schema.'
)->withTier(Prompt::TIER_FAST)->withSchema([
    'type'       => 'object',
    'properties' => [
        'category' => ['type' => 'string'],
        'urgency'  => ['type' => 'integer'],
    ],
], 'triage');

try {
    $completion = Client::complete($prompt, (int) $ticket->fields['entities_id']);
    $suggestion = $completion->data;   // decoded, schema-shaped
} catch (AiException $e) {
    // $e->kind is one of: disabled, auth, rate_limit, server, invalid_request,
    // refused, transport. $e->isRetryable() and $e->userMessage() are for
    // deciding what to do and what to show.
}
```

For a request where the model may call tools, `Client::run()` is the equivalent
door: a loop over `complete()`, so the same guards hold on every turn rather than
only the first, returning a `Conversation` carrying every turn, every tool call
and the summed cost.

`Client::complete()` is the only door. It is where the master switch, the entity
gate, the timeout ceiling and the usage record are enforced, once. A caller
reaching past it into `Registry` would silently opt out of the entity policy, and
nobody would notice until somebody asked.

### The two model tiers

Every provider is configured with a fast model and a quality one, and callers say
which shape of work they are doing rather than naming a model. Triage is
high-volume and low-value-per-call; drafting a resolution is the opposite.
Pinning both to one model means overpaying for one of them, and which model each
maps to is the administrator's decision.

### Portable schemas

All four vendors support constrained JSON output, through four request shapes and
three schema dialects — OpenAI demands `additionalProperties: false` and every
key in `required`; Gemini rejects `additionalProperties` outright and wants types
upper-cased. The adapters translate what is translatable, so keep schemas to
plain types, plain nesting and `enum`. Anything exotic will survive on some
providers and be rejected by others.

`Prompt::$extra` is the escape hatch for vendor-specific parameters, and is
an ugly one: anything put there is not portable.

## API

The four features are reachable from `glpimobile` over the high-level API, with
the rights of the `ajax/` endpoint each stands in for — thread ownership through
`Thread::mine()`, drafting through `Drafter::refusal()`, triage and drafting
through `Ticket::canUpdateItem()` — and central-interface only.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/GlpiAi/status` | what will actually answer, in the entity of the request |
| `GET` | `/GlpiAi/threads` | the caller's recent conversations |
| `POST` | `/GlpiAi/threads` | open or resume the thread for a context |
| `GET` | `/GlpiAi/threads/{id}` | one conversation with its transcript |
| `POST` | `/GlpiAi/threads/{id}/ask` | ask, and wait for the finished answer |
| `POST` | `/GlpiAi/threads/{id}/stream` | ask, and receive the run as server-sent events |
| `DELETE` | `/GlpiAi/threads/{id}` | clear a transcript |
| `GET`/`POST` | `/GlpiAi/tickets/{id}/draft` | the drafted solution, and asking for one |
| `POST` | `/GlpiAi/drafts/{id}/used` · `/discard` | what became of a draft |
| `GET`/`POST` | `/GlpiAi/tickets/{id}/triage` | the triage suggestion, and running it now |
| `POST` | `/GlpiAi/triage/{id}/apply` · `/dismiss` | accept or reject one proposed field |
| `POST` | `/GlpiAi/reply/review` | read a drafted reply before it is sent |

**Streaming, because a still spinner reads as a crash.** An agent run is four to
eight vendor round trips and takes the better part of a minute; on a phone that
is long enough for somebody to background the app, which kills the request.
`/threads/{id}/stream` speaks the same events as `ajax/assistant-stream.php` —
`turn`, `tool`, `tool_result`, `thinking`, `text`, `continued`, `done`, `failed`,
plus an `open` frame — through the same `Progress` sink, so there is one
narration and not two. It is a Symfony `StreamedResponse` wrapped in the HL API's
`StreamedResponseWrapper`, which keeps the router from sending headers and
stringifying a body that does not exist yet.

`/status` exists because the capability map cannot answer for it: that map is
computed once per session, the entity gate is not a session property (the
technician can switch entity in the app), and "may this entity's data reach a
provider" is decided per entity.

Feature discovery goes through `glpimobile_capabilities`
(`plugin_glpiai_mobile_capabilities()` in `setup.php`): `assistant`, `draft`,
`reply_review`, `triage`, all false outside the central interface.

## Install

```bash
# from the GLPI root — the directory must be named for the plugin key,
# which is not the repository name
git clone https://github.com/bijstaan/glpi-ai.git plugins/glpiai
php bin/console plugin:install -u glpi glpiai
php bin/console plugin:activate glpiai
```

## Tests

Nine PHP suites and five browser suites, none needing a real credential or any
network egress.

```bash
docker compose -p glpi exec glpi sh -c 'cd /var/www/glpi/plugins/glpiai && tests/run.sh'

cd tests/browser && ./ai-setup.sh start && node ai-check.js
node triage-check.js && node drafts-check.js
node assistant-check.js
./ai-setup.sh stop
```

- `tests/adapters.php` — the one that matters most. Translation is the only thing
  this code is responsible for, and every mistake in it produces a request that
  looks plausible and is accepted by nobody. It drives all four adapters from one
  identical `Prompt` against a mock vendor API and asserts on what each *sent*.
- `tests/tools-wire.php` — the same discipline for tool calling: three shapes per
  vendor, and getting one subtly wrong produces a model that stops calling tools.
- `tests/integration.php` — settings persistence, encryption at rest, the tenant
  gate against a real entity tree, and the guards on `Client::complete()`. It
  restores the configuration and purges the entities it creates.
- `tests/tools.php` — rights, entity scoping, the write switch, the agent loop,
  the audit trail, and the MCP client against a mock server.
- `tests/triage.php` — eligibility, the taxonomy as an allowlist, a hallucinated
  category being discarded, and the accept-rate arithmetic. Several assertions
  check only that the ticket is *unchanged*.
- `tests/drafts.php` — the evidence gatherer, both schemas, and the outcomes.
  Several assertions check what did not happen: no solution written to the
  ticket, and a created article with no visibility rows at all.
- `tests/discovery.php` — that every tool can be *found*. Almost everything past
  the pinned handful is reached through `find_tools`, whose ranker is word
  overlap with no synonyms and the crudest stemming, so a tool whose description
  lacks the words people use is a tool nobody will call. It asks the question a
  technician would ask and asserts the right tool comes back.
- `tests/native-tools.php` — the deeper reads and the writes, driven through
  `ToolRegistry::execute()` rather than by calling handlers, because for a write
  tool most of the interesting behaviour is on the path a model takes: the write
  switch, the profile right, the missing-argument check. Its sharpest assertions
  are negative — a note cannot be made public even when `is_private` is passed, a
  `status` argument is ignored, a link needs rights at both ends.
- `tests/reply.php` — where the strip draws and where it must not, the flags that
  survive validation, and the outcome. The mock answers a review by comparing the
  ticket's internal notes with the reply, so "the internal notes reached the
  prompt" is a checked fact.
- `tests/streaming.php` — the three adapters that stream, and the event channel.
  The mock chops text mid-word and splits every tool call's JSON arguments across
  frames on purpose: an adapter that buffered the stream and called back once
  would produce an identical `Completion` and show a technician nothing until the
  end, so assertions are on the *number* of fragments as much as the content. It
  also holds the rule that thinking never becomes the answer.
- `tests/mcp-user-auth.php` — MCP servers authenticating the person rather than
  the instance. Mostly about what one person's credential does *not* do: another
  technician's lookup finds nothing and their call is refused; a run with no
  signed-in person is refused rather than falling back to whichever grant exists;
  the in-flight authorization lives on the person's own row so two people can
  connect at once; nothing personal is written to the server record.
- `tests/oauth-toolbox.php` — the OAuth grants against a mock authorization
  server that checks the client secret, the PKCE verifier and whether a code has
  been used; plus the tool-search threshold, ranking, and the wire that makes a
  found tool callable on the next turn.
- `tests/assistant.php` — the conversation and the tools. The network tools are
  driven against a real switch, laptop and cable so "follows a cable" is a
  checked fact, and the live-query guards are asserted directly rather than
  through a model, because a guard that only holds when a model behaves is not a
  guard.
- `tests/browser/ai-check.js` — the settings page, including the two things only
  a browser reaches: the connection test's CSRF handling, and that saving does
  not wipe credentials it is not showing you.
- `tests/browser/drafts-check.js` — the drafts tab and the strip inside GLPI's
  solution editor. Its central assertion is that after clicking Insert the editor
  holds the draft and the ticket still has no solution.
- `tests/browser/assistant-check.js` — the panel: that it knows which record is
  open, that a different record gets its own conversation, that coming back
  resumes the first, that past conversations reopen from a page they were not had
  on, and that a requester never sees the launcher.
- `tests/browser/triage-check.js` — the panel, the chips and who can see them. It
  signs in as an ordinary Technician, because the people this feature is for are
  exactly the people who do not hold the configuration right, and as a
  self-service user, because they must never see it.

`tests/mock-provider.php` stands in for all four vendors plus Microsoft Entra,
and `tests/mock-mcp.php` for an MCP server, which also plays its own
authorization server. Both answer in the real shapes including the unhappy ones
that arrive as a successful HTTP response — a refusal, a safety block, a content
filter, a tool reporting its own failure — since those are easiest to mistake for
a working call that returned nothing.

For triage the mock parses the category list back out of whichever field that
vendor puts the system instruction in, and answers by word overlap with the
ticket. A canned response would sail through an assembly failure — a taxonomy
that never reached the prompt, a ticket that never reached the user turn —
because both produce a well-formed request.

### Against a real model

`tests/live-ollama.php` runs triage, drafting and the assistant against a live
Ollama host. Opt-in and not in `run.sh`, because a suite needing a GPU on the
network gets skipped — but a mock can only prove the plugin sent what it meant
to, and the claim here is about what a model does.

```bash
docker compose -p glpi exec glpi sh -c \
  'cd /var/www/glpi/plugins/glpiai && OLLAMA=http://host:11434 \
   CHAT=some-model php tests/live-ollama.php'
```

It found three things a mock structurally could not: a base URL ending in `/v1`
produced `/v1/v1/chat/completions` and a bare 404; a reasoning model spends its
whole output budget thinking and returns an empty message; and a small model
carries a department name into a knowledge article unless the instruction says,
in those words, to take team names out.

## Layout

```
setup.php            hooks, and the SECURED_CONFIGS declaration that makes
                     GLPI encrypt the credentials
hook.php             install/uninstall: the tables, one right
src/Prompt.php       the neutral request — the intersection of what all four
                     vendors can do, not the union
src/Client.php       the one door: master switch, entity gate, timeout, logging
src/Settings.php     configuration, including the secret round-trip
src/UsageLog.php     what every call cost and which model answered
src/ToolLog.php      what the model went looking for, and on whose behalf
src/Tool*.php        the tool vocabulary and the registry that assembles it
src/Conversation.php the record of one agent run
src/Provider/        Registry, the Provider contract, Field, and the four adapters
src/Tools/           the native tools and the rights-respecting search behind them
src/Mcp/             the MCP client, the configured-server item, the OAuth 2.1
                     flows, the per-technician credentials, and the catalogue
                     that turns a server into tools
src/Toolbox.php      tool search
src/Triage/          the taxonomy, the stored suggestion and its outcome, the
                     service, and the panel
src/Draft/           what happened on a ticket, the two drafts made from it,
                     the tab, and the strip inside GLPI's solution editor
src/Assistant/       the conversation, its starting context, and the agent loop
src/Progress.php     what the run is doing, for whoever is watching
src/MobileController.php  the four features over the high-level API
ajax/assistant-stream.php the same question over server-sent events
src/Reply/           reply review and the record of whether the reply changed
front/config.php     the settings page, generated from the declared fields
front/mcp/           the MCP server list and form
ajax/test.php        the connection test
```

Adding a fifth vendor is one class and one line in `Registry`. The settings form,
the save handler, the uninstall key list and the connection test all read from
the registry and the declared `Field`s, so none needs a per-vendor branch — which
is what stops the fifth vendor being a class plus three forgettable edits that
each fail silently by not saving a field.

## Independence

We have never had a GLPI Network subscription. We have not seen the source of
GLPI's "Exclusive" plugins, or their screens, or their docs. Nothing in here
came from them.

It was built from GLPI's own source, which is GPL and public, and from its API.
That is the whole list.

If it looks like theirs in places, that is because core only gives you so many
places to hook into.

GLPI is a trademark of Teclib'. This plugin is not affiliated with Teclib' or
the GLPI project.

## Licence

GPL-3.0-or-later, the same licence as GLPI. The plugin is loaded into GLPI's
process and extends its classes, so it is a derivative work. See
[LICENSE](LICENSE).
