const bodyLockedClass = 'overflow-hidden';

function setupMobileNavigation() {
    const drawer = document.querySelector('[data-mobile-navigation]');
    const trigger = document.querySelector('[data-mobile-navigation-trigger]');
    const closeButton = document.querySelector('[data-mobile-navigation-close]');
    const backdrop = document.querySelector('[data-mobile-navigation-backdrop]');

    if (!drawer || !trigger || !closeButton || !backdrop) {
        return;
    }

    let previouslyFocusedElement = null;

    const close = (restoreFocus = true) => {
        drawer.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-label', trigger.dataset.openLabel || trigger.getAttribute('aria-label') || '');
        document.body.classList.remove(bodyLockedClass);

        if (restoreFocus) {
            previouslyFocusedElement?.focus();
        }
    };

    const open = () => {
        previouslyFocusedElement = document.activeElement;
        drawer.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        trigger.setAttribute('aria-label', trigger.dataset.closeLabel || trigger.getAttribute('aria-label') || '');
        document.body.classList.add(bodyLockedClass);
        closeButton.focus();
    };

    trigger.addEventListener('click', () => {
        if (drawer.hidden) {
            open();
            return;
        }

        close();
    });

    closeButton.addEventListener('click', () => close());
    backdrop.addEventListener('click', () => close());

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !drawer.hidden) {
            event.preventDefault();
            close();
        }
    });

    drawer.addEventListener('click', (event) => {
        if (event.target.closest('a')) {
            close(false);
        }
    });
}

document.addEventListener('DOMContentLoaded', setupMobileNavigation);