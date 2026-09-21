# Feature Specification: Vehicle Photographs

**Feature Branch**: `003-vehicle-photographs`
**Created**: 2026-09-02
**Status**: Implemented and verified live
**Input**: User description: "action or detail dono mayy bhi images nahii a rahiii" … "action main mayy 3 images ayaii gi jasayy phlayy thaa or us par click sayy zoom … barii hogii sab ka width height sab same … detail maiii one big image us par click sayy usii same width height big open hogii baaqi sab neechayy alag alag pose mayy"

## Context

Every vehicle photograph on the portal comes from one image host,
`p3.aleado.com`, at an address the harvester never invents: the listing hands
back a single address per vehicle and it is stored as-is. Every other
photograph — the second, the third, the inspection sheet — is that same address
with a different `number=`. Nothing is probed, so a gallery costs no requests;
the buyer's own browser fetches what exists and the page removes what does not.

That last step is where three separate "the images have gone" reports came from.
The page has to decide, after a picture arrives, whether it is a photograph or
the host's stand-in, and for a long time it decided by width. That was correct
while a real photograph came back 853x640 and the stand-in 128x96. It stopped
being correct, silently, and the portal began throwing away photographs it had
already loaded.

This specification writes down what the pictures actually are, so the decision
is never made on a guess again.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A buyer scanning the auction list sees the cars (Priority: P1)

A buyer opens the auction listing. Each row leads with photographs, so a saloon
can be told from a van without opening anything.

**Why this priority**: A listing row without its picture is a row nobody clicks.
This is the portal's front page and the first thing the client judges.

**Acceptance Scenarios**:

1. **Given** a vehicle whose source holds two or more photographs,
   **When** its row is drawn,
   **Then** the row shows three tiles — the first photograph, the second, and
   the inspection sheet — each at the same width and height.
2. **Given** a vehicle whose source holds one photograph,
   **When** its row is drawn,
   **Then** the row shows that one photograph and no empty frames beside it.
3. **Given** any row that has at least one photograph,
   **When** the page has finished loading,
   **Then** the row never shows the "—" placeholder.

### User Story 2 - Opening a photograph shows it larger (Priority: P1)

A buyer clicks a picture, anywhere on the portal, and it opens large.

**Why this priority**: The one thing a person clicking a photograph is asking
for is to see it bigger. Answering with the same size, or smaller, reads as a
broken feature — and it was reported as one.

**Acceptance Scenarios**:

1. **Given** a photograph shown as a thumbnail,
   **When** the buyer clicks it,
   **Then** the overlay opens the **original file** — the address with no height
   asked for — not the scaled copy the page was already showing.
2. **Given** two photographs of different pixel sizes,
   **When** each is opened,
   **Then** both fill the **same frame**, and each keeps its own shape inside it.
3. **Given** a vehicle whose only photograph is the small post-sale preview,
   **When** it is opened,
   **Then** it is enlarged to that same frame rather than left at its own size.

### User Story 3 - The detail page shows every pose (Priority: P1)

A buyer opens a lot and sees one large photograph, a strip of every other pose
beneath it, and the auction sheet.

**Acceptance Scenarios**:

1. **Given** a vehicle whose source holds eleven photographs,
   **When** the detail page is opened,
   **Then** the strip beneath the large photograph offers every pose the source
   holds, up to twelve.
2. **Given** the buyer chooses a pose from the strip,
   **When** they then click the large photograph,
   **Then** the overlay opens **that pose** at full size — not the first one, and
   not the scaled copy in the frame.
3. **Given** a vehicle with no photographs at all,
   **When** the detail page is opened,
   **Then** it says "No photo available" rather than leaving an empty frame.

### User Story 5 - The list does not change underneath the buyer (Priority: P1)

A buyer opens a lot, reads it, presses back, and finds the same vehicles they
left — with the same photographs.

**Why this priority**: This was reported as a picture fault — *"detail mayy jata
huu phir back athaa huu phir kharab hojatii hai images"* — and it was not one.
The listing was ordered by whichever rows the harvester had written most
recently, so pressing back re-ran the query and returned a **different set of
vehicles**. The pictures were never lost; the cars under them were replaced.

It also decided *which* cars led the portal, and it chose the worst ones. The
harvester spends much of its time re-checking the day's concluded sales, so
those were the rows it had touched last — and those are exactly the vehicles
whose photographs the source has withdrawn. Measured before the change: 20 of 20
rows on page one were already sold, 38 image requests answered 404, and every
row carried a single small preview.

**Acceptance Scenarios**:

1. **Given** a buyer on the listing,
   **When** they open a lot and press back,
   **Then** the same vehicles are shown, in the same order, with the same
   photographs.
2. **Given** the listing with no sort chosen,
   **When** it is drawn,
   **Then** vehicles still to be auctioned come first, soonest sale first.
3. **Given** the listing with no sort chosen,
   **When** it is drawn,
   **Then** vehicles whose sale has concluded come after them, not before.

### User Story 4 - Missing pictures leave no trace (Priority: P2)

