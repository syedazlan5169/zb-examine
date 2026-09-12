document.addEventListener('DOMContentLoaded', () => {
    const trigger = document.querySelector('[data-delete-trigger]');
    const modal = document.querySelector('[data-delete-modal]');
    const cancel = document.querySelector('[data-delete-cancel]');
    const confirm = document.querySelector('[data-delete-confirm]');

    if (!trigger || !modal || !cancel || !confirm) {
        return;
    }

    let previouslyFocused = null;

    const close = () => {
        modal.hidden = true;
        document.body.classList.remove('overflow-hidden');
        previouslyFocused?.focus();
    };

    trigger.addEventListener('click', () => {
        previouslyFocused = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('overflow-hidden');
        cancel.focus();
    });
    cancel.addEventListener('click', close);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            close();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            close();
        }
    });
    confirm.closest('form')?.addEventListener('submit', () => {
        confirm.disabled = true;
        confirm.setAttribute('aria-busy', 'true');
    });
});