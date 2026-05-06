(function () {
    const menu = document.querySelector('[data-user-menu]');
    if (!menu) {
        return;
    }

    const toggle = menu.querySelector('[data-user-menu-toggle]');
    const dropdown = menu.querySelector('[data-user-menu-dropdown]');
    if (!(toggle instanceof HTMLElement) || !(dropdown instanceof HTMLElement)) {
        return;
    }

    const setOpen = (open) => {
        menu.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    toggle.addEventListener('click', (event) => {
        event.preventDefault();
        const isOpen = menu.classList.contains('open');
        setOpen(!isOpen);
    });

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Node)) {
            return;
        }
        if (!menu.contains(target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });
})();

(function () {
    const pollConfig = document.getElementById('poll-config');
    if (!pollConfig) {
        return;
    }

    const endpoint = pollConfig.getAttribute('data-poll-endpoint');
    if (!endpoint) {
        return;
    }

    const intervalMs = Number(pollConfig.getAttribute('data-poll-interval') || '10000');
    const includeQuery = pollConfig.getAttribute('data-poll-include-query') === '1';
    const updatedLabel = document.getElementById('js-last-updated');

    let isPolling = false;

    const isEditingForm = () => {
        const active = document.activeElement;
        if (!(active instanceof HTMLElement)) {
            return false;
        }
        return Boolean(active.closest('form') && active.matches('input, select, textarea'));
    };

    const buildPollUrl = () => {
        const url = new URL(endpoint, window.location.origin);
        if (includeQuery) {
            const query = new URLSearchParams(window.location.search);
            query.forEach((value, key) => {
                url.searchParams.set(key, value);
            });
        }
        url.searchParams.set('_ts', String(Date.now()));
        return url.toString();
    };

    const applyTargets = (targets) => {
        Object.entries(targets).forEach(([selector, html]) => {
            const el = document.querySelector(selector);
            if (el) {
                el.innerHTML = String(html);
            }
        });
    };

    const runPoll = async () => {
        if (isPolling || document.hidden || isEditingForm()) {
            return;
        }
        isPolling = true;

        try {
            const response = await fetch(buildPollUrl(), {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });

            if (!response.ok) {
                throw new Error('Polling request failed.');
            }

            const payload = await response.json();
            if (payload && payload.targets && typeof payload.targets === 'object') {
                applyTargets(payload.targets);
            }

            if (updatedLabel) {
                const stamp = payload.updated_at || new Date().toLocaleTimeString();
                updatedLabel.textContent = `Last update: ${stamp}`;
            }
        } catch (error) {
            if (updatedLabel) {
                updatedLabel.textContent = 'Last update: reconnecting...';
            }
        } finally {
            isPolling = false;
        }
    };

    setInterval(runPoll, Math.max(intervalMs, 3000));
})();

(function () {
    const dateModeSelect = document.getElementById('date-mode-select');
    const customDateField = document.getElementById('custom-date-field');
    const customDateInput = document.getElementById('custom-date-input');

    if (!dateModeSelect || !customDateField || !customDateInput) {
        return;
    }

    const toggleCustomDateField = () => {
        const isCustom = dateModeSelect.value === 'custom';
        customDateField.style.display = isCustom ? '' : 'none';
        customDateInput.disabled = !isCustom;
        customDateInput.required = isCustom;
    };

    const submitFilterForm = () => {
        const form = dateModeSelect.closest('form');
        if (form instanceof HTMLFormElement) {
            form.submit();
        }
    };

    dateModeSelect.addEventListener('change', () => {
        toggleCustomDateField();
        if (dateModeSelect.value !== 'custom') {
            submitFilterForm();
        }
    });

    customDateInput.addEventListener('change', () => {
        if (dateModeSelect.value === 'custom') {
            submitFilterForm();
        }
    });

    toggleCustomDateField();
})();

(function () {
    const confirmForms = document.querySelectorAll('form[data-confirm]');
    confirmForms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            const message = form.getAttribute('data-confirm') || 'Are you sure?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
})();

