-- SBK Chat — moving the chat from the auction portal to the WEBSITE
--
-- The owner's instruction, 2026-09-10, in his own words: the chat belongs to
-- sbkautotrading.com and not to the auction; only staff and agents appear in
-- the list a customer chooses from; accounts are made on the website; and a
-- customer who is already signed in there walks straight into the conversation.
--
-- WHAT CHANGES IN THE DATA
--
--   chat_threads.staff_id   was `admins.id`  (the auction portal's staff table)
--                           is  `wp_users.ID` (the website's own accounts)
--   chat_messages.staff_id  the same
--   chat_guests.wp_user_id  NEW — the website account of a signed-in customer,
--                           NULL for somebody who walked in off the street.
--
-- There is nothing to convert. Every conversation was cleared on the owner's
-- instruction earlier the same day, so the tables are empty and the columns
-- simply mean something new from here on. Had they not been, this file would
-- have carried a mapping instead — do not run it on a database with rows in
-- chat_threads without one.
--
-- Run once:
--   mysql -u <user> -p <auction db> < 2026-09-10-chat-on-the-website.sql

-- The website account of a customer who is signed in, if they are.
--
-- Nullable and not unique-by-default on purpose: most rows will never have one,
-- because somebody with no account at all is still a customer with a name and a
-- conversation. The unique index is on the column only where it is set.
ALTER TABLE `chat_guests`
  ADD COLUMN `wp_user_id` int(11) DEFAULT NULL
      COMMENT 'wp_users.ID when this person is signed in to the website'
      AFTER `token`,
  ADD UNIQUE KEY `uq_wp_user` (`wp_user_id`);

-- The comments, so the next person to read the schema is told the truth.
ALTER TABLE `chat_threads`
  MODIFY `staff_id` int(11) NOT NULL
      COMMENT 'wp_users.ID - the website account of the member of staff';

ALTER TABLE `chat_messages`
  MODIFY `staff_id` int(11) DEFAULT NULL
      COMMENT 'wp_users.ID, when a member of staff wrote it';
