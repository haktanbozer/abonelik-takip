</main>

<!-- Alt Bilgi (Footer) -->
<footer class="border-t border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-slate-900/60 py-6 text-center text-xs text-slate-500 dark:text-slate-400 transition-colors">
    <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <span class="font-bold text-slate-800 dark:text-slate-200">Abonelik &amp; Fatura Takip</span>
            <span>&bull;</span>
            <span>Kişisel Finans &amp; Abonelik Asistanı</span>
        </div>
        <div class="flex items-center gap-4 text-slate-600 dark:text-slate-400">
            <a href="bildirim-gecmisi.php" class="hover:text-blue-600 dark:hover:text-blue-400 transition">Bildirim Geçmişi</a>
            <a href="profil.php" class="hover:text-blue-600 dark:hover:text-blue-400 transition">Ayarlar</a>
        </div>
    </div>
</footer>

<!-- PWA & Tema Yonetim Scriptleri -->
<script nonce="<?= $cspNonce ?>">
    // 1. Tema Degistirme Mantigi
    function guncelleTemaIkonlari() {
        const isDark = document.documentElement.classList.contains('dark');
        const darkIcon = document.getElementById('theme-toggle-dark-icon');
        const lightIcon = document.getElementById('theme-toggle-light-icon');
        if (darkIcon && lightIcon) {
            if (isDark) {
                lightIcon.classList.remove('hidden');
                darkIcon.classList.add('hidden');
            } else {
                lightIcon.classList.add('hidden');
                darkIcon.classList.remove('hidden');
            }
        }
    }

    const themeToggleBtn = document.getElementById('theme-toggle');
    if (themeToggleBtn) {
        guncelleTemaIkonlari();
        themeToggleBtn.addEventListener('click', function() {
            const isDark = document.documentElement.classList.contains('dark');
            if (isDark) {
                document.documentElement.classList.remove('dark');
                document.documentElement.classList.add('light');
                localStorage.setItem('color-theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                document.documentElement.classList.remove('light');
                localStorage.setItem('color-theme', 'dark');
            }
            guncelleTemaIkonlari();
        });
    }

    // 2. PWA Service Worker Kaydi
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('./sw.js')
                .then(reg => {
                    // Service worker kayit edildi
                })
                .catch(err => console.warn('[PWA] SW Hatasi:', err));
        });
    }

    // 3. PWA Kurulum Butonu
    let deferredPrompt;
    const pwaBtn = document.getElementById('pwa-install-btn');

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        if (pwaBtn) {
            pwaBtn.classList.remove('hidden');
            pwaBtn.classList.add('inline-flex');
        }
    });

    if (pwaBtn) {
        pwaBtn.addEventListener('click', async () => {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                if (outcome === 'accepted') {
                    pwaBtn.classList.add('hidden');
                }
                deferredPrompt = null;
            }
        });
    }

    window.addEventListener('appinstalled', () => {
        if (pwaBtn) pwaBtn.classList.add('hidden');
        deferredPrompt = null;
    });

    // 4. Global SweetAlert2 Bildirim Yardimcisi
    function toastBildirim(title, icon = 'success') {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: icon,
            title: title,
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            background: document.documentElement.classList.contains('dark') ? '#1e293b' : '#ffffff',
            color: document.documentElement.classList.contains('dark') ? '#f8fafc' : '#0f172a'
        });
    }

    // 5. Bildirim Dropdown Menusu
    const notifBtn = document.getElementById('notif-btn');
    const notifDropdown = document.getElementById('notif-dropdown');
    if (notifBtn && notifDropdown) {
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notifDropdown.classList.toggle('hidden');
        });
        document.addEventListener('click', (e) => {
            if (!notifDropdown.contains(e.target) && !notifBtn.contains(e.target)) {
                notifDropdown.classList.add('hidden');
            }
        });
    }
</script>

</body>
</html>
