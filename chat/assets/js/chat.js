/* SBK Chat — the browser half.
   ---------------------------------------------------------------------------
   One file, no framework and nothing fetched from anywhere else. The owner's
   instruction was that this must never make the site hang, and every library a
   page pulls from somebody else's CDN is a request that can stall in front of
   everything after it.

   The whole screen is driven by one repeating call to api/poll.php, which
   answers with the new messages, the side list and the number waiting. See the
   note at the top of that file for why it polls rather than holding a
   connection open: this account allows thirty concurrent requests in total
   across twenty-eight sites, so a held connection per open chat would stop the
   auction portal as surely as the chat.
   ------------------------------------------------------------------------- */
(function () {
'use strict';

var M       = window.SBK || {};
var isGuest = (M.mode === 'guest');

var el = function (id) { return document.getElementById(id); };
var sideList = el('sideList'), scroll = el('scroll'), compose = el('compose');
var box = el('box'), sendBtn = el('sendBtn'), micBtn = el('micBtn');
var picBtn = el('picBtn'), fileIn = el('fileIn'), emojiBtn = el('emojiBtn');
var emojiPad = el('emojiPad'), recBar = el('recBar'), recTime = el('recTime');
var headAv = el('headAv'), headName = el('headName'), headSub = el('headSub');
var bellDot = el('bellDot'), body = el('body'), backBtn = el('backBtn');

var state = {
  thread: 0,          // which conversation is open
  group: 0,           // or which group - never both, the pane holds one
  to: 0,              // for a visitor: which member of staff
  lastId: 0,          // the newest message already on screen
  title: document.title,
  unread: 0,
  busy: false,
  seen: {}            // ids already drawn, so a retry never doubles a bubble
};

/* ---------------------------------------------------------------- helpers */

function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/** Bare links become links, and nothing else in the text is trusted. */
function linkify(s) {
  return esc(s).replace(/\bhttps?:\/\/[^\s<]+/g, function (u) {
    return '<a href="' + u + '" target="_blank" rel="noopener noreferrer">' + u + '</a>';
  });
}

function clockOf(iso) {
  var d = new Date(String(iso).replace(' ', 'T') + 'Z');
  if (isNaN(d)) { d = new Date(); }
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function dayOf(iso) {
  var d = new Date(String(iso).replace(' ', 'T') + 'Z');
  if (isNaN(d)) { return ''; }
  var today = new Date(), y = new Date();
  y.setDate(today.getDate() - 1);
  var same = function (a, b) { return a.toDateString() === b.toDateString(); };
  if (same(d, today)) { return 'Today'; }
  if (same(d, y))     { return 'Yesterday'; }
  return d.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
}

/* A face, in the company's colours and nobody else's.
   ---------------------------------------------------------------------------
   The server sends a number it worked out from the person's name, which used to
   be fed straight into hsl() as a hue. That gave every person a different point
   on the colour wheel, and a column of a dozen random hues is the loudest thing
   a screen can do - it was the main reason the desk looked like a toy.

   The same stable number now picks one of six discs mixed from the logo's own
   red, navy and blue (.av-0 … .av-5 in the stylesheet), so one person is always
   one colour and the column reads as one company. */
var FACES = 6;

function faceClass(hue) {
  return 'av-' + (Math.abs(parseInt(hue, 10) || 0) % FACES);
}

function avatar(node, ini, hue, small) {
  node.textContent = ini || '?';
  node.style.background = '';
  node.className = 'av' + (small ? ' sm' : '') + ' ' + faceClass(hue);
}

function atBottom() {
  return scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 90;
}
function toBottom() { scroll.scrollTop = scroll.scrollHeight; }

function post(url, data, done, fail) {
  var x = new XMLHttpRequest();
  x.open('POST', url, true);
  x.onreadystatechange = function () {
    if (x.readyState !== 4) { return; }
    var j = null;
    try { j = JSON.parse(x.responseText); } catch (e) { j = null; }
    if (j && j.ok) { done(j); }
    else if (j && j.signedout) { location.href = 'index.php'; }
    else if (fail) { fail((j && j.error) || 'That did not go through. Try again.'); }
  };
  if (data instanceof FormData) {
    x.send(data);
  } else {
    x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    x.send(data);
  }
}

/* ------------------------------------------------------------ the ticks
   One grey  — the server has it.
   Two grey  — the other end has fetched it.
   Two blue  — the other end had the conversation open.
   Only ever drawn on our own messages: a tick on somebody else's message
   would be telling them something they already know. */
function ticks(m) {
  if (!m.mine) { return ''; }
  var seen = m.read ? ' seen' : '';
  if (!m.delivered && !m.read) {
    return '<svg class="tick" viewBox="0 0 20 12"><path d="M2 6.5l3.4 3.4L12 3"/></svg>';
  }
  return '<svg class="tick' + seen + '" viewBox="0 0 20 12">'
       + '<path d="M1 6.5l3.4 3.4L11 3"/><path d="M7.5 9.9L14 3"/></svg>';
}

/* -------------------------------------------------------------- drawing */

var lastDay = '';

function bubble(m) {
  if (state.seen[m.id]) { return; }
  state.seen[m.id] = 1;

  var d = dayOf(m.at);
  if (d && d !== lastDay) {
    lastDay = d;
    var sep = document.createElement('div');
    sep.className = 'day';
    sep.innerHTML = '<span>' + esc(d) + '</span>';
    scroll.appendChild(sep);
  }

  var row = document.createElement('div');
  row.className = 'msg' + (m.mine ? ' me' : '');
  row.dataset.id = m.id;

  var inner = '';
  if (m.kind === 'call') {
    row.innerHTML = callLogHtml(m);
    scroll.appendChild(row);
    return;
  }
  if (m.kind === 'image') {
    inner = '<img class="photo" src="' + esc(m.media) + '" alt="Photo" loading="lazy">';
  } else if (m.kind === 'voice') {
    inner = '<audio controls preload="none" src="' + esc(m.media) + '"></audio>';
  } else {
    inner = '<p>' + linkify(m.body) + '</p>';
  }

  /* In a group, whose bubble it is. WhatsApp writes the name above the words
     for everybody but you, and without it a room of six cannot be followed. */
  if (m.who && !m.mine) {
    inner = '<b class="gwho" style="color:hsl(' + (m.hue | 0) + ',54%,34%)">'
          + esc(m.who) + '</b>' + inner;
  }

  row.innerHTML = '<div class="bub">' + inner
    + '<div class="foot"><span>' + esc(clockOf(m.at)) + '</span>' + ticks(m) + '</div></div>';
  scroll.appendChild(row);
}

/**
 * Ticks that have moved on since their bubble was drawn.
 *
 * Fed by `receipts` from every poll rather than by the message list: a message
 * already on the screen is never fetched again, so if the tick were only drawn
 * with the bubble it would stay on one grey mark for ever. What changes here is
 * three bytes, not the message.
 */
function refreshTicks(list) {
  for (var i = 0; i < list.length; i++) {
    var m = list[i];
    var foot = scroll.querySelector('.msg[data-id="' + m.id + '"] .foot');
    if (!foot) { continue; }
    var svg = foot.querySelector('.tick');
    if (!svg) { continue; }
    svg.outerHTML = ticks({ mine: true,
                            delivered: (m.d !== undefined ? m.d : m.delivered),
                            read:      (m.r !== undefined ? m.r : m.read) });
  }
}

function clearPane(msg, sub) {
  scroll.innerHTML = '<div class="empty"><b>' + esc(msg) + '</b>'
    + (sub ? '<span>' + esc(sub) + '</span>' : '') + '</div>';
  lastDay = '';
  state.seen = {};
}

/* ------------------------------------------------------------ the side list

   Redrawn only when it has actually changed, and that is not a nicety.

   The poll comes round every second and a half, and rebuilding the list each
   time tore every row out of the page and put a new one back. A row that is
   replaced under the pointer cannot be clicked - the browser loses the element
   between the press and the release - so a person reaching for a conversation
   would miss it, again and again, with nothing on screen to explain why. It
   also made the whole column flicker.

   So the list is reduced to one short string and compared with the last one.
   Nothing has changed, nothing is touched: no flicker, and a row stays still
   long enough to be clicked. */

var sideSig = '';

/* And never while a button is being held down on the list.
   ---------------------------------------------------------------------------
   The signature above stops the POINTLESS redraws. It cannot stop the ones that
   are genuinely needed - a message arrives, a number changes, somebody comes
   online - and those land at exactly the moment a person is reaching for the
   row, because the thing that changed is the thing they are reaching for.

   A browser sends `click` to the nearest ancestor SHARED by where the button
   went down and where it came up. Replace the row in between and that ancestor
   is the list itself, so `ev.target.closest('.person')` finds nothing and the
   conversation simply does not open. No error, no flicker: the row is just
   dead, and only under a real mouse - a scripted .click() is one event and
   always works, which is how this survived the first round of testing.

   So the list holds still for as long as the button is down. At most one poll's
   worth of change waits a fraction of a second, and the next poll draws it. */
var sideHeld = false;
sideList.addEventListener('pointerdown', function () { sideHeld = true; });
document.addEventListener('pointerup', function () { sideHeld = false; });
document.addEventListener('pointercancel', function () { sideHeld = false; });

function drawPeople(people, threads) {
  if (sideHeld) { return; }
  var sig = people.map(function (p) {
    var t = threads[p.id];
    return p.id + ':' + (p.online ? 1 : 0) + ':' + (t ? t.thread + '/' + t.unread : '0');
  }).join('|') + '#' + state.to;
  if (sig === sideSig) { return; }
  sideSig = sig;
  drawPeopleNow(people, threads);
}

function drawPeopleNow(people, threads) {
  if (!people.length) {
    sideList.innerHTML = '<div class="empty" style="height:180px">'
      + '<b>Nobody is listed yet</b><span>Our team will appear here.</span></div>';
    return;
  }
  var h = '';
  for (var i = 0; i < people.length; i++) {
    var p = people[i];
    var t = threads[p.id] || null;
    var n = t ? t.unread : 0;
    h += '<div class="person' + (state.to === p.id ? ' on' : '') + '" data-to="' + p.id
       + '" data-thread="' + (t ? t.thread : 0) + '" data-hue="' + (p.hue | 0) + '">'
       + '<div class="av ' + faceClass(p.hue) + '">' + esc(p.ini)
       + '<span class="dot' + (p.online ? ' up' : '') + '"></span></div>'
       + '<div class="nm"><b>' + esc(p.name) + '</b>'
       + '<span' + (p.online ? ' class="live"' : '') + '>'
       + (p.online ? 'Online now' : (p.role ? esc(p.role) : 'Offline')) + '</span></div>'
       + '<div class="meta">' + (n > 0 ? '<span class="pill">' + n + '</span>' : '') + '</div>'
       + '</div>';
  }
  sideList.innerHTML = h;
  openNamedPerson();
}

/* Somebody who clicked a name on the website arrives with that person already
   chosen, and the conversation opens itself. Once only - after that the list
   behaves normally, or a person who pressed Back would be dragged into the same
   conversation on every poll. */
function openNamedPerson() {
  var want = M.openTo | 0;
  if (!want) { return; }
  M.openTo = 0;
  var row = sideList.querySelector('.person[data-to="' + want + '"]');
  if (row) { row.click(); }
}

/* ---- an administrator's two steps ---------------------------------------

   The owner, 16 September 2026: "when the admin logs in, the list of all agents
   appears... when we click on an agent, another screen will open, and on the
   side it will show all the clients under Talal... then I'll click on a client,
   and their full chat will be shown to me."

   So for an administrator the side list has a step in front of it: the agents,
   then that agent's customers, then the conversation as before. `agentAt` is
   which agent is open - 0 means the list of agents itself.

   NOBODY ELSE'S SCREEN CHANGES. An agent has no `seesAll`, so every line below
   that matters to them is the same code it always was, and a customer never
   reaches this function at all. */
var agentAt = 0, agentName = '', lastList = [], lastSeesAll = false;
var sideHead = el('sideHead');

function drawThreads(list, seesAll) {
  if (sideHeld) { return; }
  lastList = list; lastSeesAll = seesAll;
  var sig = list.map(function (t) {
    return t.id + ':' + t.unread + ':' + (t.online ? 1 : 0) + ':' + (t.preview || '').length
         + ':' + (t.at || '');
  }).join('|') + '#' + state.thread + '@' + agentAt
    + '~' + lastGroups.map(function (g) {
        return g.id + ':' + g.unread + ':' + (g.at || '') + ':' + g.name + ':' + g.members;
      }).join('|') + '&' + state.group;
  if (sig === sideSig) { return; }
  sideSig = sig;
  drawThreadsNow(list, seesAll);
}

/** Step back out to the agents, or into one. */
function showAgent(sid, name) {
  agentAt = sid | 0;
  agentName = name || '';
  sideSig = '';                                   // the level changed: redraw
  drawThreadsNow(lastList, lastSeesAll);
}

function syncSideHead() {
  if (!sideHead || !lastSeesAll) { return; }
  if (agentAt) {
    sideHead.classList.add('is-back');
    sideHead.textContent = '‹ ' + (agentName || 'Agent');
    sideHead.title = 'Back to the agents';
  } else {
    sideHead.classList.remove('is-back');
    sideHead.textContent = 'Agents';
    sideHead.removeAttribute('title');
  }
}
if (sideHead) {
  sideHead.addEventListener('click', function () {
    if (agentAt) { showAgent(0, ''); }
  });
}

/** The agents, each with how many conversations they hold and what is waiting. */
function drawAgents(list) {
  var by = {}, order = [];
  list.forEach(function (t) {
    var k = t.sid | 0;
    if (!by[k]) {
      by[k] = { sid: k, name: t.staff || 'The desk', clients: 0, unread: 0, at: '' };
      order.push(k);
    }
    by[k].clients++;
    by[k].unread += (t.unread | 0);
    if ((t.at || '') > by[k].at) { by[k].at = t.at || ''; }
  });
  // Whoever was written to most recently sits at the top, as the conversations do.
  order.sort(function (a, b) { return (by[b].at || '').localeCompare(by[a].at || ''); });

  var h = '';
  order.forEach(function (k) {
    var a = by[k];
    h += '<div class="person" data-agent="' + a.sid + '" data-hue="' + ((a.sid * 47) % 360) + '">'
       + '<div class="av ' + faceClass((a.sid * 47) % 360) + '">' + esc(initialsOf(a.name)) + '</div>'
       + '<div class="nm"><b>' + esc(a.name) + '</b><span>'
       + a.clients + (a.clients === 1 ? ' conversation' : ' conversations') + '</span></div>'
       + '<div class="meta">'
       + (a.unread > 0 ? '<span class="pill">' + a.unread + '</span>' : '')
       + '</div></div>';
  });
  sideList.innerHTML = groupRowsHtml() + h;
  syncSideHead();
}

/** Initials for a name the server sent us as text. */
function initialsOf(name) {
  var p = String(name || '').trim().split(/\s+/);
  return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
}

function drawThreadsNow(list, seesAll) {
  if (seesAll && !agentAt) {
    if (!list.length) {
      sideList.innerHTML = groupRowsHtml() + '<div class="empty" style="height:180px">'
        + '<b>Nothing waiting</b><span>Conversations land under the agent holding them.</span></div>';
      syncSideHead();
      return;
    }
    drawAgents(list);
    return;
  }
  // Inside an agent, only that agent's customers.
  if (seesAll) { list = list.filter(function (t) { return (t.sid | 0) === agentAt; }); }
  syncSideHead();

  if (!list.length) {
    sideList.innerHTML = groupRowsHtml() + '<div class="empty" style="height:180px">'
      + '<b>Nothing waiting</b><span>New conversations land here.</span></div>';
    return;
  }
  var h = '';
  for (var i = 0; i < list.length; i++) {
    var t = list[i];
    h += '<div class="person' + (state.thread === t.id ? ' on' : '') + '" data-thread="' + t.id
       + '" data-hue="' + (t.hue | 0) + '" data-email="' + esc(t.email || '')
       + '" data-mine="' + (t.mine ? 1 : 0) + '">'
       + '<div class="av ' + faceClass(t.hue) + '">' + esc(t.ini)
       + '<span class="dot' + (t.online ? ' up' : '') + '"></span></div>'
       + '<div class="nm"><b>' + esc(t.name) + '</b><span>'
       + (t.preview ? esc(t.preview) : esc(t.email)) + '</span></div>'
       + '<div class="meta">'
       + (t.unread > 0 ? '<span class="pill">' + t.unread + '</span>' : '')
       + (seesAll && !agentAt && !t.mine && t.staff ? '<em>' + esc(t.staff) + '</em>' : '')
       + '</div></div>';
  }
  sideList.innerHTML = groupRowsHtml() + h;
}

/* ------------------------------------------------------------- opening one */

function openThread(threadId, toId, name, sub, ini, hue, live) {
  /* Pictures waiting in the tray belong to the conversation they were chosen
     in. Carrying them into the next one is how a customer's car ends up in
     somebody else's chat, so they are dropped when the conversation changes. */
  if (typeof clearTray === 'function') { clearTray(); }
  state.thread = threadId || 0;
  state.group  = 0;              // a conversation and a group are never both open
  state.to     = toId || 0;
  body.classList.remove('in-group');
  state.lastId = 0;
  state.seen   = {};
  lastDay = '';
  scroll.innerHTML = '';
  compose.hidden = false;
  el('paneHead').hidden = false;
  body.classList.add('has-thread');

  headName.textContent = name || '';
  headSub.textContent  = sub || '';
  headSub.removeAttribute('data-sig');         // let the next poll redraw it fully
  /* The green "live" style is the CUSTOMER'S view of a member of staff. On the
     desk the line is an e-mail address, and presence is added after it by
     syncHead() instead — a whole address in green reads as a link. */
  headSub.className    = (live && isGuest) ? 'live' : '';
  avatar(headAv, ini, hue, true);

  /* A customer can ring the member of staff they are talking to; a member of
     staff can ring the customer in THEIR OWN conversation — an administrator
     reading somebody else's gets no phone. The server enforces the same rule. */
  state.canCall = isGuest || !!arguments[7];
  setCallButtons(state.canCall, !!live);

  if (!state.thread) {
    clearPane('Say hello', 'This is the beginning of your conversation with ' + (name || 'us') + '.');
  }
  tick(true);
  box.focus();
}

sideList.addEventListener('click', function (ev) {
  var row = ev.target.closest ? ev.target.closest('.person') : null;
  if (!row) { return; }
  /* An agent's row is a step, not a conversation: it opens that agent's
     customers. Only an administrator ever sees one. */
  if (row.dataset.agent) {
    var nm = row.querySelector('.nm b');
    showAgent(parseInt(row.dataset.agent, 10) || 0, nm ? nm.textContent : '');
    return;
  }
  /* A group is opened, not stepped into: it is a conversation like any other,
     it simply has more than two people in it. */
  if (row.dataset.group) {
    var gnm = row.querySelector('.nm b'), gav = row.querySelector('.av');
    openGroup(parseInt(row.dataset.group, 10) || 0,
              gnm ? gnm.textContent : '',
              gav ? gav.textContent : '?',
              row.dataset.hue,
              parseInt(row.dataset.members || 0, 10));
    var onNow = sideList.querySelector('.person.on');
    if (onNow) { onNow.classList.remove('on'); }
    row.classList.add('on');
    return;
  }
  var nameEl = row.querySelector('.nm b'), subEl = row.querySelector('.nm span');
  var av = row.querySelector('.av');
  /* On the desk the line under a name in the LIST is the last message, but the
     line under the name in the HEADER is the customer's e-mail — so the header
     is given the address straight away instead of flashing the last message
     until the next poll corrects it. */
  openThread(parseInt(row.dataset.thread || 0, 10),
             parseInt(row.dataset.to || 0, 10),
             nameEl ? nameEl.textContent : '',
             row.dataset.email || (subEl ? subEl.textContent : ''),
             av ? av.firstChild.textContent : '?',
             row.dataset.hue,
             !!row.querySelector('.dot.up'),
             row.dataset.mine === '1');
  var on = sideList.querySelector('.person.on');
  if (on) { on.classList.remove('on'); }
  row.classList.add('on');
});

if (backBtn) {
  backBtn.addEventListener('click', function () {
    body.classList.remove('has-thread');
    compose.hidden = true;
    el('paneHead').hidden = true;
    state.thread = 0; state.to = 0; state.group = 0;
  });
}

/* --------------------------------------------------------------- the pulse */

var timer = null;

function tick(force) {
  if (state.busy && !force) { return; }
  state.busy = true;

  var q = 'api/poll.php?after=' + state.lastId
        + (state.group ? '&group=' + state.group
                       : (state.thread ? '&thread=' + state.thread : ''))
        + (document.hasFocus() ? '&open=1' : '');

  var x = new XMLHttpRequest();
  x.open('GET', q, true);
  x.onreadystatechange = function () {
    if (x.readyState !== 4) { return; }
    state.busy = false;
    var j = null;
    try { j = JSON.parse(x.responseText); } catch (e) { j = null; }
    if (!j) { return; }
    if (j.signedout) { location.href = 'index.php'; return; }
    if (!j.ok) { return; }

    // messages
    if (j.messages && j.messages.length) {
      var stick = atBottom();
      var fresh = 0;
      for (var i = 0; i < j.messages.length; i++) {
        var m = j.messages[i];
        if (m.id > state.lastId) { state.lastId = m.id; }
        if (!state.seen[m.id]) {
          if (scroll.querySelector('.empty')) { scroll.innerHTML = ''; }
          bubble(m);
          if (!m.mine) { fresh++; }
        }
      }
      if (stick) { toBottom(); }
      if (fresh) { chime(); }
    }
    // ticks that moved
    if (j.receipts) { refreshTicks(j.receipts); }

    // the side
    if (isGuest && j.people) { drawPeople(j.people, j.threads || {}); syncHead(j.people, null); }
    if (!isGuest && j.groups) { lastGroups = j.groups; }
    if (!isGuest && j.list)  { drawThreads(j.list, !!j.all); syncHead(null, j.list); }
    if (!isGuest && state.group) { syncGroupHead(); }

    // A call ringing for this person, wherever in the chat they are looking.
    if (j.call && j.call.incoming && j.call.state === 'ringing' && !cv.id) {
      incomingCall(j.call);
    }

    setUnread(j.unread || 0);
  };
  x.send();
}

/**
 * Keep the line under the name in the conversation's header honest.
 *
 * It was written once, when the conversation was opened, and then never again -
 * so somebody who came to their desk while you were typing stayed "Offline" at
 * the top of the screen even though the list beside it had already gone green.
 * Two places showing two different answers to the same question is the sort of
 * thing that makes a screen feel unfinished.
 */
function syncHead(people, list) {
  if (!state.thread && !state.to) { return; }

  var live = null, sub = null;
  var i;
  if (people) {
    for (i = 0; i < people.length; i++) {
      if (people[i].id === state.to) {
        live = people[i].online;
        sub  = live ? 'Online now' : (people[i].role || 'Offline');
        break;
      }
    }
  } else if (list) {
    /* The DESK's view of a customer: the e-mail stays, always.
       The owner looked at this line and said the e-mail under the name is
       exactly right — and it used to vanish, replaced by "Online now", at the
       very moment the customer was there to be answered. A member of staff
       reaching for that address to send a quotation should not have to wait
       for the customer to go away to see it. So presence is ADDED beside it. */
    for (i = 0; i < list.length; i++) {
      if (list[i].id === state.thread) {
        var mail = list[i].email || '';
        var on   = !!list[i].online;
        var html = esc(mail) + (on ? ' <i class="on-now">· Online now</i>' : '');
        if (headSub.getAttribute('data-sig') !== html) {
          headSub.innerHTML = html;
          headSub.setAttribute('data-sig', html);
        }
        if (headSub.className !== '') { headSub.className = ''; }
        setCallButtons(state.canCall, on);     // the phone follows the green dot
        return;
      }
    }
  }
  if (sub === null) { return; }

  if (headSub.textContent !== sub) { headSub.textContent = sub; }
  var want = live ? 'live' : '';
  if (headSub.className !== want) { headSub.className = want; }
  setCallButtons(state.canCall, !!live);
}

/* Faster while somebody is looking, slower when the tab is in the background.
   A window left open all day should cost almost nothing. */
function pace() {
  if (timer) { clearInterval(timer); }
  timer = setInterval(tick, document.hidden ? 6000 : 1500);
}
document.addEventListener('visibilitychange', function () {
  pace();
  if (!document.hidden) { tick(true); }
});

/* ------------------------------------------------------- waiting, and noise */

function setUnread(n) {
  var rose = (n > state.unread);
  state.unread = n;
  if (n > 0) {
    bellDot.textContent = n > 99 ? '99+' : n;
    bellDot.classList.add('on');
    document.title = '(' + n + ') ' + state.title;

    /* The bell moves once when the number goes UP, and not otherwise. A badge
       that is always animating is a badge nobody looks at; one that moves the
       moment something arrives is the whole point of having it. */
    if (rose) {
      var b = el('bell');
      b.classList.remove('ring');
      void b.offsetWidth;                    // let the browser drop the old run
      b.classList.add('ring');
    }
  } else {
    bellDot.classList.remove('on');
    el('bell').classList.remove('ring');
    document.title = state.title;
  }
}

/* A short tone, made in the browser. A sound file would be one more thing to
   fetch and one more thing to fail. */
var ac = null;
function chime() {
  if (document.hasFocus() && state.thread) { return; }
  try {
    ac = ac || new (window.AudioContext || window.webkitAudioContext)();
    var o = ac.createOscillator(), g = ac.createGain();
    o.connect(g); g.connect(ac.destination);
    o.type = 'sine'; o.frequency.value = 880;
    g.gain.setValueAtTime(0.0001, ac.currentTime);
    g.gain.exponentialRampToValueAtTime(0.16, ac.currentTime + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + 0.32);
    o.start(); o.stop(ac.currentTime + 0.34);
  } catch (e) { /* a browser that will not make a noise is not a fault */ }

  if (window.Notification && Notification.permission === 'granted' && document.hidden) {
    try { new Notification('SBK — new message'); } catch (e) {}
  }
}
if (window.Notification && Notification.permission === 'default') {
  document.addEventListener('click', function once() {
    document.removeEventListener('click', once);
    try { Notification.requestPermission(); } catch (e) {}
  });
}
el('bell').addEventListener('click', function () {
  var first = sideList.querySelector('.person .pill');
  if (!first) { return; }
  first.closest('.person').click();
  /* For an administrator that first click may have opened an AGENT rather than
     a conversation - the bell should still land on the person who is waiting,
     so it follows the count one step further in. */
  var next = sideList.querySelector('.person[data-thread] .pill');
  if (next) { next.closest('.person').click(); }
});

/* ---------------------------------------------------------------- sending */

function grow() {
  box.style.height = 'auto';
  box.style.height = Math.min(150, box.scrollHeight) + 'px';
}
box.addEventListener('input', grow);

function send() {
  var text = box.value.trim();
  /* Pictures waiting in the tray go first, in the order they were chosen, and
     anything typed beside them follows - the order they were composed in. */
  if (drafts.length) { sendDrafts(text); return; }
  if (!text) { return; }
  if (isGuest && !state.to) { return; }
  if (!isGuest && !state.thread && !state.group) { return; }

  box.value = ''; grow();
  sendBtn.disabled = true;

  var d = 'body=' + encodeURIComponent(text)
        + (isGuest ? '&to=' + state.to
                   : (state.group ? '&group=' + state.group : '&thread=' + state.thread));

  post('api/send.php', d, function (j) {
    sendBtn.disabled = false;
    if (j.thread) { state.thread = j.thread; }
    if (scroll.querySelector('.empty')) { scroll.innerHTML = ''; }
    if (j.message.id > state.lastId) { state.lastId = j.message.id; }
    bubble(j.message);
    toBottom();
    tick(true);
  }, function (msg) {
    sendBtn.disabled = false;
    box.value = text; grow();
    alert(msg);
  });
}

sendBtn.addEventListener('click', send);
box.addEventListener('keydown', function (ev) {
  // Enter sends; Shift+Enter is a new line — the habit every messenger built.
  if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); send(); }
});

/* ------------------------------------------------------------- a picture */

/* ------------------------------------------------------------- pictures ---

   SEVERAL AT ONCE. The owner's words: "we'll have to send multiple images —
   right now we can only select a single one". A buyer looking at a car wants
   the front, the back, the interior and the auction sheet, not four trips to
   the picker.

   They go ONE AT A TIME, in the order they were chosen, and not all at once.
   Twenty photographs fired in parallel would be twenty PHP processes at the
   same moment on an account whose ceiling is thirty for twenty-eight websites —
   the auction portal would stall while a customer's holiday snaps went up.
   One after another they cost one process at a time, arrive in order, and a
   failure in the middle stops cleanly instead of leaving a half-sent jumble.

   Twenty per batch. That is more photographs of one car than anybody takes,
   and it keeps one careless select-all of a camera roll from flooding the
   conversation — and this server's disk, which is the thing it is short of. */

var MAX_BATCH = 20;
var queue = [], sending = false, sentInBatch = 0, batchSize = 0;
var sendNote = null;

/* CHOSEN IS NOT SENT.

   A picture used to leave the moment it was picked: choose the wrong one and it
   was already in the customer's conversation, with no way back. They now wait in
   a tray above the box - each with a cross - and go only when Send is pressed,
   which is what every messenger does and what the owner asked for.

   The file is held, not uploaded, so removing one costs nothing and sends
   nothing. The thumbnail is drawn straight from the file in the browser
   (`createObjectURL`), so the tray needs no server at all; each address is
   handed back when the picture leaves, or the page would keep them all. */
var tray = el('tray'), trayStrip = el('trayStrip'), trayClear = el('trayClear');
var drafts = [];

function renderTray() {
  trayStrip.innerHTML = '';
  drafts.forEach(function (d, idx) {
    var wrap = document.createElement('div');
    wrap.className = 'tray-item';

    var img = document.createElement('img');
    img.src = d.url;
    img.alt = d.file.name || 'Picture waiting to be sent';

    var x = document.createElement('button');
    x.type = 'button';
    x.title = 'Remove this picture';
    x.setAttribute('aria-label', 'Remove this picture');
    x.textContent = '×';
    x.addEventListener('click', function () { removeDraft(idx); });

    wrap.appendChild(img);
    wrap.appendChild(x);
    trayStrip.appendChild(wrap);
  });
  tray.hidden = drafts.length === 0;
}

function removeDraft(i) {
  if (drafts[i]) {
    URL.revokeObjectURL(drafts[i].url);
    drafts.splice(i, 1);
  }
  renderTray();
}

function clearTray() {
  drafts.forEach(function (d) { URL.revokeObjectURL(d.url); });
  drafts = [];
  renderTray();
}

trayClear.addEventListener('click', clearTray);

picBtn.addEventListener('click', function () { fileIn.click(); });
fileIn.addEventListener('change', function () {
  var files = fileIn.files ? Array.prototype.slice.call(fileIn.files) : [];
  fileIn.value = '';                          // the same photo can be chosen again later
  if (!files.length) { return; }

  files = files.filter(function (f) { return /^image\//.test(f.type || ''); });
  if (!files.length) { alert('Only pictures can be sent this way.'); return; }

  // The ceiling counts what is already waiting, not just this trip to the picker.
  var room = MAX_BATCH - drafts.length;
  if (room <= 0) {
    alert('Up to ' + MAX_BATCH + ' pictures at a time. Send these first.');
    return;
  }
  if (files.length > room) {
    alert('Up to ' + MAX_BATCH + ' pictures at a time — the first ' + room + ' were added.');
    files = files.slice(0, room);
  }

  files.forEach(function (f) {
    drafts.push({ file: f, url: URL.createObjectURL(f) });
  });
  renderTray();
  box.focus();
});

/* "Sending 2 of 5 photos…" — a person who chose five pictures and sees one
   arrive should know the other four are coming and not tap send again. */
function showProgress() {
  if (!sendNote) {
    sendNote = document.createElement('div');
    sendNote.className = 'send-note';
    compose.parentNode.insertBefore(sendNote, compose);
  }
  if (batchSize <= 1) { sendNote.hidden = true; return; }
  sendNote.hidden = false;
  sendNote.textContent = 'Sending ' + Math.min(sentInBatch + 1, batchSize) + ' of ' + batchSize + ' photos…';
}

/* Send what is in the tray. The words wait until the last picture has landed so
   they read as a caption under them rather than arriving first out of nowhere;
   `pendingText` carries them across, and doneSending() posts them. */
var pendingText = '';

function sendDrafts(text) {
  if (isGuest && !state.to) { alert('Pick somebody to talk to first.'); return; }
  if (!isGuest && !state.thread && !state.group) { alert('Open a conversation first.'); return; }

  pendingText = text;
  box.value = ''; grow();

  drafts.forEach(function (d) { queue.push(d.file); });
  batchSize += drafts.length;
  clearTray();
  pump();
}

function doneSending() {
  sending = false;
  sentInBatch = 0;
  batchSize = 0;
  picBtn.disabled = micBtn.disabled = false;
  if (sendNote) { sendNote.hidden = true; }

  if (pendingText) {
    var t = pendingText;
    pendingText = '';
    box.value = t;
    send();
  }
}

function pump() {
  if (sending) { return; }
  if (!queue.length) { doneSending(); return; }
  sending = true;
  showProgress();
  var f = queue.shift();
  upload(f, 'image', 0, function () {
    sentInBatch++;
    sending = false;
    pump();
  }, function (msg) {
    /* One went wrong: the rest are dropped rather than sent out of order,
       and the person is told how far it got. */
    var left = queue.length;
    queue = [];
    /* Anything typed to go with them stays in the box rather than being sent on
       its own: a caption with nothing above it reads as a non-sequitur, and the
       person may want to try the pictures again. */
    if (pendingText) { box.value = pendingText; pendingText = ''; grow(); }
    doneSending();
    alert(msg + (left ? '\n\n' + left + ' more picture' + (left === 1 ? ' was' : 's were') + ' not sent.' : ''));
  });
}

function upload(blob, kind, secs, onDone, onFail) {
  if (isGuest && !state.to) { if (onFail) { onFail('Pick somebody to talk to first.'); } return; }
  if (!isGuest && !state.thread && !state.group) { if (onFail) { onFail('Open a conversation first.'); } return; }

  var fd = new FormData();
  fd.append('file', blob, kind === 'voice' ? 'voice.webm' : (blob.name || 'photo.jpg'));
  fd.append('kind', kind);
  fd.append('secs', secs || 0);
  if (isGuest) { fd.append('to', state.to); }
  else if (state.group) { fd.append('group', state.group); }
  else { fd.append('thread', state.thread); }

  picBtn.disabled = micBtn.disabled = true;
  post('api/upload.php', fd, function (j) {
    if (!sending) { picBtn.disabled = micBtn.disabled = false; }
    if (j.thread) { state.thread = j.thread; }
    if (scroll.querySelector('.empty')) { scroll.innerHTML = ''; }
    if (j.message.id > state.lastId) { state.lastId = j.message.id; }
    bubble(j.message);
    toBottom();
    tick(true);
    if (onDone) { onDone(); }
  }, function (msg) {
    picBtn.disabled = micBtn.disabled = false;
    if (onFail) { onFail(msg); } else { alert(msg); }
  });
}

/* ---------------------------------------------------------- a voice note */

var rec = null, chunks = [], recStart = 0, recTick = null;

micBtn.addEventListener('click', function () {
  if (rec && rec.state === 'recording') { stopRec(); return; }
  if (!navigator.mediaDevices || !window.MediaRecorder) {
    alert('This browser cannot record sound.');
    return;
  }
  navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
    chunks = [];
    rec = new MediaRecorder(stream);
    rec.ondataavailable = function (e) { if (e.data && e.data.size) { chunks.push(e.data); } };
    rec.onstop = function () {
      stream.getTracks().forEach(function (t) { t.stop(); });
      clearInterval(recTick);
      recBar.classList.remove('on');
      box.style.display = '';
      micBtn.classList.remove('rec');
      var secs = Math.round((Date.now() - recStart) / 1000);
      if (chunks.length && secs >= 1) {
        upload(new Blob(chunks, { type: rec.mimeType || 'audio/webm' }), 'voice', secs);
      }
    };
    rec.start();
    recStart = Date.now();
    recBar.classList.add('on');
    box.style.display = 'none';
    micBtn.classList.add('rec');
    recTick = setInterval(function () {
      var s = Math.round((Date.now() - recStart) / 1000);
      recTime.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
      if (s >= 300) { stopRec(); }        // five minutes is a long voice note
    }, 250);
  }).catch(function () {
    alert('We could not reach your microphone. Check the browser has permission.');
  });
});

function stopRec() { if (rec && rec.state === 'recording') { rec.stop(); } }

/* ------------------------------------------------------------------ emoji */

var EMOJI = ('😀 😃 😄 😁 😆 😅 😂 🤣 😊 🙂 😉 😍 🥰 😘 😋 😎 🤩 🥳 🤔 🤨 😐 😴 😌 😔 '
 + '😢 😭 😤 😠 😡 🤯 😱 😳 🥺 😬 🙄 😷 🤒 🤕 🤢 🤧 👍 👎 👌 🙏 👏 🙌 💪 🤝 ✌️ 🤞 '
 + '👋 ✋ 🖐️ 👊 ❤️ 🧡 💛 💚 💙 💜 🖤 💯 🔥 ✨ ⭐ 🎉 🎊 ✅ ❌ ⚠️ ❓ ❗ 💬 📌 📎 '
 + '🚗 🚙 🏎️ 🚐 🚚 🛻 🏍️ 🚢 ✈️ 🔑 💰 💵 💳 🧾 📦 📍 🕐 📅 📞 📧').split(' ');

emojiPad.innerHTML = EMOJI.map(function (e) {
  return '<button type="button">' + e + '</button>';
}).join('');

emojiBtn.addEventListener('click', function (ev) {
  ev.stopPropagation();
  emojiPad.classList.toggle('on');
});
emojiPad.addEventListener('click', function (ev) {
  if (ev.target.tagName !== 'BUTTON') { return; }
  var at = box.selectionStart || box.value.length;
  box.value = box.value.slice(0, at) + ev.target.textContent + box.value.slice(at);
  box.focus();
  box.selectionStart = box.selectionEnd = at + ev.target.textContent.length;
  grow();
});
document.addEventListener('click', function () { emojiPad.classList.remove('on'); });

/* ------------------------------------------------------- a picture, opened */

/* Every picture in the conversation, not only the one that was clicked.

   It used to open one and stop there: to see the next photograph of a car you
   closed this, found the next bubble and opened that. Now the whole
   conversation is the gallery - arrows, arrow keys and a swipe on a phone -
   starting at whichever picture was clicked.

   The list is gathered at the moment of opening rather than kept: pictures
   arrive while a conversation is open, and a list built once would be stale by
   the time somebody had looked at three of them. */
var lb = el('lb'), lbImg = el('lbImg'), lbPrev = el('lbPrev'),
    lbNext = el('lbNext'), lbClose = el('lbClose'), lbCount = el('lbCount');
var shots = [], shotAt = 0;

function showShot(i) {
  if (!shots.length) { return; }
  shotAt = (i + shots.length) % shots.length;      // walks round, as a gallery does
  lbImg.src = shots[shotAt].src;
  lbCount.textContent = (shotAt + 1) + ' / ' + shots.length;
  var alone = shots.length < 2;
  lbCount.hidden = lbPrev.hidden = lbNext.hidden = alone;
}
function openShot(img) {
  shots = Array.prototype.slice.call(scroll.querySelectorAll('img.photo'));
  var i = shots.indexOf(img);
  showShot(i < 0 ? 0 : i);
  lb.classList.add('on');
}
function closeShot() { lb.classList.remove('on'); lbImg.src = ''; shots = []; }

scroll.addEventListener('click', function (ev) {
  if (ev.target.classList && ev.target.classList.contains('photo')) { openShot(ev.target); }
});
lbPrev.addEventListener('click', function (ev) { ev.stopPropagation(); showShot(shotAt - 1); });
lbNext.addEventListener('click', function (ev) { ev.stopPropagation(); showShot(shotAt + 1); });
lbClose.addEventListener('click', function (ev) { ev.stopPropagation(); closeShot(); });
// The picture itself is not a way out - people tap it to look closer.
lbImg.addEventListener('click', function (ev) { ev.stopPropagation(); });
lb.addEventListener('click', closeShot);

document.addEventListener('keydown', function (ev) {
  if (!lb.classList.contains('on')) { return; }
  if (ev.key === 'ArrowRight') { ev.preventDefault(); showShot(shotAt + 1); }
  if (ev.key === 'ArrowLeft')  { ev.preventDefault(); showShot(shotAt - 1); }
});

/* A swipe, for the thumb that already expects one. Forty-five pixels, so a tap
   with a little travel in it is not read as one, and only when the movement is
   more sideways than up - or scrolling the picture would flick past it. */
var swX = 0, swY = 0;
lb.addEventListener('touchstart', function (ev) {
  if (!ev.touches || ev.touches.length !== 1) { return; }
  swX = ev.touches[0].clientX; swY = ev.touches[0].clientY;
}, { passive: true });
lb.addEventListener('touchend', function (ev) {
  var t = ev.changedTouches && ev.changedTouches[0];
  if (!t) { return; }
  var dx = t.clientX - swX, dy = t.clientY - swY;
  if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) { showShot(shotAt + (dx < 0 ? 1 : -1)); }
}, { passive: true });
document.addEventListener('keydown', function (ev) {
  if (ev.key === 'Escape') { lb.classList.remove('on'); emojiPad.classList.remove('on'); }
});

