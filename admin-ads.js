// مدیریت حرفه‌ای آگهی‌ها
// ==============================================

let adViewState = {

    filter:
        'all',

    search:
        '',

    propertyType:
        'all',

    transactionType:
        'all',

    sort:
        'newest',

    page:
        1,

    pageSize:
        12,

    selected:
        new Set()
};


function getAdImage(ad) {

    try {

        const selected =
            Array.isArray(
                ad.selected_images
            )
                ? ad.selected_images
                : JSON.parse(
                    ad.selected_images ||
                    '[]'
                );


        if (selected.length) {
            return selected[0];
        }


        const images =
            Array.isArray(ad.images)
                ? ad.images
                : JSON.parse(
                    ad.images ||
                    '[]'
                );


        return images.length
            ? images[0]
            : '';

    } catch(e) {

        return '';
    }
}


function getAdSearchText(ad) {

    return [

        ad.id,
        ad.ad_id,
        ad.title,
        ad.location,
        ad.last_name,
        ad.phone,
        ad.property_type,
        ad.transaction_type,
        ad.address

    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
}


function getNumericPrice(ad) {

    const values = [

        ad.price_sell,
        ad.total_price,
        ad.deposit,
        ad.rent_monthly

    ];


    for (const v of values) {

        if (
            v === null ||
            v === undefined ||
            v === ''
        ) {
            continue;
        }


        const n =
            Number(
                String(v)
                    .replace(
                        /[\s,٬،]/g,
                        ''
                    )
                    .replace(
                        /[۰-۹]/g,
                        d =>
                            '۰۱۲۳۴۵۶۷۸۹'
                                .indexOf(d)
                    )
            );


        if (
            !Number.isNaN(n) &&
            n > 0
        ) {
            return n;
        }
    }


    return 0;
}


function getFilteredAds() {

    const search =
        adViewState.search
            .trim()
            .toLowerCase();


    let list =
        adsData.filter(
            ad => {

                if (adViewState.filter === 'vip') {
                    if (!(ad.is_vip === true || ad.is_vip === '1')) return false;
                } else if (
                    adViewState.filter !==
                    'all' &&
                    (ad.status || 'pending') !==
                    adViewState.filter
                ) {
                    return false;
                }


                if (
                    adViewState.propertyType !==
                    'all' &&
                    ad.property_type !==
                    adViewState.propertyType
                ) {
                    return false;
                }


                if (
                    adViewState.transactionType !==
                    'all' &&
                    ad.transaction_type !==
                    adViewState.transactionType
                ) {
                    return false;
                }


                if (
                    search &&
                    !getAdSearchText(ad)
                        .includes(search)
                ) {
                    return false;
                }


                return true;
            }
        );


    list.sort(
        (a,b) => {

            if (
                adViewState.sort ===
                'oldest'
            ) {

                return new Date(
                    a.created_at || 0
                ) -
                new Date(
                    b.created_at || 0
                );
            }


            if (
                adViewState.sort ===
                'priceHigh'
            ) {

                return (
                    getNumericPrice(b) -
                    getNumericPrice(a)
                );
            }


            if (
                adViewState.sort ===
                'priceLow'
            ) {

                return (
                    getNumericPrice(a) -
                    getNumericPrice(b)
                );
            }


            if (
                adViewState.sort ===
                'title'
            ) {

                return String(
                    a.title || ''
                ).localeCompare(
                    String(
                        b.title || ''
                    ),
                    'fa'
                );
            }


            return new Date(
                b.created_at || 0
            ) -
            new Date(
                a.created_at || 0
            );
        }
    );


    return list;
}


// ==============================================
function renderAds() {

    const allFiltered =
        getFilteredAds();


    const totalPages =
        Math.max(
            1,
            Math.ceil(
                allFiltered.length /
                adViewState.pageSize
            )
        );


    if (
        adViewState.page >
        totalPages
    ) {
        adViewState.page =
            totalPages;
    }


    const start =
        (
            adViewState.page -
            1
        ) *
        adViewState.pageSize;


    const pageItems =
        allFiltered.slice(
            start,
            start +
            adViewState.pageSize
        );


    const container =
        document.getElementById(
            'adsListContainer'
        );


    document.getElementById(
        'adsResultCount'
    ).innerText =
        `${allFiltered.length} آگهی`;


    if (!pageItems.length) {

        container.innerHTML =
            `
            <div class="empty-table">
                🔎
                <br>
                <strong>
                    آگهی‌ای پیدا نشد
                </strong>

                <div style="margin-top:6px">
                    فیلترها یا عبارت جستجو را تغییر دهید.
                </div>
            </div>
            `;

    } else {

        const allPageSelected =
            pageItems.every(
                ad =>
                    adViewState.selected.has(
                        String(ad.id)
                    )
            );


        const rows =
            pageItems
                .map(
                    ad => {

                        const selected =
                            adViewState.selected.has(
                                String(ad.id)
                            );


                        const img =
                            getAdImage(ad);


                        const details =
                            ad.property_details ||
                            {};


                        const rawArea =
                            details.area ??
                            details.built_area ??
                            details.land_area ??
                            ad.area ??
                            ad.built_area ??
                            ad.land_area ??
                            '';
                        const area = rawArea !== '' && rawArea !== null && rawArea !== undefined ? normalizeNumberText(rawArea) : '-';


                        const statusLabel =
                            getStatusLabel(
                                ad.status
                            );


                        const statusClass =
                            getStatusClass(
                                ad.status
                            );


                        let primaryAction =
                            '';


                        if (
                            ad.status ===
                            'pending'
                        ) {

                            primaryAction = `
<button
    class="table-action primary"
    title="تایید و انتشار"
    onclick="event.stopPropagation(); approveAd('${ad.id}')"
>
    ✓
</button>
`;

                        } else if (
                            ad.status ===
                                'suspended' ||
                            ad.status ===
                                'sold' ||
                            ad.status ===
                                'rejected'
                        ) {

                            primaryAction = `
<button
    class="table-action primary"
    title="انتشار مجدد"
    onclick="event.stopPropagation(); changeAdStatus('${ad.id}','published')"
>
    ↻
</button>
`;

                        } else {

                            primaryAction = `
<button
    class="table-action"
    title="معلق کردن"
    onclick="event.stopPropagation(); changeAdStatus('${ad.id}','suspended')"
>
    ⏸
</button>
`;
                        }


                        return `

<tr>

<td>

<input
    class="selection-check ad-select"
    type="checkbox"
    value="${ad.id}"
    ${
        selected
            ? 'checked'
            : ''
    }
    onchange="toggleAdSelection('${ad.id}', this.checked)"
>

</td>


<td>
    ${ad.id ?? '-'}
</td>


<td>

<div class="ad-row-main">

${
    img
        ?
        `<img
            class="ad-row-thumb"
            src="${img}"
            alt=""
        >`

        :

        `<div
            class="ad-row-thumb"
        ></div>`
}

<div>

<div class="ad-row-title">
    ${escapeHtml(
        ad.title ||
        'بدون عنوان'
    )}
</div>

<div class="ad-row-sub">
    ${escapeHtml(
        ad.location ||
        '-'
    )}
</div>
${ad.is_vip ? '<span class="ad-vip-pill">★ VIP</span>' : ''}

</div>

</div>

</td>


<td>
    ${escapeHtml(
        ad.property_type ||
        '-'
    )}
</td>


<td>
    ${escapeHtml(
        ad.transaction_type ||
        '-'
    )}
</td>


<td>

<strong>
    ${escapeHtml(
        getDisplayPrice(ad)
            .replace(
                /^.+?: /,
                ''
            ) ||
        '-'
    )}
</strong>

<div class="ad-quick-status">

${
    escapeHtml(area)
}

${
    area !== '-'
        ? ' متر'
        : ''
}

</div>

</td>


<td>

${escapeHtml(
    ad.last_name ||
    '-'
)}

<div
    class="ad-row-sub"
    dir="ltr"
>
    ${escapeHtml(
        ad.phone ||
        '-'
    )}
</div>

</td>


<td>

<span
    class="ad-status ${statusClass}"
>
    ${statusLabel}
</span>

</td>


<td>
    ${escapeHtml(
        ad.created_at ||
        '-'
    )}
</td>


<td>

<div class="table-actions">

<button
    class="table-action"
    title="جزئیات"
    onclick="showAdDetails('${ad.id}')"
>
    ◉
</button>


<button
    class="table-action"
    title="ویرایش"
    onclick="openAdEditModal('${ad.id}')"
>
    ✎
</button>


${primaryAction}


<button
    class="table-action vip ${ad.is_vip ? 'active' : ''}"
    title="${ad.is_vip ? 'حذف از VIP' : 'افزودن به VIP'}"
    onclick="event.stopPropagation(); toggleAdVip('${ad.id}')"
>
    ★
</button>


<button
    class="table-action"
    title="تلگرام"
    onclick="publishToTelegram('${ad.id}')"
>
    ↗
</button>


<button
    class="table-action"
    title="بله"
    onclick="publishToBale('${ad.id}')"
>
    💬
</button>


<button
    class="table-action danger"
    title="حذف"
    onclick="deleteAd('${ad.id}')"
>
    🗑
</button>

</div>

</td>

</tr>

`;
                    }
                )
                .join('');


        container.innerHTML = `

<div class="ads-table-wrap">

<table class="ads-table">

<thead>

<tr>

<th>

<input
    class="selection-check"
    type="checkbox"
    ${
        allPageSelected
            ? 'checked'
            : ''
    }
    onchange="togglePageSelection(this.checked)"
>

</th>

<th>#</th>

<th>آگهی</th>

<th>نوع ملک</th>

<th>معامله</th>

<th>قیمت / متراژ</th>

<th>مالک</th>

<th>وضعیت</th>

<th>تاریخ</th>

<th>عملیات</th>

</tr>

</thead>


<tbody>
    ${rows}
</tbody>

</table>

</div>

`;
    }


    document.getElementById(
        'adsPaginationInfo'
    ).innerText =
        allFiltered.length
            ?
            `نمایش ${start + 1} تا ${Math.min(
                start +
                adViewState.pageSize,
                allFiltered.length
            )} از ${allFiltered.length}`
            :
            '';


    renderPagination(
        totalPages
    );


    updateBulkBar();
}


function renderPagination(totalPages) {

    const el =
        document.getElementById(
            'adsPagination'
        );


    if (totalPages <= 1) {

        el.innerHTML =
            '';

        return;
    }


    let html =
        '';


    for (
        let i = 1;
        i <= totalPages;
        i++
    ) {

        if (
            i === 1 ||
            i === totalPages ||
            Math.abs(
                i -
                adViewState.page
            ) <= 1
        ) {

            html += `
<button
    class="page-btn ${
        i === adViewState.page
            ? 'active'
            : ''
    }"
    onclick="goToAdPage(${i})"
>
    ${i}
</button>
`;

        } else if (
            i === 2 ||
            i ===
                totalPages - 1
        ) {

            html +=
                '<span style="padding:8px;color:var(--text-secondary)">…</span>';
        }
    }


    el.innerHTML =
        html;
}


function goToAdPage(page) {

    adViewState.page =
        page;

    renderAds();
}


function filterAds(
    filter,
    btn
) {

    adViewState.filter =
        filter;

    adViewState.page =
        1;


    document
        .querySelectorAll(
            '#tab-ads .btn-filter'
        )
        .forEach(
            b =>
                b.classList.remove(
                    'active'
                )
        );


    if (btn) {
        btn.classList.add(
            'active'
        );
    }


    renderAds();
}


function resetAdFilters() {

    adViewState = {

        ...adViewState,

        filter:
            'all',

        search:
            '',

        propertyType:
            'all',

        transactionType:
            'all',

        sort:
            'newest',

        page:
            1
    };


    document.getElementById(
        'adsSearch'
    ).value = '';


    document.getElementById(
        'adsPropertyFilter'
    ).value =
        'all';


    document.getElementById(
        'adsTransactionFilter'
    ).value =
        'all';


    document.getElementById(
        'adsSort'
    ).value =
        'newest';


    document
        .querySelectorAll(
            '#tab-ads .btn-filter'
        )
        .forEach(
            b =>
                b.classList.remove(
                    'active'
                )
        );


    document
        .querySelector(
            '#tab-ads .btn-filter'
        )
        .classList.add(
            'active'
        );


    renderAds();
}


function escapeHtml(value) {

    return String(
        value ??
        ''
    ).replace(
        /[&<>'"]/g,
        ch =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#039;',
                '"': '&quot;'
            })[ch]
    );
}


