document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-mobile-detail-link]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (!window.matchMedia('(max-width: 1023px)').matches) {
                return;
            }

            event.preventDefault();
            window.location.assign(`${link.href}#examination-details`);
        });
    });
});