# Tasks 011 - server tidy

- [x] T1 portal: `sourceDataDir()` + its 13 callers; lint on the server (8 files clean)
- [x] T2 chat: `SBK_DATA`, `MEDIA_DIR`, `APP_CRASH_LOG`, `PUSH_DIR`; `crash.php`, `upload.php`; lint clean
- [x] T3 app domain `crash.php`; lint clean
- [x] T4 deploy T1-T3 (safedeploy: live = before copy; read back equal) - nothing changed yet
      (aaa-stats-ingest.php differed live only by a comment naming the server's IP, already gone in git)
- [x] T5 the move, 9 Oct 2026 08:46:16 UTC: 21 steps OK; the aaajapan lock and the PB harvester lock
      taken (waited 0 s); 8 root entries -> `~/sbk-data/{auction,chat,app,backups}`; permissions kept
      (sbk-data 700, push 700, key 600)
- [x] T6 live checks: every folder resolves to its new place and takes a write; nothing left in the
      root; crash reports through all three doors land in the new log (test lines removed); push key ->
      Google access token; PB runs 106 + 107 in the new folder; signals green; sourcescheck 27/27;
      ussphotocheck 10/10; appmediapresencecheck 20/20 (website + app, picture + voice, both ways)
- [x] T7 `sbk-tools`: 40 references in 27 tools moved to the new places; phplint's temp folder is
      `~/sbk-data/lint-tmp`; appmediapresencecheck now deletes the files it uploads
- [x] T8 commit + push: SBK, client portal, SBK-Chat, SBK-Application

Acceptance: every FR in spec.md has a check above that shows it.

Found on the way:
- 37 picture/voice files in chat-media belonged to no message - five test files uploaded again and
  again by the app checks (18-20 Sep, 9 Oct), whose clean-up removed the rows but not the files.
  Removed (only unreferenced files of those five exact sizes); 29 kept, 0 messages lost a file.
- The v2 website's own chat keeps its media in its Laravel storage - untouched by this.
