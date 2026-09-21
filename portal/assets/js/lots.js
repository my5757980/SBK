/**
 * SBK Auction — auction list behaviour.
 *
 *  1. facets apply themselves, without the Search button
 *  2. facet "+ More" toggles
 *  3. live refresh that PATCHES the rows in place — the page is never rebuilt
 *     under the reader, so scrolling is never disturbed.
 *
 * The photo viewer that used to live here has gone. site.js carries one for the
 * whole site, and this file had a second: clicking a thumbnail opened both, one
 * on top of the other, so closing appeared to do nothing until it was done
 * twice. Two viewers for one click is a bug however carefully each is written,
 * and the site-wide one is the keeper - the lot page uses it too.
 */
(function () {
  'use strict';

  /* --------------------------------------------------- facets apply themselves
     Make was a link, so picking one filtered the list at once. Model, Chassis
     Model, Auction, the sale days and the grades were form controls, so they
     sat there doing nothing until the Search button was found and pressed -
     two behaviours in one panel, and the one that needs explaining is the one
     that covers four columns out of six.

     Submitted on a delay rather than on the click. These are multi-select
     boxes: somebody ticking three grades wants one search at the end, not
     three page loads while they are still choosing. */
  (function () {
    var form = document.getElementById('pbSearch');
    var cols = document.getElementById('pbCols');
    if (!form || !cols) return;

    var timer = null;
    var WAIT = 650;

    cols.addEventListener('change', function (e) {
      var el = e.target;
      if (!el || !el.tagName) return;
      var tag = el.tagName.toLowerCase();
      var type = (el.type || '').toLowerCase();
      // `change` is the safe event throughout: a slider fires it when the
      // handle is let go rather than on every pixel of the drag, and a number
      // box when the reader leaves it rather than on every digit.
      if (!(tag === 'select' || type === 'checkbox' || type === 'radio'
            || type === 'range' || type === 'number')) return;

      clearTimeout(timer);
      timer = setTimeout(function () {
        // Dimmed at the moment of submitting, not while the reader is still
        // choosing - the panel has to stay usable through the whole wait or
        // the delay that lets them tick three boxes stops them ticking two.
        cols.classList.add('is-working');
        // Come back to the same place on the page. A filter panel that throws
        // the reader to the top on every tick is worse than the button was.
        try { sessionStorage.setItem('sbkListY', String(window.scrollY)); } catch (err) {}
        form.submit();
      }, WAIT);
    });

    try {
      var y = sessionStorage.getItem('sbkListY');
      if (y !== null) {
        sessionStorage.removeItem('sbkListY');
        window.scrollTo(0, parseInt(y, 10) || 0);
      }
    } catch (err) { /* private browsing: the scroll position is not worth an error */ }

    /* Going back has to take a filter off, and it did not.

       Every tick submits the form, so each one is its own entry in the
       browser's history and going back returns to the address that had one
       fewer - the server reads that address and answers correctly, and the
       count on the page is right. But the browser also restores what was typed
       and ticked, which is a kindness on a form somebody is filling in and
       wrong on a form that IS the address: the tick came back while the filter
       behind it did not.

       So the panel showed a sale day ticked that was not being filtered on,
       and the next tick submitted that stale one along with it - the filter
       appeared to refuse to come off, and the only way out was Reset. The
       owner reported it as exactly that.

       The address is the truth. On every arrival - a fresh load, a back, a
       forward, a restore from the back-forward cache - the controls are set to
       say what the address says. Setting `checked` and `value` from script does
       not fire `change`, so this cannot set the auto-search off. */
    function syncFromUrl() {
      var q = new URLSearchParams(window.location.search);
      var seen = {};

      cols.querySelectorAll('input[name], select[name]').forEach(function (el) {
        var name = el.getAttribute('name');
        if (!name) { return; }
        if (!(name in seen)) { seen[name] = q.getAll(name); }
        var vals = seen[name];
        var type = (el.type || '').toLowerCase();

        if (type === 'checkbox' || type === 'radio') {
          el.checked = vals.indexOf(el.value) !== -1;
          return;
        }
        // A slider's two handles are redrawn from the fields they feed, further
        // down this file, so they are left to that rather than set from here.
        if (type === 'range') { return; }
        if (el.tagName.toLowerCase() === 'select' && el.multiple) {
          Array.prototype.forEach.call(el.options, function (o) {
            o.selected = vals.indexOf(o.value) !== -1;
          });
          return;
        }
        el.value = vals.length ? vals[0] : '';
      });
    }

    // `pageshow` fires on a first load and on every history navigation,
    // including one served from the back-forward cache, where nothing else
    // runs at all.
    window.addEventListener('pageshow', syncFromUrl);
    syncFromUrl();
  })();

  /* ------------------------------------------------------------- the sliders
     Two range inputs sharing one track. Each keeps its own side: the left one
     may not be dragged past the right, and the hidden fields the form actually
     posts follow whatever they settle on.

     The reading only updates while dragging - the search waits for `change`,
     which fires when the handle is let go. Searching on every pixel of a drag
     would fire fifty requests to arrive at the one the buyer wanted. */
  document.querySelectorAll('.pb-range').forEach(function (box) {
    var a = box.querySelector('.pb-range-a');
    var b = box.querySelector('.pb-range-b');
    var fill = box.querySelector('.pb-range-fill');
    var out = box.querySelector('.pb-range-v');
    var head = box.querySelector('.pb-range-h');
    var nums = box.querySelectorAll('.pb-range-x input');
    if (!a || !b || nums.length !== 2) return;

    var key = box.getAttribute('data-key') || '';
    var lo = parseFloat(a.min), hi = parseFloat(a.max);
    var comma = box.getAttribute('data-fmt') === 'comma';

    function show(n) {
      return comma ? Number(n).toLocaleString('en-US') : String(n);
    }

    /** Slider handles -> the boxes, which are what the form posts. */
    function paint() {
      var v1 = parseFloat(a.value), v2 = parseFloat(b.value);
      if (v1 > v2) { var t = v1; v1 = v2; v2 = t; }
      var span = (hi - lo) || 1;
      if (fill) {
        fill.style.left = (((v1 - lo) / span) * 100) + '%';
        fill.style.width = (((v2 - v1) / span) * 100) + '%';
      }
      if (out) out.textContent = show(v1) + ' – ' + show(v2);
      // A handle against the end of its track means "no limit", and posts
      // nothing. The track ends where the slider was clamped, not where the
      // stock does, so posting the end value would hide everything beyond it.
      nums[0].value = (v1 > lo) ? v1 : '';
      nums[1].value = (v2 < hi) ? v2 : '';
    }

    /** The boxes -> the handles, so switching back shows what was typed. */
    function fromBoxes() {
      var v1 = nums[0].value === '' ? lo : parseFloat(nums[0].value);
      var v2 = nums[1].value === '' ? hi : parseFloat(nums[1].value);
      if (!isFinite(v1)) v1 = lo;
      if (!isFinite(v2)) v2 = hi;
      v1 = Math.max(lo, Math.min(hi, v1));
      v2 = Math.max(v1, Math.min(hi, v2));
      a.value = v1;
      b.value = v2;
      var span = (hi - lo) || 1;
      if (fill) {
        fill.style.left = (((v1 - lo) / span) * 100) + '%';
        fill.style.width = (((v2 - v1) / span) * 100) + '%';
      }
      if (out) out.textContent = show(v1) + ' – ' + show(v2);
    }

    // Neither handle may cross the other, or the pair reads back to front and
    // the filter asks for "from 2019 to 1995", which matches nothing.
    a.addEventListener('input', function () {
      if (parseFloat(a.value) > parseFloat(b.value)) a.value = b.value;
      paint();
    });
    b.addEventListener('input', function () {
      if (parseFloat(b.value) < parseFloat(a.value)) b.value = a.value;
      paint();
    });
    nums[0].addEventListener('input', fromBoxes);
    nums[1].addEventListener('input', fromBoxes);

    /* Which mode this row is in, remembered.
       Searching reloads the page, so a buyer who switched to typing would find
       the slider back every time they changed a figure. */
    function setMode(typed) {
      box.classList.toggle('is-typed', typed);
      if (head) head.setAttribute('aria-expanded', typed ? 'true' : 'false');
      try { sessionStorage.setItem('sbkRange:' + key, typed ? '1' : '0'); } catch (e) {}
    }
    try {
      if (sessionStorage.getItem('sbkRange:' + key) === '1') setMode(true);
    } catch (e) { /* private browsing */ }

    if (head) {
      head.addEventListener('click', function () {
        setMode(!box.classList.contains('is-typed'));
      });
    }

    paint();
  });

  /* ------------------------------------------------------------ facet + More */
  document.addEventListener('click', function (e) {
    var btn = e.target;
    if (!btn.classList || !btn.classList.contains('facet-more')) return;
    var facet = btn.closest('.facet');
    var open  = facet.classList.toggle('is-open');
    btn.textContent = open
      ? '− Less'
      : '+ More ' + facet.querySelectorAll('.facet-item.extra').length;
  });

  /* ----------------------------------------------------------- live refresh */
  var table = document.getElementById('lotTable');
  if (!table) return;

  var badge    = document.getElementById('liveBadge');
  var countEl  = document.getElementById('resultsCount');
  var POLL_MS  = 15000;
  var TOP_ZONE = 150;      // only re-order the list while the reader is at the top
  var version  = null;
  var pill     = null;

  function apiUrl(extra) {
    var params = new URLSearchParams(window.location.search);
    params.delete('version');
    if (!params.get('per_page')) {
      params.set('per_page', table.getAttribute('data-per-page') || '20');
    }
    if (extra) params.set(extra, '1');
    return 'api/cars.php?' + params.toString();
  }

  function fmtInt(n) { return Number(n).toLocaleString('en-US'); }

  /** Update one row's volatile cells. Photos and identity are left alone. */
  function patchRow(tr, car) {
    var start = tr.querySelector('.p-start');
    var sold  = tr.querySelector('.p-sold');
    var chip  = tr.querySelector('.chip-status');

    if (start && start.textContent.trim() !== car.start_fmt) {
      start.textContent = car.start_fmt;
      flash(start);
    }
    if (sold && sold.textContent.trim() !== car.sold_fmt) {
      sold.textContent = car.sold_fmt;
      flash(sold);
    }
    if (chip && chip.textContent.trim() !== car.status_label) {
      chip.textContent = car.status_label;
      chip.className = 'chip-status ' + car.status_class + ' mini';
      flash(chip);
    }
  }

  function flash(el) {
    el.classList.remove('just-changed');
    void el.offsetWidth;
    el.classList.add('just-changed');
  }

  function showPill(n) {
    if (pill) return;
    pill = document.createElement('button');
    pill.type = 'button';
    pill.className = 'live-pill';
    pill.textContent = fmtInt(n) + ' vehicles now — refresh list';
    pill.addEventListener('click', function () { window.location.reload(); });
    document.body.appendChild(pill);
  }

  function apply(data) {
    if (countEl) countEl.textContent = fmtInt(data.total);

    var byId = {};
    (data.cars || []).forEach(function (c) { byId[c.id] = c; });

    var rows = table.querySelectorAll('tbody tr[data-id]');
    var seen = 0;
    rows.forEach(function (tr) {
      var car = byId[tr.getAttribute('data-id')];
      if (car) { patchRow(tr, car); seen++; }
    });

    // the result set itself moved (new lots pushed in / lots dropped out)
    if (rows.length && seen < rows.length) {
      if (window.scrollY <= TOP_ZONE) {
        window.location.reload();
      } else {
        showPill(data.total);
      }
    }
  }

  function poll() {
    fetch(apiUrl('version'), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (v) {
        if (!v || v.error) return;
        if (badge) badge.classList.add('is-on');
        if (version === null) { version = v.version; return; }
        if (v.version === version) return;
        version = v.version;
        return fetch(apiUrl(), { credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (d) { if (d && !d.error) apply(d); });
      })
      .catch(function () { /* offline for a moment — next tick tries again */ });
  }

  poll();
  setInterval(function () {
    if (!document.hidden) poll();
  }, POLL_MS);
})();
