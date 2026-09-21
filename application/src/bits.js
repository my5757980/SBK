import React from 'react';
import {
  View, Text, TextInput, TouchableOpacity, ActivityIndicator, StyleSheet,
} from 'react-native';
import { Feather } from '@expo/vector-icons';
import { C } from './theme';
import { useUI, type, TOUCH, lift } from './ui';

/* The pieces every screen is built from.
   -------------------------------------------------------------------------
   They live together so that a button is the same button everywhere, at every
   size, on every phone. The moment a screen starts styling its own button is
   the moment the app starts looking like several apps. */

/* -------------------------------------------------------------- the button */

export function Button({ label, onPress, busy, kind = 'solid', icon, style, full = true }) {
  const ui = useUI();
  const off = !!busy;
  const tone =
    kind === 'ghost' ? { backgroundColor: 'transparent', borderWidth: 1.5, borderColor: C.line }
    : kind === 'quiet' ? { backgroundColor: C.navyWash }
    : { backgroundColor: C.navy, ...lift };
  const ink = kind === 'solid' ? '#fff' : C.navy;

  return (
    <TouchableOpacity
      accessibilityRole="button"
      accessibilityLabel={label}
      activeOpacity={0.85}
      onPress={onPress}
      disabled={off}
      style={[
        {
          minHeight: ui.tap(TOUCH),
          borderRadius: ui.s(13),
          paddingHorizontal: ui.s(18),
          alignItems: 'center',
          justifyContent: 'center',
          flexDirection: 'row',
          gap: ui.s(8),
          alignSelf: full ? 'stretch' : 'flex-start',
          opacity: off ? 0.6 : 1,
        },
        tone,
        style,
      ]}
    >
      {busy ? (
        <ActivityIndicator color={ink} />
      ) : (
        <>
          {icon ? <Feather name={icon} size={ui.s(17)} color={ink} /> : null}
          <Text
            maxFontSizeMultiplier={ui.maxFont}
            style={[type('title', ui), { color: ink }]}
          >
            {label}
          </Text>
        </>
      )}
    </TouchableOpacity>
  );
}

/* --------------------------------------------------------------- the field */

export function Field({
  label, value, onChangeText, placeholder, icon, secure, keyboard,
  autoCapitalize = 'none', onSubmitEditing, returnKeyType, autoComplete,
}) {
  const ui = useUI();
  const [shown, setShown] = React.useState(false);
  const [on, setOn] = React.useState(false);
  const hide = !!secure && !shown;

  return (
    <View style={{ marginTop: ui.s(16) }}>
      <Text
        maxFontSizeMultiplier={ui.maxFont}
        style={[type('tiny', ui), s.label]}
      >
        {label}
      </Text>
      <View
        style={[
          s.fieldWrap,
          {
            minHeight: ui.tap(TOUCH + 4),
            borderRadius: ui.s(13),
            paddingHorizontal: ui.s(13),
            gap: ui.s(10),
            borderColor: on ? C.navyLite : C.line,
            backgroundColor: on ? C.card : C.paper,
          },
        ]}
      >
        {icon ? <Feather name={icon} size={ui.s(17)} color={on ? C.navy : C.ink3} /> : null}
        <TextInput
          style={[type('body', ui), { flex: 1, color: C.ink, paddingVertical: ui.s(12) }]}
          maxFontSizeMultiplier={ui.maxFont}
          value={value}
          onChangeText={onChangeText}
          placeholder={placeholder}
          placeholderTextColor={C.ink3}
          secureTextEntry={hide}
          keyboardType={keyboard}
          autoCapitalize={autoCapitalize}
          autoCorrect={false}
          autoComplete={autoComplete}
          returnKeyType={returnKeyType}
          onSubmitEditing={onSubmitEditing}
          onFocus={() => setOn(true)}
          onBlur={() => setOn(false)}
        />
        {secure ? (
          <TouchableOpacity
            onPress={() => setShown(!shown)}
            hitSlop={12}
            accessibilityRole="button"
            accessibilityLabel={shown ? 'Hide password' : 'Show password'}
          >
            <Feather name={shown ? 'eye-off' : 'eye'} size={ui.s(18)} color={C.ink3} />
          </TouchableOpacity>
        ) : null}
      </View>
    </View>
  );
}

/* ------------------------------------------------------- saying what happened */

export function Notice({ text, tone = 'bad' }) {
  const ui = useUI();
  if (!text) return null;
  const bad = tone === 'bad';
  return (
    <View
      style={[
        s.notice,
        {
          marginTop: ui.s(16),
          padding: ui.s(12),
          borderRadius: ui.s(11),
          gap: ui.s(9),
          backgroundColor: bad ? C.redWash : C.navyWash,
        },
      ]}
    >
      <Feather
        name={bad ? 'alert-circle' : 'info'}
        size={ui.s(17)}
        color={bad ? C.redDark : C.navy}
        style={{ marginTop: ui.s(1) }}
      />
      <Text
        maxFontSizeMultiplier={ui.maxFont}
        style={[type('sub', ui), { flex: 1, color: bad ? C.redDark : C.navy }]}
      >
        {text}
      </Text>
    </View>
  );
}

/* ------------------------------------------------------- a screen with nothing */

export function Empty({ icon = 'message-circle', head, note }) {
  const ui = useUI();
  return (
    <View style={[s.empty, { paddingTop: ui.s(ui.short ? 40 : 80), paddingHorizontal: ui.s(34) }]}>
      <View
        style={[
          s.emptyRing,
          { width: ui.s(70), height: ui.s(70), borderRadius: ui.s(35), marginBottom: ui.s(16) },
        ]}
      >
        <Feather name={icon} size={ui.s(28)} color={C.navyLite} />
      </View>
      <Text maxFontSizeMultiplier={ui.maxFont} style={[type('title', ui), { color: C.ink2 }]}>
        {head}
      </Text>
      {note ? (
        <Text
          maxFontSizeMultiplier={ui.maxFont}
          style={[type('sub', ui), { color: C.ink3, textAlign: 'center', marginTop: ui.s(6) }]}
        >
          {note}
        </Text>
      ) : null}
    </View>
  );
}

/* --------------------------------------------------------------- while loading */

export function Waiting() {
  return (
    <View style={s.mid}>
      <ActivityIndicator color={C.navy} size="large" />
    </View>
  );
}

const s = StyleSheet.create({
  label: { color: C.ink3, letterSpacing: 1, textTransform: 'uppercase', marginBottom: 7 },
  fieldWrap: { flexDirection: 'row', alignItems: 'center', borderWidth: 1.5 },
  notice: { flexDirection: 'row', alignItems: 'flex-start' },
  empty: { alignItems: 'center' },
  emptyRing: { backgroundColor: C.navyWash, alignItems: 'center', justifyContent: 'center' },
  mid: { flex: 1, alignItems: 'center', justifyContent: 'center' },
});
