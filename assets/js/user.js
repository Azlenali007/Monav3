/**
 * Mona SMM Panel v2 - User Portal Client Utilities
 */

document.addEventListener('DOMContentLoaded', () => {
    // Dynamic price estimation helper for preview only
    window.calculateOrderEstimate = function(rate, quantity) {
        return ((parseFloat(rate) * parseInt(quantity)) / 1000).toFixed(4);
    };
});
