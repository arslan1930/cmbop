/* Publisher My Sites — guided bulk URL+price paste/CSV (expects PublisherWebsitesConfig.maxBulkRows) */
(function () {
    const body = document.getElementById('bulkUrlPriceBody');
    const addBtn = document.getElementById('bulkAddRowBtn');
    const pasteArea = document.getElementById('bulkPasteUrls');
    const pasteBtn = document.getElementById('bulkPasteUrlsBtn');
    const pasteError = document.getElementById('bulkPasteUrlsError');
    const pasteSuccess = document.getElementById('bulkPasteUrlsSuccess');
    const pasteWarning = document.getElementById('bulkPasteUrlsWarning');
    const sheetFile = document.getElementById('bulkSheetFile');
    const sheetFileName = document.getElementById('bulkSheetFileName');
    const templateBtn = document.getElementById('bulkSheetTemplateBtn');
    if (!body || !addBtn) return;

    const MAX_ROWS = (window.PublisherWebsitesConfig && Number(window.PublisherWebsitesConfig.maxBulkRows)) || 200;

    function reindexRows() {
        Array.from(body.querySelectorAll('.bulk-url-price-row')).forEach(function (tr, i) {
            // Match on input type as well as name: rows added by the CSV import
            // or the Add row button start life without a name, and a selector
            // that only found already-named inputs left every row past the two
            // Blade renders unnamed — so the browser never submitted them and a
            // 50-row import silently saved 2.
            const url = tr.querySelector('input[name*="[url]"]') || tr.querySelector('input[type="url"]');
            const price = tr.querySelector('input[name*="[price]"]') || tr.querySelector('input[type="number"]');
            if (url) {
                url.name = 'sites[' + i + '][url]';
                url.id = 'bulk-url-' + i;
            }
            if (price) {
                price.name = 'sites[' + i + '][price]';
                price.id = 'bulk-price-' + i;
            }
            const fields = tr.querySelectorAll('.bulk-url-price-field');
            const urlLabel = fields[0] && fields[0].querySelector('label');
            const priceLabel = fields[1] && fields[1].querySelector('label');
            if (urlLabel) urlLabel.setAttribute('for', 'bulk-url-' + i);
            if (priceLabel) priceLabel.setAttribute('for', 'bulk-price-' + i);
        });
    }

    function syncRemoveButtons() {
        const rows = body.querySelectorAll('.bulk-url-price-row');
        rows.forEach(function (tr) {
            const btn = tr.querySelector('.bulk-remove-row');
            if (btn) btn.disabled = rows.length <= 2;
        });
    }

    function syncRowSummary(row) {
        const urlInput = rowUrlInput(row);
        const priceInput = rowPriceInput(row);
        const urlVal = String((urlInput && urlInput.value) || '').trim();
        const priceVal = String((priceInput && priceInput.value) || '').trim();
        const label = row.querySelector('[data-bulk-url-label]');
        const priceLabel = row.querySelector('[data-bulk-price-label]');
        const chip = row.querySelector('[data-bulk-url-price-chip]');
        if (label) label.textContent = urlVal || 'Website URL';
        const priceSymbol = window.__slbListingPriceSymbol || '€';
        if (priceLabel) priceLabel.textContent = priceVal !== '' ? (priceSymbol + priceVal) : 'No price';
        if (chip) {
            chip.classList.remove('is-empty', 'is-partial', 'is-ready');
            if (!urlVal && priceVal === '') {
                chip.classList.add('is-empty');
                chip.textContent = 'Empty';
            } else if (urlVal && priceVal !== '') {
                chip.classList.add('is-ready');
                chip.textContent = 'Ready';
            } else {
                chip.classList.add('is-partial');
                chip.textContent = '1/2 filled';
            }
        }
    }

    function createRow(urlValue, priceValue) {
        const tr = document.createElement('details');
        tr.className = 'bulk-url-price-row';
        tr.open = true;
        // Named here as well as in reindexRows so a row is submittable the
        // moment it exists, whatever order the callers run in.
        const seq = body.querySelectorAll('.bulk-url-price-row').length;
        tr.innerHTML =
            '<summary class="bulk-url-price-row__summary">' +
                '<span class="bulk-url-price-row__identity"><span class="fw-semibold text-break" data-bulk-url-label>Website URL</span></span>' +
                '<span class="bulk-url-price-row__meta">' +
                    '<span class="text-nowrap" data-bulk-price-label>No price</span>' +
                    '<span class="bulk-url-price-row__chip is-empty" data-bulk-url-price-chip>Empty</span>' +
                '</span>' +
            '</summary>' +
            '<div class="bulk-url-price-row__body">' +
                '<div class="bulk-url-price-row__fields">' +
                    '<div class="bulk-url-price-field">' +
                        '<label class="form-label">Website URL <span class="text-danger">*</span></label>' +
                        '<input type="url" name="sites[' + seq + '][url]" class="form-control" placeholder="https://example.com">' +
                    '</div>' +
                    '<div class="bulk-url-price-field bulk-url-price-field--price">' +
                        '<label class="form-label">Price (' + (window.__slbListingPriceSymbol || '€') + ') <span class="text-danger">*</span></label>' +
                        '<input type="number" name="sites[' + seq + '][price]" step="0.01" min="0" class="form-control" placeholder="99">' +
                    '</div>' +
                '</div>' +
                '<button type="button" class="btn btn-sm btn-outline-danger bulk-remove-row" title="Remove row" aria-label="Remove row">Remove</button>' +
            '</div>';
        const urlInput = tr.querySelector('input[type="url"]');
        const priceInput = tr.querySelector('input[type="number"]');
        if (urlInput && urlValue) urlInput.value = urlValue;
        if (priceInput && priceValue !== undefined && priceValue !== null && priceValue !== '') {
            priceInput.value = priceValue;
        }
        syncRowSummary(tr);
        return tr;
    }

    function ensureRowCount(n) {
        n = Math.max(2, Math.min(MAX_ROWS, n));
        let rows = body.querySelectorAll('.bulk-url-price-row');
        while (rows.length < n) {
            body.appendChild(createRow('', ''));
            rows = body.querySelectorAll('.bulk-url-price-row');
        }
        while (rows.length > n) {
            rows[rows.length - 1].remove();
            rows = body.querySelectorAll('.bulk-url-price-row');
        }
        reindexRows();
        syncRemoveButtons();
    }

    function listingPriceSymbol() {
        return window.__slbListingPriceSymbol || '€';
    }

    function clearImportMessages() {
        if (pasteError) {
            pasteError.classList.add('d-none');
            pasteError.textContent = '';
        }
        if (pasteSuccess) {
            pasteSuccess.classList.add('d-none');
            pasteSuccess.textContent = '';
        }
        if (pasteWarning) {
            pasteWarning.classList.add('d-none');
            pasteWarning.textContent = '';
        }
    }

    function showImportError(msg) {
        if (pasteSuccess) {
            pasteSuccess.classList.add('d-none');
            pasteSuccess.textContent = '';
        }
        if (pasteError) {
            pasteError.textContent = msg;
            pasteError.classList.remove('d-none');
        }
    }

    function showImportSuccess(msg) {
        if (pasteError) {
            pasteError.classList.add('d-none');
            pasteError.textContent = '';
        }
        if (pasteSuccess) {
            pasteSuccess.textContent = msg;
            pasteSuccess.classList.remove('d-none');
        }
    }

    function showImportWarning(msg) {
        if (!pasteWarning) return;
        pasteWarning.textContent = msg || '';
        pasteWarning.classList.toggle('d-none', !msg);
    }

    function stripCell(token) {
        return String(token ?? '').trim().replace(/^["']|["']$/g, '').trim();
    }

    /** Pure number / price-like token (never treat as a website). */
    function isNumericToken(token) {
        const raw = stripCell(token);
        if (!raw) return false;
        return /^€?\s*\d{1,3}([.,]\d{3})*([.,]\d{1,2})?\s*€?$/.test(raw)
            || /^€?\s*\d+([.,]\d{1,2})?\s*€?$/.test(raw);
    }

    function normalizeUrl(token) {
        let u = stripCell(token);
        if (!u) return null;
        // Prices like "99" / "150.5" become https://0.0.0.99 via URL() — reject those.
        if (isNumericToken(u)) return null;
        if (/\s/.test(u)) return null;
        if (!/^https?:\/\//i.test(u)) {
            u = 'https://' + u;
        }
        try {
            const parsed = new URL(u);
            const host = (parsed.hostname || '').toLowerCase().replace(/^www\./, '');
            if (!host) return null;
            // Require a real domain/host with a letter (blocks IPv4 from bare numbers).
            if (!/[a-z]/i.test(host)) return null;
            if (host.indexOf('.') === -1 && host !== 'localhost') return null;
            if ((parsed.pathname === '/' || parsed.pathname === '') && !parsed.search && !parsed.hash) {
                return parsed.protocol + '//' + parsed.host;
            }
            return parsed.href;
        } catch (e) {
            return null;
        }
    }

    function parsePriceToken(token) {
        if (token === undefined || token === null) return null;
        let raw = stripCell(token);
        if (!raw) return null;
        // Never parse a URL-looking token as a price.
        if (/^https?:\/\//i.test(raw) || /[a-z]/i.test(raw.replace(/€/g, ''))) {
            // allow only digits, separators, euro — if letters remain after stripping euro, not a price
            const withoutEuro = raw.replace(/€/gi, '').trim();
            if (/[a-z]/i.test(withoutEuro)) return null;
        }
        raw = raw.replace(/€/g, '').replace(/\s/g, '');
        // European 1.234,56 or plain 1234,56
        if (/^\d{1,3}(\.\d{3})+,\d{1,2}$/.test(raw) || /^\d+,\d{1,2}$/.test(raw)) {
            raw = raw.replace(/\./g, '').replace(',', '.');
        } else {
            raw = raw.replace(/,/g, '');
        }
        if (!/^\d+(\.\d{1,2})?$/.test(raw)) return null;
        const n = Number(raw);
        if (!isFinite(n) || n < 0) return null;
        return Math.round(n * 100) / 100;
    }

    function splitCsvLine(line) {
        const out = [];
        let cur = '';
        let inQuotes = false;
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (ch === '"') {
                if (inQuotes && line[i + 1] === '"') {
                    cur += '"';
                    i++;
                } else {
                    inQuotes = !inQuotes;
                }
                continue;
            }
            if ((ch === ',' || ch === ';' || ch === '\t') && !inQuotes) {
                out.push(cur.trim());
                cur = '';
                continue;
            }
            cur += ch;
        }
        out.push(cur.trim());
        return out;
    }

    /** Split a single line into cells (CSV/TSV) or "url price" / "url €99". */
    function lineToCells(line) {
        const trimmed = String(line || '').trim();
        if (!trimmed) return [];
        if (/[,\t;]/.test(trimmed)) {
            return splitCsvLine(trimmed).map(stripCell).filter(Boolean);
        }
        // Space-separated: https://a.com 99   or   a.com €150
        const spaceParts = trimmed.split(/\s+/).filter(Boolean);
        if (spaceParts.length >= 2) {
            const last = spaceParts[spaceParts.length - 1];
            const head = spaceParts.slice(0, -1).join(' ');
            if (normalizeUrl(head) && parsePriceToken(last) !== null) {
                return [head, last];
            }
            if (parsePriceToken(spaceParts[0]) !== null && normalizeUrl(spaceParts.slice(1).join(' '))) {
                return [spaceParts[0], spaceParts.slice(1).join(' ')];
            }
        }
        return [trimmed];
    }

    function looksLikeHeader(cells) {
        const joined = cells.join(' ').toLowerCase();
        if ((/(url|website|domain|site)/.test(joined) && /(price|€|eur|cost)/.test(joined))) {
            return true;
        }
        return joined === 'url' || joined === 'website url' || joined === 'price' || joined === 'website';
    }

    function looksLikePrice(token) {
        return parsePriceToken(token) !== null;
    }

    /**
     * Parse paste/CSV into [{url, price|null}].
     * Prefers url+price pairs; falls back to URL-only tokens.
     */
    function parseUrlPriceImport(text) {
        const raw = String(text || '').replace(/^\uFEFF/, '').trim();
        if (!raw) {
            return { rows: [], mode: 'empty', truncated: false, skipped: [] };
        }

        const lines = raw.split(/\r\n|\n|\r/).map(function (l) { return l.trim(); }).filter(Boolean);
        const pairRows = [];
        const seen = {};
        const skipped = [];
        let truncated = false;

        function skip(line, reason) {
            skipped.push({ line: String(line || '').slice(0, 120), reason: reason });
        }

        function addPair(urlRaw, priceRaw, line) {
            const url = normalizeUrl(urlRaw);
            if (!url) {
                skip(line || urlRaw, 'Not a valid website URL');
                return false;
            }
            let host = '';
            try { host = new URL(url).hostname.toLowerCase().replace(/^www\./, ''); } catch (e) {
                skip(line || urlRaw, 'Not a valid website URL');
                return false;
            }
            if (!host) {
                skip(line || urlRaw, 'Not a valid website URL');
                return false;
            }
            if (seen[host]) {
                skip(line || urlRaw, 'Duplicate domain');
                return false;
            }
            seen[host] = true;
            if (pairRows.length >= MAX_ROWS) {
                truncated = true;
                skip(line || urlRaw, 'Over the ' + MAX_ROWS + ' row limit');
                return false;
            }
            const price = priceRaw === undefined || priceRaw === null || priceRaw === ''
                ? null
                : parsePriceToken(priceRaw);
            pairRows.push({ url: url, price: price });
            return true;
        }

        let started = false;
        lines.forEach(function (line) {
            const nonEmpty = lineToCells(line);
            if (!nonEmpty.length) return;
            if (!started && looksLikeHeader(nonEmpty)) {
                started = true;
                skip(line, 'Header row');
                return;
            }
            started = true;

            if (nonEmpty.length >= 2) {
                const a = nonEmpty[0];
                const b = nonEmpty[1];
                if (normalizeUrl(a) && looksLikePrice(b)) {
                    addPair(a, b, line);
                    return;
                }
                if (looksLikePrice(a) && normalizeUrl(b)) {
                    addPair(b, a, line);
                    return;
                }
                if (normalizeUrl(a) && parsePriceToken(b) !== null) {
                    addPair(a, b, line);
                    return;
                }
                if (looksLikePrice(a) && !normalizeUrl(b)) {
                    skip(line, 'Price without a URL');
                    return;
                }
            }

            if (nonEmpty.length === 1 && normalizeUrl(nonEmpty[0])) {
                addPair(nonEmpty[0], null, line);
                return;
            }
            if (nonEmpty.length === 1 && looksLikePrice(nonEmpty[0])) {
                skip(line, 'Price without a URL');
                return;
            }
            skip(line, 'Could not read a website URL on this line');
        });

        const withPrice = pairRows.filter(function (r) { return r.price !== null; }).length;
        if (pairRows.length >= 2) {
            return {
                rows: pairRows,
                mode: withPrice > 0 ? 'pairs' : 'urls',
                truncated: truncated,
                skipped: skipped,
            };
        }

        // Fallback: URL-only token soup (legacy paste of many URLs on one line)
        const tokens = raw.split(/[\s,;]+/).map(function (t) { return t.trim(); }).filter(Boolean);
        const urls = [];
        const seenUrl = {};
        tokens.forEach(function (token) {
            if (isNumericToken(token) || looksLikePrice(token)) return;
            const url = normalizeUrl(token);
            if (!url) return;
            let host = '';
            try { host = new URL(url).hostname.toLowerCase().replace(/^www\./, ''); } catch (e) { return; }
            if (!host || seenUrl[host]) return;
            seenUrl[host] = true;
            if (urls.length >= MAX_ROWS) {
                truncated = true;
                return;
            }
            urls.push({ url: url, price: null });
        });
        return { rows: urls, mode: 'urls', truncated: truncated, skipped: skipped };
    }

    function rowUrlInput(tr) {
        return tr.querySelector('input[name*="[url]"]') || tr.querySelector('input[type="url"]');
    }

    function rowPriceInput(tr) {
        return tr.querySelector('input[name*="[price]"]') || tr.querySelector('input[type="number"]');
    }

    function applyImportRows(rows, mode, truncated, skipped) {
        if (!rows || rows.length < 2) {
            showImportError('Need at least 2 valid website URLs. Use one per line, or url,price (CSV / Excel paste).');
            if (skipped && skipped.length) {
                const reasons = skipped.slice(0, 6).map(function (item) {
                    return (item.reason || 'Skipped') + (item.line ? ' (“' + item.line + '”)' : '');
                }).join(' · ');
                showImportWarning('Skipped ' + skipped.length + ' line' + (skipped.length === 1 ? '' : 's') + ': ' + reasons);
            }
            return false;
        }

        ensureRowCount(rows.length);
        const trs = body.querySelectorAll('.bulk-url-price-row');
        rows.forEach(function (row, i) {
            const urlInput = rowUrlInput(trs[i]);
            const priceInput = rowPriceInput(trs[i]);
            if (urlInput) urlInput.value = row.url || '';
            if (priceInput) {
                priceInput.value = (row.price !== null && row.price !== undefined) ? String(row.price) : '';
            }
        });
        reindexRows();
        syncRemoveButtons();
        refreshOpenStateAfterImport();

        const priced = rows.filter(function (r) { return r.price !== null && r.price !== undefined; }).length;
        const symbol = listingPriceSymbol();
        let msg = 'Loaded ' + rows.length + ' site' + (rows.length === 1 ? '' : 's');
        if (priced > 0) {
            msg += ' (' + priced + ' with ' + symbol + ' price)';
        } else {
            msg += ' — fill ' + symbol + ' prices in the rows before submit';
        }
        if (truncated) {
            msg += '. Maximum ' + MAX_ROWS + ' rows; extras were skipped.';
        }
        showImportSuccess(msg + '.');
        if (skipped && skipped.length) {
            const reasons = skipped.slice(0, 8).map(function (item) {
                return (item.reason || 'Skipped') + (item.line ? ' (“' + item.line + '”)' : '');
            }).join(' · ');
            showImportWarning('Skipped ' + skipped.length + ' line' + (skipped.length === 1 ? '' : 's') + ': ' + reasons);
        }
        return true;
    }

    function importFromText(text) {
        clearImportMessages();
        const parsed = parseUrlPriceImport(text);
        return applyImportRows(parsed.rows, parsed.mode, parsed.truncated, parsed.skipped || []);
    }

    function refreshOpenStateAfterImport() {
        let openedEmpty = false;
        body.querySelectorAll('.bulk-url-price-row').forEach(function (row) {
            const urlVal = String((rowUrlInput(row) && rowUrlInput(row).value) || '').trim();
            const priceVal = String((rowPriceInput(row) && rowPriceInput(row).value) || '').trim();
            const ready = urlVal !== '' && priceVal !== '';
            const empty = urlVal === '' && priceVal === '';
            if (ready) {
                row.open = false;
            } else if (empty) {
                row.open = !openedEmpty;
                openedEmpty = true;
            } else {
                row.open = true;
            }
            syncRowSummary(row);
        });
    }

    function focusFirstInvalidBulkRow() {
        const invalid = body.querySelector('.is-invalid');
        if (!invalid) return;
        const row = invalid.closest('.bulk-url-price-row');
        if (row) row.open = true;
        if (typeof invalid.focus === 'function') invalid.focus();
    }

    function showRowError(msg) {
        const el = document.getElementById('bulkUrlPriceError');
        if (!el) return;
        el.textContent = msg || '';
        el.classList.toggle('d-none', !msg);
    }

    function classifyUrlPriceRow(row) {
        const url = String((rowUrlInput(row) && rowUrlInput(row).value) || '').trim();
        const price = String((rowPriceInput(row) && rowPriceInput(row).value) || '').trim();
        if (!url && !price) return 'empty';
        if (url && price) return 'complete';
        return 'partial';
    }

    function firstMissingUrlPriceField(row) {
        const url = rowUrlInput(row);
        if (url && !String(url.value || '').trim()) return url;
        return rowPriceInput(row);
    }

    const form = body.closest('form');
    if (form) {
        form.addEventListener('submit', function (e) {
            const complete = [];
            const partial = [];
            body.querySelectorAll('.bulk-url-price-row').forEach(function (row) {
                const fill = classifyUrlPriceRow(row);
                if (fill === 'complete') complete.push(row);
                if (fill === 'partial') partial.push(row);
            });
            if (partial.length === 0 && complete.length >= 2) {
                showRowError('');
                return;
            }
            e.preventDefault();
            const target = partial[0] || body.querySelector('.bulk-url-price-row');
            if (target) {
                target.open = true;
                const field = firstMissingUrlPriceField(target);
                if (field && typeof field.focus === 'function') field.focus();
            }
            showRowError(partial.length
                ? 'Finish or clear incomplete rows. Each started site needs a URL and a price.'
                : 'Add at least two websites with URL and price.');
        });
    }

    addBtn.addEventListener('click', function () {
        if (body.querySelectorAll('.bulk-url-price-row').length >= MAX_ROWS) return;
        const row = createRow('', '');
        body.appendChild(row);
        reindexRows();
        syncRemoveButtons();
        if (typeof row.scrollIntoView === 'function') {
            row.scrollIntoView({ block: 'nearest' });
        }
    });

    body.addEventListener('click', function (e) {
        const btn = e.target.closest('.bulk-remove-row');
        if (!btn) return;
        const rows = body.querySelectorAll('.bulk-url-price-row');
        if (rows.length <= 2) return;
        btn.closest('.bulk-url-price-row')?.remove();
        reindexRows();
        syncRemoveButtons();
    });

    body.addEventListener('input', function (e) {
        const row = e.target.closest('.bulk-url-price-row');
        if (row) syncRowSummary(row);
    });

    const modal = document.getElementById('bulkRequestModal');
    if (modal) {
        modal.addEventListener('shown.bs.modal', focusFirstInvalidBulkRow);
    }

    if (pasteBtn && pasteArea) {
        pasteBtn.addEventListener('click', function () {
            importFromText(pasteArea.value);
        });
    }

    if (sheetFile) {
        sheetFile.addEventListener('change', function () {
            clearImportMessages();
            const file = sheetFile.files && sheetFile.files[0];
            if (!file) return;
            const name = file.name || 'sheet';
            if (sheetFileName) sheetFileName.textContent = name;

            const lower = name.toLowerCase();
            if (/\.(xlsx|xls|ods)$/.test(lower)) {
                showImportError('Please save the sheet as CSV (File → Save As → CSV) or copy the URL + Price columns and paste them below.');
                sheetFile.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function () {
                const text = String(reader.result || '');
                importFromText(text);
                sheetFile.value = '';
            };
            reader.onerror = function () {
                showImportError('Could not read that file. Try CSV or paste the columns instead.');
                sheetFile.value = '';
            };
            reader.readAsText(file);
        });
    }

    if (templateBtn) {
        templateBtn.addEventListener('click', function (e) {
            e.preventDefault();
            const csv = 'Website URL,Price\nhttps://site-one.example,99\nhttps://site-two.example,150\n';
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'bulk-sites-url-price-sample.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        });
    }

    // Expose for tests / console debugging
    window.__bulkParseUrlPriceImport = parseUrlPriceImport;

    reindexRows();
    syncRemoveButtons();
    body.querySelectorAll('.bulk-url-price-row').forEach(syncRowSummary);
    focusFirstInvalidBulkRow();
})();
