-- SBK Auction — past auction results (the feed's SALES STATISTICS section)
--
-- Why this is a table of its own and not more rows in `cars`:
--
--   * it is an order of magnitude bigger - 2.23 million records against
--     143,000 - so mixing it in would make every customer query pay for data
--     no customer is ever shown
--   * it is a different thing. `cars` holds vehicles that are for sale now;
--     this holds what a vehicle sold for on a day that has passed. The same
--     chassis can appear here many times over the years
--   * the owner's rule is that results are staff-only, and a separate table
--     makes that impossible to get wrong by accident
--
-- It is also how a lot's outcome finally gets answered. When an auction
-- concludes the feed moves the lot out of the auctions section and into this
-- one, which is why ~17,000 lots sit in `cars` marked available with a date
-- that has passed: their result was never in the section we were reading.
--
-- Run once:
--   mysql -u <user> -p <database> < database/migrations/2026-08-01-auction-results.sql

CREATE TABLE IF NOT EXISTS `auction_results` (
  `id`           bigint(20)   NOT NULL AUTO_INCREMENT,
  `result_key`   varchar(80)  NOT NULL COMMENT 'the feed key for this result row',
  `car_id`       varchar(50)  DEFAULT NULL COMMENT 'matches cars.car_id when the lot is one we held',
  `lot_no`       varchar(100) DEFAULT NULL,
  `make`         varchar(100) DEFAULT NULL,
  `model`        varchar(100) DEFAULT NULL,
  `year`         int(11)      DEFAULT NULL,
  `mileage`      int(11)      DEFAULT NULL,
  `engine_cc`    int(11)      DEFAULT NULL,
  `transmission` varchar(40)  DEFAULT NULL,
  `grade`        varchar(120) DEFAULT NULL,
  `rating`       varchar(20)  DEFAULT NULL COMMENT 'inspection grade',
  `color`        varchar(40)  DEFAULT NULL,
  `chassis`      varchar(60)  DEFAULT NULL,
  `auction`      varchar(120) DEFAULT NULL COMMENT 'auction house',
  `auction_date` varchar(20)  DEFAULT NULL COMMENT 'as the feed gives it, dd.mm.yyyy',
  `auction_on`   date         DEFAULT NULL COMMENT 'auction_date parsed, for indexed comparisons',
  `start_price`  decimal(12,2) NOT NULL DEFAULT 0.00,
  `sold_price`   decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'the hammer price',
  `result`       varchar(30)  DEFAULT NULL COMMENT 'sold / not sold / sold by nego',
  `images`       text         DEFAULT NULL,
  `imported_at`  timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `result_key` (`result_key`),
  KEY `idx_car_id` (`car_id`),
  KEY `idx_chassis` (`chassis`),
  KEY `idx_make_model` (`make`, `model`),
  KEY `idx_auction_on` (`auction_on`),
  KEY `idx_lot` (`lot_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Past auction results from the feed statistics section';
