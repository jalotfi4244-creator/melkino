// مدیریت درخواست‌ها
// ==============================================

function requestEsc(value) {
    return escapeHtml(value);
}


function normalizeNumberText(value) {
    if (value === null || value === undefined) return '';
    let text = String(value).trim();
    if (!text) return '';
    text = text.replace(/[٬،,\s]/g, '');
    text = text.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
    const n = Number(text);
    return Number.isFinite(n) ? n.toLocaleString('en-US') : String(value);
}

function requestPriceText(r) {
    const tx = String(r.transaction_type || '').trim();
    const isRent = tx.includes('اجاره') || tx.includes('رهن');
    if (isRent) {
        const deposit = r.min_deposit || r.deposit || r.down_payment || r.max_deposit || '';
        const rent = r.min_rent || r.rent_monthly || r.max_rent || '';
        if (deposit || rent) {
            return [deposit ? 'ودیعه: ' + normalizeNumberText(deposit) + ' تومان' : '', rent ? 'اجاره: ' + normalizeNumberText(rent) + ' تومان' : ''].filter(Boolean).join(' | ');
        }
    }
    const min = r.min_price || r.price_sell || r.total_price || '';
    const max = r.max_price || '';
    if (min && max && String(min) !== String(max)) return normalizeNumberText(min) + ' تا ' + normalizeNumberText(max) + ' تومان';
    if (min) return normalizeNumberText(min) + ' تومان';
    return 'تعیین نشده';
}

function getMatchedAdData(match) {
    const matchId = String(match?.id ?? match?.ad_id ?? match?.property_id ?? '').trim();
    const found = adsData.find(ad => String(ad?.id ?? ad?.ad_id ?? '').trim() === matchId);
    return found ? { ...found, ...match } : (match || {});
}

function matchDisplayPrice(ad) {
    const tx = String(ad.transaction_type || '').trim();
    const isRent = tx.includes('اجاره') || tx.includes('رهن');
    if (isRent) {
        const deposit = ad.deposit || ad.down_payment || '';
        const rent = ad.rent_monthly || '';
        const parts = [];
        if (deposit && String(deposit) !== '0') parts.push('ودیعه: ' + normalizeNumberText(deposit) + ' تومان');
        if (rent && String(rent) !== '0') parts.push('اجاره: ' + normalizeNumberText(rent) + ' تومان');
        if (parts.length) return parts.join(' | ');
    }
    const value = ad.display_price || ad.price_sell || ad.total_price || ad.price || '';
    if (!value) return 'تماس بگیرید';
    return String(value).includes('تومان') ? String(value) : normalizeNumberText(value) + ' تومان';
}

function matchArea(ad) {
    const d = ad.property_details && typeof ad.property_details === 'object' ? ad.property_details : {};
    const value = ad.area ?? ad.land_area ?? ad.built_area ?? ad.office_area ?? d.area ?? d.land_area ?? d.built_area ?? d.office_area ?? '';
    return value !== '' && value !== null && value !== undefined ? normalizeNumberText(value) : '—';
}

function matchRooms(ad) {
    const d = ad.property_details && typeof ad.property_details === 'object' ? ad.property_details : {};
    return ad.rooms || ad.bedrooms || d.rooms || d.bedrooms || '—';
}

function matchAmenities(ad) {
    const list = Array.isArray(ad.amenities) ? ad.amenities : [];
    return list.length ? list.join('، ') : '—';
}