function editEsc(value) {

    return escapeHtml(
        value
    );
}


function toggleAdSelection(
    id,
    checked
) {

    const key =
        String(id);


    if (checked) {

        adViewState.selected.add(
            key
        );

    } else {

        adViewState.selected.delete(
            key
        );
    }


    updateBulkBar();
}


function togglePageSelection(
    checked
) {

    getFilteredAds()
        .slice(
            (
                adViewState.page -
                1
            ) *
            adViewState.pageSize,

            adViewState.page *
            adViewState.pageSize
        )
        .forEach(
            ad => {

                const key =
                    String(ad.id);


                if (checked) {

                    adViewState.selected.add(
                        key
                    );

                } else {

                    adViewState.selected.delete(
                        key
                    );
                }
            }
        );


    renderAds();
}


function updateBulkBar() {

    const count =
        adViewState.selected.size;


    document.getElementById(
        'selectedCount'
    ).innerText =
        count;


    document.getElementById(
        'bulkBar'
    ).classList.toggle(
        'active',
        count > 0
    );
}


async function bulkChangeStatus(
    status
) {

    const ids =
        [
            ...adViewState.selected
        ];


    if (!ids.length) {
        return;
    }


    const label =
        status ===
        'published'
            ? 'منتشر'
            : 'معلق';


    if (
        !confirm(
            `وضعیت ${ids.length} آگهی به «${label}» تغییر کند؟`
        )
    ) {
        return;
    }


    adsData.forEach(
        ad => {

            if (
                ids.includes(
                    String(ad.id)
                )
            ) {
                ad.status =
                    status;
            }
        }
    );


    await saveAdsToFile();


    adViewState.selected.clear();


    renderAds();

    renderDashboard();
}


async function bulkDeleteAds() {

    const ids =
        [
            ...adViewState.selected
        ];


    if (!ids.length) {
        return;
    }


    if (
        !confirm(
            `آیا ${ids.length} آگهی انتخاب‌شده حذف شوند؟ این عملیات قابل بازگشت نیست.`
        )
    ) {
        return;
    }


    adsData =
        adsData.filter(
            ad =>
                !ids.includes(
                    String(ad.id)
                )
        );


    adViewState.selected.clear();


    await saveAdsToFile();


    renderAds();

    renderDashboard();
}


// ==============================================
// عملیات آگهی
// ==============================================

async function approveAd(id) {

    const ad =
        adsData.find(
            a =>
                a.id == id
        );


    if (!ad) {
        return;
    }


    ad.status =
        'published';


    await saveAdsToFile();


    alert(
        '✅ تایید شد'
    );


    renderAds();

    renderDashboard();
}


async function changeAdStatus(
    id,
    newStatus
) {

    const ad =
        adsData.find(
            a =>
                a.id == id
        );


    if (!ad) {
        return;
    }


    const labels = {

        sold:
            'فروخته شده',

        suspended:
            'معلق',

        published:
            'منتشر شده'
    };


    if (
        !confirm(
            `آیا وضعیت را به "${labels[newStatus]}" تغییر می‌دهید؟`
        )
    ) {
        return;
    }


    ad.status =
        newStatus;


    await saveAdsToFile();


    alert(
        '✅ تغییر کرد'
    );


    renderAds();

    renderDashboard();
}


async function deleteAd(id) {

    if (
        !confirm(
            'حذف شود؟'
        )
    ) {
        return;
    }


    adsData =
        adsData.filter(
            a =>
                a.id != id
        );


    await saveAdsToFile();


    renderAds();

    renderDashboard();
}


async function toggleAdVip(id){
    const ad=adsData.find(a=>String(a.id)===String(id));
    if(!ad)return;
    ad.is_vip=!ad.is_vip;
    const ok=await saveAdsToFile();
    if(ok){renderAds();renderDashboard();}
    else{ad.is_vip=!ad.is_vip;}
}

/**
 * انتشار آگهی در تلگرام/بله
 *
 * ترتیب تلاش:
 *   ۱. ارسال مستقیم از «مرورگرِ ادمین» به تلگرام/بله
 *      (این مسیر مشکلِ هاست‌هایی مثل InfinityFree را حل می‌کند که
 *       دسترسیِ خروجیِ سرور به api.telegram.org را مسدود کرده‌اند)
 *   ۲. در صورت شکست، ارسال از «سرور» (هاست‌های معمولی)
 */
async function melkinoPublishAd(id, platform) {

    const label = platform === 'bale' ? 'بله' : 'تلگرام';

    const ad =
        adsData.find(
            a =>
                a.id == id
        );


    if (!ad) {

        alert(
            'آگهی یافت نشد'
        );

        return;
    }

    if (!confirm(`آگهی «${ad.title}» به کانال ${label} ارسال شود؟`)) {
        return;
    }

    let prepared = null;
    let browserReason = '';

    try {

        prepared = await fetch('telegram-relay.php?action=prepare', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ad_id: ad.id, platform: platform })
        }).then(function (r) { return r.json(); });

    } catch (e) {
        prepared = null;
        browserReason = 'ارتباط با telegram-relay.php برقرار نشد.';
    }

    if (!(prepared && prepared.success)) {

        if (!browserReason) {
            browserReason =
                prepared && prepared.message
                    ? prepared.message
                    : 'آماده‌سازیِ پیام روی سرور ناموفق بود.';
        }

    } else if (typeof window.melkinoApiCall !== 'function') {

        browserReason =
            'اسکریپتِ ارتباط با پیام‌رسان (telegram-relay.js) در صفحه لود نشده است.';

    } else {

        if (!prepared.chat_id) {
            alert(`❌ شناسه کانال ${label} تنظیم نشده است. از تب «ربات و کانال» آن را وارد کن.`);
            return;
        }

        const usePhoto = !!(prepared.has_photo && prepared.photo_url);

        function buildParams(withPhoto) {
            const p = withPhoto
                ? {
                    chat_id: prepared.chat_id,
                    photo: prepared.photo_url,
                    caption: prepared.text
                }
                : {
                    chat_id: prepared.chat_id,
                    text: prepared.text
                };

            if (platform !== 'bale') {
                p.parse_mode = 'HTML';
            }

            return p;
        }

        let result = null;
        let usedFallback = false;

        if (usePhoto) {
            result = await window.melkinoApiCall(platform, 'sendPhoto', buildParams(true));

            // اگر تلگرام/بله نتوانست تصویر را از سایت دریافت کند
            // (مثلاً هات‌لینک بسته باشد)، بدون عکس دوباره امتحان می‌کنیم
            if (!(result && result.ok)) {
                const textResult = await window.melkinoApiCall(platform, 'sendMessage', buildParams(false));
                if (textResult && textResult.ok) {
                    result = textResult;
                    usedFallback = true;
                }
            }
        } else {
            result = await window.melkinoApiCall(platform, 'sendMessage', buildParams(false));
        }

        if (result && result.ok) {

            const messageId =
                result.result && result.result.message_id
                    ? result.result.message_id
                    : '';

            if (messageId && typeof window.melkinoRecordPublish === 'function') {
                await window.melkinoRecordPublish(platform, ad.id, String(messageId));
            }

            const via =
                result.via === 'browser'
                    ? 'ارسال از مرورگر شما'
                    : 'ارسال از سرور';

            let msg = `📢 آگهی «${ad.title}» با موفقیت در ${label} منتشر شد (${via}).`;

            if (usedFallback) {
                msg += '\n⚠️ پیام بدون عکس ارسال شد، چون ' + label + ' نتوانست تصویر را از سایت دریافت کند.';
            }

            alert(msg);

            return;
        }

        browserReason =
            result && result.description
                ? result.description
                : 'علت نامشخص';
    }

    /*
    |--------------------------------------------------------------------------
    | مسیرِ پشتیبانِ سرور
    |--------------------------------------------------------------------------
    | اگر ارسال از مرورگر ممکن نبود (اسکریپت لود نشده، دسترسیِ شبکه‌ایِ
    | مرورگر مسدود، یا خطا در آماده‌سازی)، انتشار را از سمتِ سرور امتحان
    | می‌کنیم.
    |
    | قبلاً برای «بله» این مسیر وجود نداشت و فقط یک پیامِ کلیِ
    | «ناموفق بود» نشان داده می‌شد، بدون اینکه علتش گفته شود — برای همین
    | تشخیصِ مشکل عملاً غیرممکن بود. حالا علتِ هر دو مسیر گزارش می‌شود.
    |--------------------------------------------------------------------------
    */
    const endpoint = platform === 'bale' ? 'publish-to-bale.php' : 'publish-to-telegram.php';

    try {

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: ad.id })
        });

        const result = await response.json();

        if (result && result.success) {
            alert(`📢 آگهی «${ad.title}» از طریقِ سرور در ${label} منتشر شد.`);
            return;
        }

        const serverReason =
            result && result.message
                ? result.message
                : 'پاسخ نامعتبر از سرور';

        alert(
            `❌ انتشار در ${label} ناموفق بود.\n` +
            `• علتِ مسیرِ مرورگر: ${browserReason}\n` +
            `• علتِ مسیرِ سرور: ${serverReason}`
        );

    } catch (e) {

        alert(
            `❌ انتشار در ${label} ناموفق بود.\n` +
            `• علتِ مسیرِ مرورگر: ${browserReason}\n` +
            `• مسیرِ سرور هم در دسترس نبود.`
        );
    }
}

