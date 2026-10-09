-- ==============================================================================
-- Mona SMM Panel v2 - Unified Production Database Schema
-- Baseline Schema for Fresh Installations (MySQL 8+ / MariaDB 10.5+)
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `balance` DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
  `spent` DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
  `role` ENUM('user', 'admin') NOT NULL DEFAULT 'user',
  `status` ENUM('active', 'suspended', 'banned') NOT NULL DEFAULT 'active',
  `api_key` VARCHAR(64) NULL UNIQUE,
  `referral_code` VARCHAR(32) NULL UNIQUE,
  `referred_by` INT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `custom_rates` JSON NULL,
  `reset_token` VARCHAR(64) NULL,
  `reset_expires` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_status` (`status`),
  INDEX `idx_users_referred_by` (`referred_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Categories Table
CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `icon` VARCHAR(50) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_categories_status` (`status`),
  INDEX `idx_categories_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Providers Table (Upstream SMM APIs)
CREATE TABLE IF NOT EXISTS `providers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `api_url` VARCHAR(255) NOT NULL,
  `api_key` VARCHAR(255) NOT NULL,
  `balance` DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_providers_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Services Table
CREATE TABLE IF NOT EXISTS `services` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT NOT NULL,
  `provider_id` INT NULL,
  `provider_service_id` VARCHAR(50) NULL,
  `name` VARCHAR(255) NOT NULL,
  `type` VARCHAR(50) NOT NULL DEFAULT 'Default',
  `rate` DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
  `original_rate` DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
  `min_quantity` INT NOT NULL DEFAULT 10,
  `max_quantity` INT NOT NULL DEFAULT 10000,
  `description` TEXT NULL,
  `drip_feed` TINYINT(1) NOT NULL DEFAULT 0,
  `refill` TINYINT(1) NOT NULL DEFAULT 0,
  `cancel` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_services_category` (`category_id`),
  INDEX `idx_services_provider` (`provider_id`),
  INDEX `idx_services_status` (`status`),
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Orders Table
CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `service_id` INT NOT NULL,
  `provider_id` INT NULL,
  `provider_order_id` VARCHAR(100) NULL,
  `link` TEXT NOT NULL,
  `quantity` INT NOT NULL,
  `charge` DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
  `start_count` INT NOT NULL DEFAULT 0,
  `remains` INT NOT NULL DEFAULT 0,
  `status` ENUM('pending', 'processing', 'in_progress', 'completed', 'partial', 'canceled', 'refunded') NOT NULL DEFAULT 'pending',
  `error` TEXT NULL,
  `drip_feed_id` INT NULL,
  `refunded_amount` DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_orders_user` (`user_id`),
  INDEX `idx_orders_service` (`service_id`),
  INDEX `idx_orders_provider` (`provider_id`),
  INDEX `idx_orders_status` (`status`),
  INDEX `idx_orders_provider_order` (`provider_order_id`),
  INDEX `idx_orders_drip_feed` (`drip_feed_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Drip-Feed Orders Table (Standardized on runs_completed)
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
  INDEX `idx_drip_status` (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Transactions Ledger Table
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `type` ENUM('credit', 'debit', 'refund') NOT NULL,
  `amount` DECIMAL(15, 4) NOT NULL,
  `fee` DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `description` VARCHAR(255) NOT NULL,
  `reference_id` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_trans_user` (`user_id`),
  INDEX `idx_trans_type` (`type`),
  INDEX `idx_trans_ref` (`reference_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Payment Gateways Table (Configuration & Credentials)
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
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_gateways_status` (`status`),
  INDEX `idx_gateways_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Payments (Deposit Requests & Webhook Audits)
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
  INDEX `idx_payments_txid` (`transaction_id`),
  INDEX `idx_payments_gateway_ref` (`gateway_ref`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Payment Webhook Events (Deduplication & Idempotency)
CREATE TABLE IF NOT EXISTS `payment_webhook_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `gateway` VARCHAR(50) NOT NULL,
  `event_id` VARCHAR(191) NOT NULL UNIQUE,
  `transaction_id` VARCHAR(100) NULL,
  `payload` MEDIUMTEXT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'processed',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_webhook_gateway` (`gateway`),
  INDEX `idx_webhook_event_id` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Support Tickets Table
CREATE TABLE IF NOT EXISTS `tickets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `subject` VARCHAR(200) NOT NULL,
  `status` ENUM('open', 'answered', 'user_reply', 'closed') NOT NULL DEFAULT 'open',
  `priority` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_tickets_user` (`user_id`),
  INDEX `idx_tickets_status` (`status`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Ticket Messages Table
CREATE TABLE IF NOT EXISTS `ticket_messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `message` TEXT NOT NULL,
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ticket_msg_ticket` (`ticket_id`),
  FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Referrals Table
CREATE TABLE IF NOT EXISTS `referrals` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `referrer_id` INT NOT NULL,
  `referred_id` INT NOT NULL UNIQUE,
  `commission_rate` DECIMAL(5, 2) NOT NULL DEFAULT 5.00,
  `total_earned` DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_referrals_referrer` (`referrer_id`),
  FOREIGN KEY (`referrer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`referred_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Currencies Table
CREATE TABLE IF NOT EXISTS `currencies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(10) NOT NULL UNIQUE,
  `name` VARCHAR(50) NOT NULL,
  `symbol` VARCHAR(10) NOT NULL,
  `rate` DECIMAL(15, 6) NOT NULL DEFAULT 1.000000,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  INDEX `idx_curr_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Announcements Table
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `type` ENUM('info', 'warning', 'success', 'danger') NOT NULL DEFAULT 'info',
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_announcements_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. User Announcement Dismissals Table
CREATE TABLE IF NOT EXISTS `user_announcement_dismissals` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `announcement_id` INT NOT NULL,
  `dismissed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_user_announcement` (`user_id`, `announcement_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`announcement_id`) REFERENCES `announcements`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Settings Key-Value Table
CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
