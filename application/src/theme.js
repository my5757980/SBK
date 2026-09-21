/* The same colours as the website's chat, and they were measured rather than
   chosen: a pixel count of the SBK logo gives #BC1E2D red, #18335E navy and
   #0370CD blue, and nothing else. The owner looked at an earlier palette and
   said it was "bekar" - the theme has to be whatever is on the mark. */

export const C = {
  red:      '#BC1E2D',
  redDark:  '#9C1825',
  redWash:  '#FDEEF0',

  navy:     '#18335E',
  navyDark: '#0E1F3D',
  navyLite: '#2A4C82',
  navyWash: '#EEF2F9',

  blue:     '#0370CD',

  paper:    '#F4F6F9',
  card:     '#FFFFFF',
  line:     '#E4E8EF',
  line2:    '#EEF1F6',

  ink:      '#101828',
  ink2:     '#54617A',
  ink3:     '#8B96AA',

  ok:       '#1F9254',
  tick:     '#8FD0FF',
};

/* Six faces mixed from those three, picked by the hue the SERVER sends - so a
   person is the same colour on the phone as in the browser. */
export const FACES = [
  ['#2A4C82', '#18335E'],
  ['#3F97E2', '#0370CD'],
  ['#D9394A', '#BC1E2D'],
  ['#1F6EA8', '#134A75'],
  ['#8E1B2A', '#5E121C'],
  ['#4A6EA8', '#26406E'],
];

export function faceOf(hue) {
  return FACES[Math.abs(Number(hue) || 0) % FACES.length];
}

export function initialsOf(name) {
  const p = String(name || '').trim().split(/\s+/);
  return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
}

/** The clock under a message, in the reader's own time. */
export function clockOf(iso) {
  if (!iso) return '';
  const d = new Date(String(iso).replace(' ', 'T') + (String(iso).includes('Z') ? '' : 'Z'));
  if (isNaN(d.getTime())) return '';
  let h = d.getHours();
  const m = String(d.getMinutes()).padStart(2, '0');
  const ap = h >= 12 ? 'PM' : 'AM';
  h = h % 12 || 12;
  return h + ':' + m + ' ' + ap;
}
