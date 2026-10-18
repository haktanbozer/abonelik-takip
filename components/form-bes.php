<?php
/**
 * Bireysel Emeklilik (BES) Detayları Form Bileşeni
 * Hem abonelik-ekle.php hem de abonelik-duzenle.php tarafından ortak kullanılır.
 */
$isEdit = isset($sub);
$hiddenClass = $isEdit ? (($sub['category_type'] === 'bes') ? '' : 'hidden') : 'hidden';
$besCompany = $isEdit ? ($sub['bes_company'] ?? '') : ($_POST['bes_company'] ?? '');
$besContractNo = $isEdit ? ($sub['bes_contract_no'] ?? '') : ($_POST['bes_contract_no'] ?? '');
$besPaymentDay = $isEdit ? ($sub['bes_payment_day'] ?? '15') : ($_POST['bes_payment_day'] ?? '15');
$besRelation = $isEdit ? ($sub['bes_owner_relation'] ?? 'Kendim') : ($_POST['bes_owner_relation'] ?? 'Kendim');
$besAutoPaySource = $isEdit ? ($sub['bes_auto_pay_source'] ?? '') : ($_POST['bes_auto_pay_source'] ?? '');
?>
<!-- 6. Bireysel Emeklilik (BES) Detayları -->
<div id="section_bes" class="<?= $hiddenClass ?> bg-white dark:bg-slate-900 border border-cyan-200 dark:border-cyan-500/30 rounded-3xl p-6 shadow-sm space-y-4">
    <h2 class="text-xs font-bold uppercase tracking-wider text-cyan-600 dark:text-cyan-400 flex items-center gap-2">
        <span>📈 Bireysel Emeklilik (BES) Sözleşme Bilgileri</span>
    </h2>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Emeklilik Şirketi *</label>
            <input type="text" name="bes_company" value="<?= htmlspecialchars($besCompany) ?>" placeholder="Örn: Anadolu Hayat, Avivasa, Garanti Emeklilik"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500 outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Sözleşme / Sertifika No</label>
            <input type="text" name="bes_contract_no" value="<?= htmlspecialchars($besContractNo) ?>" placeholder="Örn: BES-98231012"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500 outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Düzenli Çekim Günü (Ayın Kaçı?)</label>
            <input type="number" name="bes_payment_day" min="1" max="31" value="<?= htmlspecialchars($besPaymentDay) ?>" placeholder="Örn: 15"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500 outline-none">
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Sözleşme Sahibi / Kime Ait?</label>
            <select name="bes_owner_relation" class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500 outline-none">
                <?php 
                $relations = ['Kendim', 'Eşim', 'Çocuğum', 'Anne / Baba', 'Diğer'];
                foreach ($relations as $rel): ?>
                    <option value="<?= $rel ?>" <?= $besRelation === $rel ? 'selected' : '' ?>><?= $rel ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Otomatik Tahsilat Kaynağı</label>
            <input type="text" name="bes_auto_pay_source" value="<?= htmlspecialchars($besAutoPaySource) ?>" placeholder="Örn: Bonus Kartım veya Vadesiz TL"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500 outline-none">
        </div>
    </div>
</div>
