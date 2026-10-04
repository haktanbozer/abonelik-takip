# 🤝 Katkıda Bulunma Rehberi (Contributing Guide)

Abonelik ve Fatura Takip Sistemi projesine katkıda bulunmak istediğiniz için teşekkür ederiz! Açık kaynak topluluğunun desteği bu projeyi daha güçlü ve güvenli kılmaktadır.

---

## 📋 Katkı İlkeleri ve Kurallar

1. **Zero-Dependency İlkesi:**
   - Projeye Composer, Laravel, React, Vue, npm veya harici ağır kütüphaneler eklenemez.
   - Tüm geliştirmeler saf PHP 8.x, Native PDO, Tailwind CSS (CDN) ve saf JavaScript (Vanilla JS) ile yapılmalıdır.

2. **Güvenlik Standartları:**
   - Yeni bir POST formu ekliyorsanız mutlaka `csrfAlaniniEkle()` ve `csrfKontrol()` fonksiyonlarını uygulayın.
   - SQL sorgularında doğrudan değişken birleştirmeyin; daima PDO `prepare()` ve `execute()` kullanın.
   - Kullanıcı çıktılarında `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` uygulayın.
   - Her veri tabanı işleminde `user_id` / `payer_user_id` yetki izolasyonunu (IDOR önlemi) gözetin.

3. **Kod Düzeni:**
   - Kod girintilemesi için 4 boşluk kullanın.
   - Fonksiyon ve değişken isimlerinde projede kullanılan camelCase veya snake_case stiline sadık kalın.
   - Türkçe değişken/yorum satırları veya anlaşılır İngilizce terminoloji tercih edilebilir.

---

## 🚀 Geliştirme ve PR Süreci

1. Bu depoyu GitHub üzerinde **Fork** edin.
2. Yeni bir özellik veya hata düzeltmesi için bir dal (branch) oluşturun:
   ```bash
   git checkout -b feature/yeni-ozellik
   ```
3. Değişikliklerinizi yapın ve test edin.
4. Anlaşılır bir commit mesajı ile kaydedin:
   ```bash
   git commit -m "feat: taksitli sigorta hesaplama mantığı eklendi"
   ```
5. Dalınızı GitHub'a gönderin:
   ```bash
   git push origin feature/yeni-ozellik
   ```
6. Ana depoya bir **Pull Request (PR)** açın ve yaptığınız değişiklikleri açıklayın.
