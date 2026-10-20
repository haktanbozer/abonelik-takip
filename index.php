<?php
$pageTitle = "Genel Bakış - Abonelik ve Fatura Takip";
require_once __DIR__ . '/header.php';

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'USER';
$isAdmin = ($userRole === 'ADMIN');
$aktifKonumId = $_SESSION['aktif_konum_id'] ?? null;
$aktifKartId = $_SESSION['aktif_kart_id'] ?? null;
$aktifKonumAd = $_SESSION['aktif_konum_ad'] ?? 'Tüm Konumlar';

$locSql = "";
if ($aktifKonumId) {
    $locSql = " AND s.location_id = " . (int)$aktifKonumId . " ";
} elseif ($aktifKartId) {
    $locSql = " AND s.credit_card_id = " . (int)$aktifKartId . " ";
}

// Döviz Kırılımı Formatlama Yardımcısı
function dovizRozetleriHtml($totals, $color = 'default') {
    if (empty($totals)) {
        return '<span class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white">0,00 ₺</span>';
    }
    
    // Yalnızca 0'dan büyük tutarları al
    $valid = array_filter($totals, function($v) { return (float)$v > 0; });
    if (empty($valid)) {
        return '<span class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white">0,00 ₺</span>';
    }

    // Tek para birimi varsa doğrudan büyük yaz
    if (count($valid) === 1) {
        $curr = array_key_first($valid);
        $textColor = ($color === 'emerald') ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-900 dark:text-white';
        return '<span class="text-xl xs:text-2xl sm:text-3xl font-extrabold ' . $textColor . ' tracking-tight inline-block max-w-full truncate">' . paraFormat($valid[$curr], $curr) . '</span>';
    }

    // Birden fazla döviz varsa yan yana şık rozetler (badge) halinde bas
    $badges = [];
    foreach ($valid as $curr => $val) {
        $formatted = paraFormat($val, $curr);
        $badges[] = '<span class="inline-flex items-center px-2 py-0.5 sm:px-2.5 sm:py-1 rounded-lg sm:rounded-xl text-[11px] sm:text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-200 dark:border-slate-700 shadow-sm whitespace-nowrap">' . $formatted . '</span>';
    }
    return '<div class="flex flex-wrap items-center gap-1 sm:gap-1.5 mt-1 max-w-full">' . implode('<span class="text-slate-400 font-bold text-[10px] sm:text-xs">+</span>', $badges) . '</div>';
}

function dovizYillikProjeksiyonHtml($totals) {
    if (empty($totals)) return '0,00 ₺';
    $valid = array_filter($totals, function($v) { return (float)$v > 0; });
    if (empty($valid)) return '0,00 ₺';
    
    $parts = [];
    foreach ($valid as $curr => $val) {
        $parts[] = paraFormat($val * 12, $curr);
    }
    return implode(' + ', $parts);
}

function dovizKucukRozetlerHtml($totals) {
    if (empty($totals)) return '0,00 ₺';
    $valid = array_filter($totals, function($v) { return (float)$v > 0; });
    if (empty($valid)) return '0,00 ₺';
    $parts = [];
    foreach ($valid as $curr => $val) {
        $parts[] = paraFormat($val, $curr);
    }
    return implode(' + ', $parts);
}

