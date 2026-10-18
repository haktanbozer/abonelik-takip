<?php
/**
 * Abonelik ve Fatura Takip Sistemi - Otomatik Kurulum Sihirbazı (Turnkey Installer)
 * stoktakip mimarisi referans alınarak geliştirilmiştir.
 */

// Kurulum Kilidi ve Yetkisiz Erişim Engeli
$envFile = __DIR__ . '/.env';
$isInstalled = false;

if (file_exists($envFile)) {
    $envContentCheck = @file_get_contents($envFile) ?: '';
    if (strpos($envContentCheck, 'APP_INSTALLED=true') !== false || strpos($envContentCheck, 'DB_HOST=') !== false) {
        $isInstalled = true;
    }
}

if ($isInstalled) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Sistem Zaten Kurulu - Abonelik Takip</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = { darkMode: 'class' };
            if (localStorage.getItem('color-theme') === 'dark') {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        </script>
        <link rel="icon" type="image/png" href="icons/favicon.png">
    </head>
    <body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex items-center justify-center p-4 transition-colors">
        <div class="max-w-md w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 text-center shadow-xl">
            <div class="w-16 h-16 bg-emerald-500/10 text-emerald-500 dark:text-emerald-400 rounded-2xl flex items-center justify-center mx-auto mb-4 text-3xl font-bold">✓</div>
            <h1 class="text-xl font-bold text-slate-900 dark:text-white mb-2">Sistem Zaten Kurulu</h1>
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-6 leading-relaxed">Abonelik Takip Sistemi başarıyla kurulmuş ve çalışır durumda. Tekrar kurulum yapmak için ana dizindeki <code>.env</code> dosyasını silmeniz gerekmektedir.</p>
            <a href="login.php" class="inline-block w-full py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-sm transition shadow-md shadow-blue-500/20">Giriş Ekranına Git</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$error = null;
$success = null;

// Otomatik Güvenli Token Üretimi
$defaultCronSecret = bin2hex(random_bytes(16));
$defaultAppSecret  = bin2hex(random_bytes(16));
$defaultSalt       = bin2hex(random_bytes(8));

// Sistem Uyumluluk Kontrolleri
$requirements = [
    'PHP Sürümü (>= 7.4)' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO MySQL Eklentisi' => extension_loaded('pdo_mysql'),
    'OpenSSL Eklentisi' => extension_loaded('openssl'),
    'cURL Eklentisi' => extension_loaded('curl'),
    'Dizin Yazılabilirliği (Ana Dizin)' => is_writable(__DIR__),
    'Dizin Yazılabilirliği (logs/)' => is_writable(__DIR__ . '/logs'),
    'Dizin Yazılabilirliği (sessions/)' => is_writable(__DIR__ . '/sessions')
];

$allRequirementsPassed = !in_array(false, $requirements, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kurulum_yap'])) {
    if (!$allRequirementsPassed) {
        $error = 'Lütfen önce sistem gereksinimlerini ve klasör yazma izinlerini tamamlayın.';
    } else {
        $dbHost = trim($_POST['db_host'] ?? 'localhost');
        $dbPort = trim($_POST['db_port'] ?? '3306');
        $dbName = trim($_POST['db_name'] ?? 'abonelik_takip');
        $dbUser = trim($_POST['db_user'] ?? 'root');
        $dbPass = $_POST['db_pass'] ?? '';

        $adminUser   = trim($_POST['admin_user'] ?? 'admin');
        $adminEmail  = trim($_POST['admin_email'] ?? '');
        $adminPass   = $_POST['admin_pass'] ?? '';
        $adminPin    = trim($_POST['admin_pin'] ?? '1234');
        $cronSecret  = trim($_POST['cron_secret'] ?? $defaultCronSecret);

        $smtpHost    = trim($_POST['smtp_host'] ?? '');
        $smtpPort    = trim($_POST['smtp_port'] ?? '587');
        $smtpUser    = trim($_POST['smtp_user'] ?? '');
        $smtpPass    = $_POST['smtp_pass'] ?? '';
        $smtpSecure  = trim($_POST['smtp_secure'] ?? 'tls');
        $mailFrom    = trim($_POST['mail_from_address'] ?? $adminEmail);
        $mailFromName= trim($_POST['mail_from_name'] ?? 'Abonelik Takip');

        if (empty($adminUser) || empty($adminEmail) || empty($adminPass)) {
            $error = 'Yönetici kullanıcı adı, e-posta ve şifre zorunludur.';
        } elseif (strlen($adminPass) < 6) {
            $error = 'Yönetici şifresi en az 6 karakter olmalıdır.';
        } elseif (strlen($adminPin) < 4) {
            $error = 'Master PIN (Şifre Çözme PIN Kodu) en az 4 haneli olmalıdır.';
        } else {
            try {
                // 1. Veritabanı Sunucusuna Bağlan
                $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);

                // 2. Veritabanını Oluştur (Yoksa)
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$dbName}`");

                // 3. Tabloları Oluştur
                $sql = "
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

                CREATE TABLE IF NOT EXISTS `locations` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT NOT NULL,
                    `name` VARCHAR(100) NOT NULL,
                    `icon` VARCHAR(50) DEFAULT 'home',
                    `color` VARCHAR(30) DEFAULT 'blue',
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `location_access` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `location_id` INT NOT NULL,
                    `user_id` INT NOT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `loc_user_unique` (`location_id`, `user_id`),
                    INDEX (`location_id`),
                    INDEX (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

                CREATE TABLE IF NOT EXISTS `category_types` (
                    `type_key` VARCHAR(50) NOT NULL PRIMARY KEY,
                    `name` VARCHAR(100) NOT NULL,
                    `icon` VARCHAR(10) DEFAULT '📌',
                    `badge_color` VARCHAR(50) DEFAULT 'blue',
                    `sort_order` INT DEFAULT 0,
                    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `schema_migrations` (
                    `version` VARCHAR(100) NOT NULL PRIMARY KEY,
                    `applied_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ";
                $pdo->exec($sql);

                // 4. Varsayılan Kategorileri Ekle
                $defaultCategories = [
                    ['Dijital Dizi & Film', 'dijital-dizi-film', 'digital', 'film', 'red', 1],
                    ['Müzik & Ses', 'muzik-ses', 'digital', 'music', 'green', 2],
                    ['Bulut & Depolama', 'bulut-depolama', 'digital', 'cloud', 'blue', 3],
                    ['Yazılım & SaaS', 'yazilim-saas', 'digital', 'cpu', 'purple', 4],
                    ['E-Ticaret & Alışveriş', 'e-ticaret-alisveris', 'digital', 'shopping-bag', 'amber', 5],
                    ['Oyun Servisleri', 'oyun-servisleri', 'digital', 'gamepad-2', 'indigo', 6],
                    ['Ev Faturaları (Elektrik, Su, Gaz)', 'ev-faturalari', 'utility', 'zap', 'yellow', 7],
                    ['Apartman / Site Aidatı', 'apartman-aidati', 'utility', 'home', 'orange', 8],
                    ['Ev İnterneti & TV Platformu', 'ev-interneti', 'telecom', 'wifi', 'cyan', 9],
                    ['Mobil Hat & GSM', 'mobil-hat', 'telecom', 'smartphone', 'emerald', 10],
                    ['Tamamlayıcı Sağlık Sigortası (TSS)', 'tamamlayici-saglik-sigortasi', 'insurance', 'activity', 'emerald', 11],
                    ['Kasko & Trafik Sigortası', 'kasko-trafik-sigortasi', 'insurance', 'shield', 'blue', 12],
                    ['Bireysel Emeklilik (BES)', 'bireysel-emeklilik-bes', 'bes', 'trending-up', 'cyan', 13],
                    ['Araç Sigorta & MTV', 'arac-sigorta-mtv', 'vehicle', 'car', 'rose', 14],
                    ['Spor & Kişisel Bakım', 'spor-kisisel-bakim', 'general', 'activity', 'teal', 15]
                ];

                $catStmt = $pdo->prepare("INSERT IGNORE INTO `categories` (`name`, `slug`, `type`, `icon`, `color`, `sort_order`) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($defaultCategories as $c) {
                    $catStmt->execute($c);
                }

                // Varsayılan Kategori Türlerini Ekle
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

                // schema_migrations tablosunu v1..v17 ile başlangıçta işaretle
                $pdo->exec("
                    INSERT IGNORE INTO `schema_migrations` (`version`) VALUES 
                    ('v1_roles'), ('v2_locations'), ('v3_payer_permissions'), ('v4_autopay'),
                    ('v5_cards_insurance_bes'), ('v6_attachments'), ('v7_schema_sync'),
                    ('v8_final_sync'), ('v9_security_sync'), ('v10_session_invalidation'),
                    ('v11_location_access'), ('v12_shares_userid'), ('v13_cashback'),
                    ('v14_insurance_installments'), ('v15_category_types'),
                    ('v16_insurance_location_sync'), ('v17_card_color_fix'),
                    ('v18_categories_type_varchar_and_tax'), ('v19_price_history'),
                    ('v20_monthly_budget')
                ");

                // 5. Yönetici Kullanıcıyı Ekle
                $adminPassHash = password_hash($adminPass, PASSWORD_DEFAULT);
                $adminPinHash  = password_hash($adminPin, PASSWORD_DEFAULT);

                $userStmt = $pdo->prepare("INSERT INTO `users` (`username`, `email`, `password`, `master_pin_hash`, `role`, `status`, `full_name`, `password_changed_at`) VALUES (?, ?, ?, ?, 'ADMIN', 'active', ?, NOW())");
                $userStmt->execute([$adminUser, $adminEmail, $adminPassHash, $adminPinHash, 'Sistem Yöneticisi']);
                $adminId = $pdo->lastInsertId();

                // 6. Varsayılan Konumlar (Mekanlar)
                $defaultLocations = [
                    ['Evim', 'home', 'blue'],
                    ['Kişisel & Dijital', 'globe', 'purple'],
                    ['İş / Ofis', 'building', 'emerald'],
                    ['Yazlık', 'sun', 'amber']
                ];
                $locStmt = $pdo->prepare("INSERT INTO `locations` (`user_id`, `name`, `icon`, `color`) VALUES (?, ?, ?, ?)");
                foreach ($defaultLocations as $dl) {
                    $locStmt->execute([$adminId, $dl[0], $dl[1], $dl[2]]);
                }

                // 7. Varsayılan Ödeme Yöntemi, Kredi Kartı ve Bildirim Ayarı
                $pmStmt = $pdo->prepare("INSERT INTO `payment_methods` (`user_id`, `title`, `bank_name`, `card_type`, `is_auto_pay`) VALUES (?, ?, ?, ?, ?)");
                $pmStmt->execute([$adminId, 'Maaş Hesabı (Vadesiz)', 'Garanti BBVA', 'bank_transfer', 1]);
                $pmStmt->execute([$adminId, 'Fatura Hesabı', 'Enpara', 'bank_transfer', 1]);

                $ccStmt = $pdo->prepare("INSERT INTO `credit_cards` (`user_id`, `bank_name`, `card_name`, `card_network`, `card_last_4`, `statement_cut_day`, `due_day`, `limit_amount`, `color`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ccStmt->execute([$adminId, 'Garanti BBVA', 'Bonus Platinum', 'mastercard', '4892', 15, 25, 75000.00, 'from-slate-800 to-slate-950']);

                $notifStmt = $pdo->prepare("INSERT INTO `notification_settings` (`user_id`, `email_enabled`, `notify_trial_days`, `notify_commitment_days`, `notify_due_days`) VALUES (?, 1, '3,1', '30,15,3', '3,1')");
                $notifStmt->execute([$adminId]);

                // 7. .env Dosyasını Oluştur
                $envContent = "# -------------------------------------------------------------\n";
                $envContent .= "# ABONELİK VE FATURA TAKİP SİSTEMİ YAPILANDIRMASI\n";
                $envContent .= "# Kurulum Tarihi: " . date('Y-m-d H:i:s') . "\n";
                $envContent .= "# -------------------------------------------------------------\n\n";
                $envContent .= "APP_INSTALLED=true\n\n";
                $envContent .= "DB_HOST={$dbHost}\n";
                $envContent .= "DB_PORT={$dbPort}\n";
                $envContent .= "DB_NAME={$dbName}\n";
                $envContent .= "DB_USER={$dbUser}\n";
                $envContent .= "DB_PASS={$dbPass}\n\n";

                $envContent .= "APP_ENV=production\n";
                $envContent .= "APP_URL=" . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}" . dirname($_SERVER['SCRIPT_NAME']) . "\n";
                $envContent .= "APP_SECRET_KEY={$defaultAppSecret}\n";
                $envContent .= "MASTER_SALT={$defaultSalt}\n\n";

                $envContent .= "SMTP_HOST={$smtpHost}\n";
                $envContent .= "SMTP_PORT={$smtpPort}\n";
                $envContent .= "SMTP_USER={$smtpUser}\n";
                $envContent .= "SMTP_PASS={$smtpPass}\n";
                $envContent .= "SMTP_SECURE={$smtpSecure}\n";
                $envContent .= "MAIL_FROM_ADDRESS={$mailFrom}\n";
                $envContent .= "MAIL_FROM_NAME=\"{$mailFromName}\"\n";
                $envContent .= "NOTIFICATION_EMAIL={$adminEmail}\n\n";

                $envContent .= "CRON_SECRET={$cronSecret}\n";
                $envContent .= "DEFAULT_CURRENCY=TRY\n";

                file_put_contents(__DIR__ . '/.env', $envContent);

                // 8. Belge Yükleme Klasörünü, index.php ve Güvenlik (.htaccess) Dosyasını Oluştur
                $uploadDocDir = __DIR__ . '/uploads/documents';
                if (!is_dir($uploadDocDir)) {
                    @mkdir($uploadDocDir, 0755, true);
                }

                $indexDoc = $uploadDocDir . '/index.php';
                if (!file_exists($indexDoc)) {
                    @file_put_contents($indexDoc, "<?php\nheader(\"HTTP/1.1 403 Forbidden\");\nhttp_response_code(403);\nexit(\"403 Forbidden - Dogrudan erisim yasaktir.\");\n");
                }

                $htaccessDoc = $uploadDocDir . '/.htaccess';
                if (!file_exists($htaccessDoc)) {
                    $htaccessContent = "# Hassas Belge Klasörü - Doğrudan web erişimini tamamen engelle\n";
                    $htaccessContent .= "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n";
                    $htaccessContent .= "<IfModule !mod_authz_core.c>\n    Order Deny,Allow\n    Deny from all\n</IfModule>\n\n";
                    $htaccessContent .= "# PHP scriptlerinin doğrudan çalıştırılmasını engelle\n";
                    $htaccessContent .= "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phps|pht|phar|inc|cgi|pl|py|sh|exe|asp|aspx)\$\">\n";
                    $htaccessContent .= "    Order Allow,Deny\n    Deny from all\n";
                    $htaccessContent .= "</FilesMatch>\n\n";
                    $htaccessContent .= "<IfModule mod_php7.c>\n    php_flag engine off\n</IfModule>\n";
                    $htaccessContent .= "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n";
                    $htaccessContent .= "<IfModule mod_mime.c>\n    RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps .pht .phar\n</IfModule>\n";
                    $htaccessContent .= "SetHandler default-handler\n";
                    @file_put_contents($htaccessDoc, $htaccessContent);
                }

                $success = true;
            } catch (Exception $e) {
                $error = 'Kurulum Hatası: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kurulum Sihirbazı - Abonelik ve Fatura Takip</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
        if (localStorage.getItem('color-theme') === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    <link rel="icon" type="image/png" href="icons/favicon.png">
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen py-8 px-4 flex flex-col items-center justify-center transition-colors">

<div class="max-w-2xl w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
    
    <!-- Başlık & Logo -->
    <div class="flex items-center gap-3.5 mb-6 pb-6 border-b border-slate-800">
        <div class="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center text-white font-bold text-xl shadow-lg shadow-blue-500/20">
            AT
        </div>
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-white">Abonelik & Fatura Takip</h1>
            <p class="text-xs sm:text-sm text-slate-400">Otomatik Web Kurulum Sihirbazı (Turnkey Installer)</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="p-6 bg-emerald-500/10 border border-emerald-500/20 rounded-2xl text-center space-y-4">
            <div class="w-16 h-16 bg-emerald-500/20 text-emerald-400 rounded-full flex items-center justify-center mx-auto text-3xl font-bold">✓</div>
            <h2 class="text-xl font-bold text-white">Kurulum Başarıyla Tamamlandı!</h2>
            <p class="text-sm text-slate-300">
                Veritabanı tabloları, varsayılan 12 abonelik kategorisi, şifreleme anahtarları ve <code>.env</code> yapılandırma dosyası başarıyla oluşturuldu.
            </p>
            <div class="p-4 bg-slate-950/60 rounded-xl text-left text-xs font-mono text-slate-400 space-y-1">
                <div><span class="text-slate-500">Yönetici Kullanıcı:</span> <strong class="text-slate-200"><?= htmlspecialchars($_POST['admin_user']) ?></strong></div>
                <div><span class="text-slate-500">Yönetici E-Posta:</span> <strong class="text-slate-200"><?= htmlspecialchars($_POST['admin_email']) ?></strong></div>
                <div><span class="text-slate-500">Master PIN:</span> <strong class="text-emerald-400">•••• (Kaydedildi)</strong></div>
                <div><span class="text-slate-500">Cron Token:</span> <strong class="text-amber-400 font-mono"><?= htmlspecialchars($_POST['cron_secret']) ?></strong></div>
            </div>
            <a href="login.php" class="inline-block w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-sm transition shadow-lg shadow-blue-600/20">
                Giriş Ekranına Git →
            </a>
        </div>
    <?php else: ?>

        <?php if ($error): ?>
            <div class="mb-6 p-4 bg-rose-500/10 border border-rose-500/20 rounded-2xl text-rose-400 text-sm flex items-center gap-3">
                <span class="text-lg">⚠</span>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Sistem Uyumluluk Tablosu -->
        <div class="mb-6 p-4 bg-slate-950/60 border border-slate-800 rounded-2xl">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">Sistem Uyumluluk Kontrolleri</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                <?php foreach ($requirements as $title => $passed): ?>
                    <div class="flex items-center justify-between p-2 bg-slate-900/80 rounded-lg">
                        <span class="text-slate-300"><?= $title ?></span>
                        <span class="font-bold <?= $passed ? 'text-emerald-400' : 'text-rose-400' ?>">
                            <?= $passed ? '✓ Uygun' : '✗ Başarısız' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <form method="POST" class="space-y-6">
            
            <!-- 1. Veritabanı Bilgileri -->
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-blue-400 mb-3 flex items-center gap-2">
                    <span>1. MySQL Veritabanı Bağlantısı</span>
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Sunucu Adresi (Host)</label>
                        <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Port</label>
                        <input type="text" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>" required
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Veritabanı Adı</label>
                        <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'abonelik_takip') ?>" required
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Kullanıcı Adı</label>
                        <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>" required
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Şifre</label>
                        <input type="password" name="db_pass" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>" placeholder="Varsa şifreniz"
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                </div>
            </div>

            <!-- 2. Yönetici & Güvenlik PIN -->
            <div class="pt-4 border-t border-slate-800">
                <h3 class="text-xs font-bold uppercase tracking-wider text-purple-400 mb-3 flex items-center gap-2">
                    <span>2. Yönetici Hesabı & Master Güvenlik PIN</span>
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Yönetici Kullanıcı Adı</label>
                        <input type="text" name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>" required
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-purple-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Yönetici E-Posta (Bildirim Alıcısı)</label>
                        <input type="email" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required placeholder="ornek@domain.com"
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-purple-500 outline-none">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Yönetici Giriş Şifresi</label>
                        <input type="password" name="admin_pass" required minlength="6" placeholder="En az 6 karakter"
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-purple-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Master PIN (Şifre Çözme PIN Kodu)</label>
                        <input type="password" name="admin_pin" required minlength="4" maxlength="8" placeholder="Örn: 1453"
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-purple-300 focus:ring-2 focus:ring-purple-500 outline-none font-mono">
                        <p class="text-[11px] text-slate-500 mt-1">Dijital hesap şifrelerini (Netflix, Disney vb.) görüntülemek için kullanılacak anahtar PIN.</p>
                    </div>
                </div>
            </div>

            <!-- 3. Cron & Güvenlik -->
            <div class="pt-4 border-t border-slate-800">
                <h3 class="text-xs font-bold uppercase tracking-wider text-amber-400 mb-3 flex items-center gap-2">
                    <span>3. Cron Bildirim Güvenlik Anahtarı</span>
                </h3>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Cron Güvenlik Tokenı (CRON_SECRET)</label>
                    <input type="text" name="cron_secret" value="<?= htmlspecialchars($_POST['cron_secret'] ?? $defaultCronSecret) ?>" required
                        class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-amber-300 focus:ring-2 focus:ring-amber-500 outline-none font-mono">
                    <p class="text-[11px] text-slate-500 mt-1">cron-mail.php çağrıldığında yetkisiz erişimi engeller. Sizin için güvenli bir anahtar üretilmiştir.</p>
                </div>
            </div>

            <!-- 4. SMTP E-Posta Ayarları (Opsiyonel) -->
            <div class="pt-4 border-t border-slate-800">
                <h3 class="text-xs font-bold uppercase tracking-wider text-emerald-400 mb-3 flex items-center gap-2">
                    <span>4. SMTP Mail Sunucusu (Opsiyonel - Sonradan da girilebilir)</span>
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-slate-300 mb-1">SMTP Host</label>
                        <input type="text" name="smtp_host" value="<?= htmlspecialchars($_POST['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com"
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-emerald-500 outline-none font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Port</label>
                        <input type="number" name="smtp_port" value="<?= htmlspecialchars($_POST['smtp_port'] ?? '587') ?>" placeholder="587"
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-emerald-500 outline-none font-mono">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">SMTP Kullanıcı</label>
                        <input type="text" name="smtp_user" value="<?= htmlspecialchars($_POST['smtp_user'] ?? '') ?>" placeholder="bildirim@domain.com"
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-emerald-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">SMTP Şifre</label>
                        <input type="password" name="smtp_pass" value="<?= htmlspecialchars($_POST['smtp_pass'] ?? '') ?>" placeholder="Şifre veya Uygulama Şifresi"
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-emerald-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Güvenlik Türü</label>
                        <select name="smtp_secure" class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-emerald-500 outline-none">
                            <option value="tls" <?= (($_POST['smtp_secure'] ?? 'tls') === 'tls') ? 'selected' : '' ?>>TLS (Önerilen - 587)</option>
                            <option value="ssl" <?= (($_POST['smtp_secure'] ?? '') === 'ssl') ? 'selected' : '' ?>>SSL (465)</option>
                            <option value="none" <?= (($_POST['smtp_secure'] ?? '') === 'none') ? 'selected' : '' ?>>None (Şifresiz)</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Gönderen E-Posta</label>
                        <input type="text" name="mail_from_address" value="<?= htmlspecialchars($_POST['mail_from_address'] ?? '') ?>" placeholder="bildirim@domain.com"
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-emerald-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Gönderen İsmi</label>
                        <input type="text" name="mail_from_name" value="<?= htmlspecialchars($_POST['mail_from_name'] ?? 'Abonelik Takip') ?>"
                            class="w-full p-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:ring-2 focus:ring-emerald-500 outline-none">
                    </div>
                </div>
            </div>

            <button type="submit" name="kurulum_yap" value="1"
                class="w-full py-4 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white rounded-2xl font-bold text-sm transition shadow-xl shadow-blue-600/30 flex items-center justify-center gap-2">
                <span>🚀 Kurulumu Başlat ve .env Dosyasını Oluştur</span>
            </button>
        </form>
    <?php endif; ?>

</div>

</body>
</html>
