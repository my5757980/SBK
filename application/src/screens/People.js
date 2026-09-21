import React, { useEffect, useRef, useState, useCallback, useMemo } from 'react';
import {
  View, Text, Image, FlatList, TouchableOpacity, StyleSheet, RefreshControl,
  TextInput, ActivityIndicator,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Feather } from '@expo/vector-icons';
import Avatar from '../Avatar';
import { C, initialsOf } from '../theme';
import { useUI, type, page, TOUCH, liftSoft, lift } from '../ui';
import { Empty, Waiting, Notice } from '../bits';
import { poll, saveName } from '../api';
import { useCallEngine, useCallSnap } from '../calls';
import { chime } from '../chime';
import NewGroup from './NewGroup';

/* ONE SCREEN, SEVERAL SHAPES - and the SERVER decides which, exactly as it does
   in the browser's own chat.js.

     a customer gets `people`   the desk, and never another customer
     the desk gets   `list`     the conversations waiting for them, and `groups`
     an ADMINISTRATOR's `list` additionally carries `all: true` and a `sid` on
     every row - the agent who holds that conversation - which is what lets
     this screen do the owner's drill-down: agents first, then that agent's
     clients, then the chat. Nothing here asks who is signed in; the same
     request comes back a different shape, so the phone can never show
     something the website would not. */
