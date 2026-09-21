import React, { useEffect, useRef, useState } from 'react';
import { View, Text, TouchableOpacity, StyleSheet, StatusBar, Platform } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import * as SecureStore from 'expo-secure-store';
import * as SystemUI from 'expo-system-ui';

import SignIn from './src/screens/SignIn';
import SignUp from './src/screens/SignUp';
import People from './src/screens/People';
import Chat from './src/screens/Chat';
import { C } from './src/theme';
import { Waiting } from './src/bits';
import { whoAmI, reportProblem } from './src/api';
import { CallProvider, useCallEngine, useCallSnap } from './src/calls';
import CallScreen from './src/screens/CallScreen';
import { startPush, stopPush, notif, payloadOf, targetOf, doAction } from './src/push';

/* "Answer" pressed on a call notification: the app has opened, and the moment
   the ringing call reaches the call engine (the next poll hands it in) it is
   picked up - no second tap. Given up after a minute: that call is over. */
function PushAnswer({ answerRef }) {
  const engine = useCallEngine();
  const snap = useCallSnap();
  useEffect(() => {
    const want = answerRef.current;
    if (!want || !engine) return;
    if (Date.now() - want.at > 60000) { answerRef.current = null; return; }
    if (snap.status === 'incoming' && (snap.id | 0) === want.id) {
      answerRef.current = null;
      engine.answer();
    }
  }, [snap.status, snap.id, engine, answerRef]);
  return null;
}

/* SBK Chat, on a phone.
   -------------------------------------------------------------------------
   The same chat the website runs, reaching the same endpoints and the same
   database - not a copy of it. A conversation started here IS the conversation
   the desk sees in its browser, because there is one server and one set of rows
   behind both.

   The pass is kept in the phone's own secure store rather than ordinary
   storage: it is the one thing on the device worth anything, and it should be
   held the way a password manager holds things. It is checked against the
   website every time the app opens, so an account taken away over there is out
   before the first screen is drawn. */

const KEY = 'sbk.pass';

/* If a screen throws while drawing, show a way back instead of closing the
   app - and tell our server what broke. */
class Guard extends React.Component {
  constructor(props) { super(props); this.state = { err: null }; }

  static getDerivedStateFromError(err) { return { err }; }

  componentDidCatch(err, info) {
    const where = info && info.componentStack ? String(info.componentStack).slice(0, 1500) : '';
    reportProblem('a screen failed to draw ' + where, err);
  }

  render() {
    if (!this.state.err) return this.props.children;
    return (
      <View style={s.oops}>
        <Text style={s.oopsHead}>Something went wrong</Text>
        <Text style={s.oopsNote}>It has been reported. Please try again.</Text>
        <TouchableOpacity onPress={() => this.setState({ err: null })} style={s.oopsBtn}>
          <Text style={s.oopsBtnTxt}>Try again</Text>
        </TouchableOpacity>
      </View>
    );
  }
}

