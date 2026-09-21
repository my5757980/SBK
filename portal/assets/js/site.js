/* Site chrome that every page carries. */

/* ------------------------------------------------------------------ toasts
   Every message the site gives back - a bid placed, an enquiry sent, a
   password rejected - was a coloured band printed into the page where the
   markup happened to sit. Some of those places were below the fold, so the
   answer to "did that work?" was somewhere the reader was not looking.

   They slide in at the top right instead, and go away by themselves.

   The pages are not rewritten. Each still prints its `.alert`, and this reads
   it, shows it as a toast and removes the original - eleven pages, one
   change, and anything added later behaves the same without being told to. */
window.sbkToast = (function () {
  var host = null;

  function shell() {
    if (!host) {
      host = document.createElement('div');
      host.className = 'sbk-toasts';
      host.setAttribute('role', 'status');
      host.setAttribute('aria-live', 'polite');
      document.body.appendChild(host);
    }
    return host;
  }

  return function (text, kind, ms) {
    text = String(text == null ? '' : text).trim();
    if (!text) return;
    kind = (kind === 'ok' || kind === 'success') ? 'ok' : 'err';

    var t = document.createElement('div');
    t.className = 'sbk-toast is-' + kind;
    t.innerHTML =
        '<span class="sbk-toast-i" aria-hidden="true">' + (kind === 'ok' ? '&#10003;' : '!') + '</span>' +
        '<span class="sbk-toast-t"></span>' +
        '<button class="sbk-toast-x" type="button" aria-label="Dismiss">&times;</button>';
    t.querySelector('.sbk-toast-t').textContent = text;
    shell().appendChild(t);

    // An error is the one worth reading twice, so it stays longer - and a long
    // message needs longer than a short one whatever it says.
    var life = ms || (kind === 'err' ? 7000 : 4500) + Math.min(4000, text.length * 25);
    var timer = setTimeout(close, life);

    function close() {
      clearTimeout(timer);
      t.classList.add('is-gone');
      setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 220);
    }
    t.querySelector('.sbk-toast-x').addEventListener('click', close);
    // reading takes as long as it takes
    t.addEventListener('mouseenter', function () { clearTimeout(timer); });
    t.addEventListener('mouseleave', function () { timer = setTimeout(close, 2500); });

    return close;
  };
})();

/* Lift whatever the page printed into a toast. */
(function () {
  document.querySelectorAll('.alert').forEach(function (el) {
    var text = el.textContent.replace(/\s+/g, ' ').trim();
    if (!text) return;
    var ok = el.classList.contains('alert-success');
    window.sbkToast(text, ok ? 'ok' : 'err');
    el.parentNode.removeChild(el);
  });
})();

/* ---------------------------------------------------------------- spinner
   A ring turning in the middle of the window while the next page loads.

   The filters submit themselves now, so a tick or a dragged handle rebuilds
   the page - and between the click and the new page arriving there was nothing
   at all to say the site had heard. On a list of thirty thousand vehicles that
   pause is long enough for somebody to tick the box a second time.

   Held back for a moment before it appears. Most navigations here finish
   quickly, and a spinner that flashes up and vanishes on every click is worse
   than none: it reads as a fault rather than as progress. It shows only once
   the wait is long enough to be worth explaining. */
(function () {
  var wrap = document.createElement('div');
  wrap.className = 'sbk-spin';
  wrap.setAttribute('aria-hidden', 'true');
  wrap.innerHTML = '<div class="sbk-spin-i"></div>';
  document.documentElement.appendChild(wrap);

  var DELAY = 180;
  var timer = null;

  function start() {
    if (timer) return;
    timer = setTimeout(function () {
      wrap.classList.add('is-on');
    }, DELAY);
  }
  function stop() {
    clearTimeout(timer);
    timer = null;
    wrap.classList.remove('is-on');
  }

  // Anything that replaces the document: a filter submitting, a link followed,
  // the back button.
  window.addEventListener('beforeunload', start);

  // A submit that is called off leaves nothing to wait for. The bid form stops
  // its own submit when the terms box is unticked, and the spinner went on
  // turning over a page that was going nowhere - so whether it was prevented
  // is checked once every other handler has had its say.
  window.addEventListener('submit', function (e) {
    start();
    setTimeout(function () { if (e.defaultPrevented) stop(); }, 0);
  }, true);

  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    var href = a.getAttribute('href') || '';
    // Links that do not navigate: anchors, new tabs, downloads, javascript:
    if (a.target === '_blank' || a.hasAttribute('download')) return;
    if (href === '' || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return;
    if (a.origin && a.origin !== location.origin) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    start();
  }, true);

  // Coming back through the history cache shows the old page again, spinner
  // and all, unless it is cleared on arrival.
  window.addEventListener('pageshow', stop);

  // A download does not replace the page: the browser fetches the file and the
  // page stays where it was. The zip and the PDF would have left the spinner
  // turning over a page that had finished loading long ago.
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    if (/car-pdf\.php|images-zip\.php/.test(a.getAttribute('href') || '')) {
      setTimeout(stop, 1200);
    }
  }, true);
})();

