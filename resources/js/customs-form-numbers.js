/**
 * Repeatable customs-form-number rows on the real Examination form: Add
 * clones the last row's current value (fast sequential entry), Remove drops
 * a row, and duplicate checking is client-side UX only — the server/domain
 * normalizer remains authoritative regardless.
 */
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('examination-form');
    const list = document.getElementById('customs-form-numbers-list');
    const addButton = document.getElementById('customs-form-numbers-add');

    if (!form || !list || !addButton) {
        return;
    }

    const duplicateMessage = addButton.dataset.duplicateMessage || 'This customs form number has already been added.';

    function rows() {
        return Array.from(list.querySelectorAll('[data-role="customs-form-number-row"]'));
    }

    function rowInput(row) {
        return row.querySelector('input');
    }

    function rowError(row) {
        return row.querySelector('[data-role="customs-form-number-error"]');
    }

    function clearRowError(row) {
        rowInput(row).classList.remove('border-red-600');
        rowInput(row).removeAttribute('aria-invalid');

        const error = rowError(row);
        if (error) {
            error.hidden = true;
            error.textContent = '';
        }
    }

    function showRowError(row) {
        rowInput(row).classList.add('border-red-600');
        rowInput(row).setAttribute('aria-invalid', 'true');

        const error = rowError(row);
        if (error) {
            error.textContent = duplicateMessage;
            error.hidden = false;
        }
    }

    // Client-side comparison only: trimmed + case-insensitive, never mutates the input value.
    function normalizedValue(row) {
        return rowInput(row).value.trim().toLocaleLowerCase();
    }

    function validateDuplicates() {
        const seen = new Map();
        let hasDuplicate = false;

        rows().forEach((row) => clearRowError(row));

        rows().forEach((row) => {
            const key = normalizedValue(row);

            if (key === '') {
                return;
            }

            const previousRow = seen.get(key);

            if (previousRow) {
                showRowError(previousRow);
                showRowError(row);
                hasDuplicate = true;
                return;
            }

            seen.set(key, row);
        });

        return hasDuplicate;
    }

    addButton.addEventListener('click', () => {
        const existingRows = rows();
        const lastRow = existingRows[existingRows.length - 1];
        const lastInput = rowInput(lastRow);

        const newRow = lastRow.cloneNode(true);
        clearRowError(newRow);

        const newInput = rowInput(newRow);
        newInput.value = lastInput.value;

        const removeButton = newRow.querySelector('[data-action="remove-customs-form-number"]');
        if (removeButton) {
            removeButton.hidden = false;
        }

        list.appendChild(newRow);

        newInput.focus();
        const caretPosition = newInput.value.length;
        newInput.setSelectionRange(caretPosition, caretPosition);
    });

    list.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-action="remove-customs-form-number"]');
        if (!removeButton) {
            return;
        }

        const row = removeButton.closest('[data-role="customs-form-number-row"]');
        row.remove();
        validateDuplicates();
    });

    list.addEventListener('input', (event) => {
        const row = event.target.closest('[data-role="customs-form-number-row"]');
        if (row) {
            clearRowError(row);
        }
    });

    // blur doesn't bubble; capture it on the list container instead.
    list.addEventListener('blur', (event) => {
        if (event.target.matches('input')) {
            validateDuplicates();
        }
    }, true);

    form.addEventListener('submit', (event) => {
        if (!validateDuplicates()) {
            return;
        }

        // preventDefault() alone would not stop the double-submit-protection
        // listener from also running and leaving the submit button disabled.
        event.preventDefault();
        event.stopImmediatePropagation();

        const firstInvalid = list.querySelector('input.border-red-600');
        firstInvalid?.focus();
    });
});