export default function People({ token, me, onOpen, onSignOut }) {
  const ui = useUI();
  const inset = useSafeAreaInsets();
  const [rows, setRows] = useState([]);
  const [kind, setKind] = useState('');
  const [seesAll, setSeesAll] = useState(false);
  const [threads, setThreads] = useState({});
  const [groups, setGroups] = useState([]);
  const [err, setErr] = useState('');
  const [first, setFirst] = useState(true);
  const [pulling, setPulling] = useState(false);
  const alive = useRef(true);
  const misses = useRef(0);
  const calls = useCallEngine();

  /* Which agent's clients are on screen - 0 means the agents list itself.
     Kept only on the phone: the server sends the same flat list to every
     administrator poll, and this is what turns it into three screens. */
  const [agentAt, setAgentAt] = useState(0);
  const [agentName, setAgentName] = useState('');

  /* "What's your name?" - the website's card for a customer whose only name
     is their login. Asked of the server once, when this list opens. */
  const askMe = useRef(true);
  const [myName, setMyName] = useState(me.name);
  const [needsName, setNeedsName] = useState(false);
  const [newGroup, setNewGroup] = useState(false);

  /* The tone when something new is waiting - the bell's job on the website.
     Not for what was already waiting when the list opened, and never over a call. */
  const snap = useCallSnap();
  const callIdle = useRef(true);
  callIdle.current = !snap || snap.status === 'idle';
  const unreadWas = useRef(-1);

  const beat = useCallback(async () => {
    try {
      const j = await poll(token, { me: askMe.current });
      if (!alive.current) return;
      if (j.signedout) { onSignOut(); return; }
      // A ring for this person, whichever screen they are on.
      if (j.call && calls) calls.notePoll(j.call);
      if (j.people) { setRows(j.people); setKind('people'); setThreads(j.threads || {}); }
      else if (j.list) {
        setRows(j.list); setKind('list'); setGroups(j.groups || []); setSeesAll(!!j.all);
      }
      if (askMe.current) {
        askMe.current = false;
        if (j.me) {
          if (j.me.name) setMyName(j.me.name);
          setNeedsName(!!j.me.needs_name);
        }
      }
      const total = j.unread | 0;
      if (unreadWas.current >= 0 && total > unreadWas.current && callIdle.current) chime();
      unreadWas.current = total;
      misses.current = 0;
      setErr('');
    } catch (e) {
      // One missed beat is a phone changing network, not a fault; three is one.
      misses.current += 1;
      if (alive.current && misses.current >= 3) setErr(e.message);
    } finally {
      if (alive.current) { setFirst(false); setPulling(false); }
    }
  }, [token, onSignOut, calls]);

  useEffect(() => {
    alive.current = true;
    beat();
    /* Every two seconds, the same heartbeat the browser keeps. The server
       answers in about twenty milliseconds and holds nothing open - see the note
       at the top of api/poll.php for why that matters on this host. */
    const t = setInterval(beat, 2000);
    return () => { alive.current = false; clearInterval(t); };
  }, [beat]);

  const isDesk = kind === 'list';
  const onAgentsScreen = isDesk && seesAll && !agentAt;

  /* The agents, grouped from the same flat list - one row per `sid`, newest
     activity first, exactly as drawAgents() does on the website. */
  const agentRows = useMemo(() => {
    if (!onAgentsScreen) return [];
    const by = {};
    const order = [];
    for (const t of rows) {
      const sid = t.sid | 0;
      if (!by[sid]) {
        by[sid] = { id: sid, name: t.staff || 'The desk', clients: 0, unread: 0, at: '' };
        order.push(sid);
      }
      by[sid].clients += 1;
      by[sid].unread += (t.unread | 0);
      if ((t.at || '') > by[sid].at) by[sid].at = t.at || '';
    }
    order.sort((a, b) => (by[b].at || '').localeCompare(by[a].at || ''));
    return order.map((sid) => {
      const a = by[sid];
      return {
        id: a.id,
        _agent: true,
        name: a.name,
        unread: a.unread,
        sub: a.clients + (a.clients === 1 ? ' conversation' : ' conversations'),
        ini: initialsOf(a.name),
        hue: (a.id * 47) % 360,
      };
    });
  }, [onAgentsScreen, rows]);

  const clientRows = isDesk && seesAll && agentAt
    ? rows.filter((t) => (t.sid | 0) === agentAt)
    : rows;

  /* Groups sit above the agents list, the same as the website: they are the
     administrator's OWN rooms and have nothing to do with any one agent, so
     they disappear the moment you step inside somebody's clients. */
  const showGroups = isDesk && (!seesAll || !agentAt);
  /* This used to leave the groups off the administrator's first screen
     altogether - `onAgentsScreen ? agentRows : ...` - so the person who owns the
     desk never saw a single group in the app, while the website (chat.js
     groupRowsHtml) puts them at the top of that very list. Found 19 September
     2026 from "groups are still not in the app". */
  const groupRows = showGroups ? groups.map((g) => ({ ...g, _group: true })) : [];
  const data = onAgentsScreen ? [...groupRows, ...agentRows] : [...groupRows, ...clientRows];

  const waitingAll = isDesk
    ? rows.reduce((n, it) => n + (it.unread | 0), 0) + groups.reduce((n, g) => n + (g.unread | 0), 0)
    : data.reduce((n, it) => n + (it.unread | 0), 0);

  const title = onAgentsScreen ? 'Agents'
    : (isDesk && seesAll && agentAt) ? agentName
    : (isDesk ? 'Conversations' : 'Our team');
  const canGoBack = isDesk && seesAll && !!agentAt;

  return (
    <View style={page}>
      {/* ---- the bar */}
      <View
        style={[
          s.top,
          {
            paddingTop: inset.top + ui.s(10),
            paddingBottom: ui.s(13),
            paddingHorizontal: ui.s(16),
            gap: ui.s(11),
          },
        ]}
      >
        {canGoBack ? (
          <TouchableOpacity
            onPress={() => { setAgentAt(0); setAgentName(''); }}
            accessibilityRole="button"
            accessibilityLabel="Back to agents"
            hitSlop={8}
            style={{ width: ui.tap(30), height: ui.tap(30), alignItems: 'center', justifyContent: 'center' }}
          >
            <Feather name="chevron-left" size={ui.s(24)} color="#fff" />
          </TouchableOpacity>
        ) : (
          <Image
            source={require('../../assets/sbk-mark.png')}
            resizeMode="contain"
            style={{ width: ui.s(40), height: ui.s(30) }}
          />
        )}

        <View style={{ flex: 1, minWidth: 0 }}>
          <Text
            maxFontSizeMultiplier={ui.maxFont}
            numberOfLines={1}
            style={[type('h2', ui), { color: '#fff' }]}
          >
            {title}
          </Text>
          <Text
            maxFontSizeMultiplier={ui.maxFont}
            numberOfLines={1}
            style={[type('sub', ui), { color: '#B9C6DC', marginTop: ui.s(2) }]}
          >
            {onAgentsScreen ? 'Every conversation, by who is holding it'
              : canGoBack ? 'Their conversations'
              : myName + (me.is_staff && me.role_name ? ' · ' + me.role_name : '')}
          </Text>
        </View>

        {waitingAll > 0 ? (
          <View
            style={[
              s.topCount,
              { paddingHorizontal: ui.s(9), height: ui.s(24), borderRadius: ui.s(12), marginRight: ui.s(9) },
            ]}
          >
            <Text
              maxFontSizeMultiplier={1}
              style={{ color: '#fff', fontSize: ui.s(11.5), fontWeight: '800' }}
            >
              {waitingAll > 99 ? '99+' : waitingAll} waiting
            </Text>
          </View>
        ) : null}

        {/* A new group - the desk's "+" on the website. Not on the agents
            screen or inside one agent's clients: groups are your own rooms. */}
        {isDesk && !canGoBack ? (
          <TouchableOpacity
            onPress={() => setNewGroup(true)}
            accessibilityRole="button"
            accessibilityLabel="New group"
            hitSlop={8}
            style={{
              width: ui.tap(TOUCH - 6), height: ui.tap(TOUCH - 6), borderRadius: ui.s(11),
              backgroundColor: '#ffffff1f', alignItems: 'center', justifyContent: 'center',
            }}
          >
            <Feather name="user-plus" size={ui.s(18)} color="#fff" />
          </TouchableOpacity>
        ) : null}

        <TouchableOpacity
          onPress={onSignOut}
          accessibilityRole="button"
          accessibilityLabel="Sign out"
          hitSlop={8}
          style={{
            width: ui.tap(TOUCH - 6), height: ui.tap(TOUCH - 6), borderRadius: ui.s(11),
            backgroundColor: '#ffffff1f', alignItems: 'center', justifyContent: 'center',
          }}
        >
          <Feather name="log-out" size={ui.s(18)} color="#fff" />
        </TouchableOpacity>
      </View>

      {err ? (
        <View style={{ paddingHorizontal: ui.s(16) }}><Notice text={err} /></View>
      ) : null}

      {first ? (
        <Waiting />
      ) : (
        <FlatList
          data={data}
          keyExtractor={(it) => (it._group ? 'g' : it._agent ? 'a' : 'p') + it.id}
          contentContainerStyle={{
            paddingBottom: inset.bottom + ui.s(16),
            maxWidth: ui.col,
            alignSelf: 'center',
            width: '100%',
            flexGrow: 1,
          }}
          refreshControl={
            <RefreshControl
              refreshing={pulling}
              onRefresh={() => { setPulling(true); beat(); }}
              tintColor={C.navy}
              colors={[C.navy]}
            />
          }
          keyboardShouldPersistTaps="handled"
          ListHeaderComponent={needsName && kind === 'people' ? (
            <NameCard
              token={token}
              onSaved={(n) => { setMyName(n); setNeedsName(false); }}
            />
          ) : null}
          ListEmptyComponent={
            <Empty
              icon={onAgentsScreen ? 'users' : isDesk ? 'inbox' : 'users'}
              head={onAgentsScreen ? 'Nobody on the desk' : 'Nothing waiting'}
              note={onAgentsScreen
                ? 'Conversations land under whichever agent is holding them.'
                : canGoBack
                  ? 'This agent has no conversations yet.'
                  : isDesk
                    ? 'New conversations land here as customers write in.'
                    : 'Nobody is on the desk just now. Leave a message and they will see it.'}
            />
          }
          renderItem={({ item }) => {
            const group = !!item._group;
            const agent = !!item._agent;
            const perThread = (!isDesk && !agent) ? (threads[String(item.id)] || {}) : null;
            const badge = (isDesk || agent) ? (item.unread | 0) : (perThread ? perThread.unread | 0 : 0);
            const sub = agent
              ? item.sub
              : group
                ? (item.preview || item.members + (item.members === 1 ? ' member' : ' members'))
                : (isDesk ? (item.preview || item.email)
                          : (item.online ? 'Online now' : (item.role || 'Offline')));

            function press() {
              if (agent) { setAgentAt(item.id); setAgentName(item.name); return; }
              onOpen({
                kind: group ? 'group' : (isDesk ? 'thread' : 'person'),
                id: item.id,
                thread: group ? 0 : (isDesk ? item.id : (perThread ? perThread.thread | 0 : 0)),
                name: item.name,
                sub: group
                  ? item.members + (item.members === 1 ? ' member' : ' members')
                  : (isDesk ? item.email : (item.role || '')),
                hue: item.hue,
                ini: item.ini,
                online: !!item.online,
                /* The desk rings only a customer in its OWN conversation; an
                   administrator reading somebody else's gets no phone - the
                   website's rule, and the server's. */
                mine: !!item.mine,
              });
            }

            return (
              <TouchableOpacity
                activeOpacity={0.65}
                accessibilityRole="button"
                accessibilityLabel={item.name}
                onPress={press}
                style={[
                  s.row,
                  {
                    paddingHorizontal: ui.s(16),
                    paddingVertical: ui.s(12),
                    gap: ui.s(13),
                    minHeight: ui.tap(TOUCH + 22),
                  },
                ]}
              >
                <Avatar
                  name={item.name}
                  ini={item.ini}
                  hue={item.hue}
                  size={ui.s(46)}
                  online={!group && !agent && item.online}
                  square={group || agent}
                />
                <View style={{ flex: 1, minWidth: 0 }}>
                  <View style={s.nameRow}>
                    <Text
                      maxFontSizeMultiplier={ui.maxFont}
                      numberOfLines={1}
                      style={[type('title', ui), { color: C.ink, flexShrink: 1 }]}
                    >
                      {item.name}
                    </Text>
                    {group ? (
                      <View style={[s.tag, { paddingHorizontal: ui.s(6), borderRadius: ui.s(5) }]}>
                        <Text
                          maxFontSizeMultiplier={1}
                          style={{ fontSize: ui.s(9.5), fontWeight: '800', color: C.navy, letterSpacing: 0.6 }}
                        >
                          GROUP
                        </Text>
                      </View>
                    ) : null}
                  </View>
                  <Text
                    maxFontSizeMultiplier={ui.maxFont}
                    numberOfLines={1}
                    style={[type('sub', ui), { color: C.ink3, marginTop: ui.s(2) }]}
                  >
                    {sub}
                  </Text>
                </View>
                {badge > 0 ? (
                  <View
                    style={[
                      s.pill,
                      {
                        minWidth: ui.s(22), height: ui.s(22),
                        borderRadius: ui.s(11), paddingHorizontal: ui.s(7),
                      },
                      liftSoft,
                    ]}
                  >
                    <Text
                      maxFontSizeMultiplier={1}
                      style={{ color: '#fff', fontSize: ui.s(11.5), fontWeight: '800' }}
                    >
                      {badge > 99 ? '99+' : badge}
                    </Text>
                  </View>
                ) : (
                  <Feather name="chevron-right" size={ui.s(19)} color={C.ink3} />
                )}
              </TouchableOpacity>
            );
          }}
        />
      )}

      {newGroup ? (
        <NewGroup
          token={token}
          onClose={() => setNewGroup(false)}
          onMade={(g) => {
            setNewGroup(false);
            beat();
            onOpen({
              kind: 'group',
              id: g.id,
              thread: 0,
              name: g.name,
              sub: g.count + (g.count === 1 ? ' member' : ' members'),
              hue: g.hue,
              ini: g.ini,
              online: false,
              mine: false,
            });
          }}
        />
      ) : null}
    </View>
  );
}

