#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
HARVEST ALL - Nikkyocars complete data via internal data feed
Uses the site's OWN loader endpoint (authenticated) = 100% same data as website.
Logs in -> loops every make (vendor) -> every page -> upserts to MySQL.

Usage:
  python harvest_all.py            # full run (all makes)
  python harvest_all.py --test     # only 1 small make, 2 pages (quick verify)
  python harvest_all.py --vendor 1 # only Toyota
"""

import asyncio
import sys
import io
import os
import math
import time
import json
import argparse
import html as html_mod
from datetime import datetime

from playwright.async_api import async_playwright
import mysql.connector
from dotenv import load_dotenv

if sys.platform == 'win32':
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

load_dotenv('.env')

BASE = "https://nikkyocars.ajes.com"
LOG_FILE = "harvest.log"

# All makes (vendor id -> name). id 0 = Any (skipped; we loop real makes)
VENDORS = [
    ("1","TOYOTA"),("2","NISSAN"),("3","MAZDA"),("4","MITSUBISHI"),("5","HONDA"),
    ("6","SUZUKI"),("7","SUBARU"),("8","ISUZU"),("9","DAIHATSU"),("10","MITSUOKA"),
    ("23","LEXUS"),("11","ACURA"),("12","ALFAROMEO"),("112","ASTON MARTIN"),("13","AUDI"),
    ("115","BENTLEY"),("14","BMW"),("117","BMW ALPINA"),("118","BUICK"),("119","CADILLAC"),
    ("337","CATERPILLAR"),("121","CHEVROLET"),("15","CHRYSLER"),("16","CITROEN"),("124","DODGE"),
    ("126","FERRARI"),("18","FIAT"),("19","FORD"),("214","FRUEHAUF"),("20","GM"),
    ("128","GMC"),("339","HANIX"),("21","HINO"),("340","HITACHI"),("129","HUMMER"),
    ("130","HYUNDAI"),("341","ISEKI"),("132","JAGUAR"),("234","JEEP"),("236","KAWASAKI"),
    ("241","KIA"),("342","KOBELCO"),("343","KOMATSU"),("344","KUBOTA"),("134","LAMBORGHINI"),
    ("135","LANCIA"),("34","LAND ROVER"),("35","LINCOLN"),("137","MASERATI"),("255","MAYBACH"),
    ("24","MERCEDES BENZ"),("36","MERCURY"),("139","MINI"),("26","PEUGEOT"),("148","PORSCHE"),
    ("27","RENAULT"),("28","ROVER"),("150","SAAB"),("291","SMART"),("345","SUMITOMO"),
    ("346","TADANO"),("30","TCM"),("348","TESLA"),("31","VOLKSWAGEN"),("32","VOLVO"),
    ("326","YAMAHA"),("347","YANMAR"),("98","OTHERS"),
]

# JS that fetches ONE page for a given vendor and returns parsed cars + navi.
FETCH_JS = r"""
async (args) => {
  const FIELDS = ['sort_ord','url_luboy','url_lubaya','tpl','edit_post','is_stat','vendor','model','bid','kuzov','rate','status','kpp_add','colour','auct_name','_day','_rate','_status','_kpp_add','_auct_name','list_size','_list_size','lhw','eqqp','stDt1','stDt2','sanction','year','year2','probeg','probeg2','eng_v','eng_v2','price_start','price_start2','price_finish','price_finish2','_year','_year2','_probeg','_probeg2','_eng_v','_eng_v2','_price_start','_price_start2','_price_finish','_price_finish2'];
  function buildForm(pageNum, vendor, sort) {
    const fd = new FormData();
    fd.append('url_loader', 'aj_neo?file=loader&Q=');
    fd.append('page', String(pageNum));
    fd.append('lose_time_here_buT_not_buy_servlce_for_100_usd_monthly_here_http_avto_jp', 'http://avto.jp/specification.html');
    for (const f of FIELDS) {
      if (f === 'url_luboy' || f === 'url_lubaya') fd.append(f, 'Any');
      else if (f === 'is_stat') fd.append(f, '0');
      else if (f === 'vendor') fd.append(f, vendor);
      else if (f === 'sort_ord') fd.append(f, sort || '');
      else fd.append(f, '');
    }
    return fd;
  }
  const url = '/aj_neo?file=loader&ajx=' + (Date.now() + Math.floor(Math.random()*1000)) + '-form';
  const resp = await fetch(url, { method: 'POST', body: buildForm(args.page, args.vendor, args.sort) });
  let text = await resp.text();
  text = text.replace(/<script[^>]*>/i,'').replace(/<!--/,'').replace(/\/\/-->/,'').replace(/<\/script>/i,'').trim();
  let captured = null;
  const parent = { ajx: { dataReady: (id, x, obj) => { captured = obj; } } };
  try { eval(text); } catch(e) { return { ok:false, reason:'wrapper', raw:text.substring(0,200) }; }
  if (!captured || !captured.tpl_poisk) return { ok:false, reason:'nodata', raw:text.substring(0,200) };
  if (/var data=\{body:\[\]\}/.test(captured.tpl_poisk)) return { ok:false, reason:'reload' };
  let data;
  try { data = (new Function(captured.tpl_poisk + '; return data;'))(); }
  catch(e) { return { ok:false, reason:'parse:'+e.message }; }
  // letter -> meaning taken from the site's own row template (tpl_poisk):
  //   a car key · b name · c lot · d auction · e date · f time · g year
  //   h engine cc · i engine hp · j chassis · k gearbox · l grade
  //   m equipment · n load · o average price · p recent sale prices
  //   q mileage · r condition · s start price · t sold-for · v result
  //   w colour · x/y/z photos
  const cars = (data.body || []).map(c => ({
    car_id: c.a, title: c.b, lot: c.c, auction: c.d, date: c.e, time: c.f,
    year: c.g, engine_cc: c.h, engine_hp: c.i, chassis: c.j, transmission: c.k,
    grade: c.l, equipment: c.m, load: c.n,
    avg_price: c.o, price_history: c.p,
    mileage: c.q, rating: c.r, start_price: c.s, sold_price: c.t,
    status: (c.v||'').replace(/<[^>]+>/g,''), color: c.w,
    img1: c.x, img2: c.y, img3: c.z
  }));
  return { ok:true, rows: parseInt(data.navi.rows)||0, page: parseInt(data.navi.page)||args.page, curr: data.navi.curr, cars };
}
"""


def log(msg):
    line = f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}"
    print(line, flush=True)
    with open(LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(line + "\n")


def get_db():
    return mysql.connector.connect(
        host=os.getenv('MYSQL_HOST', 'localhost'),
        user=os.getenv('MYSQL_USER', 'root'),
        password=os.getenv('MYSQL_PASSWORD', ''),
        database=os.getenv('MYSQL_DATABASE', 'sbk_auction'),
        autocommit=False,
    )


def to_int(v):
    try:
        return int(str(v).replace(',', '').strip())
    except Exception:
        return None


def to_dec(v):
    try:
        return float(str(v).replace(',', '').strip())
    except Exception:
        return None


UPSERT_SQL = """
INSERT INTO cars
  (car_id, lot_no, make, model, year, mileage, price, currency, avg_price, sold_price,
   auction, auction_date, auction_time, chassis, transmission, grade, rating,
   engine_cc, engine_hp, equipment, load_capacity, price_history,
   color, status, images, source_url, last_updated, created_at)
