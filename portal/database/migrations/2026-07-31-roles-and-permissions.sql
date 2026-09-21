-- SBK Auction — staff roles and permissions
--
-- The owner's model: admin is the main role, the customer has its own login,
-- and in-house sales agents need a third. Agents sign in through the same staff
-- door as the admin; what they may do is decided by their role's permissions,
-- which the admin ticks on and off.
--
-- Permission KEYS live in PHP (permissionCatalogue()) so they are versioned with
-- the code that enforces them; only the grants live here.
--
-- Run once:
--   mysql -u <user> -p <database> < database/migrations/2026-07-31-roles-and-permissions.sql

CREATE TABLE IF NOT EXISTS `roles` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `name`       varchar(40)  NOT NULL COMMENT 'stable key, e.g. admin / agent',
  `label`      varchar(80)  NOT NULL COMMENT 'what the admin panel shows',
  `is_system`  tinyint(1)   NOT NULL DEFAULT 0 COMMENT '1 = cannot be deleted or stripped',
  `created_at` timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Staff roles';

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id`    int(11)     NOT NULL,
  `permission` varchar(60) NOT NULL COMMENT 'key from permissionCatalogue()',
  PRIMARY KEY (`role_id`, `permission`),
  KEY `idx_permission` (`permission`),
  CONSTRAINT `fk_roleperm_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Which permissions each role grants';

-- Staff accounts gain a role and a contact address. Existing rows are admins.
ALTER TABLE `admins` ADD COLUMN `role_id` int(11) DEFAULT NULL AFTER `username`;
ALTER TABLE `admins` ADD COLUMN `email` varchar(120) DEFAULT NULL AFTER `name`;
ALTER TABLE `admins` ADD KEY `idx_role` (`role_id`);

-- The two roles the owner described.
INSERT INTO `roles` (`name`, `label`, `is_system`) VALUES
  ('admin', 'Administrator', 1),
  ('agent', 'Sales agent',   0)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);

-- Everyone already in the table is an administrator.
UPDATE `admins` SET `role_id` = (SELECT id FROM roles WHERE name = 'admin')
 WHERE `role_id` IS NULL;

-- A sensible starting point for agents: they work the desk — see the stock and
-- the customer traffic, answer enquiries, move bids along — but they do not see
-- the sold archive or manage other staff. The admin can change any of this from
-- the Roles screen.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, p.perm FROM `roles` r JOIN (
  SELECT 'portal.view'     AS perm UNION ALL
  SELECT 'vehicles.view'   UNION ALL
  SELECT 'bids.view'       UNION ALL
  SELECT 'bids.manage'     UNION ALL
  SELECT 'enquiries.view'  UNION ALL
  SELECT 'enquiries.reply' UNION ALL
  SELECT 'orders.view'     UNION ALL
  SELECT 'clients.view'
) p WHERE r.name = 'agent';
