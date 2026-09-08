/**
 * Small, dependency-free enhancements for the Examination submission form:
 * conditional "other" fields and double-submit protection. Correctness of
 * persisted data never depends on this file — the server/DTO layer remains
 * authoritative regardless of whether JS runs.
 */

function setupConditionalField(form, controllingName, triggerValue, wrapperId, fieldName) {
    const control = form.elements[controllingName];
    const wrapper = document.getElementById(wrapperId);
    const field = form.elements[fieldName];

    if (!control || !wrapper || !field) {
        return;
    }

    const sync = () => {
        const isVisible = control.value === triggerValue;
        wrapper.hidden = !isVisible;

        if (!isVisible) {
            field.value = '';
        }
    };

    control.addEventListener('change', sync);

    // Re-sync on load so a Laravel validation redirect (old() restoring the
    // controlling <select>'s value) shows/hides the right field immediately.
    sync();
}

function setupDoubleSubmitProtection(form) {
    const submitButton = document.getElementById('examination-submit');

    if (!submitButton) {
        return;
    }

    form.addEventListener('submit', () => {
        submitButton.disabled = true;
        submitButton.setAttribute('aria-busy', 'true');
        submitButton.textContent = form.dataset.loadingText || submitButton.textContent;
    });
}

function setupCopySubmissionNumber() {
    const button = document.getElementById('copy-submission-number');

    if (!button) {
        return;
    }

    const target = document.getElementById(button.dataset.copyTarget);

    if (!target) {
        return;
    }

    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(target.textContent.trim());
            button.textContent = button.dataset.copiedLabel;
            setTimeout(() => {
                button.textContent = button.dataset.defaultLabel;
            }, 2000);
        } catch {
            // Clipboard API unavailable/denied — the number remains visibly readable on screen.
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('examination-form');

    if (form) {
        setupConditionalField(form, 'form_type', 'other', 'form_type_other_wrapper', 'form_type_other');
        setupConditionalField(form, 'reason', 'other', 'reason_other_wrapper', 'reason_other');
        setupDoubleSubmitProtection(form);
    }

    setupCopySubmissionNumber();
});