/* --------------------------------------------------- "Hide All Search" toggle
   Six columns of filters is a lot of screen. Once a buyer has the list they
   want, the panel is in the way - so it folds, and the choice is remembered
   for the rest of the visit rather than being made again on every page. */
(function () {
  var btn = document.getElementById('pbToggle'),
      cols = document.getElementById('pbCols');
  if (!btn || !cols) return;

  var KEY = 'sbk.search.hidden';

  function apply(hidden) {
    cols.style.display = hidden ? 'none' : '';
    btn.innerHTML = hidden ? 'Show All Search +' : 'Hide All Search \u2212';
    btn.setAttribute('data-shown', hidden ? '0' : '1');
  }

  try { apply(sessionStorage.getItem(KEY) === '1'); } catch (e) { apply(false); }

  btn.addEventListener('click', function () {
    var hide = btn.getAttribute('data-shown') === '1';
    apply(hide);
    try { sessionStorage.setItem(KEY, hide ? '1' : '0'); } catch (e) {}
  });
})();

/* -------------------------------------------------------------- countdown
   "Left: 12 hr 45 min" against each lot.

   The server works out the moment the hammer falls in Japan's clock and hands
   it over as a unix timestamp, so the arithmetic here is plain subtraction and
   the buyer reads the answer in their own time zone with no conversion to get
   wrong. Ticks once a minute - the display has no seconds, so anything faster
   would only burn battery. */
(function () {
  var els = document.querySelectorAll('.left[data-ends]');
  if (!els.length) return;

  function paint() {
    var now = Date.now() / 1000;
    for (var i = 0; i < els.length; i++) {
      var left = parseInt(els[i].getAttribute('data-ends'), 10) - now;
      if (!isFinite(left)) { els[i].textContent = ''; continue; }
      if (left <= 0) { els[i].textContent = 'Closed'; els[i].classList.add('is-over'); continue; }

      var d = Math.floor(left / 86400),
          h = Math.floor(left % 86400 / 3600),
          m = Math.floor(left % 3600 / 60);
      els[i].textContent = 'Left: ' + (
        d > 0 ? d + ' day' + (d > 1 ? 's ' : ' ') + h + ' hr'
              : h > 0 ? h + ' hr ' + m + ' min'
                      : m + ' min');
    }
  }

  paint();
  setInterval(function () { if (!document.hidden) paint(); }, 60000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) paint();
  });
})();

/* ------------------------------------------------------------ back to top
   The auction list runs to a hundred rows, and a buyer who has read to the
   bottom wants the search again, not a long scroll. Appears only once there
   is enough page behind you to be worth the shortcut. */
(function () {
  var btn = document.createElement('button');
  btn.className = 'pb-top';
  btn.type = 'button';
  btn.setAttribute('aria-label', 'Back to top');
  btn.innerHTML = '\u2191';
  btn.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  document.body.appendChild(btn);

  function check() {
    btn.classList.toggle('is-on', window.scrollY > 400);
  }
  check();
  window.addEventListener('scroll', check, { passive: true });
})();

/* ---------------------------------------------------------------- lightbox
   Any picture marked data-zoom opens full size where it is. Clicking a car's
   photograph used to carry you off to another page, which is the one thing a
   buyer looking closely at a picture does not want - they wanted the picture
   bigger, not somewhere else. The car's name still links to the car. */
