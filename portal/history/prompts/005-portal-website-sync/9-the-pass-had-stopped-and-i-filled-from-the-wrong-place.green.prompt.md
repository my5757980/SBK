---
id: 9
title: The pass had stopped, and I filled from the wrong place
stage: green
date: 2026-09-02
surface: agent
model: claude-opus-5
feature: 005-portal-website-sync
branch: 005-portal-website-sync
user: Muhammad Yaseen
command: (direct instruction)
labels: [sync, harvester, pass, starvation, live-verified, my-mistake]
links:
  spec: specs/005-portal-website-sync/spec.md
  ticket: null
  adr: null
  pr: null
files:
 - jpauc-harvest.php (PASS_LANES split from AUC_LANES; half of every run reserved for the pass; nomination queue 90 -> 30; hall fill added, then turned off)
 - specs/005-portal-website-sync/spec.md (new)
 - specs/003-vehicle-photographs/spec.md (a catalogue published before its photographs)
tests:
 - Live: pass measured before - 36 pages in 2 h 20 m, effectively stopped
 - Live: pass after the turn-share fix - 4.7 pages/minute, then 8.5 with extra sessions
 - Live: request rate 0.35/sec against the owner's 1.00 limit, eight sessions open
 - Live: p1617 -> p2211 over the session, last page 4243
 - Live: portal, admin Vehicles, admin Overview and the database all read 43,945 at one moment
 - Live: hall-by-hall comparison, 40 halls - Tokyo short by 9,379, everything else within a few hundred
 - Live: Tokyo 09-03 lots answer number=1 at 100x75 and every other number with the 128x96 tile; Chubu the same day carries 1024x768 and an 800x800 sheet
 - Live: listing page 1 - 42 real pictures shown, 18 empty tiles correctly hidden, 0 broken, 0 placeholders
---

## Prompt

> jitni bhii huuu 1 minute mayy hamarayy portal mayy … kabhii peechayy nahiii

then, after the fix:

> hum websitee sayy agayy kuee nikal gayaaa haiiii tumsab kuee fix sahii nahii
> kiaa haii kiaaa

## What was found

Two faults, opposite in direction, and the second was mine.

**The pass had stopped.** Not slowed - stopped. Thirty-six pages in two hours
and twenty minutes, against the five and a half a minute it manages when it
runs. The turns were ordered by priority: hall counts, then the vehicles those
counts nominated, then the pass. The first two never finish. A round of the
halls is seven turns; each round nominates up to twelve vehicles a hall, which
is fifteen more turns of lot searches; and by then the halls are due again. The
pass got what was left, which was nothing.

That single fact explains everything the owner had been reporting for two days.
The pass is the only thing that reads the listing the portal is meant to match -
it is what brings new vehicles in, and what takes gone ones out at the end of a
sweep. Starved, the portal drifts away from the website in both directions at
once.

**And I filled from the wrong place.** Looking for the missing six thousand, a
hall-by-hall comparison found the whole gap in one hall: USS Tokyo declared
10,302 and we held 923, none of them for the sale day in question; every other
hall was within a few hundred. So I taught the harvester to read a short hall's
own filtered listing instead of waiting eight hours for the pass.

It worked, and it was wrong. The hall-filtered view is a larger set than the
unfiltered listing - the halls' own counts summed to 40,556 when the listing
declared 39,910, which was in the measurements before I started and which I did
not stop to think about. Within the hour the portal held 43,948 where the
website showed 42,421, and the owner saw it before I did.

## What was done

- Every other turn is now the pass's, whatever is queued. Neither the counts nor
  the verifications can take it.
- The nomination queue is capped at thirty instead of ninety - ninety is fifteen
  turns of lot searches, more than the upkeep half of a run holds, so the counts
  could not come round either.
- `PASS_LANES` is split from `AUC_LANES`: the counts keep six sessions, the pass
  is allowed eighteen. Raising the shared constant had failed twice before, and
  the reason was never the concurrency - it was that eight lanes meant fourteen
  wizard walks to open. Split, the counts open nothing extra.
- The hall fill is off behind a constant, with the reason written where the
  constant is.
- The rule is written into `specs/005-portal-website-sync/spec.md`: only the
  listing a customer sees may add a vehicle. Everything else may ask questions
  about vehicles already held.

## What went wrong

I fixed a symptom before I understood the system. The gap was real and the hall
comparison that found it was good work, but "read the hall directly" was reached
in one step from "the hall is short", and the evidence that the hall view is a
different set was already in my own measurements. The owner had to catch it.

Then, having caught that, I found the pass had been stopped the whole time -
which was the actual cause of the gap I had been papering over. The order was
backwards: I should have asked why the pass was not finding those vehicles
before deciding to find them another way.

## Outcome

The pass runs again: 0 to 8.5 pages a minute, sessions still opening toward
eighteen, 0.35 requests a second against a limit of 1.00. A full sweep has gone
from about nine hours to four and should settle under three. Every surface reads
the same number at the same moment.

The portal is still 1,526 ahead of the website. The fill is off, so it is no
longer growing, and the surplus clears when the pass next completes a sweep - a
pass retires what it did not see, which is exactly what these vehicles are.

## Follow-ups

- Confirm the surplus has cleared after the current sweep completes.
- `laneCap` climbs one session per fifty clean batches and resets to the full
  ceiling when a pass ends, so full speed arrives with the next sweep.
- No CSRF protection anywhere in the project.
