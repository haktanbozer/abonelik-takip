<?php
if (!defined('HEADER_INCLUDED')) {
    define('HEADER_INCLUDED', true);
}
require_once __DIR__ . '/db.php';
girisKontrol();

$activePage = basename($_SERVER['PHP_SELF'] ?? '');
$currentUser = $_SESSION['user_data'] ?? ['username' => 'Kullanıcı', 'full_name' => 'Kullanıcı'];

// Yaklaşan acil bildirim sayısı ve listesi (Son 3 gün içinde süresi dolacak veya gecikmiş ödeme/deneme)
$urgentCount = 0;
$urgentItems = [];
try {
    $stmtUrgentList = $pdo->prepare("
        SELECT id, title, price, currency, next_payment_date, is_trial, trial_end_date 
        FROM subscriptions 
        WHERE user_id = ? AND status = 'active' AND (
            (is_trial = 1 AND trial_end_date IS NOT NULL AND trial_end_date <= DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY))
            OR (next_payment_date IS NOT NULL AND next_payment_date <= DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY))
        )
        ORDER BY COALESCE(trial_end_date, next_payment_date) ASC
        LIMIT 5
    ");
    $stmtUrgentList->execute([$_SESSION['user_id']]);
    $urgentItems = $stmtUrgentList->fetchAll();

    $stmtUrgentCount = $pdo->prepare("
        SELECT COUNT(*) FROM subscriptions 
        WHERE user_id = ? AND status = 'active' AND (
            (is_trial = 1 AND trial_end_date IS NOT NULL AND trial_end_date <= DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY))
            OR (next_payment_date IS NOT NULL AND next_payment_date <= DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY))
        )
    ");
    $stmtUrgentCount->execute([$_SESSION['user_id']]);
    $urgentCount = (int)$stmtUrgentCount->fetchColumn();
} catch (Exception $e) {
    // sessizce gec
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Abonelik ve Fatura Takip') ?></title>
    
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/png" href="icons/favicon.png">
    <link rel="apple-touch-icon" href="icons/icon-192x192.png">
    <meta name="theme-color" content="#0f172a">
    
    <!-- Tailwind CSS (Derlenmiş Üretim Dosyası veya CDN Yedeklemesi) -->
    <?php if (file_exists(__DIR__ . '/dist/style.css')): ?>
        <link rel="stylesheet" href="dist/style.css?v=<?= filemtime(__DIR__ . '/dist/style.css') ?>">
    <?php else: ?>
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                darkMode: 'class',
                theme: { extend: { colors: { brand: { 50: '#f0f9ff', 100: '#e0f2fe', 500: '#0ea5e9', 600: '#0284c7', 700: '#0369a1' } } } }
            };
        </script>
    <?php endif; ?>

    <script>
        // Sayfa yuklenmeden once beyaz/karanlik tema sinifini derhal uygula (FOUC onleyici)
        if (localStorage.getItem('color-theme') === 'dark') {
            document.documentElement.classList.add('dark');
            document.documentElement.classList.remove('light');
        } else {
            document.documentElement.classList.remove('dark');
            document.documentElement.classList.add('light');
        }

        // PWA Service Worker Kaydı
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('sw.js')
                    .catch(function(err) { console.warn('[PWA] SW Hatası:', err); });
            });
        }
    </script>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" crossorigin="anonymous"></script>
    
    <style>
        body { transition: background-color 0.2s, color 0.2s; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        /* Scrollbar guzellestirme */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .dark ::-webkit-scrollbar-thumb { background: #334155; }
        .dark ::-webkit-scrollbar-thumb:hover { background: #475569; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-100 min-h-screen flex flex-col antialiased selection:bg-blue-600 selection:text-white transition-colors duration-200">

<!-- Ust Navigasyon Cubugu -->
<nav class="sticky top-0 z-50 bg-white/90 dark:bg-slate-900/90 backdrop-blur-md border-b border-slate-200 dark:border-slate-800 transition-colors duration-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between py-2 md:py-0 md:h-16">
            
            <!-- Logo & Marka + Konum Secici (Mobilde Ust Satir, Masaustunde Sol Taraf) -->
            <div class="flex items-center justify-between md:justify-start gap-2 sm:gap-3 min-w-0 w-full md:w-auto">
                <a href="index.php" class="flex items-center gap-2 sm:gap-2.5 group flex-shrink-0">
                    <img src="icons/icon-96x96.png" alt="Logo" class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl shadow-md shadow-purple-500/20 group-hover:scale-105 transition-transform object-cover">
                    <div class="flex flex-col">
                        <span class="font-bold text-sm sm:text-base text-slate-900 dark:text-white tracking-tight leading-none group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">Abonelik Takip</span>
                        <span class="hidden sm:inline text-[10px] text-slate-500 dark:text-slate-400 font-medium leading-tight mt-0.5">Fatura &amp; Dijital Asistan</span>
                    </div>
                </a>

                <!-- Konum / Kapsam Secici Butonu -->
                <?php
                    $aktifKonumAd = $_SESSION['aktif_konum_ad'] ?? 'Tüm Mekanlar';
                    $aktifKonumId = $_SESSION['aktif_konum_id'] ?? null;
                    $aktifKonumIcon = '📍';
                    if (!empty($aktifKonumId) && isset($pdo)) {
                        try {
                            $locIcnStmt = $pdo->prepare("SELECT icon FROM locations WHERE id = ?");
                            $locIcnStmt->execute([$aktifKonumId]);
                            $rawIcn = $locIcnStmt->fetchColumn();
                            if ($rawIcn) {
                                $icMap = ['home' => '🏠', 'globe' => '💻', 'shield' => '🛡️', 'chart' => '📈', 'tax' => '💰', 'car' => '🚗', 'building' => '🏢', 'sun' => '🏖️'];
                                $aktifKonumIcon = $icMap[$rawIcn] ?? (!empty($rawIcn) && mb_strlen($rawIcn, 'UTF-8') <= 4 && !ctype_alnum($rawIcn) ? $rawIcn : '📍');
                            }
                        } catch (Exception $e) {}
                    }
                ?>
                <a href="secim.php" class="flex items-center gap-1 sm:gap-1.5 px-2.5 py-1 sm:py-1.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800/80 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700/60 rounded-xl text-xs font-semibold transition ml-1 sm:ml-2 shadow-sm group flex-shrink-0" title="Alan / Portföy Değiştir">
                    <span class="text-xs sm:text-sm"><?= $aktifKonumIcon ?></span>
                    <span class="max-w-[90px] xs:max-w-[130px] sm:max-w-[160px] truncate text-[11px] sm:text-xs"><?= htmlspecialchars($aktifKonumAd) ?></span>
                    <svg class="w-3 h-3 text-slate-400 group-hover:text-slate-600 dark:group-hover:text-white transition flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </a>

                <!-- Masaustu Linkler -->
                <div class="hidden md:flex items-center gap-1 ml-6 border-l border-slate-200 dark:border-slate-800 pl-6 text-sm font-medium">
                    <a href="index.php" class="px-3 py-1.5 rounded-lg transition <?= $activePage === 'index.php' ? 'bg-blue-600 text-white font-semibold shadow-sm' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                        Genel Bakış
                    </a>
                    <a href="abonelikler.php" class="px-3 py-1.5 rounded-lg transition <?= $activePage === 'abonelikler.php' ? 'bg-blue-600 text-white font-semibold shadow-sm' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                        Abonelikler
                    </a>
                    <a href="fatura-takvimi.php" class="px-3 py-1.5 rounded-lg transition <?= $activePage === 'fatura-takvimi.php' ? 'bg-blue-600 text-white font-semibold shadow-sm' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                        Takvim
                    </a>
                    <a href="kredi-kartlari.php" class="px-3 py-1.5 rounded-lg transition <?= $activePage === 'kredi-kartlari.php' ? 'bg-blue-600 text-white font-semibold shadow-sm' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
                        Kartlar &amp; Hesaplar
                    </a>
                    <?php if (($_SESSION['role'] ?? 'USER') === 'ADMIN'): ?>
                        <a href="admin.php" class="px-3 py-1.5 rounded-lg transition flex items-center gap-1.5 <?= $activePage === 'admin.php' ? 'bg-purple-600 text-white font-semibold shadow-sm' : 'text-purple-600 dark:text-purple-400 hover:text-purple-700 dark:hover:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-500/10' ?>">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                            <span>Yönetim</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sag Aksiyon Butonlari & Kullanici Alani (Mobilde Alt Satir, Masaustunde Sag Taraf) -->
            <div class="flex items-center justify-between md:justify-end gap-1.5 sm:gap-2.5 w-full md:w-auto pt-2 md:pt-0 border-t border-slate-100 dark:border-slate-800/60 md:border-t-0 mt-1.5 md:mt-0 flex-shrink-0">
                
                <!-- Mobilde Kullanici Profili Butonu -->
                <div class="flex items-center md:hidden">
                    <a href="profil.php" class="flex items-center gap-1.5 px-2 py-1 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800/80 dark:hover:bg-slate-800 rounded-xl text-slate-700 dark:text-slate-300 transition" title="Profil Ayarları">
                        <div class="w-6 h-6 bg-blue-500/20 text-blue-600 dark:text-blue-400 rounded-lg flex items-center justify-center font-bold text-xs uppercase">
                            <?= mb_substr($currentUser['username'] ?? 'U', 0, 1) ?>
                        </div>
                        <span class="text-xs font-semibold max-w-[110px] xs:max-w-[140px] truncate">
                            <?= htmlspecialchars($currentUser['username'] ?? 'Hesabım') ?>
                        </span>
                    </a>
                </div>

                <div class="flex items-center gap-1.5 sm:gap-2.5 ml-auto md:ml-0">
                    <!-- Hizli Ekle Butonu -->
                    <a href="abonelik-ekle.php" class="hidden sm:flex items-center gap-1.5 px-3.5 py-1.5 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-xs font-bold rounded-xl shadow-md shadow-blue-600/20 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
                        <span>Yeni Abonelik</span>
                    </a>

                    <!-- PWA Yukle Butonu -->
                    <button id="pwa-install-btn" class="hidden px-2.5 py-1.5 bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-xs font-bold rounded-xl border border-emerald-500/30 transition items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                        <span>Yükle</span>
                    </button>

                    <!-- Bildirim Zili ve Açılır Menü -->
                    <div class="relative">
                        <button id="notif-btn" type="button" class="relative p-2 rounded-xl text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition" title="Acil Bildirimler ve Yaklaşan Ödemeler">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                            <?php if ($urgentCount > 0): ?>
                                <span class="absolute top-1 right-1 flex h-4 min-w-[16px] px-1 items-center justify-center rounded-full bg-rose-500 text-[9px] font-bold text-white shadow-sm ring-2 ring-white dark:ring-slate-900 animate-pulse">
                                    <?= $urgentCount ?>
                                </span>
                            <?php endif; ?>
                        </button>

                        <!-- Bildirim Açılır Listesi -->
                        <div id="notif-dropdown" class="hidden absolute right-0 mt-2 w-[calc(100vw-2rem)] max-w-sm sm:w-96 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-800 z-50 overflow-hidden">
                            <div class="px-4 py-3 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                                    🔔 <span>Yaklaşan Ödemeler</span>
                                </span>
                                <?php if ($urgentCount > 0): ?>
                                    <span class="bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 text-[10px] font-bold px-2 py-0.5 rounded-full">
                                        <?= $urgentCount ?> Acil
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="max-h-80 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
                                <?php if (empty($urgentItems)): ?>
                                    <div class="p-6 text-center text-xs text-slate-400 dark:text-slate-500">
                                        🎉 Önümüzdeki 3 gün içinde acil ödemeniz veya süresi dolacak deneme üyeliğiniz bulunmuyor.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($urgentItems as $item): 
                                        $targetDate = $item['is_trial'] ? $item['trial_end_date'] : $item['next_payment_date'];
                                        $diff = kalanGunHesapla($targetDate);
                                    ?>
                                    <a href="abonelik-detay.php?id=<?= $item['id'] ?>" class="flex items-center justify-between p-3.5 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition group">
                                        <div class="min-w-0 pr-3">
                                            <p class="text-xs font-bold text-slate-800 dark:text-slate-200 truncate group-hover:text-blue-600 dark:group-hover:text-blue-400 transition">
                                                <?= htmlspecialchars($item['title']) ?>
                                            </p>
                                            <div class="flex items-center gap-2 mt-1">
                                                <span class="text-[10px] text-slate-400">
                                                    <?= $item['is_trial'] ? 'Deneme Bitişi: ' : 'Vade: ' ?><?= tarihFormat($targetDate) ?>
                                                </span>
                                                <?php if ($diff !== null): ?>
                                                    <span class="text-[9px] font-bold px-1.5 py-0.5 rounded <?= $diff < 0 ? 'bg-rose-100 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400' : ($diff === 0 ? 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400' : 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400') ?>">
                                                        <?= $diff < 0 ? abs($diff) . ' gün geçti' : ($diff === 0 ? 'Bugün!' : $diff . ' gün kaldı') ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="text-right flex-shrink-0">
                                            <span class="text-xs font-bold text-slate-900 dark:text-white">
                                                <?= paraFormat($item['price'], $item['currency']) ?>
                                            </span>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="p-2.5 bg-slate-50 dark:bg-slate-800/50 border-t border-slate-100 dark:border-slate-800 text-center">
                                <a href="fatura-takvimi.php" class="text-[11px] font-bold text-blue-600 dark:text-blue-400 hover:underline">
                                    Takvimi ve Tüm Planı Gör &rarr;
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Tema Degistirici (Gunes / Ay Ikonu) -->
                    <button id="theme-toggle" type="button" class="p-2 rounded-xl text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition" title="Aydınlık / Karanlık Temayı Değiştir">
                        <svg id="theme-toggle-light-icon" class="hidden w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                        <svg id="theme-toggle-dark-icon" class="hidden w-5 h-5 text-slate-700 dark:text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                    </button>

                    <!-- Masaustu Kullanici Menusu -->
                    <div class="hidden md:flex items-center gap-1.5 border-l border-slate-200 dark:border-slate-800 pl-2">
                        <a href="profil.php" class="flex items-center gap-2 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition group" title="Profil Ayarları">
                            <div class="w-7 h-7 bg-blue-500/20 text-blue-600 dark:text-blue-400 rounded-lg flex items-center justify-center font-bold text-xs uppercase">
                                <?= mb_substr($currentUser['username'] ?? 'U', 0, 1) ?>
                            </div>
                            <span class="hidden lg:inline text-xs font-semibold text-slate-700 dark:text-slate-300 group-hover:text-slate-900 dark:group-hover:text-white">
                                <?= htmlspecialchars($currentUser['username'] ?? 'Hesabım') ?>
                            </span>
                        </a>
                    </div>

                    <!-- Guvenli Cikis -->
                    <a href="cikis.php?csrf_token=<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>" class="p-2 text-rose-500 hover:text-rose-700 dark:text-rose-400 dark:hover:text-white hover:bg-rose-50 dark:hover:bg-rose-600/20 rounded-xl transition" title="Güvenli Çıkış">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    </a>
                </div>

            </div>
        </div>
    </div>

    <!-- Mobil Alt Menu Bari (Kucuk Ekranlar Icin) -->
    <div class="md:hidden flex items-center justify-around border-t border-slate-200 dark:border-slate-800 bg-white/95 dark:bg-slate-900/95 py-2 px-2 text-xs">
        <a href="index.php" class="flex flex-col items-center gap-0.5 <?= $activePage === 'index.php' ? 'text-blue-600 dark:text-blue-400 font-bold' : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
            <span>Özet</span>
        </a>
        <a href="abonelikler.php" class="flex flex-col items-center gap-0.5 <?= $activePage === 'abonelikler.php' ? 'text-blue-600 dark:text-blue-400 font-bold' : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            <span>Abonelikler</span>
        </a>
        <a href="abonelik-ekle.php" class="flex flex-col items-center gap-0.5 text-blue-600 dark:text-blue-400 font-bold -mt-4">
            <div class="w-10 h-10 bg-blue-600 rounded-full flex items-center justify-center text-white shadow-lg shadow-blue-600/40">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
            </div>
            <span>Ekle</span>
        </a>
        <a href="fatura-takvimi.php" class="flex flex-col items-center gap-0.5 <?= $activePage === 'fatura-takvimi.php' ? 'text-blue-600 dark:text-blue-400 font-bold' : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            <span>Takvim</span>
        </a>
        <a href="kredi-kartlari.php" class="flex flex-col items-center gap-0.5 <?= $activePage === 'kredi-kartlari.php' ? 'text-blue-600 dark:text-blue-400 font-bold' : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200' ?>">
            <span class="text-lg leading-tight">💳</span>
            <span>Kartlar</span>
        </a>
        <a href="profil.php" class="flex flex-col items-center gap-0.5 <?= $activePage === 'profil.php' ? 'text-blue-600 dark:text-blue-400 font-bold' : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
            <span>Profil</span>
        </a>
    </div>
</nav>

<!-- Ana Govde Tasiyicisi -->
<main class="flex-grow max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
