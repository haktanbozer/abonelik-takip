<?php
// kredi-kartlari.php - Kartlar & Banka Hesapları Yönetim Merkezi
require_once __DIR__ . '/db.php';
girisKontrol();

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'USER';
$isAdmin = ($userRole === 'ADMIN');

$aktifTab = $_GET['tab'] ?? (($_GET['action'] ?? '') === 'new_bank' ? 'bank' : 'cards'); // 'cards' veya 'bank'
$msg = $_GET['msg'] ?? '';
$error = '';

// AJAX / JSON listesi talebi (abonelik-ekle & duzenle icin dinamik yenileme)
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stmtC = $pdo->prepare("SELECT id, bank_name, card_name, card_last_4 FROM credit_cards WHERE user_id = ? AND is_active = 1 ORDER BY bank_name ASC");
        $stmtC->execute([$userId]);
        $cList = $stmtC->fetchAll(PDO::FETCH_ASSOC);

        $stmtP = $pdo->prepare("SELECT id, title, bank_name, card_type, card_last_4, is_auto_pay FROM payment_methods WHERE user_id = ? ORDER BY title ASC");
        $stmtP->execute([$userId]);
        $pList = $stmtP->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'cards' => $cList, 'accounts' => $pList]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Aktif Kullanicilar (Sadece Yonetici icin)
