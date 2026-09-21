import React from 'react';
import { View, Text, StyleSheet } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { faceOf, initialsOf, C } from './theme';

/* A person's face: their initials on one of six discs, and WHICH disc is the
   server's decision, not ours. It sends a hue with every name, so the same
   person is the same colour here and in the browser - which is the whole point
   of the app being the same chat rather than a second one that looks like it. */
export default function Avatar({ name, ini, hue, size = 46, online, square }) {
  const [a, b] = faceOf(hue);
  const letters = ini || initialsOf(name);
  return (
    <View style={{ width: size, height: size }}>
      <LinearGradient
        colors={[a, b]}
        start={{ x: 0.1, y: 0 }}
        end={{ x: 0.9, y: 1 }}
        style={[
          styles.disc,
          { width: size, height: size, borderRadius: square ? size * 0.34 : size / 2 },
        ]}
      >
        <Text style={[styles.txt, { fontSize: size * 0.32 }]}>{letters}</Text>
      </LinearGradient>
      {online ? <View style={styles.dot} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  disc: { alignItems: 'center', justifyContent: 'center' },
  txt: { color: '#fff', fontWeight: '700', letterSpacing: 0.5 },
  dot: {
    position: 'absolute', right: -1, bottom: -1,
    width: 13, height: 13, borderRadius: 7,
    backgroundColor: C.ok, borderWidth: 2.5, borderColor: '#fff',
  },
});
