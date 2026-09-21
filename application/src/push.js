import * as SecureStore from 'expo-secure-store';
import { pushRegister, pushForget, send, poll, callFinish, reportProblem } from './api';

/* Push notifications - the phone rings with the app shut.
   -------------------------------------------------------------------------
   The owner's order (19 September 2026): notifications that behave "exactly
   the same way as they do in a real/production application". So this is what
   every production Android app does: Google's Firebase Cloud Messaging
   delivers a small data message from our server (includes/push.php), and
   expo-notifications turns it into a notification on the phone - even when
   the app has been swiped away.

   THREE CHANNELS, the three switches under Settings > Apps > SBK Chat >
   Notifications, so each can be turned off on its own:
     Messages   one-to-one conversations (and missed calls)
     Groups     the desk's groups
     Calls      a call ringing - its own ringtone, loudest priority

   THE BUTTONS, as on WhatsApp:
     a message  Reply (typed right in the notification)  and  Mark as read
     a call     Decline  and  Answer
   Reply, Mark as read and Decline are done without opening the app - by the
   background task below, which Android runs even when the app is closed.
   Answer opens the app and picks the call up.

   While the app is open and in front of you nothing is shown by the system:
   the app's own screens, tone and call screen already say it all - exactly as
   before. */

export const PASS_KEY = 'sbk.pass';        // where App.js keeps the pass
const FCM_KEY = 'sbk.fcm';                 // the Firebase token this phone last registered
export const TASK = 'SBK_PUSH_TASK';

/* Loaded on first use, like lazy.js: a native module that cannot start costs
   the notifications, never the app. */
let N;
let TM;
export function notif() {
  if (N === undefined) {
    try {
      // eslint-disable-next-line global-require
      N = require('expo-notifications');
    } catch (e) {
      N = null;
      reportProblem('expo-notifications could not load', e);
    }
  }
  return N;
}
function taskManager() {
  if (TM === undefined) {
    try {
      // eslint-disable-next-line global-require
      TM = require('expo-task-manager');
    } catch (e) {
      TM = null;
      reportProblem('expo-task-manager could not load', e);
    }
  }
  return TM;
}

/** Our own details inside a notification: which conversation, which call. */
export function payloadOf(notification) {
  const c = notification && notification.request && notification.request.content;
  const d = c && c.data;
  if (!d) return {};
  if (typeof d === 'string') {
    try { return JSON.parse(d); } catch (e) { return {}; }
  }
  return d;
}

/** The `body` of a push as the background task receives it - its exact shape differs by path. */
function bodyOf(d) {
  const tries = [
    d && d.data && d.data.body, d && d.data && d.data.dataString,
    d && d.dataString, d && d.body,
  ];
  for (const t of tries) {
    if (!t) continue;
    if (typeof t === 'object') return t;
    try { return JSON.parse(t); } catch (e) { /* the next shape */ }
  }
  return null;
}

/** Where a notification leads when it is tapped - the same shape People.js hands Chat. */
export function targetOf(p) {
  if (!p || !p.kind) return null;
  const base = { name: p.name || 'Chat', sub: '', hue: p.hue | 0, ini: p.ini || '', online: false };
  if (p.kind === 'group' && p.group) return { ...base, kind: 'group', id: p.group | 0, thread: 0, mine: false };
  if (p.kind === 'person' && p.to) return { ...base, kind: 'person', id: p.to | 0, thread: p.thread | 0, mine: false };
  if (p.kind === 'thread' && p.thread) return { ...base, kind: 'thread', id: p.thread | 0, thread: p.thread | 0, mine: true };
  return null;
}

/**
 * Reply / Mark as read / Decline - pressed on a notification. Done straight
 * against the server with the pass the app keeps, then the notification goes.
 */
export async function doAction(actionId, userText, notification) {
  const p = payloadOf(notification);
  const nid = notification && notification.request && notification.request.identifier;
  const pass = await SecureStore.getItemAsync(PASS_KEY);
  if (!pass) return;
  if (actionId === 'reply') {
    const body = String(userText || '').trim();
    if (body) {
      await send(pass, p.kind === 'group' ? { group: p.group | 0, body }
        : p.kind === 'person' ? { to: p.to | 0, body }
          : { thread: p.thread | 0, body });
    }
  } else if (actionId === 'read') {
    await poll(pass, p.kind === 'group' ? { group: p.group | 0, open: true } : { thread: p.thread | 0, open: true });
  } else if (actionId === 'decline') {
    if (p.call) await callFinish(pass, p.call | 0, 'decline');
  } else {
    return;
  }
  const Nn = notif();
  if (Nn && nid) await Nn.dismissNotificationAsync(nid);
}

/* ------------------------------------------------ the background task
   Run by Android when the app is in the background or not running at all:
   a button pressed on one of our notifications, or a silent push (the one
   that takes a ringing call away once it has been answered or ended). It has
   to be defined as the app's code loads - index.js imports this file first. */
