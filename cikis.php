<?php
require_once __DIR__ . '/db.php';

// Logout CSRF Koruması: Token GET veya POST ile eşleşmeli
if (isset($_SESSION['user_id'])) {
    $token = $_GET['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    if (!empty($_SESSION['csrf_token']) && !hash_equals($_SESSION['csrf_token'], $token)) {
        // Geçersiz token — yine de güvenli çıkış yap ama logla
        sistemLogla("Şüpheli Logout CSRF Girişimi: " . ($_SESSION['username'] ?? 'User'), 'SECURITY');
    }
    sistemLogla("Çıkış Yapıldı: " . ($_SESSION['username'] ?? 'User'), 'AUTH');
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

header("Location: login.php?msg=logout");
exit;
