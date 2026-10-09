/**
 * Mona SMM Panel v2 - Global Application JS Utilities
 */

document.addEventListener('DOMContentLoaded', () => {
    // Clipboard Helper
    window.copyText = function(text) {
        navigator.clipboard.writeText(text).then(() => {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'Copied to clipboard',
                showConfirmButton: false,
                timer: 2000,
                background: '#0f172a',
                color: '#f8fafc'
            });
        });
    };
});