async function publishToTelegram(id) {
    return melkinoPublishAd(id, 'telegram');
}


// ==============================================
// انتشار در کانال بله
// ==============================================

async function publishToBale(id) {
    return melkinoPublishAd(id, 'bale');
}


// ==============================================
// جزئیات کامل آگهی
// ==============================================

const propertyFieldDefs = {

    'آپارتمان': [

        [
            'area_apt',
            'متراژ',
            'area'
        ],

        [
            'floor',
            'طبقه',
            'floor'
        ],

        [
            'unit',
            'شماره واحد',
            'unit'
        ],

        [
            'total_units',
            'تعداد کل واحدها',
            'total_units'
        ],

        [
            'rooms_apt',
            'تعداد اتاق',
            'rooms'
        ],

        [
            'year_apt',
            'سال ساخت',
            'year'
        ],

        [
            'flooring_apt',
            'نوع پوشش کف',
            'flooring'
        ],

        [
            'cabinet_apt',
            'نوع کابینت',
            'cabinet'
        ],

        [
            'cooling_apt',
            'سیستم سرمایش',
            'cooling'
        ],

        [
            'heating_apt',
            'سیستم گرمایش',
            'heating'
        ]

    ],


    'ویلا': [

        [
            'land_villa',
            'مساحت زمین',
            'land_area'
        ],

        [
            'built_villa',
            'زیربنا',
            'built_area'
        ],

        [
            'rooms_villa',
            'تعداد اتاق',
            'rooms'
        ],

        [
            'year_villa',
            'سال ساخت',
            'year'
        ],

        [
            'flooring_villa',
            'نوع پوشش کف',
            'flooring'
        ],

        [
            'cabinet_villa',
            'نوع کابینت',
            'cabinet'
        ],

        [
            'cooling_villa',
            'سیستم سرمایش',
            'cooling'
        ],

        [
            'heating_villa',
            'سیستم گرمایش',
            'heating'
        ]

    ],


    'زمین': [

        [
            'land_area',
            'مساحت زمین',
            'land_area'
        ],

        [
            'land_usage',
            'نوع کاربری',
            'land_usage'
        ],

        [
            'land_type',
            'نوع زمین',
            'land_type'
        ],

        [
            'land_width',
            'عرض زمین',
            'land_width'
        ],

        [
            'land_length',
            'طول زمین',
            'land_length'
        ],

        [
            'land_front_width',
            'عرض بر',
            'land_front_width'
        ],

        [
            'land_blocks',
            'تعداد بر',
            'land_blocks'
        ],

        [
            'land_direction',
            'جهت ملک',
            'land_direction'
        ],

        [
            'land_shape',
            'شکل زمین',
            'land_shape'
        ],

        [
            'land_deed_status',
            'وضعیت سند',
            'land_deed_status'
        ],

        [
            'land_deed_type',
            'نوع سند',
            'land_deed_type'
        ],

        [
            'land_division_status',
            'وضعیت تفکیک',
            'land_division_status'
        ],

        [
            'land_setback_status',
            'وضعیت عقب‌نشینی',
            'land_setback_status'
        ],

        [
            'land_ownership',
            'وضعیت مالکیت',
            'land_ownership'
        ]

    ],


    'باغ': [

        [
            'garden_area',
            'مساحت باغ',
            'garden_area'
        ],

        [
            'tree_count',
            'تعداد درختان',
            'tree_count'
        ],

        [
            'tree_types',
            'نوع درختان',
            'tree_types'
        ],

        [
            'tree_age',
            'سن درختان',
            'tree_age'
        ],

        [
            'irrigation_type',
            'نوع آبیاری',
            'irrigation_type'
        ],

        [
            'water_source',
            'منبع آب',
            'water_source'
        ],

        [
            'water_share',
            'سهم آب',
            'water_share'
        ],

        [
            'has_well',
            'چاه آب',
            'has_well'
        ],

        [
            'has_pond',
            'استخر آب',
            'has_pond'
        ],

        [
            'has_building',
            'بنا / خانه باغ',
            'has_building'
        ],

        [
            'building_area',
            'متراژ بنا',
            'building_area'
        ],

        [
            'document_type',
            'نوع سند',
            'document_type'
        ]

    ],


    'اداری': [

        [
            'office_area',
            'متراژ واحد',
            'office_area'
        ],

        [
            'office_floor',
            'طبقه',
            'office_floor'
        ],

        [
            'office_units_per_floor',
            'تعداد واحد در طبقه',
            'office_units_per_floor'
        ],

        [
            'office_rooms',
            'تعداد اتاق',
            'office_rooms'
        ],

        [
            'office_year',
            'سال ساخت',
            'office_year'
        ],

        [
            'office_condition',
            'وضعیت واحد',
            'office_condition'
        ],

        [
            'office_orientation',
            'موقعیت واحد',
            'office_orientation'
        ],

        [
            'office_usage',
            'کاربری',
            'office_usage'
        ]

    ],


    'تجاری': [

        [
            'area_comm',
            'متراژ',
            'area_comm'
        ],

        [
            'front_comm',
            'بر مغازه',
            'front_comm'
        ],

        [
            'floor_comm',
            'پوشش کف',
            'floor_comm'
        ],

        [
            'wall_comm',
            'پوشش دیوارها',
            'wall_comm'
        ],

        [
            'cabinet_comm',
            'نوع کابینت',
            'cabinet_comm'
        ],

        [
            'cooling_comm',
            'سیستم سرمایش',
            'cooling_comm'
        ],

        [
            'heating_comm',
            'سیستم گرمایش',
            'heating_comm'
        ],

        [
            'has_balcony',
            'بالکن دارد',
            'has_balcony'
        ],

        [
            'has_basement',
            'زیرزمین دارد',
            'has_basement'
        ],

        [
            'balcony_comm',
            'متراژ بالکن',
            'balcony_comm'
        ],

        [
            'basement_comm',
            'متراژ زیرزمین',
            'basement_comm'
        ],

        [
            'location_type_1',
            'موقعیت ملک تجاری',
            'location_type_1'
        ],

        [
            'location_type_2',
            'موقعیت خیابان / گذر',
            'location_type_2'
        ],

        [
            'jobs_comm',
            'مناسب برای مشاغل',
            'jobs_comm'
        ]

    ]

};


const propertyAmenities = {

    'آپارتمان': [
        'آسانسور',
        'پارکینگ',
        'انباری',
        'مطبخ',
        'بالکن / تراس',
        'حیاط اختصاصی',
        'روف گاردن',
        'لاندری روم',
        'کلوزت',
        'اتاق مستر',
        'نگهبانی',
        'استخر',
        'سونا',
        'جکوزی'
    ],

    'ویلا': [
        'آسانسور',
        'پارکینگ',
        'انباری',
        'مطبخ',
        'بالکن / تراس',
        'حیاط اختصاصی',
        'روف گاردن',
        'لاندری روم',
        'کلوزت',
        'اتاق مستر',
        'نگهبانی',
        'استخر',
        'سونا',
        'جکوزی',
        'زیرزمین',
        'گلخانه',
        'حیاط خلوت',
        'دوبلکس'
    ],

    'زمین': [
        'آب',
        'برق',
        'گاز',
        'تلفن',
        'فاضلاب',
        'آب شهری',
        'چاه',
        'دیوارکشی',
        'درب ورودی',
        'آسفالت بودن مسیر',
        'دسترسی به خیابان اصلی',
        'دسترسی به کوچه'
    ],

    'باغ': [
        'سرویس بهداشتی',
        'پارکینگ',
        'انباری',
        'آلاچیق',
        'استخر',
        'سونا',
        'باربیکیو',
        'برق',
        'گاز',
        'آب شهری',
        'دیوارکشی',
        'نگهبانی',
        'درب ورودی خودرو'
    ],

    'اداری': [
        'آسانسور',
        'پارکینگ',
        'انباری',
        'لابی',
        'نگهبانی',
        'دوربین مداربسته',
        'سیستم اعلام حریق',
        'اطفای حریق',
        'سیستم سرمایش',
        'سیستم گرمایش',
        'برق اختصاصی',
        'سه‌فاز',
        'آب',
        'گاز',
        'اینترنت',
        'تلفن',
        'آبدارخانه',
        'سرویس بهداشتی',
        'اتاق مدیریت',
        'اتاق جلسات',
        'پارتیشن‌بندی',
        'سیستم هوشمند',
        'درب ضدسرقت',
        'تابلوخور مناسب',
        'دسترسی به حمل‌ونقل عمومی'
    ],

    'تجاری': [
        'شیشه سکوریت',
        'درب اتوماتیک',
        'درب فلزی',
        'کرکره برقی',
        'کرکره معمولی',
        'سرویس بهداشتی',
        'آسانسور',
        'بالابر',
        'ویترین',
        'نورپردازی',
        'اسپیلت',
        'کولر آبی',
        'آبگرمکن',
        'پکیج',
        'بخاری'
    ]

};


