import React, { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { PermissionsAndroid, Platform } from 'react-native';
import { createCallEngine } from './callEngine';
import { iceServers, callStart, callState, callAnswer, callFinish, reportProblem } from './api';

/* Where the call engine meets the phone.
   -------------------------------------------------------------------------
   callEngine.js is the website's calling code and knows nothing about phones.
   This file hands it the three things a phone does differently:

     the microphone and camera   react-native-webrtc, asked for permission first
     the sound of a call         InCallManager - the phone's own ringtone, the
                                 ring-back tone, earpiece for voice, speaker for video
     the server                  api.js, with this person's pass

   THE FIRST APK WITH CALLS CLOSED ITSELF THE MOMENT IT OPENED (2026-09-18).
   The real cause was a different library entirely: `expo-av`, used for voice
   notes, was retired by Expo after SDK 54 and is not meant to run on this
   app's SDK (57) - its own native start-up code reaches for a bridge object
   that is not there under this architecture. It has been replaced everywhere
   with `expo-audio`, the library Expo actually ships for this SDK - see
   VoiceBubble.js and Chat.js's recorder.

   That was the known cause, but react-native-webrtc is real native code too
   (its own camera/audio drivers, a whole WebRTC engine) and this was the
   first time it had ever run on a phone rather than inside a test browser.
   So as a second line of defence, `require('react-native-webrtc')` and
   `require('react-native-incall-manager')` happen HERE, on first use, inside
   a try - not at the top of the file, where a failure would be silent and
   total. If either cannot start, the app still opens; a call says so in
   plain words instead of a message that never sends; and the reason is
   reported to our own server (api/crash.php) so it can be read rather than
   guessed at. What this CANNOT do is stop a crash inside the native module's
   own construction, which Android runs when the app starts, before any of
   this file's JavaScript executes - that is a real, separate risk this
   change does not remove, and the crash report is what would prove whether
   it ever happens. */

const EngineCtx = createContext(null);
const SnapCtx = createContext({ status: 'idle' });

let webrtcLib = null;
let webrtcErr = null;

/** react-native-webrtc, loaded on first use - or an error that says why not. */
function webrtc() {
  if (!webrtcLib && !webrtcErr) {
    try {
      // eslint-disable-next-line global-require
      webrtcLib = require('react-native-webrtc');
      if (!webrtcLib || !webrtcLib.RTCPeerConnection) throw new Error('react-native-webrtc loaded without RTCPeerConnection');
    } catch (e) {
      webrtcErr = e;
      reportProblem('calls could not load react-native-webrtc', e);
    }
  }
  if (webrtcErr) {
    const err = new Error('Calls are not available on this phone yet. Please use a message for now.');
    err.sbk = true;
    throw err;
  }
  return webrtcLib;
}

/** The video surface, or null when WebRTC could not start. */
export function rtcView() {
  try { return webrtc().RTCView; } catch (e) { return null; }
}

let incallLib;
function incall() {
  if (incallLib === undefined) {
    try {
      // eslint-disable-next-line global-require
      const m = require('react-native-incall-manager');
      incallLib = (m && m.default) || m || null;
    } catch (e) {
      incallLib = null;
      reportProblem('calls could not load react-native-incall-manager', e);
    }
  }
  return incallLib;
}

async function askFor(wantVideo) {
  if (Platform.OS !== 'android') return;
  const want = [PermissionsAndroid.PERMISSIONS.RECORD_AUDIO];
  if (wantVideo) want.push(PermissionsAndroid.PERMISSIONS.CAMERA);
  const got = await PermissionsAndroid.requestMultiple(want);
  if (want.some((p) => got[p] !== PermissionsAndroid.RESULTS.GRANTED)) {
    throw new Error('permission');
  }
}

/* Every native sound call is wrapped: a ringtone that fails to play must never
   be the reason a call fails. */
function safe(fn) {
  return (...a) => {
    try {
      const m = incall();
      if (m) fn(m, ...a);
    } catch (e) {}
  };
}

const audio = {
  outgoing: safe((m, kind) => {
    m.start({ media: kind === 'video' ? 'video' : 'audio', ringback: '_DTMF_' });
  }),
  incoming: safe((m) => {
    m.startRingtone('_DEFAULT_', [0, 800, 1200], 'playback', -1);
  }),
  answered: safe((m, kind) => {
    m.stopRingtone();
    m.start({ media: kind === 'video' ? 'video' : 'audio' });
  }),
  connected: safe((m) => { m.stopRingback(); }),
  speaker: safe((m, on) => { m.setForceSpeakerphoneOn(!!on); }),
  end: safe((m) => {
    m.stopRingtone();
    m.stopRingback();
    m.stop();
  }),
};

/* The engine reaches these only once a call is actually being made or
   answered - which is the moment WebRTC is loaded, not before. */
const rtc = {
  get RTCPeerConnection() { return webrtc().RTCPeerConnection; },
  get RTCSessionDescription() { return webrtc().RTCSessionDescription; },
  mediaDevices: {
    getUserMedia: async (c) => {
      const lib = webrtc();              // throws the plain-words error if calls cannot start
      await askFor(!!c.video);
      return lib.mediaDevices.getUserMedia(c);
    },
  },
};

export function CallProvider({ token, children }) {
  const [snap, setSnap] = useState({ status: 'idle' });

  const engine = useMemo(() => {
    if (!token) return null;
    return createCallEngine({
      api: {
        ice: () => iceServers(token),
        start: (o) => callStart(token, o),
        state: (id) => callState(token, id),
        answer: (id, sdp) => callAnswer(token, id, sdp),
        finish: (id, action, failed) => callFinish(token, id, action, failed),
      },
      rtc,
      audio,
      onChange: setSnap,
    });
  }, [token]);

  // Signing out, or a different person signing in, puts any call down first.
  useEffect(() => () => {
    if (engine && engine.busy()) engine.hangUp();
  }, [engine]);

  return (
    <EngineCtx.Provider value={engine}>
      <SnapCtx.Provider value={snap}>{children}</SnapCtx.Provider>
    </EngineCtx.Provider>
  );
}

/** The engine - stable for as long as the same person is signed in. */
export function useCallEngine() { return useContext(EngineCtx); }

/** What the call screen draws - changes every second while a call is live. */
export function useCallSnap() { return useContext(SnapCtx); }