(function () {
  var box = document.createElement('div');
  box.className = 'pb-lb';
  box.innerHTML =
      '<button class="pb-lb-x" type="button" aria-label="Close">&times;</button>'
    + '<button class="pb-lb-arw pb-lb-prev" type="button" aria-label="Previous">&#10094;</button>'
    + '<img alt="">'
    + '<button class="pb-lb-arw pb-lb-next" type="button" aria-label="Next">&#10095;</button>'
    + '<span class="pb-lb-count"></span>';
  document.body.appendChild(box);
  var img   = box.querySelector('img');
  var prevB = box.querySelector('.pb-lb-prev');
  var nextB = box.querySelector('.pb-lb-next');
  var count = box.querySelector('.pb-lb-count');

  /* A picture that will not load must not leave the reader looking at a black
     screen they cannot scroll away from. The overlay says what happened and
     lets go of the page; a picture that arrives clears the message. */
  var note = document.createElement('p');
  note.className = 'pb-lb-note';
  note.textContent = 'This photograph is not available.';
  note.hidden = true;
  box.appendChild(note);

  img.addEventListener('error', function () {
    img.hidden = true;
    note.hidden = false;
  });
  img.addEventListener('load', function () {
    img.hidden = false;
    note.hidden = true;
  });

  /* ---- the set a picture belongs to.

     Closing one photograph to open the next is not looking at a car, it is
     operating a lightbox. The set a picture belongs to is already on the page,
     so the arrows simply walk it - and wrap, because a row of four pictures has
     no natural end and stopping dead at the fourth only sends the reader back
     the way they came.

     Which set depends on where the picture sits. In the auction list each row
     carries one car's three photographs, so a row is the set. On the lot page
     the strip below the frame is the set - and the big frame belongs to that
     strip rather than to itself, or opening it would offer nothing to step
     through. On the desk's vehicle list there is one photograph per car and no
     row-level group, so the table is the set and staff can walk the page. */
  var host = null;      // the set's container, resolved when the overlay opens
  var anchor = null;    // the picture the reader actually clicked
  var shown = '';       // the file on screen, so a rebuilt set can find its place
  var at = 0;

  /* Only the pictures that are actually there.

     Three different things can leave a slot in the page with no photograph in
     it, and the count was believing all three:

       - the lot page prints every slot the set MIGHT hold, and photos.js marks
         the ones the host 404s with data-broken and hides them;
       - the image host does not 404 a missing picture, it serves a 128x96 "no
         image" tile, and prune() removes those - but only once they have
         loaded;
       - a slot that has not finished loading is not yet either.

     A car with four photographs was opening at "1 / 12" and the arrows walked
     into blank frames. Worse on a car with none: its strip is hidden whole, so
     clicking the big picture found one hidden broken slot and opened that
     instead of the picture the reader had just clicked. */
  var TILE_W2 = 128, TILE_H2 = 96;

  function alive(el) {
    return el.isConnected
        && !el.hasAttribute('data-broken')
        && el.offsetParent !== null
        && !(el.complete && el.naturalWidth === 0)
        && !(el.complete && el.naturalWidth === TILE_W2
                         && el.naturalHeight === TILE_H2);
  }

  function srcOf(el) {
    return el.getAttribute('data-zoom') || el.getAttribute('src') || '';
  }

  /* Which set a picture belongs to.

     In the auction list each row carries one car's photographs, so a row is the
     set. On the lot page the strip below the frame is the set - and the big
     frame belongs to that strip rather than to itself, or opening it would
     offer nothing to step through. On the desk's vehicle list there is one
     photograph per car and no row-level group, so the table is the set. */
  function hostFor(el) {
    if (el.id === 'stageImg') {
      var strip = document.querySelector('.pb-thumbs');
      if (strip) { return strip; }
    }
    return (el.closest
      ? el.closest('.lot-shots, .pb-thumbs, .pb-more-shots, .car-photo, tbody')
      : null) || document;
  }

  /* Worked out afresh every time, not once when the overlay opened.

     prune() takes the "no image" tiles out as they load, which can be after the
     reader has already clicked. A set captured at that moment kept the tiles,
     and the count kept counting them - the owner saw more pictures offered than
     the strip below was showing. Rebuilding costs nothing and is always right. */
  function setNow() {
    var arr = host
      ? [].slice.call(host.querySelectorAll('[data-zoom]')).filter(alive)
      : [];
    if (!arr.length || (anchor && arr.indexOf(anchor) < 0
                        && !arr.some(function (x) { return srcOf(x) === srcOf(anchor); }))) {
      // Whatever was clicked is in the set, always: it is the one picture the
      // reader asked for by name.
      return anchor ? [anchor] : arr;
    }
    return arr;
  }

  function showAt(arr, i) {
    if (!arr.length) { return; }
    at = (i % arr.length + arr.length) % arr.length;   // wraps both ways
    var el = arr[at];
    img.hidden = false;
    note.hidden = true;
    shown = srcOf(el);
    img.src = shown;
    img.alt = el.getAttribute('alt') || '';
    var many = arr.length > 1;
    prevB.hidden = nextB.hidden = !many;
    count.hidden = !many;
    count.textContent = many ? (at + 1) + ' / ' + arr.length : '';
  }

  function step(by) {
    var arr = setNow();
    if (!arr.length) { return; }
    var i = -1;
    for (var n = 0; n < arr.length; n++) {
      if (srcOf(arr[n]) === shown) { i = n; break; }
    }
    showAt(arr, (i < 0 ? 0 : i) + by);
  }

  function open(el) {
    if (!srcOf(el)) { return; }        // nothing to show, so nothing to open
    anchor = el;
    host = hostFor(el);
    var arr = setNow();
    var k = arr.indexOf(el);
    if (k < 0) {
      // The big frame is not itself in the strip; match it by the file it opens.
      for (var n = 0; n < arr.length; n++) {
        if (srcOf(arr[n]) === srcOf(el)) { k = n; break; }
      }
    }
    showAt(arr, k < 0 ? 0 : k);
    box.classList.add('is-on');
    document.body.style.overflow = 'hidden';
  }
  function close() {
    box.classList.remove('is-on');
    document.body.style.overflow = '';
    img.removeAttribute('src');
    img.hidden = false;
    note.hidden = true;
    host = anchor = null;
    shown = '';
  }

  // Anywhere inside the overlay closes it - the backdrop, the button, or the
  // picture itself, which shows a zoom-out cursor and so has to act like one.
  // Matching the button by exact className broke the moment anything else was
  // added to its class list, and left the only way out as the Escape key.
  //
  // The arrows are the exception: a click on one is a request to stay.
  box.addEventListener('click', function (e) {
    e.stopPropagation();
    var arw = e.target.closest ? e.target.closest('.pb-lb-arw') : null;
    if (arw) {
      step(arw.classList.contains('pb-lb-next') ? 1 : -1);
      return;
    }
    close();
  });
  document.addEventListener('keydown', function (e) {
    if (!box.classList.contains('is-on')) { return; }
    if (e.key === 'Escape')     { close(); return; }
    if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
    if (e.key === 'ArrowLeft')  { e.preventDefault(); step(-1); }
  });

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-zoom]') : null;
    if (!t) return;
    e.preventDefault();
    open(t);
  });
})();