try {
    $allUsers = $pdo->query("SELECT id, username, full_name FROM users WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();
} catch (Exception $e) {
    $allUsers = [];
}

// POST ISLEMLERI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfKontrol($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    $isAjax = !empty($_POST['ajax']);

    // 1. KREDI KARTI EKLE
    if ($action === 'add' || $action === 'add_card') {
        $bankName = trim($_POST['bank_name'] ?? '');
        $cardName = trim($_POST['card_name'] ?? '');
        $cardNetwork = $_POST['card_network'] ?? 'mastercard';
        $cardLast4 = trim($_POST['card_last_4'] ?? '');
        $statementCutDay = (int)($_POST['statement_cut_day'] ?? 1);
        $dueDay = (int)($_POST['due_day'] ?? 10);
        $limitAmount = !empty($_POST['limit_amount']) ? (float)str_replace(',', '.', $_POST['limit_amount']) : null;
        $color = str_replace('slate-850', 'slate-800', $_POST['color'] ?? 'from-slate-800 to-slate-950');
        $cardOwnerId = ($isAdmin && !empty($_POST['user_id'])) ? (int)$_POST['user_id'] : $userId;

        if (empty($bankName) || empty($cardName)) {
            $error = "Banka adı ve kart adı zorunludur.";
        } elseif ($statementCutDay < 1 || $statementCutDay > 31 || $dueDay < 1 || $dueDay > 31) {
            $error = "Ekstre kesim ve son ödeme günleri 1 ile 31 arasında olmalıdır.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO credit_cards (user_id, bank_name, card_name, card_network, card_last_4, statement_cut_day, due_day, limit_amount, color)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$cardOwnerId, $bankName, $cardName, $cardNetwork, $cardLast4, $statementCutDay, $dueDay, $limitAmount, $color]);
                $newId = $pdo->lastInsertId();
                sistemLogla("Yeni Kredi Kartı Eklendi: {$bankName} - {$cardName}", 'INFO');

                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'success', 'id' => $newId, 'label' => "💳 {$bankName} - {$cardName} (•••• {$cardLast4})"]);
                    exit;
                }

                guvenliYonlendir("kredi-kartlari.php?tab=cards&msg=added");
            } catch (Exception $e) {
                sistemLogla("Kart ekleme hatası: " . $e->getMessage(), 'ERROR');
                $error = "Kart kaydedilirken bir hata oluştu.";
            }
        }
    } 
    // 2. KREDI KARTI DUZENLE
    elseif ($action === 'edit' || $action === 'edit_card') {
        $cardId = (int)($_POST['card_id'] ?? 0);
        $bankName = trim($_POST['bank_name'] ?? '');
        $cardName = trim($_POST['card_name'] ?? '');
        $cardNetwork = $_POST['card_network'] ?? 'mastercard';
        $cardLast4 = trim($_POST['card_last_4'] ?? '');
        $statementCutDay = (int)($_POST['statement_cut_day'] ?? 1);
        $dueDay = (int)($_POST['due_day'] ?? 10);
        $limitAmount = !empty($_POST['limit_amount']) ? (float)str_replace(',', '.', $_POST['limit_amount']) : null;
        $color = str_replace('slate-850', 'slate-800', $_POST['color'] ?? 'from-slate-800 to-slate-950');
        $cardOwnerId = ($isAdmin && !empty($_POST['user_id'])) ? (int)$_POST['user_id'] : $userId;

        if (empty($bankName) || empty($cardName)) {
            $error = "Banka adı ve kart adı zorunludur.";
        } else {
            try {
                $whereUser = $isAdmin ? "" : " AND user_id = ?";
                $stmt = $pdo->prepare("
                    UPDATE credit_cards SET
                        bank_name = ?, card_name = ?, card_network = ?, card_last_4 = ?,
                        statement_cut_day = ?, due_day = ?, limit_amount = ?, color = ?" . ($isAdmin ? ", user_id = ?" : "") . "
                    WHERE id = ? {$whereUser}
                ");
                $params = [$bankName, $cardName, $cardNetwork, $cardLast4, $statementCutDay, $dueDay, $limitAmount, $color];
                if ($isAdmin) $params[] = $cardOwnerId;
                $params[] = $cardId;
                if (!$isAdmin) $params[] = $userId;

                $stmt->execute($params);
                sistemLogla("Kredi Kartı Güncellendi: {$bankName} - {$cardName} (ID: {$cardId})", 'INFO');
                guvenliYonlendir("kredi-kartlari.php?tab=cards&msg=updated");
            } catch (Exception $e) {
                sistemLogla("Kart güncelleme hatası: " . $e->getMessage(), 'ERROR');
                $error = "Kart güncellenirken bir hata oluştu: " . $e->getMessage();
            }
        }
    } 
    // 3. KREDI KARTI SIL
    elseif ($action === 'delete' || $action === 'delete_card') {
        $cardId = (int)($_POST['card_id'] ?? 0);
        try {
            // Bağlı aboneliklerin referansını temizle
            $stmtUnlink = $pdo->prepare("UPDATE subscriptions SET credit_card_id = NULL WHERE credit_card_id = ?");
            $stmtUnlink->execute([$cardId]);

            $stmt = $pdo->prepare("DELETE FROM credit_cards WHERE id = ?" . ($isAdmin ? "" : " AND user_id = ?"));
            $stmt->execute($isAdmin ? [$cardId] : [$cardId, $userId]);
            sistemLogla("Kredi Kartı Silindi (ID: {$cardId})", 'WARNING');
            guvenliYonlendir("kredi-kartlari.php?tab=cards&msg=deleted");
        } catch (Exception $e) {
            sistemLogla("Kart silme hatası: " . $e->getMessage(), 'ERROR');
            $error = "Kart silinirken bir hata oluştu: " . $e->getMessage();
        }
    }
    // 4. BANKA HESABI EKLE
    elseif ($action === 'add_bank') {
        $bankName = trim($_POST['bank_name'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $cardType = $_POST['card_type'] ?? 'bank_transfer';
        $cardLast4 = trim($_POST['card_last_4'] ?? '');
        $isAutoPay = !empty($_POST['is_auto_pay']) ? 1 : 0;
        $bankOwnerId = ($isAdmin && !empty($_POST['user_id'])) ? (int)$_POST['user_id'] : $userId;

        if (empty($title)) {
            $error = "Hesap tanımı / başlığı zorunludur.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO payment_methods (user_id, title, bank_name, card_type, card_last_4, is_auto_pay)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$bankOwnerId, $title, $bankName, $cardType, $cardLast4, $isAutoPay]);
                $newId = $pdo->lastInsertId();
                sistemLogla("Yeni Banka Hesabı Eklendi: {$title}", 'INFO');

                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['status' => 'success', 'id' => $newId, 'label' => "🏦 {$title} " . ($bankName ? "({$bankName})" : "")]);
                    exit;
                }

                guvenliYonlendir("kredi-kartlari.php?tab=bank&msg=bank_added");
            } catch (Exception $e) {
                sistemLogla("Banka hesabı ekleme hatası: " . $e->getMessage(), 'ERROR');
                $error = "Banka hesabı kaydedilirken hata oluştu: " . $e->getMessage();
            }
        }
    }
    // 5. BANKA HESABI DUZENLE
    elseif ($action === 'edit_bank') {
        $bankId = (int)($_POST['bank_id'] ?? 0);
        $bankName = trim($_POST['bank_name'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $cardType = $_POST['card_type'] ?? 'bank_transfer';
        $cardLast4 = trim($_POST['card_last_4'] ?? '');
        $isAutoPay = !empty($_POST['is_auto_pay']) ? 1 : 0;
        $bankOwnerId = ($isAdmin && !empty($_POST['user_id'])) ? (int)$_POST['user_id'] : $userId;

        if (empty($title)) {
            $error = "Hesap tanımı / başlığı zorunludur.";
        } else {
            try {
                $whereUser = $isAdmin ? "" : " AND user_id = ?";
                $stmt = $pdo->prepare("
                    UPDATE payment_methods SET
                        title = ?, bank_name = ?, card_type = ?, card_last_4 = ?, is_auto_pay = ?" . ($isAdmin ? ", user_id = ?" : "") . "
                    WHERE id = ? {$whereUser}
                ");
                $params = [$title, $bankName, $cardType, $cardLast4, $isAutoPay];
                if ($isAdmin) $params[] = $bankOwnerId;
                $params[] = $bankId;
                if (!$isAdmin) $params[] = $userId;

                $stmt->execute($params);
                sistemLogla("Banka Hesabı Güncellendi: {$title} (ID: {$bankId})", 'INFO');
                guvenliYonlendir("kredi-kartlari.php?tab=bank&msg=bank_updated");
            } catch (Exception $e) {
                sistemLogla("Banka hesabı güncelleme hatası: " . $e->getMessage(), 'ERROR');
                $error = "Hesap güncellenirken hata oluştu: " . $e->getMessage();
            }
        }
    }
    // 6. BANKA HESABI SIL
    elseif ($action === 'delete_bank') {
        $bankId = (int)($_POST['bank_id'] ?? 0);
        try {
            // Bağlı aboneliklerin referansını temizle
            $stmtUnlink = $pdo->prepare("UPDATE subscriptions SET payment_method_id = NULL WHERE payment_method_id = ?");
            $stmtUnlink->execute([$bankId]);

            $stmt = $pdo->prepare("DELETE FROM payment_methods WHERE id = ?" . ($isAdmin ? "" : " AND user_id = ?"));
            $stmt->execute($isAdmin ? [$bankId] : [$bankId, $userId]);
            sistemLogla("Banka Hesabı Silindi (ID: {$bankId})", 'WARNING');
            guvenliYonlendir("kredi-kartlari.php?tab=bank&msg=bank_deleted");
        } catch (Exception $e) {
            sistemLogla("Banka hesabı silme hatası: " . $e->getMessage(), 'ERROR');
            $error = "Hesap silinirken hata oluştu: " . $e->getMessage();
        }
    }
}

// 1. Kredi Kartlarini Cek
try {
    $whereSql = $isAdmin ? "1=1" : "c.user_id = ?";
    $stmtCards = $pdo->prepare("
        SELECT c.*, u.full_name as owner_name, u.username as owner_username,
               COUNT(s.id) as sub_count,
               COALESCE(SUM(CASE WHEN s.billing_cycle = 'monthly' THEN s.price 
                                 WHEN s.billing_cycle = 'annual' THEN s.price / 12 
                                 WHEN s.billing_cycle = 'quarterly' THEN s.price / 3 
                                 WHEN s.billing_cycle = 'semi_annual' THEN s.price / 6 
                                 WHEN s.billing_cycle = 'one_time' THEN 0 
                                 ELSE s.price END), 0) as monthly_charge
        FROM credit_cards c
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN subscriptions s ON (s.credit_card_id = c.id AND s.status = 'active')
        WHERE {$whereSql}
        GROUP BY c.id
        ORDER BY c.created_at DESC
    ");
    if ($isAdmin) {
        $stmtCards->execute();
    } else {
        $stmtCards->execute([$userId]);
    }
    $cards = $stmtCards->fetchAll();
} catch (Exception $e) {
    sistemLogla("Kredi kartı listeleme hatası: " . $e->getMessage(), 'ERROR');
    $cards = [];
}

// 2. Banka Hesaplarini Cek
try {
    $wherePm = $isAdmin ? "1=1" : "pm.user_id = ?";
    $stmtPm = $pdo->prepare("
        SELECT pm.*, u.full_name as owner_name, u.username as owner_username,
               COUNT(s.id) as sub_count,
               COALESCE(SUM(CASE WHEN s.billing_cycle = 'monthly' THEN s.price 
                                 WHEN s.billing_cycle = 'annual' THEN s.price / 12 
                                 WHEN s.billing_cycle = 'quarterly' THEN s.price / 3 
                                 WHEN s.billing_cycle = 'semi_annual' THEN s.price / 6 
                                 WHEN s.billing_cycle = 'one_time' THEN 0 
                                 ELSE s.price END), 0) as monthly_charge
        FROM payment_methods pm
        LEFT JOIN users u ON pm.user_id = u.id
        LEFT JOIN subscriptions s ON (s.payment_method_id = pm.id AND s.status = 'active')
        WHERE {$wherePm}
        GROUP BY pm.id
        ORDER BY pm.created_at DESC
    ");
    if ($isAdmin) {
        $stmtPm->execute();
    } else {
        $stmtPm->execute([$userId]);
    }
    $bankAccounts = $stmtPm->fetchAll();
} catch (Exception $e) {
    sistemLogla("Banka hesabı listeleme hatası: " . $e->getMessage(), 'ERROR');
    $bankAccounts = [];
}

/**
 * Belirli bir gun icin bu ayki veya bir sonraki tarihi hesaplar
 */
function hedefGunTarihi($gun) {
    $bugun = new DateTime();
    $buYil = (int)$bugun->format('Y');
    $buAy  = (int)$bugun->format('m');
    $buGun = (int)$bugun->format('d');

    $buAyMax = (int)date('t', strtotime("{$buYil}-{$buAy}-01"));
    $gecerliGun = min($gun, $buAyMax);
    $hedef = new DateTime("{$buYil}-{$buAy}-{$gecerliGun}");

    if ($buGun > $gecerliGun) {
        $hedef->modify('+1 month');
        $sonrakiAyMax = (int)date('t', strtotime($hedef->format('Y-m-01')));
        $hedefGun = min($gun, $sonrakiAyMax);
        $hedef->setDate((int)$hedef->format('Y'), (int)$hedef->format('m'), $hedefGun);
    }

    $diff = $bugun->diff($hedef);
    $kalan = (int)$diff->format('%r%a');
    return ['tarih' => $hedef->format('Y-m-d'), 'kalan' => $kalan];
}

$pageTitle = "Kartlar & Banka Hesapları - Abonelik Takip";
require_once __DIR__ . '/header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">

    <!-- Ust Baslik & Tab Gecisi & Eylem Butonlari -->
    <div class="pb-4 border-b border-slate-200 dark:border-slate-800">
        <div class="flex items-center gap-2.5">
            <span class="text-2xl">💳</span>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">Kartlar &amp; Banka Hesapları</h1>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
            Kredi kartlarınızı ve otomatik ödeme bağlı banka hesaplarınızı tek noktadan yönetin.
        </p>
    </div>

    <!-- TAB BUTONLARI & SEKMEYE OZEL EKLE BUTONU -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-200 dark:border-slate-800 pb-3">
        <div class="flex items-center gap-2">
            <a href="kredi-kartlari.php?tab=cards" class="px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 <?= $aktifTab === 'cards' ? 'bg-blue-600 text-white shadow-sm' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                <span>💳 Kredi Kartlarım</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] <?= $aktifTab === 'cards' ? 'bg-blue-700 text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300' ?>"><?= count($cards) ?></span>
            </a>
            <a href="kredi-kartlari.php?tab=bank" class="px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 <?= $aktifTab === 'bank' ? 'bg-emerald-600 text-white shadow-sm' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                <span>🏦 Banka Hesaplarım</span>
                <span class="px-2 py-0.5 rounded-full text-[10px] <?= $aktifTab === 'bank' ? 'bg-emerald-700 text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300' ?>"><?= count($bankAccounts) ?></span>
            </a>
        </div>
        <div>
            <?php if ($aktifTab === 'cards'): ?>
                <button type="button" class="btn-yeni-kart px-3 py-1.5 bg-blue-50 dark:bg-blue-900/30 hover:bg-blue-100 dark:hover:bg-blue-900/50 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-800 rounded-lg text-xs font-bold transition flex items-center gap-1 shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
                    <span>+ Yeni Kredi Kartı Ekle</span>
                </button>
            <?php else: ?>
                <button type="button" class="btn-yeni-banka px-3 py-1.5 bg-emerald-50 dark:bg-emerald-900/30 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 rounded-lg text-xs font-bold transition flex items-center gap-1 shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
                    <span>+ Yeni Banka Hesabı Ekle</span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- BILDIRIM MESAJLARI -->
    <?php if ($msg === 'added'): ?>
        <div class="p-3.5 bg-emerald-500/10 border border-emerald-500/20 rounded-xl text-emerald-600 dark:text-emerald-400 text-xs font-semibold flex items-center gap-2">
            <span>✓</span><span>Kredi kartı başarıyla sisteme tanımlandı.</span>
        </div>
    <?php elseif ($msg === 'updated'): ?>
        <div class="p-3.5 bg-blue-500/10 border border-blue-500/20 rounded-xl text-blue-600 dark:text-blue-400 text-xs font-semibold flex items-center gap-2">
            <span>✓</span><span>Kredi kartı bilgileri güncellendi.</span>
        </div>
    <?php elseif ($msg === 'deleted'): ?>
        <div class="p-3.5 bg-amber-500/10 border border-amber-500/20 rounded-xl text-amber-600 dark:text-amber-400 text-xs font-semibold flex items-center gap-2">
            <span>✓</span><span>Kredi kartı sistemden kaldırıldı.</span>
        </div>
    <?php elseif ($msg === 'bank_added'): ?>
        <div class="p-3.5 bg-emerald-500/10 border border-emerald-500/20 rounded-xl text-emerald-600 dark:text-emerald-400 text-xs font-semibold flex items-center gap-2">
            <span>✓</span><span>Banka hesabı başarıyla eklendi.</span>
        </div>
    <?php elseif ($msg === 'bank_updated'): ?>
        <div class="p-3.5 bg-blue-500/10 border border-blue-500/20 rounded-xl text-blue-600 dark:text-blue-400 text-xs font-semibold flex items-center gap-2">
            <span>✓</span><span>Banka hesabı güncellendi.</span>
        </div>
    <?php elseif ($msg === 'bank_deleted'): ?>
        <div class="p-3.5 bg-amber-500/10 border border-amber-500/20 rounded-xl text-amber-600 dark:text-amber-400 text-xs font-semibold flex items-center gap-2">
            <span>✓</span><span>Banka hesabı sistemden silindi.</span>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="p-3.5 bg-rose-500/10 border border-rose-500/20 rounded-xl text-rose-600 dark:text-rose-400 text-xs font-semibold flex items-center gap-2">
            <span>⚠</span><span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- 1. TAB: KREDI KARTLARI -->
    <?php if ($aktifTab === 'cards'): ?>
        <?php if (empty($cards)): ?>
            <div class="py-14 text-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-8">
                <div class="w-14 h-14 bg-blue-50 dark:bg-slate-800 text-blue-600 dark:text-blue-400 rounded-2xl flex items-center justify-center mx-auto mb-3 text-2xl">
                    💳
                </div>
                <h3 class="text-base font-bold text-slate-900 dark:text-white mb-1">Henüz Kredi Kartı Tanımlanmamış</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-5 max-w-sm mx-auto">
                    Kredi kartınızı ekleyerek aboneliklerinizle eşleştirebilir ve son ödeme günü uyarıları alabilirsiniz.
                </p>
                <button type="button" class="btn-yeni-kart px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition shadow-md shadow-blue-600/20">
                    + İlk Kartınızı Ekleyin
                </button>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($cards as $card): 
                    $ekstreInfo = hedefGunTarihi($card['statement_cut_day']);
                    $odemeInfo  = hedefGunTarihi($card['due_day']);
                    $networkLogos = [
                        'visa' => 'VISA',
                        'mastercard' => 'Mastercard',
                        'troy' => 'TROY',
                        'amex' => 'AMEX'
                    ];
                    $networkLabel = $networkLogos[$card['card_network']] ?? 'Kart';
                    $isDueSoon = ($odemeInfo['kalan'] <= 3 && $odemeInfo['kalan'] >= 0);
                ?>
                    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 shadow-sm hover:shadow-md transition flex flex-col justify-between">
                        
                        <!-- Sanal Kredi Karti Mockup -->
                        <?php
                            $cardColor = $card['color'] ?: 'from-slate-800 to-slate-950';
                            if (strpos($cardColor, 'slate-850') !== false) {
                                $cardColor = str_replace('slate-850', 'slate-800', $cardColor);
                            }
                        ?>
                        <div class="w-full h-44 rounded-xl p-4 bg-slate-900 bg-gradient-to-br <?= htmlspecialchars($cardColor) ?> border border-slate-700/50 dark:border-white/10 shadow-lg relative flex flex-col justify-between overflow-hidden">
                            <div class="absolute -right-10 -bottom-10 w-40 h-40 rounded-full bg-white/5 blur-2xl pointer-events-none"></div>

                            <!-- Banka & Network -->
                            <div class="flex items-center justify-between z-10">
                                <div>
                                    <span class="text-xs font-extrabold text-white tracking-wider uppercase block drop-shadow-sm"><?= htmlspecialchars($card['bank_name']) ?></span>
                                    <span class="text-[10px] text-white/80 font-medium"><?= htmlspecialchars($card['card_name']) ?></span>
                                </div>
                                <div class="px-2 py-0.5 rounded bg-white/10 backdrop-blur-sm border border-white/20 text-white font-extrabold text-[10px] tracking-wider">
                                    <?= $networkLabel ?>
                                </div>
                            </div>

                            <!-- Cip Ikonu -->
                            <div class="flex items-center justify-between my-auto z-10">
                                <div class="w-8 h-6 rounded bg-gradient-to-tr from-amber-300 via-amber-200 to-yellow-400 border border-amber-400/60 shadow-inner flex items-center justify-center">
                                    <div class="w-5 h-3 border border-amber-600/40 rounded-sm grid grid-cols-2 gap-0.5 p-0.5">
                                        <div class="border-b border-r border-amber-600/40"></div>
                                        <div class="border-b border-amber-600/40"></div>
                                    </div>
                                </div>
                                <div class="text-white/60 text-xs font-mono font-bold tracking-widest">
                                    •••• <?= htmlspecialchars($card['card_last_4'] ?: '0000') ?>
                                </div>
                            </div>

                            <!-- Alt Bilgi: Sahip & Limit -->
                            <div class="flex items-end justify-between z-10">
                                <div>
                                    <span class="text-[9px] uppercase tracking-wider text-white/60 block">Kart Sahibi</span>
                                    <span class="text-xs font-semibold text-white truncate max-w-[130px] block">
                                        <?= htmlspecialchars($card['owner_name'] ?: $card['owner_username'] ?: 'Kullanıcı') ?>
                                    </span>
                                </div>
                                <?php if ($card['limit_amount']): ?>
                                <div class="text-right">
                                    <span class="text-[9px] uppercase tracking-wider text-white/60 block">Kart Limiti</span>
                                    <span class="text-xs font-bold text-white"><?= number_format($card['limit_amount'], 0, ',', '.') ?> TL</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Kart Takvim & Abonelik Ozeti -->
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800 space-y-2 text-xs">
                            <div class="flex justify-between items-center text-slate-600 dark:text-slate-400">
                                <span>Ekstre Günü:</span>
                                <span class="font-bold text-slate-800 dark:text-slate-200">Her ayın <?= $card['statement_cut_day'] ?>. günü</span>
                            </div>
                            <div class="flex justify-between items-center text-slate-600 dark:text-slate-400">
                                <span>Son Ödeme Günü:</span>
                                <span class="font-bold <?= $isDueSoon ? 'text-rose-500 font-extrabold' : 'text-slate-800 dark:text-slate-200' ?>">
                                    Her ayın <?= $card['due_day'] ?>. günü 
                                    <?php if ($isDueSoon): ?>
                                        <span class="text-[10px] text-rose-500">(⚠ <?= $odemeInfo['kalan'] ?> gün)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="flex justify-between items-center pt-1 border-t border-dashed border-slate-100 dark:border-slate-800">
                                <span class="text-slate-500 dark:text-slate-400">Bağlı Abonelik:</span>
                                <div class="text-right">
                                    <span class="font-bold text-blue-600 dark:text-blue-400"><?= $card['sub_count'] ?> Adet</span>
                                    <span class="text-[10px] text-slate-500 dark:text-slate-400 block"><?= number_format($card['monthly_charge'], 2, ',', '.') ?> TL / ay</span>
                                </div>
                            </div>
                        </div>

                        <!-- Aksiyon Butonlari -->
                        <div class="mt-3 pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2">
                            <button type="button"
                                class="btn-kart-duzenle px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 rounded-lg text-xs font-semibold transition"
                                data-card='<?= htmlspecialchars(json_encode($card), ENT_QUOTES, "UTF-8") ?>'>
                                Düzenle
                            </button>
                            <form method="POST" onsubmit="return confirm('Bu kredi kartını silmek istediğinize emin misiniz?');" class="inline">
                                <?= csrfAlaniniEkle() ?>
                                <input type="hidden" name="action" value="delete_card">
                                <input type="hidden" name="card_id" value="<?= $card['id'] ?>">
                                <button type="submit" class="px-3 py-1.5 bg-rose-50 dark:bg-rose-900/20 hover:bg-rose-100 dark:hover:bg-rose-900/40 text-rose-600 dark:text-rose-400 rounded-lg text-xs font-semibold transition">
                                    Sil
                                </button>
                            </form>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <!-- 2. TAB: BANKA HESAPLARI -->
    <?php elseif ($aktifTab === 'bank'): ?>
        <?php if (empty($bankAccounts)): ?>
            <div class="py-14 text-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-8">
                <div class="w-14 h-14 bg-emerald-50 dark:bg-slate-800 text-emerald-600 dark:text-emerald-400 rounded-2xl flex items-center justify-center mx-auto mb-3 text-2xl">
                    🏦
                </div>
                <h3 class="text-base font-bold text-slate-900 dark:text-white mb-1">Henüz Banka Hesabı Tanımlanmamış</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-5 max-w-sm mx-auto">
                    Vadesiz hesap veya otomatik ödeme talimatı verdiğiniz banka hesaplarınızı ekleyerek faturalarınızla eşleştirebilirsiniz.
                </p>
                <button type="button" class="btn-yeni-banka px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition shadow-md shadow-emerald-600/20">
                    + İlk Banka Hesabınızı Ekleyin
                </button>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($bankAccounts as $acc): 
                    $typeLabels = [
                        'bank_transfer' => 'Vadesiz TL Hesabı',
                        'debit' => 'Banka Kartı (Debit)',
                        'credit' => 'Kredi Kartı',
                        'virtual' => 'Sanal Kart',
                        'other' => 'Diğer Hesap'
                    ];
                    $typeLabel = $typeLabels[$acc['card_type']] ?? 'Vadesiz Hesap';
                ?>
                    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 shadow-sm hover:shadow-md transition flex flex-col justify-between">
                        <div>
                            <!-- Baslik & Banka -->
                            <div class="flex items-start justify-between gap-2 mb-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-100 dark:border-emerald-800 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-lg font-bold">
                                        🏦
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-900 dark:text-white text-sm"><?= htmlspecialchars($acc['title']) ?></h3>
                                        <span class="text-[11px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($acc['bank_name'] ?: 'Banka Belirtilmemiş') ?></span>
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                    <?= $typeLabel ?>
                                </span>
                            </div>

                            <!-- Rozetler & Son 4 Hane -->
                            <div class="p-3 bg-slate-50 dark:bg-slate-950/60 rounded-xl space-y-2 text-xs">
                                <div class="flex items-center justify-between text-slate-600 dark:text-slate-400">
                                    <span>Hesap / Kart No:</span>
                                    <span class="font-mono font-bold text-slate-800 dark:text-slate-200">
                                        <?= $acc['card_last_4'] ? '•••• ' . htmlspecialchars($acc['card_last_4']) : 'Tanımlanmamış' ?>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between text-slate-600 dark:text-slate-400">
                                    <span>Otomatik Ödeme:</span>
                                    <?php if ($acc['is_auto_pay']): ?>
                                        <span class="text-emerald-600 dark:text-emerald-400 font-bold flex items-center gap-1 text-[11px]">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block"></span>
                                            Talimatlı Hesap
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-[11px]">Manuel Ödeme</span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center justify-between pt-1 border-t border-dashed border-slate-200 dark:border-slate-800">
                                    <span class="text-slate-500 dark:text-slate-400">Bağlı Abonelik:</span>
                                    <div class="text-right">
                                        <span class="font-bold text-emerald-600 dark:text-emerald-400"><?= $acc['sub_count'] ?> Adet</span>
                                        <span class="text-[10px] text-slate-500 dark:text-slate-400 block"><?= number_format($acc['monthly_charge'], 2, ',', '.') ?> TL / ay</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Aksiyon Butonlari -->
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2">
                            <button type="button"
                                class="btn-banka-duzenle px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 rounded-lg text-xs font-semibold transition"
                                data-bank='<?= htmlspecialchars(json_encode($acc), ENT_QUOTES, "UTF-8") ?>'>
                                Düzenle
                            </button>
                            <form method="POST" onsubmit="return confirm('Bu banka hesabını silmek istediğinize emin misiniz?');" class="inline">
                                <?= csrfAlaniniEkle() ?>
                                <input type="hidden" name="action" value="delete_bank">
                                <input type="hidden" name="bank_id" value="<?= $acc['id'] ?>">
                                <button type="submit" class="px-3 py-1.5 bg-rose-50 dark:bg-rose-900/20 hover:bg-rose-100 dark:hover:bg-rose-900/40 text-rose-600 dark:text-rose-400 rounded-lg text-xs font-semibold transition">
                                    Sil
                                </button>
                            </form>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<!-- 1. KREDI KARTI EKLE / DUZENLE MODAL -->
<div id="cardModal" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
            <h3 id="cardModalTitle" class="text-base font-bold text-slate-900 dark:text-white">Yeni Kredi Kartı Ekle</h3>
            <button type="button" class="btn-modal-kapat text-slate-400 hover:text-slate-600 dark:hover:text-white text-lg font-bold">&times;</button>
        </div>

        <form method="POST" id="cardForm" class="space-y-4">
            <?= csrfAlaniniEkle() ?>
            <input type="hidden" name="action" id="cardFormAction" value="add_card">
            <input type="hidden" name="card_id" id="formCardId" value="">

            <?php if ($isAdmin): ?>
            <div>
                <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Kart Sahibi Üye *</label>
                <select name="user_id" id="formUserId" class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= ($u['id'] == $userId) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Banka Adı *</label>
                    <input type="text" name="bank_name" id="formBankName" required placeholder="Örn: Garanti BBVA, İş Bankası"
                        class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Kart Adı *</label>
                    <input type="text" name="card_name" id="formCardName" required placeholder="Örn: Bonus Platinum, Maximum"
                        class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Şebeke (Network)</label>
                    <select name="card_network" id="formCardNetwork" class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="mastercard">Mastercard</option>
                        <option value="visa">Visa</option>
                        <option value="troy">TROY (Yerli)</option>
                        <option value="amex">American Express</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Son 4 Hanesi</label>
                    <input type="text" name="card_last_4" id="formCardLast4" maxlength="4" placeholder="Örn: 4892"
                        class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none font-mono">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-blue-600 dark:text-blue-400 uppercase tracking-wider mb-1.5">Ekstre Kesim Günü (1-31) *</label>
                    <input type="number" name="statement_cut_day" id="formStatementDay" min="1" max="31" required placeholder="Örn: 15"
                        class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none font-bold">
                </div>
                <div>
                    <label class="block text-xs font-bold text-amber-600 dark:text-amber-400 uppercase tracking-wider mb-1.5">Son Ödeme Günü (1-31) *</label>
                    <input type="number" name="due_day" id="formDueDay" min="1" max="31" required placeholder="Örn: 25"
                        class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-amber-500 outline-none font-bold">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Kart Limiti (Opsiyonel)</label>
                    <input type="number" step="0.01" name="limit_amount" id="formLimitAmount" placeholder="Örn: 50000"
                        class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Kart Rengi</label>
                    <select name="color" id="formColor" class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="from-slate-800 to-slate-950">⚫ Platin / Karbon Siyahı</option>
                        <option value="from-amber-600 to-yellow-800">🟡 Bonus Altın Sarısı</option>
                        <option value="from-blue-700 to-indigo-950">🔵 Gece Mavisi</option>
                        <option value="from-emerald-700 to-teal-950">🟢 Zümrüt Yeşili</option>
                        <option value="from-rose-700 to-red-950">🔴 Bordo / Yakut Kırmızısı</option>
                        <option value="from-purple-800 to-indigo-950">🟣 Asil Mor</option>
                    </select>
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs transition shadow-md shadow-blue-600/20">
                    Kartı Kaydet
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 2. BANKA HESABI EKLE / DUZENLE MODAL -->
<div id="bankModal" class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
            <h3 id="bankModalTitle" class="text-base font-bold text-slate-900 dark:text-white">Yeni Banka Hesabı Ekle</h3>
            <button type="button" class="btn-modal-kapat text-slate-400 hover:text-slate-600 dark:hover:text-white text-lg font-bold">&times;</button>
        </div>

        <form method="POST" id="bankForm" class="space-y-4">
            <?= csrfAlaniniEkle() ?>
            <input type="hidden" name="action" id="bankFormAction" value="add_bank">
            <input type="hidden" name="bank_id" id="formBankId" value="">

            <?php if ($isAdmin): ?>
            <div>
                <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Hesap Sahibi Üye *</label>
                <select name="user_id" id="formBankUserId" class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-emerald-500 outline-none">
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= ($u['id'] == $userId) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Hesap Tanımı / Başlık *</label>
                    <input type="text" name="title" id="formAccountTitle" required placeholder="Örn: Maaş Hesabı, Kira Hesabı"
                        class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-emerald-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Banka Adı</label>
                    <input type="text" name="bank_name" id="formAccountBankName" placeholder="Örn: Garanti BBVA, Enpara, İş Bankası"
                        class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-emerald-500 outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Hesap Türü</label>
                    <select name="card_type" id="formAccountType" class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-emerald-500 outline-none">
                        <option value="bank_transfer">Vadesiz TL Hesabı (EFT / Otomatik Talimat)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Son 4 Hane veya IBAN Sonu</label>
                    <input type="text" name="card_last_4" id="formAccountLast4" maxlength="4" placeholder="Örn: 9021"
                        class="w-full p-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-xs focus:ring-2 focus:ring-emerald-500 outline-none font-mono">
                </div>
            </div>

            <div>
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input type="checkbox" name="is_auto_pay" id="formAccountAutoPay" value="1" checked
                        class="w-4 h-4 rounded text-emerald-600 focus:ring-emerald-500 bg-slate-50 dark:bg-slate-950 border-slate-300 dark:border-slate-800">
                    <span class="text-xs font-bold text-slate-700 dark:text-slate-300">Otomatik Ödeme Talimatına Açık Hesap</span>
                </label>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 pl-6">
                    Abonelik veya faturalarınızda bu hesabı seçtiğinizde otomatik ödeme olarak listelenir.
                </p>
            </div>

            <div class="pt-2">
                <button type="submit" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold text-xs transition shadow-md shadow-emerald-600/20">
                    Hesabı Kaydet
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cardModal = document.getElementById('cardModal');
    const bankModal = document.getElementById('bankModal');

    // 1. KREDI KARTI MODAL FONKSIYONLARI
    function yeniKartModalAc() {
        if (!cardModal) return;
        document.getElementById('cardModalTitle').innerText = 'Yeni Kredi Kartı Ekle';
        document.getElementById('cardFormAction').value = 'add_card';
        document.getElementById('formCardId').value = '';
        document.getElementById('formBankName').value = '';
        document.getElementById('formCardName').value = '';
        document.getElementById('formCardLast4').value = '';
        document.getElementById('formStatementDay').value = '';
        document.getElementById('formDueDay').value = '';
        if (document.getElementById('formLimitAmount')) document.getElementById('formLimitAmount').value = '';
        cardModal.classList.remove('hidden');
        cardModal.classList.add('flex');
    }

    function kartDuzenle(card) {
        if (!cardModal) return;
        document.getElementById('cardModalTitle').innerText = 'Kredi Kartını Düzenle';
        document.getElementById('cardFormAction').value = 'edit_card';
        document.getElementById('formCardId').value = card.id || '';
        document.getElementById('formBankName').value = card.bank_name || '';
        document.getElementById('formCardName').value = card.card_name || '';
        document.getElementById('formCardNetwork').value = card.card_network || 'mastercard';
        document.getElementById('formCardLast4').value = card.card_last_4 || '';
        document.getElementById('formStatementDay').value = card.statement_cut_day || '';
        document.getElementById('formDueDay').value = card.due_day || '';
        let cardCol = card.color || 'from-slate-800 to-slate-950';
        if (cardCol.includes('slate-850')) cardCol = cardCol.replace('slate-850', 'slate-800');
        document.getElementById('formColor').value = cardCol;
        if (document.getElementById('formUserId')) {
            document.getElementById('formUserId').value = card.user_id || '';
        }
        cardModal.classList.remove('hidden');
        cardModal.classList.add('flex');
    }

    function cardModalKapat() {
        if (!cardModal) return;
        cardModal.classList.add('hidden');
        cardModal.classList.remove('flex');
    }

    // 2. BANKA HESABI MODAL FONKSIYONLARI
    function yeniBankaModalAc() {
        if (!bankModal) return;
        document.getElementById('bankModalTitle').innerText = 'Yeni Banka Hesabı Ekle';
        document.getElementById('bankFormAction').value = 'add_bank';
        document.getElementById('formBankId').value = '';
        document.getElementById('formAccountTitle').value = '';
        document.getElementById('formAccountBankName').value = '';
        document.getElementById('formAccountLast4').value = '';
        document.getElementById('formAccountType').value = 'bank_transfer';
        document.getElementById('formAccountAutoPay').checked = true;
        bankModal.classList.remove('hidden');
        bankModal.classList.add('flex');
    }

    function bankaDuzenle(acc) {
        if (!bankModal) return;
        document.getElementById('bankModalTitle').innerText = 'Banka Hesabını Düzenle';
        document.getElementById('bankFormAction').value = 'edit_bank';
        document.getElementById('formBankId').value = acc.id || '';
        document.getElementById('formAccountTitle').value = acc.title || '';
        document.getElementById('formAccountBankName').value = acc.bank_name || '';
        document.getElementById('formAccountType').value = acc.card_type || 'bank_transfer';
        document.getElementById('formAccountLast4').value = acc.card_last_4 || '';
        document.getElementById('formAccountAutoPay').checked = (acc.is_auto_pay == 1);
        if (document.getElementById('formBankUserId')) {
            document.getElementById('formBankUserId').value = acc.user_id || '';
        }
        bankModal.classList.remove('hidden');
        bankModal.classList.add('flex');
    }

    function bankModalKapat() {
        if (!bankModal) return;
        bankModal.classList.add('hidden');
        bankModal.classList.remove('flex');
    }

    // EVENT LISTENER BINDINGS (CSP NONCE UYUMLU)
    // Yeni Kart Butonlari
    document.querySelectorAll('.btn-yeni-kart').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            yeniKartModalAc();
        });
    });

    // Yeni Banka Butonlari
    document.querySelectorAll('.btn-yeni-banka').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            yeniBankaModalAc();
        });
    });

    // Kart Duzenle Butonlari
    document.querySelectorAll('.btn-kart-duzenle').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            try {
                const cardData = JSON.parse(this.getAttribute('data-card'));
                kartDuzenle(cardData);
            } catch (err) {
                console.error("Kart verisi okunamadı:", err);
            }
        });
    });

    // Banka Duzenle Butonlari
    document.querySelectorAll('.btn-banka-duzenle').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            try {
                const bankData = JSON.parse(this.getAttribute('data-bank'));
                bankaDuzenle(bankData);
            } catch (err) {
                console.error("Banka verisi okunamadı:", err);
            }
        });
    });

    // Modal Kapat Butonlari
    document.querySelectorAll('.btn-modal-kapat').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            cardModalKapat();
            bankModalKapat();
        });
    });

    // Disari tiklandiginda modal kapatma
    window.addEventListener('click', function(e) {
        if (e.target === cardModal) cardModalKapat();
        if (e.target === bankModal) bankModalKapat();
    });

    // ESC tusuna basildiginda modal kapatma
    window.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            cardModalKapat();
            bankModalKapat();
        }
    });

    // URL parametresine gore otomatik modal acma (?action=new_card veya ?action=new_bank)
    const urlParams = new URLSearchParams(window.location.search);
    const action = urlParams.get('action');
    if (action === 'new_card') {
        yeniKartModalAc();
    } else if (action === 'new_bank') {
        yeniBankaModalAc();
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
