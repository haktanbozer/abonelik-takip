# 💳 Abonelik ve Fatura Takip Sistemi (Subscription & Recurring Bill Tracker)

[![PHP Version](https://img.shields.io/badge/PHP-7.4%20|%208.0%20|%208.1%20|%208.2%20|%208.3-blue.svg)](https://www.php.net/)
[![Database](https://img.shields.io/badge/Database-MySQL%20%7C%20MariaDB-orange.svg)](https://mariadb.org/)
[![Styling](https://img.shields.io/badge/UI-Tailwind%20CSS%203-38bdf8.svg)](https://tailwindcss.com/)
[![Encryption](https://img.shields.io/badge/Security-AES--256--CBC%20%7C%20CSP-emerald.svg)](https://owasp.org/)
[![PWA](https://img.shields.io/badge/PWA-Ready-purple.svg)](https://web.dev/progressive-web-apps/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Modern, güvenli, çoklu para birimi destekli, **kademeli otomatik e-posta bildirim motoruna** sahip açık kaynaklı kişisel ve aile/ekip finans & abonelik yönetim sistemi.

Hem dijital platformlarınızı (Netflix, Spotify, ChatGPT vb.), hem ev faturalarınızı (Elektrik, Su, Doğalgaz, Aidat), hem taahhütlü telekomünikasyon sözleşmelerinizi (Ev İnterneti, TV, Mobil Hat), hem sigorta poliçelerinizi (Kasko, Trafik, TSS), hem de Bireysel Emeklilik (BES) birikimlerinizi tek merkezden takip edin.

---

## 🌟 Öne Çıkan Özellikler

### 1. ⚡ Ev & Fiziksel Faturalar
* Elektrik, Su, Doğalgaz, Apartman / Site Aidatı gibi tüm konut faturalarını yönetin.
* **Abone No, Sayaç No, Tesisat No** kayıtları ve tek tıkla panoya kopyalama.
* Aidat seçildiğinde sayaç/tesisat gibi gereksiz alanların dinamik gizlenmesi.
* Fatura kesim günü ve son ödeme günü bazında periyodik vade takibi.

### 2. 📶 İnternet & Mobil Hat (Taahhüt & Zam Kalkanı)
* **Taahhüt Bitiş Takvimi:** Taahhüdünüzün bitmesine 30 gün, 15 gün ve 3 gün kala otomatik e-posta uyarısı.
* **Taahhüt Sonrası Zam Alarmı:** Taahhüt bittiğinde paket fiyatının kaça katlanacağını görerek *(Örn: 350 TL'den 1.100 TL'ye fırlayacak uyarısı)* yeni tarifeye geçişinizi zamanında planlayın.
* Taahhüt dolduğunda tek tıkla yeni tarife fiyatına uyarla butonu.
* Hız, kota, operatör, servis türü (Fiber, VDSL, Mobil) ve modem kira bedeli takibi.

### 3. 💻 Dijital Platform & SaaS Kasası
* **AES-256-CBC Şifreli Kasa:** Kullanıcı adı ve şifreler veritabanında düz metin olarak değil, OpenSSL AES-256-CBC ile rastgele IV ile şifreli tutulur.
* **Master PIN Koruması:** Dijital şifreler ekranda maskeli görünür (`••••••••`). Yalnızca Master PIN doğrulandığında geçici olarak çözülür.
* **Master PIN Kaba Kuvvet Koruması:** 5 ardışık hatalı PIN girişinde veritabanı kilit mekanizması devreye girer.
* **Üyeler Arası Seçici Şifre Paylaşımı:** Dijital abonelik sahibi, hesap şifresini hangi üyelerin görebileceğini tek tıkla seçebilir (`subscription_access`).
* **Ücretsiz Deneme (Free Trial) Takibi:** Deneme süresi bitmeden önce bildirim göndererek kartınızdan istenmeyen para çekilmesini önler.

### 4. 🛡️ Sigorta & Poliçe Yönetimi
* Tamamlayıcı Sağlık Sigortası (TSS), Özel Sağlık (ÖSS), Kasko, Trafik, DASK ve Konut sigortaları.
* Sigorta acentesi, acente telefonu, poliçe no ve sigortalanan araç/konut takibi.
* **Akıllı Taksitli Ödeme Mantığı:** Taksitli poliçelerde bütçe ve takvim hesaplamalarında toplam tutar yerine aylık taksit tutarı (`installment_amount`) baz alınır (örneğin 14.150 TL'lik yıllık kasko her ay bütçeye tek seferde değil, 1.179 TL taksit olarak yansır).
* **Dönemsel Ödeme Akışı & Takvim Entegrasyonu:** Taksitli poliçeler takvimde her ay taksit gününde düzenli olarak yer alır; "Ödendi" işaretlendiğinde otomatik olarak bir sonraki taksit ayına ötelenir.
* Poliçe bitiş gününden önce (30, 15, 7, 3, 1 gün kala) acente teklif alma ve poliçe yenileme alarmları.

### 5. 📈 Bireysel Emeklilik Sistemi (BES)
* Şirket adı, sözleşme no, aylık katkı payı tutarı ve tahsilat günü takibi.
* Hak sahibi bağı (Kendim, Eşim, Çocuğum).

### 6. 💳 Kredi Kartı & Banka Hesapları Yönetimi
* Kart adı, son 4 hane, kart ağı (Mastercard, Visa, Troy, Amex), kart limiti ve renk teması.
* Hesap kesim günü ve son ödeme günü takibi.
* Aboneliklerin hangi kart veya hesaptan çekileceğini bağlama (Otomatik / Manuel ödeme takibi).

### 7. 🎁 Nakit İade (Cashback) & Paylaşımlı Masraf Gelirleri (Net Bütçe)
* **Cashback / İndirim Desteği:** Kredi kartı nakit iadelerini (Papara, Nays, Enpara, Axess vb.) veya kampanya indirimlerini doğrudan abonelik bazında kaydedebilme.
* **Net Aylık Gider Analizi:** Paylaşımlı aboneliklerden tahsil edilen ortak paylarını (`is_paid = 1`) ve cashback indirimlerini brüt tutardan düşerek gerçek **"Net Aylık Gider"** ve **"Tasarruf / Bütçe Katkısı"** hesaplama.
* **Ortak Kullanım & Masraf Bölüşümü:** Spotify Aile, Netflix, YouTube Premium vb. ortak hesaplarda kişi başı hisse ve kimin ödeyip ödemediğinin takibi.
* **Ödeme Sorumlusu Atama:** Her aboneliğe sorumlu üye atanabilir (`payer_user_id`).
* **Kişisel Bütçe Dağılımı:** Ana sayfada kimin ne kadar aylık sabit gider üstlendiği (tutar, yüzde ve abonelik adedi) grafiksel olarak raporlanır.
* **Kişiye Özel Bildirimler:** Fatura ve vade uyarıları doğrudan ödemeyi üstlenen üyeye gönderilir.

### 8. 🎯 Aylık Bütçe Limiti & Harcama Uyarı Çubuğu
* Profil sayfasından belirlenebilen kişisel **"Aylık Hedef Bütçe Limiti"** (`monthly_budget`).
* Ana sayfada gerçek net harcamanızın bütçenize oranını canlı hesaplayan dinamik renkli ilerleme çubuğu:
  * 🟢 **Normal (%0 - %75):** Güvenli harcama bandı (Zümrüt yeşili).
  * 🟡 **Dikkat (%75 - %99):** Bütçe sınırına yaklaşma uyarısı (Amber sarısı).
  * 🔴 **Limit Aşımı (%100+):** Bütçe aşım alarmı (Canlı kırmızı).

### 9. 📈 Son 1 Yıldaki Fiyat Değişimi & Dinamik Zam Rozetleri
* Her abonelik düzenlendiğinde veya fiyatı güncellendiğinde otomatik olarak `subscription_price_history` tablosuna tarihçeli kayıt düşülür.
* Abonelik kartlarında ve detay sayfasında son 12 aydaki kümülatif fiyat değişimini gösteren dinamik rozetler:
  * `+%25` Kırmızı rozet: Son 1 yılda fiyat artışı.
  * `-%10` Yeşil rozet: İndirim veya paket düşürme.
* Detay sayfasında eski fiyat, yeni fiyat, değişim oranı ve güncellenme tarihini içeren detaylı zam tablosu.

### 10. 👥 Evrensel Erişim & Aile Yetkilendirmesi
* Yalnızca dijital platformlar değil; elektrik, su, doğalgaz, internet, sigorta ve diğer tüm kategorilerdeki abonelikler için aile üyelerine görüntüleme izni (`subscription_access`).
* İzni olan kullanıcılar abonelik detaylarını inceleyebilir ve yüklenmiş fatura/poliçe eklerini güvenli biçimde indirebilir.
* IDOR ve yetki denetimi sayesinde izni olmayan kullanıcılar diğer üyelerin fatura detaylarına veya eklerine erişemez.

### 11. 📍 Mekan & Kapsam İzolasyonu (Locations)
* Evim, Ofis, Yazlık veya Kişisel & Dijital gibi mekanlar tanımlayabilme (`mekan-yonetimi.php`).
* Kullanıcı bazlı mekan yetkilendirmesi (`location_access`).
* Mekan seçildiğinde yalnızca o mekana uygun kategorilerin dinamik listelenmesi (Türkçe harf ve büyük/küçük harf duyarlı akıllı eşleşme).

### 12. 📅 İnteraktif Nakit Akışı & Fatura Takvimi
* Ay boyunca hangi gün ne kadar para çıkacağını gösteren interaktif takvim ızgarası.
* Fatura kesim günleri ve son ödeme günleri farklı renklerde görselleştirilir.

### 13. 🤖 Otomatik Bildirim Motoru (`cron-mail.php`)
* cPanel veya Linux crontab üzerinden her sabah arka planda otomatik çalışır.
* Fatura, taahhüt ve deneme sürümü uyarılarını doğrudan ilgili üyeye HTML e-posta olarak iletir.
* Gömülü **PHPMailer (v6.9)** ile Composer veya harici kütüphane gerektirmez.

### 14. 📱 PWA (Progressive Web App) Desteği
* Android, iOS ve masaüstünde yerel uygulama gibi kurulabilir.
* Service Worker ve `manifest.json` önbellek altyapısı ile çevrimdışı fallback (`offline.html`) sayfası sunar.

---

## 🛡️ Güvenlik ve Mimari

* **Zero-Dependency Altyapı:** Composer zorunluluğu yoktur; standart paylaşımlı hosting ve cPanel üzerinde tak-çalıştır çalışır.
* **SQL Injection Koruması:** Tüm sorgular PDO `prepare()` ve `execute()` ile parametrik bağlanır.
* **IDOR (Veri İzolasyonu):** Kullanıcılar sadece yetkili oldukları abonelikleri, mekanları ve kartları düzenleyebilir/görüntüleyebilir.
* **CSRF Token Kalkanı:** Tüm formlar ve AJAX uç noktaları kriptografik token ile korunur.
* **XSS & Katı CSP Politikası:** Çıktılar `htmlspecialchars` ile temizlenir; sayfalarda dinamik `nonce` tabanlı Content-Security-Policy (CSP) başlıkları uygulanır.
* **Brute-Force Koruması:** Giriş sayfasında 5 hatalı denemede IP kilitlenir; Master PIN için kullanıcı bazlı kilit devrededir.
* **Path Traversal Koruması:** Yüklenen belgeler web erişimine kapalıdır, yalnızca yetki kontrolü yapan `belge-indir.php` üzerinden `realpath()` doğrulaması ile indirilebilir.

---

## 🚀 Kurulum Rehberi

### Sistem Gereksinimleri
* PHP 7.4 veya üzeri (PHP 8.0, 8.1, 8.2, 8.3 ile tam uyumludur)
* MySQL 5.7+ veya MariaDB 10.3+
* PHP Eklentileri: `pdo_mysql`, `openssl`, `mbstring`, `json`
* Apache, Nginx veya LiteSpeed web sunucusu

---

### Yöntem 1: Otomatik Kurulum Sihirbazı (Önerilen)

1. Proje dosyalarını web sunucunuzun dizinine (`public_html/` veya `htdocs/`) yükleyin.
2. Tarayıcınızdan kurulum sihirbazını açın:
   ```
   http://localhost/abonelik-takip/install.php
   ```
3. Kurulum ekranında veritabanı, yönetici hesabı, Master PIN ve SMTP bilgilerinizi girip **"Kurulumu Başlat"** butonuna tıklayın.
4. Sihirbaz veritabanı tablolarını, varsayılan kategorileri ve `.env` dosyasını otomatik oluşturacaktır.
5. Kurulum bittikten sonra güvenlik için `install.php` dosyasını sunucunuzdan silin.

---

### Yöntem 2: Manuel Kurulum (.env ve SQL ile)

1. Sunucunuzda MySQL veritabanı oluşturun ve `database.sql` dosyasını phpMyAdmin veya MySQL CLI ile içeri aktarın:
   ```bash
   mysql -u kullanici_adi -p veritabani_adi < database.sql
   ```
2. `.env.example` dosyasını `.env` olarak kopyalayın:
   ```bash
   cp .env.example .env
   ```
3. `.env` dosyasını kendi ortamınıza göre düzenleyin:
   ```env
   APP_INSTALLED=true
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=veritabani_adiniz
   DB_USER=veritabani_kullaniciniz
   DB_PASS=veritabani_sifreniz

   APP_ENV=production
   APP_URL=https://alanadiniz.com/abonelik-takip
   APP_SECRET_KEY=32_karakterlik_guclu_rastgele_bir_anahtar
   MASTER_SALT=rastgele_bir_salt_metni

   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=587
   SMTP_USER=ornek@gmail.com
   SMTP_PASS=uygulama_sifreniz
   SMTP_SECURE=tls
   MAIL_FROM_ADDRESS=ornek@gmail.com
   MAIL_FROM_NAME="Abonelik Takip Asistanı"

   CRON_SECRET=guclu_bir_cron_guvenlik_anahtari
   DEFAULT_CURRENCY=TRY
   ```
4. Yönetici hesabınızı oluşturun ve sisteme giriş yapın:
   * Tarayıcınızdan kurulum sihirbazını (`http://localhost/abonelik-takip/install.php`) açarak yönetici kullanıcı adı, güçlü şifre, e-posta ve Master PIN kodunuzu belirleyin.
   * Sihirbaz ilk yönetici kaydınızı ve şifreleme anahtarlarını güvenli hash algoritmalarıyla otomatik olarak kaydedecektir.
   *(Kurulum tamamlandıktan sonra güvenlik amacıyla `install.php` dosyasını sunucunuzdan silebilirsiniz).*

---

## ⏰ Otomatik E-Posta Cron Görevi Kurulumu

Her sabah saat 09:00'da vadesi yaklaşan faturaları, biten taahhütleri ve deneme sürümlerini tarayıp e-posta göndermesi için sunucunuza şu crontab satırını ekleyin:

### cPanel Üzerinden:
* **Dakika:** `0`
* **Saat:** `9`
* **Gün:** `*` | **Ay:** `*` | **Haftanın Günü:** `*`
* **Komut:**
  ```bash
  php /home/KULLANICI_ADINIZ/public_html/cron-mail.php >/dev/null 2>&1
  ```

### HTTP / Webhook Üzerinden:
Sunucunuzda crontab CLI desteği yoksa, harici bir cron servisi (cron-job.org vb.) ile tetikleyebilirsiniz:
```
GET https://alanadiniz.com/cron-mail.php?token=CRON_SECRET_DEGERINIZ
```

---

## 📂 Dizin Yapısı

```
abonelik-takip/
├── components/             # Yeniden kullanılabilir UI bileşenleri
├── icons/                  # PWA ve favicon uygulama ikonları
├── logs/                   # Sistem ve güvenlik logları (.htaccess ile korumalı)
├── PHPMailer/              # Gömülü PHPMailer v6.9 kütüphanesi
├── sessions/               # Güvenli PHP oturum dosyaları (.htaccess ile korumalı)
├── uploads/                # Yüklenen poliçe ve fatura belgeleri (.htaccess ile korumalı)
│   └── documents/
├── .cursorrules            # AI ajan ve geliştirici mimari kuralları
├── .env.example            # Çevre değişkenleri yapılandırma şablonu
├── .gitattributes          # Satır sonu (LF) ve ikili dosya normalizasyonu
├── .gitignore              # Hassas verileri koruyan Git kuralları
├── .htaccess               # Apache güvenlik ve yönlendirme kuralları
├── build-css.bat           # Tailwind CSS derleme betiği
├── CONTRIBUTING.md         # Katkıda bulunma ilkeleri ve PR rehberi
├── LICENSE                 # MIT Açık Kaynak Lisansı
├── README.md               # Detaylı sistem dokümantasyonu
├── SECURITY.md             # Güvenlik politikası ve açık bildirim yönergeleri
├── database.sql            # Temiz, hazır veritabanı şeması ve başlangıç verileri
├── db.php                  # Veritabanı bağlantısı ve çekirdek fonksiyonlar
├── migrations.php          # Otomatik versiyon ve şema göç yöneticisi (v1..v20)
├── header.php              # Navigasyon, acil bildirimler ve CSP başlıkları
├── footer.php              # Sayfa altlığı, tema yöneticisi ve scriptler
├── sidebar.php             # Yönetim yan menüsü
├── index.php               # Ana gösterge paneli (Dashboard), bütçe limiti ve dağılım
├── abonelikler.php         # Abonelik ve fatura listeleme / filtreleme
├── abonelik-ekle.php       # Yeni abonelik / fatura ekleme formu
├── abonelik-duzenle.php    # Abonelik güncelleme formu
├── abonelik-detay.php      # Abonelik detay sayfası ve Master PIN çözücü
├── fatura-takvimi.php      # İnteraktif aylık nakit akışı takvimi
├── kredi-kartlari.php      # Kredi kartları ve banka hesapları yönetimi
├── secim.php               # Aktif portföy/alan/kart hızlı geçiş ekranı
├── mekan-yonetimi.php      # Konum ve mekan yönetimi (Ekle / Düzenle / Sil)
├── kategoriler.php         # Kategori ve tür yönetimi (Admin)
├── admin.php               # Kullanıcı ve mekan yetkilendirme paneli (Admin)
├── profil.php              # Kullanıcı profili, bütçe limiti, parola ve Master PIN
├── bildirim-ayarlari.php   # Bildirim eşikleri ve SMTP ayarları
├── bildirim-gecmisi.php    # Gönderilen e-posta günlükleri
├── log-goruntule.php       # Sistem logları denetim ekranı (Admin)
├── cron-mail.php           # Otomatik e-posta bildirim motoru
├── ajax.php                # Güvenli merkezi AJAX işleyicisi
├── belge-indir.php         # Path-traversal ve yetki kontrollü belge indirme
├── excel-export.php        # Abonelik listesini Excel formatında dışa aktarma
├── tailwind.config.js      # Tailwind CSS yapılandırması
├── sw.js                   # PWA Service Worker
├── manifest.json           # PWA Web Uygulama Manifesti
├── offline.html            # Çevrimdışı durum bilgilendirme sayfası
└── install.php             # Otomatik kurulum sihirbazı
```

---

## 🐙 GitHub'a Yükleme (Git İlk Kurulum)

Projeyi ilk defa GitHub'a aktarmak için terminal veya Git Bash üzerinden şu adımları izleyebilirsiniz:

```bash
# 1. Proje dizinine geçin ve Git deposunu başlatın
git init

# 2. Tüm optimize edilmiş dosyaları hazırlık alanına ekleyin
git add .

# 3. İlk commit'inizi oluşturun
git commit -m "feat: ilk kararlı sürüm (Abonelik ve Fatura Takip Sistemi)"

# 4. Ana dal adını main olarak belirleyin
git branch -M main

# 5. Kendi GitHub deponuzu uzak depo (remote) olarak bağlayın
git remote add origin https://github.com/KULLANICI_ADINIZ/DEPO_ADINIZ.git

# 6. Kodları GitHub'a gönderin
git push -u origin main
```

> [!NOTE]
> `.env`, kişisel veritabanı dökümleri (`*.sql`), derleme araçları (`tools/`), oturum dosyaları (`sessions/`), sistem logları (`logs/`) ve yüklenen gerçek faturalar (`uploads/documents/`) `.gitignore` ile korunmaktadır; GitHub'a asla yüklenmez. Depoya yalnızca temiz şablon olan `.env.example` ve boş şema dosyası `database.sql` dahil edilir.

---

## 📄 Lisans

Bu proje [MIT Lisansı](LICENSE) kapsamında lisanslanmıştır. Dilediğiniz gibi kullanabilir, değiştirebilir ve geliştirebilirsiniz.
