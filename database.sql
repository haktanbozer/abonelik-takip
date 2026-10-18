-- ==============================================================================
-- Abonelik ve Fatura Takip Sistemi (Subscription & Recurring Bill Tracker)
-- Veritabanı Şeması ve Başlangıç Verileri (Database Schema & Seed Data)
-- Sürüm: v20_monthly_budget
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. users (Kullanıcılar)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `master_pin_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('ADMIN', 'USER') NOT NULL DEFAULT 'USER',
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `full_name` VARCHAR(100) NULL,
    `monthly_budget` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `password_changed_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. categories (Kategoriler)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `type` VARCHAR(50) NOT NULL DEFAULT 'general',
    `icon` VARCHAR(50) DEFAULT 'credit-card',
    `color` VARCHAR(30) DEFAULT 'blue',
    `sort_order` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. credit_cards (Kredi Kartları & Hesaplar)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `credit_cards` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `bank_name` VARCHAR(100) NOT NULL,
    `card_name` VARCHAR(100) NOT NULL,
    `card_network` ENUM('visa','mastercard','troy','amex') DEFAULT 'mastercard',
    `card_last_4` VARCHAR(4) NULL,
    `statement_cut_day` TINYINT(3) UNSIGNED NOT NULL,
    `due_day` TINYINT(3) UNSIGNED NOT NULL,
    `limit_amount` DECIMAL(12,2) DEFAULT NULL,
    `color` VARCHAR(50) DEFAULT 'from-slate-800 to-slate-950',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. payment_methods (Ödeme Yöntemleri)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_methods` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `title` VARCHAR(100) NOT NULL,
    `bank_name` VARCHAR(100) NULL,
    `card_type` ENUM('credit', 'debit', 'virtual', 'bank_transfer', 'other') DEFAULT 'bank_transfer',
    `card_last_4` VARCHAR(4) NULL,
    `is_auto_pay` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. locations (Mekanlar & Kapsamlar)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `locations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `icon` VARCHAR(50) DEFAULT 'home',
    `color` VARCHAR(30) DEFAULT 'blue',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. location_access (Mekan Erişim İzinleri)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `location_access` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `location_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `loc_user_unique` (`location_id`, `user_id`),
    INDEX (`location_id`),
    INDEX (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7. subscriptions (Merkezi Abonelik ve Fatura Tablosu)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscriptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `payer_user_id` INT NULL,
    `location_id` INT NULL,
    `category_id` INT NOT NULL,
    `payment_method_id` INT NULL,
    `credit_card_id` INT NULL,
    `is_auto_pay` TINYINT(1) DEFAULT 0,
    `auto_pay_type` ENUM('credit_card', 'bank_account', 'other') DEFAULT NULL,
    `title` VARCHAR(150) NOT NULL,
    `plan_tier` VARCHAR(100) NULL,
    `price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `cashback_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TRY',
    `billing_cycle` ENUM('monthly', 'annual', 'quarterly', 'semi_annual', 'one_time') DEFAULT 'monthly',
    `next_payment_date` DATE NULL,
    `status` ENUM('active', 'paused', 'canceled') NOT NULL DEFAULT 'active',
    `is_trial` TINYINT(1) DEFAULT 0,
    `trial_end_date` DATE NULL,
    `auto_renewal` TINYINT(1) DEFAULT 1,
    `icon` VARCHAR(50) DEFAULT NULL,
    `color` VARCHAR(30) DEFAULT NULL,
    `notes` TEXT NULL,
    `attachment_file` VARCHAR(255) NULL,
    `attachment_name` VARCHAR(255) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (`user_id`),
    INDEX (`payer_user_id`),
    INDEX (`location_id`),
    INDEX (`category_id`),
    INDEX (`payment_method_id`),
    INDEX (`credit_card_id`),
    INDEX (`status`),
    INDEX (`next_payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 8. subscription_access (Abonelik Görüntüleme & Yetkilendirme)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscription_access` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subscription_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `can_view_credentials` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `sub_user_unique` (`subscription_id`, `user_id`),
    INDEX (`subscription_id`),
    INDEX (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 9. subscription_digital_details (Dijital Platform & Şifreli Kasa)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscription_digital_details` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subscription_id` INT NOT NULL UNIQUE,
    `account_username` VARCHAR(255) NULL,
    `account_password_encrypted` TEXT NULL,
    `encryption_iv` VARCHAR(64) NULL,
    `website_url` VARCHAR(255) NULL,
    `profile_count` INT DEFAULT 1,
    `shared_with_count` INT DEFAULT 0,
    INDEX (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 10. subscription_utility_details (Ev & Fiziksel Faturalar)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscription_utility_details` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subscription_id` INT NOT NULL UNIQUE,
    `company_name` VARCHAR(150) NULL,
    `subscriber_no` VARCHAR(100) NULL,
    `meter_no` VARCHAR(100) NULL,
    `billing_cut_day` TINYINT UNSIGNED NULL,
    `due_day` TINYINT UNSIGNED NULL,
    `is_auto_pay` TINYINT(1) DEFAULT 0,
    `auto_pay_type` ENUM('credit_card', 'bank_account', 'other') DEFAULT 'credit_card',
    `auto_pay_bank` VARCHAR(100) NULL,
    `auto_pay_source_title` VARCHAR(150) NULL,
    `installation_no` VARCHAR(100) NULL,
    INDEX (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 11. subscription_telecom_details (İnternet & GSM Taahhüt Takibi)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscription_telecom_details` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subscription_id` INT NOT NULL UNIQUE,
    `operator_name` VARCHAR(100) NULL,
    `service_type` ENUM('mobile', 'fiber', 'vdsl', 'adsl', 'fixed_phone', 'tv_box') DEFAULT 'mobile',
    `phone_or_line_no` VARCHAR(50) NULL,
    `speed_or_quota` VARCHAR(100) NULL,
    `commitment_start` DATE NULL,
    `commitment_end` DATE NULL,
    `post_commitment_price` DECIMAL(10, 2) NULL,
    `device_rental_fee` DECIMAL(10, 2) DEFAULT 0.00,
    `is_auto_pay` TINYINT(1) DEFAULT 0,
    `auto_pay_type` ENUM('credit_card', 'bank_account', 'other') DEFAULT 'credit_card',
    `auto_pay_bank` VARCHAR(100) NULL,
    `auto_pay_source_title` VARCHAR(150) NULL,
    INDEX (`subscription_id`),
    INDEX (`commitment_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 12. subscription_insurance_details (Sigorta & Poliçe Yönetimi)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscription_insurance_details` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subscription_id` INT NOT NULL UNIQUE,
    `insurance_type` VARCHAR(100) DEFAULT 'tss',
    `company_name` VARCHAR(150) DEFAULT NULL,
    `policy_no` VARCHAR(100) DEFAULT NULL,
    `insured_item` VARCHAR(150) DEFAULT NULL,
    `has_agency` TINYINT(1) DEFAULT 0,
    `agency_name` VARCHAR(150) DEFAULT NULL,
    `agency_phone` VARCHAR(50) DEFAULT NULL,
    `policy_start_date` DATE DEFAULT NULL,
    `policy_end_date` DATE DEFAULT NULL,
    `payment_type` ENUM('single', 'installment') DEFAULT 'single',
    `installment_count` INT DEFAULT 1,
    `installment_amount` DECIMAL(10, 2) DEFAULT NULL,
    INDEX (`subscription_id`),
    INDEX (`policy_end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 13. subscription_bes_details (Bireysel Emeklilik - BES)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscription_bes_details` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subscription_id` INT NOT NULL UNIQUE,
    `company_name` VARCHAR(150) DEFAULT NULL,
    `contract_no` VARCHAR(100) DEFAULT NULL,
    `payment_day` TINYINT(3) UNSIGNED DEFAULT NULL,
    `owner_relation` VARCHAR(100) DEFAULT 'Kendim',
    `auto_pay_source` VARCHAR(150) DEFAULT NULL,
    `state_subsidy_rate` DECIMAL(5,2) DEFAULT 30.00,
    `fund_distribution` VARCHAR(255) DEFAULT NULL,
    INDEX (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 14. subscription_shares (Ortak Abonelik Masraf Bölüşümü)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscription_shares` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subscription_id` INT NOT NULL,
    `user_id` INT NULL,
    `person_name` VARCHAR(100) NOT NULL,
    `person_email` VARCHAR(100) NULL,
    `share_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `is_paid` TINYINT(1) DEFAULT 0,
    `last_paid_date` DATE NULL,
    `notes` VARCHAR(255) NULL,
    INDEX (`subscription_id`),
    INDEX (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 15. subscription_price_history (Fiyat Değişim & Zam Geçmişi)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscription_price_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subscription_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `old_price` DECIMAL(10, 2) NOT NULL,
    `new_price` DECIMAL(10, 2) NOT NULL,
    `percentage_change` DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TRY',
    `notes` VARCHAR(255) NULL,
    `changed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sph_sub` (`subscription_id`),
    INDEX `idx_sph_user` (`user_id`),
    INDEX `idx_sph_date` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 16. notification_settings (Bildirim Ayarları)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notification_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `email_enabled` TINYINT(1) DEFAULT 1,
    `admin_receive_all_notifications` TINYINT(1) DEFAULT 1,
    `notify_trial_days` VARCHAR(50) DEFAULT '3,1',
    `notify_commitment_days` VARCHAR(50) DEFAULT '30,15,3',
    `notify_due_days` VARCHAR(50) DEFAULT '3,1',
    `monthly_summary_enabled` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 17. notification_logs (Bildirim Kayıtları)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notification_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `subscription_id` INT NULL,
    `notification_type` ENUM('trial_end', 'commitment_end', 'payment_due', 'insurance_renewal', 'admin_copy_trial', 'admin_copy_comm', 'admin_copy_due', 'monthly_summary', 'system') NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `recipient_email` VARCHAR(150) NOT NULL,
    `status` ENUM('sent', 'failed') DEFAULT 'sent',
    `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (`user_id`),
    INDEX (`subscription_id`),
    INDEX (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 18. login_attempts & pin_attempts (Brute-Force Güvenlik Kilitleri)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `ip_address` VARCHAR(45) NOT NULL PRIMARY KEY,
    `username` VARCHAR(50) DEFAULT NULL,
    `attempts` INT DEFAULT 1,
    `last_attempt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `locked_until` DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pin_attempts` (
    `user_id` INT NOT NULL PRIMARY KEY,
    `attempts` INT DEFAULT 1,
    `last_attempt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `locked_until` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 19. category_types (Kategori Türleri Tanımları)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `category_types` (
    `type_key` VARCHAR(50) NOT NULL PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `icon` VARCHAR(10) DEFAULT '📌',
    `badge_color` VARCHAR(50) DEFAULT 'blue',
    `sort_order` INT DEFAULT 0,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 20. schema_migrations (Sürüm & Migration Takip Tablosu)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `version` VARCHAR(100) NOT NULL PRIMARY KEY,
    `applied_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- BAŞLANGIÇ VERİLERİ (SEED DATA)
-- ==============================================================================

-- Varsayılan Kategori Türleri
INSERT IGNORE INTO `category_types` (`type_key`, `name`, `icon`, `badge_color`, `sort_order`) VALUES
('digital', 'Dijital Abonelik', '💻', 'purple', 1),
('utility', 'Ev / Fiziksel Fatura', '⚡', 'yellow', 2),
('telecom', 'Ev / Telekom', '📡', 'cyan', 3),
('insurance', 'Sigorta & Poliçe', '🛡️', 'rose', 4),
('bes', 'Bireysel Emeklilik (BES & OKS)', '📈', 'emerald', 5),
('vehicle', 'Araç & Taşıt', '🚗', 'blue', 6),
('general', 'Genel Harcama', '📄', 'slate', 7),
('tax', 'Vergi & Harç', '💰', 'rose', 8);

-- Varsayılan Kategoriler
INSERT IGNORE INTO `categories` (`name`, `slug`, `type`, `icon`, `color`, `sort_order`) VALUES
('Dijital Dizi & Film', 'dijital-dizi-film', 'digital', 'film', 'red', 1),
('Müzik & Ses', 'muzik-ses', 'digital', 'music', 'green', 2),
('Bulut & Depolama', 'bulut-depolama', 'digital', 'cloud', 'blue', 3),
('Yazılım & SaaS', 'yazilim-saas', 'digital', 'cpu', 'purple', 4),
('E-Ticaret & Alışveriş', 'e-ticaret-alisveris', 'digital', 'shopping-bag', 'amber', 5),
('Oyun Servisleri', 'oyun-servisleri', 'digital', 'gamepad-2', 'indigo', 6),
('Ev Faturaları (Elektrik, Su, Gaz)', 'ev-faturalari', 'utility', 'zap', 'yellow', 7),
('Apartman / Site Aidatı', 'apartman-aidati', 'utility', 'home', 'orange', 8),
('Ev İnterneti & TV Platformu', 'ev-interneti', 'telecom', 'wifi', 'cyan', 9),
('Mobil Hat & GSM', 'mobil-hat', 'telecom', 'smartphone', 'emerald', 10),
('Tamamlayıcı Sağlık Sigortası (TSS)', 'tamamlayici-saglik-sigortasi', 'insurance', 'activity', 'emerald', 11),
('Kasko & Trafik Sigortası', 'kasko-trafik-sigortasi', 'insurance', 'shield', 'blue', 12),
('Bireysel Emeklilik (BES)', 'bireysel-emeklilik-bes', 'bes', 'trending-up', 'cyan', 13),
('Araç Sigorta & MTV', 'arac-sigorta-mtv', 'vehicle', 'car', 'rose', 14),
('Spor & Kişisel Bakım', 'spor-kisisel-bakim', 'general', 'activity', 'teal', 15);

-- Migration Kayıtları (v1..v20 Tam Eşleşme)
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES
('v1_roles'), ('v2_locations'), ('v3_payer_permissions'), ('v4_autopay'),
('v5_cards_insurance_bes'), ('v6_attachments'), ('v7_schema_sync'),
('v8_final_sync'), ('v9_security_sync'), ('v10_session_invalidation'),
('v11_location_access'), ('v12_shares_userid'), ('v13_cashback'),
('v14_insurance_installments'), ('v15_category_types'),
('v16_insurance_location_sync'), ('v17_card_color_fix'),
('v18_categories_type_varchar_and_tax'), ('v19_price_history'),
('v20_monthly_budget');

SET FOREIGN_KEY_CHECKS = 1;
