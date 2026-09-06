# Reply review

*Setup → AI → Reply review.* Off by default.

A technician writing a reply to a customer can ask for a second read before
sending it. What comes back is a short list of remarks — or, most of the time,
nothing.

This is the one place in the plugin where a model touches customer-facing text,
and it is allowed because of the direction: it reads what a person wrote rather
than writing on their behalf. A model that writes to a customer is a
reputational event carried on a client's behalf the first time it is
confidently wrong. A model that reads a draft before a person sends it can only
cost them the ten seconds it takes to disagree.

---

## What it looks at, and what it says

Four things, and only four:

| Flag | What it means |
|---|---|
| **Internal content** | Something in the reply came from the internal notes and should not leave the team — a colleague's aside, a supplier's failure, a candid word about the customer's equipment, or a phrase lifted straight out of a private followup. |
| **Unexplained term** | A term, abbreviation or product name the customer has not used themselves and would not be expected to know. |
| **No next step** | The reply leaves the customer without knowing who does what, by when, or what is needed from them. |
| **Tone** | Wrong register for a customer: blaming them, brusqueness that will read as annoyance, or promising something the ticket does not support. |

It is **not** asked whether the reply is factually right. That is a claim about
the work rather than about the writing, it cannot be checked from here, and a
confident "this is wrong" about something a technician has just done is the
fastest way to lose their attention for the three flags that were worth having.

It does not flag spelling, grammar, formatting, or brevity by itself.

Every flag except *No next step* carries a **verbatim quote** from the reply,
and a flag whose quote cannot be found in the reply is discarded before it is
shown. A reviewer that appears to be reading a different document is one nobody
trusts a second time.

## What it never does

- **Rewrite anything.** There is no suggested version to accept, because
  offering one is how "review" becomes "write" over a fortnight of people
  clicking the easier button.
- **Block Save.** The reply belongs to the technician, and they can send it
  unchanged with the flags on screen. A review that can stop a customer being
  answered is a review that gets switched off on the first busy afternoon, and
  rightly.
- **Write to the ticket.** Nothing is stored on the item. The reply is still
  unsaved text in an editor at the moment it is read, which is the entire point
  of reading it there.
- **Run by itself.** It is a button. Reviewing every reply as it is typed would
  be a provider call per keystroke and a running commentary nobody asked for.

## What it sends

The ticket's title, what the requester reported, **the internal notes on the
ticket**, and the unsent reply.

The internal notes are the point. Flagging a leak means knowing what was
internal — a phrase is only *carried across* if there is somewhere it was
carried from — and without them the model is guessing at what sounds private,
which is the same guess the technician already made. They are also, plainly,
the most sensitive thing this plugin sends anywhere.

That is why the feature has a switch of its own on top of the entity allowlist,
and why it is off until an administrator turns it on. The allowlist still
applies underneath: an entity that may not use AI features cannot use this one,
and the strip does not draw there at all rather than drawing a button that
refuses.

Model tier: **quality**, unlike triage. This is a judgement about register and
implication rather than a classification, it happens once when somebody presses
a button, and the way it fails is by being wrong in a way that reads as
pedantic — which is exactly what a cheaper model is worse at.

## Where it draws

Inside GLPI's own reply editor, above the field, on the central interface only.
Requesters never see it.

There is one thing worth knowing if this ever has to be moved. The followup
form fires `PRE_ITEM_FORM`, but — unlike the solution form — it does not hand
the hook its parent: the timeline includes that template with `form_mode`,
`subitem` and `mention_options` and nothing else, so `$params['options']['item']`
is empty and reading it renders an empty strip on every ticket, silently. What
*is* populated is the blank followup itself: core sets `itemtype` and `items_id`
on it before rendering, and that is what `Reply\Panel` reads.

## How it is measured

Every other feature here is measured by a click — a triage chip is accepted or
dismissed, a draft is inserted or discarded. A review has no click to count:
nothing is applied, and a technician who reads a flag and decides it is wrong
does exactly what a technician who never saw it does.

So the outcome is read from the reply. The reviewed text is fingerprinted, and
when a followup is posted to the same item by the same person within a couple
of hours the fingerprints are compared: **was the reply edited between being
reviewed and being sent?** Measured against the reviews that raised something,
that is as close to "did this catch anything real" as this can get without
asking anybody to rate it.

The fingerprint ignores markup and whitespace, so reflowing a paragraph does
not read as an edit, and it is a hash rather than a copy — this table records
that a reply changed, never what it said. A second copy of customer
correspondence living in a plugin's audit table is one nobody would think to
look for when a customer asks what was written about them.

The figures are on the settings page: reviews run, how many raised something,
how many of those replies were sent, and how many had been edited by then. If
that last figure stays near zero, the feature is costing attention and buying
nothing — switch it off.
