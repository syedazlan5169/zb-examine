export const DRAFT_VERSION = 1;
export const DRAFT_MAX_AGE_MS = 24 * 60 * 60 * 1000;
const DRAFT_PREFIX = 'zb-examine.examination-draft.v1.';

const FIELD_NAMES = [
    'agent_name',
    'agent_phone',
    'agent_code',
    'agent_company_name',
    'agent_station_code',
    'location',
    'form_type',
    'form_type_other',
    'customs_form_numbers',
    'container_status',
    'reason',
    'reason_other',
    'attending_officer_type',
];

export function draftStorageKey(actor = 'guest') {
    return `${DRAFT_PREFIX}${actor}`;
}

export function clearDraft(actor = 'guest') {
    sessionStorage.removeItem(draftStorageKey(actor));
}

function valuesFor(form) {
    const fields = {};

    FIELD_NAMES.forEach((name) => {
        if (name === 'customs_form_numbers') {
            fields[name] = Array.from(form.querySelectorAll('[name="customs_form_numbers[]"]'))
                .map((input) => input.value);
            return;
        }

        const controls = form.elements[name];
        if (!controls) {
            return;
        }

        if (controls instanceof RadioNodeList || (controls.length && controls[0]?.type === 'radio')) {
            fields[name] = controls.value || '';
            return;
        }

        fields[name] = controls.value || '';
    });

    return fields;
}

function setFieldValue(form, name, value) {
    const controls = form.elements[name];
    if (!controls) {
        return;
    }

    if (controls instanceof RadioNodeList || (controls.length && controls[0]?.type === 'radio')) {
        Array.from(controls).forEach((input) => {
            input.checked = input.value === value;
        });
        return;
    }

    controls.value = value ?? '';
}

function restoreCustomsFormNumbers(form, values) {
    const list = document.getElementById('customs-form-numbers-list');
    const firstRow = list?.querySelector('[data-role="customs-form-number-row"]');

    if (!list || !firstRow || !Array.isArray(values) || values.length === 0) {
        return;
    }

    while (list.querySelectorAll('[data-role="customs-form-number-row"]').length < values.length) {
        const row = firstRow.cloneNode(true);
        const removeButton = row.querySelector('[data-action="remove-customs-form-number"]');
        if (removeButton) {
            removeButton.hidden = false;
        }
        list.appendChild(row);
    }

    const rows = Array.from(list.querySelectorAll('[data-role="customs-form-number-row"]'));
    rows.slice(values.length).forEach((row) => row.remove());
    rows.slice(0, values.length).forEach((row, index) => {
        const input = row.querySelector('input');
        if (input) {
            input.value = values[index] ?? '';
        }
    });
}

function readDraft(actor) {
    const raw = sessionStorage.getItem(draftStorageKey(actor));
    if (!raw) {
        return null;
    }

    try {
        const draft = JSON.parse(raw);
        const savedAt = Date.parse(draft.savedAt || '');
        if (draft.version !== DRAFT_VERSION || !Number.isFinite(savedAt) || Date.now() - savedAt > DRAFT_MAX_AGE_MS || !draft.fields || typeof draft.fields !== 'object') {
            clearDraft(actor);
            return null;
        }

        return draft;
    } catch {
        clearDraft(actor);
        return null;
    }
}

function saveDraft(form, actor) {
    sessionStorage.setItem(draftStorageKey(actor), JSON.stringify({
        version: DRAFT_VERSION,
        savedAt: new Date().toISOString(),
        fields: valuesFor(form),
    }));
}

function setupDraft() {
    const form = document.getElementById('examination-form');
    if (!form) {
        return;
    }

    const actor = form.dataset.draftActor || 'guest';
    const status = document.getElementById('examination-draft-status');
    const serverValues = form.dataset.serverValues === 'true';
    const setStatus = (message) => {
        if (status) {
            status.textContent = message;
        }
    };

    const draft = serverValues ? null : readDraft(actor);
    if (draft) {
        const fields = draft.fields;
        FIELD_NAMES.forEach((name) => {
            if (name !== 'customs_form_numbers' && Object.hasOwn(fields, name)) {
                setFieldValue(form, name, fields[name]);
            }
        });
        restoreCustomsFormNumbers(form, fields.customs_form_numbers);
        setStatus(form.dataset.draftRestoredLabel || 'Draft restored.');
    }

    let saveTimer;
    const persist = () => {
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(() => {
            saveDraft(form, actor);
            setStatus(form.dataset.draftSavedLabel || 'Draft saved.');
        }, 250);
    };

    window.addEventListener('pagehide', () => {
        window.clearTimeout(saveTimer);
        saveDraft(form, actor);
    });

    form.addEventListener('input', persist);
    form.addEventListener('change', persist);
    document.getElementById('customs-form-numbers-list')?.addEventListener('click', (event) => {
        if (event.target.closest('[data-action="remove-customs-form-number"]')) {
            window.setTimeout(persist, 0);
        }
    });
    document.getElementById('customs-form-numbers-add')?.addEventListener('click', () => {
        window.setTimeout(persist, 0);
    });

    document.getElementById('examination-draft-clear')?.addEventListener('click', () => {
        clearDraft(actor);
        const defaults = JSON.parse(form.dataset.agentDefaults || '{}');
        FIELD_NAMES.forEach((name) => {
            if (name !== 'customs_form_numbers') {
                setFieldValue(form, name, defaults[name] ?? '');
            }
        });
        restoreCustomsFormNumbers(form, ['']);
        form.querySelectorAll('#form_type_other_wrapper, #reason_other_wrapper').forEach((wrapper) => {
            wrapper.hidden = false;
        });
        form.elements.form_type?.dispatchEvent(new Event('change'));
        form.elements.reason?.dispatchEvent(new Event('change'));
        setStatus(form.dataset.draftClearedLabel || 'Draft cleared.');
    });
}

document.addEventListener('DOMContentLoaded', setupDraft);