/* Build the APK on a machine with 4 GB of memory.
   -------------------------------------------------------------------------
   The build machine has 3.9 GB of RAM in total. Twice on 2026-09-18 the build
   was stopped for low memory: Gradle's own JVM (1.5-2 GB), a SECOND JVM for the
   Kotlin compiler, and six C++ compilers at once (~180 MB each - ninja starts
   one per CPU plus two) do not fit beside a browser.

   `npx expo prebuild --clean` throws the android/ folder away and writes it
   again, so settings typed into android/gradle.properties by hand vanish every
   time - which is exactly what happened. They live here instead, and prebuild
   puts them back itself:

     one worker, no parallel projects       one task's memory at a time
     Kotlin compiled inside Gradle's JVM     no second JVM
     at most TWO C++ compiles at a time      CMake job pools, every native module
     arm64-v8a + armeabi-v7a only            every real Android phone; the two
                                             x86 builds only ever ran on emulators,
                                             and they doubled the C++ work

   Nothing here changes what the app does - only how much of the computer
   building it is used at once. */
const { withGradleProperties, withProjectBuildGradle } = require('expo/config-plugins');

const PROPS = {
  'org.gradle.jvmargs': '-Xmx1536m -XX:MaxMetaspaceSize=384m -XX:+UseParallelGC',
  'org.gradle.parallel': 'false',
  'org.gradle.workers.max': '1',
  'kotlin.compiler.execution.strategy': 'in-process',
  reactNativeArchitectures: 'armeabi-v7a,arm64-v8a',
};

const MARK = '// sbk-low-memory-build';
const BLOCK = `
${MARK}
// Every native module's CMake build gets the same two job pools, so ninja
// runs at most two compilers and one linker at once instead of one per CPU.
// Registered BEFORE the React Native root plugin below: that plugin evaluates
// :app on the spot (evaluationDependsOn), and a hook added after it would be
// too late for :app. plugins.withId fires the moment AGP is applied inside
// each module - before its own android {} block, whose arguments append.
subprojects { sp ->
  def cap = {
    def a = sp.extensions.findByName('android')
    if (a != null) {
      a.defaultConfig.externalNativeBuild.cmake.arguments(
        '-DCMAKE_JOB_POOLS=sbk_cc=2;sbk_ld=1',
        '-DCMAKE_JOB_POOL_COMPILE=sbk_cc',
        '-DCMAKE_JOB_POOL_LINK=sbk_ld')
    }
  }
  sp.plugins.withId('com.android.library') { cap() }
  sp.plugins.withId('com.android.application') { cap() }
}

`;

module.exports = function withLowMemoryBuild(config) {
  config = withGradleProperties(config, (c) => {
    for (const [key, value] of Object.entries(PROPS)) {
      const at = c.modResults.findIndex((p) => p.type === 'property' && p.key === key);
      if (at >= 0) c.modResults[at].value = value;
      else c.modResults.push({ type: 'property', key, value });
    }
    return c;
  });
  config = withProjectBuildGradle(config, (c) => {
    const src = c.modResults.contents;
    const at = src.indexOf('apply plugin: "expo-root-project"');
    if (!src.includes(MARK) && at >= 0) {
      c.modResults.contents = src.slice(0, at) + BLOCK.trimStart() + src.slice(at);
    }
    return c;
  });
  return config;
};
