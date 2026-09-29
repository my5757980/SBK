# Feature Specification: The website as an app

**Created**: 2026-09-29 | **Status**: In progress
**Input**: Sir's third voice note, 29 Sep 2026: "Leave the mobile application you've already made as it is. Create a new application in which the entire website comes in automatically. Don't make it fetch separately. The whole UI should be exactly the same as our website. It should be fully mobile responsive; if anything needs to be adjusted according to mobile, do it accordingly. From there, the user should also be able to use the chat. Everything will be managed from my database and from my admin panel."
**Owner's rule**: exactly what Sir said, nothing added.

## Requirements (1:1 with Sir's words)

| # | Sir said | Done as |
|---|---|---|
| 1 | Leave the existing app as it is | A new project and a new Android package (`com.sbkautotrading.app`). SBK Chat (`com.sbkautotrading.chat`) is not touched. |
| 2 | A new app where the entire website comes in automatically; nothing fetched separately | One full-screen WebView of the website |
| 3 | UI exactly the same as the website | It IS the website: no screens, styles or data of its own |
| 4 | Fully mobile responsive; adjust what needs it | Phone-width audit of the website. The fixes go into the website itself (`sbk-main`). |
| 5 | The user can use the chat | Microphone, camera and file picking work inside the app (voice notes, calls, pictures) |
| 6 | Managed from Sir's database and admin panel | It follows from 2-3 |

## Needed for the website to work in an app (not new features)

- The phone's Back button goes back in the website. Otherwise the first press closes the app.
- tel:, mailto:, WhatsApp and intent: links open their own apps, as they do from a browser.
- Text is not re-scaled by the phone's font setting, so every page looks exactly as the website draws it.

## Not done (not asked)

An offline screen, a loading bar, pull-to-refresh, a remote address switch, push notifications, and anything with its own look.

## Success Criteria

- **SC-001**: The APK installs beside SBK Chat and opens the website.
- **SC-002**: The website's pages show no sideways scroll and hide no text at 320-768 px.
- **SC-003**: The chat works on a phone: send a message, the agent is told, pictures, voice notes, calls.
