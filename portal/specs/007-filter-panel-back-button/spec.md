# Feature Specification: The Filter Panel and the Back Button

**Feature Branch**: `007-filter-panel-back-button`
**Created**: 2026-09-02
**Status**: Implemented and verified live
**Input**: Owner: "in mayy asaa haiii aik aik kar kayy tick karooo automatic search hotaa hii choooose karoo likinn jab peechayy jaooo wo bhii to asaa hii honaa chiyaa naaa — aik peechayy khud hataa, dusaraa peechayy khud automatic hataa. peechayy ka nahii horahaa, poora reset karnaa hotaa haii"

## Context

The listing's six facet columns — Auc. Date, Make, Model, More Filters, Chassis
Model, Auction — apply themselves. A tick waits 650ms, so somebody choosing
three grades gets one search rather than three, and then the form submits.

Because it submits, every tick is its own entry in the browser's history, and
going back should take one filter off. The address did exactly that. The panel
did not, and the panel is what the buyer looks at.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Back takes one filter off (Priority: P1)

A buyer narrows the list a tick at a time and walks it back the same way.

**Why this priority**: Without it the only way out is Reset, which throws away
every choice — so a buyer who ticked five things and wants four has to start
again. The owner reported it in exactly those words.

**Acceptance Scenarios**:

1. **Given** two filters applied one after the other,
   **When** the buyer presses Back once,
   **Then** the second filter is gone — from the result **and** from the panel,
   with its box no longer ticked.
2. **Given** the same, **When** Back is pressed again, **Then** the first filter
   is gone too and the list is unfiltered.
3. **Given** any facet — a sale day, an auction hall, a chassis model, a make, a
   colour, a gearbox — **When** Back is pressed, **Then** it clears the same way.
4. **Given** a filter cleared by Back, **When** the buyer then ticks something
   else, **Then** only the new choice is applied; the cleared one does not come
   back with it.

### Edge Cases

- **The browser restores form state on history navigation.** That is helpful on
  a form somebody is filling in and wrong on a form that *is* the address: the
  tick returns while the filter behind it does not.
- **The back-forward cache.** A page served from it runs no scripts at all, so a
  `DOMContentLoaded` handler never fires. `pageshow` does, on every arrival.
- **Sliders.** Year, mileage and engine size are two handles feeding hidden
  fields, redrawn by their own code; they are left to it rather than set twice.

## Requirements *(mandatory)*

- **FR-001**: On every arrival at the listing — first load, back, forward, or a
  restore from the back-forward cache — every facet control MUST be set to
  what the address says, not to what the browser remembers.
- **FR-002**: That synchronisation MUST NOT trigger the auto-search. Setting
  `checked` or `value` from script does not fire `change`, which is what the
  auto-search listens for.
- **FR-003**: The address remains the single source of truth for what is
  filtered. Nothing about a filter may be kept in the session or in storage.

## Success Criteria *(mandatory)*

- **SC-001**: One Back removes one filter, from the result and from the panel.
  *Measured live: Tokyo + 02-Sep applied → Back → 10,312 with only Tokyo
  ticked → Back → 44,429 with nothing ticked.*
- **SC-002**: The same holds for a facet from a different column. *Measured:
  Chassis Model `NHP10` applied → Back → 44,429, no ticks, every select and text
  box empty.*
- **SC-003**: Before the change, the count was already right and the panel was
  wrong. *Measured before: Back gave 10,312 — correct — while `02-Sep` stayed
  ticked.*

## Where it lives

`assets/js/lots.js`, `syncFromUrl()` in the facets block, bound to `pageshow`
and called once on load.
