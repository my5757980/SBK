# Implementation Plan: Vehicle Photographs

**Spec**: `specs/003-vehicle-photographs/spec.md`
**Status**: Implemented, live, verified 2026-09-02

## Where each rule lives

| Rule | File | What it does |
|---|---|---|
| FR-001, FR-002 | `includes/functions.php` → `jpaucImageSet()` | Reads `date`, `auct`, `bid` out of the stored address and returns the set by substituting `number=`. Returns empty for a non-`aleado.com` address (FR-013). |
| FR-002 | `includes/functions.php` → `jpaucSheetUrl()` | The same address at `number=0`. |
| FR-008 | `includes/functions.php` → `fullSizeImage()` | Strips `[?&]h=\d+`, which is what turns a scaled copy into the original. |
| FR-003 | `index.php` (photo cell, ~line 410) | `jpaucImageSet($car, 2)` + the sheet, each `&h=640` rewritten to `&h=320`; `data-zoom` is `fullSizeImage()` of the same. Falls back to `getCarImages()` when no set can be derived. |
| FR-012 | `index.php` (~line 436) | The `—` placeholder, in the `else` branch only. |
| FR-004, FR-010 | `car-details.php` (~line 51, ~line 284, ~line 305) | `jpaucImageSet($car, 12)`; the stage carries `data-zoom="fullSizeImage(...)"`; each pose carries three addresses — `src` (strip size), `data-full` (frame size), `data-zoom` (original). |
| FR-005, FR-011 | `assets/js/site.js` → `isMissing()`, `prune()`, `hideTile()` | The exact-tile test, and the two places that act on it — the detail strip and the listing row. |
| FR-006, FR-007 | `assets/js/photos.js` → `reallyFailed()`, `drop()` | 404 handling; the `currentSrc` guard; the early return for the overlay's own `<img>`. |
| FR-009 | `assets/css/style.css` → `.pb-lb img` | The fixed frame: `width:min(1180px, 100vw-36px)`, `height:min(860px, 100vh-36px)`, `object-fit:contain`. |
| FR-010 | `assets/js/site.js` (~line 308) | Choosing a pose sets the stage's `src` from `data-full` and its `data-zoom` from the pose's `data-zoom`. |
| FR-003 (equal sizes) | `assets/css/style.css` → `.lot-table .lot-thumb` | `flex:0 0 106px; width:106px; height:80px; object-fit:cover` — the sheet is square and would otherwise be squeezed by flex. |
| FR-014 | `includes/functions.php` → `carSortOptions()`, key `newest` | The sale moment is assembled from `auction_on` and `auction_time` and compared against Japan's clock, worked out in PHP because `CONVERT_TZ` needs timezone tables this host does not carry. Unsold first, soonest first; concluded after, latest first. |

## Settled — do not reopen

This was reported four times and half-fixed twice, each partial answer creating
the next report. It is now measured end to end and signed off. **Do not change
`isMissing()`, the zoom addresses, the overlay frame, or the default sort again
except in response to a fault somebody has actually seen — and then measure
first, on the live site, before editing anything.**

The specific trap: every one of these defects looked like "the images are
broken" and none of them was. Two were the page discarding pictures it had
already loaded; the third was the list changing underneath the buyer. A
diagnostic written from the same assumption as the page will agree with the page
and confirm nothing — that happened here too. Measure the picture itself, and
measure what the source holds, before believing any theory.

## The four defects this replaced

1. **`isMissing()` judged by width** (`naturalWidth < 200`). Correct while a real
   photograph was 853x640. Once the halls began serving a 100x75 preview, the
   test matched photographs as well as the 128x96 tile, so `hideTile()` emptied
   the row and replaced it with `—`, and `prune()` emptied the detail strip. Now
   the test is the exact tile size.
2. **The overlay opened the picture at its own size.** `max-width`/`max-height`
   with `width:auto;height:auto` means a 100x75 preview opens as a stamp. Now the
   frame is fixed and the picture is fitted into it.
3. **`data-zoom` carried the frame's own file.** The stage and each pose pointed
   `data-zoom` at the `h=640` copy already on screen, so zooming returned the
   same picture. Now every `data-zoom` is `fullSizeImage()`.
4. **The listing was ordered by `last_updated DESC`.** Not an order at all — an
   accident of the sync. It made the list unstable, so pressing back after
   opening a lot returned different vehicles and the pictures appeared to break;
   and because the harvester spends its time re-checking the day's concluded
   sales, those filled the front page — 20 of 20 rows Closed, 38 image requests
   404, one small preview each, since the source withdraws a vehicle's
   photographs once its sale has run. Now ordered by the sale moment: 0 of 20
   Closed, 20 of 20 rows with three pictures, and the same list on the way back.

## How to check it again

Nothing here needs a diagnostic endpoint on the live site. In a logged-in
browser, on any listing or detail page:

```js
[...document.images].filter(i => i.src).map(i => ({
  cls: i.className,
  size: i.naturalWidth + 'x' + i.naturalHeight,
  hidden: getComputedStyle(i).display === 'none' || i.hasAttribute('data-broken')
}))
```

- A row with `128x96` **and** `hidden:false` means FR-005 has regressed.
- A picture with a real size **and** `hidden:true` means photographs are being
  thrown away — the defect this spec exists to prevent.

To find out what the source really holds for a lot, open
`https://jpauc.com/auction/detail/<data-id>` with the harvester's cookie; the
`data-id` is on the listing row, not in our database.

## Risks

- The tile size is the single point of failure. If the host changes it,
  every slot shows a grey tile — visible, and safer than the reverse.
- Twelve slots per detail page is twelve browser requests, of which the unused
  ones 404. Measured as acceptable; discovering the count in the browser instead
  was tried, and it lost the strip.
