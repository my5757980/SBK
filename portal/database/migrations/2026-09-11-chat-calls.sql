-- SBK Chat — voice and video calls
--
-- The owner asked for calling on 2026-09-10 and then said to leave it; on
-- 2026-09-11 he asked for it again, and for it to cost nothing.
--
-- HOW A CALL TRAVELS. The sound and the picture go straight from one browser to
-- the other (WebRTC) and never touch this server — which is why a call cannot
-- make the website hang, and why this table holds no media at all. What it
-- holds is the handshake: the caller's "offer", the answer, and where the call
-- is up to. Both browsers poll for it the way they already poll for messages.
--
-- One row per call. A call ends in exactly one of:
--   ended      answered, and then hung up by either side
--   declined   the person rang pressed Decline
--   cancelled  the caller gave up before it was answered
--   missed     nobody answered within the ring time
--   failed     answered, but the two browsers could not reach each other
--
-- Run once:
--   mysql -u <user> -p <auction db> < 2026-09-11-chat-calls.sql

CREATE TABLE IF NOT EXISTS `chat_calls` (
  `id`          int(11)     NOT NULL AUTO_INCREMENT,
  `thread_id`   int(11)     NOT NULL,
  `from_guest`  tinyint(1)  NOT NULL COMMENT '1 the customer rang, 0 the member of staff did',
  `kind`        varchar(8)  NOT NULL DEFAULT 'voice' COMMENT 'voice | video',
  `state`       varchar(12) NOT NULL DEFAULT 'ringing'
                COMMENT 'ringing | accepted | ended | declined | cancelled | missed | failed',
  `offer`       mediumtext  DEFAULT NULL COMMENT "The caller's session description",
  `answer`      mediumtext  DEFAULT NULL COMMENT "The answerer's session description",
  `created_at`  datetime    NOT NULL DEFAULT current_timestamp(),
  `answered_at` datetime    DEFAULT NULL,
  `ended_at`    datetime    DEFAULT NULL,
  `guest_seen`  datetime    DEFAULT NULL COMMENT 'Last time the customer''s browser asked about this call',
  `staff_seen`  datetime    DEFAULT NULL COMMENT 'Last time the staff browser asked about this call',
  PRIMARY KEY (`id`),
  KEY `idx_thread` (`thread_id`, `id`),
  KEY `idx_live` (`state`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='The handshake of each call; the call itself never passes through here';
