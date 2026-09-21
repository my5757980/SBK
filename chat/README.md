# SBK Live Chat

The chat that runs on **chat.sbkautotrading.com** and, through the same code, serves the
Android application on **chat-application.sbkautotrading.com**. Both addresses run this one
copy: the application's domain holds only small files that `require` these.

## What is here

    chat.php            the chat itself (the desk and the customer see the same page)
    enter.php           the handover from the website - a signed ticket, never a password
    staff-login.php     the desk's own door
    api/                send, poll, upload, calls (WebRTC), groups, push, presence
    includes/lib.php    everything the two sides share
    includes/config.php the database, and where the website's own database is read from
    assets/             the page's stylesheet and its JavaScript

## Where it gets its data

- **Its own tables** (`chat_guests`, `chat_messages`, `chat_groups`, `chat_push`) live in the
  auction portal's database, so one database holds the portal, the chat and the application.
- **Who the people are** is read from the WordPress website's database - read only. The chat
  never writes to the shop's accounts.

## The WordPress side

Two files belong to the website rather than here and are deployed into
`wp-content/mu-plugins`: `sbk-live-chat.php` (the chat's button, the sign-in handover and the
tick box on a user's profile) and `sbk-app-api.php` (the application's sign-in and sign-up).

## Running it

Nothing to build. PHP 8 and MySQL; the credentials come from the server, never from the code.
