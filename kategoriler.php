<?php
$pageTitle = "Kategori Yönetimi - Abonelik Takip";
require_once __DIR__ . '/db.php';
girisKontrol();

$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN';
if (!$isAdmin) {
    if (function_exists('sistemLogla')) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user = $_SESSION['username'] ?? 'Bilinmeyen';
        sistemLogla("Yetkisiz Sayfa Erişimi Engellendi: kategoriler.php (Kullanıcı: $user, IP: $ip)", 'SECURITY');
    }
    header("Location: index.php?hata=yetkisiz");
    exit;
}

$aktifTab = $_GET['tab'] ?? 'kategoriler';
$mesaj = '';
$mesajTuru = 'info';
$duzenleKategori = null;
$duzenleTur = null;

// POST Islemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');

    // 1. KATEGORİ İŞLEMLERİ
    if (isset($_POST['kategori_ekle'])) {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $type = trim($_POST['type'] ?? 'digital');
        $color = trim($_POST['color'] ?? 'blue');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        if ($sort_order <= 0) {
            $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM categories")->fetchColumn();
            $sort_order = $maxSort + 1;
        }
        
        if (empty($slug) && !empty($name)) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        }

        if (empty($name) || empty($slug)) {
            $mesaj = "Kategori adı ve URL (slug) zorunludur.";
            $mesajTuru = 'error';
        } else {
            $check = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE slug = ?");
            $check->execute([$slug]);
            if ($check->fetchColumn() > 0) {
                $mesaj = "Bu slug (URL) zaten başka bir kategoride kullanımda.";
                $mesajTuru = 'error';
            } else {
                try { $pdo->exec("ALTER TABLE categories MODIFY COLUMN `type` VARCHAR(50) NOT NULL DEFAULT 'general'"); } catch (Exception $e) {}
                $stmt = $pdo->prepare("INSERT INTO categories (name, slug, type, color, sort_order) VALUES (?, ?, ?, ?, ?)");
                if ($stmt->execute([$name, $slug, $type, $color, $sort_order])) {
                    $mesaj = "Kategori başarıyla eklendi.";
                    $mesajTuru = 'success';
                }
            }
        }
    }
    elseif (isset($_POST['kategori_guncelle'])) {
        $id = (int)$_POST['cat_id'];
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $type = trim($_POST['type'] ?? 'digital');
        $color = trim($_POST['color'] ?? 'blue');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        
        $check = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE slug = ? AND id != ?");
        $check->execute([$slug, $id]);
        if ($check->fetchColumn() > 0) {
            $mesaj = "Bu slug (URL) başka bir kategoride kullanımda.";
            $mesajTuru = 'error';
        } else {
            try { $pdo->exec("ALTER TABLE categories MODIFY COLUMN `type` VARCHAR(50) NOT NULL DEFAULT 'general'"); } catch (Exception $e) {}
            $stmt = $pdo->prepare("UPDATE categories SET name = ?, slug = ?, type = ?, color = ?, sort_order = ? WHERE id = ?");
            $stmt->execute([$name, $slug, $type, $color, $sort_order, $id]);
            $mesaj = "Kategori başarıyla güncellendi.";
            $mesajTuru = 'success';
        }
    }
    elseif (isset($_POST['sil_cat_id'])) {
        $silId = (int)$_POST['sil_cat_id'];
        $checkSub = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE category_id = ?");
        $checkSub->execute([$silId]);
        $subCount = (int)$checkSub->fetchColumn();

        if ($subCount > 0) {
            $mesaj = "Bu kategori silinemez! İçinde $subCount adet tanımlı abonelik bulunuyor.";
            $mesajTuru = 'error';
        } else {
            $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$silId]);
            $mesaj = "Kategori silindi.";
            $mesajTuru = 'success';
        }
    }

    // 2. BAĞLI TÜR İŞLEMLERİ (category_types)
    elseif (isset($_POST['tur_ekle'])) {
        $typeKey = strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', trim($_POST['new_type_key'] ?? ''))));
        $typeName = trim($_POST['tur_name'] ?? '');
        $typeIcon = trim($_POST['tur_icon'] ?? '📌');
        $typeBadge = trim($_POST['badge_color'] ?? 'blue');
        $typeSort = (int)($_POST['tur_sort_order'] ?? 0);
        if ($typeSort <= 0) {
            $maxTSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM category_types")->fetchColumn();
            $typeSort = $maxTSort + 1;
        }

        if (empty($typeKey) || empty($typeName)) {
            $mesaj = "Sistem kodu ve tür başlığı zorunludur.";
            $mesajTuru = 'error';
            $aktifTab = 'turler';
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM category_types WHERE type_key = ?");
            $chk->execute([$typeKey]);
            if ($chk->fetchColumn() > 0) {
                $mesaj = "Bu sistem kodu ($typeKey) zaten kullanımda.";
                $mesajTuru = 'error';
                $aktifTab = 'turler';
            } else {
                try {
                    $pdo->exec("ALTER TABLE categories MODIFY COLUMN `type` VARCHAR(50) NOT NULL DEFAULT 'general'");
                } catch (Exception $e) {}

                $insTur = $pdo->prepare("INSERT INTO category_types (type_key, name, icon, badge_color, sort_order) VALUES (?, ?, ?, ?, ?)");
                $insTur->execute([$typeKey, $typeName, $typeIcon, $typeBadge, $typeSort]);
                $mesaj = "Yeni Bağlı Tür başarıyla eklendi: $typeName";
                $mesajTuru = 'success';
                $aktifTab = 'turler';
            }
        }
    }
    elseif (isset($_POST['tur_guncelle'])) {
        $typeKey = trim($_POST['type_key'] ?? '');
        $typeName = trim($_POST['tur_name'] ?? '');
        $typeIcon = trim($_POST['tur_icon'] ?? '📌');
        $typeBadge = trim($_POST['badge_color'] ?? 'blue');
        $typeSort = (int)($_POST['tur_sort_order'] ?? 0);

        if (!empty($typeKey) && !empty($typeName)) {
            $updTur = $pdo->prepare("UPDATE category_types SET name = ?, icon = ?, badge_color = ?, sort_order = ? WHERE type_key = ?");
            $updTur->execute([$typeName, $typeIcon, $typeBadge, $typeSort, $typeKey]);
            $mesaj = "Bağlı Tür başarıyla güncellendi: $typeName";
            $mesajTuru = 'success';
            $aktifTab = 'turler';
        }
    }
}

