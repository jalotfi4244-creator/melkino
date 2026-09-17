<?php
/**
|--------------------------------------------------------------------------
| صفحه‌ی عیب‌یابِ مینی‌اپ (تلگرام / بله)
|--------------------------------------------------------------------------
|
| چرا این صفحه وجود دارد؟
|   در config.php نمایشِ خطاها خاموش است (display_errors = 0). بنابراین هر
|   خطای مرگبارِ PHP به‌جای نمایشِ پیام، یک «صفحه‌ی کاملاً سفید» نشان
|   می‌دهد و تشخیصِ علت غیرممکن می‌شود. مکانیزمِ ?debug=1 هم فقط برای
|   نشستِ ادمین کار می‌کند و کاربرِ عادیِ مینی‌اپ به آن دسترسی ندارد.
|
|   این صفحه مستقل است، نیازی به ورود ندارد و خطاها را در خودش نمایش
|   می‌دهد. اگر در بله/تلگرام باز شود و این متن را ببینید، یعنی صفحه
|   سالم است و مشکل در جای دیگری است؛ اگر سفید باشد، یعنی یک خطای مرگبار
|   در همین مسیر رخ می‌دهد و متنِ آن در پایینِ صفحه چاپ می‌شود.
|
| نکته‌ی امنیتی: این صفحه هیچ توکن یا رمزی را چاپ نمی‌کند. پس از پایانِ
| عیب‌یابی بهتر است آن را از هاست حذف کنید.
|--------------------------------------------------------------------------
*/

// نمایشِ خطاها باید پیش از هر کاری انجام شود
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@ini_set('log_errors', '1');
@error_reporting(E_ALL);

// گرفتنِ خطاهای مرگبار (همان‌هایی که صفحه‌ی سفید می‌سازند)
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array((int)($e['type'] ?? 0), $fatal, true)) {
        return;
    }
    echo '<div dir="rtl" style="font-family:Tahoma;background:#2A0F0F;color:#FFD7D7;'
        . 'border:2px solid #7F1D1D;border-radius:12px;padding:16px;margin:14px 0;line-height:2">'
        . '<b style="font-size:16px">⛔ خطای مرگبار — علتِ صفحه‌ی سفید</b><br>'
        . 'پیام: ' . htmlspecialchars((string)($e['message'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>'
        . 'فایل: ' . htmlspecialchars((string)($e['file'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>'
        . 'خط: ' . (int)($e['line'] ?? 0)
        . '</div>';
});

$checks = [];
$notes  = [];

// ---------------------------------------------------------------
// ۱) اطلاعاتِ محیط
// ---------------------------------------------------------------
$checks[] = ['نسخه‌ی PHP', phpversion(), true];
$checks[] = ['نوعِ اجرا (SAPI)', php_sapi_name(), true];

// ---------------------------------------------------------------
// ۲) تشخیصِ پلتفرم از روی User-Agent (همان منطقِ index.php)
// ---------------------------------------------------------------
$uaRaw   = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$uaLower = strtolower($uaRaw);
$uaIsBale     = (strpos($uaLower, 'bale') !== false) || (strpos($uaLower, 'ble.ir') !== false);
$uaIsTelegram = (strpos($uaLower, 'telegram') !== false);

$checks[] = ['User-Agent', $uaRaw === '' ? '(خالی)' : $uaRaw, true];
$checks[] = ['تشخیصِ «بله» از روی UA', $uaIsBale ? 'بله' : 'خیر', true];
$checks[] = ['تشخیصِ «تلگرام» از روی UA', $uaIsTelegram ? 'بله' : 'خیر', true];

if (!$uaIsBale && !$uaIsTelegram) {
    $notes[] = 'این درخواست از طریقِ یک مرورگرِ معمولی بوده است. برای نتیجه‌ی دقیق،'
        . ' این صفحه را از داخلِ خودِ مینی‌اپ (در بله) باز کنید.';
}

// ---------------------------------------------------------------
// ۳) اتصال به پایگاه داده
// ---------------------------------------------------------------
$dbOk      = false;
$dbMessage = '';
try {
    if (is_file(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
        if (isset($pdo) && ($pdo instanceof PDO)) {
            $dbOk = true;
            $dbMessage = 'برقرار است';
        } else {
            $dbMessage = 'برقرار نیست (اتصال در config.php ناموفق بود)';
        }
    } else {
        $dbMessage = 'فایل config.php یافت نشد';
    }
} catch (Throwable $e) {
    $dbMessage = 'خطا: ' . $e->getMessage();
}
$checks[] = ['اتصال به پایگاه داده', $dbMessage, $dbOk];

// ---------------------------------------------------------------
// ۴) دسترسیِ سرور به tapi.bale.ai
// ---------------------------------------------------------------
$baleReachable = null; // null یعنی نامشخص
$baleNote      = '';
$target = 'https://tapi.bale.ai';
try {
    if (function_exists('curl_init')) {
        $ch = curl_init($target);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_NOBODY         => true,
        ]);
        $ok  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $baleReachable = ($ok !== false && $code > 0);
        $baleNote = 'کد پاسخ: ' . $code;
    } elseif (function_exists('get_headers')) {
        $ctx  = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 12, 'ignore_errors' => true]]);
        $hdrs = @get_headers($target, 0, $ctx);
        $baleReachable = is_array($hdrs) && !empty($hdrs);
        $baleNote = is_array($hdrs) ? (string)($hdrs[0] ?? '') : 'بدون پاسخ';
    } else {
        $baleNote = 'روشی برای بررسی در دسترس نیست';
    }
} catch (Throwable $e) {
    $baleNote = 'خطا: ' . $e->getMessage();
}
$checks[] = [
    'دسترسیِ «سرور» به ' . $target,
    ($baleReachable === true ? 'برقرار' : ($baleReachable === false ? 'برقرار نیست' : 'نامشخص')) . ' — ' . $baleNote,
    $baleReachable === true,
];

