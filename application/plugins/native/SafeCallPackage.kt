package com.sbkautotrading.chat

import com.facebook.react.BaseReactPackage
import com.facebook.react.bridge.NativeModule
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.module.model.ReactModuleInfo
import com.facebook.react.module.model.ReactModuleInfoProvider
import com.facebook.react.uimanager.ViewManager

/**
 * The call libraries' native modules, built only when a call needs them.
 *
 * react-native-webrtc and react-native-incall-manager ship old-style packages
 * (a plain ReactPackage). React Native 0.86 builds every module of such a
 * package the moment the app starts (ReactPackageTurboModuleManagerDelegate
 * calls createNativeModules() while it is being set up) - so WebRTC's whole
 * engine (PeerConnectionFactory, the EGL context, the audio device) was being
 * started on every launch, whether or not anybody ever made a call. If that
 * cannot start on a phone, the phone cannot open the app at all.
 *
 * Served from a BaseReactPackage instead, the same module is created only the
 * first time JavaScript asks for it (getLegacyModule -> getModule), i.e. when
 * a call is actually made. And if it throws, the error is reported and null
 * is returned: the app stays open, and src/calls.js turns "module not found"
 * into "Calls are not available on this phone" for that one call.
 *
 * MainApplication removes the two original packages from the autolinked list
 * and adds this one in their place (see plugins/withSafeNative.js).
 */
class SafeCallPackage : BaseReactPackage() {

  override fun getModule(name: String, reactContext: ReactApplicationContext): NativeModule? {
    return try {
      when (name) {
        WEBRTC -> com.oney.WebRTCModule.WebRTCModule(reactContext)
        INCALL -> com.zxcpoiu.incallmanager.InCallManagerModule(reactContext)
        else -> null
      }
    } catch (t: Throwable) {
      SbkCrash.report("native module " + name + " could not start", t)
      null
    }
  }

  override fun getReactModuleInfoProvider(): ReactModuleInfoProvider {
    val infos: MutableMap<String, ReactModuleInfo> = HashMap()
    // name, class, canOverrideExistingModule, needsEagerInit, isCxxModule, isTurboModule
    infos[WEBRTC] = ReactModuleInfo(WEBRTC, "com.oney.WebRTCModule.WebRTCModule", false, false, false, false)
    infos[INCALL] = ReactModuleInfo(INCALL, "com.zxcpoiu.incallmanager.InCallManagerModule", false, false, false, false)
    return ReactModuleInfoProvider { infos }
  }

  // The video surface is only a view class - building it starts nothing.
  override fun createViewManagers(reactContext: ReactApplicationContext): List<ViewManager<*, *>> {
    return listOf<ViewManager<*, *>>(com.oney.WebRTCModule.RTCVideoViewManager())
  }

  companion object {
    const val WEBRTC = "WebRTCModule"
    const val INCALL = "InCallManager"
  }
}
