/* The app's only way of reaching anything.
   -------------------------------------------------------------------------
   THE APP TALKS TO THE CHAT THAT IS ALREADY RUNNING. Every call below goes to
   an endpoint the website has been using for a week - api/poll.php, send.php,
   upload.php, group.php - so the two are not "kept in step", they ARE the same
   thing. A conversation opened on a phone and a conversation opened in a browser
   are one row in one table, because there is one server and one database.

   The only difference is how we say who we are. A browser carries a session
   cookie; a phone carries a PASS, minted on the website by sbk-app-api.php where
   the accounts and the passwords live, and signed with the same secret the
   website's own handover uses. It goes on every request as a bearer token, and
   the chat checks it every time - so an account taken away on the website is
   gone from the app on its very next poll.

   Nothing here knows a password. Sign-in and registration are the website's
   job, and this file only carries the answer back. */

const WEB  = 'https://sbkautotrading.com';
/* The application's own address (the owner's order, 19 September 2026). It
   runs the very same chat as chat.sbkautotrading.com - the same code, the same
   database - so a message sent from here is in the website's conversation the
   moment it lands, and the other way round. See sbk-appsite/ on the server. */
const CHAT = 'https://chat-application.sbkautotrading.com';

/** Bodies go as a form, because that is what the chat has always accepted. */
function form(fields) {
  const p = [];
  for (const k of Object.keys(fields)) {
    const v = fields[k];
    if (v === undefined || v === null) continue;
    if (Array.isArray(v)) {
      for (const one of v) p.push(encodeURIComponent(k + '[]') + '=' + encodeURIComponent(one));
    } else {
      p.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
    }
  }
  return p.join('&');
}

async function call(url, opts) {
  let r;
  try {
    r = await fetch(url, opts);
  } catch (e) {
    /* A phone loses its signal in lifts, tunnels and most of the ground floor.
       That is not an error the person should be shown as a failure of ours. */
    throw new Error('No connection. Check your internet and try again.');
  }
  let j = null;
  const text = await r.text();
  try { j = JSON.parse(text); } catch (e) { j = null; }
  if (!j) throw new Error('The server answered something unexpected.');
  if (j.ok === false && !j.signedout) throw new Error(j.error || 'That did not go through.');
  return { status: r.status, body: j };
}

/* ------------------------------------------------------- the website's door */

export async function signIn(login, password) {
  const { body } = await call(WEB + '/?sbk_app=login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form({ login, password }),
  });
  return body;                       // { ok, token, user }
}

export async function signUp(name, email, phone, password) {
  const { body } = await call(WEB + '/?sbk_app=register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form({ name, email, phone, password }),
  });
  return body;
}

/** Is this pass still good, and who does it belong to now? */
export async function whoAmI(token) {
  const { body } = await call(WEB + '/?sbk_app=me', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form({ token }),
  });
  return body;
}

/* ----------------------------------------------------------- and the chat */

function authed(token, extra) {
  return Object.assign({
    Authorization: 'Bearer ' + token,
    'Content-Type': 'application/x-www-form-urlencoded',
  }, extra || {});
}

/**
 * One beat. Exactly the request the website's own chat.js makes.
 *
 * Answers, depending on who is asking:
 *   a customer   people[]  (the desk)        threads{}  unread
 *   the desk     list[]    (conversations)   groups[]   unread
 * and, when a conversation is named, messages[] and receipts[] for it.
 */
export async function poll(token, { thread = 0, group = 0, after = 0, open = false, me = false } = {}) {
  const q = new URLSearchParams();
  q.set('after', String(after));
  if (group) q.set('group', String(group));
  else if (thread) q.set('thread', String(thread));
  if (open) q.set('open', '1');
  // A customer's list asks once whether they still need to give a real name.
  if (me) q.set('me', '1');
  const { status, body } = await call(CHAT + '/api/poll.php?' + q.toString(), {
    method: 'GET',
    headers: authed(token),
  });
  return { status, ...body };
}

export async function send(token, { to = 0, thread = 0, group = 0, body: text }) {
  const { body } = await call(CHAT + '/api/send.php', {
    method: 'POST',
    headers: authed(token),
    body: form(group ? { group, body: text } : (to ? { to, body: text } : { thread, body: text })),
  });
  return body;
}

/**
 * A picture or a voice note, sent exactly the way the browser's own upload.php
 * expects it - multipart, `kind` telling it which, `secs` only for a voice
 * note. `file` is `{ uri, name, type }`, the shape React Native's own fetch
 * wants for a local file - not a browser File object, because there isn't one
 * on a phone.
 */
export async function upload(token, { to = 0, thread = 0, group = 0, kind, file, secs = 0 }) {
  const fd = new FormData();
  fd.append('kind', kind);
  fd.append('file', file);
  if (kind === 'voice') fd.append('secs', String(secs || 0));
  if (group) fd.append('group', String(group));
  else if (to) fd.append('to', String(to));
  else fd.append('thread', String(thread));

  const { body } = await call(CHAT + '/api/upload.php', {
    method: 'POST',
    headers: { Authorization: 'Bearer ' + token },   // no Content-Type - fetch sets the multipart boundary itself
    body: fd,
  });
  return body;
}

