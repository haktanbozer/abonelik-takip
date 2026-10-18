<?php
// bildirim-ayarlari.php - Abonelik ve Fatura Takip Bildirim Eşikleri
require_once __DIR__ . '/db.php';
girisKontrol();

// Güvenlik & Yetki Kontrolü
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    if (function_exists('sistemLogla')) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user = $_SESSION['username'] ?? 'Bilinmeyen';
        sistemLogla("Yetkisiz Sayfa Erişimi Engellendi: bildirim-ayarlari.php (Kullanıcı: $user, IP: $ip)", 'SECURITY');
    }
    header("Location: index.php?hata=yetkisiz");
    exit;
}

if (!isset($cspNonce)) { $cspNonce = ''; }

$userId = (int)$_SESSION['user_id'];
$mesaj = '';
$mesajTuru = 'info';

// Mevcut Ayarları Çek
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
        $stmtInsert = $pdo->prepare("
            INSERT INTO notification_settings 
            (user_id, email_enabled, admin_receive_all_notifications, notify_trial_days, notify_commitment_days, notify_due_days, monthly_summary_enabled)
            VALUES (?, 1, 1, '3,1', '30,15,3', '3,1', 1)
        ");
        $stmtInsert->execute([$userId]);
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

// Yardımcı Fonksiyon: Gün Listesini Diziye Çevir (Büyükten Küçüğe)
function parseDaysList($daysStr) {
    if (empty($daysStr)) return [];
    $parts = explode(',', $daysStr);
    $clean = [];
    foreach ($parts as $p) {
        $val = (int)trim($p);
        if ($val > 0) {
            $clean[] = $val;
        }
    }
    $clean = array_unique($clean);
    rsort($clean);
    return $clean;
}

$commDays = parseDaysList($ayarlar['notify_commitment_days'] ?? '30,15,3');
$trialDays = parseDaysList($ayarlar['notify_trial_days'] ?? '3,1');
$dueDays = parseDaysList($ayarlar['notify_due_days'] ?? '3,1');

// --- İŞLEMLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');

    // 1. Yeni Eşik Kuralı Ekleme
    if (isset($_POST['ekle_esik'])) {
        $kategori = $_POST['kategori'] ?? '';
        $gun = (int)($_POST['gun'] ?? 0);

        if ($gun <= 0 || $gun > 730) {
            $mesaj = "Lütfen 1 ile 730 gün arasında geçerli bir değer giriniz.";
            $mesajTuru = 'error';
        } else {
            $targetCol = '';
            $katBaslik = '';
            $currentArr = [];

            if ($kategori === 'commitment') {
                $targetCol = 'notify_commitment_days';
                $katBaslik = 'Taahhüt Bitişi';
                $currentArr = $commDays;
            } elseif ($kategori === 'trial') {
                $targetCol = 'notify_trial_days';
                $katBaslik = 'Deneme Sürümü';
                $currentArr = $trialDays;
            } elseif ($kategori === 'due') {
                $targetCol = 'notify_due_days';
                $katBaslik = 'Fatura / Son Ödeme Günü';
                $currentArr = $dueDays;
            }

            if ($targetCol) {
                if (in_array($gun, $currentArr)) {
                    $mesaj = "Bu gün sayısı ($gun gün) zaten $katBaslik için tanımlı.";
                    $mesajTuru = 'warning';
                } else {
                    $currentArr[] = $gun;
                    rsort($currentArr);
                    $newStr = implode(',', $currentArr);

                    try {
                        $stmtUp = $pdo->prepare("UPDATE notification_settings SET {$targetCol} = ? WHERE user_id = ?");
                        $stmtUp->execute([$newStr, $userId]);
                        $ayarlar[$targetCol] = $newStr;
                        if ($kategori === 'commitment') $commDays = $currentArr;
                        elseif ($kategori === 'trial') $trialDays = $currentArr;
                        elseif ($kategori === 'due') $dueDays = $currentArr;

                        if (function_exists('auditLog')) {
                            auditLog('BİLDİRİM_AYARI', "Yeni bildirim eşiği eklendi: $katBaslik - $gun gün");
                        }
                        $mesaj = "$katBaslik için $gun gün kala bildirim kuralı başarıyla eklendi.";
                        $mesajTuru = 'success';
                    } catch (Exception $e) {
                        $mesaj = "Kural kaydedilirken hata oluştu: " . $e->getMessage();
                        $mesajTuru = 'error';
                    }
                }
            }
        }
    }

    // 2. Eşik Kuralı Silme
    if (isset($_POST['sil_esik'])) {
        $kategori = $_POST['kategori'] ?? '';
        $silGun = (int)($_POST['sil_gun'] ?? 0);

        $targetCol = '';
        $katBaslik = '';
        $currentArr = [];

        if ($kategori === 'commitment') {
            $targetCol = 'notify_commitment_days';
            $katBaslik = 'Taahhüt Bitişi';
            $currentArr = $commDays;
        } elseif ($kategori === 'trial') {
            $targetCol = 'notify_trial_days';
            $katBaslik = 'Deneme Sürümü';
            $currentArr = $trialDays;
        } elseif ($kategori === 'due') {
            $targetCol = 'notify_due_days';
            $katBaslik = 'Fatura / Son Ödeme Günü';
            $currentArr = $dueDays;
        }

        if ($targetCol) {
            $currentArr = array_diff($currentArr, [$silGun]);
            rsort($currentArr);
            $newStr = implode(',', $currentArr);

            try {
                $stmtUp = $pdo->prepare("UPDATE notification_settings SET {$targetCol} = ? WHERE user_id = ?");
                $stmtUp->execute([$newStr, $userId]);
                $ayarlar[$targetCol] = $newStr;
                if ($kategori === 'commitment') $commDays = $currentArr;
                elseif ($kategori === 'trial') $trialDays = $currentArr;
                elseif ($kategori === 'due') $dueDays = $currentArr;

                if (function_exists('auditLog')) {
                    auditLog('BİLDİRİM_AYARI', "Bildirim eşiği silindi: $katBaslik - $silGun gün");
                }
                $mesaj = "$katBaslik için $silGun gün kuralı silindi.";
                $mesajTuru = 'success';
            } catch (Exception $e) {
                $mesaj = "Kural silinirken hata oluştu: " . $e->getMessage();
                $mesajTuru = 'error';
            }
        }
    }

    // 3. Genel Tercihleri Kaydetme
    if (isset($_POST['genel_tercihleri_kaydet'])) {
        $emailEnabled = isset($_POST['email_enabled']) ? 1 : 0;
        $adminReceiveAll = isset($_POST['admin_receive_all_notifications']) ? 1 : 0;
        $monthlySummaryEnabled = isset($_POST['monthly_summary_enabled']) ? 1 : 0;

        try {
            $stmtUp = $pdo->prepare("
                UPDATE notification_settings 
                SET email_enabled = ?, admin_receive_all_notifications = ?, monthly_summary_enabled = ?
                WHERE user_id = ?
            ");
            $stmtUp->execute([$emailEnabled, $adminReceiveAll, $monthlySummaryEnabled, $userId]);
            $ayarlar['email_enabled'] = $emailEnabled;
            $ayarlar['admin_receive_all_notifications'] = $adminReceiveAll;
            $ayarlar['monthly_summary_enabled'] = $monthlySummaryEnabled;

            $mesaj = "Genel bildirim tercihleri başarıyla kaydedildi.";
            $mesajTuru = 'success';
        } catch (Exception $e) {
            $mesaj = "Ayarlar kaydedilirken hata oluştu: " . $e->getMessage();
            $mesajTuru = 'error';
        }
    }
}

$smtpHost = env('SMTP_HOST', 'mail.example.com');
$smtpPort = env('SMTP_PORT', '587');
$smtpFrom = env('MAIL_FROM_ADDRESS') ?: env('SMTP_FROM_EMAIL') ?: env('SMTP_FROM', 'bildirim@example.com');

$activeTab = $_GET['tab'] ?? ($_POST['active_tab'] ?? 'commitment');
if (!in_array($activeTab, ['commitment', 'trial', 'due'])) {
    $activeTab = 'commitment';
}

require_once __DIR__ . '/header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="flex-1 w-full max-w-7xl mx-auto">
        
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors flex items-center gap-2">
                    🔔 Bildirim &amp; Eşik Ayarları
                </h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Otomatik e-posta bildirimlerinin tetikleneceği taahhüt, deneme süresi ve ödeme eşikleri.
                </p>
            </div>
            <a href="bildirim-gecmisi.php" class="bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-800 px-3.5 py-2 rounded-xl text-xs font-bold hover:bg-blue-100 dark:hover:bg-blue-800/40 transition flex items-center gap-1.5 self-start sm:self-auto shadow-sm">
                <span>📨 Bildirim Logları</span>
                <span>&rarr;</span>
            </a>
        </div>

        <?php if(!empty($mesaj)): ?>
            <?php 
                $alertRenk = $mesajTuru === 'success' 
                    ? 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200 border-green-500' 
                    : ($mesajTuru === 'error' ? 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 border-red-500' : 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-200 border-yellow-500');
            ?>
            <div class="<?= $alertRenk ?> p-3.5 rounded-xl mb-6 border-l-4 transition-colors text-sm font-medium">
                <?= htmlspecialchars($mesaj) ?>
            </div>
        <?php endif; ?>

        <!-- Kategori Sekmeleri (Tabs) -->
        <div class="flex flex-wrap gap-2 mb-6 border-b border-slate-200 dark:border-slate-800 pb-3">
            <a href="?tab=commitment" class="px-4 py-2.5 rounded-xl font-bold text-xs transition flex items-center gap-2 <?= $activeTab === 'commitment' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700' ?>">
                <span>📡 Taahhüt Bitişi</span>
                <span class="text-[10px] px-2 py-0.5 rounded-full <?= $activeTab === 'commitment' ? 'bg-blue-500 text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>"><?= count($commDays) ?> Kural</span>
            </a>
            <a href="?tab=trial" class="px-4 py-2.5 rounded-xl font-bold text-xs transition flex items-center gap-2 <?= $activeTab === 'trial' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700' ?>">
                <span>🎁 Deneme Sürümleri</span>
                <span class="text-[10px] px-2 py-0.5 rounded-full <?= $activeTab === 'trial' ? 'bg-blue-500 text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>"><?= count($trialDays) ?> Kural</span>
            </a>
            <a href="?tab=due" class="px-4 py-2.5 rounded-xl font-bold text-xs transition flex items-center gap-2 <?= $activeTab === 'due' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700' ?>">
                <span>💳 Fatura &amp; Son Ödeme</span>
                <span class="text-[10px] px-2 py-0.5 rounded-full <?= $activeTab === 'due' ? 'bg-blue-500 text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>"><?= count($dueDays) ?> Kural</span>
            </a>
        </div>

        <?php 
        // Aktif Sekme Bilgileri
        $tabTitle = 'Taahhüt Bitişi Uyarı Eşikleri';
        $tabDesc = 'İnternet, GSM ve taahhütlü aboneliklerin süresi bitmeden kaç gün önce e-posta gönderileceğini belirleyin (Örn: 30, 15, 3 gün).';
        $activeDaysList = $commDays;
        $tabPlaceholder = 'Örn: 30';

        if ($activeTab === 'trial') {
            $tabTitle = 'Ücretsiz Deneme Sürümü Uyarı Eşikleri';
            $tabDesc = 'Dijital servislerin deneme süresi bitip karttan ücret kesilmeden önce kaç gün kala hatırlatılacağını belirleyin (Örn: 3, 1 gün).';
            $activeDaysList = $trialDays;
            $tabPlaceholder = 'Örn: 3';
        } elseif ($activeTab === 'due') {
            $tabTitle = 'Fatura &amp; Son Ödeme Günü Uyarı Eşikleri';
            $tabDesc = 'Aylık faturaların ve aboneliklerin son ödeme gününden kaç gün önce hatırlatma gönderileceğini belirleyin (Örn: 3, 1 gün).';
            $activeDaysList = $dueDays;
            $tabPlaceholder = 'Örn: 3';
        }
        ?>

        <!-- Ana Grid: stok-bildirim-ayarlari.php ile Birebir 2 Kolonlu Yapı -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            
            <!-- Sol Kolon: Ekleme Formu & Nasıl Çalışır -->
            <div class="space-y-6">
                <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
                    <h3 class="font-bold text-base mb-2 text-slate-800 dark:text-white">➕ Yeni Eşik Kuralı Ekle</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-4 leading-relaxed">
                        <?= $tabDesc ?>
                    </p>
                    
                    <form method="POST" class="flex gap-2">
                        <?= csrfAlaniniEkle() ?>
                        <input type="hidden" name="active_tab" value="<?= htmlspecialchars($activeTab) ?>">
                        <input type="hidden" name="kategori" value="<?= htmlspecialchars($activeTab) ?>">
                        <input type="number" name="gun" placeholder="<?= $tabPlaceholder ?>" required min="1" max="730" class="flex-1 p-2.5 border rounded-lg focus:ring-2 focus:ring-blue-500 outline-none dark:bg-slate-700 dark:border-slate-600 dark:text-white text-sm transition-colors font-semibold">
                        <button type="submit" name="ekle_esik" value="1" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg font-bold text-sm shadow-md shadow-blue-500/20 transition whitespace-nowrap">
                            Kuralı Ekle
                        </button>
                    </form>
                </div>

                <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-xl border border-blue-100 dark:border-blue-800 text-xs text-blue-800 dark:text-blue-300 transition-colors leading-relaxed">
                    <span class="text-base mr-1">ℹ️</span> <b>Nasıl Çalışır?</b><br>
                    <div class="mt-1.5 opacity-90 space-y-1">
                        <p>• Sunucuda zamanlanmış Cron Job (<code>cron-mail.php</code>) her sabah bu eşikleri kontrol eder.</p>
                        <p>• Tarihe tam olarak bu gün sayısı kalan abonelikler sorumlu kullanıcılara e-posta olarak iletilir.</p>
                        <p>• Taahhüdü biten aboneliklerde olası zamlı fiyat uyarısı da otomatik olarak rapora eklenir.</p>
                    </div>
                </div>
            </div>

            <!-- Sağ Kolon: Aktif Kurallar Listesi -->
            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
                <div class="flex items-center justify-between border-b dark:border-slate-700 pb-3 mb-4">
                    <h3 class="font-bold text-base text-slate-800 dark:text-white">Aktif <?= $tabTitle ?></h3>
                    <span class="text-xs bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-0.5 rounded-full font-bold">
                        <?= count($activeDaysList) ?> Kural
                    </span>
                </div>
                
                <?php if(empty($activeDaysList)): ?>
                    <p class="text-slate-400 dark:text-slate-500 text-center py-6 text-sm">Bu kategori için henüz kural tanımlanmamış. Sistem otomatik uyarı gönderemez.</p>
                <?php else: ?>
                    <div class="space-y-2 max-h-96 overflow-y-auto pr-1">
                        <?php foreach($activeDaysList as $g): ?>
                        <div class="flex justify-between items-center p-3 bg-slate-50 dark:bg-slate-700/40 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700/70 border border-slate-100 dark:border-slate-700 transition group">
                            <span class="font-semibold text-slate-700 dark:text-slate-200 flex items-center gap-2 text-sm">
                                📅 <span class="font-bold text-blue-600 dark:text-blue-400"><?= (int)$g ?></span> Gün Kala
                            </span>
                            <form method="POST" onsubmit="return confirm('<?= (int)$g ?> gün kuralını silmek istediğinize emin misiniz?')">
                                <?= csrfAlaniniEkle() ?>
                                <input type="hidden" name="active_tab" value="<?= htmlspecialchars($activeTab) ?>">
                                <input type="hidden" name="kategori" value="<?= htmlspecialchars($activeTab) ?>">
                                <input type="hidden" name="sil_gun" value="<?= (int)$g ?>">
                                <button type="submit" name="sil_esik" value="1" class="text-red-400 hover:text-red-600 dark:hover:text-red-400 px-2.5 py-1 text-xs rounded transition opacity-80 group-hover:opacity-100 font-bold" title="Kuralı Sil">✕ Sil</button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- Alt Kısım: Genel Tercihler ve Cron/SMTP Kartları -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            
            <!-- Genel Bildirim Tercihleri -->
            <div class="bg-white dark:bg-slate-800 p-6 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
                <h3 class="font-bold text-base mb-3 text-slate-800 dark:text-white flex items-center gap-2">
                    <span>⚙️</span> Genel Bildirim Tercihleri
                </h3>
                <form method="POST" class="space-y-4">
                    <?= csrfAlaniniEkle() ?>
                    <input type="hidden" name="active_tab" value="<?= htmlspecialchars($activeTab) ?>">
                    
                    <div class="flex items-start gap-3 p-3 bg-slate-50 dark:bg-slate-700/30 rounded-xl border border-slate-100 dark:border-slate-700/50">
                        <input type="checkbox" name="email_enabled" id="email_enabled" <?= !empty($ayarlar['email_enabled']) ? 'checked' : '' ?> class="w-4 h-4 mt-0.5 rounded text-blue-600 bg-white dark:bg-slate-700 border-slate-300 dark:border-slate-600">
                        <label for="email_enabled" class="text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                            <span class="font-bold block text-sm">✉️ E-Posta Bildirimleri</span>
                            <span class="text-slate-500 dark:text-slate-400 text-[11px]">Tüm hatırlatıcı ve eşik e-postalarının gönderimini açar/kapatır.</span>
                        </label>
                    </div>

                    <div class="flex items-start gap-3 p-3 bg-slate-50 dark:bg-slate-700/30 rounded-xl border border-slate-100 dark:border-slate-700/50">
                        <input type="checkbox" name="admin_receive_all_notifications" id="admin_receive_all" <?= !empty($ayarlar['admin_receive_all_notifications']) ? 'checked' : '' ?> class="w-4 h-4 mt-0.5 rounded text-blue-600 bg-white dark:bg-slate-700 border-slate-300 dark:border-slate-600">
                        <label for="admin_receive_all" class="text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                            <span class="font-bold block text-sm">📝 Yönetici Bildirim Kopyası</span>
                            <span class="text-slate-500 dark:text-slate-400 text-[11px]">Diğer üyelerin ödemekle yükümlü olduğu fatura ve aboneliklerin uyarıları da yöneticiye kopyalanır.</span>
                        </label>
                    </div>

                    <div class="flex items-start gap-3 p-3 bg-slate-50 dark:bg-slate-700/30 rounded-xl border border-slate-100 dark:border-slate-700/50">
                        <input type="checkbox" name="monthly_summary_enabled" id="monthly_summary" <?= !empty($ayarlar['monthly_summary_enabled']) ? 'checked' : '' ?> class="w-4 h-4 mt-0.5 rounded text-blue-600 bg-white dark:bg-slate-700 border-slate-300 dark:border-slate-600">
                        <label for="monthly_summary" class="text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                            <span class="font-bold block text-sm">📊 Aylık Bütçe Özet Raporu</span>
                            <span class="text-slate-500 dark:text-slate-400 text-[11px]">Her ayın 1'inde o ay yapılacak toplam harcamaları ve bütçe projeksiyonunu e-posta olarak gönderir.</span>
                        </label>
                    </div>

                    <button type="submit" name="genel_tercihleri_kaydet" value="1" class="w-full bg-slate-800 hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600 text-white py-2.5 rounded-lg font-bold text-xs transition shadow-sm">
                        Tercihleri Kaydet
                    </button>
                </form>
            </div>

            <!-- Sunucu & Cron Bilgisi -->
            <div class="space-y-4">
                <div class="bg-white dark:bg-slate-800 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
                    <h3 class="font-bold text-xs uppercase tracking-wider text-amber-600 dark:text-amber-400 mb-2 flex items-center gap-1.5">
                        <span>⏰</span> Otomatik Cron Görevi
                    </h3>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mb-2 leading-relaxed">
                        Her sabah saat 09:00'da yukarıdaki eşikleri kontrol edip e-posta gönderecek cPanel komutu:
                    </p>
                    <div class="bg-slate-950 text-slate-200 p-3 rounded-lg border border-slate-800 text-[11px] font-mono break-all leading-relaxed select-all">
                        0 9 * * * php <?= htmlspecialchars(realpath(__DIR__ . '/cron-mail.php') ?: '/home/kullanici/public_html/cron-mail.php') ?> &gt;/dev/null 2&gt;&amp;1
                    </div>
                </div>

                <div class="bg-white dark:bg-slate-800 p-5 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 transition-colors">
                    <h3 class="font-bold text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-2 flex items-center gap-1.5">
                        <span>📧</span> SMTP Yapılandırması (.env)
                    </h3>
                    <div class="bg-slate-50 dark:bg-slate-700/50 p-3 rounded-lg border border-slate-200 dark:border-slate-600 text-xs font-mono space-y-1 text-slate-700 dark:text-slate-300">
                        <div><b>Host:</b> <?= htmlspecialchars($smtpHost) ?>:<?= htmlspecialchars($smtpPort) ?></div>
                        <div><b>Gönderen:</b> <?= htmlspecialchars($smtpFrom) ?></div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
