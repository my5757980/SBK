import { Dimensions, PixelRatio, Platform, useWindowDimensions } from 'react-native';
import { C } from './theme';

/* HOW THIS APP STAYS THE RIGHT SIZE ON EVERY PHONE.
   -------------------------------------------------------------------------
   The owner's rule for the website was that not one thing, however small, may
   break between a 360px phone and the largest screen in the office. The same
   rule applies here, and a phone makes it harder rather than easier: the same
   build has to sit on a 320pt Android of 2019, a 430pt iPhone Pro Max, a tablet,
   and any of those turned on its side - and on top of that, on whatever text
   size the owner of the phone has chosen in their own settings.

   Three ideas do all of the work:

   1. ONE DESIGN WIDTH, SCALED. Everything is drawn for 390pt - a common modern
      phone - and multiplied by how much wider or narrower this screen really is.

   2. THE SCALE IS CLAMPED. Between 0.92 and 1.14. Without the floor, a 320pt
      phone gets text nobody can read; without the ceiling, a tablet gets a
      chat bubble the size of a poster. Past a point a bigger screen should show
      MORE, not bigger - which is what the width cap below is for.

   3. TEXT SIZE IS THE READER'S CHOICE, WITHIN REASON. A person who has set
      their phone to large text meant it, so it is honoured - but capped, or a
      200% setting pushes the send button off the screen.

   None of this is guesswork: the numbers are checked by sbk-tools/appsizecheck.py
   at nine widths, the same way the website is. */

export const BASE_W = 390;

/** How much bigger or smaller this screen is than the one it was drawn for. */
export function scaleFor(width) {
  /* The floor is 0.92, not 0.88. At 0.88 a 48pt button came out 42pt on a 320pt
     Android - under the 44pt that is the smallest thing a thumb can be asked to
     hit accurately, which appsizecheck.py caught before anybody installed
     anything. 0.92 keeps a small phone's text readable AND its buttons hittable. */
  return Math.max(0.92, Math.min(1.14, width / BASE_W));
}

/** A size in design points, turned into points for THIS screen. */
export function ms(size, width) {
  const w = width || Dimensions.get('window').width;
  return Math.round(PixelRatio.roundToNearestPixel(size * scaleFor(w)));
}

/**
 * Everything a screen needs to lay itself out, in one call.
 *
 * `wide` is true from about a large phone in landscape upwards. It is the one
 * place the layout is allowed to CHANGE rather than stretch: past 720pt a column
 * of text is given a maximum width and centred, because a line of chat running
 * the full width of a tablet is a line nobody can follow back to its start.
 */
export function useUI() {
  const { width, height } = useWindowDimensions();
  const k = scaleFor(width);
  const wide = width >= 720;
  const short = height < 620;           // a small phone, or the keyboard is up

  return {
    width,
    height,
    wide,
    short,
    landscape: width > height,
    /** A size in design points for this screen. */
    s: (n) => Math.round(PixelRatio.roundToNearestPixel(n * k)),
    /** The same, but never smaller than a thumb can hit. */
    tap: (n) => tap(n, k),
    /** The reading column: full width on a phone, held in on anything bigger. */
    col: wide ? Math.min(720, width - 48) : width,
    /** Never let the system text setting run away with the layout. */
    maxFont: 1.25,
  };
}

/* The type scale. Four sizes carry almost the whole app, which is what makes it
   look like one thing rather than a pile of screens. */
export const T = {
  h1:    { size: 27, weight: '800', line: 33 },
  h2:    { size: 20, weight: '800', line: 26 },
  title: { size: 16, weight: '700', line: 21 },
  body:  { size: 15, weight: '400', line: 21 },
  sub:   { size: 13, weight: '400', line: 18 },
  tiny:  { size: 11, weight: '700', line: 14 },
};

/** A heading, a label, a line of body text - with the scale already applied. */
export function type(name, ui, extra) {
  const t = T[name] || T.body;
  return Object.assign(
    { fontSize: ui.s(t.size), fontWeight: t.weight, lineHeight: ui.s(t.line) },
    extra || {}
  );
}

/* A touch target is never smaller than this. Apple asks for 44, Android for 48;
   the larger of the two is the one that is right on both. */
export const TOUCH = 48;

/**
 * A touch target, which may be scaled UP for a big screen but never down past
 * what a thumb can hit. The scale is for looks; this is for fingers, and the two
 * are not the same question - which is why it is not just ui.s(TOUCH).
 */
export function tap(n, k) {
  return Math.max(44, Math.round((n || TOUCH) * (k || 1)));
}

/* One shadow, used everywhere something sits above the page. Two different
   shadows on one screen is the quickest way to make an app look assembled
   rather than designed. */
export const lift = Platform.select({
  ios: {
    shadowColor: '#0F1C2E',
    shadowOpacity: 0.09,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 4 },
  },
  android: { elevation: 3 },
  default: {},
});

export const liftSoft = Platform.select({
  ios: {
    shadowColor: '#0F1C2E',
    shadowOpacity: 0.06,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 2 },
  },
  android: { elevation: 1 },
  default: {},
});

/** The page behind everything. */
export const page = { flex: 1, backgroundColor: C.paper };
