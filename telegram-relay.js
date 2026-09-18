/**
|--------------------------------------------------------------------------
| رله‌ی سمت مرورگر برای ارتباط با تلگرام و بله
|--------------------------------------------------------------------------
| چرا این فایل لازم است؟
|   بعضی هاست‌ها (مثل InfinityFree) دسترسیِ خروجیِ سرور به
|   api.telegram.org را مسدود کرده‌اند. اما مرورگرِ ادمین معمولاً
|   به تلگرام دسترسی دارد. تلگرام هدرهای CORS را می‌فرستد
|   (Access-Control-Allow-Origin: *)، بنابراین مرورگر می‌تواند
|   مستقیماً با API صحبت کند.
|
| ترتیب تلاش:
|   ۱. فراخوانی مستقیم از مرورگرِ ادمین  (حل مشکل هاست‌های مسدود)
|   ۲. در صورت شکست، فراخوانی از سمت سرور (هاست‌های معمولی)
|--------------------------------------------------------------------------
*/

(function () {
    'use strict';

    var tokenCache = {};

    var API_BASES = {
        telegram: 'https://api.telegram.org/bot',
        bale:     'https://tapi.bale.ai/bot'
    };

    window.melkinoPlatformLabel = function (platform) {
        return platform === 'bale' ? 'بله' : 'تلگرام';
    };

    /** دریافت توکن از سرور (فقط برای ادمین) — همراه با کش در حافظه */
    window.melkinoRelayToken = async function (platform) {
        if (tokenCache[platform]) {
            return tokenCache[platform];
        }
        try {
            const res = await fetch('telegram-relay.php?action=token&platform=' + encodeURIComponent(platform), {
                cache: 'no-store'
            });
            const data = await res.json();
            if (data && data.success && data.token) {
                tokenCache[platform] = data;
                return data;
            }
            return null;
        } catch (e) {
            return null;
        }
    };

    /**
     * فراخوانی مستقیم از مرورگر به تلگرام/بله
     * نکته: از application/x-www-form-urlencoded استفاده می‌شود چون این نوع
     * درخواست در CORS «ساده» محسوب می‌شود و نیازی به preflight ندارد.
     */
    window.melkinoClientCall = async function (platform, method, params, options) {
        options = options || {};

        let token = options.token || '';
        let apiBase = API_BASES[platform] || API_BASES.telegram;

        if (!token) {
            const tokenData = await window.melkinoRelayToken(platform);
            if (!tokenData) {
                return { ok: false, description: 'توکن ' + window.melkinoPlatformLabel(platform) + ' در دسترس نیست.', via: 'none' };
            }
            token = tokenData.token;
            if (tokenData.api_base) {
                apiBase = tokenData.api_base;
            }
        }

        let url = apiBase + token + '/' + method;
        const body = new URLSearchParams(params || {}).toString();

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            });
            const data = await res.json();
            data.via = 'browser';
            return data;
        } catch (e) {
            return {
                ok: false,
                via: 'browser',
                description: 'مرورگر نتوانست به ' + window.melkinoPlatformLabel(platform) + ' وصل شود.'
            };
        }
    };

    /** فراخوانی از سمت سرور (مسیر پشتیبان) */
    window.melkinoServerCall = async function (platform, method, params) {
        try {
            const res = await fetch('telegram-relay.php?action=server_call', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ platform: platform, method: method, params: params || {} })
            });
            const data = await res.json();
            return {
                ok: !!(data && data.success),
                result: data ? data.result : null,
                description: data ? (data.message || '') : 'پاسخ نامعتبر',
                via: 'server'
            };
        } catch (e) {
            return { ok: false, via: 'server', description: 'خطا در ارتباط با سرور سایت.' };
        }
    };

    /**
     * فراخوانی کامل: ابتدا از مرورگر، در صورت شکست از سرور
     * خروجی: { ok, result, description, via }
     */
    window.melkinoApiCall = async function (platform, method, params, options) {
        options = options || {};

        var clientDescription = '';

        if (!options.serverOnly) {
            const clientResult = await window.melkinoClientCall(platform, method, params, options);
            if (clientResult && clientResult.ok) {
                return clientResult;
            }
            if (clientResult && clientResult.description) {
                clientDescription = clientResult.description;
            }
            if (options.clientOnly) {
                return clientResult;
            }
        }

        const serverResult = await window.melkinoServerCall(platform, method, params);

        // هر دو مسیر شکست خورد: علتِ هر دو گفته می‌شود تا راه‌حل معلوم باشد
        // (مرورگر = فیلترشکن؛ سرور = پروکسی در تب ربات‌ها).
        if (serverResult && !serverResult.ok && clientDescription && !options.serverOnly) {
            serverResult.description =
                (serverResult.description || 'خطای نامشخص')
                + ' — همچنین: ' + clientDescription
                + ' (راه‌حل: فیلترشکن را روشن کن یا در تب «ربات و کانال» یک پروکسی برای سرور ثبت کن).';
        }

        return serverResult;
    };

    /** ثبتِ نتیجه‌ی ارسال موفق برای یک آگهی */
    window.melkinoRecordPublish = async function (platform, adId, messageId) {
        try {
            await fetch('telegram-relay.php?action=record', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ platform: platform, ad_id: adId, message_id: messageId })
            });
        } catch (e) {
            // ثبتِ شناسه اختیاری است؛ اصل پیام ارسال شده است
        }
    };

    window.melkinoClearRelayCache = function () {
        tokenCache = {};
    };
})();