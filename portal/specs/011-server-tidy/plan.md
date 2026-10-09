# Plan 011 - server tidy

## One rule in each code base

- **Portal** - `includes/source-health.php` gains `sourceDataDir($name)`: `~/sbk-data/auction/<name>`
  when that folder exists, else `~/<name>`. Every place that built `~/pb-harvest`, `~/aaa-fetch` or
  `~/source-ids` asks it: `source-health.php` (3), `source-ids.php` (2), `admin/sources.php` (2),
  `api/pb-photos.php`, `functions.php` `ussPhotoDir()`, `pb-harvest.php` `PB_DIR`,
  `aaa-auction-ingest.php` (5, through `aucDir()`), `aaa-stats-ingest.php`.
- **Chat** - `includes/config.php`: `SBK_DATA`, `MEDIA_DIR` (`sbk-data/chat/chat-media` when there),
  `APP_CRASH_LOG` (`sbk-data/app/app-crash.log` when `sbk-data/app` is there). `api/crash.php` and
  `api/upload.php` write `APP_CRASH_LOG`; `includes/push.php` `PUSH_DIR` = `sbk-data/app/push` when there.
- **App domain** - its standalone `crash.php` (no chat config on purpose) does the same test itself.

Because each test is "is the new folder there", the code goes live first and nothing changes; the move
switches each user over by itself. Code that creates a missing folder (`aucLock`, `aucSave`, the stats
health) asks the same rule, so it can only create the new one once the move is made.

## The move (one server-side script, PHP `rename()` - same disk, one step)

1. `mkdir ~/sbk-data/{auction,chat,app,backups}` 0700 (PHP and the cron run as the account).
2. `aaa-fetch` while holding `auction-halls.lock`; `pb-harvest` while holding `harvest.lock` (waits for a
   running harvest to finish); then `source-ids`, `chat-media`, `sbk-private` -> `app/push`,
   `app-crash.log` -> `app/`, `old-backups` -> `backups`; `rmdir lint-tmp` (empty).
3. Report each step; stop at the first failure (what is moved stays moved - each step is whole).

## Checks (live)

Paths resolve to the new places; a write test in each; the old names gone from the root; chat media
upload+view (`appmediapresencecheck.py`); a crash report lands in the new log (both doors); the push key
loads and Google gives a token; the next PB run and the next aaajapan pass write their new folders; ID
signals green; Data sources page opens; a USS photo set loads; after an hour no old folder is back.

## Rollback

Rename back (the code then falls back by itself). Nothing is deleted except the empty `lint-tmp`.
