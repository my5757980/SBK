# Spec 011 - our private folders in one place on the server

**Asked:** 9 October 2026, the client through the owner: our things lie loose in the hosting account's
root ("chat media should be in the chat"), and he believes that is what filled the server. The owner
chose option **A**: one folder for all of them, outside every web root.

## What is true first (counted 9 Oct, every file on the account)

The account holds 269,584 files and folders. Ours - auction, chat, app and their private folders - are
about 770 (0.3%). The limit was filled by the main WordPress site (110k, 38k of it an old backup copy),
two Node.js environments of the CRM (47.7k), mailboxes of other domains (34k) and two other businesses'
websites (59k). This spec is about tidiness and safety, not about the limit.

## Requirements

- **FR-001** The account's root holds ONE folder of ours, `sbk-data`, instead of eight entries
  (`aaa-fetch`, `pb-harvest`, `source-ids`, `chat-media`, `sbk-private`, `app-crash.log`, `old-backups`,
  the empty `lint-tmp`). The three website folders stay where cPanel keeps every domain's folder.
- **FR-002** Inside: `auction/` (`aaa-fetch`, `pb-harvest`, `source-ids`), `chat/` (`chat-media`),
  `app/` (`push` - the Firebase key, its token cache and log - and `app-crash.log`), `backups/`.
- **FR-003** Everything stays OUTSIDE every web root: customers' pictures and voice notes, the PB
  member session, the encrypted feed IDs and the push key must never be one URL away.
- **FR-004** No outage: the code learns the new places first and keeps using the old ones until a
  folder has been moved; a folder is moved in one step (rename), the PB harvester's and the auction
  ingest's own locks held while theirs move.
- **FR-005** Nothing lost or changed inside the folders: the same files, the same permissions.
- **FR-006** Every user of them works afterwards, checked live: chat pictures and voice (upload and
  view, website and app), the app's crash reports, push notifications' key, the PB harvester's next
  run, the aaajapan feed's next pass and the statistics' report, the ID signals, the Data sources page,
  USS photographs.
- **FR-007** No old folder comes back at the root afterwards (code that creates a missing folder must
  create it in the new place).

## Out of scope

The big file users that are not ours (WordPress backup copy, the CRM's Node environments, other
domains' mail and sites) - listed for the owner, his decision.