(function () {
    const normalizeSafeLocalUrl = (rawUrl) => {
        if (typeof rawUrl !== 'string') {
            return null;
        }

        const trimmed = rawUrl.trim();
        if (!trimmed) {
            return null;
        }

        // Block control characters in case of malformed/injected attributes.
        if (/[\u0000-\u001F\u007F]/.test(trimmed)) {
            return null;
        }

        let parsed;
        try {
            parsed = new URL(trimmed, window.location.origin);
        } catch (_error) {
            return null;
        }

        if (!['http:', 'https:'].includes(parsed.protocol)) {
            return null;
        }

        if (parsed.origin !== window.location.origin) {
            return null;
        }

        if (!parsed.pathname.startsWith('/')) {
            return null;
        }

        return `${parsed.pathname}${parsed.search}${parsed.hash}`;
    };

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        if (target.closest('a, button, input, select, textarea, label')) {
            return;
        }

        const row = target.closest('tr[data-select-url]');
        if (!row) {
            return;
        }

        const safeUrl = normalizeSafeLocalUrl(row.getAttribute('data-select-url'));
        if (safeUrl) {
            window.location.assign(safeUrl);
        }
    });
})();

(function () {
    const form = document.querySelector('[data-customer-search-form]');
    const tbody = document.querySelector('[data-customer-table-body]');
    if (!(form instanceof HTMLFormElement) || !(tbody instanceof HTMLElement)) {
        return;
    }

    const input = form.querySelector('[data-customer-search-input]');
    const resetLink = form.querySelector('[data-customer-search-reset]');
    const endpoint = form.getAttribute('data-search-endpoint');
    if (!(input instanceof HTMLInputElement) || !(endpoint && endpoint.trim() !== '')) {
        return;
    }

    const normalizeSafeLocalUrl = (rawUrl) => {
        if (typeof rawUrl !== 'string') {
            return null;
        }

        const trimmed = rawUrl.trim();
        if (!trimmed || /[\u0000-\u001F\u007F]/.test(trimmed)) {
            return null;
        }

        let parsed;
        try {
            parsed = new URL(trimmed, window.location.origin);
        } catch (_error) {
            return null;
        }

        if (!['http:', 'https:'].includes(parsed.protocol)) {
            return null;
        }

        if (parsed.origin !== window.location.origin || !parsed.pathname.startsWith('/')) {
            return null;
        }

        return `${parsed.pathname}${parsed.search}${parsed.hash}`;
    };

    const buildEndpoint = (query) => {
        const url = new URL(endpoint, window.location.origin);
        if (query !== '') {
            url.searchParams.set('q', query);
        }
        url.searchParams.set('_ts', String(Date.now()));
        return url.toString();
    };

    const createCell = (text) => {
        const td = document.createElement('td');
        td.textContent = String(text ?? '');
        return td;
    };

    const createBadgeCell = (badgeClass, label) => {
        const td = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = `badge ${badgeClass}`;
        badge.textContent = String(label ?? '');
        td.appendChild(badge);
        return td;
    };

    const renderRows = (rows, emptyMessage) => {
        tbody.textContent = '';

        if (!Array.isArray(rows) || rows.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 6;
            td.textContent = String(emptyMessage || 'No customers yet.');
            tr.appendChild(td);
            tbody.appendChild(tr);
            return;
        }

        rows.forEach((row) => {
            const tr = document.createElement('tr');
            tr.className = 'table-row-clickable';

            const safeSelectUrl = normalizeSafeLocalUrl(row.select_url);
            if (safeSelectUrl) {
                tr.dataset.selectUrl = safeSelectUrl;
            }

            tr.appendChild(createCell(row.customer_code));
            tr.appendChild(createCell(row.full_name));
            tr.appendChild(createCell(row.phone));
            tr.appendChild(createCell(row.running_principal));
            tr.appendChild(createBadgeCell(String(row.quality_badge_class || 'badge-neutral'), row.quality_label));
            tr.appendChild(createBadgeCell(String(row.status_badge_class || 'badge-neutral'), row.status_label));
            tbody.appendChild(tr);
        });
    };

    const updateQueryString = (query) => {
        const url = new URL(window.location.href);
        if (query === '') {
            url.searchParams.delete('q');
        } else {
            url.searchParams.set('q', query);
        }
        window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
    };

    let debounceTimer = null;
    let activeController = null;

    const runSearch = async () => {
        const query = input.value.trim();

        if (activeController) {
            activeController.abort();
        }
        activeController = new AbortController();

        try {
            const response = await fetch(buildEndpoint(query), {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
                signal: activeController.signal,
            });

            if (!response.ok) {
                return false;
            }

            const payload = await response.json();
            renderRows(payload.rows, payload.empty_message);
            updateQueryString(query);
            return true;
        } catch (error) {
            if (error && typeof error === 'object' && 'name' in error && error.name === 'AbortError') {
                return false;
            }
            return false;
        }
    };

    const queueSearch = () => {
        if (debounceTimer !== null) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = window.setTimeout(runSearch, 300);
    };

    input.addEventListener('input', queueSearch);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (debounceTimer !== null) {
            clearTimeout(debounceTimer);
        }
        const ok = await runSearch();
        if (!ok) {
            form.submit();
        }
    });

    if (resetLink instanceof HTMLElement) {
        resetLink.addEventListener('click', (event) => {
            event.preventDefault();
            input.value = '';
            runSearch();
            input.focus();
        });
    }
})();

