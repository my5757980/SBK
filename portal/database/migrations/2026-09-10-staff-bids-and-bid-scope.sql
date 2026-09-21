-- SBK Auction — staff may bid, and a bid remembers who placed it
--
-- Until now a bid could only come from a customer: `bids.client_id` was NOT
-- NULL and there was nowhere to record a member of staff. The owner's
-- instruction is that everyone bids - customers, staff and the administrator -
-- and that whoever places a bid sees only their own.
--
-- Both halves need the same thing: a bid has to know whose it is. So one of the
-- two columns is set and the other is null, and every screen reads the pair.
--
--   customer bid : client_id = <id>, staff_id = NULL
--   staff bid    : client_id = NULL, staff_id = <id>
--
-- Run once:
--   mysql -u <user> -p <database> < database/migrations/2026-09-10-staff-bids-and-bid-scope.sql
--
-- The application runs the same three statements through a guarded routine, so
-- running this by hand afterwards is safe: each is written to be a no-op when
-- it has already been applied.

ALTER TABLE `bids`
  ADD COLUMN IF NOT EXISTS `staff_id` INT(11) NULL DEFAULT NULL
    COMMENT 'Which member of staff placed it; NULL for a customer bid'
    AFTER `client_id`;

ALTER TABLE `bids`
  MODIFY COLUMN `client_id` INT(11) NULL DEFAULT NULL
    COMMENT 'Reference to clients.id; NULL when a member of staff placed the bid';

ALTER TABLE `bids`
  ADD KEY IF NOT EXISTS `idx_staff` (`staff_id`);
