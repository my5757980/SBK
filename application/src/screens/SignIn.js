import React, { useState } from 'react';
import {
  View, Text, KeyboardAvoidingView, Platform, ScrollView, TouchableOpacity, Image,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { C } from '../theme';
import { useUI, type, page } from '../ui';
import { Button, Field, Notice } from '../bits';
import { signIn } from '../api';

/* One account, one password, and the WEBSITE owns both.
   This screen never checks a password - it hands what was typed to
   sbkautotrading.com, which has been doing the checking since the day the chat
   was built, and gets back a pass. An account made on the website works here
   without anybody being told about it, and taking it away over there takes it
   away here too. */
export default function SignIn({ onDone, goRegister }) {
  const ui = useUI();
  const inset = useSafeAreaInsets();
  const [login, setLogin] = useState('');
  const [pass, setPass] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  async function go() {
    if (busy) return;
    if (!login.trim() || !pass) { setErr('E-mail and password, please.'); return; }
    setBusy(true); setErr('');
    try {
      const j = await signIn(login.trim(), pass);
      onDone(j.token, j.user);
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <KeyboardAvoidingView
      style={page}
      behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
    >
      <ScrollView
        contentContainerStyle={{
          paddingTop: inset.top + ui.s(ui.short ? 18 : 44),
          paddingBottom: inset.bottom + ui.s(28),
          paddingHorizontal: ui.s(24),
          flexGrow: 1,
          /* On a tablet or a phone on its side the form is held to a readable
             column instead of stretching across the whole glass. */
          maxWidth: ui.col,
          alignSelf: 'center',
          width: '100%',
        }}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
        <Image
          source={require('../../assets/sbk-mark.png')}
          resizeMode="contain"
          style={{ width: ui.s(96), height: ui.s(74), marginBottom: ui.s(18) }}
        />

        <Text maxFontSizeMultiplier={ui.maxFont} style={[type('h1', ui), { color: C.ink }]}>
          Welcome back
        </Text>
        <Text
          maxFontSizeMultiplier={ui.maxFont}
          style={[type('body', ui), { color: C.ink2, marginTop: ui.s(6) }]}
        >
          Sign in with the account you use on our website.
        </Text>

        <Field
          label="E-mail"
          icon="mail"
          value={login}
          onChangeText={setLogin}
          placeholder="you@example.com"
          keyboard="email-address"
          autoComplete="email"
          returnKeyType="next"
        />
        <Field
          label="Password"
          icon="lock"
          value={pass}
          onChangeText={setPass}
          placeholder="Your password"
          secure
          autoComplete="current-password"
          returnKeyType="go"
          onSubmitEditing={go}
        />

        <Notice text={err} />

        <View style={{ marginTop: ui.s(26) }}>
          <Button label="Sign in" onPress={go} busy={busy} icon="log-in" />
        </View>

        <View style={{ flex: 1, minHeight: ui.s(16) }} />

        <TouchableOpacity
          onPress={goRegister}
          accessibilityRole="button"
          style={{ alignItems: 'center', paddingVertical: ui.s(14) }}
        >
          <Text maxFontSizeMultiplier={ui.maxFont} style={[type('body', ui), { color: C.ink2 }]}>
            New here? <Text style={{ color: C.navy, fontWeight: '700' }}>Create an account</Text>
          </Text>
        </TouchableOpacity>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

