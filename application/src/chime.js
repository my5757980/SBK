import { audioLib } from './lazy';
import { reportProblem } from './api';

/* The little tone when something arrives - the website's chime() in chat.js:
   the same 880 Hz and the same third of a second, played from a file here
   because a phone has no oscillator to make it with (assets/chime.wav was made
   from exactly those numbers). Quiet on purpose. A phone that cannot make it
   simply stays quiet: a missing tone is never worth an error on screen. */

let player = null;
let broken = false;
let lastAt = 0;

export function chime() {
  if (broken) return;
  const now = Date.now();
  if (now - lastAt < 1500) return;          // five messages at once are one tone, not five
  lastAt = now;
  try {
    const A = audioLib();
    if (!A || !A.createAudioPlayer) { broken = true; return; }
    if (!player) {
      // eslint-disable-next-line global-require
      player = A.createAudioPlayer(require('../assets/chime.wav'));
      player.play();
      return;
    }
    // Played before: back to the start first, or it would "play" from its end.
    player.seekTo(0).then(() => player.play(), () => player.play());
  } catch (e) {
    broken = true;
    reportProblem('the new-message tone could not play', e);
  }
}
