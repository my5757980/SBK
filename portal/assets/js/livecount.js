/* Keep the numbers on a page current without reloading it.
 *
 * Every heading that prints a count printed it at the moment the page was
 * built, and the catalogue moves all day - so the only way to see the real
 * figure was to press refresh, on the portal, on the inventory screen and on
 * the dashboard alike.
 *
 * Mark an element with data-live="key" and it is kept up to date from
 * api/counts.php. Nothing else on the page is touched: no rows are replaced, no
 * scroll position moves, and a number that has not changed is not rewritten -
 * so a reader mid-sentence is never interrupted by a flash that means nothing.
 */
(function () {
  'use strict';

  var EVERY = 20000;

  function targets() {
    return document.querySelectorAll('[data-live]');
  }
  if (!targets().length) { return; }

  // api/ sits beside the client pages and one level up from the admin ones.
  var base = /\/admin\//.test(window.location.pathname) ? '../api/' : 'api/';

  function fmt(n) {
    return Number(n).toLocaleString('en-US');
  }

  function flash(el) {
    el.classList.add('live-changed');
    setTimeout(function () { el.classList.remove('live-changed'); }, 1400);
  }

  var failures = 0;

  function tick() {
    fetch(base + 'counts.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (data) {
        failures = 0;
        Array.prototype.forEach.call(targets(), function (el) {
          var key = el.getAttribute('data-live');
          if (!(key in data) || data[key] < 0) { return; }
          var next = fmt(data[key]);
          if (el.textContent.trim() === next) { return; }
          el.textContent = next;
          flash(el);
        });
      })
      .catch(function () {
        // A signed-out session or a hiccup. Back off rather than hammer, and
        // stop altogether once it is clearly not coming back - a page quietly
        // showing an old number is better than one retrying for ever.
        failures++;
      })
      .then(function () {
        if (failures < 5) { setTimeout(tick, failures ? EVERY * 3 : untilNextBeat()); }
      });
  }

  /* Everybody on the same beat.
     The figures are read from one live count, so any two screens agree - but
     each page used to start its own clock when it happened to load, so the desk's
     overview and the customer's dashboard refreshed at different moments and the
     owner, looking at both, saw one run ahead of the other. Ticking on the wall
     clock instead - :00, :20, :40 - means every screen fetches at the same
     instants and shows the same number between them. */
  function untilNextBeat() {
    return EVERY - (Date.now() % EVERY);
  }

  setTimeout(tick, untilNextBeat());
})();
