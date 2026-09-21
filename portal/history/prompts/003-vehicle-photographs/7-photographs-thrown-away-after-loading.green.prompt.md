---
id: 7
title: Photographs were thrown away after they had loaded
stage: green
date: 2026-09-02
surface: agent
model: claude-opus-5
feature: 003-vehicle-photographs
branch: 003-vehicle-photographs
user: Muhammad Yaseen
command: (direct instruction)
labels: [images, gallery, lightbox, jpauc, aleado, live-verified, regression]
links:
  spec: specs/003-vehicle-photographs/spec.md
  ticket: null
  adr: null
  pr: null
files:
 - assets/js/site.js (isMissing: exact 128x96 instead of "narrower than 200"; pose click carries data-zoom)
 - assets/css/style.css (.pb-lb img: one fixed frame instead of the picture's own size)
 - car-details.php (stage data-zoom and each pose's data-zoom now fullSizeImage)
 - assets/js/photos.js (stage promotion keeps the slot's own zoom address)
 - specs/003-vehicle-photographs/spec.md (new)
 - specs/003-vehicle-photographs/plan.md (new)
 - specs/004-admin-staff-management/spec.md (new)
tests:
 - Live browser: auction listing, default view - 20/20 rows x 3 pictures, 62 loaded, all real, 0 placeholders
 - Live browser: auction listing, hall filter - 20/20 rows x 3, 64 real, 0 tiles visible, 0 real pictures hidden
 - Live browser: auction detail - 23 pictures all real, 12 poses, sheet 640x640, no "No photo available"
 - Live browser: zoom from the frame opened 1024x768 where the frame showed 853x640
 - Live browser: choosing pose 4 then zooming opened number=4 at 1024x768, frame 1180x549 both times
 - Live browser: admin Vehicles (Auction, Available) 33,050 - 30/30 rows carry a real picture
 - Live: admin Overview reads AUCTION 33,050; client listing reads 33,050; database reads 33,050
 - Live database: 33,050 live auction vehicles, 33,050 carry a usable address, 0 empty, 0 unparseable
 - Live sample: 40 vehicles across 20 auction halls - 40 real photographs, 0 tiles, 0 failures
 - Live admin end-to-end: staff created, listed, edit page pre-filled, edit saved, account deleted
 - Live: 10 admin screens and 6 client screens, HTTP 200, 0 PHP errors
---

## Prompt

> yar kiaa hogaya hai tum ko claude images portal mayyy action main page or detail
> mayy bhii aik main a rahii baaki neechayy ki nahiii sab kharab phlayyy sahii kia
> thaaaa tum phir ya bar bar kharab kar detaa hooo

and then, when the first answer was not the whole answer:

> nahiii aur deep jaooo main action mayy bhii 3 aayayaa giii 1 nahii phir detail
> mayy aik us kii main big image baaqi neechayy har pose walii … us par click sayy
> zoom hogii to bhot chooti nahii hogii barii hogii sab ka width height sab same

## What was found

The pictures were arriving and the page was discarding them.

`site.js` decided whether a picture was real by its width — anything under 200
pixels was taken for the host's "no image" tile. That was right when a real
photograph came back 853x640. It is wrong now: the host's tile is **128x96** and
several auction halls serve a **100x75** preview, so the tile had become *larger*
than the photograph. `hideTile()` therefore emptied the listing row and replaced
it with `—`, and `prune()` emptied the detail page's pose strip, leaving the one
picture above it. Three earlier reports of "the images have gone" were this.

Two further faults, found only after the first was fixed and the owner said the
answer was incomplete:

- The overlay sized itself to the picture. A 1024x768 photograph opened at
  1024x768, well inside the edges of a wide monitor; a 100x75 preview opened as a
  stamp on a black screen. Clicking a picture is a request to see it bigger and
  it was answering with the same size or smaller.
- `data-zoom` on the detail page pointed at the same `h=640` file the frame was
  already showing, so zooming returned the picture that was on screen. Choosing a
  pose and then zooming had the same fault.

Separately, and **not** a fault: the halls withdraw the full set once a sale day
has run and keep only the 100x75 preview. Checked against jpauc.com's own gallery
for a concluded lot — it shows the same one small picture. Cars for later sale
days carry 640x480 to 1024x768 and up to eleven poses.

## What was done

- `isMissing()` now tests the exact tile — `naturalWidth === 128 &&
  naturalHeight === 96` — and nothing else. Width thresholds are gone.
- `.pb-lb img` was given a fixed frame, `min(1180px, 100vw-36px)` by
  `min(860px, 100vh-36px)` with `object-fit:contain`, so every zoom opens at one
  size and a small picture is enlarged into it rather than left alone.
- The detail page's stage and every pose now carry `data-zoom` built with
  `fullSizeImage()`, and choosing a pose sets the stage's zoom address from the
  pose rather than from the frame's file.
- `photos.js`, when it promotes a surviving pose into the frame, now takes that
  pose's own zoom address too.
- All twenty-six `ztmp-*.php` diagnostic endpoints were deleted from the live
  site and confirmed 404.
- The findings were written into `specs/003-vehicle-photographs/` (spec and
  plan) and the admin work into `specs/004-admin-staff-management/`.

## What went wrong

The first answer was wrong twice over, and the owner caught both.

The first fix — the exact-tile test — was real and necessary, but it was reported
as though it settled the matter. It did not: the listing still showed one picture
per row, because the rows on top were that day's concluded sales, and the zoom
was still opening the file already on screen. The owner had to say *"nahiii aur
deep jaooo"* before the other two faults were looked for.

Earlier in the same session a diagnostic was written that queried the database
with the same broken assumption the page used, so it agreed with the page and
confirmed nothing. A check that shares the fault it is checking for is not a
check.

## Outcome

Verified on the live site with a real browser session and by fetching each
address directly. Listing: 20/20 rows with three pictures, no placeholders.
Detail: 23 pictures, all real, 12 poses, zoom 1024x768 in a fixed frame. Admin
Vehicles, admin Overview, the client listing and the database all read **33,050**
at the same moment. A 40-vehicle sample across 20 halls returned 40 real
photographs and no tiles.

## Follow-ups

- `car-details.php` returned HTTP 500 once during the sweep and 200 on twelve
  retries. Suspected contention with the harvester's writes; not diagnosed.
- No CSRF protection exists anywhere in the project.
- The pass still needs roughly 12.5 hours for a full sweep of the catalogue, and
  the oneprice section remains paused at the owner's instruction.