const fieldLabelMap =
    Object.fromEntries(
        Object.values(
            propertyFieldDefs
        )
        .flat()
        .map(
            ([
                key,
                label
            ]) =>
                [
                    key,
                    label
                ]
        )
    );


const valueAliases = {

    area_apt:
        [
            'area',
            'area_apt'
        ],

    flooring_apt:
        [
            'flooring',
            'flooring_apt'
        ],

    cabinet_apt:
        [
            'cabinet',
            'cabinet_apt'
        ],

    cooling_apt:
        [
            'cooling',
            'cooling_apt'
        ],

    heating_apt:
        [
            'heating',
            'heating_apt'
        ],

    rooms_apt:
        [
            'rooms',
            'rooms_apt'
        ],

    year_apt:
        [
            'year',
            'year_apt'
        ],

    land_villa:
        [
            'land_area',
            'land_villa'
        ],

    built_villa:
        [
            'built_area',
            'built_villa'
        ],

    rooms_villa:
        [
            'rooms',
            'rooms_villa'
        ],

    year_villa:
        [
            'year',
            'year_villa'
        ],

    flooring_villa:
        [
            'flooring',
            'flooring_villa'
        ],

    cabinet_villa:
        [
            'cabinet',
            'cabinet_villa'
        ],

    cooling_villa:
        [
            'cooling',
            'cooling_villa'
        ],

    heating_villa:
        [
            'heating',
            'heating_villa'
        ]

};


function normalizeJsonArray(
    value
) {

    if (
        Array.isArray(value)
    ) {
        return value;
    }


    if (!value) {
        return [];
    }


    if (
        typeof value ===
        'string'
    ) {

        try {

            const x =
                JSON.parse(value);

            return Array.isArray(x)
                ? x
                : [];

        } catch(e) {

            return [];
        }
    }


    return [];
}


function normalizeJsonObject(
    value
) {

    if (
        value &&
        typeof value ===
        'object' &&
        !Array.isArray(value)
    ) {
        return value;
    }


    if (
        typeof value ===
        'string'
    ) {

        try {

            const x =
                JSON.parse(value);


            return (
                x &&
                typeof x ===
                    'object' &&
                !Array.isArray(x)
            )
                ? x
                : {};

        } catch(e) {

            return {};
        }
    }


    return {};
}


function getPropertyValue(
    details,
    key
) {

    const keys = [
        ...(valueAliases[key] || [key]),
        key
    ];


    for (
        const k of keys
    ) {

        if (
            details[k] !==
                undefined &&
            details[k] !==
                null &&
            details[k] !==
                ''
        ) {

            return details[k];
        }
    }


    return '';
}


function prettyBool(v) {

    return [
        '1',
        'true',
        'yes',
        'on',
        'دارد',
        'بله'
    ].includes(
        String(v).toLowerCase()
    )

        ? 'دارد'

        :

        [
            '0',
            'false',
            'no',
            'off',
            'ندارد',
            'خیر'
        ].includes(
            String(v).toLowerCase()
        )

            ? 'ندارد'

            : String(v || '');
}


function prettyDetailValue(v) {

    if (
        v === undefined ||
        v === null ||
        v === ''
    ) {
        return 'ثبت نشده';
    }


    if (
        String(v) === '1' ||
        String(v) === '0' ||
        [
            'true',
            'false'
        ].includes(
            String(v).toLowerCase()
        )
    ) {
        return prettyBool(v);
    }


    return String(v);
}


function renderKVGrid(
    items
) {

    return `

<div class="detail-kv-grid">

${
    items
        .map(
            ([
                label,
                value
            ]) => `

<div class="detail-kv">

    <span>
        ${escapeHtml(label)}
    </span>

    <strong>
        ${escapeHtml(
            prettyDetailValue(value)
        )}
    </strong>

</div>

`
        )
        .join('')
}

</div>

`;
}


function imageUrl(src) {

    return String(
        src || ''
    ).replace(
        /&amp;/g,
        '&'
    );
}


function renderDetailGallery(
    ad
) {

    const images =
        normalizeJsonArray(
            ad.images
        );


    const selected =
        normalizeJsonArray(
            ad.selected_images
        );


    const userYes =
        String(
            ad.publish_photos ||
            'yes'
        ) === 'yes';


    if (!images.length) {

        return `
<div class="detail-empty">
    این آگهی هیچ تصویری ندارد.
</div>
`;
    }


    return `

<div class="detail-policy">

    <span>
        انتخاب کاربر:
    </span>

    <strong>
        ${
            userYes
                ? '✅ انتشار تصاویر را می‌خواهد'
                : '❌ انتشار تصاویر را نمی‌خواهد'
        }
    </strong>

    <small>
        تصاویر نهایی منتشرشده توسط ادمین تعیین می‌شوند.
    </small>

</div>


<div class="detail-gallery">

${
    images
        .map(
            (
                src,
                i
            ) => `

<div
    class="detail-image-card ${
        selected.includes(src)
            ? 'is-selected'
            : ''
    }"
>

<img
    src="${escapeHtml(
        imageUrl(src)
    )}"
    alt="تصویر ${i + 1}"
>

<div class="detail-image-meta">

<span>
    تصویر ${i + 1}
</span>

${
    selected.includes(src)
        ? '<b>✓ برای انتشار</b>'
        : ''
}

</div>

</div>

`
        )
        .join('')
}

</div>

`;
}


