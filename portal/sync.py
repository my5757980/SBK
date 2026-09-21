#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
SBK Auction — inventory sync (HTTP only, no browser required).

The auction feed is reachable with ordinary HTTP requests once signed in, so
this runs anywhere Python does — including cPanel shared hosting via cron.
No Playwright, no Chromium.

Modes
  --full            import every make, every page (first run; ~40-60 min)
  --cron            one chunk of work, then exit  <- what cron calls
  --forever         loop locally, same work as --cron on an interval
  --make TOYOTA     limit to one make (with --full)

A cron run does two things:
  1. FRESH  - re-reads the newest pages of the big makes (catches new arrivals
              and same-day price/sold changes)
  2. SWEEP  - advances a persistent cursor through every page of every make so
              that, over time, every car is re-read. Without this only the
              newest few pages would ever be refreshed and a car that sold
              later would stay "available" forever.

The sweep cursor lives in the `sync_state` table, so it survives between cron
invocations.

Typical cPanel cron (every 5 minutes):
    */5 * * * * cd ~/sbk-auction && /usr/bin/python3 sync.py --cron >> sync.log 2>&1
"""

import argparse
import html as html_mod
import io
import json
import math
import os
import re
import sys
import time
from datetime import datetime, timedelta

import requests
import mysql.connector
from dotenv import load_dotenv

if sys.platform == 'win32':
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

load_dotenv(os.path.join(os.path.dirname(os.path.abspath(__file__)), '.env'))

# ---------------------------------------------------------------- the source
#
# Both sites run the same software and serve the same stock, so the only thing
# that separates them is the address and whether an account is needed:
#
#   auction.carjunction.me   no account. Two sections: the auctions and the
#                            fixed-price stock.
#   nikkyocars.ajes.com      needs an account, and carries a third section -
#                            years of past auction results.
#
# The owner's plan is to run off carjunction now and move to nikkyocars once an
# account exists, so the address and the credentials both come from .env and
# nothing else in this file names either site. Swapping source is one line in
# .env; no code change, no redeploy.
#
#   SOURCE_URL=https://auction.carjunction.me
#   SOURCE_USERNAME=            <- leave blank when the source needs no login
#   SOURCE_PASSWORD=
#
# The old NIKKYOCARS_* names still work so an existing .env keeps running.
_SOURCE_SET = (os.getenv('SOURCE_URL') or '').strip()
BASE = (_SOURCE_SET or "https://nikkyocars.ajes.com").rstrip('/')

if _SOURCE_SET:
    # Naming a source means naming its credentials too. Falling back to the old
    # NIKKYOCARS_* values here would send that account's password to whichever
    # site SOURCE_URL points at - so blank really means "no login".
    SOURCE_USER = (os.getenv('SOURCE_USERNAME') or '').strip()
    SOURCE_PASS = (os.getenv('SOURCE_PASSWORD') or '').strip()
else:
    SOURCE_USER = (os.getenv('SOURCE_USERNAME') or os.getenv('NIKKYOCARS_USERNAME') or '').strip()
    SOURCE_PASS = (os.getenv('SOURCE_PASSWORD') or os.getenv('NIKKYOCARS_PASSWORD') or '').strip()

# The site's own JS lists three sections as id@slug@...@label:
#
#   1@japan          JAPANESE AUCTIONS   ~76,000 lots currently on sale
#   2@japan_st       SALES STATISTICS    ~2.2m past auction results
#   3@oneprice_mix   FIXED PRICE mix     ~255,000 fixed-price vehicles
#
# They are NOT selected by the URL you browse - visiting the section page and
# calling the usual loader returns the auctions every time. Two different
# switches are involved, found by diffing the section pages' own hidden fields:
#
#   statistics   same endpoint, is_stat=1
#   fixed price  a DIFFERENT endpoint - /on instead of /aj_neo, and the
#                url_loader field has to match it
#
# SOURCE_SECTION picks which one a run reads.
# Each section posts to its own endpoint - the page's own hidden url_loader
# field names it. is_stat alone was not enough: pointed at /aj_neo the
# statistics section answered with a single row.
SECTIONS = {
    'japan':        {'path': 'aj_neo', 'is_stat': '0'},
    # is_stat picks one of four views the statistics screen offers
    # (LIST A-D). A returns nothing on its own - it is the search view -
    # so the listing we want is B.
    'japan_st':     {'path': 'st',     'is_stat': '2'},
    'oneprice_mix': {'path': 'on',     'is_stat': '0'},
}
SECTION = (os.getenv('SOURCE_SECTION', 'japan').strip() or 'japan')
if SECTION not in SECTIONS:
    SECTION = 'japan'

UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")

PER_PAGE = 20
SORT_NEWEST  = "auct_date#desc"   # furthest-future auctions first - new listings land here
SORT_SOONEST = "auct_date#asc"    # today's auctions first - results and live prices

# how much a single --cron run does (keep modest: shared hosting kills long jobs)
                         # do_fresh reads one page from each end of every big
                         # make's list, so it costs 2 requests per make
SWEEP_PAGES = 25         # pages of the rolling sweep
REQUEST_GAP = float(os.getenv('REQUEST_GAP', '0.4'))   # seconds between requests

# The feed is sorted auct_date#desc, so the furthest-future auctions come first
# and the deeper you page the further back you go. Once a page is older than
# this, the rest of that make is settled history that will never change again -
# so the sweep moves on instead of re-reading the archive forever. The few days
# of overlap are what lets a concluded auction's result still be picked up.
SWEEP_BACK_DAYS = 3

# A lot the feed has stopped listing.
#
# The feed drops a lot the moment it leaves the sale - sold elsewhere, withdrawn,
# relisted under a new number - but an upsert-only sync keeps it for ever. That
# is why our "currently on sale" count drifted to roughly double the source's:
# carjunction showed 1,157 ALPHARD, we showed 2,296.
#
# Every upsert sets last_updated, so it doubles as "the feed last showed us this",
# and the sweep gets round every live lot in about eleven hours. Anything still
# marked available but untouched for far longer than that is gone from the sale.
STALE_HOURS = 48

# makes holding most of the inventory
BIG_MAKES = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '23', '24', '14', '21']

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

FORM_FIELDS = [
    'sort_ord','url_luboy','url_lubaya','tpl','edit_post','is_stat','vendor','model','bid',
    'kuzov','rate','status','kpp_add','colour','auct_name','_day','_rate','_status','_kpp_add',
    '_auct_name','list_size','_list_size','lhw','eqqp','stDt1','stDt2','sanction','year','year2',
    'probeg','probeg2','eng_v','eng_v2','price_start','price_start2','price_finish','price_finish2',
    '_year','_year2','_probeg','_probeg2','_eng_v','_eng_v2','_price_start','_price_start2',
    '_price_finish','_price_finish2',
]

MULTIWORD_MAKES = [
    'MERCEDES BENZ', 'ASTON MARTIN', 'LAND ROVER', 'BMW ALPINA',
    'ALFA ROMEO', 'ROLLS ROYCE',
]

LOG_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'sync.log')


def log(msg):
    line = f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}"
    print(line, flush=True)
    try:
        with open(LOG_FILE, 'a', encoding='utf-8') as f:
            f.write(line + "\n")
    except Exception:
        pass


# ---------------------------------------------------------------- feed client

class Feed:
    """Signed-in HTTP client for the auction inventory feed."""

    def __init__(self):
        self.s = requests.Session()
        self.s.headers.update({'User-Agent': UA, 'Accept-Language': 'en-US,en;q=0.9'})
        self.user = SOURCE_USER
        self.pwd = SOURCE_PASS

    def login(self, retries=3):
        """
        Sign in, surviving a dropped connection.

        fetch() already retries, but this did not: the feed closing the socket
        mid-handshake raised straight out of the cron run and killed it. That
        happens often enough to matter — the site drops connections when it is
        being read quickly — and a lost run means the portal goes stale for
        five minutes for no good reason.
        """
        # A source that needs no account (carjunction) just gets its session
        # cookie from the section page; there is nothing to sign in to.
        if not self.user or not self.pwd:
            for attempt in range(retries):
                try:
                    self.s.get(f"{BASE}/{SECTION}", timeout=30)
                    log(f"open source - no sign-in needed ({BASE}/{SECTION})")
                    return True
                except Exception as e:
                    log(f"  could not reach the source ({str(e)[:60]}) - retry {attempt + 1}/{retries}")
                    time.sleep(3 * (attempt + 1))
            return False

        for attempt in range(retries):
            try:
                self.s.get(f"{BASE}/{SECTION}", timeout=30)
                r = self.s.post(
                    f"{BASE}/set",
                    data={'username': self.user, 'password': self.pwd,
                          'is_login': '1', 'ref': 'aj_neo'},
                    headers={'Referer': f"{BASE}/{SECTION}"},
                    timeout=30,
                )
            except Exception as e:
                log(f"  sign-in error ({str(e)[:70]}) - retry {attempt + 1}/{retries}")
                time.sleep(3 * (attempt + 1))
                continue

            if 'logout' in r.text.lower():
                log("signed in")
                return True
            log("SIGN-IN FAILED - check .env credentials")
            return False

        log("SIGN-IN FAILED - could not reach the feed")
        return False

    def _form(self, vendor, page, sort, status=''):
        spec = SECTIONS.get(SECTION, SECTIONS['japan'])
        parts = [('url_loader', f"{spec['path']}?file=loader&Q="), ('page', str(page)),
                 ('lose_time_here_buT_not_buy_servlce_for_100_usd_monthly_here_http_avto_jp',
                  'http://avto.jp/specification.html')]
        for f in FORM_FIELDS:
            if f in ('url_luboy', 'url_lubaya'):
                parts.append((f, 'Any'))
            elif f == 'is_stat':
                parts.append((f, spec['is_stat']))
            elif f == 'vendor':
                parts.append((f, vendor))
            elif f == 'sort_ord':
                parts.append((f, sort or ''))
            elif f == 'status':
                parts.append((f, status or ''))
            else:
                parts.append((f, ''))
        # the site posts this as multipart/form-data
        return {k: (None, v) for k, v in parts}

    def fetch(self, vendor, page, sort='', retries=3, status=''):
        """Return (rows_total, [car dicts]) or (None, []) on failure."""
        for attempt in range(retries):
            try:
                path = SECTIONS.get(SECTION, SECTIONS['japan'])['path']
                url = f"{BASE}/{path}?file=loader&ajx={int(time.time()*1000)}-form"
                r = self.s.post(url, files=self._form(vendor, page, sort, status),
                                headers={'Referer': f"{BASE}/{SECTION}",
                                         'X-Requested-With': 'XMLHttpRequest'},
                                timeout=45)
                text = r.text
            except Exception as e:
                log(f"  fetch error ({str(e)[:70]}) - retry {attempt + 1}/{retries}")
                time.sleep(3 * (attempt + 1))
                continue

            if 'Reload page' in text or 'var data={body:[]}' in text:
                # session went stale
                log("  session expired - signing in again")
                if not self.login():
                    return None, []
                continue

            rows = None
            m = re.search(r'rows:\\?"(\d+)\\?"', text)
            if m:
                rows = int(m.group(1))
            return rows, parse_cars(text)
        return None, []


# ------------------------------------------------------------------- parsing

# letters map to the columns the site's own row template renders:
#   a key · b name · c lot · d auction · e date · f time · g year · h cc
#   i hp · j chassis · k gearbox · l grade · m equipment · n drive
#   o market average · p recent sales · q mileage · r condition
#   s start price · t sold-for · v result · w colour · x/y/z photos
CAR_RE = re.compile(r'\{a:\\?"(?P<a>[^"\\]*)\\?",(?P<rest>.*?)\}(?=,\{a:\\?"|\])', re.S)
KV_RE = re.compile(r'(?P<k>[a-z]\d?):\\?"(?P<v>(?:[^"\\]|\\.)*?)\\?"')


def _body_segment(text):
    """
    Isolate the first `body:[ ... ]` array.

    The payload carries more than one array (a second `body:` plus a `filter:`
    block), and objects in those use the same single-letter keys. Scanning the
    whole response therefore invents cars whose make is "1", "2", "3"... - so
    only the first array is ever parsed.
    """
    m = re.search(r'body\s*:\s*\[', text)
    if not m:
        return ''
    start = m.end()
    end = text.find('}]', start)
    # keep the closing ']' - CAR_RE needs it to recognise the final lot
    return text[start:end + 2] if end != -1 else text[start:]


# field names that appear in the payload's own template/sort metadata - if one
# of these turns up where a vehicle name belongs, we matched the wrong object
PAYLOAD_KEYWORDS = {
    'auct_name', 'auct_date', 'price_start', 'price_finish', 'eng_v', 'kuzov',
    'kpp', 'probeg', 'rate', 'color', 'equipment', 'bid', 'model', 'vendor',
    'year', 'status', 'lot number', 'body', 'navi', 'filter',
}


def looks_like_lot(car):
    """
    Guard against matching non-vehicle objects.

    A real lot has a long opaque key in `a` (the id used for aj-<id>.htm), a
    name in `b`, and a lot number in `c`. Template/metadata objects fail at
    least one of these - that is how `auct_name` briefly became a "make".
    """
    key = (car.get('a') or '').strip()
    name = (car.get('b') or '').strip()
    if len(key) < 10 or not re.match(r'^[A-Za-z0-9_-]+$', key):
        return False
    if not name or name.lower() in PAYLOAD_KEYWORDS:
        return False
    if not (car.get('c') or '').strip():
        return False
    # the feed writes manufacturers in capitals ("TOYOTA ALPHARD"); an
    # all-lowercase first word means we matched a field name such as
    # "colour" or "kpp_add" rather than a vehicle. This catches leaks we
    # have not seen yet, without having to enumerate every field.
    first = name.split(' ', 1)[0]
    if first and first == first.lower() and first != first.upper():
        return False
    return True


def parse_cars(text):
    """Pull the car objects out of the JS payload the feed returns."""
    out = []
    for m in CAR_RE.finditer(_body_segment(text)):
        car = {'a': m.group('a')}
        for kv in KV_RE.finditer(m.group('rest')):
            car[kv.group('k')] = kv.group('v')
        if looks_like_lot(car):
            out.append(car)
    return out


def dec(v):
    if v is None:
        return None
    return html_mod.unescape(str(v)).replace('\\"', '"').strip()


def strip_tags(v):
    return re.sub(r'<[^>]+>', '', dec(v) or '')


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


def split_make_model(title):
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
    make, model = split_make_model(dec(c.get('b')))
    photos = [p for p in (c.get('x'), c.get('y'), c.get('z')) if p and len(p) > 5]
    return {
        'car_id':        c.get('a'),
        'lot_no':        dec(c.get('c')),
        'make':          make,
        'model':         model,
        'year':          to_int(c.get('g')),
        'mileage':       to_int(c.get('q')),
        'price':         to_dec(c.get('s')),
        'currency':      'yen',
        'avg_price':     to_dec(c.get('o')),
        'sold_price':    to_dec(c.get('t')),
        'auction':       dec(c.get('d')),
        'auction_date':  dec(c.get('e')),
        'auction_time':  (dec(c.get('f')) or '').strip('[]') or None,
        'chassis':       dec(c.get('j')),
        'transmission':  dec(c.get('k')),
        'grade':         dec(c.get('l')),
        'equipment':     dec(c.get('m')) or None,
        'load_capacity': dec(c.get('n')) or None,
        'price_history': dec(c.get('p')) or None,
        'rating':        dec(c.get('r')),
        'engine_cc':     to_int(c.get('h')),
        'engine_hp':     dec(c.get('i')) or None,
        'color':         dec(c.get('w')),
        'status':        strip_tags(c.get('v')) or 'available',
        'images':        json.dumps(photos),
        'source_url':    f"{BASE}/aj-{c.get('a')}.htm",
        # which section this row came from, so auction lots and fixed-price
        # stock never get mixed together in the portal
        'source_section': SECTION,
    }


# ------------------------------------------------------------------ database

UPSERT_SQL = """
INSERT INTO cars
  (car_id, lot_no, make, model, year, mileage, price, currency, avg_price, sold_price,
   auction, auction_date, auction_time, chassis, transmission, grade, rating,
   engine_cc, engine_hp, equipment, load_capacity, price_history,
   color, status, images, source_url, source_section, last_updated, created_at)
