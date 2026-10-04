<?php
$pageTitle = "Mekan & Yetki Yönetimi - Abonelik Takip";
require_once __DIR__ . '/db.php';
adminKontrol();

$mesaj = '';
$mesajTuru = 'info';
$duzenleModu = false;
$duzenlenecekMekan = null;

// POST Islemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');
    
    // 1. Yeni Mekan Ekleme
    if (isset($_POST['mekan_ekle'])) {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? 'home');
        $color = trim($_POST['color'] ?? 'blue');
        $userId = (int)$_SESSION['user_id'];
        $selectedUsers = array_map('intval', $_POST['selected_users'] ?? []);
        
        if (empty($name)) {
            $mesaj = "Mekan adı boş bırakılamaz.";
            $mesajTuru = 'error';
        } else {
            $check = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE name = ?");
            $check->execute([$name]);
            if ($check->fetchColumn() > 0) {
                $mesaj = "Bu isimde bir mekan zaten tanımlı.";
                $mesajTuru = 'error';
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("INSERT INTO locations (user_id, name, icon, color) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$userId, $name, $icon, $color]);
                    $newLocId = (int)$pdo->lastInsertId();

                    if (!empty($selectedUsers)) {
                        $insAcc = $pdo->prepare("INSERT IGNORE INTO location_access (location_id, user_id) VALUES (?, ?)");
                        foreach ($selectedUsers as $uId) {
                            $insAcc->execute([$newLocId, $uId]);
                        }
                    }

                    $pdo->commit();
                    $mesaj = "Mekan ve üye yetkileri başarıyla oluşturuldu: " . htmlspecialchars($name);
                    $mesajTuru = 'success';
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $mesaj = "Mekan eklenirken hata oluştu: " . $e->getMessage();
                    $mesajTuru = 'error';
                }
            }
        }
    }
    // 2. Mekan Guncelleme
    elseif (isset($_POST['mekan_guncelle'])) {
        $id = (int)$_POST['mekan_id'];
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? 'home');
        $color = trim($_POST['color'] ?? 'blue');
        
        if (empty($name)) {
            $mesaj = "Mekan adı boş bırakılamaz.";
            $mesajTuru = 'error';
        } else {
            $check = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE name = ? AND id != ?");
            $check->execute([$name, $id]);
            if ($check->fetchColumn() > 0) {
                $mesaj = "Bu isimde başka bir mekan mevcut.";
                $mesajTuru = 'error';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE locations SET name = ?, icon = ?, color = ? WHERE id = ?");
                    $stmt->execute([$name, $icon, $color, $id]);
                    $mesaj = "Mekan başarıyla güncellendi.";
                    $mesajTuru = 'success';
                } catch (Exception $e) {
                    $mesaj = "Güncelleme hatası: " . $e->getMessage();
                    $mesajTuru = 'error';
                }
            }
        }
    }
    // 3. Mekan Yetkilerini Guncelle
    elseif (isset($_POST['yetki_guncelle'])) {
        $locId = (int)($_POST['location_id'] ?? 0);
        $userIds = array_map('intval', $_POST['user_ids'] ?? []);

        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM location_access WHERE location_id = ?")->execute([$locId]);

            if (!empty($userIds)) {
                $insStmt = $pdo->prepare("INSERT IGNORE INTO location_access (location_id, user_id) VALUES (?, ?)");
                foreach ($userIds as $uId) {
                    $insStmt->execute([$locId, $uId]);
                }
            }

            $pdo->commit();
            sistemLogla("Mekan Yetkileri Güncellendi: Loc ID {$locId}, Üye Sayısı: " . count($userIds), 'INFO');
            $mesaj = "Mekana ait üye yetkileri başarıyla kaydedildi.";
            $mesajTuru = 'success';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mesaj = "Yetkiler güncellenirken hata oluştu: " . $e->getMessage();
            $mesajTuru = 'error';
        }
    }
    // 4. Mekan Silme
    elseif (isset($_POST['sil_mekan_id'])) {
        $silId = (int)$_POST['sil_mekan_id'];
        
        try {
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE location_id = ?");
            $stmtCheck->execute([$silId]);
            $subCount = (int)$stmtCheck->fetchColumn();
            
            if ($subCount > 0) {
                $mesaj = "Bu mekan silinemez! İçinde tanımlı {$subCount} adet abonelik bulunuyor. Önce abonelikleri başka bir mekana aktarın.";
                $mesajTuru = 'error';
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM location_access WHERE location_id = ?")->execute([$silId]);
                $pdo->prepare("DELETE FROM locations WHERE id = ?")->execute([$silId]);
                $pdo->commit();

                $mesaj = "Mekan ve bağlı erişim izinleri başarıyla silindi.";
                $mesajTuru = 'success';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mesaj = "Silme işlemi sırasında hata: " . $e->getMessage();
            $mesajTuru = 'error';
        }
    }
}