// Düzenleme Modları
if (isset($_GET['edit_cat'])) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_cat']]);
    $duzenleKategori = $stmt->fetch(PDO::FETCH_ASSOC);
    $aktifTab = 'kategoriler';
}
if (isset($_GET['edit_tur'])) {
    $stmt = $pdo->prepare("SELECT * FROM category_types WHERE type_key = ?");
    $stmt->execute([trim($_GET['edit_tur'])]);
    $duzenleTur = $stmt->fetch(PDO::FETCH_ASSOC);
    $aktifTab = 'turler';
}

// Veritabanından Tanımlı Türleri ve Kategorileri Çek (Yoksa otomatik oluştur)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `category_types` (
            `type_key` VARCHAR(50) NOT NULL PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `icon` VARCHAR(10) DEFAULT '📌',
            `badge_color` VARCHAR(50) DEFAULT 'blue',
            `sort_order` INT DEFAULT 0,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $turCheck = (int)$pdo->query("SELECT COUNT(*) FROM category_types")->fetchColumn();
    if ($turCheck === 0) {
        $defaultTypes = [
            ['digital', 'Dijital Abonelik', '💻', 'purple', 1],
            ['utility', 'Ev / Fiziksel Fatura', '⚡', 'yellow', 2],
            ['telecom', 'Ev / Telekom', '📡', 'cyan', 3],
            ['insurance', 'Sigorta & Poliçe', '🛡️', 'rose', 4],
            ['bes', 'Bireysel Emeklilik (BES & OKS)', '📈', 'emerald', 5],
            ['vehicle', 'Araç & Taşıt', '🚗', 'blue', 6],
            ['general', 'Genel Harcama', '📄', 'slate', 7],
            ['tax', 'Vergi', '💰', 'rose', 8]
        ];
        $stmtDef = $pdo->prepare("INSERT IGNORE INTO `category_types` (`type_key`, `name`, `icon`, `badge_color`, `sort_order`) VALUES (?, ?, ?, ?, ?)");
        foreach ($defaultTypes as $dt) {
            $stmtDef->execute($dt);
        }
    }
    try { $pdo->exec("INSERT INTO `category_types` (`type_key`, `name`, `icon`, `badge_color`, `sort_order`) VALUES ('tax', 'Vergi', '💰', 'rose', 8) ON DUPLICATE KEY UPDATE `name` = 'Vergi', `icon` = '💰', `badge_color` = 'rose'"); } catch (Exception $e) {}
    $turler = $pdo->query("SELECT * FROM category_types ORDER BY sort_order ASC, type_key ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $turler = [];
}
$categoryTypesMap = [];
foreach ($turler as $t) {
    $categoryTypesMap[$t['type_key']] = $t;
}

