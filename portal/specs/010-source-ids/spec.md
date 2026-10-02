# Spec 010 - Change the feeds' IDs from the admin panel

**Asked by:** the client, through the owner, 2 October 2026 - "admin panel mein dono websites ka URL,
username, password daalein; ID suspend ho to hum khud badal dein aur naye se khud fetch ho".

## What is possible (agreed with the owner before building)

| Feed | What it reads | How it signs in | After a new ID is saved |
|---|---|---|---|
| **Feed B** - auction B + statistics | the second auction site, by the job on GitHub | username + password, by itself | used on the job's next run (within ~20 min), no person needed |
| **Feed A** - auction A | the first auction site, by pb-harvest.php on our server | the site asks a PERSON to tick "I'm not a robot" | a person signs in once with the new ID and hands the session over on this page; then it runs by itself |

The address may be changed only to the SAME site at a new address. A different website needs its
own code (owner confirmed, 2 Oct).

## Requirements

- **FR-001** An admin-only page, "Data sources", under Setting up, permission `sources.manage`
  (the administrator always; other roles only if ticked on the Permissions screen).
- **FR-002** One card per feed: live status (working / not working / waiting for the new ID, in our
  own words), the address, the username, the password (hidden, Show, Copy), Save.
- **FR-003** Passwords are stored on the server outside every web root, encrypted (AES-256-GCM, key
  in its own 0600 file). Never in a repository, never in a log, never in the GitHub job's state.
- **FR-004** Feed B: the GitHub job asks the portal for the saved ID at the start of every run
  (token-protected) and uses it instead of its GitHub secrets. A NEW ID (higher revision) clears
  the job's 24-hour stop and door count. If the portal cannot answer and a panel ID has been used
  before, the job asks the source nothing and hands on - it never falls back to an old ID.
  Until an ID is saved here the job keeps using its GitHub secrets (nothing changes on day one).
- **FR-005** Feed A: the card keeps the username/password for the person who signs in, opens the
  site, and takes the session code they paste; the page checks it with ONE request to the site
  (who does this session belong to?) and only a session that belongs to a member is installed.
  Installing clears the harvester's stop. If a harvest run is in progress the session waits in
  `session.pending` and the next run (within 5 min) takes it.
- **FR-006** The request address of both feeds follows the saved address; the identity prefixes
  stored in `cars.source_url` do not change, so no existing row changes meaning.
- **FR-007** Every save and every session hand-over is written to the desk's activity log.
- **FR-008** The page works from a 320px phone to a 2560px monitor (the 1 Oct standard).
- **FR-009** No source name in the page's own words (labels say Feed A / Feed B); only the address
  a person types or has saved is shown, and only to this permission.

## Out of scope

Solving or sidestepping the robot check (never). A different website. Changing request volumes.
