<?php
/**
 * Abonelik ve Fatura Takip Sistemi - Veritabanı, Güvenlik ve Çekirdek Fonksiyonlar
 * stoktakip mimarisi referans alınarak tasarlanmıştır.
 */

// UTF-8 Karakter Kodlama Yapılandırması (Türkçe karakterlerin bozulmasını kesin olarak engeller)
ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
if (!ob_get_level()) {
    ob_start();
}
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

// 1. Kurulum Kontrolü: .env yoksa install.php'ye yönlendir
$envFile = __DIR__ . '/.env';
$currentScript = basename($_SERVER['PHP_SELF'] ?? '');

if (!file_exists($envFile)) {
    if ($currentScript !== 'install.php') {
        header("Location: install.php");
        exit;
    }
    return; // install.php çalışırken db.php yüklenirse devam etmesin
}

// 2. Güvenli Oturum (Session) Yapılandırması
$sessionDir = __DIR__ . '/sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0700, true);
}
if (is_writable($sessionDir)) {
    ini_set('session.save_path', $sessionDir);
}

ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);

$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
           (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. .env Dosyasını Oku ve Ortama Yükle
function envYukle($dosya) {
    if (!file_exists($dosya)) return [];
    $satirlar = file($dosya, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $veriler = [];
    foreach ($satirlar as $satir) {
        $satir = trim($satir);
        if (empty($satir) || strpos($satir, '#') === 0) continue;
        if (strpos($satir, '=') !== false) {
            list($anahtar, $deger) = explode('=', $satir, 2);
            $anahtar = trim($anahtar);
            $deger = trim($deger);
            // Tırnak işaretlerini temizle
            if (preg_match('/^"(.*)"$/', $deger, $m) || preg_match("/^'(.*)'$/", $deger, $m)) {
                $deger = $m[1];
            }
            $veriler[$anahtar] = $deger;
            if (!array_key_exists($anahtar, $_SERVER) && !array_key_exists($anahtar, $_ENV)) {
                putenv("{$anahtar}={$deger}");
                $_ENV[$anahtar] = $deger;
                $_SERVER[$anahtar] = $deger;
            }
        }
    }
    return $veriler;
}

$env = envYukle($envFile);

// Canlı ortamda (production) hata detaylarının ekrana basılmasını engelle, loglara yönlendir
if (env('APP_ENV') === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
}

function env($anahtar, $varsayilan = null) {
    global $env;
    return $env[$anahtar] ?? getenv($anahtar) ?: $varsayilan;
}

// 4. Sistem Loglama
function sistemLogla($mesaj, $seviye = 'INFO') {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $dosya = $logDir . '/app-' . date('Y-m') . '.log';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    $zaman = date('Y-m-d H:i:s');
    $satir = "[{$zaman}] [{$seviye}] [IP: {$ip}] {$mesaj}" . PHP_EOL;
    @file_put_contents($dosya, $satir, FILE_APPEND | LOCK_EX);
}

// 5. PDO Veritabanı Bağlantısı
try {
    $dbHost = env('DB_HOST', 'localhost');
    $dbPort = env('DB_PORT', '3306');
    $dbName = env('DB_NAME', 'abonelik_takip');
    $dbUser = env('DB_USER', 'root');
    $dbPass = env('DB_PASS', '');

    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);

    // Otomatik Şema Güncellemesi: role ve status alanları
    if (empty($_SESSION['_abonelik_mig_v1_roles'])) {
        try {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `role` ENUM('ADMIN', 'USER') NOT NULL DEFAULT 'USER' AFTER `master_pin_hash`");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER `role`");
        } catch (Exception $e) {}
        $_SESSION['_abonelik_mig_v1_roles'] = true;
    }

    // Otomatik Şema Güncellemesi v2: locations tablosu ve location_id
    if (empty($_SESSION['_abonelik_mig_v2_locations'])) {
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
        $_SESSION['_abonelik_mig_v2_locations'] = true;
    }

    // Otomatik Şema Güncellemesi v3: payer_user_id, subscription_access ve admin bildirim tercihi
    if (empty($_SESSION['_abonelik_mig_v3_payer_permissions'])) {
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
        $_SESSION['_abonelik_mig_v3_payer_permissions'] = true;
    }

    // Otomatik Şema Güncellemesi v4: Otomatik Ödeme Bilgileri (Utility & Telecom)
    if (empty($_SESSION['_abonelik_mig_v4_autopay'])) {
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
        $_SESSION['_abonelik_mig_v4_autopay'] = true;
    }

    // Otomatik Şema Güncellemesi v5: Kredi Kartları, Sigorta/Poliçeler ve Bireysel Emeklilik (BES)
    if (empty($_SESSION['_abonelik_mig_v5_cards_insurance_bes'])) {
        try {
            // 1. Kategoriler tablosuna insurance ve bes tipleri ekle
            $pdo->exec("ALTER TABLE `categories` MODIFY COLUMN `type` ENUM('digital', 'utility', 'telecom', 'vehicle', 'general', 'insurance', 'bes') NOT NULL DEFAULT 'digital'");
            
            // Varsayılan Sigorta ve BES Kategorileri Ekle
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
            // 2. Kredi Kartları Tablosu
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
            // 3. Sigorta & Poliçe Detay Tablosu
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
            // 4. Bireysel Emeklilik (BES) Detay Tablosu
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
            // 5. subscriptions tablosuna credit_card_id bağlama
            $pdo->exec("ALTER TABLE `subscriptions` ADD COLUMN `credit_card_id` INT NULL AFTER `payment_method_id`");
        } catch (Exception $e) {}

        $_SESSION['_abonelik_mig_v5_cards_insurance_bes'] = true;
    }

    // Otomatik Şema Güncellemesi v6: Poliçe & Sözleşme Belge Eki (PDF / Görsel)
    if (empty($_SESSION['_abonelik_mig_v6_attachments'])) {
        try {
            $pdo->exec("ALTER TABLE `subscriptions` 
                ADD COLUMN `attachment_file` VARCHAR(255) NULL AFTER `notes`,
                ADD COLUMN `attachment_name` VARCHAR(255) NULL AFTER `attachment_file`");
        } catch (Exception $e) {}
        $_SESSION['_abonelik_mig_v6_attachments'] = true;
    }

    // Otomatik Şema Güncellemesi v7: Sigorta & BES Alan Sağlamlaştırma
    if (empty($_SESSION['_abonelik_mig_v7_schema_sync'])) {
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
        $_SESSION['_abonelik_mig_v7_schema_sync'] = true;
    }

    // Otomatik Şema Güncellemesi v8: Nihai Şema ve Alan Sağlamlaştırma (Final Sync)
    if (empty($_SESSION['_abonelik_mig_v8_final_sync'])) {
        try {
            $sutunVarMi = function($tablo, $sutun) use ($pdo) {
                try {
                    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$tablo}` LIKE ?");
                    $stmt->execute([$sutun]);
                    return (bool)$stmt->fetch();
                } catch (Exception $e) {
                    return false;
                }
            };

            // 1. `subscriptions` Tablosu
            try {
                $pdo->exec("ALTER TABLE `subscriptions` MODIFY COLUMN `billing_cycle` ENUM('monthly', 'annual', 'quarterly', 'semi_annual', 'one_time') DEFAULT 'monthly'");
            } catch (Exception $e) {}

            if (!$sutunVarMi('subscriptions', 'is_auto_pay')) {
                try {
                    $pdo->exec("ALTER TABLE `subscriptions` ADD COLUMN `is_auto_pay` TINYINT(1) DEFAULT 0 AFTER `credit_card_id`");
                } catch (Exception $e) {}
            }
            if (!$sutunVarMi('subscriptions', 'auto_pay_type')) {
                try {
                    $pdo->exec("ALTER TABLE `subscriptions` ADD COLUMN `auto_pay_type` ENUM('credit_card', 'bank_account', 'other') DEFAULT NULL AFTER `is_auto_pay`");
                } catch (Exception $e) {}
            }
            if (!$sutunVarMi('subscriptions', 'attachment_file')) {
                try {
                    $pdo->exec("ALTER TABLE `subscriptions` ADD COLUMN `attachment_file` VARCHAR(255) NULL AFTER `notes`");
                } catch (Exception $e) {}
            }
            if (!$sutunVarMi('subscriptions', 'attachment_name')) {
                try {
                    $pdo->exec("ALTER TABLE `subscriptions` ADD COLUMN `attachment_name` VARCHAR(255) NULL AFTER `attachment_file`");
                } catch (Exception $e) {}
            }

            // 2. `subscription_telecom_details` Tablosu
            if (!$sutunVarMi('subscription_telecom_details', 'is_auto_pay')) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_telecom_details` ADD COLUMN `is_auto_pay` TINYINT(1) DEFAULT 0 AFTER `device_rental_fee`");
                } catch (Exception $e) {}
            }
            if (!$sutunVarMi('subscription_telecom_details', 'auto_pay_type')) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_telecom_details` ADD COLUMN `auto_pay_type` ENUM('credit_card', 'bank_account', 'other') DEFAULT 'credit_card' AFTER `is_auto_pay`");
                } catch (Exception $e) {}
            }
            if (!$sutunVarMi('subscription_telecom_details', 'auto_pay_bank')) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_telecom_details` ADD COLUMN `auto_pay_bank` VARCHAR(100) NULL AFTER `auto_pay_type`");
                } catch (Exception $e) {}
            }
            if (!$sutunVarMi('subscription_telecom_details', 'auto_pay_source_title')) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_telecom_details` ADD COLUMN `auto_pay_source_title` VARCHAR(150) NULL AFTER `auto_pay_bank`");
                } catch (Exception $e) {}
            }

            // 3. `subscription_utility_details` Tablosu
            if (!$sutunVarMi('subscription_utility_details', 'is_auto_pay')) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_utility_details` ADD COLUMN `is_auto_pay` TINYINT(1) DEFAULT 0 AFTER `due_day`");
                } catch (Exception $e) {}
            }
            if (!$sutunVarMi('subscription_utility_details', 'auto_pay_type')) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_utility_details` ADD COLUMN `auto_pay_type` ENUM('credit_card', 'bank_account', 'other') DEFAULT 'credit_card' AFTER `is_auto_pay`");
                } catch (Exception $e) {}
            }
            if (!$sutunVarMi('subscription_utility_details', 'auto_pay_bank')) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_utility_details` ADD COLUMN `auto_pay_bank` VARCHAR(100) NULL AFTER `auto_pay_type`");
                } catch (Exception $e) {}
            }
            if (!$sutunVarMi('subscription_utility_details', 'auto_pay_source_title')) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_utility_details` ADD COLUMN `auto_pay_source_title` VARCHAR(150) NULL AFTER `auto_pay_bank`");
                } catch (Exception $e) {}
            }

            // 4. `subscription_insurance_details` Tablosu
            if (!$sutunVarMi('subscription_insurance_details', 'insured_item')) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_insurance_details` ADD COLUMN `insured_item` VARCHAR(150) NULL AFTER `policy_no`");
                } catch (Exception $e) {}
            }
            if (!$sutunVarMi('subscription_insurance_details', 'payment_type')) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_insurance_details` ADD COLUMN `payment_type` ENUM('single', 'installment') DEFAULT 'single' AFTER `policy_end_date`");
                } catch (Exception $e) {}
            }
            if (!$sutunVarMi('subscription_insurance_details', 'installment_count')) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_insurance_details` ADD COLUMN `installment_count` INT DEFAULT 1 AFTER `payment_type`");
                } catch (Exception $e) {}
            }
            if (!$sutunVarMi('subscription_insurance_details', 'installment_amount')) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_insurance_details` ADD COLUMN `installment_amount` DECIMAL(10, 2) NULL AFTER `installment_count`");
                } catch (Exception $e) {}
            }

            // 5. `subscription_bes_details` Tablosu
            if (!$sutunVarMi('subscription_bes_details', 'owner_relation')) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_bes_details` ADD COLUMN `owner_relation` VARCHAR(100) DEFAULT 'Kendim' AFTER `payment_day`");
                } catch (Exception $e) {}
            }
            if (!$sutunVarMi('subscription_bes_details', 'auto_pay_source')) {
                try {
                    $pdo->exec("ALTER TABLE `subscription_bes_details` ADD COLUMN `auto_pay_source` VARCHAR(150) NULL AFTER `owner_relation`");
                } catch (Exception $e) {}
            }

            // 6. `notification_logs` Tablosu ENUM Güncellemesi
            try {
                $pdo->exec("ALTER TABLE `notification_logs` MODIFY COLUMN `notification_type` ENUM('trial_end','commitment_end','payment_due','insurance_renewal','admin_copy_trial','admin_copy_comm','admin_copy_due','monthly_summary','system') NOT NULL");
            } catch (Exception $e) {}

            // 7. Master PIN Brute-Force Koruma Tablosu (`pin_attempts`)
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

            $_SESSION['_abonelik_mig_v8_final_sync'] = true;
        } catch (Exception $e) {
            sistemLogla("Migration v8 Hatası: " . $e->getMessage(), 'WARNING');
        }
    }

    // Otomatik Şema Güncellemesi v9: Güvenlik ve Bildirim ENUM Senkronizasyonu
    if (empty($_SESSION['_abonelik_mig_v9_security_sync'])) {
        try {
            // 1. Master PIN Brute-Force Koruma Tablosu
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `pin_attempts` (
                    `user_id` INT NOT NULL PRIMARY KEY,
                    `attempts` INT DEFAULT 1,
                    `last_attempt` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `locked_until` DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (Exception $e) {
            sistemLogla("Migration v9 pin_attempts hatası: " . $e->getMessage(), 'WARNING');
        }

        try {
            // 2. notification_logs tablosundaki notification_type ENUM güncellemesi
            $pdo->exec("
                ALTER TABLE `notification_logs` MODIFY COLUMN `notification_type` 
                ENUM('trial_end','commitment_end','payment_due','insurance_renewal','admin_copy_trial','admin_copy_comm','admin_copy_due','monthly_summary','system') NOT NULL
            ");
        } catch (Exception $e) {
            sistemLogla("Migration v9 notification_logs ENUM hatası: " . $e->getMessage(), 'WARNING');
        }

        $_SESSION['_abonelik_mig_v9_security_sync'] = true;
    }

    // Otomatik Şema Güncellemesi v10: Şifre Değişimi ve Oturum Düşürme (Session Invalidation)
    if (empty($_SESSION['_abonelik_mig_v10_session_invalidation'])) {
        try {
            $sutunVarMi = function($tablo, $sutun) use ($pdo) {
                try {
                    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$tablo}` LIKE ?");
                    $stmt->execute([$sutun]);
                    return (bool)$stmt->fetch();
                } catch (Exception $e) {
                    return false;
                }
            };

            if (!$sutunVarMi('users', 'password_changed_at')) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `password_changed_at` DATETIME NULL AFTER `updated_at`");
            }

            try {
                $pdo->exec("ALTER TABLE `subscriptions` ADD KEY `credit_card_id` (`credit_card_id`)");
            } catch (Exception $e) {}
            try {
                $pdo->exec("ALTER TABLE `subscriptions` ADD KEY `payment_method_id` (`payment_method_id`)");
            } catch (Exception $e) {}
        } catch (Exception $e) {
            sistemLogla("Migration v10 password_changed_at hatası: " . $e->getMessage(), 'WARNING');
        }

        $_SESSION['_abonelik_mig_v10_session_invalidation'] = true;
    }

    // Otomatik Şema Güncellemesi v11: Mekan & Konum Üye Yetkilendirmesi (location_access)
    if (empty($_SESSION['_abonelik_mig_v11_location_access'])) {
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
        } catch (Exception $e) {
            sistemLogla("Migration v11 location_access hatası: " . $e->getMessage(), 'WARNING');
        }
        $_SESSION['_abonelik_mig_v11_location_access'] = true;
    }

    // Otomatik Şema Güncellemesi v12: subscription_shares tablosuna user_id sütunu ve indeksi
    if (empty($_SESSION['_abonelik_mig_v12_shares_userid'])) {
        try {
            $sutunVarMi = function($tablo, $sutun) use ($pdo) {
                try {
                    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$tablo}` LIKE ?");
                    $stmt->execute([$sutun]);
                    return (bool)$stmt->fetch();
                } catch (Exception $e) {
                    return false;
                }
            };

            if (!$sutunVarMi('subscription_shares', 'user_id')) {
                $pdo->exec("ALTER TABLE `subscription_shares` ADD COLUMN `user_id` INT NULL AFTER `subscription_id`, ADD KEY `user_id` (`user_id`)");
            }
        } catch (Exception $e) {
            sistemLogla("Migration v12 subscription_shares hatası: " . $e->getMessage(), 'WARNING');
        }
        $_SESSION['_abonelik_mig_v12_shares_userid'] = true;
    }

    // Otomatik Şema Güncellemesi v12: Nakit İade / Cashback Alanı (cashback_amount)
    if (empty($_SESSION['_abonelik_mig_v12_cashback'])) {
        try {
            $sutunVarMi = function($tablo, $sutun) use ($pdo) {
                try {
                    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$tablo}` LIKE ?");
                    $stmt->execute([$sutun]);
                    return (bool)$stmt->fetch();
                } catch (Exception $e) {
                    return false;
                }
            };

            if (!$sutunVarMi('subscriptions', 'cashback_amount')) {
                $pdo->exec("ALTER TABLE `subscriptions` ADD COLUMN `cashback_amount` DECIMAL(10,2) DEFAULT 0.00 AFTER `price`");
            }
        } catch (Exception $e) {
            sistemLogla("Migration v12 cashback_amount hatası: " . $e->getMessage(), 'WARNING');
        }
        $_SESSION['_abonelik_mig_v12_cashback'] = true;
    }

    // Otomatik Şema Güncellemesi v14: Taksitli Sigorta / Poliçelerde Aylık Ödeme Döngüsü ve Tarih Normalizasyonu
    if (empty($_SESSION['_abonelik_mig_v14_insurance_installments'])) {
        try {
            // Taksitli sigortaların fatura döngüsünü otomatik 'monthly' (Aylık) yap
            $pdo->exec("
                UPDATE subscriptions s
                JOIN categories c ON s.category_id = c.id
                JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
                SET s.billing_cycle = 'monthly'
                WHERE c.type = 'insurance' AND ins.payment_type = 'installment' AND s.billing_cycle != 'monthly'
            ");

            // Taksitli sigortalarda sonraki ödeme tarihi 60 günden fazla ileriye (örn. 1 yıl sonrasına) atılmışsa,
            // bunu takvimde ve yaklaşan ödemelerde görünmesi için içinde bulunulan / yaklaşan aya normalize et
            $stmtFixIns = $pdo->query("
                SELECT s.id, s.next_payment_date, ins.policy_start_date, ins.policy_end_date
                FROM subscriptions s
                JOIN categories c ON s.category_id = c.id
                JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
                WHERE c.type = 'insurance' 
                  AND ins.payment_type = 'installment'
                  AND s.status = 'active'
                  AND (s.next_payment_date > DATE_ADD(CURRENT_DATE, INTERVAL 60 DAY) OR s.next_payment_date IS NULL)
            ");
            $toFix = $stmtFixIns->fetchAll(PDO::FETCH_ASSOC);

            foreach ($toFix as $fRow) {
                $tDay = 1;
                if (!empty($fRow['policy_start_date'])) {
                    $tDay = (int)date('j', strtotime($fRow['policy_start_date']));
                } elseif (!empty($fRow['next_payment_date'])) {
                    $tDay = (int)date('j', strtotime($fRow['next_payment_date']));
                } else {
                    $tDay = (int)date('j');
                }

                $curYear = (int)date('Y');
                $curMonth = (int)date('n');
                $daysInCurMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $curYear, $curMonth)));
                $validDay = min($tDay, $daysInCurMonth);

                $targetDateThisMonth = sprintf('%04d-%02d-%02d', $curYear, $curMonth, $validDay);

                $pdo->prepare("UPDATE subscriptions SET next_payment_date = ? WHERE id = ?")
                    ->execute([$targetDateThisMonth, $fRow['id']]);
            }
        } catch (Exception $e) {
            sistemLogla("Migration v14 insurance installments hatası: " . $e->getMessage(), 'WARNING');
        }
        $_SESSION['_abonelik_mig_v14_insurance_installments'] = true;
    }
} catch (\PDOException $e) {
    sistemLogla("Veritabanı Bağlantı Hatası: " . $e->getMessage(), 'CRITICAL');
    if (!headers_sent()) {
        header("Location: error.php?code=db");
        exit;
    } else {
        die("Veritabanı bağlantı hatası oluştu. Lütfen .env dosyasını kontrol edin.");
    }
}

// 6. CSRF Koruması
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrfKontrol($token) {
    if (!isset($_SESSION['csrf_token']) || empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        sistemLogla("CSRF Doğrulama Hatası! IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Bilinmiyor'), 'SECURITY');
        http_response_code(403);
        die("Güvenlik Hatası: Geçersiz veya süresi dolmuş form oturumu (CSRF). Sayfayı yenileyip tekrar deneyin.");
    }
}

function csrfAlaniniEkle() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

// 7. Kullanıcı Doğrulama ve Güvenlik
function girisKontrol() {
    global $pdo;
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT id, username, email, full_name, role, status, password_changed_at FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $kullanici = $stmt->fetch();
        if (!$kullanici || $kullanici['status'] === 'inactive') {
            session_destroy();
            header("Location: login.php?msg=" . (!$kullanici ? 'deleted' : 'inactive'));
            exit;
        }

        // Şifre Değişimi Kontrolü: password_changed_at > login_time ise diğer oturumları derhal düşür
        if (!empty($kullanici['password_changed_at'])) {
            $passChangedTs = strtotime($kullanici['password_changed_at']);
            $loginTimeTs = (int)($_SESSION['login_time'] ?? 0);
            if ($passChangedTs > $loginTimeTs) {
                sistemLogla("Şifre Değişimi Nedeniyle Oturum Düşürüldü: {$kullanici['username']} (ID: {$kullanici['id']})", 'SECURITY');
                $_SESSION = [];
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $params["path"], $params["domain"],
                        $params["secure"], $params["httponly"]
                    );
                }
                session_destroy();
                header("Location: login.php?msg=session_expired");
                exit;
            }
        }

        $_SESSION['user_data'] = $kullanici;
        $_SESSION['role'] = $kullanici['role'] ?? 'USER';
    } catch (PDOException $e) {
        sistemLogla("Kullanıcı Doğrulama Hatası: " . $e->getMessage(), 'ERROR');
    }
}

function adminKontrol() {
    girisKontrol();
    if (($_SESSION['role'] ?? 'USER') !== 'ADMIN') {
        sistemLogla("Yetkisiz Admin Sayfası Erişimi: " . ($_SESSION['username'] ?? 'Bilinmeyen'), 'SECURITY');
        header("Location: index.php?msg=unauthorized");
        exit;
    }
}

// 8. AES-256 Şifreleme / Çözme Yardımcıları (Kullanıcı Adı & Şifre Saklama)
function sifrelemeAnahtariAl() {
    $secret = env('APP_SECRET_KEY', 'default_secret_key_32_characters');
    $salt   = env('MASTER_SALT', 'default_salt_string');
    return hash('sha256', $secret . $salt, true);
}

function sifrele($duzMetin) {
    if (empty($duzMetin)) return ['encrypted' => null, 'iv' => null];
    $anahtar = sifrelemeAnahtariAl();
    $iv = random_bytes(16);
    $sifreli = openssl_encrypt($duzMetin, 'AES-256-CBC', $anahtar, 0, $iv);
    return [
        'encrypted' => base64_encode($sifreli),
        'iv'        => base64_encode($iv)
    ];
}

function sifreCoz($sifreliMetin, $iv) {
    if (empty($sifreliMetin) || empty($iv)) return '';
    $anahtar = sifrelemeAnahtariAl();
    $decodedSifreli = base64_decode($sifreliMetin);
    $decodedIv = base64_decode($iv);
    return openssl_decrypt($decodedSifreli, 'AES-256-CBC', $anahtar, 0, $decodedIv);
}

// 9. Master PIN Kontrolü (Dijital Şifreleri Görmek İçin)
function masterPinDogrula($pin) {
    global $pdo;
    if (!isset($_SESSION['user_id'])) return false;
    $stmt = $pdo->prepare("SELECT master_pin_hash FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $pinHash = $stmt->fetchColumn();
    if ($pinHash && password_verify($pin, $pinHash)) {
        $_SESSION['pin_verified_until'] = time() + 900; // 15 dakika boyunca kilit açık kalır
        return true;
    }
    return false;
}

function masterPinAktifMi() {
    return isset($_SESSION['pin_verified_until']) && $_SESSION['pin_verified_until'] > time();
}

function kullaniciSifreGorebilirMi($subscriptionId, $userId, $userRole = 'USER') {
    global $pdo;
    // 1. Yönetici ise her zaman görüntüleyebilir
    if ($userRole === 'ADMIN') return true;

    // 2. Aboneliği oluşturan veya ödeyen kişi ise
    $stmt = $pdo->prepare("SELECT user_id, payer_user_id FROM subscriptions WHERE id = ?");
    $stmt->execute([$subscriptionId]);
    $sub = $stmt->fetch();
    if ($sub && ($sub['user_id'] == $userId || $sub['payer_user_id'] == $userId)) {
        return true;
    }

    // 3. Erişim izni verilen üyeler arasında mı?
    $stmtAcc = $pdo->prepare("SELECT id FROM subscription_access WHERE subscription_id = ? AND user_id = ? AND can_view_credentials = 1");
    $stmtAcc->execute([$subscriptionId, $userId]);
    return ($stmtAcc->rowCount() > 0);
}

// 9.1 Konum & Mekan Yetki Kontrolü
function kullaniciKonumGorebilirMi($locationId, $userId, $userRole = 'USER') {
    global $pdo;
    if (empty($locationId)) return true; // Genel / Mekansız
    if ($userRole === 'ADMIN') return true;

    // 1. Kullanıcı mekanın sahibi mi?
    $stmt = $pdo->prepare("SELECT 1 FROM locations WHERE id = ? AND user_id = ?");
    $stmt->execute([$locationId, $userId]);
    if ($stmt->fetchColumn()) {
        return true;
    }

    // 2. location_access tablosunda yetkisi var mı?
    $stmtAcc = $pdo->prepare("SELECT 1 FROM location_access WHERE location_id = ? AND user_id = ?");
    $stmtAcc->execute([$locationId, $userId]);
    return (bool)$stmtAcc->fetchColumn();
}

// 9.2 Kullanıcının Yetkili Olduğu Konumları Getir
function kullaniciKonumlariGetir($userId, $userRole = 'USER') {
    global $pdo;
    if ($userRole === 'ADMIN') {
        return $pdo->query("SELECT * FROM locations ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT l.* FROM locations l 
        LEFT JOIN location_access la ON l.id = la.location_id 
        WHERE l.user_id = ? OR la.user_id = ? 
        ORDER BY l.id ASC
    ");
    $stmt->execute([$userId, $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 9.3 Mekana / Konuma Uygun Kategori Türlerini Döndür
function mekanKategoriTurleri($locationOrId) {
    global $pdo;
    if (empty($locationOrId)) {
        return ['digital', 'utility', 'telecom', 'insurance', 'bes', 'vehicle', 'general'];
    }

    $loc = is_array($locationOrId) ? $locationOrId : null;
    if (!$loc && is_numeric($locationOrId)) {
        $stmt = $pdo->prepare("SELECT * FROM locations WHERE id = ?");
        $stmt->execute([(int)$locationOrId]);
        $loc = $stmt->fetch();
    }
    if (!$loc) {
        return ['digital', 'utility', 'telecom', 'insurance', 'bes', 'vehicle', 'general'];
    }

    $icon = strtolower($loc['icon'] ?? '');
    $cleanName = str_replace(['İ', 'I', 'ı'], ['i', 'i', 'i'], $loc['name'] ?? '');
    $cleanName = mb_strtolower($cleanName, 'UTF-8');

    // 1. Ev / Fiziksel / Konut / Ofis / Şehirler (İstanbul, Sakarya vb.) -> Ev Faturaları & Telekom
    if (in_array($icon, ['home', 'building', 'sun']) || 
        strpos($cleanName, 'ev') !== false || strpos($cleanName, 'konut') !== false || 
        strpos($cleanName, 'daire') !== false || strpos($cleanName, 'apartman') !== false ||
        strpos($cleanName, 'istanbul') !== false || strpos($cleanName, 'sakarya') !== false ||
        strpos($cleanName, 'ofis') !== false || strpos($cleanName, 'yazlık') !== false ||
        strpos($cleanName, 'yazlik') !== false) {
        return ['utility', 'telecom'];
    }

    // 2. Dijital / Bulut / Servis
    if ($icon === 'globe' || strpos($cleanName, 'dijital') !== false || strpos($cleanName, 'digital') !== false || strpos($cleanName, 'online') !== false || strpos($cleanName, 'bulut') !== false) {
        return ['digital'];
    }

    // 3. Sigorta / Poliçe / Kasko
    if ($icon === 'shield' || strpos($cleanName, 'sigorta') !== false || strpos($cleanName, 'kasko') !== false || strpos($cleanName, 'poliçe') !== false || strpos($cleanName, 'police') !== false || strpos($cleanName, 'dask') !== false) {
        return ['insurance'];
    }

    // 4. Bireysel Emeklilik (BES) / Finans
    if (in_array($icon, ['chart', 'trending-up']) || strpos($cleanName, 'bes') !== false || strpos($cleanName, 'emeklilik') !== false || strpos($cleanName, 'fon') !== false) {
        return ['bes'];
    }

    // 5. Araç / Taşıt
    if ($icon === 'car' || strpos($cleanName, 'araç') !== false || strpos($cleanName, 'arac') !== false || strpos($cleanName, 'araba') !== false || strpos($cleanName, 'taşıt') !== false || strpos($cleanName, 'tasit') !== false) {
        return ['vehicle'];
    }

    return ['digital', 'utility', 'telecom', 'insurance', 'bes', 'vehicle', 'general'];
}

// 10. Formatlayıcılar
function paraFormat($tutar, $paraBirimi = 'TRY') {
    $semboller = [
        'TRY' => '₺',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£'
    ];
    $sembol = $semboller[$paraBirimi] ?? $paraBirimi;
    return number_format((float)$tutar, 2, ',', '.') . ' ' . $sembol;
}

function tarihFormat($tarih, $format = 'd.m.Y') {
    if (empty($tarih) || $tarih === '0000-00-00') return '-';
    $ts = strtotime($tarih);
    return $ts ? date($format, $ts) : '-';
}

function kalanGunHesapla($hedefTarih) {
    if (empty($hedefTarih)) return null;
    $bugun = new DateTime('today');
    $hedef = new DateTime($hedefTarih);
    $fark = (int)$bugun->diff($hedef)->format('%r%a');
    return $fark;
}

// 11. CSP NONCE & Güvenlik Başlıkları
if (!isset($cspNonce)) {
    try {
        $cspNonce = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        $cspNonce = bin2hex(openssl_random_pseudo_bytes(16));
    }
}

if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    if ($isHttps) {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    }

    $cspHeader = "default-src 'self'; " .
                 "base-uri 'self'; " .
                 "object-src 'none'; " . 
                 "form-action 'self'; " . 
                 "script-src 'self' 'nonce-{$cspNonce}' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com; " .
                 "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; " .
                 "img-src 'self' data: blob: https:; " .
                 "font-src 'self' https://cdnjs.cloudflare.com; " .
                 "connect-src 'self';";

    header("Content-Security-Policy: " . $cspHeader);
}
