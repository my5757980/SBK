-- SBK Auction — tag every vehicle with the section it came from
--
-- The source publishes three sections and they are not the same kind of thing:
--
--   japan          lots going to auction    - customers bid on these
--   oneprice_mix   fixed-price stock        - bought at the listed price,
--                                             there is no bidding
--   japan_st       past auction results     - reference data, staff only
--
-- Without this column they would all land in `cars` indistinguishable, and a
-- customer would see fixed-price stock in the auction list and try to bid on
-- something that has no auction. Existing rows are all auction lots.
--
-- Run once:
--   mysql -u <user> -p <database> < database/migrations/2026-08-01-source-section.sql

ALTER TABLE `cars`
  ADD COLUMN `source_section` varchar(20) NOT NULL DEFAULT 'japan'
    COMMENT 'which section of the feed this came from: japan | oneprice_mix';

-- Everything already stored came from the auctions.
UPDATE `cars` SET `source_section` = 'japan' WHERE `source_section` = '';

-- Every customer-facing query filters on this alongside status and date, so it
-- leads the index the same way status does.
ALTER TABLE `cars` ADD KEY `idx_section_status_date`
  (`source_section`, `status`, `auction_on`);
