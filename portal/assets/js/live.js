/**
 * SBK Auction — live inventory.
 *
 * Polls a cheap version hash; when the data behind the current view changes
 * (new lot, price move, sold/unsold) the page catches up WITHOUT disturbing
 * what the visitor is looking at:
 *
 *   - same set of lots  -> patch the changed values in place (no DOM rebuild,
 *                          no reflow, no scroll jump)
 *   - different lots    -> only rebuild while the visitor is at the top of the
 *                          page; otherwise show a quiet "new listings" pill and
 *                          let them choose when to jump
 *
 * Rebuilding the grid under someone mid-scroll made the page blank for a beat
 * and shifted everything down as lazy images re-loaded, so it is never done
 * behind their back.
 */
(function () {
  'use strict';

  var POLL_MS = 7000;
  var TOP_ZONE = 150;          // px from top where a rebuild is unobtrusive

  var grid = document.getElementById('carsGrid');
  var countEl = document.getElementById('resultsCount');
  var liveBadge = document.getElementById('liveBadge');
  if (!grid) return;

  var qs = new URLSearchParams(window.location.search);
  var lastVersion = '';
  var pendingData = null;
  var pill = null;

  function apiUrl(extra) {
    var p = new URLSearchParams(qs.toString());
    p.set('per_page', grid.getAttribute('data-per-page') || '24');
    if (extra) Object.keys(extra).forEach(function (k) { p.set(k, extra[k]); });
    return 'api/cars.php?' + p.toString();
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m];
    });
  }
  function num(n) { return (parseInt(n, 10) || 0).toLocaleString('en-US'); }
  function tag(v) { return v ? '<span class="tag">' + esc(v) + '</span>' : ''; }

  function cardHtml(c) {
    var href = 'car-details.php?id=' + c.id;

    var photo = c.photo
      ? '<img src="' + esc(c.photo) + '" alt="' + esc(c.title) + '" loading="lazy">'
      : '<div class="noimg">No photo</div>';

    var rating = c.rating
      ? '<span class="chip chip-rating">Grade ' + esc(c.rating) + '</span>'
      : '';

    var meta = [
      tag(c.year),
      tag(c.mileage ? num(c.mileage) + ' km' : ''),
      tag(c.engine_cc ? num(c.engine_cc) + ' cc' : ''),
      tag(c.transmission),
      tag(c.drive),
      tag(c.equipment)
    ].join('');

    var hasPrice = (c.sold_price > 0) || (c.price > 0);
    var priceLbl = c.price_label || (c.price > 0 ? 'Start price' : 'Price');

    var origin = '<b>' + esc(c.auction || '—') + '</b>' +
      (c.auction_date ? ' ' + esc(c.auction_date) : '') +
      (c.auction_time ? ' ' + esc(c.auction_time) : '');

    return '' +
      '<article class="car-card" data-id="' + c.id + '">' +
        '<a href="' + href + '" class="car-photo">' + photo +
          '<span class="chip chip-status ' + esc(c.status_class) + '">' + esc(c.status_label) + '</span>' +
          rating +
        '</a>' +
        '<div class="car-body">' +
          '<a href="' + href + '"><h3 class="car-title">' + esc(c.title) + '</h3></a>' +
          '<div class="car-grade">' + esc(c.grade || '—') + '</div>' +
          '<div class="car-meta">' + meta + '</div>' +
          '<div class="car-origin"><span>' + origin + '</span>' +
            '<span>Lot ' + esc(c.lot_no) + '</span></div>' +
          '<div class="car-foot">' +
            '<div class="price' + (hasPrice ? '' : ' is-none') + '">' +
              '<small>' + priceLbl + '</small>' + esc(c.price_fmt) +
            '</div>' +
            '<a href="' + href + '" class="btn btn-secondary">Details</a>' +
          '</div>' +
        '</div>' +
      '</article>';
  }

  /** Update one existing card's volatile bits without touching its layout. */
  function patchCard(el, c) {
    var changed = false;

    var priceEl = el.querySelector('.price');
    if (priceEl) {
      var hasPrice = (c.sold_price > 0) || (c.price > 0);
      var lbl = c.price_label || (c.price > 0 ? 'Start price' : 'Price');
      var wanted = '<small>' + lbl + '</small>' + esc(c.price_fmt);
      if (priceEl.innerHTML !== wanted) {
        priceEl.innerHTML = wanted;
        priceEl.classList.toggle('is-none', !hasPrice);
        changed = true;
      }
    }

    var chip = el.querySelector('.chip-status');
    if (chip && chip.textContent.trim() !== String(c.status_label)) {
      chip.textContent = c.status_label;
      chip.className = 'chip chip-status ' + c.status_class;
      changed = true;
    }

    if (changed) {
      el.classList.add('just-updated');
      setTimeout(function () { el.classList.remove('just-updated'); }, 1500);
    }
    return changed;
  }

  function idsOf(cars) { return cars.map(function (c) { return String(c.id); }).join(','); }

  function currentIds() {
    return Array.prototype.map.call(grid.querySelectorAll('.car-card'), function (el) {
      return el.getAttribute('data-id');
    }).join(',');
  }

  function rebuild(data) {
    grid.innerHTML = data.cars.map(cardHtml).join('');
    if (countEl) countEl.textContent = num(data.total);
    hidePill();
    flash();
  }

  function showPill(data) {
    pendingData = data;
    if (pill) return;
    pill = document.createElement('button');
    pill.type = 'button';
    pill.className = 'live-pill';
    pill.textContent = 'New listings — show';
    pill.addEventListener('click', function () {
      if (pendingData) rebuild(pendingData);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    document.body.appendChild(pill);
    requestAnimationFrame(function () { pill.classList.add('on'); });
  }

  function hidePill() {
    pendingData = null;
    if (!pill) return;
    var p = pill;
    pill = null;
    p.classList.remove('on');
    setTimeout(function () { p.remove(); }, 250);
  }

  function apply(data) {
    if (!data || !data.cars || !data.cars.length) return;

    if (countEl) countEl.textContent = num(data.total);

    // same lots in the same order -> quietly patch values, never rebuild
    if (idsOf(data.cars) === currentIds()) {
      var any = false;
      data.cars.forEach(function (c) {
        var el = grid.querySelector('.car-card[data-id="' + c.id + '"]');
        if (el && patchCard(el, c)) any = true;
      });
      if (any) flash();
      hidePill();
      return;
    }

    // the listing itself changed - only reshuffle if it won't yank the page
    if (window.scrollY <= TOP_ZONE) {
      rebuild(data);
    } else {
      showPill(data);
    }
  }

  function flash() {
    if (!liveBadge) return;
    liveBadge.classList.add('live-pulse');
    setTimeout(function () { liveBadge.classList.remove('live-pulse'); }, 1200);
  }

  function check() {
    fetch(apiUrl({ version: '1' }), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (v) {
        if (!v || !v.version) return;
        if (lastVersion === '') { lastVersion = v.version; return; }
        if (v.version === lastVersion) return;
        lastVersion = v.version;
        return fetch(apiUrl(), { cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(apply);
      })
      .catch(function () { /* keep the last good view */ });
  }

  setInterval(check, POLL_MS);
  setTimeout(check, 1500);
})();
