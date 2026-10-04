<?php
/**
 * Abonelik ve Fatura Takip Sistemi - Otomatik E-Posta Bildirim Motoru
 * stoktakip mimarisi referans alınarak geliştirilmiştir.
 * 
 * Çalıştırma:
 * - cPanel / Crontab: 0 9 * * * php /home/user/public_html/abonelik-takip/cron-mail.php
 * - HTTP / Webhook: https://domain.com/cron-mail.php?token=CRON_SECRET
 */

require_once __DIR__ . '/db.php';

// PHPMailer Sınıfları
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

// Güvenlik Kontrolü (Web üzerinden tetiklenirse CRON_SECRET veya CRON_TOKEN zorunludur)
$cronSecretEnv = env('CRON_SECRET') ?: env('CRON_TOKEN');
if (php_sapi_name() !== 'cli') {
    // 1. HTTP Authorization Header (Bearer <token>)
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader) && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    $token = '';
    if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = $matches[1];
    } elseif (!empty($_POST['token'])) {
        // 2. HTTP POST body
        $token = trim($_POST['token']);
    } elseif (!empty($_GET['token'])) {
        // 3. Fallback: GET query param
        $token = trim($_GET['token']);
    }

    if (empty($cronSecretEnv) || empty($token) || !hash_equals($cronSecretEnv, $token)) {
        sistemLogla("Yetkisiz Cron Çağrısı! IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Bilinmiyor'), 'SECURITY');
        http_response_code(403);
        die("Erişim Reddedildi: Geçersiz cron güvenlik tokenı.");
    }
}

sistemLogla("Otomatik bildirim cron motoru başlatıldı.", 'INFO');

// SMTP Ayarları
$smtpHost        = env('SMTP_HOST');
$smtpPort        = (int)env('SMTP_PORT', 587);
$smtpUser        = env('SMTP_USER');
$smtpPass        = env('SMTP_PASS');
$smtpSecure      = env('SMTP_SECURE', 'tls');
$mailFromAddress = env('MAIL_FROM_ADDRESS') ?: env('SMTP_FROM_EMAIL') ?: env('SMTP_USER');
$mailFromName    = env('MAIL_FROM_NAME') ?: env('SMTP_FROM_NAME', 'Abonelik Takip Asistanı');
$appUrl          = rtrim(env('APP_URL', 'http://localhost/abonelik-takip'), '/');

if (empty($smtpHost) || empty($smtpUser) || empty($smtpPass)) {
    sistemLogla("SMTP ayarları eksik olduğu için e-posta gönderimi atlandı.", 'WARNING');
    if (php_sapi_name() !== 'cli') {
        echo "<p style='color:orange;'>SMTP ayarları yapılmadığı için e-posta gönderilemedi. Lütfen .env dosyasını doldurun.</p>";
    }
    exit;
}

