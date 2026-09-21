# Feature Specification: JPAuc Inventory Sync

**Feature Branch**: `002-jpauc-data-sync`
**Created**: 2026-08-12
**Status**: Draft
**Input**: User description: "theek hy tmhari baaty ab spec bna do us me sab likh do taky implement krty wakt koi mistake na ho"

## Context

The portal's previous supplier (nikkyocars) cut us off: the server's IP is refused
and the account is rejected. The cause is settled and documented — we read far
more than the source would tolerate, roughly 149,000 requests a day against a
normal buyer's 50–100, because the supplier offered no way to ask "what changed"
and we answered a demand for five-minute freshness by re-reading everything.

A replacement source (jpauc.com) has been surveyed. It carries the auction stock,
a fixed-price section, motorbikes, and — unlike the old supplier — a public
archive of concluded auctions. It also states, on its own landing pages, how many
vehicles each sale day holds, which makes "has anything changed?" answerable
cheaply for the first time.

This feature replaces the inventory source. **Its first purpose is to be a source
we do not lose.** Freshness matters; not being cut off matters more, because a
portal with data an hour old still sells cars and a portal with no data does not.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The portal has stock again (Priority: P1)

A buyer opens the portal and sees Japanese auction vehicles that are genuinely on
sale — make, model, year, mileage, grade, start price, sale day, auction hall and
photographs — and can filter and open any of them.

**Why this priority**: The portal is presently showing data frozen at the day the
old supplier cut us off. Every other ambition is worthless until stock is real
again. One complete import satisfies this on its own.

**Independent Test**: Run the first full import, then open the portal as a
customer and confirm the vehicle count and a sample of ten lots match what the
source shows for the same sale days.

**Acceptance Scenarios**:

1. **Given** an empty or stale inventory, **When** the first full import finishes,
   **Then** the portal shows vehicles for every sale day the source lists, and the
   count per day is within 2% of the count the source publishes for that day.
2. **Given** an imported vehicle, **When** a buyer opens its page, **Then** make,
   model, year, mileage, engine size, transmission, colour, chassis code,
   inspection grade, start price, sale day, auction hall and at least one
   photograph are all shown.
3. **Given** the import is interrupted part-way, **When** it is started again,
   **Then** it resumes without duplicating vehicles already stored.

---

### User Story 2 - The stock stays current by itself (Priority: P2)

New listings, withdrawals and auction outcomes reach the portal on their own,
without anyone starting anything, and without the operator's machine being on.

**Why this priority**: A one-off import is stale within a day. This is what makes
the portal a product rather than a snapshot — but it depends on Story 1 existing
first.

**Independent Test**: Note the source's published count for a sale day, wait for
it to change, and confirm the portal's count follows within the stated window
without manual action.

**Acceptance Scenarios**:

1. **Given** the source's published count for a sale day increases, **When** the
   next change check runs, **Then** only that day is re-read, and the new vehicles
   appear in the portal within 15 minutes.
2. **Given** the source's published counts are unchanged, **When** the change
   check runs, **Then** no vehicle pages are read at all.
3. **Given** an auction takes place, **When** its outcomes are published,
   **Then** those lots show their result and hammer price in the portal within 30
   minutes, and bidding on them is refused.
4. **Given** the operator's computer is switched off, **When** a day passes,
   **Then** the portal is as current as it would have been with the computer on.

---

### User Story 3 - The sync cannot get us cut off again (Priority: P1)

The system stays inside a request budget the source tolerates, notices the moment
it is being refused, and stops itself rather than making things worse. Staff can
see what it is doing and switch it off without a developer.

**Why this priority**: Equal first with Story 1. Losing the source costs more than
any amount of staleness, and it is exactly what happened last time. Building this
after the sync is how the last one failed.

**Independent Test**: Set the daily budget deliberately low, let the sync exceed
it, and confirm it stops on its own, records why, and raises an alert — without a
developer intervening.

**Acceptance Scenarios**:

1. **Given** a configured daily request budget, **When** the sync reaches it,
   **Then** it stops for the rest of the day and records that it stopped and why.
2. **Given** the source answers with a refusal or rate-limit response, **When**
   that happens, **Then** the sync stops immediately, raises an alert, and does not
   retry until a person restarts it.
3. **Given** a network error or a slow answer, **When** the sync retries, **Then**
   each retry waits longer than the last, and it gives up after a fixed number of
   attempts rather than retrying indefinitely.
4. **Given** staff open the admin panel, **When** they look at sync status,
   **Then** they see requests used today against the budget, when it last ran,
   what it last read, and any error — and can stop it with one action.

---

### User Story 4 - Concluded auctions build an archive (Priority: P3)

Past auction results accumulate in the portal, so staff can tell a buyer what
comparable cars actually sold for.

**Why this priority**: Real commercial value — it is the thing the old supplier
refused us — but the portal sells cars without it, so it comes after current
stock is right.

