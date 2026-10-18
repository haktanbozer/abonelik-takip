<?php
$pageTitle = "Fatura ve Ödeme Takvimi - Abonelik Takip";
require_once __DIR__ . '/header.php';

$userId = $_SESSION['user_id'];

// Seçili Ay ve Yıl
$seciliAy  = isset($_GET['ay']) ? (int)$_GET['ay'] : (int)date('n');
$seciliYil = isset($_GET['yil']) ? (int)$_GET['yil'] : (int)date('Y');

if ($seciliAy < 1) { $seciliAy = 12; $seciliYil--; }
if ($seciliAy > 12) { $seciliAy = 1; $seciliYil++; }

$ayBaslangic = sprintf('%04d-%02d-01', $seciliYil, $seciliAy);
$ayBitis     = date('Y-m-t', strtotime($ayBaslangic));
$ayToplamGun = (int)date('t', strtotime($ayBaslangic));
$ilkGunHaftanin = (int)date('N', strtotime($ayBaslangic)); // 1 (Pazartesi) - 7 (Pazar)

// Ayın Türkçe İsmi
$ayIsimleri = [
    1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran',
    7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'
];
$ayAdi = $ayIsimleri[$seciliAy];

$userRole = $_SESSION['role'] ?? 'USER';
$isAdmin = ($userRole === 'ADMIN');
$aktifKonumId = !empty($_SESSION['aktif_konum_id']) ? (int)$_SESSION['aktif_konum_id'] : null;
$aktifKonumAd = $_SESSION['aktif_konum_ad'] ?? 'Tüm Konumlar';

// Bu Ayki Ödemeleri ve Rutin Faturaları Çek
$gunlukOdemeler = [];
$buAyToplam = 0;

