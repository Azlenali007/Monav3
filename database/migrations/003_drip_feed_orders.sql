-- ==============================================================================
-- Migration 003: Drip-Feed Orders
-- Standardizes on runs_completed column
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `drip_feed_orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `service_id` INT NOT NULL,
  `link` TEXT NOT NULL,
  `total_quantity` INT NOT NULL,
  `quantity_per_run` INT NOT NULL,
  `runs` INT NOT NULL,
  `interval_minutes` INT NOT NULL,
  `runs_completed` INT NOT NULL DEFAULT 0,
  `total_charge` DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
  `status` ENUM('active', 'completed', 'canceled') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_drip_user` (`user_id`),
  INDEX `idx_drip_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Safely add drip_feed_id to orders if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'drip_feed_id');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `orders` ADD COLUMN `drip_feed_id` INT NULL DEFAULT NULL AFTER `error`', 'SELECT "Column drip_feed_id already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
