# The WordPress side of the chat and the application

Two files that live on **sbkautotrading.com**, in `wp-content/mu-plugins` (must-use: WordPress
loads them without anybody enabling them).

    sbk-live-chat.php   the chat button on every page, the signed handover that carries a
                        signed-in customer into the chat without a second password, and the
                        tick box on a user's profile that puts a person on the desk or holds
                        them back
    sbk-app-api.php     the Android application's sign-in and sign-up against these same
                        website accounts

Neither file holds a password: they run inside WordPress and use its own database connection.

The chat itself is a separate code base; these two only open the door to it.
