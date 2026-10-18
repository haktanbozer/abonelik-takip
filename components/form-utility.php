<?php
/**
 * Ev Faturası Detayları Form Bileşeni (Su, Elektrik, Doğalgaz, Aidat)
 * Hem abonelik-ekle.php hem de abonelik-duzenle.php tarafından ortak kullanılır.
 */
$isEdit = isset($sub);
$hiddenClass = $isEdit ? (($sub['category_type'] === 'utility') ? '' : 'hidden') : 'hidden';
$companyName = $isEdit ? ($sub['company_name'] ?? '') : ($_POST['company_name'] ?? '');
$subscriberNo = $isEdit ? ($sub['subscriber_no'] ?? '') : ($_POST['subscriber_no'] ?? '');
$meterNo = $isEdit ? ($sub['meter_no'] ?? '') : ($_POST['meter_no'] ?? '');
$cutDay = $isEdit ? ($sub['billing_cut_day'] ?? '') : ($_POST['billing_cut_day'] ?? '');
$dueDay = $isEdit ? ($sub['due_day'] ?? '') : ($_POST['due_day'] ?? '');
?>
<!-- 3. Fatura & Ev Detayları -->
<div id="section_utility" class="<?= $hiddenClass ?> bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-500/30 rounded-3xl p-6 shadow-sm space-y-4">
    <h2 class="text-xs font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400 flex items-center gap-2">
        <span>⚡ Ev Faturası Bilgileri (Su, Doğalgaz, Elektrik, Aidat)</span>
    </h2>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div id="util_company_wrap">
            <label id="util_company_label" class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Firma / Kurum Adı</label>
            <input type="text" name="company_name" id="util_company_input" value="<?= htmlspecialchars($companyName) ?>" placeholder="Örn: İSKİ, İGDAŞ, BEDAŞ"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-amber-500 outline-none">
        </div>
        <div id="util_subno_wrap">
            <label id="util_subno_label" class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Abone / Sözleşme No</label>
            <input type="text" name="subscriber_no" id="util_subno_input" value="<?= htmlspecialchars($subscriberNo) ?>" placeholder="Örn: 10492819"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-amber-500 outline-none">
        </div>
        <div id="util_meter_wrap">
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Sayaç / Tesisat No</label>
            <input type="text" name="meter_no" id="util_meter_input" value="<?= htmlspecialchars($meterNo) ?>" placeholder="Örn: SY-9201"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-amber-500 outline-none">
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div id="util_cutday_wrap">
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Fatura Kesim Günü (Ayın Kaçı?)</label>
            <input type="number" name="billing_cut_day" id="util_cutday_input" value="<?= htmlspecialchars($cutDay) ?>" min="1" max="31" placeholder="Örn: 10"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-amber-500 outline-none">
            <p class="text-[11px] text-slate-500 mt-1">Örn: Her ayın 10'unda fatura kesiliyorsa 10 yazın.</p>
        </div>
        <div id="util_dueday_wrap">
            <label id="util_dueday_label" class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Fatura Son Ödeme Günü (Ayın Kaçı?)</label>
            <input type="number" name="due_day" id="util_dueday_input" value="<?= htmlspecialchars($dueDay) ?>" min="1" max="31" placeholder="Örn: 20"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-amber-500 outline-none">
            <p id="util_dueday_desc" class="text-[11px] text-slate-500 mt-1">Örn: Her ayın 20'si son ödeme günüyse 20 yazın.</p>
        </div>
    </div>
</div>
