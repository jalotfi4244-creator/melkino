/* =========================================================
   تب‌های جدید پنل ادمین ملکینو
   - ربات و کانال
   - تبلیغات
   - تم و رنگ
   - پشتیبان‌گیری
   ========================================================= */

(function () {
    'use strict';

    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[c]));

    function setStatus(id, message, ok) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = message || '';
        el.style.color = ok === true ? 'var(--success)' : (ok === false ? 'var(--danger)' : 'var(--text-secondary)');
    }

    async function postJson(url, payload) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload || {})
        });
        return res.json();
    }

    async function getJson(url) {
        const res = await fetch(url, { cache: 'no-store' });
        return res.json();
    }

    /* =====================================================
       ربات و کانال
       ===================================================== */

    window.loadBotSettings = async function () {
        try {
            const data = await getJson('admin-bots.php?action=get');
            if (!data.success) return;
            const s = data.settings || {};
            const set = (id, val) => { const e = document.getElementById(id); if (e) e.value = val || ''; };
            set('botTelegramChannel', s.telegram_channel);
            set('botTelegramUsername', s.telegram_bot_username);
            set('botBaleChannel', s.bale_channel);
            set('botBaleUsername', s.bale_bot_username);
            set('botProxy', s.http_proxy);
            const tg = document.getElementById('botTelegramToken');
            const ba = document.getElementById('botBaleToken');
            if (tg) tg.placeholder = s.telegram_token_masked || 'تنظیم نشده';
            if (ba) ba.placeholder = s.bale_token_masked || 'تنظیم نشده';
        } catch (e) {
            setStatus('botSettingsStatus', 'خطا در دریافت تنظیمات.', false);
        }
    };

    window.saveBotSettings = async function () {
        const val = id => { const e = document.getElementById(id); return e ? e.value.trim() : ''; };
        setStatus('botSettingsStatus', 'در حال ذخیره...', null);
        try {
            const data = await postJson('admin-bots.php?action=save', {
                telegram_token: val('botTelegramToken'),
                telegram_channel: val('botTelegramChannel'),
                telegram_bot_username: val('botTelegramUsername'),
                bale_token: val('botBaleToken'),
                bale_channel: val('botBaleChannel'),
                bale_bot_username: val('botBaleUsername'),
                http_proxy: val('botProxy')
            });
            setStatus('botSettingsStatus', data.message || '', data.success);
            if (data.success) loadBotSettings();
        } catch (e) {
            setStatus('botSettingsStatus', 'خطا در ارتباط با سرور.', false);
        }
    };

    /**
     * تست اتصال به تلگرام/بله
     *
     * ابتدا از «مرورگرِ ادمین» امتحان می‌شود؛ این برای هاست‌هایی که خروجیِ
     * سرورشان به api.telegram.org مسدود است (مثل InfinityFree) ضروری است،
     * چون در آن حالت تستِ سمتِ سرور همیشه خطا می‌دهد هرچند توکن سالم باشد.
     * اگر مرورگر هم موفق نشد، در نهایت از سرور امتحان می‌شود.
     */
    window.testBotConnection = async function (which) {
        const platform = which === 'telegram' ? 'telegram' : 'bale';
        const label = platform === 'telegram' ? 'تلگرام' : 'بله';
        const tokenId = which === 'telegram' ? 'botTelegramToken' : 'botBaleToken';
        const tokenEl = document.getElementById(tokenId);
        const typedToken = tokenEl ? tokenEl.value.trim() : '';

        setStatus('botSettingsStatus', 'در حال تست اتصال...', null);

        // ۱) تلاش از مرورگر
        if (typeof window.melkinoApiCall === 'function') {
            try {
                const res = await window.melkinoApiCall(platform, 'getMe', {}, {
                    token: typedToken || ''
                });

                if (res && res.ok && res.result) {
                    const username = res.result.username ? ('@' + res.result.username) : '';
                    const via = res.via === 'browser' ? 'از طریق مرورگر شما' : 'از طریق سرور';
                    let msg = `✅ اتصال به ${label} برقرار است — ربات: ${username} (${via})`;
                    if (typedToken) {
                        msg += ' — توکن واردشده معتبر است؛ آن را ذخیره کن.';
                    }
                    setStatus('botSettingsStatus', msg, true);
                    return;
                }

                if (res && res.description) {
                    setStatus('botSettingsStatus', `❌ ${label}: ${res.description}`, false);
                    return;
                }
            } catch (e) {
                // ادامه می‌دهیم به مسیر سرور
            }
        }

        // ۲) تلاش از سرور (مسیر پشتیبان)
        const action = which === 'telegram' ? 'test_telegram' : 'test_bale';
        try {
            const data = await postJson('admin-bots.php?action=' + action, { token: typedToken });
            setStatus('botSettingsStatus', data.message || '', data.success);
        } catch (e) {
            setStatus('botSettingsStatus', 'خطا در ارتباط با سرور.', false);
        }
    };

    /**
     * تست دسترسی به کانالِ بله
     *
     * ابتدا از مرورگرِ ادمین تلاش می‌شود (برای هاست‌هایی که ارتباطِ
     * خروجی‌شان با پیام‌رسان‌ها مسدود است) و در صورت عدم موفقیت از سرور.
     *
     * نکته: شناسه‌ی خالی یا پیش‌فرض پیش از ارسال بررسی می‌شود، چون در غیر
     * این صورت بله پاسخِ گمراه‌کننده‌ی
     * «chat_id: must be a valid value» را برمی‌گرداند.
     */
    /* =========================================================
   یافتنِ شناسه‌ی کانال بله
   =========================================================
   وقتی انتشار با خطای «no such group or user» شکست می‌خورد، یعنی
   شناسه‌ای که ادمین وارد کرده برای ربات قابلِ شناسایی نیست. این تابع
   از سرور می‌خواهد گفتگوهایی را که ربات اخیراً دیده است فهرست کند تا
   ادمین شناسه‌ی عددیِ درست را مستقیماً انتخاب کند.
========================================================= */
/* =========================================================
   بررسیِ توکنِ بله
   =========================================================
   خطای «Unauthorized» مربوط به خودِ توکن است، نه شناسه‌ی کانال.
   این تابع از سرور می‌پرسد آیا بله این توکن را قبول دارد یا نه، و
   کدام ربات به آن وصل است.
========================================================= */
window.baleWhoAmI = async function () {
    try {
        var r = await fetch('admin-bots.php?action=bale_whoami', { cache: 'no-store' });
        var j = await r.json();
        var msg = (j && j.message) || 'پاسخ نامعتبر از سرور';
        if (j && j.masked) {
            msg += '\n' + 'توکنِ ذخیره‌شده: ' + j.masked;
        }
        alert((j && j.success ? '✅ ' : '❌ ') + msg);
    } catch (e) {
        alert('خطا در ارتباط با سرور.');
    }
};

