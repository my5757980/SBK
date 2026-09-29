/* SBK Global Auto Trading - the website, as an app.
   ---------------------------------------------------------------------------
   Sir's instruction (29 Sep 2026): a NEW app, separate from the SBK Chat app,
   in which the whole website comes in by itself - nothing fetched separately,
   the UI exactly the website's, the chat usable, and everything managed from
   the website's own database and admin panel.

   So this is one full-screen WebView of the website and nothing else. What is
   here only makes the website behave inside an app as it does in a phone's
   browser:
     - the phone's Back button goes back in the website (otherwise it would
       close the app on the first press);
     - phone, e-mail, WhatsApp and map links open in their own apps, as they
       do from a browser;
     - the live chat's microphone, camera and picture picker work (voice
       notes, calls, pictures);
     - text is not re-sized by the phone's font setting, so every page looks
       exactly as the website draws it. */
import { useCallback, useEffect, useRef, useState } from 'react';
import { BackHandler, Linking, StyleSheet } from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { WebView } from 'react-native-webview';

import { SITE_URL } from './src/config';

/* A link for another app (tel:, mailto:, whatsapp:, geo:, intent: ...). An
   Android "intent://" link - WhatsApp's own button uses one - names the app
   and, sometimes, a web page to fall back to. */
function openOutside(url) {
  if (/^intent:/i.test(url)) {
    const scheme = /;scheme=([^;]+)/i.exec(url);
    const fallback = /;S\.browser_fallback_url=([^;]+)/i.exec(url);
    const target = scheme ? scheme[1] + '://' + url.replace(/^intent:\/\//i, '').split('#Intent')[0] : '';
    Linking.openURL(target).catch(() => {
      if (fallback) {
        Linking.openURL(decodeURIComponent(fallback[1])).catch(() => {});
      }
    });
    return;
  }
  Linking.openURL(url).catch(() => {});
}

export default function App() {
  const web = useRef(null);
  const [canGoBack, setCanGoBack] = useState(false);

  // Back goes back in the website; only on the first page does it leave the app.
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      if (canGoBack && web.current) {
        web.current.goBack();
        return true;
      }
      return false;
    });
    return () => sub.remove();
  }, [canGoBack]);

  // Web pages stay in the app; links meant for other apps go to them.
  const onShouldStartLoadWithRequest = useCallback((req) => {
    const url = String(req.url || '');
    if (/^(https?:|about:|blob:|data:|javascript:)/i.test(url)) {
      return true;
    }
    openOutside(url);
    return false;
  }, []);

  return (
    <SafeAreaProvider>
      <StatusBar style="dark" backgroundColor="#FFFFFF" />
      <SafeAreaView style={styles.page} edges={['top', 'bottom']}>
        <WebView
          ref={web}
          source={{ uri: SITE_URL }}
          style={styles.page}
          onNavigationStateChange={(s) => setCanGoBack(s.canGoBack)}
          onShouldStartLoadWithRequest={onShouldStartLoadWithRequest}
          // Signed-in customers and staff stay signed in, as in a browser.
          javaScriptEnabled
          domStorageEnabled
          sharedCookiesEnabled
          thirdPartyCookiesEnabled
          // The live chat: voice notes, voice/video calls, their sound.
          mediaCapturePermissionGrantType="grant"
          allowsInlineMediaPlayback
          mediaPlaybackRequiresUserAction={false}
          // "Open in a full window" and target="_blank" stay inside the app.
          setSupportMultipleWindows={false}
          // iPhone: swipe back, like Back on Android.
          allowsBackForwardNavigationGestures
          // Pages exactly as the website draws them, whatever the phone's font size.
          textZoom={100}
          originWhitelist={['*']}
        />
      </SafeAreaView>
    </SafeAreaProvider>
  );
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: '#FFFFFF' },
});
