/**
 * Japan's clock, and the language picker.
 *
 * The clock is the portal's own: every lot closes on Tokyo time, so the header
 * shows Tokyo time whatever the reader's own zone happens to be. It is worked
 * out from the time zone, not from an offset - Japan does not keep summer time
 * today, but hard-coding +9 is the kind of thing that quietly breaks.
 *
 * The picker drives Google's translate widget. That widget ships with its own
 * control: a grey bar across the top carrying Google's name, plus a banner
 * frame that pushes the whole page down forty pixels and leaves the header
 * floating. So the widget is loaded hidden and this select speaks to it, which
 * keeps the header looking like the rest of the header.
 */
(function () {
  'use strict';

  /* ------------------------------------------------------------ Japan clock */
  var out = document.querySelector('#jstClock .jst-t');
  if (out) {
    var fmt;
    try {
      fmt = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Tokyo',
        weekday: 'short', day: '2-digit', month: 'short',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        hour12: false
      });
    } catch (e) {
      fmt = null;     // very old browser: the server-rendered time simply stays
    }
    if (fmt) {
      var tick = function () {
        var p = {};
        fmt.formatToParts(new Date()).forEach(function (x) { p[x.type] = x.value; });
        out.textContent = p.weekday + ' ' + p.day + ' ' + p.month
                        + ' · ' + p.hour + ':' + p.minute + ':' + p.second;
      };
      tick();
      setInterval(tick, 1000);
    }
  }

  /* -------------------------------------------------------- language picker */
  var pick = document.getElementById('langPick');
  if (!pick) return;

  // Google remembers the choice in a cookie of its own; read it back so the
  // select still shows the language the reader is actually looking at after a
  // page load, rather than snapping back to English.
  function current() {
    var m = document.cookie.match(/(?:^|;\s*)googtrans=([^;]+)/);
    if (!m) return 'en';
    var parts = decodeURIComponent(m[1]).split('/');
    return parts[2] || 'en';
  }

  // Changing the widget's own select in place is unreliable: it sets the cookie
  // but often leaves the page in the old language until something else nudges
  // it. Writing the cookie and reloading is what the widget does on a fresh
  // load anyway, and it always takes.
  pick.addEventListener('change', function () {
    var lang = pick.value;
    var value = (lang === 'en') ? '/en/en' : '/en/' + lang;
    var host = location.hostname;

    document.cookie = 'googtrans=' + value + ';path=/';
    // also at the registered domain, so the choice survives a move between
    // www and the bare host
    var bare = host.split('.').slice(-2).join('.');
    if (bare && bare !== host) {
      document.cookie = 'googtrans=' + value + ';path=/;domain=.' + bare;
    }
    location.reload();
  });

  window.googleTranslateElementInit = function () {
    /* global google */
    new google.translate.TranslateElement({
      pageLanguage: 'en',
      autoDisplay: false
    }, 'google_translate_element');

    // reflect whatever is already in force
    var now = current();
    for (var i = 0; i < pick.options.length; i++) {
      if (pick.options[i].value === now) { pick.selectedIndex = i; break; }
    }
  };

  var s = document.createElement('script');
  s.src = 'https://translate.google.com/translate_a/element.js'
        + '?cb=googleTranslateElementInit';
  s.async = true;
  document.head.appendChild(s);

  /* The widget sets `top` on <body> to make room for a banner it then hides.
     Undo it whenever it reappears - it comes back on every language change. */
  var fix = function () {
    if (document.body && document.body.style.top) document.body.style.top = '';
    var html = document.documentElement;
    if (html.style.top) html.style.top = '';
  };
  setInterval(fix, 400);
})();
