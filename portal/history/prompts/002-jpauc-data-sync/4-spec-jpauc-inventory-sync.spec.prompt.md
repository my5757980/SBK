---
id: 4
title: Spec the JPAuc inventory sync
stage: spec
date: 2026-08-12
surface: agent
model: claude-opus-5
feature: 002-jpauc-data-sync
branch: 002-jpauc-data-sync
user: Muhammad Yaseen
command: /sp.specify
labels: [spec, data-sync, jpauc, rate-limiting, supplier-change]
links:
  spec: specs/002-jpauc-data-sync/spec.md
  ticket: null
  adr: null
  pr: null
files:
 - specs/002-jpauc-data-sync/spec.md
 - specs/002-jpauc-data-sync/checklists/requirements.md
tests:
 - none (specification only; no code changed)
---

## Prompt

theek hy tmhari baaty ab spec bna do us me sab likh do taky implement krty wakt koi mistake na ho

(Preceding context in the same session: the previous supplier, nikkyocars, blocked
the portal's server IP and rejected the account after the sync rate was raised to
roughly 149,000 requests a day. A replacement source, jpauc.com, was surveyed
without an account: it serves plain server-rendered pages, publishes per-sale-day
vehicle counts on its section pages, returns ten vehicles per page to a guest,
requires a sale day to be accompanied by lot numbers before it returns anything,
exposes predictable photograph addresses, and carries a public archive of
concluded auctions. The user then reviewed a nine-point list of anti-blocking
practices, which was corrected: identifying as a browser does not help, and the
larger levers — asking only for what changed, and a hard daily budget with a kill
switch — were missing from it.)

## Response snapshot

Created branch `002-jpauc-data-sync` and wrote the specification with four
prioritised user stories, twenty-five functional requirements, ten measurable
success criteria, eleven dated assumptions, and explicit scope and dependency
sections.

The shaping decision: **Story 3 (cannot get us cut off again) is priority P1
alongside Story 1 (the portal has stock again)** — not a later hardening pass.
The budget, the delay, the single-flight rule, the exponential backoff, the
stop-on-refusal and the kill switch are written as functional requirements
FR-012 to FR-020 rather than as non-functional notes, because on the previous
supplier they were treated as tuning, built last, and never constrained anything.

Two further decisions recorded in the checklist:

- Success criteria SC-003 and SC-005 count source requests, which reads
  technical for a spec. Kept deliberately — the request count is the business
  constraint that decides whether the supplier survives, and it is verifiable
  without knowing how the sync is built.
- No `[NEEDS CLARIFICATION]` markers. The three questions worth asking (how
  fresh, how much risk, account or not) are already answered by what happened
  last time; the budget is set low and made configurable so the owner can raise
  it having seen it hold.

Edge cases were written from the specific failures of the previous build rather
than generically: the thirteen-hour clock gap that once dropped a whole day of
listings, silent parse failure reading as "no new stock", lot numbers colliding
across auction halls, and overlapping runs spending the budget twice.

## Outcome

- ✅ Impact: The replacement supplier is specified with the constraint that lost
  the last one written into the requirements rather than left to judgement at
  build time. Ready for `/sp.plan`.
- 🧪 Tests: None — specification only, no code changed.
- 📁 Files: `specs/002-jpauc-data-sync/spec.md`,
  `specs/002-jpauc-data-sync/checklists/requirements.md`
- 🔁 Next prompts: `/sp.plan` — but confirm Dependency 1 first (see reflection).
- 🧠 Reflection: The spec's own largest risk is recorded as a dependency, not
  buried: jpauc.com is operated by the same company as nikkyocars, which already
  refuses this server's address. If it refuses too, the approach is invalid
  before a line is written. That check costs one request and should precede
  planning.

## Evaluation notes (flywheel)

- Failure modes observed: On the previous supplier, rate limits were treated as
  tuning rather than as requirements, so nothing in the design stopped the rate
  being raised until the source cut us off. Freshness was promised (five minutes)
  before the source's tolerance had been measured — the promise then drove the
  behaviour that broke it.
- Graders run and results (PASS/FAIL): Spec quality checklist — PASS, after one
  iteration fixing three items (source URLs leaking into assumptions, an
  unbounded archive scope, and an unjustified request-counting success criterion,
  the last kept with its reasoning stated).
- Prompt variant (if applicable): n/a
- Next experiment (smallest change to try): Before planning, make one request
  from the production server to the new source and record the response. It either
  confirms or invalidates Dependency 1, and it is the cheapest test in the
  feature.
