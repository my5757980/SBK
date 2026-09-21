# Feature Specification: Admin Staff Management

**Feature Branch**: `004-admin-staff-management`
**Created**: 2026-09-02
**Status**: Implemented and verified live
**Input**: Client's list, relayed by the owner: "Staff sy login nai horaha ha … staff create krne ka alag page hoga ek row ma do dabbe aye gye create staff ky name sy page hoga or dosra list staff ky name sy page hoga … first alphabet capital hoge saare … or sath edit or delete ka button lag jye ga edit krne py edit ky page pr jyega jaha old data show horaha hoga agr vo edit krke save krega tu vo update krde ga data"

## Context

Staff logins were managed from one screen that both listed and created, its
labels were lower-case, and there was no way to edit or remove an account once
made. The client asked for the screen to be split in two, reachable from one row
of two boxes, with Edit and Delete on each row and an edit page that opens
already filled in.

The one thing that was not a layout request — "staff sy login nai horaha" — was a
real defect and is fixed.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A staff member can sign in (Priority: P1)

An account created on the admin side can be used to sign in — with whichever of
the two things it was created with.

**Why this matters**: Create Staff asks for a username **and** an email address,
so an account gets handed over as "here is your Gmail and your password". The
sign-in only ever looked at the username column, so that person typed their
email, got "Invalid username or password", and concluded the login was broken.
The account was fine every time. This was reported twice.

**Acceptance Scenarios**:

1. **Given** an account created through Create Staff with a password,
   **When** that **username** and password are used at the admin sign-in,
   **Then** the sign-in succeeds and lands on Overview.
2. **Given** the same account,
   **When** its **email address** and password are used instead,
   **Then** the sign-in succeeds just the same.
3. **Given** an account marked not Active,
   **When** either credential is used,
   **Then** the sign-in is refused.
4. **Given** an email address that somehow sits on two accounts,
   **When** it is used to sign in,
   **Then** it is refused rather than signing anybody in — an address that names
   two people names nobody.
5. **Given** an email already used by another login,
   **When** it is entered on Create Staff or Edit Staff,
   **Then** it is refused with "Another login already uses that email address".

### User Story 2 - Creating and listing are separate screens (Priority: P2)

**Acceptance Scenarios**:

1. **Given** the admin navigation,
   **When** Staff is opened,
   **Then** it offers two entries, **Create Staff** and **List Staff**, and Staff
   itself lands on List Staff.
2. **Given** the Create Staff form,
   **When** it is drawn on a normal screen,
   **Then** its fields sit **two to a row**, not in one narrow strip.
3. **Given** any label on either screen,
   **When** it is read,
   **Then** it is in Title Case — "Active", "New Password", "Create Login".

### User Story 3 - An account can be edited (Priority: P1)

**Acceptance Scenarios**:

1. **Given** a staff row,
   **When** Edit is clicked,
   **Then** the edit page opens with the existing name, email, role and Active
   state **already filled in**.
2. **Given** the edit page,
   **When** a field is changed and saved,
   **Then** the change is stored and shown on List Staff.
3. **Given** the edit page,
   **When** the username is looked at,
   **Then** it is shown but cannot be changed — a username is how a login is
   known.

### User Story 4 - An account can be removed (Priority: P2)

**Acceptance Scenarios**:

1. **Given** a staff row that is not the signed-in user,
   **When** Delete is confirmed,
   **Then** the account is removed and the list no longer shows it.
2. **Given** the signed-in user's own row,
   **When** it is looked at,
   **Then** Delete is not offered.
3. **Given** the only account holding the administrator role,
   **When** Delete is attempted,
   **Then** it is refused with a reason — removing it would lock everybody out
   of the screen that could put it back.
4. **Given** a role without the `staff.delete` permission,
   **When** a delete is posted,
   **Then** it is refused.

### Edge Cases

- **Deleting yourself.** Refused; nobody recovers from it alone.
- **Deleting the last administrator.** Refused, for the same reason one step out.
- **A stale id.** Posting a delete for an account that no longer exists answers
  "That login no longer exists" rather than reporting success.
