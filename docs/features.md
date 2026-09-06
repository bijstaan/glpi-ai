# GLPI AI — feature roadmap

Captured 2026-08-20. This is the backlog the plugin exists to deliver; the
provider layer shipped first is the substrate under all of it.

---

## The governing principle

**AI proposes, a technician disposes.** Every output lands as a draft, a
suggestion chip, or a highlighted candidate that a human accepts, edits or
rejects. Nothing reaches a requester without a person having read it.

That is not only a safety posture — it is what makes these shippable. An
internal suggestion that is wrong costs a technician ten seconds and a click, so
the feature can ship at 85% accuracy and improve. A wrong customer-facing reply
is a reputational event carried on a client's behalf, and would need to be
near-perfect before it could be turned on at all.

**Record every accept and reject.** It converts "the AI seems decent" into a
number, and the same log is the eval set for improving the prompt. This is the
house habit already: glpi-sop records skip reasons and a run log, glpi-presence
records who was looking at what.

---

## Tier 1 — build these

### 1. Semantic search over tickets, KB and SOPs — **shipped, then removed**

GLPI's search is exact-match SQL: *"has anyone seen the VPN drop after a Windows
update?"* finds nothing unless someone typed those words. Retrieval closes that
gap by meaning rather than by word, and it shipped: a separate embedder
registry, int8-quantised vectors scored by an exact linear scan, a background
indexer, and a public `Search\Semantic` API that **glpi-palette** blended into
the groups it was already showing.

It has been removed. Not because it did not work — it did, and the assertion it
was built to satisfy (a query and a ticket with no token in common ranking
together) passed against a real embedding model. It was removed because of what
it cost where people actually met it: the command palette runs on every
keystroke, and putting an embedding call plus a linear scan in front of that
made a navigation tool people reach for a hundred times a day noticeably slower
than the plain lexical search it replaced. A feature that is right in principle
and slow in the one place it is used is a feature with the wrong shape.

What that leaves, and what to weigh if it comes back:

- **The cost was in the read path, not the write path.** Indexing was already
  asynchronous and bounded. Scoring 20,000 vectors in PHP takes ~65ms, which is
  fine for a search page and is not fine for a keystroke.
- **A vector store with a real index** — MariaDB 11.7's native `VECTOR` type, or
  something outside GLPI — moves that cost from linear to logarithmic, and is
  the honest version of this feature rather than a cleverer scan.
- **The embedding call itself is a network round trip** and stays one whatever
  the store does. Anything on a keystroke path needs to be doing that from a
  cache or not at all.
- **The master switch has to stop *indexing*, not just querying.** Indexing
  sends the whole history rather than one ticket, and was by a wide margin the
  largest outbound flow the plugin had. Whatever replaces this inherits that
  rule.
- **A model change has to discard the index automatically**, because vectors
  from two models are not comparable and a mixed index returns confident
  nonsense rather than an error.

**The tool-calling route was never superseded and is what remains.** The
`search_tickets` and `search_knowledge` tools in [tools.md](tools.md) let the
*model* paraphrase: it runs two or three wordings through GLPI's own search and
reads the results. That needs no index, no embeddings bill, and no second copy
of every ticket to keep in step or delete on request. It is also on a path where
a second or two is unremarkable, which is exactly where this kind of work
belongs.

### 2. Triage suggestion at ticket creation — **shipped**

Propose category, urgency/impact and matching SOP as accept-with-one-click
chips. Deterministic cases stay in GLPI's rules engine; the model handles messy
free text and email-sourced tickets.

Tightest loop into what already exists: **glpi-sop attaches on category**, so
better categorisation means the right procedures actually fire. Accept-rate on
the chips is the accuracy metric.

Documented in [triage.md](triage.md). What shipped, and what changed on the way:

- **Assignee group was dropped.** Proposing which team owns a ticket is a
  different kind of claim from proposing what the ticket is about — it depends
  on rota, skills and who is already loaded, none of which is in the ticket
  text. The three fields that ship are the ones the text can actually support.
- **It runs from cron, not inline.** The obvious reading of "at ticket
  creation" is a provider call inside the create request. That would put a
  vendor's latency in front of whoever pressed submit — a customer, for portal
  tickets — and make the mail collector's runtime depend on an external API. So
  creation writes one local row and the queue is drained every five minutes,
  with a *Suggest now* button for whoever arrives first.
