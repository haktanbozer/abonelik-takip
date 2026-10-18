<?php
/**
 * Doğrudan klasör ve dosya erişimi yasaktır.
 * Dosyalar yalnızca belge-indir.php üzerinden IDOR ve yetki kontrolüyle indirilebilir.
 */
http_response_code(403);
header("HTTP/1.1 403 Forbidden");
die("403 Forbidden - Doğrudan erişim yasaktır.");