// ---------------------------------------------------------------
// ۵) وضعیتِ نشست
// ---------------------------------------------------------------
$loggedIn = !empty($_SESSION['reg_telegram_id']) || !empty($_SESSION['reg_bale_id']);
$checks[] = ['کاربر از قبل وارد شده؟', $loggedIn ? 'بله' : 'خیر', true];

if ($loggedIn) {
    $notes[] = 'نکته: در نسخه‌ی قبلی، کدِ بارگیریِ اسکریپتِ بله دقیقاً در همین حالت'
        . ' (کاربرِ وارد‌شده) اجرا نمی‌شد و همین باعثِ صفحه‌ی سفید بود. این مورد'
        . ' اصلاح شده است؛ اگر هنوز سفید است، نتیجه‌ی بخشِ «وضعیتِ آمادگی» را'
        . ' در پایینِ همین صفحه ببینید.';
}

// ---------------------------------------------------------------
// ۶) وجودِ فایل‌های کلیدی
// ---------------------------------------------------------------
foreach (['index.php', 'header.php', 'home.php', 'config.php', 'telegram-relay.js'] as $f) {
    $checks[] = ['وجودِ فایل ' . $f, is_file(__DIR__ . '/' . $f) ? 'موجود' : 'یافت نشد', is_file(__DIR__ . '/' . $f)];
}


// ---------------------------------------------------------------
// ۷) وضعیتِ فایل‌ها — برای اطمینان از اینکه نسخه‌ی جدید واقعاً بالا رفته
// ---------------------------------------------------------------
$versionFiles = [
    'index.php',
    'header.php',
    'admin-ads.js',
    'admin-bots.php',
    'admin-new-tabs.js',
    'bot-settings.php',
    'miniapp-check.php',
];
$fileInfo = [];
foreach ($versionFiles as $f) {
    $fp = __DIR__ . '/' . $f;
    if (is_file($fp)) {
        $fileInfo[] = [
            $f,
            date('Y-m-d H:i:s', filemtime($fp)) . '  —  ' . number_format(filesize($fp)) . ' بایت',
        ];
    } else {
        $fileInfo[] = [$f, 'یافت نشد'];
    }
}

?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>عیب‌یابِ مینی‌اپ — ملکینو</title>

<!--
    آمادگیِ مینی‌اپ: دقیقاً همان منطقی که در index.php و header.php
    استفاده شده، اینجا هم اجرا می‌شود تا بتوان دید آیا اسکریپتِ بله
    در شبکه‌ی کاربر در دسترس هست یا نه.
