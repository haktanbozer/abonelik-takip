<?php
$pageTitle = "Kullanıcı Yönetimi - Abonelik Takip";
require_once __DIR__ . '/db.php';
girisKontrol();

$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN';
if (!$isAdmin) {
    if (function_exists('sistemLogla')) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user = $_SESSION['username'] ?? 'Bilinmeyen';
        sistemLogla("Yetkisiz Sayfa Erişimi Engellendi: admin.php (Kullanıcı: $user, IP: $ip)", 'SECURITY');
    }
    header("Location: index.php?hata=yetkisiz");
    exit;
}

$mesaj = '';
$mesajTuru = 'info';
$duzenleUser = null;

// POST Islemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');

    // Kullanici Ekle
    if (isset($_POST['kullanici_ekle'])) {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $masterPin = trim($_POST['master_pin'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['ADMIN', 'USER']) ? $_POST['role'] : 'USER';
        $full_name = trim($_POST['full_name'] ?? '');
        $status = isset($_POST['status']) ? 'active' : 'inactive';
        
        if (empty($username) || empty($password) || empty($email) || empty($masterPin)) {
            $mesaj = "Kullanıcı adı, e-posta, şifre ve Master PIN zorunludur.";
            $mesajTuru = 'error';
        } elseif (strlen($masterPin) < 4 || strlen($masterPin) > 8) {
            $mesaj = "Master PIN 4 ile 8 hane arasında olmalıdır.";
            $mesajTuru = 'error';
        } else {
            $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $check->execute([$username, $email]);
            if ($check->fetchColumn() > 0) {
                $mesaj = "Bu kullanıcı adı veya e-posta zaten kullanımda.";
                $mesajTuru = 'error';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $pinHashed = password_hash($masterPin, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, master_pin_hash, role, full_name, status, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                if ($stmt->execute([$username, $email, $hashed, $pinHashed, $role, $full_name, $status])) {
                    $newUserId = (int)$pdo->lastInsertId();
                    $locIds = array_map('intval', $_POST['location_ids'] ?? []);
                    if (!empty($locIds)) {
                        $insLoc = $pdo->prepare("INSERT IGNORE INTO location_access (location_id, user_id) VALUES (?, ?)");
                        foreach ($locIds as $lid) {
                            $insLoc->execute([$lid, $newUserId]);
                        }
                    }
                    $mesaj = "Kullanıcı ve yetkileri başarıyla eklendi.";
                    $mesajTuru = 'success';
                }
            }
        }
    }
    // Kullanici Guncelle
    elseif (isset($_POST['kullanici_guncelle'])) {
        $id = (int)$_POST['user_id'];
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['ADMIN', 'USER']) ? $_POST['role'] : 'USER';
        $full_name = trim($_POST['full_name'] ?? '');
        $status = isset($_POST['status']) ? 'active' : 'inactive';
        $password = $_POST['password'] ?? '';
        $masterPin = trim($_POST['master_pin'] ?? '');
        
        if ($id == $_SESSION['user_id'] && $role !== 'ADMIN') {
            $role = 'ADMIN'; // Kendi yetkisini dusuremez
        }
        
        $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $check->execute([$username, $email, $id]);
        if ($check->fetchColumn() > 0) {
            $mesaj = "Bu kullanıcı adı veya e-posta başka bir hesapta kullanılıyor.";
            $mesajTuru = 'error';
        } else {
            if (!empty($password) && !empty($masterPin)) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $pinHashed = password_hash($masterPin, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, password = ?, master_pin_hash = ?, role = ?, full_name = ?, status = ?, password_changed_at = NOW() WHERE id = ?");
                $stmt->execute([$username, $email, $hashed, $pinHashed, $role, $full_name, $status, $id]);
            } elseif (!empty($password)) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, password = ?, role = ?, full_name = ?, status = ?, password_changed_at = NOW() WHERE id = ?");
                $stmt->execute([$username, $email, $hashed, $role, $full_name, $status, $id]);
            } elseif (!empty($masterPin)) {
                $pinHashed = password_hash($masterPin, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, master_pin_hash = ?, role = ?, full_name = ?, status = ? WHERE id = ?");
                $stmt->execute([$username, $email, $pinHashed, $role, $full_name, $status, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, role = ?, full_name = ?, status = ? WHERE id = ?");
                $stmt->execute([$username, $email, $role, $full_name, $status, $id]);
            }

            // Mekan yetkilerini senkronize et
            $locIds = array_map('intval', $_POST['location_ids'] ?? []);
            $pdo->prepare("DELETE FROM location_access WHERE user_id = ?")->execute([$id]);
            if (!empty($locIds)) {
                $insLoc = $pdo->prepare("INSERT IGNORE INTO location_access (location_id, user_id) VALUES (?, ?)");
                foreach ($locIds as $lid) {
                    $insLoc->execute([$lid, $id]);
                }
            }

            $mesaj = "Kullanıcı bilgileri ve mekan yetkileri güncellendi.";
            $mesajTuru = 'success';
        }
    }
    // Kullanici Sil (Veri Bütünlüğü Korumalı Transaction)
    elseif (isset($_POST['sil_user_id'])) {
        $silId = (int)$_POST['sil_user_id'];
        if ($silId == $_SESSION['user_id']) {
            $mesaj = "Kendi hesabınızı silemezsiniz.";
            $mesajTuru = 'error';
        } else {
            // Devredilecek yönetici ID'sini belirle (Öncelikli olarak ID: 1 veya mevcut admin)
            $targetAdminId = 1;
            try {
                $checkAdmin = $pdo->query("SELECT id FROM users WHERE id = 1 AND role = 'ADMIN'")->fetch();
                if (!$checkAdmin) {
                    $targetAdminId = (int)$_SESSION['user_id'];
                }

                $pdo->beginTransaction();

                // 1. Kullanıcının oluşturduğu abonelikleri ve sorumlu (ödeyici) olduğu kayıtları yöneticiye devret
                $pdo->prepare("UPDATE subscriptions SET user_id = ? WHERE user_id = ?")->execute([$targetAdminId, $silId]);
                $pdo->prepare("UPDATE subscriptions SET payer_user_id = ? WHERE payer_user_id = ?")->execute([$targetAdminId, $silId]);

                // 2. Mekanları (locations) yöneticiye devret
                $pdo->prepare("UPDATE locations SET user_id = ? WHERE user_id = ?")->execute([$targetAdminId, $silId]);

                // 3. Tanımlı kredi kartları ve ödeme yöntemlerini devret
                try {
                    $pdo->prepare("UPDATE credit_cards SET user_id = ? WHERE user_id = ?")->execute([$targetAdminId, $silId]);
                } catch (Exception $e) {}
                try {
                    $pdo->prepare("UPDATE payment_methods SET user_id = ? WHERE user_id = ?")->execute([$targetAdminId, $silId]);
                } catch (Exception $e) {}

                // 4. Yetki ve güvenlik tablolarını temizle
                try {
                    $pdo->prepare("DELETE FROM subscription_access WHERE user_id = ?")->execute([$silId]);
                } catch (Exception $e) {}
                try {
                    $pdo->prepare("DELETE FROM pin_attempts WHERE user_id = ?")->execute([$silId]);
                } catch (Exception $e) {}

                // 5. Kullanıcı kaydını güvenle sil
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$silId]);

                $pdo->commit();
                sistemLogla("Kullanıcı silindi ve verileri Yöneticiye (ID {$targetAdminId}) devredildi: Silinen ID {$silId}", 'ADMIN');
                $mesaj = "Kullanıcı başarıyla silindi. Veri kaybını önlemek için abonelik ve mekan kayıtları yönetici hesabına devredildi.";
                $mesajTuru = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                sistemLogla("Kullanıcı silme hatası: " . $e->getMessage(), 'ERROR');
                $mesaj = "Kullanıcı silinirken hata oluştu: " . $e->getMessage();
                $mesajTuru = 'error';
            }
        }
    }
}