/* -------------------------------------------------------------- your name
   "What's your name?" - the card the website shows a customer whose only name
   is their login. Kept in the chat's own row, never written into the shop's
   accounts; see api/name.php. */
export async function saveName(token, name) {
  const { body } = await call(CHAT + '/api/name.php', {
    method: 'POST',
    headers: authed(token),
    body: form({ name }),
  });
  return body;                       // { ok, name }
}

/* ----------------------------------------------------------------- groups
   The desk's rooms, the website's api/group.php and nothing else: make one,
   who is in it, its name, adding and taking people out, and leaving. The
   server decides who may do what - only whoever made a group may change it,
   anybody in it may leave - and the app only asks. */
async function groupCall(token, fields) {
  const { body } = await call(CHAT + '/api/group.php', {
    method: 'POST',
    headers: authed(token),
    body: form(fields),
  });
  return body;
}

/** Everybody on the desk who could be put in a group (not you). */
export function groupPeople(token) { return groupCall(token, { do: 'people' }); }

export function groupCreate(token, name, members) {
  return groupCall(token, { do: 'create', name, members });
}

/** The group, everybody in it, and who else could be added. */
export function groupInfo(token, gid) { return groupCall(token, { do: 'info', group: gid }); }

export function groupRename(token, gid, name) { return groupCall(token, { do: 'rename', group: gid, name }); }

export function groupAdd(token, gid, members) { return groupCall(token, { do: 'add', group: gid, members }); }

export function groupRemove(token, gid, staff) { return groupCall(token, { do: 'remove', group: gid, staff }); }

export function groupLeave(token, gid) { return groupCall(token, { do: 'leave', group: gid }); }

/* ----------------------------------------------------- push notifications
   "Send this person's notifications to this phone" (api/push.php). The token
   is the phone's own Firebase token; the server files it against whoever the
   pass signs in as, and moves it if somebody else signs in on the same phone. */
export async function pushRegister(token, fcm) {
  const { body } = await call(CHAT + '/api/push.php', {
    method: 'POST',
    headers: authed(token),
    body: form({ token: fcm }),
  });
  return body;
}

/** Signed out on this phone: stop. No pass needed - the phone's token is the proof. */
export async function pushForget(fcm) {
  const { body } = await call(CHAT + '/api/push.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form({ do: 'forget', token: fcm }),
  });
  return body;
}

/* ------------------------------------------------------------------ calls
   The same handshake the browser makes in chat.js, against the same two
   endpoints. The sound and the picture never come near the server; what comes
   here is only the paperwork two phones (or a phone and a browser) need to
   find each other, and the state of the call, which both sides ask about. */

/** Where to look for each other: STUN always, the free relay when set up. */
export async function iceServers(token) {
  const { body } = await call(CHAT + '/api/ice.php', { method: 'GET', headers: authed(token) });
  return Array.isArray(body.iceServers) ? body.iceServers : [];
}

/** Ring somebody. A customer names the member of staff (`to`); the desk names
 *  the conversation (`thread`) - and only its OWN, which the server enforces. */
export async function callStart(token, { kind, offer, thread = 0, to = 0 }) {
  const { body } = await call(CHAT + '/api/call.php', {
    method: 'POST',
    headers: authed(token),
    body: form(Object.assign({ action: 'start', kind, offer }, to ? { to } : { thread })),
  });
  return body.call;
}

/** Where a call is. The caller gets the answer once there is one; the one
 *  being rung gets the offer while it is still ringing. */
export async function callState(token, id) {
  const { body } = await call(
    CHAT + '/api/call.php?action=state&call=' + encodeURIComponent(id),
    { method: 'GET', headers: authed(token) },
  );
  return body.call;
}

export async function callAnswer(token, id, answer) {
  const { body } = await call(CHAT + '/api/call.php', {
    method: 'POST',
    headers: authed(token),
    body: form({ action: 'answer', call: id, answer }),
  });
  return body.call;
}

/** decline / cancel / end - the server works out which from the call's state. */
export async function callFinish(token, id, action, failed) {
  const { body } = await call(CHAT + '/api/call.php', {
    method: 'POST',
    headers: authed(token),
    body: form(Object.assign({ action, call: id }, failed ? { failed: 1 } : {})),
  });
  return body.call;
}

/**
 * Tell our server something went wrong on this phone - the error, where, and
 * which phone - so it can be read and fixed without anyone having to describe
 * a screen that closed before they could read it. Never throws; a report that
 * cannot be sent is simply dropped.
 */
export function reportProblem(what, err) {
  try {
    const stack = err && (err.stack || err.message) ? String(err.stack || err.message) : String(err || '');
    const text = '[js] ' + what + '\n' + stack.slice(0, 8000);
    fetch(CHAT + '/api/crash.php', {
      method: 'POST',
      headers: { 'Content-Type': 'text/plain; charset=utf-8' },
      body: text,
    }).catch(() => {});
  } catch (e) {}
}

/** The picture behind a message. media.php asks who wants it before it answers. */
export function mediaUrl(token, messageId) {
  return CHAT + '/media.php?m=' + encodeURIComponent(messageId)
       + '&sbk_token=' + encodeURIComponent(token);
}

export { WEB, CHAT };
