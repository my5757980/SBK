import React, { useEffect, useRef, useState, useCallback, useMemo } from 'react';
import {
  View, Text, TextInput, TouchableOpacity, FlatList, StyleSheet,
  KeyboardAvoidingView, Platform, Image, ActivityIndicator, Alert, AppState,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Feather } from '@expo/vector-icons';
import Avatar from '../Avatar';
import { C, clockOf } from '../theme';
import { useUI, type, page, TOUCH, lift } from '../ui';
import { Empty, Waiting, Notice } from '../bits';
import { poll, send, mediaUrl, reportProblem } from '../api';
import VoiceBubble from '../VoiceBubble';
import { useCallEngine, useCallSnap } from '../calls';
import { dur } from '../callEngine';
import { audioLib, pickerLib } from '../lazy';
import { sendFileToChat } from '../sendFile';
import LinkText from '../LinkText';
import PictureViewer from '../PictureViewer';
import { chime } from '../chime';
import GroupInfo from './GroupInfo';

/* The website's own numbers (chat.js): up to twenty pictures in one go, and a
   voice note stops itself at five minutes - a long voice note is a phone call. */
const MAX_BATCH = 20;
const MAX_VOICE_SECS = 300;

/* The conversation itself.

   A customer opening this for the first time has no thread yet - the row is made
   by the server the moment they say something, exactly as in the browser, which
   is why `to` is sent instead of `thread` until one comes back.

   The beat is the same one the whole chat runs on: one small request every
   second and a half, answered and closed. Nothing here holds a connection open;
   see the note at the top of api/poll.php for why that would take the whole
   account down. */