// 1. İstatistikleri Çek
try {
    if ($isAdmin) {
        // ADMIN: Hane / Ekip Genel Toplamı (Brüt, Cashback, Ortak Gelir ve Net Gider)
        $stmtCurrencies = $pdo->prepare("
            SELECT 
                s.currency,
                COALESCE(SUM(CASE
                                  WHEN c.type = 'insurance' AND ins.payment_type = 'installment' AND COALESCE(ins.installment_amount, 0) > 0 THEN ins.installment_amount
                                  WHEN s.billing_cycle = 'monthly' THEN s.price 
                                  WHEN s.billing_cycle = 'annual' THEN s.price / 12 
                                  WHEN s.billing_cycle = 'quarterly' THEN s.price / 3 
                                  WHEN s.billing_cycle = 'semi_annual' THEN s.price / 6 
                                  WHEN s.billing_cycle = 'one_time' THEN 0 
                                  ELSE s.price END), 0) as gross_monthly,
                COALESCE(SUM(CASE
                                  WHEN c.type = 'insurance' AND ins.payment_type = 'installment' AND COALESCE(ins.installment_amount, 0) > 0 THEN s.cashback_amount
                                  WHEN s.billing_cycle = 'monthly' THEN s.cashback_amount 
                                  WHEN s.billing_cycle = 'annual' THEN s.cashback_amount / 12 
                                  WHEN s.billing_cycle = 'quarterly' THEN s.cashback_amount / 3 
                                  WHEN s.billing_cycle = 'semi_annual' THEN s.cashback_amount / 6 
                                  WHEN s.billing_cycle = 'one_time' THEN 0 
                                  ELSE s.cashback_amount END), 0) as cashback_monthly,
                COALESCE(SUM((SELECT COALESCE(SUM(sh.share_amount), 0) FROM subscription_shares sh WHERE sh.subscription_id = s.id AND sh.is_paid = 1)), 0) as shares_monthly
            FROM subscriptions s
            LEFT JOIN categories c ON s.category_id = c.id
            LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
            WHERE s.status = 'active' {$locSql}
            GROUP BY s.currency
            ORDER BY FIELD(s.currency, 'TRY', 'USD', 'EUR', 'GBP'), s.currency ASC
        ");
        $stmtCurrencies->execute();
        $currRows = $stmtCurrencies->fetchAll(PDO::FETCH_ASSOC);

        $monthlyTotalsByCurrency = [];
        $netMonthlyTotalsByCurrency = [];
        $monthlySavingsByCurrency = [];
        $monthlyCashbackByCurrency = [];
        $monthlySharesByCurrency = [];

        foreach ($currRows as $r) {
            $curr = $r['currency'];
            $gross = (float)$r['gross_monthly'];
            $cashback = (float)$r['cashback_monthly'];
            $shares = (float)$r['shares_monthly'];
            $net = max(0, $gross - $cashback - $shares);
            $savings = $cashback + $shares;

            $monthlyTotalsByCurrency[$curr] = $gross;
            $netMonthlyTotalsByCurrency[$curr] = $net;
            $monthlySavingsByCurrency[$curr] = $savings;
            $monthlyCashbackByCurrency[$curr] = $cashback;
            $monthlySharesByCurrency[$curr] = $shares;
        }

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM subscriptions s WHERE s.status = 'active' {$locSql}");
        $stmtCount->execute();
        $totalActive = (int)$stmtCount->fetchColumn();

        // Admin'in Kendi Bütçesi (Brüt, Cashback, Ortak Gelir ve Net Gider)
        $stmtMyCurrencies = $pdo->prepare("
            SELECT 
                s.currency,
                COALESCE(SUM(CASE 
                    WHEN c.type = 'insurance' AND ins.payment_type = 'installment' AND COALESCE(ins.installment_amount, 0) > 0 THEN ins.installment_amount
                    WHEN s.billing_cycle = 'monthly' THEN s.price 
                    WHEN s.billing_cycle = 'annual' THEN s.price / 12 
                    WHEN s.billing_cycle = 'quarterly' THEN s.price / 3 
                    WHEN s.billing_cycle = 'semi_annual' THEN s.price / 6 
                    WHEN s.billing_cycle = 'one_time' THEN 0 
                    ELSE s.price END), 0) as gross_monthly,
                COALESCE(SUM(CASE 
                    WHEN c.type = 'insurance' AND ins.payment_type = 'installment' AND COALESCE(ins.installment_amount, 0) > 0 THEN s.cashback_amount
                    WHEN s.billing_cycle = 'monthly' THEN s.cashback_amount 
                    WHEN s.billing_cycle = 'annual' THEN s.cashback_amount / 12 
                    WHEN s.billing_cycle = 'quarterly' THEN s.cashback_amount / 3 
                    WHEN s.billing_cycle = 'semi_annual' THEN s.cashback_amount / 6 
                    WHEN s.billing_cycle = 'one_time' THEN 0 
                    ELSE s.cashback_amount END), 0) as cashback_monthly,
                COALESCE(SUM((SELECT COALESCE(SUM(sh.share_amount), 0) FROM subscription_shares sh WHERE sh.subscription_id = s.id AND sh.is_paid = 1)), 0) as shares_monthly
            FROM subscriptions s
            LEFT JOIN categories c ON s.category_id = c.id
            LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
            WHERE COALESCE(s.payer_user_id, s.user_id) = ? AND s.status = 'active' {$locSql}
            GROUP BY s.currency
            ORDER BY FIELD(s.currency, 'TRY', 'USD', 'EUR', 'GBP'), s.currency ASC
        ");
        $stmtMyCurrencies->execute([$userId]);
        $myCurrRows = $stmtMyCurrencies->fetchAll(PDO::FETCH_ASSOC);

        $myTotalsByCurrency = [];
        $myNetTotalsByCurrency = [];
        $mySavingsByCurrency = [];
        $myCashbackByCurrency = [];
        $mySharesByCurrency = [];

        foreach ($myCurrRows as $r) {
            $curr = $r['currency'];
            $gross = (float)$r['gross_monthly'];
            $cashback = (float)$r['cashback_monthly'];
            $shares = (float)$r['shares_monthly'];
            $net = max(0, $gross - $cashback - $shares);
            $savings = $cashback + $shares;

            $myTotalsByCurrency[$curr] = $gross;
            $myNetTotalsByCurrency[$curr] = $net;
            $mySavingsByCurrency[$curr] = $savings;
            $myCashbackByCurrency[$curr] = $cashback;
            $mySharesByCurrency[$curr] = $shares;
        }

        $stmtMyCount = $pdo->prepare("SELECT COUNT(*) FROM subscriptions s WHERE COALESCE(s.payer_user_id, s.user_id) = ? AND s.status = 'active' {$locSql}");
        $stmtMyCount->execute([$userId]);
        $myCount = (int)$stmtMyCount->fetchColumn();

        // Kişisel Bütçe Dağılım Raporu (Sadece Yönetici Görür)
        $stmtBudgets = $pdo->prepare("
            SELECT 
                u.id as user_id, 
                u.username, 
                u.full_name, 
                u.role,
                COALESCE(u.monthly_budget, 0) as monthly_budget,
                COUNT(s.id) as sub_count,
                COALESCE(SUM(CASE 
                    WHEN c.type = 'insurance' AND ins.payment_type = 'installment' AND COALESCE(ins.installment_amount, 0) > 0 THEN ins.installment_amount
                    WHEN s.billing_cycle = 'monthly' THEN s.price 
                    WHEN s.billing_cycle = 'annual' THEN s.price / 12 
                    WHEN s.billing_cycle = 'quarterly' THEN s.price / 3 
                    WHEN s.billing_cycle = 'semi_annual' THEN s.price / 6 
                    WHEN s.billing_cycle = 'one_time' THEN 0 
                    ELSE s.price END), 0) as user_monthly
            FROM users u
            LEFT JOIN subscriptions s ON (COALESCE(s.payer_user_id, s.user_id) = u.id AND s.status = 'active' {$locSql})
            LEFT JOIN categories c ON s.category_id = c.id
            LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
            WHERE u.status = 'active'
            GROUP BY u.id
            ORDER BY user_monthly DESC, u.full_name ASC
        ");
        $stmtBudgets->execute();
        $personalBudgets = $stmtBudgets->fetchAll();

        // Yaklaşan Ödemeler (Tümü - 10 Gün)
        $stmtUpcoming = $pdo->prepare("
            SELECT s.*, c.name as category_name, c.icon as category_icon, c.color as category_color, c.type as category_type,
                   pm.title as payment_method_title,
                   u_payer.full_name as payer_name, u_payer.username as payer_username,
                   ins.payment_type as ins_payment_type, ins.installment_count as ins_installment_count, ins.installment_amount as ins_installment_amount
            FROM subscriptions s
            LEFT JOIN categories c ON s.category_id = c.id
            LEFT JOIN payment_methods pm ON s.payment_method_id = pm.id
            LEFT JOIN users u_payer ON COALESCE(s.payer_user_id, s.user_id) = u_payer.id
            LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
            WHERE s.status = 'active' {$locSql}
              AND s.next_payment_date IS NOT NULL 
              AND s.next_payment_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 10 DAY)
            ORDER BY s.next_payment_date ASC
        ");
        $stmtUpcoming->execute();
        $upcomingPayments = $stmtUpcoming->fetchAll();

        // Taahhüt Bitişleri (Yaklaşanlar ve Süresi Dolanlar)
        $stmtCommitments = $pdo->prepare("
            SELECT s.id, s.title, s.price, s.currency, t.operator_name, t.commitment_end, t.post_commitment_price, t.speed_or_quota,
                   u_payer.full_name as payer_name, u_payer.username as payer_username,
                   DATEDIFF(t.commitment_end, CURRENT_DATE) as gun_farki
            FROM subscriptions s
            JOIN subscription_telecom_details t ON s.id = t.subscription_id
            LEFT JOIN users u_payer ON COALESCE(s.payer_user_id, s.user_id) = u_payer.id
            WHERE s.status = 'active' {$locSql}
              AND t.commitment_end IS NOT NULL
              AND t.commitment_end <= DATE_ADD(CURRENT_DATE, INTERVAL 45 DAY)
            ORDER BY t.commitment_end ASC
        ");
        $stmtCommitments->execute();
        $upcomingCommitments = $stmtCommitments->fetchAll();

        // Deneme Sürümleri (Yaklaşanlar ve Süresi Dolanlar)
        $stmtTrials = $pdo->prepare("
            SELECT s.id, s.title, s.price, s.currency, s.trial_end_date, s.is_trial,
                   u_payer.full_name as payer_name, u_payer.username as payer_username,
                   DATEDIFF(s.trial_end_date, CURRENT_DATE) as gun_farki
            FROM subscriptions s
            LEFT JOIN users u_payer ON COALESCE(s.payer_user_id, s.user_id) = u_payer.id
            WHERE s.status = 'active' AND s.is_trial = 1 {$locSql}
              AND s.trial_end_date IS NOT NULL
              AND s.trial_end_date <= DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)
            ORDER BY s.trial_end_date ASC
        ");
        $stmtTrials->execute();
        $upcomingTrials = $stmtTrials->fetchAll();

        // Yaklaşan Sigorta / Poliçe Yenilemeleri (Tümü - 45 Gün)
        $stmtInsurances = $pdo->prepare("
            SELECT s.id, s.title, s.price, s.currency, ins.company_name, ins.policy_no, ins.policy_end_date, ins.insurance_type,
                   u_payer.full_name as payer_name, u_payer.username as payer_username
            FROM subscriptions s
            JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
            LEFT JOIN users u_payer ON COALESCE(s.payer_user_id, s.user_id) = u_payer.id
            WHERE s.status = 'active' {$locSql}
              AND ins.policy_end_date IS NOT NULL
              AND ins.policy_end_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 45 DAY)
            ORDER BY ins.policy_end_date ASC
        ");
        $stmtInsurances->execute();
        $upcomingInsurances = $stmtInsurances->fetchAll();

        // Kategori Dağılımı (Tümü)
        $stmtCatStats = $pdo->prepare("
            SELECT c.name, c.color, c.type, COUNT(s.id) as count,
                   SUM(CASE 
                        WHEN c.type = 'insurance' AND ins.payment_type = 'installment' AND COALESCE(ins.installment_amount, 0) > 0 THEN ins.installment_amount
                        WHEN s.billing_cycle = 'monthly' THEN s.price 
                        WHEN s.billing_cycle = 'annual' THEN s.price / 12 
                        WHEN s.billing_cycle = 'quarterly' THEN s.price / 3 
                        WHEN s.billing_cycle = 'semi_annual' THEN s.price / 6 
                        WHEN s.billing_cycle = 'one_time' THEN 0 
                        ELSE s.price END) as total_price
            FROM subscriptions s
            JOIN categories c ON s.category_id = c.id
            LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
            WHERE s.status = 'active' {$locSql}
            GROUP BY c.id
            ORDER BY total_price DESC
        ");
        $stmtCatStats->execute();
        $categoryStats = $stmtCatStats->fetchAll();

    } else {
        // REGULAR USER: Yalnızca Kendi Bütçesi ve Sorumlu Olduğu Ödemeler (Döviz Kırılımlı)
        $stmtMyCurrencies = $pdo->prepare("
            SELECT 
                s.currency,
                COALESCE(SUM(CASE 
                    WHEN c.type = 'insurance' AND ins.payment_type = 'installment' AND COALESCE(ins.installment_amount, 0) > 0 THEN ins.installment_amount
                    WHEN s.billing_cycle = 'monthly' THEN s.price 
                    WHEN s.billing_cycle = 'annual' THEN s.price / 12 
                    WHEN s.billing_cycle = 'quarterly' THEN s.price / 3 
                    WHEN s.billing_cycle = 'semi_annual' THEN s.price / 6 
                    WHEN s.billing_cycle = 'one_time' THEN 0 
                    ELSE s.price END), 0) as gross_monthly,
                COALESCE(SUM(CASE 
                    WHEN c.type = 'insurance' AND ins.payment_type = 'installment' AND COALESCE(ins.installment_amount, 0) > 0 THEN s.cashback_amount
                    WHEN s.billing_cycle = 'monthly' THEN s.cashback_amount 
                    WHEN s.billing_cycle = 'annual' THEN s.cashback_amount / 12 
                    WHEN s.billing_cycle = 'quarterly' THEN s.cashback_amount / 3 
                    WHEN s.billing_cycle = 'semi_annual' THEN s.cashback_amount / 6 
                    WHEN s.billing_cycle = 'one_time' THEN 0 
                    ELSE s.cashback_amount END), 0) as cashback_monthly,
                COALESCE(SUM((SELECT COALESCE(SUM(sh.share_amount), 0) FROM subscription_shares sh WHERE sh.subscription_id = s.id AND sh.is_paid = 1)), 0) as shares_monthly
            FROM subscriptions s
            LEFT JOIN categories c ON s.category_id = c.id
            LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
            WHERE COALESCE(s.payer_user_id, s.user_id) = ? AND s.status = 'active' {$locSql}
            GROUP BY s.currency
            ORDER BY FIELD(s.currency, 'TRY', 'USD', 'EUR', 'GBP'), s.currency ASC
        ");
        $stmtMyCurrencies->execute([$userId]);
        $myCurrRows = $stmtMyCurrencies->fetchAll(PDO::FETCH_ASSOC);

        $myTotalsByCurrency = [];
        $myNetTotalsByCurrency = [];
        $mySavingsByCurrency = [];
        $myCashbackByCurrency = [];
        $mySharesByCurrency = [];

        foreach ($myCurrRows as $r) {
            $curr = $r['currency'];
            $gross = (float)$r['gross_monthly'];
            $cashback = (float)$r['cashback_monthly'];
            $shares = (float)$r['shares_monthly'];
            $net = max(0, $gross - $cashback - $shares);
            $savings = $cashback + $shares;

            $myTotalsByCurrency[$curr] = $gross;
            $myNetTotalsByCurrency[$curr] = $net;
            $mySavingsByCurrency[$curr] = $savings;
            $myCashbackByCurrency[$curr] = $cashback;
            $mySharesByCurrency[$curr] = $shares;
        }

        $monthlyTotalsByCurrency = $myTotalsByCurrency;
        $netMonthlyTotalsByCurrency = $myNetTotalsByCurrency;
        $monthlySavingsByCurrency = $mySavingsByCurrency;
        $monthlyCashbackByCurrency = $myCashbackByCurrency;
        $monthlySharesByCurrency = $mySharesByCurrency;

        $stmtMyCount = $pdo->prepare("SELECT COUNT(*) FROM subscriptions s WHERE COALESCE(s.payer_user_id, s.user_id) = ? AND s.status = 'active' {$locSql}");
        $stmtMyCount->execute([$userId]);
        $myCount = (int)$stmtMyCount->fetchColumn();
        $totalActive = $myCount;
        $personalBudgets = [];

        // Yaklaşan Ödemeler (Sadece Kullanıcının Ödemeleri)
        $stmtUpcoming = $pdo->prepare("
            SELECT s.*, c.name as category_name, c.icon as category_icon, c.color as category_color, c.type as category_type,
                   pm.title as payment_method_title,
                   u_payer.full_name as payer_name, u_payer.username as payer_username,
                   ins.payment_type as ins_payment_type, ins.installment_count as ins_installment_count, ins.installment_amount as ins_installment_amount
            FROM subscriptions s
            LEFT JOIN categories c ON s.category_id = c.id
            LEFT JOIN payment_methods pm ON s.payment_method_id = pm.id
            LEFT JOIN users u_payer ON COALESCE(s.payer_user_id, s.user_id) = u_payer.id
            LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
            WHERE s.status = 'active' {$locSql}
              AND COALESCE(s.payer_user_id, s.user_id) = ?
              AND s.next_payment_date IS NOT NULL 
              AND s.next_payment_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 10 DAY)
            ORDER BY s.next_payment_date ASC
        ");
        $stmtUpcoming->execute([$userId]);
        $upcomingPayments = $stmtUpcoming->fetchAll();

        // Taahhüt Bitişleri (Yaklaşanlar ve Süresi Dolanlar)
        $stmtCommitments = $pdo->prepare("
            SELECT s.id, s.title, s.price, s.currency, t.operator_name, t.commitment_end, t.post_commitment_price, t.speed_or_quota,
                   u_payer.full_name as payer_name, u_payer.username as payer_username,
                   DATEDIFF(t.commitment_end, CURRENT_DATE) as gun_farki
            FROM subscriptions s
            JOIN subscription_telecom_details t ON s.id = t.subscription_id
            LEFT JOIN users u_payer ON COALESCE(s.payer_user_id, s.user_id) = u_payer.id
            WHERE s.status = 'active' {$locSql}
              AND COALESCE(s.payer_user_id, s.user_id) = ?
              AND t.commitment_end IS NOT NULL
              AND t.commitment_end <= DATE_ADD(CURRENT_DATE, INTERVAL 45 DAY)
            ORDER BY t.commitment_end ASC
        ");
        $stmtCommitments->execute([$userId]);
        $upcomingCommitments = $stmtCommitments->fetchAll();

        // Deneme Sürümleri (Yaklaşanlar ve Süresi Dolanlar)
        $stmtTrials = $pdo->prepare("
            SELECT s.id, s.title, s.price, s.currency, s.trial_end_date, s.is_trial,
                   u_payer.full_name as payer_name, u_payer.username as payer_username,
                   DATEDIFF(s.trial_end_date, CURRENT_DATE) as gun_farki
            FROM subscriptions s
            LEFT JOIN users u_payer ON COALESCE(s.payer_user_id, s.user_id) = u_payer.id
            WHERE s.status = 'active' AND s.is_trial = 1 {$locSql}
              AND COALESCE(s.payer_user_id, s.user_id) = ?
              AND s.trial_end_date IS NOT NULL
              AND s.trial_end_date <= DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)
            ORDER BY s.trial_end_date ASC
        ");
        $stmtTrials->execute([$userId]);
        $upcomingTrials = $stmtTrials->fetchAll();

        // Yaklaşan Sigorta / Poliçe Yenilemeleri (Sadece Kullanıcının - 45 Gün)
        $stmtInsurances = $pdo->prepare("
            SELECT s.id, s.title, s.price, s.currency, ins.company_name, ins.policy_no, ins.policy_end_date, ins.insurance_type,
                   u_payer.full_name as payer_name, u_payer.username as payer_username
            FROM subscriptions s
            JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
            LEFT JOIN users u_payer ON COALESCE(s.payer_user_id, s.user_id) = u_payer.id
            WHERE s.status = 'active' {$locSql}
              AND COALESCE(s.payer_user_id, s.user_id) = ?
              AND ins.policy_end_date IS NOT NULL
              AND ins.policy_end_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 45 DAY)
            ORDER BY ins.policy_end_date ASC
        ");
        $stmtInsurances->execute([$userId]);
        $upcomingInsurances = $stmtInsurances->fetchAll();

        // Kategori Dağılımı (Sadece Kullanıcının)
        $stmtCatStats = $pdo->prepare("
            SELECT c.name, c.color, c.type, COUNT(s.id) as count,
                   SUM(CASE 
                        WHEN c.type = 'insurance' AND ins.payment_type = 'installment' AND COALESCE(ins.installment_amount, 0) > 0 THEN ins.installment_amount
                        WHEN s.billing_cycle = 'monthly' THEN s.price 
                        WHEN s.billing_cycle = 'annual' THEN s.price / 12 
                        WHEN s.billing_cycle = 'quarterly' THEN s.price / 3 
                        WHEN s.billing_cycle = 'semi_annual' THEN s.price / 6 
                        WHEN s.billing_cycle = 'one_time' THEN 0 
                        ELSE s.price END) as total_price
            FROM subscriptions s
            JOIN categories c ON s.category_id = c.id
            LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
            WHERE s.status = 'active' AND COALESCE(s.payer_user_id, s.user_id) = ? {$locSql}
            GROUP BY c.id
            ORDER BY total_price DESC
        ");
        $stmtCatStats->execute([$userId]);
        $categoryStats = $stmtCatStats->fetchAll();
    }

    // Yaklaşan Ödemelerin Döviz Bazlı Toplamı
    $upcomingTotalsByCurrency = [];
    foreach ($upcomingPayments as $up) {
        $c = $up['currency'] ?: 'TRY';
        if (!isset($upcomingTotalsByCurrency[$c])) {
            $upcomingTotalsByCurrency[$c] = 0;
        }
        $isInst = ($up['category_type'] === 'insurance' && ($up['ins_payment_type'] ?? '') === 'installment' && !empty($up['ins_installment_amount']));
        $upcomingTotalsByCurrency[$c] += $isInst ? (float)$up['ins_installment_amount'] : (float)$up['price'];
    }

} catch (Exception $e) {
    sistemLogla("Dashboard veri çekme hatası: " . $e->getMessage(), 'ERROR');
    $totalActive = 0;
    $monthlyTotalsByCurrency = [];
    $netMonthlyTotalsByCurrency = [];
    $monthlySavingsByCurrency = [];
    $monthlyCashbackByCurrency = [];
    $monthlySharesByCurrency = [];
    $myTotalsByCurrency = [];
    $myNetTotalsByCurrency = [];
    $mySavingsByCurrency = [];
    $myCashbackByCurrency = [];
    $mySharesByCurrency = [];
    $upcomingTotalsByCurrency = [];
    $myCount = 0;
    $personalBudgets = [];
    $upcomingPayments = [];
    $upcomingCommitments = [];
    $upcomingTrials = [];
    $upcomingInsurances = [];
    $categoryStats = [];
}

// Atıl Abonelik ve Tasarruf Dedektörü Verileri
$tasarrufOnerileri = tasarrufOnerileriniGetir($userId, $isAdmin, $aktifKonumId);

// 2. Kullanıcı Bütçe Limiti & Harcama Oranı Hesaplama
$userMonthlyBudget = 0.00;
try {
    $stmtUB = $pdo->prepare("SELECT COALESCE(monthly_budget, 0) FROM users WHERE id = ?");
    $stmtUB->execute([$userId]);
    $userMonthlyBudget = (float)$stmtUB->fetchColumn();
} catch (Exception $e) {
    $userMonthlyBudget = 0.00;
}

// Bütçe takibi TRY cinsinden net harcama baz alınarak yapılır
$userSpentTRY = (float)($myNetTotalsByCurrency['TRY'] ?? 0);
$hasMonthlyBudget = ($userMonthlyBudget > 0);
$budgetPercent = $hasMonthlyBudget ? round(($userSpentTRY / $userMonthlyBudget) * 100, 1) : 0;
$budgetRemaining = $hasMonthlyBudget ? ($userMonthlyBudget - $userSpentTRY) : 0;
$barWidthPercent = min(100, max(0, $budgetPercent));

if ($budgetPercent > 100) {
    $budgetStatus = 'danger';
    $budgetThemeBorder = 'border-rose-500/40';
    $budgetThemeBg = 'bg-rose-500/5 dark:bg-rose-950/20';
    $budgetBarColor = 'bg-gradient-to-r from-rose-500 to-red-600';
    $budgetBadge = 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300 border border-rose-200 dark:border-rose-500/30';
    $budgetStatusText = 'Limit Aşıldı!';
    $budgetStatusIcon = '🚨';
    $descText = 'Aylık bütçe limitinizi ' . paraFormat(abs($budgetRemaining)) . ' aştınız! Lütfen gereksiz aboneliklerinizi gözden geçirin.';
} elseif ($budgetPercent >= 75) {
    $budgetStatus = 'warning';
    $budgetThemeBorder = 'border-amber-500/40';
    $budgetThemeBg = 'bg-amber-500/5 dark:bg-amber-950/20';
    $budgetBarColor = 'bg-gradient-to-r from-amber-500 to-orange-500';
    $budgetBadge = 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300 border border-amber-200 dark:border-amber-500/30';
    $budgetStatusText = 'Bütçe Limitine Yaklaşıldı';
    $budgetStatusIcon = '⚠️';
    $descText = 'Aylık bütçenizin <b>%' . $budgetPercent . '</b> kadarı doldu. Kalan harcama payınız: <b>' . paraFormat($budgetRemaining) . '</b>.';
} else {
    $budgetStatus = 'safe';
    $budgetThemeBorder = 'border-emerald-500/30';
    $budgetThemeBg = 'bg-emerald-500/5 dark:bg-emerald-950/20';
    $budgetBarColor = 'bg-gradient-to-r from-emerald-500 to-teal-500';
    $budgetBadge = 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30';
    $budgetStatusText = 'Bütçeniz Güvende';
    $budgetStatusIcon = '🎯';
    $descText = 'Aylık bütçenizin <b>%' . $budgetPercent . '</b> kadarı kullanıldı. Kalan harcama payınız: <b>' . paraFormat($budgetRemaining) . '</b>.';
}
?>

<!-- Üst Bilgi Başlığı -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">Finansal Genel Bakış</h1>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">Sabit giderleriniz, taahhüt alarmlarınız ve yaklaşan ödemeleriniz.</p>
    </div>
    <div class="flex items-center gap-2">
        <a href="abonelik-ekle.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-md shadow-blue-600/20">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
            <span>Abonelik Ekle</span>
        </a>
    </div>
</div>

<!-- İstatistik Kartları (3'lü Grid) -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
    
    <?php if ($isAdmin): ?>
        <!-- ADMIN Kart 1: Net Aylık Toplam Gider (Hane / Ekip) -->
        <div class="p-5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl relative overflow-hidden shadow-sm transition-colors">
            <div class="absolute top-0 right-0 p-5 opacity-10">
                <svg class="w-20 h-20 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-2">Net Aylık Gider (Genel Hane / Ekip)</div>
            <div class="min-w-0 max-w-full overflow-hidden"><?= dovizRozetleriHtml($netMonthlyTotalsByCurrency) ?></div>
            <div class="mt-2.5 pt-2 border-t border-slate-100 dark:border-slate-800 text-[11px] text-slate-500 dark:text-slate-400 flex flex-wrap items-center justify-between gap-1">
                <span>Brüt: <b><?= dovizKucukRozetlerHtml($monthlyTotalsByCurrency) ?></b></span>
                <?php if (array_sum($monthlySavingsByCurrency) > 0): ?>
                    <span class="text-emerald-600 dark:text-emerald-400 font-bold bg-emerald-50 dark:bg-emerald-500/10 px-2 py-0.5 rounded-lg">
                        🎁 -<?= dovizKucukRozetlerHtml($monthlySavingsByCurrency) ?> Tasarruf
                    </span>
                <?php endif; ?>
            </div>
            <div class="mt-1 text-[11px] text-blue-600 dark:text-blue-400 font-medium flex flex-wrap items-center justify-between gap-1">
                <span>Yıllık Net Projeksiyon:</span>
                <span class="font-bold text-slate-700 dark:text-slate-200"><?= dovizYillikProjeksiyonHtml($netMonthlyTotalsByCurrency) ?></span>
            </div>
        </div>

        <!-- ADMIN Kart 2: Benim Net Bütçem / Sorumlu Olduğum Pay -->
        <div class="p-5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl relative overflow-hidden shadow-sm transition-colors">
            <div class="absolute top-0 right-0 p-5 opacity-10">
                <svg class="w-20 h-20 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
            </div>
            <div class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 mb-2">Benim Net Bütçem (Sorumluluğum)</div>
            <div class="min-w-0 max-w-full overflow-hidden"><?= dovizRozetleriHtml($myNetTotalsByCurrency, 'emerald') ?></div>
            <div class="mt-2.5 pt-2 border-t border-slate-100 dark:border-slate-800 text-[11px] text-slate-500 dark:text-slate-400 flex flex-wrap items-center justify-between gap-1">
                <span>Brüt: <b><?= dovizKucukRozetlerHtml($myTotalsByCurrency) ?></b></span>
                <?php if (array_sum($mySavingsByCurrency) > 0): ?>
                    <span class="text-emerald-600 dark:text-emerald-400 font-bold bg-emerald-50 dark:bg-emerald-500/10 px-2 py-0.5 rounded-lg">
                        +<?= dovizKucukRozetlerHtml($mySavingsByCurrency) ?> Katkı
                    </span>
                <?php endif; ?>
            </div>
            <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400 flex flex-wrap items-center justify-between gap-1">
                <span>Aktif Kalem: <strong class="text-emerald-600 dark:text-emerald-400"><?= $myCount ?> Adet</strong></span>
                <span>Toplam: <?= $totalActive ?> Abonelik</span>
            </div>
        </div>
    <?php else: ?>
        <!-- USER Kart 1: Net Aylık Sabit Bütçem -->
        <div class="p-5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl relative overflow-hidden shadow-sm transition-colors">
            <div class="absolute top-0 right-0 p-5 opacity-10">
                <svg class="w-20 h-20 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 mb-2">Net Aylık Sabit Bütçem</div>
            <div class="min-w-0 max-w-full overflow-hidden"><?= dovizRozetleriHtml($myNetTotalsByCurrency, 'emerald') ?></div>
            <div class="mt-2.5 pt-2 border-t border-slate-100 dark:border-slate-800 text-[11px] text-slate-500 dark:text-slate-400 flex flex-wrap items-center justify-between gap-1">
                <span>Brüt: <b><?= dovizKucukRozetlerHtml($myTotalsByCurrency) ?></b></span>
                <?php if (array_sum($mySavingsByCurrency) > 0): ?>
                    <span class="text-emerald-600 dark:text-emerald-400 font-bold bg-emerald-50 dark:bg-emerald-500/10 px-2 py-0.5 rounded-lg">
                        🎁 -<?= dovizKucukRozetlerHtml($mySavingsByCurrency) ?> Tasarruf
                    </span>
                <?php endif; ?>
            </div>
            <div class="mt-1 text-[11px] text-emerald-600 dark:text-emerald-400 font-medium flex flex-wrap items-center justify-between gap-1">
                <span>Yıllık Net Projeksiyon:</span>
                <span class="font-bold text-slate-700 dark:text-slate-200"><?= dovizYillikProjeksiyonHtml($myNetTotalsByCurrency) ?></span>
            </div>
        </div>

        <!-- USER Kart 2: Aktif Aboneliklerim -->
        <div class="p-5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl relative overflow-hidden shadow-sm transition-colors">
            <div class="absolute top-0 right-0 p-5 opacity-10">
                <svg class="w-20 h-20 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            </div>
            <div class="text-xs font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400 mb-2">Aktif Aboneliklerim</div>
            <div class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight"><?= $myCount ?> <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">Adet</span></div>
            <div class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                <span>Sorumlu olduğunuz aktif harcama kalemleri</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Kart 3: Yaklaşan Ödeme Sayısı (10 Gün) -->
    <div class="p-5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl relative overflow-hidden shadow-sm transition-colors sm:col-span-2 lg:col-span-1">
        <div class="absolute top-0 right-0 p-5 opacity-10">
            <svg class="w-20 h-20 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        </div>
        <div class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-2">Önümüzdeki 10 Gün <?= $isAdmin ? '(Genel)' : '(Bana Ait)' ?></div>
        <div class="text-3xl font-extrabold text-amber-600 dark:text-amber-400 tracking-tight"><?= count($upcomingPayments) ?> <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">Ödeme</span></div>
        <div class="mt-3 text-xs text-slate-500 dark:text-slate-400 flex flex-wrap items-center justify-between gap-1">
            <span>Toplam Tutar:</span>
            <div class="min-w-0 max-w-full"><?= dovizRozetleriHtml($upcomingTotalsByCurrency) ?></div>
        </div>
    </div>

</div>

<!-- Aylık Bütçe Limiti & Harcama Uyarı Çubuğu -->
<div id="monthly-budget-container" class="mb-6">
    <?php if ($hasMonthlyBudget): ?>
        <div class="bg-white dark:bg-slate-900 border <?= $budgetThemeBorder ?> rounded-3xl p-5 sm:p-6 shadow-sm transition-all duration-300 relative overflow-hidden <?= $budgetThemeBg ?>">
            <!-- Üst Başlık ve Rozet -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 mb-4 border-b border-slate-100 dark:border-slate-800/80">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl flex items-center justify-center text-xl shadow-sm bg-white dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700">
                        <?= $budgetStatusIcon ?>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-bold text-slate-900 dark:text-white">Aylık Bütçe Limiti &amp; Harcama Takibi</h2>
                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-extrabold <?= $budgetBadge ?> shadow-sm">
                                <?= $budgetStatusText ?> (%<?= $budgetPercent ?>)
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                            <?= $descText ?>
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2 self-start sm:self-auto">
                    <button type="button" onclick="toggleBudgetEdit()" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-xl text-xs font-bold transition flex items-center gap-1.5 border border-slate-200 dark:border-slate-700">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                        <span>Limiti Düzenle</span>
                    </button>
                    <a href="profil.php" class="p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition" title="Profil Ayarları">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    </a>
                </div>
            </div>

            <!-- Hızlı Bütçe Güncelleme Çekmecesi / Formu (Varsayılan gizli) -->
            <div id="budget-edit-box" class="hidden mb-4 p-4 bg-white/90 dark:bg-slate-950/80 border border-slate-200 dark:border-slate-800 rounded-2xl backdrop-blur-sm">
                <form id="form-update-budget" onsubmit="saveBudgetAjax(event)" class="flex flex-col sm:flex-row items-center gap-2.5">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                    <div class="relative w-full sm:w-64">
                        <input type="number" step="0.01" min="0" name="monthly_budget" id="input_monthly_budget" value="<?= htmlspecialchars((string)$userMonthlyBudget) ?>" placeholder="Örn: 3500.00" required
                            class="w-full p-2.5 pl-8 bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl text-slate-900 dark:text-white text-xs font-bold focus:ring-2 focus:ring-blue-500 outline-none">
                        <span class="absolute left-3 top-2.5 text-slate-400 font-bold text-xs">₺</span>
                    </div>
                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <button type="submit" id="btn-save-budget" class="flex-1 sm:flex-initial px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition shadow-sm">
                            Kaydet
                        </button>
                        <button type="button" onclick="toggleBudgetEdit()" class="px-3 py-2.5 bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-xl text-xs font-bold transition">
                            Vazgeç
                        </button>
                    </div>
                    <span class="text-[11px] text-slate-400 sm:ml-auto">Bütçeyi kapatmak için 0 yazabilirsiniz.</span>
                </form>
            </div>

            <!-- İstatistik Sayıları (4'lü Grid) -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
                <div class="p-3 bg-white/70 dark:bg-slate-950/50 border border-slate-100 dark:border-slate-800/80 rounded-2xl">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-0.5">Harcanan (Net)</span>
                    <span class="text-base sm:text-lg font-black text-slate-900 dark:text-white"><?= paraFormat($userSpentTRY) ?></span>
                </div>
                <div class="p-3 bg-white/70 dark:bg-slate-950/50 border border-slate-100 dark:border-slate-800/80 rounded-2xl">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-0.5">Hedef Bütçe</span>
                    <span class="text-base sm:text-lg font-black text-slate-700 dark:text-slate-300"><?= paraFormat($userMonthlyBudget) ?></span>
                </div>
                <div class="p-3 bg-white/70 dark:bg-slate-950/50 border border-slate-100 dark:border-slate-800/80 rounded-2xl">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-0.5"><?= $budgetRemaining >= 0 ? 'Kalan Bütçe' : 'Bütçe Aşımı' ?></span>
                    <span class="text-base sm:text-lg font-black <?= $budgetRemaining >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?>">
                        <?= $budgetRemaining >= 0 ? paraFormat($budgetRemaining) : '-' . paraFormat(abs($budgetRemaining)) ?>
                    </span>
                </div>
                <div class="p-3 bg-white/70 dark:bg-slate-950/50 border border-slate-100 dark:border-slate-800/80 rounded-2xl">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-0.5">Kullanım Oranı</span>
                    <span class="text-base sm:text-lg font-black <?= $budgetPercent > 100 ? 'text-rose-600 dark:text-rose-400' : ($budgetPercent >= 75 ? 'text-amber-600 dark:text-amber-400' : 'text-blue-600 dark:text-blue-400') ?>">
                        %<?= $budgetPercent ?>
                    </span>
                </div>
            </div>

            <!-- İlerleme Çubuğu (Progress Bar) -->
            <div class="space-y-1.5">
                <div class="relative w-full h-4 bg-slate-200/80 dark:bg-slate-800 rounded-full p-0.5 overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-700 ease-out <?= $budgetBarColor ?>" style="width: <?= $barWidthPercent ?>%;"></div>
                    <!-- %75 Uyarı Çizgisi -->
                    <div class="absolute top-0 bottom-0 left-3/4 w-0.5 bg-amber-400/50 dark:bg-amber-400/30" title="%75 Eşiği"></div>
                </div>
                <div class="flex items-center justify-between text-[10px] font-semibold text-slate-400 px-1">
                    <span>0 ₺</span>
                    <span class="hidden sm:inline text-amber-500/80">%75 Uyarı Seviyesi</span>
                    <span>Hedef: <?= paraFormat($userMonthlyBudget) ?></span>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Bütçe Belirlenmemiş Durum (Tanıtım ve Hızlı Ekleme) -->
        <div class="bg-gradient-to-r from-blue-500/10 via-indigo-500/10 to-purple-500/10 border border-blue-500/20 rounded-3xl p-5 sm:p-6 shadow-sm">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex items-start gap-3.5">
                    <div class="w-11 h-11 rounded-2xl bg-blue-500/20 text-blue-600 dark:text-blue-400 flex items-center justify-center text-2xl shadow-sm shrink-0">
                        🎯
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-bold text-slate-900 dark:text-white">Aylık Bütçe Limiti Belirleyin</h2>
                        </div>
                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1 max-w-xl">
                            Aylık maksimum ne kadar harcamak istediğinizi belirleyin. Bütçenizin doluluk oranını, kalan limitinizi ve harcama alarmlarını bu alandan anlık takip edin.
                        </p>
                    </div>
                </div>

                <form id="form-first-budget" onsubmit="saveBudgetAjax(event)" class="flex items-center gap-2 shrink-0">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                    <div class="relative w-44 sm:w-48">
                        <input type="number" step="0.01" min="1" name="monthly_budget" placeholder="Örn: 3500" required
                            class="w-full p-2.5 pl-8 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl text-slate-900 dark:text-white text-xs font-bold focus:ring-2 focus:ring-blue-500 outline-none">
                        <span class="absolute left-3 top-2.5 text-slate-400 font-bold text-xs">₺</span>
                    </div>
                    <button type="submit" class="px-4 py-2.5 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white rounded-xl text-xs font-bold transition shadow-md shadow-blue-500/20 whitespace-nowrap">
                        Hedef Belirle
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Kişisel Bütçeler & Sorumluluk Dağılımı Raporu (SADECE YÖNETİCİ GÖRÜR) -->
<?php if ($isAdmin && !empty($personalBudgets) && count($personalBudgets) > 1): ?>
<div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 shadow-sm mb-6 transition-colors">
    <div class="flex items-center justify-between pb-3 mb-4 border-b border-slate-100 dark:border-slate-800">
        <div>
            <h2 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                <span>👑 Yönetici Raporu: Kişisel Bütçeler &amp; Sorumluluk Dağılımı</span>
            </h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Hane ve ekip üyelerinin aylık harcama sorumlulukları.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
        <?php foreach ($personalBudgets as $pb): ?>
            <div class="p-3.5 bg-slate-50 dark:bg-slate-950/60 border border-slate-200 dark:border-slate-800/80 rounded-xl">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs font-bold text-slate-800 dark:text-slate-200 truncate">
                        <?= htmlspecialchars($pb['full_name'] ?: $pb['username']) ?>
                        <?php if ($pb['user_id'] == $userId): ?><span class="text-emerald-500 text-[10px]">(Siz)</span><?php endif; ?>
                    </span>
                    <span class="text-[10px] font-semibold text-slate-400"><?= $pb['sub_count'] ?> Abonelik</span>
                </div>
                <div class="text-base font-extrabold text-blue-600 dark:text-blue-400">
                    <?= paraFormat($pb['user_monthly']) ?>
                </div>
                <?php if ((float)($pb['monthly_budget'] ?? 0) > 0): 
                    $uPct = round(((float)$pb['user_monthly'] / (float)$pb['monthly_budget']) * 100, 1);
                    $uBar = min(100, max(0, $uPct));
                    $uColor = $uPct > 100 ? 'bg-rose-500' : ($uPct >= 75 ? 'bg-amber-500' : 'bg-emerald-500');
                    $uTextColor = $uPct > 100 ? 'text-rose-500' : ($uPct >= 75 ? 'text-amber-500' : 'text-emerald-500');
                ?>
                    <div class="mt-2 pt-2 border-t border-slate-200/60 dark:border-slate-800/80">
                        <div class="flex items-center justify-between text-[10px] text-slate-500 dark:text-slate-400 mb-1">
                            <span>Hedef: <?= paraFormat($pb['monthly_budget']) ?></span>
                            <span class="font-bold <?= $uTextColor ?>">%<?= $uPct ?></span>
                        </div>
                        <div class="w-full bg-slate-200 dark:bg-slate-800 h-1.5 rounded-full overflow-hidden">
                            <div class="<?= $uColor ?> h-full rounded-full transition-all duration-500" style="width: <?= $uBar ?>%;"></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Atıl Abonelik & Tasarruf Dedektörü -->
<?php if (!empty($tasarrufOnerileri['count'])): ?>
<div class="bg-gradient-to-r from-emerald-500/10 via-teal-500/10 to-blue-500/10 border border-emerald-500/30 rounded-3xl p-5 sm:p-6 mb-6 shadow-sm">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 mb-4 border-b border-emerald-500/20">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-2xl bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xl shadow-sm">
                💡
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">Atıl Abonelik &amp; Tasarruf Dedektörü</h2>
                    <span class="px-2 py-0.5 bg-emerald-500 text-white rounded-full text-[10px] font-extrabold shadow-sm">
                        <?= $tasarrufOnerileri['count'] ?> Tasarruf Önerisi
                    </span>
                </div>
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-0.5">
                    Kullanılmayan, dondurulan veya bitmek üzere olan servisleri tespit ettik.
                </p>
            </div>
        </div>
        <div class="text-left sm:text-right bg-white/70 dark:bg-slate-900/70 p-2.5 rounded-2xl border border-emerald-500/20">
            <span class="text-[10px] uppercase font-bold text-slate-500 dark:text-slate-400 block">Yıllık Tasarruf Potansiyeli</span>
            <span class="text-base sm:text-lg font-black text-emerald-600 dark:text-emerald-400">
                +<?= dovizKucukRozetlerHtml($tasarrufOnerileri['total_annual_savings']) ?> / yıl
            </span>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5">
        <?php foreach ($tasarrufOnerileri['items'] as $item): ?>
            <div class="bg-white/90 dark:bg-slate-900/90 backdrop-blur-sm border border-slate-200/80 dark:border-slate-800/80 rounded-2xl p-4 flex flex-col justify-between shadow-xs hover:shadow-md transition">
                <div>
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold <?= $item['badge_class'] ?>">
                            <?= $item['badge'] ?>
                        </span>
                        <span class="text-xs font-black text-emerald-600 dark:text-emerald-400">
                            +<?= paraFormat($item['annual_savings'], $item['currency']) ?> / yıl
                        </span>
                    </div>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white truncate">
                        <?= htmlspecialchars($item['title']) ?>
                    </h3>
                    <p class="text-[11px] text-slate-600 dark:text-slate-400 mt-1 line-clamp-2">
                        <?= $item['recommendation'] ?>
                    </p>
                </div>

                <div class="flex items-center justify-end gap-2 mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-800">
                    <?php if (!empty($item['can_cancel_direct'])): ?>
                        <button type="button" onclick="abonelikIptalEt(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['title'])) ?>')" class="px-3 py-1.5 bg-rose-50 dark:bg-rose-500/10 hover:bg-rose-100 dark:hover:bg-rose-500/20 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-500/20 rounded-xl text-xs font-bold transition">
                            İptal Et
                        </button>
                    <?php endif; ?>
                    <a href="abonelik-detay.php?id=<?= $item['id'] ?>" class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-xl text-xs font-bold transition">
                        İncele &rarr;
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Kritik Alarmlar (Deneme Sürümü & Taahhüt Bitişi & Sigorta Yenileme) -->
<?php if (!empty($upcomingTrials) || !empty($upcomingCommitments) || !empty($upcomingInsurances)): ?>
<div class="mb-6 space-y-3">
    
    <!-- Sigorta / Poliçe Yenileme Uyarıları -->
    <?php foreach ($upcomingInsurances as $ins): 
        $kalan = kalanGunHesapla($ins['policy_end_date']);
    ?>
        <div class="p-4 bg-rose-500/10 border border-rose-500/20 rounded-2xl flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-rose-500/20 text-rose-400 flex items-center justify-center font-bold text-lg">🛡️</div>
                <div>
                    <div class="text-sm font-bold text-white flex items-center gap-2">
                        <span><?= htmlspecialchars($ins['title']) ?> (<?= htmlspecialchars($ins['company_name'] ?: 'Poliçe') ?>)</span>
                        <span class="px-2 py-0.5 bg-rose-500/20 text-rose-300 text-[10px] rounded-md font-bold">Poliçe Yenileme Zamanı</span>
                    </div>
                    <div class="text-xs text-slate-400 mt-0.5">
                        Poliçe süresi <strong class="text-white"><?= $kalan <= 0 ? 'Bugün doluyor!' : "{$kalan} gün sonra" ?></strong> bitiyor (<?= tarihFormat($ins['policy_end_date']) ?>).
                        <?php if (!empty($ins['policy_no'])): ?>
                            Poliçe No: <strong class="text-slate-300 font-mono"><?= htmlspecialchars($ins['policy_no']) ?></strong>.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <a href="abonelik-detay.php?id=<?= $ins['id'] ?>" class="px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold transition whitespace-nowrap">
                Poliçeyi Gör
            </a>
        </div>
    <?php endforeach; ?>

    <!-- Deneme Sürümü Uyarıları (Süresi Dolanlar ve Yaklaşanlar) -->
    <?php foreach ($upcomingTrials as $trial): 
        $gunFarki = (int)($trial['gun_farki'] ?? 0);
        $isExpired = ($gunFarki < 0);
    ?>
        <?php if ($isExpired): ?>
            <!-- SÜRESİ DOLMUŞ DENEME SÜRÜMÜ -->
            <div class="p-4 bg-rose-500/10 border border-rose-500/30 rounded-2xl flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-rose-500/20 text-rose-400 flex items-center justify-center font-bold text-lg">⚠️</div>
                    <div>
                        <div class="text-sm font-bold text-white flex flex-wrap items-center gap-2">
                            <span><?= htmlspecialchars($trial['title']) ?></span>
                            <span class="px-2 py-0.5 bg-rose-500/20 text-rose-300 border border-rose-500/30 text-[10px] rounded-md font-bold">⚠️ Deneme Süresi Doldu (Normal Ücretlendirmeye Geçildi)</span>
                        </div>
                        <div class="text-xs text-slate-400 mt-0.5">
                            Ücretsiz deneme süresi <strong class="text-white"><?= abs($gunFarki) ?> gün önce</strong> (<?= tarihFormat($trial['trial_end_date']) ?>) doldu. Artık <strong class="text-rose-300"><?= paraFormat($trial['price'], $trial['currency']) ?></strong> üzerinden faturalandırılıyor.
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2 ml-auto sm:ml-0">
                    <button type="button" onclick="normalAboneligeCevir(<?= $trial['id'] ?>, '<?= htmlspecialchars(addslashes($trial['title'])) ?>')" class="px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition whitespace-nowrap shadow-sm">
                        ✓ Normal Aboneliğe Çevir
                    </button>
                    <a href="abonelik-detay.php?id=<?= $trial['id'] ?>" class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-xl text-xs font-semibold transition whitespace-nowrap">
                        İncele
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- BİTİŞİ YAKLAŞAN DENEME SÜRÜMÜ -->
            <div class="p-4 bg-purple-500/10 border border-purple-500/20 rounded-2xl flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-purple-500/20 text-purple-400 flex items-center justify-center font-bold text-lg">⏳</div>
                    <div>
                        <div class="text-sm font-bold text-white flex items-center gap-2">
                            <span><?= htmlspecialchars($trial['title']) ?></span>
                            <span class="px-2 py-0.5 bg-purple-500/20 text-purple-300 text-[10px] rounded-md font-bold">Ücretsiz Deneme Bitiyor</span>
                        </div>
                        <div class="text-xs text-slate-400 mt-0.5">
                            <?= $gunFarki <= 0 ? 'Bugün sona eriyor!' : "{$gunFarki} gün sonra sona erecek (" . tarihFormat($trial['trial_end_date']) . ")" ?>. 
                            İptal edilmezse <strong class="text-purple-300"><?= paraFormat($trial['price'], $trial['currency']) ?></strong> çekilecek.
                        </div>
                    </div>
                </div>
                <a href="abonelik-detay.php?id=<?= $trial['id'] ?>" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-bold transition whitespace-nowrap">
                    İncele
                </a>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <!-- Taahhüt Bitiş Uyarıları (Süresi Dolanlar ve Yaklaşanlar) -->
    <?php foreach ($upcomingCommitments as $comm): 
        $gunFarki = (int)($comm['gun_farki'] ?? 0);
        $isExpired = ($gunFarki < 0);
    ?>
        <?php if ($isExpired): ?>
            <!-- TAAHHÜDÜ DOLMUŞ TELEKOM -->
            <div class="p-4 bg-rose-500/10 border border-rose-500/30 rounded-2xl flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-rose-500/20 text-rose-400 flex items-center justify-center font-bold text-lg">⚠️</div>
                    <div>
                        <div class="text-sm font-bold text-white flex flex-wrap items-center gap-2">
                            <span><?= htmlspecialchars($comm['title']) ?> (<?= htmlspecialchars($comm['operator_name'] ?? 'Telekom') ?>)</span>
                            <span class="px-2 py-0.5 bg-rose-500/20 text-rose-300 border border-rose-500/30 text-[10px] rounded-md font-bold">Taahhüt Bitti: Güncel Tarifeye Uyarla</span>
                        </div>
                        <div class="text-xs text-slate-400 mt-0.5">
                            Taahhüt süresi <strong class="text-white"><?= abs($gunFarki) ?> gün önce</strong> (<?= tarihFormat($comm['commitment_end']) ?>) sona erdi.
                            <?php if (!empty($comm['post_commitment_price']) && (float)$comm['post_commitment_price'] > (float)$comm['price']): ?>
                                Faturanız indirimden çıkarak <strong class="text-rose-400 font-bold"><?= paraFormat($comm['post_commitment_price'], $comm['currency']) ?></strong> seviyesine yükseldi!
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2 ml-auto sm:ml-0">
                    <?php if (!empty($comm['post_commitment_price']) && (float)$comm['post_commitment_price'] > (float)$comm['price']): ?>
                        <button type="button" onclick="tarifeyeUyarla(<?= $comm['id'] ?>, '<?= htmlspecialchars(addslashes($comm['title'])) ?>', '<?= paraFormat($comm['post_commitment_price'], $comm['currency']) ?>')" class="px-3.5 py-1.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold transition whitespace-nowrap shadow-sm">
                            ⚡ Güncel Tarifeye Uyarla
                        </button>
                    <?php endif; ?>
                    <a href="abonelik-detay.php?id=<?= $comm['id'] ?>" class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-xl text-xs font-semibold transition whitespace-nowrap">
                        Detaylar
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- YAKLAŞAN TAAHHÜT BİTİŞİ -->
            <div class="p-4 bg-amber-500/10 border border-amber-500/20 rounded-2xl flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center font-bold text-lg">⚠️</div>
                    <div>
                        <div class="text-sm font-bold text-white flex items-center gap-2">
                            <span><?= htmlspecialchars($comm['title']) ?> (<?= htmlspecialchars($comm['operator_name'] ?? 'Telekom') ?>)</span>
                            <span class="px-2 py-0.5 bg-amber-500/20 text-amber-300 text-[10px] rounded-md font-bold">Taahhüt Bitişi Yakın</span>
                        </div>
                        <div class="text-xs text-slate-400 mt-0.5">
                            Taahhüt <strong class="text-white"><?= $gunFarki ?> gün</strong> sonra bitiyor (<?= tarihFormat($comm['commitment_end']) ?>).
                            <?php if ($comm['post_commitment_price'] > 0): ?>
                                Yenilenmezse faturanız <span class="line-through"><?= paraFormat($comm['price'], $comm['currency']) ?></span> yerine 
                                <strong class="text-rose-400 font-bold"><?= paraFormat($comm['post_commitment_price'], $comm['currency']) ?></strong> olacak!
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <a href="abonelik-detay.php?id=<?= $comm['id'] ?>" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold transition whitespace-nowrap">
                    Detaylar
                </a>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

</div>
<?php endif; ?>

<!-- 2 Sütunlu Ana Bölüm: Yaklaşan Ödemeler & Kategori Dağılımı -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    
    <!-- Sol Sütun: Yaklaşan Ödemeler Listesi (2 Birim Genişlik) -->
    <div class="lg:col-span-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 shadow-sm transition-colors">
        <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-slate-800">
            <h2 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                <span>Yaklaşan Ödemeler (10 Gün)</span>
            </h2>
            <a href="abonelikler.php" class="text-xs text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-semibold transition">
                Tümünü Gör &rarr;
            </a>
        </div>

        <?php if (empty($upcomingPayments)): ?>
            <div class="py-12 text-center">
                <div class="w-12 h-12 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center mx-auto mb-3 text-xl">✓</div>
                <p class="text-sm font-semibold text-slate-700 dark:text-slate-300">Önümüzdeki 10 gün içinde ödemeniz bulunmuyor.</p>
                <p class="text-xs text-slate-500 mt-1">Harika! Tüm faturalarınız ve abonelikleriniz güvende.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($upcomingPayments as $item): 
                    $kalan = kalanGunHesapla($item['next_payment_date']);
                ?>
                    <div class="py-3.5 flex items-center justify-between gap-3 hover:bg-slate-50 dark:hover:bg-slate-800/50 px-2 rounded-xl transition">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold text-sm">
                                <?= mb_substr($item['title'], 0, 1) ?>
                            </div>
                            <div>
                                <a href="abonelik-detay.php?id=<?= $item['id'] ?>" class="text-sm font-bold text-slate-900 dark:text-white hover:text-blue-600 dark:hover:text-blue-400 transition block">
                                    <?= htmlspecialchars($item['title']) ?>
                                </a>
                                <div class="text-xs text-slate-500 dark:text-slate-400 flex flex-wrap items-center gap-2 mt-0.5">
                                    <span><?= htmlspecialchars($item['category_name'] ?? 'Genel') ?></span>
                                    <span>&bull;</span>
                                    <span class="text-blue-600 dark:text-blue-400 font-medium">💳 <?= htmlspecialchars($item['payer_name'] ?: ($item['payer_username'] ?? 'Genel')) ?><?= ($item['payer_user_id'] == $userId) ? ' (Ben)' : '' ?></span>
                                    <?php if (!empty($item['payment_method_title'])): ?>
                                        <span>&bull;</span>
                                        <span><?= htmlspecialchars($item['payment_method_title']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php 
                            $isTaksitItem = ($item['category_type'] === 'insurance' && ($item['ins_payment_type'] ?? '') === 'installment' && !empty($item['ins_installment_amount']));
                            $itemPrice = $isTaksitItem ? (float)$item['ins_installment_amount'] : (float)$item['price'];
                        ?>
                        <div class="text-right">
                            <div class="text-sm font-bold text-slate-900 dark:text-white flex items-center justify-end gap-1">
                                <span><?= paraFormat($itemPrice, $item['currency']) ?></span>
                                <?php if ($isTaksitItem): ?>
                                    <span class="text-[9px] bg-amber-500/10 text-amber-600 dark:text-amber-400 px-1 py-0.5 rounded font-bold border border-amber-500/20" title="<?= (int)($item['ins_installment_count'] ?? 1) ?> Taksit">Taksit</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-[11px] font-semibold <?= $kalan <= 2 ? 'text-rose-500 dark:text-rose-400' : 'text-amber-500 dark:text-amber-400' ?>">
                                <?= $kalan === 0 ? 'Bugün!' : ($kalan === 1 ? 'Yarın!' : "{$kalan} gün kaldı") ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Sağ Sütun: Kategori Harcama Dağılımı (1 Birim Genişlik) -->
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 shadow-sm flex flex-col justify-between transition-colors">
        <div>
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-slate-800">
                <h2 class="text-base font-bold text-slate-900 dark:text-white">Kategori Dağılımı</h2>
                <span class="text-xs text-slate-500 dark:text-slate-400">Aylık Pay</span>
            </div>

            <?php if (empty($categoryStats)): ?>
                <div class="py-12 text-center text-slate-400 text-xs">
                    Henüz abonelik eklenmedi.
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php 
                    $catTotalSum = array_sum(array_column($categoryStats, 'total_price'));
                    foreach ($categoryStats as $cat): 
                        $yuzde = $catTotalSum > 0 ? round(($cat['total_price'] / $catTotalSum) * 100) : 0;
                    ?>
                        <div>
                            <div class="flex items-center justify-between text-xs mb-1.5">
                                <span class="font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                                    <span><?= htmlspecialchars($cat['name']) ?></span>
                                    <span class="text-[10px] text-slate-400">(<?= $cat['count'] ?>)</span>
                                </span>
                                <span class="font-bold text-slate-900 dark:text-white"><?= paraFormat($cat['total_price']) ?> <span class="text-slate-400 text-[10px]">(%<?= $yuzde ?>)</span></span>
                            </div>
                            <div class="w-full h-2 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                <div class="h-full bg-blue-500 rounded-full" style="width: <?= $yuzde ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="mt-6 pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
            <span>Fatura Takvimi Görünümü</span>
            <a href="fatura-takvimi.php" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-bold transition">Aç &rarr;</a>
        </div>
    </div>

</div>

<!-- Deneme ve Taahhüt Güncelleme Scriptleri -->
<script>
function normalAboneligeCevir(subId, title) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Normal Aboneliğe Çevirilsin mi?',
            text: `"${title}" aboneliğinin deneme sürümü statüsü kaldırılacak ve normal ücretli abonelik olarak takip edilecektir.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Evet, Çevir',
            cancelButtonText: 'Vazgeç',
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#64748b'
        }).then((result) => {
            if (result.isConfirmed) {
                postNormalAbonelik(subId);
            }
        });
    } else {
        if (confirm(`"${title}" aboneliği normal aboneliğe çevrilsin mi?`)) {
            postNormalAbonelik(subId);
        }
    }
}

function postNormalAbonelik(subId) {
    const formData = new FormData();
    formData.append('action', 'trial_to_normal');
    formData.append('subscription_id', subId);
    formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');

    fetch('ajax.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Başarılı!', res.message, 'success').then(() => location.reload());
                } else {
                    alert(res.message);
                    location.reload();
                }
            } else {
                alert(res.message);
            }
        })
        .catch(() => alert('Sunucu bağlantı hatası.'));
}

function tarifeyeUyarla(subId, title, priceFormatted) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Tarife Fiyatı Güncellensin mi?',
            text: `"${title}" için taahhüt süresi dolduğundan güncel tarife tutarı olan ${priceFormatted} yeni abonelik fiyatı olarak kaydedilecek.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Evet, Fiyatı Güncelle',
            cancelButtonText: 'Vazgeç',
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#64748b'
        }).then((result) => {
            if (result.isConfirmed) {
                postTarifeyeUyarla(subId);
            }
        });
    } else {
        if (confirm(`"${title}" için tarife fiyatı ${priceFormatted} olarak güncellensin mi?`)) {
            postTarifeyeUyarla(subId);
        }
    }
}

function postTarifeyeUyarla(subId) {
    const formData = new FormData();
    formData.append('action', 'apply_post_commitment_price');
    formData.append('subscription_id', subId);
    formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');

    fetch('ajax.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Güncellendi!', res.message, 'success').then(() => location.reload());
                } else {
                    alert(res.message);
                    location.reload();
                }
            } else {
                alert(res.message);
            }
        })
        .catch(() => alert('Sunucu bağlantı hatası.'));
}

function abonelikIptalEt(subId, baslik) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Aboneliği İptal Et',
            text: `"${baslik}" aboneliğini iptal edildi olarak işaretlemek istiyor musunuz?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Evet, İptal Et',
            cancelButtonText: 'Vazgeç'
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('action', 'durum_degistir');
                formData.append('subscription_id', subId);
                formData.append('status', 'canceled');
                formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');

                fetch('ajax.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Başarılı',
                                text: data.message || 'Abonelik iptal edildi.',
                                showConfirmButton: false,
                                timer: 1500
                            }).then(() => location.reload());
                        } else {
                            Swal.fire('Hata', data.message || 'İşlem gerçekleştirilemedi.', 'error');
                        }
                    })
                    .catch(() => Swal.fire('Hata', 'Sunucuya bağlanılamadı.', 'error'));
            }
        });
    } else {
        if (confirm(`"${baslik}" aboneliğini iptal edildi olarak işaretlemek istiyor musunuz?`)) {
            const formData = new FormData();
            formData.append('action', 'durum_degistir');
            formData.append('subscription_id', subId);
            formData.append('status', 'canceled');
            formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');

            fetch('ajax.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    alert(data.message || (data.success ? 'İptal edildi' : 'Hata'));
                    if (data.success) location.reload();
                })
                .catch(() => alert('Sunucu bağlantı hatası.'));
        }
    }
}

function toggleBudgetEdit() {
    const box = document.getElementById('budget-edit-box');
    if (box) {
        box.classList.toggle('hidden');
        if (!box.classList.contains('hidden')) {
            const inp = document.getElementById('input_monthly_budget');
            if (inp) {
                inp.focus();
                inp.select();
            }
        }
    }
}

function saveBudgetAjax(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('button[type="submit"]');
    const oldText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = 'Kaydediliyor...';
    }

    const formData = new FormData(form);
    formData.append('action', 'butce_guncelle');

    fetch('ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    title: 'Bütçe Kaydedildi',
                    text: res.message || 'Aylık bütçe hedefiniz güncellendi.',
                    showConfirmButton: false,
                    timer: 1200
                }).then(() => location.reload());
            } else {
                alert(res.message || 'Bütçe hedefiniz güncellendi.');
                location.reload();
            }
        } else {
            if (typeof Swal !== 'undefined') {
                Swal.fire('Hata', res.message || 'Bütçe kaydedilemedi.', 'error');
            } else {
                alert(res.message || 'Bütçe kaydedilemedi.');
            }
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = oldText;
            }
        }
    })
    .catch(err => {
        if (typeof Swal !== 'undefined') {
            Swal.fire('Hata', 'Sunucu bağlantı hatası oluştu.', 'error');
        } else {
            alert('Sunucu bağlantı hatası oluştu.');
        }
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = oldText;
        }
    });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