Where the source has no picture, the page shows nothing at all — not a torn
corner, not a grey rectangle.

**Acceptance Scenarios**:

1. **Given** an address the host answers with its 128x96 stand-in tile,
   **When** the picture arrives,
   **Then** the page removes that tile.
2. **Given** an address the host answers 404,
   **When** the browser gives up on it,
   **Then** the page removes that slot.
3. **Given** a strip in which every slot turned out to be missing,
   **When** the last one is removed,
   **Then** the strip closes rather than leaving a gap in the layout.

### Edge Cases

- **A photograph smaller than the stand-in tile.** Real photographs are not
  always large. Auction halls publish a 100x75 preview before — and after — the
  full set exists, and the stand-in tile is 128x96. A photograph is therefore
  routinely *smaller* than the thing that means "no photograph".
- **A sale day that has already run.** The source withdraws the full set once a
  sale concludes and keeps only the 100x75 preview. jpauc.com shows the same
  thing. This is not a fault and there is nothing to recover.
- **A catalogue published before its photographs.** The same 100x75 preview
  appears at the other end of a lot's life. A hall lists its vehicles as soon as
  the sale is entered and uploads the photographs later — sometimes hours later.
  Measured on 2026-09-02: USS Tokyo's 3 September lots answered `number=1` with
  the 100x75 preview and every other number with the tile, while Chubu's lots
  for the same day already carried a 800x800 sheet and two 1024x768
  photographs. Nothing needs doing; the addresses are already stored, so the
  photographs appear on the portal the moment the hall uploads them.
  **A row showing one small picture is therefore normal, and means one of two
  things: the sale has run, or the hall has not photographed the car yet.**
  Neither is a fault in the portal, and both were reported as one.
- **`loading="lazy"` before it starts.** A lazy image reports
  `complete === true` with `naturalWidth === 0` *before* it has begun. That is
  indistinguishable from failure unless `currentSrc` is also checked.
- **The overlay's own picture.** The overlay reuses one `<img>` for every zoom.
  Hiding it once hides it for every later zoom, which reads as the site freezing.
- **A vehicle that is not a JPAuc lot.** Its stored address is not an
  `aleado.com` address; no set can be derived and the stored addresses are used
  as they are.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST derive every photograph address from the single
  address stored with the vehicle, by substituting `number=`. It MUST NOT fetch
  an address to find out whether it exists.
- **FR-002**: The inspection sheet MUST be `number=0`. The photographs MUST start
  at `number=1`.
- **FR-003**: The auction listing MUST offer, per row, the first two photographs
  and the inspection sheet, at one fixed width and height for all three.
- **FR-004**: The detail page MUST offer up to twelve poses plus the inspection
  sheet, and MUST show one of them large.
- **FR-005**: A picture MUST be treated as missing **only** when it measures
  **exactly 128 x 96**, or when the browser reports it failed. Width alone MUST
  NOT be used, at any threshold.
- **FR-006**: A picture that has not started loading MUST NOT be treated as
  failed. Failure requires a non-empty `currentSrc`.
- **FR-007**: The overlay's own `<img>` MUST never be hidden by the
  missing-picture rule; the overlay MUST report its own failures in words.
- **FR-008**: Clicking any photograph MUST open the **original** file — the
  address with no `h=` — regardless of which scaled copy was on screen.
- **FR-009**: The overlay MUST present every photograph in the **same frame**,
  fitting each inside it without distortion, enlarging one that is smaller.
- **FR-010**: Choosing a pose on the detail page MUST update both what the frame
  shows and what the frame opens to.
- **FR-011**: A strip or panel left with no surviving picture MUST close.
- **FR-012**: A listing row with no surviving picture MUST show the "—"
  placeholder, and only then.
- **FR-013**: A vehicle whose stored address is not an `aleado.com` address MUST
  fall back to its stored addresses unchanged.
- **FR-014**: The listing's default order MUST be a **stable** one, derived from
  the sale moment: vehicles still to be auctioned first, soonest first, then the
  concluded ones. It MUST NOT be ordered by when the harvester last wrote a row.
  Two consequences depend on this and both were reported as picture faults —
  the list must not change when a buyer presses back, and the front page must
  not fill with concluded sales, which are the vehicles whose photographs the
  source has withdrawn.

### Key Entities

- **Photograph address** — `https://p3.aleado.com/pic/?system=auto&date=<sale
  day>&auct=<hall code>&bid=<lot>&number=<n>[&h=<height>]`. `date`, `auct` and
  `bid` are read out of the stored address; `number` is substituted; `h` asks for
  a scaled copy and is dropped to get the original.
- **The stand-in tile** — one fixed file the host returns in place of a picture
  that does not exist. **Exactly 128 x 96.** Served with HTTP 404, but also
  reachable with 200, so the status code alone is not the test.
- **The post-sale preview** — 100 x 75. All that remains once a sale day has run.
- **Full-size photograph** — 640x480, 853x640, 960x720 or 1024x768 depending on
  the hall. `h=320` yields 426x320; dropping `h` yields the original.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On the auction listing, every row that has at least one photograph
  shows it. *Measured 2026-09-02: 20 of 20 rows, twice (default view and a
  hall-filtered view); 0 rows fell back to "—".*