window.findBaleChats = async function () {

    var box = document.getElementById('baleChatsBox');
    if (!box) { return; }

    box.style.display = 'block';
    box.innerHTML = '<div style="font-size:12px;color:#666">در حال دریافتِ گفتگوها از بله…</div>';

    function esc(v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function render(list, note) {
        if (!list || !list.length) {
            box.innerHTML = '<div style="font-size:12px;line-height:1.9">' + esc(note || 'موردی پیدا نشد.') + '</div>';
            return;
        }

        var html = '<div style="font-size:12px;margin-bottom:8px">'
                 + esc(note || 'روی شناسه‌ی عددی بزن تا در فیلدِ بالا قرار بگیرد:')
                 + '</div>';

        list.forEach(function (c) {
            var label = esc(c.title || (c.username ? '@' + c.username : c.id));
            html += '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:7px 0;border-bottom:1px solid #eee">'
                  +   '<code dir="ltr" data-id="' + esc(c.id) + '" style="background:#eef3f2;border:1px solid #cfdedc;'
                  +   'border-radius:6px;padding:3px 8px;font-size:12px;cursor:pointer">' + esc(c.id) + '</code>'
                  +   '<span style="font-size:12px">' + label + '</span>'
                  +   (c.type ? '<span style="font-size:11px;color:#888">' + esc(c.type) + '</span>' : '')
                  +   (c.username ? '<span dir="ltr" style="font-size:11px;color:#888">@' + esc(c.username) + '</span>' : '')
                  + '</div>';
        });

        box.innerHTML = html;

        var codes = box.querySelectorAll('code[data-id]');
        Array.prototype.forEach.call(codes, function (el) {
            el.onclick = function () {
                var input = document.getElementById('botBaleChannel');
                if (input) { input.value = el.getAttribute('data-id'); }
                var done = document.createElement('div');
                done.style.cssText = 'font-size:12px;color:#0b5d59;margin-top:8px';
                done.textContent = '✅ شناسه در فیلد قرار گرفت. حالا دکمه‌ی «ذخیره» را بزن، سپس دوباره انتشار را امتحان کن.';
                box.appendChild(done);
            };
        });
    }

    try {
        var r = await fetch('admin-bots.php?action=bale_find_chats', { cache: 'no-store' });
        var j = await r.json();
        render(j && j.chats, (j && j.message) || '');
    } catch (e) {
        box.innerHTML = '<div style="font-size:12px;color:#b00020">خطا در ارتباط با سرور.</div>';
    }
};

window.testBaleChannelConnection = async function () {
        const el = document.getElementById('botBaleChannel');
        const channel = el ? el.value.trim() : '';

        const placeholders = ['', '@\u0622\u06cc\u062f\u06cc_\u06a9\u0627\u0646\u0627\u0644', '\u0622\u06cc\u062f\u06cc_\u06a9\u0627\u0646\u0627\u0644', '@', '-', '0'];
        if (placeholders.indexOf(channel) !== -1) {
            setStatus('botSettingsStatus', 'ابتدا شناسه کانال بله را وارد و ذخیره کن.', false);
            return;
        }

        setStatus('botSettingsStatus', 'در حال بررسی کانال بله...', null);

        // ۱) تلاش از مرورگر
        if (typeof window.melkinoApiCall === 'function') {
            try {
                const res = await window.melkinoApiCall('bale', 'getChat', { chat_id: channel });
                if (res && res.ok && res.result) {
                    const title = res.result.title || channel;
                    const via = res.via === 'browser' ? 'از طریق مرورگر شما' : 'از طریق سرور';
                    setStatus('botSettingsStatus', `✅ کانال بله در دسترس است: ${title} (${via})`, true);
                    return;
                }
                if (res && res.description) {
                    setStatus('botSettingsStatus', `❌ کانال بله: ${res.description}`, false);
                    return;
                }
            } catch (e) {
                // ادامه می‌دهیم به مسیر سرور
            }
        }

        // ۲) تلاش از سرور
        try {
            const data = await postJson('admin-bots.php?action=test_bale_channel', { channel: channel });
            setStatus('botSettingsStatus', data.message || '', data.success);
        } catch (e) {
            setStatus('botSettingsStatus', 'خطا در ارتباط با سرور.', false);
        }
    };

    window.testChannelConnection = async function () {
        const el = document.getElementById('botTelegramChannel');
        const channel = el ? el.value.trim() : '';
        if (!channel) {
            setStatus('botSettingsStatus', 'ابتدا شناسه کانال را وارد کن.', false);
            return;
        }
        setStatus('botSettingsStatus', 'در حال بررسی کانال...', null);

        // ۱) تلاش از مرورگر
        if (typeof window.melkinoApiCall === 'function') {
            try {
                const res = await window.melkinoApiCall('telegram', 'getChat', { chat_id: channel });
                if (res && res.ok && res.result) {
                    const title = res.result.title || channel;
                    const via = res.via === 'browser' ? 'از طریق مرورگر شما' : 'از طریق سرور';
                    setStatus('botSettingsStatus', `✅ کانال در دسترس است: ${title} (${via})`, true);
                    return;
                }
                if (res && res.description) {
                    setStatus('botSettingsStatus', `❌ کانال: ${res.description}`, false);
                    return;
                }
            } catch (e) {
                // ادامه می‌دهیم به مسیر سرور
            }
        }

        // ۲) تلاش از سرور
        try {
            const data = await postJson('admin-bots.php?action=test_channel', { channel: channel });
            setStatus('botSettingsStatus', data.message || '', data.success);
        } catch (e) {
            setStatus('botSettingsStatus', 'خطا در ارتباط با سرور.', false);
        }
    };

    /* =====================================================
       تبلیغات
       ===================================================== */

    window.loadPromotions = async function () {
        const box = document.getElementById('promotionsListContainer');
        if (!box) return;
        box.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-secondary);font-size:13px;">در حال بارگذاری...</div>';

        try {
            const data = await getJson('admin-promotions.php?action=list');
            const rows = data.promotions || [];

            if (!rows.length) {
                box.innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px;">هنوز تبلیغی ثبت نشده است.</div>';
                return;
            }

            const placementLabel = {
                all: 'همه صفحه‌ها', home: 'صفحه اصلی', properties: 'فهرست املاک',
                search: 'نتایج جستجو', vip: 'ملک‌های ویژه'
            };

            box.innerHTML = rows.map(p => `
                <div style="border:1px solid var(--border);border-radius:14px;padding:13px;margin-bottom:10px;">
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;">
                        <div style="flex:1;min-width:180px;">
                            <div style="font-size:14px;font-weight:800;color:var(--text-primary);">${esc(p.title)}</div>
                            <div style="font-size:12px;color:var(--text-secondary);margin-top:4px;line-height:1.8;">
                                محل: ${esc(placementLabel[p.placement] || p.placement)} ·
                                بعد از کارت ${esc(p.position_after)}${Number(p.repeat_every) > 0 ? ' · تکرار هر ' + esc(p.repeat_every) + ' کارت' : ''}
                            </div>
                            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                                بازدید: ${esc(p.views || 0)} · کلیک: ${esc(p.clicks || 0)}
                                ${p.start_date ? ' · شروع: ' + esc(p.start_date) : ''}
                                ${p.end_date ? ' · پایان: ' + esc(p.end_date) : ''}
                            </div>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <button type="button" class="btn-secondary" style="padding:5px 10px;font-size:11px;" data-promo-toggle="${esc(p.id)}" data-active="${p.is_active == 1 ? 1 : 0}">
                                ${p.is_active == 1 ? '⏸ غیرفعال' : '▶️ فعال'}
                            </button>
                            <button type="button" class="btn-secondary" style="padding:5px 10px;font-size:11px;" data-promo-edit="${esc(p.id)}">✏️ ویرایش</button>
                            <button type="button" class="btn-secondary" style="padding:5px 10px;font-size:11px;color:var(--danger);" data-promo-delete="${esc(p.id)}">🗑 حذف</button>
                        </div>
                    </div>
                </div>
            `).join('');
        } catch (e) {
            box.innerHTML = '<div style="color:var(--danger);font-size:13px;">خطا در بارگذاری تبلیغ‌ها.</div>';
        }
    };

    document.addEventListener('click', async function (e) {
        const t = e.target;

        const del = t.closest('[data-promo-delete]');
        if (del) {
            if (!confirm('این تبلیغ حذف شود؟')) return;
            await postJson('admin-promotions.php?action=delete', { id: del.getAttribute('data-promo-delete') });
            loadPromotions();
            return;
        }

        const tog = t.closest('[data-promo-toggle]');
        if (tog) {
            const active = tog.getAttribute('data-active') === '1' ? 0 : 1;
            await postJson('admin-promotions.php?action=toggle', { id: tog.getAttribute('data-promo-toggle'), is_active: active });
            loadPromotions();
            return;
        }

        const ed = t.closest('[data-promo-edit]');
        if (ed) {
            const data = await getJson('admin-promotions.php?action=list');
            const promo = (data.promotions || []).find(x => String(x.id) === String(ed.getAttribute('data-promo-edit')));
            if (promo) openPromotionEditor(promo);
        }
    });

    /* بستنِ مطمئنِ مودال تبلیغ:
       هم کلاس active را برمی‌دارد و هم display را مستقیماً none می‌کند،
       تا به هیچ تابع یا استایل بیرونی وابسته نباشد. */
    window.closePromotionModal = function () {
        const modal = document.getElementById('promotionModal');
        if (!modal) return;
        modal.classList.remove('active');
        modal.style.display = 'none';
    };

    window.openPromotionEditor = function (promo) {
        const modal = document.getElementById('promotionModal');
        if (!modal) return;
        // مودال‌های این پنل با کلاس active نمایش داده می‌شوند (نه style.display)
        modal.style.display = 'flex';
        modal.classList.add('active');

        const set = (id, v) => { const e = document.getElementById(id); if (e) e.value = v ?? ''; };
        set('promoId', promo ? promo.id : '');
        set('promoTitle', promo ? promo.title : '');
        set('promoDescription', promo ? promo.description : '');
        set('promoImageUrl', promo ? promo.image_url : '');
        set('promoLinkUrl', promo ? promo.link_url : '');
        set('promoButtonText', promo ? promo.button_text || 'مشاهده' : 'مشاهده');
        set('promoPlacement', promo ? promo.placement : 'all');
        set('promoPosition', promo ? promo.position_after : 3);
        set('promoRepeat', promo ? promo.repeat_every : 0);
        set('promoActive', promo ? String(promo.is_active) : '1');

        const toLocal = v => v ? String(v).replace(' ', 'T').slice(0, 16) : '';
        set('promoStart', promo ? toLocal(promo.start_date) : '');
        set('promoEnd', promo ? toLocal(promo.end_date) : '');

        const title = document.getElementById('promotionModalTitle');
        if (title) title.textContent = promo ? 'ویرایش تبلیغ' : 'تبلیغ جدید';
        setStatus('promoFormStatus', '', null);
    };

    window.savePromotion = async function () {
        const val = id => { const e = document.getElementById(id); return e ? e.value.trim() : ''; };
        setStatus('promoFormStatus', 'در حال ذخیره...', null);

        try {
            const data = await postJson('admin-promotions.php?action=save', {
                id: val('promoId'),
                title: val('promoTitle'),
                description: val('promoDescription'),
                image_url: val('promoImageUrl'),
                link_url: val('promoLinkUrl'),
                button_text: val('promoButtonText'),
                placement: val('promoPlacement'),
                position_after: val('promoPosition'),
                repeat_every: val('promoRepeat'),
                start_date: val('promoStart').replace('T', ' '),
                end_date: val('promoEnd').replace('T', ' '),
                is_active: val('promoActive') === '1'
            });
            setStatus('promoFormStatus', data.message || '', data.success);
            if (data.success) {
                loadPromotions();
                setTimeout(function () {
                    window.closePromotionModal();
                    setStatus('promoFormStatus', '', null);
                }, 500);
            }
        } catch (e) {
            setStatus('promoFormStatus', 'خطا در ارتباط با سرور.', false);
        }
    };

    window.uploadPromotionImage = async function () {
        const input = document.getElementById('promoImageFile');
        if (!input || !input.files || !input.files[0]) {
            setStatus('promoFormStatus', 'ابتدا یک تصویر انتخاب کن.', false);
            return;
        }
        setStatus('promoFormStatus', 'در حال آپلود...', null);

        const form = new FormData();
        form.append('image', input.files[0]);
        form.append('action', 'upload');

        try {
            const res = await fetch('admin-promotions.php?action=upload', { method: 'POST', body: form });
            const data = await res.json();
            if (data.success && data.url) {
                const urlEl = document.getElementById('promoImageUrl');
                if (urlEl) urlEl.value = data.url;
                setStatus('promoFormStatus', 'تصویر آپلود شد.', true);
            } else {
                setStatus('promoFormStatus', data.message || 'آپلود ناموفق بود.', false);
            }
        } catch (e) {
            setStatus('promoFormStatus', 'خطا در ارتباط با سرور.', false);
        }
    };

    /* =====================================================
       تم و رنگ
       ===================================================== */

    const THEME_KEYS = [
        ['--primary', 'رنگ اصلی'],
        ['--primary-dark', 'اصلی تیره'],
        ['--primary-light', 'اصلی روشن'],
        ['--secondary', 'رنگ دوم'],
        ['--gold', 'طلایی'],
        ['--bg', 'پس‌زمینه'],
        ['--bg-secondary', 'پس‌زمینه دوم'],
        ['--surface', 'سطح کارت‌ها'],
        ['--text-primary', 'متن اصلی'],
        ['--text-secondary', 'متن فرعی'],
        ['--border', 'حاشیه'],
        ['--success', 'موفق'],
        ['--danger', 'خطا'],
        ['--warning', 'هشدار'],
        ['--info', 'اطلاعات']
    ];

    let themeData = null;
    let themeEditMode = 'light';

    const THEME_PRESETS = {
        default: null, // از فایل theme.json خوانده می‌شود
        ocean: {
            light: { '--primary': '#0369A1', '--primary-dark': '#075985', '--primary-light': '#0EA5E9', '--secondary': '#0284C7', '--gold': '#D4AF37', '--bg': '#F8FAFC', '--bg-secondary': '#EFF6FF', '--surface': '#FFFFFF', '--text-primary': '#0F172A', '--text-secondary': '#64748B', '--border': '#E2E8F0', '--success': '#16A34A', '--danger': '#DC2626', '--warning': '#D97706', '--info': '#0284C7' },
            dark: { '--primary': '#38BDF8', '--primary-dark': '#0EA5E9', '--primary-light': '#7DD3FC', '--secondary': '#0EA5E9', '--gold': '#E5B842', '--bg': '#0B1220', '--bg-secondary': '#111C2E', '--surface': '#142033', '--text-primary': '#E2E8F0', '--text-secondary': '#94A3B8', '--border': '#1E293B', '--success': '#4ADE80', '--danger': '#F87171', '--warning': '#FBBF24', '--info': '#38BDF8' }
        },
        royal: {
            light: { '--primary': '#6D28D9', '--primary-dark': '#5B21B6', '--primary-light': '#8B5CF6', '--secondary': '#7C3AED', '--gold': '#D4AF37', '--bg': '#FAF8FF', '--bg-secondary': '#F3EEFF', '--surface': '#FFFFFF', '--text-primary': '#1E1B3A', '--text-secondary': '#6B6480', '--border': '#E9E4F5', '--success': '#16A34A', '--danger': '#DC2626', '--warning': '#D97706', '--info': '#2563EB' },
            dark: { '--primary': '#A78BFA', '--primary-dark': '#8B5CF6', '--primary-light': '#C4B5FD', '--secondary': '#8B5CF6', '--gold': '#E5B842', '--bg': '#120E1F', '--bg-secondary': '#1A1429', '--surface': '#211A33', '--text-primary': '#EDE9FE', '--text-secondary': '#A79DBF', '--border': '#2E2545', '--success': '#4ADE80', '--danger': '#F87171', '--warning': '#FBBF24', '--info': '#60A5FA' }
        },
        emerald: {
            light: { '--primary': '#065F46', '--primary-dark': '#064E3B', '--primary-light': '#0F766E', '--secondary': '#047857', '--gold': '#C9A227', '--bg': '#FAFAF7', '--bg-secondary': '#EEF4F1', '--surface': '#FFFFFF', '--text-primary': '#0F1F1A', '--text-secondary': '#5F6F68', '--border': '#DFE8E3', '--success': '#16A34A', '--danger': '#DC2626', '--warning': '#D97706', '--info': '#0EA5E9' },
            dark:  { '--primary': '#34D399', '--primary-dark': '#10B981', '--primary-light': '#6EE7B7', '--secondary': '#10B981', '--gold': '#E5C558', '--bg': '#08130F', '--bg-secondary': '#0E1C17', '--surface': '#12241E', '--text-primary': '#E6F5EF', '--text-secondary': '#9FB3AC', '--border': '#1E322A', '--success': '#4ADE80', '--danger': '#F87171', '--warning': '#FBBF24', '--info': '#38BDF8' }
        },
        onyx: {
            light: { '--primary': '#1F2937', '--primary-dark': '#111827', '--primary-light': '#374151', '--secondary': '#4B5563', '--gold': '#C9A227', '--bg': '#FAFAF9', '--bg-secondary': '#F1F1EF', '--surface': '#FFFFFF', '--text-primary': '#111827', '--text-secondary': '#6B7280', '--border': '#E5E7EB', '--success': '#16A34A', '--danger': '#DC2626', '--warning': '#D97706', '--info': '#2563EB' },
            dark:  { '--primary': '#D4AF37', '--primary-dark': '#B98F19', '--primary-light': '#E6C766', '--secondary': '#9CA3AF', '--gold': '#E5C558', '--bg': '#0B0B0C', '--bg-secondary': '#141416', '--surface': '#1A1A1D', '--text-primary': '#F5F5F4', '--text-secondary': '#A8A29E', '--border': '#2A2A2E', '--success': '#4ADE80', '--danger': '#F87171', '--warning': '#FBBF24', '--info': '#60A5FA' }
        },
        sapphire: {
            light: { '--primary': '#0F2E5C', '--primary-dark': '#0B2447', '--primary-light': '#1E4F8F', '--secondary': '#1D4ED8', '--gold': '#C9A227', '--bg': '#F8FAFC', '--bg-secondary': '#EDF2F9', '--surface': '#FFFFFF', '--text-primary': '#0B1B2B', '--text-secondary': '#5A6B80', '--border': '#DDE5EF', '--success': '#16A34A', '--danger': '#DC2626', '--warning': '#D97706', '--info': '#0284C7' },
            dark:  { '--primary': '#60A5FA', '--primary-dark': '#3B82F6', '--primary-light': '#93C5FD', '--secondary': '#3B82F6', '--gold': '#E5C558', '--bg': '#070F1C', '--bg-secondary': '#0D1729', '--surface': '#131F33', '--text-primary': '#E8F0FA', '--text-secondary': '#9AAEC4', '--border': '#1E2E47', '--success': '#4ADE80', '--danger': '#F87171', '--warning': '#FBBF24', '--info': '#38BDF8' }
        },
        champagne: {
            light: { '--primary': '#8A6A3B', '--primary-dark': '#6F5430', '--primary-light': '#A98A57', '--secondary': '#B08D57', '--gold': '#D4AF37', '--bg': '#FCFAF5', '--bg-secondary': '#F5F0E6', '--surface': '#FFFFFF', '--text-primary': '#2A2419', '--text-secondary': '#736853', '--border': '#EDE4D3', '--success': '#4D7C0F', '--danger': '#B91C1C', '--warning': '#B45309', '--info': '#0369A1' },
            dark:  { '--primary': '#E0C88C', '--primary-dark': '#C9A227', '--primary-light': '#EFDCB0', '--secondary': '#C9A227', '--gold': '#E5C558', '--bg': '#141108', '--bg-secondary': '#1D1810', '--surface': '#241E13', '--text-primary': '#F7EFDD', '--text-secondary': '#BCAA8A', '--border': '#382F1E', '--success': '#A3E635', '--danger': '#F87171', '--warning': '#FBBF24', '--info': '#7DD3FC' }
        },
        silver: {
            light: { '--primary': '#334155', '--primary-dark': '#1E293B', '--primary-light': '#475569', '--secondary': '#64748B', '--gold': '#BFA46F', '--bg': '#F8FAFC', '--bg-secondary': '#EEF2F6', '--surface': '#FFFFFF', '--text-primary': '#0F172A', '--text-secondary': '#64748B', '--border': '#E2E8F0', '--success': '#16A34A', '--danger': '#DC2626', '--warning': '#D97706', '--info': '#0EA5E9' },
            dark:  { '--primary': '#CBD5E1', '--primary-dark': '#94A3B8', '--primary-light': '#E2E8F0', '--secondary': '#94A3B8', '--gold': '#D6BE8B', '--bg': '#0A0F16', '--bg-secondary': '#101823', '--surface': '#16202B', '--text-primary': '#E9EEF5', '--text-secondary': '#9AA9BC', '--border': '#253141', '--success': '#4ADE80', '--danger': '#F87171', '--warning': '#FBBF24', '--info': '#38BDF8' }
        },
        sunset: {
            light: { '--primary': '#C2410C', '--primary-dark': '#9A3412', '--primary-light': '#EA580C', '--secondary': '#DB2777', '--gold': '#D4AF37', '--bg': '#FFFAF5', '--bg-secondary': '#FFF1E7', '--surface': '#FFFFFF', '--text-primary': '#2B1B14', '--text-secondary': '#7C5A4A', '--border': '#F5E1D3', '--success': '#16A34A', '--danger': '#DC2626', '--warning': '#D97706', '--info': '#0891B2' },
            dark: { '--primary': '#FB923C', '--primary-dark': '#EA580C', '--primary-light': '#FDBA74', '--secondary': '#F472B6', '--gold': '#E5B842', '--bg': '#1A1008', '--bg-secondary': '#241610', '--surface': '#2C1C13', '--text-primary': '#FEEBC8', '--text-secondary': '#C4A48F', '--border': '#3A2519', '--success': '#4ADE80', '--danger': '#F87171', '--warning': '#FBBF24', '--info': '#22D3EE' }
        },
        forest: {
            light: { '--primary': '#15803D', '--primary-dark': '#166534', '--primary-light': '#22C55E', '--secondary': '#0F766E', '--gold': '#D4AF37', '--bg': '#F7FAF7', '--bg-secondary': '#EAF5EC', '--surface': '#FFFFFF', '--text-primary': '#10261A', '--text-secondary': '#5B6E60', '--border': '#DCE9DE', '--success': '#16A34A', '--danger': '#DC2626', '--warning': '#D97706', '--info': '#0EA5E9' },
            dark: { '--primary': '#4ADE80', '--primary-dark': '#22C55E', '--primary-light': '#86EFAC', '--secondary': '#34B7A9', '--gold': '#E5B842', '--bg': '#0B1410', '--bg-secondary': '#111C17', '--surface': '#16241D', '--text-primary': '#E7F5EC', '--text-secondary': '#9DB3A5', '--border': '#25382D', '--success': '#4ADE80', '--danger': '#F87171', '--warning': '#FBBF24', '--info': '#38BDF8' }
        }
    };

    window.initThemeManager = function () {
        if (themeData) return;
        loadThemeSettings();
    };

    window.loadThemeSettings = async function () {
        try {
            const data = await getJson('save_theme.php');
            if (data.success && data.themes) {
                themeData = JSON.parse(JSON.stringify(data.themes));
                renderThemeInputs();
            }
        } catch (e) {
            setStatus('themeStatus', 'خطا در دریافت رنگ‌ها.', false);
        }
    };

    function renderThemeInputs() {
        const grid = document.getElementById('themeColorGrid');
        if (!grid || !themeData) return;

        const mode = themeData[themeEditMode] || {};
        grid.innerHTML = THEME_KEYS.map(([key, label]) => {
            const value = mode[key] || '#000000';
            const hex = /^#([0-9a-fA-F]{3,8})$/.test(value) ? value : '#000000';
            return `
                <div style="display:flex;flex-direction:column;gap:6px;">
                    <label style="font-size:12px;color:var(--text-secondary);">${esc(label)}</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="color" value="${esc(hex)}" data-theme-key="${esc(key)}" style="width:46px;height:38px;border:1px solid var(--border);border-radius:8px;cursor:pointer;padding:2px;background:var(--surface);">
                        <input type="text" value="${esc(value)}" data-theme-text="${esc(key)}" dir="ltr" class="admin-input" style="flex:1;font-size:12px;">
                    </div>
                </div>`;
        }).join('');

        const lightBtn = document.getElementById('themeModeLight');
        const darkBtn = document.getElementById('themeModeDark');
        if (lightBtn) lightBtn.style.opacity = themeEditMode === 'light' ? '1' : '.6';
        if (darkBtn) darkBtn.style.opacity = themeEditMode === 'dark' ? '1' : '.6';
    }

    document.addEventListener('input', function (e) {
        const colorEl = e.target.closest('[data-theme-key]');
        if (colorEl) {
            const key = colorEl.getAttribute('data-theme-key');
            const textEl = document.querySelector('[data-theme-text="' + key + '"]');
            if (textEl) textEl.value = colorEl.value;
            if (themeData && themeData[themeEditMode]) themeData[themeEditMode][key] = colorEl.value;
            return;
        }
        const textEl = e.target.closest('[data-theme-text]');
        if (textEl) {
            const key = textEl.getAttribute('data-theme-text');
            const colorEl2 = document.querySelector('[data-theme-key="' + key + '"]');
            if (colorEl2 && /^#([0-9a-fA-F]{3,8})$/.test(textEl.value.trim())) {
                colorEl2.value = textEl.value.trim();
            }
            if (themeData && themeData[themeEditMode]) themeData[themeEditMode][key] = textEl.value.trim();
        }
    });

    window.setThemeEditMode = function (mode) {
        themeEditMode = mode === 'dark' ? 'dark' : 'light';
        renderThemeInputs();
    };

    window.previewThemeColors = function () {
        if (!themeData) return;
        const root = document.documentElement;
        const current = themeEditMode;
        const values = themeData[current] || {};
        // پیش‌نمایش فقط روی همان حالتی که کاربر الان در آن است
        const isDarkNow = root.getAttribute('data-theme') === 'dark';
        if ((current === 'dark') !== isDarkNow) {
            setStatus('themeStatus', 'برای دیدن این حالت، ابتدا حالت روشن/تاریک سایت را عوض کن.', null);
            return;
        }
        Object.keys(values).forEach(k => root.style.setProperty(k, values[k]));
        setStatus('themeStatus', 'پیش‌نمایش اعمال شد (ذخیره نشده).', true);
    };

    window.saveThemeColors = async function () {
        if (!themeData) return;
        setStatus('themeStatus', 'در حال ذخیره...', null);
        try {
            const res = await fetch('save_theme.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ themes: themeData })
            });
            const data = await res.json();
            setStatus('themeStatus', data.message || (data.success ? 'رنگ‌ها ذخیره شد.' : 'ذخیره ناموفق بود.'), data.success);
            if (data.success) setTimeout(() => location.reload(), 800);
        } catch (e) {
            setStatus('themeStatus', 'خطا در ارتباط با سرور.', false);
        }
    };

    window.applyThemePreset = async function (name) {
        if (!confirm('تم انتخاب‌شده جایگزین رنگ‌های فعلی شود؟')) return;

        let preset = THEME_PRESETS[name];
        if (name === 'default') {
            try {
                const res = await fetch('theme.json', { cache: 'no-store' });
                const json = await res.json();
                preset = json.themes || null;
            } catch (e) {
                preset = null;
            }
        }
        if (!preset) {
            setStatus('themeStatus', 'این تم در دسترس نیست.', false);
            return;
        }

        // فقط کلیدهایی که در ویرایشگر هستند جایگزین می‌شوند
        const merged = { light: {}, dark: {} };
        ['light', 'dark'].forEach(mode => {
            merged[mode] = Object.assign({}, themeData ? themeData[mode] : {});
            THEME_KEYS.forEach(([key]) => {
                if (preset[mode] && preset[mode][key]) merged[mode][key] = preset[mode][key];
            });
        });

        themeData = merged;
        renderThemeInputs();

        try {
            const res = await fetch('save_theme.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ themes: themeData })
            });
            const data = await res.json();
            setStatus('themeStatus', data.success ? 'تم اعمال شد.' : 'اعمال تم ناموفق بود.', data.success);
            if (data.success) setTimeout(() => location.reload(), 800);
        } catch (e) {
            setStatus('themeStatus', 'خطا در ارتباط با سرور.', false);
        }
    };

    window.resetThemeColors = async function () {
        if (!confirm('رنگ‌ها به پیش‌فرض ملکینو برگردند؟')) return;
        await applyThemePreset('default');
    };

    /* =====================================================
       پشتیبان‌گیری
       ===================================================== */

    window.loadBackups = async function () {
        const box = document.getElementById('backupsListContainer');
        if (!box) return;
        box.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-secondary);font-size:13px;">در حال بارگذاری...</div>';

        try {
            const data = await getJson('admin-backup.php?action=list');
            const rows = data.backups || [];

            if (!rows.length) {
                box.innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px;">هیچ فایل پشتیبانی وجود ندارد.</div>';
                return;
            }

            box.innerHTML = rows.map(b => `
                <div style="border:1px solid var(--border);border-radius:14px;padding:13px;margin-bottom:10px;display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;">
                    <div>
                        <div style="font-size:13px;font-weight:700;color:var(--text-primary);" dir="ltr">${esc(b.name)}</div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">${esc(b.created_at)} · ${esc(b.size_human)}</div>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <button type="button" class="btn-secondary" style="padding:5px 10px;font-size:11px;" data-backup-download="${esc(b.name)}">⬇️ دانلود</button>
                        <button type="button" class="btn-secondary" style="padding:5px 10px;font-size:11px;" data-backup-restore="${esc(b.name)}">♻️ بازیابی</button>
                        <button type="button" class="btn-secondary" style="padding:5px 10px;font-size:11px;color:var(--danger);" data-backup-delete="${esc(b.name)}">🗑 حذف</button>
                    </div>
                </div>
            `).join('');
        } catch (e) {
            box.innerHTML = '<div style="color:var(--danger);font-size:13px;">خطا در بارگذاری فهرست پشتیبان‌ها.</div>';
        }
    };

    window.createBackup = async function () {
        const withDb = document.getElementById('backupWithDb');
        const includeDb = withDb ? (withDb.checked ? '1' : '0') : '1';

        if (!confirm('ساخت فایل پشتیبان ممکن است کمی طول بکشد. ادامه بدهیم؟')) return;

        const status = document.getElementById('backupsListContainer');
        if (status) status.innerHTML = '<div style="padding:20px;text-align:center;font-size:13px;">⏳ در حال ساخت پشتیبان...</div>';

        try {
            const data = await getJson('admin-backup.php?action=create&with_db=' + includeDb);
            if (data.success) {
                alert(data.message || 'پشتیبان ساخته شد.');
                loadBackups();
            } else {
                alert(data.message || 'ساخت پشتیبان ناموفق بود.');
                loadBackups();
            }
        } catch (e) {
            alert('خطا در ارتباط با سرور.');
            loadBackups();
        }
    };

    document.addEventListener('click', async function (e) {
        const t = e.target;

        const dl = t.closest('[data-backup-download]');
        if (dl) {
            window.location.href = 'admin-backup.php?action=download&file=' + encodeURIComponent(dl.getAttribute('data-backup-download'));
            return;
        }

        const del = t.closest('[data-backup-delete]');
        if (del) {
            if (!confirm('این فایل پشتیبان حذف شود؟')) return;
            await postJson('admin-backup.php?action=delete', { file: del.getAttribute('data-backup-delete') });
            loadBackups();
            return;
        }

        const rs = t.closest('[data-backup-restore]');
        if (rs) {
            const withDb = document.getElementById('restoreWithDb');
            const restoreDb = withDb ? withDb.checked : false;
            const warning = restoreDb
                ? 'بازیابی فایل‌ها و دیتابیس انجام شود؟ اطلاعات فعلی دیتابیس جایگزین می‌شود.'
                : 'فایل‌های پروژه از این پشتیبان بازیابی شوند؟';
            if (!confirm(warning)) return;

            try {
                const data = await postJson('admin-backup.php?action=restore', {
                    file: rs.getAttribute('data-backup-restore'),
                    restore_db: restoreDb
                });
                alert(data.message || (data.success ? 'بازیابی انجام شد.' : 'بازیابی ناموفق بود.'));
                if (data.success) setTimeout(() => location.reload(), 1000);
            } catch (err) {
                alert('خطا در ارتباط با سرور.');
            }
        }
    });


    /* =====================================================
       مدیریت تصاویر آگهی‌ها
       ===================================================== */

    window.loadAdminImages = async function () {
        const box = document.getElementById('adminImagesContainer');
        if (!box) return;
        box.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-secondary);font-size:13px;">در حال بارگذاری تصاویر...</div>';

        const q = (document.getElementById('imagesSearch') || {}).value || '';
        const missing = (document.getElementById('imagesMissingOnly') || {}).value || '0';
        const url = 'admin-images.php?action=list&q=' + encodeURIComponent(q) + '&missing=' + encodeURIComponent(missing);

        try {
            const data = await getJson(url);
            const rows = data.images || [];

            if (!rows.length) {
                box.innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px;">تصویری با این فیلتر پیدا نشد.</div>';
                return;
            }

            const esc2 = esc;
            box.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;">' +
                rows.map(im => `
                    <div style="border:1px solid var(--border);border-radius:14px;overflow:hidden;background:var(--surface);display:flex;flex-direction:column;">
                        <div style="position:relative;height:110px;background:var(--bg-secondary);display:flex;align-items:center;justify-content:center;overflow:hidden;">
                            ${im.exists
                                ? `<img src="${esc2(im.url)}" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;">`
                                : '<span style="font-size:11px;color:var(--danger);padding:8px;text-align:center;">فایل روی سرور نیست</span>'}
                            ${im.is_primary == 1 ? '<span style="position:absolute;top:6px;right:6px;background:var(--gold-gradient, #D4AF37);color:#111827;font-size:10px;font-weight:800;padding:3px 8px;border-radius:999px;">اصلی</span>' : ''}
                        </div>
                        <div style="padding:9px 10px 11px;display:flex;flex-direction:column;gap:4px;flex:1;">
                            <div style="font-size:11px;color:var(--text-primary);font-weight:700;line-height:1.6;word-break:break-word;">${esc2(im.ad_title || ('آگهی ' + im.ad_id))}</div>
                            <div style="font-size:10px;color:var(--text-muted);word-break:break-all;" dir="ltr">${esc2(im.filename)}</div>
                            <div style="font-size:10px;color:var(--text-muted);">${esc2(im.size_human)}${im.exists ? '' : ' · ' + 'بدون فایل'}</div>
                            <button type="button" class="btn-secondary" style="margin-top:auto;padding:6px 10px;font-size:11px;color:var(--danger);border-color:var(--danger)!important;" data-image-delete="${esc2(im.id)}">
                                🗑 حذف تصویر
                            </button>
                        </div>
                    </div>
                `).join('') + '</div>';
        } catch (e) {
            box.innerHTML = '<div style="color:var(--danger);font-size:13px;">خطا در بارگذاری تصاویر.</div>';
        }
    };

    window.scanOrphanImages = async function () {
        const box = document.getElementById('orphanImagesContainer');
        if (!box) return;
        box.innerHTML = '<div style="padding:18px;text-align:center;font-size:13px;">🔍 در حال اسکن پوشه uploads...</div>';

        try {
            const data = await getJson('admin-images.php?action=orphans');
            const rows = data.orphans || [];

            if (!rows.length) {
                box.innerHTML = '<div style="padding:18px;text-align:center;color:var(--success);font-size:13px;">✅ هیچ فایل بدون استفاده‌ای پیدا نشد.</div>';
                return;
            }

            box.innerHTML = `
                <div style="border:1px solid var(--border);border-radius:14px;padding:12px;margin-bottom:10px;">
                    <div style="font-size:13px;font-weight:800;color:var(--text-primary);margin-bottom:4px;">${rows.length} فایل بدون استفاده (${esc(data.total_size_human)})</div>
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px;">این فایل‌ها به هیچ آگهی‌ای وصل نیستند.</div>
                    <div style="max-height:190px;overflow:auto;border:1px solid var(--border-light);border-radius:10px;padding:8px;margin-bottom:10px;">
                        ${rows.slice(0, 200).map(f => `<div style="font-size:11px;color:var(--text-secondary);padding:2px 0;" dir="ltr">${esc(f.name)} <span style="color:var(--text-muted);">(${Math.round(f.size/1024)} کیلوبایت)</span></div>`).join('')}
                    </div>
                    <button type="button" class="btn-secondary" style="padding:8px 14px;font-size:12px;color:var(--danger);border-color:var(--danger)!important;" onclick="deleteOrphanImages()">
                        🗑 حذف همه (${rows.length} فایل)
                    </button>
                </div>`;

            window.__melkinoOrphans = rows.map(f => f.name);
        } catch (e) {
            box.innerHTML = '<div style="color:var(--danger);font-size:13px;">خطا در اسکن پوشه.</div>';
        }
    };

    window.deleteOrphanImages = async function () {
        const files = window.__melkinoOrphans || [];
        if (!files.length) return;
        if (!confirm(files.length + ' فایل برای همیشه حذف شود؟ این کار قابل بازگشت نیست.')) return;
        if (!confirm('تأیید دوباره: فایل‌ها از روی سرور پاک می‌شوند. ادامه بدهیم؟')) return;

        try {
            const data = await postJson('admin-images.php?action=delete_orphans', { files: files });
            alert(data.message || (data.success ? 'پاکسازی انجام شد.' : 'پاکسازی انجام نشد.'));
            window.__melkinoOrphans = [];
            scanOrphanImages();
        } catch (e) {
            alert('خطا در ارتباط با سرور.');
        }
    };

    document.addEventListener('click', async function (e) {
        const del = e.target.closest('[data-image-delete]');
        if (!del) return;
        if (!confirm('این تصویر از دیتابیس و از روی سرور حذف شود؟')) return;

        const btn = del;
        btn.disabled = true;
        btn.textContent = '⏳ در حال حذف...';

        try {
            const data = await postJson('admin-images.php?action=delete', { id: del.getAttribute('data-image-delete') });
            alert(data.message || (data.success ? 'حذف شد.' : 'حذف انجام نشد.'));
            loadAdminImages();
        } catch (err) {
            alert('خطا در ارتباط با سرور.');
            loadAdminImages();
        }
    });

    /* =====================================================
       بارگذاری اولیه
       ===================================================== */
    // بستن مودال تبلیغ با کلیک روی پس‌زمینه یا کلید Escape
    document.addEventListener('click', function (e) {
        if (e.target && e.target.id === 'promotionModal') {
            window.closePromotionModal();
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('promotionModal');
            if (modal && (modal.classList.contains('active') || modal.style.display === 'flex')) {
                window.closePromotionModal();
            }
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        // بارگذاری تنبل: فقط تب باز شده داده می‌گیرد (سرعت لود پنل)
        if (typeof switchTab === 'function') {
            const current = document.querySelector('.tab-content.active');
            if (current && current.id === 'tab-bots') loadBotSettings();
            if (current && current.id === 'tab-promotions') loadPromotions();
            if (current && current.id === 'tab-backup') loadBackups();
        }
    });

})();
