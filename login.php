<?php
/**
|--------------------------------------------------------------------------
| ورود به ملکینو — فقط از طریق تلگرام و پیام‌رسان بله
|--------------------------------------------------------------------------
| ورود با شماره موبایل / کد یک‌بارمصرف حذف شده است. تنها راه ورود،
| مینی‌اپ تلگرام یا بله است که هویت کاربر را با امضای رمزنگاری‌شده
| تأیید می‌کند (امکان جعل ندارد).
|
| نکته‌ی مهم: اگر کاربر واقعاً داخل مینی‌اپ باشد، ورود به‌صورت
| خودکار (بدون نیاز به کلیک) انجام می‌شود. داده‌ی هویت از سه مسیر
| خوانده می‌شود تا در صورت کندی/فیلتر بودنِ اسکریپت تلگرام هم
| ورود انجام شود:
|   ۱. window.Telegram.WebApp.initData  (روش استاندارد)
|   ۲. بخش هشِ آدرس (#tgWebAppData=...) در لینک مستقیم مینی‌اپ
|   ۳. window.Bale.WebApp.initData      (پیام‌رسان بله)
|--------------------------------------------------------------------------
*/

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/bot-settings.php';

/** فقط مسیرهای داخلی برای بازگشت مجازند (جلوگیری از Open Redirect) */
function melkinoSafeRedirectTarget(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'profile.php';
    }
    if (preg_match('#^https?://#i', $value)) {
        return 'profile.php';
    }
    if (preg_match('#^[a-zA-Z0-9_./?=&%\-]+\.php#', $value) === 1) {
        return $value;
    }
    return 'profile.php';
}

// اگر از قبل هویت معتبر دارد، مستقیم به مقصد برود
$identity = melkinoCurrentIdentity();
if (!empty($identity['user_id'])) {
    header('Location: ' . melkinoSafeRedirectTarget($_GET['redirect'] ?? null));
    exit;
}

$redirectTarget = melkinoSafeRedirectTarget($_GET['redirect'] ?? null);
$botSettings = function_exists('melkinoBotSettings') ? melkinoBotSettings() : [];
$tgBot = ltrim((string)($botSettings['telegram_bot_username'] ?? ''), '@');
$baleBot = ltrim((string)($botSettings['bale_bot_username'] ?? ''), '@');