- **SC-002**: No photograph is hidden by the page after it has loaded.
  *Measured: 0 of 126 loaded pictures across the listing and detail pages.*
- **SC-003**: No stand-in tile is left visible. *Measured: 0.*
- **SC-004**: A detail page offers every pose the source holds. *Measured: 12
  poses plus a 640x640 sheet on a lot the source holds 11 photographs for; 23
  pictures on the page, all real.*
- **SC-005**: Zooming opens the original. *Measured: frame showed 853x640, the
  overlay opened 1024x768.*
- **SC-006**: Every zoom opens at one frame size. *Measured: 1180 x 549 for both
  a 1024x768 photograph and a 100x75 preview.*
- **SC-007**: Every live auction vehicle in the database carries a usable photo
  address. *Measured: 33,050 of 33,050; 0 empty, 0 unparseable.*
- **SC-008**: A sample drawn across the halls returns real photographs.
  *Measured: 40 vehicles across 20 auction halls — 40 real, 0 tiles, 0 failures.*
- **SC-009**: The vehicle count agrees on every surface. *Measured
  simultaneously: client listing 33,050, admin Vehicles (Auction + Available)
  33,050, admin Overview 33,050, database 33,050.*
- **SC-010**: Opening a lot and pressing back returns the same vehicles with the
  same photographs. *Measured over three round trips: the same twenty lot
  numbers each time, 20/20 rows showing three pictures, 60 of 60 pictures real
  and visible, 0 hidden, 0 placeholders.*
- **SC-011**: The front page leads with vehicles a buyer can still bid on.
  *Measured: 0 of 20 rows Closed, where before the change it was 20 of 20; and
  the first 30 rows of the live order are all still to be sold and all thirty
  carry the full three pictures.*

## Assumptions

- The stand-in tile stays 128x96. If the host changes it, FR-005 is the single
  place to correct — and the symptom will be tiles appearing, never photographs
  disappearing, which is the safer way round.
- The source withdraws full photographs after a sale day. Confirmed by opening
  jpauc.com's own gallery for a concluded lot: it shows the same 100x75 preview.
- Twelve slots is enough. The largest set observed is eleven.

## Out of Scope

- Recovering photographs for concluded sale days. The source no longer has them.
- Copying photographs onto our own server. The host is fetched by the buyer's
  browser; we store addresses, not files.
- Improving the quality of a 100x75 preview. Enlarging it is all that can be done.

## Dependencies

- `p3.aleado.com` — the image host, reached directly by the buyer's browser.
- `jpauc.com/auction/detail/<data-id>` — the source's own gallery, the way to
  check what the source really holds for a lot. `data-id` sits on the listing
  row (`<tr id="item-N" data-id="N">`) and the portal does not store it.

## Verification Record — 2026-09-02

Carried out against the live site with a real browser session and by fetching
each address directly.

Signed off by the owner after this sweep. **This behaviour is settled: do not
change the photograph rules or the listing's default order again without a
reported fault and a fresh measurement.** Every number below was read off the
live site, not inferred.

| Surface | Result |
|---|---|
| Client auction listing, page 1 | 20/20 rows x 3 pictures; **60 of 60 real and visible**; 0 tiles shown; 0 hidden; 0 placeholders; 0 concluded sales |
| Client auction listing, page 2 | 20/20 rows x 3; 60 of 60 real and visible; 0 placeholders |
| Client auction listing, hall filter | 20/20 rows x 3; 64 real; 0 tiles shown; 0 real hidden |
| Back from a lot, three round trips | the same 20 lots each time; 20/20 rows x 3; 60 of 60 real; 0 hidden |
| Client auction detail (2-photo lot) | 12 pictures, all real; sheet 640x640; zoom opened the original 1024x768 |
| Client auction detail (12-pose lot) | 22 pictures, all real; 12 poses; sheet 640x640; **8th pose chosen → zoom opened number=8** at 1024x768, frame 1180x549 |
| Admin → Vehicles (Auction, Available) | 33,050; 30/30 rows carry a picture, all real |
| Admin → Overview | AUCTION 33,050 |
| Database | 33,050 live; 33,050 with an address; 0 empty; 0 unparseable |
| Database sample | 40 vehicles, 20 halls → 40 real, 0 tiles, 0 failures |
| Database, first 30 in the live order | 30/30 still to be sold; 30/30 will show three pictures; 0 with none |
| Every page after the change | 20 client and admin screens, HTTP 200, 0 PHP or SQL errors |
| Diagnostic endpoints | all `ztmp-*.php` removed from the live site, confirmed 404 |

### Known issues, open

- **Intermittent HTTP 500.** `car-details.php` returned 500 once during the
  sweep and 200 on every retry (12 further requests). Suspected contention with
  the harvester's writes; not reproduced, not diagnosed.
- **No CSRF protection anywhere in the project.** No admin form carries a token.
  Out of scope here; recorded so it is not lost.
