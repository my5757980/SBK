import React, { useEffect, useState } from 'react';
import { View, Text, TextInput, ScrollView, ActivityIndicator } from 'react-native';
import Sheet, { PickRow } from '../Sheet';
import { C } from '../theme';
import { useUI, type, TOUCH } from '../ui';
import { Button, Notice } from '../bits';
import { groupPeople, groupCreate } from '../api';

/* A new group - the website's newGroupBox(): a name, who is in it, and
   "Create group". The name is the only thing asked for; the people are
   optional, exactly as on the website, because a room can be made first and
   filled later. The server checks every id against the website's own desk, so
   nothing chosen here can put a customer in a group. */
export default function NewGroup({ token, onClose, onMade }) {
  const ui = useUI();
  const [people, setPeople] = useState(null);
  const [name, setName] = useState('');
  const [picks, setPicks] = useState({});
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  useEffect(() => {
    let alive = true;
    groupPeople(token)
      .then((j) => { if (alive) setPeople(j.people || []); })
      .catch((e) => { if (alive) { setPeople([]); setErr(e.message); } });
    return () => { alive = false; };
  }, [token]);

  async function make() {
    const n = name.trim();
    if (!n) { setErr('Give the group a name.'); return; }
    setBusy(true); setErr('');
    try {
      const ids = Object.keys(picks).filter((k) => picks[k]).map((k) => k | 0);
      const j = await groupCreate(token, n, ids);
      onMade(j.group);
    } catch (e) {
      setErr(e.message);
      setBusy(false);
    }
  }

  const chosen = Object.keys(picks).filter((k) => picks[k]).length;

  return (
    <Sheet
      title="New group"
      onClose={onClose}
      footer={(
        <Button
          label={chosen ? 'Create group (' + (chosen + 1) + ' people)' : 'Create group'}
          icon="users"
          busy={busy}
          onPress={make}
        />
      )}
    >
      <View style={{ paddingHorizontal: ui.s(18) }}>
        <TextInput
          value={name}
          onChangeText={setName}
          placeholder="Group name"
          placeholderTextColor={C.ink3}
          maxLength={120}
          autoFocus
          maxFontSizeMultiplier={ui.maxFont}
          style={[
            type('body', ui),
            {
              color: C.ink, backgroundColor: C.paper, borderRadius: ui.s(13),
              borderWidth: 1.5, borderColor: C.line,
              paddingHorizontal: ui.s(14), minHeight: ui.tap(TOUCH + 2),
            },
          ]}
        />
        {err ? <Notice text={err} /> : null}
        <Text maxFontSizeMultiplier={ui.maxFont} style={[type('tiny', ui), { color: C.ink3, marginTop: ui.s(16), letterSpacing: 0.6 }]}>
          WHO IS IN IT
        </Text>
      </View>

      {people === null ? (
        <ActivityIndicator color={C.navy} style={{ marginVertical: ui.s(24) }} />
      ) : people.length ? (
        <ScrollView style={{ marginTop: ui.s(6) }} keyboardShouldPersistTaps="handled">
          {people.map((p) => (
            <PickRow
              key={p.id}
              p={p}
              on={!!picks[p.id]}
              onToggle={() => setPicks((o) => ({ ...o, [p.id]: !o[p.id] }))}
            />
          ))}
        </ScrollView>
      ) : (
        <Text maxFontSizeMultiplier={ui.maxFont} style={[type('sub', ui), { color: C.ink3, padding: ui.s(18) }]}>
          There is nobody else on the desk yet.
        </Text>
      )}
    </Sheet>
  );
}