VALUES
  (%(car_id)s, %(lot_no)s, %(make)s, %(model)s, %(year)s, %(mileage)s, %(price)s, %(currency)s, %(avg_price)s, %(sold_price)s,
   %(auction)s, %(auction_date)s, %(auction_time)s, %(chassis)s, %(transmission)s, %(grade)s, %(rating)s,
   %(engine_cc)s, %(engine_hp)s, %(equipment)s, %(load_capacity)s, %(price_history)s,
   %(color)s, %(status)s, %(images)s, %(source_url)s, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  lot_no=VALUES(lot_no), make=VALUES(make), model=VALUES(model), year=VALUES(year),
  mileage=VALUES(mileage), price=VALUES(price),
  avg_price=VALUES(avg_price), sold_price=VALUES(sold_price),
  auction=VALUES(auction), auction_date=VALUES(auction_date), auction_time=VALUES(auction_time),
  chassis=VALUES(chassis), transmission=VALUES(transmission), grade=VALUES(grade),
  rating=VALUES(rating), engine_cc=VALUES(engine_cc), engine_hp=VALUES(engine_hp),
  equipment=VALUES(equipment), load_capacity=VALUES(load_capacity),
  price_history=VALUES(price_history), color=VALUES(color), status=VALUES(status),
  images=VALUES(images), source_url=VALUES(source_url), last_updated=NOW()
"""


def dec(v):
    """Decode HTML entities (site encodes Japanese as &#NNN;)."""
    if v is None:
        return None
    return html_mod.unescape(str(v)).strip()


# makes whose name is more than one word - split on these before falling back
# to "first word is the make" (otherwise MERCEDES BENZ S CLASS becomes
# make=MERCEDES / model=BENZ S CLASS)
MULTIWORD_MAKES = [
    'MERCEDES BENZ', 'ASTON MARTIN', 'LAND ROVER', 'BMW ALPINA',
    'ALFA ROMEO', 'ROLLS ROYCE',
]


def split_make_model(title):
    """Return (make, model) for a feed title."""
    t = (title or '').strip()
    up = t.upper()
    for mk in MULTIWORD_MAKES:
        if up == mk:
            return mk, ''
        if up.startswith(mk + ' '):
            return mk, t[len(mk):].strip()
    parts = t.split(' ', 1)
    return (parts[0] if parts else ''), (parts[1] if len(parts) > 1 else '')


def map_car(c):
    title = dec(c.get('title'))
    make, model = split_make_model(title)
    return {
        'car_id': c.get('car_id'),
        'lot_no': c.get('lot'),
        'make': make,
        'model': model,
        'year': to_int(c.get('year')),
        'mileage': to_int(c.get('mileage')),
        'price': to_dec(c.get('start_price')),
        'currency': 'yen',
        'avg_price': to_dec(c.get('avg_price')),      # feed 'o' - market average
        'sold_price': to_dec(c.get('sold_price')),    # feed 't' - hammer price
        'auction': dec(c.get('auction')),
        'auction_date': c.get('date'),
        'auction_time': dec(c.get('time')).strip('[]') or None,
        'chassis': dec(c.get('chassis')),
        'transmission': dec(c.get('transmission')),
        'grade': dec(c.get('grade')),
        'equipment': dec(c.get('equipment')) or None,
        'load_capacity': dec(c.get('load')) or None,
        'price_history': dec(c.get('price_history')) or None,
        'rating': c.get('rating'),
        'engine_cc': to_int(c.get('engine_cc')),
        'engine_hp': dec(c.get('engine_hp')) or None,
        'color': dec(c.get('color')),
        'status': dec(c.get('status')) or 'available',
        'images': json.dumps([x for x in [c.get('img1'), c.get('img2'), c.get('img3')] if x]),
        'source_url': f"{BASE}/aj-{c.get('car_id')}.htm",
    }


class DB:
    """MySQL wrapper that survives the server restarting mid-run."""

    def __init__(self):
        self.conn = get_db()

    def _reconnect(self):
        for attempt in range(1, 31):  # keep trying up to ~5 min
            try:
                self.conn = get_db()
                log(f"DB reconnected (attempt {attempt})")
                return True
            except Exception:
                time.sleep(10)
        return False

    def upsert(self, cars):
        if not cars:
            return 0
        rows = [map_car(c) for c in cars if c.get('car_id')]
        if not rows:
            return 0
        for attempt in (1, 2):
            try:
                cur = self.conn.cursor()
                cur.executemany(UPSERT_SQL, rows)
                self.conn.commit()
                cur.close()
                return len(rows)
            except mysql.connector.Error as e:
                log(f"DB write failed ({e}) - reconnecting...")
                if not self._reconnect():
                    log("DB unreachable - dropping this batch")
                    return 0
        return 0

    def close(self):
        try:
            self.conn.close()
        except Exception:
            pass


def upsert_cars(conn, cars):
    """Backwards-compatible helper: accepts a DB wrapper or raw connection."""
    if isinstance(conn, DB):
        return conn.upsert(cars)
    if not cars:
        return 0
    cur = conn.cursor()
    rows = [map_car(c) for c in cars if c.get('car_id')]
    cur.executemany(UPSERT_SQL, rows)
    conn.commit()
    cur.close()
    return len(rows)


async def ensure_login(context, page):
    await page.goto(f"{BASE}/japan", wait_until='domcontentloaded', timeout=45000)
    await asyncio.sleep(2)
    body = await page.inner_text('body')
    if 'logout' in body.lower():
        log("Already logged in")
        return True

    user = os.getenv('NIKKYOCARS_USERNAME')
    pwd = os.getenv('NIKKYOCARS_PASSWORD')
    log(f"Logging in as {user} (form POST /set)...")
    try:
        await page.fill('#form_auth input[name="username"]', user)
        await page.fill('#form_auth input[name="password"]', pwd)
        # submit the login form -> POST /set -> establishes rolling session
        await page.evaluate("() => document.getElementById('form_auth').submit()")
        await page.wait_for_load_state('domcontentloaded')
        await asyncio.sleep(3)
        body = await page.inner_text('body')
        if 'logout' in body.lower():
            log("Login successful")
            return True
        log("Login FAILED - check credentials in .env")
        return False
    except Exception as e:
        log(f"Login error: {e}")
        return False


async def fetch_page(page, vendor, page_num, sort='', retries=3):
    for attempt in range(retries):
        try:
            res = await page.evaluate(FETCH_JS, {"vendor": vendor, "page": page_num, "sort": sort})
        except Exception as e:
            # network blip / renderer hiccup - back off and retry rather than
            # letting one bad page kill an hour-long run
            log(f"fetch error (attempt {attempt + 1}/{retries}): {str(e)[:120]}")
            await asyncio.sleep(5 * (attempt + 1))
            try:
                await page.goto(f"{BASE}/japan", wait_until='domcontentloaded', timeout=45000)
                await asyncio.sleep(1)
            except Exception:
                pass
            continue
        if res.get('ok'):
            return res
        reason = res.get('reason')
        if reason == 'reload':
            # session hiccup - reload page context then retry
            await asyncio.sleep(2)
            await page.goto(f"{BASE}/japan", wait_until='domcontentloaded')
            await asyncio.sleep(1)
        else:
            await asyncio.sleep(1)
    return {'ok': False, 'reason': 'exhausted'}


def db_count_for_make(conn, make_name):
    """How many cars of this make we already stored (for resume)."""
    try:
        cur = conn.conn.cursor()
        cur.execute("SELECT COUNT(*) FROM cars WHERE make = %s", (make_name,))
        n = cur.fetchone()[0]
        cur.close()
        return n
    except Exception:
        return 0


async def harvest(test=False, only_vendor=None, resume=False):
    vendors = VENDORS
    if only_vendor:
        vendors = [v for v in VENDORS if v[0] == only_vendor]
    if test:
        vendors = [("10", "MITSUOKA")]  # tiny make

    conn = DB()
    grand_total = 0
    t0 = time.time()

    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=False)
        context = await browser.new_context(viewport={'width': 1600, 'height': 900})
        page = await context.new_page()

        await ensure_login(context, page)

        for vid, vname in vendors:
          try:
            first = await fetch_page(page, vid, 1)
            if not first.get('ok'):
                log(f"[{vname}] FAILED page 1: {first.get('reason')}")
                continue
            rows = first.get('rows', 0)
            total_pages = max(1, math.ceil(rows / 20))
            if test:
                total_pages = min(total_pages, 2)

            # resume: skip makes we already have (within 1%) - survives power cuts
            if resume and rows > 0:
                have = db_count_for_make(conn, vname)
                if have >= rows * 0.99:
                    log(f"[{vname}] already complete ({have}/{rows}) - skipping")
                    grand_total += 0
                    continue
                if have > 0:
                    log(f"[{vname}] resuming ({have}/{rows} already stored)")

            log(f"[{vname}] {rows} cars, {total_pages} pages")

            n = upsert_cars(conn, first['cars'])
            grand_total += n

            for p in range(2, total_pages + 1):
                res = await fetch_page(page, vid, p)
                if not res.get('ok'):
                    log(f"[{vname}] page {p} failed: {res.get('reason')} - skipping")
                    continue
                n = upsert_cars(conn, res['cars'])
                grand_total += n
                if p % 25 == 0:
                    log(f"[{vname}] page {p}/{total_pages} | grand total upserted: {grand_total}")
                await asyncio.sleep(0.4)  # be gentle

            log(f"[{vname}] DONE. Grand total so far: {grand_total}")

          except Exception as e:
            # one make failing must not end the run - move on to the next
            log(f"[{vname}] ERROR: {str(e)[:150]} - skipping to next make")
            try:
                await page.goto(f"{BASE}/japan", wait_until='domcontentloaded', timeout=45000)
                await asyncio.sleep(2)
            except Exception:
                pass

        await browser.close()

    conn.close()
    dt = int(time.time() - t0)
    log(f"HARVEST COMPLETE: {grand_total} cars upserted in {dt}s")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--test', action='store_true')
    ap.add_argument('--vendor', default=None)
    ap.add_argument('--resume', action='store_true', help='skip makes already fully stored')
    args = ap.parse_args()
    log("=" * 60)
    log(f"HARVEST START (test={args.test}, vendor={args.vendor}, resume={args.resume})")
    asyncio.run(harvest(test=args.test, only_vendor=args.vendor, resume=args.resume))


if __name__ == '__main__':
    main()
