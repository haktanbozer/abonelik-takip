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

$mesaj = '';
$mesajTuru = 'info';
$duzenleKategori = null;

$categoryTypes = [
    'utility'   => '⚡ Ev / Fiziksel Fatura (Elektrik, Su, Doğalgaz, Aidat)',
    'telecom'   => '📶 Ev / Telekom (Ev İnterneti, TV, Telefon, Mobil)',
    'digital'   => '💻 Dijital Abonelik (Netflix, Spotify, Bulut, AI)',
    'insurance' => '🛡️ Sigorta & Poliçe (TSS, ÖSS, Hayat, Kasko)',
    'bes'       => '📈 Bireysel Emeklilik (BES & OKS)',
    'vehicle'   => '🚗 Araç & Taşıt (Muayene, MTV, Bakım, HGS)'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');

    // İzin verilen kategori türleri ve ikonlar (Beyaz Liste Doğrulaması)
    $allowedTypes = ['digital', 'utility', 'telecom', 'vehicle', 'general', 'insurance', 'bes'];
    $allowedIcons = [
        'credit-card', 'zap', 'droplet', 'flame', 'home', 'wifi', 'smartphone', 'film', 
        'music', 'cloud', 'cpu', 'shopping-bag', 'gamepad-2', 'activity', 'shield', 
        'trending-up', 'car', 'sun', 'building', 'globe', 'tv', 'phone', 'lock', 'server', 'database'
    ];

    // Kategori Ekle
    if (isset($_POST['kategori_ekle'])) {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $rawType = $_POST['type'] ?? 'digital';
        $type = in_array($rawType, $allowedTypes, true) ? $rawType : 'digital';
        $rawIcon = trim($_POST['icon'] ?? 'credit-card');
        $icon = in_array($rawIcon, $allowedIcons, true) ? $rawIcon : 'credit-card';
        $color = trim($_POST['color'] ?? 'blue');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        
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
                $stmt = $pdo->prepare("INSERT INTO categories (name, slug, type, icon, color, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$name, $slug, $type, $icon, $color, $sort_order])) {
                    $mesaj = "Kategori başarıyla eklendi.";
                    $mesajTuru = 'success';
                }
            }
        }
    }
    // Kategori Guncelle
    elseif (isset($_POST['kategori_guncelle'])) {
        $id = (int)$_POST['cat_id'];
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $rawType = $_POST['type'] ?? 'digital';
        $type = in_array($rawType, $allowedTypes, true) ? $rawType : 'digital';
        $rawIcon = trim($_POST['icon'] ?? 'credit-card');
        $icon = in_array($rawIcon, $allowedIcons, true) ? $rawIcon : 'credit-card';
        $color = trim($_POST['color'] ?? 'blue');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        
        $check = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE slug = ? AND id != ?");
        $check->execute([$slug, $id]);
        if ($check->fetchColumn() > 0) {
            $mesaj = "Bu slug (URL) başka bir kategoride kullanımda.";
            $mesajTuru = 'error';
        } else {
            $stmt = $pdo->prepare("UPDATE categories SET name = ?, slug = ?, type = ?, icon = ?, color = ?, sort_order = ? WHERE id = ?");
            $stmt->execute([$name, $slug, $type, $icon, $color, $sort_order, $id]);
            $mesaj = "Kategori başarıyla güncellendi.";
            $mesajTuru = 'success';
        }
    }
    // Kategori Sil
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
}

// Duzenleme Modu
if (isset($_GET['edit_cat'])) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_cat']]);
    $duzenleKategori = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Kategorileri ve Abonelik Sayilarini Cek
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

require_once __DIR__ . '/header.php';
?>

<div class="flex flex-col md:flex-row gap-6 items-start">
    <?php require_once __DIR__ . '/sidebar.php'; ?>
    
    <div class="flex-1 w-full max-w-7xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 dark:text-white transition-colors flex items-center gap-2">
                    🏷️ Kategori Yönetimi
                </h2>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Aboneliklerin bağlı olduğu kategorileri ve ait oldukları mekan tiplerini yönetin.
                </p>
            </div>
            <?php if($duzenleKategori): ?>
                <a href="kategoriler.php" class="bg-slate-500 hover:bg-slate-600 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition">
                    &larr; Yeni Kategori Ekleme Moduna Dön
                </a>
            <?php endif; ?>
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
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Bağlı Mekan / Tür *</label>
                            <select name="type" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none transition">
                                <?php foreach($categoryTypes as $k => $v): ?>
                                    <option value="<?= $k ?>" <?= ($duzenleKategori && $duzenleKategori['type'] === $k) ? 'selected' : '' ?>><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Sıralama Önceliği</label>
                            <input type="number" name="sort_order" value="<?= $duzenleKategori ? (int)$duzenleKategori['sort_order'] : 0 ?>" class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-slate-800 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none transition">
                        </div>
                        <div class="flex gap-2 pt-2">
                            <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-xl font-bold text-xs transition shadow-md shadow-blue-500/20">
                                <?= $duzenleKategori ? 'Güncelle' : 'Kaydet' ?>
                            </button>
                            <?php if($duzenleKategori): ?>
                                <a href="kategoriler.php" class="bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-200 text-center py-2.5 px-4 rounded-xl font-bold text-xs hover:bg-slate-300 dark:hover:bg-slate-700 transition">
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
                                    <th class="p-3.5">Bağlı Mekan Türü</th>
                                    <th class="p-3.5">Abonelik Sayısı</th>
                                    <th class="p-3.5">Sıra</th>
                                    <th class="p-3.5 text-right">İşlem</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <?php foreach($categories as $c): ?>
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
                                        <?php 
                                        $typeBadge = [
                                            'utility'   => 'bg-yellow-50 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300 border-yellow-200 dark:border-yellow-800',
                                            'telecom'   => 'bg-cyan-50 dark:bg-cyan-900/30 text-cyan-700 dark:text-cyan-300 border-cyan-200 dark:border-cyan-800',
                                            'digital'   => 'bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-800',
                                            'insurance' => 'bg-rose-50 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 border-rose-200 dark:border-rose-800',
                                            'bes'       => 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
                                            'vehicle'   => 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800'
                                        ];
                                        $badgeStyle = $typeBadge[$c['type']] ?? 'bg-slate-100 text-slate-700';
                                        ?>
                                        <span class="inline-block px-2.5 py-0.5 rounded-md text-[10px] font-bold border <?= $badgeStyle ?>">
                                            <?= $c['type'] ?>
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
                                        <a href="kategoriler.php?edit_cat=<?= $c['id'] ?>" class="inline-flex items-center px-2.5 py-1 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-800/40 font-bold transition">
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

    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