// تشخیص وضعیت تنظیمات برای نمایش راهنمای دقیق‌تر
$tokenState = 'unknown';
if (function_exists('melkinoTelegramToken')) {
    $tg = (string)melkinoTelegramToken();
    $tokenState = ($tg === '' || $tg === 'توکن_ربات_تلگرام') ? 'missing' : 'ok';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ورود به ملکینو</title>

<!-- اسکریپت‌های مینی‌اپ؛ به‌صورت async لود می‌شوند تا در صورت کندی
     یا در دسترس نبودن، صفحه معطل نماند (ورود از طریق هشِ آدرس هم
     به‌عنوان مسیر پشتیبان انجام می‌شود). -->
<script>
    window.__melkinoSdk = { telegram: false, bale: false, telegramError: '', baleError: '' };

    function melkinoLoadSdk(src, onOk, onErr) {
        var s = document.createElement('script');
        s.src = src;
        s.async = true;
        s.onload = onOk;
        s.onerror = onErr;
        document.head.appendChild(s);
    }

    // منبع اصلی و دو منبع جایگزین برای هر پیام‌رسان
    window.__melkinoTelegramSources = [
        'https://telegram.org/js/telegram-web-app.js',
        'https://telegram.org/js/telegram-web-app.js?1'
    ];
    window.__melkinoBaleSources = [
        'https://tapi.bale.ai/miniapp.js?3',
        'https://tapi.bale.ai/miniapp.js?4'
    ];

    window.__melkinoTryTelegram = function (i) {
        if (i >= window.__melkinoTelegramSources.length) return;
        melkinoLoadSdk(
            window.__melkinoTelegramSources[i],
            function () {
                window.__melkinoSdk.telegram = true;
                // بله/تلگرام تا وقتی ready() صدا زده نشود صفحه را نشان نمی‌دهند
                try { if (window.Telegram && window.Telegram.WebApp) window.Telegram.WebApp.ready(); } catch (e) {}
            },
            function () {
                window.__melkinoSdk.telegramError = window.__melkinoTelegramSources[i];
                window.__melkinoTryTelegram(i + 1);
            }
        );
    };

    window.__melkinoTryBale = function (i) {
        if (i >= window.__melkinoBaleSources.length) return;
        melkinoLoadSdk(
            window.__melkinoBaleSources[i],
            function () {
                window.__melkinoSdk.bale = true;
                try { if (window.Bale && window.Bale.WebApp && typeof window.Bale.WebApp.ready === 'function') window.Bale.WebApp.ready(); } catch (e) {}
            },
            function () {
                window.__melkinoSdk.baleError = window.__melkinoBaleSources[i];
                window.__melkinoTryBale(i + 1);
            }
        );
    };
</script>

<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" media="print" onload="this.media='all'" />
<style>
    * { box-sizing: border-box; font-family: 'Vazirmatn', Tahoma, sans-serif; }
    body {
        margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
        background: linear-gradient(160deg, #0D1413 0%, #122320 100%); color: #F3F4F6; padding: 20px;
    }
    .login-card {
        background: #16211F; border: 1px solid #223330; border-radius: 20px;
        padding: 32px 26px; max-width: 420px; width: 100%; text-align: center;
    }
    .login-card h1 { font-size: 20px; margin: 0 0 8px; }
    .login-card p { color: #A8B1AE; font-size: 14px; margin: 0 0 22px; line-height: 1.9; }
    .login-btn {
        width: 100%; padding: 14px; border-radius: 12px; border: none;
        font-size: 15px; font-weight: 700; cursor: pointer; margin-bottom: 12px;
        display: flex; align-items: center; justify-content: center; gap: 8px;
        text-decoration: none;
    }
    .login-btn.telegram { background: #2AABEE; color: #fff; }
    .login-btn.bale { background: #2C4A7C; color: #fff; }
    .login-btn.ghost { background: transparent; color: #A8B1AE; border: 1px solid #2E423E; }
    .login-btn:disabled { opacity: .5; cursor: not-allowed; }
    .login-msg { font-size: 13px; margin-top: 10px; min-height: 18px; line-height: 1.8; }
    .login-msg.error { color: #F87171; }
    .login-msg.success { color: #4ADE80; }
    .login-msg.info { color: #A8B1AE; }
    .login-hint {
        font-size: 12px; color: #6B7A76; line-height: 1.9; margin-top: 18px;
        border-top: 1px solid #223330; padding-top: 16px;
    }
    .box-note {
        background: #101A18; border: 1px solid #2E423E; border-radius: 14px;
        padding: 14px 16px; font-size: 13px; color: #C6CFCC; line-height: 1.9;
        text-align: right; margin-bottom: 14px;
    }
    .box-note b { color: #F3F4F6; }
    .spinner {
        width: 22px; height: 22px; border-radius: 50%;
        border: 3px solid rgba(255,255,255,.2); border-top-color: #fff;
        animation: melkino-spin .8s linear infinite; margin: 0 auto 12px;
    }
    @keyframes melkino-spin { to { transform: rotate(360deg); } }
    details.diag {
        margin-top: 18px; text-align: right; font-size: 12px; color: #6B7A76;
        border-top: 1px solid #223330; padding-top: 12px;
    }
    details.diag summary { cursor: pointer; outline: none; }
    details.diag pre {
        background: #0D1413; border: 1px solid #223330; border-radius: 10px;
        padding: 10px; overflow-x: auto; color: #9FB3AF; font-size: 11px;
        line-height: 1.7; white-space: pre-wrap; word-break: break-word;
    }
</style>
</head>
<body>
    <div class="login-card">
        <div style="font-size:40px;margin-bottom:6px;">🏠</div>
        <h1>ورود به ملکینو</h1>

        <!-- وضعیت اولیه: در حال بررسی محیط -->
        <div id="checkingBox">
            <div class="spinner"></div>
            <p style="margin:0;">در حال بررسی ورود از طریق تلگرام/بله...</p>
        </div>

        <!-- دکمه‌های دستی (فقط در صورت نیاز نمایش داده می‌شوند) -->
        <div id="manualBox" style="display:none;">
            <p>برای ورود، یکی از پیام‌رسان‌های زیر را انتخاب کن.</p>

            <button type="button" class="login-btn telegram" id="btnTelegram" style="display:none;" onclick="loginWith('telegram')">
                📨 ورود با تلگرام
            </button>

            <button type="button" class="login-btn bale" id="btnBale" style="display:none;" onclick="loginWith('bale')">
                💬 ورود با بله
            </button>
        </div>

        <!-- راهنمای بیرون از مینی‌اپ -->
        <div id="outsideBox" style="display:none;">
            <div class="box-note" id="outsideNote"></div>

            <?php if ($tgBot !== ''): ?>
                <a class="login-btn telegram" id="openTgBot" href="https://t.me/<?= htmlspecialchars($tgBot, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                    📨 باز کردن ربات در تلگرام
                </a>
            <?php endif; ?>
            <?php if ($baleBot !== ''): ?>
                <a class="login-btn bale" href="https://ble.ir/<?= htmlspecialchars($baleBot, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                    💬 باز کردن ربات در بله
                </a>
            <?php endif; ?>
            <?php if ($tgBot === '' && $baleBot === ''): ?>
                <p style="color:#F87171;font-size:13px;line-height:1.9;">
                    لطفاً این صفحه را از داخل ربات تلگرام یا بله‌ی ملکینو باز کن.
                </p>
            <?php endif; ?>

            <button type="button" class="login-btn ghost" onclick="location.reload()">
                🔄 تلاش دوباره
            </button>
        </div>

        <div class="login-msg" id="loginMsg"></div>

        <div class="login-hint">
            ورود فقط از طریق تلگرام و بله امکان‌پذیر است؛<br>
            هویت شما با امضای امنِ خودِ پیام‌رسان تأیید می‌شود.
        </div>

        <details class="diag">
            <summary>جزئیات فنی (برای رفع اشکال)</summary>
            <pre id="diagBox">در حال جمع‌آوری اطلاعات...</pre>
        </details>
    </div>

<script>
    const REDIRECT_TARGET = <?= json_encode($redirectTarget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const TG_BOT   = <?= json_encode($tgBot, JSON_UNESCAPED_UNICODE) ?>;
    const BALE_BOT = <?= json_encode($baleBot, JSON_UNESCAPED_UNICODE) ?>;

    const el = function (id) { return document.getElementById(id); };
    let lastInitData = { telegram: '', bale: '' };
    let authFinished = false;

    /* ---------------------------------------------------------
       ابزارها
       --------------------------------------------------------- */
    function userAgent() { return navigator.userAgent || ''; }
    function uaHas(pattern) { try { return new RegExp(pattern, 'i').test(userAgent()); } catch (e) { return false; } }

    /**
     * بعضی نسخه‌های تلگرام/بله در عامل کاربر (UA) اسمی از خودشان نمی‌گذارند؛
     * بنابراین علاوه بر UA، پلتفرمی که خودِ اسکریپت اعلام می‌کند هم بررسی
     * می‌شود (مقدار unknown یعنی خارج از برنامه).
     */
    function sdkPlatform() {
        let tg = '', bl = '';
        try { if (window.Telegram && window.Telegram.WebApp) tg = String(window.Telegram.WebApp.platform || ''); } catch (e) {}
        try { if (window.Bale && window.Bale.WebApp) bl = String(window.Bale.WebApp.platform || ''); } catch (e) {}
        return { telegram: tg, bale: bl };
    }

    function insideTelegramApp() {
        if (uaHas('telegram')) return true;
        const p = sdkPlatform().telegram;
        return p !== '' && p !== 'unknown';
    }

    function insideBaleApp() {
        if (uaHas('bale')) return true;
        const p = sdkPlatform().bale;
        return p !== '' && p !== 'unknown';
    }

    /**
     * تلگرام در «لینک مستقیم مینی‌اپ» داده‌های هویت را در بخش هشِ آدرس
     * قرار می‌دهد (#tgWebAppData=...). اگر اسکریپت تلگرام لود نشود
     * (مثلاً به‌دلیل فیلتر یا کندی شبکه) باز هم می‌توان از همین مسیر
     * هویت کاربر را به‌دست آورد.
     */
    function initDataFromHash() {
        const hash = (location.hash || '').replace(/^#/, '');
        if (!hash) return { data: '', platform: '' };
        const params = new URLSearchParams(hash);
        const raw = params.get('tgWebAppData') || params.get('tgWebAppData'.toLowerCase()) || '';
        if (!raw) return { data: '', platform: '' };
        const platform = uaHas('bale') ? 'bale' : 'telegram';
        return { data: raw, platform: platform };
    }

    function sdkInitData() {
        let tg = '', bl = '';
        try { if (window.Telegram && window.Telegram.WebApp) tg = window.Telegram.WebApp.initData || ''; } catch (e) {}
        try { if (window.Bale && window.Bale.WebApp) bl = window.Bale.WebApp.initData || ''; } catch (e) {}
        return { telegram: tg, bale: bl };
    }

    function readyWebApp() {
        try {
            if (window.Telegram && window.Telegram.WebApp) {
                window.Telegram.WebApp.ready();
                try { window.Telegram.WebApp.expand(); } catch (e) {}
            }
        } catch (e) {}
        try { if (window.Bale && window.Bale.WebApp) window.Bale.WebApp.ready(); } catch (e) {}
    }

    /* ---------------------------------------------------------
       انتظار برای آماده شدن داده‌ی هویت (حداکثر ۵ ثانیه)
       --------------------------------------------------------- */
    function waitForInitData(timeoutMs) {
        return new Promise(function (resolve) {
            const start = Date.now();
            (function tick() {
                const sdk = sdkInitData();
                if (sdk.telegram) { lastInitData = sdk; return resolve({ platform: 'telegram', data: sdk.telegram, source: 'sdk' }); }
                if (sdk.bale)     { lastInitData = sdk; return resolve({ platform: 'bale',     data: sdk.bale,     source: 'sdk' }); }

                const fromHash = initDataFromHash();
                if (fromHash.data) {
                    return resolve({ platform: fromHash.platform || 'telegram', data: fromHash.data, source: 'hash' });
                }

                if (Date.now() - start >= timeoutMs) {
                    return resolve({ platform: '', data: '', source: 'none' });
                }
                setTimeout(tick, 120);
            })();
        });
    }

    /* ---------------------------------------------------------
       ارسال داده‌ی هویت به سرور
       --------------------------------------------------------- */
    function sendInitData(platform, initData) {
        const endpoint = platform === 'bale' ? 'auth-bale.php' : 'auth-telegram.php';
        const msg = el('loginMsg');
        msg.className = 'login-msg info';
        msg.textContent = 'در حال ورود...';

        return fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ init_data: initData })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.success) {
                authFinished = true;
                msg.className = 'login-msg success';
                msg.textContent = 'ورود موفق — در حال انتقال...';
                goToTarget(data.login_token || '');
                return true;
            }

            // ورود ناموفق: پیام سرور + امکان تلاش دوباره
            authFinished = false;
            msg.className = 'login-msg error';
            let text = (data && data.message) ? data.message : 'ورود ناموفق بود.';
            if (text.indexOf('منقضی') !== -1 || text.indexOf('معتبر نیست') !== -1) {
                text += ' — برای دریافت اعتبارنامه‌ی تازه، صفحه را دوباره باز کن.';
            }
            msg.innerHTML = text.replace(/</g, '&lt;')
                + '<br><a href="#" onclick="location.reload();return false;" style="color:#7FD1BE;">🔄 تلاش دوباره</a>';
            return false;
        })
        .catch(function () {
            msg.className = 'login-msg error';
            msg.innerHTML = 'خطا در ارتباط با سرور. اتصال اینترنت را بررسی کن.'
                + '<br><a href="#" onclick="location.reload();return false;" style="color:#7FD1BE;">🔄 تلاش دوباره</a>';
            return false;
        });
    }

    function goToTarget(token) {
        let url = REDIRECT_TARGET;
        try {
            const u = new URL(REDIRECT_TARGET, location.href);
            if (token) {
                u.searchParams.set('t', token);
                try { localStorage.setItem('melkino_login_token', token); } catch (e) {}
            }
            url = u.toString();
        } catch (e) {
            if (token) url = REDIRECT_TARGET + (REDIRECT_TARGET.indexOf('?') === -1 ? '?' : '&') + 't=' + encodeURIComponent(token);
        }
        setTimeout(function () { location.replace(url); }, 350);
    }

    function loginWith(platform) {
        const sdk = sdkInitData();
        const fromHash = initDataFromHash();
        const data = (platform === 'bale')
            ? (sdk.bale || (fromHash.platform === 'bale' ? fromHash.data : ''))
            : (sdk.telegram || (fromHash.platform === 'telegram' ? fromHash.data : ''));

        if (!data) {
            const msg = el('loginMsg');
            msg.className = 'login-msg error';
            msg.textContent = 'داده‌ی هویت از ' + (platform === 'bale' ? 'بله' : 'تلگرام') + ' دریافت نشد.';
            return;
        }
        sendInitData(platform, data);
    }

    /* ---------------------------------------------------------
       نمایش راهنما وقتی داده‌ی هویت در دسترس نیست
       --------------------------------------------------------- */
    function showOutsideGuide() {
        el('checkingBox').style.display = 'none';
        el('outsideBox').style.display = 'block';

        const note = el('outsideNote');
        const msg = el('loginMsg');

        if (insideTelegramApp()) {
            note.innerHTML = '<b>شما داخل تلگرام هستید، ولی این صفحه به‌عنوان «مینی‌اپ» باز نشده است.</b><br>'
                + 'تلگرام فقط زمانی هویت شما را به سایت می‌دهد که برنامه را از '
                + '<b>دکمه‌ی منوی ربات</b> (یا دکمه‌ی شیشه‌ای کنار پیام‌ها) باز کنید؛ '
                + 'باز کردنِ لینک در مرورگر داخلی تلگرام کافی نیست.<br>'
                + '<span style="color:#7FD1BE;">راه حل: ربات را باز کن و از دکمه‌ی «ورود به ملکینو» وارد شو.</span>';
            msg.className = 'login-msg info';
            msg.textContent = 'هویت تلگرام دریافت نشد (مرورگر داخلی بدون مجوز مینی‌اپ).';
        } else if (insideBaleApp()) {
            note.innerHTML = '<b>شما داخل بله هستید، ولی این صفحه به‌عنوان «مینی‌اپ» باز نشده است.</b><br>'
                + 'برای ورود، برنامه را از <b>دکمه‌ی منوی ربات بله</b> باز کنید.';
            msg.className = 'login-msg info';
            msg.textContent = 'هویت بله دریافت نشد.';
        } else {
            note.innerHTML = 'این صفحه در یک مرورگر معمولی باز شده است.<br>'
                + 'برای ورود باید برنامه را از داخل <b>ربات تلگرام یا بله</b> باز کنید.';
            msg.className = 'login-msg info';
            msg.textContent = 'خارج از تلگرام/بله هستید.';
        }

        // اگر رباتی تنظیم شده باشد، لینک باز کردنِ مینی‌اپ را مستقیم می‌کنیم
        const tgLink = el('openTgBot');
        if (tgLink && TG_BOT) {
            tgLink.href = 'https://t.me/' + TG_BOT;
            tgLink.target = '_top';
        }
    }

    function showManualButtons(platform) {
        el('checkingBox').style.display = 'none';
        el('manualBox').style.display = 'block';
        if (platform === 'telegram') el('btnTelegram').style.display = 'flex';
        if (platform === 'bale') el('btnBale').style.display = 'flex';
    }

    /* ---------------------------------------------------------
       نمایش جزئیات فنی
       --------------------------------------------------------- */
    function renderDiag(extra) {
        const info = {
            'آدرس صفحه': location.href.replace(/#.*$/, '(هش حذف شد)'),
            'بخش هش دارد': (location.hash || '').length > 0 ? 'بله (' + location.hash.length + ' کاراکتر)' : 'خیر',
            'اسکریپت تلگرام': window.__melkinoSdk.telegram ? 'لود شد' : (window.__melkinoSdk.telegramError ? 'خطا در لود' : 'لود نشد (بی‌نیاز اگر هش موجود باشد)'),
            'اسکریپت بله': window.__melkinoSdk.bale ? 'لود شد' : (window.__melkinoSdk.baleError ? 'خطا در لود' : 'لود نشد'),
            'Telegram.WebApp': (function () { try { return !!(window.Telegram && window.Telegram.WebApp); } catch (e) { return 'خطا'; } })(),
            'پلتفرم (اسکریپت)': (function () { const p = sdkPlatform(); return ('تلگرام: ' + (p.telegram || '—') + ' | بله: ' + (p.bale || '—')); })(),
            'داخل تلگرام؟': insideTelegramApp() ? 'بله' : 'خیر',
            'داخل بله؟': insideBaleApp() ? 'بله' : 'خیر',
            'initData (تلگرام)': lastInitData.telegram ? 'موجود (' + lastInitData.telegram.length + ' کاراکتر)' : 'خالی',
            'initData (بله)': lastInitData.bale ? 'موجود (' + lastInitData.bale.length + ' کاراکتر)' : 'خالی',
            'initData از هش': (function () { const h = initDataFromHash(); return h.data ? 'موجود (' + h.data.length + ')' : 'خالی'; })(),
            'عامل کاربر (UA)': userAgent(),
            'پروتکل': location.protocol,
            'توکن ربات (سرور)': <?= json_encode($tokenState === 'ok' ? 'تنظیم شده' : ($tokenState === 'missing' ? 'تنظیم نشده ⚠️' : 'نامشخص'), JSON_UNESCAPED_UNICODE) ?>
        };
        if (extra) { for (const k in extra) { info[k] = extra[k]; } }

        let text = '';
        for (const k in info) { text += k + ': ' + info[k] + '\n'; }
        el('diagBox').textContent = text;
    }

    /* ---------------------------------------------------------
       اجرا
       --------------------------------------------------------- */
    (function start() {
        // لود اسکریپت‌ها (غیرِ مسدودکننده)
        try { window.__melkinoTryTelegram(0); } catch (e) {}
        try { window.__melkinoTryBale(0); } catch (e) {}

        readyWebApp();
        renderDiag();

        waitForInitData(5000).then(function (found) {
            renderDiag({ 'نتیجه‌ی جستجو': found.source === 'none' ? 'هیچ هویتی یافت نشد' : ('یافت شد از طریق ' + found.source) });

            if (found.data) {
                lastInitData[found.platform === 'bale' ? 'bale' : 'telegram'] = found.data;
                el('checkingBox').style.display = 'block';
                const msg = el('loginMsg');
                msg.className = 'login-msg info';
                msg.textContent = 'در حال ورود خودکار...';
                sendInitData(found.platform, found.data);
                return;
            }

            showOutsideGuide();
        });
    })();
</script>
</body>
</html>
