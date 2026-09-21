# Feature Specification: Past Auction Prices (Statistics)

**Feature branch**: `008-auction-statistics`
**Created**: 2026-09-08
**Status**: Specified
**Source**: `bid.aaajapan.com/st` — "SALES STATISTICS"

## Context

The portal shows what is for sale. It cannot show what things actually **sold
for**, because jpauc.com — the source the auction list is built from — does not
publish past results at all. A buyer deciding what to bid has nothing to measure
against.

The owner supplied a second site, `bid.aaajapan.com`, which does. Its statistics
section carries **1,212,520 past auction records** up to 05.09.2026, and the
account he gave has access to them (`is_user: 1` in the source's own answer —
unlike the nikkyocars account, which was entitled to eighteen rows).

This feature is only about past results. It does **not** change the auction list,
and it must not: the two sites are about nineteen-twenty — near enough the same
stock — but the auction list is jpauc's and stays jpauc's. Mixing a second
source into it is how a portal ends up holding vehicles nobody can buy.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A buyer checks what a model actually sells for (Priority: P1)

A customer is looking at a 2015 Toyota Vellfire and wants to know what similar
cars have gone for.

**Acceptance Scenarios**:

1. **Given** a signed-in customer on **Statistics**,
   **When** they choose a maker and type a model,
   **Then** they see past auctions of that model: date, hall, lot, year, engine,
   mileage, grade, colour, **start price and final price**, and whether it sold.
2. **Given** a result list,
   **When** the customer narrows by year, engine size, grade or auction house,
   **Then** the list narrows and the count updates.
3. **Given** a lot that did not sell,
   **When** it is shown,
   **Then** it is marked plainly as **not sold**, and its final price is not
   presented as a sale price.

### User Story 2 - The same figures reach the vehicle a buyer is looking at (Priority: P2)

**Acceptance Scenarios**:

1. **Given** a vehicle on the auction detail page,
   **When** past results exist for its maker and model,
   **Then** a short "what this model has sold for" panel appears with the last
   few results and an average, linking through to the full statistics search.

### User Story 3 - Statistics stay current without anyone doing anything (Priority: P2)

**Acceptance Scenarios**:

1. **Given** the source publishes a new sale day,
   **When** the harvester next runs,
   **Then** that day's results are added, and nothing already stored is lost.

### Edge Cases

- **The source needs a maker.** A statistics query with no maker returns nothing
  at all — not an error, an empty body. The harvester always names one.
- **Twenty rows a page, and only twenty.** `list_size=50` is accepted and
  ignored. 1,212,520 records is therefore 60,626 requests; there is no shortcut.
- **A record has no stable public id.** Its key (`a:`) is a per-session token and
  changes. Identity is derived instead: hall + sale date + lot number.
- **Prices are yen.** The source can render other currencies; the stored figure
  is always yen, converted at display time if at all.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Statistics are stored in their own table, `car_stats`. They are
  never written into `cars`, and nothing in the auction list reads from them.
- **FR-002**: The harvester signs in to `bid.aaajapan.com` and walks the
  statistics listing **one maker at a time**, page by page.
- **FR-003**: Identity is `sha1(hall|date|lot)`. Re-reading a record updates it;
  it never duplicates.
- **FR-004**: A record stores: maker, model, lot, hall, sale date, sale time,
  year, engine cc, mileage, chassis, grade, model grade, transmission, rating,
  colour, start price, final price, result, and up to three photograph keys.
- **FR-005**: The customer page requires a signed-in account, like the rest of
  the portal.
- **FR-006**: Search is by maker (required), and optionally model, chassis,
  year range, engine size, grade, auction house, and result.
- **FR-007**: The harvester obeys the owner's rate rule — one request a second,
  no more — and stops on a refusal from the source exactly as the jpauc
  harvester does.
- **FR-008**: A run is bounded by the clock and resumes where it stopped, so
  nothing depends on a machine staying on.

### Key Entities

- **`car_stats`** — one row per past auction result.
- **The statistics loader** — `POST bid.aaajapan.com/st?file=loader`, the whole
  `poisk` form, with `vendor` set to a maker id and `model` **empty**. "Any" is
  not empty and returns nothing; that distinction cost two hours.
- **The maker list** — 71 of them, from `manuf_str` on the page:
  `0:Any;1:TOYOTA;2:NISSAN;3:MAZDA;4:MITSUBISHI;5:HONDA;…`

## Success Criteria *(mandatory)*

- **SC-001**: A customer can find past results for any maker in the list.
- **SC-002**: Every row shown carries a final price and a plain sold / not-sold
  answer.
- **SC-003**: The stored count grows toward the source's own figure and never
  exceeds it.
- **SC-004**: The harvester's request rate stays at or below one a second,
  measured, and it never runs while the jpauc harvester is mid-run if that would
  put the two of them over that between them.

## What the source actually returns

One row, as the loader sends it:

| key | meaning | example |
|---|---|---|
| `a` | session token (not an id) | `qmg5hs0hCvB0b5M` |
| `b` | maker and model | `TOYOTA 86` |
| `c` | lot number | `70018` |
| `d` | auction house | `TAA Shikoku` |
| `e` | sale date | `09.06.2026` |
| `f` | sale time | `[08:04]` |
| `g` | year | `2025` |
| `h` | engine cc | `2400` |
| `j` | chassis | `ZN8` |
| `k` | grade code | `F6` |
| `l` | model grade | `RZ` |
| `m` | transmission | `AAC` |
| `r` | auction rating | `5` |
| `s` | start price (yen) | `2200000` |
| `t` | **final price (yen)** | `2911000` |
| `v` | **result** | `not sold` |
| `w` | colour | `white` |
| `x` `y` `z` | photograph keys | … |

`navi.rows` carries the total for the query — 379,719 for TOYOTA, 148,168 for
NISSAN — and `navi.is_user` is `1` when the account may see them.

## Assumptions

- The owner's account keeps its statistics entitlement. If `is_user` comes back
  `0`, the harvester stops and says so rather than storing nothing quietly.
- Photographs are fetched lazily, if at all. 1.2 million records at three images
  each is not something to pull ahead of being asked for.

## Out of Scope

- Adding aaajapan vehicles to the **auction list**. Different feature, and the
  owner deferred it.
- Currency conversion beyond display.
- Statistics for fixed-price stock.

## Rate and cost

| | |
|---|---|
| Records at the source | 1,212,520 |
| Rows per request | 20 (fixed) |
| Requests for a complete first pull | ~60,626 |
| At one a second | ~17 hours |
| After that | only new sale days — a few hundred requests a day |

The first pull is long and that is unavoidable. It is also resumable, so it can
run across days without anyone watching it.