/* -------------------------------------------------------- thumbnail strip
   Renamed with the rest of the lot page, so the old handler was matching
   nothing and the thumbnails had quietly stopped switching the main picture. */
(function () {
  // the A-D letters switch the same picture, so they are handled together
  var thumbs = document.querySelectorAll('.pb-thumbs .thumb, .pb-abcd .thumb');
  if (!thumbs.length) return;
  thumbs.forEach(function (t) {
    t.addEventListener('click', function (e) {
      e.preventDefault();
      var stage = document.getElementById('stageImg');
      if (stage) {
        stage.src = this.getAttribute('data-full');
        // What the frame shows and what it opens to are different files; the
        // frame took both from data-full, so choosing a pose and then zooming
        // it gave back the frame's own picture rather than the full one.
        stage.setAttribute('data-zoom',
          this.getAttribute('data-zoom') || this.getAttribute('data-full'));
      }
      thumbs.forEach(function (x) { x.classList.remove('active'); });
      this.classList.add('active');
    });
  });
})();

/* ------------------------------------------------------- FOB cost breakdown
   Adds up on the page as the buyer changes it. What they may change is what
   genuinely varies for them - the bid, the inspection body, L/C, vanning. The
   four shipping charges are the desk's own prices and are read-only: they are
   still inputs so this sum can read them, but a buyer cannot type over them and
   quote back a total the desk will not honour. Auction charges are deliberately
   absent: they are quoted on the day, and guessing would make the total look
   more certain than it is. */
(function () {
  var total = document.getElementById('fobTotal');
  if (!total) return;

  function num(id) {
    var el = document.getElementById(id);
    var v = el ? parseFloat(el.value) : 0;
    return isFinite(v) ? v : 0;
  }

  function tick(id, outId) {
    var box = document.getElementById(id);
    var out = document.getElementById(outId);
    var on = box && box.checked;
    var fee = box ? parseFloat(box.getAttribute('data-fee')) || 0 : 0;
    if (out) out.textContent = on ? fee.toLocaleString() : '0';
    return on ? fee : 0;
  }

  function sum() {
    var inspect = document.getElementById('fobInspect');
    var iv = inspect ? parseFloat(inspect.value) || 0 : 0;
    var io = document.getElementById('fobInspectOut');
    if (io) io.textContent = iv.toLocaleString();

    var t = num('fobBid') + num('fobTransport') + num('fobClearance')
          + num('fobRadiation') + num('fobRange') + iv
          + tick('fobLc', 'fobLcOut') + tick('fobVan', 'fobVanOut');

    total.textContent = Math.round(t).toLocaleString();
  }

  document.querySelectorAll('.pb-fob input, .pb-fob select').forEach(function (el) {
    el.addEventListener('input', sum);
    el.addEventListener('change', sum);
  });
  sum();
})();

/* --------------------------------------------------- the bid panel's sums
   Approx FOB moves with the bid, and the character count with the message.
   Both are the buyer checking their own arithmetic before they commit, which
   is the moment they most want the page to answer. */
