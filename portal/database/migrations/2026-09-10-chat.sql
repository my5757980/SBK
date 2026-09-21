-- SBK Chat — the tables
--
-- They live in the AUCTION database on purpose. The owner's requirement is that
-- the chat's staff list is the website's staff list: only people who already
-- have a login on the website appear, and nobody else. Sharing the database
-- means `chat_threads.staff_id` points straight at `admins.id` - one set of
-- accounts, one set of roles, one password to change when somebody leaves.
--
-- Run once:
--   mysql -u <user> -p thelyfas_sbkauction < database/migrations/2026-09-10-chat.sql

-- ---------------------------------------------------------------- the visitor
-- No password and no account: three boxes on the way in, and a token in a
-- cookie so the same person coming back lands in the same conversation.
CREATE TABLE IF NOT EXISTS `chat_guests` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `token`      char(40)     NOT NULL COMMENT 'What the browser holds; the whole of their identity',
  `name`       varchar(120) NOT NULL,
  `email`      varchar(160) NOT NULL,
  `phone`      varchar(40)  NOT NULL,
  `ip`         varchar(45)  DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp    NOT NULL DEFAULT current_timestamp(),
  `last_seen`  datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_email` (`email`),
  KEY `idx_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='People who walked in from the website';

-- ------------------------------------------------------------- one conversation
-- A visitor talking to one member of staff. A visitor who writes to two people
-- has two threads, exactly as they would in any messenger.
CREATE TABLE IF NOT EXISTS `chat_threads` (
  `id`              int(11)   NOT NULL AUTO_INCREMENT,
  `guest_id`        int(11)   NOT NULL,
  `staff_id`        int(11)   NOT NULL COMMENT 'admins.id - the website account',
  `created_at`      timestamp NOT NULL DEFAULT current_timestamp(),
  `last_message_at` datetime  DEFAULT NULL,
  `last_preview`    varchar(160) DEFAULT NULL COMMENT 'So a thread list costs one query',
  `guest_unread`    int(11)   NOT NULL DEFAULT 0,
  `staff_unread`    int(11)   NOT NULL DEFAULT 0,
  `closed_at`       datetime  DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pair` (`guest_id`, `staff_id`),
  KEY `idx_staff` (`staff_id`, `last_message_at`),
  KEY `idx_guest` (`guest_id`, `last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One visitor to one member of staff';

-- ------------------------------------------------------------------ a message
-- `kind` says what to draw. Text keeps its words in `body`; a picture or a voice
-- note keeps a path and leaves `body` for the caption.
--
-- The two ticks are two columns, not one status word: a message is delivered
-- when the other end has fetched it and read when the other end has the thread
-- open. Both are moments, and knowing WHEN is worth more than knowing THAT.
CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id`           bigint(20)  NOT NULL AUTO_INCREMENT,
  `thread_id`    int(11)     NOT NULL,
  `from_guest`   tinyint(1)  NOT NULL COMMENT '1 the visitor wrote it, 0 the staff did',
  `staff_id`     int(11)     DEFAULT NULL COMMENT 'Who, when staff wrote it',
  `kind`         varchar(10) NOT NULL DEFAULT 'text' COMMENT 'text | image | voice | file',
  `body`         text        DEFAULT NULL,
  `media_path`   varchar(255) DEFAULT NULL,
  `media_mime`   varchar(80) DEFAULT NULL,
  `media_bytes`  int(11)     DEFAULT NULL,
  `media_secs`   int(11)     DEFAULT NULL COMMENT 'How long a voice note runs',
  `created_at`   datetime    NOT NULL DEFAULT current_timestamp(),
  `delivered_at` datetime    DEFAULT NULL,
  `read_at`      datetime    DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_thread` (`thread_id`, `id`),
  KEY `idx_undelivered` (`thread_id`, `delivered_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Every message, both directions';

-- ------------------------------------------------------------------- presence
-- Who is at their desk right now. A row is stamped while somebody has the chat
-- open - or, for staff, while they have the admin panel open, which is what the
-- owner asked for: online means signed in to the website.
CREATE TABLE IF NOT EXISTS `chat_presence` (
  `who`       varchar(20) NOT NULL COMMENT 'staff | guest',
  `who_id`    int(11)     NOT NULL,
  `last_ping` datetime    NOT NULL,
  `where_at`  varchar(40) DEFAULT NULL COMMENT 'chat | panel - only for reading the logs',
  PRIMARY KEY (`who`, `who_id`),
  KEY `idx_ping` (`last_ping`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Stamped every few seconds by whoever has a window open';
