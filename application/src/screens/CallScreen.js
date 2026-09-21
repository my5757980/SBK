import React, { useEffect, useRef } from 'react';
import {
  View, Text, TouchableOpacity, StyleSheet, Animated, Easing, BackHandler, Image,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { LinearGradient } from 'expo-linear-gradient';
import { Feather } from '@expo/vector-icons';
import Avatar from '../Avatar';
import { C } from '../theme';
import { useUI, type } from '../ui';
import { useCallEngine, useCallSnap, rtcView } from '../calls';

/* The call, on the whole screen - over whatever was open, the way a phone's own
   call screen comes up over any app.

   Voice: SBK navy, the other person's face in the middle, a slow pulse while it
   rings. Video: their picture fills the glass and yours sits in the corner.
   The words ("Ringing…", "Connecting…", the running clock, "No answer") are
   the website's own, from the same engine, so the two never say different
   things about the same call. */

function RoundButton({ icon, label, onPress, tone = 'glass', size, ui, active }) {
  const bg = tone === 'end' ? C.red
    : tone === 'go' ? C.ok
    : active ? '#FFFFFF' : 'rgba(255,255,255,.16)';
  const ink = active && tone === 'glass' ? C.navy : '#fff';
  return (
    <View style={{ alignItems: 'center', width: size + ui.s(22) }}>
      <TouchableOpacity
        onPress={onPress}
        activeOpacity={0.75}
        accessibilityRole="button"
        accessibilityLabel={label}
        style={{
          width: size, height: size, borderRadius: size / 2, backgroundColor: bg,
          alignItems: 'center', justifyContent: 'center',
        }}
      >
        <Feather name={icon} size={size * 0.4} color={ink} />
      </TouchableOpacity>
      <Text
        maxFontSizeMultiplier={1.1}
        numberOfLines={1}
        style={{ color: '#D6DEEA', fontSize: ui.s(11.5), fontWeight: '600', marginTop: ui.s(7) }}
      >
        {label}
      </Text>
    </View>
  );
}

export default function CallScreen() {
  const ui = useUI();
  const inset = useSafeAreaInsets();
  const snap = useCallSnap();
  const calls = useCallEngine();
  const pulse = useRef(new Animated.Value(0)).current;

  const showing = snap.status && snap.status !== 'idle';
  const ringing = snap.status === 'incoming' || (snap.status === 'outgoing' && snap.id);

  /* The pulse behind the face, only while it rings. */
  useEffect(() => {
    if (!ringing) { pulse.stopAnimation(); pulse.setValue(0); return undefined; }
    const loop = Animated.loop(Animated.timing(pulse, {
      toValue: 1, duration: 1600, easing: Easing.out(Easing.quad), useNativeDriver: true,
    }));
    loop.start();
    return () => loop.stop();
  }, [ringing, pulse]);

  /* Back does not drop a call by accident; the red button does. */
  useEffect(() => {
    if (!showing) return undefined;
    const sub = BackHandler.addEventListener('hardwareBackPress', () => true);
    return () => sub.remove();
  }, [showing]);

  if (!showing || !calls) return null;

  // Only now, with a call on screen, is WebRTC's video surface asked for.
  const RTCView = snap.kind === 'video' ? rtcView() : null;

  const video = snap.kind === 'video';
  const remoteVideo = video && RTCView && snap.remote && snap.status === 'live' && snap.remote.getVideoTracks
    && snap.remote.getVideoTracks().length > 0;
  const localVideo = video && RTCView && snap.local && !snap.camOff;
  const ended = snap.status === 'ended';
  const asking = snap.status === 'incoming';
  const face = ui.s(ui.short ? 96 : 116);
  const btn = ui.tap(62);
  const big = ui.tap(70);

  const ringScale = pulse.interpolate({ inputRange: [0, 1], outputRange: [1, 1.55] });
  const ringFade = pulse.interpolate({ inputRange: [0, 1], outputRange: [0.45, 0] });

  return (
    <View style={StyleSheet.absoluteFill}>
      {remoteVideo ? (
        <RTCView
          streamURL={snap.remote.toURL()}
          style={StyleSheet.absoluteFill}
          objectFit="cover"
          zOrder={0}
        />
      ) : (
        <LinearGradient
          colors={[C.navyLite, C.navy, C.navyDark]}
          start={{ x: 0.2, y: 0 }}
          end={{ x: 0.8, y: 1 }}
          style={StyleSheet.absoluteFill}
        />
      )}
      {remoteVideo ? <View style={[StyleSheet.absoluteFill, s.shade]} /> : null}

      {/* ---- top: whose call this is */}
      <View style={{ paddingTop: inset.top + ui.s(14), paddingHorizontal: ui.s(20), alignItems: 'center' }}>
        <View style={[s.brandRow, { gap: ui.s(7), borderRadius: ui.s(20),
          paddingHorizontal: ui.s(12), paddingVertical: ui.s(6) }]}>
          <Image
            source={require('../../assets/sbk-mark.png')}
            resizeMode="contain"
            style={{ width: ui.s(26), height: ui.s(20) }}
          />
          <Text maxFontSizeMultiplier={1} style={[type('tiny', ui), s.brandTxt]}>
            {video ? 'SBK VIDEO CALL' : 'SBK VOICE CALL'}
          </Text>
        </View>
      </View>

      {/* ---- middle: the face, the name, where it is */}
      <View style={s.middle}>
        {!remoteVideo ? (
          <View style={{ width: face * 1.6, height: face * 1.6, alignItems: 'center', justifyContent: 'center' }}>
            {ringing ? (
              <Animated.View
                style={{
                  position: 'absolute', width: face, height: face, borderRadius: face / 2,
                  backgroundColor: '#ffffff', opacity: ringFade, transform: [{ scale: ringScale }],
                }}
              />
            ) : null}
            <View style={[s.faceRing, { borderRadius: face / 2 + ui.s(5), padding: ui.s(5) }]}>
              <Avatar name={snap.peer} hue={snap.hue} size={face} />
            </View>
          </View>
        ) : null}
        <Text
          maxFontSizeMultiplier={1.15}
          numberOfLines={1}
          style={[type('h1', ui), { color: '#fff', marginTop: remoteVideo ? 0 : ui.s(10), paddingHorizontal: ui.s(24) }]}
        >
          {snap.peer || 'SBK'}
        </Text>
        <Text
          maxFontSizeMultiplier={1.15}
          numberOfLines={2}
          style={[
            type('body', ui),
            {
              color: ended ? '#FFD2D6' : '#C9D5E6', marginTop: ui.s(6), textAlign: 'center',
              paddingHorizontal: ui.s(28), fontVariant: ['tabular-nums'],
            },
          ]}
        >
          {snap.text}
        </Text>
      </View>

      {/* ---- their view of you, in the corner */}
      {localVideo ? (
        <View
          style={[
            s.pip,
            {
              top: inset.top + ui.s(56), right: ui.s(16),
              width: ui.s(104), height: ui.s(148), borderRadius: ui.s(14),
            },
          ]}
        >
          <RTCView
            streamURL={snap.local.toURL()}
            style={{ flex: 1 }}
            objectFit="cover"
            mirror={snap.front}
            zOrder={1}
          />
        </View>
      ) : null}

      {/* ---- the buttons */}
      {!ended ? (
        <View style={{ paddingBottom: inset.bottom + ui.s(34), paddingHorizontal: ui.s(18) }}>
          {asking ? (
            <View style={[s.row, { justifyContent: 'space-evenly' }]}>
              <RoundButton ui={ui} size={big} icon="phone-off" label="Decline" tone="end"
                onPress={calls.hangUp} />
              <RoundButton ui={ui} size={big} icon={video ? 'video' : 'phone'} label="Answer" tone="go"
                onPress={calls.answer} />
            </View>
          ) : (
            <>
              <View style={[s.row, { justifyContent: 'center', gap: ui.s(4), marginBottom: ui.s(22) }]}>
                <RoundButton ui={ui} size={btn} icon={snap.muted ? 'mic-off' : 'mic'}
                  label={snap.muted ? 'Unmute' : 'Mute'} active={snap.muted} onPress={calls.toggleMute} />
                <RoundButton ui={ui} size={btn} icon={snap.speaker ? 'volume-2' : 'volume-1'}
                  label="Speaker" active={snap.speaker} onPress={calls.toggleSpeaker} />
                {video ? (
                  <RoundButton ui={ui} size={btn} icon={snap.camOff ? 'video-off' : 'video'}
                    label={snap.camOff ? 'Camera on' : 'Camera off'} active={snap.camOff}
                    onPress={calls.toggleCam} />
                ) : null}
                {video ? (
                  <RoundButton ui={ui} size={btn} icon="refresh-cw" label="Flip"
                    onPress={calls.switchCamera} />
                ) : null}
              </View>
              <View style={[s.row, { justifyContent: 'center' }]}>
                <RoundButton ui={ui} size={big} icon="phone-off" label="End" tone="end"
                  onPress={calls.hangUp} />
              </View>
            </>
          )}
        </View>
      ) : (
        <View style={{ height: inset.bottom + ui.s(34) + big + ui.s(30) }} />
      )}
    </View>
  );
}

const s = StyleSheet.create({
  shade: { backgroundColor: 'rgba(8,18,36,.28)' },
  brandRow: {
    // The size of it comes from the screen (ui.s) where it is used, like
    // everything else on this screen; only the look lives here.
    flexDirection: 'row', alignItems: 'center',
    backgroundColor: 'rgba(255,255,255,.08)',
  },
  brandTxt: { color: '#D6DEEA', letterSpacing: 1.4 },
  middle: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  faceRing: { backgroundColor: 'rgba(255,255,255,.12)' },
  row: { flexDirection: 'row', alignItems: 'flex-start' },
  pip: {
    position: 'absolute', overflow: 'hidden', backgroundColor: '#000',
    borderWidth: 2, borderColor: 'rgba(255,255,255,.7)',
  },
});