- **The taxonomy is the allowlist as well as the prompt prefix.** It was built
  separately for prompt caching, and then turned out to be the natural place to
  validate the answer: a category id is accepted only if it is one the model was
  actually offered *in that entity*. A hallucinated id fails closed, and cannot
  reach across into another customer's tree.
- **`matched` is a third outcome.** A suggestion agreeing with what the ticket
  already said is neither accepted nor rejected. Counting those as accepts would
  have produced an accuracy figure mostly measuring GLPI's own defaults — the
  metric this feature exists to produce would have been quietly worthless.

Shape as predicted: the `fast` model tier, structured output (a JSON schema),
and a system instruction that is identical for every ticket in an entity so a
provider's prompt cache can charge for it once.

### 3. Resolution and KB drafting from evidence — **shipped**

The differentiator, because of what the other plugins already collect:

- **glpi-osquery** knows machine state before and after,
- **glpi-sop** knows which steps were checked and what was answered,
- the timeline knows what was tried.

Draft the solution text and the KB article from *what actually happened*, not
from the ticket title. The technician edits and posts.

Documented in [drafting.md](drafting.md). What shipped, and what the building
of it settled:

- **Two artefacts, two prompts.** A solution is about this ticket and keeps the
  customer in it; an article is about the class of problem and must take the
  customer out. A model asked for both at once does neither.
- **glpi-osquery needed no integration.** It already writes its findings into
  the timeline as a followup, so reading the timeline collects them. One of the
  three named evidence sources turned out to be free.
- **The placement was the design.** Not prefilling the solution field: GLPI's
  editor opens empty as always, with one line above it offering the draft, and
  Insert fills the *editor* rather than the database. The technician's Save is
  still the only thing that publishes.
- **Articles are created unpublished** — no visibility rows, so only the author
  can see one until somebody publishes it. Not configurable, because an option
  to publish on creation would be one checkbox between generated prose and a
  customer-facing knowledge base.
- **A `gaps` field was not in the plan and earned its place immediately.** Asked
  what the evidence does *not* establish, a real model answered "the fix was
  confirmed on two of the three machines, not the third" — which nobody had
  written down anywhere.
- **It refuses on a thin ticket.** A plausible paragraph written from a title is
  more likely to be posted than an honest refusal, because it reads like work
  somebody already did.

Shape as predicted: the `quality` tier, on demand, no queue.

---

**Tier 1 is complete**, and one of the three has since been withdrawn. What
remains is still a pipeline rather than two independent things: triage files the
ticket so the right procedure fires, and drafting reads what the procedure
recorded. Precedent — "how was this fixed last time" — is the part that left
with semantic search, and the `search_tickets` tool is what answers it now, on
the model's initiative rather than by standing index.

### Also shipped: HEIMDALL, the troubleshooting agent

Not on the original list, and it turned out to be the thing the tool registry
was actually built for. A panel over any page, with the whole tool set rather
than a chosen few, documented in [assistant.md](assistant.md).

The lesson from building it is worth writing down: **the model was never the
variable.** The same small local model asked why a laptop keeps dropping gives a
useless general answer with no tools and names the switch port and its 20,431
error counter with them. Everything that made this feature good was a tool
someone else's plugin already had — glpi-osquery's live query, glpi-netscan's
traps, GLPI's own network inventory — reachable because the registry was built
before anything used it.

It also settled a question the roadmap had left open. Live osquery is not gated
behind "tools that change data": it is gated on glpi-osquery's own free-form SQL
right, which that plugin grants to nobody by default. The principle is that the
assistant reaches exactly what the technician driving it could reach by hand,
and no more — which is a cleaner rule than any new switch would have been.

---

## Tier 2

- **Shift handover and escalation summaries.** A 40-followup ticket condensed to
  "what has been tried, what is known, what is next". Zero customer exposure,
  and it pairs with on-call/escalation if that gets built.
- **Explaining osquery output.** A technician facing 200 rows of
  `process_events` asking "what is anomalous here?" — slow for a human, well
  suited to a model. Strictly advisory.