/* ====================================================================== calls

   Voice and video, browser to browser (WebRTC). The sound and the picture
   never pass through our server — which is why a call cannot slow the website
   down — and the only thing that does is the handshake in api/call.php.

   The flow, both ways round:
     CALLER   camera/mic -> offer -> POST start -> ring -> answer arrives -> connected
     CALLEE   poll says "ringing" -> Answer -> camera/mic -> offer in -> answer out -> connected

   Each side gathers ALL of its connection details before sending, rather than
   trickling them across one by one, because the channel between the two is a
   poll: two messages that arrive in a second beat forty that take a minute. */

var callBox = el('callBox'), callBtns = el('callBtns');
var cv = {
  id: 0, kind: 'voice', incoming: false, pc: null, stream: null,
  polling: null, clock: null, startedAt: 0, remoteSet: false,
  ringer: null, closing: false, ice: null, iceAt: 0, peer: '', mine: true
};

function callLogHtml(m) {
  var b = String(m.body || '').split('|');
  var video = (b[0] === 'video'), how = b[1] || '', secs = parseInt(b[2] || '0', 10);
  var what = video ? 'Video call' : 'Voice call';
  var line = what, sub = '', missed = false;
  if (how === 'ended')     { sub = dur(secs); }
  if (how === 'missed')    { line = m.mine ? what + ' — no answer' : 'Missed ' + what.toLowerCase(); missed = !m.mine; }
  if (how === 'cancelled') { line = m.mine ? what + ' — cancelled' : 'Missed ' + what.toLowerCase(); missed = !m.mine; }
  if (how === 'declined')  { line = m.mine ? what + ' — declined' : what + ' — you declined'; }
  if (how === 'failed')    { line = what + ' — could not connect'; }
  var icon = video
    ? '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="14" height="12" rx="2.5"/><path d="M16 10.5l6-3.5v10l-6-3.5z"/></svg>'
    : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></svg>';
  return '<div class="bub callog' + (missed ? ' missed' : '') + '">'
       + '<span class="ci">' + icon + '</span>'
       + '<div><p>' + esc(line) + '</p>' + (sub ? '<small>' + esc(sub) + '</small>' : '')
       + '<div class="foot"><span>' + esc(clockOf(m.at)) + '</span></div></div></div>';
}