function renderRequests() {

    const container =
        document.getElementById(
            'requestsListContainer'
        );


    const countEl =
        document.getElementById(
            'requestsResultCount'
        );


    if (!container) {
        return;
    }


    const toolbar = `

        <div class="requests-toolbar">

            <input
                id="requestsSearch"
                type="search"
                placeholder="جستجو کد رهگیری، نام، تلفن، محدوده..."
                autocomplete="off"
            >

            <select
                id="requestsMatchFilter"
            >

                <option value="all">
                    همه درخواست‌ها
                </option>

                <option value="matched">
                    دارای فایل مطابق
                </option>

                <option value="unmatched">
                    بدون فایل مطابق
                </option>

            </select>

            <select
                id="requestsStatusFilter"
            >

                <option value="all">
                    همه وضعیت‌ها
                </option>

                <option value="new">
                    جدید
                </option>

                <option value="tracking">
                    در حال پیگیری
                </option>

                <option value="archived">
                    بایگانی
                </option>

                <option value="closed">
                    بسته شده
                </option>

            </select>

        </div>
    `;


    const paint = () => {

        const q =
            (
                document.getElementById(
                    'requestsSearch'
                )?.value ||
                ''
            )
                .trim()
                .toLowerCase();


        const filter =
            document.getElementById(
                'requestsMatchFilter'
            )?.value ||
            'all';

        const statusFilter =
            document.getElementById(
                'requestsStatusFilter'
            )?.value ||
            'all';


        const list =
            requestsData.filter(
                r => {

                    const hay = [

                        r.tracking_code,
                        r.last_name,
                        r.phone,
                        r.location,
                        r.property_type,
                        r.transaction_type,
                        r.telegram_id

                    ]
                        .join(' ')
                        .toLowerCase();


                    const matches =
                        Array.isArray(
                            r.matches
                        )
                            ? r.matches
                            : [];


                    if (
                        filter ===
                        'matched' &&
                        matches.length === 0
                    ) {
                        return false;
                    }


                    if (
                        filter ===
                        'unmatched' &&
                        matches.length > 0
                    ) {
                        return false;
                    }

                    if (
                        statusFilter !== 'all' &&
                        (r.status || 'new') !== statusFilter
                    ) {
                        return false;
                    }

                    return (
                        !q ||
                        hay.includes(q)
                    );
                }
            );


        if (countEl) {

            countEl.innerText =
                list.length +
                ' درخواست';
        }


        const cardsEl = document.getElementById('requestsCards');
        if (!cardsEl) return;

        if (!list.length) {
            cardsEl.innerHTML = `
                <div class="request-empty" data-request-empty>
                    📭<br>
                    درخواستی مطابق فیلتر پیدا نشد.
                </div>`;
            return;
        }

        cardsEl.innerHTML =
            list
                .map(
                    r => {

                        const matches =
                            Array.isArray(
                                r.matches
                            )
                                ? r.matches
                                : [];


                        const combos =
                            Array.isArray(
                                r.combinations
                            )
                                ? r.combinations
                                : [];


                        const amenities =
                            Array.isArray(
                                r.amenities
                            )
                                ? r.amenities.join('، ')
                                : (
                                    r.amenities ||
                                    'هیچکدام'
                                );


                        return `

<article class="request-admin-card" data-request-card-code="${requestEsc(r.tracking_code || '')}">

    <div class="request-admin-head">
        <div>
            <div class="request-code">🎫 ${requestEsc(r.tracking_code || 'بدون کد رهگیری')}</div>
            <div class="request-date">${requestEsc(r.created_at || '')}</div>
        </div>

        <div class="request-status-wrap">
            <select class="request-status-select" data-request-status data-code="${requestEsc(r.tracking_code || '')}">
                <option value="new" ${(r.status || 'new') === 'new' ? 'selected' : ''}>جدید</option>
                <option value="tracking" ${(r.status || '') === 'tracking' ? 'selected' : ''}>در حال پیگیری</option>
                <option value="archived" ${(r.status || '') === 'archived' ? 'selected' : ''}>بایگانی</option>
                <option value="closed" ${(r.status || '') === 'closed' ? 'selected' : ''}>بسته شده</option>
            </select>
        </div>
    </div>

    <div class="request-admin-grid">
        <div class="request-admin-item"><small>متقاضی</small><strong>${requestEsc(r.last_name || '—')}</strong></div>
        <div class="request-admin-item"><small>شماره تماس</small><strong dir="ltr">${requestEsc(r.phone || '—')}</strong></div>
        <div class="request-admin-item"><small>نوع معامله</small><strong>${requestEsc(r.transaction_type || '—')}</strong></div>
        <div class="request-admin-item"><small>نوع ملک</small><strong>${requestEsc(r.property_type || '—')}</strong></div>
        <div class="request-admin-item"><small>محدوده</small><strong>${requestEsc(r.location || '—')}</strong></div>
        <div class="request-admin-item"><small>متراژ</small><strong>${requestEsc(((r.min_area !== null && r.min_area !== undefined && r.min_area !== '') ? normalizeNumberText(r.min_area) : '—') + ' تا ' + ((r.max_area !== null && r.max_area !== undefined && r.max_area !== '') ? normalizeNumberText(r.max_area) : '—'))}</strong></div>
        <div class="request-admin-item"><small>بودجه</small><strong>${requestEsc(requestPriceText(r))}</strong></div>
        <div class="request-admin-item"><small>امکانات</small><strong>${requestEsc(amenities)}</strong></div>
    </div>

    <div class="request-followup-box">
        <textarea class="request-followup-input" data-request-note data-code="${requestEsc(r.tracking_code || '')}" placeholder="یادداشت پیگیری این درخواست...">${requestEsc(r.followup_note || '')}</textarea>
        <button type="button" class="request-followup-save" data-request-save data-code="${requestEsc(r.tracking_code || '')}">ذخیره پیگیری</button>
        <button type="button" class="request-followup-save" style="background:var(--danger,#e74c3c);" data-request-delete data-code="${requestEsc(r.tracking_code || '')}">🗑️ حذف درخواست</button>
        <span class="request-followup-state" data-request-state data-code="${requestEsc(r.tracking_code || '')}"></span>
    </div>

    <div class="request-matches-toggle-row">
        <button type="button" class="request-collapse-toggle" data-collapse-target="matches-${requestEsc(r.tracking_code || '')}">
            🔎 فایل‌های نزدیک به درخواست (${matches.length})
            <span class="request-collapse-arrow">◂</span>
        </button>
        ${combos.length ? `
        <button type="button" class="request-collapse-toggle" data-collapse-target="combos-${requestEsc(r.tracking_code || '')}">
            🧩 ترکیب‌های پیشنهادی (${combos.length})
            <span class="request-collapse-arrow">◂</span>
        </button>` : ''}
    </div>

    <div class="request-matches" id="matches-${requestEsc(r.tracking_code || '')}" style="display:none;">
        ${matches.length ? matches.map(m => {
            const ad = getMatchedAdData(m);
            const propertyId = String(ad.id || ad.ad_id || '').trim();
            const detailUrl = propertyId ? 'property-details.php?id=' + encodeURIComponent(propertyId) : '';
            return `
<div class="request-match-row">
    <div class="request-match-main">
        ${detailUrl ? `<a class="request-match-code" href="${detailUrl}" target="_blank" rel="noopener">🔗 کد ملک: ${requestEsc(propertyId)}</a>` : `<span class="request-match-code">کد ملک: ${requestEsc(propertyId || '—')}</span>`}
        <div class="request-match-title">${requestEsc(ad.title || 'ملک')}</div>
        <div class="request-match-meta">${requestEsc((ad.property_type||'')+' · '+(ad.transaction_type||'')+' · '+(ad.location||''))}</div>
        <div class="request-match-inline">
            <span>قیمت: <strong>${requestEsc(matchDisplayPrice(ad))}</strong></span>
            <span>متراژ: <strong>${requestEsc(matchArea(ad))}</strong></span>
            <span>اتاق: <strong>${requestEsc(matchRooms(ad))}</strong></span>
            <span>امکانات: <strong>${requestEsc(matchAmenities(ad))}</strong></span>
            <span>مالک: <strong>${requestEsc(ad.last_name || ad.owner_last_name || '—')}</strong></span>
            <span>تلفن مالک: <strong dir="ltr">${requestEsc(ad.phone || ad.owner_phone || '—')}</strong></span>
        </div>
    </div>
    <div class="request-match-score">${Number(m.match_percent || 0)}٪</div>
</div>`;
        }).join('') : `<div style="font-size:12px;color:var(--text-secondary);padding-top:7px;">هنوز فایل مطابقی برای این درخواست پیدا نشده است.</div>`}
    </div>

    ${combos.length ? `
    <div class="request-matches" id="combos-${requestEsc(r.tracking_code || '')}" style="display:none;">
        ${combos.map((combo, comboIndex) => {
            const items = Array.isArray(combo.items) ? combo.items : [];
            return `
<div class="request-combo-row">
    <div class="request-combo-head">
        <strong>ترکیب ${comboIndex + 1}</strong>
        <span>${requestEsc(combo.summary || '')}</span>
        <span>جمع قیمت: <strong>${requestEsc(combo.total_price ? normalizeNumberText(combo.total_price) + ' تومان' : '—')}</strong></span>
        <span class="request-match-score">میانگین تطبیق: ${Number(combo.total_score || 0).toFixed(0)}٪</span>
    </div>
    <div class="request-combo-items">
        ${items.map(it => {
            const itemId = String(it.ad_id || it.id || '').trim();
            const itemUrl = itemId ? 'property-details.php?id=' + encodeURIComponent(itemId) : '';
            return `
        <div class="request-match-row">
            <div class="request-match-main">
                ${itemUrl ? `<a class="request-match-code" href="${itemUrl}" target="_blank" rel="noopener">🔗 کد ملک: ${requestEsc(itemId)}</a>` : `<span class="request-match-code">کد ملک: ${requestEsc(itemId || '—')}</span>`}
                <div class="request-match-title">${requestEsc(it.title || 'ملک')}</div>
                <div class="request-match-meta">${requestEsc((it.property_type||'')+' · '+(it.transaction_type||'')+' · '+(it.location||''))}</div>
                <div class="request-match-inline">
                    <span>قیمت: <strong>${requestEsc(it.price || '—')}</strong></span>
                    <span>متراژ: <strong>${requestEsc(it.area || '—')}</strong></span>
                </div>
            </div>
            <div class="request-match-score">${Number(it.match_percent || 0)}٪</div>
        </div>`;
        }).join('')}
    </div>
</div>`;
        }).join('')}
    </div>` : ''}

</article>

                        `;
                    }
                )
                .join('');


        bindRequestFilters();
    };


    container.innerHTML = toolbar + '<div id="requestsCards"></div>';

    bindRequestFilters();
    paint();
}


