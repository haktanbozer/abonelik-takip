<?php
/**
 * Telekomünikasyon & İnternet Detayları Form Bileşeni (Fiber, Mobil Hat, Taahhüt)
 * Hem abonelik-ekle.php hem de abonelik-duzenle.php tarafından ortak kullanılır.
 */
$isEdit = isset($sub);
$hiddenClass = $isEdit ? (($sub['category_type'] === 'telecom') ? '' : 'hidden') : 'hidden';
$operatorName = $isEdit ? ($sub['operator_name'] ?? '') : ($_POST['operator_name'] ?? '');
$serviceType = $isEdit ? ($sub['service_type'] ?? 'fiber') : ($_POST['service_type'] ?? 'fiber');
$phoneOrLine = $isEdit ? ($sub['phone_or_line_no'] ?? '') : ($_POST['phone_or_line_no'] ?? '');
$speedOrQuota = $isEdit ? ($sub['speed_or_quota'] ?? '') : ($_POST['speed_or_quota'] ?? '');
$commStart = $isEdit ? ($sub['commitment_start'] ?? '') : ($_POST['commitment_start'] ?? '');
$commEnd = $isEdit ? ($sub['commitment_end'] ?? '') : ($_POST['commitment_end'] ?? '');
$postCommPrice = $isEdit ? ($sub['post_commitment_price'] ?? '') : ($_POST['post_commitment_price'] ?? '');
$deviceRental = $isEdit ? ($sub['device_rental_fee'] ?? '') : ($_POST['device_rental_fee'] ?? '');
?>
<!-- 4. Telekomünikasyon & İnternet Detayları -->
<div id="section_telecom" class="<?= $hiddenClass ?> bg-white dark:bg-slate-900 border border-cyan-200 dark:border-cyan-500/30 rounded-3xl p-6 shadow-sm space-y-4">
    <h2 class="text-xs font-bold uppercase tracking-wider text-cyan-600 dark:text-cyan-400 flex items-center gap-2">
        <span>📶 İnternet & Mobil Hat & Taahhüt Bilgileri</span>
    </h2>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Operatör Adı</label>
            <input type="text" name="operator_name" value="<?= htmlspecialchars($operatorName) ?>" placeholder="Örn: Turkcell, Türk Telekom, Vodafone"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500 outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Hizmet Türü</label>
            <select name="service_type" id="tel_service_type" class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500 outline-none">
                <option value="fiber" <?= $serviceType === 'fiber' ? 'selected' : '' ?>>Fiber İnternet</option>
                <option value="vdsl" <?= $serviceType === 'vdsl' ? 'selected' : '' ?>>VDSL İnternet</option>
                <option value="adsl" <?= $serviceType === 'adsl' ? 'selected' : '' ?>>ADSL İnternet</option>
                <option value="mobile" <?= $serviceType === 'mobile' ? 'selected' : '' ?>>Mobil Hat (Faturalı)</option>
                <option value="tv_box" <?= $serviceType === 'tv_box' ? 'selected' : '' ?>>TV Platformu (Kutu / Dekoder)</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Hat / Hizmet Numarası</label>
            <input type="text" name="phone_or_line_no" value="<?= htmlspecialchars($phoneOrLine) ?>" placeholder="Örn: 0532xxxxxxx veya 8820xxxxxx"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500 outline-none">
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Hız / Kota / Paket Bilgisi</label>
            <input type="text" name="speed_or_quota" value="<?= htmlspecialchars($speedOrQuota) ?>" placeholder="Örn: 1000 Mbps Fiber / 30 GB + 1000 DK"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500 outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Taahhüt Başlangıç</label>
            <input type="date" name="commitment_start" value="<?= htmlspecialchars($commStart) ?>"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500 outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Taahhüt Bitiş Tarihi *</label>
            <input type="date" name="commitment_end" value="<?= htmlspecialchars($commEnd) ?>"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500 outline-none">
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-slate-200 dark:border-slate-800">
        <div>
            <label class="block text-xs font-bold text-rose-600 dark:text-rose-400 uppercase tracking-wider mb-1.5">Taahhüt Bitişi Sonrası Fiyat (Zam Uyarısı)</label>
            <input type="text" name="post_commitment_price" value="<?= htmlspecialchars($postCommPrice) ?>" placeholder="Örn: 950.00"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-rose-600 dark:text-rose-300 text-sm focus:ring-2 focus:ring-rose-500 outline-none font-bold">
            <p class="text-[11px] text-slate-500 mt-1">Taahhüt bitince indirim kalkınca fırlayacak olan güncel fiyat.</p>
        </div>
        <div id="device_rental_fee_wrapper">
            <label class="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-1.5">Modem / Cihaz Kira Bedeli</label>
            <input type="text" name="device_rental_fee" id="device_rental_fee_input" value="<?= htmlspecialchars($deviceRental) ?>" placeholder="0.00"
                class="w-full p-3 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500 outline-none">
        </div>
    </div>
</div>
