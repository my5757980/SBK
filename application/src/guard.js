import { reportProblem } from './api';

/* Loaded before anything else in the app (index.js imports it first).

   A JavaScript error that nobody catches closes a release build without a
   word - which is how the owner met the first calls APK: tap, gone, tap,
   gone. Every such error is now sent to our server first (api/crash.php), with
   the stack, so the next one is read rather than guessed at. What happens
   after that is unchanged - React Native's own handler still decides. The
   native side (SbkCrash.java) reports the crashes that happen below
   JavaScript, and a fatal JS error reaches it as well. */

const E = global.ErrorUtils;
if (E && E.getGlobalHandler && E.setGlobalHandler) {
  const before = E.getGlobalHandler();
  E.setGlobalHandler((error, isFatal) => {
    reportProblem((isFatal ? 'FATAL' : 'error') + ' - uncaught in JavaScript', error);
    if (before) before(error, isFatal);
  });
}
