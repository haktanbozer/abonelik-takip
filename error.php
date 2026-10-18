<?php
$code = $_GET['code'] ?? 'general';
$titles = [
    'db' => 'Veritabanı Bağlantı Hatası',
    'auth' => 'Yetkisiz Erişim',
    'general' => 'Bir Hata Oluştu'
];
$messages = [
    'db' => 'Veritabanına bağlanılamadı. Lütfen .env dosyasındaki MySQL kullanıcı adı ve şifrenizi kontrol edin.',
    'auth' => 'Bu sayfayı görüntülemek için giriş yapmanız gerekmektedir.',
    'general' => 'İşlem sırasında beklenmedik bir sorun oluştu.'
];
$title = $titles[$code] ?? $titles['general'];
$message = $messages[$code] ?? $messages['general'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - Abonelik Takip</title>
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
    <link rel="icon" type="image/png" href="icons/favicon.png">
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex items-center justify-center p-4 transition-colors">
    <div class="max-w-md w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 text-center shadow-xl">
        <div class="w-16 h-16 bg-rose-500/10 text-rose-500 dark:text-rose-400 rounded-2xl flex items-center justify-center mx-auto mb-4 text-3xl font-bold">
            ⚠
        </div>
        <h1 class="text-xl font-bold text-slate-900 dark:text-white mb-2"><?= htmlspecialchars($title) ?></h1>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-6 leading-relaxed"><?= htmlspecialchars($message) ?></p>
        <div class="space-y-2">
            <a href="index.php" class="block w-full py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-sm transition">
                Ana Sayfaya Dön
            </a>
            <a href="install.php" class="block w-full py-3 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-xl font-semibold text-xs transition">
                Kurulum Sihirbazını Kontrol Et
            </a>
        </div>
    </div>
</body>
</html>
