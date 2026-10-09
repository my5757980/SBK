# Tasks: A Second Auction Feed

- [x] T1 pb-harvest.php: sweepDay() spares `aj-` rows; touchedRows() counts only rows PB wrote;
      storeLots() takes PB's picture when it adopts a row whose pictures are not PB's.
      Test: lint; dry run of one house; the next live runs clean (pblogdays).
- [x] T2 aaa-auction-ingest.php: token; `?have` (per hall: our aj- counts by date, last whole-read
      count, hall map); POST rows (map fields, skip past days / results-only, skip what PB has,
      upsert aj-, learn hall map); POST done (retire unseen aj- of a whole read, capped; store
      count); dedupe pass (aj- that PB also holds -> move references, delete).
      Test: ingest check with marked rows (new / same-as-PB / result / retire / dedupe), then cleaned.
- [x] T3 functions.php auctionSplit(); api/counts.php auction_a/auction_b; the six surfaces show
      total = A + B; livecount.js keeps the split live. Test: numbers = DB counts; namescan 0.
- [x] T4 aaa_fetch.py auction pass: survey (tpl_filterADV), plan halls due, read pages via the
      loader (is_stat=0, _auct_name), forward to the ingest, report; sub-allowance; survey-only
      switch. Test: simulation (fake source + fake portal) incl. refusal, budget, due rules.
- [ ] T5 Rollout 1-4 (plan.md), each verified live; memory + PHR. (1-3 done: survey live 13:29 UTC,
      read mode on 14:16 UTC 29 Sep; 4 = first read verified - feedbcheck.py)
- [ ] T6 (FR-008) The signal: aaa_fetch.py reports `auction` {mode, ok_at, err, share} at every run's
      end; aaa-stats-ingest.php keeps it; source-health.php sourceHealthAuctionB() + one combined
      auction signal naming the feed at fault; source-signal.js repaints the label.
      Test: t_auction 30/30, t_sim2 70/70; signalcheck (rules 50/50 before deploy, pages after);
      namescan allows only a red auction signal.
- [x] T7 (9 Oct 2026, the owner's yes) The fold no longer waits for the fetcher: `php aaa-auction-ingest.php
      --dedupe` from the server's cron at minutes 4,14,...,54 (command line only - a web request is never
      PHP_SAPI cli, so the web door still wants the token; no source request). Why: passes stop once the
      day's share is spent (07:40 UTC on 9 Oct) and 382 B lots then showed twice beside PB's until the
      next day. Trace: aaa-fetch/dedupe-last.json (each run) and dedupe.log (a line when it folded).
      Test: 4 token-less web calls 404; token dry run still answers; first cron run 10:14:25 UTC wrote
      its trace (merged 0 - the 382 had been folded by hand at 09:52 UTC: dry 382 -> 382 -> dry 0).
