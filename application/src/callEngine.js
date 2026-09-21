/* The call engine - the website's own calling code, with the screen taken out.
   -------------------------------------------------------------------------
   This is chat.js's call section (sbk-chat/assets/js/chat.js, "calls") moved
   across step for step: the same handshake against the same api/call.php, the
   same "gather everything, then send it in one go" rule, the same states, the
   same words on the screen. A call from this phone to a browser, or from a
   browser to this phone, is the SAME call the website has been making since the
   day it was built - there is no second protocol to drift out of step.

     CALLER   camera/mic -> offer -> POST start -> ring -> answer arrives -> connected
     CALLEE   poll says "ringing" -> Answer -> camera/mic -> offer in -> answer out -> connected

   It knows nothing about React Native. Everything it touches is handed in:
     api    ice(), start(), state(), answer(), finish()       - the server
     rtc    { RTCPeerConnection, RTCSessionDescription, mediaDevices }
     audio  outgoing(kind) incoming() answered(kind) connected() speaker(on) end()
   which is why the very same file runs inside a desktop browser for the test
   that rings the real website, and inside the phone for real. */

const STUN = [{ urls: 'stun:stun.l.google.com:19302' }];

function dur(s) {
  s = Math.max(0, s | 0);
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
  return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(sec).padStart(2, '0');
}