function dur(s) {
  s = Math.max(0, s | 0);
  var h = Math.floor(s / 3600), mnt = Math.floor((s % 3600) / 60), sec = s % 60;
  return (h ? h + ':' + ('0' + mnt).slice(-2) : mnt) + ':' + ('0' + sec).slice(-2);
}

/* ---- the buttons in the header: only for the two people in the conversation.
   They are NOT dead when the other person is away.

   They used to be, and the owner corrected it (20 September 2026): "if the
   person isn't online, why is the call button getting disabled? The call should
   still go through." He is right - it is how a telephone behaves. Ringing
   somebody who is not at their screen is not a mistake to prevent:
     * the chat pushes a ringing notification to their phone, so a shut app
       still rings (includes/push.php, pushCallRinging);
     * if nobody picks up, the call ends itself after RING_SECONDS and is
       written into the conversation as a missed call - which is the record the
       desk wants anyway;
     * the server never checked presence: only who may ring whom.
   The buttons are dead only while a call is already up. */
function setCallButtons(show, peerOnline) {
  if (!callBtns) { return; }
  var ok = !!window.RTCPeerConnection && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  callBtns.hidden = !(show && ok);
  var dis = !!cv.id;                       // in a call already, and nothing else
  ['callVoice', 'callVideo'].forEach(function (id) {
    var b = el(id);
    b.disabled = dis;
    var what = (id === 'callVoice' ? 'Voice call' : 'Video call');
    b.title = peerOnline ? what : what + ' — they are away, so it will ring their phone';
  });
}