async function onBackground({ data, error }) {
  if (error || !data) return;
  try {
    if (data.actionIdentifier) {
      await doAction(data.actionIdentifier, data.userText, data.notification);
      return;
    }
    const body = bodyOf(data);
    if (body && body.type === 'dismiss' && body.tag) {
      const Nn = notif();
      if (Nn) await Nn.dismissNotificationAsync(String(body.tag));
    }
  } catch (e) {
    reportProblem('push: the background task failed', e);
  }
}

(function prepare() {
  const Nn = notif();
  if (!Nn) return;
  // In front of you: the app's own screens speak, the system says nothing.
  Nn.setNotificationHandler({
    handleNotification: async () => ({
      shouldShowBanner: false, shouldShowList: false, shouldPlaySound: false, shouldSetBadge: false,
    }),
  });
  const T = taskManager();
  if (!T) return;
  try {
    if (!T.isTaskDefined(TASK)) T.defineTask(TASK, onBackground);
    Nn.registerTaskAsync(TASK).catch((e) => reportProblem('push: the background task would not register', e));
  } catch (e) {
    reportProblem('push: the background task could not be defined', e);
  }
}());

let tokenSub = null;

/**
 * Once somebody is signed in: the three channels, the buttons, the one-time
 * "allow notifications?" question (Android 13+), and this phone's token to our
 * server. Safe to call on every start - each step only sets what it needs to.
 */
export async function startPush(pass) {
  const Nn = notif();
  if (!Nn || !pass) return;
  try {
    const quiet = { lightColor: '#18335E', showBadge: true, enableVibrate: true };
    await Nn.setNotificationChannelAsync('messages', {
      ...quiet,
      name: 'Messages',
      description: 'New messages in your conversations, and missed calls',
      importance: Nn.AndroidImportance.HIGH,
      vibrationPattern: [0, 250, 150, 250],
      lockscreenVisibility: Nn.AndroidNotificationVisibility.PRIVATE,
    });
    await Nn.setNotificationChannelAsync('groups', {
      ...quiet,
      name: 'Groups',
      description: 'New messages in the groups you are in',
      importance: Nn.AndroidImportance.HIGH,
      vibrationPattern: [0, 250, 150, 250],
      lockscreenVisibility: Nn.AndroidNotificationVisibility.PRIVATE,
    });
    await Nn.setNotificationChannelAsync('calls', {
      name: 'Calls',
      description: 'Incoming voice and video calls',
      importance: Nn.AndroidImportance.MAX,
      sound: 'ring.ogg',
      enableVibrate: true,
      vibrationPattern: [0, 700, 500, 700, 500, 700, 500, 700],
      lightColor: '#BC1E2D',
      lockscreenVisibility: Nn.AndroidNotificationVisibility.PUBLIC,
      audioAttributes: {
        usage: Nn.AndroidAudioUsage.NOTIFICATION_RINGTONE,
        contentType: Nn.AndroidAudioContentType.SONIFICATION,
      },
    });

    await Nn.setNotificationCategoryAsync('message', [
      {
        identifier: 'reply',
        buttonTitle: 'Reply',
        textInput: { submitButtonTitle: 'Send', placeholder: 'Write a reply…' },
        options: { opensAppToForeground: false },
      },
      { identifier: 'read', buttonTitle: 'Mark as read', options: { opensAppToForeground: false } },
    ]);
    await Nn.setNotificationCategoryAsync('call', [
      { identifier: 'decline', buttonTitle: 'Decline', options: { opensAppToForeground: false, isDestructive: true } },
      { identifier: 'answer', buttonTitle: 'Answer', options: { opensAppToForeground: true } },
    ]);

    let perm = await Nn.getPermissionsAsync();
    if (!perm.granted && perm.canAskAgain !== false) perm = await Nn.requestPermissionsAsync();
    if (!perm.granted) return;

    const t = await Nn.getDevicePushTokenAsync();
    if (t && t.data) {
      await pushRegister(pass, String(t.data));
      await SecureStore.setItemAsync(FCM_KEY, String(t.data));
    }
    if (!tokenSub) {
      // Google may hand the phone a new token later; the server must hear about it.
      tokenSub = Nn.addPushTokenListener(async (nt) => {
        try {
          const p = await SecureStore.getItemAsync(PASS_KEY);
          if (p && nt && nt.data) {
            await pushRegister(p, String(nt.data));
            await SecureStore.setItemAsync(FCM_KEY, String(nt.data));
          }
        } catch (e) { /* the next start registers it */ }
      });
    }
  } catch (e) {
    reportProblem('push notifications could not start', e);
  }
}

/** Signing out: this phone stops receiving this person's notifications. */
export async function stopPush() {
  try {
    const t = await SecureStore.getItemAsync(FCM_KEY);
    if (t) {
      await pushForget(t).catch(() => {});
      await SecureStore.deleteItemAsync(FCM_KEY);
    }
    const Nn = notif();
    if (Nn) await Nn.dismissAllNotificationsAsync();
  } catch (e) { /* signing out must never be stopped by this */ }
}
