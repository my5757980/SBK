/* The ID signals (green / red) keep themselves current on a page left open.
 *
 * Every signal on the page is a .src-sig[data-src] rendered by
 * includes/source-health.php for staff only; this asks api/source-health.php
 * once a minute and repaints them. A page without a signal asks nothing. */
(function () {
  'use strict';
  var sigs = document.querySelectorAll('.src-sig[data-src]');
  if (!sigs.length || !window.fetch) { return; }
  // The same script serves the portal's pages and the desk's (admin/), so the
  // API is found from where this file itself was loaded, not from the page.
  var me = document.currentScript && document.currentScript.src;
  var api = (me ? me.replace(/assets\/js\/source-signal\.js.*$/, '') : '') + 'api/source-health.php';

  function paint(all) {
    for (var i = 0; i < sigs.length; i++) {
      var el = sigs[i], h = all[el.getAttribute('data-src')];
      if (!h) { continue; }
      el.classList.remove('is-ok', 'is-bad', 'is-wait');
      el.classList.add(h.ok === true ? 'is-ok' : (h.ok === false ? 'is-bad' : 'is-wait'));
      el.title = h.detail;
      var t = el.querySelector('.src-text');
      if (t) { t.textContent = h.text; }
    }
  }

  function tick() {
    if (document.hidden) { return; }
    fetch(api, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j) { paint(j); } })
      .catch(function () {});
  }

  setInterval(tick, 60000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { tick(); } });
})();