function showAdDetails(id) {

    const ad =
        adsData.find(
            a =>
                String(a.id) ===
                String(id)
        );


    if (!ad) {

        alert(
            'آگهی یافت نشد'
        );

        return;
    }


    const details =
        normalizeJsonObject(
            ad.property_details
        );


    const amenities =
        normalizeJsonArray(
            ad.amenities
        );


    const type =
        ad.property_type ||
        'ملک';


    const fields =
        propertyFieldDefs[type] ||
        [];


    const specItems =
        fields
            .map(
                ([
                    key,
                    label
                ]) => [
                    label,
                    getPropertyValue(
                        details,
                        key
                    )
                ]
            )
            .filter(
                ([,v]) =>
                    v !== '' &&
                    v !== null &&
                    v !== undefined
            );


    const priceRows =
        [];


    if (
        ad.transaction_type ===
        'فروش'
    ) {

        priceRows.push(
            [
                'قیمت فروش',
                ad.price_sell !== null && ad.price_sell !== undefined && Number(ad.price_sell) > 0 ? normalizeNumberText(ad.price_sell) : '-'
            ],
            [
                'وضعیت قیمت',
                ad.price_condition ===
                'fixed'
                    ? 'مقطوع'
                    : 'قابل مذاکره'
            ]
        );

    } else if (
        ad.transaction_type ===
        'اجاره'
    ) {

        if (
            String(
                ad.full_rent_enabled
            ) === '1' ||
            (
                ad.full_rent &&
                !ad.deposit &&
                !ad.rent_monthly
            )
        ) {

            priceRows.push(
                [
                    'رهن کامل',
                    ad.full_rent !== null && ad.full_rent !== undefined && Number(ad.full_rent) > 0 ? normalizeNumberText(ad.full_rent) : '-'
                ]
            );

        } else {

            priceRows.push(
                [
                    'ودیعه',
                    ad.deposit !== null && ad.deposit !== undefined && Number(ad.deposit) > 0 ? normalizeNumberText(ad.deposit) : '-'
                ],

                [
                    'اجاره ماهانه',
                    ad.rent_monthly !== null && ad.rent_monthly !== undefined && Number(ad.rent_monthly) > 0 ? normalizeNumberText(ad.rent_monthly) : '-'
                ]
            );
        }

    } else if (
        ad.transaction_type ===
        'پیش فروش'
    ) {

        priceRows.push(

            [
                'قیمت کل',
                ad.total_price !== null && ad.total_price !== undefined && Number(ad.total_price) > 0 ? normalizeNumberText(ad.total_price) : '-'
            ],

            [
                'پیش‌پرداخت',
                ad.down_payment !== null && ad.down_payment !== undefined && Number(ad.down_payment) > 0 ? normalizeNumberText(ad.down_payment) : '-'
            ],

            [
                'شرایط پرداخت',
                ad.payment_terms ||
                    '-'
            ]

        );
    }


    const contact =
        renderKVGrid(
            [
                [
                    'جنسیت',
                    ad.gender ||
                        '-'
                ],
                [
                    'نام خانوادگی',
                    ad.last_name ||
                        '-'
                ],
                [
                    'شماره تماس',
                    ad.phone ||
                        '-'
                ]
            ]
        );


    const base =
        renderKVGrid(
            [

                [
                    'کد آگهی',
                    ad.ad_id ||
                        ad.id
                ],

                [
                    'عنوان',
                    ad.title ||
                        '-'
                ],

                [
                    'نوع معامله',
                    ad.transaction_type ||
                        '-'
                ],

                [
                    'نوع ملک',
                    type
                ],

                [
                    'محدوده',
                    ad.location ||
                        '-'
                ],

                [
                    'آدرس دقیق',
                    ad.address ||
                        '-'
                ],

                [
                    'لوکیشن',
                    ad.location_received ===
                    '1'
                        ? 'دریافت شده'
                        : 'ثبت نشده'
                ],

                [
                    'وضعیت',
                    getStatusLabel(
                        ad.status
                    )
                ]

            ]
        );


    const price =
        renderKVGrid(
            priceRows
        );


    const amen =
        amenities.length

            ?

            amenities
                .map(
                    x => `
<span class="detail-tag">
    ✓
    ${escapeHtml(x)}
</span>
`
                )
                .join('')

            :

            `
<span class="detail-muted">
    امکاناتی ثبت نشده است.
</span>
`;


    const content =
        document.getElementById(
            'adDetailContent'
        );


    content.innerHTML = `

<div class="detail-shell">

<div class="detail-hero">

<div>

<div class="detail-title">
    ${escapeHtml(
        ad.title ||
        'بدون عنوان'
    )}
</div>

<div class="detail-sub">
    ${escapeHtml(type)}
    ·
    ${escapeHtml(
        ad.transaction_type ||
        '-'
    )}
    ·
    ${escapeHtml(
        ad.location ||
        ''
    )}
</div>

</div>


<span
    class="ad-status ${getStatusClass(
        ad.status
    )}"
>
    ${getStatusLabel(
        ad.status
    )}
</span>

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۱. اطلاعات پایه
</div>

${base}

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۲. اطلاعات تماس مالک
</div>

${contact}

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۳. مشخصات ${escapeHtml(type)}
</div>

${
    specItems.length
        ? renderKVGrid(
            specItems
        )
        : `
<div class="detail-muted">
    مشخصات اختصاصی ثبت نشده است.
</div>
`
}

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۴. قیمت و شرایط معامله
</div>

${
    price ||
    `
<div class="detail-muted">
    اطلاعات قیمت ثبت نشده است.
</div>
`
}

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۵. امکانات
</div>

<div class="detail-tags">
    ${amen}
</div>

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۶. تصاویر
</div>

${renderDetailGallery(ad)}

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۷. توضیحات
</div>

<div class="detail-description">

${
    escapeHtml(
        ad.description ||
        'توضیحاتی ثبت نشده است.'
    ).replace(
        /\n/g,
        '<br>'
    )
}

</div>

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۸. زمان‌ها
</div>

${renderKVGrid(

    [

        [
            'تاریخ ثبت',
            ad.created_at ||
                '-'
        ],

        [
            'آخرین ویرایش',
            ad.updated_at ||
                '-'
        ],

        [
            'انتشار تصاویر',
            String(
                ad.publish_photos ||
                'yes'
            ) === 'yes'
                ? 'کاربر موافق بوده'
                : 'کاربر مخالف بوده'
        ],

        [
            'تعداد تصاویر',
            normalizeJsonArray(
                ad.images
            ).length
        ],

        [
            'تصاویر انتخاب‌شده',
            normalizeJsonArray(
                ad.selected_images
            ).length
        ]

    ]

)}

</div>


<div class="detail-actions">

<button
    class="btn-secondary"
    onclick="
        openAdEditModal('${String(
            ad.id
        ).replace(
            /'/g,
            "\\'"
        )}');
        closeModal('adDetailModal')
    "
>
    ✏️ ویرایش کامل
</button>


<button
    class="btn-primary-full"
    style="width:auto;padding:0 22px"
    onclick="publishToTelegram('${String(
        ad.id
    ).replace(
        /'/g,
        "\\'"
    )}')"
>
    📢 انتشار در تلگرام
</button>


<button
    class="btn-primary-full"
    style="width:auto;padding:0 22px;background:linear-gradient(135deg,#2C4A7C,#1E3557)!important;"
    onclick="publishToBale('${String(
        ad.id
    ).replace(
        /'/g,
        "\\'"
    )}')"
>
    💬 انتشار در بله
</button>

</div>

</div>
`;


    document
        .getElementById(
            'adDetailModal'
        )
        .classList.add(
            'active'
        );
}


// ==============================================
// ویرایش کامل آگهی
// ==============================================

const editFieldDefs = {

    'آپارتمان': [

        [
            'area_apt',
            'متراژ',
            'number'
        ],

        [
            'floor',
            'طبقه',
            'text'
        ],

        [
            'unit',
            'شماره واحد',
            'text'
        ],

        [
            'total_units',
            'کل واحدها',
            'number'
        ],

        [
            'rooms_apt',
            'تعداد اتاق',
            'select',
            [
                '۱',
                '۲',
                '۳',
                '۴',
                '۵',
                '۶'
            ]
        ],

        [
            'year_apt',
            'سال ساخت',
            'text'
        ],

        [
            'flooring_apt',
            'نوع پوشش کف',
            'text'
        ],

        [
            'cabinet_apt',
            'نوع کابینت',
            'text'
        ],

        [
            'cooling_apt',
            'سیستم سرمایش',
            'text'
        ],

        [
            'heating_apt',
            'سیستم گرمایش',
            'text'
        ]

    ],


    'ویلا': [

        [
            'land_villa',
            'متراژ زمین',
            'number'
        ],

        [
            'built_villa',
            'زیربنا',
            'number'
        ],

        [
            'rooms_villa',
            'تعداد اتاق',
            'select',
            [
                '۱',
                '۲',
                '۳',
                '۴',
                '۵',
                '۶'
            ]
        ],

        [
            'year_villa',
            'سال ساخت',
            'text'
        ],

        [
            'flooring_villa',
            'نوع پوشش کف',
            'text'
        ],

        [
            'cabinet_villa',
            'نوع کابینت',
            'text'
        ],

        [
            'cooling_villa',
            'سیستم سرمایش',
            'text'
        ],

        [
            'heating_villa',
            'سیستم گرمایش',
            'text'
        ]

    ],


    'زمین': [

        [
            'land_area',
            'مساحت زمین',
            'number'
        ],

        [
            'land_usage',
            'نوع کاربری',
            'text'
        ],

        [
            'land_type',
            'نوع زمین',
            'select',
            [
                'مسکونی',
                'تجاری',
                'اداری',
                'کشاورزی',
                'باغی'
            ]
        ],

        [
            'land_width',
            'عرض زمین',
            'number'
        ],

        [
            'land_length',
            'طول زمین',
            'number'
        ],

        [
            'land_front_width',
            'عرض بر',
            'number'
        ],

        [
            'land_blocks',
            'تعداد بر',
            'number'
        ],

        [
            'land_direction',
            'جهت ملک',
            'text'
        ],

        [
            'land_shape',
            'شکل زمین',
            'text'
        ],

        [
            'land_deed_status',
            'وضعیت سند',
            'text'
        ],

        [
            'land_deed_type',
            'نوع سند',
            'text'
        ],

        [
            'land_division_status',
            'وضعیت تفکیک',
            'text'
        ],

        [
            'land_setback_status',
            'وضعیت عقب‌نشینی',
            'text'
        ],

        [
            'land_ownership',
            'وضعیت مالکیت',
            'text'
        ]

    ],


    'باغ': [

        [
            'garden_area',
            'مساحت باغ',
            'number'
        ],

        [
            'tree_count',
            'تعداد درختان',
            'number'
        ],

        [
            'tree_types',
            'نوع درختان',
            'text'
        ],

        [
            'tree_age',
            'سن درختان',
            'text'
        ],

        [
            'irrigation_type',
            'نوع آبیاری',
            'text'
        ],

        [
            'water_source',
            'منبع آب',
            'text'
        ],

        [
            'water_share',
            'سهم آب',
            'text'
        ],

        [
            'has_well',
            'چاه آب',
            'boolean'
        ],

        [
            'has_pond',
            'استخر آب',
            'boolean'
        ],

        [
            'has_building',
            'بنا / خانه باغ',
            'boolean'
        ],

        [
            'building_area',
            'متراژ بنا',
            'number'
        ],

        [
            'document_type',
            'نوع سند',
            'text'
        ]

    ],


    'اداری': [

        [
            'office_area',
            'متراژ واحد',
            'number'
        ],

        [
            'office_floor',
            'طبقه',
            'text'
        ],

        [
            'office_units_per_floor',
            'تعداد واحد در طبقه',
            'number'
        ],

        [
            'office_rooms',
            'تعداد اتاق',
            'select',
            [
                '۱',
                '۲',
                '۳',
                '۴',
                '۵',
                '۶'
            ]
        ],

        [
            'office_year',
            'سال ساخت',
            'text'
        ],

        [
            'office_condition',
            'وضعیت واحد',
            'text'
        ],

        [
            'office_orientation',
            'موقعیت واحد',
            'text'
        ],

        [
            'office_usage',
            'کاربری',
            'text'
        ]

    ],


    'تجاری': [

        [
            'area_comm',
            'متراژ',
            'number'
        ],

        [
            'front_comm',
            'بر مغازه',
            'number'
        ],

        [
            'floor_comm',
            'پوشش کف',
            'text'
        ],

        [
            'wall_comm',
            'پوشش دیوارها',
            'text'
        ],

        [
            'cabinet_comm',
            'نوع کابینت',
            'text'
        ],

        [
            'cooling_comm',
            'سیستم سرمایش',
            'text'
        ],

        [
            'heating_comm',
            'سیستم گرمایش',
            'text'
        ],

        [
            'has_balcony',
            'بالکن دارد',
            'boolean'
        ],

        [
            'has_basement',
            'زیرزمین دارد',
            'boolean'
        ],

        [
            'balcony_comm',
            'متراژ بالکن',
            'number'
        ],

        [
            'basement_comm',
            'متراژ زیرزمین',
            'number'
        ],

        [
            'location_type_1',
            'موقعیت ملک تجاری',
            'text'
        ],

        [
            'location_type_2',
            'موقعیت خیابان / گذر',
            'text'
        ],

        [
            'jobs_comm',
            'مناسب برای مشاغل',
            'text'
        ]

    ]

};


