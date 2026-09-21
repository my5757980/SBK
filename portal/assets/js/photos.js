/* Hide the photographs the source does not have.
 *
 * A vehicle's pictures answer to one address with a number on the end, and the
 * feed only ever links the first. The rest are worked out - number=2, number=3,
 * the inspection sheet at number=0 - because most vehicles do have them and
 * fetching each one first to find out would cost a request a picture.
 *
 * Most, not all. Where a picture is not there the server answers 404 with a
 * placeholder, and the browser draws its broken-image mark: a listing row with
 * one real photograph and two torn corners beside it, and a detail page showing
 * twelve slots where three have pictures. That reads as a portal that cannot
 * load its images.
 *
 * So a picture that fails to load is taken out of the page. The gallery keeps
 * only what exists, and a vehicle whose pictures are all missing says so in
 * words instead of showing nothing.
 */
(function () {
  'use strict';

  function drop(img) {
    /* The lightbox is not a gallery and its picture is not a slot.
       It is one <img> that every zoom reuses, and hiding it once hides it for
       good: after a single missing file, every later zoom put up a black screen
       with nothing in it - and because opening the overlay also stops the page
       scrolling, that read as the site having frozen. It was the commonest
       complaint about the portal and it came from this line.
       The overlay looks after its own failures now; see site.js. */
    if (img.closest('.pb-lb')) { return; }

    // A thumbnail belongs to its slot; take the slot with it where there is one.
    var host = img.closest('.pb-thumbs') ? img : img.parentElement;
    if (img.id === 'stageImg') {
      // The big one. Promote the first thumbnail that did load, or say plainly
      // that there is nothing to show - never leave an empty frame.
      var next = document.querySelector('.pb-thumbs .thumb:not([data-broken])');
      if (next) {
        var full = next.getAttribute('data-full') || next.src;
        img.src = full;
        /* And what it opens to, which was left pointing at the picture that had
           just failed: the frame showed a good photograph and zooming it put up
           the missing one. The slot's own zoom address is preferred, because
           the frame's file and the file behind it are different sizes. */
        img.setAttribute('data-zoom', next.getAttribute('data-zoom') || full);
        return;
      }
      var stage = img.parentElement;
      img.remove();
      if (stage && !stage.querySelector('img')) {
        var p = document.createElement('div');
        p.className = 'noimg';
        p.textContent = 'No photo available';
        stage.appendChild(p);
      }
      return;
    }

    img.setAttribute('data-broken', '1');
    img.style.display = 'none';

    // A sheet panel or a thumbnail strip left with nothing in it is a hole in
    // the layout; close it.
    var strip = img.closest('.pb-thumbs, .pb-sheet, .lot-shots');
    if (strip && !strip.querySelector('img:not([data-broken])')) {
      strip.style.display = 'none';
    }
    if (host !== img && host && !host.querySelector('img:not([data-broken])')) {
      host.style.display = 'none';
    }
  }

  /* Ask for the next photograph only once the last one has arrived.
     The set runs 1, 2, 3 with no gaps, so the first address that answers 404
     is the end of it - and stopping there is the difference between one wasted
     request a lot and eleven. Each picture that does arrive is added to the
     strip in order, so the reader watches the set fill rather than watching
     broken marks disappear. */
  function loadMore(strip) {
    var tpl  = strip.getAttribute('data-tpl');
    var full = strip.getAttribute('data-tpl-full') || tpl;
    var max  = parseInt(strip.getAttribute('data-max'), 10) || 12;
    if (!tpl || !/number=\d+/.test(tpl)) { return; }

    var n = 1;
    (function next() {
      n++;
      if (n > max) { return; }
      var thumbSrc = tpl.replace(/number=\d+/, 'number=' + n);
      var probe = new Image();
      probe.onload = function () {
        var img = document.createElement('img');
        img.src = thumbSrc;
        img.className = 'thumb';
        img.alt = 'View ' + n;
        img.setAttribute('data-full', full.replace(/number=\d+/, 'number=' + n));
        strip.appendChild(img);
        next();
      };
      // 404, or anything else: that is the end of the set.
      probe.onerror = function () {};
      probe.src = thumbSrc;
    })();
  }

  document.addEventListener('DOMContentLoaded', function () {
    var strip = document.querySelector('.pb-thumbs[data-tpl]');
    if (strip) { loadMore(strip); }
  });

  /* USS halls. The host gives everyone a 100x75 preview for these, so the page
     arrives with that and asks our server for the full set (api/pb-photos.php),
     which fetches it from Pacific Boeki the first time anybody opens the car.
     "processing" means Pacific Boeki is still getting it - ask again when told.
     When it comes, the sheet panel, the big frame, the strip and the A-D letters
     are rebuilt from it; prune() in site.js may have taken the sheet panel out
     already, so it is put back rather than assumed. */
  function ussShow(body, j) {
    if (j.sheet) {
      var sh = body.querySelector('.pb-sheet');
      if (!sh) {
        sh = document.createElement('div');
        sh.className = 'pb-sheet';
        body.insertBefore(sh, body.firstChild);
      }
      body.classList.remove('is-solo');
      sh.style.display = '';
      sh.innerHTML = '';
      var si = document.createElement('img');
      si.src = j.sheet;
      si.alt = 'Auction inspection sheet';
      si.setAttribute('data-zoom', j.sheet);
      sh.appendChild(si);
    }
    var stage = body.querySelector('.pb-stage');
    var big = document.getElementById('stageImg');
    if (stage) {
      var none = stage.querySelector('.noimg');
      if (none) { none.remove(); }
      if (!big) {
        big = document.createElement('img');
        big.id = 'stageImg';
        big.alt = document.title;
        stage.appendChild(big);
      }
      big.removeAttribute('data-broken');
      big.style.display = '';
      big.src = j.photos[0];
      big.setAttribute('data-zoom', j.photos[0]);
    }
    var shots = body.querySelector('.pb-shots');
    var strip = body.querySelector('.pb-thumbs');
    if (!strip && shots) {
      strip = document.createElement('div');
      strip.className = 'pb-thumbs';
      shots.insertBefore(strip, shots.querySelector('.pb-deadline'));
    }
    if (strip) {
      strip.removeAttribute('data-tpl');
      strip.style.display = '';
      strip.innerHTML = '';
      j.photos.forEach(function (u, i) {
        var t = document.createElement('img');
        t.src = u;
        t.className = 'thumb' + (i === 0 ? ' active' : '');
        t.alt = 'View ' + (i + 1);
        t.setAttribute('data-full', u);
        t.setAttribute('data-zoom', u);
        // the strip's own handler in site.js was bound to the slots that were
        // there when the page loaded; these are new
        t.addEventListener('click', function () {
          if (big) { big.src = u; big.setAttribute('data-zoom', u); }
          strip.querySelectorAll('.thumb').forEach(function (x) { x.classList.remove('active'); });
          t.classList.add('active');
        });
        strip.appendChild(t);
      });
    }
    // The A-D letters read data-full when clicked, so pointing them is enough -
    // and switching them back on: prune() in site.js greyed them out while the
    // strip held only the preview.
    document.querySelectorAll('.pb-abcd a.ltr').forEach(function (a, i) {
      if (j.photos[i]) {
        a.setAttribute('data-full', j.photos[i]);
        a.classList.remove('is-off');
        if (!a.getAttribute('href')) { a.setAttribute('href', '#'); }
      }
    });
  }

  function ussLoad(body) {
    var id = body.getAttribute('data-uss');
    var tries = 0;
    (function ask() {
      tries++;
      var x = new XMLHttpRequest();
      x.open('GET', 'api/pb-photos.php?id=' + encodeURIComponent(id), true);
      x.onreadystatechange = function () {
        if (x.readyState !== 4) { return; }
        var j = null;
        try { j = JSON.parse(x.responseText); } catch (e) { j = null; }
        if (!j) { return; }
        if (j.status === 'processing' && tries < 6) {
          setTimeout(ask, Math.max(3, j.retry || 10) * 1000);
          return;
        }
        if (j.status === 'ok' && j.photos && j.photos.length) { ussShow(body, j); }
      };
      x.send();
    })();
  }

  document.addEventListener('DOMContentLoaded', function () {
    var body = document.querySelector('.pb-body[data-uss]');
    if (body) { ussLoad(body); }
  });

  // Capture, because an image's error event does not bubble.
  document.addEventListener('error', function (e) {
    var t = e.target;
    if (t && t.tagName === 'IMG' && !t.hasAttribute('data-broken')) { drop(t); }
  }, true);

  /* Anything that finished failing before this script ran.

     `complete && naturalWidth === 0` reads as "it tried and got nothing", and
     for an ordinary image it is. For one marked loading="lazy" it is also the
     state it sits in BEFORE it has tried at all - the browser reports it
     complete because it has decided not to start yet. Every listing thumbnail
     carries loading="lazy", so this sweep took a row of perfectly good
     photographs and hid them for never having begun.

     That is why pictures came and went: whether a thumbnail survived depended
     on where the page had got to when this ran. The owner reported it three
     times as "the images have gone again".

     currentSrc is what separates the two. It is empty until the browser
     actually fetches something, so an image that has genuinely failed has one
     and an image that has not started has not. Nothing is hidden without it. */
  function reallyFailed(img) {
    return img.complete && img.naturalWidth === 0
        && (img.currentSrc || '') !== '';
  }

  document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.forEach.call(document.images, function (img) {
      if (reallyFailed(img) && !img.hasAttribute('data-broken')) {
        drop(img);
      }
    });
  });
})();