(function () {
  var amount = document.getElementById('bidAmount');
  var fob = document.getElementById('bidFob');
  if (amount && fob) {
    var over = document.getElementById('overBid');
    var calc = function () {
      var a = parseFloat(amount.value) || 0;
      var o = over ? (parseFloat(over.value) || 0) : 0;
      var total = a + o + 20000;      // service charge, as stated on the panel
      fob.textContent = total > 20000 ? Math.round(total).toLocaleString() : '\u2014';
    };
    amount.addEventListener('input', calc);
    if (over) over.addEventListener('input', calc);
    calc();
  }

  var msg = document.getElementById('bidMsg');
  var chars = document.getElementById('bidChars');
  if (msg && chars) {
    msg.addEventListener('input', function () { chars.textContent = msg.value.length; });
  }

  /* The terms box has to be ticked.
     It is checked on the server as well - a form can be posted without a
     browser at all - but stopping it here means the buyer is told at once,
     with the box still in front of them, rather than losing the page and
     getting the answer back from a round trip. */
  var tc = document.getElementById('bidTc');
  var form = tc ? tc.closest('form') : null;
  if (form && tc) {
    form.addEventListener('submit', function (e) {
      if (tc.disabled || tc.checked) return;
      e.preventDefault();
      window.sbkToast('Please accept the Terms & Conditions before placing your bid.', 'err');
      tc.closest('.bid-tc').classList.add('is-wrong');
      tc.focus();
      setTimeout(function () { tc.closest('.bid-tc').classList.remove('is-wrong'); }, 2200);
    });
    tc.addEventListener('change', function () {
      tc.closest('.bid-tc').classList.remove('is-wrong');
    });
  }
})();

/* ---------------------------------------- pictures the source does not have
   The gallery is worked out from one address rather than fetched, so a lot with
   two photographs is still offered twelve. The picture host does not answer the
   missing ones with an error - it answers with a small "no image" tile, 128 by
   96, which loads perfectly well and so slips past any onerror handler. It is
   caught by its size instead: a real photograph comes back 853 by 640.

   The sheet is the last of the set, so on a lot with no sheet its panel is
   removed and the photographs take the width. */
(function () {
  /* The tile is exactly 128 by 96, and that exact size is the whole test.

     Calling anything narrower than 200 a tile was right while a real photograph
     came back 853 by 640. The host has since stopped honouring the height in
     the address - every real photograph now arrives 100 by 75, whatever size is
     asked for, and the tile is still 128 by 96. So the tile became the larger
     of the two and "narrower than 200" matched the photographs as well.

     What that did: each picture was measured as it arrived, judged missing, and
     hidden; when a row had nothing left, its strip was replaced with a dash. On
     the detail page the same test emptied the thumbnail strip and left the one
     picture above it. So the portal looked as though it could not load its
     images, and the images were fine - they were being thrown away after they
     had already arrived.

     The exact size cannot make that mistake. The tile is one fixed file the
     host serves in place of a picture that does not exist, always 128 by 96, and
     a photograph that measures anything else is a photograph. */
  var TILE_W = 128, TILE_H = 96;

  function isMissing(img) {
    return img.complete && img.naturalWidth === TILE_W && img.naturalHeight === TILE_H;
  }

  function prune() {
    var strip = document.querySelector('.pb-thumbs');
    var sheetBox = document.querySelector('.pb-sheet');
    var body = document.querySelector('.pb-body');

    if (sheetBox) {
      var sh = sheetBox.querySelector('img');
      if (sh && isMissing(sh)) {
        sheetBox.remove();
        if (body) body.classList.add('is-solo');
      }
    }

    if (strip) {
      strip.querySelectorAll('img').forEach(function (img) {
        if (isMissing(img)) img.remove();
      });
      var left = strip.querySelectorAll('img');
      if (left.length < 2) strip.style.display = 'none';

      // the A-D letters only mean anything for pictures that exist
      var letters = document.querySelectorAll('.pb-abcd .ltr');
      letters.forEach(function (el, i) {
        if (i >= left.length) {
          el.classList.add('is-off');
          el.removeAttribute('href');
        }
      });
    }
  }

  // run once everything has had a chance to load, and again on each late arrival
  if (document.readyState === 'complete') prune();
  window.addEventListener('load', prune);
  document.querySelectorAll('.pb-thumbs img, .pb-sheet img').forEach(function (img) {
    img.addEventListener('load', prune);
    img.addEventListener('error', prune);
  });

  /* The same tile turns up in the listings.
     A row asks for two photographs and the sheet, but plenty of lots were
     photographed once. The image host answers for the missing ones with a
     128x96 "no image" tile rather than a 404, so the row showed a stretched
     grey placeholder next to the real picture and it read as a broken image.
     It is only recognisable once loaded - the address gives nothing away - so
     each one is measured as it arrives. Load does not bubble; the listener has
     to capture. */
  function hideTile(e) {
    var img = e.target;
    if (!img.classList || !img.classList.contains('lot-thumb')) return;
    if (e.type === 'error' || isMissing(img)) {
      img.style.display = 'none';
      var box = img.parentElement;
      if (box && !box.querySelector('.lot-thumb:not([style*="none"])')) {
        box.innerHTML = '<span class="no-thumb">—</span>';
      }
    }
  }
  document.addEventListener('load', hideTile, true);
  document.addEventListener('error', hideTile, true);
  // anything already loaded before this ran
  document.querySelectorAll('.lot-shots .lot-thumb').forEach(function (img) {
    if (isMissing(img)) hideTile({ target: img, type: 'load' });
  });
})();

