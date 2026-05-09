(function () {
    const applyMobileTableStack = () => {
        const tables = document.querySelectorAll('.table-wrap table');
        tables.forEach((table) => {
            if (!(table instanceof HTMLTableElement)) {
                return;
            }

            if (table.matches('.docs-table-compact, .reports-table-compact, [data-no-mobile-stack]')) {
                return;
            }

            const headCells = Array.from(table.querySelectorAll('thead th'));
            if (headCells.length === 0) {
                return;
            }

            const labels = headCells.map((cell) => {
                return (cell.textContent || '').replace(/\s+/g, ' ').trim();
            });

            table.classList.add('mobile-stack-table');

            const rows = table.querySelectorAll('tbody tr');
            rows.forEach((row) => {
                const cells = Array.from(row.children).filter((child) => child.tagName === 'TD');
                cells.forEach((cell, index) => {
                    if (!(cell instanceof HTMLTableCellElement)) {
                        return;
                    }

                    if (cell.colSpan > 1) {
                        cell.setAttribute('data-label', '');
                        return;
                    }

                    const label = labels[index] || '';
                    cell.setAttribute('data-label', label);
                });
            });
        });
    };

    window.applyMobileTableStack = applyMobileTableStack;
    applyMobileTableStack();
})();

(function () {
    const shell = document.querySelector('.app-shell');
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const overlay = document.querySelector('[data-sidebar-overlay]');
    const sidebar = document.getElementById('main-sidebar');

    if (!(shell instanceof HTMLElement) || !(toggle instanceof HTMLElement) || !(overlay instanceof HTMLElement) || !(sidebar instanceof HTMLElement)) {
        return;
    }

    const isMobile = () => window.matchMedia('(max-width: 1024px)').matches;

    const setOpen = (open) => {
        shell.classList.toggle('sidebar-open', open);
        document.body.classList.toggle('sidebar-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    toggle.addEventListener('click', () => {
        if (!isMobile()) {
            return;
        }
        setOpen(!shell.classList.contains('sidebar-open'));
    });

    overlay.addEventListener('click', () => {
        setOpen(false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });

    sidebar.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            if (isMobile()) {
                setOpen(false);
            }
        });
    });

    window.addEventListener('resize', () => {
        if (!isMobile()) {
            setOpen(false);
        }
    });
})();

(function () {
    const chip = document.getElementById('js-connection-chip');
    const text = document.getElementById('js-connection-text');
    if (!(chip instanceof HTMLElement) || !(text instanceof HTMLElement)) {
        return;
    }

    const applyState = () => {
        const online = navigator.onLine;
        chip.classList.toggle('is-online', online);
        chip.classList.toggle('is-offline', !online);
        text.textContent = online ? 'Online' : 'Offline';
    };

    window.addEventListener('online', applyState);
    window.addEventListener('offline', applyState);
    applyState();
})();

(function () {
    const dateInputs = document.querySelectorAll('input[type="date"]');
    dateInputs.forEach((input) => {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        input.addEventListener('click', () => {
            if (input.disabled || input.readOnly) {
                return;
            }
            if (typeof input.showPicker === 'function') {
                try {
                    input.showPicker();
                } catch (_error) {
                    // Ignore browser restrictions; native behavior still applies.
                }
            }
        });
    });
})();

(function () {
    const menus = document.querySelectorAll('[data-user-menu]');
    if (!menus.length) {
        return;
    }

    const menuEntries = Array.from(menus).map((menu) => {
        const toggle = menu.querySelector('[data-user-menu-toggle]');
        const dropdown = menu.querySelector('[data-user-menu-dropdown]');
        if (!(toggle instanceof HTMLElement) || !(dropdown instanceof HTMLElement)) {
            return null;
        }
        return { menu, toggle };
    }).filter(Boolean);

    if (!menuEntries.length) {
        return;
    }

    const setOpen = (entry, open) => {
        entry.menu.classList.toggle('open', open);
        entry.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    const closeAll = () => {
        menuEntries.forEach((entry) => setOpen(entry, false));
    };

    menuEntries.forEach((entry) => {
        entry.toggle.addEventListener('click', (event) => {
            event.preventDefault();
            const isOpen = entry.menu.classList.contains('open');
            closeAll();
            setOpen(entry, !isOpen);
        });
    });

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Node)) {
            return;
        }

        let clickedInsideAnyMenu = false;
        menuEntries.forEach((entry) => {
            if (entry.menu.contains(target)) {
                clickedInsideAnyMenu = true;
            }
        });

        if (!clickedInsideAnyMenu) {
            closeAll();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAll();
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

        if (typeof window.applyMobileTableStack === 'function') {
            window.applyMobileTableStack();
        }
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

        const selectTarget = target.closest('[data-select-url]');
        if (!selectTarget) {
            return;
        }

        const safeUrl = normalizeSafeLocalUrl(selectTarget.getAttribute('data-select-url'));
        if (safeUrl) {
            let finalUrl = safeUrl;

            const isMobileViewport = window.matchMedia('(max-width: 1024px)').matches;
            if (isMobileViewport) {
                const mobileSafeUrl = normalizeSafeLocalUrl(selectTarget.getAttribute('data-mobile-select-url'));
                if (mobileSafeUrl) {
                    finalUrl = mobileSafeUrl;
                }
            }

            // Mobile 2-step flow for today collections:
            // selecting an installment opens the dedicated record step.
            if (isMobileViewport) {
                try {
                    const currentPath = window.location.pathname.toLowerCase();
                    const isTodayCollectionsPage = currentPath.endsWith('/pages/today_collections.php');
                    if (isTodayCollectionsPage) {
                        const parsed = new URL(safeUrl, window.location.origin);
                        if (parsed.pathname.toLowerCase().endsWith('/pages/today_collections.php')) {
                            parsed.searchParams.set('mobile_record', '1');
                            finalUrl = `${parsed.pathname}${parsed.search}${parsed.hash}`;
                        }
                    }
                } catch (_error) {
                    // Fallback to safeUrl when URL parsing fails.
                }
            }

            window.location.assign(finalUrl);
        }
    });
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