function getEditDetailValue(
    details,
    key
) {

    return getPropertyValue(
        details,
        key
    );
}


function buildEditPropertyFields(
    type,
    details
) {

    const defs =
        editFieldDefs[type] ||
        [];


    return defs
        .map(
            ([
                key,
                label,
                control,
                opts
            ]) => {

                const val =
                    getEditDetailValue(
                        details,
                        key
                    );


                if (
                    control ===
                    'boolean'
                ) {

                    return `

<div class="edit-field">

<label>
    ${escapeHtml(label)}
</label>

<label class="edit-bool">

<input
    type="checkbox"
    id="editDetail_${key}"
    data-detail-key="${key}"
    ${
        prettyBool(val) ===
        'دارد'
            ? 'checked'
            : ''
    }
>

<span>
    دارد
</span>

</label>

</div>

`;
                }


                if (
                    control ===
                    'select'
                ) {

                    return `

<div class="edit-field">

<label>
    ${escapeHtml(label)}
</label>

<select
    id="editDetail_${key}"
    data-detail-key="${key}"
>

<option value="">
    انتخاب کنید
</option>

${
    opts
        .map(
            o =>
                `
<option
    value="${escapeHtml(o)}"
    ${
        String(val) ===
        String(o)
            ? 'selected'
            : ''
    }
>
    ${escapeHtml(o)}
</option>
`
        )
        .join('')
}

</select>

</div>

`;
                }


                return `

<div class="edit-field">

<label>
    ${escapeHtml(label)}
</label>

<input
    id="editDetail_${key}"
    data-detail-key="${key}"
    type="text"
    value="${editEsc(val)}"
>

</div>

`;
            }
        )
        .join('');
}


function collectEditDetails() {

    const type =
        document.getElementById(
            'editAdPropertyType'
        ).value;


    const details =
        normalizeJsonObject(
            window.__editDetails ||
            {}
        );


    (
        editFieldDefs[type] ||
        []
    ).forEach(
        ([key]) => {

            const el =
                document.getElementById(
                    'editDetail_' +
                    key
                );


            if (!el) {
                return;
            }


            if (
                el.type ===
                'checkbox'
            ) {

                details[key] =
                    el.checked
                        ? '1'
                        : '0';

            } else {

                details[key] =
                    el.value.trim();
            }
        }
    );


    return details;
}


function buildEditAmenities(
    type,
    arr
) {

    const options =
        propertyAmenities[type] ||
        [];


    const values =
        Array.isArray(arr)
            ? arr
            : [];


    const known =
        options
            .map(
                x =>
                    `

<label class="edit-check">

<input
    class="edit-amenity"
    type="checkbox"
    value="${editEsc(x)}"
    ${
        values.includes(x)
            ? 'checked'
            : ''
    }
>

<span>
    ${editEsc(x)}
</span>

</label>

`
            )
            .join('');


    const extra =
        values.filter(
            x =>
                !options.includes(x)
        );


    return known +

        `

<div class="edit-field full">

<label>
    امکانات سفارشی
</label>

<input
    id="editCustomAmenities"
    value="${editEsc(
        extra.join(', ')
    )}"
    placeholder="در صورت نیاز با ویرگول جدا کنید"
>

</div>

`;
}


function buildEditImages(ad) {

    const imgs =
        normalizeJsonArray(
            ad.images
        );


    const selected =
        normalizeJsonArray(
            ad.selected_images
        );


    if (!imgs.length) {

        return `
<div class="detail-empty">
    هیچ تصویر ارسالی برای این آگهی وجود ندارد.
</div>
`;
    }


    return imgs
        .map(
            (
                src,
                i
            ) => `

<div
    class="manage-image ${
        selected.includes(src)
            ? 'selected'
            : ''
    }"
>

<img
    src="${escapeHtml(
        imageUrl(src)
    )}"
    alt="تصویر ${i + 1}"
>


<div class="manage-image-footer">

<strong>
    تصویر ${i + 1}
</strong>


<label>

<input
    class="edit-image-select"
    type="checkbox"
    value="${editEsc(src)}"
    ${
        selected.includes(src)
            ? 'checked'
            : ''
    }

    onchange="
        this
        .closest('.manage-image')
        ?.classList.toggle(
            'selected',
            this.checked
        )
    "
>

انتخاب برای انتشار

</label>

</div>

</div>

`
        )
        .join('');
}


function switchEditPane(
    id,
    btn
) {

    document
        .querySelectorAll(
            '#adEditModal .edit-tab'
        )
        .forEach(
            x =>
                x.classList.remove(
                    'active'
                )
        );


    document
        .querySelectorAll(
            '#adEditModal .edit-pane'
        )
        .forEach(
            x =>
                x.classList.remove(
                    'active'
                )
        );


    if (btn) {

        btn.classList.add(
            'active'
        );
    }


    document
        .getElementById(id)
        ?.classList.add(
            'active'
        );
}


function updateEditPriceSections() {

    const t =
        document.getElementById(
            'editAdTransactionType'
        )?.value ||
        '';


    document
        .querySelectorAll(
            '#adEditModal .edit-price-box'
        )
        .forEach(
            x =>
                x.classList.remove(
                    'active'
                )
        );


    document
        .getElementById(
            'editPrice_' +
            (
                t === 'فروش'
                    ? 'sell'
                    : t === 'اجاره'
                        ? 'rent'
                        : 'presell'
            )
        )
        ?.classList.add(
            'active'
        );
}


