---
id: 6
title: Permissions on every action, not just pages
stage: green
date: 2026-08-19
surface: agent
model: claude-opus-5
feature: 002-jpauc-data-sync
branch: 002-jpauc-data-sync
user: Muhammad Yaseen
command: (direct instruction)
labels: [permissions, admin, roles, staff, authorisation, live-verified]
links:
  spec: specs/002-jpauc-data-sync/spec.md
  ticket: null
  adr: null
  pr: null
files:
 - includes/functions.php (permissionCatalogue, permissionAliases, expandPermissions, bidRow, inquiryRow)
 - admin/orders.php (unguarded POST closed)
 - admin/bids.php (status and note split)
 - admin/inquiries.php (reply and status split)
 - admin/staff.php (create, edit, password split)
 - admin/roles.php (create, rename, delete, permissions split)
 - admin/_header.php (sidebar keys moved to .view)
 - assets/css/style.css (.ro-value, .ro-note)
tests:
 - Live self-test: 21 permission keys asked for by the code, 21 present in the catalogue
 - Live: every legacy key expands to the keys it stood for; Sales agent keeps its access (8 stored + 3 inherited)
 - Live end-to-end with a throwaway view-only role: orders, bids, enquiries, staff create, role add and role permissions all refused
 - Live: no control rendered that the role could not use; bid status rendered as a plain value
 - Live: the bid and order the test aimed at came through unchanged
---

## Prompt

Sir relayed his client's answer on permissions:

> Yes — even the buttons will have permissions, like whether he can bid on it or
> not, whether he can update it or not. Permissions will be given on all these
> things. Even for every small action — like whether he can edit or delete —
> those permissions will be provided too. Right now it's not like that; it's not
> just set on entire pages. A page may be accessible, but whether he can do this
> action — edit, update, etc. — will be controlled. So we'll need to configure
> everything properly with a clean layout.

Followed by: deploy it and finish the whole thing.

## What was found

Half the enforcement already existed. Bids and enquiries checked a permission on
POST; staff and roles were gated by a page permission that happened to be the
manage-level one. **Orders had no check at all** — a role holding only
`orders.view` could move an order to "paid". That was the one real hole.

## What was done

Permissions became per action. `bids.manage` split into `bids.status` and
`bids.note`; `staff.manage` into view/create/edit/password; `roles.manage` into
view/create/rename/delete/permissions.

Two decisions worth recording:

**Old grants were not migrated, they were aliased.** `permissionAliases()` maps
each retired key to the keys it stood for, and `expandPermissions()` opens them
at read time. A role ticked yesterday keeps exactly the access it had, with
nothing to re-tick and no migration to get wrong.

**What a submission may do is decided by which values it moves**, not by the fact
that it was submitted. One form carries a bid's status and its note, and a role
can be trusted with the second and not the first — so the page reads the row
first and asks only about the fields that actually changed.

Where a permission is withheld the control is removed and the value it would
have held is printed in its place. A disabled select reads as "broken, try
again"; a plain value reads as "this is how it is".

## What went wrong

An apostrophe inside a single-quoted PHP message ended the string early, and
`admin/inquiries.php` returned 500 on the live site until it was caught. It was
found because the deploy check walks every admin page and reads the status code
— seven returned 302, one returned 500.

Before that, the first upload reported `ok` for all eight files and changed
nothing: Git Bash rewrote `/home/thelyfas/...` into `C:/Program Files/Git/home/...`
on its way into the upload call, and cPanel accepted it. Never trust the
uploader's own success report — the check that mattered was fetching the CSS
back and looking for a rule that only exists in the new copy.

## Outcome

Deployed and verified live. All checks pass. Committed as 9d8f21b and 6d6c29a,
pushed to origin/002-jpauc-data-sync.

## Follow-ups

- The orders screen could not be checked for read-only rendering: the orders
  table is empty. The refusal was verified; the rendering was verified on bids.
- The cPanel password used for deploys has been exposed in chat and should be
  changed.