// Duzenleme Modu
$userAllowedLocIds = [];
if (isset($_GET['edit_user'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_user']]);
    $duzenleUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($duzenleUser) {
        $uLocStmt = $pdo->prepare("SELECT location_id FROM location_access WHERE user_id = ?");
        $uLocStmt->execute([(int)$duzenleUser['id']]);
        $userAllowedLocIds = $uLocStmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Mekanlari ve Kullanicilari Cek
$allLocations = $pdo->query("SELECT * FROM locations ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require_once __DIR__ . '/sidebar.php'; ?>
    
    <div class="flex-1 w-full max-w-7xl mx-auto">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors flex items-center gap-2">
                    👥 Kullanıcı Yönetimi
                </h2>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Sistemdeki kullanıcıları, yetkilerini ve hesap durumlarını yönetin.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="mekan-yonetimi.php" class="bg-blue-600 hover:bg-blue-700 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-md shadow-blue-600/20">
                    <span>📍</span>
                    <span>Mekan & Yetki Yönetimi</span>
                </a>
                <?php if($duzenleUser): ?>
                    <a href="admin.php" class="bg-slate-500 hover:bg-slate-600 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition">
                        &larr; Yeni Kullanıcı Moduna Dön
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if($mesaj): ?>
            <?php 
                $alertRenk = $mesajTuru === 'success' 
                    ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 border-emerald-500' 
                    : 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-300 border-rose-500';
            ?>
            <div class="<?= $alertRenk ?> p-4 rounded-xl mb-6 border-l-4 text-xs font-semibold">
                <?= htmlspecialchars($mesaj) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Sol: Kullanici Ekleme / Duzenleme Formu -->
            <div class="lg:col-span-1">
                <div class="bg-white dark:bg-slate-900 p-6 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-800 space-y-4 transition-colors">
                    <h3 class="font-bold text-base <?= $duzenleUser ? 'text-amber-600 dark:text-amber-400' : 'text-slate-800 dark:text-white' ?> border-b border-slate-100 dark:border-slate-800 pb-2">
                        <?= $duzenleUser ? '✏️ Kullanıcıyı Düzenle' : '➕ Yeni Kullanıcı Ekle' ?>
                    </h3>
                    <form method="POST" class="space-y-4">
                        <?= csrfAlaniniEkle() ?>
                        <?php if($duzenleUser): ?>
                            <input type="hidden" name="kullanici_guncelle" value="1">
                            <input type="hidden" name="user_id" value="<?= $duzenleUser['id'] ?>">
                        <?php else: ?>
                            <input type="hidden" name="kullanici_ekle" value="1">
                        <?php endif; ?>
                        
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Ad Soyad</label>
                            <input type="text" name="full_name" value="<?= $duzenleUser ? htmlspecialchars($duzenleUser['full_name'] ?? '') : '' ?>" placeholder="Örn: Ahmet Yılmaz"
                                class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Kullanıcı Adı *</label>
                            <input type="text" name="username" value="<?= $duzenleUser ? htmlspecialchars($duzenleUser['username']) : '' ?>" required 
                                class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">E-Posta *</label>
                            <input type="email" name="email" value="<?= $duzenleUser ? htmlspecialchars($duzenleUser['email']) : '' ?>" required 
                                class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1"><?= $duzenleUser ? 'Yeni Şifre (Boş bırakılabilir)' : 'Şifre *' ?></label>
                            <input type="password" name="password" <?= $duzenleUser ? '' : 'required' ?> 
                                class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1"><?= $duzenleUser ? 'Yeni Master PIN (Boş bırakılabilir, 4-8 hane)' : 'Master PIN (4-8 hane) *' ?></label>
                            <input type="password" name="master_pin" <?= $duzenleUser ? '' : 'required' ?> minlength="4" maxlength="8" placeholder="Örn: 1923"
                                class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none transition">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Rol</label>
                            <select name="role" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none transition">
                                <option value="USER" <?= ($duzenleUser && $duzenleUser['role'] === 'USER') ? 'selected' : '' ?>>Kullanıcı (USER)</option>
                                <option value="ADMIN" <?= ($duzenleUser && $duzenleUser['role'] === 'ADMIN') ? 'selected' : '' ?>>Yönetici (ADMIN)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Erişebileceği Mekanlar</label>
                            <div class="space-y-1.5 max-h-36 overflow-y-auto p-2.5 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl">
                                <?php foreach ($allLocations as $loc): 
                                    $isLocChecked = in_array((int)$loc['id'], array_map('intval', $userAllowedLocIds), true);
                                ?>
                                <label class="flex items-center gap-2 p-1 rounded hover:bg-slate-100 dark:hover:bg-slate-700/60 cursor-pointer text-xs select-none">
                                    <input type="checkbox" name="location_ids[]" value="<?= $loc['id'] ?>" <?= $isLocChecked ? 'checked' : '' ?>
                                        class="w-4 h-4 rounded text-blue-600 focus:ring-blue-500">
                                    <span class="text-slate-800 dark:text-slate-200 font-medium"><?= htmlspecialchars($loc['name']) ?></span>
                                </label>
                                <?php endforeach; ?>
                                <?php if(empty($allLocations)): ?>
                                    <p class="text-[11px] text-slate-400 p-1">Tanımlı mekan bulunmuyor.</p>
                                <?php endif; ?>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-1">Yöneticiler (ADMIN) tüm mekanlara otomatik olarak tam erişebilir.</p>
                        </div>
                        <div class="flex items-center gap-2 pt-1">
                            <input type="checkbox" name="status" id="u_status" <?= (!$duzenleUser || $duzenleUser['status'] === 'active') ? 'checked' : '' ?> class="w-4 h-4 rounded text-blue-600 bg-slate-100 dark:bg-slate-800 border-slate-300 dark:border-slate-600">
                            <label for="u_status" class="text-xs font-bold text-slate-700 dark:text-slate-300 cursor-pointer">Hesap Aktif</label>
                        </div>
                        <div class="flex gap-2 pt-2">
                            <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-xl font-bold text-xs transition shadow-md shadow-blue-500/20">
                                <?= $duzenleUser ? 'Güncelle' : 'Kaydet' ?>
                            </button>
                            <?php if($duzenleUser): ?>
                                <a href="admin.php" class="bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-200 text-center py-2.5 px-4 rounded-xl font-bold text-xs hover:bg-slate-300 dark:hover:bg-slate-700 transition">
                                    &#10005;
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Sag: Kullanici Listesi Tablosu -->
            <div class="lg:col-span-2">
                <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-800 overflow-hidden transition-colors">
                    <div class="px-5 py-4 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                        <h3 class="font-bold text-sm text-slate-800 dark:text-white">Kayıtlı Kullanıcılar</h3>
                        <span class="text-xs bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-2.5 py-0.5 rounded-full font-bold">
                            <?= count($users) ?> Kullanıcı
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400 font-bold border-b border-slate-200 dark:border-slate-800">
                                <tr>
                                    <th class="p-3.5">Kullanıcı</th>
                                    <th class="p-3.5">Rol</th>
                                    <th class="p-3.5">Durum</th>
                                    <th class="p-3.5 text-right">İşlem</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <?php foreach($users as $u): ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                    <td class="p-3.5">
                                        <div class="font-bold text-slate-800 dark:text-white text-sm">
                                             <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?>
                                        </div>
                                        <div class="text-[11px] text-slate-400 font-mono mt-0.5">
                                            @<?= htmlspecialchars($u['username']) ?> &bull; <?= htmlspecialchars($u['email']) ?>
                                        </div>
                                    </td>
                                    <td class="p-3.5">
                                        <span class="inline-block px-2.5 py-0.5 rounded-md text-[10px] font-bold <?= $u['role'] === 'ADMIN' ? 'bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' ?>">
                                            <?= $u['role'] ?>
                                        </span>
                                    </td>
                                    <td class="p-3.5">
                                        <span class="inline-block px-2.5 py-0.5 rounded-md text-[10px] font-bold <?= $u['status'] === 'active' ? 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : 'bg-rose-50 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800' ?>">
                                            <?= $u['status'] === 'active' ? 'Aktif' : 'Pasif' ?>
                                        </span>
                                    </td>
                                    <td class="p-3.5 text-right space-x-1">
                                        <a href="admin.php?edit_user=<?= $u['id'] ?>" class="inline-flex items-center px-2.5 py-1 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-800/40 font-bold transition">
                                            ✏️ Düzenle
                                        </a>
                                        <?php if($u['id'] != $_SESSION['user_id']): ?>
                                        <form method="POST" onsubmit="return confirm('Bu kullanıcıyı silmek istediğinize emin misiniz?')" class="inline">
                                            <?= csrfAlaniniEkle() ?>
                                            <input type="hidden" name="sil_user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="inline-flex items-center px-2.5 py-1 rounded-lg bg-rose-50 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400 hover:bg-rose-100 dark:hover:bg-rose-800/40 font-bold transition">
                                                🗑️ Sil
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
