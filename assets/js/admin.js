/**
 * Mona SMM Panel v2 - Admin Panel Client Utilities
 */

document.addEventListener('DOMContentLoaded', () => {
    // Confirm Action Dialog
    window.confirmAdminAction = function(message, form) {
        Swal.fire({
            title: 'Are you sure?',
            text: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#2563eb',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, proceed',
            background: '#0f172a',
            color: '#f8fafc'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
        return false;
    };
});
