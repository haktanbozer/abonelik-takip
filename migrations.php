<?php
/**
 * Abonelik ve Fatura Takip Sistemi - Merkezi Şema & Migration Yöneticisi
 * Bu dosya yalnızca veritabanında henüz uygulanmamış yeni bir güncelleme olduğunda çalışır.
 * Her sayfa açılışında veritabanını yormaz, schema_migrations tablosu üzerinden takip eder.
 */

if (!defined('HEADER_INCLUDED') && !isset($pdo)) {
    // Doğrudan web erişimini engelle
    http_response_code(403);
    die('Doğrudan erişim engellendi.');
}

function semaGuncelle(PDO $pdo) {
    $currentVersion = 20;

    // Oturumda zaten doğrulanmışsa sıfır sorguyla hemen dön
    if (!empty($_SESSION['_abonelik_schema_version']) && $_SESSION['_abonelik_schema_version'] >= $currentVersion) {
        return;
    }

    try {
        // 1. Migration takip tablosu yoksa oluştur
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `version` VARCHAR(100) NOT NULL PRIMARY KEY,
                `applied_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 2. Uygulanmış versiyonları al
        $stmtApplied = $pdo->query("SELECT `version` FROM `schema_migrations`");
        $appliedList = $stmtApplied ? $stmtApplied->fetchAll(PDO::FETCH_COLUMN) : [];
        $appliedMap = array_flip($appliedList);

        // Canlı sistem tespiti: schema_migrations boşsa ama tablolar zaten varsa, v1..v17'yi topluca kaydet
        if (empty($appliedList)) {
            $checkTable = $pdo->query("SHOW TABLES LIKE 'credit_cards'")->fetch();
            if ($checkTable) {
                // Tablolar zaten kurulu ve güncel
                $pdo->exec("
                    INSERT IGNORE INTO `schema_migrations` (`version`) VALUES 
                    ('v1_roles'), ('v2_locations'), ('v3_payer_permissions'), ('v4_autopay'),
                    ('v5_cards_insurance_bes'), ('v6_attachments'), ('v7_schema_sync'),
                    ('v8_final_sync'), ('v9_security_sync'), ('v10_session_invalidation'),
                    ('v11_location_access'), ('v12_shares_userid'), ('v13_cashback'),
                    ('v14_insurance_installments'), ('v15_category_types'),
                    ('v16_insurance_location_sync'), ('v17_card_color_fix')
                ");
                $_SESSION['_abonelik_schema_version'] = $currentVersion;
                return;
            }
        }

        // Yardımcı fonksiyon: Sütun var mı kontrolü
        $sutunVarMi = function($tablo, $sutun) use ($pdo) {
            try {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$tablo}` LIKE ?");
                $stmt->execute([$sutun]);
                return (bool)$stmt->fetch();
            } catch (Exception $e) {
                return false;
            }
        };

        // 3. Tanımlı Migration Listesi
        $migrations = [
            'v1_roles' => function($pdo) {
                try {
                    $pdo->exec("ALTER TABLE `users` ADD COLUMN `role` ENUM('ADMIN', 'USER') NOT NULL DEFAULT 'USER' AFTER `master_pin_hash`");
                } catch (Exception $e) {}
                try {
                    $pdo->exec("ALTER TABLE `users` ADD COLUMN `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER `role`");
                } catch (Exception $e) {}
            },
            'v2_locations' => function($pdo) {
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `locations` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `user_id` INT NOT NULL,
                            `name` VARCHAR(100) NOT NULL,
                            `icon` VARCHAR(50) DEFAULT 'home',
                            `color` VARCHAR(30) DEFAULT 'blue',
                            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                            INDEX (`user_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                    $pdo->exec("ALTER TABLE `subscriptions` ADD COLUMN `location_id` INT NULL AFTER `user_id`");
                } catch (Exception $e) {}
            },
            'v3_payer_permissions' => function($pdo) {
                try {
                    $pdo->exec("ALTER TABLE `subscriptions` ADD COLUMN `payer_user_id` INT NULL AFTER `user_id`");
                    $pdo->exec("UPDATE `subscriptions` SET `payer_user_id` = `user_id` WHERE `payer_user_id` IS NULL");
                } catch (Exception $e) {}
                try {
                    $pdo->exec("
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
                    ");
                } catch (Exception $e) {}
                try {
                    $pdo->exec("ALTER TABLE `notification_settings` ADD COLUMN `admin_receive_all_notifications` TINYINT(1) DEFAULT 1 AFTER `email_enabled`");
                } catch (Exception $e) {}
            },
            'v4_autopay' => function($pdo) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_utility_details` 
                        ADD COLUMN `is_auto_pay` TINYINT(1) DEFAULT 0 AFTER `due_day`,
                        ADD COLUMN `auto_pay_type` ENUM('credit_card', 'bank_account', 'other') DEFAULT 'credit_card' AFTER `is_auto_pay`,
                        ADD COLUMN `auto_pay_bank` VARCHAR(100) NULL AFTER `auto_pay_type`,
                        ADD COLUMN `auto_pay_source_title` VARCHAR(150) NULL AFTER `auto_pay_bank`");
                } catch (Exception $e) {}
                try {
                    $pdo->exec("ALTER TABLE `subscription_telecom_details` 
                        ADD COLUMN `is_auto_pay` TINYINT(1) DEFAULT 0 AFTER `device_rental_fee`,
                        ADD COLUMN `auto_pay_type` ENUM('credit_card', 'bank_account', 'other') DEFAULT 'credit_card' AFTER `is_auto_pay`,
                        ADD COLUMN `auto_pay_bank` VARCHAR(100) NULL AFTER `auto_pay_type`,
                        ADD COLUMN `auto_pay_source_title` VARCHAR(150) NULL AFTER `auto_pay_bank`");
                } catch (Exception $e) {}
            },
            'v5_cards_insurance_bes' => function($pdo) {
                try {
                    $pdo->exec("ALTER TABLE `categories` MODIFY COLUMN `type` ENUM('digital', 'utility', 'telecom', 'vehicle', 'general', 'insurance', 'bes') NOT NULL DEFAULT 'digital'");
                    $newCategories = [
                        ['Kasko & Araç Sigortası', 'kasko-arac-sigortasi', 'insurance', 'shield', 'rose', 13],
                        ['DASK & Konut Sigortası', 'dask-konut-sigortasi', 'insurance', 'home', 'amber', 14],
                        ['Özel & Tamamlayıcı Sağlık', 'saglik-sigortasi', 'insurance', 'activity', 'emerald', 15],
                        ['Bireysel Emeklilik (BES)', 'bireysel-emeklilik-bes', 'bes', 'trending-up', 'cyan', 16]
                    ];
                    $catIns = $pdo->prepare("INSERT IGNORE INTO `categories` (`name`, `slug`, `type`, `icon`, `color`, `sort_order`) VALUES (?, ?, ?, ?, ?, ?)");
                    foreach ($newCategories as $nc) {
                        $catIns->execute($nc);
                    }
                } catch (Exception $e) {}
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `credit_cards` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `user_id` INT NOT NULL,
                            `bank_name` VARCHAR(100) NOT NULL,
                            `card_name` VARCHAR(100) NOT NULL,
                            `card_network` ENUM('visa', 'mastercard', 'troy', 'amex') DEFAULT 'mastercard',
                            `card_last_4` VARCHAR(4) NULL,
                            `statement_cut_day` TINYINT UNSIGNED NOT NULL,
                            `due_day` TINYINT UNSIGNED NOT NULL,
                            `limit_amount` DECIMAL(12, 2) NULL,
                            `color` VARCHAR(50) DEFAULT 'from-slate-800 to-slate-950',
                            `is_active` TINYINT(1) DEFAULT 1,
                            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            INDEX (`user_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } catch (Exception $e) {}
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `subscription_insurance_details` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `subscription_id` INT NOT NULL UNIQUE,
                            `insurance_type` VARCHAR(50) DEFAULT 'traffic',
                            `company_name` VARCHAR(150) NULL,
                            `policy_no` VARCHAR(100) NULL,
                            `insured_item` VARCHAR(150) NULL,
                            `agency_name` VARCHAR(150) NULL,
                            `agency_phone` VARCHAR(50) NULL,
                            `policy_start_date` DATE NULL,
                            `policy_end_date` DATE NULL,
                            `payment_type` ENUM('single', 'installment') DEFAULT 'single',
                            `installment_count` INT DEFAULT 1,
                            `installment_amount` DECIMAL(10, 2) NULL,
                            INDEX (`subscription_id`),
                            INDEX (`policy_end_date`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } catch (Exception $e) {}
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `subscription_bes_details` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `subscription_id` INT NOT NULL UNIQUE,
                            `company_name` VARCHAR(150) NULL,
                            `contract_no` VARCHAR(100) NULL,
                            `payment_day` TINYINT UNSIGNED NULL,
                            `owner_relation` VARCHAR(100) DEFAULT 'self',
                            `auto_pay_source` VARCHAR(150) NULL,
                            INDEX (`subscription_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } catch (Exception $e) {}
                try {
                    $pdo->exec("ALTER TABLE `subscriptions` ADD COLUMN `credit_card_id` INT NULL AFTER `payment_method_id`");
                } catch (Exception $e) {}
            },
            'v6_attachments' => function($pdo) {
                try {
                    $pdo->exec("ALTER TABLE `subscriptions` 
                        ADD COLUMN `attachment_file` VARCHAR(255) NULL AFTER `notes`,
                        ADD COLUMN `attachment_name` VARCHAR(255) NULL AFTER `attachment_file`");
                } catch (Exception $e) {}
            },
            'v7_schema_sync' => function($pdo) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_insurance_details` 
                        ADD COLUMN `insured_item` VARCHAR(150) NULL AFTER `policy_no`,
                        ADD COLUMN `payment_type` ENUM('single', 'installment') DEFAULT 'single' AFTER `policy_end_date`,
                        ADD COLUMN `installment_count` INT DEFAULT 1 AFTER `payment_type`,
                        ADD COLUMN `installment_amount` DECIMAL(10, 2) NULL AFTER `installment_count`");
                } catch (Exception $e) {}
                try {
                    $pdo->exec("ALTER TABLE `subscription_bes_details` 
                        ADD COLUMN `owner_relation` VARCHAR(100) DEFAULT 'Kendim' AFTER `payment_day`,
                        ADD COLUMN `auto_pay_source` VARCHAR(150) NULL AFTER `owner_relation`");
                } catch (Exception $e) {}
            },
            'v8_final_sync' => function($pdo) use ($sutunVarMi) {
                try {
                    $pdo->exec("ALTER TABLE `subscriptions` MODIFY COLUMN `billing_cycle` ENUM('monthly', 'annual', 'quarterly', 'semi_annual', 'one_time') DEFAULT 'monthly'");
                } catch (Exception $e) {}
                if (!$sutunVarMi('subscriptions', 'is_auto_pay')) {
                    try { $pdo->exec("ALTER TABLE `subscriptions` ADD COLUMN `is_auto_pay` TINYINT(1) DEFAULT 0 AFTER `credit_card_id`"); } catch (Exception $e) {}
                }
                if (!$sutunVarMi('subscriptions', 'auto_pay_type')) {
                    try { $pdo->exec("ALTER TABLE `subscriptions` ADD COLUMN `auto_pay_type` ENUM('credit_card', 'bank_account', 'other') DEFAULT NULL AFTER `is_auto_pay`"); } catch (Exception $e) {}
                }
                if (!$sutunVarMi('subscription_telecom_details', 'is_auto_pay')) {
                    try { $pdo->exec("ALTER TABLE `subscription_telecom_details` ADD COLUMN `is_auto_pay` TINYINT(1) DEFAULT 0 AFTER `device_rental_fee`"); } catch (Exception $e) {}
                }
                if (!$sutunVarMi('subscription_utility_details', 'is_auto_pay')) {
                    try { $pdo->exec("ALTER TABLE `subscription_utility_details` ADD COLUMN `is_auto_pay` TINYINT(1) DEFAULT 0 AFTER `due_day`"); } catch (Exception $e) {}
                }
            },
            'v9_security_sync' => function($pdo) {
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `pin_attempts` (
                            `user_id` INT NOT NULL PRIMARY KEY,
                            `attempts` INT DEFAULT 1,
                            `last_attempt` DATETIME DEFAULT CURRENT_TIMESTAMP,
                            `locked_until` DATETIME NULL
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } catch (Exception $e) {}
                try {
                    $pdo->exec("
                        ALTER TABLE `notification_logs` MODIFY COLUMN `notification_type` 
                        ENUM('trial_end','commitment_end','payment_due','insurance_renewal','admin_copy_trial','admin_copy_comm','admin_copy_due','monthly_summary','system') NOT NULL
                    ");
                } catch (Exception $e) {}
            },
            'v10_session_invalidation' => function($pdo) use ($sutunVarMi) {
                if (!$sutunVarMi('users', 'password_changed_at')) {
                    try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `password_changed_at` DATETIME NULL AFTER `updated_at`"); } catch (Exception $e) {}
                }
            },
            'v11_location_access' => function($pdo) {
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `location_access` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `location_id` INT NOT NULL,
                            `user_id` INT NOT NULL,
                            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                            UNIQUE KEY `loc_user_unique` (`location_id`, `user_id`),
                            INDEX (`location_id`),
                            INDEX (`user_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } catch (Exception $e) {}
            },
            'v12_shares_userid' => function($pdo) use ($sutunVarMi) {
                if (!$sutunVarMi('subscription_shares', 'user_id')) {
                    try { $pdo->exec("ALTER TABLE `subscription_shares` ADD COLUMN `user_id` INT NULL AFTER `subscription_id`, ADD KEY `user_id` (`user_id`)"); } catch (Exception $e) {}
                }
            },
            'v13_cashback' => function($pdo) use ($sutunVarMi) {
                if (!$sutunVarMi('subscriptions', 'cashback_amount')) {
                    try { $pdo->exec("ALTER TABLE `subscriptions` ADD COLUMN `cashback_amount` DECIMAL(10,2) DEFAULT 0.00 AFTER `price`"); } catch (Exception $e) {}
                }
            },
            'v14_insurance_installments' => function($pdo) {
                try {
                    $pdo->exec("
                        UPDATE subscriptions s
                        JOIN categories c ON s.category_id = c.id
                        JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
                        SET s.billing_cycle = 'monthly'
                        WHERE c.type = 'insurance' AND ins.payment_type = 'installment' AND s.billing_cycle != 'monthly'
                    ");
                } catch (Exception $e) {}
            },
            'v15_category_types' => function($pdo) {
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `category_types` (
                            `type_key` VARCHAR(50) NOT NULL PRIMARY KEY,
                            `name` VARCHAR(100) NOT NULL,
                            `icon` VARCHAR(10) DEFAULT '📌',
                            `badge_color` VARCHAR(50) DEFAULT 'blue',
                            `sort_order` INT DEFAULT 0,
                            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                    $defaultTypes = [
                        ['digital', 'Dijital Abonelik', '💻', 'purple', 1],
                        ['utility', 'Ev / Fiziksel Fatura', '⚡', 'yellow', 2],
                        ['telecom', 'Ev / Telekom', '📡', 'cyan', 3],
                        ['insurance', 'Sigorta & Poliçe', '🛡️', 'rose', 4],
                        ['bes', 'Bireysel Emeklilik (BES & OKS)', '📈', 'emerald', 5],
                        ['vehicle', 'Araç & Taşıt', '🚗', 'blue', 6],
                        ['general', 'Genel Harcama', '📄', 'slate', 7],
                        ['tax', 'Vergi & Harç', '💰', 'rose', 8]
                    ];
                    $stmtDef = $pdo->prepare("INSERT IGNORE INTO `category_types` (`type_key`, `name`, `icon`, `badge_color`, `sort_order`) VALUES (?, ?, ?, ?, ?)");
                    foreach ($defaultTypes as $dt) {
                        $stmtDef->execute($dt);
                    }
                } catch (Exception $e) {}
            },
            'v16_insurance_location_sync' => function($pdo) {
                try {
                    $sigortaLocStmt = $pdo->query("SELECT id FROM `locations` WHERE `name` LIKE '%Sigorta%' ORDER BY id ASC LIMIT 1");
                    $sigortaLocId = $sigortaLocStmt ? $sigortaLocStmt->fetchColumn() : null;
                    if ($sigortaLocId) {
                        $pdo->exec("
                            UPDATE `subscriptions` s
                            INNER JOIN `categories` c ON s.category_id = c.id
                            SET s.location_id = " . (int)$sigortaLocId . "
                            WHERE c.type = 'insurance' AND (s.location_id IS NULL OR s.location_id != " . (int)$sigortaLocId . ")
                        ");
                    }
                } catch (Exception $e) {}
            },
            'v17_card_color_fix' => function($pdo) {
                try {
                    $pdo->exec("UPDATE `credit_cards` SET `color` = REPLACE(`color`, 'slate-850', 'slate-800') WHERE `color` LIKE '%slate-850%'");
                } catch (Exception $e) {}
            },
            'v18_categories_type_varchar_and_tax' => function($pdo) {
                try {
                    $pdo->exec("ALTER TABLE `categories` MODIFY COLUMN `type` VARCHAR(50) NOT NULL DEFAULT 'general'");
                } catch (Exception $e) {}
                try {
                    $pdo->exec("
                        INSERT INTO `category_types` (`type_key`, `name`, `icon`, `badge_color`, `sort_order`) 
                        VALUES ('tax', 'Vergi', '💰', 'rose', 8)
                        ON DUPLICATE KEY UPDATE `name` = 'Vergi', `icon` = '💰', `badge_color` = 'rose'
                    ");
                } catch (Exception $e) {}
                try {
                    $pdo->exec("UPDATE `categories` SET `type` = 'tax' WHERE `type` = 'vergi'");
                    $pdo->exec("DELETE FROM `category_types` WHERE `type_key` = 'vergi'");
                    $pdo->exec("
                        UPDATE `categories` 
                        SET `type` = 'tax' 
                        WHERE (LOWER(`name`) LIKE '%vergi%' OR LOWER(`slug`) LIKE '%vergi%' OR LOWER(`name`) LIKE '%maliye%' OR `name` = 'GSS' OR LOWER(`slug`) = 'gss')
                          AND LOWER(`name`) NOT LIKE '%motorlu%' AND LOWER(`slug`) NOT LIKE '%motorlu%' AND LOWER(`slug`) NOT LIKE '%mtv%'
                    ");
                    $pdo->exec("
                        UPDATE `categories` 
                        SET `type` = 'vehicle' 
                        WHERE LOWER(`name`) LIKE '%motorlu%' OR LOWER(`slug`) LIKE '%motorlu%' OR LOWER(`slug`) LIKE '%mtv%'
                    ");
                    $pdo->exec("UPDATE `categories` SET `type` = 'general' WHERE `type` = '' OR `type` IS NULL");
                } catch (Exception $e) {}
            },
            'v19_price_history' => function($pdo) {
                try {
                    $pdo->exec("
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
                    ");
                } catch (Exception $e) {}
            },
            'v20_monthly_budget' => function($pdo) use ($sutunVarMi) {
                try {
                    if (!$sutunVarMi('users', 'monthly_budget')) {
                        $pdo->exec("ALTER TABLE `users` ADD COLUMN `monthly_budget` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER `full_name`");
                    }
                } catch (Exception $e) {}
            }
        ];

        // 4. Henüz uygulanmamış olanları çalıştır
        $stmtInsert = $pdo->prepare("INSERT INTO `schema_migrations` (`version`) VALUES (?)");
        foreach ($migrations as $vKey => $migCallback) {
            if (!isset($appliedMap[$vKey])) {
                try {
                    $migCallback($pdo);
                    $stmtInsert->execute([$vKey]);
                    $appliedMap[$vKey] = true;
                } catch (Exception $e) {
                    sistemLogla("Migration {$vKey} hatası: " . $e->getMessage(), 'WARNING');
                }
            }
        }

        $_SESSION['_abonelik_schema_version'] = $currentVersion;
    } catch (Exception $e) {
        sistemLogla("Şema Güncelleme Yöneticisi Hatası: " . $e->getMessage(), 'ERROR');
    }
}
