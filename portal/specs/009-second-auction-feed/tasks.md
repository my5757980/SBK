# Tasks: A Second Auction Feed

- [ ] T1 pb-harvest.php: sweepDay() spares `aj-` rows; touchedRows() counts only rows PB wrote;
      storeLots() takes PB's picture when it adopts a row whose pictures are not PB's.
      Test: lint; dry run of one house; the next live runs clean (pblogdays).
- [ ] T2 aaa-auction-ingest.php: token; `?have` (per hall: our aj- counts by date, last whole-read
      count, hall map); POST rows (map fields, skip past days / results-only, skip what PB has,
      upsert aj-, learn hall map); POST done (retire unseen aj- of a whole read, capped; store
      count); dedupe pass (aj- that PB also holds -> move references, delete).
      Test: ingest check with marked rows (new / same-as-PB / result / retire / dedupe), then cleaned.
- [ ] T3 functions.php auctionSplit(); api/counts.php auction_a/auction_b; the six surfaces show
      total = A + B; livecount.js keeps the split live. Test: numbers = DB counts; namescan 0.
- [ ] T4 aaa_fetch.py auction pass: survey (tpl_filterADV), plan halls due, read pages via the
      loader (is_stat=0, _auct_name), forward to the ingest, report; sub-allowance; survey-only
      switch. Test: simulation (fake source + fake portal) incl. refusal, budget, due rules.
- [ ] T5 Rollout 1-4 (plan.md), each verified live; memory + PHR.