/* ---- where two browsers look for each other: STUN always, a free relay for
   the networks that need one, fetched from our server so no key is exposed. */
function iceServers(done) {
  if (cv.ice && Date.now() - cv.iceAt < 5 * 60 * 1000) { done(cv.ice); return; }
  var x = new XMLHttpRequest();
  x.open('GET', 'api/ice.php', true);
  x.onreadystatechange = function () {
    if (x.readyState !== 4) { return; }
    var j = null;
    try { j = JSON.parse(x.responseText); } catch (e) { j = null; }
    cv.ice = (j && j.ok && j.iceServers) ? j.iceServers : [{ urls: 'stun:stun.l.google.com:19302' }];
    cv.iceAt = Date.now();
    done(cv.ice);
  };
  x.send();
}

function callGet(q, done) {
  var x = new XMLHttpRequest();
  x.open('GET', 'api/call.php?' + q, true);
  x.onreadystatechange = function () {
    if (x.readyState !== 4) { return; }
    var j = null;
    try { j = JSON.parse(x.responseText); } catch (e) { j = null; }
    done(j);
  };
  x.send();
}

/* Wait until this browser has found all the ways it can be reached — or two and
   a half seconds, whichever is first. Most calls have everything in well under
   a second; the cap stops one slow network card holding a call up for ever. */
