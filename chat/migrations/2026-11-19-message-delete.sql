-- Message delete ("delete for everyone") — adds soft-delete support so a person
-- can delete their own message. Run once on the live auction/chat database
-- before deploying the code that uses it. Safe to leave the row in place: the
-- text and media are wiped by the app and the media file removed from disk.
--
-- MySQL/MariaDB (this account is MariaDB, which supports IF NOT EXISTS):
ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL;
