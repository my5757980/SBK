-- SBK Auction — bids and sync bookkeeping
--
-- Run once on any database created from an older schema.sql:
--   mysql -u <user> -p <database> < database/migrations/2026-07-30-bids-and-sync-state.sql
--
-- Both tables must use utf8mb4_unicode_ci: `bids.car_id` is joined against
-- `cars.car_id`, and MySQL refuses to compare columns whose collations differ
-- ("Illegal mix of collations").

CREATE TABLE IF NOT EXISTS `bids` (
  `id`          int(11)        NOT NULL AUTO_INCREMENT,
  `bid_number`  varchar(40)    NOT NULL COMMENT 'Human-readable bid ID',
  `client_id`   int(11)        NOT NULL COMMENT 'Reference to clients.id',
  `car_id`      varchar(50)    NOT NULL COMMENT 'Reference to cars.car_id',
  `make`        varchar(100)   DEFAULT NULL COMMENT 'Denormalised for reporting',
  `model`       varchar(100)   DEFAULT NULL COMMENT 'Denormalised for reporting',
  `year`        int(11)        DEFAULT NULL,
  `lot_no`      varchar(100)   DEFAULT NULL,
  `amount`      decimal(12,2)  NOT NULL COMMENT 'What the client bid',
  `currency`    varchar(10)    NOT NULL DEFAULT 'yen',
  `min_allowed` decimal(12,2)  DEFAULT NULL COMMENT 'Window the bid was checked against',
  `max_allowed` decimal(12,2)  DEFAULT NULL,
  `status`      varchar(30)    NOT NULL DEFAULT 'placed'
                COMMENT 'placed, under review, accepted, rejected, won, lost',
  `admin_note`  text           DEFAULT NULL,
  `placed_at`   timestamp      NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime       DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bid_number` (`bid_number`),
  KEY `idx_client` (`client_id`),
  KEY `idx_car` (`car_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Client bids awaiting the auction desk';

-- Where the rolling sweep left off, so no make goes stale between runs.
CREATE TABLE IF NOT EXISTS `sync_state` (
  `k`          varchar(40)  NOT NULL,
  `v`          varchar(255) NOT NULL,
  `updated_at` timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sync cursor for sync.py';

-- Repair a `bids` table created before this file existed.
ALTER TABLE `bids` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Indexes the auction list relies on for its filters.
-- (Ignore "Duplicate key name" — it means the index is already there.)
ALTER TABLE `cars` ADD KEY `idx_chassis` (`chassis`);
ALTER TABLE `cars` ADD KEY `idx_rating`  (`rating`);
ALTER TABLE `cars` ADD KEY `idx_color`   (`color`);
ALTER TABLE `cars` ADD KEY `idx_trans`   (`transmission`);
ALTER TABLE `cars` ADD KEY `idx_mileage` (`mileage`);