function applyRequestFiltersInPlace() {
    const search = document.getElementById('requestsSearch');
    const filter = document.getElementById('requestsMatchFilter');
    const statusFilter = document.getElementById('requestsStatusFilter');
    const cardsEl = document.getElementById('requestsCards');
    const countEl = document.getElementById('requestsResultCount');
    if (!search || !filter || !statusFilter || !cardsEl) return;

    const q = (search.value || '').trim().toLowerCase();
    const matchValue = filter.value || 'all';
    const statusValue = statusFilter.value || 'all';
    let visible = 0;

    requestsData.forEach(r => {
        const code = String(r.tracking_code || '');
        const card = cardsEl.querySelector(`[data-request-card-code="${CSS.escape(code)}"]`);
        if (!card) return;

        const hay = [r.tracking_code, r.last_name, r.phone, r.location, r.property_type, r.transaction_type, r.telegram_id]
            .join(' ').toLowerCase();
        const matches = Array.isArray(r.matches) ? r.matches : [];

        const okSearch = !q || hay.includes(q);
        const okMatch = matchValue === 'all' || (matchValue === 'matched' ? matches.length > 0 : matches.length === 0);
        const okStatus = statusValue === 'all' || (r.status || 'new') === statusValue;
        const show = okSearch && okMatch && okStatus;

        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    if (countEl) countEl.innerText = visible + ' درخواست';

    const empty = cardsEl.querySelector('[data-request-empty]');
    if (empty) empty.style.display = visible ? 'none' : '';
}


function bindRequestFilters() {
    const search = document.getElementById('requestsSearch');
    const filter = document.getElementById('requestsMatchFilter');
    const statusFilter = document.getElementById('requestsStatusFilter');

    if (search && !search.dataset.bound) {
        search.dataset.bound = '1';
        search.addEventListener('input', applyRequestFiltersInPlace);
    }

    if (filter && !filter.dataset.bound) {
        filter.dataset.bound = '1';
        filter.addEventListener('change', applyRequestFiltersInPlace);
    }

    if (statusFilter && !statusFilter.dataset.bound) {
        statusFilter.dataset.bound = '1';
        statusFilter.addEventListener('change', applyRequestFiltersInPlace);
    }

    document.querySelectorAll('[data-request-status]').forEach(select => {
        if (select.dataset.bound) return;
        select.dataset.bound = '1';
        select.addEventListener('change', () => saveRequestMeta(select.dataset.code));
    });

    document.querySelectorAll('[data-request-save]').forEach(button => {
        if (button.dataset.bound) return;
        button.dataset.bound = '1';
        button.addEventListener('click', () => saveRequestMeta(button.dataset.code));
    });

    document.querySelectorAll('[data-request-delete]').forEach(button => {
        if (button.dataset.bound) return;
        button.dataset.bound = '1';
        button.addEventListener('click', () => deleteRequest(button.dataset.code));
    });

    document.querySelectorAll('[data-collapse-target]').forEach(button => {
        if (button.dataset.bound) return;
        button.dataset.bound = '1';
        button.addEventListener('click', () => {
            const target = document.getElementById(button.dataset.collapseTarget);
            if (!target) return;
            const isOpen = target.style.display !== 'none';
            target.style.display = isOpen ? 'none' : '';
            button.classList.toggle('open', !isOpen);
        });
    });
}

async function deleteRequest(code) {
    if (!code) return;
    if (!confirm('این درخواست برای همیشه حذف می‌شود. مطمئنی؟')) return;

    const stateEl = document.querySelector(`[data-request-state][data-code="${CSS.escape(code)}"]`);
    if (stateEl) stateEl.textContent = 'در حال حذف...';

    const body = new URLSearchParams();
    body.set('request_action', 'delete_request');
    body.set('tracking_code', code);

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString(),
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const result = await response.json();
        if (!result.ok) throw new Error(result.message || 'خطا در حذف');

        requestsData = requestsData.filter(r => String(r.tracking_code || '') !== String(code));
        renderRequests();
    } catch (error) {
        if (stateEl) stateEl.textContent = 'خطا در حذف';
        console.error('request delete error:', error);
        alert(error.message || 'حذف درخواست انجام نشد.');
    }
}

async function saveRequestMeta(code) {
    const statusEl = document.querySelector(`[data-request-status][data-code="${CSS.escape(code)}"]`);
    const noteEl = document.querySelector(`[data-request-note][data-code="${CSS.escape(code)}"]`);
    const stateEl = document.querySelector(`[data-request-state][data-code="${CSS.escape(code)}"]`);
    if (!code || !statusEl) return;

    if (stateEl) stateEl.textContent = 'در حال ذخیره...';

    const body = new URLSearchParams();
    body.set('request_action', 'update_request_meta');
    body.set('tracking_code', code);
    body.set('status', statusEl.value);
    body.set('followup_note', noteEl ? noteEl.value : '');

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString(),
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const result = await response.json();
        if (!result.ok) throw new Error(result.message || 'خطا در ذخیره‌سازی');

        const request = requestsData.find(r => String(r.tracking_code || '') === String(code));
        if (request) {
            request.status = result.status;
            request.status_label = result.status_label;
            request.followup_note = result.followup_note || '';
        }

        if (stateEl) {
            stateEl.textContent = '✓ ذخیره شد';
            setTimeout(() => { stateEl.textContent = ''; }, 1800);
        }
    } catch (error) {
        if (stateEl) stateEl.textContent = 'خطا در ذخیره';
        console.error('request save error:', error);
    }
}