export default function App() {
  const [ready, setReady] = useState(false);
  const [token, setToken] = useState('');
  const [me, setMe] = useState(null);
  const [where, setWhere] = useState('signin');   // signin | signup | people | chat
  const [target, setTarget] = useState(null);

  /* A notification tapped: the conversation it belongs to, opened as soon as
     somebody is signed in (a cold start is still restoring the pass). */
  const pendingOpen = useRef(null);
  const answerRef = useRef(null);
  const seenResponses = useRef(new Set());
  const [tick, setTick] = useState(0);

  useEffect(() => {
    SystemUI.setBackgroundColorAsync(C.paper).catch(() => {});
    (async () => {
      try {
        const saved = await SecureStore.getItemAsync(KEY);
        if (saved) {
          const j = await whoAmI(saved);
          if (j && j.ok && j.user) {
            setToken(saved); setMe(j.user); setWhere('people');
            startPush(saved);
          } else {
            await SecureStore.deleteItemAsync(KEY);
          }
        }
      } catch (e) {
        /* No signal on the way in is not a reason to sign somebody out - they
           start at the front door and try again. */
      } finally {
        setReady(true);
      }
    })();
  }, []);

  /* Notifications tapped - or their buttons pressed while the app was open. */
  useEffect(() => {
    const Nn = notif();
    if (!Nn) return undefined;
    function handle(r) {
      if (!r || !r.notification) return;
      const key = (r.notification.request && r.notification.request.identifier) + '|'
        + r.actionIdentifier + '|' + (r.notification.date || '');
      if (seenResponses.current.has(key)) return;
      seenResponses.current.add(key);
      const act = r.actionIdentifier;
      if (act === 'reply' || act === 'read' || act === 'decline') {
        doAction(act, r.userText, r.notification).catch((e) => reportProblem('push: a button failed', e));
        return;
      }
      const p = payloadOf(r.notification);
      if (p.type === 'call' && act === 'answer') answerRef.current = { id: p.call | 0, at: Date.now() };
      const t = targetOf(p);
      if (t) {
        pendingOpen.current = t;
        setTick((n) => n + 1);
      }
      Nn.clearLastNotificationResponseAsync().catch(() => {});
    }
    Nn.getLastNotificationResponseAsync().then(handle).catch(() => {});
    const sub = Nn.addNotificationResponseReceivedListener(handle);
    return () => sub.remove();
  }, []);

  useEffect(() => {
    if (!pendingOpen.current || !me || !token) return;
    const t = pendingOpen.current;
    pendingOpen.current = null;
    setTarget(t);
    setWhere('chat');
  }, [tick, me, token]);

  async function arrived(pass, user) {
    setToken(pass); setMe(user); setWhere('people');
    try { await SecureStore.setItemAsync(KEY, pass); } catch (e) {}
    startPush(pass);
  }

  async function leave() {
    // This phone stops receiving this person's notifications first.
    await stopPush();
    try { await SecureStore.deleteItemAsync(KEY); } catch (e) {}
    setToken(''); setMe(null); setTarget(null); setWhere('signin');
  }

  /* The bar at the top of the phone belongs to whichever screen is showing:
     white letters on navy once somebody is in, dark letters on the pale
     sign-in page. Getting this wrong is the quickest way for an app to look
     unfinished on the very first screen. */
  const onNavy = where === 'people' || where === 'chat';

  return (
    <SafeAreaProvider>
      <Guard>
      <CallProvider token={token}>
      <View style={s.all}>
        <StatusBar
          barStyle={onNavy ? 'light-content' : 'dark-content'}
          backgroundColor={onNavy ? C.navy : C.paper}
          translucent={Platform.OS === 'android' ? false : undefined}
        />
        {!ready ? (
          <Waiting />
        ) : (
          <>
            {where === 'signin' && (
              <SignIn onDone={arrived} goRegister={() => setWhere('signup')} />
            )}
            {where === 'signup' && (
              <SignUp onDone={arrived} goSignIn={() => setWhere('signin')} />
            )}
            {where === 'people' && me && (
              <People
                token={token}
                me={me}
                onOpen={(t) => { setTarget(t); setWhere('chat'); }}
                onSignOut={leave}
              />
            )}
            {where === 'chat' && me && target && (
              <Chat
                // A notification can open another conversation while one is open: a fresh screen for it.
                key={target.kind + ':' + target.id}
                token={token}
                target={target}
                onBack={(out) => (out ? leave() : setWhere('people'))}
              />
            )}
          </>
        )}
        {/* Over everything, whichever screen is open - a ring must reach the
            person wherever they are in the app, as a phone call does. */}
        {ready && me ? <CallScreen /> : null}
        {ready && me ? <PushAnswer answerRef={answerRef} /> : null}
      </View>
      </CallProvider>
      </Guard>
    </SafeAreaProvider>
  );
}

const s = StyleSheet.create({
  all: { flex: 1, backgroundColor: C.paper },
  oops: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 28, backgroundColor: C.paper },
  oopsHead: { fontSize: 20, fontWeight: '800', color: C.ink },
  oopsNote: { fontSize: 14, color: C.ink2, marginTop: 8, textAlign: 'center' },
  oopsBtn: { marginTop: 22, backgroundColor: C.navy, paddingHorizontal: 26, paddingVertical: 13, borderRadius: 12 },
  oopsBtnTxt: { color: '#fff', fontSize: 15, fontWeight: '700' },
});
