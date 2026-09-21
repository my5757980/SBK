# SBK Auction — deploying to a server

This portal mirrors live Japanese auction inventory. Two things have to run:

| Piece | What it is | Needs |
|---|---|---|
| **Portal** | PHP pages + JSON API | Apache/Nginx + PHP 8 + MySQL |
| **Sync worker** | `auto_sync.py` — pulls inventory continuously | Python 3.10+ **and a headless browser** |

> **Shared hosting will not work.** The sync worker drives a real browser
> (Playwright/Chromium) to read the auction feed. You need a **VPS** where you
> can install packages and run a background service.
> The portal alone would run on shared hosting, but then nothing would keep the
> data fresh.

---

## 1. What the server needs

```bash
# Ubuntu / Debian
sudo apt update
sudo apt install -y apache2 php php-mysqli php-mbstring \
                    mariadb-server python3 python3-pip python3-venv git
```

PHP 8.0+ with `mysqli` and `mbstring`. MariaDB 10.4+ or MySQL 5.7+.

---

## 2. Get the code

```bash
cd /var/www
sudo git clone https://github.com/MohsinRaza2000/sbk-auction.git
sudo chown -R www-data:www-data sbk-auction
cd sbk-auction
```

---

## 3. Database

```bash
sudo mysql -e "CREATE DATABASE sbk_auction CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'sbk_user'@'localhost' IDENTIFIED BY 'a-strong-password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON sbk_auction.* TO 'sbk_user'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

# schema
sudo mysql sbk_auction < database/schema.sql
```

If you were given a data dump (`sbk_auction_dump.sql.gz`), load it and you start
with the full inventory instead of an empty table:

```bash
gunzip -c sbk_auction_dump.sql.gz | sudo mysql sbk_auction
```

Without a dump, the first `harvest_all.py` run (step 6) builds it from scratch —
allow roughly 45–60 minutes for ~85,000 vehicles.

---

## 4. Configuration

```bash
cp .env.example .env
nano .env          # fill in the auction account + the MySQL user above
```

Also set the site URL in `includes/config.php`:

```php
define('DB_USER', 'sbk_user');
define('DB_PASS', 'a-strong-password');
define('SITE_URL', 'https://your-domain.com/');
```

Create the admin account. There is deliberately **no default admin password in
this repository** — you set it here, and only the bcrypt hash is stored:

```bash
php setup_admin.php admin "YourStrongPassword" "Administrator"
```

Run the same command again any time you want to reset the password.

---

## 5. Web server

`/etc/apache2/sites-available/sbk.conf`:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/sbk-auction

    <Directory /var/www/sbk-auction>
        AllowOverride All
        Require all granted
    </Directory>

    # keep secrets and tooling out of the web root
    <FilesMatch "^\.env">
        Require all denied
    </FilesMatch>
    <FilesMatch "\.(py|log|out|md)$">
        Require all denied
    </FilesMatch>

    ErrorLog  ${APACHE_LOG_DIR}/sbk-error.log
    CustomLog ${APACHE_LOG_DIR}/sbk-access.log combined
</VirtualHost>
```

```bash
sudo a2ensite sbk && sudo a2enmod rewrite && sudo systemctl reload apache2

# HTTPS (free)
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d your-domain.com
```

---

## 6. Sync worker

```bash
cd /var/www/sbk-auction
python3 -m venv .venv
.venv/bin/pip install -r requirements-sync.txt
.venv/bin/python -m playwright install --with-deps chromium
```

First full import (once):

```bash
.venv/bin/python harvest_all.py
```

Then run it forever as a service — `/etc/systemd/system/sbk-sync.service`:

```ini
[Unit]
Description=SBK Auction inventory sync
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/sbk-auction
ExecStart=/var/www/sbk-auction/.venv/bin/python auto_sync.py --sweep 55
Restart=always
RestartSec=30

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now sbk-sync
sudo systemctl status sbk-sync
journalctl -u sbk-sync -f        # watch it work
```

`Restart=always` means the worker comes back on its own after a crash, a network
drop, or a server reboot.

---

## 7. Check it worked

```bash
# inventory is filling
mysql -u sbk_user -p sbk_auction -e "SELECT COUNT(*) FROM cars;"

# API responds
curl -s https://your-domain.com/api/cars.php?version=1

# sync is cycling (a line every ~60s)
journalctl -u sbk-sync -n 5
```

Then open the site:

- Portal — `https://your-domain.com/`
- Staff sign-in — footer link, or `https://your-domain.com/admin/login.php`

---

## How the sync keeps up

| Tier | Interval | Covers |
|---|---|---|
| Fast | 60 s | Newest pages of the 13 largest makes — new arrivals |
| Full | ~10 min | Newest pages of all 68 makes |
| Sweep | continuous | Walks **every** page of **every** make, then starts again |

The sweep is what stops data going stale: without it only the newest ~60 cars
per make were ever re-read, so a car that sold later stayed "available" forever.
At `--sweep 55` a complete pass over ~85,000 vehicles takes roughly 80 minutes,
then it loops.

Lower the number if you want to be gentler on the source; raise it to refresh faster.

---

## Keeping it healthy

```bash
sudo systemctl restart sbk-sync      # restart worker
journalctl -u sbk-sync --since "1 hour ago"

# nightly backup
mysqldump -u sbk_user -p sbk_auction | gzip > ~/sbk_$(date +%F).sql.gz
```

**If the portal shows no cars:** check `sbk-sync` is running and that `.env`
credentials are right — `journalctl -u sbk-sync -n 50` will say if sign-in failed.

**If photos don't load:** they are served from the auction CDN
(`https://8.ajes.com/imgs/...`). The server needs outbound HTTPS.

---

## Updating later

```bash
cd /var/www/sbk-auction
sudo -u www-data git pull
sudo systemctl restart sbk-sync
```

`.env` and the database are untouched by a pull.
