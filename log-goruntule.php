<?php
$pageTitle = "Sistem Logları (Admin) - Abonelik Takip";
require_once __DIR__ . '/header.php';

adminKontrol();

$logDir = __DIR__ . '/logs';
$logDosyalari = [];
if (is_dir($logDir)) {
    $dosyalar = scandir($logDir);
    foreach ($dosyalar as $d) {
        if (strpos($d, 'app-') === 0 && substr($d, -4) === '.log') {
            $logDosyalari[] = $d;
        }
    }
    rsort($logDosyalari);
}

$seciliLog = $_GET['dosya'] ?? ($logDosyalari[0] ?? '');
$logIcerik = '';

if ($seciliLog && in_array($seciliLog, $logDosyalari, true)) {
    $tamYol = $logDir . '/' . $seciliLog;
    if (file_exists($tamYol)) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['temizle'])) {
            csrfKontrol($_POST['csrf_token'] ?? '');
            @file_put_contents($tamYol, '');
            sistemLogla("Log Dosyası Temizlendi: {$seciliLog}", 'ADMIN');
            header("Location: log-goruntule.php?dosya=" . urlencode($seciliLog));
            exit;
        }

        $satirlar = @file($tamYol, FILE_IGNORE_NEW_LINES);
        if ($satirlar) {
            $satirlar = array_reverse($satirlar); // En son olaylar en başta
            $logIcerik = $satirlar;
        }
    }
}
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require_once __DIR__ . '/sidebar.php'; ?>
    
    <div class="flex-1 w-full max-w-7xl mx-auto space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-200 dark:border-slate-800">
        <div>
            <div class="flex items-center gap-2">
                <span class="px-2.5 py-1 bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 rounded-lg text-xs font-bold uppercase tracking-wider">Güvenlik &amp; Denetim</span>
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">Sistem ve Hata Logları</h1>
            </div>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">Giriş denemeleri, brute-force alarmları, cron bildirimleri ve hata kayıtları.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="admin.php" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-300 rounded-xl text-xs font-semibold transition">
                ← Kullanıcı Yönetimi
            </a>
        </div>
    </div>

    <!-- Filtre & Dosya Seçimi -->
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-3 transition-colors">
        <form method="GET" class="flex items-center gap-2 w-full sm:w-auto">
            <label class="text-xs font-bold text-slate-700 dark:text-slate-400">Log Dosyası:</label>
            <select name="dosya" onchange="this.form.submit()" class="p-2 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-xs text-slate-900 dark:text-white outline-none">
                <?php foreach ($logDosyalari as $ld): ?>
                    <option value="<?= htmlspecialchars($ld) ?>" <?= $seciliLog === $ld ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ld) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if (!empty($seciliLog)): ?>
            <form method="POST" onsubmit="return confirm('Bu ayın log dosyasını temizlemek istediğinize emin misiniz?');">
                <?= csrfAlaniniEkle() ?>
                <input type="hidden" name="temizle" value="1">
                <button type="submit" class="px-3.5 py-1.5 bg-rose-500/10 hover:bg-rose-500/20 text-rose-600 dark:text-rose-400 border border-rose-500/30 rounded-xl text-xs font-bold transition">
                    🗑 Logu Temizle
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Log Görüntüleyici Terminal Kutusu -->
    <div class="bg-slate-950 border border-slate-800 rounded-3xl p-5 shadow-2xl font-mono text-xs overflow-x-auto max-h-[600px] overflow-y-auto space-y-1">
        <?php if (empty($logIcerik)): ?>
            <div class="py-8 text-center text-slate-500">
                Bu log dosyasında herhangi bir kayıt bulunmuyor.
            </div>
        <?php else: ?>
            <?php foreach ($logIcerik as $satir): 
                $renk = 'text-slate-300';
                if (strpos($satir, '[ERROR]') !== false || strpos($satir, '[CRITICAL]') !== false) {
                    $renk = 'text-rose-400 font-bold';
                } elseif (strpos($satir, '[SECURITY]') !== false) {
                    $renk = 'text-purple-400 font-bold';
                } elseif (strpos($satir, '[WARNING]') !== false) {
                    $renk = 'text-amber-400';
                } elseif (strpos($satir, '[AUTH]') !== false) {
                    $renk = 'text-emerald-400';
                } elseif (strpos($satir, '[ADMIN]') !== false) {
                    $renk = 'text-cyan-400';
                }
            ?>
                <div class="py-1 border-b border-slate-900/60 <?= $renk ?> hover:bg-slate-900/40 px-2 rounded">
                    <?= htmlspecialchars($satir) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
