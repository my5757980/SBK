import React from 'react';
import {
  Modal, View, Text, TouchableOpacity, KeyboardAvoidingView, Platform, StyleSheet,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Feather } from '@expo/vector-icons';
import Avatar from './Avatar';
import { C } from './theme';
import { useUI, type } from './ui';

/* A panel that rises from the bottom - the phone's version of the website's
   little group box (chat.js gdlg). One of these for everything a group needs,
   so the new-group panel and the group's own panel are visibly the same thing.
   The back button and the cross both close it; tapping the dimmed page above
   it does too, as a click outside the box does on the website. */
export default function Sheet({ title, onClose, children, footer }) {
  const ui = useUI();
  const inset = useSafeAreaInsets();
  return (
    <Modal visible transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <KeyboardAvoidingView
        style={{ flex: 1 }}
        behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
      >
        <View style={s.dim}>
          <TouchableOpacity
            style={{ flex: 1 }}
            activeOpacity={1}
            onPress={onClose}
            accessibilityRole="button"
            accessibilityLabel="Close"
          />
          <View
            style={[
              s.sheet,
              {
                maxHeight: '88%',
                borderTopLeftRadius: ui.s(20),
                borderTopRightRadius: ui.s(20),
                paddingBottom: Math.max(inset.bottom, ui.s(12)),
                width: '100%',
                maxWidth: ui.wide ? 620 : undefined,
                alignSelf: 'center',
              },
            ]}
          >
            <View style={[s.head, { paddingHorizontal: ui.s(18), paddingTop: ui.s(14), paddingBottom: ui.s(10) }]}>
              <Text maxFontSizeMultiplier={ui.maxFont} numberOfLines={1} style={[type('h2', ui), { color: C.ink, flex: 1 }]}>
                {title}
              </Text>
              <TouchableOpacity
                onPress={onClose}
                accessibilityRole="button"
                accessibilityLabel="Close"
                hitSlop={10}
                style={{
                  width: ui.tap(40), height: ui.tap(40), borderRadius: ui.s(20),
                  alignItems: 'center', justifyContent: 'center', backgroundColor: C.paper,
                }}
              >
                <Feather name="x" size={ui.s(20)} color={C.ink2} />
              </TouchableOpacity>
            </View>
            <View style={{ flexShrink: 1 }}>{children}</View>
            {footer ? <View style={{ paddingHorizontal: ui.s(18), paddingTop: ui.s(10) }}>{footer}</View> : null}
          </View>
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

/** One person to tick - the website's pickRow(). */
export function PickRow({ p, on, onToggle }) {
  const ui = useUI();
  return (
    <TouchableOpacity
      onPress={onToggle}
      accessibilityRole="checkbox"
      accessibilityState={{ checked: !!on }}
      accessibilityLabel={p.name}
      activeOpacity={0.7}
      style={[s.pick, { paddingHorizontal: ui.s(18), paddingVertical: ui.s(9), gap: ui.s(12), minHeight: ui.tap(54) }]}
    >
      <Avatar name={p.name} ini={p.ini} hue={p.hue} size={ui.s(38)} online={!!p.online} />
      <View style={{ flex: 1, minWidth: 0 }}>
        <Text maxFontSizeMultiplier={ui.maxFont} numberOfLines={1} style={[type('title', ui), { color: C.ink }]}>
          {p.name}
        </Text>
        {p.role ? (
          <Text maxFontSizeMultiplier={ui.maxFont} numberOfLines={1} style={[type('sub', ui), { color: C.ink3 }]}>
            {p.role}
          </Text>
        ) : null}
      </View>
      <View
        style={[
          s.box,
          {
            width: ui.s(24), height: ui.s(24), borderRadius: ui.s(7),
            backgroundColor: on ? C.navy : C.card, borderColor: on ? C.navy : C.line,
          },
        ]}
      >
        {on ? <Feather name="check" size={ui.s(16)} color="#fff" /> : null}
      </View>
    </TouchableOpacity>
  );
}

const s = StyleSheet.create({
  dim: { flex: 1, backgroundColor: 'rgba(16,24,40,.45)' },
  sheet: { backgroundColor: C.card },
  head: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  pick: { flexDirection: 'row', alignItems: 'center' },
  box: { borderWidth: 2, alignItems: 'center', justifyContent: 'center' },
});
