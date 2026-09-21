import React, { useState } from 'react';
import {
  View, Text, StyleSheet, KeyboardAvoidingView, Platform, ScrollView, TouchableOpacity,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Feather } from '@expo/vector-icons';
import { C } from '../theme';
import { useUI, type, page, TOUCH } from '../ui';
import { Button, Field, Notice } from '../bits';
import { signUp } from '../api';

/* Name, e-mail AND telephone - the owner's rule for every customer, and the
   same three the website asks for. Asked ONCE, here, so the chat never has to
   stop somebody halfway through to ask again: the account carries all three
   from the moment it exists.

   A customer, always. A role - agent, manager, CSD - is something the desk
   gives somebody on the website; nobody hands themselves one through a sign-up
   form. */
export default function SignUp({ onDone, goSignIn }) {
  const ui = useUI();
  const inset = useSafeAreaInsets();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [pass, setPass] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  async function go() {
    if (busy) return;
    if (!name.trim() || !email.trim() || !pass) {
      setErr('Name, e-mail and password, please.'); return;
    }
    if (pass.length < 8) { setErr('Use at least eight characters.'); return; }
    setBusy(true); setErr('');
    try {
      const j = await signUp(name.trim(), email.trim(), phone.trim(), pass);
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
      <View
        style={{
          paddingTop: inset.top + ui.s(8),
          paddingHorizontal: ui.s(10),
          paddingBottom: ui.s(4),
        }}
      >
        <TouchableOpacity
          onPress={goSignIn}
          accessibilityRole="button"
          accessibilityLabel="Back to sign in"
          hitSlop={10}
          style={{
            width: ui.tap(TOUCH), height: ui.tap(TOUCH),
            alignItems: 'center', justifyContent: 'center',
          }}
        >
          <Feather name="chevron-left" size={ui.s(25)} color={C.ink2} />
        </TouchableOpacity>
      </View>

      <ScrollView
        contentContainerStyle={{
          paddingBottom: inset.bottom + ui.s(28),
          paddingHorizontal: ui.s(24),
          flexGrow: 1,
          maxWidth: ui.col,
          alignSelf: 'center',
          width: '100%',
        }}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
        <Text maxFontSizeMultiplier={ui.maxFont} style={[type('h1', ui), { color: C.ink }]}>
          Create your account
        </Text>
        <Text
          maxFontSizeMultiplier={ui.maxFont}
          style={[type('body', ui), { color: C.ink2, marginTop: ui.s(6) }]}
        >
          The same account works on our website and here.
        </Text>

        <Field label="Your name" icon="user" value={name} onChangeText={setName}
          placeholder="Full name" autoCapitalize="words" autoComplete="name" />
        <Field label="E-mail" icon="mail" value={email} onChangeText={setEmail}
          placeholder="you@example.com" keyboard="email-address" autoComplete="email" />
        <Field label="Telephone" icon="phone" value={phone} onChangeText={setPhone}
          placeholder="03001234567" keyboard="phone-pad" autoComplete="tel" />
        <Field label="Password" icon="lock" value={pass} onChangeText={setPass}
          placeholder="At least 8 characters" secure autoComplete="new-password"
          returnKeyType="go" onSubmitEditing={go} />

        <Notice text={err} />

        <View style={{ marginTop: ui.s(24) }}>
          <Button label="Create account" onPress={go} busy={busy} icon="user-plus" />
        </View>

        <View style={{ flex: 1, minHeight: ui.s(12) }} />

        <TouchableOpacity
          onPress={goSignIn}
          accessibilityRole="button"
          style={{ alignItems: 'center', paddingVertical: ui.s(14) }}
        >
          <Text maxFontSizeMultiplier={ui.maxFont} style={[type('body', ui), { color: C.ink2 }]}>
            Already have one? <Text style={{ color: C.navy, fontWeight: '700' }}>Sign in</Text>
          </Text>
        </TouchableOpacity>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
