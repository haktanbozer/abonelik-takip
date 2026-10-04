# 🔒 Güvenlik Politikası (Security Policy)

Bu proje, kişisel finans ve abonelik verilerinin güvenliğini en üst düzeyde tutmak amacıyla tasarlanmıştır.

---

## 🛡️ Desteklenen Sürümler

| Sürüm | Destek Durumu |
| :--- | :--- |
| **v1.0.x (Güncel)** | :white_check_mark: Destekleniyor |
| < v1.0.0 | :x: Desteklenmiyor |

---

## 🚨 Güvenlik Açığı Bildirimi

Bir güvenlik açığı keşfederseniz lütfen herkese açık bir GitHub Issue (Hata Kaydı) **açmayınız**. 

Bunun yerine:
1. GitHub deposunun **"Security" > "Advisories" > "Report a vulnerability"** bölümünden özel bildirim oluşturun.
2. Veya doğrudan e-posta yoluyla sistem yöneticisine bildirin: `security@example.com`

Lütfen bildiriminizde şu ayrıntılara yer verin:
- Güvenlik açığının türü (Örn: XSS, SQLi, CSRF, Yetki Aşımı vb.)
- Adım adım yeniden oluşturma (reproduce) yönergeleri
- Varsa ekran görüntüsü, PoC kodu veya HTTP istek/yanıt dökümü

Bildiriminiz en geç 48 saat içinde incelenecek ve gerekli yama yayınlanacaktır.

---

## 🔐 Uygulanan Temel Güvenlik Önlemleri

- **SQL Injection Koruması:** Tüm veritabanı etkileşimleri native PDO Prepared Statements (`prepare` + `execute`) ile parametrik çalışır.
- **XSS Koruması & CSP:** Ekrana basılan tüm kullanıcı girdileri `htmlspecialchars` süzgecinden geçirilir; dinamik kriptografik `nonce` tabanlı Content-Security-Policy başlıkları uygulanır.
- **Kriptografik Kasa (AES-256-CBC):** Dijital abonelik şifreleri OpenSSL AES-256-CBC ile rastgele Initialization Vector (IV) kullanılarak şifrelenir.
- **Kaba Kuvvet (Brute-Force) Kalkanı:** Giriş ekranında IP bazlı, Master PIN doğrulamalarında ise kullanıcı bazlı otomatik kilit mekanizması bulunur.
- **CSRF Token Koruması:** Tüm HTTP POST formları ve AJAX istekleri oturum bazlı kriptografik token ile doğrulanır.
- **Path Traversal Önlemi:** Yüklenen ekler web kök dizini erişimine kapalı tutulur; yalnızca `belge-indir.php` üzerinden `realpath` yetki doğrulamasıyla indirilebilir.