try {
    // 1. next_payment_date bu aya denk gelen aktif abonelikler
    $sql1 = "
        SELECT s.id, s.title, s.price, s.currency, s.next_payment_date, c.name as category_name, c.color as category_color, c.type as category_type,
               u_payer.full_name as payer_name, u_payer.username as payer_username,
               ins.payment_type as ins_payment_type, ins.installment_count as ins_installment_count, ins.installment_amount as ins_installment_amount,
               ins.policy_start_date as ins_start_date, ins.policy_end_date as ins_end_date
        FROM subscriptions s
        LEFT JOIN categories c ON s.category_id = c.id
        LEFT JOIN users u_payer ON COALESCE(s.payer_user_id, s.user_id) = u_payer.id
        LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
        WHERE s.status = 'active'
          AND s.next_payment_date BETWEEN ? AND ?
    ";
    $params1 = [$ayBaslangic, $ayBitis];

    if (!$isAdmin) {
        $sql1 .= " AND (s.user_id = ? OR s.payer_user_id = ?)";
        $params1[] = $userId;
        $params1[] = $userId;
    }
    if ($aktifKonumId) {
        $sql1 .= " AND s.location_id = ?";
        $params1[] = $aktifKonumId;
    }

    $stmt = $pdo->prepare($sql1);
    $stmt->execute($params1);
    $odemeler = $stmt->fetchAll();

    $eklenenTakvimSubIds = [];

    foreach ($odemeler as $o) {
        $gun = (int)date('j', strtotime($o['next_payment_date']));
        $pName = $o['payer_name'] ?: $o['payer_username'];
        $isInst = ($o['category_type'] === 'insurance' && ($o['ins_payment_type'] ?? '') === 'installment' && !empty($o['ins_installment_amount']));
        $fiyat = $isInst ? (float)$o['ins_installment_amount'] : (float)$o['price'];
        $label = $isInst ? 'Taksit Ödemesi' : 'Ödeme Günü';

        $gunlukOdemeler[$gun][] = [
            'id' => $o['id'],
            'clean_title' => $o['title'],
            'title' => $o['title'] . ($pName ? " ({$pName})" : "") . ($isInst ? " (Taksit)" : ""),
            'price' => $fiyat,
            'currency' => $o['currency'],
            'type' => 'due',
            'label' => $label
        ];
        $buAyToplam += $fiyat;
        $eklenenTakvimSubIds[$o['id']] = true;
    }

    // 2. Rutin fatura kesim ve son ödeme günleri (Fiziksel faturalar için)
    $sql2 = "
        SELECT s.id, s.title, s.price, s.currency, u.billing_cut_day, u.due_day,
               u_payer.full_name as payer_name, u_payer.username as payer_username
        FROM subscriptions s
        JOIN subscription_utility_details u ON s.id = u.subscription_id
        LEFT JOIN users u_payer ON COALESCE(s.payer_user_id, s.user_id) = u_payer.id
        WHERE s.status = 'active'
    ";
    $params2 = [];

    if (!$isAdmin) {
        $sql2 .= " AND (s.user_id = ? OR s.payer_user_id = ?)";
        $params2[] = $userId;
        $params2[] = $userId;
    }
    if ($aktifKonumId) {
        $sql2 .= " AND s.location_id = ?";
        $params2[] = $aktifKonumId;
    }

    $stmtUtil = $pdo->prepare($sql2);
    $stmtUtil->execute($params2);
    $rutinler = $stmtUtil->fetchAll();

    foreach ($rutinler as $r) {
        $pName = $r['payer_name'] ?: $r['payer_username'];
        // Fatura kesim günü
        if (!empty($r['billing_cut_day']) && $r['billing_cut_day'] <= $ayToplamGun) {
            $gunlukOdemeler[(int)$r['billing_cut_day']][] = [
                'id' => $r['id'],
                'clean_title' => $r['title'],
                'title' => $r['title'] . ($pName ? " ({$pName})" : ""),
                'price' => (float)$r['price'],
                'currency' => $r['currency'],
                'type' => 'cut',
                'label' => 'Fatura Kesimi'
            ];
        }
    }

    // 3. Taksitli Sigorta ve Poliçeler (Her ay taksit günü düzenli olarak takvimde yer alsın)
    $sqlIns = "
        SELECT s.id, s.title, s.price, s.currency, s.next_payment_date,
               ins.payment_type as ins_payment_type, ins.installment_count as ins_installment_count, ins.installment_amount as ins_installment_amount,
               ins.policy_start_date as ins_start_date, ins.policy_end_date as ins_end_date,
               u_payer.full_name as payer_name, u_payer.username as payer_username
        FROM subscriptions s
        JOIN categories c ON s.category_id = c.id
        JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
        LEFT JOIN users u_payer ON COALESCE(s.payer_user_id, s.user_id) = u_payer.id
        WHERE s.status = 'active'
          AND c.type = 'insurance'
          AND ins.payment_type = 'installment'
          AND (ins.policy_end_date IS NULL OR ins.policy_end_date >= ?)
          AND (ins.policy_start_date IS NULL OR ins.policy_start_date <= ?)
    ";
    $paramsIns = [$ayBaslangic, $ayBitis];
    if (!$isAdmin) {
        $sqlIns .= " AND (s.user_id = ? OR s.payer_user_id = ?)";
        $paramsIns[] = $userId;
        $paramsIns[] = $userId;
    }
    if ($aktifKonumId) {
        $sqlIns .= " AND s.location_id = ?";
        $paramsIns[] = $aktifKonumId;
    }

    $stmtInsList = $pdo->prepare($sqlIns);
    $stmtInsList->execute($paramsIns);
    $taksitliSigortalar = $stmtInsList->fetchAll();

    foreach ($taksitliSigortalar as $tSig) {
        if (isset($eklenenTakvimSubIds[$tSig['id']])) {
            continue;
        }

        $tDay = 1;
        if (!empty($tSig['ins_start_date'])) {
            $tDay = (int)date('j', strtotime($tSig['ins_start_date']));
        } elseif (!empty($tSig['next_payment_date'])) {
            $tDay = (int)date('j', strtotime($tSig['next_payment_date']));
        }
        $validDay = min($tDay, $ayToplamGun);

        $pName = $tSig['payer_name'] ?: $tSig['payer_username'];
        $fiyat = !empty($tSig['ins_installment_amount']) ? (float)$tSig['ins_installment_amount'] : (float)$tSig['price'];

        $gunlukOdemeler[$validDay][] = [
            'id' => $tSig['id'],
            'clean_title' => $tSig['title'],
            'title' => $tSig['title'] . ($pName ? " ({$pName})" : "") . " (Taksit)",
            'price' => $fiyat,
            'currency' => $tSig['currency'],
            'type' => 'due',
            'label' => 'Taksit Ödemesi'
        ];
        $buAyToplam += $fiyat;
        $eklenenTakvimSubIds[$tSig['id']] = true;
    }

} catch (Exception $e) {
    sistemLogla("Fatura takvimi çekme hatası: " . $e->getMessage(), 'ERROR');
}