- **A password nobody wrote down.** Passwords cannot be read back; the create and
  reset screens both say so at the moment they are set.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-000**: The admin sign-in MUST accept either the account's username or the
  email address it was created with. The username is matched first; the email is
  a fallback and MUST be accepted only when exactly one active account carries
  it.
- **FR-00A**: Create Staff and Edit Staff MUST refuse an email address that
  already belongs to another login, so an address can never name two accounts.
- **FR-001**: Staff MUST be managed from two screens — `staff-create.php` and
  `staff-list.php` — reachable from one navigation group, with `staff.php`
  redirecting to the list.
- **FR-002**: Create Staff MUST lay its fields two to a row.
- **FR-003**: Every label on both screens MUST be Title Case.
- **FR-004**: Each staff row MUST offer Edit when the viewer holds `staff.edit`
  or `staff.password`, and Delete when the viewer holds `staff.delete`.
- **FR-005**: The edit screen MUST arrive pre-filled with the account's name,
  email, role and Active state.
- **FR-006**: The username MUST be shown on the edit screen and MUST NOT be
  editable.
- **FR-007**: Saving the edit screen MUST persist the change and return to the
  list with a confirmation.
- **FR-008**: Delete MUST be refused for the viewer's own account, for the last
  administrator, and for a role lacking `staff.delete`.
- **FR-009**: Every create, edit, password reset and delete MUST be written to
  the staff action log.
- **FR-010**: A password MUST never be readable after it is set; both screens
  MUST say so when one is set.

### Key Entities

- **`admins`** — id, username, name, email, password hash, `role_id`,
  `is_active`, `last_login`, `created_at`. Both `username` and `email` are ways
  in; `email` stays optional, and `staffEmailTaken()` in `includes/functions.php`
  is what keeps it unambiguous.
- **`roles`** — id, `name` (machine, e.g. `admin`), `label` (shown, e.g.
  "Administrator"), and the permissions it grants.
- **Permission `staff.delete`** — "Remove a staff login for good". Deliberately
  **not** folded into the `staff.manage` alias, so an existing role does not gain
  the power to delete just because it could already manage staff. It has to be
  ticked on the Roles screen for any role that should have it.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Every admin screen loads without a PHP error. *Measured
  2026-09-02: 10 of 10 screens — Overview, Vehicles, Clients, List Staff, Create
  Staff, Roles, Permissions, Orders, Bids, Enquiries — HTTP 200, 0 errors.*
- **SC-002**: An account created on the admin side can be created, listed,
  edited and deleted. *Measured end to end against the live site: created →
  "Created … as Administrator"; appeared on List Staff with Edit and Delete;
  edit page arrived pre-filled (name, email, role, Active); a changed name saved
  and showed on the list; delete answered "Deleted …" and the account was gone.*
- **SC-003**: The edit screen shows the username without allowing it to change.
  *Verified: rendered `disabled`, with the reason stated beneath it.*
- **SC-004**: Delete is refused where it should be. *Verified: own account offers
  no Delete; a stale id answers "That login no longer exists".*
- **SC-005**: A login works with either credential. *Verified live 2026-09-02:
  an account created with `…@gmail.com` was refused by email before the change
  ("Invalid username or password") and signed in with it afterwards, landing on
  Overview as the right person with the right role; the username still worked.*
- **SC-006**: An email cannot be shared. *Verified: creating a second login with
  an address already in use was refused — "Another login already uses that email
  address".*

## Assumptions

- Roles, not individual accounts, carry permissions. Granting somebody the right
  to delete staff means ticking `staff.delete` on their role.
- The administrator role exists and at least one account holds it.

## Out of Scope

- Two-factor sign-in.
- Password rules beyond what the create screen already enforces.
- Self-service password reset by e-mail.

## Known issues, open

- **No CSRF protection anywhere in the project.** None of these forms carries a
  token, so they rely on the session cookie alone. Recorded here because it is a
  real gap and it is not visible from the screens.
- **`staff.delete` must be ticked per role.** Any non-administrator role that
  should be able to delete needs it set on the Roles screen; it is not implied by
  `staff.manage`.
- **Admin credentials in use are the defaults** (`admin` / `admin123`). Should be
  changed before handover.
