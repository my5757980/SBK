---
id: 12
title: The tick came back but the filter did not
stage: green
date: 2026-09-02
surface: agent
model: claude-opus-5
feature: 007-filter-panel-back-button
branch: 007-filter-panel-back-button
user: Muhammad Yaseen
command: (direct instruction)
labels: [filters, history, back-button, live-verified, ui]
links:
  spec: specs/007-filter-panel-back-button/spec.md
  ticket: null
  adr: null
  pr: null
files:
 - assets/js/lots.js (syncFromUrl, bound to pageshow and called on load)
 - specs/007-filter-panel-back-button/spec.md (new)
tests:
 - Live before - Tokyo + 02-Sep applied, Back gave the right count (10,312) with 02-Sep STILL TICKED
 - Live after  - Back once, 10,312 with only Tokyo ticked; Back again, 44,429 with nothing ticked
 - Live after  - Chassis Model NHP10 applied, Back, 44,429, no ticks, every select and text box empty
 - Live - the sync does not fire the auto-search; setting checked/value from script raises no change event
---

## Prompt

> in mayy asaa haiii aik aik kar kayy tick karooo automatic search hotaa hii …
> likinn jab peechayy jaooo wo bhii to asaa hii honaa chiyaa naaa — aik peechayy
> khud hataa, dusaraa peechayy khud automatic hataa. peechayy ka nahii horahaa,
> poora reset karnaa hotaa haii

## What was found

The facets already submit the form, so every tick is its own history entry and
Back returns to the address with one fewer filter. The server read that address
correctly - the count on the page was right every time.

The panel was wrong. Browsers restore what was typed and ticked when you
navigate through history, which is a kindness on a form somebody is filling in
and exactly wrong on a form that **is** the address: the tick came back while
the filter behind it did not. Measured: after Back the list showed Tokyo's
10,312 - correct - with `02-Sep` still ticked beside it.

And it compounded. The next tick submitted that stale one along with the new
choice, so the filter appeared to refuse to come off, and Reset was the only way
out. That is what was reported.

## What was done

`syncFromUrl()` sets every facet control to what the address says - checkboxes
and radios from the values present, multi-selects option by option, text and
number boxes from the first value or empty. It is bound to `pageshow`, which
fires on a first load, on Back, on Forward, and on a restore from the
back-forward cache where no other script runs at all.

Setting `checked` and `value` from script raises no `change` event, so the
auto-search cannot be set off by the synchronisation - a real risk here, since a
sync that submitted would loop.

Sliders are left alone: their two handles are redrawn from the fields they feed
by code further down the same file, and setting them twice from two places is
how paired handles get out of step.

## Outcome

Back now takes one filter off at a time, from the result and from the panel.
Two filters, two backs: 10,312 with only Tokyo, then 44,429 with nothing ticked.
A facet from a different column - Chassis Model - clears the same way.

## Follow-ups

- The submitted address carries an empty `auction[]=` alongside the real value,
  left over from how the form is built. Harmless, and worth tidying one day.
