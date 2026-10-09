-- ==============================================================================
-- Migration 004: Payment Gateways, Webhook Idempotency, and Critical Bug Fixes
-- Safe, Non-Destructive Migration for Existing Installations
-- ==============================================================================

-- 1. Ensure payment_webhook_events exists for webhook deduplication & idempotency
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

-- 2. Ensure payment_gateways table exists
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

-- 3. Safely add missing columns to payments table if not existing
SET @col_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'gateway_ref');
SET @stmt := IF(@col_exist = 0, 'ALTER TABLE `payments` ADD COLUMN `gateway_ref` VARCHAR(191) NULL AFTER `transaction_id`, ADD INDEX `idx_payments_gateway_ref` (`gateway_ref`)', 'SELECT 1');
PREPARE run_stmt FROM @stmt;
EXECUTE run_stmt;
DEALLOCATE PREPARE run_stmt;

SET @col_exist2 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'audit_data');
SET @stmt2 := IF(@col_exist2 = 0, 'ALTER TABLE `payments` ADD COLUMN `audit_data` JSON NULL AFTER `status`', 'SELECT 1');
PREPARE run_stmt2 FROM @stmt2;
EXECUTE run_stmt2;
DEALLOCATE PREPARE run_stmt2;

SET @col_exist3 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'credited_at');
SET @stmt3 := IF(@col_exist3 = 0, 'ALTER TABLE `payments` ADD COLUMN `credited_at` DATETIME NULL AFTER `audit_data`', 'SELECT 1');
PREPARE run_stmt3 FROM @stmt3;
EXECUTE run_stmt3;
DEALLOCATE PREPARE run_stmt3;

-- 4. Safely add refunded_amount to orders table to prevent duplicate/excess refunds
SET @col_exist4 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'refunded_amount');
SET @stmt4 := IF(@col_exist4 = 0, 'ALTER TABLE `orders` ADD COLUMN `refunded_amount` DECIMAL(15, 4) NOT NULL DEFAULT 0.0000 AFTER `drip_feed_id`', 'SELECT 1');
PREPARE run_stmt4 FROM @stmt4;
EXECUTE run_stmt4;
DEALLOCATE PREPARE run_stmt4;

-- 5. Safely standardize drip_feed_orders column name if total_runs_completed exists
SET @old_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drip_feed_orders' AND COLUMN_NAME = 'total_runs_completed');
SET @stmt5 := IF(@old_col > 0, 'ALTER TABLE `drip_feed_orders` CHANGE `total_runs_completed` `runs_completed` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE run_stmt5 FROM @stmt5;
EXECUTE run_stmt5;
DEALLOCATE PREPARE run_stmt5;

-- 6. Insert all 12 supported payment gateways with default inactive status if not existing
INSERT IGNORE INTO `payment_gateways` (`name`, `code`, `type`, `fee_percentage`, `min_amount`, `max_amount`, `currency`, `environment`, `status`) VALUES
('Razorpay', 'razorpay', 'fiat', 2.00, 10.00, 100000.00, 'INR', 'sandbox', 0),
('Cashfree Payments', 'cashfree', 'fiat', 2.00, 10.00, 100000.00, 'INR', 'sandbox', 0),
('PhonePe Payment Gateway', 'phonepe', 'fiat', 0.00, 10.00, 100000.00, 'INR', 'sandbox', 0),
('PayU', 'payu', 'fiat', 2.00, 10.00, 100000.00, 'INR', 'sandbox', 0),
('PayPal Checkout', 'paypal', 'fiat', 3.50, 5.00, 5000.00, 'USD', 'sandbox', 0),
('Stripe Checkout', 'stripe', 'fiat', 2.90, 5.00, 5000.00, 'USD', 'sandbox', 0),
('Verifone / 2Checkout', 'twocheckout', 'fiat', 3.50, 10.00, 5000.00, 'USD', 'sandbox', 0),
('Cryptomus', 'cryptomus', 'crypto', 0.00, 5.00, 50000.00, 'USD', 'live', 0),
('NOWPayments', 'nowpayments', 'crypto', 0.50, 5.00, 50000.00, 'USD', 'sandbox', 0),
('CoinPayments', 'coinpayments', 'crypto', 0.50, 5.00, 50000.00, 'USD', 'live', 0),
('Binance Pay', 'binancepay', 'crypto', 0.00, 5.00, 50000.00, 'USDT', 'sandbox', 0),
('Manual Bank / UPI Transfer', 'manual', 'manual', 0.00, 5.00, 100000.00, 'USD', 'live', 1);
