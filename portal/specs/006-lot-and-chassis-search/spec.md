# Feature Specification: Searching by Lot Number and Chassis

**Feature Branch**: `006-lot-and-chassis-search`
**Created**: 2026-09-02
**Status**: Implemented — three named boxes, one of which does the most the feed allows
**Input**: Owner: "On the Action main page, there is a search bar at the top, and there is also one in the detail view. In both places, when we search using a chassis number, the corresponding car should appear. Since every car has a unique chassis number, searching any chassis number should return only that one specific car."

## Context

Two boxes search the same way: the one above the auction listing, and "Chassis
model :" on a lot's own page. Both submit `lot` to `index.php`, so there is one
filter to get right and it serves both.

The request was for a chassis **number** to return one vehicle. That cannot be
done, and the reason is not in our code — it is that jpauc does not publish
chassis numbers. What can be done, and now is, is that a chassis number no
longer returns nothing.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A lot number finds its lot (Priority: P1)

**Acceptance Scenarios**:

1. **Given** a lot number, **When** it is searched, **Then** the lots carrying
   that exact number are shown — lot 44 is not lot 444.
2. **Given** several lot numbers separated by commas, **When** they are
   searched, **Then** all of them are shown. The box says so and now does it.

### User Story 2 - A chassis code finds the vehicles built on it (Priority: P1)

**Acceptance Scenarios**:

1. **Given** a chassis code such as `NHP10`, **When** it is searched, **Then**
   every vehicle carrying it is shown.
2. **Given** the same code in lower case, **When** it is searched, **Then** the
   result is the same.
3. **Given** a lot's own page, **When** "Find others like this" is used, **Then**
   the listing opens filtered to that lot's chassis code.

### User Story 3 - A whole chassis number lands on the right model (Priority: P2)

**Why this priority**: It cannot do what was asked — a chassis number cannot
single out one vehicle here — but returning nothing at all reads as a broken
search, and that is what it did.

**Acceptance Scenarios**:

1. **Given** a whole chassis number such as `NHP10-2054321`,
   **When** it is searched,
   **Then** the vehicles built on `NHP10` are shown, not an empty list.
2. **Given** the same number written without its dash, `NHP102054321`,
   **When** it is searched,
   **Then** the result is the same.

## Requirements *(mandatory)*

- **FR-000**: The listing MUST offer three named boxes — **Search By Lot
  Number**, **Search By Chassis Model**, **Search By Chassis Number** — each
  labelled for what it does. One box accepting all three, labelled after one of
  them, is how the chassis searches came to be reported as missing: they worked
  and nothing said so.
- **FR-00A**: The lot page MUST offer the chassis-model and chassis-number boxes
  too, both submitting to the listing.
- **FR-00B**: The page MUST say, where the boxes are, that a chassis number
  finds the model rather than one vehicle, and that the hall narrows it. A limit
  of the feed stated in advance is information; the same limit discovered by
  getting nothing back is a fault.
- **FR-001**: One filter MUST serve both search boxes.
- **FR-002**: A term MUST be tried as a lot number (exact) and as a chassis code
  (loose), because a buyer holding either types into the same box.
- **FR-003**: Terms MUST be comma-separable, up to twenty-five.
- **FR-004**: A term of eight characters or more MUST **also** be matched the
  other way round — a vehicle's chassis code found *inside* the typed term — so
  that a whole chassis number lands on its model whether or not it carries a
  dash. Codes shorter than three characters are excluded from this test.
- **FR-005**: Matching MUST be case-insensitive.

## Why a chassis number cannot return one vehicle

Established on 2026-09-02 by reading the source itself, not by inference:

- **The feed carries the model code, not the number.** Across 44,429 live
  vehicles the `chassis` column holds 4,736 distinct values — `NHP10` alone is on
  722 vehicles, `JF1` on 426. The longest value is 17 characters, and the few
  containing a dash are machinery model codes (`PC10UU-3`, `ZX30U-2`), not
  chassis numbers.
- **jpauc's own listing calls it "Model Code"** and shows `NHP10`.
- **jpauc's own detail page carries `data-cn` — chassis number — and it is
  empty.** The "Chassis Number" box beside it is a lookup the buyer types into:
  it posts to `/vin/get_data_chassis_no` against a third-party database to return
  a production month, and the page says in as many words that the result is "for
  customer Reference Only".

So the number is not withheld from us by a paid plan or a missing field; the
source does not hold it. Nothing in the portal can search by something the feed
never sent.

## Success Criteria *(mandatory)*

- **SC-001**: A chassis code finds its vehicles. *Measured live: `NHP10` → 739,
  `nhp10` → 739, `LA600S` → 321, `GVF55` → 11.*
- **SC-002**: A lot number finds its lots. *Measured: `65054` → 2.*
- **SC-003**: Comma-separated lots work. *Measured: `4189, 65054` → 4.*
- **SC-004**: A whole chassis number no longer returns nothing. *Measured before
  the change: `NHP10-2054321` → 0. After: 723, and `NHP102054321` → 723,
  `LA600S-0512345` → 320.*
- **SC-005**: Nothing else changed. *Measured: every search above returns what it
  returned before the change.*
- **SC-006**: The three boxes exist and each works on its own. *Measured live
  2026-09-02 after the split: lot `65054` → 2 and `4189, 65054` → 5;
  chassis model `NHP10` → 644, `nhp10` → 644, `LA600S` → 353, `KF2P` → 134;
  chassis number `NHP10-2054321` → 631, `NHP102054321` → 631,
  `KF2P-1234567` → 134, `KF2P1234567` → 134.*
- **SC-007**: The boxes combine with the facets. *Measured: lot `65054` + hall
  BAYAUC → 1 vehicle; chassis number `KF2P-1234567` + Chubu + 03-Sep → 6.*
- **SC-008**: The lot page carries both chassis boxes. *Verified: "Chassis
  model :" with "Find others like this", and "Chassis number :" with "Find this
  model".*

## Out of Scope

- Narrowing to a single vehicle by chassis number. Not possible with this feed.
- The "Find Month of Production" control, which sits behind the feed's paid plan
  and is shown as unavailable rather than as a button that does nothing.

## Where it lives

`buildCarFilter()` in `includes/functions.php`, the `lot` filter. Both boxes —
`index.php` (top of the listing) and `car-details.php` (`form.pb-chassis`) —
submit `lot` to `index.php`, so there is one place to change.
