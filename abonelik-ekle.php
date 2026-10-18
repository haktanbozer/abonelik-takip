<?php
require_once __DIR__ . '/db.php';
girisKontrol();

$userId = (int)$_SESSION['user_id'];
$error = '';
$success = '';

// Kategorileri, Konumları, Ödeme Yöntemlerini ve Aktif Üyeleri Çek
try {
    $categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll();
    
    // Konumlar (Admin tümünü, normal kullanıcı sadece yetkili olduğu mekanları görebilir)
    $userRole = $_SESSION['role'] ?? 'USER';
    $locations = kullaniciKonumlariGetir($userId, $userRole);

    $paymentMethods = $pdo->query("SELECT * FROM payment_methods ORDER BY title ASC")->fetchAll();
    
    // Aktif Kullanıcılar (Ödeme sorumlusu atama ve şifre paylaşımı için)
    $stmtUsers = $pdo->query("SELECT id, username, full_name, role FROM users WHERE status = 'active' ORDER BY full_name ASC, username ASC");
    $allUsers = $stmtUsers->fetchAll();

    // Kullanıcının Tanımlı Kredi Kartları
    $stmtCards = $pdo->prepare("SELECT id, bank_name, card_name, card_last_4 FROM credit_cards WHERE user_id = ? AND is_active = 1 ORDER BY bank_name ASC");
    $stmtCards->execute([$userId]);
    $creditCards = $stmtCards->fetchAll();
} catch (Exception $e) {
    sistemLogla("Kategori ve kullanıcı çekme hatası: " . $e->getMessage(), 'ERROR');
    $categories = [];
    $locations = [];
    $paymentMethods = [];
    $allUsers = [];
    $creditCards = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');

    $categoryId = (int)($_POST['category_id'] ?? 0);
    $locationId = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
    $payerUserId = !empty($_POST['payer_user_id']) ? (int)$_POST['payer_user_id'] : $userId;
    $paymentMethodId = !empty($_POST['payment_method_id']) ? (int)$_POST['payment_method_id'] : null;
    $creditCardId = !empty($_POST['credit_card_id']) ? (int)$_POST['credit_card_id'] : null;

    // IDOR Güvenliği: Kredi kartı ve mekan doğrulama
    $userRole = $_SESSION['role'] ?? 'USER';
    $isAdmin = ($userRole === 'ADMIN');
    if ($creditCardId !== null && !$isAdmin) {
        $checkCard = $pdo->prepare("SELECT id FROM credit_cards WHERE id = ? AND user_id = ? AND is_active = 1");
        $checkCard->execute([$creditCardId, $userId]);
        if ($checkCard->rowCount() === 0) {
            $creditCardId = null; // Başkasına ait kartın izinsiz bağlanması engellendi
        }
    }
    if ($locationId !== null) {
        if (!kullaniciKonumGorebilirMi($locationId, $userId, $userRole)) {
            $error = 'Seçtiğiniz mekana erişim veya abonelik atama yetkiniz bulunmuyor.';
            sistemLogla("Yetkisiz Mekan Atama Girişimi Engellendi (IDOR): User ID {$userId}, Location ID {$locationId}", 'SECURITY');
            $locationId = null;
        } else {
            $checkLoc = $pdo->prepare("SELECT id FROM locations WHERE id = ?");
            $checkLoc->execute([$locationId]);
            if ($checkLoc->rowCount() === 0) {
                $locationId = null;
            }
        }
    }
    $title = trim($_POST['title'] ?? '');
    $planTier = trim($_POST['plan_tier'] ?? '');
    $price = (float)str_replace(',', '.', $_POST['price'] ?? 0);
    $cashbackAmount = max(0, (float)str_replace(',', '.', $_POST['cashback_amount'] ?? 0));
    $currency = trim($_POST['currency'] ?? 'TRY');
    $billingCycle = $_POST['billing_cycle'] ?? 'monthly';
    $nextPaymentDate = !empty($_POST['next_payment_date']) ? $_POST['next_payment_date'] : null;
    $isTrial = !empty($_POST['is_trial']) ? 1 : 0;
    $trialEndDate = ($isTrial && !empty($_POST['trial_end_date'])) ? $_POST['trial_end_date'] : null;
    $isAutoPay = !empty($_POST['is_auto_pay']) ? 1 : 0;
    $autoPayType = !empty($_POST['auto_pay_type']) ? $_POST['auto_pay_type'] : null;
    $notes = trim($_POST['notes'] ?? '');

    // Belge / Poliçe / Sözleşme Dosya Yükleme Kontrolü
    $attachmentFile = null;
    $attachmentName = null;
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['document'];
        $maxSize = 10 * 1024 * 1024; // 10 MB
        $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $fileMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($file['size'] > $maxSize) {
            $error = 'Yüklenen poliçe/sözleşme dosyası 10 MB sınırını aşıyor.';
        } elseif (!in_array($fileExt, $allowedExts) || !in_array($fileMime, $allowedMimes)) {
            $error = 'Yalnızca PDF, JPG, PNG ve WEBP formatında poliçe veya sözleşme belgeleri yüklenebilir.';
        } else {
            $uploadDir = __DIR__ . '/uploads/documents';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $uniqueName = 'doc_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $fileExt;
            $destPath = $uploadDir . '/' . $uniqueName;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $attachmentFile = 'uploads/documents/' . $uniqueName;
                $attachmentName = $file['name'];
            } else {
                $error = 'Belge sunucuya kaydedilirken bir hata oluştu.';
            }
        }
    }

    if (empty($title) || empty($categoryId)) {
        $error = 'Lütfen başlık ve kategorisini seçin.';
    } elseif (empty($error)) {
        try {
            $pdo->beginTransaction();

            // Kategori tipini öğren
            $stmtCatType = $pdo->prepare("SELECT type FROM categories WHERE id = ?");
            $stmtCatType->execute([$categoryId]);
            $categoryType = $stmtCatType->fetchColumn() ?: 'general';

            // Sigorta taksitli ödeme tutar ve döngü senkronizasyonu
            $insPayType = $_POST['ins_payment_type'] ?? 'single';
            if ($categoryType === 'insurance' && $insPayType === 'installment') {
                $billingCycle = 'monthly';
                $instAmount = !empty($_POST['ins_installment_amount']) ? (float)str_replace(',', '.', $_POST['ins_installment_amount']) : 0;
                $instCount = (int)($_POST['ins_installment_count'] ?? 1);
                if (($price <= 0 || empty($price)) && $instAmount > 0 && $instCount > 0) {
                    $price = $instAmount * $instCount;
                }

                $insStart = !empty($_POST['ins_start_date']) ? $_POST['ins_start_date'] : null;
                $insEnd = !empty($_POST['ins_end_date']) ? $_POST['ins_end_date'] : null;
                $startDay = !empty($insStart) ? (int)date('j', strtotime($insStart)) : (!empty($nextPaymentDate) ? (int)date('j', strtotime($nextPaymentDate)) : (int)date('j'));

                // Eğer sonraki ödeme tarihi poliçe bitişiyle (1 yıl sonrası) aynı girilmişse veya 60 günden fazlaysa, ilk aylık taksit gününe çek
                if (empty($nextPaymentDate) || (!empty($insEnd) && $nextPaymentDate === $insEnd) || strtotime($nextPaymentDate) > strtotime('+60 days')) {
                    $curY = (int)date('Y');
                    $curM = (int)date('n');
                    $maxD = (int)date('t', strtotime(sprintf('%04d-%02d-01', $curY, $curM)));
                    $useD = min($startDay, $maxD);
                    $nextPaymentDate = sprintf('%04d-%02d-%02d', $curY, $curM, $useD);
                }
            }

            // 1. Ana Abonelik Kaydı (payer_user_id, credit_card_id, is_auto_pay ve belge eki ile)
            $stmtSub = $pdo->prepare("
                INSERT INTO subscriptions 
                (user_id, payer_user_id, location_id, category_id, payment_method_id, credit_card_id, is_auto_pay, auto_pay_type, title, plan_tier, price, cashback_amount, currency, billing_cycle, next_payment_date, is_trial, trial_end_date, notes, attachment_file, attachment_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtSub->execute([
                $userId, $payerUserId, $locationId, $categoryId, $paymentMethodId, $creditCardId, $isAutoPay, $autoPayType, $title, $planTier, $price, $cashbackAmount, $currency, $billingCycle, $nextPaymentDate, $isTrial, $trialEndDate, $notes, $attachmentFile, $attachmentName
            ]);
            $subscriptionId = $pdo->lastInsertId();

            // 2. Kategori Tipine Göre Detay Kaydı
            if ($categoryType === 'digital') {
                $accUsername = trim($_POST['account_username'] ?? '');
                $accPassword = $_POST['account_password'] ?? '';
                $websiteUrl  = trim($_POST['website_url'] ?? '');
                $profileCount = (int)($_POST['profile_count'] ?? 1);
                $sharedWith   = (int)($_POST['shared_with_count'] ?? 0);

                $encryptedData = sifrele($accPassword);

                $stmtDig = $pdo->prepare("
                    INSERT INTO subscription_digital_details
                    (subscription_id, account_username, account_password_encrypted, encryption_iv, website_url, profile_count, shared_with_count)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtDig->execute([
                    $subscriptionId, $accUsername, $encryptedData['encrypted'], $encryptedData['iv'], $websiteUrl, $profileCount, $sharedWith
                ]);
            } elseif ($categoryType === 'utility') {
                $companyName = trim($_POST['company_name'] ?? '');
                $subscriberNo = trim($_POST['subscriber_no'] ?? '');
                $meterNo = trim($_POST['meter_no'] ?? '');
                $installationNo = trim($_POST['installation_no'] ?? '');
                $billingCutDay = !empty($_POST['billing_cut_day']) ? (int)$_POST['billing_cut_day'] : null;
                $dueDay = !empty($_POST['due_day']) ? (int)$_POST['due_day'] : null;
                $isUtilAutoPay = !empty($_POST['is_auto_pay']) ? 1 : 0;
                $utilAutoPayType = !empty($_POST['auto_pay_type']) ? $_POST['auto_pay_type'] : null;
                $utilAutoPayBank = '';
                $utilAutoPaySourceTitle = '';

                $stmtUtil = $pdo->prepare("
                    INSERT INTO subscription_utility_details
                    (subscription_id, company_name, subscriber_no, meter_no, installation_no, billing_cut_day, due_day, is_auto_pay, auto_pay_type, auto_pay_bank, auto_pay_source_title)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtUtil->execute([
                    $subscriptionId, $companyName, $subscriberNo, $meterNo, $installationNo, $billingCutDay, $dueDay,
                    $isUtilAutoPay, $utilAutoPayType, $utilAutoPayBank, $utilAutoPaySourceTitle
                ]);
            } elseif ($categoryType === 'telecom') {
                $operatorName = trim($_POST['operator_name'] ?? '');
                $serviceType = $_POST['service_type'] ?? 'mobile';
                $phoneOrLine = trim($_POST['phone_or_line_no'] ?? '');
                $speedQuota = trim($_POST['speed_or_quota'] ?? '');
                $commStart = !empty($_POST['commitment_start']) ? $_POST['commitment_start'] : null;
                $commEnd = !empty($_POST['commitment_end']) ? $_POST['commitment_end'] : null;
                $postPrice = !empty($_POST['post_commitment_price']) ? (float)str_replace(',', '.', $_POST['post_commitment_price']) : null;
                $deviceFee = !empty($_POST['device_rental_fee']) ? (float)str_replace(',', '.', $_POST['device_rental_fee']) : 0.00;
                $isTelAutoPay = !empty($_POST['is_auto_pay']) ? 1 : 0;
                $telAutoPayType = !empty($_POST['auto_pay_type']) ? $_POST['auto_pay_type'] : null;
                $telAutoPayBank = '';
                $telAutoPaySourceTitle = '';

                $stmtTel = $pdo->prepare("
                    INSERT INTO subscription_telecom_details
                    (subscription_id, operator_name, service_type, phone_or_line_no, speed_or_quota, commitment_start, commitment_end, post_commitment_price, device_rental_fee, is_auto_pay, auto_pay_type, auto_pay_bank, auto_pay_source_title)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtTel->execute([
                    $subscriptionId, $operatorName, $serviceType, $phoneOrLine, $speedQuota, $commStart, $commEnd, $postPrice, $deviceFee,
                    $isTelAutoPay, $telAutoPayType, $telAutoPayBank, $telAutoPaySourceTitle
                ]);
            } elseif ($categoryType === 'insurance') {
                $insType = $_POST['ins_type'] ?? 'insurance';
                $insCompany = trim($_POST['ins_company'] ?? '');
                $insPolicyNo = trim($_POST['ins_policy_no'] ?? '');
                $insItem = trim($_POST['ins_item'] ?? '');
                $insAgencyName = trim($_POST['ins_agency_name'] ?? '');
                $insAgencyPhone = trim($_POST['ins_agency_phone'] ?? '');
                $insStart = !empty($_POST['ins_start_date']) ? $_POST['ins_start_date'] : null;
                $insEnd = !empty($_POST['ins_end_date']) ? $_POST['ins_end_date'] : null;
                $insPayType = $_POST['ins_payment_type'] ?? 'single';
                $insInstallCount = (int)($_POST['ins_installment_count'] ?? 1);
                $insInstallAmount = !empty($_POST['ins_installment_amount']) ? (float)str_replace(',', '.', $_POST['ins_installment_amount']) : null;
                if ($insPayType === 'installment' && empty($insInstallAmount) && $price > 0 && $insInstallCount > 0) {
                    $insInstallAmount = round($price / $insInstallCount, 2);
                }

                $stmtIns = $pdo->prepare("
                    INSERT INTO subscription_insurance_details
                    (subscription_id, insurance_type, company_name, policy_no, insured_item, agency_name, agency_phone, policy_start_date, policy_end_date, payment_type, installment_count, installment_amount)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([
                    $subscriptionId, $insType, $insCompany, $insPolicyNo, $insItem, $insAgencyName, $insAgencyPhone, $insStart, $insEnd, $insPayType, $insInstallCount, $insInstallAmount
                ]);
            } elseif ($categoryType === 'bes') {
                $besCompany = trim($_POST['bes_company'] ?? '');
                $besContractNo = trim($_POST['bes_contract_no'] ?? '');
                $besPaymentDay = !empty($_POST['bes_payment_day']) ? (int)$_POST['bes_payment_day'] : null;
                $besOwnerRelation = trim($_POST['bes_owner_relation'] ?? 'Kendim');
                $besAutoPaySource = trim($_POST['bes_auto_pay_source'] ?? '');

                $stmtBes = $pdo->prepare("
                    INSERT INTO subscription_bes_details
                    (subscription_id, company_name, contract_no, payment_day, owner_relation, auto_pay_source)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmtBes->execute([
                    $subscriptionId, $besCompany, $besContractNo, $besPaymentDay, $besOwnerRelation, $besAutoPaySource
                ]);
            }

            // Bilgileri görüntüleme / şifre izni verilen üyeleri subscription_access tablosuna kaydet (Tüm kategoriler için)
            if (!empty($_POST['allowed_users']) && is_array($_POST['allowed_users'])) {
                $stmtAccess = $pdo->prepare("INSERT IGNORE INTO subscription_access (subscription_id, user_id, can_view_credentials) VALUES (?, ?, 1)");
                foreach ($_POST['allowed_users'] as $allowedUid) {
                    $allowedUid = (int)$allowedUid;
                    if ($allowedUid > 0 && $allowedUid !== $userId) {
                        $stmtAccess->execute([$subscriptionId, $allowedUid]);
                    }
                }
            }

            $pdo->commit();
            sistemLogla("Yeni Abonelik Eklendi: {$title} (ID: {$subscriptionId})", 'INFO');

            if (!headers_sent()) {
                header("Location: abonelikler.php?msg=added");
            } else {
                echo "<script>window.location.href='abonelikler.php?msg=added';</script>";
            }
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sistemLogla("Abonelik ekleme hatası: " . $e->getMessage(), 'ERROR');
            $error = 'Abonelik kaydedilirken bir hata oluştu: ' . $e->getMessage();
        }
    }
}

$pageTitle = "Yeni Abonelik Ekle - Abonelik Takip";
require_once __DIR__ . '/header.php';
?>

<div class="max-w-4xl mx-auto">
    
    <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-200 dark:border-slate-800">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">Yeni Abonelik / Gider Ekle</h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">Dijital hesap, ev faturası veya telekomünikasyon paketinizi sisteme tanımlayın.</p>
        </div>
        <a href="abonelikler.php" class="px-3.5 py-1.5 bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 text-xs font-semibold rounded-xl transition shadow-sm">
            ← Listeye Dön
        </a>
    </div>

    <?php if ($error): ?>
        <div class="mb-6 p-4 bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-500/20 rounded-2xl text-rose-600 dark:text-rose-400 text-sm flex items-center gap-2">
            <span>⚠</span>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="space-y-6" id="subForm">
        <?= csrfAlaniniEkle() ?>

        <!-- 1. Temel Bilgiler Kartı -->
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-4">
            <h2 class="text-xs font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400">1. Temel Abonelik Bilgileri</h2>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Konum / Kapsam</label>
                    <?php 
                    $sessionAktifMekanId = $_SESSION['aktif_konum_id'] ?? ($_SESSION['secili_mekan_id'] ?? ($_SESSION['location_id'] ?? null));
                    $aktifMekanObj = null;
                    if (!empty($sessionAktifMekanId)) {
                        foreach ($locations as $l) {
                            if ((string)$l['id'] === (string)$sessionAktifMekanId) {
                                $aktifMekanObj = $l;
                                break;
                            }
                        }
                    }
                    ?>
                    <?php if ($aktifMekanObj): ?>
                        <input type="hidden" name="location_id" id="location_id_select" value="<?= $aktifMekanObj['id'] ?>">
                        <div class="w-full p-3 bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl text-slate-900 dark:text-white text-sm flex items-center justify-between">
                            <span class="flex items-center gap-2 font-medium">
                                <span>📍 <?= htmlspecialchars($aktifMekanObj['name']) ?></span>
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 font-semibold">Aktif Mekan</span>
                            </span>
                            <span class="text-xs text-slate-400 dark:text-slate-500 font-mono flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                                <span>Kilitli</span>
                            </span>
                        </div>
                    <?php else: ?>
                        <select name="location_id" id="location_id_select" class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            <option value="">-- Genel / Tüm Konumlar --</option>
                            <?php 
                            $postLocId = $_POST['location_id'] ?? null;
                            foreach ($locations as $loc): 
                                $isSelected = ($postLocId !== null && (string)$postLocId === (string)$loc['id']);
                            ?>
                                <option value="<?= $loc['id'] ?>" <?= $isSelected ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($loc['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="bg-slate-50 dark:bg-slate-900/50 p-3 rounded-xl border border-slate-200 dark:border-slate-700/50">
                    <label class="flex items-center gap-2 cursor-pointer mb-2">
                        <input type="checkbox" name="is_auto_pay" id="is_auto_pay_check" value="1" <?= !empty($_POST['is_auto_pay']) ? 'checked' : '' ?> class="w-4 h-4 text-blue-600 bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-700 rounded focus:ring-blue-500">
                        <span class="text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Otomatik Ödeme Var mı?</span>
                    </label>

                    <div id="auto_pay_details" class="<?= !empty($_POST['is_auto_pay']) ? '' : 'hidden' ?> grid grid-cols-1 gap-3 mt-3">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1">Ödeme Aracı Türü</label>
                            <select name="auto_pay_type" id="auto_pay_type" class="w-full p-2.5 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                                <option value="credit_card" <?= (($_POST['auto_pay_type'] ?? '') === 'credit_card') ? 'selected' : '' ?>>Kredi Kartı</option>
                                <option value="bank_account" <?= (($_POST['auto_pay_type'] ?? '') === 'bank_account') ? 'selected' : '' ?>>Banka Hesabı (Vadesiz / Otomatik Talimat)</option>
                            </select>
                        </div>
                        
                        <!-- Kredi Karti Secimi -->
                        <div id="auto_pay_cc_wrapper">
                            <div class="flex items-center justify-between mb-1">
                                <label class="text-[11px] font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Kredi Kartı Seçin</label>
                                <div class="flex items-center gap-2">
                                    <button type="button" onclick="kartListesiniYenile('card')" class="text-[10px] text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white transition flex items-center gap-0.5" title="Listeyi Yenile">
                                        <span>↺ Yenile</span>
                                    </button>
                                    <a href="kredi-kartlari.php?action=new_card" target="_blank" class="text-[11px] font-bold text-blue-600 dark:text-blue-400 hover:underline transition flex items-center gap-0.5">
                                        <span>+ Yeni Kart Ekle</span>
                                    </a>
                                </div>
                            </div>
                            <select name="credit_card_id" id="credit_card_select" class="w-full p-2.5 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                                <option value="">-- Kredi Kartı Seçin --</option>
                                <?php foreach ($creditCards as $cc): ?>
                                    <option value="<?= $cc['id'] ?>" <?= (($_POST['credit_card_id'] ?? '') == $cc['id']) ? 'selected' : '' ?>>
                                        💳 <?= htmlspecialchars($cc['bank_name']) ?> - <?= htmlspecialchars($cc['card_name']) ?> (&bull;&bull;&bull;&bull; <?= $cc['card_last_4'] ?: '0000' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="no_card_warning" class="mt-1.5 p-2 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-lg text-[11px] text-amber-700 dark:text-amber-400 <?= empty($creditCards) ? 'flex' : 'hidden' ?> items-center justify-between">
                                <span>Henüz kayıtlı kredi kartınız yok.</span>
                                <a href="kredi-kartlari.php?action=new_card" target="_blank" class="underline font-bold text-amber-600 dark:text-amber-300 hover:text-amber-800">Kart Ekle &rarr;</a>
                            </div>
                        </div>

                        <!-- Banka Hesabi Secimi -->
                        <div id="auto_pay_bank_wrapper" class="hidden">
                            <div class="flex items-center justify-between mb-1">
                                <label class="text-[11px] font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Banka Hesabı Seçin</label>
                                <div class="flex items-center gap-2">
                                    <button type="button" onclick="kartListesiniYenile('bank')" class="text-[10px] text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white transition flex items-center gap-0.5" title="Listeyi Yenile">
                                        <span>↺ Yenile</span>
                                    </button>
                                    <a href="kredi-kartlari.php?tab=bank&action=new_bank" target="_blank" class="text-[11px] font-bold text-emerald-600 dark:text-emerald-400 hover:underline transition flex items-center gap-0.5">
                                        <span>+ Yeni Hesap Ekle</span>
                                    </a>
                                </div>
                            </div>
                            <select name="payment_method_id" id="bank_account_select" class="w-full p-2.5 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                                <option value="">-- Banka Hesabı Seçin --</option>
                                <?php foreach ($paymentMethods as $pm): ?>
                                    <option value="<?= $pm['id'] ?>" <?= (($_POST['payment_method_id'] ?? '') == $pm['id']) ? 'selected' : '' ?>>
                                        🏦 <?= htmlspecialchars($pm['title']) ?> <?= $pm['bank_name'] ? '('.htmlspecialchars($pm['bank_name']).')' : '' ?> <?= $pm['card_last_4'] ? '(&bull;&bull;&bull;&bull; '.$pm['card_last_4'].')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="no_bank_warning" class="mt-1.5 p-2 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-lg text-[11px] text-amber-700 dark:text-amber-400 <?= empty($paymentMethods) ? 'flex' : 'hidden' ?> items-center justify-between">
                                <span>Henüz kayıtlı banka hesabınız yok.</span>
                                <a href="kredi-kartlari.php?tab=bank&action=new_bank" target="_blank" class="underline font-bold text-amber-600 dark:text-amber-300 hover:text-amber-800">Hesap Ekle &rarr;</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Kategori Seçin *</label>
                    <select name="category_id" id="category_id" required
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="">-- Kategori Seçin --</option>
                        <?php
                        $aktifMekanId = $_POST['location_id'] ?? ($_SESSION['aktif_konum_id'] ?? ($_SESSION['secili_mekan_id'] ?? ($_SESSION['location_id'] ?? null)));
                        $allowedTypes = mekanKategoriTurleri($aktifMekanId);

                        $groupTitlesMap = [];
                        try {
                            $catTypesDb = $pdo->query("SELECT type_key, name, icon FROM category_types ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($catTypesDb as $ct) {
                                $icn = !empty($ct['icon']) ? $ct['icon'] . ' ' : '';
                                $groupTitlesMap[$ct['type_key']] = $icn . $ct['name'];
                            }
                        } catch (Exception $e) {}

                        if (empty($groupTitlesMap)) {
                            $groupTitlesMap = [
                                'utility'   => '⚡ Ev / Fiziksel Fatura (Elektrik, Su, Doğalgaz, Aidat)',
                                'telecom'   => '📶 Ev / Telekom (Ev İnterneti, TV, Telefon, Mobil)',
                                'digital'   => '💻 Dijital Abonelik (Netflix, Spotify, Bulut, AI)',
                                'insurance' => '🛡️ Sigorta & Poliçe (TSS, ÖSS, Hayat, Kasko)',
                                'bes'       => '📈 Bireysel Emeklilik (BES & OKS)',
                                'vehicle'   => '🚗 Araç & Taşıt (Muayene, MTV, Bakım, HGS)',
                                'tax'       => '💰 Vergi & Harç (MTV, Gelir, KDV, Emlak)',
                                'general'   => 'Genel Abonelikler'
                            ];
                        }
                        $groupedCats = [];
                        foreach ($categories as $cat) {
                            $t = !empty($cat['type']) ? $cat['type'] : 'general';
                            $isMotorlu = (strpos(mb_strtolower($cat['name'], 'UTF-8'), 'motorlu') !== false || strpos(mb_strtolower($cat['slug'] ?? '', 'UTF-8'), 'motorlu') !== false || strpos(mb_strtolower($cat['slug'] ?? '', 'UTF-8'), 'mtv') !== false);
                            if (!$isMotorlu && ($t === 'vergi' || strpos(mb_strtolower($cat['name'], 'UTF-8'), 'vergi') !== false || strpos(mb_strtolower($cat['slug'] ?? '', 'UTF-8'), 'vergi') !== false || strpos(mb_strtolower($cat['name'], 'UTF-8'), 'maliye') !== false || $cat['name'] === 'GSS' || mb_strtolower($cat['slug'] ?? '', 'UTF-8') === 'gss')) {
                                $t = 'tax';
                            }
                            $isTaxAllowed = in_array('tax', $allowedTypes) || in_array('vergi', $allowedTypes);
                            if (!empty($allowedTypes) && !in_array($t, $allowedTypes) && !($t === 'tax' && $isTaxAllowed)) {
                                continue;
                            }
                            $grp = $groupTitlesMap[$t] ?? 'Diğer Abonelikler';
                            $groupedCats[$grp][] = $cat;
                        }
                        $selectedCatId = (int)($_POST['category_id'] ?? 0);
                        foreach ($groupedCats as $grpTitle => $cats): ?>
                            <optgroup label="<?= htmlspecialchars($grpTitle) ?>">
                                <?php foreach ($cats as $c): ?>
                                    <option value="<?= $c['id'] ?>" data-type="<?= htmlspecialchars(!empty($c['type']) && $c['type'] !== 'general' ? $c['type'] : 'tax') ?>" <?= ($selectedCatId == $c['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Abonelik / Kurum Başlığı *</label>
                    <input type="text" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required placeholder="Örn: Netflix, İSKİ Su, Turkcell Fiber"
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label id="main_price_label" class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Tutar *</label>
                    <input type="text" name="price" value="<?= htmlspecialchars($_POST['price'] ?? '') ?>" required placeholder="0.00"
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider mb-1.5 flex items-center gap-1">
                        <span>🎁</span>
                        <span>Nakit İade / İndirim</span>
                    </label>
                    <input type="text" name="cashback_amount" value="<?= htmlspecialchars($_POST['cashback_amount'] ?? '') ?>" placeholder="0.00"
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-emerald-200 dark:border-emerald-500/30 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-emerald-500 outline-none" title="Varsa kart nakit iadesi (cashback) veya ekstre indirimi">
                    <span class="text-[10px] text-slate-400 dark:text-slate-500 mt-1 block">Aylık net bütçeden düşülür</span>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Para Birimi</label>
                    <select name="currency" class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="TRY" <?= (($_POST['currency'] ?? 'TRY') === 'TRY') ? 'selected' : '' ?>>₺ TRY (Türk Lirası)</option>
                        <option value="USD" <?= (($_POST['currency'] ?? '') === 'USD') ? 'selected' : '' ?>>$ USD (Dolar)</option>
                        <option value="EUR" <?= (($_POST['currency'] ?? '') === 'EUR') ? 'selected' : '' ?>>€ EUR (Euro)</option>
                        <option value="GBP" <?= (($_POST['currency'] ?? '') === 'GBP') ? 'selected' : '' ?>>£ GBP (Sterlin)</option>
                    </select>
                </div>
                <div>
                    <label id="billing_cycle_label" class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Ödeme Aralığı</label>
                    <select name="billing_cycle" id="billing_cycle_select" class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="monthly" <?= (($_POST['billing_cycle'] ?? 'monthly') === 'monthly') ? 'selected' : '' ?>>Aylık (Her Ay)</option>
                        <option value="annual" <?= (($_POST['billing_cycle'] ?? '') === 'annual') ? 'selected' : '' ?>>Yıllık (Senede Bir / Peşin)</option>
                        <option value="quarterly" <?= (($_POST['billing_cycle'] ?? '') === 'quarterly') ? 'selected' : '' ?>>3 Aylık</option>
                        <option value="semi_annual" <?= (($_POST['billing_cycle'] ?? '') === 'semi_annual') ? 'selected' : '' ?>>6 Aylık</option>
                        <option value="one_time" <?= (($_POST['billing_cycle'] ?? '') === 'one_time') ? 'selected' : '' ?>>Tek Seferlik</option>
                    </select>
                    <div id="annual_cycle_hint" class="text-[10px] text-blue-600 dark:text-blue-400 mt-1 <?= (($_POST['billing_cycle'] ?? '') === 'annual') ? '' : 'hidden' ?>">
                        💡 Yıllık peşin ödemelerde girilen tutar 12'ye bölünerek aylık bütçenize maliyet olarak yansıtılır.
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold text-blue-600 dark:text-blue-400 uppercase tracking-wider mb-1.5">Ödemeyi Yapan Kişi (Sorumlu) *</label>
                    <select name="payer_user_id" required class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <?php foreach ($allUsers as $u): 
                            $isMe = ($u['id'] == $userId);
                            $isSelected = (($_POST['payer_user_id'] ?? $userId) == $u['id']);
                        ?>
                            <option value="<?= $u['id'] ?>" <?= $isSelected ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?><?= $isMe ? ' (Ben)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label id="next_payment_date_label" class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Sonraki Ödeme Tarihi</label>
                    <input type="date" name="next_payment_date" id="next_payment_date_input" value="<?= htmlspecialchars($_POST['next_payment_date'] ?? date('Y-m-d', strtotime('+1 month'))) ?>"
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    <span id="next_payment_date_hint" class="text-[10px] text-slate-400 mt-1 hidden">Taksitli poliçelerde aylık kart taksit günüdür. Poliçenin asıl yenileme tarihi aşağıda belirtilmektedir.</span>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Paket / Plan Detayı</label>
                    <input type="text" name="plan_tier" id="plan_tier" value="<?= htmlspecialchars($_POST['plan_tier'] ?? '') ?>" placeholder="Örn: 1000 Mbps Fiber, 4K Ultra HD, Aile Paketi"
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
            </div>

            <!-- Deneme Sürümü Mü? (Yalnızca Dijital Aboneliklerde Görünür) -->
            <div id="trial_wrapper" class="hidden pt-2 border-t border-slate-100 dark:border-slate-800/80">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input type="checkbox" name="is_trial" id="is_trial" value="1" <?= !empty($_POST['is_trial']) ? 'checked' : '' ?>
                        class="w-4 h-4 rounded bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-purple-600 focus:ring-0">
                    <span class="text-xs font-bold text-purple-600 dark:text-purple-400">Ücretsiz Deneme Sürümü (Free Trial) Var mı?</span>
                </label>
                <div id="trial_fields" class="mt-3 <?= !empty($_POST['is_trial']) ? '' : 'hidden' ?> p-4 bg-purple-500/10 border border-purple-500/20 rounded-2xl">
                    <label class="block text-xs font-bold text-purple-600 dark:text-purple-400 uppercase tracking-wider mb-1.5">Deneme Sürümü Bitiş Tarihi *</label>
                    <input type="date" name="trial_end_date" value="<?= htmlspecialchars($_POST['trial_end_date'] ?? date('Y-m-d', strtotime('+7 days'))) ?>"
                        class="w-full sm:w-1/2 p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                    <p class="text-[11px] text-slate-400 mt-1.5 flex items-center gap-1">
                        <span>⏰</span>
                        <span>Bu tarihten 3 gün ve 1 gün önce iptal etmeniz için size otomatik e-posta uyarısı gider.</span>
                    </p>
                </div>
            </div>
        </div>

        <!-- Kategoriye Özel Dinamik Form Bileşenleri -->
        <?php 
        $compDir = __DIR__ . '/components';
        if (file_exists($compDir . '/form-digital.php')) require $compDir . '/form-digital.php';
        if (file_exists($compDir . '/form-utility.php')) require $compDir . '/form-utility.php';
        if (file_exists($compDir . '/form-telecom.php')) require $compDir . '/form-telecom.php';
        if (file_exists($compDir . '/form-insurance.php')) require $compDir . '/form-insurance.php';
        if (file_exists($compDir . '/form-bes.php')) require $compDir . '/form-bes.php';
        ?>

        <!-- 6. Bilgileri Görüntüleme ve Erişim İzni (Tüm Kategoriler) -->
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
                $allowedList = $_POST['allowed_users'] ?? [];
                foreach ($allUsers as $u): 
                    if ($u['id'] == $userId) continue;
                    $otherUsersCount++;
                    $isAllowed = in_array($u['id'], $allowedList);
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
        <!-- 7. Notlar & Belge Eki & Kaydet Butonu -->
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-sm space-y-4">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 flex items-center gap-2">
                <span>📎 Poliçe / Sözleşme / Fatura Belgesi & Ek Notlar</span>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5 flex items-center justify-between">
                        <span>Poliçe / Sözleşme Dosyası (PDF veya Görsel)</span>
                        <span class="text-[10px] text-slate-400 font-normal">Maks. 10 MB</span>
                    </label>
                    <div class="relative border-2 border-dashed border-slate-200 dark:border-slate-800 hover:border-blue-500 dark:hover:border-blue-500 rounded-2xl p-4 text-center transition group bg-slate-50/50 dark:bg-slate-950/50">
                        <input type="file" name="document" id="doc_file_input" accept=".pdf,.jpg,.jpeg,.png,.webp" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                        <div class="flex flex-col items-center justify-center gap-1.5 pointer-events-none">
                            <span class="text-2xl group-hover:scale-110 transition-transform">📄</span>
                            <span id="doc_file_label" class="text-xs font-semibold text-slate-700 dark:text-slate-300">Belge seçin veya buraya sürükleyin</span>
                            <span class="text-[10px] text-slate-400">PDF, JPG, PNG veya WEBP formatı</span>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Ek Notlar / Açıklama</label>
                    <textarea name="notes" rows="3" placeholder="Örn: İptal etmeyi unutma, sanal kart tanımlı, ortak kullanımda vs."
                        class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none resize-none"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <button type="submit"
                class="w-full py-4 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white rounded-2xl font-bold text-sm transition shadow-xl shadow-blue-600/30 flex items-center justify-center gap-2">
                <span>✓ Kaydet</span>
            </button>
        </div>

    </form>

</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Otomatik Ödeme Mantığı
    const check = document.getElementById('is_auto_pay_check');
    const details = document.getElementById('auto_pay_details');
    const typeSel = document.getElementById('auto_pay_type');
    const ccWrap = document.getElementById('auto_pay_cc_wrapper');
    const bankWrap = document.getElementById('auto_pay_bank_wrapper');
    const ccSel = document.getElementById('credit_card_select');
    const bankSel = document.getElementById('bank_account_select');

    if(check) {
        check.addEventListener('change', function() {
            if(this.checked) {
                details.classList.remove('hidden');
            } else {
                details.classList.add('hidden');
                if (ccSel) ccSel.value = '';
                if (bankSel) bankSel.value = '';
            }
        });
        if(typeSel) {
            typeSel.addEventListener('change', function() {
                if(this.value === 'credit_card') {
                    if (ccWrap) ccWrap.classList.remove('hidden');
                    if (bankWrap) bankWrap.classList.add('hidden');
                    if (bankSel) bankSel.value = '';
                } else {
                    if (bankWrap) bankWrap.classList.remove('hidden');
                    if (ccWrap) ccWrap.classList.add('hidden');
                    if (ccSel) ccSel.value = '';
                }
            });
        }
    }

    // 2. Mekan -> Kategori Dinamik Filtreleme (Yerel ve Anlık)
    const locSelect = document.getElementById('location_id_select');
    const catSelect = document.getElementById('category_id');
    if (!locSelect || !catSelect) return;

    const allCategories = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?>;
    const allLocations = <?= json_encode($locations, JSON_UNESCAPED_UNICODE) ?>;

    const groupTitles = <?= json_encode($groupTitlesMap, JSON_UNESCAPED_UNICODE) ?>;

    function getMekanTypes(locId) {
        const allKeys = Object.keys(groupTitles);
        const fallbackTypes = allKeys.length > 0 ? allKeys : ['digital', 'utility', 'telecom', 'insurance', 'bes', 'vehicle', 'general', 'tax'];
        if (!locId) return fallbackTypes;
        const loc = allLocations.find(l => String(l.id) === String(locId));
        if (!loc) return fallbackTypes;

        const icon = (loc.icon || '').toLowerCase();
        const normName = (loc.name || '')
            .replace(/İ/g, 'i').replace(/I/g, 'ı')
            .replace(/ı/g, 'i')
            .toLowerCase();

        // 0. Vergi / Maliye / Harç / KDV (İkon veya Mekan Adı)
        if (icon === 'tax' || icon === '💰' || /vergi|maliye|harc|harç|kdv|stopaj|tax/.test(normName)) {
            return ['tax', 'vergi'];
        }

        // 1. Ev / Konut / Bina / Güneş (İstanbul, Sakarya vb. tüm ev ve ofis mekanları) -> SADECE Ev Faturaları & Ev İnterneti
        if (['home', 'building', 'sun', '🏠', '🏡', '🏢', '🏖️'].includes(icon) || 
            /ev|konut|daire|apartman|istanbul|sakarya|ofis|yazlik|yazlık/.test(normName)) {
            return ['utility', 'telecom'];
        }

        // 2. Dijital / Bulut -> SADECE Dijital Abonelikler
        if (['globe', 'cloud', '💻', '🌐', '☁️'].includes(icon) || /dijital|digital|online|bulut/.test(normName)) {
            return ['digital'];
        }

        // 3. Sigorta / Güvence -> SADECE Sigorta & Poliçeler
        if (['shield', '🛡️', '🛡'].includes(icon) || /sigorta|kasko|police|poliçe|dask/.test(normName)) {
            return ['insurance'];
        }

        // 4. BES / Finans -> SADECE Bireysel Emeklilik (ve mekan adı vergi değilse)
        if ((['chart', 'trending-up', '📈', '📊'].includes(icon) && !/vergi|tax/.test(normName)) || /bes|emeklilik|fon/.test(normName)) {
            return ['bes'];
        }

        // 5. Araç / Taşıt -> SADECE Araç Giderleri
        if (['car', '🚗', '🚙', '🚘'].includes(icon) || /arac|araç|araba|tasit|taşıt/.test(normName)) {
            return ['vehicle'];
        }

        return fallbackTypes;
    }

    function kategorileriYukle(targetCatId) {
        const locId = locSelect.value || '';
        const prevCatVal = targetCatId !== undefined ? targetCatId : catSelect.value;
        const allowedTypes = getMekanTypes(locId);

        const filtered = allCategories.filter(c => {
            let catType = c.type || 'general';
            const isMot = /motorlu|mtv/.test((c.name || '').toLowerCase()) || /motorlu|mtv/.test((c.slug || '').toLowerCase());
            if (!isMot && (catType === 'vergi' || /vergi|maliye|gss/.test((c.name || '').toLowerCase()) || /vergi|maliye|gss/.test((c.slug || '').toLowerCase()))) {
                catType = 'tax';
            }
            if ((allowedTypes.includes('tax') || allowedTypes.includes('vergi')) && catType === 'tax') return true;
            return allowedTypes.includes(catType);
        });

        catSelect.innerHTML = '<option value="">-- Kategori Seçin --</option>';

        const groups = {};
        filtered.forEach(c => {
            let catType = c.type || 'general';
            const isMot = /motorlu|mtv/.test((c.name || '').toLowerCase()) || /motorlu|mtv/.test((c.slug || '').toLowerCase());
            if (!isMot && (catType === 'vergi' || /vergi|maliye|gss/.test((c.name || '').toLowerCase()) || /vergi|maliye|gss/.test((c.slug || '').toLowerCase()))) {
                catType = 'tax';
            }
            const grp = groupTitles[catType] || 'Diğer Abonelikler';
            if (!groups[grp]) groups[grp] = [];
            groups[grp].push({ ...c, resolvedType: catType });
        });

        let matched = false;
        for (const [groupLabel, cats] of Object.entries(groups)) {
            const optgroup = document.createElement('optgroup');
            optgroup.label = groupLabel;
            cats.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.setAttribute('data-type', c.resolvedType || c.type);
                opt.textContent = c.name;
                if (prevCatVal && String(prevCatVal) === String(c.id)) {
                    opt.selected = true;
                    matched = true;
                }
                optgroup.appendChild(opt);
            });
            catSelect.appendChild(optgroup);
        }

        if (!matched && !prevCatVal && locId && filtered.length === 1) {
            catSelect.value = filtered[0].id;
        } else if (!matched && prevCatVal) {
            const stillExists = Array.from(catSelect.options).some(o => String(o.value) === String(prevCatVal));
            if (!stillExists) {
                catSelect.value = '';
            } else {
                catSelect.value = prevCatVal;
            }
        }

        guncelleKategoriDetaylari();
    }

    // 3. Kategori Tipine Göre Dinamik Bölümleri Aç / Kapat
    function guncelleKategoriDetaylari() {
        const selectedOpt = catSelect.options[catSelect.selectedIndex];
        const catType = selectedOpt ? (selectedOpt.getAttribute('data-type') || '') : '';
        const catText = selectedOpt ? selectedOpt.textContent.trim() : '';

        const secDigital = document.getElementById('section_digital');
        const secUtility = document.getElementById('section_utility');
        const secTelecom = document.getElementById('section_telecom');
        const secInsurance = document.getElementById('section_insurance');
        const secBes = document.getElementById('section_bes');

        if (secDigital) secDigital.classList.toggle('hidden', catType !== 'digital');
        if (secUtility) secUtility.classList.toggle('hidden', catType !== 'utility');
        if (secTelecom) secTelecom.classList.toggle('hidden', catType !== 'telecom');
        if (secInsurance) secInsurance.classList.toggle('hidden', catType !== 'insurance');
        if (secBes) secBes.classList.toggle('hidden', catType !== 'bes');

        // Apartman / Site Aidatı Mantığı
        if (catType === 'utility') {
            const isAidat = /aidat/i.test(catText);
            const utilCompLabel = document.getElementById('util_company_label');
            const utilCompInput = document.getElementById('util_company_input');
            const utilSubLabel = document.getElementById('util_subno_label');
            const utilSubInput = document.getElementById('util_subno_input');
            const utilMeterWrap = document.getElementById('util_meter_wrap');
            const utilCutdayWrap = document.getElementById('util_cutday_wrap');
            const utilDuedayLabel = document.getElementById('util_dueday_label');
            const utilDuedayDesc = document.getElementById('util_dueday_desc');

            if (isAidat) {
                if (utilCompLabel) utilCompLabel.textContent = 'Apartman / Site Adı *';
                if (utilCompInput) utilCompInput.placeholder = 'Örn: Güneş Sitesi A Blok';
                if (utilSubLabel) utilSubLabel.textContent = 'Daire No / Blok';
                if (utilSubInput) utilSubInput.placeholder = 'Örn: Daire 12 veya A Blok No:4';
                if (utilMeterWrap) utilMeterWrap.classList.add('hidden');
                if (utilCutdayWrap) utilCutdayWrap.classList.add('hidden');
                if (utilDuedayLabel) utilDuedayLabel.textContent = 'Aidat Ödeme Günü (Ayın Kaçı?)';
                if (utilDuedayDesc) utilDuedayDesc.textContent = 'Aidatın her ay ödenmesi gereken günü (1-31)';
            } else {
                if (utilCompLabel) utilCompLabel.textContent = 'Firma / Kurum Adı';
                if (utilCompInput) utilCompInput.placeholder = 'Örn: İSKİ, İGDAŞ, BEDAŞ';
                if (utilSubLabel) utilSubLabel.textContent = 'Abone / Sözleşme No';
                if (utilSubInput) utilSubInput.placeholder = 'Örn: 10492819';
                if (utilMeterWrap) utilMeterWrap.classList.remove('hidden');
                if (utilCutdayWrap) utilCutdayWrap.classList.remove('hidden');
                if (utilDuedayLabel) utilDuedayLabel.textContent = 'Fatura Son Ödeme Günü (Ayın Kaçı?)';
                if (utilDuedayDesc) utilDuedayDesc.textContent = 'Örn: Her ayın 20\'si son ödeme günüyse 20 yazın.';
            }
        }

        // Deneme sürümü kontrolü (Yalnızca Digital seçildiğinde görünür)
        const trialWrapper = document.getElementById('trial_wrapper');
        const trialCheck = document.getElementById('is_trial');
        const trialFields = document.getElementById('trial_fields');
        if (trialWrapper) {
            const isDigital = (catType === 'digital');
            trialWrapper.classList.toggle('hidden', !isDigital);
            if (!isDigital) {
                if (trialCheck) trialCheck.checked = false;
                if (trialFields) trialFields.classList.add('hidden');
            }
        }

        // Sigorta Alanı Başlık Güncellemesi
        const nextPayLabel = document.getElementById('next_payment_date_label');
        const nextPayHint = document.getElementById('next_payment_date_hint');
        if (nextPayLabel) {
            if (catType === 'insurance') {
                const insPayTypeVal = document.getElementById('ins_payment_type') ? document.getElementById('ins_payment_type').value : 'single';
                if (insPayTypeVal === 'installment') {
                    nextPayLabel.textContent = 'Sonraki Aylık Taksit Tarihi';
                    if (nextPayHint) nextPayHint.classList.remove('hidden');
                } else {
                    nextPayLabel.textContent = 'Poliçe Yenileme Tarihi';
                    if (nextPayHint) nextPayHint.classList.add('hidden');
                }
            } else {
                nextPayLabel.textContent = 'Sonraki Ödeme Tarihi';
                if (nextPayHint) nextPayHint.classList.add('hidden');
            }
        }

        // Telecom & GSM Modem Kontrolü
        if (catType === 'telecom') {
            guncelleTelecomDetaylari();
        }
    }
    window.guncelleKategoriDetaylari = guncelleKategoriDetaylari;
    catSelect.addEventListener('change', guncelleKategoriDetaylari);

    const billingSelect = document.getElementById('billing_cycle_select');
    const annualHint = document.getElementById('annual_cycle_hint');
    if (billingSelect && annualHint) {
        billingSelect.addEventListener('change', function() {
            annualHint.classList.toggle('hidden', this.value !== 'annual');
        });
    }

    // Mobil Hat & GSM Modem Kira Bedeli Gizleme
    function guncelleTelecomDetaylari() {
        const selectedOpt = catSelect.options[catSelect.selectedIndex];
        const catText = selectedOpt ? selectedOpt.textContent.trim() : '';
        const serviceTypeSel = document.getElementById('tel_service_type');
        const serviceType = serviceTypeSel ? serviceTypeSel.value : '';
        const rentalWrap = document.getElementById('device_rental_fee_wrapper');
        const rentalInput = document.getElementById('device_rental_fee_input');

        const isMobile = serviceType === 'mobile' || /mobil|gsm/i.test(catText);
        if (rentalWrap) {
            if (isMobile) {
                rentalWrap.classList.add('hidden');
                if (rentalInput) rentalInput.value = '0.00';
            } else {
                rentalWrap.classList.remove('hidden');
            }
        }
    }
    const telServiceType = document.getElementById('tel_service_type');
    if (telServiceType) {
        telServiceType.addEventListener('change', guncelleTelecomDetaylari);
    }

    // Sigorta Taksitli Ödeme Yönetimi ve Tutar Senkronizasyonu
    const insPayType = document.getElementById('ins_payment_type');
    const insInstFields = document.getElementById('ins_installment_fields');
    const insInstCount = document.getElementById('ins_installment_count');
    const insInstAmount = document.getElementById('ins_installment_amount');
    const insCalcBtn = document.getElementById('ins_installment_calc_btn');
    const priceInput = document.querySelector('input[name="price"]');

    function hesaplaTaksitTutari() {
        if (!insInstCount || !insInstAmount) return;
        const count = parseInt(insInstCount.value) || 1;
        const priceVal = priceInput ? parseFloat(priceInput.value.replace(',', '.')) : 0;
        if (priceVal > 0 && count > 0) {
            insInstAmount.value = (priceVal / count).toFixed(2);
        }
    }

    // Taksit tutarından toplam poliçe tutarını hesapla
    function hesaplaToplamTutari() {
        if (!insInstCount || !insInstAmount || !priceInput) return;
        const count = parseInt(insInstCount.value) || 1;
        const instVal = parseFloat(insInstAmount.value.replace(',', '.')) || 0;
        if (instVal > 0 && count > 0) {
            priceInput.value = (instVal * count).toFixed(2);
        }
    }

    if (insPayType && insInstFields) {
        insPayType.addEventListener('change', function() {
            const isInst = this.value === 'installment';
            insInstFields.classList.toggle('hidden', !isInst);
            if (isInst) {
                const billingSelect = document.querySelector('select[name="billing_cycle"]');
                if (billingSelect) billingSelect.value = 'monthly';
                
                const insStartDate = document.querySelector('input[name="ins_start_date"]');
                const nextPayDate = document.querySelector('input[name="next_payment_date"]');
// clean
                if (insStartDate && nextPayDate && insStartDate.value) {
                    nextPayDate.value = insStartDate.value;
                }

                if (insInstAmount && !insInstAmount.value) {
                    hesaplaTaksitTutari();
                }
            }
        });
    }

    if (insCalcBtn) {
        insCalcBtn.addEventListener('click', hesaplaTaksitTutari);
    }

    if (insInstCount) {
        insInstCount.addEventListener('input', function() {
            if (insPayType && insPayType.value === 'installment') {
                hesaplaTaksitTutari();
            }
        });
    }

    if (insInstAmount) {
        insInstAmount.addEventListener('input', function() {
            if (insPayType && insPayType.value === 'installment') {
                const count = parseInt(insInstCount ? insInstCount.value : 1) || 1;
                const instVal = parseFloat(this.value.replace(',', '.')) || 0;
                if (instVal > 0 && count > 0 && (!priceInput.value || parseFloat(priceInput.value) <= 0)) {
                    hesaplaToplamTutari();
                }
            }
        });
    }

    // 4. Deneme Sürümü Checkbox Dinamik Gösterimi
    const trialCheck = document.getElementById('is_trial');
    const trialFields = document.getElementById('trial_fields');
    if (trialCheck && trialFields) {
        trialCheck.addEventListener('change', function() {
            trialFields.classList.toggle('hidden', !this.checked);
        });
    }

    if (locSelect && locSelect.tagName === 'SELECT') {
        locSelect.addEventListener('change', () => {
            catSelect.value = '';
            kategorileriYukle();
        });
    }
    
    // İlk yüklemede seçili mekanın kategorilerini getir
    const initialCatId = "<?= (int)($_POST['category_id'] ?? 0) ?: '' ?>";
    kategorileriYukle(initialCatId);

    // Belge Dosya Seçim Önizleme
    const docInput = document.getElementById('doc_file_input');
    const docLabel = document.getElementById('doc_file_label');
    if (docInput && docLabel) {
        docInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const f = this.files[0];
                const sizeMb = (f.size / (1024 * 1024)).toFixed(2);
                docLabel.textContent = '✓ ' + f.name + ' (' + sizeMb + ' MB)';
                docLabel.className = 'text-xs font-bold text-blue-600 dark:text-blue-400';
            }
        });
    }
});

function kartListesiniYenile(type) {
    fetch('kredi-kartlari.php?format=json')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                if (type === 'card' || !type) {
                    const sel = document.getElementById('credit_card_select');
                    const curVal = sel.value;
                    sel.innerHTML = '<option value="">-- Kredi Kartı Seçin --</option>';
                    data.cards.forEach(c => {
                        const opt = document.createElement('option');
                        opt.value = c.id;
                        opt.textContent = `💳 ${c.bank_name} - ${c.card_name} (•••• ${c.card_last_4 || '0000'})`;
                        if (c.id == curVal) opt.selected = true;
                        sel.appendChild(opt);
                    });
                    const warn = document.getElementById('no_card_warning');
                    if (warn) {
                        warn.classList.toggle('hidden', data.cards.length > 0);
                        warn.classList.toggle('flex', data.cards.length === 0);
                    }
                }
                if (type === 'bank' || !type) {
                    const selB = document.getElementById('bank_account_select');
                    const curValB = selB.value;
                    selB.innerHTML = '<option value="">-- Banka Hesabı Seçin --</option>';
                    data.accounts.forEach(a => {
                        const opt = document.createElement('option');
                        opt.value = a.id;
                        const bName = a.bank_name ? `(${a.bank_name})` : '';
                        const l4 = a.card_last_4 ? `(•••• ${a.card_last_4})` : '';
                        opt.textContent = `🏦 ${a.title} ${bName} ${l4}`;
                        if (a.id == curValB) opt.selected = true;
                        selB.appendChild(opt);
                    });
                    const warnB = document.getElementById('no_bank_warning');
                    if (warnB) {
                        warnB.classList.toggle('hidden', data.accounts.length > 0);
                        warnB.classList.toggle('flex', data.accounts.length === 0);
                    }
                }
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: 'Ödeme araçları güncellendi',
                        showConfirmButton: false,
                        timer: 1500
                    });
                }
            }
        })
        .catch(err => console.error(err));
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

