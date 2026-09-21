# SBK Auction Portal

A buyer-facing portal for Japanese vehicle auctions. It mirrors live inventory
from the auction feed into MySQL and keeps it current on its own, so clients
always see the same lots, grades, prices and results that appear on the block.

- **~85,000 vehicles**, 68 manufacturers, every major Japanese auction house
- Real auction photos, lot numbers, inspection grades and hammer prices
- Updates continuously — the listing refreshes itself without a page reload
- Client accounts, orders and inquiries, plus a staff admin panel

---

## How it fits together

```
   nikkyocars auction feed
             │
   auto_sync.py  (Python + Playwright)      ← runs forever
             │  signs in, reads inventory
             ▼
        MySQL  sbk_auction.cars
             │
    ┌────────┴─────────┐
    ▼                  ▼
 index.php        api/cars.php   ← JSON + a cheap change hash
 (server render)       │
                       ▼
                 assets/js/live.js  ← polls, re-renders in place
```

`live.js` asks the API for a small version hash every few seconds. When the hash
changes — a new lot, a price move, a car marked sold — it re-renders the grid
without reloading the page.

---

## Layout

| Path | What it is |
|---|---|
| `index.php` | Inventory listing — search, filters, sorting, live updates |
| `car-details.php` | One vehicle: gallery, auction record, full specification |
| `login.php` / `register.php` | Client accounts |
| `dashboard-client.php` | Client's orders and inquiries |
| `order.php` | Place an order against a lot |
| `admin/` | Staff panel — overview, vehicles, orders, clients |
| `api/cars.php` | JSON feed for the live front end |
| `includes/` | Config, shared functions, page chrome |
| `assets/` | Stylesheet, `live.js` |
| `harvest_all.py` | One-shot full import of every make |
| `auto_sync.py` | The long-running sync worker |
| `database/schema.sql` | Table definitions |
| `DEPLOYMENT.md` | **Putting this on a server — start here** |

---

## Running it locally

Requires PHP 8 with `mysqli`, MySQL/MariaDB, and Python 3.10+.

```bash
cp .env.example .env      # fill in the auction account + database
mysql sbk_auction < database/schema.sql

pip install -r requirements-sync.txt
python -m playwright install chromium

python harvest_all.py     # first full import (~45-60 min)
python auto_sync.py       # then keep it current
```

Create an admin account (nothing is hardcoded — you choose the password):

```bash
php setup_admin.php admin "YourStrongPassword"
```

Point a web server at the project root and open `index.php`.

For a real deployment — service files, HTTPS, backups — see **[DEPLOYMENT.md](DEPLOYMENT.md)**.

---

## How the sync stays current

| Tier | Interval | What it covers |
|---|---|---|
| Fast | 60 s | Newest pages of the 13 biggest makes — catches new arrivals |
| Full | ~10 min | Newest pages of all 68 makes |
| Sweep | continuous | Walks **every** page of **every** make, then starts over |

The sweep matters: without it only the newest ~60 cars per make were ever
re-read, so a vehicle that sold after we first saw it stayed "available" in our
data indefinitely. With it, every car is revisited on a predictable cycle
(~80 minutes for the full inventory at the default rate).

The worker survives network blips, a MySQL restart, and a lost session — it
reconnects and signs in again rather than dying.

---

## Notes

- **Credentials** live in `.env`, which is git-ignored. Admin passwords are
  bcrypt hashes in the `admins` table, never in a file.
- **Photos** come from the auction CDN, which serves only two sizes
  (`&h=50` and `&w=320`); `carImageUrl()` snaps requests to a supported one.
- **Access** — the feed requires a valid auction account. This portal reads the
  same data that account can already see in a browser; it is a mirror for the
  account holder's own clients, not a public scraper.

---

## Requirements

```
PHP 8.0+  (mysqli, mbstring)
MySQL 5.7+ / MariaDB 10.4+
Python 3.10+  (see requirements-sync.txt)
Chromium via Playwright — the sync worker drives a real browser,
so shared hosting will not run it. A small VPS is enough.
```
