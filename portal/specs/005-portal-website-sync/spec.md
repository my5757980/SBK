# Feature Specification: Keeping the Portal Level with the Website

**Feature Branch**: `005-portal-website-sync`
**Created**: 2026-09-02
**Status**: Implemented; one correction still settling
**Input**: Owner, repeatedly: "jitni bhii huuu 1 minute mayy hamarayy portal mayy … kabhii peechayy nahiii" and later "hum websitee sayy agayy kuee nikal gayaaa haiiii"

## Context

The portal's whole promise is that it shows what jpauc.com shows. Not more, not
less, and not an hour late. Everything in this specification exists because that
promise was broken in both directions on the same day: the portal sat at 33,050
while the website declared 39,910, and then — after a fix that was wrong — ran
ahead of it at 43,948 against 42,421.

Neither number was a display fault. Both were the sync, and the two failures had
different causes that looked identical from the outside.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A vehicle that has sold stops being offered (Priority: P1)

**Why this priority**: Offering a car that cannot be bought is the one fault a
customer will not forgive. It is worse than showing nothing.

**Acceptance Scenarios**:

1. **Given** a hall's count at the source drops below what the portal holds,
   **When** the next round of counts runs,
   **Then** that hall is queued to be **read out of the customer listing**,
   narrowed to that hall.
2. **Given** a hall being read out,
   **When** the read runs,
   **Then** it walks the hall's pages **backwards, last to first**, and stamps
   only vehicles the portal already holds. It never writes a vehicle in.
3. **Given** a completed read of a hall,
   **When** it is done,
   **Then** every vehicle of that hall the read did not see is marked gone and
   leaves every surface at once — capped at the number the count said had gone,
   plus a small slack.
4. **Given** a read that came back short — pages that failed, or fewer vehicles
   than the hall declared,
   **When** it finishes,
   **Then** nothing is removed. A count is never enough on its own; acting on
   counts alone took live vehicles off the portal for hours.
5. **Given** a vehicle that is gone from the listing,
   **When** the source's lot search is asked about it,
   **Then** that answer is **not used**. It answers from a wider set and says
   "present" for vehicles the listing has dropped — see the 7 September record.

### User Story 2 - A new sale day appears by itself (Priority: P1)

**Acceptance Scenarios**:

1. **Given** the source adds a sale day to its wizard,
   **When** the sessions are next walked,
   **Then** the day is included without anybody naming it in code.
2. **Given** a new day's vehicles,
   **When** the tail walk runs,
   **Then** it keeps reading past the first page of already-known vehicles —
   three quiet pages, not one — because a day's arrivals are not all at the end.

### User Story 3 - The portal never runs ahead of the website (Priority: P1)

**Why this priority**: A portal holding vehicles the website does not list is
offering stock nobody can bid on, and it is invisible to every check that only
counts what is missing.

**Acceptance Scenarios**:

1. **Given** any source of vehicles other than the listing the customer sees,
   **When** it disagrees with that listing,
   **Then** the listing wins and the other source is not used to add vehicles.
2. **Given** the portal's count,
   **When** it is compared with the website's declared total,
   **Then** it is at or below it, never above.

### Edge Cases

- **The hall-filtered view is a larger set than the listing.** Asking the source
  for one hall returns more vehicles than that hall contributes to the
  unfiltered listing — the halls' counts summed to 40,556 when the listing
  declared 39,910. Every vehicle in it is real and reachable; they are simply
  not in the set a customer browsing jpauc sees.
- **The lot search is a larger set still.** 8,377 vehicles for one day where the
  listing showed 4,905. It is reliable for "is this exact vehicle still there?"
  and useless as a source of vehicles to add.
- **A hall publishes ten thousand vehicles at once.** USS Tokyo does. There is no
  way to be told which are new; the only honest answer is to read.
- **The counts and the verifications never run out of work.** Each round of
  counts nominates more vehicles than a run can verify, so a priority order of
  counts → verifications → pass means the pass never runs at all.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Vehicles MUST only be added from the source's own unfiltered
  listing — the pass and the tail. No hall-filtered view, no lot search, no
  other endpoint may introduce a vehicle.
- **FR-002**: A vehicle MUST only be marked gone after being asked after by name
  and not found. A count showing a surplus MAY nominate; it MUST NOT decide.
