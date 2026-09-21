---
id: 10
title: The sign-in never looked at the email column
stage: green
date: 2026-09-02
surface: agent
model: claude-opus-5
feature: 004-admin-staff-management
branch: 004-admin-staff-management
user: Muhammad Yaseen
command: (direct instruction)
labels: [admin, login, staff, email, live-verified]
links:
  spec: specs/004-admin-staff-management/spec.md
  ticket: null
  adr: null
  pr: null
files:
 - admin/login.php (username first, then a unique active email; label and errors reworded)
 - includes/functions.php (staffEmailTaken)
 - admin/staff-create.php (refuses an email already in use)
 - admin/staff-edit.php (the same, allowing the account to keep its own)
 - specs/004-admin-staff-management/spec.md (User Story 1, FR-000, FR-00A, SC-005, SC-006)
tests:
 - Live: account created with a gmail address, signed out, signed in by email - before the change "Invalid username or password"
 - Live: after the change the same email and password land on Overview as "SALES AGENT - Test Person"
 - Live: the username still signs the same account in
 - Live: a second login with the same email is refused - "Another login already uses that email address"
 - Live: all four changed files pass a syntax check on the server before deployment
 - Live: the test account was deleted afterwards; no zztest rows remain
---

## Prompt

> On the dashboard / admin side, there is an admin section … used to grant
> access by creating a Gmail account and generating a password, which is then
> shared with others so they can log in and use the system. However, the login is
> currently not working.

## What was found

Create Staff asks for a username **and** an email address, and the email is the
part that gets written down and handed over - it is the thing that looks like an
account. The sign-in queried `WHERE username = ?` and nothing else, so the person
typed their Gmail, got "Invalid username or password", and reported the login as
broken. Nothing was broken. The form simply never looked at the column.

Reproduced first, on the live site: an account created with `…@gmail.com` was
refused by that address and accepted by its username.

Clients was checked and ruled out before this - that screen has no form at all,
only a list, so it is not where a login is made.

## What was done

The sign-in tries the username, and then - only if that finds nothing and the
input contains an `@` - the email. The email is accepted only when exactly one
active account carries it: the field is optional and was never made unique, so a
shared address must not sign somebody in as whichever row came first. Create
Staff and Edit Staff now refuse an address another login already uses, so new
data cannot become ambiguous.

The label now reads "Username or Email". The fault was as much the form not
saying what it would accept as the query not accepting it.

## Outcome

Verified live: the same Gmail and password that were refused before now land on
Overview as the right person with the right role; the username still works; a
duplicate address is refused at creation. The test account was removed and no
trace of it remains on the list.

## Follow-ups

- Existing accounts may already share an email. The sign-in refuses those rather
  than guessing, and Edit Staff can be used to give each its own.
- No CSRF protection anywhere in the project.
