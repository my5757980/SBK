# Tasks: The website as an app

- [x] T001 Set up the project: Expo 57 / RN 0.86 (the same toolchain as SBK Chat), package `com.sbkautotrading.app`, the SBK icons, and the low-memory build plugin.
- [x] T002 `App.js`: a full-screen WebView of `EXPO_PUBLIC_SITE_URL`, Back in the website, external schemes to their apps, chat media permissions, and a fixed text size.
- [x] T003 Phone-width audit of the website: 20 pages x 5 widths, with no sideways scroll and no element past the edge.
- [x] T004 The one real phone fault: the hero badge hid the car's name on phones. Fixed in `sbk-main` (branch `phone-hero-badge`), checked at 5 widths.
- [x] T005 Test link: a demo copy of the website (test data only, CRM off, debug off) behind a temporary Cloudflare link. Chat checked over it on a phone-sized browser: 3/3.
- [x] T006 Build the APK against the test link (31m59s, 37 MB), and check inside it: package com.sbkautotrading.app, label, only the needed permissions (Expo's SYSTEM_ALERT_WINDOW and VIBRATE blocked), arm64 + armeabi-v7a, the test link in the bundle, and the debug-key signature.
- [ ] T007 The owner installs it on a phone and checks the pages, chat, pictures, voice and calls.
- [ ] T008 Once the website is live: build with the real address (a JS-only rebuild, a few minutes).
