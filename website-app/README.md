# SBK Global Auto Trading - the website as an app

Sir's instruction (29 Sep 2026, third voice note):
- Keep the SBK Chat app as it is.
- Make a **new** app that shows the whole website, identical, with the live chat usable.
- Everything is managed from the website's database and admin panel.

So this app is one full-screen WebView of the website (Mohsin's Laravel site, `MohsinRaza2000/sbk-main`). It has no screens or data of its own, and a change on the website shows in the app at once.

| | |
|---|---|
| Android package | `com.sbkautotrading.app`, separate from SBK Chat's `com.sbkautotrading.chat`, so both install side by side |
| Name / icon | "SBK Global Auto Trading", with the same SBK mark as the other app |
| Permissions | internet; microphone, camera and audio settings (the chat's voice notes, calls and pictures) |

What `App.js` does, besides showing the site: the Back button goes back in the website; phone, e-mail, WhatsApp and map links open their own apps; the text size is fixed to the website's own.

## The address

The app opens `EXPO_PUBLIC_SITE_URL`, which is set when the app is built. The default is `https://sbkautotrading.com/`.
- **Now:** the website is not live yet, so test builds point at a temporary test link.
- **Final:** once the website is live, build with its real address.

## Building the APK (this machine: Node 24, JDK 21, Android SDK)

```
npm install
set EXPO_PUBLIC_SITE_URL=https://<the website>/
npx expo prebuild --platform android
# android/local.properties:  sdk.dir=C:/Users/ASFAR/AppData/Local/Android/Sdk
cd android
set JAVA_HOME=C:\Program Files\Android\Android Studio\jbr
gradlew :app:assembleRelease --no-daemon --max-workers=1 --console=plain -x lintVitalRelease -x lintVitalReportRelease -x lintVitalAnalyzeRelease
```

The APK lands in `android/app/build/outputs/apk/release/app-release.apk`. `plugins/withLowMemoryBuild.js` keeps the build within this 4 GB machine.
