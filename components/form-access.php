<?php
/**
 * Abonelik Bilgilerini & Şifreyi Görüntüleme İzni Form Bileşeni
 * Tüm abonelik türlerinde (Dijital, Fatura, Telekom, Sigorta, BES, Araç, Vergi, Genel) ortak kullanılır.
 */
if (!defined('HEADER_INCLUDED') && !isset($pdo)) {
    http_response_code(403);
    die('Doğrudan erişim engellendi.');
}
$isEdit = isset($sub);
$ownerId = $isEdit ? (int)($sub['user_id'] ?? $userId) : (int)$userId;
$allowedUsersList = $isEdit ? ($currentAllowedUsers ?? []) : ($_POST['allowed_users'] ?? []);
?>
<!-- Abonelik Bilgilerini Görüntüleme ve Erişim İzni (Tüm Kategoriler) -->
<div id="access-section" class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-4">
    <div class="pb-3 border-b border-slate-100 dark:border-slate-800">
        <h2 class="text-xs font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400 flex items-center gap-2">
            <span>👥 Bilgileri Görüntüleme ve Erişim İzni</span>
        </h2>
        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
            Aboneliği ekleyen kişi ve yöneticiler tüm detayları görebilir. Bu aboneliği, fatura/poliçe bilgilerini ve (varsa) hesap giriş şifresini görme yetkisi vermek istediğiniz diğer üyeleri işaretleyin:
        </p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5">
        <?php 
        $otherUsersCount = 0;
        foreach ($allUsers as $u): 
            if ($u['id'] == $ownerId) continue;
            $otherUsersCount++;
            $isAllowed = in_array($u['id'], $allowedUsersList);
        ?>
            <label class="flex items-center gap-2.5 p-3 bg-slate-50 dark:bg-slate-950/80 border border-slate-200 dark:border-slate-800 rounded-2xl cursor-pointer hover:border-blue-400 dark:hover:border-blue-500/40 transition">
                <input type="checkbox" name="allowed_users[]" value="<?= $u['id'] ?>" <?= $isAllowed ? 'checked' : '' ?>
                    class="w-4 h-4 rounded bg-white dark:bg-slate-900 border-slate-300 dark:border-slate-700 text-blue-600 focus:ring-0">
                <div class="min-w-0">
                    <span class="text-xs font-bold text-slate-800 dark:text-slate-200 block truncate"><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></span>
                    <span class="text-[10px] text-slate-400 block truncate">@<?= htmlspecialchars($u['username']) ?> &bull; <?= $u['role'] ?></span>
                </div>
            </label>
        <?php endforeach; ?>
        <?php if ($otherUsersCount === 0): ?>
            <div class="col-span-full text-xs text-slate-500 italic py-2">
                Sistemde henüz başka aktif üye bulunmuyor. (Yönetici panelinden yeni aile/ekip üyesi ekleyebilirsiniz)
            </div>
        <?php endif; ?>
    </div>
</div>
