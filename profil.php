<?php
// profil.php - Profil ve Güvenlik Ayarları
require_once __DIR__ . '/db.php';
girisKontrol();

$userId = $_SESSION['user_id'];
$successMsg = '';
$errorMsg = '';

// Kullanıcı Bilgilerini Çek
try {
    $stmt = $pdo->prepare("SELECT id, username, email, full_name, COALESCE(monthly_budget, 0) as monthly_budget, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    $stmtCards = $pdo->prepare("SELECT id, bank_name, card_name, card_last_4, statement_cut_day, due_day FROM credit_cards WHERE user_id = ? AND is_active = 1 ORDER BY id ASC");
    $stmtCards->execute([$userId]);
    $userCreditCards = $stmtCards->fetchAll();

    $stmtPm = $pdo->prepare("SELECT * FROM payment_methods WHERE user_id = ? ORDER BY id ASC");
    $stmtPm->execute([$userId]);
    $paymentMethods = $stmtPm->fetchAll();
} catch (Exception $e) {
    sistemLogla("Profil veri çekme hatası: " . $e->getMessage(), 'ERROR');
}

// Profil / Şifre / PIN Güncelleme İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');
    $formType = $_POST['form_type'] ?? '';

    // 0. Aylık Harcama Bütçesi
    if ($formType === 'butce') {
        $rawVal = trim($_POST['monthly_budget'] ?? '0');
        $rawVal = str_replace(' ', '', $rawVal);
        if (strpos($rawVal, ',') !== false && strpos($rawVal, '.') !== false) {
            $rawVal = str_replace('.', '', $rawVal);
            $rawVal = str_replace(',', '.', $rawVal);
        } else {
            $rawVal = str_replace(',', '.', $rawVal);
        }
        $monthlyBudget = max(0, (float)$rawVal);
        try {
            $pdo->prepare("UPDATE users SET monthly_budget = ? WHERE id = ?")->execute([$monthlyBudget, $userId]);
            $user['monthly_budget'] = $monthlyBudget;
            $successMsg = 'Aylık bütçe hedefiniz başarıyla kaydedildi.';
            sistemLogla("Kullanıcı Aylık Bütçe Hedefini Güncelledi: {$monthlyBudget} ₺ (ID {$userId})", 'INFO');
        } catch (Exception $e) {
            $errorMsg = 'Bütçe güncellenirken bir hata oluştu: ' . $e->getMessage();
        }
    }

    // 1. Profil Bilgileri
    if ($formType === 'profil') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $currentPass = $_POST['current_password'] ?? '';

        if (empty($currentPass)) {
            $errorMsg = 'Profil bilgilerini güncellemek için mevcut giriş şifrenizi girmelisiniz.';
        } elseif (empty($email)) {
            $errorMsg = 'E-posta adresi boş bırakılamaz.';
        } else {
            // Şifre doğrulama (E-posta değişikliği için zorunlu güvenlik onayı)
            $stmtP = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmtP->execute([$userId]);
            $userPassHash = $stmtP->fetchColumn();

            if (!password_verify($currentPass, $userPassHash)) {
                $errorMsg = 'Mevcut giriş şifreniz hatalı! Profil bilgileri güncellenemedi.';
                sistemLogla("Profil Güncelleme Hatalı Şifre Doğrulaması: User ID {$userId}", 'SECURITY');
            } else {
                try {
                    $upd = $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
                    $upd->execute([$fullName, $email, $userId]);
                    $user['full_name'] = $fullName;
                    $user['email'] = $email;
                    $_SESSION['user_email'] = $email;
                    $successMsg = 'Profil bilgileriniz başarıyla güncellendi.';
                    sistemLogla("Kullanıcı Profil Bilgilerini Güncelledi: ID {$userId}", 'INFO');
                } catch (Exception $e) {
                    sistemLogla("Profil güncelleme hatası: " . $e->getMessage(), 'ERROR');
                    $errorMsg = 'Profil güncellenirken bir hata oluştu. Lütfen tekrar deneyin.';
                }
            }
        }
    }

    // 2. Giriş Şifresi Değiştirme
    if ($formType === 'sifre') {
        $oldPass = $_POST['old_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $newPass2 = $_POST['new_password_confirm'] ?? '';

        if (empty($oldPass) || empty($newPass)) {
            $errorMsg = 'Lütfen tüm şifre alanlarını doldurun.';
        } elseif ($newPass !== $newPass2) {
            $errorMsg = 'Yeni şifreler birbiriyle uyuşmuyor.';
        } elseif (strlen($newPass) < 6) {
            $errorMsg = 'Yeni şifre en az 6 karakter olmalıdır.';
        } else {
            $stmtP = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmtP->execute([$userId]);
            $currentHash = $stmtP->fetchColumn();

            if (!password_verify($oldPass, $currentHash)) {
                $errorMsg = 'Mevcut giriş şifreniz hatalı.';
            } else {
                $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?")->execute([$newHash, $userId]);
                $_SESSION['login_time'] = time(); // Bu tarayıcının oturumunun düşmesini önle
                $successMsg = 'Giriş şifreniz başarıyla değiştirildi. Güvenliğiniz için diğer oturumlar sonlandırıldı.';
                sistemLogla("Kullanıcı Şifresini Değiştirdi: ID {$userId}", 'AUTH');
            }
        }
    }

    // 3. Master PIN Değiştirme (Veritabanı Brute-Force Korumalı)
    if ($formType === 'pin') {
        $oldPin = trim($_POST['old_pin'] ?? '');
        $newPin = trim($_POST['new_pin'] ?? '');

        // 1. PIN Brute-force kilit kontrolü (pin_attempts)
        try {
            $stmtPinAtt = $pdo->prepare("SELECT attempts, locked_until FROM pin_attempts WHERE user_id = ?");
            $stmtPinAtt->execute([$userId]);
            $pinAttempt = $stmtPinAtt->fetch();

            if ($pinAttempt && !empty($pinAttempt['locked_until']) && strtotime($pinAttempt['locked_until']) > time()) {
                $kalan = ceil((strtotime($pinAttempt['locked_until']) - time()) / 60);
                $errorMsg = "Çok fazla hatalı PIN denemesi. PIN değiştirme işlemi {$kalan} dakika süreyle kilitlendi.";
            }
        } catch (Exception $e) {
            sistemLogla("PIN attempt sorgu hatası: " . $e->getMessage(), 'ERROR');
            $pinAttempt = null;
        }

        if (empty($errorMsg)) {
            if (strlen($newPin) < 4 || strlen($newPin) > 8) {
                $errorMsg = 'Yeni Master PIN 4 ile 8 hane arasında olmalıdır.';
            } else {
                $stmtPin = $pdo->prepare("SELECT password, master_pin_hash FROM users WHERE id = ?");
                $stmtPin->execute([$userId]);
                $uRow = $stmtPin->fetch();
                $pinHash = $uRow['master_pin_hash'] ?? null;
                $accPass = $uRow['password'] ?? '';

                $pinValid = false;
                if (!empty($pinHash) && password_verify($oldPin, $pinHash)) {
                    $pinValid = true;
                } elseif (password_verify($oldPin, $accPass)) {
                    $pinValid = true;
                }

                if (!$pinValid) {
                    $curAttempts = ($pinAttempt && (empty($pinAttempt['locked_until']) || strtotime($pinAttempt['locked_until']) <= time())) ? (int)$pinAttempt['attempts'] : 0;
                    $newAttempts = $curAttempts + 1;

                    if ($newAttempts >= 5) {
                        $lockedUntil = date('Y-m-d H:i:s', strtotime('+5 minutes'));
                        $stmtLock = $pdo->prepare("
                            INSERT INTO pin_attempts (user_id, attempts, last_attempt, locked_until)
                            VALUES (?, ?, NOW(), ?)
                            ON DUPLICATE KEY UPDATE attempts = ?, last_attempt = NOW(), locked_until = ?
                        ");
                        $stmtLock->execute([$userId, $newAttempts, $lockedUntil, $newAttempts, $lockedUntil]);
                        sistemLogla("Master PIN Değiştirme Brute-Force Kilitlendi: User ID {$userId}", 'SECURITY');
                        $errorMsg = '5 hatalı PIN denemesi! Güvenlik nedeniyle PIN işlemleri 5 dakika kilitlendi.';
                    } else {
                        $stmtUpsert = $pdo->prepare("
                            INSERT INTO pin_attempts (user_id, attempts, last_attempt, locked_until)
                            VALUES (?, 1, NOW(), NULL)
                            ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW(), locked_until = NULL
                        ");
                        $stmtUpsert->execute([$userId]);
                        $kalanHak = 5 - $newAttempts;
                        $errorMsg = "Mevcut Master PIN kodunuz hatalı! (Kalan deneme hakkı: {$kalanHak})";
                    }
                } else {
                    $newPinHash = password_hash($newPin, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET master_pin_hash = ? WHERE id = ?")->execute([$newPinHash, $userId]);
                    try {
                        $pdo->prepare("DELETE FROM pin_attempts WHERE user_id = ?")->execute([$userId]);
                    } catch (Exception $e) {}
                    unset($_SESSION['pin_attempts']);
                    unset($_SESSION['pin_lock_until']);
                    $successMsg = 'Master PIN kodunuz başarıyla güncellendi.';
                    sistemLogla("Master PIN Güncellendi: ID {$userId}", 'SECURITY');
                }
            }
        }
    }

    // 4. Kart / Ödeme Yöntemi Ekleme
    if ($formType === 'kart_ekle') {
        $itemType = $_POST['item_type'] ?? 'credit_card';
        $title = trim($_POST['pm_title'] ?? '');
        $last4 = trim($_POST['pm_last4'] ?? '');
        $isAuto = !empty($_POST['pm_auto']) ? 1 : 0;

        if (empty($title)) {
            $errorMsg = 'Kart veya hesap tanımı boş bırakılamaz.';
        } else {
            if ($itemType === 'credit_card') {
                $bankName = $title;
                $cardName = $title;
                $parts = explode(' ', $title, 2);
                if (count($parts) > 1) {
                    $bankName = $parts[0];
                    $cardName = $parts[1];
                }
                $stmtAddCard = $pdo->prepare("
                    INSERT INTO credit_cards (user_id, bank_name, card_name, card_network, card_last_4, statement_cut_day, due_day, is_active)
                    VALUES (?, ?, ?, 'mastercard', ?, 1, 10, 1)
                ");
                $stmtAddCard->execute([$userId, $bankName, $cardName, !empty($last4) ? $last4 : null]);
                guvenliYonlendir("profil.php?msg=card_added");
            } else {
                $stmtAddPm = $pdo->prepare("
                    INSERT INTO payment_methods (user_id, title, bank_name, card_type, card_last_4, is_auto_pay)
                    VALUES (?, ?, ?, 'bank_transfer', ?, ?)
                ");
                $stmtAddPm->execute([$userId, $title, $title, !empty($last4) ? $last4 : null, $isAuto]);
                guvenliYonlendir("profil.php?msg=card_added");
            }
        }
    }

    // 5. Kart / Ödeme Yöntemi Silme
    if ($formType === 'kart_sil') {
        $delType = $_POST['del_type'] ?? 'payment_method';
        $targetId = (int)($_POST['target_id'] ?? $_POST['pm_id'] ?? 0);

        try {
            if ($delType === 'credit_card') {
                $pdo->prepare("UPDATE subscriptions SET credit_card_id = NULL WHERE credit_card_id = ? AND user_id = ?")->execute([$targetId, $userId]);
                $pdo->prepare("DELETE FROM credit_cards WHERE id = ? AND user_id = ?")->execute([$targetId, $userId]);
            } else {
                $pdo->prepare("UPDATE subscriptions SET payment_method_id = NULL WHERE payment_method_id = ? AND user_id = ?")->execute([$targetId, $userId]);
                $pdo->prepare("DELETE FROM payment_methods WHERE id = ? AND user_id = ?")->execute([$targetId, $userId]);
            }
            guvenliYonlendir("profil.php?msg=card_deleted");
        } catch (Exception $e) {
            $errorMsg = 'Silme işlemi sırasında hata oluştu: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'card_added') {
        $successMsg = 'Kart / Ödeme aracı başarıyla eklendi.';
    } elseif ($_GET['msg'] === 'card_deleted') {
        $successMsg = 'Kart / Ödeme aracı başarıyla silindi.';
    }
}

$pageTitle = "Profil ve Güvenlik Ayarları - Abonelik Takip";
require_once __DIR__ . '/header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">

    <div class="pb-4 border-b border-slate-200 dark:border-slate-800">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">Hesap & Güvenlik Ayarları</h1>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">Giriş şifrenizi, dijital şifre çözücü Master PIN kodunuzu ve kartlarınızı yönetin.</p>
    </div>

    <?php if ($successMsg): ?>
        <div class="p-4 bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 rounded-2xl text-emerald-700 dark:text-emerald-400 text-xs font-bold">
            ✓ <?= htmlspecialchars($successMsg) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="p-4 bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-500/20 rounded-2xl text-rose-700 dark:text-rose-400 text-xs font-bold">
            ⚠ <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <!-- Aylık Harcama Bütçesi ve Limit Takibi -->
        <div class="bg-white dark:bg-slate-900 border border-emerald-500/30 rounded-3xl p-6 shadow-sm space-y-4 md:col-span-2 relative overflow-hidden">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xl shadow-sm">
                        🎯
                    </div>
                    <div>
                        <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Aylık Harcama Bütçe Hedefi</h2>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Aylık maksimum hedef abonelik bütçenizi belirleyin. Dashboard ana sayfasında kalan bütçeniz ve harcama yüzdeniz anlık hesaplanır.</p>
                    </div>
                </div>
                <?php if ((float)($user['monthly_budget'] ?? 0) > 0): ?>
                    <span class="px-3 py-1 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 rounded-full text-xs font-bold self-start sm:self-auto">
                        Mevcut Hedef: <?= paraFormat($user['monthly_budget'], 'TRY') ?>
                    </span>
                <?php endif; ?>
            </div>

            <form method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                <?= csrfAlaniniEkle() ?>
                <input type="hidden" name="form_type" value="butce">

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Aylık Maksimum Hedef Tutar (₺)</label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" name="monthly_budget" value="<?= htmlspecialchars((string)((float)($user['monthly_budget'] ?? 0) > 0 ? $user['monthly_budget'] : '')) ?>" placeholder="Örn: 3500.00 (Kapalı bırakmak için 0)"
                            class="w-full p-3 pl-8 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs font-bold focus:ring-2 focus:ring-emerald-500 outline-none">
                        <span class="absolute left-3 top-3 text-slate-400 font-bold text-xs">₺</span>
                    </div>
                    <span class="text-[10px] text-slate-400 mt-1 block">Bütçe takibini pasif bırakmak isterseniz 0 yazabilir veya boş bırakabilirsiniz.</span>
                </div>

                <div>
                    <button type="submit" class="w-full py-3 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white rounded-xl font-bold text-xs transition shadow-md shadow-emerald-500/20">
                        Bütçe Limitini Kaydet
                    </button>
                </div>
            </form>
        </div>

        <!-- 1. Profil Bilgileri -->
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-4">
            <h2 class="text-xs font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400">1. Profil Bilgileri</h2>

            <form method="POST" class="space-y-4">
                <?= csrfAlaniniEkle() ?>
                <input type="hidden" name="form_type" value="profil">

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Kullanıcı Adı (Sabit)</label>
                    <input type="text" value="<?= htmlspecialchars($user['username']) ?>" disabled
                        class="w-full p-3 bg-slate-100 dark:bg-slate-950/40 border border-slate-200 dark:border-slate-800/80 rounded-xl text-slate-500 dark:text-slate-400 text-xs font-mono cursor-not-allowed">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Ad Soyad</label>
                    <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>"
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">E-Posta (Bildirim Adresi)</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Mevcut Şifre * (Değişikliği Onaylamak İçin)</label>
                    <input type="password" name="current_password" required autocomplete="current-password" placeholder="Mevcut giriş şifreniz"
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs transition shadow-sm">
                    Bilgileri Güncelle
                </button>
            </form>
        </div>

        <!-- 2. Master PIN Ayarı (Şifre Çözücü) -->
        <div class="bg-white dark:bg-slate-900 border border-purple-200 dark:border-purple-500/20 rounded-3xl p-6 shadow-sm space-y-4">
            <div>
                <h2 class="text-xs font-bold uppercase tracking-wider text-purple-600 dark:text-purple-400">2. Master Güvenlik PIN Kodu</h2>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">Dijital hesap şifrelerini (Netflix, Disney vb.) görüntülemek için gereken 4-8 haneli güvenlik anahtarınız.</p>
            </div>

            <form method="POST" class="space-y-4">
                <?= csrfAlaniniEkle() ?>
                <input type="hidden" name="form_type" value="pin">

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Mevcut Master PIN</label>
                    <input type="password" name="old_pin" required maxlength="8" placeholder="••••"
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-purple-700 dark:text-purple-300 text-xs font-mono focus:ring-2 focus:ring-purple-500 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Yeni Master PIN</label>
                    <input type="password" name="new_pin" required minlength="4" maxlength="8" placeholder="Örn: 1923"
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-purple-700 dark:text-purple-300 text-xs font-mono focus:ring-2 focus:ring-purple-500 outline-none">
                </div>

                <button type="submit" class="w-full py-3 bg-purple-600 hover:bg-purple-700 text-white rounded-xl font-bold text-xs transition shadow-sm">
                    Master PIN Güncelle
                </button>
            </form>
        </div>

        <!-- 3. Giriş Şifresi Değiştirme -->
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-4">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-800 dark:text-slate-300">3. Giriş Şifresini Değiştir</h2>

            <form method="POST" class="space-y-4">
                <?= csrfAlaniniEkle() ?>
                <input type="hidden" name="form_type" value="sifre">

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Mevcut Giriş Şifresi</label>
                    <input type="password" name="old_password" required
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Yeni Şifre (En az 6 karakter)</label>
                    <input type="password" name="new_password" required minlength="6"
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">Yeni Şifre Tekrar</label>
                    <input type="password" name="new_password_confirm" required minlength="6"
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <button type="submit" class="w-full py-3 bg-slate-800 hover:bg-slate-900 dark:bg-slate-800 dark:hover:bg-slate-700 text-white rounded-xl font-bold text-xs transition shadow-sm">
                    Giriş Şifresini Değiştir
                </button>
            </form>
        </div>

        <!-- 4. Tanımlı Kartlar / Ödeme Araçları -->
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">4. Tanımlı Kartlar & Ödeme Araçları</h2>
                <a href="kredi-kartlari.php" class="text-xs font-bold text-blue-600 dark:text-blue-400 hover:text-blue-500 dark:hover:text-blue-300 flex items-center gap-1 transition">
                    <span>💳 Gelişmiş Yönetim Merkezi</span>
                    <span>&rarr;</span>
                </a>
            </div>

            <!-- Mevcut Kartlar ve Hesaplar -->
            <div class="divide-y divide-slate-100 dark:divide-slate-800/80 max-h-64 overflow-y-auto pr-1">
                <?php if (empty($userCreditCards) && empty($paymentMethods)): ?>
                    <p class="text-xs text-slate-400 italic py-2">Henüz kayıtlı kredi kartı veya banka hesabı bulunmuyor.</p>
                <?php endif; ?>

                <!-- Kredi Kartları -->
                <?php foreach ($userCreditCards as $card): ?>
                    <div class="py-2.5 flex items-center justify-between text-xs">
                        <div class="flex items-center gap-2.5">
                            <span class="text-base">💳</span>
                            <div>
                                <span class="font-bold text-slate-900 dark:text-white block"><?= htmlspecialchars($card['bank_name'] . ' ' . $card['card_name']) ?></span>
                                <span class="text-slate-500 dark:text-slate-400">
                                    <span class="text-blue-600 dark:text-blue-400 font-semibold">Kredi Kartı</span>
                                    <?= $card['card_last_4'] ? ' • Son 4: ' . htmlspecialchars($card['card_last_4']) : '' ?>
                                    • Kesim: <?= (int)$card['statement_cut_day'] ?> / Vade: <?= (int)$card['due_day'] ?>
                                </span>
                            </div>
                        </div>
                        <form method="POST" onsubmit="return confirm('Bu kredi kartını silmek istediğinize emin misiniz?');">
                            <?= csrfAlaniniEkle() ?>
                            <input type="hidden" name="form_type" value="kart_sil">
                            <input type="hidden" name="del_type" value="credit_card">
                            <input type="hidden" name="target_id" value="<?= $card['id'] ?>">
                            <button type="submit" class="text-slate-400 hover:text-rose-500 p-1 font-bold text-base transition" title="Sil">
                                &times;
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>

                <!-- Banka Hesapları / Ödeme Yöntemleri -->
                <?php foreach ($paymentMethods as $pm): ?>
                    <div class="py-2.5 flex items-center justify-between text-xs">
                        <div class="flex items-center gap-2.5">
                            <span class="text-base">🏦</span>
                            <div>
                                <span class="font-bold text-slate-900 dark:text-white block"><?= htmlspecialchars($pm['title']) ?></span>
                                <span class="text-slate-500 dark:text-slate-400">
                                    <span class="text-emerald-600 dark:text-emerald-400 font-semibold">Banka Hesabı</span>
                                    <?= $pm['card_last_4'] ? ' • Son 4: ' . htmlspecialchars($pm['card_last_4']) : '' ?>
                                    <?= $pm['is_auto_pay'] ? ' • Otomatik Ödeme' : '' ?>
                                </span>
                            </div>
                        </div>
                        <form method="POST" onsubmit="return confirm('Bu ödeme yöntemini silmek istediğinize emin misiniz?');">
                            <?= csrfAlaniniEkle() ?>
                            <input type="hidden" name="form_type" value="kart_sil">
                            <input type="hidden" name="del_type" value="payment_method">
                            <input type="hidden" name="target_id" value="<?= $pm['id'] ?>">
                            <button type="submit" class="text-slate-400 hover:text-rose-500 p-1 font-bold text-base transition" title="Sil">
                                &times;
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Yeni Kart / Hesap Ekle Formu -->
            <form method="POST" class="pt-3 border-t border-slate-200 dark:border-slate-800 space-y-3">
                <?= csrfAlaniniEkle() ?>
                <input type="hidden" name="form_type" value="kart_ekle">

                <!-- Tür Seçimi -->
                <div class="grid grid-cols-2 gap-2 p-1 bg-slate-100 dark:bg-slate-950 rounded-xl">
                    <label class="flex items-center justify-center gap-1.5 p-2 rounded-lg cursor-pointer text-xs font-semibold text-slate-700 dark:text-slate-300 has-[:checked]:bg-white dark:has-[:checked]:bg-slate-800 has-[:checked]:text-blue-600 dark:has-[:checked]:text-blue-400 has-[:checked]:shadow-sm transition">
                        <input type="radio" name="item_type" value="credit_card" checked class="hidden" onchange="toggleCardInputs(this.value)">
                        <span>💳 Kredi Kartı</span>
                    </label>
                    <label class="flex items-center justify-center gap-1.5 p-2 rounded-lg cursor-pointer text-xs font-semibold text-slate-700 dark:text-slate-300 has-[:checked]:bg-white dark:has-[:checked]:bg-slate-800 has-[:checked]:text-blue-600 dark:has-[:checked]:text-blue-400 has-[:checked]:shadow-sm transition">
                        <input type="radio" name="item_type" value="bank_account" class="hidden" onchange="toggleCardInputs(this.value)">
                        <span>🏦 Banka Hesabı</span>
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <input type="text" name="pm_title" id="pm_title_input" placeholder="Kart Adı (Örn: Enpara Kredi Kartı)" required
                            class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                    <div>
                        <input type="text" name="pm_last4" placeholder="Son 4 Hane (Örn: 4219)" maxlength="4"
                            class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs font-mono outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                </div>

                <div class="flex items-center justify-between text-xs pt-1">
                    <label id="pm_auto_wrapper" class="flex items-center gap-1.5 cursor-pointer text-slate-600 dark:text-slate-400 hidden">
                        <input type="checkbox" name="pm_auto" value="1" checked class="rounded bg-slate-100 dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-emerald-500 focus:ring-0">
                        <span>Otomatik Ödeme Talimatı</span>
                    </label>

                    <button type="submit" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold text-xs transition shadow-sm ml-auto">
                        + Ekle
                    </button>
                </div>
            </form>
        </div>

    </div>

</div>

<script>
function toggleCardInputs(type) {
    const titleInput = document.getElementById('pm_title_input');
    const autoWrapper = document.getElementById('pm_auto_wrapper');
    if (type === 'credit_card') {
        if (titleInput) titleInput.placeholder = 'Kart Adı (Örn: Enpara Kredi Kartı)';
        if (autoWrapper) autoWrapper.classList.add('hidden');
    } else {
        if (titleInput) titleInput.placeholder = 'Hesap Adı (Örn: Ziraat Vadesiz TL)';
        if (autoWrapper) autoWrapper.classList.remove('hidden');
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
