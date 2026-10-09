    </main>

    <!-- Footer -->
    <footer class="bg-slate-900 border-t border-slate-800 text-slate-400 text-xs py-6 mt-auto">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center space-x-2">
                <span class="font-semibold text-slate-200"><?= htmlspecialchars(SITE_NAME) ?></span>
                <span>&copy; <?= date('Y') ?>. All rights reserved.</span>
            </div>
            <div class="flex items-center space-x-6">
                <span>Fast & Secure SMM Solutions</span>
                <span class="text-slate-600">|</span>
                <span class="text-emerald-400 flex items-center space-x-1">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>Systems Operational</span>
                </span>
            </div>
        </div>
    </footer>

    <!-- Service Worker Registration for PWA -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js').catch(err => {
                    console.warn('SW registration skipped:', err);
                });
            });
        }

        // Global Alert Helper
        function showNotification(title, text, icon = 'info') {
            Swal.fire({
                title: title,
                text: text,
                icon: icon,
                background: '#0f172a',
                color: '#f8fafc',
                confirmButtonColor: '#2563eb'
            });
        }
    </script>
</body>
</html>