try {
    $sql = "
        SELECT c.*, COUNT(s.id) as sub_count
        FROM categories c
        LEFT JOIN subscriptions s ON c.id = s.category_id AND s.status = 'active'
        GROUP BY c.id
        ORDER BY c.sort_order ASC, c.name ASC
    ";
    $categories = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories = [];
}

// Son sıralama önceliklerini ve sıradaki numarayı hesapla
$maxCatSort = 0;
foreach ($categories as $c) {
    if ((int)$c['sort_order'] > $maxCatSort) {
        $maxCatSort = (int)$c['sort_order'];
    }
}
$nextCatSortOrder = $maxCatSort + 1;

$maxTurSort = 0;
foreach ($turler as $t) {
    if ((int)$t['sort_order'] > $maxTurSort) {
        $maxTurSort = (int)$t['sort_order'];
    }
}
$nextTurSortOrder = $maxTurSort + 1;

require_once __DIR__ . '/header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require_once __DIR__ . '/sidebar.php'; ?>
    
    <div class="flex-1 w-full max-w-7xl mx-auto">
        <!-- Üst Başlık & Sekmeler -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors flex items-center gap-2">
                    🏷️ Kategori &amp; Bağlı Tür Yönetimi
                </h2>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Abonelik kategorilerini ve bunların bağlı olduğu sistem türlerini düzenleyin.
                </p>
            </div>
            
            <!-- Tab Sekmeleri -->
            <div class="flex items-center gap-1.5 bg-slate-100 dark:bg-slate-800/80 p-1.5 rounded-2xl border border-slate-200 dark:border-slate-700/60 shadow-sm">
                <a href="kategoriler.php?tab=kategoriler" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition flex items-center gap-1.5 <?= ($aktifTab === 'kategoriler') ? 'bg-white dark:bg-slate-900 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' ?>">
                    <span>📂 Kategoriler</span>
                    <span class="text-[10px] px-1.5 py-0.2 rounded-md bg-slate-100 dark:bg-slate-800"><?= count($categories) ?></span>
                </a>
                <a href="kategoriler.php?tab=turler" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition flex items-center gap-1.5 <?= ($aktifTab === 'turler') ? 'bg-white dark:bg-slate-900 text-purple-600 dark:text-purple-400 shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white' ?>">
                    <span>⚙️ Bağlı Türler (Seçenekler)</span>
                    <span class="text-[10px] px-1.5 py-0.2 rounded-md bg-slate-100 dark:bg-slate-800"><?= count($turler) ?></span>
                </a>
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

        <?php if ($aktifTab === 'kategoriler'): ?>
            <!-- 1. SEKME: KATEGORİLER -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Sol: Kategori Ekleme / Duzenleme Formu -->
                <div class="lg:col-span-1">
                    <div class="bg-white dark:bg-slate-900 p-6 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-800 space-y-4 transition-colors">
                        <h3 class="font-bold text-base <?= $duzenleKategori ? 'text-amber-600 dark:text-amber-400' : 'text-slate-800 dark:text-white' ?> border-b border-slate-100 dark:border-slate-800 pb-2">
                            <?= $duzenleKategori ? '✏️ Kategoriyi Düzenle' : '➕ Yeni Kategori Ekle' ?>
                        </h3>
                        <form method="POST" class="space-y-4">
                            <?= csrfAlaniniEkle() ?>
                            <?php if($duzenleKategori): ?>
                                <input type="hidden" name="kategori_guncelle" value="1">
                                <input type="hidden" name="cat_id" value="<?= $duzenleKategori['id'] ?>">
                            <?php else: ?>
                                <input type="hidden" name="kategori_ekle" value="1">
                            <?php endif; ?>
                            
                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Kategori Adı *</label>
                                <input type="text" name="name" value="<?= $duzenleKategori ? htmlspecialchars($duzenleKategori['name']) : '' ?>" placeholder="Örn: Elektrik Faturası, Netflix" required 
                                    class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Slug (URL Kodu)</label>
                                <input type="text" name="slug" value="<?= $duzenleKategori ? htmlspecialchars($duzenleKategori['slug']) : '' ?>" placeholder="Örn: elektrik-faturasi" 
                                    class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none font-mono transition">
                            </div>
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300">Bağlı Tür *</label>
                                    <a href="kategoriler.php?tab=turler" class="text-[10px] text-blue-600 dark:text-blue-400 font-semibold hover:underline">Seçenekleri Düzenle ⚙️</a>
                                </div>
                                <select name="type" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none transition">
                                    <?php foreach($turler as $t): ?>
                                        <?php 
                                            $isCatSel = false;
                                            if ($duzenleKategori) {
                                                $cType = $duzenleKategori['type'] ?? '';
                                                if (($cType === 'general' || empty($cType)) && (strpos(mb_strtolower($duzenleKategori['name']), 'vergi') !== false || strpos(mb_strtolower($duzenleKategori['slug'] ?? ''), 'vergi') !== false || $duzenleKategori['name'] === 'GSS')) {
                                                    $cType = 'tax';
                                                }
                                                $isCatSel = ($cType === $t['type_key'] || ($t['type_key'] === 'tax' && $cType === 'vergi'));
                                            }
                                        ?>
                                        <option value="<?= htmlspecialchars($t['type_key']) ?>" <?= $isCatSel ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($t['icon'] . ' ' . $t['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Sıralama Önceliği</label>
                                <input type="number" name="sort_order" value="<?= $duzenleKategori ? (int)$duzenleKategori['sort_order'] : $nextCatSortOrder ?>" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none transition">
                            </div>
                            <div class="flex gap-2 pt-2">
                                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-xl font-bold text-xs transition shadow-md shadow-blue-500/20">
                                    <?= $duzenleKategori ? 'Güncelle' : 'Kaydet' ?>
                                </button>
                                <?php if($duzenleKategori): ?>
                                    <a href="kategoriler.php?tab=kategoriler" class="bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-200 text-center py-2.5 px-4 rounded-xl font-bold text-xs hover:bg-slate-300 dark:hover:bg-slate-700 transition">
                                        &#10005;
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Sag: Kategori Listesi Tablosu -->
                <div class="lg:col-span-2">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-800 overflow-hidden transition-colors">
                        <div class="px-5 py-4 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                            <h3 class="font-bold text-sm text-slate-800 dark:text-white">Tanımlı Kategoriler</h3>
                            <span class="text-xs bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-2.5 py-0.5 rounded-full font-bold">
                                <?= count($categories) ?> Kategori
                            </span>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-xs text-left">
                                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400 font-bold border-b border-slate-200 dark:border-slate-800">
                                    <tr>
                                        <th class="p-3.5">Kategori</th>
                                        <th class="p-3.5">Bağlı Tür</th>
                                        <th class="p-3.5">Abonelik Sayısı</th>
                                        <th class="p-3.5">Sıra</th>
                                        <th class="p-3.5 text-right">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <?php foreach($categories as $c): 
                                        $tKey = $c['type'];
                                        if (($tKey === 'general' || empty($tKey)) && strpos(mb_strtolower($c['name']), 'motorlu') === false && (strpos(mb_strtolower($c['name']), 'vergi') !== false || strpos(mb_strtolower($c['slug'] ?? ''), 'vergi') !== false || strpos(mb_strtolower($c['name']), 'maliye') !== false || $c['name'] === 'GSS')) {
                                            $tKey = 'tax';
                                        }
                                        $curTur = $categoryTypesMap[$tKey] ?? ($tKey === 'tax' || $tKey === 'vergi' ? ['name' => 'Vergi', 'icon' => '💰', 'badge_color' => 'rose'] : ['name' => $tKey ?: 'Genel', 'icon' => '📌', 'badge_color' => 'blue']);
                                        $colorMap = [
                                            'purple' => 'bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-800',
                                            'yellow' => 'bg-yellow-50 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300 border-yellow-200 dark:border-yellow-800',
                                            'cyan'   => 'bg-cyan-50 dark:bg-cyan-900/30 text-cyan-700 dark:text-cyan-300 border-cyan-200 dark:border-cyan-800',
                                            'rose'   => 'bg-rose-50 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 border-rose-200 dark:border-rose-800',
                                            'emerald'=> 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
                                            'blue'   => 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800',
                                            'slate'  => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700'
                                        ];
                                        $badgeClass = $colorMap[$curTur['badge_color']] ?? $colorMap['blue'];
                                    ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                        <td class="p-3.5">
                                            <div class="font-bold text-slate-800 dark:text-white text-sm">
                                                <?= htmlspecialchars($c['name']) ?>
                                            </div>
                                            <div class="text-[11px] text-slate-400 font-mono mt-0.5">
                                                <?= htmlspecialchars($c['slug']) ?>
                                            </div>
                                        </td>
                                        <td class="p-3.5">
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-bold border <?= $badgeClass ?>">
                                                <span><?= htmlspecialchars($curTur['icon']) ?></span>
                                                <span><?= htmlspecialchars($curTur['name']) ?></span>
                                            </span>
                                        </td>
                                        <td class="p-3.5">
                                            <span class="px-2 py-0.5 rounded-md text-xs font-bold <?= (int)($c['sub_count'] ?? 0) > 0 ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-500' ?>">
                                                <?= (int)($c['sub_count'] ?? 0) ?>
                                            </span>
                                        </td>
                                        <td class="p-3.5 font-mono text-slate-500 dark:text-slate-400">
                                            <?= (int)$c['sort_order'] ?>
                                        </td>
                                        <td class="p-3.5 text-right space-x-1">
                                            <a href="kategoriler.php?tab=kategoriler&edit_cat=<?= $c['id'] ?>" class="inline-flex items-center px-2.5 py-1 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-800/40 font-bold transition">
                                                ✏️ Düzenle
                                            </a>
                                            <form method="POST" onsubmit="return confirm('Bu kategoriyi silmek istediğinize emin misiniz?')" class="inline">
                                                <?= csrfAlaniniEkle() ?>
                                                <input type="hidden" name="sil_cat_id" value="<?= $c['id'] ?>">
                                                <button type="submit" class="inline-flex items-center px-2.5 py-1 rounded-lg bg-rose-50 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400 hover:bg-rose-100 dark:hover:bg-rose-800/40 font-bold transition">
                                                    🗑️ Sil
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- 2. SEKME: BAĞLI TÜRLER (SEÇENEKLER YÖNETİMİ) -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Sol: Tür Ekleme / Düzenleme Formu -->
                <div class="lg:col-span-1">
                    <div class="bg-white dark:bg-slate-900 p-6 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-800 space-y-4 transition-colors">
                        <h3 class="font-bold text-base <?= $duzenleTur ? 'text-amber-600 dark:text-amber-400' : 'text-purple-600 dark:text-purple-400' ?> border-b border-slate-100 dark:border-slate-800 pb-2">
                            <?= $duzenleTur ? '✏️ Tür Seçeneğini Düzenle' : '➕ Yeni Bağlı Tür Ekle' ?>
                        </h3>

                        <form method="POST" class="space-y-4">
                            <?= csrfAlaniniEkle() ?>
                            <?php if ($duzenleTur): ?>
                                <input type="hidden" name="tur_guncelle" value="1">
                                <input type="hidden" name="type_key" value="<?= htmlspecialchars($duzenleTur['type_key']) ?>">

                                <div>
                                    <label class="block text-xs font-bold text-slate-500 mb-1">Sistem Kodu (Değiştirilemez)</label>
                                    <input type="text" value="<?= htmlspecialchars($duzenleTur['type_key']) ?>" disabled
                                        class="w-full bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-500 text-xs font-mono">
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="tur_ekle" value="1">

                                <div>
                                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Sistem Kodu (Benzersiz Kod) *</label>
                                    <input type="text" name="new_type_key" placeholder="Örn: education, health, cloud" required
                                        class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs font-mono focus:ring-2 focus:ring-purple-500 outline-none transition">
                                    <span class="text-[10px] text-slate-400 mt-0.5 block">Yalnızca küçük harf ve alt çizgi (a-z, 0-9, _)</span>
                                </div>
                            <?php endif; ?>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Görünen Tür Başlığı *</label>
                                <input type="text" name="tur_name" value="<?= $duzenleTur ? htmlspecialchars($duzenleTur['name']) : '' ?>" placeholder="Örn: Eğitim & Kurslar" required
                                    class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-purple-500 outline-none transition">
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">İkon (Emoji)</label>
                                    <input type="text" name="tur_icon" value="<?= $duzenleTur ? htmlspecialchars($duzenleTur['icon'] ?? '📌') : '📌' ?>" maxlength="4"
                                        class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs text-center focus:ring-2 focus:ring-purple-500 outline-none transition">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Rozet Rengi</label>
                                    <select name="badge_color" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-purple-500 outline-none transition">
                                        <option value="purple" <?= (($duzenleTur['badge_color'] ?? '') === 'purple') ? 'selected' : '' ?>>Mor</option>
                                        <option value="yellow" <?= (($duzenleTur['badge_color'] ?? '') === 'yellow') ? 'selected' : '' ?>>Sarı</option>
                                        <option value="cyan" <?= (($duzenleTur['badge_color'] ?? '') === 'cyan') ? 'selected' : '' ?>>Turkuaz</option>
                                        <option value="rose" <?= (($duzenleTur['badge_color'] ?? '') === 'rose') ? 'selected' : '' ?>>Gül / Kırmızı</option>
                                        <option value="emerald" <?= (($duzenleTur['badge_color'] ?? '') === 'emerald') ? 'selected' : '' ?>>Yeşil</option>
                                        <option value="blue" <?= (($duzenleTur['badge_color'] ?? '') === 'blue') ? 'selected' : '' ?>>Mavi</option>
                                        <option value="slate" <?= (($duzenleTur['badge_color'] ?? '') === 'slate') ? 'selected' : '' ?>>Gri</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Sıralama</label>
                                <input type="number" name="tur_sort_order" value="<?= $duzenleTur ? (int)$duzenleTur['sort_order'] : $nextTurSortOrder ?>"
                                    class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-purple-500 outline-none transition">
                            </div>

                            <div class="flex gap-2 pt-2">
                                <button type="submit" class="flex-1 bg-purple-600 hover:bg-purple-700 text-white py-2.5 rounded-xl font-bold text-xs transition shadow-md shadow-purple-500/20">
                                    <?= $duzenleTur ? 'Tür Başlığını Güncelle' : 'Yeni Türü Kaydet' ?>
                                </button>
                                <?php if ($duzenleTur): ?>
                                    <a href="kategoriler.php?tab=turler" class="bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-200 text-center py-2.5 px-4 rounded-xl font-bold text-xs hover:bg-slate-300 dark:hover:bg-slate-700 transition">
                                        &#10005;
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                <!-- Sag: Tür Listesi Tablosu -->
                <div class="lg:col-span-2">
                    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-800 overflow-hidden transition-colors">
                        <div class="px-5 py-4 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                            <h3 class="font-bold text-sm text-slate-800 dark:text-white">Tanımlı Bağlı Türler</h3>
                            <span class="text-xs bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 px-2.5 py-0.5 rounded-full font-bold">
                                <?= count($turler) ?> Tür Seçeneği
                            </span>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-xs text-left">
                                <thead class="bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400 font-bold border-b border-slate-200 dark:border-slate-800">
                                    <tr>
                                        <th class="p-3.5">Tür Başlığı (Görünen İsim)</th>
                                        <th class="p-3.5">Sistem Kodu</th>
                                        <th class="p-3.5">İkon</th>
                                        <th class="p-3.5">Sıra</th>
                                        <th class="p-3.5 text-right">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <?php foreach($turler as $t): ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                        <td class="p-3.5 font-bold text-slate-800 dark:text-white text-sm">
                                            <span class="mr-1"><?= htmlspecialchars($t['icon']) ?></span>
                                            <?= htmlspecialchars($t['name']) ?>
                                        </td>
                                        <td class="p-3.5 font-mono text-slate-500 dark:text-slate-400">
                                            <?= htmlspecialchars($t['type_key']) ?>
                                        </td>
                                        <td class="p-3.5 text-base">
                                            <?= htmlspecialchars($t['icon']) ?>
                                        </td>
                                        <td class="p-3.5 font-mono text-slate-500 dark:text-slate-400">
                                            <?= (int)$t['sort_order'] ?>
                                        </td>
                                        <td class="p-3.5 text-right">
                                            <a href="kategoriler.php?tab=turler&edit_tur=<?= urlencode($t['type_key']) ?>" class="inline-flex items-center px-2.5 py-1 rounded-lg bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 hover:bg-purple-100 dark:hover:bg-purple-800/40 font-bold transition">
                                                ✏️ Düzenle
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
