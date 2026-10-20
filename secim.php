<?php
require_once __DIR__ . '/db.php';
girisKontrol();

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'USER';
$isAdmin = ($userRole === 'ADMIN');
$hata = '';


// 1. "Tum Mekanlar" Secildiyse
if (isset($_GET['hepsi'])) {
    $_SESSION['aktif_konum_id'] = null;
    $_SESSION['aktif_kart_id'] = null;
    $_SESSION['aktif_konum_ad'] = 'Tüm Mekanlar';
    header("Location: index.php");
    exit;
}

// 2. Belirli Bir Mekan Secildiyse
if (isset($_GET['sec'])) {
    $secilenId = (int)$_GET['sec'];
    if (!kullaniciKonumGorebilirMi($secilenId, $userId, $userRole)) {
        $hata = 'Bu mekana erişim yetkiniz bulunmuyor.';
        sistemLogla("Yetkisiz Mekan Seçimi Girişimi (IDOR): User ID {$userId}, Location ID {$secilenId}", 'SECURITY');
    } else {
        $stmtC = $pdo->prepare("SELECT id, name FROM locations WHERE id = ?");
        $stmtC->execute([$secilenId]);
        $konum = $stmtC->fetch();

        if ($konum) {
            $_SESSION['aktif_konum_id'] = $konum['id'];
            $_SESSION['aktif_kart_id'] = null;
            $_SESSION['aktif_konum_ad'] = $konum['name'];
            header("Location: index.php");
            exit;
        } else {
            $hata = 'Geçersiz mekan seçimi.';
        }
    }
}

// 3. Kart Secildiyse
if (isset($_GET['kart'])) {
    $secilenId = (int)$_GET['kart'];
    $stmtK = $pdo->prepare("SELECT id, bank_name, card_name FROM credit_cards WHERE id = ? AND user_id = ?");
    $stmtK->execute([$secilenId, $userId]);
    $kart = $stmtK->fetch();

    if ($kart) {
        $_SESSION['aktif_konum_id'] = null;
        $_SESSION['aktif_kart_id'] = $kart['id'];
        $_SESSION['aktif_konum_ad'] = $kart['bank_name'] . ' ' . $kart['card_name'];
        header("Location: index.php");
        exit;
    } else {
        $hata = 'Geçersiz kart seçimi.';
    }
}

