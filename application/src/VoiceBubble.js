import React, { useEffect, useRef, useState } from 'react';
import { View, Text, TouchableOpacity, StyleSheet } from 'react-native';
import { Feather } from '@expo/vector-icons';
import { C } from './theme';
import { useUI, type } from './ui';
import { audioLib } from './lazy';

/* A voice note, playable in place.

   expo-audio, the audio library that belongs to this Expo SDK (57). The first
   calls APK used expo-av, which Expo retired after SDK 54 - `expo install` had
   quietly fetched the old one - and an old native module in a new app is
   exactly the kind of thing that stops an app opening at all.

   One player per bubble, and it is EMPTY until the bubble is pressed: a long
   conversation full of voice notes downloads none of them until one is
   played. Two people cannot be listened to at once, so playing one stops any
   other bubble that was playing. */

const playing = new Set();       // every bubble's own stop function, while it plays

function stopOthers(except) {
  for (const stop of Array.from(playing)) {
    if (stop !== except) stop();
  }
}

function fmt(sec) {
  sec = Math.max(0, Math.round(sec || 0));
  return Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0');
}

export default function VoiceBubble({ uri, mine, knownSecs }) {
  const ui = useUI();
  // Loaded on first use (see lazy.js). The same answer for the life of the
  // app, so these two hooks run on every render or on none.
  const A = audioLib();
  const player = A ? A.useAudioPlayer() : null;
  const st = A ? A.useAudioPlayerStatus(player) : null;
  const [loaded, setLoaded] = useState(false);
  const stopRef = useRef(null);

  if (!stopRef.current) {
    stopRef.current = () => {
      try { if (player) player.pause(); } catch (e) {}
      playing.delete(stopRef.current);
    };
  }

  useEffect(() => () => { playing.delete(stopRef.current); }, []);

  /* At the end it STOPS, and goes back to the start ready to be played again -
     WhatsApp's behaviour, and nothing plays until it is pressed.

     The pause is the whole fix. On Android the player underneath (ExoPlayer)
     reaches the end still set to "play" - expo-audio reports playing: false
     and didJustFinish, but never pauses it - so the seek back to 0 simply
     started it again, and it finished, and went back, and started again: a
     voice note that never stopped (the owner's report, 19 September 2026). */
  useEffect(() => {
    if (player && st && st.didJustFinish) {
      playing.delete(stopRef.current);
      try { player.pause(); } catch (e) {}
      try { player.seekTo(0); } catch (e) {}
    }
  }, [st && st.didJustFinish, player]);

  async function toggle() {
    if (!A || !player) return;          // voice notes cannot play on this phone - reported by lazy.js
    try {
      if (st && st.playing) { stopRef.current(); return; }
      stopOthers(stopRef.current);
      if (!loaded) {
        try { await A.setAudioModeAsync({ playsInSilentMode: true, allowsRecording: false }); } catch (e) {}
        player.replace({ uri });
        setLoaded(true);
      } else if (st && st.duration > 0 && st.currentTime >= st.duration - 0.05) {
        await player.seekTo(0);
      }
      player.play();
      playing.add(stopRef.current);
    } catch (e) {}
  }

  const isPlaying = !!(st && st.playing);
  const waiting = loaded && !isPlaying && !!(st && st.isBuffering);
  const durSec = st && st.duration > 0 ? st.duration : (knownSecs || 0);
  const posSec = (st && st.currentTime) || 0;
  const ink = mine ? '#fff' : C.navy;
  const track = mine ? 'rgba(255,255,255,.35)' : C.line;
  const frac = durSec > 0 ? Math.min(1, posSec / durSec) : 0;

  return (
    <TouchableOpacity
      onPress={toggle}
      activeOpacity={0.75}
      accessibilityRole="button"
      accessibilityLabel={isPlaying ? 'Pause voice note' : 'Play voice note'}
      style={{ flexDirection: 'row', alignItems: 'center', gap: ui.s(9), minWidth: ui.s(160) }}
    >
      <View
        style={{
          width: ui.tap(34), height: ui.tap(34), borderRadius: ui.tap(17),
          alignItems: 'center', justifyContent: 'center',
          backgroundColor: mine ? 'rgba(255,255,255,.18)' : C.navyWash,
        }}
      >
        <Feather
          name={waiting ? 'more-horizontal' : isPlaying ? 'pause' : 'play'}
          size={ui.s(16)}
          color={ink}
        />
      </View>
      <View style={{ flex: 1, justifyContent: 'center' }}>
        <View style={[s.track, { backgroundColor: track, borderRadius: ui.s(2) }]}>
          <View style={[s.fill, { width: (frac * 100) + '%', backgroundColor: ink, borderRadius: ui.s(2) }]} />
        </View>
        <Text
          maxFontSizeMultiplier={1}
          style={[type('tiny', ui), { color: ink, opacity: 0.85, marginTop: ui.s(4), fontWeight: '600' }]}
        >
          {fmt(isPlaying || posSec > 0 ? posSec : durSec)}
        </Text>
      </View>
    </TouchableOpacity>
  );
}

const s = StyleSheet.create({
  track: { height: 3, width: '100%', overflow: 'hidden' },
  fill: { height: 3 },
});
