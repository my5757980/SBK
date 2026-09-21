
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `name` varchar(120) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cars`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cars` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `car_id` varchar(50) NOT NULL COMMENT 'Unique car identifier from source',
  `lot_no` varchar(100) DEFAULT NULL COMMENT 'Auction lot number',
  `make` varchar(100) NOT NULL COMMENT 'Car manufacturer (e.g., Toyota)',
  `model` varchar(100) NOT NULL COMMENT 'Car model (e.g., Camry)',
  `year` int(11) DEFAULT NULL COMMENT 'Manufacturing year',
  `mileage` int(11) DEFAULT NULL COMMENT 'Odometer reading in kilometers',
  `price` decimal(12,2) DEFAULT NULL COMMENT 'Auction price in currency',
  `currency` varchar(10) DEFAULT 'JPY' COMMENT 'Price currency (JPY, USD, etc)',
  `images` longtext DEFAULT NULL COMMENT 'JSON array of image URLs: [{"url":"...", "order":1}, ...]',
  `auction_sheet` varchar(500) DEFAULT NULL COMMENT 'URL to auction sheet PDF',
  `status` varchar(50) DEFAULT 'active' COMMENT 'Status: active, sold, reserved, withdrawn',
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `auction` varchar(100) DEFAULT NULL,
  `auction_date` varchar(20) DEFAULT NULL,
  `chassis` varchar(100) DEFAULT NULL,
  `transmission` varchar(30) DEFAULT NULL,
  `grade` varchar(150) DEFAULT NULL,
  `rating` varchar(20) DEFAULT NULL,
  `engine_cc` int(11) DEFAULT NULL,
  `avg_price` decimal(12,2) DEFAULT NULL,
  `color` varchar(60) DEFAULT NULL,
  `source_url` varchar(255) DEFAULT NULL,
  `auction_time` varchar(20) DEFAULT NULL,
  `engine_hp` varchar(20) DEFAULT NULL,
  `equipment` varchar(120) DEFAULT NULL,
  `load_capacity` varchar(40) DEFAULT NULL,
  `sold_price` decimal(12,2) DEFAULT NULL,
  `price_history` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `car_id` (`car_id`),
  UNIQUE KEY `uq_car_id` (`car_id`),
  KEY `idx_car_id` (`car_id`),
  KEY `idx_status` (`status`),
  KEY `idx_make_model` (`make`,`model`),
  KEY `idx_year` (`year`),
  KEY `idx_price` (`price`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_last_updated` (`last_updated`),
  FULLTEXT KEY `ft_make_model` (`make`,`model`)
) ENGINE=InnoDB AUTO_INCREMENT=251291 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Car listings and inventory';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'Client full name',
  `email` varchar(100) NOT NULL COMMENT 'Unique email address',
  `phone` varchar(20) DEFAULT NULL COMMENT 'Contact phone number',
  `password_hash` varchar(255) NOT NULL COMMENT 'Hashed password (use bcrypt)',
  `password_salt` varchar(255) DEFAULT NULL COMMENT 'Password salt for hashing',
  `address` text DEFAULT NULL COMMENT 'Physical/delivery address',
  `city` varchar(50) DEFAULT NULL COMMENT 'City',
  `state` varchar(50) DEFAULT NULL COMMENT 'State/Province',
  `postal_code` varchar(20) DEFAULT NULL COMMENT 'Postal/ZIP code',
  `country` varchar(50) DEFAULT NULL COMMENT 'Country',
  `phone_verified` tinyint(1) DEFAULT 0 COMMENT 'Whether phone is verified',
  `email_verified` tinyint(1) DEFAULT 0 COMMENT 'Whether email is verified',
  `is_active` tinyint(1) DEFAULT 1 COMMENT 'Account active status',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_email` (`email`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `ck_email_format` CHECK (`email` like '%@%.%')
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Client/user accounts';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inquiries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inquiries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inquiry_number` varchar(50) NOT NULL COMMENT 'Human-readable inquiry ID',
  `client_id` int(11) NOT NULL COMMENT 'Reference to clients table',
  `car_id` varchar(50) NOT NULL COMMENT 'Reference to cars.car_id',
  `make` varchar(100) DEFAULT NULL COMMENT 'Car make (denormalized for reporting)',
  `model` varchar(100) DEFAULT NULL COMMENT 'Car model (denormalized for reporting)',
  `message` text NOT NULL COMMENT 'Client inquiry/question',
  `response` text DEFAULT NULL COMMENT 'Response from support team',
  `status` varchar(50) DEFAULT 'open' COMMENT 'Status: open, in_progress, responded, closed',
  `priority` varchar(20) DEFAULT 'normal' COMMENT 'Priority: low, normal, high, urgent',
  `assigned_to` int(11) DEFAULT NULL COMMENT 'Staff member ID handling inquiry',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `responded_at` datetime DEFAULT NULL COMMENT 'When response was sent',
  `closed_at` datetime DEFAULT NULL COMMENT 'When inquiry was closed',
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `inquiry_number` (`inquiry_number`),
  KEY `idx_inquiry_number` (`inquiry_number`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_car_id` (`car_id`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_responded_at` (`responded_at`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_status_priority` (`status`,`priority`),
  KEY `idx_client_created` (`client_id`,`created_at`),
  KEY `idx_fk_client_id` (`client_id`),
  CONSTRAINT `fk_inquiries_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Customer inquiries and support tickets';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL COMMENT 'Human-readable order ID (e.g., ORD-2026-001)',
  `client_id` int(11) NOT NULL COMMENT 'Reference to clients table',
  `car_id` varchar(50) NOT NULL COMMENT 'Reference to cars.car_id',
  `car_details` longtext DEFAULT NULL COMMENT 'JSON snapshot of car data at purchase time',
  `make` varchar(100) DEFAULT NULL COMMENT 'Car make (denormalized for reporting)',
  `model` varchar(100) DEFAULT NULL COMMENT 'Car model (denormalized for reporting)',
  `year` int(11) DEFAULT NULL COMMENT 'Car year (denormalized for reporting)',
  `amount` decimal(12,2) NOT NULL COMMENT 'Final purchase price',
  `currency` varchar(10) DEFAULT 'JPY' COMMENT 'Currency of transaction',
  `status` varchar(50) DEFAULT 'pending' COMMENT 'Status: pending, confirmed, paid, shipped, delivered, cancelled',
  `payment_method` varchar(50) DEFAULT NULL COMMENT 'Payment method: card, bank_transfer, cash',
  `payment_date` datetime DEFAULT NULL COMMENT 'When payment was received',
  `shipping_date` datetime DEFAULT NULL COMMENT 'When car was shipped',
  `delivery_date` datetime DEFAULT NULL COMMENT 'When car was delivered',
  `notes` text DEFAULT NULL COMMENT 'Order notes/special instructions',
  `order_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `idx_order_number` (`order_number`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_car_id` (`car_id`),
  KEY `idx_status` (`status`),
  KEY `idx_order_date` (`order_date`),
  KEY `idx_payment_date` (`payment_date`),
  KEY `idx_updated_at` (`updated_at`),
  KEY `idx_client_date` (`client_id`,`order_date`),
  KEY `idx_fk_client_id` (`client_id`),
  CONSTRAINT `fk_orders_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `ck_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Purchase orders and transactions';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `v_active_cars`;
/*!50001 DROP VIEW IF EXISTS `v_active_cars`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_active_cars` AS SELECT
 1 AS `id`,
  1 AS `car_id`,
  1 AS `lot_no`,
  1 AS `make`,
  1 AS `model`,
  1 AS `year`,
  1 AS `mileage`,
  1 AS `price`,
  1 AS `currency`,
  1 AS `status`,
  1 AS `auction_sheet`,
  1 AS `created_at`,
  1 AS `last_updated` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_client_orders`;
/*!50001 DROP VIEW IF EXISTS `v_client_orders`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_client_orders` AS SELECT
 1 AS `id`,
  1 AS `order_number`,
  1 AS `client_id`,
  1 AS `name`,
  1 AS `email`,
  1 AS `car_id`,
  1 AS `make`,
  1 AS `model`,
  1 AS `year`,
  1 AS `amount`,
  1 AS `currency`,
  1 AS `status`,
  1 AS `order_date`,
  1 AS `updated_at` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_open_inquiries`;
/*!50001 DROP VIEW IF EXISTS `v_open_inquiries`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_open_inquiries` AS SELECT
 1 AS `id`,
  1 AS `inquiry_number`,
  1 AS `client_id`,
  1 AS `name`,
  1 AS `email`,
  1 AS `car_id`,
  1 AS `make`,
  1 AS `model`,
  1 AS `status`,
  1 AS `priority`,
  1 AS `message`,
  1 AS `response`,
  1 AS `created_at`,
  1 AS `responded_at` */;
SET character_set_client = @saved_cs_client;
/*!50001 DROP VIEW IF EXISTS `v_active_cars`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_active_cars` AS select `cars`.`id` AS `id`,`cars`.`car_id` AS `car_id`,`cars`.`lot_no` AS `lot_no`,`cars`.`make` AS `make`,`cars`.`model` AS `model`,`cars`.`year` AS `year`,`cars`.`mileage` AS `mileage`,`cars`.`price` AS `price`,`cars`.`currency` AS `currency`,`cars`.`status` AS `status`,`cars`.`auction_sheet` AS `auction_sheet`,`cars`.`created_at` AS `created_at`,`cars`.`last_updated` AS `last_updated` from `cars` where `cars`.`status` = 'active' order by `cars`.`last_updated` desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_client_orders`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_client_orders` AS select `o`.`id` AS `id`,`o`.`order_number` AS `order_number`,`c`.`id` AS `client_id`,`c`.`name` AS `name`,`c`.`email` AS `email`,`o`.`car_id` AS `car_id`,`o`.`make` AS `make`,`o`.`model` AS `model`,`o`.`year` AS `year`,`o`.`amount` AS `amount`,`o`.`currency` AS `currency`,`o`.`status` AS `status`,`o`.`order_date` AS `order_date`,`o`.`updated_at` AS `updated_at` from (`orders` `o` join `clients` `c` on(`o`.`client_id` = `c`.`id`)) order by `o`.`order_date` desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_open_inquiries`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_open_inquiries` AS select `i`.`id` AS `id`,`i`.`inquiry_number` AS `inquiry_number`,`c`.`id` AS `client_id`,`c`.`name` AS `name`,`c`.`email` AS `email`,`i`.`car_id` AS `car_id`,`i`.`make` AS `make`,`i`.`model` AS `model`,`i`.`status` AS `status`,`i`.`priority` AS `priority`,`i`.`message` AS `message`,`i`.`response` AS `response`,`i`.`created_at` AS `created_at`,`i`.`responded_at` AS `responded_at` from (`inquiries` `i` join `clients` `c` on(`i`.`client_id` = `c`.`id`)) where `i`.`status` <> 'closed' order by case `i`.`priority` when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 when 'low' then 4 end,`i`.`created_at` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- ---------------------------------------------------------------------------
-- Who did what.
--
-- Nothing recorded which staff member acted. The `assigned_to` column on
-- inquiries below was in the schema from the start and no code ever wrote to
-- it, so "which of my people answered this customer" had no answer at all -
-- and the Overview could say how many bids existed but not who had touched one.
--
-- Written from the same places that decide whether an action is allowed, so
-- the log records exactly what the permission check let through.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `staff_activity` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `staff_id`   int(11)      NOT NULL COMMENT 'admins.id',
  `action`     varchar(40)  NOT NULL COMMENT 'bid.status, enquiry.reply, order.status, …',
  `subject`    varchar(120) DEFAULT NULL COMMENT 'what it was done to, for reading back',
  `subject_id` int(11)      DEFAULT NULL,
  `at`         datetime     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_staff` (`staff_id`),
  KEY `idx_at` (`at`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
