<?php
/**
 * Dijital Servis Detayları Form Bileşeni (Netflix, Spotify, Bulut vb.)
 * Hem abonelik-ekle.php hem de abonelik-duzenle.php tarafından ortak kullanılır.
 */
$isEdit = isset($sub);
$hiddenClass = $isEdit ? (($sub['category_type'] === 'digital') ? '' : 'hidden') : 'hidden';
$accountUsername = $isEdit ? ($sub['account_username'] ?? '') : ($_POST['account_username'] ?? '');
$websiteUrl = $isEdit ? ($sub['website_url'] ?? '') : ($_POST['website_url'] ?? '');
$profileCount = $isEdit ? (int)($sub['profile_count'] ?? 1) : (int)($_POST['profile_count'] ?? 4);
$ownerId = $isEdit ? (int)($sub['user_id'] ?? $userId) : (int)$userId;
$allowedUsersList = $isEdit ? ($currentAllowedUsers ?? []) : ($_POST['allowed_users'] ?? []);
?>
<!-- 2. Dijital Servis Detayları -->
<div id="section_digital" class="<?= $hiddenClass ?> bg-white dark:bg-slate-900 border border-purple-200 dark:border-purple-500/30 rounded-3xl p-6 shadow-sm space-y-4">
    <h2 class="text-xs font-bold uppercase tracking-wider text-purple-600 dark:text-purple-400 flex items-center gap-2">
        <span>💻 Dijital Servis & Giriş Bilgileri (AES-256 Şifreli Saklanır)</span>
    </h2>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Kullanıcı Adı / E-Posta</label>
            <input type="text" name="account_username" value="<?= htmlspecialchars($accountUsername) ?>" placeholder="netflix@domain.com"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-purple-500 outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5"><?= $isEdit ? 'Yeni Şifre (Değiştirmek istemiyorsanız boş bırakın)' : 'Hesap Şifresi' ?></label>
            <input type="password" name="account_password" placeholder="<?= $isEdit ? 'Değişmeyecekse boş bırakın' : 'AES-256 ile veritabanında şifrelenecek' ?>"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-purple-500 outline-none">
            <p class="text-[11px] text-slate-500 mt-1">Giriş şifreniz veritabanında açık metin olarak tutulmaz.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Profil Sayısı</label>
            <input type="number" name="profile_count" value="<?= $profileCount ?>" min="1" max="10"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-purple-500 outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Giriş Adresi (URL)</label>
            <input type="url" name="website_url" value="<?= htmlspecialchars($websiteUrl) ?>" placeholder="https://netflix.com"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-purple-500 outline-none">
        </div>
    </div>
</div>