function gathered(pc) {
  return new Promise(function (res) {
    if (pc.iceGatheringState === 'complete') { res(); return; }
    var t = setTimeout(res, 2500);
    pc.addEventListener('icegatheringstatechange', function () {
      if (pc.iceGatheringState === 'complete') { clearTimeout(t); res(); }
    });
  });
}

function media(kind) {
  return navigator.mediaDevices.getUserMedia({
    audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
    video: kind === 'video' ? { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: 'user' } : false
  });
}

function buildPeer(servers) {
  var pc = new RTCPeerConnection({ iceServers: servers });
  cv.stream.getTracks().forEach(function (t) { pc.addTrack(t, cv.stream); });
  pc.ontrack = function (ev) {
    var s = ev.streams && ev.streams[0] ? ev.streams[0] : new MediaStream([ev.track]);
    if (cv.kind === 'video') {
      el('callRemote').srcObject = s;
      el('callRemote').play && el('callRemote').play().catch(function () {});
    }
    // The sound always goes through the audio element — a video element with
    // no picture yet still needs its voice heard.
    el('callAudio').srcObject = s;
    el('callAudio').play && el('callAudio').play().catch(function () {});
  };
  pc.onconnectionstatechange = function () {
    var st = pc.connectionState;
    if (st === 'connected') { connected(); }
    if (st === 'failed') { hangUp(true); }
  };
  pc.oniceconnectionstatechange = function () {
    // Older browsers only report here.
    if (pc.iceConnectionState === 'connected' || pc.iceConnectionState === 'completed') { connected(); }
    if (pc.iceConnectionState === 'failed') { hangUp(true); }
  };
  return pc;
}

