<?php
/**
 * Geriye dönük uyumluluk: Eski konum-sec.php linklerini 301 kalıcı yönlendirmeyle secim.php'ye aktarır.
 */
$queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header("Location: secim.php" . $queryString, true, 301);
exit;