- **FR-003**: The run MUST guarantee the pass a share of every run. Half the
  turns are the pass's and no queue may take them.
- **FR-004**: The nomination queue MUST be bounded well below what the upkeep
  half of a run can serve — thirty, not ninety — so the counts themselves are
  not starved by the verifications they created.
- **FR-005**: The number of reading sessions MUST be separate from the number of
  counting sessions. The pass needs concurrency; the counts do not.
- **FR-006**: Concurrency MUST give way on its own. Two sessions failing in one
  batch drops a session and the ceiling with it; the ceiling climbs back only
  after fifty clean batches.
- **FR-007**: The request rate MUST stay below one a second, which is the limit
  the owner set after the previous supplier refused the server's IP.
- **FR-008**: A pass MUST end only at the declared last page. An empty page is a
  failed read and means nothing.
- **FR-009**: Retirement MUST require two passes, not one, so a single bad sweep
  cannot empty the portal.

### Key Entities

- **The pass** — a walk of the unfiltered listing, page by page, six to
  eighteen at a time. The only thing that both adds vehicles and, on completing,
  retires the ones it never saw. The authority.
- **The tail** — a short walk backwards from the last page every minute, for
  arrivals at the end of the catalogue.
- **The hall counts** — one number per hall per round, used to nominate
  suspicions. Never to add, never to remove. They cover the day being sold, plus
  two of the later days' halls each round, because sellers withdraw lots days
  ahead of a sale.
- **The hall read (reconciliation)** — the customer listing narrowed to one
  hall, walked backwards, page by page. The only thing that answers *which* of
  ours have gone. It stamps what it finds and never inserts.
- **The lot search** — `/auction/search?lots=N`. **Not an authority on anything.**
  It answers from a wider set than the listing and must never decide a removal.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: The portal's vehicle count is never above the website's declared
  total.
- **SC-002**: The pass advances in every run. *Measured before the fix: 36 pages
  in 2 h 20 m — effectively stopped. After: 4.7 pages a minute, and 8.5 once the
  extra sessions began opening.*
- **SC-003**: The request rate stays under one a second. *Measured: 0.35 a
  second with eight sessions open; the design ceiling of eighteen is projected
  at about 0.65.*
- **SC-004**: A full sweep of the catalogue completes in hours, not a day.
  *Measured: about 9 hours before, 4.3 hours at eight sessions, projected under
  3 at eighteen.*
- **SC-005**: Every surface reads the same number at the same moment.
  *Measured: client listing, admin Vehicles, admin Overview and the database all
  read 43,945 simultaneously.*

## What can and cannot be promised in a minute

The owner asked for everything within a minute. Two of the three are real:

| | within a minute? | why |
|---|---|---|
| A sold vehicle leaves the portal | **no — tens of minutes** | the count notices within a round, but knowing *which* means reading that hall out of the listing, and a hall-filtered page costs the source about four times a plain one. A hall of 4,750 is 475 pages. |
| A new sale day appears | **yes** | the wizard is re-walked and the tail reads the end |
| Ten thousand newly published vehicles arrive | **no** | that is a thousand pages; at the owner's own one-a-second limit it cannot be done in under twenty minutes, and a whole catalogue is hours |

Asking for the third in a minute means about seventeen requests a second. That
is the rate that got the previous supplier to refuse the server's IP, and it is
not on the table. What is on the table is a sweep measured in hours rather than
half a day, which is what the session-count change buys.

## Assumptions

- One request a second remains the owner's limit. Everything here is sized to it
  and currently uses about a third of it.
- The source will keep serving one page at a time per session and several
  sessions in parallel. Throughput comes from sessions, not from asking faster.

## Out of Scope

- Any endpoint other than the listing as a source of vehicles.
- Pushing past one request a second.

## Verification Record — 2026-09-02

| What | Before | After |
|---|---|---|
| Pass | 0 pages/min (stalled) | 8.5 pages/min, sessions still opening |
| Request rate | 0.25/sec | 0.35/sec (limit 1.00) |
| Full sweep | ~9 hours | 4.3 hours, projected under 3 |
| Nomination queue | 90 (starved everything) | 30 |
| Reading sessions | 6 | 18 was tried and **reverted to 6** — see below |
| Portal vs website | 33,050 vs 39,910 (behind) | 43,947 vs 42,421 (ahead by 1,526, clearing) |

