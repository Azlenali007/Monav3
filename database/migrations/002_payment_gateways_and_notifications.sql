-- ==============================================================================
-- Migration 002: Payment Gateways, Payments, Announcements, and Referrals
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `payment_gateways` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(50) NOT NULL UNIQUE,
  `type` ENUM('fiat', 'crypto', 'manual') NOT NULL DEFAULT 'fiat',
  `credentials` TEXT NULL,
  `fee_percentage` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
  `min_amount` DECIMAL(15, 2) NOT NULL DEFAULT 1.00,
  `max_amount` DECIMAL(15, 2) NOT NULL DEFAULT 10000.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `environment` ENUM('sandbox', 'live') NOT NULL DEFAULT 'live',
  `instructions` TEXT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `payment_method` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(15, 4) NOT NULL,
  `fee` DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
  `net_amount` DECIMAL(15, 4) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `transaction_id` VARCHAR(100) NOT NULL UNIQUE,
  `gateway_ref` VARCHAR(191) NULL,
  `status` ENUM('pending', 'completed', 'failed', 'canceled', 'expired', 'refunded') NOT NULL DEFAULT 'pending',
  `audit_data` JSON NULL,
  `credited_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_payments_user` (`user_id`),
  INDEX `idx_payments_status` (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `type` ENUM('info', 'warning', 'success', 'danger') NOT NULL DEFAULT 'info',
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_announcement_dismissals` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `announcement_id` INT NOT NULL,
  `dismissed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_user_announcement` (`user_id`, `announcement_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`announcement_id`) REFERENCES `announcements`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `referrals` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `referrer_id` INT NOT NULL,
  `referred_id` INT NOT NULL UNIQUE,
  `commission_rate` DECIMAL(5, 2) NOT NULL DEFAULT 5.00,
  `total_earned` DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`referrer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`referred_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Safely add referral fields to users if missing
SET @col_ref := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'referral_code');
SET @stmt_ref := IF(@col_ref = 0, 'ALTER TABLE `users` ADD COLUMN `referral_code` VARCHAR(32) NULL UNIQUE AFTER `api_key`, ADD COLUMN `referred_by` INT NULL AFTER `referral_code`', 'SELECT 1');
PREPARE run_ref FROM @stmt_ref;
EXECUTE run_ref;
DEALLOCATE PREPARE run_ref;