/* ---- the screen */
function showCall(opts) {
  callBox.hidden = false;
  callBox.className = 'call' + (opts.ringing ? ' ringing' : '') + (cv.kind === 'video' ? ' has-video' : '');
  el('callName').textContent = cv.peer || '—';
  el('callState').textContent = opts.text || '';
  el('callAv').textContent = initialsOf(cv.peer);
  el('callBar').hidden = !!opts.asking;
  el('callAsk').hidden = !opts.asking;
  el('callCam').hidden = (cv.kind !== 'video');
  if (cv.kind === 'video' && cv.stream) {
    el('callLocal').srcObject = cv.stream;
  }
  setCallButtons(!callBtns.hidden, false);
}

function initialsOf(name) {
  var p = String(name || '').trim().split(/\s+/);
  return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
}

function setCallText(t) { el('callState').textContent = t; }

/* A ring made in the browser: two short tones, a pause, again. Nothing to
   fetch, nothing to fail. */
function ringOn(outgoing) {
  ringOff();
  try {
    ac = ac || new (window.AudioContext || window.webkitAudioContext)();
    var beep = function (at, f) {
      var o = ac.createOscillator(), g = ac.createGain();
      o.connect(g); g.connect(ac.destination);
      o.type = 'sine'; o.frequency.value = f;
      g.gain.setValueAtTime(0.0001, at);
      g.gain.exponentialRampToValueAtTime(outgoing ? 0.05 : 0.14, at + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, at + 0.38);
      o.start(at); o.stop(at + 0.4);
    };
    var once = function () {
      var t = ac.currentTime;
      if (outgoing) { beep(t, 440); beep(t + 0.5, 440); }
      else { beep(t, 880); beep(t + 0.45, 988); }
    };
    once();
    cv.ringer = setInterval(once, outgoing ? 3000 : 2000);
  } catch (e) {}
}
function ringOff() { if (cv.ringer) { clearInterval(cv.ringer); cv.ringer = null; } }

function connected() {
  if (cv.startedAt) { return; }
  ringOff();
  cv.startedAt = Date.now();
  callBox.classList.remove('ringing');
  setCallText('0:00');
  cv.clock = setInterval(function () {
    setCallText(dur((Date.now() - cv.startedAt) / 1000));
  }, 1000);
}

/* ---- making a call */
function startCall(kind) {
  if (cv.id || cv.closing) { return; }
  if (!state.thread && !state.to) { return; }
  cv.kind = kind; cv.incoming = false; cv.remoteSet = false; cv.startedAt = 0;
  cv.peer = headName.textContent || '';
  showCall({ text: 'Starting…' });

  media(kind).then(function (stream) {
    cv.stream = stream;
    if (kind === 'video') { el('callLocal').srcObject = stream; }
    iceServers(function (servers) {
      cv.pc = buildPeer(servers);
      cv.pc.createOffer().then(function (o) {
        return cv.pc.setLocalDescription(o);
      }).then(function () {
        return gathered(cv.pc);
      }).then(function () {
        var body = 'action=start&kind=' + kind + '&offer=' + encodeURIComponent(cv.pc.localDescription.sdp)
                 + (isGuest ? '&to=' + state.to : '&thread=' + state.thread);
        post('api/call.php', body, function (j) {
          cv.id = j.call.id;
          if (j.call.thread && !state.thread) { state.thread = j.call.thread; }
          showCall({ ringing: true, text: 'Ringing…' });
          ringOn(true);
          watchCall();
        }, function (msg) {
          closeCall(msg);
        });
      }).catch(function () { closeCall('The call could not be started.'); });
    });
  }).catch(function () {
    closeCall(kind === 'video' ? 'Your camera or microphone could not be used. Check the browser allowed them.'
                               : 'Your microphone could not be used. Check the browser allowed it.');
  });
}

/* ---- being called: the main poll hands the call in */
/* Answering opens the caller's conversation, the way a phone does: the person
   on the line is the person on the screen, and the line the call leaves behind
   lands where they can see it. Found by the test — an agent who answered from
   the list saw neither. */
function focusCallThread() {
  var sel = isGuest ? '.person[data-to="' + cv.peerId + '"]'
                    : '.person[data-thread="' + cv.thread + '"]';
  if ((isGuest && state.to === cv.peerId) || (!isGuest && state.thread === cv.thread)) { return; }
  /* An administrator may be looking at the list of AGENTS, where the ringing
     conversation has no row at all. Step into the agent holding it first. */
  if (!isGuest && lastSeesAll) {
    var owner = null;
    lastList.forEach(function (t) { if ((t.id | 0) === (cv.thread | 0)) { owner = t; } });
    if (owner && (owner.sid | 0) !== agentAt) { showAgent(owner.sid | 0, owner.staff || ''); }
  }
  var row = sideList.querySelector(sel);
  if (row) { row.click(); }
}

function incomingCall(c) {
  if (cv.id || cv.closing) { return; }
  cv.id = c.id; cv.kind = c.kind; cv.incoming = true; cv.remoteSet = false; cv.startedAt = 0;
  cv.peer = c.peer || '';
  cv.thread = c.thread | 0; cv.peerId = c.peerId | 0;
  showCall({ ringing: true, asking: true,
             text: (c.kind === 'video' ? 'Video call' : 'Voice call') + ' — incoming' });
  ringOn(false);
  watchCall();
  if (window.Notification && Notification.permission === 'granted' && document.hidden) {
    try { new Notification('SBK — ' + (c.peer || 'Somebody') + ' is calling'); } catch (e) {}
  }
}