// Mekanlari Yetkiye Gore ve Ilgili Abonelik Sayilariyla Cek
try {
    $userLocations = kullaniciKonumlariGetir($userId, $userRole);
    $userLocIds = array_column($userLocations, 'id');

    if ($isAdmin) {
        $stmt = $pdo->prepare("
            SELECT l.*, 
                   COUNT(s.id) as sub_count,
                   COALESCE(SUM(CASE
                                     WHEN c.type = 'insurance' AND ins.payment_type = 'installment' AND COALESCE(ins.installment_amount, 0) > 0 THEN ins.installment_amount
                                     WHEN s.billing_cycle = 'monthly' THEN s.price
                                     WHEN s.billing_cycle = 'annual' THEN s.price / 12 
                                     WHEN s.billing_cycle = 'quarterly' THEN s.price / 3 
                                     WHEN s.billing_cycle = 'semi_annual' THEN s.price / 6 
                                     WHEN s.billing_cycle = 'one_time' THEN 0 
                                     ELSE s.price END), 0) as monthly_total
            FROM locations l
            LEFT JOIN subscriptions s ON l.id = s.location_id AND s.status = 'active'
            LEFT JOIN categories c ON s.category_id = c.id
            LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
            GROUP BY l.id
            ORDER BY l.id ASC
        ");
        $stmt->execute();
        $konumlar = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        if (empty($userLocIds)) {
            $konumlar = [];
        } else {
            $inPlaceholders = implode(',', array_fill(0, count($userLocIds), '?'));
            $stmt = $pdo->prepare("
                SELECT l.*, 
                       COUNT(s.id) as sub_count,
                       COALESCE(SUM(CASE
                                         WHEN c.type = 'insurance' AND ins.payment_type = 'installment' AND COALESCE(ins.installment_amount, 0) > 0 THEN ins.installment_amount
                                         WHEN s.billing_cycle = 'monthly' THEN s.price
                                         WHEN s.billing_cycle = 'annual' THEN s.price / 12 
                                         WHEN s.billing_cycle = 'quarterly' THEN s.price / 3 
                                         WHEN s.billing_cycle = 'semi_annual' THEN s.price / 6 
                                         WHEN s.billing_cycle = 'one_time' THEN 0 
                                         ELSE s.price END), 0) as monthly_total
                FROM locations l
                LEFT JOIN subscriptions s ON l.id = s.location_id AND s.status = 'active' AND (s.user_id = ? OR s.payer_user_id = ?)
                LEFT JOIN categories c ON s.category_id = c.id
                LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
                WHERE l.id IN ($inPlaceholders)
                GROUP BY l.id
                ORDER BY l.id ASC
            ");
            $stmt->execute(array_merge([$userId, $userId], $userLocIds));
            $konumlar = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Hic mekan yoksa (ve admin ise) varsayilan 5 ana mekani olustur
    if (empty($konumlar) && $isAdmin) {
        $defaultLocations = [
            [$userId, 'Ev', 'home', 'blue'],
            [$userId, 'Dijital', 'globe', 'purple'],
            [$userId, 'Sigorta', 'shield', 'rose'],
            [$userId, 'BES', 'chart', 'cyan'],
            [$userId, 'Araç', 'car', 'amber']
        ];
        $locStmt = $pdo->prepare("INSERT INTO `locations` (`user_id`, `name`, `icon`, `color`) VALUES (?, ?, ?, ?)");
        foreach ($defaultLocations as $dl) {
            $locStmt->execute($dl);
        }
        header("Location: secim.php");
        exit;
    }

} catch (Exception $e) {
    sistemLogla("Mekan listesi cekme hatasi: " . $e->getMessage(), 'ERROR');
    $konumlar = [];
}

try {
    $stmtK = $pdo->prepare("
        SELECT c.*, 
               COUNT(s.id) as sub_count,
               COALESCE(SUM(CASE
                                 WHEN cat.type = 'insurance' AND ins.payment_type = 'installment' AND COALESCE(ins.installment_amount, 0) > 0 THEN ins.installment_amount
                                 WHEN s.billing_cycle = 'monthly' THEN s.price
                                 WHEN s.billing_cycle = 'annual' THEN s.price / 12 
                                 WHEN s.billing_cycle = 'quarterly' THEN s.price / 3 
                                 WHEN s.billing_cycle = 'semi_annual' THEN s.price / 6 
                                 WHEN s.billing_cycle = 'one_time' THEN 0 
                                 ELSE s.price END), 0) as monthly_total
        FROM credit_cards c
        LEFT JOIN subscriptions s ON c.id = s.credit_card_id AND s.status = 'active'
        LEFT JOIN categories cat ON s.category_id = cat.id
        LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
        WHERE c.user_id = ? AND c.is_active = 1
        GROUP BY c.id
        ORDER BY c.bank_name ASC
    ");
    $stmtK->execute([$userId]);
    $kartlar = $stmtK->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $kartlar = [];
}

$aktifKonumId = $_SESSION['aktif_konum_id'] ?? null;
$aktifKartId = $_SESSION['aktif_kart_id'] ?? null;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portföy ve Alan Seçimi - Abonelik Takip</title>
    <link rel="icon" type="image/png" href="icons/favicon.png">
    <?php if (file_exists(__DIR__ . '/dist/style.css')): ?>
        <link rel="stylesheet" href="dist/style.css?v=<?= filemtime(__DIR__ . '/dist/style.css') ?>">
    <?php else: ?>
        <script src="https://cdn.tailwindcss.com"></script>
        <script>tailwind.config = { darkMode: 'class' };</script>
    <?php endif; ?>
    <script>
        if (localStorage.getItem('color-theme') === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    <style> body { transition: background-color 0.3s, color 0.3s; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; } </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 flex flex-col items-center justify-center min-h-screen p-4 sm:p-6 transition-colors relative">

    <!-- Dark Mode Butonu -->
    <button id="theme-toggle" type="button" class="absolute top-5 right-5 p-2 rounded-xl text-slate-500 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-800 transition">
        <svg id="theme-toggle-light-icon" class="hidden w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
        <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5 text-slate-700 dark:text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
    </button>

    <!-- Baslik & Aciklama -->
    <div class="text-center mb-8 max-w-lg">
        <div class="inline-block mx-auto mb-3 relative group">
            <div class="absolute -inset-1 bg-gradient-to-r from-blue-600 to-indigo-600 rounded-2xl blur opacity-30 group-hover:opacity-60 transition duration-300"></div>
            <img src="icons/favicon.png" alt="Abonelik Takip Logo" class="relative w-14 h-14 sm:w-16 sm:h-16 rounded-2xl shadow-xl shadow-blue-500/20 object-cover border border-white/20 dark:border-slate-800">
        </div>
        <h1 class="text-2xl sm:text-3xl font-bold text-slate-800 dark:text-white tracking-tight mb-2">
            Hoş Geldiniz, <?= htmlspecialchars($_SESSION['username'] ?? '') ?> 👋
        </h1>
        <p class="text-slate-500 dark:text-slate-400 text-xs sm:text-sm">
            Yönetmek istediğiniz alanı veya portföyü seçiniz:
        </p>

        <?php if (!empty($hata)): ?>
            <div class="mt-3 p-3 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-300 rounded-xl text-xs font-semibold">
                <?= $hata ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Mekan Secim Kartlari Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 max-w-4xl w-full">
        
        <!-- Kart 1: Tum Mekanlar (Konsolide Genel Bakis) -->
        <a href="?hepsi=1" class="group bg-gradient-to-br from-blue-600 to-indigo-700 p-6 rounded-3xl shadow-xl hover:shadow-2xl transform hover:-translate-y-1 transition text-white text-center flex flex-col items-center justify-center h-48 relative overflow-hidden border border-blue-500/50">
            <?php if ($aktifKonumId === null && $aktifKartId === null): ?>
                <span class="absolute top-3 right-3 bg-white/20 text-white text-[10px] font-bold px-2 py-0.5 rounded-full">Aktif Seçim</span>
            <?php endif; ?>
            <span class="text-4xl mb-2 transition transform group-hover:scale-110">⭐</span>
            <span class="font-bold text-lg">Tüm Mekanlar</span>
            <span class="text-xs text-blue-100 mt-1">Konsolide Raporlama</span>
        </a>

        <!-- Dinamik Mekan Kartlari (Ev, Dijital, Sigorta, BES, Arac vb.) -->
        <?php foreach ($konumlar as $k): 
            $isCurrent = ($k['id'] === $aktifKonumId);
            $iconMap = [
                'home' => '🏠',
                'globe' => '💻',
                'shield' => '🛡️',
                'chart' => '📈',
                'tax' => '💰',
                'car' => '🚗',
                'building' => '🏢',
                'sun' => '🏖️',
                '💰' => '💰'
            ];
            $iconEmoji = $iconMap[$k['icon']] ?? (!empty($k['icon']) && mb_strlen($k['icon'], 'UTF-8') <= 4 && !ctype_alnum($k['icon']) ? $k['icon'] : '📍');
        ?>
        <a href="?sec=<?= htmlspecialchars($k['id']) ?>" 
            class="group bg-white dark:bg-slate-900 p-6 rounded-3xl shadow-sm hover:shadow-xl border <?= $isCurrent ? 'ring-2 ring-blue-500 border-blue-500 dark:border-blue-500' : 'border-slate-200 dark:border-slate-800' ?> transform hover:-translate-y-1 transition flex flex-col items-center justify-center h-48 relative">
            
            <?php if ($isCurrent): ?>
                <span class="absolute top-3 right-3 bg-blue-50 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-700 text-[10px] font-bold px-2 py-0.5 rounded-full">Aktif</span>
            <?php endif; ?>

            <span class="text-4xl mb-2 transition transform group-hover:scale-110"><?= $iconEmoji ?></span>
            
            <span class="font-bold text-slate-800 dark:text-white text-lg group-hover:text-blue-600 dark:group-hover:text-blue-400 transition">
                <?= htmlspecialchars($k['name']) ?>
            </span>

            <div class="flex items-center gap-2 mt-2 text-xs text-slate-500 dark:text-slate-400 font-medium">
                <span>📝 <?= (int)$k['sub_count'] ?> Abonelik</span>
                <span>&bull;</span>
                <span class="text-slate-700 dark:text-slate-200 font-bold"><?= paraFormat($k['monthly_total']) ?>/ay</span>
            </div>
        </a>
        <?php endforeach; ?>

    </div>

    <?php if (!empty($kartlar)): ?>
    <div class="mt-8 mb-4 text-center max-w-lg w-full">
        <h2 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider border-b border-slate-200 dark:border-slate-800 pb-2">💳 Kredi Kartı &amp; Ekstre Takibi</h2>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 max-w-4xl w-full">
        <?php foreach ($kartlar as $k): 
            $isCurrentCard = ($k['id'] === $aktifKartId);
        ?>
        <a href="?kart=<?= htmlspecialchars($k['id']) ?>" 
            class="group bg-white dark:bg-slate-900 p-6 rounded-3xl shadow-sm hover:shadow-xl border <?= $isCurrentCard ? 'ring-2 ring-purple-500 border-purple-500' : 'border-slate-200 dark:border-slate-800' ?> transform hover:-translate-y-1 transition flex flex-col items-center justify-center h-48 relative">
            
            <?php if ($isCurrentCard): ?>
                <span class="absolute top-3 right-3 bg-purple-50 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-700 text-[10px] font-bold px-2 py-0.5 rounded-full">Aktif</span>
            <?php endif; ?>

            <span class="text-4xl mb-2 transition transform group-hover:scale-110">💳</span>
            
            <span class="font-bold text-slate-800 dark:text-white text-lg group-hover:text-purple-600 dark:group-hover:text-purple-400 transition text-center leading-tight">
                <?= htmlspecialchars($k['bank_name']) ?><br>
                <span class="text-xs text-slate-500 dark:text-slate-400 font-normal"><?= htmlspecialchars($k['card_name']) ?> (&bull;&bull;&bull;&bull; <?= htmlspecialchars($k['card_last_4']) ?>)</span>
            </span>

            <div class="flex items-center gap-2 mt-3 text-xs text-slate-500 dark:text-slate-400 font-medium">
                <span>📝 <?= (int)$k['sub_count'] ?> Abonelik</span>
                <span>&bull;</span>
                <span class="text-slate-700 dark:text-slate-200 font-bold"><?= paraFormat($k['monthly_total']) ?>/ay</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Alt Linkler -->
    <div class="mt-10 text-xs text-slate-500 dark:text-slate-400 flex items-center gap-4">
        <?php if (isset($_SESSION['aktif_konum_id']) || isset($_SESSION['aktif_kart_id'])): ?>
            <a href="index.php" class="hover:text-slate-800 dark:hover:text-white transition font-semibold">&larr; Panele Dön</a>
            <span>&bull;</span>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
            <a href="mekan-yonetimi.php" class="hover:text-blue-600 dark:hover:text-blue-400 transition font-semibold">🏠 Mekanları Yönet</a>
            <span>&bull;</span>
            <a href="admin.php" class="hover:text-blue-600 dark:hover:text-blue-400 transition font-semibold">🛠️ Yönetim Paneli</a>
            <span>&bull;</span>
        <?php endif; ?>
        <a href="cikis.php" class="text-rose-500 hover:text-rose-600 transition font-semibold">Güvenli Çıkış</a>
    </div>

    <script>
        var themeToggleDarkIcon = document.getElementById('theme-toggle-dark-icon');
        var themeToggleLightIcon = document.getElementById('theme-toggle-light-icon');

        function updateToggleIcons() {
            var isDark = document.documentElement.classList.contains('dark');
            if (isDark) {
                if (themeToggleLightIcon) themeToggleLightIcon.classList.remove('hidden');
                if (themeToggleDarkIcon) themeToggleDarkIcon.classList.add('hidden');
            } else {
                if (themeToggleLightIcon) themeToggleLightIcon.classList.add('hidden');
                if (themeToggleDarkIcon) themeToggleDarkIcon.classList.remove('hidden');
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
    </script>
</body>
</html>