/* ----------------------------------------------------------- prod. year
   The chassis code, the number after it - and the answer appears under the box.
   It used to be two searches of our own list that both landed the reader back
   on the auction page; see the note in car-details.php.

   The request goes to our own server, which asks jpauc: jpauc's answer carries
   no cross-origin header, so the browser cannot ask it directly.

   The maker fills itself in as the code is typed, which is what jpauc's page
   does and the reason a buyer there only has to copy what is on the document.
   It is still a box they can correct; it is no longer a question they have to
   answer before the lookup will run at all. */
(function () {
  var box = document.getElementById('pbVin');
  if (!box) { return; }
  var go    = document.getElementById('vinGo');
  var goSt  = document.getElementById('vinStockGo');
  var type  = document.getElementById('vinType');
  var no    = document.getElementById('vinNo');
  var maker = document.getElementById('vinMaker');
  var note  = document.getElementById('vinNote');
  var tbl   = document.getElementById('vinTbl');
  var body  = tbl.querySelector('tbody');
  var more  = document.getElementById('vinMore');
  var stock = document.getElementById('vinStock');

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }
  function cell(row, text, cls) { row.appendChild(el('td', cls || '', text)); }

  /* The model code and the serial out of whatever the box holds: "KDY221-1234567",
     or Pacific Boeki's prefixed "QDF-KDY221", or both at once. A last part of
     digits only is the serial; a two- or three-character first part in front of a
     longer code is a type-approval prefix, not the model. Otherwise the first part
     is the model and the rest the serial. The same reading as chassisParts() on
     the server. */
  function chassisParts(v) {
    var parts = String(v || '').toUpperCase().split(/[\s-]+/).filter(function (p) { return p; });
    var serial = '', model = '';
    if (parts.length > 1 && /^\d+$/.test(parts[parts.length - 1])) { serial = parts.pop(); }
    if (parts.length > 1 && parts[0].length <= 3 && parts[parts.length - 1].length >= 4) {
      model = parts[parts.length - 1];
    } else if (parts.length > 1 && !serial) {
      model = parts[0];
      serial = parts.slice(1).join('');
    } else {
      model = parts[parts.length - 1] || '';
    }
    return { model: model.replace(/[^A-Z0-9]/g, ''), serial: serial.replace(/[^A-Z0-9]/g, '') };
  }

  function say(msg, bad) {
    note.textContent = msg;
    note.hidden = !msg;
    note.className = 'pb-vin-note' + (bad ? ' is-bad' : '');
  }

  function ask() {
    // A buyer pastes the whole number as often as they split it.
    if (!no.value.trim() && /[\s-]/.test(type.value.trim())) {
      var cp = chassisParts(type.value);
      type.value = cp.model;
      no.value = cp.serial;
    }
    if (!type.value.trim() || !no.value.trim()) {
      say('Enter the chassis code and the number after it.', true);
      return;
    }
    tbl.hidden = true;
    body.innerHTML = '';
    more.hidden = true; more.innerHTML = '';
    stock.hidden = true; stock.innerHTML = '';
    say('Looking it up\u2026', false);
    go.disabled = true;

    var data = 'maker=' + encodeURIComponent(maker.value)
             + '&type=' + encodeURIComponent(type.value.trim())
             + '&number=' + encodeURIComponent(no.value.trim());
    var x = new XMLHttpRequest();
    x.open('POST', 'api/prod-year.php', true);
    x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    x.onreadystatechange = function () {
      if (x.readyState !== 4) { return; }
      go.disabled = false;
      var j = null;
      try { j = JSON.parse(x.responseText); } catch (e) { j = null; }
      if (!j) { say('The lookup did not answer. Try again shortly.', true); return; }
      if (j.error) { say(j.error, true); return; }
      if (!j.rows || !j.rows.length) {
        say('No record for that chassis number and maker.', true);
        return;
      }
      if (j.maker) { maker.value = j.maker; }
      say(j.chassis + '  \u00b7  ' + j.maker, false);
      j.rows.forEach(function (r) {
        var tr = document.createElement('tr');
        [r.ym, r.model, r.grade, r.seat].forEach(function (v) {
          var td = document.createElement('td');
          td.textContent = v || '\u2014';
          tr.appendChild(td);
        });
        body.appendChild(tr);
      });
      tbl.hidden = false;

      /* Everything else the same answer carried. */
      if (j.more && j.more.length) {
        more.appendChild(el('h4', 'pb-vin-h', 'Catalogue detail'));
        var dl = el('div', 'pb-vin-grid');
        j.more.forEach(function (m) {
          dl.appendChild(el('span', 'pb-vin-k', m.k));
          dl.appendChild(el('span', 'pb-vin-v', m.v));
        });
        more.appendChild(dl);
        more.hidden = false;
      }

      renderStock(j.stock);
    };
    x.send(data);
  }

  /* ---- what WE hold of this model.

     jpauc's answer describes a document. This describes the sale: how many, in
     which halls, of which years, at what money, and the first dozen lots with
     their numbers - each one openable, each chip a filtered list. It is the
     half the client asked for and the half jpauc has no way to give, because
     the model code is the only field their data and our catalogue share. */
  function chipInto(box, text, href, cls) {
    var n = href ? el('a', 'pb-vin-chip ' + (cls || ''), text)
                 : el('span', 'pb-vin-chip ' + (cls || ''), text);
    if (href) { n.href = href; }
    box.appendChild(n);
    return n;
  }
  function listUrl(model, extra) {
    return 'index.php?chassis_model=' + encodeURIComponent(model) + (extra || '');
  }

  function renderStock(st) {
    stock.innerHTML = '';
    stock.hidden = true;
    if (!st || !st.model) { return; }
    var total = (st.auction || 0) + (st.fixed || 0);
    if (!total) {
      stock.appendChild(el('p', 'pb-vin-none',
        'We are not carrying any ' + st.model + ' at the moment.'));
      stock.hidden = false;
      return;
    }

    stock.appendChild(el('h4', 'pb-vin-h', 'Our stock \u2014 chassis model ' + st.model));

    var sum = el('div', 'pb-vin-chips');
    if (st.auction) {
      chipInto(sum, st.auction + (st.capped ? '+' : '')
                    + (st.auction === 1 ? ' vehicle' : ' vehicles') + ' in the auction',
               listUrl(st.model), 'is-key');
    }
    if (st.price) {
      /* The middle eight tenths, not the lowest and the highest: the feed writes
         "no start price" as 99,999,000 and as 77,777,000, and either one alone
         made the range meaningless. */
      chipInto(sum, 'Start price mostly \u00a5 ' + st.price.min + ' \u2013 \u00a5 '
                    + st.price.max + '  \u00b7  middle \u00a5 ' + st.price.avg);
    }
    stock.appendChild(sum);

    if (st.halls && st.halls.length) {
      stock.appendChild(el('div', 'pb-vin-lab', 'By auction hall'));
      var hb = el('div', 'pb-vin-chips');
      st.halls.forEach(function (h) {
        chipInto(hb, h.v + ' (' + h.n + ')',
                 listUrl(st.model, '&auction%5B%5D=' + encodeURIComponent(h.v)));
      });
      stock.appendChild(hb);
    }

    if (st.years && st.years.length) {
      stock.appendChild(el('div', 'pb-vin-lab', 'By year'));
      var yb = el('div', 'pb-vin-chips');
      st.years.forEach(function (y) {
        chipInto(yb, y.v + ' (' + y.n + ')',
                 listUrl(st.model, '&year_min=' + y.v + '&year_max=' + y.v),
                 st.matchYear === y.v ? 'is-key' : '');
      });
      stock.appendChild(yb);
    }

    if (st.days && st.days.length) {
      stock.appendChild(el('div', 'pb-vin-lab', 'By sale day'));
      var db = el('div', 'pb-vin-chips');
      st.days.forEach(function (d) {
        chipInto(db, d.v + ' (' + d.n + ')',
                 listUrl(st.model, '&auction_on%5B%5D=' + encodeURIComponent(d.v)));
      });
      stock.appendChild(db);
    }

    if (st.lots && st.lots.length) {
      var wrap = el('div', 'pb-vin-scroll');
      var t = el('table', 'pb-vin-tbl');
      var th = el('tr');
      ['Lot No.', 'Auction', 'Date', 'Vehicle', 'Year', 'Grade', 'Auc.Grade',
       'Colour', 'KM', 'Start Price'].forEach(function (h) {
        th.appendChild(el('th', '', h));
      });
      t.appendChild(el('thead')).appendChild(th);
      var tb = el('tbody');
      st.lots.forEach(function (r) {
        var tr = el('tr', r.match ? 'is-match' : '');
        var td = el('td');
        var a = el('a', 'pb-vin-lot', r.lot || '\u2014');
        a.href = 'car-details.php?id=' + encodeURIComponent(r.id);
        td.appendChild(a);
        tr.appendChild(td);
        cell(tr, r.hall || '\u2014');
        cell(tr, r.day ? (r.day + (r.time ? ' ' + r.time : '')) : '\u2014');
        cell(tr, r.name || '\u2014');
        cell(tr, r.year || '\u2014');
        cell(tr, r.grade || '\u2014');
        cell(tr, r.rating || '\u2014');
        cell(tr, r.color || '\u2014');
        cell(tr, r.km || '\u2014');
        cell(tr, r.price ? ('\u00a5 ' + r.price) : '\u2014');
        tb.appendChild(tr);
      });
      t.appendChild(tb);
      wrap.appendChild(t);
      stock.appendChild(wrap);

      if (total > st.lots.length) {
        var all = el('a', 'pb-vin-all', 'See all ' + total + ' \u2192');
        all.href = listUrl(st.model);
        stock.appendChild(all);
      }
    }
    stock.hidden = false;
  }

  /* Our stock on its own - no chassis number, nothing asked of jpauc. */
  function askStock() {
    var code = chassisParts(type.value).model;
    if (code.length < 3) { say('Enter the chassis model, e.g. NHP10.', true); return; }
    tbl.hidden = true; body.innerHTML = '';
    more.hidden = true; more.innerHTML = '';
    stock.hidden = true; stock.innerHTML = '';
    say('Looking through our stock\u2026', false);
    goSt.disabled = true;
    var x = new XMLHttpRequest();
    x.open('POST', 'api/prod-year.php', true);
    x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    x.onreadystatechange = function () {
      if (x.readyState !== 4) { return; }
      goSt.disabled = false;
      var j = null;
      try { j = JSON.parse(x.responseText); } catch (e) { j = null; }
      if (!j) { say('That did not answer. Try again shortly.', true); return; }
      if (j.error) { say(j.error, true); return; }
      say('', false);
      renderStock(j.stock);
    };
    x.send('stock=1&type=' + encodeURIComponent(code));
  }

  /* The maker, from the code, while it is being typed - jpauc's own page asks
     /vin/maker_frame_no/<code> half a second after the last key, and so does
     this, through the same proxy. Nothing is asked until typing stops, and a
     maker the reader has chosen for themselves is left alone. */
  var lookAt = 0, lastFrame = '';
  function guessMaker() {
    var code = chassisParts(type.value).model;
    if (code.length < 3 || code === lastFrame) { return; }
    lastFrame = code;
    var x = new XMLHttpRequest();
    x.open('GET', 'api/prod-year.php?frame=' + encodeURIComponent(code), true);
    x.onreadystatechange = function () {
      if (x.readyState !== 4) { return; }
      var j = null;
      try { j = JSON.parse(x.responseText); } catch (e) { return; }
      if (j && j.maker) { maker.value = j.maker; }
    };
    x.send();
  }

  go.addEventListener('click', ask);
  goSt.addEventListener('click', askStock);
  type.addEventListener('keyup', function () {
    clearTimeout(lookAt);
    lookAt = setTimeout(guessMaker, 500);
  });
  [type, no].forEach(function (el) {
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); ask(); }
    });
  });
})();