function openAdEditModal(
    id
) {

    const ad =
        adsData.find(
            a =>
                String(a.id) ===
                String(id)
        );


    if (!ad) {

        alert(
            'آگهی یافت نشد'
        );

        return;
    }


    window.__editAdId =
        ad.id;


    window.__editDetails =
        normalizeJsonObject(
            ad.property_details
        );


    window.__editAmenities =
        normalizeJsonArray(
            ad.amenities
        );


    const images =
        normalizeJsonArray(
            ad.images
        );


    const selected =
        normalizeJsonArray(
            ad.selected_images
        );


    const userChoice =
        String(
            ad.publish_photos ||
            'yes'
        ) === 'yes';


    const transaction =
        ad.transaction_type ||
        'فروش';


    document.getElementById(
        'adEditContent'
    ).innerHTML = `

<div class="edit-pro-shell">

<div class="edit-pro-head">

<div>

<h2>
    ویرایش کامل آگهی
</h2>

<p>

کد:
<b>
    ${editEsc(
        ad.ad_id ||
        ad.id
    )}
</b>

·

${editEsc(
    ad.property_type ||
    '-'
)}

·

${editEsc(
    ad.location ||
    '-'
)}

</p>

</div>


<span
    class="ad-status ${getStatusClass(
        ad.status
    )}"
>
    ${getStatusLabel(
        ad.status
    )}
</span>

</div>


<div class="edit-tabs">

<button
    class="edit-tab active"
    onclick="switchEditPane('editBase',this)"
>
    اطلاعات پایه
</button>

<button
    class="edit-tab"
    onclick="switchEditPane('editSpecs',this)"
>
    مشخصات ملک
</button>

<button
    class="edit-tab"
    onclick="switchEditPane('editPrice',this)"
>
    قیمت
</button>

<button
    class="edit-tab"
    onclick="switchEditPane('editAmenities',this)"
>
    امکانات و توضیحات
</button>

<button
    class="edit-tab"
    onclick="switchEditPane('editImages',this)"
>
    تصاویر و انتشار
</button>

</div>


<div class="edit-pro-body">


<section
    class="edit-pane active"
    id="editBase"
>

<div class="edit-section">

<h3>
    اطلاعات آگهی
</h3>


<div class="edit-grid">

<div class="edit-field full">

<label>
    عنوان آگهی
</label>

<input
    id="editAdTitle"
    value="${editEsc(
        ad.title
    )}"
>

</div>


<div class="edit-field">

<label>
    نوع معامله
</label>

<select
    id="editAdTransactionType"
>

<option
    value="فروش"
    ${
        transaction ===
        'فروش'
            ? 'selected'
            : ''
    }
>
    خرید و فروش
</option>

<option
    value="اجاره"
    ${
        transaction ===
        'اجاره'
            ? 'selected'
            : ''
    }
>
    اجاره
</option>

<option
    value="پیش فروش"
    ${
        transaction ===
        'پیش فروش'
            ? 'selected'
            : ''
    }
>
    پیش فروش
</option>

</select>

</div>


<div class="edit-field">

<label>
    نوع ملک
</label>

<select
    id="editAdPropertyType"
>

${
    Object.keys(
        editFieldDefs
    )
        .map(
            x =>
                `
<option
    value="${x}"
    ${
        ad.property_type ===
        x
            ? 'selected'
            : ''
    }
>
    ${x}
</option>
`
        )
        .join('')
}

</select>

</div>


<div class="edit-field">

<label>
    وضعیت آگهی
</label>

<select
    id="editAdStatus"
>

${
    [
        [
            'pending',
            'در انتظار تایید'
        ],
        [
            'published',
            'منتشر شده'
        ],
        [
            'sold',
            'فروخته شده'
        ],
        [
            'suspended',
            'معلق'
        ],
        [
            'rejected',
            'رد شده'
        ],
        [
            'price-pending',
            'قیمت در انتظار'
        ]
    ]
        .map(
            ([
                v,
                l
            ]) =>
                `
<option
    value="${v}"
    ${
        ad.status ===
        v
            ? 'selected'
            : ''
    }
>
    ${l}
</option>
`
        )
        .join('')
}

</select>

</div>


<div class="edit-field">

<label>
    محله / محدوده
</label>

<input
    id="editAdLocation"
    value="${editEsc(
        ad.location
    )}"
>

</div>


<div class="edit-field full">

<label>
    آدرس دقیق
</label>

<textarea
    id="editAdAddress"
>${editEsc(
    ad.address
)}</textarea>

</div>

</div>

</div>


<div class="edit-section">

<h3>
    اطلاعات مالک
</h3>


<div class="edit-grid">


<div class="edit-field">

<label>
    جنسیت
</label>

<select
    id="editAdGender"
>

<option
    value="آقا"
    ${
        ad.gender ===
        'آقا'
            ? 'selected'
            : ''
    }
>
    آقا
</option>

<option
    value="خانم"
    ${
        ad.gender ===
        'خانم'
            ? 'selected'
            : ''
    }
>
    خانم
</option>

</select>

</div>


<div class="edit-field">

<label>
    نام خانوادگی
</label>

<input
    id="editAdLastName"
    value="${editEsc(
        ad.last_name
    )}"
>

</div>


<div class="edit-field">

<label>
    شماره تماس
</label>

<input
    id="editAdPhone"
    value="${editEsc(
        ad.phone
    )}"
    dir="ltr"
>

</div>


<div class="edit-field">

<label>
    کد آگهی
</label>

<input
    id="editAdCode"
    value="${editEsc(
        ad.ad_id ||
        ad.id
    )}"
>

</div>


</div>

</div>

</section>


<section
    class="edit-pane"
    id="editSpecs"
>

<div class="edit-section">

<h3>
    مشخصات اختصاصی
    ${editEsc(
        ad.property_type ||
        'ملک'
    )}
</h3>


<div
    class="edit-grid"
    id="editPropertyFields"
>

${buildEditPropertyFields(
    ad.property_type ||
    'آپارتمان',
    window.__editDetails
)}

</div>

</div>

</section>


<section
    class="edit-pane"
    id="editPrice"
>

<div class="edit-section">

<h3>
    قیمت و شرایط معامله
</h3>


<div
    id="editPrice_sell"
    class="edit-price-box ${
        transaction ===
        'فروش'
            ? 'active'
            : ''
    }"
>

<div class="edit-grid">


<div class="edit-field">

<label>
    قیمت فروش
</label>

<input
    id="editAdPriceSell"
    value="${editEsc(
        normalizeNumberText(ad.price_sell)
    )}"
>

</div>


<div class="edit-field">

<label>
    قیمت
</label>

<select
    id="editAdPriceCondition"
>

<option
    value="negotiable"
    ${
        ad.price_condition !==
        'fixed'
            ? 'selected'
            : ''
    }
>
    قابل مذاکره
</option>

<option
    value="fixed"
    ${
        ad.price_condition ===
        'fixed'
            ? 'selected'
            : ''
    }
>
    مقطوع
</option>

</select>

</div>

</div>

</div>


<div
    id="editPrice_rent"
    class="edit-price-box ${
        transaction ===
        'اجاره'
            ? 'active'
            : ''
    }"
>

<div class="edit-grid">


<div class="edit-field">

<label>
    ودیعه
</label>

<input
    id="editAdDeposit"
    value="${editEsc(
        normalizeNumberText(ad.deposit)
    )}"
>

</div>


<div class="edit-field">

<label>
    اجاره ماهانه
</label>

<input
    id="editAdRentMonthly"
    value="${editEsc(
        normalizeNumberText(ad.rent_monthly)
    )}"
>

</div>


<div class="edit-field">

<label>
    رهن کامل
</label>

<input
    id="editAdFullRent"
    value="${editEsc(
        normalizeNumberText(ad.full_rent)
    )}"
>

</div>


<div class="edit-field full">

<label>

<input
    type="checkbox"
    id="editFullRentEnabled"
    ${
        String(
            ad.full_rent_enabled
        ) === '1'
            ? 'checked'
            : ''
    }
>

این آگهی رهن کامل است

</label>

</div>

</div>

</div>


<div
    id="editPrice_presell"
    class="edit-price-box ${
        transaction ===
        'پیش فروش'
            ? 'active'
            : ''
    }"
>

<div class="edit-grid">


<div class="edit-field">

<label>
    قیمت کل
</label>

<input
    id="editAdTotalPrice"
    value="${editEsc(
        normalizeNumberText(ad.total_price)
    )}"
>

</div>


<div class="edit-field">

<label>
    پیش‌پرداخت
</label>

<input
    id="editAdDownPayment"
    value="${editEsc(
        normalizeNumberText(ad.down_payment)
    )}"
>

</div>


<div class="edit-field full">

<label>
    شرایط پرداخت
</label>

<textarea
    id="editAdPaymentTerms"
>${editEsc(
    ad.payment_terms
)}</textarea>

</div>

</div>

</div>

<div class="edit-section" style="margin-top:10px;">

<label class="edit-bool" style="width:fit-content;">
<input
    type="checkbox"
    id="editAdPriceHidden"
    ${ad.price_hidden ? 'checked' : ''}
>
<span>نمایش قیمت برای کاربران مخفی شود و به‌جای آن «برای استعلام قیمت تماس بگیرید» نمایش داده شود</span>
</label>

</div>

</div>

</section>


<section
    class="edit-pane"
    id="editAmenities"
>

<div class="edit-section">

<h3>
    امکانات ملک
</h3>


<div
    class="edit-checks"
    id="editAmenitiesFields"
>

${buildEditAmenities(
    ad.property_type ||
    'آپارتمان',
    window.__editAmenities
)}

</div>

</div>


<div class="edit-section">

<h3>
    توضیحات
</h3>


<div class="edit-field">

<textarea
    id="editAdDescription"
    class="edit-description"
>${editEsc(
    ad.description
)}</textarea>

</div>

</div>

</section>


<section
    class="edit-pane"
    id="editImages"
>

<div class="edit-section">

<h3>
    مدیریت تصاویر
</h3>


<div
    class="image-choice-banner ${
        userChoice
            ? 'yes'
            : 'no'
    }"
>

<b>
    انتخاب کاربر:
</b>

${
    userChoice
        ? 'کاربر موافق انتشار تصاویر است.'
        : 'کاربر درخواست کرده تصاویر همراه آگهی منتشر نشوند.'
}

<br>

<small>
    این انتخاب به معنی انتخاب عکس نیست؛ انتخاب عکس نهایی با ادمین است.
</small>

</div>


<div class="admin-image-controls">

<label class="edit-check">

<input
    type="checkbox"
    id="editPublishPhotos"
    ${
        userChoice
            ? 'checked'
            : ''
    }
>

انتشار تصاویر در آگهی

</label>


<button
    type="button"
    class="btn-secondary"
    onclick="selectAllAdminImages(true)"
>
    انتخاب همه
</button>


<button
    type="button"
    class="btn-secondary"
    onclick="selectAllAdminImages(false)"
>
    لغو همه
</button>

</div>


<div class="edit-note" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">

    <label class="btn-secondary" style="cursor:pointer; margin:0;">
        ➕ افزودن عکس جدید
        <input
            type="file"
            id="adminAddImageInput"
            accept="image/jpeg,image/png,image/webp,image/gif"
            multiple
            style="display:none;"
            onchange="uploadAdminAdImage('${editEsc(String(ad.id))}', this)"
        >
    </label>

    <span id="adminAddImageStatus" style="font-size:12px; color:var(--text-secondary);"></span>

</div>


<div class="edit-note">

${
    images.length
        ? 'تمام تصاویر ارسالی کاربر در پایین نمایش داده می‌شوند. فقط تصاویر تیک‌خورده به عنوان عکس انتشار ذخیره می‌شوند.'
        : 'این کاربر تصویری ارسال نکرده است.'
}

</div>


<div class="manage-image-grid">

${buildEditImages(ad)}

</div>

</div>

</section>


</div>


<div class="edit-pro-footer">

<span id="editImageCounter">

${selected.length}
تصویر برای انتشار انتخاب شده

</span>


<div>

<button
    class="btn-secondary"
    onclick="closeModal('adEditModal')"
>
    انصراف
</button>


<button
    class="btn-primary-full"
    style="display:inline-flex;width:auto;padding:0 25px"
    onclick="saveAdEdit()"
>
    ذخیره تغییرات
</button>

</div>

</div>

</div>

`;


    document
        .getElementById(
            'editAdPropertyType'
        )
        .addEventListener(
            'change',
            () => {

                window.__editDetails =
                    {};


                document
                    .getElementById(
                        'editPropertyFields'
                    )
                    .innerHTML =
                        buildEditPropertyFields(
                            document
                                .getElementById(
                                    'editAdPropertyType'
                                )
                                .value,
                            {}
                        );


                document
                    .getElementById(
                        'editAmenitiesFields'
                    )
                    .innerHTML =
                        buildEditAmenities(
                            document
                                .getElementById(
                                    'editAdPropertyType'
                                )
                                .value,
                            []
                        );
            }
        );


    document
        .getElementById(
            'editAdTransactionType'
        )
        .addEventListener(
            'change',
            updateEditPriceSections
        );


    document
        .getElementById(
            'editPublishPhotos'
        )
        .addEventListener(
            'change',
            updateEditImageCounter
        );


    document
        .getElementById(
            'adEditModal'
        )
        .classList.add(
            'active'
        );


    updateEditImageCounter();
}


function selectAllAdminImages(
    flag
) {

    document
        .querySelectorAll(
            '#adEditModal .edit-image-select'
        )
        .forEach(
            x => {

                x.checked =
                    flag;


                x
                    .closest(
                        '.manage-image'
                    )
                    ?.classList.toggle(
                        'selected',
                        flag
                    );
            }
        );


    updateEditImageCounter();
}


function updateEditImageCounter() {

    const n =
        document
            .querySelectorAll(
                '#adEditModal .edit-image-select:checked'
            )
            .length;


    const el =
        document.getElementById(
            'editImageCounter'
        );


    if (el) {

        el.textContent =
            `${n} تصویر برای انتشار انتخاب شده`;
    }
}


