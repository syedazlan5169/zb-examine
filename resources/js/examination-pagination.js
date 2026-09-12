const MOBILE_PAGE_SIZE = 10;

function setupMobileExaminationPagination() {
    const list = document.querySelector('[data-mobile-examination-list]');
    const rows = list?.querySelectorAll('[data-mobile-examination-row]');
    const pagination = list?.parentElement?.querySelector('[data-mobile-examination-pagination]');
    const previous = pagination?.querySelector('[data-mobile-examination-previous]');
    const next = pagination?.querySelector('[data-mobile-examination-next]');
    const pageLabel = pagination?.querySelector('[data-mobile-examination-page]');

    if (!list || !rows?.length || !pagination || !previous || !next || !pageLabel) {
        return;
    }

    if (!window.matchMedia('(max-width: 1023px)').matches) {
        return;
    }

    const pageSize = Number(list.dataset.mobilePageSize) || MOBILE_PAGE_SIZE;
    const totalPages = Math.ceil(rows.length / pageSize);
    let currentPage = 1;

    const render = () => {
        const firstVisible = (currentPage - 1) * pageSize;
        const lastVisible = firstVisible + pageSize;

        rows.forEach((row, index) => {
            row.hidden = index < firstVisible || index >= lastVisible;
        });

        pagination.hidden = false;
        rows.forEach((row) => row.classList.toggle('hidden', row.hidden));
        pageLabel.textContent = `${currentPage} / ${totalPages}`;
        previous.disabled = currentPage === 1 && !list.dataset.serverPreviousUrl;
        next.disabled = currentPage === totalPages && !list.dataset.serverNextUrl;
    };

    previous.addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage -= 1;
            render();
            return;
        }

        if (list.dataset.serverPreviousUrl) {
            window.location.assign(list.dataset.serverPreviousUrl);
        }
    });

    next.addEventListener('click', () => {
        if (currentPage < totalPages) {
            currentPage += 1;
            render();
            return;
        }

        if (list.dataset.serverNextUrl) {
            window.location.assign(list.dataset.serverNextUrl);
        }
    });

    render();
}

document.addEventListener('DOMContentLoaded', setupMobileExaminationPagination);