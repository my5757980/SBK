#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Read the site's OWN row template to learn what every feed letter means,
instead of guessing from sample values.
"""

import asyncio
import re
import html as html_mod

from playwright.async_api import async_playwright
from harvest_all import BASE, ensure_login


async def main():
    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=True)
        ctx = await browser.new_context(viewport={'width': 1400, 'height': 900})
        page = await ctx.new_page()
        await ensure_login(ctx, page)

        # the listing row template lives in the page as a template string
        tpl = await page.evaluate(r"""
        () => {
          const out = [];
          // templates are stashed in textareas / script blocks
          document.querySelectorAll('textarea, script[type="text/html"]').forEach(el => {
            const t = el.value || el.textContent || '';
            if (t.includes('${b.') && t.includes('for b in body')) {
              out.push({ id: el.id || el.name || '(unnamed)', len: t.length, body: t });
            }
          });
          return out;
        }
        """)

        # also grab the column headers the site declares
        heads = await page.evaluate(r"""
        () => {
          const html = document.documentElement.outerHTML;
          const m = html.match(/var head_arr\s*=\s*new Array\(([\s\S]{0,1200}?)\);/);
          return m ? m[1] : null;
        }
        """)

        await browser.close()

    print("=" * 78)
    print("COLUMN HEADERS THE SITE DECLARES")
    print("=" * 78)
    print(html_mod.unescape(heads or '(not found)'))
    print()

    if not tpl:
        print("no row template found")
        return

    t = max(tpl, key=lambda x: x['len'])
    body = html_mod.unescape(t['body'])
    print("=" * 78)
    print("HOW EACH FIELD IS RENDERED  (template: %s)" % t['id'])
    print("=" * 78)

    # for every ${b.X} show the surrounding label/markup so the meaning is clear
    seen = {}
    for m in re.finditer(r'\$\{b\.([a-z]\d?)\}', body):
        key = m.group(1)
        start = max(0, m.start() - 170)
        ctx_txt = body[start:m.end() + 60]
        ctx_txt = re.sub(r'\s+', ' ', ctx_txt)
        seen.setdefault(key, []).append(ctx_txt)

    for key in sorted(seen.keys(), key=lambda s: (len(s), s)):
        print("\n--- b.%s ---" % key)
        print("   " + seen[key][0][-200:])


if __name__ == '__main__':
    asyncio.run(main())