### Eighteen reading sessions was wrong, and six is the number

Raising `PASS_LANES` to eighteen was measured before it was done - eighteen
requests together came back in 27s where six took 17s - and it still failed.
A burst of eighteen requests is not the same as eighteen sessions held open: the
give-way valve began dropping lanes within the hour (twelve down to eight), and
between the dropping and the reopening the pass stopped advancing altogether -
three minutes at 0.58 requests a second and not one page read.

Back at six the pass runs at **9.9 pages a minute**, its best measured rate, at
0.31 requests a second. **Do not raise it again without a reason better than a
throughput calculation.** The pass is worth more steady than fast: a stalled
pass puts the portal out of step with the website in both directions at once,
which is the fault this whole specification exists to prevent.

### The tail judged by id, and could not see what it was reading

The worst of the three, found on 4 September with the portal 20,000 behind.

The tail walks back from the end of the listing every minute and decides whether
a page holds anything new. Its test was the vehicle's **source id** against the
highest id it had ever seen: higher meant new, lower meant already known.

That is not a test of what we hold. A hall that publishes a sale day late gets
ids below the mark - page 3,706 held ten vehicles for 5 September with ids
around 979,175,562 against a mark of 979,194,422, and the portal had **none** of
them. The tail read that page, called every vehicle on it already known, counted
the page quiet, and stopped after three such pages. It could not find them at
any speed; only the pass could, and the pass was 2,600 pages away.

Two things followed from fixing it:

- **A page is quiet when we already hold everything on it** - `heldCarIds()`
  asks the database, one query a page. What we hold is a fact; the id was a
  guess about it.
- **The tail is now a reverse sweep.** `TAIL_QUIET` at 800 means "keep going",
  `TAIL_PAGES` at 60 bounds what one run may spend on it, and `tailBack` takes
  the walk up where it left off. The forward pass and the reverse tail meet in
  the middle, so a sale day published anywhere is found by whichever is nearer.
  It also stopped being counted against `checkCap`, which was a second, tighter
  bound nobody intended - it cut the walk off after twelve pages.

*Measured after: the tail walks back 12 pages a minute while the pass keeps 9.5
forward; the portal gains 21 vehicles a minute where it gained 6. The block 600
pages from the end is reached in about forty minutes rather than five hours.*

### Reading every page was the wrong shape of work

On 5 September the portal was 22,541 short of the website and **losing ground**:
it took in 5,003 vehicles while the source published 8,040. The catalogue had
grown to 5,710 pages, the run read about 21 a minute, and a full sweep was four
and a half hours - most of it spent re-reading pages we already held.

The owner asked the right question first: *where else are you taking the data
from?* Nowhere else - a wizard walked from scratch returned the identical
listing and the identical total, 57,092, against our own twelve-minute-old
session. The source was never the problem.

**A missing sale day is a block, not a scattering**, because the listing is
ordered by sale time. So one page in twenty is enough to find it. The tail now
samples at `TAIL_STRIDE`, and a sample holding anything we are missing queues
the nineteen pages behind it to be read in full; a sample holding nothing new
costs one request instead of twenty. Scanning the whole catalogue went from
5,710 requests to 286 - twenty-three minutes instead of four and a half hours.

Two things had to be fixed alongside it:

- **The tail took every turn.** Its gate is `tailAt`, which only moves when the
  walk completes, and a scan rarely completes - so the pass sat at page 1,021
  for ten minutes. The run now rotates three ways: upkeep, tail, pass, a third
  each.
- **Nine sessions was tried and refused.** Between six and eighteen there is no
  middle: nine collapsed the same way, from six open to one inside five minutes.
  The budget was never the limit - the run uses a third of the one request a
  second the owner set. **Six is the source's limit. More sessions is not the
  lever; reading fewer pages is.**

*Measured after: the portal gains 60 vehicles a minute where it gained 21, six
sessions steady, the pass advancing, 0.32 requests a second.*

### Known issues, open

- **The portal is 1,526 ahead of the website.** Caused by the hall-fill
  described below. The fill is off; the surplus clears when the pass next
  completes a full sweep, because a pass retires what it did not see.