(function () {
    const form = document.getElementById('loan-form');
    if (!form) {
        return;
    }

    const principalInput = form.querySelector('[name="principal_amount"]');
    const interestInput = form.querySelector('[name="interest_rate"]');
    const interestTypeInput = form.querySelector('[name="interest_rate_type"]');
    const interestMonthsInput = form.querySelector('[name="interest_rate_months"]');
    const interestMonthsField = form.querySelector('[data-interest-months-field]');
    const frequencyInput = form.querySelector('[name="installment_frequency"]');
    const timeframeValueInput = form.querySelector('[name="timeframe_value"]');
    const timeframeUnitInput = form.querySelector('[name="timeframe_unit"]');
    const totalEl = document.getElementById('preview-total');
    const installmentEl = document.getElementById('preview-installment');
    const profitEl = document.getElementById('preview-profit');
    const installmentCountEl = document.getElementById('preview-installment-count');

    const toNumber = (value) => {
        const n = Number(value);
        return Number.isFinite(n) ? n : 0;
    };

    const formatMoney = (value) => {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(value);
    };

    const installmentCountFromTimeframe = (frequency, timeframeValue, timeframeUnit) => {
        const safeTimeframe = Math.max(timeframeValue, 1);
        const totalDays = timeframeUnit === 'months' ? safeTimeframe * 30 : safeTimeframe;

        if (frequency === 'weekly') {
            return Math.max(Math.ceil(totalDays / 7), 1);
        }

        if (frequency === 'monthly') {
            if (timeframeUnit === 'months') {
                return safeTimeframe;
            }
            return Math.max(Math.ceil(totalDays / 30), 1);
        }

        return Math.max(totalDays, 1);
    };

    const toggleInterestMonthsField = () => {
        if (!interestTypeInput || !interestMonthsField || !interestMonthsInput) {
            return;
        }

        const isMonthly = interestTypeInput.value === 'monthly';
        interestMonthsField.style.display = isMonthly ? '' : 'none';
        interestMonthsInput.disabled = !isMonthly;
        interestMonthsInput.required = isMonthly;
        if (isMonthly && toNumber(interestMonthsInput.value) < 1) {
            interestMonthsInput.value = '1';
        }
    };

    const updatePreview = () => {
        const principal = toNumber(principalInput.value);
        const interestRate = toNumber(interestInput.value);
        const interestType = interestTypeInput && interestTypeInput.value === 'monthly'
            ? 'monthly'
            : 'amount_based';
        const interestMonths = Math.max(toNumber(interestMonthsInput ? interestMonthsInput.value : 1), 1);
        const timeframeValue = Math.max(toNumber(timeframeValueInput.value), 1);
        const timeframeUnit = timeframeUnitInput.value === 'months' ? 'months' : 'days';
        const frequency = frequencyInput.value;
        const count = installmentCountFromTimeframe(frequency, timeframeValue, timeframeUnit);
        const monthlyFactor = interestType === 'monthly' ? interestMonths : 1;
        const total = principal + ((principal * interestRate / 100) * monthlyFactor);
        const profit = total - principal;
        const installment = total / count;

        if (installmentCountEl) {
            installmentCountEl.textContent = String(count);
        }
        totalEl.textContent = formatMoney(total);
        installmentEl.textContent = formatMoney(installment);
        if (profitEl) {
            profitEl.textContent = formatMoney(profit);
        }
    };

    [principalInput, interestInput, interestTypeInput, frequencyInput, timeframeValueInput, timeframeUnitInput].forEach((el) => {
        if (!el) {
            return;
        }
        el.addEventListener('input', updatePreview);
        el.addEventListener('change', updatePreview);
    });
    if (interestMonthsInput) {
        interestMonthsInput.addEventListener('input', updatePreview);
        interestMonthsInput.addEventListener('change', updatePreview);
    }
    if (interestTypeInput) {
        interestTypeInput.addEventListener('change', toggleInterestMonthsField);
        interestTypeInput.addEventListener('input', toggleInterestMonthsField);
    }

    toggleInterestMonthsField();
    updatePreview();
})();

