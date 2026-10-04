<?php
/**
 * Abonelik ve Fatura Takip Sistemi - Güvenli Belge Görüntüleme ve İndirme Motoru
 * IDOR ve Path Traversal Korumalı
 */

require_once __DIR__ . '/db.php';
girisKontrol();

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'USER';
$isAdmin = ($userRole === 'ADMIN');

$id = (int)($_GET['id'] ?? 0);
$viewInline = !empty($_GET['view']); // 1 ise tarayıcıda aç (PDF / Resim), yoksa direkt indir

if (!$id) {
    http_response_code(400);
    die("Geçersiz abonelik referansı.");
}

try {
    $stmt = $pdo->prepare("SELECT id, user_id, payer_user_id, attachment_file, attachment_name FROM subscriptions WHERE id = ?");
    $stmt->execute([$id]);
    $sub = $stmt->fetch();

    if (!$sub) {
        http_response_code(404);
        die("Abonelik kaydı bulunamadı.");
    }

    // Yetki & IDOR Kontrolü
    if (!$isAdmin) {
        $yetkili = ($sub['user_id'] == $userId || $sub['payer_user_id'] == $userId);
        if (!$yetkili) {
            $stmtAcc = $pdo->prepare("SELECT 1 FROM subscription_access WHERE subscription_id = ? AND user_id = ?");
            $stmtAcc->execute([$id, $userId]);
            $yetkili = (bool)$stmtAcc->fetchColumn();
        }
        if (!$yetkili) {
            $userEmail = $_SESSION['user_email'] ?? '';
            $stmtShare = $pdo->prepare("SELECT 1 FROM subscription_shares WHERE subscription_id = ? AND (user_id = ? OR (person_email IS NOT NULL AND person_email != '' AND person_email = ?))");
            $stmtShare->execute([$id, $userId, $userEmail]);
            $yetkili = (bool)$stmtShare->fetchColumn();
        }

        if (!$yetkili) {
            sistemLogla("Yetkisiz Belge İndirme Girişimi Engellendi (IDOR): Sub ID {$id}, User ID {$userId}", 'SECURITY');
            http_response_code(403);
            die("Bu belgeye erişim yetkiniz bulunmuyor.");
        }
    }

    if (empty($sub['attachment_file'])) {
        http_response_code(404);
        die("Bu aboneliğe ait ekli bir belge bulunmuyor.");
    }

    $filePath = __DIR__ . '/' . ltrim($sub['attachment_file'], '/\\');
    $realPath = realpath($filePath);
    $baseDir  = realpath(__DIR__ . '/uploads/documents');

    // Path Traversal Koruması
    if (!$realPath || !$baseDir || strpos($realPath, $baseDir) !== 0 || !file_exists($realPath)) {
        sistemLogla("Belge dosyası diskte bulunamadı veya geçersiz yol: Sub ID {$id}", 'WARNING');
        http_response_code(404);
        die("Belge dosyası sunucuda bulunamadı.");
    }

    // MIME Türü Belirleme
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $realPath) ?: 'application/octet-stream';
    finfo_close($finfo);

    // Orijinal İsim
    $downloadName = $sub['attachment_name'] ?: basename($realPath);
    // Güvenli dosya adı oluştur
    $safeDownloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);

    // Çıktı tamponunu temizle
    while (ob_get_level()) {
        ob_end_clean();
    }

    // HTTP Başlıkları
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($realPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    $disposition = ($viewInline && in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])) ? 'inline' : 'attachment';
    header("Content-Disposition: {$disposition}; filename=\"{$safeDownloadName}\"");

    readfile($realPath);
    exit;

} catch (Exception $e) {
    sistemLogla("Belge getirme hatası: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    die("Belge işlenirken bir sunucu hatası oluştu.");
}