// Önceki ve Sonraki Ay Linkleri
$oncekiAy = $seciliAy - 1;
$oncekiYil = $seciliYil;
if ($oncekiAy < 1) { $oncekiAy = 12; $oncekiYil--; }

$sonrakiAy = $seciliAy + 1;
$sonrakiYil = $seciliYil;
if ($sonrakiAy > 12) { $sonrakiAy = 1; $sonrakiYil++; }
?>

<div class="space-y-6">

    <!-- Üst Başlık & Ay Seçici -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-200 dark:border-slate-800">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">Fatura & Nakit Akışı Takvimi</h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">Ay boyunca fatura kesim ve son ödeme tarihlerinizin akışı.</p>
        </div>

        <div class="flex items-center gap-3">
            <div class="flex items-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-1 shadow-sm">
                <a href="fatura-takvimi.php?ay=<?= $oncekiAy ?>&yil=<?= $oncekiYil ?>" 
                    class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white rounded-xl transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                </a>
                <span class="px-4 text-xs sm:text-sm font-bold text-slate-900 dark:text-white">
                    <?= $ayAdi ?> <?= $seciliYil ?>
                </span>
                <a href="fatura-takvimi.php?ay=<?= $sonrakiAy ?>&yil=<?= $sonrakiYil ?>" 
                    class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white rounded-xl transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </a>
            </div>

            <div class="p-2.5 bg-blue-50 dark:bg-blue-500/10 border border-blue-200 dark:border-blue-500/20 rounded-2xl text-xs">
                <span class="text-slate-500 dark:text-slate-400">Bu Ay Toplam:</span>
                <strong class="text-blue-600 dark:text-blue-400 font-bold ml-1"><?= paraFormat($buAyToplam) ?></strong>
            </div>
        </div>
    </div>

    <!-- Takvim Kartı -->
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-4 sm:p-6 shadow-sm overflow-hidden">
        
        <!-- Gün Başlıkları (Pzt - Paz) -->
        <div class="grid grid-cols-7 gap-1 sm:gap-2 mb-2 text-center text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider py-2">
            <div>Pzt</div>
            <div>Sal</div>
            <div>Çar</div>
            <div>Per</div>
            <div>Cum</div>
            <div class="text-rose-500 dark:text-rose-400">Cmt</div>
            <div class="text-rose-500 dark:text-rose-400">Paz</div>
        </div>

        <!-- Takvim Izgarası -->
        <div class="grid grid-cols-7 gap-1 sm:gap-2">
            
            <!-- İlk günden önceki boş hücreler -->
            <?php for ($i = 1; $i < $ilkGunHaftanin; $i++): ?>
                <div class="min-h-[85px] sm:min-h-[110px] p-2 bg-slate-100/50 dark:bg-slate-950/20 border border-slate-200/50 dark:border-slate-800/40 rounded-2xl opacity-40"></div>
            <?php endfor; ?>

            <!-- Ayın Günleri -->
            <?php 
            $bugunGun = (int)date('j');
            $bugunAy  = (int)date('n');
            $bugunYil = (int)date('Y');

            for ($g = 1; $g <= $ayToplamGun; $g++): 
                $isBugun = ($g === $bugunGun && $seciliAy === $bugunAy && $seciliYil === $bugunYil);
                $gununOdemeleri = $gunlukOdemeler[$g] ?? [];
            ?>
                <div class="min-h-[85px] sm:min-h-[110px] p-2 bg-slate-50/70 dark:bg-slate-950/60 border <?= $isBugun ? 'border-blue-500 ring-1 ring-blue-500 shadow-md shadow-blue-500/10' : 'border-slate-200 dark:border-slate-800/80 hover:border-slate-300 dark:hover:border-slate-700' ?> rounded-2xl flex flex-col justify-between transition">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold <?= $isBugun ? 'w-5 h-5 bg-blue-600 text-white rounded-full flex items-center justify-center text-[10px]' : 'text-slate-500 dark:text-slate-400' ?>">
                            <?= $g ?>
                        </span>
                        <?php if (!empty($gununOdemeleri)): ?>
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-500 dark:bg-blue-400"></span>
                        <?php endif; ?>
                    </div>

                    <!-- Gündeki Ödeme Hapları (Pills) -->
                    <div class="space-y-1 mt-1 overflow-y-auto max-h-[70px]">
                        <?php foreach ($gununOdemeleri as $item): ?>
                            <div class="group/pill flex items-center justify-between p-1 rounded-lg text-[10px] sm:text-xs leading-tight font-semibold transition cursor-pointer <?= $item['type'] === 'cut' ? 'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-500/20 hover:bg-amber-100 dark:hover:bg-amber-500/20' : 'bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-500/20 hover:bg-blue-100 dark:hover:bg-blue-500/20' ?>"
                                onclick="hapTiklandi(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['clean_title'])) ?>', '<?= paraFormat($item['price'], $item['currency']) ?>', '<?= $item['label'] ?>')"
                                title="<?= htmlspecialchars($item['title']) ?> (<?= $item['label'] ?>): <?= paraFormat($item['price'], $item['currency']) ?>">
                                <div class="min-w-0 flex-1 truncate pr-1">
                                    <div class="font-bold truncate"><?= htmlspecialchars($item['title']) ?></div>
                                    <div class="opacity-80"><?= paraFormat($item['price'], $item['currency']) ?></div>
                                </div>
                                <button type="button" 
                                    onclick="event.stopPropagation(); odendiIsaretle(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['clean_title'])) ?>')" 
                                    class="flex-shrink-0 px-1 py-0.5 text-[9px] font-bold bg-emerald-500 hover:bg-emerald-600 text-white rounded transition shadow-sm" 
                                    title="Ödendi olarak işaretle ve bir sonraki döneme ötele">
                                    ✓
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endfor; ?>

        </div>

    </div>

    <!-- Açıklama / Legend -->
    <div class="flex items-center gap-4 text-xs text-slate-500 dark:text-slate-400 pt-2">
        <div class="flex items-center gap-1.5">
            <span class="w-3 h-3 rounded-md bg-blue-100 dark:bg-blue-500/20 border border-blue-300 dark:border-blue-500/40 inline-block"></span>
            <span>Son Ödeme Günü (Hesaptan Çekilecek)</span>
        </div>
        <div class="flex items-center gap-1.5">
            <span class="w-3 h-3 rounded-md bg-amber-100 dark:bg-amber-500/20 border border-amber-300 dark:border-amber-500/40 inline-block"></span>
            <span>Rutin Fatura Kesim Tarihi</span>
        </div>
    </div>