-->
<script>
/*
 * آمادگیِ مینی‌اپ (تلگرام و بله)
 * ========================================================
 * کلاینتِ تلگرام/بله تا وقتی پیامِ «آمادگی» را دریافت نکند، یک لایه‌ی
 * سفید روی صفحه نگه می‌دارد.
 *
 * سه اشکالِ پشتِ‌هم باعث می‌شد این پیام فرستاده نشود:
 *   ۱) تشخیص با User-Agent در سمتِ سرور (اگر کلاینت خودش را معرفی
 *      نمی‌کرد، کد اصلاً چاپ نمی‌شد)؛
 *   ۲) وابستگی به ورودِ کاربر (برای کاربرِ وارد‌شده اجرا نمی‌شد)؛
 *   ۳) تشخیص در مرورگر بر اساسِ «بودن در قاب» یا User-Agent — که روی
 *      کلاینتِ بله در iOS هر دو شکست می‌خورند: نه قاب دارد و نه در
 *      User-Agent نشانی از «bale» می‌فرستد.
 *
 * نتیجه‌ی عیب‌یابیِ واقعی روی دستگاهِ کاربر نشان داد User-Agent چیزی
 * جز یک Safari معمولی نیست. بنابراین حالا هیچ تشخیصی انجام نمی‌شود:
 * این بلوک همیشه اجرا می‌شود، اسکریپتِ بله همیشه (به‌صورت غیرِ
 * مسدودکننده) بارگیری می‌شود و آمادگی در اولین فرصت اعلام می‌گردد.
 * هزینه‌اش فقط یک درخواستِ کوچکِ غیرِمسدودکننده در مرورگرِ معمولی است.
 */
(function () {

    var sdkDone = { bale: false, telegram: false };

    // حالتِ عادی: ready() از طریقِ اسکریپتِ رسمی
    function announceSDK() {
        try {
            if (window.Bale && window.Bale.WebApp) {
                if (!sdkDone.bale) {
                    sdkDone.bale = true;
                    if (typeof window.Bale.WebApp.ready === 'function') window.Bale.WebApp.ready();
                    try { if (typeof window.Bale.WebApp.expand === 'function') window.Bale.WebApp.expand(); } catch (e) {}
                }
                return true;
            }
        } catch (e) {}
        try {
            if (window.Telegram && window.Telegram.WebApp) {
                if (!sdkDone.telegram) {
                    sdkDone.telegram = true;
                    if (typeof window.Telegram.WebApp.ready === 'function') window.Telegram.WebApp.ready();
                    try { if (typeof window.Telegram.WebApp.expand === 'function') window.Telegram.WebApp.expand(); } catch (e) {}
                }
                return true;
            }
        } catch (e) {}
        return false;
    }

    function inFrame() {
        try { return (window.self !== window.top); } catch (e) { return true; }
    }

    // مسیرِ جایگزین: پروتکلِ خامِ مینی‌اپ (فقط وقتی درونِ قاب باشیم)
    function postReady() {
        if (!inFrame()) { return; }
        try {
            function send(eventType, eventData) {
                window.parent.postMessage(
                    JSON.stringify({ eventType: eventType, eventData: eventData }),
                    '*'
                );
            }
            send('iframe_ready', { reload_supported: true });
            send('web_app_ready', null);
            send('web_app_expand', null);
        } catch (e) {}
    }

    // ۱) اگر شیء از پیش وجود داشت، همان لحظه اعلام کن
    announceSDK();

    // ۲) بارگیریِ اسکریپتِ بله: همیشه و غیرِ مسدودکننده.
    //    اسکریپتِ تلگرام فقط وقتی لود می‌شود که نشانه‌ای از تلگرام باشد
    //    (درونِ قاب بودن یا User-Agent) تا در شبکه‌ی ایران — که
    //    telegram.org در دسترس نیست — درخواستِ بی‌فایده معلق نماند.
    var ua = '';
    try { ua = navigator.userAgent || ''; } catch (e) {}

    var sources = ['https://tapi.bale.ai/miniapp.js?3'];
    if (inFrame() || /telegram/i.test(ua)) {
        sources.unshift('https://telegram.org/js/telegram-web-app.js');
    }

    for (var i = 0; i < sources.length; i++) {
        (function (src) {
            var s = document.createElement('script');
            s.src = src;
            s.async = true;                 // هرگز مسدودکننده نباشد
            s.onload = function () { announceSDK(); };
            s.onerror = function () {};
            document.head.appendChild(s);
        })(sources[i]);
    }

    // ۳) پایشِ مداوم: به‌محض اینکه هر کدام از SDKها ظاهر شد، آمادگی
    //    اعلام می‌شود — حتی اگر اسکریپت دیرتر از موعد لود شود.
    var tries = 0;
    var timer = setInterval(function () {
        tries++;
        announceSDK();
        if ((sdkDone.bale && sdkDone.telegram) || tries > 120) { clearInterval(timer); }
    }, 150);

    // ۴) مسیرِ جایگزین برای کلاینت‌هایی که صفحه را درونِ قاب نشان می‌دهند
    setTimeout(postReady, 400);
    setTimeout(postReady, 1500);
    setTimeout(postReady, 3000);
})();
</script>

