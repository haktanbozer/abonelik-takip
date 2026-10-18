<?php
/**
 * Abonelik ve Fatura Takip Sistemi - Merkezi AJAX ve API İşleyicisi
 */

if (!ob_get_level()) {
    ob_start();
}

// Hata gösterimini AJAX için kapat (hata olursa JSON olarak yakalayacağız)
ini_set('display_errors', '0');
error_reporting(E_ALL);

/**
 * JSON yanıt fonksiyonu: Tamponu tamamen temizler ve sadece saf JSON döner
 */
function jsonYanit(array $data, int $statusCode = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Beklenmedik fatal error veya parse error durumlarını JSON formatında yakala
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        jsonYanit([
            'success' => false,
            'message' => 'Sunucu Hatası: ' . $error['message'] . ' (' . basename($error['file']) . ':' . $error['line'] . ')'
        ], 500);
    }
});

require_once __DIR__ . '/db.php';

// Oturum Doğrulama (AJAX JSON yanıtlı: Kesinlikle 302 redirect üretmez, JSON döner)
if (!isset($_SESSION['user_id'])) {
    jsonYanit(['success' => false, 'message' => 'Oturum süresi doldu, lütfen tekrar giriş yapın.'], 401);
}

try {
    $stmtAuth = $pdo->prepare("SELECT status FROM users WHERE id = ?");
    $stmtAuth->execute([(int)$_SESSION['user_id']]);
    $uStatus = $stmtAuth->fetchColumn();
    if (!$uStatus || $uStatus === 'inactive') {
        jsonYanit(['success' => false, 'message' => 'Hesap aktif değil veya silinmiş.'], 403);
    }
} catch (Exception $e) {
    jsonYanit(['success' => false, 'message' => 'Veritabanı bağlantı hatası.'], 500);
}