VALUES
  (%(car_id)s, %(lot_no)s, %(make)s, %(model)s, %(year)s, %(mileage)s, %(price)s, %(currency)s, %(avg_price)s, %(sold_price)s,
   %(auction)s, %(auction_date)s, %(auction_time)s, %(chassis)s, %(transmission)s, %(grade)s, %(rating)s,
   %(engine_cc)s, %(engine_hp)s, %(equipment)s, %(load_capacity)s, %(price_history)s,
   %(color)s, %(status)s, %(images)s, %(source_url)s, %(source_section)s, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  lot_no=VALUES(lot_no), make=VALUES(make), model=VALUES(model), year=VALUES(year),
  -- A source read without an account returns the vehicle but no commercial
  -- figures - carjunction gives price 0 and mileage blank on every row. Taking
  -- those at face value would wipe good numbers we already hold and take the
  -- bid window with them, so a zero never overwrites a real value. A genuine
  -- change from one real number to another still lands normally.
  mileage=IF(VALUES(mileage) > 0, VALUES(mileage), mileage),
  price=IF(VALUES(price) > 0, VALUES(price), price),
  avg_price=IF(VALUES(avg_price) > 0, VALUES(avg_price), avg_price),
  sold_price=IF(VALUES(sold_price) > 0, VALUES(sold_price), sold_price),
  auction=VALUES(auction), auction_date=VALUES(auction_date), auction_time=VALUES(auction_time),
  chassis=VALUES(chassis), transmission=VALUES(transmission), grade=VALUES(grade),
  rating=VALUES(rating), engine_cc=VALUES(engine_cc), engine_hp=VALUES(engine_hp),
  equipment=VALUES(equipment), load_capacity=VALUES(load_capacity),
  price_history=VALUES(price_history), color=VALUES(color), status=VALUES(status),
  images=VALUES(images), source_url=VALUES(source_url), last_updated=NOW()
"""


RESULT_SQL = """
INSERT INTO auction_results
  (result_key, car_id, lot_no, make, model, year, mileage, engine_cc,
   transmission, grade, rating, color, chassis, auction, auction_date,
   auction_on, start_price, sold_price, result, images)
VALUES
  (%(result_key)s, %(car_id)s, %(lot_no)s, %(make)s, %(model)s, %(year)s,
   %(mileage)s, %(engine_cc)s, %(transmission)s, %(grade)s, %(rating)s,
   %(color)s, %(chassis)s, %(auction)s, %(auction_date)s,
   STR_TO_DATE(%(auction_date)s, '%%d.%%m.%%Y'),
   %(start_price)s, %(sold_price)s, %(result)s, %(images)s)
ON DUPLICATE KEY UPDATE
  sold_price = IF(VALUES(sold_price) > 0, VALUES(sold_price), sold_price),
  start_price = IF(VALUES(start_price) > 0, VALUES(start_price), start_price),
  result = VALUES(result)
"""


class DB:
    def __init__(self):
        self.conn = self._connect()
        self._ensure_state_table()

    @staticmethod
    def _connect():
        return mysql.connector.connect(
            host=os.getenv('MYSQL_HOST', '127.0.0.1'),
            user=os.getenv('MYSQL_USER', 'root'),
            password=os.getenv('MYSQL_PASSWORD', ''),
            database=os.getenv('MYSQL_DATABASE', 'sbk_auction'),
            autocommit=False,
        )

    def _ensure_state_table(self):
        cur = self.conn.cursor()
        cur.execute("""
            CREATE TABLE IF NOT EXISTS sync_state (
              k VARCHAR(40) PRIMARY KEY,
              v VARCHAR(255) NOT NULL,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                         ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """)
        self.conn.commit()
        cur.close()

    def reconnect(self):
        for i in range(1, 11):
            try:
                self.conn = self._connect()
                log(f"  database reconnected (attempt {i})")
                return True
            except Exception:
                time.sleep(5)
        return False

    def upsert(self, cars):
        # The statistics section is a different thing - what a vehicle sold for
        # on a day that has passed - and lands in its own table.
        if SECTION == 'japan_st':
            return self.upsert_results(cars)

        rows = [map_car(c) for c in cars if c.get('a')]
        if not rows:
            return 0
        for _ in range(2):
            try:
                cur = self.conn.cursor()
                cur.executemany(UPSERT_SQL, rows)
                self.conn.commit()
                cur.close()
                return len(rows)
            except mysql.connector.Error as e:
                log(f"  database write failed ({str(e)[:80]})")
                if not self.reconnect():
                    return 0
        return 0

    def upsert_results(self, cars):
        """
        Store rows from the statistics section as past auction results.

        These are never offered for sale, so they stay out of `cars` entirely.
        Keyed on the feed's own row key, so re-reading a page costs nothing.
        """
        rows = []
        for c in cars:
            key = c.get('a')
            if not key:
                continue
            make, model = split_make_model(dec(c.get('b')))
            photos = [p for p in (c.get('x'), c.get('y'), c.get('z')) if p and len(p) > 5]
            rows.append({
                'result_key':   key,
                'car_id':       key,
                'lot_no':       dec(c.get('c')),
                'make':         make,
                'model':        model,
                'year':         to_int(c.get('g')),
                'mileage':      to_int(c.get('q')),
                'engine_cc':    to_int(c.get('h')),
                'transmission': dec(c.get('k')),
                'grade':        dec(c.get('l')),
                'rating':       dec(c.get('r')),
                'color':        dec(c.get('w')),
                'chassis':      dec(c.get('j')),
                'auction':      dec(c.get('d')),
                'auction_date': dec(c.get('e')),
                'start_price':  to_dec(c.get('s')),
                'sold_price':   to_dec(c.get('t')),
                'result':       strip_tags(c.get('v')) or None,
                'images':       json.dumps(photos) if photos else None,
            })
        if not rows:
            return 0

        for _ in range(2):
            try:
                cur = self.conn.cursor()
                cur.executemany(RESULT_SQL, rows)
                self.conn.commit()
                cur.close()
                return len(rows)
            except mysql.connector.Error as e:
                log(f"  results write failed ({str(e)[:80]})")
                if not self.reconnect():
                    return 0
        return 0

    def retire_missing(self, hours=STALE_HOURS):
        """
        Retire lots the feed has stopped listing.

        Only ever touches rows still marked available: a lot that already has a
        result keeps it. Returns how many were retired so the run can log it.
        """
        try:
            cur = self.conn.cursor()
            cur.execute(
                "UPDATE cars SET status='removed', last_updated=last_updated "
                "WHERE status='available' AND last_updated < NOW() - INTERVAL %s HOUR",
                (hours,),
            )
            n = cur.rowcount
            self.conn.commit()
            cur.close()
            return n
        except mysql.connector.Error as e:
            log(f"  retire failed ({str(e)[:80]})")
            return 0

    @staticmethod
    def _scoped(key):
        """Keep each section's bookmarks apart.

        The three sections run as three separate cron jobs against one database.
        They used to share these keys, so whichever job ran last overwrote the
        others' place in the list - the sweep cursor was dragged back and forth
        and the smaller sections never advanced past their first few pages.
        Auctions keeps the bare key so its existing position is not lost.
        """
        return key if SECTION == 'japan' else f'{SECTION}:{key}'

    def get_state(self, key, default=''):
        cur = self.conn.cursor()
        cur.execute("SELECT v FROM sync_state WHERE k=%s", (self._scoped(key),))
        row = cur.fetchone()
        cur.close()
        return row[0] if row else default

    def set_state(self, key, value):
        cur = self.conn.cursor()
        cur.execute("INSERT INTO sync_state (k,v) VALUES (%s,%s) "
                    "ON DUPLICATE KEY UPDATE v=VALUES(v)",
                    (self._scoped(key), str(value)))
        self.conn.commit()
        cur.close()

    def car_count(self):
        cur = self.conn.cursor()
        cur.execute("SELECT COUNT(*) FROM cars")
        n = cur.fetchone()[0]
        cur.close()
        return n

    def close(self):
        try:
            self.conn.close()
        except Exception:
            pass


# --------------------------------------------------------------------- work

def do_full(feed, db, only_make=None):
    total = 0
    for vid, vname in VENDORS:
        if only_make and vname.upper() != only_make.upper():
            continue
        rows, cars = feed.fetch(vid, 1)
        if rows is None:
            log(f"[{vname}] could not read page 1 - skipping")
            continue
        pages = max(1, math.ceil(rows / PER_PAGE))
        log(f"[{vname}] {rows} cars, {pages} pages")
        total += db.upsert(cars)
        for p in range(2, pages + 1):
            _, cars = feed.fetch(vid, p)
            total += db.upsert(cars)
            if p % 25 == 0:
                log(f"[{vname}] page {p}/{pages} | total upserted {total}")
            time.sleep(REQUEST_GAP)
        log(f"[{vname}] done | total upserted {total}")
    return total


def do_fresh(feed, db):
    """
    Both ends of each big make's list, every run.

    The list is ordered by auction date, so the two ends carry the two things
    that actually move:

      auct_date#desc  page 1 - the furthest-future auctions, which is where a
                      newly listed lot lands. Catches new arrivals.
      auct_date#asc   page 1 - auctions happening today, whose price, bidding
                      and result change hour by hour. Catches results.

    This used to read two pages of #desc only, so it refreshed auctions three
    weeks out every five minutes while today's - the ones customers are
    actually bidding on - waited for the sweep to come round hours later. Same
    number of requests, pointed at both ends instead of one.

    Every make gets its newest page read, not just the big ones. A new lot in a
    small make used to wait for the sweep to reach that make, which is most of
    an hour - and since a new lot is exactly what the count is short of, that
    wait was the gap between our figure and the source's. One extra page per
    small make closes it to five minutes.
    """
    written = 0
    for vid, vname in VENDORS:
        # the far end is where a new listing lands, so every make is worth it
        sorts = (SORT_NEWEST, SORT_SOONEST) if vid in BIG_MAKES else (SORT_NEWEST,)
        for sort in sorts:
            _, cars = feed.fetch(vid, 1, sort=sort)
            if cars:
                written += db.upsert(cars)
            time.sleep(REQUEST_GAP)
    return written


def _page_is_history(cars):
    """
    True when every auction on this page is already older than the results
    window — i.e. we have paged past the live part of this make's list.
    """
    cutoff = datetime.now().date() - timedelta(days=SWEEP_BACK_DAYS)
    newest = None
    for c in cars:
        raw = (c.get('e') or '').strip()
        try:
            d = datetime.strptime(raw, '%d.%m.%Y').date()
        except ValueError:
            continue
        if newest is None or d > newest:
            newest = d
    return newest is not None and newest < cutoff


def do_sweep(feed, db, budget):
    """Advance the rolling cursor so every live car keeps getting re-read."""
    vi = int(db.get_state('sweep_vendor_index', '0') or 0)
    page = int(db.get_state('sweep_page', '1') or 1)
    pages_of_make = db.get_state('sweep_pages_of_make', '')
    pages_of_make = int(pages_of_make) if pages_of_make.isdigit() else None

    done = 0
    written = 0
    while done < budget:
        if vi >= len(VENDORS):
            vi = 0
            page = 1
            pages_of_make = None
            laps = int(db.get_state('sweep_laps', '0') or 0) + 1
            db.set_state('sweep_laps', laps)
            log(f"  sweep finished a full pass over every make (lap {laps})")

        vid, vname = VENDORS[vi]
        rows, cars = feed.fetch(vid, page, sort=SORT_NEWEST)
        done += 1
        if rows is not None:
            written += db.upsert(cars)
            if pages_of_make is None:
                pages_of_make = max(1, math.ceil(rows / PER_PAGE))
        else:
            pages_of_make = page      # treat as end of this make

        # Everything from here down this make is concluded history, which never
        # changes — paging on would just spend requests on the archive.
        if cars and _page_is_history(cars):
            vi += 1
            page = 1
            pages_of_make = None
            time.sleep(REQUEST_GAP)
            continue

        page += 1
        if pages_of_make is not None and page > pages_of_make:
            vi += 1
            page = 1
            pages_of_make = None
        time.sleep(REQUEST_GAP)

    db.set_state('sweep_vendor_index', vi)
    db.set_state('sweep_page', page)
    db.set_state('sweep_pages_of_make', pages_of_make if pages_of_make is not None else '')
    where = f"{VENDORS[min(vi, len(VENDORS)-1)][1]} p{page}"
    return done, written, where


# The outcomes the feed reports once a lot has been through the hall. Asking the
# auctions section for these directly is far cheaper than waiting for the blind
# sweep to come round to the lot again: the whole point of the portal is that a
# buyer sees a result as soon as the source has one.
RESULT_STATUSES = ('sold', 'not sold', 'sold by nego')


def do_results(feed, db, budget):
    """Read the concluded lots straight from the feed's own status filter.

    The sweep walks every page of every make in turn and takes over an hour to
    come back round. A lot's result, though, only ever appears in one of three
    status buckets, and those buckets are small - so a short pass over them
    catches results within minutes instead of within a lap.
    """
    if SECTION != 'japan':
        return 0, 0

    idx = int(db.get_state('res_index', '0') or 0)
    page = int(db.get_state('res_page', '1') or 1)
    pages_here = db.get_state('res_pages_here', '')
    pages_here = int(pages_here) if pages_here.isdigit() else None

    combos = [(v, s) for v, _ in VENDORS for s in RESULT_STATUSES]
    done = written = 0
    while done < budget:
        if idx >= len(combos):
            idx, page, pages_here = 0, 1, None
        vendor, status = combos[idx]
        rows, cars = feed.fetch(vendor, page, sort=SORT_SOONEST, status=status)
        done += 1
        if rows is not None:
            written += db.upsert(cars)
            if pages_here is None:
                pages_here = max(1, math.ceil(rows / PER_PAGE))
                # A result never changes once the hammer has fallen, so a bucket
                # whose total is what it was last time holds nothing new and its
                # remaining pages are not worth reading. Only the buckets that
                # actually grew get walked - which is what lets the pass come
                # round in minutes rather than in an hour and a half.
                seen = db.get_state(f'res_rows:{vendor}:{status}', '')
                if seen.isdigit() and int(seen) == rows:
                    pages_here = 1
                db.set_state(f'res_rows:{vendor}:{status}', rows)
        else:
            pages_here = page

        page += 1
        if pages_here is not None and page > pages_here:
            idx += 1
            page = 1
            pages_here = None
        time.sleep(REQUEST_GAP)

    db.set_state('res_index', idx)
    db.set_state('res_page', page)
    db.set_state('res_pages_here', pages_here if pages_here is not None else '')
    return done, written


def run_cron(feed, db, sweep_budget, result_budget=0):
    t0 = time.time()
    fresh = do_fresh(feed, db)
    res_pages, res_written = do_results(feed, db, result_budget)
    swept, sweep_written, where = do_sweep(feed, db, sweep_budget)
    retired = db.retire_missing()
    db.set_state('last_run', datetime.now().strftime('%Y-%m-%d %H:%M:%S'))
    log(f"fresh {fresh} cars"
        + (f" | results {res_pages}p/{res_written} cars" if res_pages else "")
        + f" | sweep {swept}p/{sweep_written} cars @ {where}"
        + (f" | retired {retired}" if retired else "")
        + f" | db {db.car_count():,} | {int(time.time()-t0)}s")


def main():
    ap = argparse.ArgumentParser(description="SBK Auction inventory sync (HTTP only)")
    ap.add_argument('--full', action='store_true', help='import every make and page')
    ap.add_argument('--cron', action='store_true', help='one chunk of work, then exit')
    ap.add_argument('--forever', action='store_true', help='loop locally')
    ap.add_argument('--interval', type=int, default=300, help='seconds between loops (--forever)')
    ap.add_argument('--sweep', type=int, default=SWEEP_PAGES, help='sweep pages per run')
    ap.add_argument('--results', type=int, default=0,
                    help='pages of concluded lots to read per run (auctions only)')
    ap.add_argument('--make', default=None, help='limit --full to one make')
    args = ap.parse_args()

    if not (args.full or args.cron or args.forever):
        ap.print_help()
        return

    feed = Feed()
    if not feed.login():
        sys.exit(1)
    db = DB()

    try:
        if args.full:
            log("FULL IMPORT starting")
            n = do_full(feed, db, args.make)
            log(f"FULL IMPORT done - {n} rows upserted, {db.car_count():,} cars in database")
        elif args.cron:
            run_cron(feed, db, args.sweep, args.results)
        elif args.forever:
            log(f"LOOP starting (every {args.interval}s, sweep {args.sweep} pages)")
            while True:
                try:
                    run_cron(feed, db, args.sweep, args.results)
                except Exception as e:
                    log(f"cycle error: {str(e)[:120]}")
                    feed.login()
                time.sleep(args.interval)
    finally:
        db.close()


if __name__ == '__main__':
    main()