function answerCall() {
  if (!cv.id || !cv.incoming || cv.pc) { return; }
  ringOff();
  focusCallThread();
  el('callBar').hidden = false;
  el('callAsk').hidden = true;
  setCallText('Connecting…');
  media(cv.kind).then(function (stream) {
    cv.stream = stream;
    if (cv.kind === 'video') { el('callLocal').srcObject = stream; }
    callGet('action=state&call=' + cv.id, function (j) {
      if (!j || !j.ok || !j.call || !j.call.offer) { closeCall('That call has ended.'); return; }
      iceServers(function (servers) {
        cv.pc = buildPeer(servers);
        cv.pc.setRemoteDescription({ type: 'offer', sdp: j.call.offer }).then(function () {
          cv.remoteSet = true;
          return cv.pc.createAnswer();
        }).then(function (a) {
          return cv.pc.setLocalDescription(a);
        }).then(function () {
          return gathered(cv.pc);
        }).then(function () {
          post('api/call.php', 'action=answer&call=' + cv.id + '&answer='
               + encodeURIComponent(cv.pc.localDescription.sdp),
               function () { /* the connection state takes it from here */ },
               function (msg) { closeCall(msg); });
        }).catch(function () { hangUp(true); });
      });
    });
  }).catch(function () {
    post('api/call.php', 'action=decline&call=' + cv.id, function () {}, function () {});
    closeCall(cv.kind === 'video' ? 'Your camera or microphone could not be used.'
                                  : 'Your microphone could not be used.');
  });
}

/* ---- keeping an eye on the call while it lasts: faster than the chat's own
   poll, because a ring that takes six seconds to be noticed is a missed call,
   but only for as long as a call exists. */
function watchCall() {
  if (cv.polling) { clearInterval(cv.polling); }
  cv.polling = setInterval(function () {
    if (!cv.id) { return; }
    callGet('action=state&call=' + cv.id, function (j) {
      if (!j || !j.ok || !j.call) { return; }
      var c = j.call;
      if (!cv.incoming && c.state === 'accepted' && c.answer && cv.pc && !cv.remoteSet) {
        cv.remoteSet = true;
        ringOff();
        setCallText('Connecting…');
        cv.pc.setRemoteDescription({ type: 'answer', sdp: c.answer }).catch(function () { hangUp(true); });
      }
      if (c.state !== 'ringing' && c.state !== 'accepted') {
        var why = {
          declined:  cv.incoming ? 'Call declined' : 'They are busy right now',
          cancelled: cv.incoming ? 'Missed call' : 'Call cancelled',
          missed:    cv.incoming ? 'Missed call' : 'No answer',
          failed:    'The call could not connect',
          ended:     'Call ended'
        }[c.state] || 'Call ended';
        closeCall(why);
      }
    });
  }, 900);
}

function hangUp(failed) {
  if (!cv.id) { closeCall(); return; }
  var id = cv.id;
  var act = (cv.incoming && !cv.pc) ? 'decline' : 'end';
  post('api/call.php', 'action=' + act + '&call=' + id + (failed ? '&failed=1' : ''),
       function () {}, function () {});
  closeCall(failed ? 'The call could not connect. Their network may block calls — try a message.'
                   : (act === 'decline' ? 'Call declined' : 'Call ended'));
}

/* Put everything down, once: tracks, connection, timers, sounds, screen. */
function closeCall(msg) {
  if (cv.closing) { return; }
  cv.closing = true;
  ringOff();
  if (cv.polling) { clearInterval(cv.polling); cv.polling = null; }
  if (cv.clock) { clearInterval(cv.clock); cv.clock = null; }
  if (cv.pc) { try { cv.pc.close(); } catch (e) {} cv.pc = null; }
  if (cv.stream) { cv.stream.getTracks().forEach(function (t) { t.stop(); }); cv.stream = null; }
  ['callRemote', 'callLocal', 'callAudio'].forEach(function (id) { try { el(id).srcObject = null; } catch (e) {} });
  callBox.classList.remove('ringing');
  el('callBar').hidden = true;
  el('callAsk').hidden = true;
  setCallText(msg || 'Call ended');
  setTimeout(function () {
    callBox.hidden = true;
    cv.id = 0; cv.closing = false; cv.startedAt = 0; cv.remoteSet = false; cv.incoming = false;
    tick(true);                     // the call's line in the conversation arrives now
  }, msg ? 1600 : 200);
}

el('callVoice').addEventListener('click', function () { startCall('voice'); });
el('callVideo').addEventListener('click', function () { startCall('video'); });
el('callEnd').addEventListener('click', function () { hangUp(false); });
el('callYes').addEventListener('click', answerCall);
el('callNo').addEventListener('click', function () { hangUp(false); });
el('callMute').addEventListener('click', function () {
  if (!cv.stream) { return; }
  var on = cv.stream.getAudioTracks().some(function (t) { return t.enabled; });
  cv.stream.getAudioTracks().forEach(function (t) { t.enabled = !on; });
  this.classList.toggle('off', on);
  this.title = on ? 'Unmute' : 'Mute';
});
el('callCam').addEventListener('click', function () {
  if (!cv.stream) { return; }
  var on = cv.stream.getVideoTracks().some(function (t) { return t.enabled; });
  cv.stream.getVideoTracks().forEach(function (t) { t.enabled = !on; });
  this.classList.toggle('off', on);
  this.title = on ? 'Camera on' : 'Camera off';
});
// Leaving the page mid-call hangs up properly rather than leaving them talking to nobody.
window.addEventListener('pagehide', function () {
  if (cv.id && navigator.sendBeacon) {
    var fd = new FormData();
    fd.append('action', 'end'); fd.append('call', cv.id);
    navigator.sendBeacon('api/call.php', fd);
  }
});

/* -------------------------------------------------- "what's your name?" ---
   Shown only to a customer whose "name" on the website is really their login.
   Saved once, and the card is gone; the bar at the top changes at the same
   moment so the person can see it took. */
var askName = el('askName');
if (askName) {
  askName.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var input = askName.querySelector('input'), btn = askName.querySelector('button');
    var err = askName.querySelector('.ask-err');
    var v = (input.value || '').trim();
    if (v.length < 2) { input.focus(); return; }
    btn.disabled = true;
    err.hidden = true;
    post('api/name.php', 'name=' + encodeURIComponent(v), function (j) {
      var me = el('meName');
      if (me) { me.textContent = j.name; }
      askName.style.transition = 'opacity .2s, transform .2s';
      askName.style.opacity = '0';
      askName.style.transform = 'translateY(-6px)';
      setTimeout(function () { askName.parentNode.removeChild(askName); }, 220);
    }, function (msg) {
      btn.disabled = false;
      err.textContent = msg;
      err.hidden = false;
    });
  });
}

/* ================================================================== groups

   The owner's ask, 2026-09-16: "we also need to add a grouping feature inside
   it so we can create groups... like how groups are made on WhatsApp".

   A group sits in the same side list as the conversations, the way it does in
   WhatsApp, and opens in the same pane. Everything it needs is built from here
   rather than written into chat.php, so the page a customer loads, and the page
   the desk has been using all week, are byte for byte the ones they were.

   A customer never reaches any of this: `isGuest` stops it at the door, and the
   server would refuse it anyway. */

var lastGroups = [];
var groupPick = null;        // the staff list to choose from, fetched once

/** The rooms, drawn above the conversations. */
function groupRowsHtml() {
  /* Inside an agent an administrator is looking at that agent's customers —
     his own rooms would be noise on that screen, so they stay on the one he
     stepped in from. */
  if (isGuest || agentAt || !lastGroups.length) { return ''; }
  var h = '';
  for (var i = 0; i < lastGroups.length; i++) {
    var g = lastGroups[i];
    var sub = g.preview ? g.preview : (g.members + (g.members === 1 ? ' member' : ' members'));
    h += '<div class="person is-group' + (state.group === g.id ? ' on' : '')
       + '" data-group="' + g.id + '" data-hue="' + (g.hue | 0)
       + '" data-members="' + (g.members | 0) + '">'
       + '<div class="av ' + faceClass(g.hue) + ' is-gav">' + esc(g.ini) + '</div>'
       + '<div class="nm"><b>' + esc(g.name) + '</b><span>' + esc(sub) + '</span></div>'
       + '<div class="meta">' + (g.unread > 0 ? '<span class="pill">' + g.unread + '</span>' : '')
       + '</div></div>';
  }
  return h;
}

/** Keep the line under a group's name right while it is open. */
function syncGroupHead() {
  if (!state.group) { return; }
  for (var i = 0; i < lastGroups.length; i++) {
    if (lastGroups[i].id === state.group) {
      var g = lastGroups[i];
      var sub = g.members + (g.members === 1 ? ' member' : ' members');
      if (headName.textContent !== g.name) { headName.textContent = g.name; }
      if (headSub.textContent !== sub) { headSub.textContent = sub; }
      return;
    }
  }
}

