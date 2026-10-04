<?php
/**
 * Abonelik ve Fatura Takip Sistemi - Excel / CSV Dışa Aktarma
 */

require_once __DIR__ . '/db.php';
girisKontrol();

$userId = (int)$_SESSION['user_id'];

$userRole = $_SESSION['role'] ?? 'USER';
$isAdmin = ($userRole === 'ADMIN');

// Aktif mekan / bolum filtresi (URL parametresi veya oturumdan)
$selectedLocationId = null;
if (isset($_GET['location_id']) && is_numeric($_GET['location_id']) && (int)$_GET['location_id'] > 0) {
    $selectedLocationId = (int)$_GET['location_id'];
} elseif (!empty($_SESSION['aktif_konum_id']) && is_numeric($_SESSION['aktif_konum_id']) && (int)$_SESSION['aktif_konum_id'] > 0) {
    $selectedLocationId = (int)$_SESSION['aktif_konum_id'];
}

$locationSlug = '';
if ($selectedLocationId !== null) {
    try {
        $stmtLoc = $pdo->prepare("SELECT name FROM locations WHERE id = ?");
        $stmtLoc->execute([$selectedLocationId]);
        $locName = $stmtLoc->fetchColumn();
        if ($locName) {
            $slugClean = preg_replace('/[^a-zA-Z0-9_\-]/', '', str_replace([' ', 'ı', 'ğ', 'ü', 'ş', 'ö', 'ç'], ['_', 'i', 'g', 'u', 's', 'o', 'c'], mb_strtolower($locName, 'UTF-8')));
            $locationSlug = '_' . $slugClean;
        }
    } catch (Exception $e) {}
}

