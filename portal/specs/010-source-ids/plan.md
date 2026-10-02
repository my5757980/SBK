# Plan 010 - source IDs

## Storage - `includes/source-ids.php` (new)
- Folder `~/source-ids/` (0700): `ids.json` (0600) and `key` (0600, 32 random bytes, made on first save).
- `ids.json`: `{ "a": {base, user, pass, rev, saved_at, saved_by}, "b": {...} }`; `pass` is
  base64(iv 12 | tag 16 | AES-256-GCM ciphertext). Written whole: tmp file, length verified, rename,
  under `flock` on `ids.lock` (the host has left 0-byte files before).
- Defaults when nothing is saved: A `https://pacificboeki.jp`, B `https://bid.aaajapan.com`.
- `sourceIdGet($feed)`, `sourceIdPass($feed)`, `sourceIdSave($feed, $base, $user, $pass|null, $by)`,
  `sourceIdBase($feed)`, validation (https URL, no query; user/pass lengths).
- Feed A session: `pbSessionCheck($base, $code)` (POST `/web/session/get_session_info`, one request)
  and `pbSessionInstall($cookie)` - straight into `pb-harvest/session.txt` when the harvest lock is
  free (and the stop cleared under that lock), else `session.pending`.

## Page - `admin/sources.php` (new) + `assets/css/admin.css`
- Two cards, status from `source-health.php` plus "waiting for the new ID" from revisions.
- Forms POST to the same page with a per-session CSRF token; PRG redirect with a flash message.
- Feed A's sign-in as three numbered steps (open the site / sign in and tick / paste the code)
  with a short how-to for copying the code.

## Feed B job - `SBK-Fetcher/aaa_fetch.py`
- `portal_id()` before anything: `GET aaa-stats-ingest.php?t=..&id=1` -> `{ok, rev, base, user, pass}`.
- rev > 0: BASE/USER/PW replaced; rev != state `id_rev`: drop `halted`, door 0, remember rev.
- No answer: with `id_rev` in state -> hand on (`.next`), source untouched; without -> secrets.
- Health report adds `id_rev` (never the username or password; the Actions log is public).

## Portal endpoints
- `aaa-stats-ingest.php?id=1` (token) returns feed B's saved ID; `health` keeps `id_rev`.
- `pb-harvest.php`: request base = saved address (identity prefix PB_BASE unchanged); at start,
  under its lock, a `session.pending` becomes `session.txt` and any stop is cleared.
- `api/pb-photos.php`: the same request base.

## Permissions, menu, log
- Catalogue group "Data sources": `sources.manage`. Sidebar entry under Setting up.
- `logStaffAction('source.id' | 'source.session', 'Feed A|B')`; names on the Overview.

## Tests
- `sbk-tools/sourcescheck.py` - store round trip on the server in a throwaway folder (file holds no
  plain password), endpoint needs the token and returns rev 0 while nothing is saved, the page as
  admin / refused for an agent, a bogus feed-A session refused and nothing installed, namescan.
- `fetcherfix/t_portalid.py` (in t_sim2) - rev 0 keeps secrets; new rev clears the stop; same rev
  does not; no answer + panel ID before -> no source request, hand on.
- `respsheets.py` + `responsivecheck.py` on the page, 320 -> 2560.

## Risks
1. A wrong saved address stops a feed -> "Use the standard address" button, and status shows it.
2. Saving a new B ID that is also bad -> its own refusal shows on the card within one run.
3. A session pasted while a run is mid-way could be overwritten by the run's renewal -> pending file.
