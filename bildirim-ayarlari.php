<?php
$pageTitle = "Bildirim Ayarları - Abonelik Takip";
require_once __DIR__ . '/db.php';
girisKontrol();

$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN';
if (!$isAdmin) {
    if (function_exists('sistemLogla')) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user = $_SESSION['username'] ?? 'Bilinmeyen';
        sistemLogla("Yetkisiz Sayfa Erişimi Engellendi: bildirim-ayarlari.php (Kullanıcı: $user, IP: $ip)", 'SECURITY');
    }
    header("Location: index.php?hata=yetkisiz");
    exit;
}

$userId = (int)$_SESSION['user_id'];
$mesaj = '';
$mesajTuru = 'info';

// POST Islemleri - Ayarlari Kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    if (isset($_POST['ayarlari_kaydet'])) {
        $emailEnabled = isset($_POST['email_enabled']) ? 1 : 0;
        $adminReceiveAll = isset($_POST['admin_receive_all_notifications']) ? 1 : 0;
        $notifyTrialDays = trim($_POST['notify_trial_days'] ?? '3,1');
        $notifyCommitmentDays = trim($_POST['notify_commitment_days'] ?? '30,15,3');
        $notifyDueDays = trim($_POST['notify_due_days'] ?? '3,1');
        $monthlySummaryEnabled = isset($_POST['monthly_summary_enabled']) ? 1 : 0;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO notification_settings 
                (user_id, email_enabled, admin_receive_all_notifications, notify_trial_days, notify_commitment_days, notify_due_days, monthly_summary_enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                email_enabled = VALUES(email_enabled),
                admin_receive_all_notifications = VALUES(admin_receive_all_notifications),
                notify_trial_days = VALUES(notify_trial_days),
                notify_commitment_days = VALUES(notify_commitment_days),
                notify_due_days = VALUES(notify_due_days),
                monthly_summary_enabled = VALUES(monthly_summary_enabled)
            ");
            $stmt->execute([
                $userId, $emailEnabled, $adminReceiveAll, $notifyTrialDays, $notifyCommitmentDays, $notifyDueDays, $monthlySummaryEnabled
            ]);
            $mesaj = "Bildirim ayarları başarıyla kaydedildi.";
            $mesajTuru = 'success';
        } catch (Exception $e) {
            $mesaj = "Ayarlar kaydedilirken hata oluştu: " . $e->getMessage();
            $mesajTuru = 'error';
        }
    }
}

// Mevcut Ayarlari Cek
try {
    $stmt = $pdo->prepare("SELECT * FROM notification_settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $ayarlar = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ayarlar) {
        $ayarlar = [
            'email_enabled' => 1,
            'admin_receive_all_notifications' => 1,
            'notify_trial_days' => '3,1',
            'notify_commitment_days' => '30,15,3',
            'notify_due_days' => '3,1',
            'monthly_summary_enabled' => 1
        ];
    }
} catch (Exception $e) {
    $ayarlar = [
        'email_enabled' => 1,
        'admin_receive_all_notifications' => 1,
        'notify_trial_days' => '3,1',
        'notify_commitment_days' => '30,15,3',
        'notify_due_days' => '3,1',
        'monthly_summary_enabled' => 1
    ];
}

$smtpHost = env('SMTP_HOST', 'mail.example.com');
$smtpPort = env('SMTP_PORT', '587');
$smtpFrom = env('MAIL_FROM_ADDRESS') ?: env('SMTP_FROM_EMAIL') ?: env('SMTP_FROM', 'bildirim@example.com');