try {
    $sql = "
        SELECT s.id, s.title, c.name as category_name, c.type as category_type, s.plan_tier, s.price, s.currency, s.billing_cycle, s.status,
               s.next_payment_date, s.is_trial, s.trial_end_date, s.notes, s.attachment_name,
               pm.title as payment_method,
               cc.bank_name as cc_bank_name, cc.card_name as cc_card_name, cc.card_last_4 as cc_card_last_4,
               loc.name as location_name,
               u_owner.full_name as creator_name, u_owner.username as creator_username,
               u_payer.full_name as payer_name, u_payer.username as payer_username,
               u.subscriber_no, u.meter_no, u.billing_cut_day, u.due_day,
               t.operator_name, t.speed_or_quota, t.commitment_start, t.commitment_end, t.post_commitment_price,
               ins.company_name as ins_company, ins.policy_no as ins_policy_no, ins.policy_end_date as ins_end_date,
               bes.company_name as bes_company, bes.contract_no as bes_contract_no, bes.payment_day as bes_payment_day
        FROM subscriptions s
        LEFT JOIN categories c ON s.category_id = c.id
        LEFT JOIN payment_methods pm ON s.payment_method_id = pm.id
        LEFT JOIN credit_cards cc ON s.credit_card_id = cc.id
        LEFT JOIN locations loc ON s.location_id = loc.id
        LEFT JOIN users u_owner ON s.user_id = u_owner.id
        LEFT JOIN users u_payer ON COALESCE(s.payer_user_id, s.user_id) = u_payer.id
        LEFT JOIN subscription_utility_details u ON s.id = u.subscription_id
        LEFT JOIN subscription_telecom_details t ON s.id = t.subscription_id
        LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
        LEFT JOIN subscription_bes_details bes ON s.id = bes.subscription_id
        WHERE 1=1
    ";

    $params = [];
    if (!$isAdmin) {
        $sql .= " AND (s.user_id = ? OR s.payer_user_id = ?)";
        $params[] = $userId;
        $params[] = $userId;
    }

    if ($selectedLocationId !== null) {
        $sql .= " AND s.location_id = ?";
        $params[] = $selectedLocationId;
    }

    $stmt = $pdo->prepare($sql . " ORDER BY s.id ASC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $fileName = 'abonelik_listesi' . $locationSlug . '_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Excel Türkçe karakter uyumluluğu için UTF-8 BOM
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // Başlık Satırı (Semicolon ; ayırıcı Türk Excel için en uyumlusudur)
    fputcsv($output, [
        'ID',
        'Abonelik / Kurum Başlığı',
        'Kategori',
        'Kategori Türü',
        'Konum / Kapsam',
        'Ödeyen Kişi (Sorumlu)',
        'Ekleyen Kişi',
        'Paket / Tarife',
        'Tutar',
        'Para Birimi',
        'Ödeme Aralığı',
        'Durum',
        'Sonraki Ödeme / Çekim',
        'Tanımlı Kredi Kartı',
        'Ödeme Yöntemi',
        'Deneme Sürümü mü?',
        'Deneme Bitiş Tarihi',
        'Fatura Abone No',
        'Fatura Kesim Günü',
        'Fatura Son Ödeme Günü',
        'Operatör',
        'Hız / Kota',
        'Taahhüt Bitiş Tarihi',
        'Taahhüt Sonrası Zamlı Fiyat',
        'Sigorta Şirketi',
        'Poliçe No',
        'Poliçe Yenileme Tarihi',
        'BES Şirketi',
        'BES Sözleşme No',
        'BES Çekim Günü',
        'Ekli Belge Dosyası',
        'Notlar'
    ], ';');

    // CSV Formula Injection (DDE) Koruması
    $csvTemizle = function($val) {
        if (is_string($val) && strlen($val) > 0) {
            $c = $val[0];
            if ($c === '=' || $c === '+' || $c === '-' || $c === '@' || $c === "\t" || $c === "\r") {
                return "'" . $val;
            }
        }
        return $val;
    };

    foreach ($rows as $r) {
        $payer = $r['payer_name'] ?: ($r['payer_username'] ?? 'Genel');
        $creator = $r['creator_name'] ?: ($r['creator_username'] ?? '');
        $creditCard = !empty($r['cc_bank_name']) ? "{$r['cc_bank_name']} - {$r['cc_card_name']} (**** {$r['cc_card_last_4']})" : '';

        fputcsv($output, [
            $r['id'],
            $csvTemizle($r['title']),
            $csvTemizle($r['category_name'] ?? ''),
            $csvTemizle($r['category_type'] ?? ''),
            $csvTemizle($r['location_name'] ?? 'Genel'),
            $csvTemizle($payer),
            $csvTemizle($creator),
            $csvTemizle($r['plan_tier'] ?? ''),
            number_format((float)$r['price'], 2, ',', ''),
            $r['currency'],
            $r['billing_cycle'],
            $r['status'],
            $r['next_payment_date'] ?? '',
            $csvTemizle($creditCard),
            $csvTemizle($r['payment_method'] ?? ''),
            $r['is_trial'] ? 'Evet' : 'Hayır',
            $r['trial_end_date'] ?? '',
            $csvTemizle($r['subscriber_no'] ?? ''),
            $r['billing_cut_day'] ?? '',
            $r['due_day'] ?? '',
            $csvTemizle($r['operator_name'] ?? ''),
            $csvTemizle($r['speed_or_quota'] ?? ''),
            $r['commitment_end'] ?? '',
            $r['post_commitment_price'] ? number_format((float)$r['post_commitment_price'], 2, ',', '') : '',
            $csvTemizle($r['ins_company'] ?? ''),
            $csvTemizle($r['ins_policy_no'] ?? ''),
            $r['ins_end_date'] ?? '',
            $csvTemizle($r['bes_company'] ?? ''),
            $csvTemizle($r['bes_contract_no'] ?? ''),
            $r['bes_payment_day'] ?? '',
            $csvTemizle($r['attachment_name'] ?? ''),
            $csvTemizle($r['notes'] ?? '')
        ], ';');
    }

    fclose($output);
    exit;

} catch (Exception $e) {
    sistemLogla("Excel dışa aktarma hatası: " . $e->getMessage(), 'ERROR');
    header("Location: abonelikler.php?error=export");
    exit;
}
