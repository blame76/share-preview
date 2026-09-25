(() => {
    'use strict';

    const button = document.querySelector('[data-share-url]');
    const toast = document.querySelector('[data-toast]');

    function showToast(message) {
        if (!toast) return;
        toast.textContent = message;
        toast.hidden = false;
        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(() => {
            toast.hidden = true;
        }, 2200);
    }

    async function copyUrl(url) {
        try {
            await navigator.clipboard.writeText(url);
            showToast('Link kopiert.');
        } catch (_) {
            window.prompt('Link kopieren:', url);
        }
    }

    if (button) {
        button.addEventListener('click', async () => {
            const url = button.dataset.shareUrl || window.location.href;
            const payload = {
                title: 'Share Preview',
                text: 'Social-Share-Metadaten einer URL prüfen – ohne Cookies, Tracking oder Speicherung.',
                url,
            };

            if (navigator.share) {
                try {
                    await navigator.share(payload);
                    return;
                } catch (error) {
                    if (error && error.name === 'AbortError') return;
                }
            }

            await copyUrl(url);
        });
    }
})();
