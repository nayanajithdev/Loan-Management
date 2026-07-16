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
    const widgets = document.querySelectorAll('[data-searchable-select]');
    widgets.forEach((widget) => {
        const search = widget.querySelector('[data-select-search]');
        const valueInput = widget.querySelector('[data-select-value]');
        const menu = widget.querySelector('[data-select-menu]');
        const empty = widget.querySelector('[data-select-empty]');

        if (!(search instanceof HTMLInputElement) || !(valueInput instanceof HTMLInputElement) || !(menu instanceof HTMLElement)) {
            return;
        }

        const options = Array.from(menu.querySelectorAll('[data-select-option]'))
            .filter((option) => option instanceof HTMLButtonElement)
            .map((option) => ({
                element: option,
                value: option.getAttribute('value') || '',
                label: option.textContent || '',
                search: (option.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase(),
            }));

        const setOpen = (open) => {
            menu.hidden = !open;
            widget.classList.toggle('open', open);
            search.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        const render = () => {
            const query = search.value.trim().toLowerCase();
            let visibleCount = 0;

            options.forEach((option, index) => {
                const isMatch = query === '' ? index < 5 : option.search.includes(query);
                option.element.hidden = !isMatch;
                if (isMatch) {
                    visibleCount += 1;
                }
            });

            const selectedOption = options.find((option) => option.value === valueInput.value);
            if (!selectedOption || search.value.trim() !== selectedOption.label.trim()) {
                valueInput.value = '';
            }

            if (empty instanceof HTMLElement) {
                empty.hidden = visibleCount > 0 || query === '';
            }
        };

        search.addEventListener('focus', () => {
            render();
            setOpen(true);
        });

        search.addEventListener('click', () => {
            render();
            setOpen(true);
        });

        search.addEventListener('input', () => {
            render();
            setOpen(true);
        });

        options.forEach((option) => {
            option.element.addEventListener('click', () => {
                valueInput.value = option.value;
                search.value = option.label.trim();
                render();
                setOpen(false);
            });
        });

        document.addEventListener('click', (event) => {
            const target = event.target;
            if (target instanceof Node && !widget.contains(target)) {
                setOpen(false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
                search.blur();
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
    const collectionStatusSelect = document.getElementById('collection-status-select');

    if (!dateModeSelect && !collectionStatusSelect) {
        return;
    }

    const submitParentForm = (select) => {
        const form = select.closest('form');
        if (form instanceof HTMLFormElement) {
            form.submit();
        }
    };

    if (dateModeSelect) {
        dateModeSelect.addEventListener('change', () => submitParentForm(dateModeSelect));
    }
    if (collectionStatusSelect) {
        collectionStatusSelect.addEventListener('change', () => submitParentForm(collectionStatusSelect));
    }
})();

(function () {
    const confirmForms = document.querySelectorAll('form[data-confirm]');
    confirmForms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.getAttribute('data-confirmed') === '1') {
                return;
            }

            const message = form.getAttribute('data-confirm') || 'Are you sure?';
            if (form.getAttribute('data-inline-confirm') === '1') {
                const submitter = event.submitter instanceof HTMLButtonElement || event.submitter instanceof HTMLInputElement
                    ? event.submitter
                    : form.querySelector('[type="submit"]');
                if (!(submitter instanceof HTMLElement) || submitter.disabled) {
                    event.preventDefault();
                    return;
                }

                if (submitter.getAttribute('data-inline-confirm-submit') === '1') {
                    form.setAttribute('data-confirmed', '1');
                    return;
                }

                event.preventDefault();

                if (form.querySelector('[data-inline-confirm-actions]')) {
                    return;
                }

                const actions = document.createElement('div');
                actions.className = 'inline-confirm-actions';
                actions.setAttribute('data-inline-confirm-actions', '1');
                actions.setAttribute('aria-label', message);

                const confirmButton = document.createElement('button');
                confirmButton.type = 'submit';
                const confirmVariant = form.getAttribute('data-inline-confirm-variant') === 'danger' ? 'btn-danger' : 'btn-success';
                confirmButton.className = `btn ${confirmVariant}`;
                confirmButton.textContent = 'Confirm';
                confirmButton.setAttribute('data-inline-confirm-submit', '1');

                const cancelButton = document.createElement('button');
                cancelButton.type = 'button';
                cancelButton.className = 'btn';
                cancelButton.textContent = 'Cancel';

                actions.append(confirmButton, cancelButton);
                submitter.hidden = true;
                submitter.insertAdjacentElement('afterend', actions);
                confirmButton.focus();

                cancelButton.addEventListener('click', () => {
                    actions.remove();
                    form.removeAttribute('data-confirmed');
                    submitter.hidden = false;
                    submitter.focus();
                }, { once: true });

                return;
            }

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
    const issuedDateInput = form.querySelector('[name="issued_date"]');
    const frequencyInput = form.querySelector('[name="installment_frequency"]');
    const timeframeValueInput = form.querySelector('[name="timeframe_value"]');
    const timeframeUnitInput = form.querySelector('[name="timeframe_unit"]');
    const roundedToggle = form.querySelector('[name="use_rounded_installment"]');
    const roundedAmountInput = form.querySelector('[name="rounded_installment_amount"]');
    const roundedHint = document.getElementById('rounded-installment-hint');
    const totalEl = document.getElementById('preview-total');
    const installmentEl = document.getElementById('preview-installment');
    const profitEl = document.getElementById('preview-profit');
    const installmentCountEl = document.getElementById('preview-installment-count');
    const endDateEl = document.getElementById('preview-end-date');
    const isEditLoanForm = Boolean(form.querySelector('[name="loan_id"]'));
    const repaymentLocked = form.getAttribute('data-repayment-locked') === '1';
    let holidayDates = [];
    try {
        holidayDates = JSON.parse(form.getAttribute('data-holiday-dates') || '[]');
    } catch (_error) {
        holidayDates = [];
    }
    const holidaySet = new Set(Array.isArray(holidayDates) ? holidayDates : []);

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

    const validIsoDate = (value) => /^\d{4}-\d{2}-\d{2}$/.test(String(value || ''));

    const addToIsoDate = (isoDate, amount, unit) => {
        if (!validIsoDate(isoDate)) {
            return '';
        }

        const [year, month, day] = isoDate.split('-').map(Number);
        const date = new Date(Date.UTC(year, month - 1, day));
        if (unit === 'months') {
            date.setUTCMonth(date.getUTCMonth() + amount);
        } else {
            date.setUTCDate(date.getUTCDate() + amount);
        }

        return [
            date.getUTCFullYear(),
            String(date.getUTCMonth() + 1).padStart(2, '0'),
            String(date.getUTCDate()).padStart(2, '0'),
        ].join('-');
    };

    const formatDisplayDate = (isoDate) => {
        if (!validIsoDate(isoDate)) {
            return '-';
        }

        const [year, month, day] = isoDate.split('-').map(Number);
        const date = new Date(Date.UTC(year, month - 1, day));

        return new Intl.DateTimeFormat('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            timeZone: 'UTC',
        }).format(date);
    };

    const nextCollectibleDate = (isoDate) => {
        let candidate = isoDate;
        for (let guard = 0; guard < 366; guard += 1) {
            if (!holidaySet.has(candidate)) {
                return candidate;
            }
            candidate = addToIsoDate(candidate, 1, 'days');
        }

        return isoDate;
    };

    const nextFrequencyDate = (isoDate, frequency) => {
        if (frequency === 'weekly') {
            return addToIsoDate(isoDate, 7, 'days');
        }
        if (frequency === 'monthly') {
            return addToIsoDate(isoDate, 1, 'months');
        }
        return addToIsoDate(isoDate, 1, 'days');
    };

    const calculateEndDate = (installmentCount, frequency) => {
        const firstDueDate = form.getAttribute('data-first-due-date') || '';
        const issuedDate = issuedDateInput ? issuedDateInput.value : '';
        const startDate = issuedDate || form.getAttribute('data-start-date') || '';
        let dueDate = repaymentLocked && validIsoDate(firstDueDate)
            ? firstDueDate
            : addToIsoDate(startDate, 1, 'days');

        if (!validIsoDate(dueDate) || installmentCount <= 0) {
            return '';
        }

        let endDate = nextCollectibleDate(dueDate);
        for (let i = 1; i <= installmentCount; i += 1) {
            endDate = nextCollectibleDate(dueDate);
            dueDate = nextFrequencyDate(endDate, frequency);
        }

        return endDate;
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
        const baseCount = installmentCountFromTimeframe(frequency, timeframeValue, timeframeUnit);
        const monthlyFactor = interestType === 'monthly' ? interestMonths : 1;
        const total = principal + ((principal * interestRate / 100) * monthlyFactor);
        const profit = total - principal;
        const roundedEnabled = Boolean(roundedToggle && roundedToggle.checked);
        const roundedAmount = roundedAmountInput ? toNumber(roundedAmountInput.value) : 0;
        let count = baseCount;
        let installment = count > 0 ? total / count : 0;

        if (roundedEnabled && roundedAmount > 0 && total > 0) {
            count = Math.max(Math.ceil(total / roundedAmount), 1);
            installment = roundedAmount;
        }

        if (installmentCountEl) {
            installmentCountEl.textContent = String(count);
        }
        if (endDateEl) {
            endDateEl.textContent = formatDisplayDate(calculateEndDate(count, frequency));
        }
        totalEl.textContent = formatMoney(total);
        installmentEl.textContent = formatMoney(installment);
        if (roundedHint) {
            if (roundedEnabled && roundedAmount > 0 && total > 0) {
                const lastAmount = total - (roundedAmount * Math.max(count - 1, 0));
                roundedHint.textContent = `Last installment will be ${formatMoney(lastAmount)}.`;
            } else {
                roundedHint.textContent = 'When enabled, the last installment will carry the remaining balance.';
            }
        }
        if (profitEl) {
            profitEl.textContent = formatMoney(profit);
        }
    };

    const syncRoundedInstallment = () => {
        if (!roundedToggle || !roundedAmountInput) {
            return;
        }

        const enabled = roundedToggle.checked;
        roundedAmountInput.disabled = !enabled;
        roundedAmountInput.required = enabled;
        if (!enabled) {
            roundedAmountInput.value = '';
        }
        updatePreview();
    };

    [principalInput, interestInput, interestTypeInput, issuedDateInput, frequencyInput, timeframeValueInput, timeframeUnitInput].forEach((el) => {
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
    if (roundedToggle) {
        roundedToggle.addEventListener('change', syncRoundedInstallment);
    }
    if (roundedAmountInput) {
        roundedAmountInput.addEventListener('input', updatePreview);
        roundedAmountInput.addEventListener('change', updatePreview);
    }

    toggleInterestMonthsField();
    if (roundedToggle) {
        syncRoundedInstallment();
    } else if (!isEditLoanForm) {
        updatePreview();
    }
})();
