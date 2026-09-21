import React, { useEffect, useState } from 'react';
import {
  View, Text, TextInput, ScrollView, TouchableOpacity, ActivityIndicator, Alert,
} from 'react-native';
import { Feather } from '@expo/vector-icons';
import Avatar from '../Avatar';
import Sheet, { PickRow } from '../Sheet';
import { C } from '../theme';
import { useUI, type, TOUCH } from '../ui';
import { Button, Notice } from '../bits';
import { groupInfo, groupRename, groupAdd, groupRemove, groupLeave } from '../api';

/* Inside a group - the website's groupInfoBox(): who is in it, and the things
   its owner may change.

     everybody       sees the members and may leave
     whoever made it may also rename it, add people, and take people out

   Those are the server's rules (api/group.php), not this screen's: the buttons
   are only drawn for the person the server will say yes to, and it would say
   no to anybody else regardless. */
export default function GroupInfo({ token, gid, onClose, onChanged, onLeft }) {
  const ui = useUI();
  const [g, setG] = useState(null);
  const [people, setPeople] = useState([]);
  const [name, setName] = useState('');
  const [adding, setAdding] = useState(false);
  const [picks, setPicks] = useState({});
  const [busy, setBusy] = useState('');
  const [err, setErr] = useState('');

  function took(j) {
    if (j.group) {
      setG(j.group);
      setName(j.group.name);
      if (onChanged) onChanged(j.group);
    }
    if (j.people) setPeople(j.people);
  }

  useEffect(() => {
    let alive = true;
    groupInfo(token, gid)
      .then((j) => { if (alive) took(j); })
      .catch((e) => { if (alive) setErr(e.message); });
    return () => { alive = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, gid]);

  async function act(what, fn) {
    setBusy(what); setErr('');
    try {
      const j = await fn();
      took(j);
      return j;
    } catch (e) {
      setErr(e.message);
      return null;
    } finally {
      setBusy('');
    }
  }

  function rename() {
    const n = name.trim();
    if (!n || !g || n === g.name) return;
    act('rename', () => groupRename(token, gid, n));
  }

  async function add() {
    const ids = Object.keys(picks).filter((k) => picks[k]).map((k) => k | 0);
    if (!ids.length) return;
    const j = await act('add', () => groupAdd(token, gid, ids));
    if (j) { setAdding(false); setPicks({}); }
  }

  function remove(m) {
    act('remove' + m.id, () => groupRemove(token, gid, m.id));
  }

  function leave() {
    Alert.alert('Leave "' + (g ? g.name : 'this group') + '"?', '', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Leave',
        style: 'destructive',
        onPress: async () => {
          setBusy('leave'); setErr('');
          try {
            await groupLeave(token, gid);
            onLeft();
          } catch (e) {
            setErr(e.message);
            setBusy('');
          }
        },
      },
    ]);
  }

  const inIt = {};
  if (g) for (const m of g.members) inIt[m.id] = true;
  const left = people.filter((p) => !inIt[p.id]);
  const chosen = Object.keys(picks).filter((k) => picks[k]).length;

  return (
    <Sheet
      title={adding ? 'Add people' : 'Group'}
      onClose={adding ? () => { setAdding(false); setPicks({}); } : onClose}
      footer={adding ? (
        <Button
          label={chosen ? 'Add ' + chosen + (chosen === 1 ? ' person' : ' people') : 'Add'}
          icon="user-plus"
          busy={busy === 'add'}
          onPress={add}
        />
      ) : null}
    >
      {!g ? (
        err ? (
          <View style={{ paddingHorizontal: ui.s(18), paddingBottom: ui.s(12) }}><Notice text={err} /></View>
        ) : (
          <ActivityIndicator color={C.navy} style={{ marginVertical: ui.s(28) }} />
        )
      ) : adding ? (
        <ScrollView keyboardShouldPersistTaps="handled">
          {left.length ? left.map((p) => (
            <PickRow
              key={p.id}
              p={p}
              on={!!picks[p.id]}
              onToggle={() => setPicks((o) => ({ ...o, [p.id]: !o[p.id] }))}
            />
          )) : (
            <Text maxFontSizeMultiplier={ui.maxFont} style={[type('sub', ui), { color: C.ink3, padding: ui.s(18) }]}>
              Everybody on the desk is already in this group.
            </Text>
          )}
          {err ? <View style={{ paddingHorizontal: ui.s(18) }}><Notice text={err} /></View> : null}
        </ScrollView>
      ) : (
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingBottom: ui.s(8) }}>
          {/* ---- the name: the owner's to change */}
          <View style={{ alignItems: 'center', paddingHorizontal: ui.s(18), paddingBottom: ui.s(6) }}>
            <Avatar name={g.name} ini={g.ini} hue={g.hue} size={ui.s(64)} square />
            {g.owner ? (
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: ui.s(8), marginTop: ui.s(12), alignSelf: 'stretch' }}>
                <TextInput
                  value={name}
                  onChangeText={setName}
                  maxLength={120}
                  placeholder="Group name"
                  placeholderTextColor={C.ink3}
                  maxFontSizeMultiplier={ui.maxFont}
                  onSubmitEditing={rename}
                  returnKeyType="done"
                  style={[
                    type('title', ui),
                    {
                      flex: 1, color: C.ink, backgroundColor: C.paper, borderRadius: ui.s(12),
                      borderWidth: 1.5, borderColor: C.line, paddingHorizontal: ui.s(13),
                      minHeight: ui.tap(TOUCH),
                    },
                  ]}
                />
                <TouchableOpacity
                  onPress={rename}
                  disabled={!name.trim() || name.trim() === g.name || busy === 'rename'}
                  accessibilityRole="button"
                  accessibilityLabel="Save the name"
                  style={{
                    height: ui.tap(TOUCH), paddingHorizontal: ui.s(16), borderRadius: ui.s(12),
                    backgroundColor: C.navy, alignItems: 'center', justifyContent: 'center',
                    opacity: !name.trim() || name.trim() === g.name ? 0.4 : 1,
                  }}
                >
                  {busy === 'rename'
                    ? <ActivityIndicator color="#fff" size="small" />
                    : <Text maxFontSizeMultiplier={1} style={{ color: '#fff', fontWeight: '700', fontSize: ui.s(14) }}>Save</Text>}
                </TouchableOpacity>
              </View>
            ) : (
              <Text maxFontSizeMultiplier={ui.maxFont} style={[type('h2', ui), { color: C.ink, marginTop: ui.s(10), textAlign: 'center' }]}>
                {g.name}
              </Text>
            )}
          </View>

          {err ? <View style={{ paddingHorizontal: ui.s(18) }}><Notice text={err} /></View> : null}

          <Text
            maxFontSizeMultiplier={ui.maxFont}
            style={[type('tiny', ui), { color: C.ink3, letterSpacing: 0.6, paddingHorizontal: ui.s(18), marginTop: ui.s(14), marginBottom: ui.s(4) }]}
          >
            {g.count + (g.count === 1 ? ' MEMBER' : ' MEMBERS')}
          </Text>

          {g.owner && left.length ? (
            <TouchableOpacity
              onPress={() => setAdding(true)}
              accessibilityRole="button"
              accessibilityLabel="Add people"
              style={{
                flexDirection: 'row', alignItems: 'center', gap: ui.s(12),
                paddingHorizontal: ui.s(18), paddingVertical: ui.s(9), minHeight: ui.tap(54),
              }}
            >
              <View style={{
                width: ui.s(38), height: ui.s(38), borderRadius: ui.s(19),
                backgroundColor: C.navyWash, alignItems: 'center', justifyContent: 'center',
              }}
              >
                <Feather name="user-plus" size={ui.s(18)} color={C.navy} />
              </View>
              <Text maxFontSizeMultiplier={ui.maxFont} style={[type('title', ui), { color: C.navy }]}>Add people</Text>
            </TouchableOpacity>
          ) : null}

          {g.members.map((m) => (
            <View
              key={m.id}
              style={{
                flexDirection: 'row', alignItems: 'center', gap: ui.s(12),
                paddingHorizontal: ui.s(18), paddingVertical: ui.s(9), minHeight: ui.tap(54),
              }}
            >
              <Avatar name={m.name} ini={m.ini} hue={m.hue} size={ui.s(38)} online={!!m.online} />
              <View style={{ flex: 1, minWidth: 0 }}>
                <Text maxFontSizeMultiplier={ui.maxFont} numberOfLines={1} style={[type('title', ui), { color: C.ink }]}>
                  {m.name}{m.me ? ' (you)' : ''}
                </Text>
                <Text maxFontSizeMultiplier={ui.maxFont} numberOfLines={1} style={[type('sub', ui), { color: m.online && !m.owner ? C.ok : C.ink3 }]}>
                  {m.owner ? 'Made the group' : (m.online ? 'Online' : 'Member')}
                </Text>
              </View>
              {g.owner && !m.me ? (
                <TouchableOpacity
                  onPress={() => remove(m)}
                  disabled={!!busy}
                  accessibilityRole="button"
                  accessibilityLabel={'Take ' + m.name + ' out of the group'}
                  hitSlop={8}
                  style={{
                    width: ui.tap(40), height: ui.tap(40), borderRadius: ui.s(20),
                    alignItems: 'center', justifyContent: 'center', backgroundColor: C.redWash,
                  }}
                >
                  {busy === 'remove' + m.id
                    ? <ActivityIndicator color={C.red} size="small" />
                    : <Feather name="user-minus" size={ui.s(17)} color={C.red} />}
                </TouchableOpacity>
              ) : null}
            </View>
          ))}

          <View style={{ paddingHorizontal: ui.s(18), marginTop: ui.s(14) }}>
            <TouchableOpacity
              onPress={leave}
              disabled={busy === 'leave'}
              accessibilityRole="button"
              accessibilityLabel="Leave group"
              style={{
                flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: ui.s(8),
                minHeight: ui.tap(TOUCH), borderRadius: ui.s(13), borderWidth: 1.5, borderColor: C.redWash,
                backgroundColor: C.card,
              }}
            >
              {busy === 'leave'
                ? <ActivityIndicator color={C.red} />
                : (
                  <>
                    <Feather name="log-out" size={ui.s(17)} color={C.red} />
                    <Text maxFontSizeMultiplier={ui.maxFont} style={[type('title', ui), { color: C.red }]}>Leave group</Text>
                  </>
                )}
            </TouchableOpacity>
          </View>
        </ScrollView>
      )}
    </Sheet>
  );
}
