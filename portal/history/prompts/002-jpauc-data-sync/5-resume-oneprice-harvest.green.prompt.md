---
id: 5
title: Resume the Oneprice harvest
stage: green
date: 2026-08-14
surface: agent
model: claude-opus-5
feature: 002-jpauc-data-sync
branch: 002-jpauc-data-sync
user: Muhammad Yaseen
command: (direct instruction)
labels: [harvest, oneprice, jpauc, browser-vpn, resume, coverage-gap]
links:
  spec: specs/002-jpauc-data-sync/spec.md
  ticket: null
  adr: null
  pr: null
files:
 - (browser-resident harvester; no repository file changed)
 - watch.php (temporary progress endpoint, deployed to the server only)
tests:
 - Progress verified against MySQL, not the harvester's own tally
 - Baseline 39,623 → 41,071 → 45,104 rows in `cars` where source_section='oneprice_mix'
---

## Prompt

achaa ab playwrite connected haiii ab aj 14 august ka on prize data shuru karoo lanaaa wahi sayy continue jahaa say kal choraaa thaaaa

(Follow-up in the same session:)

achaaa bat sunoo kal one price kaaaa 39 thousand taak ayaa thaaaa ab us kayy agayaa sayy ana shuru hogaa naaaaaa

## Response snapshot

Rebuilt the `window.SBK` harvester inside the VPN-connected browser tab — it does
not survive a tab close, so it is written fresh each session — and restarted it.

**Where "where we left off" actually is.** The owner read the 39,623 figure as the
resume position. It is not: it is the number of vehicles stored. Position is a lot
number, and yesterday's run covered lots 1 through 63,199. The queue therefore
resumes at **lot 63,201**, not at anything resembling 39,000. This was stated back
plainly rather than answered with a bare "yes", because agreeing would have left
the owner expecting the count and the position to track each other, and they do
not.

**A coverage gap was queued rather than assumed away.** Lots 5,000–24,999 hold
zero vehicles in the database. That is either genuinely empty ground or a stretch
yesterday's run skipped, and nothing on hand distinguishes the two. The range is
appended to the queue behind the new ground, so the answer arrives as data.

Two corrections from yesterday are carried into this build:

- Colour is read as the **last** element of `.result`, never positionally. The
  grade line is absent on lots that have no grade, which slid colour into the
  grade column on roughly a fifth of rows.
- A read that fails three times **requeues its batch** instead of dropping it.
  The earlier loop abandoned 400 lot numbers per hiccup, silently.

Settings: `gap: 1000ms`, `BATCH: 400`, `budget: 5000` requests — the owner's own
figure from yesterday ("nahii abhii 5 hi bhater"), left untouched.

A `watch.php` endpoint was deployed to the server, and a Monitor polls it every
four minutes: it reports each 5,000 vehicles and, more importantly, announces when
the run stops. The owner should not have to ask whether it is still alive.

## Outcome

- ✅ Impact: Harvest resumed at lot 63,201 and confirmed writing — 39,623 →
  45,104 vehicles (27% of 165,213) at the time of writing.
- 🧪 Tests: Counts read from MySQL directly. The harvester's `written` tally is
  deliberately not trusted; that tally is exactly what reported success while
  16,396 vehicles were collapsing onto one row.
- 📁 Files: No repository file changed. The harvester lives in the browser; the
  progress endpoint lives on the server.
- 🔁 Next prompts: When the budget runs out, either raise it or resume tomorrow.
  Then the 5,000–24,999 verdict, then the Past Auction section (~167,000), then
  items 4, 5 and 8 from sir's list (roles, staff scenario, permissions layout).
- 🧠 Reflection: The whole run depends on one browser tab staying open with the
  VPN up. Nothing in the design survives that tab closing, and the owner has to
  be told so rather than discovering it from a stalled counter.

## Evaluation notes (flywheel)

- Failure modes observed: A count was about to be mistaken for a position. Left
  uncorrected it would have produced a real bug later — resuming from "lot 39,623"
  would have re-read 24,000 lots already held and burned the day's budget on them.
- Graders run and results (PASS/FAIL): Live database check — PASS (count rising,
  +850 in four minutes, +5,481 over the run so far).
- Prompt variant (if applicable): n/a
- Next experiment (smallest change to try): When the 5,000–24,999 range is read,
  compare its yield against a same-sized range known to be populated. If it comes
  back empty a second time, the range is genuinely bare and can be struck from
  the 165,213 denominator — which would move the true completion figure up.
