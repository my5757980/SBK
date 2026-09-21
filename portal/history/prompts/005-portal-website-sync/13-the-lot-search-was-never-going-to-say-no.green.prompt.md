---
id: 13
title: The lot search was never going to say no
stage: green
date: 2026-09-07
surface: agent
model: claude-opus-5
feature: 005-portal-website-sync
branch: 005-portal-website-sync
user: Muhammad Yaseen
command: (direct instruction)
labels: [sync, harvester, retirement, lot-search, hall-reconciliation, live-verified, my-mistake]
links:
  spec: specs/005-portal-website-sync/spec.md
  ticket: null
  adr: null
  pr: null
files:
 - jpauc-harvest.php (lot-search judge removed; hall reconciliation added - RECON_ON/RECON_SLACK/RECON_CAP, touchCars, dbNow, hallHeld, retireHallUnseen, laterHouses; counts now reach the later days' halls; HOT_BUSY; RUN_LIMIT 240 -> 285)
 - specs/005-portal-website-sync/spec.md (the removal rule rewritten)
tests:
 - Live: portal 54,695 against the source's 51,951, and climbing - 55,194 an hour later
 - Live: per day - 07 Sep +347, 08 Sep +1,372, 09 Sep +487, 10 Sep +518, 11 Sep +20
 - Live: 11 Sep read out in full - website 322, portal 342, all 20 extra from MIRIVE Aichi, none in the listing
 - Live: the lot search returns 4 of 5 of those extra as present
 - Live: server state - toCheck stuck at 30/30, nothing ever retired
 - Live: previous complete pass 12 h 17 m; the one running was 14.5 h in at page 3,013 of 5,196
 - Live: Tokyo 07 Sep - source 3,353, portal 3,514; 161 sold lots still offered
 - Live: time-order rule tested and rejected - Tokyo lists lots at 00:00:00 that never leave the front
 - Live: after deploy - toCheck 0, recQ 16 halls, "hall milaana shuru: Chubu (475 zyada, 475 page ulta)"
 - Live: read speed 6 pages/min, then 12 after HOT_BUSY; request rate 0.36/sec against the owner's 1.00
---

## Prompt

> is walayy text ko abhiii skip karooo … dubaraa dekhoo confirm karoo hum phir
> website sayy agayy kuee ho gayaaa haiii dekhoo jaldieee

then

> 54,455 barta h ja rahaa haiii

then, when told the fix would make removals take 15-30 minutes:

> to nahii ya biki huii garii or naya din to tum nayy bolaa thaa 1 minute mayy
> hamrayy portal mayy hojayy gaaaaa karayy gaaaa ya work nahi kar raha tha kiaaa

## What was found

The portal was 2,744 ahead of the website and gaining. It was not gaining
because anything was being added wrongly. It was gaining because **nothing was
ever being removed**.

Removal had two paths and both were broken, one badly and one slowly.

**The fast path asked the wrong question.** A hall's count says how many the
source lists; ours says how many we hold. When ours was larger, each candidate
was put to the source's lot search - `/auction/search?lots=N` - and kept if it
came back. But the lot search is not the listing. It answers from a wider set:
on 11 September MIRIVE Aichi listed 322 vehicles and we held 342, and the lot
search returned the extra twenty as present. So the question could only ever
answer yes. The live state showed the consequence exactly - `toCheck` sat
permanently full at 30, nominating all day and retiring nothing.

**The slow path was too slow to matter.** A vehicle missing from two consecutive
complete passes is retired, which is the right rule. A complete pass took twelve
hours seventeen minutes; the one running had been going fourteen and a half and
was at page 3,013 of 5,196. Two of those is one to two days.

So a lot sold at ten in the morning was still on the portal at midnight. Tokyo
alone was offering 161 vehicles that had already gone under the hammer.

I had told the owner that sold vehicles left within a minute. The counting half
of that ran every minute and always had. The removing half never removed
anything, and I had not checked that any vehicle actually left before saying so.

## What was tried and rejected

**Retiring the earliest-timed excess.** A hall sells in time order, so the ones
that have gone should be the earliest. Measured against Tokyo: the source's own
first page still begins at 00:00:00, because that hall lists lots with no
published time and they sit at the front permanently. The boundary never moves,
so the rule finds nothing. Rejected on the evidence.

## What was done

The lot search is gone from the removal path. In its place, the hall is read out
of the listing itself - the same listing narrowed by `a[]`, which is what a
customer reads.

- **Backwards, last page to first.** The listing shrinks while it is walked; a
  lot sells and everything behind it slides forward. A reader moving towards
  page one moves with the contents and can meet a vehicle twice but never step
  over one. Forwards it steps over them, and a vehicle stepped over is
  indistinguishable from one that has gone.
- **Nothing is written in.** `touchCars()` only stamps rows we already hold.
  Reading vehicles INTO the portal from the hall view is the September mistake
  that put 6,496 strangers in the table; `FILL_ON` stays off and this does not
  reopen it.
- **Two witnesses before anything is removed.** The count says how many, the
  read says which, and the removal is capped at the count's gap plus twenty. A
  read that came back short removes less than it should rather than more.
- **The later days' halls are counted too.** `sellingHouses()` covers the day
  being sold, on the reasoning that a lot only leaves when it goes under the
  hammer. Sellers withdraw lots days ahead: 2,397 of the 2,744 sat on days not
  yet being sold. Two of them ride along with each round - `laterHouses()`.

## What went wrong on the way

- **The upload said it had worked and had not.** cPanel's uploader reported
  "succeeded" twice while leaving the file untouched at its 6 September mtime,
  and `list_files` served a cached size that agreed with whichever answer was
  wrong. `Fileman/save_file_content` writes; the uploader could not be trusted
  and the file has to be read back after every deploy.
- **The hall was queued and never picked up.** Choosing a hall asks the source
  for nothing, but it was written as a turn of its own, at the back of the queue
  behind the counts. A run ends on the clock at 285 seconds and a round of hall
  counts takes about fifty of them, so the turn that picks a hall never came.
  Tokyo sat 398 over for an hour with a queue naming it. It is now decided at
  the top of the turn and takes no turn at all.
- **The read was getting a third of the turns and needed more.** The tail hunts
  for vehicles we are MISSING; we were not missing any. It now stands aside
  while a hall is being read, and the counts drop to three minutes - `HOT_BUSY`.
  Six pages a minute became twelve.

## Where it stands

Live and running. Request rate 0.36/sec against the owner's limit of 1.00.

Chubu first, 475 over. Then fifteen more halls.

## What is honest about the speed

Not one minute. A hall-filtered page costs the source about four times what a
plain listing page costs, so reading a hall of 4,750 vehicles is 475 pages and
most of an hour. Sold vehicles will leave in tens of minutes, not in one, and
the standing surplus takes three to four hours to drain.

One minute would mean five to ten times the requests. The owner's rule is that
volume rises only with his consent, and it was not asked for here.
