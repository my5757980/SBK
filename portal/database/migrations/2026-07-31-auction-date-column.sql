-- SBK Auction — a real DATE for the auction day
--
-- The feed gives the auction date as a dd.mm.yyyy string, so "is this lot still
-- to come?" meant STR_TO_DATE() on every row of every query — a full scan of
-- 100k+ rows for the list, and again for each facet.
--
-- A PERSISTENT generated column keeps the parsed date in step automatically
-- (nothing in sync.py has to maintain it) and can carry an index.
--
-- Run once:
--   mysql -u <user> -p <database> < database/migrations/2026-07-31-auction-date-column.sql

ALTER TABLE `cars`
  ADD COLUMN `auction_on` DATE
    GENERATED ALWAYS AS (STR_TO_DATE(`auction_date`, '%d.%m.%Y')) PERSISTENT
    COMMENT 'auction_date parsed, for indexed date comparisons';

-- Customers only ever see lots that are still to be auctioned, so this pair is
-- the hot path for every list, facet and count.
ALTER TABLE `cars` ADD KEY `idx_auction_on` (`auction_on`);
ALTER TABLE `cars` ADD KEY `idx_status_auction_on` (`status`, `auction_on`);

-- Each filter panel facet is a GROUP BY over that same set. Ordering the column
-- being grouped BEFORE the date range lets the group run off the index instead
-- of building a temporary table from row lookups, and makes each one covering.
-- Measured on the 106k-row local copy: the five facets together went from
-- 2,608 ms to 460 ms, and a make-filtered list from 617 ms to 27 ms.
ALTER TABLE `cars` ADD KEY `idx_f_chassis` (`status`, `chassis`, `auction_on`);
ALTER TABLE `cars` ADD KEY `idx_f_rating`  (`status`, `rating`, `auction_on`);
ALTER TABLE `cars` ADD KEY `idx_f_color`   (`status`, `color`, `auction_on`);
ALTER TABLE `cars` ADD KEY `idx_f_trans`   (`status`, `transmission`, `auction_on`);
ALTER TABLE `cars` ADD KEY `idx_f_make`    (`status`, `make`, `auction_on`);
