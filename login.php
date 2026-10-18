<?php
require_once __DIR__ . '/db.php';

// Zaten oturum açmışsa yönlendir
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';
$infoMsg = '';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'logout') {
        $infoMsg = 'Oturumunuz başarıyla kapatıldı.';
    } elseif ($_GET['msg'] === 'inactive') {
        $error = 'Hesabınız yönetici tarafından dondurulmuştur. Lütfen yöneticinizle iletişime geçin.';
    } elseif ($_GET['msg'] === 'unauthorized') {
        $error = 'Bu sayfaya erişim için yönetici (Admin) yetkisi gerekmektedir.';
    } elseif ($_GET['msg'] === 'session_expired') {
        $error = 'Şifreniz değiştirildiği için güvenlik nedeniyle mevcut oturumunuz sonlandırıldı. Lütfen yeni şifrenizle tekrar giriş yapın.';
    }
}

$ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$limit = 5;
$lockout_minutes = 15;

// Brute-force tablosu yoksa oluştur
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `login_attempts` (
            `ip_address` VARCHAR(45) PRIMARY KEY,
            `username` VARCHAR(50),
            `attempts` INT DEFAULT 1,
            `last_attempt` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `locked_until` DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $stmt = $pdo->prepare("SELECT attempts, locked_until FROM login_attempts WHERE ip_address = ?");
    $stmt->execute([$ip_address]);
    $attempt = $stmt->fetch();

    if ($attempt && !empty($attempt['locked_until']) && strtotime($attempt['locked_until']) > time()) {
        $kalan_dakika = ceil((strtotime($attempt['locked_until']) - time()) / 60);
        $error = "Çok fazla hatalı giriş denemesi! Hesabınız güvenlik sebebiyle {$kalan_dakika} dakika kilitlendi.";
    }
} catch (Exception $e) {
    sistemLogla("Login attempts tablosu hatası: " . $e->getMessage(), 'ERROR');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($attempt['locked_until'])) {
    csrfKontrol($_POST['csrf_token'] ?? '');

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember_me']);

    if (empty($username) || empty($password)) {
        $error = 'Lütfen kullanıcı adı ve şifrenizi girin.';
    } else {
        $stmt = $pdo->prepare("SELECT id, username, email, password, full_name, role, status FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if (($user['status'] ?? 'active') === 'inactive') {
                $error = 'Hesabınız askıya alınmıştır. Lütfen sistem yöneticisi ile görüşün.';
                sistemLogla("Pasif Kullanıcı Giriş Denemesi: {$user['username']}", 'WARNING');
            } else {
                // Başarılı giriş: Brute force kaydını temizle
                $del = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
                $del->execute([$ip_address]);

                // Session yenile
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['full_name'] ?? $user['username'];
                $_SESSION['role'] = $user['role'] ?? 'USER';
                $_SESSION['login_time'] = time();

                sistemLogla("Başarılı Giriş: {$user['username']} ({$user['role']}) ({$ip_address})", 'AUTH');

                header("Location: secim.php");
                exit;
            }
        } else {
            // Hatalı giriş
            $current_attempts = $attempt ? (int)$attempt['attempts'] : 0;
            $new_count = $current_attempts + 1;

            if ($new_count >= $limit) {
                $locked_until = date('Y-m-d H:i:s', strtotime("+{$lockout_minutes} minutes"));
                $stmtLock = $pdo->prepare("INSERT INTO login_attempts (ip_address, username, attempts, last_attempt, locked_until) VALUES (?, ?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE attempts = ?, last_attempt = NOW(), locked_until = ?");
                $stmtLock->execute([$ip_address, $username, $new_count, $locked_until, $new_count, $locked_until]);
                $error = "5 kez hatalı giriş yapıldı! Güvenliğiniz için sistem {$lockout_minutes} dakika kilitlendi.";
                sistemLogla("Brute-Force Şüphesi Kilitlendi: {$username} ({$ip_address})", 'SECURITY');
            } else {
                $stmtInsert = $pdo->prepare("INSERT INTO login_attempts (ip_address, username, attempts, last_attempt) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()");
                $stmtInsert->execute([$ip_address, $username, 1]);
                $kalan = $limit - $new_count;
                $error = "Hatalı kullanıcı adı veya şifre! (Kalan deneme hakkı: {$kalan})";
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
    <title>Giriş Yap - Abonelik Takip</title>
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/png" href="icons/favicon.png">
    <link rel="apple-touch-icon" href="icons/icon-192x192.png">
    <meta name="theme-color" content="#0f172a">
    <?php if (file_exists(__DIR__ . '/dist/style.css')): ?>
        <link rel="stylesheet" href="dist/style.css?v=<?= filemtime(__DIR__ . '/dist/style.css') ?>">
    <?php else: ?>
        <script src="https://cdn.tailwindcss.com"></script>
        <script>tailwind.config = { darkMode: 'class' };</script>
    <?php endif; ?>
    <script>
        if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100 flex items-center justify-center min-h-screen relative p-4 transition-colors">

    <!-- Tema Değiştirici -->
    <button id="theme-toggle" type="button" class="absolute top-5 right-5 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white p-2.5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm transition">
        <svg id="theme-toggle-light-icon" class="hidden w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
        <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
    </button>

    <div class="bg-white dark:bg-slate-900 p-8 rounded-3xl shadow-xl w-full max-w-md border border-slate-200 dark:border-slate-800 transition-colors">
        
        <div class="text-center mb-8">
            <img src="icons/icon-192x192.png" alt="Abonelik ve Fatura Takip" class="w-16 h-16 rounded-2xl mx-auto mb-3 shadow-xl shadow-purple-500/20 object-cover hover:scale-105 transition-transform">
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">Abonelik Takip</h1>
            <p class="text-slate-500 dark:text-slate-400 text-xs mt-1">Dijital, Fatura ve Telekomünikasyon Yönetimi</p>
        </div>

        <?php if (!empty($infoMsg)): ?>
            <div class="bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-400 border border-blue-200 dark:border-blue-500/20 p-3.5 rounded-xl mb-5 text-center text-xs font-semibold">
                <?= htmlspecialchars($infoMsg) ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-400 border border-rose-200 dark:border-rose-500/20 p-3.5 rounded-xl mb-5 text-center text-xs font-semibold">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($attempt['locked_until']) || strtotime($attempt['locked_until']) < time()): ?>
        <form method="POST" class="space-y-4">
            <?= csrfAlaniniEkle() ?>
            <div>
                <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Kullanıcı Adı veya E-Posta</label>
                <input type="text" name="username" required autocomplete="username"
                    class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none text-slate-900 dark:text-white text-sm transition">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Şifre</label>
                <input type="password" name="password" required autocomplete="current-password"
                    class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none text-slate-900 dark:text-white text-sm transition">
            </div>
            <div class="flex items-center justify-between text-xs text-slate-600 dark:text-slate-400 pt-1">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="remember_me" class="rounded bg-slate-100 dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-blue-600 focus:ring-0">
                    <span>Beni Hatırla</span>
                </label>
            </div>
            <button type="submit"
                class="w-full bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-bold py-3.5 rounded-xl transition shadow-lg shadow-blue-600/30 text-sm">
                Güvenli Giriş Yap
            </button>
        </form>
        <?php else: ?>
            <div class="text-center py-4 space-y-3">
                <p class="text-slate-500 dark:text-slate-400 text-xs">Giriş denemeleri geçici olarak askıya alındı.</p>
                <button onclick="window.location.reload()" class="bg-slate-200 dark:bg-slate-800 text-slate-800 dark:text-slate-200 px-4 py-2 rounded-xl text-xs hover:bg-slate-300 dark:hover:bg-slate-700 transition">
                    Durumu Kontrol Et
                </button>
            </div>
        <?php endif; ?>

    </div>

    <script>
        var themeToggleDarkIcon = document.getElementById('theme-toggle-dark-icon');
        var themeToggleLightIcon = document.getElementById('theme-toggle-light-icon');

        function updateToggleIcons() {
            var isDark = document.documentElement.classList.contains('dark');
            if (isDark) {
                themeToggleLightIcon.classList.remove('hidden');
                themeToggleDarkIcon.classList.add('hidden');
            } else {
                themeToggleLightIcon.classList.add('hidden');
                themeToggleDarkIcon.classList.remove('hidden');
            }
        }
        updateToggleIcons();

        var themeToggleBtn = document.getElementById('theme-toggle');
        if (themeToggleBtn) {
            themeToggleBtn.addEventListener('click', function() {
                var isDark = document.documentElement.classList.contains('dark');
                if (isDark) {
                    document.documentElement.classList.remove('dark');
                    localStorage.setItem('color-theme', 'light');
                } else {
                    document.documentElement.classList.add('dark');
                    localStorage.setItem('color-theme', 'dark');
                }
                updateToggleIcons();
            });
        }

        // PWA Service Worker Kaydı
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('sw.js')
                    .catch(function(err) { console.warn('[PWA] SW Hatası:', err); });
            });
        }
    </script>
</body>
</html>
