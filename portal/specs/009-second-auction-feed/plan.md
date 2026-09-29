# Plan: A Second Auction Feed (aaajapan beside Pacific Boeki)

**Spec**: `spec.md` - **Date**: 2026-09-29

## Shape

```
GitHub (SBK-Fetcher, aaa_fetch.py - the Statistics fetcher, same run, same sign-in, same 1.5 s clock)
  auction pass:  GET /aj_neo?classic  -> tpl_filterADV: every hall + count per date   (1 request)
                 GET portal ?have     -> what we hold per hall, last counts, hall map   (free)
                 per hall due:  POST aj_neo?file=loader (is_stat=0, _auct_name, page)   (pages)
                                POST portal rows  -> aaa-auction-ingest.php
                                POST portal done  (whole read -> retire unseen aj- rows)
Portal (cPanel)
  aaa-auction-ingest.php   token-guarded, never calls the source (its IP is refused there)
  cars table               aj- rows beside pb- rows, source_section 'japan'
  pb-harvest.php           3 lines: spare aj- rows in sweepDay; count only its own rows
                           in touchedRows; on adopting an aj- row take PB's picture
  functions.php            auctionSplit(): A = not aaajapan's, B = aaajapan's (source_url)
  6 count surfaces         total = A + B (welcome, list, client dashboard, admin dashboard,
                           admin cars, api/counts.php + livecount.js)
```

## Decisions

1. **One fetcher, one purse.** The auction pass lives in the Statistics fetcher: one sign-in,
   one gap, one daily budget (16,000, unchanged); the pass has its own sub-allowance
   (`AAA_AUCTION_BUDGET`, default 3,000/day) and never touches the history reserve.
2. **The survey is the search page itself** - its JS carries every hall with its count per
   date. A hall is read when: never read; its count moved since our last whole read; or it
   has `aj-` lots selling today (results) and was not read in the last 45 min (08-19 JST).
3. **The portal decides "same car"**, not the fetcher: it sees PB's rows. Rows for past days
   or with a result are not offered (a result updates an existing `aj-` row's status only).
4. **PB wins a shared lot.** PB's `storeLots()` already adopts a picture-less row by day +
   lot + make + model; `aj-` rows carry non-PB pictures, so they land in its `$bare` list
   and are adopted the same way - the change is only to take PB's picture on adoption.
   Anything adoption misses (two candidates) the ingest's dedupe pass merges: references
   (bids, orders, inquiries, auction_results) moved to PB's row, the `aj-` row deleted.
5. **Hall names**: aaajapan's name mapped to PB's when learnt (a skipped lot tells us which
   PB hall it sits in); normalised otherwise (Tohoku/Touhoku, Kanto/Kantou, Shonan/Syonan,
   "X Nyusatsu" -> "X"...). Kept in `aaa-fetch/auction-halls.json` (outside the web root).
6. **Split rule**: B = `source_url LIKE 'https://bid.aaajapan.com%'` (not yet adopted by PB).

## Safety
- Server never contacts aaajapan (its IP is refused there - measured 2026-09).
- Stop on the first refusal (existing `Blocked`/door handling); nothing above 1.5 s/request.
- Retire only after a whole, stable read (count unchanged across it), capped like PB's.

## Rollout (each step verified live before the next)
1. pb-harvest.php 3 lines (harmless with no `aj-` rows) -> watch PB's next runs.
2. aaa-auction-ingest.php live, fed by a test with marked rows (then removed).
3. Count split on the six surfaces (B = 0 until data comes).
4. Fetcher: survey-only mode (1 request/check) -> then reads with a small cap -> full cap.
