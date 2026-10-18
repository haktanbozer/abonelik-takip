<?php
$pageTitle = "Aboneliklerim - Abonelik Takip";
require_once __DIR__ . '/header.php';

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'USER';
$isAdmin = ($userRole === 'ADMIN');

// Filtreler
$filterType = $_GET['type'] ?? 'all';
$filterStatus = $_GET['status'] ?? 'all';
$filterPayer = $_GET['payer'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$aktifKonumId = $_SESSION['aktif_konum_id'] ?? null;
$aktifKartId = $_SESSION['aktif_kart_id'] ?? null;
$aktifKonumAd = $_SESSION['aktif_konum_ad'] ?? 'Tüm Konumlar';

try {
    $allUsers = $pdo->query("SELECT id, username, full_name FROM users WHERE status = 'active' ORDER BY full_name ASC, username ASC")->fetchAll();
} catch (Exception $e) {
    $allUsers = [];
}

$sql = "
    SELECT s.*, c.name as category_name, c.icon as category_icon, c.color as category_color, c.type as category_type,
           pm.title as payment_method_title,
           cc.bank_name as cc_bank_name, cc.card_name as cc_card_name, cc.card_last_4 as cc_card_last_4,
           loc.name as location_name, loc.icon as location_icon,
           u_creator.full_name as creator_name, u_creator.username as creator_username,
           u_payer.full_name as payer_name, u_payer.username as payer_username,
           tel.commitment_end, tel.post_commitment_price, tel.speed_or_quota,
           tel.is_auto_pay as tel_is_auto_pay, tel.auto_pay_bank as tel_auto_pay_bank, tel.auto_pay_type as tel_auto_pay_type,
           util.billing_cut_day, util.due_day,
           util.is_auto_pay as util_is_auto_pay, util.auto_pay_bank as util_auto_pay_bank, util.auto_pay_type as util_auto_pay_type,
           dig.profile_count,
           ins.company_name as ins_company, ins.policy_no as ins_policy_no, ins.policy_end_date as ins_end_date, ins.insurance_type as ins_type,
           ins.payment_type as ins_payment_type, ins.installment_count as ins_installment_count, ins.installment_amount as ins_installment_amount,
           bes.company_name as bes_company, bes.contract_no as bes_contract_no, bes.payment_day as bes_payment_day
    FROM subscriptions s
    LEFT JOIN categories c ON s.category_id = c.id
    LEFT JOIN payment_methods pm ON s.payment_method_id = pm.id
    LEFT JOIN credit_cards cc ON s.credit_card_id = cc.id
    LEFT JOIN locations loc ON s.location_id = loc.id
    LEFT JOIN users u_creator ON s.user_id = u_creator.id
    LEFT JOIN users u_payer ON COALESCE(s.payer_user_id, s.user_id) = u_payer.id
    LEFT JOIN subscription_telecom_details tel ON s.id = tel.subscription_id
    LEFT JOIN subscription_utility_details util ON s.id = util.subscription_id
    LEFT JOIN subscription_digital_details dig ON s.id = dig.subscription_id
    LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
    LEFT JOIN subscription_bes_details bes ON s.id = bes.subscription_id
    WHERE 1=1
";
$params = [];

// Normal kullanici sadece kendisine ait olanlari gorur
if (!$isAdmin) {
    $sql .= " AND (s.user_id = ? OR s.payer_user_id = ? OR EXISTS (SELECT 1 FROM subscription_access sa WHERE sa.subscription_id = s.id AND sa.user_id = ?))";
    $params[] = $userId;
    $params[] = $userId;
    $params[] = $userId;
} else {
    // Sadece Yonetici (ADMIN) uyeye gore filtreleyebilir
    if ($filterPayer === 'me') {
        $sql .= " AND COALESCE(s.payer_user_id, s.user_id) = ?";
        $params[] = $userId;
    } elseif (is_numeric($filterPayer) && (int)$filterPayer > 0) {
        $sql .= " AND COALESCE(s.payer_user_id, s.user_id) = ?";
        $params[] = (int)$filterPayer;
    }
}

// Konum / Kart Filtresi
if (!empty($aktifKonumId)) {
    $sql .= " AND s.location_id = ?";
    $params[] = $aktifKonumId;
} elseif (!empty($aktifKartId)) {
    $sql .= " AND s.credit_card_id = ?";
    $params[] = $aktifKartId;
}

// Tur Filtresi
if ($filterType !== 'all') {
    $sql .= " AND c.type = ?";
    $params[] = $filterType;
}

// Durum Filtresi
if ($filterStatus !== 'all') {
    $sql .= " AND s.status = ?";
    $params[] = $filterStatus;
}

// Arama Filtresi
if (!empty($search)) {
    $sql .= " AND (s.title LIKE ? OR s.notes LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$sql .= " ORDER BY s.status ASC, s.next_payment_date ASC, s.created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    sistemLogla("Abonelik listeleme hatasi: " . $e->getMessage(), 'ERROR');
    $subscriptions = [];
}

// Son 1 yılda zamlanan aboneliklerin oranlarını hazırla
$priceHikes = [];
try {
    $oneYearAgo = date('Y-m-d H:i:s', strtotime('-1 year'));
    $histStmt = $pdo->query("
        SELECT subscription_id, old_price, new_price, changed_at
        FROM subscription_price_history
        WHERE changed_at >= '{$oneYearAgo}'
        ORDER BY changed_at ASC
    ");
    $histRows = $histStmt->fetchAll(PDO::FETCH_ASSOC);
    $subHikeMap = [];
    foreach ($histRows as $hr) {
        $sid = (int)$hr['subscription_id'];
        if (!isset($subHikeMap[$sid])) {
            $subHikeMap[$sid] = [
                'first_old' => (float)$hr['old_price'],
                'last_new' => (float)$hr['new_price'],
            ];
        } else {
            $subHikeMap[$sid]['last_new'] = (float)$hr['new_price'];
        }
    }
    foreach ($subHikeMap as $sid => $data) {
        if ($data['first_old'] > 0 && $data['last_new'] > $data['first_old']) {
            $pct = round((($data['last_new'] - $data['first_old']) / $data['first_old']) * 100, 1);
            if ($pct > 0) {
                $priceHikes[$sid] = $pct;
            }
        }
    }
} catch (Exception $e) {
    $priceHikes = [];
}
?>

<!-- Ust Baslik & Arama/Filtre Cubugu -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">Tüm Abonelik ve Faturalar</h1>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">Kayıtlı toplam <?= count($subscriptions) ?> harcama kalemi listeleniyor.</p>
    </div>
    <div class="flex items-center gap-2">
        <a href="excel-export.php<?= !empty($aktifKonumId) ? '?location_id=' . (int)$aktifKonumId : '' ?>" class="px-3.5 py-2 bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-semibold transition flex items-center gap-1.5 shadow-sm" title="<?= !empty($aktifKonumAd) ? htmlspecialchars($aktifKonumAd) . ' Listesini Dışa Aktar' : 'Excel Dışa Aktar' ?>">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            <span>Excel</span>
        </a>
        <a href="abonelik-ekle.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-lg shadow-blue-600/20">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
            <span>Yeni Ekle</span>
        </a>
    </div>
</div>

<!-- Filtre & Sekmeler -->
<div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-3 mb-6 shadow-sm flex flex-col md:flex-row items-center justify-between gap-3">
    
    <!-- Tur Sekmeleri (Mekana Gore Dinamik) -->
    <div class="flex items-center gap-1 overflow-x-auto w-full md:w-auto pb-1 md:pb-0 text-xs">
        <a href="abonelikler.php?type=all&status=<?= urlencode($filterStatus) ?>" 
            class="px-3 py-1.5 rounded-xl transition whitespace-nowrap <?= $filterType === 'all' ? 'bg-blue-600 text-white font-bold' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
            Tümü
        </a>
        
        <?php 
        $locStr = mb_strtolower($aktifKonumAd ?? '', 'UTF-8');
        $isEv = ($locStr === 'ev' || str_contains($locStr, 'ev') || str_contains($locStr, 'ofis') || str_contains($locStr, 'yazl') || str_contains($locStr, 'is'));
        $isDijital = str_contains($locStr, 'dijital');
        $isSigorta = str_contains($locStr, 'sigorta');
        $isBes = (str_contains($locStr, 'bes') || str_contains($locStr, 'emekli'));
        $isArac = (str_contains($locStr, 'ara') || str_contains($locStr, 'tasit'));
        $isAll = empty($aktifKonumId);
        ?>

        <?php if ($isAll || $isDijital): ?>
        <a href="abonelikler.php?type=digital&status=<?= urlencode($filterStatus) ?>" 
            class="px-3 py-1.5 rounded-xl transition whitespace-nowrap <?= $filterType === 'digital' ? 'bg-blue-600 text-white font-bold' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
            💻 Dijital
        </a>
        <?php endif; ?>

        <?php if ($isAll || $isEv): ?>
        <a href="abonelikler.php?type=utility&status=<?= urlencode($filterStatus) ?>" 
            class="px-3 py-1.5 rounded-xl transition whitespace-nowrap <?= $filterType === 'utility' ? 'bg-blue-600 text-white font-bold' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
            ⚡ Faturalar
        </a>
        <a href="abonelikler.php?type=telecom&status=<?= urlencode($filterStatus) ?>" 
            class="px-3 py-1.5 rounded-xl transition whitespace-nowrap <?= $filterType === 'telecom' ? 'bg-blue-600 text-white font-bold' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
            📡 Telekom / İnternet
        </a>
        <?php endif; ?>
        
        <?php if ($isAll || $isSigorta): ?>
        <a href="abonelikler.php?type=insurance&status=<?= urlencode($filterStatus) ?>" 
            class="px-3 py-1.5 rounded-xl transition whitespace-nowrap <?= $filterType === 'insurance' ? 'bg-rose-600 text-white font-bold' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
            🛡️ Sigorta &amp; Poliçeler
        </a>
        <?php endif; ?>
        
        <?php if ($isAll || $isBes): ?>
        <a href="abonelikler.php?type=bes&status=<?= urlencode($filterStatus) ?>" 
            class="px-3 py-1.5 rounded-xl transition whitespace-nowrap <?= $filterType === 'bes' ? 'bg-cyan-600 text-white font-bold' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
            📈 Bireysel Emeklilik (BES)
        </a>
        <?php endif; ?>
        
        <?php if ($isAll || $isArac): ?>
        <a href="abonelikler.php?type=vehicle&status=<?= urlencode($filterStatus) ?>" 
            class="px-3 py-1.5 rounded-xl transition whitespace-nowrap <?= $filterType === 'vehicle' ? 'bg-blue-600 text-white font-bold' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800' ?>">
            🚗 Araç
        </a>
        <?php endif; ?>
    </div>

    <!-- Arama & Odeyen Filtresi Formu -->
    <form method="GET" class="flex flex-wrap items-center gap-2 w-full md:w-auto">
        <input type="hidden" name="type" value="<?= htmlspecialchars($filterType) ?>">
        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
        
        <?php if ($isAdmin && !empty($allUsers)): ?>
        <select name="payer" onchange="this.form.submit()"
            class="px-2.5 py-1.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-xs text-slate-700 dark:text-slate-300 focus:ring-2 focus:ring-blue-500 outline-none">
            <option value="all" <?= $filterPayer === 'all' ? 'selected' : '' ?>>👥 Tüm Üyeler</option>
            <option value="me" <?= $filterPayer === 'me' ? 'selected' : '' ?>>👤 Sadece Ben</option>
            <?php foreach ($allUsers as $u): 
                if ($u['id'] == $userId) continue;
            ?>
                <option value="<?= $u['id'] ?>" <?= ($filterPayer == $u['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <div class="relative w-full md:w-48">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Abonelik ara..."
                class="w-full pl-8 pr-3 py-1.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-xs text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-blue-500 outline-none">
            <svg class="w-3.5 h-3.5 text-slate-400 dark:text-slate-500 absolute left-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        </div>
        <button type="submit" class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-800 dark:text-white border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-semibold transition">
            Ara
        </button>
    </form>

</div>

<?php if (empty($subscriptions)): ?>
    <div class="py-16 text-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-8 shadow-sm">
        <div class="w-16 h-16 bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 rounded-3xl flex items-center justify-center mx-auto mb-4 text-2xl">
            📝
        </div>
        <h3 class="text-base font-bold text-slate-900 dark:text-white mb-1">Abonelik Bulunamadı</h3>
        <p class="text-xs text-slate-500 dark:text-slate-400 mb-6 max-w-sm mx-auto">Seçtiğiniz kriterlerde herhangi bir abonelik bulunmuyor. Yeni bir abonelik ekleyerek başlayabilirsiniz.</p>
        <a href="abonelik-ekle.php" class="inline-flex items-center gap-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition shadow-lg shadow-blue-600/20">
            <span>+ İlk Aboneliğinizi Ekleyin</span>
        </a>
    </div>
<?php else: ?>

    <!-- Kart Grid Gorunumu -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($subscriptions as $sub): 
            $kalanGun = kalanGunHesapla($sub['next_payment_date']);
            $taahhutKalan = kalanGunHesapla($sub['commitment_end']);
            $canEdit = ($userRole === 'ADMIN' || $sub['user_id'] == $userId || $sub['payer_user_id'] == $userId);
        ?>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 rounded-3xl p-5 shadow-sm hover:shadow-md flex flex-col justify-between transition group">
                
                <div>
                    <!-- Ust Baslik & Durum Rozetleri -->
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-2xl bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold text-base shadow-sm">
                                <?= mb_substr($sub['title'], 0, 1) ?>
                            </div>
                            <div>
                                <a href="abonelik-detay.php?id=<?= $sub['id'] ?>" class="text-base font-bold text-slate-900 dark:text-white hover:text-blue-600 dark:hover:text-blue-400 transition leading-tight block">
                                    <?= htmlspecialchars($sub['title']) ?>
                                </a>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400 flex items-center gap-1.5 mt-0.5 flex-wrap">
                                    <span><?= htmlspecialchars($sub['category_name'] ?? 'Kategori') ?></span>
                                    <?php if (!empty($sub['plan_tier'])): ?>
                                        <span>&bull; <?= htmlspecialchars($sub['plan_tier']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($sub['location_name'])): ?>
                                        <span class="px-1.5 py-0.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-[10px] rounded font-semibold">📍 <?= htmlspecialchars($sub['location_name']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Durum Rozeti -->
                        <?php if ($sub['status'] === 'active'): ?>
                            <?php if ($sub['category_type'] === 'insurance'): ?>
                                <span class="px-2 py-0.5 bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-500/20 rounded-md text-[10px] font-bold">🛡️ Poliçe</span>
                            <?php elseif ($sub['category_type'] === 'bes'): ?>
                                <span class="px-2 py-0.5 bg-cyan-50 dark:bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border border-cyan-200 dark:border-cyan-500/20 rounded-md text-[10px] font-bold">📈 BES</span>
                            <?php elseif ($sub['is_trial']): ?>
                                <span class="px-2 py-0.5 bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-200 dark:border-purple-500/20 rounded-md text-[10px] font-bold">Deneme</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/20 rounded-md text-[10px] font-bold">Aktif</span>
                            <?php endif; ?>
                        <?php elseif ($sub['status'] === 'paused'): ?>
                            <span class="px-2 py-0.5 bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-200 dark:border-amber-500/20 rounded-md text-[10px] font-bold">Donduruldu</span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 rounded-md text-[10px] font-bold">İptal</span>
                        <?php endif; ?>
                    </div>

                    <!-- Fiyat & Dongu -->
                    <?php 
                        $isInstSub = ($sub['category_type'] === 'insurance' && ($sub['ins_payment_type'] ?? '') === 'installment' && !empty($sub['ins_installment_amount']));
                    ?>
                    <div class="my-4 p-3 bg-slate-50 dark:bg-slate-950/60 border border-slate-100 dark:border-slate-800/40 rounded-2xl flex items-center justify-between">
                        <div>
                            <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block">
                                <?= $isInstSub ? 'Aylık Taksit Tutarı' : ($sub['category_type'] === 'bes' ? 'Katkı Payı' : ($sub['category_type'] === 'insurance' ? 'Poliçe Tutarı' : 'Fiyat')) ?>
                            </span>
                            <span class="text-lg font-extrabold text-slate-900 dark:text-white tracking-tight">
                                <?= $isInstSub ? paraFormat($sub['ins_installment_amount'], $sub['currency']) : paraFormat($sub['price'], $sub['currency']) ?>
                            </span>
                            <?php if (!empty($sub['cashback_amount']) && (float)$sub['cashback_amount'] > 0): ?>
                                <span class="ml-1 text-[10px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-500/10 px-1.5 py-0.5 rounded border border-emerald-500/20" title="Nakit İade (Cashback)">
                                    🎁 -<?= paraFormat($sub['cashback_amount'], $sub['currency']) ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($priceHikes[$sub['id']])): ?>
                                <span class="ml-1 text-[10px] font-bold text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-500/10 px-1.5 py-0.5 rounded border border-rose-500/20" title="Son 1 yılda %<?= $priceHikes[$sub['id']] ?> zamlandı">
                                    📈 +%<?= $priceHikes[$sub['id']] ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($isInstSub): ?>
                                <span class="text-[10px] text-slate-500 dark:text-slate-400 block font-medium mt-0.5">Toplam: <?= paraFormat($sub['price'], $sub['currency']) ?> (<?= (int)($sub['ins_installment_count'] ?? 1) ?> Taksit)</span>
                            <?php elseif ($sub['billing_cycle'] === 'annual' && (float)$sub['price'] > 0): ?>
                                <span class="text-[10px] text-blue-600 dark:text-blue-400 block font-medium mt-0.5" title="Yıllık peşin ödemenin aylık bütçe karşılığı">
                                    💡 Aylık: ~<?= paraFormat(round((float)$sub['price'] / 12, 2), $sub['currency']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="text-right">
                            <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block">Periyot</span>
                            <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                <?= $isInstSub ? 'Aylık Taksit' : ($sub['billing_cycle'] === 'monthly' ? 'Aylık' : ($sub['billing_cycle'] === 'annual' ? 'Yıllık (Peşin)' : ucfirst($sub['billing_cycle']))) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Detay Rozetleri (Taahhut, Kesim Gunu vb.) -->
                    <div class="space-y-1.5 text-xs text-slate-500 dark:text-slate-400 mb-4">
                        
                        <?php if (!empty($sub['next_payment_date'])): ?>
                            <div class="flex items-center justify-between">
                                <span><?= $sub['category_type'] === 'bes' ? 'Sonraki Çekim:' : 'Sonraki Ödeme:' ?></span>
                                <div class="flex items-center gap-1.5">
                                    <span class="font-semibold text-slate-800 dark:text-slate-200">
                                        <?= tarihFormat($sub['next_payment_date']) ?>
                                        <?php if ($kalanGun !== null && $kalanGun >= 0 && $kalanGun <= 7): ?>
                                            <span class="text-[10px] text-amber-500 dark:text-amber-400 font-bold">(<?= $kalanGun ?> gün)</span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($canEdit): ?>
                                        <button type="button" onclick="odendiIsaretle(<?= $sub['id'] ?>, '<?= htmlspecialchars(addslashes($sub['title'])) ?>')" class="px-1.5 py-0.5 bg-emerald-50 dark:bg-emerald-500/10 hover:bg-emerald-100 dark:hover:bg-emerald-500/20 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/30 rounded text-[10px] font-bold transition flex items-center gap-0.5" title="Ödendi olarak işaretle ve bir sonraki döneme ötele">
                                            ✓ Ödendi
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($sub['category_type'] === 'insurance'): 
                            $policeKalan = !empty($sub['ins_end_date']) ? kalanGunHesapla($sub['ins_end_date']) : null;
                        ?>
                            <?php if (!empty($sub['ins_company'])): ?>
                                <div class="flex items-center justify-between">
                                    <span>Sigorta Şirketi:</span>
                                    <span class="font-semibold text-slate-900 dark:text-white"><?= htmlspecialchars($sub['ins_company']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($sub['ins_policy_no'])): ?>
                                <div class="flex items-center justify-between">
                                    <span>Poliçe No:</span>
                                    <span class="font-mono text-slate-700 dark:text-slate-300"><?= htmlspecialchars($sub['ins_policy_no']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($sub['ins_end_date'])): ?>
                                <div class="flex items-center justify-between">
                                    <span>Yenileme:</span>
                                    <span class="font-semibold <?= ($policeKalan !== null && $policeKalan <= 30) ? 'text-rose-500 dark:text-rose-400' : 'text-slate-800 dark:text-slate-200' ?>">
                                        <?= tarihFormat($sub['ins_end_date']) ?>
                                        <?php if ($policeKalan !== null): ?>
                                            <span class="text-[10px] font-bold">(<?= ($policeKalan <= 0) ? 'Doldu!' : "{$policeKalan} gün" ?>)</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($sub['category_type'] === 'bes'): ?>
                            <?php if (!empty($sub['bes_company'])): ?>
                                <div class="flex items-center justify-between">
                                    <span>Emeklilik Şirketi:</span>
                                    <span class="font-semibold text-slate-900 dark:text-white"><?= htmlspecialchars($sub['bes_company']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($sub['bes_contract_no'])): ?>
                                <div class="flex items-center justify-between">
                                    <span>Sözleşme No:</span>
                                    <span class="font-mono text-slate-700 dark:text-slate-300"><?= htmlspecialchars($sub['bes_contract_no']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($sub['bes_payment_day'])): ?>
                                <div class="flex items-center justify-between">
                                    <span>Düzenli Çekim:</span>
                                    <span class="font-semibold text-cyan-600 dark:text-cyan-300">Her Ayın <?= $sub['bes_payment_day'] ?>'i</span>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (!empty($sub['commitment_end'])): ?>
                            <div class="flex items-center justify-between">
                                <span>Taahhüt Bitişi:</span>
                                <span class="font-semibold <?= ($taahhutKalan !== null && $taahhutKalan <= 30) ? 'text-rose-500 dark:text-rose-400' : 'text-slate-800 dark:text-slate-200' ?>">
                                    <?= tarihFormat($sub['commitment_end']) ?>
                                    <?php if ($taahhutKalan !== null && $taahhutKalan <= 30): ?>
                                        <span class="text-[10px] font-bold">(<?= $taahhutKalan ?> gün!)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($sub['due_day'])): ?>
                            <div class="flex items-center justify-between">
                                <span>Son Ödeme Günü:</span>
                                <span class="font-semibold text-slate-800 dark:text-slate-200">Her Ayın <?= $sub['due_day'] ?>'si</span>
                            </div>
                        <?php endif; ?>

                        <!-- Otomatik Odeme & Kart Durumu -->
                        <?php 
                        $isAuto = (!empty($sub['is_auto_pay']) || !empty($sub['util_is_auto_pay']) || !empty($sub['tel_is_auto_pay']) || !empty($sub['credit_card_id']) || !empty($sub['payment_method_id']));
                        ?>
                        <div class="flex items-center justify-between">
                            <span>Ödeme Türü:</span>
                            <?php if ($isAuto): ?>
                                <span class="text-[10px] font-bold text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                                    <span>✓ Otomatik Ödeme</span>
                                    <?php if (!empty($sub['cc_bank_name'])): ?>
                                        <span class="text-slate-500 dark:text-slate-400 font-normal">(<?= htmlspecialchars($sub['cc_bank_name']) ?>)</span>
                                    <?php elseif (!empty($sub['payment_method_title'])): ?>
                                        <span class="text-slate-500 dark:text-slate-400 font-normal">(<?= htmlspecialchars($sub['payment_method_title']) ?>)</span>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span class="text-[10px] text-slate-400 dark:text-slate-500 font-medium">Manuel Ödeme</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($sub['credit_card_id']) && !empty($sub['cc_bank_name'])): ?>
                            <div class="flex items-center justify-between">
                                <span>Kredi Kartı:</span>
                                <span class="text-emerald-600 dark:text-emerald-400 font-medium truncate max-w-[170px]" title="<?= htmlspecialchars($sub['cc_bank_name']) ?> (&bull;&bull;&bull;&bull; <?= $sub['cc_card_last_4'] ?: '0000' ?>)">
                                    💳 <?= htmlspecialchars($sub['cc_bank_name']) ?> (&bull;&bull;&bull;&bull; <?= $sub['cc_card_last_4'] ?: '0000' ?>)
                                </span>
                            </div>
                        <?php elseif (!empty($sub['payment_method_title'])): ?>
                            <div class="flex items-center justify-between">
                                <span>Ödeme Aracı:</span>
                                <span class="text-slate-700 dark:text-slate-300"><?= htmlspecialchars($sub['payment_method_title']) ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="flex items-center justify-between pt-1 border-t border-slate-100 dark:border-slate-800/40">
                            <span>Ödeyen:</span>
                            <span class="font-bold text-blue-600 dark:text-blue-400">👤 <?= htmlspecialchars($sub['payer_name'] ?: ($sub['payer_username'] ?? 'Genel')) ?><?= ($sub['payer_user_id'] == $userId) ? ' (Ben)' : '' ?></span>
                        </div>

                        <?php if ($sub['category_type'] === 'digital'): 
                            $canSeePass = kullaniciSifreGorebilirMi($sub['id'], $userId, $_SESSION['role'] ?? 'USER');
                        ?>
                            <div class="flex items-center justify-between">
                                <span>Giriş Şifresi:</span>
                                <span class="text-[10px] font-bold <?= $canSeePass ? 'text-purple-600 dark:text-purple-400' : 'text-slate-400 dark:text-slate-500' ?>">
                                    <?= $canSeePass ? '🔑 Şifre İzni Var' : '🔒 Korumalı' ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($sub['attachment_file'])): ?>
                            <div class="flex items-center justify-between">
                                <span>Poliçe / Sözleşme:</span>
                                <a href="belge-indir.php?id=<?= $sub['id'] ?>&view=1" target="_blank" class="text-[10px] font-bold text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1" title="Belgeyi Yeni Sekmede Görüntüle">
                                    <span>📄 Belge Ekli</span>
                                </a>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- Aksiyon Butonlari -->
                <?php 
                    $userRole = $_SESSION['role'] ?? 'USER';
                    $canEdit = ($userRole === 'ADMIN' || $sub['user_id'] == $userId || $sub['payer_user_id'] == $userId);
                ?>
                <div class="pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between gap-2">
                    <a href="abonelik-detay.php?id=<?= $sub['id'] ?>" class="flex-1 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 rounded-xl text-xs font-bold text-center transition">
                        Detaylar
                    </a>
                    <?php if ($canEdit): ?>
                    <a href="abonelik-duzenle.php?id=<?= $sub['id'] ?>" class="p-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-xl transition" title="Düzenle">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                    </a>
                    <button onclick="silOnay(<?= $sub['id'] ?>, '<?= htmlspecialchars(addslashes($sub['title'])) ?>')" class="p-2 bg-rose-50 dark:bg-rose-500/10 hover:bg-rose-100 dark:hover:bg-rose-500/20 text-rose-600 dark:text-rose-400 rounded-xl transition" title="Sil">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                    </button>
                    <?php endif; ?>
                </div>

            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<script>
    function silOnay(id, baslik) {
        Swal.fire({
            title: 'Aboneliği Sil?',
            text: `"${baslik}" kaydını silmek istediğinize emin misiniz? Bu işlem geri alınamaz.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#334155',
            confirmButtonText: 'Evet, Sil',
            cancelButtonText: 'Vazgeç',
            background: document.documentElement.classList.contains('dark') ? '#0f172a' : '#ffffff',
            color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#0f172a'
        }).then((result) => {
            if (result.isConfirmed) {
                // AJAX ile sil
                fetch('ajax.php?action=sil_abonelik', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `id=${id}&csrf_token=<?= $_SESSION['csrf_token'] ?>`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Silindi',
                            text: 'Abonelik başarıyla silindi.',
                            showConfirmButton: false,
                            timer: 1500
                        }).then(() => window.location.reload());
                    } else {
                        Swal.fire('Hata', data.message || 'Silme işlemi başarısız', 'error');
                    }
                })
                .catch(() => Swal.fire('Hata', 'İstek gerçekleştirilemedi', 'error'));
            }
        });
    }

    function odendiIsaretle(id, baslik) {
        Swal.fire({
            title: 'Ödendi Olarak İşaretle',
            text: `"${baslik}" için ödeme yapıldı olarak işaretlensin ve vade tarihi bir sonraki döneme ötelensin mi?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Evet, Ötelensin',
            cancelButtonText: 'Vazgeç',
            background: document.documentElement.classList.contains('dark') ? '#0f172a' : '#ffffff',
            color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#0f172a'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('ajax.php?action=odendi_isaretle', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `subscription_id=${id}&csrf_token=<?= $_SESSION['csrf_token'] ?>`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Başarılı!',
                            text: data.message || 'Ödeme tarihi güncellendi.',
                            showConfirmButton: false,
                            timer: 1500,
                            background: document.documentElement.classList.contains('dark') ? '#0f172a' : '#ffffff',
                            color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#0f172a'
                        }).then(() => window.location.reload());
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Hata',
                            text: data.message || 'İşlem gerçekleştirilemedi.',
                            background: document.documentElement.classList.contains('dark') ? '#0f172a' : '#ffffff',
                            color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#0f172a'
                        });
                    }
                })
                .catch(() => Swal.fire('Hata', 'Sunucu ile bağlantı kurulamadı.', 'error'));
            }
        });
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