</div>

<script>
    function hapTiklandi(id, baslik, tutar, label) {
        Swal.fire({
            title: baslik,
            html: `
                <div class="text-sm text-slate-500 dark:text-slate-400 mt-2">
                    <div class="text-xl font-black text-slate-900 dark:text-white mb-1">${tutar}</div>
                    <div class="inline-block px-2.5 py-0.5 rounded-full text-xs font-bold bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-200 dark:border-blue-500/20 mb-3">${label}</div>
                    <p class="text-xs">Bu aboneliğin ödemesini öteleyebilir veya detay sayfasını görüntüleyebilirsiniz.</p>
                </div>
            `,
            icon: 'info',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: '✓ Ödendi Olarak İşaretle',
            confirmButtonColor: '#10b981',
            denyButtonText: 'Detay Sayfası &rarr;',
            denyButtonColor: '#3b82f6',
            cancelButtonText: 'Kapat',
            cancelButtonColor: '#64748b',
            background: document.documentElement.classList.contains('dark') ? '#0f172a' : '#ffffff',
            color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#0f172a'
        }).then((result) => {
            if (result.isConfirmed) {
                odendiIsaretle(id, baslik);
            } else if (result.isDenied) {
                window.location.href = `abonelik-detay.php?id=${id}`;
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