async function adminCreateNewAd() {

    if (!confirm('یک آگهی جدید و خالی ساخته می‌شود تا از همین‌جا تکمیلش کنی. ادامه بدم؟')) {
        return;
    }

    try {

        const response = await fetch('admin-create-ad.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                title: 'آگهی جدید',
                transaction_type: 'فروش',
                property_type: 'آپارتمان'
            })
        });

        const result = await response.json();

        if (!result.success || !result.ad) {
            alert('❌ ' + (result.message || 'ساخت آگهی جدید ناموفق بود.'));
            return;
        }

        const newAd = result.ad;
        newAd.images = [];
        newAd.selected_images = [];

        adsData.unshift(newAd);
        renderAds();
        renderDashboard();

        openAdEditModal(newAd.id);

    } catch (e) {
        alert('❌ خطا در ارتباط با سرور.');
    }
}


async function uploadAdminAdImage(adId, inputEl) {

    const files = inputEl.files;
    if (!files || !files.length) return;

    const statusEl = document.getElementById('adminAddImageStatus');
    if (statusEl) statusEl.textContent = 'در حال آپلود...';

    const ad =
        adsData.find(
            a => String(a.id) === String(adId)
        );

    if (!ad) {
        if (statusEl) statusEl.textContent = 'آگهی پیدا نشد.';
        return;
    }

    const formData = new FormData();
    formData.append('ad_id', adId);
    for (let i = 0; i < files.length; i++) {
        formData.append('images[]', files[i]);
    }

    try {

        const response = await fetch('admin-upload-image.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (!result.success) {
            if (statusEl) {
                statusEl.textContent =
                    '❌ ' + (result.errors && result.errors.length ? result.errors.join(' | ') : 'آپلود ناموفق بود.');
            }
            return;
        }

        const currentImages = normalizeJsonArray(ad.images);
        const newImages = result.images || [];

        ad.images = currentImages.concat(newImages);

        const currentSelected = normalizeJsonArray(ad.selected_images);
        ad.selected_images = currentSelected.concat(newImages);

        const grid = document.querySelector('#adEditModal .manage-image-grid');
        if (grid) {
            const startIndex = currentImages.length;
            newImages.forEach((src, i) => {
                const wrap = document.createElement('div');
                wrap.className = 'manage-image selected';
                wrap.innerHTML = `
                    <img src="${escapeHtml(imageUrl(src))}" alt="تصویر ${startIndex + i + 1}">
                    <div class="manage-image-footer">
                        <strong>تصویر ${startIndex + i + 1}</strong>
                        <label>
                            <input class="edit-image-select" type="checkbox" value="${editEsc(src)}" checked
                                onchange="this.closest('.manage-image')?.classList.toggle('selected', this.checked)">
                            انتخاب برای انتشار
                        </label>
                    </div>`;
                grid.appendChild(wrap);
            });
        }

        if (statusEl) {
            statusEl.textContent = `✅ ${newImages.length} تصویر اضافه شد.` +
                (result.errors && result.errors.length ? ' (' + result.errors.join(' | ') + ')' : '');
        }

        inputEl.value = '';

    } catch (e) {
        if (statusEl) statusEl.textContent = 'خطا در ارتباط با سرور.';
    }
}


async function saveAdEdit() {

    const ad =
        adsData.find(
            a =>
                String(a.id) ===
                String(
                    window.__editAdId
                )
        );


    if (!ad) {

        alert(
            'آگهی یافت نشد'
        );

        return;
    }


    const oldPublish =
        String(
            ad.publish_photos ||
            'yes'
        );


    ad.title =
        document
            .getElementById(
                'editAdTitle'
            )
            .value
            .trim();


    ad.transaction_type =
        document
            .getElementById(
                'editAdTransactionType'
            )
            .value;


    ad.property_type =
        document
            .getElementById(
                'editAdPropertyType'
            )
            .value;


    ad.status =
        document
            .getElementById(
                'editAdStatus'
            )
            .value;


    ad.location =
        document
            .getElementById(
                'editAdLocation'
            )
            .value
            .trim();


    ad.address =
        document
            .getElementById(
                'editAdAddress'
            )
            .value
            .trim();


    ad.gender =
        document
            .getElementById(
                'editAdGender'
            )
            .value;


    ad.last_name =
        document
            .getElementById(
                'editAdLastName'
            )
            .value
            .trim();


    ad.phone =
        document
            .getElementById(
                'editAdPhone'
            )
            .value
            .trim();


    ad.ad_id =
        document
            .getElementById(
                'editAdCode'
            )
            .value
            .trim() ||
        ad.ad_id ||
        ad.id;


    ad.price_sell =
        document
            .getElementById(
                'editAdPriceSell'
            )
            ?.value
            .trim() ||
        '';


    ad.price_condition =
        document
            .getElementById(
                'editAdPriceCondition'
            )
            ?.value ||
        '';


    ad.deposit =
        document
            .getElementById(
                'editAdDeposit'
            )
            ?.value
            .trim() ||
        '';


    ad.rent_monthly =
        document
            .getElementById(
                'editAdRentMonthly'
            )
            ?.value
            .trim() ||
        '';


    ad.full_rent =
        document
            .getElementById(
                'editAdFullRent'
            )
            ?.value
            .trim() ||
        '';


    ad.full_rent_enabled =
        document
            .getElementById(
                'editFullRentEnabled'
            )
            ?.checked
            ? '1'
            : '0';


    ad.total_price =
        document
            .getElementById(
                'editAdTotalPrice'
            )
            ?.value
            .trim() ||
        '';


    ad.down_payment =
        document
            .getElementById(
                'editAdDownPayment'
            )
            ?.value
            .trim() ||
        '';


    ad.payment_terms =
        document
            .getElementById(
                'editAdPaymentTerms'
            )
            ?.value
            .trim() ||
        '';


    ad.price_hidden =
        !!document
            .getElementById(
                'editAdPriceHidden'
            )
            ?.checked;


    ad.property_details =
        collectEditDetails();


    ad.amenities =
        Array.from(
            document.querySelectorAll(
                '#adEditModal .edit-amenity:checked'
            )
        )
        .map(
            x =>
                x.value
        );


    const custom =
        (
            document.getElementById(
                'editCustomAmenities'
            )?.value ||
            ''
        )
        .split(',')
        .map(
            x =>
                x.trim()
        )
        .filter(Boolean);


    ad.amenities =
        [
            ...new Set(
                [
                    ...ad.amenities,
                    ...custom
                ]
            )
        ];


    ad.description =
        document
            .getElementById(
                'editAdDescription'
            )
            .value
            .trim();


    ad.publish_photos =
        document
            .getElementById(
                'editPublishPhotos'
            )
            .checked
            ? 'yes'
            : 'no';


    ad.selected_images =
        Array.from(
            document.querySelectorAll(
                '#adEditModal .edit-image-select:checked'
            )
        )
        .map(
            x =>
                x.value
        );


    if (
        ad.publish_photos ===
            'yes' &&
        normalizeJsonArray(
            ad.images
        ).length &&
        ad.selected_images.length ===
            0
    ) {

        switchEditPane(
            'editImages',
            document.querySelector(
                '#adEditModal .edit-tab:last-child'
            )
        );


        alert(
            'برای انتشار تصاویر، حداقل یک تصویر را انتخاب کنید.'
        );


        return;
    }


    if (
        ad.publish_photos ===
        'no'
    ) {

        ad.selected_images =
            [];
    }


    ad.updated_at =
        new Date()
            .toISOString()
            .slice(0,19)
            .replace(
                'T',
                ' '
            );


    const ok = await saveAdsToFile();
    if (!ok) return;


    closeModal(
        'adEditModal'
    );


    renderAds();

    renderDashboard();


    alert(
        oldPublish ===
            ad.publish_photos
            ?

            '✅ آگهی با موفقیت ویرایش شد.'

            :

            '✅ آگهی ذخیره شد؛ انتخاب ادمین درباره انتشار تصاویر اعمال شد.'
    );
}


// ==============================================
// توابع کمکی آگهی
// ==============================================

function getStatusLabel(
    status
) {

    const labels = {

        pending:
            'در انتظار',

        published:
            'منتشر شده',

        sold:
            'فروخته شده',

        suspended:
            'معلق',

        rejected:
            'رد شده'

    };


    return labels[status] ||
        status;
}


function getStatusClass(
    status
) {

    const classes = {

        pending:
            'status-pending',

        published:
            'status-published',

        sold:
            'status-sold',

        suspended:
            'status-suspended',

        rejected:
            'status-rejected'

    };


    return classes[status] ||
        'status-pending';
}


function getDisplayPrice(
    ad
) {

    if (!ad) {
        return '';
    }


    if (
        ad.price_sell &&
        ad.price_sell !=
            '0'
    ) {

        return (
            '💰 فروش: ' +
            ad.price_sell +
            ' تومان'
        );
    }


    if (
        ad.total_price &&
        ad.total_price !=
            '0'
    ) {

        return (
            '📋 قیمت کل: ' +
            ad.total_price +
            ' تومان'
        );
    }


    if (
        ad.deposit &&
        ad.deposit !=
            '0'
    ) {

        let t =
            '🏠 ودیعه: ' +
            ad.deposit +
            ' تومان';


        if (
            ad.rent_monthly &&
            ad.rent_monthly !=
                '0'
        ) {

            t +=
                ' | اجاره: ' +
                ad.rent_monthly +
                ' تومان';
        }


        return t;
    }


    return '';
}


// ==============================================
// [NEW] مدیریت لوگوی مرکزی ملکینو
// ==============================================

const logoFileInput = document.getElementById('logoFileInput');
const uploadLogoBtn = document.getElementById('uploadLogoBtn');
const fileNameDisplay = document.getElementById('fileNameDisplay');
const logoPreview = document.getElementById('logoPreview');
const uploadProgress = document.getElementById('uploadProgress');
const progressBar = document.getElementById('progressBar');
const logoStatus = document.getElementById('logoStatus');

// نمایش نام فایل و پیش‌نمایش محلی
logoFileInput.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        fileNameDisplay.textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
        const reader = new FileReader();
        reader.onload = function(ev) {
            logoPreview.src = ev.target.result;
        };
        reader.readAsDataURL(file);
        logoStatus.textContent = '';
    } else {
        fileNameDisplay.textContent = '';
    }
});
