---
id: 8
title: The list was changing, not the pictures
stage: green
date: 2026-09-02
surface: agent
model: claude-opus-5
feature: 003-vehicle-photographs
branch: 003-vehicle-photographs
user: Muhammad Yaseen
command: (direct instruction)
labels: [images, listing-order, stability, live-verified, root-cause]
links:
  spec: specs/003-vehicle-photographs/spec.md
  ticket: null
  adr: null
  pr: null
files:
 - includes/functions.php (carSortOptions: default order is now the sale moment, not last_updated)
 - specs/003-vehicle-photographs/spec.md (User Story 5, FR-014, SC-010, SC-011, verification record)
 - specs/003-vehicle-photographs/plan.md (the fourth defect; "settled - do not reopen")
tests:
 - Live: listing page 1 - 20/20 rows x 3 pictures, 60 of 60 real and visible, 0 tiles shown, 0 hidden, 0 placeholders, 0 concluded sales
 - Live: listing page 2 - 20/20 rows x 3, 60 of 60 real
 - Live: three back round trips - the same 20 lot numbers each time, 20/20 rows x 3, 60 of 60 real
 - Live: detail with 12 poses - 22 pictures all real, 8th pose chosen, zoom opened number=8 at 1024x768, frame 1180x549
 - Live: detail with 2 poses - 12 pictures all real, zoom opened the original 1024x768
 - Live: admin Vehicles (Auction, Available) 33,050 - 30/30 rows carry a real picture
 - Live: admin Overview AUCTION 33,050; client listing 33,050; database 33,050
 - Live database: 33,050 live, 33,050 with an address, 0 empty, 0 unparseable
 - Live database: first 30 in the new order - 30/30 still to be sold, 30/30 will show three pictures
 - Live: 20 client and admin screens after the change - HTTP 200, 0 PHP or SQL errors
 - Live: every ztmp-*.php diagnostic removed, confirmed 404
---

## Prompt

> yar claudee tum imgesss ka sahii fix nahii kiaa haii kiaa action mayy detail mayy
> jata huu phir back athaa huu phir kharab hojatii hai imagess kuee gayab hojatii
> haii wajaaaa sab 1oo% fix karoooo

and then:

> ab sab confirm karooo admin side client side database dashboard har jagaa …
> ya imges kaaaa fix specs mayy likhoooo taakay yad rahayyy or tum is cheez ko
> dubara nahii chernaaaaa

## What was found

The pictures were not failing. The list under them was changing.

`carSortOptions()['newest']` — the default — was `c.last_updated DESC`, meaning
"whichever rows the harvester wrote most recently". Two things followed, and the
owner had been reporting both as image faults for days:

- **The order was unstable.** Opening a lot and pressing back re-ran the query
  and returned a *different twenty vehicles*. Photograph-rich cars were replaced
  by others. Reproduced: one measurement showed ten rows with three pictures, and
  after a round trip only five — the same result a plain reload gave, because the
  set of cars had moved on.
- **It filled the front page with concluded sales.** The harvester spends much of
  its time re-checking the day's finished auctions, so those were the rows it had
  touched last. Measured before the change: **20 of 20 rows on page one were
  "Closed"**, the console carried **38 image 404s**, and every row showed a single
  small preview — because the source withdraws a vehicle's photographs once its
  sale has run and keeps only a 100x75 preview.

So the portal's front page was, precisely, the one page where almost nothing had
more than one picture — and it reshuffled every time the buyer came back to it.

Things ruled out along the way, each by measurement rather than argument: the
image host throttling (the same address returned the 128x96 tile six times in a
row, so it genuinely has no picture there); slow images (all 45 came from cache,
0 bytes over the network); a JavaScript error (38 console errors, every one a
404 on `number=2` or `number=0`, none from our code); and bfcache (the page sends
`no-store`, so back always rebuilds — but rebuilding was not the problem).

## What was done

One change: the default order is now the sale moment. Vehicles still to be
auctioned come first, soonest first; concluded ones follow, latest first. The
moment is assembled from `auction_on` and `auction_time` and compared against
Japan's clock, computed in PHP because `CONVERT_TZ` needs timezone tables this
host does not carry.

Then the whole surface was confirmed — client listing pages 1 and 2, three back
round trips, two detail pages, admin Vehicles, admin Overview, and the database —
and the findings written into the spec and plan, including an explicit note that
this behaviour is settled and should not be reopened without a reported fault
and a fresh measurement.

## What went wrong

Three earlier answers were reported as fixed and were not the whole fix. Each one
was a real defect, and each was announced as though it closed the matter, so the
owner had to come back a fourth time. The lesson written into the plan: every one
of these looked like "the images are broken" and none of them was — measure the
picture itself, and what the source holds, before believing any theory.

## Outcome

Page one: 20 of 20 rows with three pictures, 60 of 60 pictures real and visible,
0 concluded sales, 0 placeholders. The same after three round trips through a lot
and back, with the same twenty lots each time. Detail pages show every pose the
source holds and zoom opens the chosen one at full size in one fixed frame. The
count reads 33,050 on the client listing, in admin Vehicles, on admin Overview
and in the database, at the same moment.

## Follow-ups

- `car-details.php` returned HTTP 500 once during an earlier sweep and 200 on
  every retry since. Not reproduced.
- No CSRF protection exists anywhere in the project.
- The pass still needs roughly 12.5 hours for a full sweep; oneprice remains
  paused at the owner's instruction.