/* ------------------------------------------------------ show/hide a password
   Masked by default: a staff password typed in plain text is readable by
   whoever is standing behind the desk. The eye reveals it deliberately. */
(function () {
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.pw-eye') : null;
    if (!btn) return;
    var input = document.getElementById(btn.getAttribute('data-for'));
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.classList.toggle('is-on', show);
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });
})();

/* ------------------------------------------------- permission group toggles
   One tick sets or clears a whole group, and the group's own box shows where
   it stands: on when all of them are, off when none is, and half-way when it
   is neither. Setting eleven boxes one at a time is how a role ends up half
   granted and nobody notices which half. */
(function () {
  var groups = document.querySelectorAll('.perm-group');
  if (!groups.length) return;

  groups.forEach(function (group) {
    var all = group.querySelector('.perm-group-all');
    var boxes = group.querySelectorAll('.perm-item input[type=checkbox]');
    var count = group.querySelector('.perm-count');
    if (!all || !boxes.length) return;

    function refresh() {
      var on = 0;
      boxes.forEach(function (b) { if (b.checked) on++; });
      all.checked = (on === boxes.length);
      // neither all nor none: the box says so rather than lying either way
      all.indeterminate = (on > 0 && on < boxes.length);
      if (count) count.textContent = on + '/' + boxes.length;
    }

    all.addEventListener('change', function () {
      boxes.forEach(function (b) { if (!b.disabled) b.checked = all.checked; });
      refresh();
    });
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });

    refresh();
  });
})();

/* ---------------------------------------------------------------------------
   Typing a reply moves the enquiry to Answered.

   The server does this too, and the server is what decides - this is only so
   the person can see it before they press Save rather than after. Someone who
   then picks a status themselves has said what they mean, and is left alone
   from that point on.
   --------------------------------------------------------------------------- */
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.enq-form').forEach(function (form) {
      var box = form.querySelector('textarea[name="response"]');
      var sel = form.querySelector('select[name="status"]');
      if (!box || !sel) { return; }

      var chosen = false;
      sel.addEventListener('change', function () { chosen = true; });

      box.addEventListener('input', function () {
        if (chosen) { return; }
        var want = box.value.trim() !== '' ? 'answered' : sel.dataset.was;
        if (sel.dataset.was === undefined) { sel.dataset.was = sel.value; want = box.value.trim() !== '' ? 'answered' : sel.value; }
        if (want === 'closed') { return; }
        if (sel.value !== want && [].some.call(sel.options, function (o) { return o.value === want; })) {
          sel.value = want;
        }
      });
    });
  });
})();