/** "Today", "Yesterday", or the date - the line between one day and the next. */
function dayOf(iso) {
  if (!iso) return '';
  const d = new Date(String(iso).replace(' ', 'T') + (String(iso).includes('Z') ? '' : 'Z'));
  if (isNaN(d.getTime())) return '';
  const now = new Date();
  const same = (a, b) =>
    a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
  if (same(d, now)) return 'Today';
  const y = new Date(now); y.setDate(now.getDate() - 1);
  if (same(d, y)) return 'Yesterday';
  return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

/* The line a call leaves in the conversation - the same words the website
   writes (chat.js callLogHtml), from the same "kind|how|secs" the server
   stores, so a call looks the same in the phone and in the browser. */
function callLine(m) {
  const b = String(m.body || '').split('|');
  const video = b[0] === 'video', how = b[1] || '', secs = parseInt(b[2] || '0', 10);
  const what = video ? 'Video call' : 'Voice call';
  let line = what, sub = '', missed = false;
  if (how === 'ended') sub = dur(secs);
  if (how === 'missed') { line = m.mine ? what + ' — no answer' : 'Missed ' + what.toLowerCase(); missed = !m.mine; }
  if (how === 'cancelled') { line = m.mine ? what + ' — cancelled' : 'Missed ' + what.toLowerCase(); missed = !m.mine; }
  if (how === 'declined') line = m.mine ? what + ' — declined' : what + ' — you declined';
  if (how === 'failed') line = what + ' — could not connect';
  return { video, line, sub, missed };
}

export default function Chat({ token, target, onBack }) {
  const ui = useUI();
  const inset = useSafeAreaInsets();
  const [msgs, setMsgs] = useState([]);
  const [text, setText] = useState('');
  const [thread, setThread] = useState(target.thread || 0);
  const [busy, setBusy] = useState(false);
  const [first, setFirst] = useState(true);
  const [err, setErr] = useState('');
  const lastId = useRef(0);
  const misses = useRef(0);
  const seen = useRef({});
  const alive = useRef(true);
  const listRef = useRef(null);

  // The header's words - a group can be renamed from inside, and says so at once.
  const [title, setTitle] = useState(target.name);
  const [subLine, setSubLine] = useState(target.sub);
  const [viewAt, setViewAt] = useState(null);      // the picture open full-screen, if any
  const [showInfo, setShowInfo] = useState(false); // the group's own panel
  const [batch, setBatch] = useState(null);        // { n, of } while several pictures go

  /* The tone: never on top of a call, never for the history that loads when
     the conversation opens - only for something that arrives after. */
  const snap = useCallSnap();
  const callIdle = useRef(true);
  callIdle.current = !snap || snap.status === 'idle';
  const primed = useRef(false);
  const unreadWas = useRef(0);

  const isGroup = target.kind === 'group';
  const calls = useCallEngine();
  /* Is the other person here right now, and is this conversation ours to ring
     from? Both follow the live poll, not just what the list said on the way in:
     the phone button follows the green dot, exactly as on the website. */
  const [peerOn, setPeerOn] = useState(!!target.online);
  const [mine, setMine] = useState(!!target.mine);
  const canCall = !isGroup && (target.kind === 'person' || (target.kind === 'thread' && mine));

  /* WHO THIS IS BEING SENT TO - and the app had this wrong.
     A CUSTOMER always names the member of staff (`to`); the server works out
     the conversation from the two of them, exactly as the browser's chat has
     always done. The app instead started sending `thread` as soon as one
     existed, and the server answered every one of those with "That person is
     not available." - so a customer's FIRST message went and nothing after it
     did: no message, no picture, no voice note (18 September 2026, found in
     the access log: 4 x send.php 400, then 3 x upload.php 400).
     Only the desk sends `thread`. */
  const asCustomer = target.kind === 'person';
  const outTo = !isGroup && asCustomer ? target.id : 0;
  const outThread = !isGroup && !asCustomer ? thread : 0;

  const beat = useCallback(async () => {
    try {
      const j = await poll(token, {
        thread: isGroup ? 0 : thread,
        group: isGroup ? target.id : 0,
        after: lastId.current,
        open: true,
      });
      if (!alive.current) return;
      if (j.signedout) { onBack(true); return; }
      if (j.call && calls) calls.notePoll(j.call);
      if (!isGroup && j.people && target.kind === 'person') {
        const p = j.people.find((x) => (x.id | 0) === (target.id | 0));
        if (p) setPeerOn(!!p.online);
      }
      if (!isGroup && j.list && thread) {
        const t = j.list.find((x) => (x.id | 0) === (thread | 0));
        if (t) { setPeerOn(!!t.online); setMine(!!t.mine); }
      }
      let theirs = 0;
      if (j.messages && j.messages.length) {
        const fresh = [];
        for (const m of j.messages) {
          if (m.id > lastId.current) lastId.current = m.id;
          if (!seen.current[m.id]) {
            seen.current[m.id] = 1;
            fresh.push(m);
            if (!m.mine) theirs += 1;
          }
        }
        if (fresh.length) setMsgs((old) => [...old, ...fresh]);
      }
      /* The website's chime (chat.js): no tone for the conversation you are
         looking at - only when the app is not in front of you - and the bell's
         job, somebody writing in ANOTHER conversation, is the total going up
         (this one's messages are read as they arrive, so they never count). */
      const total = j.unread | 0;
      if (primed.current && callIdle.current
          && ((theirs && AppState.currentState !== 'active') || total > unreadWas.current)) {
        chime();
      }
      unreadWas.current = total;
      primed.current = true;
      /* The ticks move AFTER the message was drawn - that is the whole point of
         them - so they arrive separately and are painted onto what is already on
         the screen rather than re-sent with the message. */
      if (j.receipts && j.receipts.length) {
        setMsgs((old) => {
          const by = {};
          for (const r of j.receipts) by[r.id] = r;
          let touched = false;
          const next = old.map((m) => {
            const r = by[m.id];
            if (!r) return m;
            if (!!m.delivered === !!r.d && !!m.read === !!r.r) return m;
            touched = true;
            return { ...m, delivered: !!r.d, read: !!r.r };
          });
          return touched ? next : old;
        });
      }
      misses.current = 0;
      setErr('');
    } catch (e) {
      /* One beat that does not come back is a phone stepping between wifi and
         the mobile network, not a fault - and a red line for it is how the
         owner came to be told "No connection" while the server was answering
         every single poll. Three in a row is a real one. */
      misses.current += 1;
      if (alive.current && misses.current >= 3) setErr(e.message);
    } finally {
      if (alive.current) setFirst(false);
    }
  }, [token, thread, isGroup, target.id, target.kind, onBack, calls]);

  useEffect(() => {
    alive.current = true;
    beat();
    const t = setInterval(beat, 1500);
    return () => { alive.current = false; clearInterval(t); };
  }, [beat]);

  /* The day lines are worked out once per change, not inside the row - a list
     that recomputes dates while it scrolls is a list that stutters. */
  const rows = useMemo(() => {
    const out = [];
    let day = '';
    for (const m of msgs) {
      const d = dayOf(m.at);
      if (d && d !== day) { day = d; out.push({ _day: d, id: 'd' + m.id }); }
      out.push(m);
    }
    return out;
  }, [msgs]);

  async function go() {
    const body = text.trim();
    if (!body || busy) return;
    setBusy(true); setText('');
    try {
      const j = await send(token, {
        group: isGroup ? target.id : 0,
        thread: outThread,
        to: outTo,
        body,
      });
      if (j.thread) setThread(j.thread);
      const m = j.message;
      if (m && !seen.current[m.id]) {
        seen.current[m.id] = 1;
        if (m.id > lastId.current) lastId.current = m.id;
        setMsgs((old) => [...old, m]);
      }
      setErr('');
    } catch (e) {
      setText(body);            // it stays in the box rather than vanishing
      setErr(e.message);
    } finally {
      setBusy(false);
    }
  }

  /* A picture or a voice note. Both land through upload.php the same way the
     browser's own compose bar sends them - one request, one message back -
     so it appears in the same row the website would show it in, the moment it
     is done. Says whether it went; the caller decides what `busy` means. */
  async function sendOne(kind, file, secs) {
    try {
      const j = await sendFileToChat(token, {
        group: isGroup ? target.id : 0,
        thread: outThread,
        to: outTo,
        kind, secs,
        uri: file.uri, name: file.name, mime: file.type,
      });
      if (j.thread) setThread(j.thread);
      const m = j.message;
      if (m && !seen.current[m.id]) {
        seen.current[m.id] = 1;
        if (m.id > lastId.current) lastId.current = m.id;
        setMsgs((old) => [...old, m]);
      }
      setErr('');
      return true;
    } catch (e) {
      setErr(e.message);
      return false;
    }
  }

  async function sendFile(kind, file, secs) {
    setBusy(true);
    try { await sendOne(kind, file, secs); } finally { setBusy(false); }
  }

  /* Several pictures, ONE AT A TIME and in the order they were chosen - the
     website's pump(): they arrive in that order for the other person too, and
     "Sending 2 of 5" tells whoever chose five that the rest are coming. If one
     fails, the rest are held back rather than sent out of order, and the person
     is told how far it got. */
  async function sendPictures(assets) {
    const list = assets.slice(0, MAX_BATCH);
    if (assets.length > MAX_BATCH) {
      Alert.alert('Up to ' + MAX_BATCH + ' pictures at a time', 'The first ' + MAX_BATCH + ' are being sent.');
    }
    setBusy(true);
    try {
      for (let k = 0; k < list.length; k += 1) {
        if (list.length > 1) setBatch({ n: k + 1, of: list.length });
        const a = list[k];
        const name = a.fileName || ('photo-' + Date.now() + '-' + (k + 1) + '.jpg');
        const type = a.mimeType || 'image/jpeg';
        // eslint-disable-next-line no-await-in-loop
        const ok = await sendOne('image', { uri: a.uri, name, type }, 0);
        if (!ok) {
          const left = list.length - k - 1;
          if (left) {
            setErr((e) => (e ? e + ' ' : '') + left + ' more picture' + (left === 1 ? ' was' : 's were') + ' not sent.');
          }
          break;
        }
      }
    } finally {
      setBatch(null);
      setBusy(false);
    }
  }

  /* The picture button does what it always did - opens the gallery - and now
     lets several be chosen, as the website does. No extra question in between:
     the owner's rule is that the app's look and feel stay exactly as they were
     and only what it can DO grows (19 September 2026). */
  async function pickPictures() {
    if (busy || recState !== 'idle') return;
    const P = pickerLib();                 // loaded on first use - see lazy.js
    if (!P) { setErr('Pictures cannot be sent from this phone yet.'); return; }
    let res;
    try {
      const perm = await P.requestMediaLibraryPermissionsAsync();
      if (!perm.granted) {
        Alert.alert('No access to photos', 'Allow photo access in Settings to send a picture.');
        return;
      }
      res = await P.launchImageLibraryAsync({
        mediaTypes: ['images'],
        quality: 0.7,
        allowsMultipleSelection: true,
        selectionLimit: MAX_BATCH,
        orderedSelection: true,
      });
    } catch (e) {
      setErr('Could not open your photos.');
      reportProblem('picture: the photo picker failed', e);
      return;
    }
    if (!res || res.canceled || !res.assets || !res.assets.length) return;
    await sendPictures(res.assets);
  }

  /* Every picture in the conversation, in order - what the full-screen view
     swipes through, as the website's lightbox does. */
  const shots = useMemo(
    () => msgs.filter((m) => m.kind === 'image' && m.media).map((m) => ({ id: m.id, uri: mediaUrl(token, m.id) })),
    [msgs, token],
  );
  function openPicture(id) {
    const k = shots.findIndex((p) => p.id === id);
    if (k >= 0) setViewAt(k);
  }

  /* ------------------------------------------------------------- recording
     expo-audio, the SDK 57 library (expo-av was retired after SDK 54). The
     recorder belongs to this screen and is released with it. */
  const A = audioLib();                   // loaded on first use - see lazy.js
  // The same answer for the life of the app, so this hook runs on every render or on none.
  const recorder = A ? A.useAudioRecorder(A.RecordingPresets.HIGH_QUALITY) : null;
  const [recState, setRecState] = useState('idle');   // idle | recording
  const [recSecs, setRecSecs] = useState(0);
  const recOn = useRef(false);
  const recTimer = useRef(null);

  useEffect(() => () => {
    if (recTimer.current) clearInterval(recTimer.current);
    if (recOn.current && recorder) { try { recorder.stop(); } catch (e) {} }
  }, [recorder]);

  async function startRecording() {
    if (busy || recOn.current) return;
    if (!A || !recorder) { setErr('Voice notes cannot be recorded on this phone yet.'); return; }
    try {
      const perm = await A.requestRecordingPermissionsAsync();
      if (!perm.granted) {
        Alert.alert('No access to the microphone', 'Allow microphone access in Settings to send a voice note.');
        return;
      }
      await A.setAudioModeAsync({ allowsRecording: true, playsInSilentMode: true });
      await recorder.prepareToRecordAsync();
      recorder.record();
      recOn.current = true;
      setRecSecs(0);
      setRecState('recording');
      recTimer.current = setInterval(() => setRecSecs((n) => n + 1), 1000);
    } catch (e) {
      recOn.current = false;
      setErr('Could not start recording.');
      reportProblem('voice note could not start recording', e);
    }
  }

  async function stopRecorder() {
    if (recTimer.current) { clearInterval(recTimer.current); recTimer.current = null; }
    setRecState('idle'); setRecSecs(0);
    if (!recOn.current || !recorder) return null;
    recOn.current = false;
    try { await recorder.stop(); } catch (e) {}
    try { await A.setAudioModeAsync({ allowsRecording: false, playsInSilentMode: true }); } catch (e) {}
    return recorder.uri;
  }

  async function cancelRecording() {
    await stopRecorder();
  }

  /* Five minutes and the recording sends itself, as the website's does. */
  useEffect(() => {
    if (recState === 'recording' && recSecs >= MAX_VOICE_SECS) finishRecording();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [recSecs, recState]);

  async function finishRecording() {
    const secs = recSecs;
    const uri = await stopRecorder();
    // Silence here is how a voice note "does not work": say what happened.
    if (secs < 1) { setErr('That was too short - hold the button a moment longer.'); return; }
    if (!uri) {
      setErr('The recording could not be saved on this phone.');
      reportProblem('voice note: the recorder gave back no file (secs ' + secs + ')', new Error('no uri'));
      return;
    }
    try {
      await sendFile('voice', { uri, name: 'voice-' + Date.now() + '.m4a', type: 'audio/m4a' }, secs);
    } catch (e) {
      setErr('Could not send that voice note.');
    }
  }

  const canSend = !!text.trim() && !busy;
  const recording = recState === 'recording';

  return (
    <KeyboardAvoidingView
      style={page}
      behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
    >
      {/* ---- who this is */}
      <View
        style={[
          s.top,
          { paddingTop: inset.top + ui.s(8), paddingBottom: ui.s(10), paddingHorizontal: ui.s(8) },
        ]}
      >
        <TouchableOpacity
          onPress={() => onBack(false)}
          accessibilityRole="button"
          accessibilityLabel="Back"
          hitSlop={10}
          style={{
            width: ui.tap(TOUCH - 6), height: ui.tap(TOUCH - 6),
            alignItems: 'center', justifyContent: 'center',
          }}
        >
          <Feather name="chevron-left" size={ui.s(26)} color="#fff" />
        </TouchableOpacity>

        {/* A group's name opens the group itself - members, name, leave - as
            clicking the group's header does on the website. */}
        <TouchableOpacity
          disabled={!isGroup}
          onPress={() => setShowInfo(true)}
          activeOpacity={0.7}
          accessibilityRole={isGroup ? 'button' : undefined}
          accessibilityLabel={isGroup ? 'Group details' : undefined}
          style={{ flex: 1, minWidth: 0, flexDirection: 'row', alignItems: 'center' }}
        >
          <Avatar
            name={title}
            ini={target.ini}
            hue={target.hue}
            size={ui.s(38)}
            online={!isGroup && peerOn}
            square={isGroup}
          />
          <View style={{ flex: 1, minWidth: 0, marginLeft: ui.s(10) }}>
            <Text
              maxFontSizeMultiplier={ui.maxFont}
              numberOfLines={1}
              style={[type('title', ui), { color: '#fff' }]}
            >
              {title}
            </Text>
            <Text
              maxFontSizeMultiplier={ui.maxFont}
              numberOfLines={1}
              style={[type('sub', ui), { color: '#B9C6DC', marginTop: ui.s(1) }]}
            >
              {peerOn && !isGroup ? 'Online now' : subLine}
            </Text>
          </View>
        </TouchableOpacity>

        {/* The phone and the camera: only for the two people in this
            conversation - and they work whether or not that person is at their
            screen. They used to be dead while the other side was away; the
            owner corrected that on 20 September 2026: "if the person isn't
            online, why is the call button getting disabled? The call should
            still go through." It rings their phone through the notification,
            and if nobody answers the chat writes it up as a missed call. */}
        {canCall && calls ? (
          <View style={{ flexDirection: 'row', gap: ui.s(2) }}>
            {[['phone', 'voice', 'Voice call'], ['video', 'video', 'Video call']].map(([icon, kind, label]) => (
              <TouchableOpacity
                key={kind}
                onPress={() => calls.start(kind, {
                  thread: target.kind === 'thread' ? thread : 0,
                  to: target.kind === 'person' ? target.id : 0,
                  peer: target.name, peerId: target.id, hue: target.hue,
                })}
                accessibilityRole="button"
                accessibilityLabel={peerOn ? label : label + ' - they are away, it will ring their phone'}
                hitSlop={6}
                style={{
                  width: ui.tap(TOUCH - 6), height: ui.tap(TOUCH - 6),
                  alignItems: 'center', justifyContent: 'center',
                }}
              >
                <Feather name={icon} size={ui.s(20)} color="#fff" />
              </TouchableOpacity>
            ))}
          </View>
        ) : null}
      </View>

      {err ? <View style={{ paddingHorizontal: ui.s(14) }}><Notice text={err} /></View> : null}

      {first ? (
        <Waiting />
      ) : (
        <FlatList
          ref={listRef}
          style={{ flex: 1 }}
          data={rows}
          keyExtractor={(m) => String(m.id)}
          contentContainerStyle={{
            padding: ui.s(14),
            paddingBottom: ui.s(8),
            maxWidth: ui.col,
            alignSelf: 'center',
            width: '100%',
            flexGrow: 1,
          }}
          onContentSizeChange={() =>
            listRef.current && listRef.current.scrollToEnd({ animated: true })}
          showsVerticalScrollIndicator={false}
          ListEmptyComponent={
            <Empty
              icon="message-square"
              head="Say hello"
              note={'This is the beginning of your conversation with ' + target.name + '.'}
            />
          }
          renderItem={({ item: m }) => {
            if (m._day) {
              return (
                <View style={{ alignItems: 'center', marginVertical: ui.s(10) }}>
                  <View
                    style={[
                      s.day,
                      { paddingHorizontal: ui.s(11), paddingVertical: ui.s(4), borderRadius: ui.s(9) },
                    ]}
                  >
                    <Text
                      maxFontSizeMultiplier={1}
                      style={{ fontSize: ui.s(11), fontWeight: '700', color: C.ink3 }}
                    >
                      {m._day}
                    </Text>
                  </View>
                </View>
              );
            }
            const mine = !!m.mine;
            return (
              <View style={[s.row, mine && { justifyContent: 'flex-end' }, { marginBottom: ui.s(8) }]}>
                <View
                  style={[
                    {
                      maxWidth: '84%',
                      borderRadius: ui.s(16),
                      paddingHorizontal: ui.s(12),
                      paddingVertical: ui.s(9),
                    },
                    mine
                      ? { backgroundColor: C.navy, borderBottomRightRadius: ui.s(5) }
                      : { backgroundColor: C.card, borderBottomLeftRadius: ui.s(5) },
                    lift,
                  ]}
                >
                  {/* In a group, whose bubble it is. Never on your own - you know. */}
                  {m.who && !mine ? (
                    <Text
                      maxFontSizeMultiplier={ui.maxFont}
                      style={{
                        fontSize: ui.s(12), fontWeight: '800',
                        color: C.blue, marginBottom: ui.s(3),
                      }}
                    >
                      {m.who}
                    </Text>
                  ) : null}

                  {m.kind === 'call' ? (() => {
                    const c = callLine(m);
                    const ink = mine ? '#fff' : (c.missed ? C.red : C.ink);
                    return (
                      <View style={{ flexDirection: 'row', alignItems: 'center', gap: ui.s(10), minWidth: ui.s(150) }}>
                        <View
                          style={{
                            width: ui.s(34), height: ui.s(34), borderRadius: ui.s(17),
                            alignItems: 'center', justifyContent: 'center',
                            backgroundColor: mine ? 'rgba(255,255,255,.18)' : (c.missed ? C.redWash : C.navyWash),
                          }}
                        >
                          <Feather
                            name={c.video ? 'video' : (c.missed ? 'phone-missed' : 'phone')}
                            size={ui.s(16)}
                            color={mine ? '#fff' : (c.missed ? C.red : C.navy)}
                          />
                        </View>
                        <View style={{ flexShrink: 1 }}>
                          <Text maxFontSizeMultiplier={ui.maxFont} style={[type('body', ui), { color: ink, fontWeight: '600' }]}>
                            {c.line}
                          </Text>
                          {c.sub ? (
                            <Text maxFontSizeMultiplier={ui.maxFont} style={[type('sub', ui), { color: mine ? '#C9D5E6' : C.ink3 }]}>
                              {c.sub}
                            </Text>
                          ) : null}
                        </View>
                      </View>
                    );
                  })() : m.kind === 'image' && m.media ? (
                    <TouchableOpacity
                      activeOpacity={0.85}
                      onPress={() => openPicture(m.id)}
                      accessibilityRole="imagebutton"
                      accessibilityLabel="Open the picture"
                    >
                      <Image
                        source={{ uri: mediaUrl(token, m.id) }}
                        style={{
                          width: Math.min(ui.s(230), ui.width * 0.62),
                          height: Math.min(ui.s(172), ui.width * 0.47),
                          borderRadius: ui.s(10),
                          marginBottom: ui.s(4),
                          backgroundColor: C.line2,
                        }}
                        resizeMode="cover"
                      />
                    </TouchableOpacity>
                  ) : m.kind === 'voice' && m.media ? (
                    <VoiceBubble uri={mediaUrl(token, m.id)} mine={mine} knownSecs={m.secs} />
                  ) : (
                    <LinkText
                      text={m.body}
                      maxFontSizeMultiplier={ui.maxFont}
                      style={[type('body', ui), { color: mine ? '#fff' : C.ink }]}
                      linkStyle={{ textDecorationLine: 'underline', color: mine ? '#fff' : C.blue }}
                    />
                  )}

                  <View style={[s.foot, { gap: ui.s(4), marginTop: ui.s(3) }]}>
                    <Text
                      maxFontSizeMultiplier={1}
                      style={{ fontSize: ui.s(10.5), color: mine ? '#9FB2CE' : C.ink3 }}
                    >
                      {clockOf(m.at)}
                    </Text>
                    {mine ? (
                      <Feather
                        name={m.delivered || m.read ? 'check-circle' : 'check'}
                        size={ui.s(12)}
                        color={m.read ? C.tick : '#9FB2CE'}
                      />
                    ) : null}
                  </View>
                </View>
              </View>
            );
          }}
        />
      )}

      {/* "Sending 2 of 5 photos…" - the website's own words, so whoever chose
          five and sees one arrive knows the rest are on their way. */}
      {batch ? (
        <View style={[s.sending, { paddingVertical: ui.s(6), paddingHorizontal: ui.s(14), gap: ui.s(8) }]}>
          <ActivityIndicator color={C.navy} size="small" />
          <Text maxFontSizeMultiplier={ui.maxFont} style={[type('sub', ui), { color: C.ink2 }]}>
            Sending {batch.n} of {batch.of} photos…
          </Text>
        </View>
      ) : null}

      {/* ---- writing */}
      <View
        style={[
          s.compose,
          {
            paddingHorizontal: ui.s(10),
            paddingTop: ui.s(9),
            paddingBottom: Math.max(inset.bottom, ui.s(9)),
            gap: ui.s(9),
          },
        ]}
      >
        {recording ? (
          <>
            <TouchableOpacity
              onPress={cancelRecording}
              accessibilityRole="button"
              accessibilityLabel="Cancel recording"
              style={{
                width: ui.tap(TOUCH - 4), height: ui.tap(TOUCH - 4),
                alignItems: 'center', justifyContent: 'center',
              }}
            >
              <Feather name="trash-2" size={ui.s(19)} color={C.ink3} />
            </TouchableOpacity>

            <View
              style={{
                flex: 1, flexDirection: 'row', alignItems: 'center', gap: ui.s(9),
                minHeight: ui.tap(TOUCH - 4), backgroundColor: C.paper,
                borderRadius: ui.s(21), paddingHorizontal: ui.s(15),
              }}
            >
              <View style={{
                width: ui.s(9), height: ui.s(9), borderRadius: ui.s(4.5), backgroundColor: C.red,
              }} />
              <Text maxFontSizeMultiplier={1} style={[type('body', ui), { color: C.ink2 }]}>
                Recording… {Math.floor(recSecs / 60)}:{String(recSecs % 60).padStart(2, '0')}
              </Text>
            </View>

            <TouchableOpacity
              onPress={finishRecording}
              accessibilityRole="button"
              accessibilityLabel="Send voice note"
              disabled={busy}
              style={[
                {
                  width: ui.tap(TOUCH - 4), height: ui.tap(TOUCH - 4), borderRadius: ui.s(TOUCH / 2),
                  backgroundColor: C.navy, alignItems: 'center', justifyContent: 'center',
                },
                lift,
              ]}
            >
              {busy
                ? <ActivityIndicator color="#fff" size="small" />
                : <Feather name="check" size={ui.s(19)} color="#fff" />}
            </TouchableOpacity>
          </>
        ) : (
          <>
            <TouchableOpacity
              onPress={pickPictures}
              disabled={busy}
              accessibilityRole="button"
              accessibilityLabel="Send a picture"
              style={{
                width: ui.tap(TOUCH - 4), height: ui.tap(TOUCH - 4),
                alignItems: 'center', justifyContent: 'center', opacity: busy ? 0.4 : 1,
              }}
            >
              <Feather name="image" size={ui.s(21)} color={C.navy} />
            </TouchableOpacity>

            <TextInput
              style={[
                type('body', ui),
                {
                  flex: 1,
                  maxHeight: ui.s(116),
                  minHeight: ui.tap(TOUCH - 4),
                  backgroundColor: C.paper,
                  borderRadius: ui.s(21),
                  paddingHorizontal: ui.s(15),
                  paddingTop: ui.s(11),
                  paddingBottom: ui.s(11),
                  color: C.ink,
                },
              ]}
              maxFontSizeMultiplier={ui.maxFont}
              value={text}
              onChangeText={setText}
              placeholder="Write a message…"
              placeholderTextColor={C.ink3}
              multiline
              maxLength={4000}
            />

            <TouchableOpacity
              onPress={canSend ? go : startRecording}
              disabled={busy}
              accessibilityRole="button"
              accessibilityLabel={canSend ? 'Send' : 'Record a voice note'}
              style={[
                {
                  width: ui.tap(TOUCH - 4), height: ui.tap(TOUCH - 4), borderRadius: ui.s(TOUCH / 2),
                  backgroundColor: C.navy, alignItems: 'center', justifyContent: 'center',
                  opacity: busy ? 0.4 : 1,
                },
                !busy && lift,
              ]}
            >
              {busy ? (
                <ActivityIndicator color="#fff" size="small" />
              ) : (
                <Feather name={canSend ? 'send' : 'mic'} size={ui.s(18)} color="#fff" />
              )}
            </TouchableOpacity>
          </>
        )}
      </View>

      {viewAt !== null ? (
        <PictureViewer shots={shots} at={viewAt} onClose={() => setViewAt(null)} />
      ) : null}

      {showInfo && isGroup ? (
        <GroupInfo
          token={token}
          gid={target.id}
          onClose={() => setShowInfo(false)}
          onChanged={(g) => {
            setTitle(g.name);
            setSubLine(g.count + (g.count === 1 ? ' member' : ' members'));
          }}
          onLeft={() => { setShowInfo(false); onBack(false); }}
        />
      ) : null}
    </KeyboardAvoidingView>
  );
}

const s = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', backgroundColor: C.navy },
  row: { flexDirection: 'row' },
  day: { backgroundColor: C.line2 },
  foot: { flexDirection: 'row', alignItems: 'center', alignSelf: 'flex-end' },
  sending: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center',
    backgroundColor: C.navyWash, borderTopWidth: 1, borderTopColor: C.line,
  },
  compose: {
    flexDirection: 'row', alignItems: 'flex-end',
    backgroundColor: C.card, borderTopWidth: 1, borderTopColor: C.line,
  },
});