<style>
    body {
        margin: 0;
        padding: 16px;
        font-family: Tahoma, Arial, sans-serif;
        background: #0b1f1e;
        color: #eaf5f3;
        direction: rtl;
        line-height: 1.9;
    }
    .box {
        background: #12312e;
        border: 1px solid #2c5b56;
        border-radius: 14px;
        padding: 14px 16px;
        margin: 12px 0;
    }
    h1 { font-size: 19px; margin: 6px 0 10px; color: #d4af37; }
    h2 { font-size: 15px; margin: 14px 0 8px; color: #9fd8cf; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    td { padding: 7px 8px; border-bottom: 1px solid #244a46; vertical-align: top; }
    td:first-child { width: 42%; color: #bfe3dd; }
    .ok { color: #7ee2b8; }
    .bad { color: #ff9b9b; }
    pre {
        background: #071715;
        border: 1px solid #244a46;
        border-radius: 10px;
        padding: 12px;
        font-size: 12px;
        white-space: pre-wrap;
        word-break: break-word;
        color: #cfe9e4;
    }
    .note {
        background: #2a2410;
        border: 1px solid #6b5a1d;
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 13px;
        margin: 8px 0;
        color: #f3e2ab;
    }
</style>
</head>
<body>

<h1>✅ عیب‌یابِ مینی‌اپ ملکینو</h1>
<div class="note">
اگر این متن را می‌بینید، یعنی صفحه از سمتِ سرور سالم است و مشکلِ «صفحه‌ی سفید»
از خطای PHP نیست. در این صورت نتیجه‌ی بخشِ «وضعیتِ آمادگی» را در پایینِ صفحه
برای پشتیبانی بفرستید.
</div>

<div class="box">
    <h2>بررسی‌های سرور</h2>
    <table>
        <?php foreach ($checks as $c): ?>
            <tr>
                <td><?= htmlspecialchars((string)$c[0], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="<?= $c[2] ? 'ok' : 'bad' ?>">
                    <?= htmlspecialchars((string)$c[1], ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php if ($notes): ?>
    <div class="box">
        <h2>نکته‌ها</h2>
        <?php foreach ($notes as $n): ?>
            <div class="note"><?= htmlspecialchars($n, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="box">
    <h2>نسخه‌ی فایل‌ها (برای اطمینان از بالا رفتنِ آپلود)</h2>
    <table>
        <?php foreach ($fileInfo as $fi): ?>
            <tr>
                <td><?= htmlspecialchars((string)$fi[0], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)$fi[1], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <div style="font-size:12px;color:#9fd8cf;margin-top:8px">
        با مقایسه‌ی زمان و حجم این فایل‌ها با بسته‌ای که فرستاده شده، می‌توان
        مطمئن شد نسخه‌ی جدید واقعاً روی هاست قرار گرفته است.
    </div>
</div>

<div class="box">
    <h2>وضعیتِ آمادگی (سمتِ مرورگر/کلاینت)</h2>
    <div id="envBox" style="font-size:13px;margin-bottom:8px;color:#bfe3dd"></div>
    <pre id="liveLog">در حال بررسی…</pre>
    <div style="font-size:12px;color:#9fd8cf">
        این بخش چند ثانیه بعد از باز شدنِ صفحه کامل می‌شود. اگر عبارتِ
        «ready() فراخوانی شد» یا «پیام‌های آمادگی … فرستاده شد» را می‌بینید،
        یعنی کلاینت باید صفحه را نمایش دهد.
    </div>
</div>

<script>
(function () {
    var box = document.getElementById('envBox');
    var env = window.__MK_ENV || { inFrame: false, ua: '', matches: false };
    box.textContent =
        'درونِ قاب (iframe): ' + (env.inFrame ? 'بله' : 'خیر')
        + '  |  انطباقِ UA با پیام‌رسان: ' + (env.matches ? 'بله' : 'خیر')
        + '  |  UA: ' + env.ua;

    var log = document.getElementById('liveLog');
    if (window.__MK_LOG && window.__MK_LOG.length) {
        log.textContent = window.__MK_LOG.join('\n');
    }
})();
</script>

</body>
</html>
