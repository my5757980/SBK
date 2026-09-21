#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
One-off backfill: re-split make/model for multi-word manufacturers.

Rows imported before the fix have e.g. make='MERCEDES', model='BENZ S CLASS'.
This rebuilds the original title and splits it correctly.
"""

import mysql.connector
import os
from dotenv import load_dotenv
from harvest_all import split_make_model, MULTIWORD_MAKES

load_dotenv('.env')

conn = mysql.connector.connect(
    host=os.getenv('MYSQL_HOST', '127.0.0.1'),
    user=os.getenv('MYSQL_USER', 'root'),
    password=os.getenv('MYSQL_PASSWORD', ''),
    database=os.getenv('MYSQL_DATABASE', 'sbk_auction'),
)
cur = conn.cursor()

# first words of the multi-word makes are the ones stored wrongly
first_words = sorted({m.split(' ')[0] for m in MULTIWORD_MAKES})
placeholders = ','.join(['%s'] * len(first_words))
cur.execute(
    "SELECT id, make, model FROM cars WHERE make IN (%s)" % placeholders,
    first_words,
)
rows = cur.fetchall()
print("candidate rows:", len(rows))

fixed = 0
for cid, make, model in rows:
    title = (make + ' ' + (model or '')).strip()
    new_make, new_model = split_make_model(title)
    if new_make != make:
        cur.execute(
            "UPDATE cars SET make=%s, model=%s WHERE id=%s",
            (new_make, new_model, cid),
        )
        fixed += 1

conn.commit()
print("rows corrected:", fixed)

cur.execute("""
    SELECT make, COUNT(*) n FROM cars
    WHERE make LIKE '%% %%' OR make IN ('MERCEDES','LAND','ASTON')
    GROUP BY make ORDER BY n DESC LIMIT 10
""")
print("\nafter fix:")
for m, n in cur.fetchall():
    print("   %-16s %d" % (m, n))

cur.close()
conn.close()
