---
id: 14
title: The queue that waited for a round that never came
stage: green
date: 2026-09-08
surface: agent
model: claude-opus-5
feature: 005-portal-website-sync
branch: 005-portal-website-sync
user: Muhammad Yaseen
command: (direct instruction)
labels: [sync, harvester, reconciliation, deadlock, throughput, live-verified, my-mistake]
links:
  spec: specs/005-portal-website-sync/spec.md
  ticket: null
  adr: null
  pr: null
files:
 - jpauc-harvest.php (recWait dropped; RUN_LIMIT 285 -> 240)
 - admin/_header.php (the portal's three sections in the desk's top bar)
tests:
 - Live: recWait stuck at 1 across five samples over seven minutes, recQ 36 halls, recHall '' throughout
 - Live: hotIdx 17 -> 23 and no wrap in twenty-five minutes; hotAt 1,502s old and growing
 - Live: after deploy, first run printed "hall milaana shuru: Kinki (1086 zyada, 203 page ulta)"
 - Live: Kinki completed - portal 51,669 -> 50,598, gap +1,673 -> +602, 1,071 retired
 - Live: 44 runs in eight hours where 96 were due; 0.2 req/sec against the owner's 1.00
 - Live: website 49,996 against portal 51,663 at the worst point today
 - Live: admin/dashboard.php 302, admin/login.php 200, admin/cars.php 302 - no PHP error after the header change
---

## Prompt

> phlayy action wala check karooo portal website jo kal sahii kiaaa fix kiaa sab
> huaa haii sahii hu rahaa haii ya nahiii sab sahi cahal rahaaa haii phalayy is
> ka confirm karooo

and later

> jab hum client side login kartayyy haiii tum ko fixed price milta haiiii admin
> side nahii haii kuee admin side bhii top navba mayy hona chiya naaaaaa

## What was found

Yesterday's fix worked: the portal no longer runs away from the website. It was
+3,243 and climbing without limit; this morning it was +1,102.

But it had stopped closing, and the reason was mine.

**The queue was deadlocked.** When a hall finished being read out, the code set
`recWait = 1` so the counts could come all the way round before the next hall
was chosen - the idea being that the next hall should be judged on a fresh gap.
`recWait` is cleared in one place only: the moment a round of hall counts wraps.

That round had not wrapped in twenty-five minutes. Measured across five samples:
`recWait` 1 the whole time, `hotIdx` crawling 17 → 23, `recHall` empty, and
**thirty-six halls queued with not one of them being read.** Gifu stood 401 over
with its own name on the list.

The wait bought nothing. The gap decides which hall goes first and how many may
be taken off, and the second of those is recomputed at the end of the read from
a fresh count of what we hold - so a stale figure can only make the cap smaller.
A hall queued on an old gap removes less than it might, never more. Dropped.

**And half the day was going nowhere.** Yesterday I raised `RUN_LIMIT` from 240
to 285 to "fill the five-minute window". It bounds the loop, not the run: after
the loop there are still the closing counts and the summary, so a 285-second
loop is a 295-second script, and the cron - which fires every 300 seconds -
arrives while the lock is still held and exits having done nothing. Forty-four
runs happened in eight hours where ninety-six were due, and the request rate sat
at 0.2 a second against the one a second the owner allows.

Sixty seconds of margin costs fifteen percent of a run and buys back half the
day. Back to 240.

## What it did

The first run after the deploy printed `hall milaana shuru: Kinki (1086 zyada,
203 page ulta)`, and when Kinki finished the portal fell from 51,669 to 50,598 -
**1,071 vehicles retired in one hall** - taking the gap from +1,673 to +602.

## The desk's top bar

Separately: the customer's top bar carries Auctions, Fixed price and Past
prices; the desk's carried one link called "View portal" that went to the
auctions. Staff answering a question about fixed-price stock had to land on the
auctions and find their way across. All three are there now, in a new tab as the
single link always was.

## Still open

- The portal is over on the 8th and short on the 9th - concluded lots not yet
  marked, and newly published lots not yet read. Both are being worked on by
  machinery that is now actually running.
- Whether the browse listing publishes a lot's result, or only the lot search
  does, is still unmeasured. If it does not, the pass writes 'available' over a
  result the lot search established and a sold car returns to the portal. The
  guard - never let 'available' overwrite a concluded status - is written down
  here rather than applied, because it has not yet been shown to be needed.