$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // 1. Abonelik Silme
    case 'sil_abonelik':
        csrfKontrol($_POST['csrf_token'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz abonelik ID.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // IDOR & Yetki Kontrolü: Silmeden önce kullanıcının yetkisini doğrula!
            $isAdmin = (($_SESSION['role'] ?? 'USER') === 'ADMIN');
            if (!$isAdmin) {
                $authCheck = $pdo->prepare("SELECT id FROM subscriptions WHERE id = ? AND (user_id = ? OR payer_user_id = ?)");
                $authCheck->execute([$id, $userId, $userId]);
                if ($authCheck->rowCount() === 0) {
                    $pdo->rollBack();
                    sistemLogla("Yetkisiz Abonelik Silme Girişimi Engellendi (IDOR): Sub ID {$id}, User ID {$userId}", 'SECURITY');
                    echo json_encode(['success' => false, 'message' => 'Bu aboneliği silme yetkiniz bulunmuyor.']);
                    exit;
                }
            }

            // Yetki doğrulandıktan sonra alt tabloları temizle
            $pdo->prepare("DELETE FROM subscription_digital_details WHERE subscription_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM subscription_utility_details WHERE subscription_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM subscription_telecom_details WHERE subscription_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM subscription_insurance_details WHERE subscription_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM subscription_bes_details WHERE subscription_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM subscription_access WHERE subscription_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM subscription_shares WHERE subscription_id = ?")->execute([$id]);

            // Ekli dosya varsa diskten temizle
            $stmtAtt = $pdo->prepare("SELECT attachment_file FROM subscriptions WHERE id = ?");
            $stmtAtt->execute([$id]);
            $oldAtt = $stmtAtt->fetchColumn();
            if (!empty($oldAtt)) {
                $oldPath = __DIR__ . '/' . ltrim($oldAtt, '/\\');
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }

            // Ana kaydı sil
            $del = $pdo->prepare("DELETE FROM subscriptions WHERE id = ?");
            $del->execute([$id]);

            $pdo->commit();
            sistemLogla("Abonelik Silindi: ID {$id} (Kullanıcı: {$userId})", 'INFO');
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            sistemLogla("Abonelik silme hatası: " . $e->getMessage(), 'ERROR');
            echo json_encode(['success' => false, 'message' => 'Silme başarısız: ' . $e->getMessage()]);
        }
        break;

    // 2. Dijital Hesap Şifresi Getirme (Master PIN Korumalı & Üye Yetkilendirmeli)
    case 'sifre_getir':
        // CSRF Koruması (JSON yanıtlı, sessizce çökmez)
        $csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
        if (empty($csrfToken) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
            jsonYanit(['success' => false, 'message' => 'Güvenlik oturumu süresi doldu (CSRF). Lütfen sayfayı yenileyip tekrar deneyin.']);
        }

        // subId ve pin POST body'den alınıyor (GET ile log/geçmişe düşmesini önlemek için)
        $subId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $pin = trim($_POST['pin'] ?? '');

        if (!$subId) {
            jsonYanit(['success' => false, 'message' => 'Geçersiz abonelik seçimi.']);
        }

        // Bu kullanıcının şifreyi görme yetkisi var mı?
        if (!kullaniciSifreGorebilirMi($subId, $userId, $_SESSION['role'] ?? 'USER')) {
            jsonYanit(['success' => false, 'message' => 'Bu aboneliğin hesap şifresini görüntüleme yetkiniz bulunmuyor.']);
        }

        // Kullanıcının Master PIN'i var mı kontrol et
        $hasMasterPin = false;
        try {
            $stmtUserPin = $pdo->prepare("SELECT master_pin_hash FROM users WHERE id = ?");
            $stmtUserPin->execute([$userId]);
            $hasMasterPin = !empty($stmtUserPin->fetchColumn());
        } catch (Exception $e) {}

        // PIN Brute-force koruması (Veritabanı tabanlı: pin_attempts)
        $pinAttempt = null;
        try {
            $stmtPin = $pdo->prepare("SELECT attempts, locked_until FROM pin_attempts WHERE user_id = ?");
            $stmtPin->execute([$userId]);
            $pinAttempt = $stmtPin->fetch();

            if ($pinAttempt && !empty($pinAttempt['locked_until']) && strtotime($pinAttempt['locked_until']) > time()) {
                $kalan = ceil((strtotime($pinAttempt['locked_until']) - time()) / 60);
                jsonYanit(['success' => false, 'message' => "Çok fazla hatalı deneme. PIN erişimi kilitli: {$kalan} dakika."]);
            }
        } catch (Exception $e) {
            sistemLogla("PIN attempt sorgu hatası: " . $e->getMessage(), 'ERROR');
        }

        // Eğer PIN gönderildiyse doğrula
        if (!empty($pin)) {
            if (!masterPinDogrula($pin)) {
                $curAttempts = ($pinAttempt && (empty($pinAttempt['locked_until']) || strtotime($pinAttempt['locked_until']) <= time())) ? (int)$pinAttempt['attempts'] : 0;
                $newAttempts = $curAttempts + 1;

                if ($newAttempts >= 5) {
                    $lockedUntil = date('Y-m-d H:i:s', strtotime('+5 minutes'));
                    $stmtLock = $pdo->prepare("
                        INSERT INTO pin_attempts (user_id, attempts, last_attempt, locked_until)
                        VALUES (?, ?, NOW(), ?)
                        ON DUPLICATE KEY UPDATE attempts = ?, last_attempt = NOW(), locked_until = ?
                    ");
                    $stmtLock->execute([$userId, $newAttempts, $lockedUntil, $newAttempts, $lockedUntil]);
                    sistemLogla("Master PIN Brute-Force Kilitlendi: User ID {$userId}", 'SECURITY');
                    jsonYanit(['success' => false, 'message' => '5 hatalı deneme! Güvenlik nedeniyle doğrulama 5 dakika kilitlendi.']);
                } else {
                    $stmtUpsert = $pdo->prepare("
                        INSERT INTO pin_attempts (user_id, attempts, last_attempt, locked_until)
                        VALUES (?, 1, NOW(), NULL)
                        ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW(), locked_until = NULL
                    ");
                    $stmtUpsert->execute([$userId]);
                    $kalanHak = 5 - $newAttempts;
                    $errHint = $hasMasterPin ? "Geçersiz Master PIN kodu!" : "Geçersiz giriş şifresi!";
                    jsonYanit(['success' => false, 'message' => "{$errHint} (Kalan deneme hakkı: {$kalanHak})"]);
                }
            }

            // Başarılı doğrulama: Veritabanındaki hatalı deneme kaydını sil ve session'ı da temizle
            try {
                $pdo->prepare("DELETE FROM pin_attempts WHERE user_id = ?")->execute([$userId]);
            } catch (Exception $e) {}
            unset($_SESSION['pin_attempts']);
            unset($_SESSION['pin_lock_until']);
        }

        // Session'da PIN kilidi açık mı?
        if (!masterPinAktifMi()) {
            jsonYanit([
                'success' => false,
                'require_pin' => true,
                'has_pin' => $hasMasterPin
            ]);
        }

        try {
            $stmt = $pdo->prepare("
                SELECT dig.account_password_encrypted, dig.encryption_iv 
                FROM subscription_digital_details dig
                WHERE dig.subscription_id = ?
            ");
            $stmt->execute([$subId]);
            $row = $stmt->fetch();

            if (!$row || empty($row['account_password_encrypted'])) {
                jsonYanit(['success' => false, 'message' => 'Bu abonelik için kayıtlı şifre bulunamadı.']);
            }

            $plainPassword = sifreCoz($row['account_password_encrypted'], $row['encryption_iv']);
            if ($plainPassword === false || $plainPassword === '') {
                jsonYanit(['success' => false, 'message' => 'Şifre çözülemedi. Veritabanındaki şifreleme anahtarı (.env) eşleşmiyor olabilir.']);
            }

            jsonYanit(['success' => true, 'password' => $plainPassword]);
        } catch (Exception $e) {
            sistemLogla('Şifre çözme hatası: ' . $e->getMessage(), 'ERROR');
            jsonYanit(['success' => false, 'message' => 'Şifre çözme işlemi başarısız oldu: ' . $e->getMessage()]);
        }
        break;

    // 3. Masraf Paylaşımı Kişi Ekleme
    case 'ortak_ekle':
        csrfKontrol($_POST['csrf_token'] ?? '');
        $subId = (int)($_POST['subscription_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $amount = (float)str_replace(',', '.', $_POST['amount'] ?? 0);

        if (!$subId || empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Eksik bilgi.']);
            exit;
        }

        try {
            $isAdmin = (($_SESSION['role'] ?? 'USER') === 'ADMIN');
            if ($isAdmin) {
                $check = $pdo->prepare("SELECT id FROM subscriptions WHERE id = ?");
                $check->execute([$subId]);
            } else {
                $check = $pdo->prepare("SELECT id FROM subscriptions WHERE id = ? AND (user_id = ? OR payer_user_id = ?)");
                $check->execute([$subId, $userId, $userId]);
            }
            if ($check->rowCount() == 0) {
                echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim.']);
                exit;
            }

            $shareUserId = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
            $shareEmail = trim($_POST['email'] ?? '');
            if (!$shareUserId && !empty($name)) {
                $findU = $pdo->prepare("SELECT id, email FROM users WHERE username = ? OR full_name = ? LIMIT 1");
                $findU->execute([$name, $name]);
                $matchedU = $findU->fetch();
                if ($matchedU) {
                    $shareUserId = (int)$matchedU['id'];
                    if (empty($shareEmail)) $shareEmail = $matchedU['email'];
                }
            }

            $stmt = $pdo->prepare("INSERT INTO subscription_shares (subscription_id, user_id, person_name, person_email, share_amount) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$subId, $shareUserId, $name, $shareEmail ?: null, $amount]);

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // 4. Masraf Paylaşımı Durum Güncelleme
    case 'ortak_durum':
        csrfKontrol($_POST['csrf_token'] ?? '');
        $shareId = (int)($_POST['share_id'] ?? 0);
        $status = !empty($_POST['status']) ? 1 : 0;

        try {
            $isAdmin = (($_SESSION['role'] ?? 'USER') === 'ADMIN');
            if ($isAdmin) {
                $stmt = $pdo->prepare("
                    UPDATE subscription_shares sh
                    JOIN subscriptions s ON sh.subscription_id = s.id
                    SET sh.is_paid = ?, sh.last_paid_date = IF(? = 1, CURRENT_DATE, NULL)
                    WHERE sh.id = ?
                ");
                $stmt->execute([$status, $status, $shareId]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE subscription_shares sh
                    JOIN subscriptions s ON sh.subscription_id = s.id
                    SET sh.is_paid = ?, sh.last_paid_date = IF(? = 1, CURRENT_DATE, NULL)
                    WHERE sh.id = ? AND (s.user_id = ? OR s.payer_user_id = ?)
                ");
                $stmt->execute([$status, $status, $shareId, $userId, $userId]);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // 5. Masraf Paylaşımı Silme
    case 'ortak_sil':
        csrfKontrol($_POST['csrf_token'] ?? '');
        $shareId = (int)($_POST['share_id'] ?? 0);

        try {
            $isAdmin = (($_SESSION['role'] ?? 'USER') === 'ADMIN');
            if ($isAdmin) {
                $stmt = $pdo->prepare("
                    DELETE sh FROM subscription_shares sh
                    JOIN subscriptions s ON sh.subscription_id = s.id
                    WHERE sh.id = ?
                ");
                $stmt->execute([$shareId]);
            } else {
                $stmt = $pdo->prepare("
                    DELETE sh FROM subscription_shares sh
                    JOIN subscriptions s ON sh.subscription_id = s.id
                    WHERE sh.id = ? AND (s.user_id = ? OR s.payer_user_id = ?)
                ");
                $stmt->execute([$shareId, $userId, $userId]);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            sistemLogla('Ortak silme hatası: ' . $e->getMessage(), 'ERROR');
            echo json_encode(['success' => false, 'message' => 'Silme işlemi başarısız oldu. Lütfen tekrar deneyin.']);
        }
        break;

    // 6. Test E-Postası Gönderme
    case 'test_mail':
        csrfKontrol($_POST['csrf_token'] ?? '');

        require_once __DIR__ . '/PHPMailer/Exception.php';
        require_once __DIR__ . '/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/SMTP.php';

        $smtpHost = env('SMTP_HOST');
        $smtpPort = (int)env('SMTP_PORT', 587);
        $smtpUser = env('SMTP_USER');
        $smtpPass = env('SMTP_PASS');
        $smtpSecure = env('SMTP_SECURE', 'tls');
        $mailFrom = env('MAIL_FROM_ADDRESS') ?: env('SMTP_FROM_EMAIL') ?: env('SMTP_USER');
        $mailFromName = env('MAIL_FROM_NAME') ?: env('SMTP_FROM_NAME', 'Abonelik Takip Asistanı');

        $toEmail = $_SESSION['user_data']['email'] ?? env('NOTIFICATION_EMAIL');

        if (empty($smtpHost) || empty($smtpUser) || empty($smtpPass)) {
            jsonYanit(['success' => false, 'message' => 'SMTP ayarları eksik! Lütfen .env dosyasını kontrol edin.']);
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $smtpHost;
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtpUser;
            $mail->Password   = $smtpPass;
            $mail->SMTPSecure = ($smtpSecure === 'ssl') ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : (($smtpSecure === 'none') ? false : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS);
            $mail->Port       = $smtpPort;
            $mail->CharSet    = 'UTF-8';
            $mail->isHTML(true);
            $mail->setFrom($mailFrom, $mailFromName);
            $mail->addAddress($toEmail);
            $mail->Subject = '✓ Abonelik Takip - SMTP Test E-Postası';
            $mail->Body    = "
                <div style='font-family:sans-serif; background:#f8fafc; padding:20px; color:#1e293b;'>
                    <div style='max-width:500px; margin:0 auto; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:24px;'>
                        <h2 style='color:#0284c7; margin-top:0;'>✓ SMTP Bağlantısı Başarılı!</h2>
                        <p style='font-size:14px; color:#475569;'>Tebrikler! Abonelik Takip Sistemi SMTP e-posta ayarlarınız sorunsuz çalışıyor. Yaklaşan fatura ve taahhüt bitişleriniz bu e-posta adresine bildirilecektir.</p>
                        <p style='font-size:12px; color:#94a3b8; margin-bottom:0;'>Test Zamanı: " . date('d.m.Y H:i:s') . "</p>
                    </div>
                </div>
            ";
            $mail->send();
            jsonYanit(['success' => true, 'message' => "Test e-postası {$toEmail} adresine başarıyla gönderildi!"]);
        } catch (Exception $e) {
            sistemLogla('SMTP Test Mail Hatası: ' . $e->getMessage(), 'ERROR');
            jsonYanit(['success' => false, 'message' => 'E-posta gönderilemedi: ' . $e->getMessage()]);
        }
        break;

    // 7. Mekana Göre Kategorileri Getirme (İkon & Tür Eşleşmeli)
    case 'kategorileri_getir':
        try {
            $locId = (int)($_GET['location_id'] ?? $_POST['location_id'] ?? 0);
            $types = mekanKategoriTurleri($locId);

            if (!empty($types) && count($types) < 8) {
                $placeholders = implode(',', array_fill(0, count($types), '?'));
                $stmtCats = $pdo->prepare("SELECT id, name, slug, type, icon, color, sort_order FROM categories WHERE type IN ($placeholders) OR (('tax' IN ($placeholders) OR 'vergi' IN ($placeholders)) AND (LOWER(name) LIKE '%vergi%' OR LOWER(slug) LIKE '%vergi%' OR LOWER(name) LIKE '%maliye%' OR name = 'GSS') AND LOWER(name) NOT LIKE '%motorlu%') ORDER BY sort_order ASC, name ASC");
                $stmtCats->execute($types);
                $cats = $stmtCats->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmtCats = $pdo->query("SELECT id, name, slug, type, icon, color, sort_order FROM categories ORDER BY sort_order ASC, name ASC");
                $cats = $stmtCats->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode([
                'success' => true,
                'categories' => $cats
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'categories' => []
            ]);
        }
        break;

    // 8. Aboneliği Ödendi Olarak İşaretle (Periyodu Ötele - Ay Sonu Gün Kayması Önleyicili)
    case 'odendi_isaretle':
        csrfKontrol($_POST['csrf_token'] ?? '');
        $subId = (int)($_POST['subscription_id'] ?? $_POST['id'] ?? 0);
        if (!$subId) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz abonelik ID.']);
            exit;
        }

        try {
            $isAdmin = (($_SESSION['role'] ?? 'USER') === 'ADMIN');
            if ($isAdmin) {
                $stmt = $pdo->prepare("
                    SELECT s.id, s.title, s.billing_cycle, s.next_payment_date,
                           u.due_day as util_due_day, bes.payment_day as bes_due_day,
                           c.type as category_type,
                           ins.payment_type as ins_payment_type, ins.installment_count as ins_installment_count, ins.installment_amount as ins_installment_amount,
                           ins.policy_start_date as ins_start_date, ins.policy_end_date as ins_end_date
                    FROM subscriptions s
                    LEFT JOIN categories c ON s.category_id = c.id
                    LEFT JOIN subscription_utility_details u ON s.id = u.subscription_id
                    LEFT JOIN subscription_bes_details bes ON s.id = bes.subscription_id
                    LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
                    WHERE s.id = ?
                ");
                $stmt->execute([$subId]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT s.id, s.title, s.billing_cycle, s.next_payment_date,
                           u.due_day as util_due_day, bes.payment_day as bes_due_day,
                           c.type as category_type,
                           ins.payment_type as ins_payment_type, ins.installment_count as ins_installment_count, ins.installment_amount as ins_installment_amount,
                           ins.policy_start_date as ins_start_date, ins.policy_end_date as ins_end_date
                    FROM subscriptions s 
                    LEFT JOIN categories c ON s.category_id = c.id
                    LEFT JOIN subscription_utility_details u ON s.id = u.subscription_id
                    LEFT JOIN subscription_bes_details bes ON s.id = bes.subscription_id
                    LEFT JOIN subscription_insurance_details ins ON s.id = ins.subscription_id
                    WHERE s.id = ? AND (
                        s.user_id = ? OR 
                        s.payer_user_id = ? OR 
                        EXISTS (SELECT 1 FROM subscription_access sa WHERE sa.subscription_id = s.id AND sa.user_id = ?)
                    )
                ");
                $stmt->execute([$subId, $userId, $userId, $userId]);
            }
            $sub = $stmt->fetch();

            if (!$sub) {
                sistemLogla("Yetkisiz Ödendi İşaretleme Girişimi (IDOR): Sub ID {$subId}, User ID {$userId}", 'SECURITY');
                echo json_encode(['success' => false, 'message' => 'Bu aboneliği güncelleme yetkiniz bulunmuyor.']);
                exit;
            }

            $isInsuranceInstallment = ($sub['category_type'] === 'insurance' && ($sub['ins_payment_type'] ?? '') === 'installment');

            // Mevcut ödeme tarihi ve periyot
            $currentDateStr = !empty($sub['next_payment_date']) ? $sub['next_payment_date'] : date('Y-m-d');
            $currentTs = strtotime($currentDateStr);

            // Orijinal günü belirle (Ay Sonu Gün Kayması - Calendar Drift Önleyici)
            $targetDay = (int)date('j', $currentTs);
            if (!empty($sub['util_due_day']) && (int)$sub['util_due_day'] >= 1 && (int)$sub['util_due_day'] <= 31) {
                $targetDay = (int)$sub['util_due_day'];
            } elseif (!empty($sub['bes_due_day']) && (int)$sub['bes_due_day'] >= 1 && (int)$sub['bes_due_day'] <= 31) {
                $targetDay = (int)$sub['bes_due_day'];
            } elseif ($isInsuranceInstallment && !empty($sub['ins_start_date'])) {
                $targetDay = (int)date('j', strtotime($sub['ins_start_date']));
            }

            $origYear = (int)date('Y', $currentTs);
            $origMonth = (int)date('n', $currentTs);

            // Eklenecek ay sayısı
            $addMonths = 1;
            if ($isInsuranceInstallment) {
                // Taksitli poliçelerde periyot otomatik olarak aylık gibi davranır ve her ay 1 ay ötelenir
                $addMonths = 1;
            } else {
                switch ($sub['billing_cycle']) {
                    case 'annual':
                        $addMonths = 12;
                        break;
                    case 'quarterly':
                        $addMonths = 3;
                        break;
                    case 'semi_annual':
                        $addMonths = 6;
                        break;
                    case 'monthly':
                    default:
                        $addMonths = 1;
                        break;
                }
            }

            // Hedef yıl ve ay hesabı
            $totalMonth = $origMonth + $addMonths;
            $newYear = $origYear + intdiv($totalMonth - 1, 12);
            $newMonth = (($totalMonth - 1) % 12) + 1;

            // Hedef ayın çekeceği maksimum gün sayısı (Örn: Şubat 28/29, Nisan 30)
            $maxDaysInNewMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $newYear, $newMonth)));
            $newDay = min($targetDay, $maxDaysInNewMonth);

            $newDate = sprintf('%04d-%02d-%02d', $newYear, $newMonth, $newDay);

            $upd = $pdo->prepare("UPDATE subscriptions SET next_payment_date = ?, billing_cycle = IF(? = 1, 'monthly', billing_cycle) WHERE id = ?");
            $upd->execute([$newDate, $isInsuranceInstallment ? 1 : 0, $subId]);

            sistemLogla("Abonelik Ödendi Olarak İşaretlendi: ID {$subId} ({$sub['title']}), Yeni Tarih: {$newDate}", 'INFO');

            echo json_encode([
                'success' => true,
                'message' => 'Abonelik ödendi olarak işaretlendi ve bir sonraki döneme ötelendi.',
                'subscription_id' => $subId,
                'next_payment_date' => $newDate,
                'next_payment_date_formatted' => tarihFormat($newDate)
            ]);
        } catch (Exception $e) {
            sistemLogla("Ödendi işaretleme hatası: " . $e->getMessage(), 'ERROR');
            echo json_encode(['success' => false, 'message' => 'İşlem gerçekleştirilemedi: ' . $e->getMessage()]);
        }
        break;

    // 9. Deneme Sürümünü Normal Aboneliğe Çevir
    case 'trial_to_normal':
        csrfKontrol($_POST['csrf_token'] ?? '');
        $subId = (int)($_POST['subscription_id'] ?? $_POST['id'] ?? 0);
        if (!$subId) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz abonelik ID.']);
            exit;
        }

        try {
            $isAdmin = (($_SESSION['role'] ?? 'USER') === 'ADMIN');
            $authCheck = $isAdmin ? "1=1" : "(user_id = {$userId} OR payer_user_id = {$userId})";
            $stmt = $pdo->prepare("SELECT id, title FROM subscriptions WHERE id = ? AND {$authCheck}");
            $stmt->execute([$subId]);
            $sub = $stmt->fetch();
            if (!$sub) {
                echo json_encode(['success' => false, 'message' => 'Abonelik bulunamadı veya yetkiniz yok.']);
                exit;
            }

            $pdo->prepare("UPDATE subscriptions SET is_trial = 0, trial_end_date = NULL WHERE id = ?")->execute([$subId]);
            sistemLogla("Abonelik Deneme Sürümünden Normal Aboneliğe Çevrildi: ID {$subId} ({$sub['title']})", 'INFO');
            echo json_encode(['success' => true, 'message' => 'Abonelik başarıyla normal ücretli aboneliğe çevrildi.']);
        } catch (Exception $e) {
            sistemLogla("Deneme sürümü çevirme hatası: " . $e->getMessage(), 'ERROR');
            echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        }
        break;

    // 10. Taahhüt Sonrası Zamlı Fiyata Güncelle (Tarifeye Uyarla)
    case 'apply_post_commitment_price':
        csrfKontrol($_POST['csrf_token'] ?? '');
        $subId = (int)($_POST['subscription_id'] ?? $_POST['id'] ?? 0);
        if (!$subId) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz abonelik ID.']);
            exit;
        }

        try {
            $isAdmin = (($_SESSION['role'] ?? 'USER') === 'ADMIN');
            $authCheck = $isAdmin ? "1=1" : "(s.user_id = {$userId} OR s.payer_user_id = {$userId})";
            $stmt = $pdo->prepare("
                SELECT s.id, s.title, s.price, s.currency, t.post_commitment_price 
                FROM subscriptions s
                JOIN subscription_telecom_details t ON s.id = t.subscription_id
                WHERE s.id = ? AND {$authCheck}
            ");
            $stmt->execute([$subId]);
            $row = $stmt->fetch();
            if (!$row || empty($row['post_commitment_price'])) {
                echo json_encode(['success' => false, 'message' => 'Taahhüt sonrası zamlı fiyat bulunamadı veya yetkiniz yok.']);
                exit;
            }

            $oldPrice = (float)($row['price'] ?? 0);
            $newPrice = (float)$row['post_commitment_price'];
            $pdo->prepare("UPDATE subscriptions SET price = ? WHERE id = ?")->execute([$newPrice, $subId]);

            // Fiyat değişimi varsa geçmişe kaydet
            if ($oldPrice > 0 && abs($newPrice - $oldPrice) >= 0.01) {
                $pct = round((($newPrice - $oldPrice) / $oldPrice) * 100, 2);
                try {
                    $stmtHist = $pdo->prepare("
                        INSERT INTO `subscription_price_history` 
                        (`subscription_id`, `user_id`, `old_price`, `new_price`, `percentage_change`, `currency`, `notes`, `changed_at`)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmtHist->execute([$subId, $_SESSION['user_id'] ?? 1, $oldPrice, $newPrice, $pct, $row['currency'] ?? 'TRY', 'Taahhüt bitişi sonrası yeni tarife fiyatı uygulandı']);
                } catch (Exception $e) {
                    sistemLogla("Taahhüt fiyat geçmişi kaydı hatası: " . $e->getMessage(), 'WARNING');
                }
            }

            sistemLogla("Taahhüt Bitişi Nedeniyle Zamlı Fiyat Uygulandı: ID {$subId} ({$row['title']}) -> " . $newPrice, 'INFO');
            echo json_encode([
                'success' => true,
                'message' => 'Abonelik fiyatı güncel taahhütsüz tarifeye (' . paraFormat($newPrice, $row['currency']) . ') uyarlandı.',
                'new_price' => $newPrice,
                'new_price_formatted' => paraFormat($newPrice, $row['currency'])
            ]);
        } catch (Exception $e) {
            sistemLogla("Tarife uyarlama hatası: " . $e->getMessage(), 'ERROR');
            echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        }
        break;

    // 11. Abonelik Durum Değiştirme (Aktif / Dondur / İptal Et)
    case 'durum_degistir':
        csrfKontrol($_POST['csrf_token'] ?? '');
        $subId = (int)($_POST['subscription_id'] ?? $_POST['id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');
        if (!$subId || !in_array($newStatus, ['active', 'paused', 'canceled', 'cancelled'])) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz parametreler.']);
            exit;
        }
        if ($newStatus === 'cancelled') $newStatus = 'canceled';

        try {
            $isAdmin = (($_SESSION['role'] ?? 'USER') === 'ADMIN');
            $authCheck = $isAdmin ? "1=1" : "(user_id = {$userId} OR payer_user_id = {$userId})";
            $stmt = $pdo->prepare("SELECT id, title FROM subscriptions WHERE id = ? AND {$authCheck}");
            $stmt->execute([$subId]);
            $sub = $stmt->fetch();
            if (!$sub) {
                echo json_encode(['success' => false, 'message' => 'Abonelik bulunamadı veya yetkiniz yok.']);
                exit;
            }

            $pdo->prepare("UPDATE subscriptions SET status = ? WHERE id = ?")->execute([$newStatus, $subId]);
            $statusLabels = ['active' => 'Aktif', 'paused' => 'Donduruldu', 'canceled' => 'İptal Edildi'];
            $label = $statusLabels[$newStatus] ?? $newStatus;
            sistemLogla("Abonelik Durumu Değiştirildi: ID {$subId} ({$sub['title']}) -> " . $label, 'INFO');
            echo json_encode([
                'success' => true,
                'message' => "'{$sub['title']}' aboneliği başarıyla '{$label}' durumuna alındı."
            ]);
        } catch (Exception $e) {
            sistemLogla("Abonelik durum değiştirme hatası: " . $e->getMessage(), 'ERROR');
            echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        }
    // 12. Aylık Bütçe Limiti Güncelleme
    case 'butce_guncelle':
        csrfKontrol($_POST['csrf_token'] ?? '');
        $rawVal = trim($_POST['monthly_budget'] ?? '0');
        $rawVal = str_replace(' ', '', $rawVal);
        if (strpos($rawVal, ',') !== false && strpos($rawVal, '.') !== false) {
            $rawVal = str_replace('.', '', $rawVal);
            $rawVal = str_replace(',', '.', $rawVal);
        } else {
            $rawVal = str_replace(',', '.', $rawVal);
        }
        $budget = max(0, (float)$rawVal);
        try {
            $pdo->prepare("UPDATE users SET monthly_budget = ? WHERE id = ?")->execute([$budget, $userId]);
            sistemLogla("AJAX: Aylık Bütçe Hedefi Güncellendi: {$budget} ₺ (User ID {$userId})", 'INFO');
            jsonYanit([
                'success' => true,
                'message' => 'Aylık bütçe hedefiniz başarıyla güncellendi.',
                'monthly_budget' => $budget,
                'monthly_budget_formatted' => paraFormat($budget, 'TRY')
            ]);
        } catch (Exception $e) {
            sistemLogla("Bütçe güncelleme hatası: " . $e->getMessage(), 'ERROR');
            jsonYanit(['success' => false, 'message' => 'Bütçe güncellenirken bir hata oluştu: ' . $e->getMessage()], 500);
        }
        break;

    default:
        jsonYanit(['success' => false, 'message' => 'Bilinmeyen işlem.']);
        break;
}
