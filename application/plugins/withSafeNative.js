/* The two native pieces that keep a bad call library from closing the app,
   and that tell us why, if anything still does.
   -------------------------------------------------------------------------
   `npx expo prebuild --clean` writes android/ from scratch, so hand edits to
   MainApplication.kt would vanish on the next build. This plugin puts them
   back every time:

     plugins/native/SbkCrash.kt          the Android-level crash reporter
     plugins/native/SafeCallPackage.kt   WebRTC + InCallManager, built on first use

   and changes MainApplication.kt in two places:

     attachBaseContext()   SbkCrash.install(this) - the first thing the app
                           runs, before content providers and React Native
     packageList           the autolinked WebRTCModulePackage and
                           InCallManagerPackage (which React Native 0.86 builds
                           at start-up) are taken out, SafeCallPackage goes in

   Each change looks for the exact line it expects and stops the build with a
   clear message if the template has moved - an edit that silently did not
   happen is how "the fix is in" turns out to be untrue. */
const fs = require('fs');
const path = require('path');
const { withDangerousMod, withMainApplication } = require('expo/config-plugins');

const FILES = ['SbkCrash.kt', 'SafeCallPackage.kt'];
const MARK = 'sbk-safe-native';

function mustReplace(src, anchor, replacement, what) {
  if (!src.includes(anchor)) {
    throw new Error('withSafeNative: could not find ' + what + ' in MainApplication.kt ("' + anchor + '")');
  }
  return src.replace(anchor, replacement);
}

module.exports = function withSafeNative(config) {
  config = withDangerousMod(config, [
    'android',
    async (c) => {
      const pkg = c.android && c.android.package;
      if (!pkg) throw new Error('withSafeNative: android.package is not set in app.json');
      const dir = path.join(c.modRequest.platformProjectRoot, 'app', 'src', 'main', 'java', ...pkg.split('.'));
      fs.mkdirSync(dir, { recursive: true });
      for (const f of FILES) {
        fs.copyFileSync(path.join(__dirname, 'native', f), path.join(dir, f));
      }
      return c;
    },
  ]);

  config = withMainApplication(config, (c) => {
    let src = c.modResults.contents;
    if (src.includes(MARK)) return c;

    src = mustReplace(
      src,
      'import android.app.Application\n',
      'import android.app.Application\nimport android.content.Context\n',
      'the Application import',
    );

    src = mustReplace(
      src,
      'class MainApplication : Application(), ReactApplication {\n',
      'class MainApplication : Application(), ReactApplication {\n' +
        '\n' +
        '  // ' + MARK + ': the crash reporter goes in before anything else runs\n' +
        '  override fun attachBaseContext(base: Context) {\n' +
        '    super.attachBaseContext(base)\n' +
        '    SbkCrash.install(this)\n' +
        '  }\n',
      'the MainApplication class line',
    );

    src = mustReplace(
      src,
      '// add(MyReactNativePackage())',
      '// add(MyReactNativePackage())\n' +
        '          // ' + MARK + ': the call libraries are built on first use, not at start-up\n' +
        '          removeAll {\n' +
        '            it.javaClass.name == "com.oney.WebRTCModule.WebRTCModulePackage" ||\n' +
        '              it.javaClass.name == "com.zxcpoiu.incallmanager.InCallManagerPackage"\n' +
        '          }\n' +
        '          add(SafeCallPackage())',
      'the packageList block',
    );

    c.modResults.contents = src;
    return c;
  });

  return config;
};