export function createCallEngine({ api, rtc, audio, onChange }) {
  const noop = () => {};
  const snd = Object.assign({
    outgoing: noop, incoming: noop, answered: noop, connected: noop, speaker: noop, end: noop,
  }, audio || {});

  const cv = {
    id: 0, kind: 'voice', incoming: false, pc: null, stream: null, remote: null,
    polling: null, clock: null, guard: null, startedAt: 0, remoteSet: false,
    closing: false, ice: null, iceAt: 0, peer: '', peerId: 0, hue: 0, thread: 0, to: 0,
    status: 'idle', text: '', elapsed: 0, muted: false, camOff: false,
    speaker: false, front: true, seq: 0, lastClosed: 0,
  };

  /* What the screen needs, and nothing it could change by accident. */
  function emit() {
    if (!onChange) return;
    onChange({
      status: cv.status,           // idle | outgoing | incoming | connecting | live | ended
      id: cv.id, kind: cv.kind, incoming: cv.incoming,
      peer: cv.peer, peerId: cv.peerId, hue: cv.hue, thread: cv.thread,
      text: cv.text, elapsed: cv.elapsed,
      muted: cv.muted, camOff: cv.camOff, speaker: cv.speaker, front: cv.front,
      local: cv.stream, remote: cv.remote,
    });
  }

  function setText(t) { cv.text = t; emit(); }

  /* ---- where two devices look for each other: STUN always, the free relay
     for the networks that need one, fetched from our server so no key lives
     in the app. Kept five minutes, exactly as the browser keeps it. */
  async function iceServers() {
    if (cv.ice && Date.now() - cv.iceAt < 5 * 60 * 1000) return cv.ice;
    try {
      const s = await api.ice();
      cv.ice = s && s.length ? s : STUN;
    } catch (e) {
      cv.ice = STUN;
    }
    cv.iceAt = Date.now();
    return cv.ice;
  }

  /* Wait until this device has found every way it can be reached - or two and
     a half seconds, whichever comes first. The channel between the two sides
     is a poll: two complete messages beat forty trickled ones. */
  function gathered(pc) {
    return new Promise((res) => {
      if (pc.iceGatheringState === 'complete') { res(); return; }
      const t = setTimeout(res, 2500);
      pc.addEventListener('icegatheringstatechange', () => {
        if (pc.iceGatheringState === 'complete') { clearTimeout(t); res(); }
      });
    });
  }

  function media(kind) {
    return rtc.mediaDevices.getUserMedia({
      audio: true,
      video: kind === 'video'
        ? { facingMode: 'user', width: 640, height: 480, frameRate: 30 }
        : false,
    });
  }

  function desc(type, sdp) {
    return rtc.RTCSessionDescription ? new rtc.RTCSessionDescription({ type, sdp }) : { type, sdp };
  }

  function buildPeer(servers) {
    const pc = new rtc.RTCPeerConnection({ iceServers: servers });
    cv.stream.getTracks().forEach((t) => pc.addTrack(t, cv.stream));
    pc.addEventListener('track', (ev) => {
      const s = ev.streams && ev.streams[0] ? ev.streams[0] : null;
      if (s && cv.remote !== s) { cv.remote = s; emit(); }
    });
    pc.addEventListener('connectionstatechange', () => {
      const st = pc.connectionState;
      if (st === 'connected') connected();
      if (st === 'failed') hangUp(true);
    });
    pc.addEventListener('iceconnectionstatechange', () => {
      // Some stacks only ever report here.
      const st = pc.iceConnectionState;
      if (st === 'connected' || st === 'completed') connected();
      if (st === 'failed') hangUp(true);
    });
    return pc;
  }

  function connected() {
    if (cv.startedAt || cv.closing || !cv.id) return;
    if (cv.guard) { clearTimeout(cv.guard); cv.guard = null; }
    snd.connected();
    cv.startedAt = Date.now();
    cv.status = 'live';
    cv.elapsed = 0;
    setText('0:00');
    cv.clock = setInterval(() => {
      cv.elapsed = Math.floor((Date.now() - cv.startedAt) / 1000);
      setText(dur(cv.elapsed));
    }, 1000);
  }

  /* An answered call that never manages to connect is not left saying
     "Connecting…" for ever - after forty seconds it is called what it is. */
  function guardConnecting() {
    if (cv.guard) clearTimeout(cv.guard);
    cv.guard = setTimeout(() => {
      cv.guard = null;
      if (cv.id && !cv.startedAt && !cv.closing) hangUp(true);
    }, 40000);
  }

  /* ---- making a call */
  async function start(kind, { thread = 0, to = 0, peer = '', peerId = 0, hue = 0 } = {}) {
    if (cv.id || cv.closing || cv.status !== 'idle') return;
    if (!thread && !to) return;
    const my = ++cv.seq;
    Object.assign(cv, {
      kind: kind === 'video' ? 'video' : 'voice', incoming: false, remoteSet: false,
      startedAt: 0, elapsed: 0, peer, peerId, hue, thread, to, remote: null,
      muted: false, camOff: false, speaker: kind === 'video', front: true,
      status: 'outgoing', text: 'Starting…',
    });
    emit();

    try {
      cv.stream = await media(cv.kind);
    } catch (e) {
      if (my === cv.seq) {
        // e.sbk: the phone's own words for "calls cannot start here" - say that, not "microphone".
        closeCall(e && e.sbk ? e.message : (cv.kind === 'video'
          ? 'Your camera or microphone could not be used. Allow them for SBK Chat in Settings.'
          : 'Your microphone could not be used. Allow it for SBK Chat in Settings.'));
      }
      return;
    }
    if (my !== cv.seq) { stopTracks(); return; }
    emit();

    let c;
    try {
      const servers = await iceServers();
      if (my !== cv.seq) return;
      cv.pc = buildPeer(servers);
      const o = await cv.pc.createOffer();
      await cv.pc.setLocalDescription(o);
      await gathered(cv.pc);
      if (my !== cv.seq) return;
      c = await api.start({
        kind: cv.kind, offer: cv.pc.localDescription.sdp,
        thread: to ? 0 : thread, to,
      });
    } catch (e) {
      if (my === cv.seq) closeCall(e && e.message ? e.message : 'The call could not be started.');
      return;
    }
    /* Hung up while the ring was still being set up: the server has a ringing
       call nobody is watching, so it is cancelled straight away rather than
       left ringing on the other side until it times out. */
    if (my !== cv.seq) {
      if (c && c.id) api.finish(c.id, 'cancel').catch(noop);
      return;
    }
    cv.id = c.id;
    if (c.thread) cv.thread = c.thread;
    cv.text = 'Ringing…';
    snd.outgoing(cv.kind);
    emit();
    watch();
  }

  /* ---- being called: whichever screen's poll sees it first hands it in */
  function notePoll(c) {
    if (!c || !c.incoming || c.state !== 'ringing') return;
    if (cv.id || cv.closing || cv.status !== 'idle') return;
    if (c.id === cv.lastClosed) return;       // the one just put down, seen once more on the way out
    ++cv.seq;
    Object.assign(cv, {
      id: c.id, kind: c.kind === 'video' ? 'video' : 'voice', incoming: true,
      remoteSet: false, startedAt: 0, elapsed: 0, remote: null,
      peer: c.peer || '', peerId: c.peerId | 0, hue: c.peerId | 0, thread: c.thread | 0, to: 0,
      muted: false, camOff: false, speaker: c.kind === 'video', front: true,
      status: 'incoming',
      text: (c.kind === 'video' ? 'Video call' : 'Voice call') + ' — incoming',
    });
    snd.incoming(cv.kind);
    emit();
    watch();
  }

  async function answer() {
    if (!cv.id || !cv.incoming || cv.pc || cv.status !== 'incoming') return;
    const my = cv.seq, id = cv.id;
    snd.answered(cv.kind);
    cv.status = 'connecting';
    setText('Connecting…');

    try {
      cv.stream = await media(cv.kind);
    } catch (e) {
      if (my !== cv.seq) return;
      api.finish(id, 'decline').catch(noop);
      closeCall(e && e.sbk ? e.message : (cv.kind === 'video' ? 'Your camera or microphone could not be used.'
                                                              : 'Your microphone could not be used.'));
      return;
    }
    if (my !== cv.seq) { stopTracks(); return; }
    emit();

    try {
      const c = await api.state(id);
      if (my !== cv.seq) return;
      if (!c || !c.offer) { closeCall('That call has ended.'); return; }
      const servers = await iceServers();
      if (my !== cv.seq) return;
      cv.pc = buildPeer(servers);
      await cv.pc.setRemoteDescription(desc('offer', c.offer));
      cv.remoteSet = true;
      const a = await cv.pc.createAnswer();
      await cv.pc.setLocalDescription(a);
      await gathered(cv.pc);
      if (my !== cv.seq) return;
      await api.answer(id, cv.pc.localDescription.sdp);
      if (my === cv.seq) guardConnecting();     // the connection state takes it from here
    } catch (e) {
      if (my === cv.seq) hangUp(true);
    }
  }

  /* ---- keeping an eye on the call while it lasts: faster than the chat's own
     beat, because a ring noticed six seconds late is a missed call - but only
     while a call exists. */
  function watch() {
    if (cv.polling) clearInterval(cv.polling);
    cv.polling = setInterval(async () => {
      if (!cv.id || cv.closing) return;
      const my = cv.seq;
      let c;
      try { c = await api.state(cv.id); } catch (e) { return; }
      if (!c || my !== cv.seq || cv.closing) return;
      if (!cv.incoming && c.state === 'accepted' && c.answer && cv.pc && !cv.remoteSet) {
        cv.remoteSet = true;
        snd.connected();
        cv.status = 'connecting';
        setText('Connecting…');
        guardConnecting();
        cv.pc.setRemoteDescription(desc('answer', c.answer)).catch(() => hangUp(true));
      }
      if (c.state !== 'ringing' && c.state !== 'accepted') {
        const why = {
          declined:  cv.incoming ? 'Call declined' : 'They are busy right now',
          cancelled: cv.incoming ? 'Missed call' : 'Call cancelled',
          missed:    cv.incoming ? 'Missed call' : 'No answer',
          failed:    'The call could not connect',
          ended:     'Call ended',
        }[c.state] || 'Call ended';
        closeCall(why);
      }
    }, 900);
  }

  function hangUp(failed) {
    if (!cv.id) { closeCall(); return; }
    const id = cv.id;
    const act = (cv.incoming && !cv.pc) ? 'decline' : 'end';
    api.finish(id, act, !!failed).catch(noop);
    closeCall(failed ? 'The call could not connect. Their network may block calls — try a message.'
                     : (act === 'decline' ? 'Call declined' : 'Call ended'));
  }

  function stopTracks() {
    if (cv.stream) {
      try { cv.stream.getTracks().forEach((t) => t.stop()); } catch (e) {}
      if (cv.stream.release) { try { cv.stream.release(); } catch (e) {} }
      cv.stream = null;
    }
  }

  /* Put everything down, once: tracks, connection, timers, sounds, screen. */
  function closeCall(msg) {
    if (cv.closing) return;
    cv.closing = true;
    cv.seq++;
    cv.lastClosed = cv.id;
    if (cv.polling) { clearInterval(cv.polling); cv.polling = null; }
    if (cv.clock) { clearInterval(cv.clock); cv.clock = null; }
    if (cv.guard) { clearTimeout(cv.guard); cv.guard = null; }
    if (cv.pc) { try { cv.pc.close(); } catch (e) {} cv.pc = null; }
    stopTracks();
    cv.remote = null;
    snd.end();
    cv.status = 'ended';
    setText(msg || 'Call ended');
    setTimeout(() => {
      Object.assign(cv, {
        id: 0, closing: false, startedAt: 0, remoteSet: false, incoming: false,
        status: 'idle', text: '', elapsed: 0, to: 0,
      });
      emit();
    }, msg ? 1600 : 200);
  }

  function toggleMute() {
    if (!cv.stream) return;
    const on = cv.stream.getAudioTracks().some((t) => t.enabled);
    cv.stream.getAudioTracks().forEach((t) => { t.enabled = !on; });
    cv.muted = on;
    emit();
  }

  function toggleCam() {
    if (!cv.stream) return;
    const on = cv.stream.getVideoTracks().some((t) => t.enabled);
    cv.stream.getVideoTracks().forEach((t) => { t.enabled = !on; });
    cv.camOff = on;
    emit();
  }

  function toggleSpeaker() {
    cv.speaker = !cv.speaker;
    snd.speaker(cv.speaker);
    emit();
  }

  async function switchCamera() {
    if (!cv.stream) return;
    const t = cv.stream.getVideoTracks()[0];
    if (!t) return;
    const want = cv.front ? 'environment' : 'user';
    try {
      if (t.applyConstraints) await t.applyConstraints({ facingMode: want });
      else if (t._switchCamera) t._switchCamera();
      cv.front = !cv.front;
    } catch (e) {
      try { if (t._switchCamera) { t._switchCamera(); cv.front = !cv.front; } } catch (e2) {}
    }
    emit();
  }

  return {
    start, answer, notePoll, toggleMute, toggleCam, toggleSpeaker, switchCamera,
    hangUp: () => hangUp(false),
    busy: () => cv.status !== 'idle',
  };
}

export { dur };