- **SOP authoring from clusters of resolved tickets — shipped, in glpi-sop.**
  Draft a procedure from how the last dozen of these were actually fixed. An
  admin reviews before publishing, and SOPs already ship inactive by default,
  so the gate exists.

  It lives in **glpi-sop** rather than here, and that is the dependency
  direction rather than a preference: glpi-sop already depends on this plugin
  for its tools, and the authoring writes glpi-sop's own tables. A feature here
  that wrote another plugin's schema would invert an arrow that currently
  points one way. It calls `Client::complete()` like any other consumer and
  catches what the tenant gate throws.

  Two things the building of it settled:

  - **The cluster was the easy part, once it stopped being a cluster.** This
    entry read as blocked when semantic search was withdrawn, because "clusters
    of resolved tickets" sounds like it needs similarity. The key that works is
    the **ITIL category** — and it is not a substitute, it is better: an SOP
    attaches on category, so the thing that selects the evidence is the thing
    the procedure will be triggered by. What a distance metric cannot do is
    notice a category is a dumping ground, so that judgement is a `usable`
    field in the schema and a refusal the model is told to give.
  - **`Draft\Evidence` was reusable across the plugin boundary, and the
    rendering was not.** The gatherer reads the timeline, the tasks, the
    procedures and — free — glpi-osquery's findings, and glpi-sop calls it
    unchanged. But drafting renders one ticket at up to 12,000 characters, and
    this reads a dozen at once, so the compression is glpi-sop's own. The line
    between what a gatherer and a renderer are for turned out to be exactly
    where the reuse stopped.

---

## The one place AI may touch customer-facing text — **shipped**

**Reviewing the technician's drafted reply, never writing it.** Flag leaked
internal notes, unexplained jargon, a missing next step, wrong tone for the
account.

This inverts the risk: the model is a safety net over human output rather than
the author of it. It is the only exception to the rule at the top of this file.

Documented in [reply-review.md](reply-review.md). What shipped, and what the
building of it settled:

- **The four kinds are the whole vocabulary, and "is it correct" is not one of
  them.** Adding it was tempting and is the version that gets ignored: a
  confident "this is wrong" about work a technician has just done spends all
  the attention the other three flags needed, and it is the one claim the model
  cannot check from what it is given.
- **A quote that is not in the reply is dropped before it is shown.** Cheap,
  and it is the difference between a reviewer that can be argued with and one
  that appears to be reading a different document.
- **There was no click to count, so the outcome is read from the reply.** The
  reviewed text is fingerprinted and compared with what is actually posted:
  against the flagged reviews, "was it edited in between" is the honest
  measurement. The fingerprint ignores markup and is a hash, not a copy —
  customer correspondence does not need a second home in a plugin's audit
  table.
- **The internal notes are what make it work and why it is off by default.** A
  leak can only be spotted by something that knows what was internal, which
  means sending the private followups. That is the most sensitive flow this
  plugin has, so it is a switch of its own on top of the entity gate.
- **The followup form does not hand its hook the parent.** Unlike the solution
  form: the timeline includes that template with three variables and none of
  them is the ticket. The blank followup core builds does carry `itemtype` and
  `items_id`, and that is the way in. Reading the options instead renders
  nothing at all, on every ticket, with no error.

---

## Deliberately not building

- **Auto-replying to requesters.** The stated exclusion, and the right one.
- **Auto-closing or auto-resolving tickets.**
- **Sentiment scoring of customers.** Frequently wrong, faintly creepy, and no
  action falls out of it.
- **Anything that silently mutates ticket data.** Always a proposal.
- **Fine-tuning, for now.** There is no labelled data yet. Prompt plus
  retrieval goes a long way, and the accept/reject log from feature 2 is
  precisely what would earn the right to fine-tune later.

---

## Architecture decisions that follow

These shaped the provider layer in `docs/providers.md`:

1. **Tenant isolation is the first design decision, not later hardening.** This
   is client data under contracts we did not write; some clients will forbid it
   leaving the estate outright. That is a per-entity config gate, not an
   exception bolted on afterwards.
2. **Tier the model to the call shape.** Triage is high-volume and
   low-value-per-call; drafting is the opposite. Hence two named model slots per
   provider (`fast` / `quality`) rather than one.
3. **Structured output where the answer is data.** Triage and extraction return
   a schema-validated object, not prose to be parsed with a regex.
4. **Batch the non-interactive work.** Nightly KB clustering, back-classifying
   historical tickets, report narration — roughly half price at every vendor
   that offers it, and none of it is latency-sensitive.
5. **Log the prompt, provider, model and version with every suggestion.**
   Without it, a regression cannot be attributed to our prompt versus a vendor
   changing a model under us.