/** Open a room. A conversation and a group are never open together. */
function openGroup(id, name, ini, hue, members) {
  if (typeof clearTray === 'function') { clearTray(); }
  state.group  = id || 0;
  state.thread = 0;
  state.to     = 0;
  state.lastId = 0;
  state.seen   = {};
  lastDay = '';
  scroll.innerHTML = '';
  compose.hidden = false;
  el('paneHead').hidden = false;
  body.classList.add('has-thread');

  headName.textContent = name || '';
  headSub.textContent  = (members || 0) + (members === 1 ? ' member' : ' members');
  headSub.removeAttribute('data-sig');
  headSub.className = '';
  avatar(headAv, ini, hue, true);

  /* No phone on a group. Calling is one person ringing another, and a room of
     six is not that; the server has no group call either, so a button that
     looked ready would be a button that lies. */
  state.canCall = false;
  setCallButtons(false, false);
  body.classList.add('in-group');       // the name at the top becomes a way in

  clearPane('Nothing here yet', 'Write the first message to this group.');
  tick(true);
  box.focus();
}

/* ----------------------------------------------------------- the little box

   One overlay, built when it is wanted and thrown away when it is closed.
   Nothing of it exists in the page until somebody asks for it. */

function gdlg(title, innerHtml, onReady) {
  var wrap = document.createElement('div');
  wrap.className = 'gdlg';
  wrap.innerHTML = '<div class="gdlg-box" role="dialog" aria-modal="true">'
    + '<div class="gdlg-top"><b>' + esc(title) + '</b>'
    + '<button class="gdlg-x" type="button" aria-label="Close">&times;</button></div>'
    + '<div class="gdlg-body">' + innerHtml + '</div></div>';
  document.body.appendChild(wrap);

  function shut() {
    if (wrap.parentNode) { wrap.parentNode.removeChild(wrap); }
    document.removeEventListener('keydown', esckey);
  }
  function esckey(ev) { if (ev.key === 'Escape') { shut(); } }
  document.addEventListener('keydown', esckey);
  wrap.querySelector('.gdlg-x').addEventListener('click', shut);
  wrap.addEventListener('click', function (ev) { if (ev.target === wrap) { shut(); } });

  if (onReady) { onReady(wrap, shut); }
  return wrap;
}

/** One person, as a line you can tick. */
function pickRow(p, checked) {
  return '<label class="gpick"><input type="checkbox" value="' + (p.id | 0) + '"'
       + (checked ? ' checked' : '') + '>'
       + '<span class="av sm ' + faceClass(p.hue) + '">' + esc(p.ini) + '</span>'
       + '<span class="gpick-nm"><b>' + esc(p.name) + '</b><span>' + esc(p.role || '') + '</span></span>'
       + (p.online ? '<i class="gpick-on">Online</i>' : '') + '</label>';
}

function withPeople(done) {
  if (groupPick) { done(groupPick); return; }
  post('api/group.php', 'do=people', function (j) {
    groupPick = j.people || [];
    done(groupPick);
  }, function (msg) { alert(msg); });
}

/** Make one. */
function newGroupBox() {
  withPeople(function (people) {
    var rows = people.map(function (p) { return pickRow(p, false); }).join('');
    gdlg('New group',
      '<input class="gname" id="gName" type="text" maxlength="120" placeholder="Group name" autocomplete="off">'
      + '<div class="gpick-h">Who is in it</div>'
      + '<div class="gpick-list">' + (rows || '<p class="gnote">There is nobody else on the desk yet.</p>') + '</div>'
      + '<div class="gdlg-do"><button class="gbtn" id="gMake" type="button">Create group</button></div>',
      function (wrap, shut) {
        var nameBox = wrap.querySelector('#gName');
        nameBox.focus();
        wrap.querySelector('#gMake').addEventListener('click', function () {
          var name = nameBox.value.trim();
          if (!name) { nameBox.focus(); return; }
          var ids = [], boxes = wrap.querySelectorAll('.gpick input:checked');
          for (var i = 0; i < boxes.length; i++) { ids.push(boxes[i].value | 0); }
          var d = 'do=create&name=' + encodeURIComponent(name);
          for (var k = 0; k < ids.length; k++) { d += '&members[]=' + ids[k]; }
          this.disabled = true;
          post('api/group.php', d, function (j) {
            shut();
            sideSig = '';
            tick(true);
            openGroup(j.group.id, j.group.name, j.group.ini, j.group.hue, j.group.count);
          }, function (msg) { alert(msg); wrap.querySelector('#gMake').disabled = false; });
        });
      });
  });
}

/** Look inside one: who is in it, and the things its owner may change. */
function groupInfoBox(gid) {
  post('api/group.php', 'do=info&group=' + (gid | 0), function (j) {
    var g = j.group, mine = {}, i;
    for (i = 0; i < g.members.length; i++) { mine[g.members[i].id] = 1; }
    var left = (j.people || []).filter(function (p) { return !mine[p.id]; });

    var mem = g.members.map(function (m) {
      return '<div class="gmem"><span class="av sm ' + faceClass(m.hue) + '">' + esc(m.ini) + '</span>'
           + '<span class="gpick-nm"><b>' + esc(m.name) + (m.me ? ' (you)' : '') + '</b><span>'
           + (m.owner ? 'Made the group' : (m.online ? 'Online' : 'Member')) + '</span></span>'
           + (g.owner && !m.me ? '<button class="gmem-x" type="button" data-off="' + m.id
                               + '" title="Remove">&times;</button>' : '')
           + '</div>';
    }).join('');

    var addBlock = (g.owner && left.length)
      ? '<div class="gpick-h">Add people</div><div class="gpick-list">'
        + left.map(function (p) { return pickRow(p, false); }).join('')
        + '</div><div class="gdlg-do"><button class="gbtn" id="gAdd" type="button">Add to group</button></div>'
      : '';

    var nameBlock = g.owner
      ? '<div class="grow"><input class="gname" id="gRename" type="text" maxlength="120" value="'
        + esc(g.name) + '"><button class="gbtn gbtn-thin" id="gSave" type="button">Save</button></div>'
      : '';

    gdlg(g.name,
      nameBlock
      + '<div class="gpick-h">' + g.count + (g.count === 1 ? ' member' : ' members') + '</div>'
      + '<div class="gmem-list">' + mem + '</div>'
      + addBlock
      + '<div class="gdlg-do"><button class="gbtn gbtn-out" id="gLeave" type="button">Leave group</button></div>',
      function (wrap, shut) {
        function again() { shut(); sideSig = ''; tick(true); groupInfoBox(gid); }

        var save = wrap.querySelector('#gSave');
        if (save) {
          save.addEventListener('click', function () {
            var nm = wrap.querySelector('#gRename').value.trim();
            if (!nm) { return; }
            post('api/group.php', 'do=rename&group=' + gid + '&name=' + encodeURIComponent(nm),
              function () { shut(); sideSig = ''; tick(true); }, function (m) { alert(m); });
          });
        }
        var add = wrap.querySelector('#gAdd');
        if (add) {
          add.addEventListener('click', function () {
            var ids = [], boxes = wrap.querySelectorAll('.gpick input:checked');
            for (var k = 0; k < boxes.length; k++) { ids.push(boxes[k].value | 0); }
            if (!ids.length) { return; }
            var d = 'do=add&group=' + gid;
            for (var n = 0; n < ids.length; n++) { d += '&members[]=' + ids[n]; }
            post('api/group.php', d, again, function (m) { alert(m); });
          });
        }
        wrap.addEventListener('click', function (ev) {
          var off = ev.target.getAttribute ? ev.target.getAttribute('data-off') : null;
          if (!off) { return; }
          post('api/group.php', 'do=remove&group=' + gid + '&staff=' + (off | 0),
               again, function (m) { alert(m); });
        });
        wrap.querySelector('#gLeave').addEventListener('click', function () {
          if (!confirm('Leave "' + g.name + '"?')) { return; }
          post('api/group.php', 'do=leave&group=' + gid, function () {
            shut();
            if (state.group === gid) {
              state.group = 0;
              body.classList.remove('has-thread');
              compose.hidden = true;
              el('paneHead').hidden = true;
              clearPane('Pick a conversation', 'Everything a customer has written is on the left.');
            }
            sideSig = '';
            tick(true);
          }, function (m) { alert(m); });
        });
      });
  }, function (msg) { alert(msg); });
}

/* The + beside the heading, and the heading of an open group being a way in.
   Both are added to the page rather than written into it — see the note above. */
if (!isGuest) {
  var sideBox = document.querySelector('aside.side');
  if (sideBox) {
    var plus = document.createElement('button');
    plus.className = 'side-new';
    plus.id = 'newGroup';
    plus.type = 'button';
    plus.title = 'New group';
    plus.setAttribute('aria-label', 'New group');
    plus.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"'
      + ' stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
    plus.addEventListener('click', newGroupBox);
    sideBox.appendChild(plus);
  }
  if (headName && headName.parentNode) {
    headName.parentNode.classList.add('head-who');
    headName.parentNode.addEventListener('click', function () {
      if (state.group) { groupInfoBox(state.group); }
    });
  }
}

/* ------------------------------------------------------------------ start */

tick(true);
pace();
setInterval(function () {
  var x = new XMLHttpRequest();
  x.open('GET', 'api/ping.php', true);
  x.send();
}, 15000);

})();
