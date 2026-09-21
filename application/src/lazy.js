import { reportProblem } from './api';

/* The native libraries a feature needs, loaded the first time the feature is
   used - not when the app starts.

   A library's JavaScript asks for its native half the moment it is imported
   (requireNativeModule). Imported at the top of a screen, a library that
   cannot start on a phone throws before the first screen is drawn, and in a
   release build that closes the app - which is exactly how the calls APK
   behaved on the owner's phone. Loaded here instead, the same failure costs
   only the one feature (the voice note, or the picture), the app stays open,
   and the reason goes to our server.

   The answer is kept for the life of the app, so a screen that calls a hook
   from one of these calls it on every render or on none - hooks stay in the
   same order either way. */

let audioMod;
let pickerMod;

/** expo-audio (voice notes: record and play), or null if it cannot start here. */
export function audioLib() {
  if (audioMod === undefined) {
    try {
      // eslint-disable-next-line global-require
      audioMod = require('expo-audio');
    } catch (e) {
      audioMod = null;
      reportProblem('expo-audio could not load', e);
    }
  }
  return audioMod;
}

/** expo-image-picker (sending a picture), or null if it cannot start here. */
export function pickerLib() {
  if (pickerMod === undefined) {
    try {
      // eslint-disable-next-line global-require
      pickerMod = require('expo-image-picker');
    } catch (e) {
      pickerMod = null;
      reportProblem('expo-image-picker could not load', e);
    }
  }
  return pickerMod;
}