/* "What's your name?" - the website's card, word for word (chat.php). Not a
   wall in front of the chat: it sits above the list, can be ignored, and is
   gone the moment it is answered. What is typed is kept in the chat's own row
   and never written into the shop's account (api/name.php). */
function NameCard({ token, onSaved }) {
  const ui = useUI();
  const [v, setV] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  async function save() {
    const n = v.trim();
    if (n.length < 2) { setErr('Please type your name.'); return; }
    setBusy(true); setErr('');
    try {
      const j = await saveName(token, n);
      onSaved(j.name || n);
    } catch (e) {
      setErr(e.message);
      setBusy(false);
    }
  }

  return (
    <View
      style={[
        s.nameCard,
        lift,
        { margin: ui.s(14), marginBottom: ui.s(6), padding: ui.s(14), borderRadius: ui.s(14) },
      ]}
    >
      <Text maxFontSizeMultiplier={ui.maxFont} style={[type('title', ui), { color: C.ink }]}>
        What&apos;s your name?
      </Text>
      <Text maxFontSizeMultiplier={ui.maxFont} style={[type('sub', ui), { color: C.ink2, marginTop: ui.s(2) }]}>
        So our team knows who they&apos;re talking to.
      </Text>
      <View style={{ flexDirection: 'row', gap: ui.s(8), marginTop: ui.s(10) }}>
        <TextInput
          value={v}
          onChangeText={setV}
          placeholder="Your name"
          placeholderTextColor={C.ink3}
          maxLength={80}
          autoComplete="name"
          autoCapitalize="words"
          returnKeyType="done"
          onSubmitEditing={save}
          maxFontSizeMultiplier={ui.maxFont}
          style={[
            type('body', ui),
            {
              flex: 1, color: C.ink, backgroundColor: C.paper, borderRadius: ui.s(12),
              borderWidth: 1.5, borderColor: C.line, paddingHorizontal: ui.s(13), minHeight: ui.tap(TOUCH - 2),
            },
          ]}
        />
        <TouchableOpacity
          onPress={save}
          disabled={busy}
          accessibilityRole="button"
          accessibilityLabel="Save your name"
          style={{
            minHeight: ui.tap(TOUCH - 2), paddingHorizontal: ui.s(18), borderRadius: ui.s(12),
            backgroundColor: C.navy, alignItems: 'center', justifyContent: 'center',
          }}
        >
          {busy
            ? <ActivityIndicator color="#fff" size="small" />
            : <Text maxFontSizeMultiplier={1} style={{ color: '#fff', fontWeight: '700', fontSize: ui.s(14.5) }}>Save</Text>}
        </TouchableOpacity>
      </View>
      {err ? (
        <Text maxFontSizeMultiplier={ui.maxFont} style={[type('sub', ui), { color: C.red, marginTop: ui.s(6) }]}>
          {err}
        </Text>
      ) : null}
    </View>
  );
}

const s = StyleSheet.create({
  nameCard: { backgroundColor: C.card, borderWidth: 1, borderColor: C.line },
  top: { flexDirection: 'row', alignItems: 'center', backgroundColor: C.navy },
  topCount: { backgroundColor: C.red, alignItems: 'center', justifyContent: 'center' },
  row: {
    flexDirection: 'row', alignItems: 'center',
    backgroundColor: C.card, borderBottomWidth: 1, borderBottomColor: C.line2,
  },
  nameRow: { flexDirection: 'row', alignItems: 'center', gap: 7 },
  tag: { backgroundColor: C.navyWash, paddingVertical: 2 },
  pill: { backgroundColor: C.red, alignItems: 'center', justifyContent: 'center' },
});
