<?php
/**
 * Sigorta & Poliçe Detayları Form Bileşeni (Kasko, Trafik, DASK, Sağlık vb.)
 * Hem abonelik-ekle.php hem de abonelik-duzenle.php tarafından ortak kullanılır.
 */
$isEdit = isset($sub);
$hiddenClass = $isEdit ? (($sub['category_type'] === 'insurance') ? '' : 'hidden') : 'hidden';
$insCompany = $isEdit ? ($sub['ins_company'] ?? '') : ($_POST['ins_company'] ?? '');
$insPolicyNo = $isEdit ? ($sub['ins_policy_no'] ?? '') : ($_POST['ins_policy_no'] ?? '');
$insItem = $isEdit ? ($sub['ins_item'] ?? '') : ($_POST['ins_item'] ?? '');
$insAgencyName = $isEdit ? ($sub['ins_agency_name'] ?? '') : ($_POST['ins_agency_name'] ?? '');
$insAgencyPhone = $isEdit ? ($sub['ins_agency_phone'] ?? '') : ($_POST['ins_agency_phone'] ?? '');
$insStartDate = $isEdit ? ($sub['ins_start_date'] ?? '') : ($_POST['ins_start_date'] ?? date('Y-m-d'));
$insEndDate = $isEdit ? ($sub['ins_end_date'] ?? '') : ($_POST['ins_end_date'] ?? date('Y-m-d', strtotime('+1 year')));
$insPaymentType = $isEdit ? ($sub['ins_payment_type'] ?? 'single') : ($_POST['ins_payment_type'] ?? 'single');
$insInstallmentCount = $isEdit ? ($sub['ins_installment_count'] ?? '3') : ($_POST['ins_installment_count'] ?? '3');
$insInstallmentAmount = $isEdit ? ($sub['ins_installment_amount'] ?? '') : ($_POST['ins_installment_amount'] ?? '');
?>
<!-- 5. Sigorta & Poliçe Detayları -->
<div id="section_insurance" class="<?= $hiddenClass ?> bg-white dark:bg-slate-900 border border-rose-200 dark:border-rose-500/30 rounded-3xl p-6 shadow-sm space-y-4">
    <h2 class="text-xs font-bold uppercase tracking-wider text-rose-600 dark:text-rose-400 flex items-center gap-2">
        <span>🛡️ Poliçe & Sigorta Bilgileri (Kasko, Trafik, DASK, Sağlık vb.)</span>
    </h2>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Sigorta Şirketi</label>
            <input type="text" name="ins_company" value="<?= htmlspecialchars($insCompany) ?>" placeholder="Örn: Anadolu Sigorta, Allianz, Axa"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-rose-500 outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Poliçe Numarası</label>
            <input type="text" name="ins_policy_no" value="<?= htmlspecialchars($insPolicyNo) ?>" placeholder="Örn: 24091823901"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-rose-500 outline-none">
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Sigortalanan Varlık / Bilgi</label>
            <input type="text" name="ins_item" value="<?= htmlspecialchars($insItem) ?>" placeholder="Örn: 34 ABC 123 veya Kadıköy Daire No:4"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-rose-500 outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Acente / Temsilci Adı</label>
            <input type="text" name="ins_agency_name" value="<?= htmlspecialchars($insAgencyName) ?>" placeholder="Örn: Mert Sigorta Aracılık"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-rose-500 outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Acente Telefon No</label>
            <input type="text" name="ins_agency_phone" value="<?= htmlspecialchars($insAgencyPhone) ?>" placeholder="Örn: 0212 555 0000"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-rose-500 outline-none">
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Poliçe Başlangıç Tarihi</label>
            <input type="date" name="ins_start_date" value="<?= htmlspecialchars($insStartDate) ?>"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-rose-500 outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-rose-600 dark:text-rose-400 uppercase tracking-wider mb-1.5">Poliçe Bitiş / Yenileme Tarihi *</label>
            <input type="date" name="ins_end_date" value="<?= htmlspecialchars($insEndDate) ?>"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-rose-500 outline-none">
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2 border-t border-slate-200 dark:border-slate-800">
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Ödeme Şekli</label>
            <select name="ins_payment_type" id="ins_payment_type"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-rose-500 outline-none">
                <option value="single" <?= $insPaymentType !== 'installment' ? 'selected' : '' ?>>Tek Çekim / Peşin</option>
                <option value="installment" <?= $insPaymentType === 'installment' ? 'selected' : '' ?>>Taksitli Ödeme</option>
            </select>
        </div>
        <div id="ins_installment_fields" class="sm:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-4 <?= $insPaymentType === 'installment' ? '' : 'hidden' ?>">
            <div>
                <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Taksit Sayısı</label>
                <input type="number" name="ins_installment_count" id="ins_installment_count" min="1" max="24" value="<?= htmlspecialchars($insInstallmentCount) ?>"
                    class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-rose-500 outline-none">
            </div>
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Aylık Taksit Tutarı</label>
                    <span id="ins_installment_calc_btn" class="text-[10px] text-rose-500 dark:text-rose-400 font-semibold cursor-pointer hover:underline" title="Toplam Tutardan Hesapla">Hesapla ↺</span>
                </div>
                <input type="text" name="ins_installment_amount" id="ins_installment_amount" value="<?= htmlspecialchars($insInstallmentAmount) ?>" placeholder="Örn: 1250.00"
                    class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-rose-500 outline-none">
            </div>
        </div>
    </div>
</div>
