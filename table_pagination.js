document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('table[data-table-pagination]').forEach(function (table) {
        const body = table.tBodies[0];
        if (!body) return;

        const rows = Array.from(body.rows).filter(function (row) {
            return !row.querySelector('td[colspan]');
        });
        if (!rows.length) return;

        const controls = document.createElement('div');
        controls.className = 'table-page-controls';
        const summary = document.createElement('span');
        summary.className = 'table-page-summary';
        const sizeLabel = document.createElement('label');
        sizeLabel.className = 'table-page-size-label';
        sizeLabel.textContent = 'Rows';
        const sizeSelect = document.createElement('select');
        sizeSelect.className = 'table-page-size';
        sizeSelect.setAttribute('aria-label', 'Rows per page');
        [10, 25, 50, 100, 'all'].forEach(function (size) {
            const option = document.createElement('option');
            option.value = size;
            option.textContent = size === 'all' ? 'All' : String(size);
            sizeSelect.append(option);
        });
        sizeSelect.value = table.dataset.pageSize || '10';
        sizeLabel.append(sizeSelect);

        const navigation = document.createElement('div');
        navigation.className = 'table-page-navigation';
        const previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'table-page-button';
        previous.textContent = 'Previous';
        const pageLabel = document.createElement('span');
        pageLabel.className = 'table-page-current';
        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'table-page-button';
        next.textContent = 'Next';
        navigation.append(previous, pageLabel, next);
        controls.append(summary, sizeLabel, navigation);

        const responsive = table.closest('.table-responsive') || table;
        responsive.insertAdjacentElement('afterend', controls);
        let currentPage = 1;

        function getPageSize() {
            return sizeSelect.value === 'all' ? rows.length : Math.max(1, parseInt(sizeSelect.value, 10) || 10);
        }

        function renderPage() {
            const pageSize = getPageSize();
            const pageCount = Math.max(1, Math.ceil(rows.length / pageSize));
            currentPage = Math.min(Math.max(currentPage, 1), pageCount);
            const start = (currentPage - 1) * pageSize;
            const end = Math.min(start + pageSize, rows.length);

            rows.forEach(function (row, index) {
                row.hidden = index < start || index >= end;
                const up = row.querySelector('.btn-up');
                const down = row.querySelector('.btn-down');
                if (up) up.disabled = index === 0 || index === start;
                if (down) down.disabled = index === rows.length - 1 || index === end - 1;
            });

            summary.textContent = 'Showing ' + (start + 1) + '-' + end + ' of ' + rows.length + ' entries';
            pageLabel.textContent = currentPage + ' / ' + pageCount;
            previous.disabled = currentPage <= 1;
            next.disabled = currentPage >= pageCount;
        }

        previous.addEventListener('click', function () { currentPage--; renderPage(); });
        next.addEventListener('click', function () { currentPage++; renderPage(); });
        sizeSelect.addEventListener('change', function () { currentPage = 1; renderPage(); });
        new MutationObserver(function () {
            rows.splice(0, rows.length, ...Array.from(body.rows).filter(function (row) {
                return !row.querySelector('td[colspan]');
            }));
            renderPage();
        }).observe(body, { childList: true });
        renderPage();
    });
});