---
id: 11
title: A chassis number the feed never sends
stage: green
date: 2026-09-02
surface: agent
model: claude-opus-5
feature: 006-lot-and-chassis-search
branch: 006-lot-and-chassis-search
user: Muhammad Yaseen
command: (direct instruction)
labels: [search, chassis, lot, live-verified, source-limit]
links:
  spec: specs/006-lot-and-chassis-search/spec.md
  ticket: null
  adr: null
  pr: null
files:
 - includes/functions.php (buildCarFilter: a term of 8+ characters is also matched the other way round)
 - specs/006-lot-and-chassis-search/spec.md (new)
tests:
 - Live before - NHP10 739, nhp10 739, 65054 2, "4189, 65054" 4, LA600S 321, GVF55 11, NHP10-2054321 ZERO
 - Live after  - NHP10-2054321 723, NHP102054321 723, LA600S-0512345 320
 - Live after  - every earlier search returns exactly what it did before
 - Live database - 44,429 vehicles carry only 4,736 distinct chassis values; NHP10 is on 722 of them
 - Live source  - jpauc detail page carries data-cn (chassis number) and it is empty
---

## Prompt

> when we search using a chassis number, the corresponding car should appear.
> Since every car has a unique chassis number, searching any chassis number
> should return only that one specific car. … It is currently not working.

## What was found

The search was not broken. It matches a lot number exactly and a chassis code
loosely, both boxes feed the same filter, and every one of those worked when
measured: `NHP10` returned 739 vehicles, `nhp10` the same, `65054` its two lots,
`4189, 65054` all four.

What returned nothing was a whole chassis number - and it could not have done
anything else, because we do not have chassis numbers. The `chassis` column
holds the **model code**: 44,429 live vehicles carry 4,736 distinct values,
`NHP10` alone on 722 of them.

The reason is at the source, and it was worth reading rather than assuming.
jpauc's listing calls the column "Model Code". Its detail page has a `data-cn`
attribute - chassis number - and it is **empty**; the "Chassis Number" box beside
it is a lookup the buyer types into, posting to `/vin/get_data_chassis_no`
against a third-party database to return a production month, with the page
stating the result is "for customer Reference Only". The number is not behind a
paid plan. The source does not hold it.

## What was done

A term of eight characters or more is now also matched the other way round -
the vehicle's chassis code found *inside* what was typed - so a whole chassis
number lands on its model whether or not it is written with a dash. Codes under
three characters are excluded so nothing matches by accident, and short terms
are untouched, so an ordinary search behaves exactly as before.

It cannot single out one vehicle. Nothing can, without the number. But it puts
the buyer on the right model instead of on an empty page, which is what the
complaint was really about.

## Outcome

`NHP10-2054321` went from 0 to 723; without its dash, the same. Every earlier
search returns exactly what it returned before.

## Follow-ups

- If a chassis number is ever needed for one vehicle, it has to come from the
  auction sheet image, which would mean reading the sheet - a different feature
  and a different cost.