require_once __DIR__ . '/header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="flex-1 w-full max-w-7xl mx-auto">
        
        <div class="flex justify-between items-center mb-6">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors flex items-center gap-2">
                    🔔 Bildirim &amp; Hatırlatıcı Ayarları
                </h2>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Taahhüt bitişi, ücretsiz deneme süresi ve fatura son ödeme uyarı eşiklerini yapılandırın.
                </p>
            </div>
            <a href="bildirim-gecmisi.php" class="bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-800 px-3.5 py-2 rounded-xl text-xs font-bold hover:bg-blue-100 dark:hover:bg-blue-800/40 transition flex items-center gap-1.5">
                <span>📨 Bildirim Logları</span>
                <span>&rarr;</span>
            </a>
        </div>

        <?php if($mesaj): ?>
            <?php 
                $alertRenk = $mesajTuru === 'success' 
                    ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 border-emerald-500' 
                    : ($mesajTuru === 'error' ? 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-300 border-rose-500' : 'bg-blue-50 dark:bg-blue-950/40 text-blue-800 dark:text-blue-300 border-blue-500');
            ?>
            <div class="<?= $alertRenk ?> p-4 rounded-xl mb-6 border-l-4 text-xs font-semibold">
                <?= htmlspecialchars($mesaj) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Sol 2 Kolon: Ana Ayar Formu -->
            <div class="lg:col-span-2">
                <div class="bg-white dark:bg-slate-900 p-6 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-800 transition-colors">
                    <form method="POST" class="space-y-6">
                        <?= csrfAlaniniEkle() ?>
                        <input type="hidden" name="ayarlari_kaydet" value="1">

                        <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
                            <div>
                                <h3 class="font-bold text-base text-slate-800 dark:text-white">
                                    Hatırlatıcı Eşikleri
                                </h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Sistem her gün Cron görevi ile bu eşikleri tarar.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="email_enabled" id="email_enabled" <?= !empty($ayarlar['email_enabled']) ? 'checked' : '' ?> class="w-4 h-4 rounded text-blue-600 bg-slate-100 dark:bg-slate-800 border-slate-300 dark:border-slate-700">
                                <label for="email_enabled" class="text-xs font-bold text-slate-700 dark:text-slate-300 cursor-pointer">E-Posta Bildirimleri Açık</label>
                            </div>
                        </div>

                        <!-- 1. Taahhut Uyarilari -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                Taahhüt Bitişi Uyarı Günleri (Virgülle Ayrılmış)
                            </label>
                            <input type="text" name="notify_commitment_days" value="<?= htmlspecialchars($ayarlar['notify_commitment_days']) ?>" required
                                class="w-full p-2.5 border rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-800 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition font-mono">
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">Örn: <code>30,15,3</code> (Taahhüt bitimine 30 gün, 15 gün ve 3 gün kala e-posta atar).</p>
                        </div>

                        <!-- 2. Ucretsiz Deneme Uyarilari -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                Ücretsiz Deneme Sürümü Uyarı Günleri (Virgülle Ayrılmış)
                            </label>
                            <input type="text" name="notify_trial_days" value="<?= htmlspecialchars($ayarlar['notify_trial_days']) ?>" required
                                class="w-full p-2.5 border rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-800 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition font-mono">
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">Örn: <code>3,1</code> (Deneme süreniz bitmeden önce ücret kesilmemesi için uyarır).</p>
                        </div>

                        <!-- 3. Fatura ve Son Odeme Uyarilari -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                Fatura &amp; Son Ödeme Günü Uyarı Günleri (Virgülle Ayrılmış)
                            </label>
                            <input type="text" name="notify_due_days" value="<?= htmlspecialchars($ayarlar['notify_due_days']) ?>" required
                                class="w-full p-2.5 border rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-800 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition font-mono">
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">Örn: <code>3,1</code> (Fatura son ödeme gününden 3 gün ve 1 gün önce hatırlatır).</p>
                        </div>

                        <div class="space-y-3 pt-2 border-t border-slate-100 dark:border-slate-800">
                            <!-- Yonetici Kopyasi -->
                            <div class="flex items-start gap-2.5">
                                <input type="checkbox" name="admin_receive_all_notifications" id="admin_receive_all" <?= !empty($ayarlar['admin_receive_all_notifications']) ? 'checked' : '' ?> class="w-4 h-4 mt-0.5 rounded text-blue-600 bg-slate-100 dark:bg-slate-800 border-slate-300 dark:border-slate-700">
                                <label for="admin_receive_all" class="text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                                    <span class="font-bold block">📝 Yönetici Bildirim Kopyası (Tüm Ev / Ekip)</span>
                                    <span class="text-slate-500 dark:text-slate-400 text-[11px]">Etkinleştirilirse, diğer üyelerin ödemekle yükümlü olduğu fatura ve aboneliklerin de bildirimleri size kopyalanır.</span>
                                </label>
                            </div>

                            <!-- Aylik Ozet Bildirimi -->
                            <div class="flex items-start gap-2.5">
                                <input type="checkbox" name="monthly_summary_enabled" id="monthly_summary" <?= !empty($ayarlar['monthly_summary_enabled']) ? 'checked' : '' ?> class="w-4 h-4 mt-0.5 rounded text-blue-600 bg-slate-100 dark:bg-slate-800 border-slate-300 dark:border-slate-700">
                                <label for="monthly_summary" class="text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                                    <span class="font-bold block">📊 Aylık Özet Raporu</span>
                                    <span class="text-slate-500 dark:text-slate-400 text-[11px]">Her ayın 1'inde o ay yapılacak toplam ödemeleri ve bütçe projeksiyonunu e-posta olarak gönder.</span>
                                </label>
                            </div>
                        </div>

                        <div class="pt-3">
                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-xl font-bold text-xs shadow-md shadow-blue-500/20 transition">
                                Ayarları Kaydet
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Sag 1 Kolon: Bilgi ve Cron Kartlari -->
            <div class="space-y-6">
                <!-- SMTP Bilgisi -->
                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-800 transition-colors">
                    <h3 class="font-bold text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-3 flex items-center gap-1.5">
                        <span>📧</span> SMTP Bilgisi
                    </h3>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mb-3 leading-relaxed">
                        <code>.env</code> dosyasındaki mevcut SMTP yapılandırmanız:
                    </p>
                    <div class="bg-slate-50 dark:bg-slate-800 p-3 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-mono space-y-1.5 text-slate-700 dark:text-slate-300">
                        <div><b>Host:</b> <?= htmlspecialchars($smtpHost) ?>:<?= htmlspecialchars($smtpPort) ?></div>
                        <div><b>Gönderen:</b> <?= htmlspecialchars($smtpFrom) ?></div>
                    </div>
                </div>

                <!-- Otomatik Cron Gorevi -->
                <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-800 transition-colors">
                    <h3 class="font-bold text-xs uppercase tracking-wider text-amber-600 dark:text-amber-400 mb-3 flex items-center gap-1.5">
                        <span>⏰</span> Otomatik Cron Görevi
                    </h3>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mb-3 leading-relaxed">
                        Her sabah saat 09:00'da bildirim göndermek için cPanel crontab satırı:
                    </p>
                    <div class="bg-slate-950 text-slate-200 p-3 rounded-xl border border-slate-800 text-[11px] font-mono break-all leading-relaxed select-all">
                        0 9 * * * php <?= htmlspecialchars(realpath(__DIR__ . '/cron-mail.php') ?: '/home/kullanici/public_html/cron-mail.php') ?> &gt;/dev/null 2&gt;&amp;1
                    </div>
                </div>

                <!-- Gecmis Linki -->
                <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-2xl border border-blue-100 dark:border-blue-800 transition-colors">
                    <a href="bildirim-gecmisi.php" class="text-xs font-bold text-blue-700 dark:text-blue-300 hover:underline flex items-center justify-between">
                        <span>📜 Gönderilen Bildirim Geçmişi</span>
                        <span>&rarr;</span>
                    </a>
                </div>
            </div>

        </div>

    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
