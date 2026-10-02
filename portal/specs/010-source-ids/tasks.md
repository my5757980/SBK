# Tasks 010 - source IDs

- [x] T1 `includes/source-ids.php` - store, encryption, validation, feed-A session check/install
- [x] T2 `admin/sources.php` + styles in `assets/css/admin.css` (cards, status, steps, Show/Copy)
- [x] T3 permission `sources.manage`, sidebar entry + icon, activity-log names
- [x] T4 `aaa-stats-ingest.php` - `?id=1` answer; health keeps `id_rev`
- [x] T5 `pb-harvest.php` + `api/pb-photos.php` - saved request address; pending session at start
- [x] T6 `aaa_fetch.py` - `portal_id()`, revision handling, `id_rev` in health; `t_portalid.py`
      (SBK-Fetcher b1285f3; t_sim2 89/89, t_auction 37/37)
- [x] T7 `sbk-tools/sourcescheck.py` 27/27 and `sbk-tools/sourcesui.py` 7/7 - live; no real ID saved
      (sourcesui saves a ztest ID on feed A with the standard address and removes it and its log lines)
- [x] T8 lint, deploy, looked at the page 320 -> 2560 (responsivecheck 0 faults at ten widths)

Acceptance: every FR in spec.md has a check above that shows it.

Notes found on the way:
- Messages show as toasts at the bottom of the screen: site.js lifts every `.alert` into one (the
  same on every desk page) - a test must read `.sbk-toast-t`, not `.alert`.
- In a test script exec'ing the appparitycheck harness, a Playwright context must NOT be called
  `ctx` - that is the harness's SSL context and run_php() dies without it (twice, 2 Oct).
- The page shows the two sites' addresses in its inputs - that is its job; its own words name no
  source (sourcescheck checks this).