- `car-details.php` returned HTTP 500 once during an earlier sweep, 200 on every
  retry since.
- No CSRF protection anywhere in the project.

## Verification Record — 2026-09-07

### The lot search was never going to say no

The portal read 54,695 against the source's 51,951 and was climbing — 55,194 an
hour later. Every day was over: 07 Sep +347, 08 Sep +1,372, 09 Sep +487, 10 Sep
+518, 11 Sep +20.

11 September was small enough to read out in full. The website listed 322
vehicles that day and the portal held 342. All twenty extra were MIRIVE Aichi's,
and none of them was in the listing. **Four of the five sampled came back from
the lot search as present.**

That is the whole fault. The only thing that removed a vehicle quickly asked the
lot search, the lot search answers from a wider set, so the answer was always
yes and nothing was ever removed. The live state showed it exactly: the
nomination queue sat full at 30, all day, retiring nothing.

The slow path — a vehicle missed by two consecutive complete passes — was
working and far too slow to cover for it. The previous complete pass took 12 h
17 m; the one running had been going 14.5 h and was at page 3,013 of 5,196. Two
of those is one to two days. Tokyo alone was offering 161 lots already sold.

### Time order was tested and does not work

A hall sells in time order, so the vehicles that have gone should be the
earliest-timed ones, and the source's own first page would mark the boundary —
no extra requests at all. Measured against Tokyo: its first page still begins at
`00:00:00`, because that hall lists lots with no published time and they never
leave the front. The boundary does not move. Rejected on the evidence.

### What replaced it

The hall is read out of the listing, backwards.

Backwards matters. The listing shrinks while it is being walked: a lot sells, it
leaves, and everything behind it slides forward one place. A reader moving
towards page one moves the same way the contents move — it can meet a vehicle
twice, but it can never step over one. Forwards it steps over them, and a
vehicle stepped over is indistinguishable from a vehicle that has gone. That is
the same fault that forced the two-pass rule on the main pass.

Two witnesses still decide: the count says **how many** have gone, the read says
**which**, and the removal is capped at the count's number plus twenty. A read
that came back short removes less than it should rather than more — a vehicle
wrongly left on the portal is a page a customer can still see; one wrongly taken
off is a page they cannot.

### Three things that hid the fix after it was written

- **The upload said it had worked and had not.** cPanel's file uploader reported
  "succeeded" twice while leaving the file at its 6 September mtime, and the
  directory listing served a cached size that agreed with whichever answer was
  wrong. `Fileman/save_file_content` writes. **Read the file back after every
  deploy** — the deploy tool's own answer is not evidence.
- **Choosing a hall was written as a turn of its own.** It asks the source for
  nothing, but it sat at the back of the queue behind the counts, and a run ends
  on the clock at 285 seconds while a round of counts takes about fifty of them.
  The turn never came. Tokyo sat 398 over for an hour with a queue naming it.
- **The read was getting a third of the turns.** The tail hunts for vehicles we
  are missing, and we were not missing any — we were carrying 3,140 the source
  no longer lists. It now stands aside while a hall is being read, and the counts
  drop to one round in three minutes. Six pages a minute became twelve.

### Never again

**No view of the source other than the customer listing may decide that a
vehicle is still there.** The hall-filtered view may not add one (that mistake
is recorded under 2 September). The lot search may not keep one. Both are the
source's own pages and both disagree with the listing, in opposite directions.

## The mistake this specification exists to prevent

A hall short by nine thousand vehicles was filled by reading that hall's own
listing. It worked — the portal went from 33,050 to 43,948 in an hour, where the
pass would have taken eight — and it was wrong, because the hall-filtered view
is not the listing the portal is meant to match. Within the hour the portal held
more vehicles than the website showed, and the owner saw it.

Nothing it took in was invented. Every one of those vehicles is at the source and
answers to its own lot search. They are simply not in the set a customer
browsing jpauc sees, and matching that set is the entire instruction.

**The rule, written down so it is not rediscovered:** the listing the customer
sees is the only thing that may add a vehicle. Everything else — hall counts,
lot searches, detail pages — may only ask questions about vehicles already held.
When the portal is behind, the answer is a faster pass, never a different source.
