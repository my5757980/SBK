import React, { useState } from 'react';
import { Modal, View, Image, FlatList, TouchableOpacity, Text, StyleSheet } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Feather } from '@expo/vector-icons';
import { useUI } from './ui';

/* A picture, opened - the website's lightbox (chat.js openShot / showShot).

   The picture as large as the screen allows, every other picture in the same
   conversation a swipe away, and "2 / 5" so nobody wonders how many there are.
   The back button closes it, as it closes everything on Android, and so does
   the cross.

   `shots` is [{ id, uri }] in the order they were sent; `at` is the one that
   was tapped. The screen mounts this only while a picture is open, so `at` is
   read once, as the place to start. */
export default function PictureViewer({ shots, at, onClose }) {
  const ui = useUI();
  const inset = useSafeAreaInsets();
  const last = Math.max(0, (shots ? shots.length : 1) - 1);
  const [i, setI] = useState(Math.min(Math.max(0, at | 0), last));
  const [box, setBox] = useState({ w: ui.width, h: ui.height });

  if (!Array.isArray(shots) || !shots.length) return null;
  const w = box.w || ui.width;
  const h = box.h || ui.height;

  return (
    <Modal visible animationType="fade" onRequestClose={onClose} statusBarTranslucent>
      <View
        style={s.all}
        onLayout={(e) => {
          const { width, height } = e.nativeEvent.layout;
          if (width && height && (width !== box.w || height !== box.h)) setBox({ w: width, h: height });
        }}
      >
        {/* Keyed on the size: a phone turned on its side starts a fresh list
            at the picture that was showing, rather than half-way between two. */}
        <FlatList
          key={w + 'x' + h}
          data={shots}
          horizontal
          pagingEnabled
          initialScrollIndex={Math.min(i, last)}
          getItemLayout={(_, n) => ({ length: w, offset: w * n, index: n })}
          keyExtractor={(p) => String(p.id)}
          showsHorizontalScrollIndicator={false}
          onMomentumScrollEnd={(e) => setI(Math.round(e.nativeEvent.contentOffset.x / w))}
          renderItem={({ item }) => (
            <View style={{ width: w, height: h, alignItems: 'center', justifyContent: 'center' }}>
              <Image source={{ uri: item.uri }} style={{ width: w, height: h }} resizeMode="contain" />
            </View>
          )}
        />

        <View
          pointerEvents="box-none"
          style={[s.bar, { paddingTop: inset.top + ui.s(6), paddingHorizontal: ui.s(8) }]}
        >
          <TouchableOpacity
            onPress={onClose}
            accessibilityRole="button"
            accessibilityLabel="Close picture"
            hitSlop={10}
            style={[s.btn, { width: ui.tap(44), height: ui.tap(44), borderRadius: ui.s(22) }]}
          >
            <Feather name="x" size={ui.s(24)} color="#fff" />
          </TouchableOpacity>
          {shots.length > 1 ? (
            <View style={[s.count, { paddingHorizontal: ui.s(12), paddingVertical: ui.s(5), borderRadius: ui.s(14) }]}>
              <Text maxFontSizeMultiplier={1} style={{ color: '#fff', fontSize: ui.s(13.5), fontWeight: '700' }}>
                {i + 1} / {shots.length}
              </Text>
            </View>
          ) : null}
        </View>
      </View>
    </Modal>
  );
}

const s = StyleSheet.create({
  all: { flex: 1, backgroundColor: '#000' },
  bar: {
    position: 'absolute', top: 0, left: 0, right: 0,
    flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between',
  },
  btn: { backgroundColor: 'rgba(0,0,0,.45)', alignItems: 'center', justifyContent: 'center' },
  count: { backgroundColor: 'rgba(0,0,0,.45)' },
});
