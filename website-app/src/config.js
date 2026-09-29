/* The one address this app opens - the SBK website itself.

   The app has no screens of its own: it shows the website, so everything in it
   (pages, prices, the chat, customers' accounts) comes from the website's own
   database and admin panel, and a change made there is in the app at once.

   Set at BUILD time, so the same code serves a test link today and the real
   domain once the website is live:
       EXPO_PUBLIC_SITE_URL=https://<the website> npx expo prebuild ...
   Without it the app opens sbkautotrading.com. */
export const SITE_URL = process.env.EXPO_PUBLIC_SITE_URL || 'https://sbkautotrading.com/';
