import { upload as uploadByForm, reportProblem, CHAT } from './api';

/* Sending a picture or a voice note from a phone.
   -------------------------------------------------------------------------
   THE OWNER'S PHONE COULD NOT SEND EITHER, AND THE SERVER NEVER SAW THE
   ATTEMPT (2026-09-18). The access log settles it: the app's polls were
   arriving and being answered 200 every second and a half, while not one
   upload request ever reached the server. So the send was failing INSIDE the
   phone, before anything left it - and because every failure in api.js's
   `call()` is reported as "No connection. Check your internet and try again.",
   that is what the owner saw on a phone whose internet was perfectly fine.

   The usual cause is React Native's own FormData: handed `{ uri, name, type }`
   it has to open that file itself, and when it cannot - a path without the
   `file://` scheme, a file the recorder has not finished writing, a content://
   URI it may not read - it throws "Network request failed", which says
   nothing about what actually went wrong.

   So the file is now sent the way the phone is best at: expo-file-system's
   own uploader, which streams it from disk natively (one multipart request,
   the same fields api/upload.php has always taken). If that fails, the old
   way is still tried, and either way the REASON - the uri, whether the file
   exists, its size, and the error itself - is sent to our server, so the next
   failure is read rather than guessed at. */

let fsMod;
function fileSystem() {
  if (fsMod === undefined) {
    try {
      // eslint-disable-next-line global-require
      fsMod = require('expo-file-system');
    } catch (e) {
      fsMod = null;
      reportProblem('expo-file-system could not load', e);
    }
  }
  return fsMod;
}

/** expo-audio hands back a bare path on some phones; everything wants a scheme. */
function withScheme(uri) {
  const s = String(uri || '');
  if (!s) return s;
  if (s.startsWith('file://') || s.startsWith('content://') || s.startsWith('http')) return s;
  return 'file://' + s;
}

function answerOf(text, status, facts) {
  let j = null;
  try { j = JSON.parse(text); } catch (e) { j = null; }
  if (!j) {
    const e = new Error('The server answered something unexpected.');
    e.detail = 'status ' + status + ' | ' + String(text).slice(0, 300);
    throw e;
  }
  if (j.ok === false) {
    /* The server turning a file away is worth knowing about, not just worth
       showing: "That person is not available." sat unseen on the owner's phone
       for a day while the fault was in what the app was sending. */
    reportProblem('picture/voice: the server said no (' + status + ') "'
      + (j.error || '?') + '" | ' + facts, new Error(j.error || 'refused'));
    const said = new Error(j.error || 'That did not go through.');
    said.fromServer = true;      // an answer, not a failure to send
    throw said;
  }
  return j;
}

export async function sendFileToChat(token, { to = 0, thread = 0, group = 0, kind, uri, name, mime, secs = 0 }) {
  const clean = withScheme(uri);
  const where = group ? { group: String(group) }
    : to ? { to: String(to) }
    : { thread: String(thread) };
  const fields = Object.assign({ kind }, where);
  if (kind === 'voice') fields.secs = String(secs || 0);

  let facts = kind + ' | ' + (clean.split(':')[0] || '?') + ' | name ' + name + ' | mime ' + mime;
  const F = fileSystem();

  /* ---- the phone's own uploader: the file is streamed from disk */
  if (F && F.File && F.UploadType) {
    try {
      const f = new F.File(clean);
      try { facts += ' | exists ' + f.exists + ' | size ' + f.size; } catch (e) {}
      const res = await f.upload(CHAT + '/api/upload.php', {
        httpMethod: 'POST',
        uploadType: F.UploadType.MULTIPART,
        fieldName: 'file',
        mimeType: mime,
        parameters: fields,
        headers: { Authorization: 'Bearer ' + token },
      });
      return answerOf(res.body, res.status, facts);
    } catch (e) {
      // An answer we understood (the server said no) is not a failure to send.
      if (e && e.fromServer) throw e;
      reportProblem('picture/voice: the phone uploader failed | ' + facts
        + (e && e.detail ? ' | ' + e.detail : ''), e);
      // and fall through - one more chance before telling the person
    }
  }

  /* ---- the older way, kept as a second chance */
  try {
    return await uploadByForm(token, {
      to, thread, group, kind, secs,
      file: { uri: clean, name, type: mime },
    });
  } catch (e) {
    reportProblem('picture/voice: both ways failed | ' + facts, e);
    const out = new Error(
      /no connection|network/i.test(e && e.message ? e.message : '')
        ? 'That file could not be sent. It has been reported - please try again.'
        : (e && e.message) || 'That file could not be sent.',
    );
    throw out;
  }
}
