import React from 'react';
import { Text, Linking } from 'react-native';
import { reportProblem } from './api';

/* A message with its web addresses made tappable - the website's linkify() in
   chat.js, with the same pattern: http:// or https:// up to the next space.
   Nothing else is guessed at (a bare "www." stays text, as it does in the
   browser), because a link that opens something the writer did not mean is
   worse than one that has to be copied. */
const URL_RE = /\bhttps?:\/\/[^\s<]+/g;

export default function LinkText({ text, style, linkStyle, ...rest }) {
  const s = String(text || '');
  const parts = [];
  let last = 0;
  let m;
  URL_RE.lastIndex = 0;
  while ((m = URL_RE.exec(s)) !== null) {
    if (m.index > last) parts.push({ t: s.slice(last, m.index) });
    parts.push({ t: m[0], url: m[0] });
    last = m.index + m[0].length;
  }
  if (last < s.length) parts.push({ t: s.slice(last) });

  if (!parts.some((p) => p.url)) {
    return <Text style={style} {...rest}>{s}</Text>;
  }
  return (
    <Text style={style} {...rest}>
      {parts.map((p, i) => (p.url ? (
        <Text
          key={i}
          style={linkStyle}
          accessibilityRole="link"
          onPress={() => Linking.openURL(p.url).catch((e) => reportProblem('a link would not open: ' + p.url, e))}
        >
          {p.t}
        </Text>
      ) : (
        <Text key={i}>{p.t}</Text>
      )))}
    </Text>
  );
}
