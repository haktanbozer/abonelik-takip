<?php
$pageTitle = "Bildirim Geçmişi - Abonelik Takip";
require_once __DIR__ . '/db.php';
girisKontrol();

$userId = (int)$_SESSION['user_id'];
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN';

$userList = [];
$filterUserId = 0;

// Gecmis Bildirimleri Cek
try {
    if ($isAdmin) {
        $userList = $pdo->query("SELECT id, username, full_name FROM users ORDER BY full_name ASC, username ASC")->fetchAll();
        $filterUserId = isset($_GET['filter_user']) && is_numeric($_GET['filter_user']) ? (int)$_GET['filter_user'] : 0;

        $whereClause = "";
        $params = [];
        if ($filterUserId > 0) {
            $whereClause = "WHERE nl.user_id = ?";
            $params[] = $filterUserId;
        }

        $stmt = $pdo->prepare("
            SELECT nl.*, s.title as subscription_title, u.username, u.full_name
            FROM notification_logs nl
            LEFT JOIN subscriptions s ON nl.subscription_id = s.id
            LEFT JOIN users u ON nl.user_id = u.id
            {$whereClause}
            ORDER BY nl.sent_at DESC
            LIMIT 150
        ");
        $stmt->execute($params);
    } else {
        // Normal kullanici icin KATI izolasyon: Sadece kendi bildirimlerini gorebilir
        $stmt = $pdo->prepare("
            SELECT nl.*, s.title as subscription_title
            FROM notification_logs nl
            LEFT JOIN subscriptions s ON nl.subscription_id = s.id
            WHERE nl.user_id = ?
            ORDER BY nl.sent_at DESC
            LIMIT 100
        ");
        $stmt->execute([$userId]);
    }
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    sistemLogla("Bildirim gecmisi cekme hatasi: " . $e->getMessage(), 'ERROR');
    $logs = [];
}

require_once __DIR__ . '/header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="flex-1 w-full max-w-7xl mx-auto space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div>
                <h1 class="text-2xl font-bold text-slate-800 dark:text-white tracking-tight flex items-center gap-2">
                    📨 Gönderilen Bildirim Geçmişi
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Sistem tarafından <?= $isAdmin ? 'üyelere' : 'size' ?> e-posta ile iletilen son uyarı ve bilgilendirme kayıtları.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="bildirim-ayarlari.php" class="px-3.5 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold rounded-xl transition">
                    &larr; Bildirim Ayarları
                </a>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <!-- Admin Filtre Cubugu -->
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center gap-2 text-xs font-bold text-slate-700 dark:text-slate-300">
                    <span class="text-base">🛡️</span>
                    <span>Yönetici Filtresi:</span>
                </div>
                <form method="GET" class="flex items-center gap-2">
                    <select name="filter_user" onchange="this.form.submit()" class="p-2 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-xs text-slate-900 dark:text-white outline-none focus:ring-1 focus:ring-blue-500">
                        <option value="0">Tüm Üyelerin Bildirimleri</option>
                        <?php foreach ($userList as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= ($filterUserId === (int)$u['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?> (@<?= htmlspecialchars($u['username']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($filterUserId > 0): ?>
                        <a href="bildirim-gecmisi.php" class="text-xs text-rose-500 hover:underline font-semibold">Temizle</a>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>

        <?php if (empty($logs)): ?>
            <div class="py-16 text-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-8 shadow-sm transition-colors">
                <div class="w-16 h-16 bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 rounded-3xl flex items-center justify-center mx-auto mb-4 text-2xl">
                    ✉️
                </div>
                <h3 class="text-base font-bold text-slate-800 dark:text-white mb-1">Henüz Gönderilen Bildirim Yok</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 max-w-sm mx-auto">
                    Taahhüt bitişleriniz veya yaklaşan son ödeme günleriniz geldiğinde sistem otomatik e-posta gönderip burada arşivleyecektir.
                </p>
            </div>
        <?php else: ?>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm overflow-hidden transition-colors">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800 uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="py-3 px-4">Zaman</th>
                                <th class="py-3 px-4">Tür</th>
                                <th class="py-3 px-4">Abonelik / Başlık</th>
                                <?php if ($isAdmin): ?>
                                    <th class="py-3 px-4">Kullanıcı</th>
                                <?php endif; ?>
                                <th class="py-3 px-4">Alıcı E-posta</th>
                                <th class="py-3 px-4 text-right">Durum</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php foreach ($logs as $l): ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                    <td class="py-3 px-4 text-slate-600 dark:text-slate-400 font-mono whitespace-nowrap">
                                        <?= date('d.m.Y H:i', strtotime($l['sent_at'])) ?>
                                    </td>
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <?php if ($l['notification_type'] === 'trial_end'): ?>
                                            <span class="px-2 py-0.5 bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800 rounded-md font-bold">Deneme</span>
                                        <?php elseif ($l['notification_type'] === 'commitment_end'): ?>
                                            <span class="px-2 py-0.5 bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800 rounded-md font-bold">Taahhüt</span>
                                        <?php elseif ($l['notification_type'] === 'insurance_renewal'): ?>
                                            <span class="px-2 py-0.5 bg-teal-50 dark:bg-teal-900/30 text-teal-700 dark:text-teal-300 border border-teal-200 dark:border-teal-800 rounded-md font-bold">Sigorta</span>
                                        <?php elseif ($l['notification_type'] === 'monthly_summary'): ?>
                                            <span class="px-2 py-0.5 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 rounded-md font-bold">Aylık Özet</span>
                                        <?php elseif (str_starts_with($l['notification_type'], 'admin_copy_')): ?>
                                            <span class="px-2 py-0.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 rounded-md font-bold">Admin Kopya</span>
                                        <?php elseif ($l['notification_type'] === 'system'): ?>
                                            <span class="px-2 py-0.5 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 rounded-md font-bold">Sistem</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-800 rounded-md font-bold">Fatura</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 font-semibold text-slate-800 dark:text-white">
                                        <?= htmlspecialchars($l['title']) ?>
                                    </td>
                                    <?php if ($isAdmin): ?>
                                        <td class="py-3 px-4 text-slate-700 dark:text-slate-300 whitespace-nowrap">
                                            <?= htmlspecialchars($l['full_name'] ?: ($l['username'] ?: 'Sistem')) ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="py-3 px-4 text-slate-600 dark:text-slate-400 font-mono">
                                        <?= htmlspecialchars($l['recipient_email']) ?>
                                    </td>
                                    <td class="py-3 px-4 text-right whitespace-nowrap">
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-bold <?= $l['status'] === 'sent' ? 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300' : 'bg-rose-50 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300' ?>">
                                            <?= $l['status'] === 'sent' ? '✓ Gönderildi' : '✕ Hata' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
