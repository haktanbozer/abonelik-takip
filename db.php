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
    $currentScriptName = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    if ($currentScriptName !== 'ajax.php') {
        header('Content-Type: text/html; charset=UTF-8');
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    $isHttpsCheck = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if ($isHttpsCheck) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
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

// Oturum Çerez Adını Projeye Özel Yap (Aynı domaindeki stok takip vb. projelerle çakışmayı %100 engeller)
if (session_status() === PHP_SESSION_NONE) {
    session_name('ABONELIK_SESSID');
}

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
                $deger = stripcslashes($m[1]);
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

    // 5.1. Şema ve Migration Yönetimi (Performanslı ve merkezi schema_migrations sistemi)
    if (file_exists(__DIR__ . '/migrations.php')) {
        if (empty($_SESSION['_abonelik_schema_version']) || $_SESSION['_abonelik_schema_version'] < 20) {
            require_once __DIR__ . '/migrations.php';
            if (function_exists('semaGuncelle')) {
                semaGuncelle($pdo);
            }
        }
    }

    // 5.2. categories.type ENUM kısıtlamasını kaldır ve Vergi türüyle senkronize et
    try {
        // ENUM kısıtlamasını kaldırarak VARCHAR(50) yap (yoksa 'tax' veya özel türler kaydedilemez)
        $pdo->exec("ALTER TABLE `categories` MODIFY COLUMN `type` VARCHAR(50) NOT NULL DEFAULT 'general'");

        // category_types tablosunda 'tax' türünün tanımlı olduğundan emin ol
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
        $pdo->exec("
            INSERT INTO `category_types` (`type_key`, `name`, `icon`, `badge_color`, `sort_order`) 
            VALUES ('tax', 'Vergi', '💰', 'rose', 8)
            ON DUPLICATE KEY UPDATE `name` = 'Vergi', `icon` = '💰', `badge_color` = 'rose'
        ");

        // Eğer veritabanında 'vergi' adında ayrı bir tür anahtarı açılmışsa, onu da 'tax' ile birleştir
        $pdo->exec("UPDATE `categories` SET `type` = 'tax' WHERE `type` = 'vergi'");
        $pdo->exec("DELETE FROM `category_types` WHERE `type_key` = 'vergi'");

        // Vergi / Maliye / GSS isimli kategorileri 'tax' türüne bağla (Motorlu Taşıtlar Vergisi araç grubunda kalmalı)
        $pdo->exec("
            UPDATE `categories` 
            SET `type` = 'tax' 
            WHERE (LOWER(`name`) LIKE '%vergi%' OR LOWER(`slug`) LIKE '%vergi%' OR LOWER(`name`) LIKE '%maliye%' OR `name` = 'GSS' OR LOWER(`slug`) = 'gss')
              AND LOWER(`name`) NOT LIKE '%motorlu%' AND LOWER(`slug`) NOT LIKE '%motorlu%' AND LOWER(`slug`) NOT LIKE '%mtv%'
        ");

        // Motorlu Taşıtlar Vergisi (MTV) kategorisini 'vehicle' (Araç) grubuna geri al
        $pdo->exec("
            UPDATE `categories` 
            SET `type` = 'vehicle' 
            WHERE LOWER(`name`) LIKE '%motorlu%' OR LOWER(`slug`) LIKE '%motorlu%' OR LOWER(`slug`) LIKE '%mtv%'
        ");

        // Varsa boş veya geçersiz türleri general yap
        $pdo->exec("UPDATE `categories` SET `type` = 'general' WHERE `type` = '' OR `type` IS NULL");

        // Vergi adlı mekanın ikonunu 'tax' olarak güncelle
        $pdo->exec("
            UPDATE `locations` 
            SET `icon` = 'tax' 
            WHERE (LOWER(`name`) LIKE '%vergi%' OR LOWER(`name`) LIKE '%tax%') 
              AND (`icon` = 'chart' OR `icon` = 'home' OR `icon` = '' OR `icon` IS NULL)
        ");

        // Fiyat ve zam geçmişi takip tablosu
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
        guvenliYonlendir("index.php?msg=unauthorized");
    }
}

/**
 * Güvenli Yönlendirme (Header'lar gönderilmiş olsa bile JS/Meta ile yönlendirir, beyaz sayfa üretmez)
 */
function guvenliYonlendir($url) {
    if (!headers_sent()) {
        header("Location: " . $url);
        exit;
    }
    echo '<script>window.location.replace(' . json_encode($url) . ');</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    exit;
}

// 8. AES-256 Şifreleme / Çözme Yardımcıları (Kullanıcı Adı & Şifre Saklama)
function sifrelemeAnahtariAl($legacy = false) {
    $secret = env('APP_SECRET_KEY', 'default_secret_key_32_characters');
    $salt   = env('MASTER_SALT', 'default_salt_string');
    if ($legacy) {
        return hash('sha256', $secret . $salt, true);
    }
    // PBKDF2 ile 10.000 iterasyonlu endüstri standardı KDF anahtar türetimi
    return hash_pbkdf2('sha256', $secret, $salt, 10000, 32, true);
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
    $decodedSifreli = base64_decode($sifreliMetin, true);
    $decodedIv = base64_decode($iv, true);
    if ($decodedSifreli === false || $decodedIv === false || strlen($decodedIv) !== 16) {
        // Ham veri veya geçersiz IV durumunda uyarı vermeden dene
        $rawIv = (string)base64_decode($iv);
        if (strlen($rawIv) === 16) {
            $plain = @openssl_decrypt($sifreliMetin, 'AES-256-CBC', sifrelemeAnahtariAl(), 0, $rawIv);
            if ($plain !== false) return $plain;
            $plain = @openssl_decrypt($sifreliMetin, 'AES-256-CBC', sifrelemeAnahtariAl(true), 0, $rawIv);
            if ($plain !== false) return $plain;
        }
        return '';
    }
    // 1. Önce modern PBKDF2 anahtarı ile çözmeyi dene
    $plain = @openssl_decrypt($decodedSifreli, 'AES-256-CBC', sifrelemeAnahtariAl(), 0, $decodedIv);
    if ($plain === false) {
        $plain = @openssl_decrypt($sifreliMetin, 'AES-256-CBC', sifrelemeAnahtariAl(), 0, $decodedIv);
    }
    // 2. Başarısız olursa eski (SHA-256) anahtar ile çözmeyi dene (Mevcut kayıtlar için %100 kesintisiz uyumluluk)
    if ($plain === false || $plain === '') {
        $plain = @openssl_decrypt($decodedSifreli, 'AES-256-CBC', sifrelemeAnahtariAl(true), 0, $decodedIv);
        if ($plain === false) {
            $plain = @openssl_decrypt($sifreliMetin, 'AES-256-CBC', sifrelemeAnahtariAl(true), 0, $decodedIv);
        }
    }
    return $plain !== false ? $plain : '';
}

// 9. Master PIN Kontrolü (Dijital Şifreleri Görmek İçin)
function masterPinDogrula($pin) {
    global $pdo;
    if (!isset($_SESSION['user_id'])) return false;
    try {
        $stmt = $pdo->prepare("SELECT password, master_pin_hash FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
        if (!$row) return false;

        // 1. Kullanicinin Master PIN'i varsa onunla dogrula
        if (!empty($row['master_pin_hash']) && password_verify($pin, $row['master_pin_hash'])) {
            $_SESSION['pin_verified_until'] = time() + 900; // 15 dakika boyunca kilit acik kalir
            return true;
        }

        // 2. Master PIN tanimli degilse veya kullanici giris sifresini girdiyse hesap sifresiyle dogrula
        if (password_verify($pin, $row['password'])) {
            $_SESSION['pin_verified_until'] = time() + 900;
            return true;
        }
    } catch (Exception $e) {
        sistemLogla("Master PIN doğrulama hatası: " . $e->getMessage(), 'ERROR');
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

    try {
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
    } catch (Exception $e) {
        return false;
    }
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
    
    // Sistemde tanımlı tüm bağlı türleri dinamik al
    $allRegisteredTypes = ['digital', 'utility', 'telecom', 'insurance', 'bes', 'vehicle', 'general', 'tax'];
    try {
        $dbTypes = $pdo->query("SELECT type_key FROM category_types ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($dbTypes)) {
            $allRegisteredTypes = $dbTypes;
        }
    } catch (Exception $e) {}

    if (empty($locationOrId)) {
        return $allRegisteredTypes;
    }

    $loc = is_array($locationOrId) ? $locationOrId : null;
    if (!$loc && is_numeric($locationOrId)) {
        $stmt = $pdo->prepare("SELECT * FROM locations WHERE id = ?");
        $stmt->execute([(int)$locationOrId]);
        $loc = $stmt->fetch();
    }
    if (!$loc) {
        return $allRegisteredTypes;
    }

    $icon = strtolower($loc['icon'] ?? '');
    $cleanName = str_replace(['İ', 'I', 'ı'], ['i', 'i', 'i'], $loc['name'] ?? '');
    $cleanName = mb_strtolower($cleanName, 'UTF-8');

    // 1. Vergi / Maliye / Harç / KDV (İkon veya Mekan Adı)
    if ($icon === 'tax' || strpos($icon, '💰') !== false || strpos($cleanName, 'vergi') !== false || strpos($cleanName, 'maliye') !== false || 
        strpos($cleanName, 'harc') !== false || strpos($cleanName, 'harç') !== false || 
        strpos($cleanName, 'kdv') !== false || strpos($cleanName, 'stopaj') !== false || 
        strpos($cleanName, 'tax') !== false) {
        return ['tax', 'vergi'];
    }

    // 2. Ev / Fiziksel / Konut / Ofis / Şehirler (İstanbul, Sakarya vb.) -> Ev Faturaları & Telekom
    if (in_array($icon, ['home', 'building', 'sun', '🏠', '🏡', '🏢', '🏖️']) || 
        strpos($cleanName, 'ev') !== false || strpos($cleanName, 'konut') !== false || 
        strpos($cleanName, 'daire') !== false || strpos($cleanName, 'apartman') !== false ||
        strpos($cleanName, 'istanbul') !== false || strpos($cleanName, 'sakarya') !== false ||
        strpos($cleanName, 'ofis') !== false || strpos($cleanName, 'yazlık') !== false ||
        strpos($cleanName, 'yazlik') !== false) {
        return ['utility', 'telecom'];
    }

    // 3. Dijital / Bulut / Servis
    if (in_array($icon, ['globe', 'cloud', '💻', '🌐', '☁️']) || strpos($cleanName, 'dijital') !== false || strpos($cleanName, 'digital') !== false || strpos($cleanName, 'online') !== false || strpos($cleanName, 'bulut') !== false) {
        return ['digital'];
    }

    // 4. Sigorta / Poliçe / Kasko
    if (in_array($icon, ['shield', '🛡️', '🛡']) || strpos($cleanName, 'sigorta') !== false || strpos($cleanName, 'kasko') !== false || strpos($cleanName, 'poliçe') !== false || strpos($cleanName, 'police') !== false || strpos($cleanName, 'dask') !== false) {
        return ['insurance'];
    }

    // 5. Bireysel Emeklilik (BES) / Finans
    if (in_array($icon, ['chart', 'trending-up', '📈', '📊']) || strpos($cleanName, 'bes') !== false || strpos($cleanName, 'emeklilik') !== false || strpos($cleanName, 'fon') !== false) {
        return ['bes'];
    }

    // 6. Araç / Taşıt
    if (in_array($icon, ['car', '🚗', '🚙', '🚘']) || strpos($cleanName, 'araç') !== false || strpos($cleanName, 'arac') !== false || strpos($cleanName, 'araba') !== false || strpos($cleanName, 'taşıt') !== false || strpos($cleanName, 'tasit') !== false) {
        return ['vehicle'];
    }

    // 7. Özel Tanımlı Bağlı Tür Eşleşmesi (category_types tablosundan dinamik kontrol)
    try {
        $checkCustom = $pdo->prepare("SELECT type_key FROM category_types WHERE LOWER(type_key) = ? OR LOWER(name) LIKE ?");
        $checkCustom->execute([$cleanName, "%{$cleanName}%"]);
        $customType = $checkCustom->fetchColumn();
        if ($customType) {
            return [$customType, 'general'];
        }
    } catch (Exception $e) {}

    return $allRegisteredTypes;
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

// 10.4. Abonelik Fiyat & Zam Geçmişini Getir
function abonelikFiyatGecmisi($subscriptionId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT h.*, u.full_name as user_name
            FROM subscription_price_history h
            LEFT JOIN users u ON h.user_id = u.id
            WHERE h.subscription_id = ?
            ORDER BY h.changed_at DESC, h.id DESC
        ");
        $stmt->execute([(int)$subscriptionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// 10.5. Aboneliğin Son 1 Yıldaki Fiyat Değişimini Hesapla
function abonelikSon1YilZamOrani($subscriptionId, $currentPrice) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT old_price, new_price, changed_at 
            FROM subscription_price_history 
            WHERE subscription_id = ? AND changed_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
            ORDER BY changed_at ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute([(int)$subscriptionId]);
        $firstChange = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($firstChange && (float)$firstChange['old_price'] > 0) {
            $basePrice = (float)$firstChange['old_price'];
            $current = (float)$currentPrice;
            $diff = $current - $basePrice;
            if (abs($diff) >= 0.01) {
                $pct = round(($diff / $basePrice) * 100, 1);
                return [
                    'has_hike' => true,
                    'percentage' => $pct,
                    'is_increase' => ($pct > 0),
                    'base_price' => $basePrice,
                    'current_price' => $current,
                    'start_date' => $firstChange['changed_at']
                ];
            }
        }
    } catch (Exception $e) {}
    return ['has_hike' => false];
}

// 10.6. Atıl Abonelik ve Tasarruf Dedektörü: İptal Edilebilecek / Tasarruf Sağlanabilecek Servisleri Listele
function tasarrufOnerileriniGetir($userId = null, $isAdmin = false, $locationId = null) {
    global $pdo;
    $items = [];
    $savingsByCurrency = [];

    try {
        $locSql = "";
        $params = [];
        if (!empty($locationId)) {
            $locSql = " AND s.location_id = ? ";
            $params[] = (int)$locationId;
        }

        $authSql = "";
        if (!$isAdmin && !empty($userId)) {
            $authSql = " AND (s.user_id = ? OR s.payer_user_id = ? OR EXISTS (SELECT 1 FROM subscription_access sa WHERE sa.subscription_id = s.id AND sa.user_id = ?)) ";
            $params[] = (int)$userId;
            $params[] = (int)$userId;
            $params[] = (int)$userId;
        }

        // 1. Dondurulmuş / Askıya Alınmış Abonelikler (Atıl Servisler)
        $qPaused = "
            SELECT s.id, s.title, s.price, s.currency, s.billing_cycle, s.status, s.icon, s.color,
                   c.name as category_name, c.type as category_type
            FROM subscriptions s
            LEFT JOIN categories c ON s.category_id = c.id
            WHERE s.status = 'paused' {$locSql} {$authSql}
            ORDER BY s.price DESC
        ";
        $stmtP = $pdo->prepare($qPaused);
        $stmtP->execute($params);
        while ($row = $stmtP->fetch(PDO::FETCH_ASSOC)) {
            $price = (float)$row['price'];
            $curr = $row['currency'] ?: 'TRY';
            $mult = match($row['billing_cycle']) {
                'annual' => 1,
                'quarterly' => 4,
                'semi_annual' => 2,
                'one_time' => 0,
                default => 12
            };
            $annualSavings = $price * $mult;
            if ($annualSavings > 0) {
                $savingsByCurrency[$curr] = ($savingsByCurrency[$curr] ?? 0) + $annualSavings;
                $items[] = [
                    'id' => (int)$row['id'],
                    'title' => $row['title'],
                    'category_name' => $row['category_name'] ?: 'Abonelik',
                    'icon' => $row['icon'] ?: 'pause-circle',
                    'color' => $row['color'] ?: 'amber',
                    'type' => 'paused',
                    'badge' => '⏸️ Dondurulmuş (Atıl)',
                    'badge_class' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20',
                    'annual_savings' => $annualSavings,
                    'currency' => $curr,
                    'recommendation' => 'Bu servis dondurulmuş / atıl durumda. Tamamen iptal ederseniz yılda ' . paraFormat($annualSavings, $curr) . ' tasarruf edersiniz.',
                    'can_cancel_direct' => true
                ];
            }
        }

        // 2. Deneme Süresi Bitmek Üzere veya Yeni Dolmuş Olanlar (Tasarruf Fırsatı)
        $qTrial = "
            SELECT s.id, s.title, s.price, s.currency, s.billing_cycle, s.trial_end_date, s.icon, s.color,
                   c.name as category_name,
                   DATEDIFF(s.trial_end_date, CURRENT_DATE) as gun_farki
            FROM subscriptions s
            LEFT JOIN categories c ON s.category_id = c.id
            WHERE s.status = 'active' AND s.is_trial = 1
              AND s.trial_end_date IS NOT NULL
              AND s.trial_end_date <= DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)
              {$locSql} {$authSql}
            ORDER BY s.trial_end_date ASC
        ";
        $stmtT = $pdo->prepare($qTrial);
        $stmtT->execute($params);
        while ($row = $stmtT->fetch(PDO::FETCH_ASSOC)) {
            $price = (float)$row['price'];
            $curr = $row['currency'] ?: 'TRY';
            $mult = match($row['billing_cycle']) {
                'annual' => 1,
                'quarterly' => 4,
                'semi_annual' => 2,
                'one_time' => 0,
                default => 12
            };
            $annualSavings = $price * $mult;
            $gunFarki = (int)$row['gun_farki'];
            $sureMetin = ($gunFarki < 0) ? abs($gunFarki) . ' gün önce doldu' : ($gunFarki === 0 ? 'bugün doluyor' : $gunFarki . ' gün kaldı');
            if ($annualSavings > 0) {
                $savingsByCurrency[$curr] = ($savingsByCurrency[$curr] ?? 0) + $annualSavings;
                $items[] = [
                    'id' => (int)$row['id'],
                    'title' => $row['title'],
                    'category_name' => $row['category_name'] ?: 'Deneme Sürümü',
                    'icon' => $row['icon'] ?: 'clock',
                    'color' => $row['color'] ?: 'purple',
                    'type' => 'trial_ending',
                    'badge' => '⏳ Deneme Bitişi (' . $sureMetin . ')',
                    'badge_class' => 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20',
                    'annual_savings' => $annualSavings,
                    'currency' => $curr,
                    'recommendation' => 'Deneme süresi ' . $sureMetin . '. Kullanmayacaksanız iptal ederek yılda ' . paraFormat($annualSavings, $curr) . ' tasarruf edebilirsiniz.',
                    'can_cancel_direct' => true
                ];
            }
        }

        // 3. Taahhüt Bitişi Yaklaşan ve Taahhütsüz Fiyatı Yüksek Olanlar (Zam Önleme Tasarrufu)
        $qComm = "
            SELECT s.id, s.title, s.price, s.currency, s.icon, s.color,
                   tel.post_commitment_price, tel.commitment_end, tel.operator_name,
                   c.name as category_name,
                   DATEDIFF(tel.commitment_end, CURRENT_DATE) as gun_farki
            FROM subscriptions s
            JOIN subscription_telecom_details tel ON s.id = tel.subscription_id
            LEFT JOIN categories c ON s.category_id = c.id
            WHERE s.status = 'active'
              AND tel.commitment_end IS NOT NULL
              AND tel.commitment_end <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY)
              AND tel.post_commitment_price > s.price
              {$locSql} {$authSql}
            ORDER BY tel.commitment_end ASC
        ";
        $stmtC = $pdo->prepare($qComm);
        $stmtC->execute($params);
        while ($row = $stmtC->fetch(PDO::FETCH_ASSOC)) {
            $oldP = (float)$row['price'];
            $newP = (float)$row['post_commitment_price'];
            $fark = $newP - $oldP;
            $curr = $row['currency'] ?: 'TRY';
            $annualRisk = $fark * 12;
            $gunFarki = (int)$row['gun_farki'];
            $sureMetin = ($gunFarki < 0) ? abs($gunFarki) . ' gün önce doldu' : ($gunFarki === 0 ? 'bugün bitiyor' : $gunFarki . ' gün kaldı');
            if ($annualRisk > 0) {
                $savingsByCurrency[$curr] = ($savingsByCurrency[$curr] ?? 0) + $annualRisk;
                $items[] = [
                    'id' => (int)$row['id'],
                    'title' => $row['title'],
                    'category_name' => $row['operator_name'] ?: 'Telekom',
                    'icon' => $row['icon'] ?: 'wifi',
                    'color' => $row['color'] ?: 'rose',
                    'type' => 'commitment_ending',
                    'badge' => '⚡ Taahhüt Zammı Riski (' . $sureMetin . ')',
                    'badge_class' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20',
                    'annual_savings' => $annualRisk,
                    'currency' => $curr,
                    'recommendation' => 'Taahhüt bitince fiyat ' . paraFormat($newP, $curr) . ' olacak. Yenileme veya iptal ile yılda ' . paraFormat($annualRisk, $curr) . ' ek maliyeti önleyebilirsiniz.',
                    'can_cancel_direct' => false
                ];
            }
        }

    } catch (Exception $e) {
        sistemLogla("Tasarruf önerileri hesaplama hatası: " . $e->getMessage(), 'ERROR');
    }

    return [
        'total_annual_savings' => $savingsByCurrency,
        'count' => count($items),
        'items' => $items
    ];
}

// 11. CSP NONCE
if (!isset($cspNonce)) {
    try {
        $cspNonce = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        $cspNonce = bin2hex(openssl_random_pseudo_bytes(16));
    }
}