**Independent Test**: Import one past sale day and confirm those vehicles carry a
hammer price and an outcome, and are visible to staff but not offered for bidding.

**Acceptance Scenarios**:

1. **Given** the archive import has run, **When** staff search past results,
   **Then** they see vehicles with outcome and hammer price for the days imported.
2. **Given** an archived vehicle, **When** a customer browses the portal, **Then**
   it does not appear among vehicles offered for sale.

---

### Edge Cases

- **The source changes its page layout.** Reading stops producing vehicles. The
  sync must treat "pages fetched but nothing recognised" as a failure worth an
  alert, not as "no new stock" — otherwise the portal silently freezes.
- **A sale day disappears** from the source's list. Its vehicles must not be
  deleted; they are retired from the customer view and kept for staff.
- **The same lot number appears at two auction halls on the same day.** Identity
  must be day + hall + lot number, not lot number alone, or two cars collapse into
  one.
- **A vehicle is withdrawn between reads.** It leaves the customer view without
  being destroyed, in case it returns.
- **The published count is unchanged but the contents changed** (one added, one
  withdrawn). The change check alone will miss this; a periodic full re-read must
  catch it, and the spec must not claim otherwise.
- **The first import cannot finish in one run** — it is tens of thousands of
  reads. It must survive being stopped and resumed across days.
- **Photographs fail to load** while vehicle data succeeds. The vehicle is still
  worth showing; a missing photograph is not a failed import.
- **Clocks disagree.** The source states times in Japan's clock; the server and
  the buyer are elsewhere. A sale "today" in Japan must not be treated as
  yesterday's, which has already cost us a whole day's listings once.
- **Two sync runs overlap** because one ran long. The second must not start, or
  the budget is spent twice as fast as intended.

## Requirements *(mandatory)*

### Functional Requirements

**Importing**

- **FR-001**: System MUST import vehicles from the new source for every sale day
  the source publishes, including make, model, year, mileage, engine size,
  transmission, colour, chassis code, model grade, inspection grade, start price,
  outcome, sale day, sale time, auction hall and lot number.
- **FR-002**: System MUST store photographs for each vehicle, and MUST record a
  vehicle successfully even when its photographs cannot be retrieved.
- **FR-003**: System MUST identify a vehicle by sale day, auction hall and lot
  number together, so that re-reading the same vehicle updates it rather than
  duplicating it.
- **FR-004**: System MUST be able to stop and resume the first full import without
  losing progress or duplicating vehicles.
- **FR-005**: System MUST keep the three kinds of stock — auction lots,
  fixed-price stock and concluded results — distinguishable, and MUST NOT offer
  bidding on anything that is not a live auction lot.

**Staying current**

- **FR-006**: System MUST detect whether a sale day has changed by reading the
  count the source publishes for that day, before reading any vehicle pages.
- **FR-007**: System MUST re-read only the sale days whose published count has
  changed since the previous check.
- **FR-008**: System MUST perform a full re-read of all live sale days on a
  regular cycle regardless of counts, to catch changes the count cannot reveal.
- **FR-009**: System MUST run without any operator machine being switched on.
- **FR-010**: System MUST retire from the customer view any vehicle the source has
  stopped listing, without deleting it.
- **FR-011**: System MUST interpret all source dates and times in Japan's clock,
  and MUST NOT let the server's own date decide whether a sale day is current.

**Not getting cut off** *(these are requirements, not optimisations)*

- **FR-012**: System MUST enforce a configurable maximum number of source requests
  per day, and MUST stop for the remainder of the day on reaching it.
- **FR-013**: System MUST enforce a configurable minimum delay between consecutive
  source requests.
- **FR-014**: System MUST make at most one source request at a time.
- **FR-015**: System MUST NOT allow two sync runs to overlap.
- **FR-016**: System MUST stop immediately and raise an alert on any refusal or
  rate-limit response from the source, and MUST NOT resume automatically.
- **FR-017**: System MUST wait progressively longer between retries after a
  failure, and MUST give up after a fixed number of attempts.
- **FR-018**: System MUST treat "requests succeeded but no vehicles were
  recognised" as a failure requiring an alert.
- **FR-019**: System MUST record every run: when, what was read, how many requests
  were used, how many vehicles changed, and any error.
- **FR-020**: System MUST identify itself in its requests consistently, and MUST
  NOT vary its identity or route requests through alternative addresses to avoid
  limits.

**Operating it**

- **FR-021**: Staff MUST be able to see, in the admin panel, the requests used
  today against the budget, when the sync last ran, what it last read, and any
  current error.
- **FR-022**: Staff MUST be able to stop and restart the sync from the admin panel
  without a developer.
- **FR-023**: System MUST allow the request budget, the delay between requests and
  the re-read cycle to be changed without altering code.