// Duzenleme Modu Kontrolu
if (isset($_GET['duzenle'])) {
    $duzId = (int)$_GET['duzenle'];
    $stmt = $pdo->prepare("SELECT * FROM locations WHERE id = ?");
    $stmt->execute([$duzId]);
    $duzenlenecekMekan = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($duzenlenecekMekan) {
        $duzenleModu = true;
    }
}

// Sistemdeki Aktif Kullanicilar
$aktifKullanicilar = $pdo->query("
    SELECT id, username, full_name, role 
    FROM users 
    WHERE status = 'active' 
    ORDER BY (role = 'ADMIN') DESC, full_name ASC, username ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Mekanlari ve Bagli Abonelik Sayilarini Cek
try {
    $sql = "
        SELECT l.*, 
               COUNT(s.id) as sub_count,
               COALESCE(SUM(CASE WHEN s.billing_cycle = 'monthly' THEN s.price 
                                 WHEN s.billing_cycle = 'annual' THEN s.price / 12 
                                 ELSE s.price END), 0) as monthly_total
        FROM locations l
        LEFT JOIN subscriptions s ON l.id = s.location_id AND s.status = 'active'
        GROUP BY l.id
        ORDER BY l.id ASC
    ";
    $mekanlar = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $mekanlar = [];
}

// Mekan Bazli Yetkili Kullanici Haritasi
$locationAccessMap = [];
try {
    $accessRows = $pdo->query("SELECT location_id, user_id FROM location_access")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($accessRows as $row) {
        $locationAccessMap[$row['location_id']][] = (int)$row['user_id'];
    }
} catch (Exception $e) {
    $locationAccessMap = [];
}

require_once __DIR__ . '/header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="flex-1 w-full max-w-7xl mx-auto space-y-6">
        
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors flex items-center gap-2">
                    📍 Mekan & Yetki Yönetimi
                </h2>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Abonelik kapsamlarını yönetin ve hangi üyelerin hangi mekanlara erişebileceğini yetkilendirin.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="admin.php" class="bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 px-3.5 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 border border-slate-200 dark:border-slate-700">
                    <span>👥</span>
                    <span>Kullanıcı Yönetimi</span>
                </a>
                <?php if($duzenleModu): ?>
                    <a href="mekan-yonetimi.php" class="bg-slate-500 hover:bg-slate-600 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition">
                        &larr; Ekleme Moduna Dön
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if($mesaj): ?>
            <?php 
                $alertRenk = $mesajTuru === 'success' 
                    ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 border-emerald-500' 
                    : ($mesajTuru === 'error' ? 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-300 border-rose-500' : 'bg-blue-50 dark:bg-blue-950/40 text-blue-800 dark:text-blue-300 border-blue-500');
            ?>
            <div class="<?= $alertRenk ?> p-4 rounded-xl border-l-4 text-xs font-semibold shadow-sm">
                <?= htmlspecialchars($mesaj) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Sol: Mekan Ekle / Duzenle Formu -->
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white dark:bg-slate-900 p-6 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-800 transition-colors">
                    <h3 class="font-bold text-base <?= $duzenleModu ? 'text-amber-600 dark:text-amber-400' : 'text-slate-800 dark:text-white' ?> mb-4 border-b border-slate-100 dark:border-slate-800 pb-2">
                        <?= $duzenleModu ? '✏️ Mekanı Düzenle' : '➕ Yeni Mekan Ekle' ?>
                    </h3>
                    
                    <form method="POST" class="space-y-4">
                        <?= csrfAlaniniEkle() ?>
                        <?php if($duzenleModu): ?>
                            <input type="hidden" name="mekan_guncelle" value="1">
                            <input type="hidden" name="mekan_id" value="<?= $duzenlenecekMekan['id'] ?>">
                        <?php else: ?>
                            <input type="hidden" name="mekan_ekle" value="1">
                        <?php endif; ?>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Mekan Adı *</label>
                            <input type="text" name="name" value="<?= $duzenleModu ? htmlspecialchars($duzenlenecekMekan['name']) : '' ?>" placeholder="Örn: Ev, Dijital, Sigorta, BES, Araç, Ofis" required 
                                class="w-full p-2.5 border rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-800 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">İkon Tipi</label>
                            <select name="icon" class="w-full p-2.5 border rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-800 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition">
                                <?php
                                $ikonlar = [
                                    'home' => '🏠 Ev / Konut',
                                    'globe' => '💻 Dijital / Bulut',
                                    'shield' => '🛡️ Sigorta / Güvence',
                                    'chart' => '📈 BES / Finans',
                                    'car' => '🚗 Araç / Taşıt',
                                    'building' => '🏢 İş / Ofis',
                                    'sun' => '🏖️ Yazlık / Tatil'
                                ];
                                $seciliIkon = $duzenleModu ? $duzenlenecekMekan['icon'] : 'home';
                                foreach ($ikonlar as $key => $label):
                                ?>
                                    <option value="<?= $key ?>" <?= ($seciliIkon === $key) ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">Renk Teması</label>
                            <select name="color" class="w-full p-2.5 border rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-800 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none transition">
                                <?php
                                $renkler = [
                                    'blue' => 'Mavi (Blue)',
                                    'purple' => 'Mor (Purple)',
                                    'rose' => 'Kırmızı (Rose)',
                                    'cyan' => 'Açık Mavi / Turkuaz (Cyan)',
                                    'amber' => 'Turuncu / Amber',
                                    'emerald' => 'Yeşil (Emerald)'
                                ];
                                $seciliRenk = $duzenleModu ? $duzenlenecekMekan['color'] : 'blue';
                                foreach ($renkler as $key => $label):
                                ?>
                                    <option value="<?= $key ?>" <?= ($seciliRenk === $key) ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if(!$duzenleModu): ?>
                        <div class="pt-2 border-t border-slate-100 dark:border-slate-800">
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">
                                👥 İlk Yetkili Üyeler (İsteğe Bağlı)
                            </label>
                            <div class="space-y-2 max-h-40 overflow-y-auto pr-1">
                                <?php foreach ($aktifKullanicilar as $u): ?>
                                    <label class="flex items-center gap-2 p-2 bg-slate-50 dark:bg-slate-800/60 rounded-lg cursor-pointer text-xs">
                                        <input type="checkbox" name="selected_users[]" value="<?= $u['id'] ?>" class="rounded text-blue-600 focus:ring-blue-500">
                                        <span class="font-medium text-slate-800 dark:text-slate-200 truncate">
                                            <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?>
                                            <?php if($u['role'] === 'ADMIN'): ?><span class="text-[10px] text-purple-600 dark:text-purple-400 font-bold">(Yönetici)</span><?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="pt-2 flex gap-2">
                            <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-xl font-bold text-xs shadow-md shadow-blue-500/20 transition">
                                <?= $duzenleModu ? 'Güncellemeyi Kaydet' : 'Mekanı Kaydet' ?>
                            </button>
                            <?php if($duzenleModu): ?>
                                <a href="mekan-yonetimi.php" class="bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-200 px-4 py-2.5 rounded-xl font-bold text-xs hover:bg-slate-300 dark:hover:bg-slate-700 transition">
                                    &#10005;
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Sag: Mevcut Mekanlar ve Yetki Yonetimi -->
            <div class="lg:col-span-2 space-y-4">
                <div class="flex items-center justify-between pb-1">
                    <h3 class="font-bold text-sm text-slate-800 dark:text-white flex items-center gap-2">
                        <span>Tanımlı Mekanlar & Üye İzinleri</span>
                        <span class="text-xs bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-2.5 py-0.5 rounded-full font-bold">
                            <?= count($mekanlar) ?> Mekan
                        </span>
                    </h3>
                </div>

                <?php 
                $ikonSembol = [
                    'home' => '🏠',
                    'globe' => '💻',
                    'shield' => '🛡️',
                    'chart' => '📈',
                    'car' => '🚗',
                    'building' => '🏢',
                    'sun' => '🏖️'
                ];
                ?>

                <div class="space-y-4">
                    <?php foreach ($mekanlar as $m): 
                        $emoji = $ikonSembol[$m['icon']] ?? '📍';
                        $allowedUsers = $locationAccessMap[$m['id']] ?? [];
                    ?>
                    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-800 p-5 space-y-4 transition-colors">
                        
                        <!-- Ust Bilgi Satiri -->
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-3">
                                <span class="text-2xl p-2.5 bg-slate-50 dark:bg-slate-800 rounded-xl"><?= $emoji ?></span>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h4 class="font-bold text-base text-slate-900 dark:text-white"><?= htmlspecialchars($m['name']) ?></h4>
                                        <span class="text-[10px] font-mono px-2 py-0.5 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 rounded-md">
                                            <?= htmlspecialchars($m['color']) ?>
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-3 text-xs text-slate-500 dark:text-slate-400 mt-1">
                                        <span class="font-semibold text-blue-600 dark:text-blue-400"><?= (int)$m['sub_count'] ?> Aktif Abonelik</span>
                                        <span>&bull;</span>
                                        <span><?= paraFormat($m['monthly_total']) ?> / ay</span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 self-end sm:self-center">
                                <a href="mekan-yonetimi.php?duzenle=<?= $m['id'] ?>" class="px-3 py-1.5 bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/50 rounded-lg text-xs font-bold transition">
                                    ✏️ Düzenle
                                </a>
                                <form method="POST" onsubmit="return confirm('Bu mekanı ve bağlı tüm yetkileri silmek istediğinize emin misiniz?');" class="inline">
                                    <?= csrfAlaniniEkle() ?>
                                    <input type="hidden" name="sil_mekan_id" value="<?= $m['id'] ?>">
                                    <button type="submit" class="px-3 py-1.5 bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 hover:bg-rose-100 dark:hover:bg-rose-900/40 rounded-lg text-xs font-bold transition">
                                        🗑️ Sil
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Yetkili Uyeler Formu -->
                        <form method="POST" class="pt-1">
                            <?= csrfAlaniniEkle() ?>
                            <input type="hidden" name="yetki_guncelle" value="1">
                            <input type="hidden" name="location_id" value="<?= $m['id'] ?>">

                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                                    <span>👥 Yetkili Üyeler:</span>
                                    <span class="text-[11px] font-normal text-slate-400">(Bu mekana ve içindeki aboneliklere erişebilecek kullanıcılar)</span>
                                </span>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2 p-3 bg-slate-50 dark:bg-slate-950/60 rounded-xl border border-slate-100 dark:border-slate-800">
                                <?php foreach ($aktifKullanicilar as $u): 
                                    $isOwner = ((int)$m['user_id'] === (int)$u['id']);
                                    $isExplicitAllowed = in_array((int)$u['id'], $allowedUsers, true);
                                    $isRoleAdmin = ($u['role'] === 'ADMIN');
                                    $isChecked = ($isRoleAdmin || $isOwner || $isExplicitAllowed);
                                ?>
                                <label class="flex items-center gap-2 p-2 rounded-lg transition <?= $isChecked ? 'bg-blue-50/70 dark:bg-blue-950/40 border border-blue-200/60 dark:border-blue-900/40' : 'hover:bg-slate-100 dark:hover:bg-slate-900' ?> cursor-pointer select-none">
                                    <input type="checkbox" name="user_ids[]" value="<?= $u['id'] ?>" <?= $isChecked ? 'checked' : '' ?> <?= $isRoleAdmin ? 'disabled checked' : '' ?>
                                        class="w-4 h-4 rounded text-blue-600 focus:ring-blue-500 bg-white dark:bg-slate-900 border-slate-300 dark:border-slate-700">
                                    
                                    <?php if($isRoleAdmin): ?>
                                        <!-- Admin disabled checkbox form postunda gitmedigi icin hidden gonder -->
                                        <input type="hidden" name="user_ids[]" value="<?= $u['id'] ?>">
                                    <?php endif; ?>

                                    <div class="truncate text-xs">
                                        <span class="font-semibold text-slate-800 dark:text-slate-200 block truncate">
                                            <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?>
                                        </span>
                                        <span class="text-[10px] text-slate-400 block">
                                            @<?= htmlspecialchars($u['username']) ?>
                                            <?php if($isRoleAdmin): ?>
                                                <span class="text-purple-600 dark:text-purple-400 font-bold ml-1">• Yönetici</span>
                                            <?php elseif($isOwner): ?>
                                                <span class="text-amber-600 dark:text-amber-400 font-bold ml-1">• Kurucu</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>

                            <div class="mt-3 flex justify-end">
                                <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm shadow-emerald-600/20">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                    <span>Yetkileri Güncelle</span>
                                </button>
                            </div>
                        </form>

                    </div>
                    <?php endforeach; ?>

                    <?php if (empty($mekanlar)): ?>
                        <div class="py-12 text-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6">
                            <p class="text-xs text-slate-400">Henüz tanımlı mekan bulunmuyor. Sol taraftan ilk mekanınızı oluşturabilirsiniz.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        </div>

    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
