-- ==============================================================================
-- Migration 001: Initial Core Schema
-- ==============================================================================

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
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `custom_rates` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `icon` VARCHAR(50) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `providers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `api_url` VARCHAR(255) NOT NULL,
  `api_key` VARCHAR(255) NOT NULL,
  `balance` DECIMAL(15, 4) NOT NULL DEFAULT 0.0000,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tickets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `subject` VARCHAR(200) NOT NULL,
  `status` ENUM('open', 'answered', 'user_reply', 'closed') NOT NULL DEFAULT 'open',
  `priority` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `message` TEXT NOT NULL,
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `currencies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(10) NOT NULL UNIQUE,
  `name` VARCHAR(50) NOT NULL,
  `symbol` VARCHAR(10) NOT NULL,
  `rate` DECIMAL(15, 6) NOT NULL DEFAULT 1.000000,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `status` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