- **FR-024**: System MUST raise an alert when it has produced no new or changed
  vehicles for a configurable period, so a silent failure is noticed.

**Changing supplier again**

- **FR-025**: System MUST keep the choice of source in configuration, so that a
  future change of supplier does not require rewriting how the portal reads,
  stores or displays vehicles.

### Key Entities

- **Vehicle**: One car offered at one auction. Identified by sale day, auction
  hall and lot number. Carries description, condition, pricing, outcome and
  photographs. Belongs to exactly one of: live auction lot, fixed-price stock, or
  concluded result.
- **Sale day**: A date on which auctions run, with the count of vehicles the
  source publishes for it. The unit by which change is detected and work is
  scheduled.
- **Auction hall**: The house running a sale. Part of a vehicle's identity, and a
  filter buyers use.
- **Sync run**: One execution — when it started, what it read, requests used,
  vehicles changed, and its outcome.
- **Request budget**: The daily allowance of source requests, how much is spent,
  and whether spending is currently permitted.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Within one week of going live, the portal's vehicle count for each
  live sale day is within 2% of the count the source publishes for that day.
- **SC-002**: A new listing on the source appears in the portal within 15 minutes;
  an auction outcome appears within 30 minutes.
- **SC-003**: Ordinary daily operation uses no more than 5,000 source requests —
  under 4% of the volume that lost us the previous supplier.
- **SC-004**: The source remains reachable for 90 consecutive days without a
  refusal or block.
- **SC-005**: On any day when nothing on the source changes, fewer than 500
  requests are made.
- **SC-006**: The portal serves current stock for 30 consecutive days with the
  operator's computer switched off throughout.
- **SC-007**: A member of staff, with no developer present, can determine from the
  admin panel whether the sync is healthy, and stop it, in under two minutes.
- **SC-008**: Every vehicle shown to a buyer carries make, model, year, mileage,
  grade, start price and sale day; at least 95% also carry a photograph.
- **SC-009**: Bidding is never accepted on a vehicle whose auction has concluded —
  zero occurrences.
- **SC-010**: A deliberately induced block is detected, the sync stopped, and an
  alert raised, within one sync cycle.

## Assumptions

Recorded from a survey of the new source on 2026-08-10. Each is a fact the
implementation depends on and should re-confirm before building on it.

1. **The source publishes per-day counts on its section pages**, which is what
   makes cheap change detection possible. If this stops, FR-006 and FR-007 fail
   and the sync falls back to the periodic full re-read (FR-008).
2. **Vehicle data is readable without an account**, though the source returns only
   ten vehicles per page to a guest. An account is expected to raise this and
   would cut the first import several-fold; the design must work either way, and
   must not assume an account exists.
3. **Requesting a sale day alone returns nothing**; the day must be accompanied by
   a set of lot numbers. Lot numbers observed span roughly 1 to 90,000 and are
   requested in batches, because request length is limited.
4. **Photograph addresses are predictable** from sale day, auction hall and
   position, and are retrievable without an account.
5. **A concluded-auction archive is public** on the new source — the data the
   previous supplier withheld.
6. **The source is plain web pages, not a data service.** There is no way to ask
   "what changed since", and no notification when something does. Polling is the
   only mechanism available, which is why the budget requirements exist.
7. **Vehicle data is read from page markup**, so a redesign at the source will
   break reading. FR-018 exists because of this.
8. **The default request budget is 5,000 per day**, the default delay between
   requests one second, and the default full re-read cycle weekly. All are
   configurable (FR-023) and should be tightened, not loosened, if the source
   shows strain.
9. **The first full import is tens of thousands of requests** and is expected to
   run over several days within the daily budget. This is deliberate: finishing
   sooner is worth less than not being blocked.
10. **Existing stored vehicles from the previous supplier are kept** and marked as
    coming from that source, rather than deleted, so nothing already sold or
    quoted disappears from the record.
11. **Obtaining an account or a data agreement with the source is a business
    action**, outside this feature. The feature must work without one and benefit
    from one.

## Out of Scope

- Negotiating an account, licence or data agreement with the source.
- Motorbike stock. The source carries it; the portal does not sell it today.
- Changing how vehicles are presented to buyers. The two screens redesigned
  against the client's reference are settled and unaffected.
- Currency conversion and landed-cost quotation beyond what the portal already does.
- Backfilling the concluded-auction archive beyond the days the source publishes;
  historical depth is a later question.
- Migrating vehicles already stored from the previous supplier into the new
  source's identity scheme.

## Dependencies

- The new source remains publicly reachable from the portal's server. This is
  worth confirming before any build work: the previous supplier refused that same
  address, and both sites are operated by the same company.
- The portal's existing storage, admin panel and customer screens, which this
  feature fills rather than replaces.
- A working alert channel — email or equivalent — for FR-016, FR-018 and FR-024.
  Without one, every stopping requirement degrades to a log nobody reads.