function createMailer($host, $user, $pass, $port, $secure, $fromEmail, $fromName) {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->SMTPAuth   = true;
    $mail->Username   = $user;
    $mail->Password   = $pass;
    $mail->SMTPSecure = ($secure === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : (($secure === 'none') ? false : PHPMailer::ENCRYPTION_STARTTLS);
    $mail->Port       = $port;
    $mail->CharSet    = 'UTF-8';
    $mail->isHTML(true);
    $mail->setFrom($fromEmail, $fromName);
    return $mail;
}

$toplamGonderilen = 0;
$todayDate = date('Y-m-d');

// Tüm Kullanıcıları ve Bildirim Ayarlarını Çek
try {
    $stmtUsers = $pdo->query("
        SELECT u.id, u.username, u.email, u.full_name, u.role,
               COALESCE(ns.email_enabled, 1) as email_enabled,
               COALESCE(ns.admin_receive_all_notifications, 1) as admin_receive_all_notifications,
               COALESCE(ns.notify_trial_days, '3,1') as notify_trial_days,
               COALESCE(ns.notify_commitment_days, '30,15,3') as notify_commitment_days,
               COALESCE(ns.notify_due_days, '3,1') as notify_due_days,
               COALESCE(ns.monthly_summary_enabled, 1) as monthly_summary_enabled
        FROM users u
        LEFT JOIN notification_settings ns ON u.id = ns.user_id
        WHERE u.status = 'active'
    ");
    $users = $stmtUsers->fetchAll();
} catch (Exception $e) {
    sistemLogla("Kullanıcı listesi çekilemedi: " . $e->getMessage(), 'CRITICAL');
    exit;
}

foreach ($users as $user) {
    if (!$user['email_enabled'] || empty($user['email'])) {
        continue;
    }

    $userId = (int)$user['id'];
    $userEmail = $user['email'];
    $userName = $user['full_name'] ?: $user['username'];
    $userRole = $user['role'] ?? 'USER';

    $trialDays = array_map('intval', explode(',', $user['notify_trial_days']));
    $commDays  = array_map('intval', explode(',', $user['notify_commitment_days']));
    $dueDays   = array_map('intval', explode(',', $user['notify_due_days']));

    // Toplanacak Uyarı Maddeleri
    $uyarilarHtml = '';
    $uyariSayisi = 0;

    // 1. Deneme Süresi Kontrolü (Ödeyen Kişiye)
    $stmtTrials = $pdo->prepare("
        SELECT id, title, price, currency, trial_end_date,
               DATEDIFF(trial_end_date, CURRENT_DATE) as gun_farki
        FROM subscriptions
        WHERE COALESCE(payer_user_id, user_id) = ? AND status = 'active' AND is_trial = 1 
          AND trial_end_date IS NOT NULL 
          AND trial_end_date >= CURRENT_DATE
    ");
    $stmtTrials->execute([$userId]);
    $trials = $stmtTrials->fetchAll();

    foreach ($trials as $tr) {
        $fark = (int)$tr['gun_farki'];
        if (in_array($fark, $trialDays, true)) {
            // Bugün bu abonelik için deneme bildirimi atılmış mı kontrol et
            $stmtCheck = $pdo->prepare("SELECT id FROM notification_logs WHERE user_id = ? AND subscription_id = ? AND notification_type = 'trial_end' AND DATE(sent_at) = ?");
            $stmtCheck->execute([$userId, $tr['id'], $todayDate]);
            if ($stmtCheck->rowCount() == 0) {
                $uyariSayisi++;
                $fiyatStr = paraFormat($tr['price'], $tr['currency']);
                $gunMetni = $fark == 0 ? "BUGÜN sona eriyor!" : "{$fark} gün sonra sona eriyor (" . tarihFormat($tr['trial_end_date']) . ")";
                $uyarilarHtml .= "
                <div style='background:#fdf4ff; border-left:4px solid #a855f7; padding:14px; margin-bottom:12px; border-radius:8px;'>
                    <div style='font-size:14px; font-weight:bold; color:#7e22ce;'>⏳ Ücretsiz Deneme Bitiyor: " . htmlspecialchars($tr['title']) . "</div>
                    <div style='font-size:13px; color:#4b5563; margin-top:4px;'>
                        Deneme süreniz <b>{$gunMetni}</b>. İptal etmezseniz kartınızdan <b>{$fiyatStr}</b> tahsil edilecek.
                    </div>
                </div>";

                // Log ekle
                $stmtLog = $pdo->prepare("INSERT INTO notification_logs (user_id, subscription_id, notification_type, title, recipient_email) VALUES (?, ?, 'trial_end', ?, ?)");
                $stmtLog->execute([$userId, $tr['id'], "Deneme Bitişi: {$tr['title']} ({$fark} gün)", $userEmail]);
            }
        }
    }

    // Deneme süresi dün dolmuş olanlar (1 kez bilgilendir)
    $stmtExpTrials = $pdo->prepare("
        SELECT id, title, price, currency, trial_end_date,
               DATEDIFF(CURRENT_DATE, trial_end_date) as gecen_gun
        FROM subscriptions
        WHERE COALESCE(payer_user_id, user_id) = ? AND status = 'active' AND is_trial = 1 
          AND trial_end_date IS NOT NULL 
          AND trial_end_date < CURRENT_DATE
          AND DATEDIFF(CURRENT_DATE, trial_end_date) = 1
    ");
    $stmtExpTrials->execute([$userId]);
    $expTrials = $stmtExpTrials->fetchAll();

    foreach ($expTrials as $etr) {
        $stmtCheck = $pdo->prepare("SELECT id FROM notification_logs WHERE user_id = ? AND subscription_id = ? AND notification_type = 'trial_end' AND title LIKE '%Sona Erdi%'");
        $stmtCheck->execute([$userId, $etr['id']]);
        if ($stmtCheck->rowCount() == 0) {
            $uyariSayisi++;
            $fiyatStr = paraFormat($etr['price'], $etr['currency']);
            $uyarilarHtml .= "
            <div style='background:#fdf4ff; border-left:4px solid #ef4444; padding:14px; margin-bottom:12px; border-radius:8px;'>
                <div style='font-size:14px; font-weight:bold; color:#b91c1c;'>⚠️ Deneme Süresi Sona Erdi: " . htmlspecialchars($etr['title']) . "</div>
                <div style='font-size:13px; color:#4b5563; margin-top:4px;'>
                    Ücretsiz deneme süreniz doldu. Aboneliğiniz iptal edilmediği için artık normal ücret (<b>{$fiyatStr}</b>) üzerinden faturalandırılacaktır.
                </div>
            </div>";

            $stmtLog = $pdo->prepare("INSERT INTO notification_logs (user_id, subscription_id, notification_type, title, recipient_email) VALUES (?, ?, 'trial_end', ?, ?)");
            $stmtLog->execute([$userId, $etr['id'], "Deneme Süresi Sona Erdi: {$etr['title']}", $userEmail]);
        }
    }

    // 2. Taahhüt Bitişi & Zam Kontrolü (Ödeyen Kişiye)
    $stmtComms = $pdo->prepare("
        SELECT s.id, s.title, s.price, s.currency, t.operator_name, t.commitment_end, t.post_commitment_price,
               DATEDIFF(t.commitment_end, CURRENT_DATE) as gun_farki
        FROM subscriptions s
        JOIN subscription_telecom_details t ON s.id = t.subscription_id
        WHERE COALESCE(s.payer_user_id, s.user_id) = ? AND s.status = 'active'
          AND t.commitment_end IS NOT NULL
          AND t.commitment_end >= CURRENT_DATE
    ");
    $stmtComms->execute([$userId]);
    $comms = $stmtComms->fetchAll();

    foreach ($comms as $cm) {
        $fark = (int)$cm['gun_farki'];
        if (in_array($fark, $commDays, true)) {
            $stmtCheck = $pdo->prepare("SELECT id FROM notification_logs WHERE user_id = ? AND subscription_id = ? AND notification_type = 'commitment_end' AND DATE(sent_at) = ?");
            $stmtCheck->execute([$userId, $cm['id'], $todayDate]);
            if ($stmtCheck->rowCount() == 0) {
                $uyariSayisi++;
                $fiyatStr = paraFormat($cm['price'], $cm['currency']);
                $zamliStr = !empty($cm['post_commitment_price']) ? paraFormat($cm['post_commitment_price'], $cm['currency']) : null;
                $zamMetni = $zamliStr ? "Taahhüt bitince indirim kalkacak ve faturanız <b>{$zamliStr}</b> seviyesine fırlayacak!" : "Taahhüdünüzü yenilemeyi unutmayın.";

                $uyarilarHtml .= "
                <div style='background:#fffbeb; border-left:4px solid #f59e0b; padding:14px; margin-bottom:12px; border-radius:8px;'>
                    <div style='font-size:14px; font-weight:bold; color:#b45309;'>⚠️ Taahhüt Bitiş Uyarısı: " . htmlspecialchars($cm['title']) . " ({$cm['operator_name']})</div>
                    <div style='font-size:13px; color:#4b5563; margin-top:4px;'>
                        Mevcut tarifenizin taahhüdü <b>{$fark} gün sonra (" . tarihFormat($cm['commitment_end']) . ")</b> doluyor. {$zamMetni}
                    </div>
                </div>";

                $stmtLog = $pdo->prepare("INSERT INTO notification_logs (user_id, subscription_id, notification_type, title, recipient_email) VALUES (?, ?, 'commitment_end', ?, ?)");
                $stmtLog->execute([$userId, $cm['id'], "Taahhüt Uyarısı: {$cm['title']} ({$fark} gün)", $userEmail]);
            }
        }
    }

    // Taahhüt süresi dün dolmuş olanlar (1 kez zam uyarısı)
    $stmtExpComms = $pdo->prepare("
        SELECT s.id, s.title, s.price, s.currency, t.operator_name, t.commitment_end, t.post_commitment_price,
               DATEDIFF(CURRENT_DATE, t.commitment_end) as gecen_gun
        FROM subscriptions s
        JOIN subscription_telecom_details t ON s.id = t.subscription_id
        WHERE COALESCE(s.payer_user_id, s.user_id) = ? AND s.status = 'active'
          AND t.commitment_end IS NOT NULL
          AND t.commitment_end < CURRENT_DATE
          AND DATEDIFF(CURRENT_DATE, t.commitment_end) = 1
    ");
    $stmtExpComms->execute([$userId]);
    $expComms = $stmtExpComms->fetchAll();

    foreach ($expComms as $ecm) {
        $stmtCheck = $pdo->prepare("SELECT id FROM notification_logs WHERE user_id = ? AND subscription_id = ? AND notification_type = 'commitment_end' AND title LIKE '%Sona Erdi%'");
        $stmtCheck->execute([$userId, $ecm['id']]);
        if ($stmtCheck->rowCount() == 0) {
            $uyariSayisi++;
            $zamliStr = !empty($ecm['post_commitment_price']) ? paraFormat($ecm['post_commitment_price'], $ecm['currency']) : null;
            $zamMetni = $zamliStr ? "İndirim sona erdi, faturanız <b>{$zamliStr}</b> seviyesine yükseldi." : "Taahhüt süreniz doldu.";
            $uyarilarHtml .= "
            <div style='background:#fffbeb; border-left:4px solid #ef4444; padding:14px; margin-bottom:12px; border-radius:8px;'>
                <div style='font-size:14px; font-weight:bold; color:#b91c1c;'>⚠️ Taahhüt Süresi Sona Erdi: " . htmlspecialchars($ecm['title']) . " ({$ecm['operator_name']})</div>
                <div style='font-size:13px; color:#4b5563; margin-top:4px;'>
                    Taahhüt süreniz sona erdi. {$zamMetni} Lütfen tarifenizi güncelleyin veya operatörünüzle görüşün.
                </div>
            </div>";

            $stmtLog = $pdo->prepare("INSERT INTO notification_logs (user_id, subscription_id, notification_type, title, recipient_email) VALUES (?, ?, 'commitment_end', ?, ?)");
            $stmtLog->execute([$userId, $ecm['id'], "Taahhüt Süresi Sona Erdi: {$ecm['title']}", $userEmail]);
        }
    }

    // 3. Fatura & Abonelik Son Ödeme Günü Kontrolü (Ödeyen Kişiye)
    $stmtDues = $pdo->prepare("
        SELECT s.id, s.title, s.price, s.currency, s.next_payment_date,
               c.type as category_type,
               ins.payment_type as ins_payment_type, ins.installment_amount as ins_installment_amount,
               DATEDIFF(s.next_payment_date, CURRENT_DATE) as gun_farki
        FROM subscriptions s
        LEFT JOIN categories c ON s.category_id = c.id
        LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
        WHERE COALESCE(s.payer_user_id, s.user_id) = ? AND s.status = 'active'
          AND s.next_payment_date IS NOT NULL
          AND s.next_payment_date >= CURRENT_DATE
    ");
    $stmtDues->execute([$userId]);
    $dues = $stmtDues->fetchAll();

    foreach ($dues as $du) {
        $fark = (int)$du['gun_farki'];
        if (in_array($fark, $dueDays, true)) {
            $stmtCheck = $pdo->prepare("SELECT id FROM notification_logs WHERE user_id = ? AND subscription_id = ? AND notification_type = 'payment_due' AND DATE(sent_at) = ?");
            $stmtCheck->execute([$userId, $du['id'], $todayDate]);
            if ($stmtCheck->rowCount() == 0) {
                $uyariSayisi++;
                $isInst = ($du['category_type'] === 'insurance' && ($du['ins_payment_type'] ?? '') === 'installment' && !empty($du['ins_installment_amount']));
                $tutar = $isInst ? (float)$du['ins_installment_amount'] : (float)$du['price'];
                $fiyatStr = paraFormat($tutar, $du['currency']);
                $vadeStr = $fark == 0 ? "BUGÜN!" : "{$fark} gün sonra (" . tarihFormat($du['next_payment_date']) . ")";
                $baslikTxt = $isInst ? "🛡️ Poliçe Taksit Ödeme Hatırlatması" : "💳 Ödeme Hatırlatması";
                $vadeMetni = $isInst ? "Aylık taksit son ödeme gününüz" : "Son ödeme gününüz";

                $uyarilarHtml .= "
                <div style='background:#f0f9ff; border-left:4px solid #0284c7; padding:14px; margin-bottom:12px; border-radius:8px;'>
                    <div style='font-size:14px; font-weight:bold; color:#0369a1;'>{$baslikTxt}: " . htmlspecialchars($du['title']) . " ({$fiyatStr})</div>
                    <div style='font-size:13px; color:#4b5563; margin-top:4px;'>
                        {$vadeMetni} <b>{$vadeStr}</b>. Hesabınızda veya kart limitinizde bakiye bulundurmayı unutmayın.
                    </div>
                </div>";

                $stmtLog = $pdo->prepare("INSERT INTO notification_logs (user_id, subscription_id, notification_type, title, recipient_email) VALUES (?, ?, 'payment_due', ?, ?)");
                $stmtLog->execute([$userId, $du['id'], "Ödeme Hatırlatması: {$du['title']} ({$fark} gün)", $userEmail]);
            }
        }
    }

    // 3.5. Kredi Kartı Ekstre & Son Ödeme Günü Bildirimleri
    try {
        $stmtUserCards = $pdo->prepare("SELECT * FROM credit_cards WHERE user_id = ? AND is_active = 1");
        $stmtUserCards->execute([$userId]);
        $userCards = $stmtUserCards->fetchAll();

        $currentDayOfMonth = (int)date('j');

        foreach ($userCards as $card) {
            $cardTitle = htmlspecialchars($card['bank_name'] . ' ' . $card['card_name']);
            if (!empty($card['card_last_4'])) {
                $cardTitle .= " (•••• {$card['card_last_4']})";
            }

            // A. Ekstre Kesildiği Gün Bildirimi
            if ($currentDayOfMonth === (int)$card['statement_cut_day']) {
                $logTitle = "Kredi Kartı Ekstresi Kesildi: {$cardTitle}";
                $checkStmt = $pdo->prepare("SELECT id FROM notification_logs WHERE user_id = ? AND title = ? AND DATE(sent_at) = ?");
                $checkStmt->execute([$userId, $logTitle, $todayDate]);
                if ($checkStmt->rowCount() == 0) {
                    $uyariSayisi++;
                    $uyarilarHtml .= "
                    <div style='background:#f5f3ff; border-left:4px solid #7c3aed; padding:14px; margin-bottom:12px; border-radius:8px;'>
                        <div style='font-size:14px; font-weight:bold; color:#6d28d9;'>💳 Kredi Kartı Ekstresi Kesildi: {$cardTitle}</div>
                        <div style='font-size:13px; color:#4b5563; margin-top:4px;'>
                            Kartınızın hesap özeti / ekstresi bugün kesilmiştir. Lütfen dönem borcunuzu kontrol edin. 
                            Son ödeme gününüz: <b>Her ayın {$card['due_day']}'si</b>.
                        </div>
                    </div>";

                    $pdo->prepare("INSERT INTO notification_logs (user_id, subscription_id, notification_type, title, recipient_email) VALUES (?, NULL, 'system', ?, ?)")
                        ->execute([$userId, $logTitle, $userEmail]);
                }
            }

            // B. Son Ödeme Gününe 3 Gün Kala Her Gün Bildirimi (3, 2, 1, 0)
            $buAyMax = (int)date('t');
            $dueTargetDay = min((int)$card['due_day'], $buAyMax);
            $dueThisMonth = new DateTime(date('Y-m-') . str_pad($dueTargetDay, 2, '0', STR_PAD_LEFT));
            $todayDt = new DateTime($todayDate);

            if ($todayDt > $dueThisMonth) {
                $dueThisMonth->modify('+1 month');
                $nextMonthMax = (int)date('t', strtotime($dueThisMonth->format('Y-m-01')));
                $dueTargetDay = min((int)$card['due_day'], $nextMonthMax);
                $dueThisMonth->setDate((int)$dueThisMonth->format('Y'), (int)$dueThisMonth->format('m'), $dueTargetDay);
            }

            $kalanGun = (int)$todayDt->diff($dueThisMonth)->format('%r%a');

            if ($kalanGun >= 0 && $kalanGun <= 3) {
                $logTitleDue = "Kredi Kartı Son Ödeme Uyarısı: {$cardTitle} ({$kalanGun} gün)";
                $checkDue = $pdo->prepare("SELECT id FROM notification_logs WHERE user_id = ? AND title = ? AND DATE(sent_at) = ?");
                $checkDue->execute([$userId, $logTitleDue, $todayDate]);
                if ($checkDue->rowCount() == 0) {
                    $uyariSayisi++;
                    $vadeTxt = $kalanGun === 0 ? "🚨 BUGÜN SON ÖDEME GÜNÜ!" : "⚠️ Son ödeme gününe {$kalanGun} gün kaldı (" . $dueThisMonth->format('d.m.Y') . ")";
                    $uyarilarHtml .= "
                    <div style='background:#fef2f2; border-left:4px solid #ef4444; padding:14px; margin-bottom:12px; border-radius:8px;'>
                        <div style='font-size:14px; font-weight:bold; color:#b91c1c;'>🚨 {$vadeTxt}: {$cardTitle}</div>
                        <div style='font-size:13px; color:#4b5563; margin-top:4px;'>
                            Gecikme faizine girmemek ve kredi notunuzu korumak için lütfen asgari tutarı veya dönem borcunuzu ödemeyi unutmayın.
                        </div>
                    </div>";

                    $pdo->prepare("INSERT INTO notification_logs (user_id, subscription_id, notification_type, title, recipient_email) VALUES (?, NULL, 'system', ?, ?)")
                        ->execute([$userId, $logTitleDue, $userEmail]);
                }
            }
        }
    } catch (Exception $e) {
        sistemLogla("Kredi kartı bildirim kontrol hatası: " . $e->getMessage(), 'ERROR');
    }

    // 3.6. Sigorta / Poliçe Yenileme Bildirimleri (30, 15, 7, 3, 1, 0 gün kala)
    try {
        $stmtUserIns = $pdo->prepare("
            SELECT s.id, s.title, s.price, s.currency, ins.company_name, ins.policy_no, ins.policy_end_date,
                   DATEDIFF(ins.policy_end_date, CURRENT_DATE) as gun_farki
            FROM subscriptions s
            JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
            WHERE COALESCE(s.payer_user_id, s.user_id) = ? AND s.status = 'active'
              AND ins.policy_end_date IS NOT NULL
              AND ins.policy_end_date >= CURRENT_DATE
        ");
        $stmtUserIns->execute([$userId]);
        $userInsurances = $stmtUserIns->fetchAll();

        $insuranceAlertDays = [30, 15, 7, 3, 1, 0];
        foreach ($userInsurances as $uins) {
            $fark = (int)$uins['gun_farki'];
            if (in_array($fark, $insuranceAlertDays, true)) {
                $logTitleIns = "Poliçe Yenileme Uyarısı: {$uins['title']} ({$fark} gün)";
                $checkIns = $pdo->prepare("SELECT id FROM notification_logs WHERE user_id = ? AND subscription_id = ? AND notification_type = 'insurance_renewal' AND DATE(sent_at) = ?");
                $checkIns->execute([$userId, $uins['id'], $todayDate]);
                if ($checkIns->rowCount() == 0) {
                    $uyariSayisi++;
                    $vadeTxt = $fark === 0 ? "🚨 BUGÜN POLİÇE BİTİYOR!" : "🛡️ Poliçe yenilemesine {$fark} gün kaldı (" . tarihFormat($uins['policy_end_date']) . ")";
                    $companyStr = htmlspecialchars($uins['company_name'] ?: 'Sigorta');
                    $policyStr = !empty($uins['policy_no']) ? " (Poliçe No: " . htmlspecialchars($uins['policy_no']) . ")" : "";

                    $uyarilarHtml .= "
                    <div style='background:#fff1f2; border-left:4px solid #f43f5e; padding:14px; margin-bottom:12px; border-radius:8px;'>
                        <div style='font-size:14px; font-weight:bold; color:#be123c;'>🛡️ {$vadeTxt}: " . htmlspecialchars($uins['title']) . "</div>
                        <div style='font-size:13px; color:#4b5563; margin-top:4px;'>
                            <b>{$companyStr}</b>{$policyStr} poliçenizin süresi dolmak üzeredir. Güvencenizin aksamaması ve zamlı fiyatlara yakalanmamak için acentenizle iletişime geçip yenileme teklifi almayı unutmayın.
                        </div>
                    </div>";

                    $pdo->prepare("INSERT INTO notification_logs (user_id, subscription_id, notification_type, title, recipient_email) VALUES (?, ?, 'insurance_renewal', ?, ?)")
                        ->execute([$userId, $uins['id'], $logTitleIns, $userEmail]);
                }
            }
        }
    } catch (Exception $e) {
        sistemLogla("Poliçe bildirim kontrol hatası: " . $e->getMessage(), 'ERROR');
    }

    // 4. Yönetici Bildirim Kopyası (Admin Seçtiyse Diğer Üyelerin Bildirimleri)
    if ($userRole === 'ADMIN' && !empty($user['admin_receive_all_notifications'])) {
        $stmtOtherSubs = $pdo->prepare("
            SELECT s.id, s.title, s.price, s.currency, s.next_payment_date, s.is_trial, s.trial_end_date,
                   u_p.full_name as payer_name, u_p.username as payer_username,
                   t.operator_name, t.commitment_end,
                   c.type as category_type,
                   ins.payment_type as ins_payment_type, ins.installment_amount as ins_installment_amount,
                   DATEDIFF(s.next_payment_date, CURRENT_DATE) as payment_diff,
                   DATEDIFF(s.trial_end_date, CURRENT_DATE) as trial_diff,
                   DATEDIFF(t.commitment_end, CURRENT_DATE) as comm_diff
            FROM subscriptions s
            LEFT JOIN users u_p ON COALESCE(s.payer_user_id, s.user_id) = u_p.id
            LEFT JOIN categories c ON s.category_id = c.id
            LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
            LEFT JOIN subscription_telecom_details t ON s.id = t.subscription_id
            WHERE COALESCE(s.payer_user_id, s.user_id) != ? AND s.status = 'active'
        ");
        $stmtOtherSubs->execute([$userId]);
        $otherSubs = $stmtOtherSubs->fetchAll();

        $adminEkUyarilar = '';
        foreach ($otherSubs as $os) {
            $pName = htmlspecialchars($os['payer_name'] ?: $os['payer_username']);

            // Deneme bitişi
            if ($os['is_trial'] && $os['trial_diff'] !== null && in_array((int)$os['trial_diff'], $trialDays, true)) {
                $f = (int)$os['trial_diff'];
                $check = $pdo->prepare("SELECT id FROM notification_logs WHERE user_id = ? AND subscription_id = ? AND notification_type = 'admin_copy_trial' AND DATE(sent_at) = ?");
                $check->execute([$userId, $os['id'], $todayDate]);
                if ($check->rowCount() == 0) {
                    $uyariSayisi++;
                    $adminEkUyarilar .= "
                    <div style='background:#f1f5f9; border-left:4px solid #64748b; padding:12px; margin-bottom:10px; border-radius:6px; font-size:13px;'>
                        <b>👑 [Üye: {$pName}]</b> Deneme Süresi Sona Eriyor: <b>" . htmlspecialchars($os['title']) . "</b> ({$f} gün sonra) - " . paraFormat($os['price'], $os['currency']) . "
                    </div>";
                    $pdo->prepare("INSERT INTO notification_logs (user_id, subscription_id, notification_type, title, recipient_email) VALUES (?, ?, 'admin_copy_trial', ?, ?)")
                        ->execute([$userId, $os['id'], "Admin Kopyası (Deneme): {$os['title']} ({$pName})", $userEmail]);
                }
            }

            // Taahhüt bitişi
            if ($os['comm_diff'] !== null && in_array((int)$os['comm_diff'], $commDays, true)) {
                $f = (int)$os['comm_diff'];
                $check = $pdo->prepare("SELECT id FROM notification_logs WHERE user_id = ? AND subscription_id = ? AND notification_type = 'admin_copy_comm' AND DATE(sent_at) = ?");
                $check->execute([$userId, $os['id'], $todayDate]);
                if ($check->rowCount() == 0) {
                    $uyariSayisi++;
                    $adminEkUyarilar .= "
                    <div style='background:#f1f5f9; border-left:4px solid #64748b; padding:12px; margin-bottom:10px; border-radius:6px; font-size:13px;'>
                        <b>👑 [Üye: {$pName}]</b> Taahhüt Bitişi: <b>" . htmlspecialchars($os['title']) . "</b> ({$f} gün sonra)
                    </div>";
                    $pdo->prepare("INSERT INTO notification_logs (user_id, subscription_id, notification_type, title, recipient_email) VALUES (?, ?, 'admin_copy_comm', ?, ?)")
                        ->execute([$userId, $os['id'], "Admin Kopyası (Taahhüt): {$os['title']} ({$pName})", $userEmail]);
                }
            }

            // Fatura / Ödeme günü
            if ($os['payment_diff'] !== null && in_array((int)$os['payment_diff'], $dueDays, true)) {
                $f = (int)$os['payment_diff'];
                $check = $pdo->prepare("SELECT id FROM notification_logs WHERE user_id = ? AND subscription_id = ? AND notification_type = 'admin_copy_due' AND DATE(sent_at) = ?");
                $check->execute([$userId, $os['id'], $todayDate]);
                if ($check->rowCount() == 0) {
                    $uyariSayisi++;
                    $isInstOs = ($os['category_type'] === 'insurance' && ($os['ins_payment_type'] ?? '') === 'installment' && !empty($os['ins_installment_amount']));
                    $osPrice = $isInstOs ? (float)$os['ins_installment_amount'] : (float)$os['price'];
                    $taksitLabel = $isInstOs ? " (Taksit)" : "";
                    $adminEkUyarilar .= "
                    <div style='background:#f1f5f9; border-left:4px solid #64748b; padding:12px; margin-bottom:10px; border-radius:6px; font-size:13px;'>
                        <b>👑 [Üye: {$pName}]</b> Yaklaşan Ödeme: <b>" . htmlspecialchars($os['title']) . "</b> (" . ($f == 0 ? 'Bugün' : "{$f} gün sonra") . ") - " . paraFormat($osPrice, $os['currency']) . $taksitLabel . "
                    </div>";
                    $pdo->prepare("INSERT INTO notification_logs (user_id, subscription_id, notification_type, title, recipient_email) VALUES (?, ?, 'admin_copy_due', ?, ?)")
                        ->execute([$userId, $os['id'], "Admin Kopyası (Ödeme): {$os['title']} ({$pName})", $userEmail]);
                }
            }
        }

        if (!empty($adminEkUyarilar)) {
            $uyarilarHtml .= "
            <div style='margin-top:20px; padding-top:14px; border-top:2px dashed #cbd5e1;'>
                <div style='font-size:13px; font-weight:bold; color:#334155; margin-bottom:10px;'>👑 Yönetici Özeti (Diğer Aile/Ekip Üyelerinin Yaklaşan Harcamaları):</div>
                {$adminEkUyarilar}
            </div>";
        }
    }

    // Eğer herhangi bir uyarı varsa HTML E-Posta Oluştur ve Gönder
    if ($uyariSayisi > 0) {
        $emailHtml = "
        <!DOCTYPE html>
        <html>
        <body style='font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif; background:#0f172a; padding:20px; color:#1e293b; margin:0;'>
            <div style='max-width:600px; margin:0 auto; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.1);'>
                
                <div style='background:#0284c7; padding:24px; color:#ffffff;'>
                    <div style='font-size:11px; text-transform:uppercase; letter-spacing:1px; opacity:0.85;'>Abonelik & Fatura Takip Asistanı</div>
                    <h1 style='margin:6px 0 0 0; font-size:20px; font-weight:bold;'>📢 {$uyariSayisi} Önemli Abonelik Hatırlatması</h1>
                </div>

                <div style='padding:24px;'>
                    <p style='margin-top:0; font-size:14px; color:#475569;'>
                        Merhaba <b>" . htmlspecialchars($userName) . "</b>,<br>
                        Sabit giderleriniz ve taahhütleriniz kontrol edildi. Önümüzdeki günlerde dikkat etmeniz gereken <b>{$uyariSayisi}</b> önemli ödeme/taahhüt süreci bulunmaktadır:
                    </p>

                    <div style='margin-top:16px;'>
                        {$uyarilarHtml}
                    </div>

                    <div style='margin-top:24px; text-align:center;'>
                        <a href='{$appUrl}/index.php' style='display:inline-block; background:#0284c7; color:#ffffff; padding:12px 24px; border-radius:10px; font-weight:bold; font-size:13px; text-decoration:none;'>
                            Abonelik Panelini Aç →
                        </a>
                    </div>
                </div>

                <div style='background:#f8fafc; border-top:1px solid #e2e8f0; padding:14px 24px; font-size:11px; color:#94a3b8; text-align:center;'>
                    Bu bilgilendirme e-postası Abonelik Takip Sistemi tarafından otomatik olarak gönderilmiştir. Bildirim tercihlerinizi panelden değiştirebilirsiniz.<br>
                    Tarih: " . date('d.m.Y H:i') . "
                </div>

            </div>
        </body>
        </html>
        ";

        $subject = "📢 Abonelik Uyarısı: {$uyariSayisi} Ödeme & Taahhüt Süreci Yaklaşıyor (" . date('d.m.Y') . ")";

        try {
            $mailer = createMailer($smtpHost, $smtpUser, $smtpPass, $smtpPort, $smtpSecure, $mailFromAddress, $mailFromName);
            $mailer->Subject = $subject;
            $mailer->Body    = $emailHtml;
            $mailer->addAddress($userEmail);
            $mailer->send();
            $toplamGonderilen++;
            sistemLogla("Bildirim e-postası başarıyla gönderildi: {$userEmail} ({$uyariSayisi} uyarı)", 'INFO');
        } catch (Exception $e) {
            sistemLogla("Mail gönderme hatası ({$userEmail}): " . $e->getMessage(), 'ERROR');
        }
    }
}

sistemLogla("Cron görevi tamamlandı. Toplam gönderilen e-posta: {$toplamGonderilen}", 'INFO');

// Tarayıcıdan test amacıyla çağrıldıysa güzel bir sonuç ekranı bas
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <title>Cron Bildirim Testi</title>
        <style>
            body { font-family: system-ui, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
            .card { background: #1e293b; padding: 32px; border-radius: 16px; border: 1px solid #334155; max-width: 500px; width: 100%; text-align: center; }
            h2 { color: #38bdf8; margin-top: 0; }
            .badge { display: inline-block; background: #0284c7; color: white; padding: 6px 12px; border-radius: 8px; font-weight: bold; font-size: 14px; margin: 12px 0; }
            p { font-size: 13px; color: #94a3b8; line-height: 1.6; }
            a { color: #38bdf8; text-decoration: none; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="card">
            <h2>✓ Bildirim Cron Motoru Çalıştı</h2>
            <div class="badge">Gönderilen E-Posta: <?= $toplamGonderilen ?> Adet</div>
            <p>Taahhüt bitişleri, deneme süreleri ve son ödeme tarihleri başarıyla tarandı ve uygun kullanıcılara HTML bildirimleri yollandı.</p>
            <p><a href="bildirim-gecmisi.php">Bildirim Geçmişini Görüntüle →</a></p>
        </div>
    </body>
    </html>
    <?php
}
