# Feature Specification: A Second Auction Feed (aaajapan beside Pacific Boeki)

**Feature branch**: `009-second-auction-feed`
**Created**: 2026-09-29
**Status**: Specified
**Source**: `bid.aaajapan.com/aj_neo` — "JAPANESE AUCTIONS" (the site the Statistics already come from)
**Asked by**: the client, through the owner, 29 September 2026

## Context

The auction list comes from Pacific Boeki (PB) since 11 September 2026 (jpauc paused).
The client wants the auction lots of `bid.aaajapan.com` in the SAME auction as well:
"PB se bhi aur saath aaajapan" - one list, one detail page, one admin, one dashboard.

The owner's rules, word for word in intent:
- A lot PB already has does NOT come again from aaajapan - no car twice.
- A lot PB does not have comes in from aaajapan - all of them.
- PB is not paused and loses nothing because of this (unlike jpauc, whose unmatched
  lots were removed only because jpauc had been stopped).
- Everything shows and works exactly as it does for PB - main page, detail page, admin,
  client side, dashboard, database. **One thing differs everywhere: the count is
  shown split, so it is clear how many are PB's and how many aaajapan's.**
- The same aaajapan fetcher (GitHub) as the Statistics, the same account, the same
  safe limits - nothing above what runs now.

Measured on 29 Sep 2026 (one look, one sign-in): aaajapan lists ~51,900 lots across
~140 halls, grouped by weekday with each hall's count; that count includes lots of
past days that already have a result. Tuesday's 26 halls matched PB's to the lot.
USS halls are not listed there now (PB has them). Halls PB lacks at that moment:
Aux Mobility ~698, LAA Okayama ~582, NAA Nagoya ~252, AUCNET 163, JU Tochigi ~288,
MOTA Kuruma Kaitori Plus 142, Honda AA ~58, NISSAN Osaka 9; several later days held
more lots there than on PB (PB lists them later).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A buyer sees every lot on sale, once (P1)
**Given** a lot on aaajapan that PB does not list, **when** a customer opens the auction,
**then** it is in the list with the same filters, sorting, detail page and bid box as
any other lot. **Given** a lot both list, **then** it appears once.

### User Story 2 - The count shows where lots come from (P1)
Wherever the auction count is shown - welcome page, main list, client dashboard, admin
dashboard, admin cars list, the live counters - it reads **total = A + B**, with A the
first feed (PB) and B the second (aaajapan). No source is named on any page, hover or
JSON (the client's standing rule); A and B are neutral labels.

### User Story 3 - Sold and finished lots leave as they do today (P1)
A lot with a result (sold, unsold, negotiated, withdrawn) leaves the customer list and
stays on record for staff; a finished sale day leaves the database at the day's end,
except cars a customer bid on, ordered or asked about - exactly PB's behaviour.

### User Story 4 - PB takes over a lot it lists later (P2)
**Given** an aaajapan lot in the portal, **when** PB lists the same lot (same sale day,
lot number, make and model), **then** PB's row takes over: one car, PB's pictures,
the customer's bids kept.

## Requirements *(mandatory)*

- **FR-001** Only lots with a sale day of today (Japan) or later and no result are offered.
- **FR-002** A lot is the same car when sale day + lot number + make + model agree
  (the rule PB's harvester already uses to adopt rows it did not write); the hall name
  is matched through a learnt map (aaajapan's name -> PB's) so the hall filter merges them.
- **FR-003** aaajapan rows carry `car_id` `aj-<day>-<hall>-<lot>`, `source_section`
  `japan`, their three pictures (two photographs + the inspection sheet) on the
  source's image host, and their origin in `source_url` (never shown).
- **FR-004** A whole read of an aaajapan hall retires that hall's `aj-` rows it did not
  see; PB's harvester never retires, sweeps or counts `aj-` rows as its own.
- **FR-005** The count split (A/B) appears in all six places counts are shown today.
- **FR-006** Requests to aaajapan: one survey request per check (the search page lists
  every hall and count); a hall is read only when its count moved, when it was never
  read, or - on its sale day - to pick up results; pages at the Statistics' pace (1.5 s)
  under a daily sub-allowance inside today's 16,000; stop on the first refusal.
- **FR-007** Nothing about the Statistics changes.

## Known limits (the source's, not choices)
- An aaajapan lot has 3 pictures (PB's have 12 derived ones). Same page, fewer pictures.
- aaajapan's image host refuses servers (as for Statistics): the PDF and ZIP of an
  aaajapan lot carry no photographs; the screen shows them (the browser fetches them).
- A result reaches an aaajapan lot at the next read of its hall (~30-60 min on a sale
  day), a little later than PB's 10-15 min.

## Success Criteria
- **SC-001** 0 duplicates: no (sale day, lot, make, model) held twice in the offered list.
- **SC-002** For every hall read whole: aaajapan's for-sale lots = PB's + ours `aj-`, to the lot.
- **SC-003** PB's daily check stays exact: portal A + PB's result lots = PB total, +0.
- **SC-004** aaajapan requests/day stay within the agreed sub-allowance; 0 refusals.
- **SC-005** Statistics tests unchanged and green.
