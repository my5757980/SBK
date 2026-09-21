#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
AUTO SYNC - keeps our MySQL mirror as close to nikkyocars as possible.

Three tiers, so nothing is ever left stale:

  FAST  every 60s  - newest pages of the big makes (new arrivals land here)
  FULL  every ~10m - newest pages of every make
  SWEEP continuous - a rolling cursor that walks EVERY page of EVERY make and
                     starts over, so each of the ~82k cars gets refreshed on a
                     predictable cycle (price moves, sold/unsold results).

Without the sweep only ~60 cars per make were ever re-read, so a car that sold
after we first saw it stayed "available" in our data forever.

The portal (assets/js/live.js) polls api/cars.php and re-renders WITHOUT reload,
so whatever lands here shows up on screen within seconds.

Run:  python auto_sync.py
"""

import asyncio
import math
import time
import argparse
from datetime import datetime

from playwright.async_api import async_playwright

# reuse the proven, tested pieces
# (harvest_all already sets up UTF-8 stdout on Windows - don't re-wrap it)
from harvest_all import BASE, VENDORS, DB, ensure_login, fetch_page

SYNC_LOG = "auto_sync.log"

FAST_INTERVAL = 60          # seconds between fast cycles
FULL_EVERY = 10             # a full-makes cycle every N fast cycles (~10 min)
FAST_PAGES = 2              # newest pages per make in fast tier
FULL_PAGES = 3              # newest pages per make in full tier
SWEEP_PAGES = 12            # pages of the rolling sweep per cycle
SORT_NEWEST = "auct_date#desc"

# makes holding most of the inventory - checked every minute
FAST_MAKES = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '23', '24', '14', '21']


def slog(msg):
    line = f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}"
    print(line, flush=True)
    with open(SYNC_LOG, 'a', encoding='utf-8') as f:
        f.write(line + "\n")


async def sync_tier(page, db, vendor_ids, pages_per_make):
    """Re-read the newest N pages of the given makes."""
    checked = 0
    written = 0
    for vid, vname in VENDORS:
        if vendor_ids is not None and vid not in vendor_ids:
            continue
        first = await fetch_page(page, vid, 1, sort=SORT_NEWEST)
        if not first.get('ok'):
            continue
        checked += 1
        written += db.upsert(first['cars'])
        rows = first.get('rows', 0)
        max_p = min(pages_per_make, max(1, math.ceil(rows / 20)))
        for p in range(2, max_p + 1):
            res = await fetch_page(page, vid, p, sort=SORT_NEWEST)
            if res.get('ok'):
                written += db.upsert(res['cars'])
            await asyncio.sleep(0.2)
    return checked, written


class Sweep:
    """Rolling cursor over every page of every make, so all cars get refreshed."""

    def __init__(self):
        self.vi = 0          # index into VENDORS
        self.page = 1
        self.pages_of_make = None
        self.laps = 0

    async def step(self, page, db, budget):
        """Advance the cursor by `budget` pages. Returns (pages_done, written)."""
        done = 0
        written = 0
        while done < budget:
            vid, vname = VENDORS[self.vi]

            res = await fetch_page(page, vid, self.page, sort=SORT_NEWEST)
            done += 1
            if res.get('ok'):
                written += db.upsert(res['cars'])
                if self.pages_of_make is None:
                    rows = res.get('rows', 0)
                    self.pages_of_make = max(1, math.ceil(rows / 20))
            else:
                # treat a failed page as the end of this make
                self.pages_of_make = self.page

            self.page += 1
            if self.pages_of_make is not None and self.page > self.pages_of_make:
                self.vi += 1
                self.page = 1
                self.pages_of_make = None
                if self.vi >= len(VENDORS):
                    self.vi = 0
                    self.laps += 1
                    slog(f"SWEEP completed a full pass over all makes (lap {self.laps})")
            await asyncio.sleep(0.2)
        return done, written

    def where(self):
        vid, vname = VENDORS[self.vi]
        total = self.pages_of_make or '?'
        return f"{vname} p{self.page}/{total}"


async def run():
    slog("=" * 60)
    slog(f"AUTO-SYNC START | fast={FAST_INTERVAL}s ({len(FAST_MAKES)} makes) | "
         f"full every {FULL_EVERY} cycles | sweep {SWEEP_PAGES} pages/cycle")

    db = DB()
    sweep = Sweep()

    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=True)
        context = await browser.new_context(viewport={'width': 1400, 'height': 900})
        page = await context.new_page()
        await ensure_login(context, page)

        cycle = 0
        while True:
            cycle += 1
            is_full = (cycle % FULL_EVERY == 1)
            t0 = time.time()
            try:
                if is_full:
                    checked, written = await sync_tier(page, db, None, FULL_PAGES)
                    tag = f"FULL : {checked} makes, {written} cars"
                else:
                    checked, written = await sync_tier(page, db, FAST_MAKES, FAST_PAGES)
                    tag = f"fast : {checked} makes, {written} cars"

                # always give the rolling sweep some budget
                sp, sw = await sweep.step(page, db, SWEEP_PAGES)
                tag += f" | sweep {sp}p/{sw} cars @ {sweep.where()}"

                slog(f"#{cycle} {tag} in {int(time.time()-t0)}s")
            except Exception as e:
                slog(f"#{cycle} ERROR: {e} - re-establishing session")
                try:
                    await ensure_login(context, page)
                except Exception as e2:
                    slog(f"   re-login failed: {e2}")

            wait = max(5, FAST_INTERVAL - int(time.time() - t0))
            await asyncio.sleep(wait)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--interval', type=int, default=None, help='seconds between fast cycles')
    ap.add_argument('--sweep', type=int, default=None, help='sweep pages per cycle')
    args = ap.parse_args()
    global FAST_INTERVAL, SWEEP_PAGES
    if args.interval:
        FAST_INTERVAL = args.interval
    if args.sweep:
        SWEEP_PAGES = args.sweep
    asyncio.run(run())


if __name__ == '__main__':
    main()
