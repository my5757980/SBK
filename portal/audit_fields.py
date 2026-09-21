#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Field-by-field audit: what the auction feed sends vs what we store/show.

Pulls a live page from the feed, dumps EVERY raw field for a couple of cars,
then puts our stored row next to it so nothing can hide.
"""

import asyncio
import json
import html as html_mod

from playwright.async_api import async_playwright
import mysql.connector

from harvest_all import BASE, ensure_login, log

# The site's own column headers, taken from its listing template:
#   head_arr = Lot number/bid, Auction/auct_name, Auction date/auct_date,
#              V,Engine CC/eng_v, Chassis ID/kuzov, Box of Modif./kpp,
#              equipment, Mileage/probeg, Condition/rate,
#              Start/price_start, Sold for/price_finish, Colour/color
RAW_DUMP_JS = r"""
async (args) => {
  const FIELDS = ['sort_ord','url_luboy','url_lubaya','tpl','edit_post','is_stat','vendor','model','bid','kuzov','rate','status','kpp_add','colour','auct_name','_day','_rate','_status','_kpp_add','_auct_name','list_size','_list_size','lhw','eqqp','stDt1','stDt2','sanction','year','year2','probeg','probeg2','eng_v','eng_v2','price_start','price_start2','price_finish','price_finish2','_year','_year2','_probeg','_probeg2','_eng_v','_eng_v2','_price_start','_price_start2','_price_finish','_price_finish2'];
  function buildForm(pageNum, vendor) {
    const fd = new FormData();
    fd.append('url_loader', 'aj_neo?file=loader&Q=');
    fd.append('page', String(pageNum));
    fd.append('lose_time_here_buT_not_buy_servlce_for_100_usd_monthly_here_http_avto_jp', 'http://avto.jp/specification.html');
    for (const f of FIELDS) {
      if (f === 'url_luboy' || f === 'url_lubaya') fd.append(f, 'Any');
      else if (f === 'is_stat') fd.append(f, '0');
      else if (f === 'vendor') fd.append(f, vendor);
      else fd.append(f, '');
    }
    return fd;
  }
  const url = '/aj_neo?file=loader&ajx=' + (Date.now() + Math.floor(Math.random()*1000)) + '-form';
  const resp = await fetch(url, { method: 'POST', body: buildForm(args.page, args.vendor) });
  let text = await resp.text();
  text = text.replace(/<script[^>]*>/i,'').replace(/<!--/,'').replace(/\/\/-->/,'').replace(/<\/script>/i,'').trim();
  let captured = null;
  const parent = { ajx: { dataReady: (id, x, obj) => { captured = obj; } } };
  eval(text);
  if (!captured || !captured.tpl_poisk) return { ok:false };
  const data = (new Function(captured.tpl_poisk + '; return data;'))();
  return {
    ok: true,
    naviKeys: Object.keys(data.navi || {}),
    navi: data.navi,
    cars: (data.body || []).slice(0, args.n || 2),
    // anything else the payload carries besides navi/body
    topLevelKeys: Object.keys(data),
    otherPayloadKeys: Object.keys(captured)
  };
}
"""


def dec(v):
    return html_mod.unescape(str(v)) if v is not None else ''


async def main():
    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=True)
        ctx = await browser.new_context(viewport={'width': 1400, 'height': 900})
        page = await ctx.new_page()
        await ensure_login(ctx, page)

        res = await page.evaluate(RAW_DUMP_JS, {"vendor": "1", "page": 1, "n": 2})
        await browser.close()

    if not res.get('ok'):
        print("FEED FETCH FAILED")
        return

    print("=" * 78)
    print("PAYLOAD STRUCTURE")
    print("=" * 78)
    print("top-level keys in data :", res['topLevelKeys'])
    print("other payload sections :", res['otherPayloadKeys'])
    print()
    print("navi (paging/meta) keys:", res['naviKeys'])
    print()

    conn = mysql.connector.connect(host='127.0.0.1', user='root', password='', database='sbk_auction')
    cur = conn.cursor(dictionary=True)

    for idx, car in enumerate(res['cars'], 1):
        print("=" * 78)
        print("CAR %d  — EVERY RAW FIELD THE SITE SENDS" % idx)
        print("=" * 78)
        for k in sorted(car.keys(), key=lambda s: (len(s), s)):
            v = dec(car[k])
            if len(v) > 70:
                v = v[:70] + '…'
            print("  %-4s = %s" % (k, v if v != '' else '(empty)'))

        cur.execute("SELECT * FROM cars WHERE car_id = %s", (car.get('a'),))
        row = cur.fetchone()
        print()
        print("  OUR DATABASE ROW for car_id=%s" % car.get('a'))
        if not row:
            print("    !! NOT IN OUR DATABASE")
        else:
            for k, v in row.items():
                s = str(v)
                if len(s) > 70:
                    s = s[:70] + '…'
                print("    %-14s = %s" % (k, s))
        print()

    cur.close()
    conn.close()


if __name__ == '__main__':
    asyncio.run(main())