(function () {
    const form = document.getElementById('legacy-loan-form');
    if (!form) {
        return;
    }

    const principalInput = form.querySelector('[name="principal_amount"]');
    const interestInput = form.querySelector('[name="interest_rate"]');
    const interestTypeInput = form.querySelector('[name="interest_rate_type"]');
    const interestMonthsInput = form.querySelector('[name="interest_rate_months"]');
    const interestMonthsField = form.querySelector('[data-legacy-interest-months-field]');
    const frequencyInput = form.querySelector('[name="installment_frequency"]');
    const timeframeValueInput = form.querySelector('[name="timeframe_value"]');
    const timeframeUnitInput = form.querySelector('[name="timeframe_unit"]');
    const collectedInput = form.querySelector('[name="collected_amount"]');
    const totalEl = document.getElementById('legacy-preview-total');
    const collectedEl = document.getElementById('legacy-preview-collected');
    const remainingEl = document.getElementById('legacy-preview-remaining');
    const installmentEl = document.getElementById('legacy-preview-installment');
    const installmentCountEl = document.getElementById('legacy-preview-installment-count');

    const toNumber = (value) => {
        const n = Number(value);
        return Number.isFinite(n) ? n : 0;
    };

    const formatMoney = (value) => {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(value);
    };

    const installmentCountFromTimeframe = (frequency, timeframeValue, timeframeUnit) => {
        const safeTimeframe = Math.max(timeframeValue, 1);
        const totalDays = timeframeUnit === 'months' ? safeTimeframe * 30 : safeTimeframe;

        if (frequency === 'weekly') {
            return Math.max(Math.ceil(totalDays / 7), 1);
        }

        if (frequency === 'monthly') {
            if (timeframeUnit === 'months') {
                return safeTimeframe;
            }
            return Math.max(Math.ceil(totalDays / 30), 1);
        }

        return Math.max(totalDays, 1);
    };

    const toggleInterestMonthsField = () => {
        if (!interestTypeInput || !interestMonthsField || !interestMonthsInput) {
            return;
        }

        const isMonthly = interestTypeInput.value === 'monthly';
        interestMonthsField.style.display = isMonthly ? '' : 'none';
        interestMonthsInput.disabled = !isMonthly;
        interestMonthsInput.required = isMonthly;
        if (isMonthly && toNumber(interestMonthsInput.value) < 1) {
            interestMonthsInput.value = '1';
        }
    };

    const updatePreview = () => {
        const principal = Math.max(toNumber(principalInput.value), 0);
        const interestRate = Math.max(toNumber(interestInput.value), 0);
        const interestType = interestTypeInput && interestTypeInput.value === 'monthly'
            ? 'monthly'
            : 'amount_based';
        const interestMonths = Math.max(toNumber(interestMonthsInput ? interestMonthsInput.value : 1), 1);
        const timeframeValue = Math.max(toNumber(timeframeValueInput.value), 1);
        const timeframeUnit = timeframeUnitInput.value === 'months' ? 'months' : 'days';
        const frequency = frequencyInput.value;
        const originalCount = installmentCountFromTimeframe(frequency, timeframeValue, timeframeUnit);
        const monthlyFactor = interestType === 'monthly' ? interestMonths : 1;
        const total = principal + ((principal * interestRate / 100) * monthlyFactor);

        const safeCollectedRaw = Math.max(toNumber(collectedInput.value), 0);
        const collected = Math.min(safeCollectedRaw, total);
        const remaining = Math.max(total - collected, 0);

        const originalInstallment = originalCount > 0 ? total / originalCount : 0;
        const remainingCount = remaining > 0
            ? Math.max(Math.ceil(remaining / Math.max(originalInstallment, 0.01)), 1)
            : 0;
        const remainingInstallment = remainingCount > 0 ? remaining / remainingCount : 0;

        totalEl.textContent = formatMoney(total);
        collectedEl.textContent = formatMoney(collected);
        remainingEl.textContent = formatMoney(remaining);
        installmentEl.textContent = formatMoney(remainingInstallment);
        installmentCountEl.textContent = String(remainingCount);
    };

    [principalInput, interestInput, interestTypeInput, frequencyInput, timeframeValueInput, timeframeUnitInput, collectedInput].forEach((el) => {
        if (!el) {
            return;
        }
        el.addEventListener('input', updatePreview);
        el.addEventListener('change', updatePreview);
    });
    if (interestMonthsInput) {
        interestMonthsInput.addEventListener('input', updatePreview);
        interestMonthsInput.addEventListener('change', updatePreview);
    }
    if (interestTypeInput) {
        interestTypeInput.addEventListener('change', toggleInterestMonthsField);
        interestTypeInput.addEventListener('input', toggleInterestMonthsField);
    }

    toggleInterestMonthsField();
    updatePreview();
})();
