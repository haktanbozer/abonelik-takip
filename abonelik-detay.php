<?php
$pageTitle = "Abonelik Detayı - Abonelik Takip";
require_once __DIR__ . '/header.php';

$userId = $_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    header("Location: abonelikler.php");
    exit;
}

// Abonelik ve İlişkili Detayları Çek
try {
    $userRole = $_SESSION['role'] ?? 'USER';

    $stmt = $pdo->prepare("
        SELECT s.*, c.name as category_name, c.icon as category_icon, c.color as category_color, c.type as category_type,
               pm.title as payment_method_title, pm.card_type, pm.card_last_4,
               cc.bank_name as cc_bank_name, cc.card_name as cc_card_name, cc.card_network as cc_card_network, cc.card_last_4 as cc_card_last_4, cc.statement_cut_day as cc_statement_cut_day, cc.due_day as cc_due_day,
               loc.name as location_name,
               u_owner.full_name as creator_name, u_owner.username as creator_username,
               u_payer.full_name as payer_name, u_payer.username as payer_username,
               dig.account_username, dig.account_password_encrypted, dig.encryption_iv, dig.website_url, dig.profile_count, dig.shared_with_count,
               util.company_name, util.subscriber_no, util.meter_no, util.installation_no, util.billing_cut_day, util.due_day,
               util.is_auto_pay as util_is_auto_pay, util.auto_pay_type as util_auto_pay_type, util.auto_pay_bank as util_auto_pay_bank, util.auto_pay_source_title as util_auto_pay_source_title,
               tel.operator_name, tel.service_type, tel.phone_or_line_no, tel.speed_or_quota, tel.commitment_start, tel.commitment_end, tel.post_commitment_price, tel.device_rental_fee,
               tel.is_auto_pay as tel_is_auto_pay, tel.auto_pay_type as tel_auto_pay_type, tel.auto_pay_bank as tel_auto_pay_bank, tel.auto_pay_source_title as tel_auto_pay_source_title,
               ins.insurance_type as ins_type, ins.company_name as ins_company, ins.policy_no as ins_policy_no, ins.insured_item as ins_item, ins.agency_name as ins_agency_name, ins.agency_phone as ins_agency_phone, ins.policy_start_date as ins_start_date, ins.policy_end_date as ins_end_date, ins.payment_type as ins_payment_type, ins.installment_count as ins_installment_count, ins.installment_amount as ins_installment_amount,
               bes.company_name as bes_company, bes.contract_no as bes_contract_no, bes.payment_day as bes_payment_day, bes.owner_relation as bes_owner_relation, bes.auto_pay_source as bes_auto_pay_source
        FROM subscriptions s
        LEFT JOIN categories c ON s.category_id = c.id
        LEFT JOIN payment_methods pm ON s.payment_method_id = pm.id
        LEFT JOIN credit_cards cc ON s.credit_card_id = cc.id
        LEFT JOIN locations loc ON s.location_id = loc.id
        LEFT JOIN users u_owner ON s.user_id = u_owner.id
        LEFT JOIN users u_payer ON COALESCE(s.payer_user_id, s.user_id) = u_payer.id
        LEFT JOIN subscription_digital_details dig ON s.id = dig.subscription_id
        LEFT JOIN subscription_utility_details util ON s.id = util.subscription_id
        LEFT JOIN subscription_telecom_details tel ON s.id = tel.subscription_id
        LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
        LEFT JOIN subscription_bes_details bes ON s.id = bes.subscription_id
        WHERE s.id = ?
    ");
    $stmt->execute([$id]);
    $sub = $stmt->fetch();

    if (!$sub) {
        header("Location: abonelikler.php");
        exit;
    }

    // IDOR Koruması: Kullanıcı bu aboneliği görmeye yetkili mi?
    if ($userRole !== 'ADMIN') {
        $yetkili = ($sub['user_id'] == $userId || $sub['payer_user_id'] == $userId);
        if (!$yetkili) {
            $stmtAuth = $pdo->prepare("SELECT 1 FROM subscription_access WHERE subscription_id = ? AND user_id = ?");
            $stmtAuth->execute([$id, $userId]);
            $yetkili = (bool)$stmtAuth->fetchColumn();
        }
        if (!$yetkili) {
            $userEmail = $_SESSION['user_email'] ?? '';
            $stmtAuthShare = $pdo->prepare("SELECT 1 FROM subscription_shares WHERE subscription_id = ? AND (user_id = ? OR (person_email IS NOT NULL AND person_email != '' AND person_email = ?))");
            $stmtAuthShare->execute([$id, $userId, $userEmail]);
            $yetkili = (bool)$stmtAuthShare->fetchColumn();
        }
        if (!$yetkili) {
            sistemLogla("Yetkisiz Abonelik Detay Erişimi Engellendi (IDOR Girişimi): Sub ID {$id}, User ID {$userId}", 'SECURITY');
            header("Location: abonelikler.php?error=unauthorized");
            exit;
        }
    }

    // Ortak Masraf Paylaşımı Kayıtlarını Çek
    $stmtShares = $pdo->prepare("SELECT * FROM subscription_shares WHERE subscription_id = ? ORDER BY id ASC");
    $stmtShares->execute([$id]);
    $shares = $stmtShares->fetchAll();

    // Bilgileri ve detayları görüntüleme yetkisi olan üyeleri çek
    $stmtAcc = $pdo->prepare("
        SELECT u.id, u.username, u.full_name, u.role
        FROM subscription_access sa
        JOIN users u ON sa.user_id = u.id
        WHERE sa.subscription_id = ?
        ORDER BY u.full_name ASC
    ");
    $stmtAcc->execute([$id]);
    $sharedUsers = $stmtAcc->fetchAll();

} catch (Exception $e) {
    sistemLogla("Abonelik detay çekme hatası: " . $e->getMessage(), 'ERROR');
    header("Location: abonelikler.php");
    exit;
}

$canViewPassword = kullaniciSifreGorebilirMi($id, $userId, $userRole);
$canEdit = ($userRole === 'ADMIN' || $sub['user_id'] == $userId || $sub['payer_user_id'] == $userId);

$kalanGun = kalanGunHesapla($sub['next_payment_date']);
$taahhutKalan = kalanGunHesapla($sub['commitment_end']);
$policeKalan = !empty($sub['ins_end_date']) ? kalanGunHesapla($sub['ins_end_date']) : null;

// Taahhüt İlerleme Yüzdesi
$taahhutYuzde = 0;
if (!empty($sub['commitment_start']) && !empty($sub['commitment_end'])) {
    $startTs = strtotime($sub['commitment_start']);
    $endTs   = strtotime($sub['commitment_end']);
    $nowTs   = time();
    if ($endTs > $startTs) {
        $taahhutYuzde = min(100, max(0, round((($nowTs - $startTs) / ($endTs - $startTs)) * 100)));
    }
}

// Ortak Masraf Paylaşımı ve Cashback Net Maliyet Hesaplaması
$toplamTahsilEdilenOrtak = 0;
$toplamBekleyenOrtak = 0;
if (!empty($shares)) {
    foreach ($shares as $sh) {
        if (!empty($sh['is_paid'])) {
            $toplamTahsilEdilenOrtak += (float)$sh['share_amount'];
        } else {
            $toplamBekleyenOrtak += (float)$sh['share_amount'];
        }
    }
}
$isTaksitliSigorta = ($sub['category_type'] === 'insurance' && ($sub['ins_payment_type'] ?? '') === 'installment' && !empty($sub['ins_installment_amount']));
$bazTutar = $isTaksitliSigorta ? (float)$sub['ins_installment_amount'] : (float)$sub['price'];

$cashback = (float)($sub['cashback_amount'] ?? 0);
$netMaliyet = max(0, $bazTutar - $cashback - $toplamTahsilEdilenOrtak);
$toplamTasarruf = $cashback + $toplamTahsilEdilenOrtak;

// Fiyat Artışı & Zam Geçmişi Verileri
$fiyatGecmisi = abonelikFiyatGecmisi($id);
$son1YilZam = abonelikSon1YilZamOrani($id, (float)$sub['price']);
?>

<div class="max-w-4xl mx-auto space-y-6">

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
    <div class="p-4 bg-emerald-500/10 border border-emerald-500/30 rounded-2xl flex items-center gap-3 text-xs font-semibold text-emerald-600 dark:text-emerald-400 shadow-sm">
        <span class="text-base">✓</span>
        <span>Abonelik bilgileri başarıyla güncellendi.</span>
    </div>
    <?php endif; ?>

    <!-- Üst Başlık & Hızlı Butonlar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-200 dark:border-slate-800">
        <div class="flex items-center gap-3.5">
            <div class="w-12 h-12 bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 rounded-2xl flex items-center justify-center font-bold text-xl">
                <?= mb_substr($sub['title'], 0, 1) ?>
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight"><?= htmlspecialchars($sub['title']) ?></h1>
                    <?php if ($sub['category_type'] === 'insurance'): ?>
                        <span class="px-2.5 py-0.5 bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-500/20 rounded-md text-[10px] font-bold">🛡️ Sigorta Poliçesi</span>
                    <?php elseif ($sub['category_type'] === 'bes'): ?>
                        <span class="px-2.5 py-0.5 bg-cyan-50 dark:bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border border-cyan-200 dark:border-cyan-500/20 rounded-md text-[10px] font-bold">📈 Bireysel Emeklilik</span>
                    <?php elseif ($sub['is_trial']): ?>
                        <?php if (!empty($sub['trial_end_date']) && strtotime($sub['trial_end_date']) < strtotime('today')): ?>
                            <span class="px-2.5 py-0.5 bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-500/30 rounded-md text-[10px] font-bold">⚠️ Deneme Süresi Doldu (Normal Ücretlendirmeye Geçildi)</span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 bg-purple-50 dark:bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-200 dark:border-purple-500/20 rounded-md text-[10px] font-bold">Deneme Sürümü</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    <?= htmlspecialchars($sub['category_name']) ?>
                    <?php if (!empty($sub['plan_tier'])): ?> • <?= htmlspecialchars($sub['plan_tier']) ?><?php endif; ?>
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <?php if ($canEdit): ?>
            <a href="abonelik-duzenle.php?id=<?= $sub['id'] ?>" class="px-3.5 py-2 bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                <span>Düzenle</span>
            </a>
            <?php endif; ?>
            <a href="abonelikler.php" class="px-3.5 py-2 bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-700 text-xs font-semibold rounded-xl transition shadow-sm">
                ← Listeye Dön
            </a>
        </div>
    </div>
    <?php if ($sub['is_trial'] && !empty($sub['trial_end_date']) && strtotime($sub['trial_end_date']) < strtotime('today')): ?>
    <div class="p-4 bg-purple-500/10 border border-purple-500/30 rounded-2xl flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs">
        <div class="flex items-center gap-3">
            <span class="text-xl">⚠️</span>
            <div>
                <strong class="text-purple-700 dark:text-purple-300 block font-bold text-sm">Deneme Süresi Doldu (Normal Ücretlendirmeye Geçildi)</strong>
                <span class="text-slate-600 dark:text-slate-400">Bu aboneliğin ücretsiz deneme süresi <?= tarihFormat($sub['trial_end_date']) ?> tarihinde sona erdi. Artık <?= paraFormat($sub['price'], $sub['currency']) ?> üzerinden normal ücretlendirme yapılmaktadır.</span>
            </div>
        </div>
        <?php if ($canEdit): ?>
        <button type="button" onclick="normalAboneligeCevir(<?= $sub['id'] ?>, '<?= htmlspecialchars(addslashes($sub['title'])) ?>')" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold transition shadow-sm whitespace-nowrap ml-auto sm:ml-0">
            ✓ Normal Aboneliğe Çevir
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($sub['status'] === 'paused'): 
        $mult = match($sub['billing_cycle']) {
            'annual' => 1,
            'quarterly' => 4,
            'semi_annual' => 2,
            'one_time' => 0,
            default => 12
        };
        $annualSave = (float)$sub['price'] * $mult;
    ?>
    <div class="p-4 bg-amber-500/10 border border-amber-500/30 rounded-2xl flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs">
        <div class="flex items-center gap-3">
            <span class="text-xl">⏸️</span>
            <div>
                <strong class="text-amber-700 dark:text-amber-300 block font-bold text-sm">Abonelik Dondurulmuş (Atıl Servis)</strong>
                <span class="text-slate-600 dark:text-slate-400">Bu abonelik şu an dondurulmuş / askıya alınmış durumda. Kullanmıyorsanız tamamen iptal ederek yılda <strong class="text-emerald-600 dark:text-emerald-400 font-bold"><?= paraFormat($annualSave, $sub['currency']) ?></strong> tasarruf edebilirsiniz.</span>
            </div>
        </div>
        <?php if ($canEdit): ?>
        <button type="button" onclick="abonelikIptalEt(<?= $sub['id'] ?>, '<?= htmlspecialchars(addslashes($sub['title'])) ?>')" class="px-3.5 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-bold transition shadow-sm whitespace-nowrap ml-auto sm:ml-0">
            Aboneliği İptal Et
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 1. Temel Maliyet Kartı (4'lü Grid) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        
        <div class="p-5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-sm">
            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-1 flex items-center justify-between">
                <span><?= $isTaksitliSigorta ? 'Aylık Taksit Tutarı' : ($sub['category_type'] === 'bes' ? 'Aylık Katkı Payı' : ($sub['category_type'] === 'insurance' ? 'Poliçe Tutarı' : 'Abonelik Bedeli')) ?></span>
                <?php if ($toplamTasarruf > 0): ?>
                    <span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-bold bg-emerald-50 dark:bg-emerald-500/10 px-1.5 py-0.5 rounded">Net: <?= paraFormat($netMaliyet, $sub['currency']) ?></span>
                <?php endif; ?>
            </div>
            <div class="text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight"><?= $isTaksitliSigorta ? paraFormat($sub['ins_installment_amount'], $sub['currency']) : paraFormat($sub['price'], $sub['currency']) ?></div>
            <div class="text-xs text-slate-500 dark:text-slate-400 mt-1 flex items-center justify-between">
                <span><?= $isTaksitliSigorta ? ((int)($sub['ins_installment_count'] ?? 1) . ' Taksit (Toplam: ' . paraFormat($sub['price'], $sub['currency']) . ')') : ($sub['billing_cycle'] === 'monthly' ? 'Her Ay' : ($sub['billing_cycle'] === 'annual' ? 'Yıllık Peşin (Aylık: ~' . paraFormat(round((float)$sub['price'] / 12, 2), $sub['currency']) . ')' : ucfirst($sub['billing_cycle']))) ?></span>
                <?php if ($toplamTasarruf > 0): ?>
                    <span class="text-emerald-600 dark:text-emerald-400 font-medium text-[11px]">-<?= paraFormat($toplamTasarruf, $sub['currency']) ?> tasarruf</span>
                <?php endif; ?>
            </div>
            <?php if (!empty($son1YilZam['has_hike'])): ?>
                <div class="mt-2.5 pt-2 border-t border-slate-100 dark:border-slate-800/80">
                    <?php if ($son1YilZam['is_increase']): ?>
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20" title="Son 1 yıldaki baz fiyata (<?= paraFormat($son1YilZam['base_price'], $sub['currency']) ?>) göre değişim">
                            <span>📈</span>
                            <span>Son 1 Yılda +%<?= number_format($son1YilZam['percentage'], 1) ?> Zam</span>
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20" title="Son 1 yıldaki baz fiyata (<?= paraFormat($son1YilZam['base_price'], $sub['currency']) ?>) göre indirim">
                            <span>📉</span>
                            <span>Son 1 Yılda -%<?= number_format(abs($son1YilZam['percentage']), 1) ?> İndirim</span>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="p-5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-sm">
            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-1">Ödemeyi Yapan (Sorumlu)</div>
            <div class="text-base font-bold text-blue-600 dark:text-blue-400 tracking-tight flex items-center gap-1.5">
                <span>💳</span>
                <span><?= htmlspecialchars($sub['payer_name'] ?: ($sub['payer_username'] ?? 'Belirtilmedi')) ?><?= ($sub['payer_user_id'] == $userId) ? ' (Ben)' : '' ?></span>
            </div>
            <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                Ekleyen: <?= htmlspecialchars($sub['creator_name'] ?: $sub['creator_username']) ?>
            </div>
        </div>

        <div class="p-5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-sm">
            <div class="flex items-center justify-between mb-1">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500"><?= $isTaksitliSigorta ? "Sonraki Aylık Taksit" : "Sonraki Ödeme / Tahsilat" ?></span>
                <?php if ($canEdit && !empty($sub['next_payment_date'])): ?>
                    <button type="button" onclick="odendiIsaretle(<?= $sub['id'] ?>, '<?= htmlspecialchars(addslashes($sub['title'])) ?>')" class="px-2 py-0.5 bg-emerald-50 dark:bg-emerald-500/10 hover:bg-emerald-100 dark:hover:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30 rounded-lg text-[11px] font-bold transition flex items-center gap-1 shadow-sm" title="Ödendi olarak işaretle ve bir sonraki döneme ötele">
                        <span>✓ Ödendi</span>
                    </button>
                <?php endif; ?>
            </div>
            <div class="text-xl font-bold text-slate-900 dark:text-white tracking-tight"><?= tarihFormat($sub['next_payment_date']) ?></div>
            <div class="text-xs font-semibold mt-1 <?= ($kalanGun !== null && $kalanGun <= 3) ? 'text-rose-500 dark:text-rose-400' : 'text-amber-500 dark:text-amber-400' ?>">
                <?= $kalanGun !== null ? ($kalanGun <= 0 ? 'Bugün / Geçti!' : "{$kalanGun} gün kaldı") : '-' ?>
            </div>
        </div>

        <div class="p-5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-sm">
            <div class="flex items-center justify-between mb-1">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">Ödeme Aracı</span>
                <?php if (!empty($sub['is_auto_pay'])): ?>
                    <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/20">⚡ Otomatik</span>
                <?php endif; ?>
            </div>
            <?php if (!empty($sub['credit_card_id']) && !empty($sub['cc_bank_name'])): ?>
                <div class="text-sm font-bold text-emerald-600 dark:text-emerald-400 tracking-tight truncate flex items-center gap-1">
                    <span>💳</span>
                    <span class="truncate"><?= htmlspecialchars($sub['cc_bank_name']) ?></span>
                </div>
                <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 truncate">
                    <?= htmlspecialchars($sub['cc_card_name']) ?> (&bull;&bull;&bull;&bull; <?= $sub['cc_card_last_4'] ?: '0000' ?>)
                </div>
            <?php elseif (!empty($sub['payment_method_title'])): ?>
                <div class="text-sm font-bold text-blue-600 dark:text-blue-400 tracking-tight truncate flex items-center gap-1">
                    <span>🏦</span>
                    <span class="truncate"><?= htmlspecialchars($sub['payment_method_title']) ?></span>
                </div>
                <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 truncate">
                    <?= $sub['card_last_4'] ? 'Hesap/Kart: &bull;&bull;&bull;&bull; ' . $sub['card_last_4'] : 'Banka Transferi / Vadesiz' ?>
                </div>
            <?php else: ?>
                <div class="text-sm font-bold text-slate-500 dark:text-slate-400 tracking-tight">Belirtilmedi</div>
                <div class="text-xs text-slate-400 dark:text-slate-500 mt-1">Manuel Ödeme</div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Net Bütçe & Cashback / Masraf Paylaşımı Katkı Bandı -->
    <?php if ($cashback > 0 || $toplamTahsilEdilenOrtak > 0 || !empty($shares)): ?>
    <div class="p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-3xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs">
        <div class="flex items-center gap-3.5">
            <div class="w-10 h-10 rounded-2xl bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xl font-bold flex-shrink-0">
                💰
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <span class="font-extrabold text-sm text-emerald-800 dark:text-emerald-300">
                        Net Gideriniz: <?= paraFormat($netMaliyet, $sub['currency']) ?>
                    </span>
                    <?php if ($toplamTasarruf > 0): ?>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-700 dark:text-emerald-300">
                        Tasarruf: <?= paraFormat($toplamTasarruf, $sub['currency']) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="text-[11px] text-slate-600 dark:text-slate-400 mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span><?= $isTaksitliSigorta ? 'Aylık Taksit Bedeli' : 'Brüt Bedel' ?>: <b><?= paraFormat($bazTutar, $sub['currency']) ?></b><?= $isTaksitliSigorta ? ' (Poliçe Toplamı: ' . paraFormat($sub['price'], $sub['currency']) . ')' : '' ?></span>
                    <?php if ($cashback > 0): ?>
                        <span class="text-emerald-600 dark:text-emerald-400">🎁 Nakit İade (Cashback): <b>-<?= paraFormat($cashback, $sub['currency']) ?></b></span>
                    <?php endif; ?>
                    <?php if ($toplamTahsilEdilenOrtak > 0): ?>
                        <span class="text-blue-600 dark:text-blue-400">👥 Ortak Tahsilat (Ödendi): <b>-<?= paraFormat($toplamTahsilEdilenOrtak, $sub['currency']) ?></b></span>
                    <?php endif; ?>
                    <?php if ($toplamBekleyenOrtak > 0): ?>
                        <span class="text-amber-600 dark:text-amber-400">⏳ Tahsil Edilecek Ortak Payı: <b><?= paraFormat($toplamBekleyenOrtak, $sub['currency']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="sm:text-right flex-shrink-0">
            <span class="text-[10px] font-bold text-slate-400 dark:text-slate-500 block uppercase tracking-wider">Aylık Bütçeye Katkı</span>
            <span class="text-sm font-extrabold text-emerald-600 dark:text-emerald-400">+<?= paraFormat($toplamTasarruf, $sub['currency']) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- 2. Kategoriye Özel Detay Kartları -->
    
    <!-- Dijital Servis Detayı -->
    <?php if ($sub['category_type'] === 'digital'): ?>
    <div class="bg-white dark:bg-slate-900 border border-purple-200 dark:border-purple-500/20 rounded-3xl p-6 shadow-sm space-y-4">
        <h2 class="text-xs font-bold uppercase tracking-wider text-purple-600 dark:text-purple-400 flex items-center justify-between">
            <span>💻 Dijital Servis & Giriş Bilgileri</span>
            <?php if (!empty($sub['website_url'])): ?>
                <a href="<?= htmlspecialchars($sub['website_url']) ?>" target="_blank" rel="noopener noreferrer" 
                    class="text-xs text-purple-600 dark:text-purple-300 hover:underline flex items-center gap-1 font-semibold">
                    <span>Giriş Sayfasına Git</span>
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                </a>
            <?php endif; ?>
        </h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Kullanıcı Adı / E-Posta</span>
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold text-slate-900 dark:text-white font-mono select-all"><?= htmlspecialchars($sub['account_username'] ?? '-') ?></span>
                    <?php if (!empty($sub['account_username'])): ?>
                    <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars(addslashes($sub['account_username'] ?? '')) ?>'); toastBildirim('Kullanıcı adı kopyalandı');" 
                        class="text-xs text-slate-400 hover:text-slate-800 dark:hover:text-white p-1 rounded transition" title="Kopyala">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Giriş Şifresi (AES-256 Şifreli)</span>
                <?php if ($canViewPassword): ?>
                    <div class="flex items-center justify-between">
                        <span id="password_display" class="text-sm font-semibold text-purple-600 dark:text-purple-300 font-mono tracking-widest select-all">••••••••••••</span>
                        <div class="flex items-center gap-1.5">
                            <button id="show_password_btn" type="button" 
                                class="text-xs bg-purple-100 dark:bg-purple-600/20 hover:bg-purple-200 dark:hover:bg-purple-600/30 text-purple-700 dark:text-purple-300 px-2.5 py-1 rounded-lg transition font-semibold inline-flex items-center gap-1 cursor-pointer">
                                <span>👁️</span> <span>Şifreyi Göster</span>
                            </button>
                            <button id="hide_password_btn" type="button" onclick="gizleSifre()" class="hidden text-xs bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 px-2.5 py-1 rounded-lg transition font-semibold inline-flex items-center gap-1 cursor-pointer">
                                <span>🙈</span> <span>Gizle</span>
                            </button>
                            <button id="copy_password_btn" type="button" class="hidden text-xs text-slate-400 hover:text-slate-800 dark:hover:text-white p-1 rounded transition" title="Kopyala">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="flex items-center gap-2 text-slate-500 text-xs py-1">
                        <span>🔒</span>
                        <span class="text-amber-600 dark:text-amber-400 font-medium">Bu şifre yalnızca yetkili üyeler tarafından görüntülenebilir.</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
    <?php endif; ?>

    <!-- Fatura & Ev Detayı -->
    <?php if ($sub['category_type'] === 'utility'): ?>
    <div class="bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-500/20 rounded-3xl p-6 shadow-sm space-y-4">
        <h2 class="text-xs font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">⚡ Fatura & Sayaç Bilgileri</h2>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Firma / Kurum</span>
                <span class="text-sm font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($sub['company_name'] ?? '-') ?></span>
            </div>
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Abone / Sözleşme No</span>
                <div class="flex items-center justify-between">
                    <span class="text-sm font-bold text-slate-900 dark:text-white font-mono select-all"><?= htmlspecialchars($sub['subscriber_no'] ?? '-') ?></span>
                    <?php if (!empty($sub['subscriber_no'])): ?>
                    <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars(addslashes($sub['subscriber_no'])) ?>'); toastBildirim('Abone no kopyalandı');" class="text-xs text-slate-400 hover:text-slate-800 dark:hover:text-white">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Sayaç / Tesisat No</span>
                <span class="text-sm font-bold text-slate-900 dark:text-white font-mono"><?= htmlspecialchars($sub['meter_no'] ?? '-') ?></span>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
            <div class="flex items-center justify-between p-3.5 bg-slate-50 dark:bg-slate-950/40 rounded-xl text-xs">
                <span class="text-slate-500 dark:text-slate-400">Rutin Fatura Kesim Günü:</span>
                <span class="font-bold text-slate-900 dark:text-white"><?= !empty($sub['billing_cut_day']) ? "Her Ayın {$sub['billing_cut_day']}'si" : '-' ?></span>
            </div>
            <div class="flex items-center justify-between p-3.5 bg-slate-50 dark:bg-slate-950/40 rounded-xl text-xs">
                <span class="text-slate-500 dark:text-slate-400">Rutin Son Ödeme Günü:</span>
                <span class="font-bold text-amber-600 dark:text-amber-400"><?= !empty($sub['due_day']) ? "Her Ayın {$sub['due_day']}'si" : '-' ?></span>
            </div>
        </div>

        <!-- Otomatik Ödeme Durumu -->
        <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs">
            <div class="flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-xl <?= !empty($sub['util_is_auto_pay']) ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400' ?> flex items-center justify-center font-bold text-sm">
                    <?= !empty($sub['util_is_auto_pay']) ? '✓' : 'ℹ' ?>
                </span>
                <div>
                    <span class="font-bold text-slate-900 dark:text-white block">
                        <?= !empty($sub['util_is_auto_pay']) ? 'Otomatik Ödeme Talimatı Aktif' : 'Manuel Ödeme' ?>
                    </span>
                    <span class="text-slate-500 dark:text-slate-400 text-[11px]">
                        <?= !empty($sub['util_is_auto_pay']) 
                            ? 'Fatura son ödeme gününde tanımlı hesaptan otomatik olarak tahsil edilmektedir.' 
                            : 'Bu fatura için sisteme tanımlanmış otomatik ödeme talimatı bulunmuyor.' ?>
                    </span>
                </div>
            </div>
            <?php if (!empty($sub['util_is_auto_pay'])): ?>
                <div class="flex items-center gap-2 bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 px-3 py-1.5 rounded-xl text-emerald-700 dark:text-emerald-300 font-medium text-xs">
                    <span>🏦 <?= htmlspecialchars($sub['util_auto_pay_bank'] ?: 'Banka Belirtilmedi') ?></span>
                    <span class="text-emerald-500">•</span>
                    <span><?= ($sub['util_auto_pay_type'] === 'credit_card') ? 'Kredi Kartı' : (($sub['util_auto_pay_type'] === 'bank_account') ? 'Banka Hesabı' : 'Diğer') ?></span>
                    <?php if (!empty($sub['util_auto_pay_source_title'])): ?>
                        <span class="text-emerald-500">•</span>
                        <span class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($sub['util_auto_pay_source_title']) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Telekomünikasyon Detayı (İnternet & Hat & Taahhüt) -->
    <?php if ($sub['category_type'] === 'telecom'): ?>
    <div class="bg-white dark:bg-slate-900 border border-cyan-200 dark:border-cyan-500/20 rounded-3xl p-6 shadow-sm space-y-5">
        <h2 class="text-xs font-bold uppercase tracking-wider text-cyan-600 dark:text-cyan-400">📶 İnternet & Mobil Hat & Taahhüt Durumu</h2>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Operatör & Hizmet</span>
                <span class="text-sm font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($sub['operator_name'] ?? '-') ?> (<?= ucfirst($sub['service_type'] ?? '') ?>)</span>
            </div>
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Hat / Hizmet Numarası</span>
                <span class="text-sm font-bold text-slate-900 dark:text-white font-mono"><?= htmlspecialchars($sub['phone_or_line_no'] ?? '-') ?></span>
            </div>
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Paket Hızı & Kota</span>
                <span class="text-sm font-bold text-cyan-600 dark:text-cyan-300"><?= htmlspecialchars($sub['speed_or_quota'] ?? '-') ?></span>
            </div>
        </div>

        <!-- Otomatik Ödeme Durumu -->
        <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs">
            <div class="flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-xl <?= !empty($sub['tel_is_auto_pay']) ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400' ?> flex items-center justify-center font-bold text-sm">
                    <?= !empty($sub['tel_is_auto_pay']) ? '✓' : 'ℹ' ?>
                </span>
                <div>
                    <span class="font-bold text-slate-900 dark:text-white block">
                        <?= !empty($sub['tel_is_auto_pay']) ? 'Otomatik Ödeme Talimatı Aktif' : 'Manuel Ödeme' ?>
                    </span>
                    <span class="text-slate-500 dark:text-slate-400 text-[11px]">
                        <?= !empty($sub['tel_is_auto_pay']) 
                            ? 'Fatura bedeli tanımlı kart/hesaptan otomatik olarak çekilmektedir.' 
                            : 'Bu hat/paket için sisteme tanımlanmış otomatik ödeme talimatı bulunmuyor.' ?>
                    </span>
                </div>
            </div>
            <?php if (!empty($sub['tel_is_auto_pay'])): ?>
                <div class="flex items-center gap-2 bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 px-3 py-1.5 rounded-xl text-emerald-700 dark:text-emerald-300 font-medium text-xs">
                    <span>🏦 <?= htmlspecialchars($sub['tel_auto_pay_bank'] ?: 'Banka Belirtilmedi') ?></span>
                    <span class="text-emerald-500">•</span>
                    <span><?= ($sub['tel_auto_pay_type'] === 'credit_card') ? 'Kredi Kartı' : (($sub['tel_auto_pay_type'] === 'bank_account') ? 'Banka Hesabı' : 'Diğer') ?></span>
                    <?php if (!empty($sub['tel_auto_pay_source_title'])): ?>
                        <span class="text-emerald-500">•</span>
                        <span class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($sub['tel_auto_pay_source_title']) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($sub['commitment_end'])): ?>
        <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80 space-y-3">
            <div class="flex items-center justify-between text-xs">
                <span class="text-slate-500 dark:text-slate-400">Taahhüt İlerlemesi:</span>
                <span class="font-bold text-slate-900 dark:text-white">%<?= $taahhutYuzde ?> Tamamlandı (<?= tarihFormat($sub['commitment_start']) ?> → <?= tarihFormat($sub['commitment_end']) ?>)</span>
            </div>
            <div class="w-full h-2.5 bg-slate-200 dark:bg-slate-800 rounded-full overflow-hidden">
                <div class="h-full bg-gradient-to-r from-blue-500 to-cyan-400 rounded-full" style="width: <?= $taahhutYuzde ?>%"></div>
            </div>
            <div class="flex items-center justify-between text-xs pt-1">
                <span class="text-slate-500 dark:text-slate-400">Kalan Süre:</span>
                <span class="font-bold <?= ($taahhutKalan !== null && $taahhutKalan <= 30) ? 'text-rose-500 dark:text-rose-400' : 'text-slate-700 dark:text-slate-200' ?>">
                    <?= $taahhutKalan !== null ? "{$taahhutKalan} Gün Kaldı" : '-' ?>
                </span>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($sub['commitment_end']) && strtotime($sub['commitment_end']) < strtotime('today')): ?>
        <div class="p-4 bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-500/30 rounded-2xl flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs">
            <div class="flex items-center gap-2 text-rose-700 dark:text-rose-300">
                <span class="text-base">⚠️</span>
                <span><strong>Taahhüt Süresi Bitti!</strong> Taahhüt süresi <?= tarihFormat($sub['commitment_end']) ?> tarihinde sona erdi.
                <?php if (!empty($sub['post_commitment_price']) && (float)$sub['post_commitment_price'] > (float)$sub['price']): ?>
                    Tarife fiyatı taahhütsüz tutar olan <strong class="text-slate-900 dark:text-white"><?= paraFormat($sub['post_commitment_price'], $sub['currency']) ?></strong> seviyesine yükseldi.
                <?php endif; ?>
                </span>
            </div>
            <?php if ($canEdit && !empty($sub['post_commitment_price']) && (float)$sub['post_commitment_price'] > (float)$sub['price']): ?>
            <button type="button" onclick="tarifeyeUyarla(<?= $sub['id'] ?>, '<?= htmlspecialchars(addslashes($sub['title'])) ?>', '<?= paraFormat($sub['post_commitment_price'], $sub['currency']) ?>')" class="px-3.5 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-bold transition shadow-sm whitespace-nowrap ml-auto sm:ml-0">
                ⚡ Güncel Tarifeye Uyarla
            </button>
            <?php endif; ?>
        </div>
        <?php elseif (!empty($sub['post_commitment_price'])): ?>
        <div class="p-4 bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-500/20 rounded-2xl flex items-center justify-between text-xs">
            <div class="flex items-center gap-2 text-rose-700 dark:text-rose-300">
                <span class="text-base">⚠️</span>
                <span><strong>Taahhüt Bittiğinde Fiyat:</strong> Taahhüt süresi dolduğunda paket fiyatı <strong class="text-slate-900 dark:text-white"><?= paraFormat($sub['post_commitment_price'], $sub['currency']) ?></strong> seviyesine yükselecektir.</span>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Sigorta & Poliçe Detayı -->
    <?php if ($sub['category_type'] === 'insurance'): ?>
    <div class="bg-white dark:bg-slate-900 border border-rose-200 dark:border-rose-500/20 rounded-3xl p-6 shadow-sm space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <h2 class="text-xs font-bold uppercase tracking-wider text-rose-600 dark:text-rose-400 flex items-center gap-2">
                <span>🛡️ Poliçe & Sigorta Bilgileri</span>
            </h2>
            <?php if ($policeKalan !== null): ?>
                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-bold <?= ($policeKalan <= 30) ? 'bg-rose-100 dark:bg-rose-500/20 text-rose-700 dark:text-rose-400 border border-rose-200 dark:border-rose-500/30 animate-pulse' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' ?>">
                    <span>⏳ Yenilemeye:</span>
                    <span><?= ($policeKalan <= 0) ? 'Süresi Doldu / Hemen Yenileyin!' : "{$policeKalan} Gün Kaldı" ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Sigorta Şirketi</span>
                <span class="text-sm font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($sub['ins_company'] ?: '-') ?></span>
            </div>
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Poliçe Numarası</span>
                <div class="flex items-center justify-between">
                    <span class="text-sm font-bold text-slate-900 dark:text-white font-mono select-all"><?= htmlspecialchars($sub['ins_policy_no'] ?: '-') ?></span>
                    <?php if (!empty($sub['ins_policy_no'])): ?>
                    <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars(addslashes($sub['ins_policy_no'])) ?>'); toastBildirim('Poliçe no kopyalandı');" class="text-xs text-slate-400 hover:text-slate-800 dark:hover:text-white" title="Kopyala">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Sigortalanan Varlık / Plaka / Adres</span>
                <span class="text-sm font-bold text-rose-600 dark:text-rose-300"><?= htmlspecialchars($sub['ins_item'] ?: '-') ?></span>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Acente / Temsilci Bilgisi</span>
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-sm font-bold text-slate-900 dark:text-white block"><?= htmlspecialchars($sub['ins_agency_name'] ?: 'Belirtilmedi') ?></span>
                        <span class="text-xs text-slate-500 dark:text-slate-400 font-mono"><?= htmlspecialchars($sub['ins_agency_phone'] ?: '-') ?></span>
                    </div>
                    <?php if (!empty($sub['ins_agency_phone'])): ?>
                        <a href="tel:<?= htmlspecialchars($sub['ins_agency_phone']) ?>" class="px-3 py-1.5 bg-rose-50 dark:bg-rose-600/20 hover:bg-rose-100 dark:hover:bg-rose-600/30 text-rose-600 dark:text-rose-300 border border-rose-200 dark:border-rose-500/30 rounded-xl text-xs font-bold transition flex items-center gap-1.5">
                            <span>📞 Acente Ara</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Ödeme Şekli & Taksit Durumu</span>
                <?php if (($sub['ins_payment_type'] ?? '') === 'installment'): ?>
                    <span class="text-sm font-bold text-amber-600 dark:text-amber-400 block"><?= (int)($sub['ins_installment_count'] ?? 1) ?> Taksit</span>
                    <span class="text-xs text-slate-500 dark:text-slate-400">Aylık: <?= !empty($sub['ins_installment_amount']) ? paraFormat($sub['ins_installment_amount'], $sub['currency']) : '-' ?></span>
                <?php else: ?>
                    <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400 block">Peşin / Tek Çekim</span>
                    <span class="text-xs text-slate-500 dark:text-slate-400">Toplam Poliçe Tutarı: <?= paraFormat($sub['price'], $sub['currency']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
            <div class="flex items-center justify-between p-3.5 bg-slate-50 dark:bg-slate-950/40 rounded-xl text-xs">
                <span class="text-slate-500 dark:text-slate-400">Poliçe Başlangıç Tarihi:</span>
                <span class="font-bold text-slate-900 dark:text-white"><?= tarihFormat($sub['ins_start_date']) ?></span>
            </div>
            <div class="flex items-center justify-between p-3.5 bg-slate-50 dark:bg-slate-950/40 rounded-xl text-xs">
                <span class="text-slate-500 dark:text-slate-400">Poliçe Bitiş / Yenileme:</span>
                <span class="font-bold text-rose-600 dark:text-rose-400"><?= tarihFormat($sub['ins_end_date']) ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bireysel Emeklilik (BES) Detayı -->
    <?php if ($sub['category_type'] === 'bes'): ?>
    <div class="bg-white dark:bg-slate-900 border border-cyan-200 dark:border-cyan-500/20 rounded-3xl p-6 shadow-sm space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <h2 class="text-xs font-bold uppercase tracking-wider text-cyan-600 dark:text-cyan-400 flex items-center gap-2">
                <span>📈 Bireysel Emeklilik (BES) Sözleşme Bilgileri</span>
            </h2>
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-bold bg-cyan-50 dark:bg-cyan-500/20 text-cyan-700 dark:text-cyan-300 border border-cyan-200 dark:border-cyan-500/30">
                <span>Aylık Katkı Payı: <?= paraFormat($sub['price'], $sub['currency']) ?></span>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Emeklilik Şirketi</span>
                <span class="text-sm font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($sub['bes_company'] ?: '-') ?></span>
            </div>
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Sözleşme / Sertifika No</span>
                <div class="flex items-center justify-between">
                    <span class="text-sm font-bold text-slate-900 dark:text-white font-mono select-all"><?= htmlspecialchars($sub['bes_contract_no'] ?: '-') ?></span>
                    <?php if (!empty($sub['bes_contract_no'])): ?>
                    <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars(addslashes($sub['bes_contract_no'])) ?>'); toastBildirim('Sözleşme no kopyalandı');" class="text-xs text-slate-400 hover:text-slate-800 dark:hover:text-white" title="Kopyala">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Sözleşme Sahibi</span>
                <span class="text-sm font-bold text-cyan-600 dark:text-cyan-300"><?= htmlspecialchars($sub['bes_owner_relation'] ?: 'Kendim') ?></span>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80 flex items-center justify-between text-xs">
                <div>
                    <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Düzenli Çekim Günü</span>
                    <span class="text-sm font-bold text-slate-900 dark:text-white">Her Ayın <?= !empty($sub['bes_payment_day']) ? $sub['bes_payment_day'] . "'si" : '-' ?></span>
                </div>
                <span class="text-slate-500 dark:text-slate-400">Sonraki Çekim: <?= tarihFormat($sub['next_payment_date']) ?></span>
            </div>
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80 text-xs">
                <span class="text-[10px] uppercase font-bold text-slate-400 dark:text-slate-500 block mb-1">Otomatik Tahsilat Kaynağı</span>
                <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400"><?= htmlspecialchars($sub['bes_auto_pay_source'] ?: 'Belirtilmedi') ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bilgileri Görüntüleme ve Erişim İzni (Tüm Abonelik Türleri) -->
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-slate-100 dark:border-slate-800">
            <div>
                <h2 class="text-xs font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400 flex items-center gap-2">
                    <span>👥 Bilgileri Görüntüleme ve Erişim İzni</span>
                </h2>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                    <?php if ($sub['category_type'] === 'digital'): ?>
                        Bu aboneliğin hesap şifresi, giriş bilgileri, faturaları ve tüm detaylarına erişebilen üyeler.
                    <?php elseif (in_array($sub['category_type'], ['utility', 'telecom'])): ?>
                        Bu aboneliğin abone/sözleşme no, sayaç/tarife bilgileri, faturaları ve belgelerine erişebilen üyeler.
                    <?php elseif (in_array($sub['category_type'], ['insurance', 'bes'])): ?>
                        Bu poliçe/sözleşmenin teminat, taksit, katkı payı detayları ve belgelerine erişebilen üyeler.
                    <?php else: ?>
                        Bu aboneliğin tüm detayları, faturaları ve ekli belgelerine erişebilen üyeler.
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($canEdit): ?>
                <a href="abonelik-duzenle.php?id=<?= $sub['id'] ?>#access-section" 
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-50 dark:bg-slate-800 hover:bg-blue-50 dark:hover:bg-blue-900/30 text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 border border-slate-200 dark:border-slate-700 hover:border-blue-300 dark:hover:border-blue-500/40 rounded-xl text-xs font-bold transition shadow-sm self-start sm:self-auto">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>
                    <span>İzinleri Yönet</span>
                </a>
            <?php endif; ?>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Ekleyen / Sahibi -->
            <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-500/20 rounded-xl text-xs font-semibold shadow-xs">
                <span class="w-6 h-6 rounded-lg bg-purple-200 dark:bg-purple-900/60 flex items-center justify-center text-xs shadow-xs">👑</span>
                <span><?= htmlspecialchars($sub['creator_name'] ?: $sub['creator_username']) ?></span>
                <span class="text-[10px] text-purple-500 font-normal">(Ekleyen)</span>
            </div>

            <!-- Ödeyen (farklıysa) -->
            <?php if (!empty($sub['payer_user_id']) && $sub['payer_user_id'] != $sub['user_id']): ?>
                <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-500/20 rounded-xl text-xs font-semibold shadow-xs">
                    <span class="w-6 h-6 rounded-lg bg-blue-200 dark:bg-blue-900/60 flex items-center justify-center text-xs shadow-xs">💳</span>
                    <span><?= htmlspecialchars($sub['payer_name'] ?: $sub['payer_username']) ?></span>
                    <span class="text-[10px] text-blue-500 font-normal">(Ödeyen)</span>
                </div>
            <?php endif; ?>

            <!-- Yetki Verilen Diğer Üyeler -->
            <?php 
            $hasAdditional = false;
            foreach ($sharedUsers as $su): 
                if ($su['id'] == $sub['user_id'] || $su['id'] == ($sub['payer_user_id'] ?? 0)) continue;
                $hasAdditional = true;
            ?>
                <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-slate-50 dark:bg-slate-800/80 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-medium">
                    <span class="w-5 h-5 rounded-md bg-emerald-100 dark:bg-emerald-900/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-[10px] font-bold">✓</span>
                    <span><?= htmlspecialchars($su['full_name'] ?: $su['username']) ?></span>
                    <span class="text-[10px] text-slate-400 font-normal">(@<?= htmlspecialchars($su['username']) ?>)</span>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!$hasAdditional): ?>
            <div class="text-[11px] text-slate-400 dark:text-slate-500 flex items-center gap-1.5 pt-1">
                <span>🔒</span>
                <span>Bu aboneliğe dışarıdan ek bir üyeye görüntüleme izni verilmemiş. Yalnızca oluşturan kişi, ödeyen ve sistem yöneticileri görebilir.</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- 4. Masraf Paylaşımı / Ortak Hesap (Family & Friends Sharing) -->
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800">
            <div>
                <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">👥 Masraf Paylaşımı & Ortak Hesap</h2>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Spotify Aile, Netflix vb. masrafı bölüştüğünüz kişileri ve ödeme durumlarını takip edin.</p>
            </div>
            <button onclick="ortakEkleModal()" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition shadow-sm">
                + Kişi Ekle
            </button>
        </div>

        <?php if (empty($shares)): ?>
            <div class="py-6 text-center text-xs text-slate-400 dark:text-slate-500">
                Bu abonelik için henüz masraf paylaşımı tanımlanmadı.
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100 dark:divide-slate-800/80">
                <?php foreach ($shares as $sh): ?>
                    <div class="py-3 flex items-center justify-between text-xs">
                        <div>
                            <span class="font-bold text-slate-900 dark:text-white block text-sm"><?= htmlspecialchars($sh['person_name']) ?></span>
                            <span class="text-slate-500 dark:text-slate-400"><?= htmlspecialchars($sh['notes'] ?? 'Ortak pay') ?></span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="font-bold text-slate-800 dark:text-slate-200"><?= paraFormat($sh['share_amount']) ?></span>
                            <button onclick="toggleSharePaid(<?= $sh['id'] ?>, <?= $sh['is_paid'] ? 0 : 1 ?>)"
                                class="px-2.5 py-1 rounded-lg text-xs font-bold transition <?= $sh['is_paid'] ? 'bg-emerald-50 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30' : 'bg-rose-50 dark:bg-rose-500/20 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-500/30' ?>">
                                <?= $sh['is_paid'] ? '✓ Ödedi' : '✗ Bekliyor' ?>
                            </button>
                            <button onclick="silShare(<?= $sh['id'] ?>)" class="text-slate-400 hover:text-rose-600 p-1">
                                &times;
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 4. Poliçe / Sözleşme / Fatura Belgesi -->
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800">
            <h2 class="text-xs font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400 flex items-center gap-2">
                <span>📄 Poliçe, Sözleşme &amp; Fatura Belgesi</span>
            </h2>
            <?php if ($canEdit): ?>
                <a href="abonelik-duzenle.php?id=<?= $sub['id'] ?>" class="text-[11px] font-bold text-slate-500 hover:text-blue-600 dark:text-slate-400 dark:hover:text-blue-400 transition">
                    <?= !empty($sub['attachment_file']) ? 'Belgeyi Değiştir / Kaldır &rarr;' : '+ Belge Ekle &rarr;' ?>
                </a>
            <?php endif; ?>
        </div>

        <?php if (!empty($sub['attachment_file'])): 
            $fileName = $sub['attachment_name'] ?: basename($sub['attachment_file']);
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $isPdf = ($fileExt === 'pdf');
            $isImg = in_array($fileExt, ['jpg', 'jpeg', 'png', 'webp']);
            $icon = $isPdf ? '📕' : ($isImg ? '🖼️' : '📄');
        ?>
            <div class="p-4 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-2xl flex items-center justify-center text-2xl flex-shrink-0 shadow-sm">
                        <?= $icon ?>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-slate-900 dark:text-white truncate">
                            <?= htmlspecialchars($fileName) ?>
                        </p>
                        <div class="flex items-center gap-2 mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            <span class="uppercase font-semibold text-[10px] bg-slate-200 dark:bg-slate-800 px-1.5 py-0.5 rounded text-slate-700 dark:text-slate-300"><?= htmlspecialchars($fileExt) ?></span>
                            <span>Aboneliğe ekli resmi belge</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-shrink-0">
                    <a href="belge-indir.php?id=<?= $sub['id'] ?>&view=1" target="_blank"
                        class="px-4 py-2 bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm" title="Yeni Sekmede Aç">
                        <span>👁</span>
                        <span>Yeni Sekmede Aç</span>
                    </a>
                    <a href="belge-indir.php?id=<?= $sub['id'] ?>"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-md shadow-blue-600/20" title="İndir">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                        <span>İndir</span>
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="p-6 text-center bg-slate-50/50 dark:bg-slate-950/40 rounded-2xl border border-dashed border-slate-200 dark:border-slate-800/80">
                <span class="text-2xl block mb-1.5 opacity-60">📑</span>
                <p class="text-xs font-semibold text-slate-600 dark:text-slate-400">Bu aboneliğe henüz poliçe, sözleşme veya fatura belgesi yüklenmemiş.</p>
                <?php if ($canEdit): ?>
                    <a href="abonelik-duzenle.php?id=<?= $sub['id'] ?>" class="inline-block mt-3 px-3.5 py-1.5 bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 text-xs font-bold rounded-xl hover:bg-blue-100 dark:hover:bg-blue-800/40 transition">
                        + Belge Yükle
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 5. Fiyat Değişim & Zam Geçmişi -->
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 flex items-center gap-2">
                <span>📈 Fiyat Değişim & Zam Geçmişi</span>
            </h3>
            <?php if (!empty($son1YilZam['has_hike'])): ?>
                <span class="text-xs font-bold px-2.5 py-1 rounded-xl <?= $son1YilZam['is_increase'] ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20' ?>">
                    Son 1 Yılda <?= ($son1YilZam['is_increase'] ? '+' : '') . number_format($son1YilZam['percentage'], 1) ?>%
                </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($fiyatGecmisi)): ?>
            <div class="space-y-3">
                <?php foreach ($fiyatGecmisi as $fh): 
                    $isUp = (float)$fh['new_price'] >= (float)$fh['old_price'];
                ?>
                <div class="p-3.5 bg-slate-50 dark:bg-slate-950/60 rounded-2xl border border-slate-200 dark:border-slate-800/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-xl flex items-center justify-center font-bold text-sm <?= $isUp ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' ?>">
                            <?= $isUp ? '↗' : '↘' ?>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-slate-400 line-through"><?= paraFormat($fh['old_price'], $fh['currency']) ?></span>
                                <span class="text-slate-400">➔</span>
                                <span class="font-extrabold text-slate-900 dark:text-white text-sm"><?= paraFormat($fh['new_price'], $fh['currency']) ?></span>
                                <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold <?= $isUp ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20' ?>">
                                    <?= ($isUp ? '+' : '') . number_format((float)$fh['percentage_change'], 1) ?>%
                                </span>
                            </div>
                            <?php if (!empty($fh['notes'])): ?>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5"><?= htmlspecialchars($fh['notes']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 text-[11px] text-slate-400 dark:text-slate-500 sm:text-right">
                        <span><?= tarihFormat($fh['changed_at'], true) ?></span>
                        <?php if (!empty($fh['changer_name'])): ?>
                            <span>• <?= htmlspecialchars($fh['changer_name']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="p-5 text-center bg-slate-50/50 dark:bg-slate-950/40 rounded-2xl border border-dashed border-slate-200 dark:border-slate-800/80">
                <span class="text-xl block mb-1 opacity-60">📊</span>
                <p class="text-xs text-slate-500 dark:text-slate-400">Henüz bir fiyat güncellemesi kaydedilmedi. Abonelik tutarı düzenlendiğinde veya taahhüt sonrası güncellendiğinde zam ve değişim geçmişi otomatik burada listelenecektir.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- 6. Notlar -->
    <?php if (!empty($sub['notes'])): ?>
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-5 shadow-sm">
        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-2">Notlar</h3>
        <p class="text-xs text-slate-700 dark:text-slate-300 whitespace-pre-wrap leading-relaxed"><?= nl2br(htmlspecialchars($sub['notes'])) ?></p>
    </div>
    <?php endif; ?>

</div>

<script>
    let plainPasswordCache = '';
    const currentSubId = <?= (int)$sub['id'] ?>;
    let isSifreYukleniyor = false;

    // Güvenli JSON ayrıştırıcı (Sunucu HTML/Hata metni döndürürse temiz hata mesajı üretir)
    async function guvenliJsonOku(response) {
        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error("AJAX Yanıtı (JSON Değil):", text);
            // HTML etiketlerini ve fazla boşlukları temizle
            const cleanText = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            throw new Error(cleanText || ('Sunucu geçerli bir JSON yanıtı döndürmedi (HTTP ' + response.status + ')'));
        }
    }

    // Şifreyi Master PIN veya Giriş Şifresi ile Çözme
    function sifreyiAc(subscriptionId) {
        if (isSifreYukleniyor) return;
        subscriptionId = subscriptionId || currentSubId;
        if (plainPasswordCache) {
            gosterSifre(plainPasswordCache);
            return;
        }

        const showBtn = document.getElementById('show_password_btn');
        const origContent = showBtn ? showBtn.innerHTML : '';
        if (showBtn) {
            showBtn.disabled = true;
            showBtn.innerHTML = '<span>⏳</span> <span>Kontrol ediliyor...</span>';
        }

        const restoreBtn = () => {
            isSifreYukleniyor = false;
            if (showBtn) {
                showBtn.disabled = false;
                showBtn.innerHTML = origContent;
            }
        };

        isSifreYukleniyor = true;

        // Önce PIN sormadan dene (session'da kayıtlı olabilir) — POST ile gönder
        fetch('ajax.php?action=sifre_getir', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${subscriptionId}&csrf_token=<?= $_SESSION['csrf_token'] ?>`
        })
            .then(r => guvenliJsonOku(r))
            .then(res => {
                if (res.success) {
                    restoreBtn();
                    gosterSifre(res.password);
                } else if (res.require_pin) {
                    // PIN Sor
                    const pinMsg = (res.has_pin === false)
                        ? 'Master PIN tanımlanmamış. Hesap giriş şifrenizi girerek şifreyi çözebilirsiniz.'
                        : 'Dijital hesap şifresini görüntülemek için güvenlik Master PIN kodunuz gereklidir.';

                    Swal.fire({
                        title: 'Güvenlik Doğrulaması',
                        text: pinMsg,
                        input: 'password',
                        inputAttributes: {
                            maxlength: '32',
                            autocapitalize: 'off',
                            autocorrect: 'off',
                            placeholder: (res.has_pin === false) ? 'Giriş Şifreniz' : 'Master PIN'
                        },
                        showCancelButton: true,
                        confirmButtonText: 'Şifreyi Çöz',
                        cancelButtonText: 'İptal',
                        background: document.documentElement.classList.contains('dark') ? '#0f172a' : '#ffffff',
                        color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#0f172a'
                    }).then((result) => {
                        if (result.isConfirmed && result.value) {
                            if (showBtn) {
                                showBtn.disabled = true;
                                showBtn.innerHTML = '<span>⏳</span> <span>Çözülüyor...</span>';
                            }
                            // PIN'i POST body ile gönder
                            fetch('ajax.php?action=sifre_getir', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: `id=${subscriptionId}&pin=${encodeURIComponent(result.value)}&csrf_token=<?= $_SESSION['csrf_token'] ?>`
                            })
                                .then(r2 => guvenliJsonOku(r2))
                                .then(res2 => {
                                    restoreBtn();
                                    if (res2.success) {
                                        gosterSifre(res2.password);
                                        toastBildirim('Şifre başarıyla çözüldü');
                                    } else {
                                        Swal.fire('Doğrulama Başarısız', res2.message || 'Kod geçersiz!', 'error');
                                    }
                                })
                                .catch(err => {
                                    restoreBtn();
                                    Swal.fire('Hata', 'Sunucu yanıt vermedi: ' + err.message, 'error');
                                });
                        } else {
                            restoreBtn();
                        }
                    });
                } else {
                    restoreBtn();
                    Swal.fire('Hata', res.message || 'Şifre çözülemedi.', 'error');
                }
            })
            .catch(err => {
                restoreBtn();
                Swal.fire('Bağlantı Hatası', 'Sunucu ile iletişim kurulamadı: ' + err.message, 'error');
            });
    }

    function gosterSifre(pass) {
        plainPasswordCache = pass;
        const passElem = document.getElementById('password_display');
        if (passElem) {
            passElem.textContent = pass;
            passElem.classList.remove('tracking-widest');
        }
        const showBtn = document.getElementById('show_password_btn');
        if (showBtn) showBtn.classList.add('hidden');

        const hideBtn = document.getElementById('hide_password_btn');
        if (hideBtn) hideBtn.classList.remove('hidden');
        
        const copyBtn = document.getElementById('copy_password_btn');
        if (copyBtn) {
            copyBtn.classList.remove('hidden');
            copyBtn.onclick = function() {
                navigator.clipboard.writeText(pass);
                toastBildirim('Şifre kopyalandı');
            };
        }
    }

    function gizleSifre() {
        const passElem = document.getElementById('password_display');
        if (passElem) {
            passElem.textContent = '••••••••••••';
            passElem.classList.add('tracking-widest');
        }
        const showBtn = document.getElementById('show_password_btn');
        if (showBtn) {
            showBtn.classList.remove('hidden');
            showBtn.disabled = false;
        }
        const hideBtn = document.getElementById('hide_password_btn');
        if (hideBtn) hideBtn.classList.add('hidden');
        const copyBtn = document.getElementById('copy_password_btn');
        if (copyBtn) copyBtn.classList.add('hidden');
    }

    // Doğrudan event listener ekle (CSP / inline uyumluluğu garantisi)
    document.addEventListener('DOMContentLoaded', function() {
        const btn = document.getElementById('show_password_btn');
        if (btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                sifreyiAc(currentSubId);
            });
        }
    });

    // Ortak Masraf Kişisi Ekleme Modalı
    function ortakEkleModal() {
        Swal.fire({
            title: 'Ortak Masraf Payı Ekle',
            html: `
                <div class="space-y-3 text-left">
                    <div>
                        <label class="block text-xs font-bold text-slate-400 mb-1">Kişi Adı</label>
                        <input id="swal_person_name" class="swal2-input !m-0 !w-full !text-sm" placeholder="Örn: Ahmet Yılmaz">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-400 mb-1">Pay Tutarı (TL)</label>
                        <input id="swal_share_amount" class="swal2-input !m-0 !w-full !text-sm" placeholder="Örn: 50.00">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Kaydet',
            cancelButtonText: 'İptal',
            preConfirm: () => {
                const name = document.getElementById('swal_person_name').value.trim();
                const amount = document.getElementById('swal_share_amount').value.trim();
                if (!name || !amount) {
                    Swal.showValidationMessage('Lütfen kişi adı ve tutarı girin');
                    return false;
                }
                return { name, amount };
            }
        }).then(result => {
            if (result.isConfirmed) {
                fetch('ajax.php?action=ortak_ekle', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `subscription_id=<?= $sub['id'] ?>&name=${encodeURIComponent(result.value.name)}&amount=${encodeURIComponent(result.value.amount)}&csrf_token=<?= $_SESSION['csrf_token'] ?>`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        Swal.fire('Hata', data.message || 'Eklenemedi', 'error');
                    }
                });
            }
        });
    }

    // Ödeme Durumu Değiştirme
    function toggleSharePaid(shareId, newStatus) {
        fetch('ajax.php?action=ortak_durum', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `share_id=${shareId}&status=${newStatus}&csrf_token=<?= $_SESSION['csrf_token'] ?>`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            }
        });
    }

    // Ortak Silme
    function silShare(shareId) {
        fetch('ajax.php?action=ortak_sil', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `share_id=${shareId}&csrf_token=<?= $_SESSION['csrf_token'] ?>`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            }
        });
    }

    // Aboneliği Ödendi Olarak İşaretle (Periyodu Ötele)
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

    // Deneme Sürümünü Normal Aboneliğe Çevir
    function normalAboneligeCevir(id, baslik) {
        Swal.fire({
            title: 'Normal Aboneliğe Çevirilsin mi?',
            text: `"${baslik}" aboneliğinin deneme sürümü statüsü kaldırılacak ve normal ücretli abonelik olarak takip edilecektir.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Evet, Çevir',
            cancelButtonText: 'Vazgeç',
            background: document.documentElement.classList.contains('dark') ? '#0f172a' : '#ffffff',
            color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#0f172a'
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('action', 'trial_to_normal');
                formData.append('subscription_id', id);
                formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');

                fetch('ajax.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Başarılı!',
                                text: data.message,
                                showConfirmButton: false,
                                timer: 1500,
                                background: document.documentElement.classList.contains('dark') ? '#0f172a' : '#ffffff',
                                color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#0f172a'
                            }).then(() => window.location.reload());
                        } else {
                            Swal.fire('Hata', data.message || 'İşlem gerçekleştirilemedi.', 'error');
                        }
                    })
                    .catch(() => Swal.fire('Hata', 'Sunucu ile bağlantı kurulamadı.', 'error'));
            }
        });
    }

    // Taahhüt Sonrası Zamlı Fiyata Güncelle (Tarifeye Uyarla)
    function tarifeyeUyarla(id, baslik, priceFormatted) {
        Swal.fire({
            title: 'Tarife Fiyatı Güncellensin mi?',
            text: `"${baslik}" için taahhüt süresi dolduğundan güncel tarife tutarı olan ${priceFormatted} yeni abonelik fiyatı olarak kaydedilecek.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Evet, Fiyatı Güncelle',
            cancelButtonText: 'Vazgeç',
            background: document.documentElement.classList.contains('dark') ? '#0f172a' : '#ffffff',
            color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#0f172a'
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('action', 'apply_post_commitment_price');
                formData.append('subscription_id', id);
                formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');

                fetch('ajax.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Güncellendi!',
                                text: data.message,
                                showConfirmButton: false,
                                timer: 1500,
                                background: document.documentElement.classList.contains('dark') ? '#0f172a' : '#ffffff',
                                color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#0f172a'
                            }).then(() => window.location.reload());
                        } else {
                            Swal.fire('Hata', data.message || 'İşlem gerçekleştirilemedi.', 'error');
                        }
                    })
                    .catch(() => Swal.fire('Hata', 'Sunucu ile bağlantı kurulamadı.', 'error'));
            }
        });
    }

    function abonelikIptalEt(subId, baslik) {
        Swal.fire({
            title: 'Aboneliği İptal Et',
            text: `"${baslik}" aboneliğini iptal edildi olarak işaretlemek istiyor musunuz?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Evet, İptal Et',
            cancelButtonText: 'Vazgeç',
            background: document.documentElement.classList.contains('dark') ? '#0f172a' : '#ffffff',
            color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#0f172a'
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('action', 'durum_degistir');
                formData.append('subscription_id', subId);
                formData.append('status', 'canceled');
                formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');

                fetch('ajax.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Başarılı',
                                text: data.message || 'Abonelik iptal edildi.',
                                showConfirmButton: false,
                                timer: 1500,
                                background: document.documentElement.classList.contains('dark') ? '#0f172a' : '#ffffff',
                                color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#0f172a'
                            }).then(() => window.location.reload());
                        } else {
                            Swal.fire('Hata', data.message || 'İşlem gerçekleştirilemedi.', 'error');
                        }
                    })
                    .catch(() => Swal.fire('Hata', 'Sunucuya bağlanılamadı.', 'error'));
            }
        });
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
