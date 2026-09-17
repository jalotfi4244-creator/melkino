<?php
/*
|--------------------------------------------------------------------------
| عیب‌یابِ ورودِ مینی‌اپ (تلگرام / بله)
|--------------------------------------------------------------------------
| این صفحه فقط برای ادمین است و دقیقاً نشان می‌دهد که چرا ورودِ
| مینی‌اپ کار نمی‌کند: آیا توکن تنظیم شده؟ آیا امضا معتبر است؟
| آیا امضا منقضی شده؟ آیا کاربر در دیتابیس ثبت می‌شود؟
|
| نحوه‌ی استفاده: ابتدا وارد پنل ادمین شو، سپس همین آدرس را
| «در داخل تلگرام» (همان‌طور که مینی‌اپ را باز می‌کنی) باز کن تا
| ببینی تلگرام چه داده‌ای به سایت می‌فرستد.
|--------------------------------------------------------------------------
*/

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/bot-settings.php';

if (empty($_SESSION['is_admin'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
        . '<title>دسترسی غیرمجاز</title></head><body style="font-family:Tahoma;background:#0D1413;color:#F3F4F6;'
        . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">'
        . '<div style="text-align:center"><h2>دسترسی غیرمجاز</h2>'
        . '<p style="color:#A8B1AE">ابتدا وارد <a href="admin-panel.php" style="color:#7FD1BE">پنل ادمین</a> شو، سپس این صفحه را باز کن.</p>'
        . '</div></body></html>';
    exit;
}

$botSettings = function_exists('melkinoBotSettings') ? melkinoBotSettings() : [];

/* ---------- بررسی توکن تلگرام و صحت آن (getMe) ---------- */
$tgToken = function_exists('melkinoTelegramToken') ? (string)melkinoTelegramToken() : '';
$tgTokenOk = ($tgToken !== '' && $tgToken !== 'توکن_ربات_تلگرام');
$tgMeResult = null;
$tgMeError = '';
if ($tgTokenOk) {
    $resp = melkinoHttpPost('https://api.telegram.org/bot' . $tgToken . '/getMe', '');
    if ($resp === null) {
        $tgMeError = 'ارتباط با api.telegram.org برقرار نشد (خروجی سرور مسدود است یا اینترنت ندارد).';
    } else {
        $decoded = json_decode($resp, true);
        if (!empty($decoded['ok'])) {
            $tgMeResult = $decoded['result'];
        } else {
            $tgMeError = (string)($decoded['description'] ?? 'پاسخ نامعتبر');
        }
    }
}

$baleToken = function_exists('melkinoBaleToken') ? (string)melkinoBaleToken() : '';
$baleTokenOk = ($baleToken !== '' && $baleToken !== 'توکن_ربات_بله');

/* ---------- بررسی یک initData نمونه (در صورت ارسال) ---------- */
$testResult = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $body = json_decode(file_get_contents('php://input'), true);
    $initData = (string)($body['init_data'] ?? '');
    $platform = (($body['platform'] ?? '') === 'bale') ? 'bale' : 'telegram';

    $steps = [];
    $token = $platform === 'bale' ? $baleToken : $tgToken;

    if ($token === '' || $token === 'توکن_ربات_تلگرام' || $token === 'توکن_ربات_بله') {
        $steps[] = ['❌', 'توکن ربات ' . ($platform === 'bale' ? 'بله' : 'تلگرام') . ' تنظیم نشده است.'];
    } else {
        $steps[] = ['✅', 'توکن تنظیم شده (' . substr($token, 0, 6) . '...' . substr($token, -4) . ')'];

        if ($initData === '') {
            $steps[] = ['❌', 'initData خالی است — یعنی تلگرام/بله هیچ داده‌ای به صفحه نداده است.'];
        } else {
            $steps[] = ['✅', 'initData دریافت شد (' . strlen($initData) . ' کاراکتر)'];

            parse_str($initData, $parsed);
            if (empty($parsed['hash'])) {
                $steps[] = ['❌', 'پارامتر hash در initData وجود ندارد.'];
            } elseif (empty($parsed['user'])) {
                $steps[] = ['❌', 'پارامتر user در initData وجود ندارد.'];
            } else {
                $receivedHash = (string)$parsed['hash'];
                unset($parsed['hash']);
                $pairs = [];
                foreach ($parsed as $k => $v) { $pairs[] = $k . '=' . $v; }
                sort($pairs, SORT_STRING);
                $dataCheckString = implode("\n", $pairs);
                $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);
                $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

                if (!hash_equals($computedHash, $receivedHash)) {
                    $steps[] = ['❌', 'امضا معتبر نیست (عدم تطابق hash). احتمالاً توکنِ رباتی که مینی‌اپ را باز کرده با توکنِ ذخیره‌شده در سایت یکی نیست.'];
                } else {
                    $steps[] = ['✅', 'امضای داده درست است'];

                    $authDate = (int)($parsed['auth_date'] ?? 0);
                    $age = $authDate > 0 ? (time() - $authDate) : -1;
                    if ($authDate <= 0) {
                        $steps[] = ['❌', 'auth_date وجود ندارد.'];
                    } elseif ($age > 86400) {
                        $steps[] = ['❌', 'داده منقضی شده (' . round($age / 3600, 1) . ' ساعت پیش صادر شده؛ سقف مجاز ۲۴ ساعت است). باید صفحه دوباره از تلگرام باز شود.'];
                    } else {
                        $steps[] = ['✅', 'داده تازه است (' . round($age / 60, 1) . ' دقیقه پیش)'];

                        $user = json_decode((string)$parsed['user'], true);
                        if (!is_array($user) || empty($user['id'])) {
                            $steps[] = ['❌', 'شناسه‌ی کاربر در initData وجود ندارد.'];
                        } else {
                            $steps[] = ['✅', 'شناسه کاربر: ' . htmlspecialchars((string)$user['id'])];

                            // بررسی ثبت در دیتابیس
                            try {
                                $col = $platform === 'bale' ? 'bale_id' : 'telegram_id';
                                $st = $pdo->prepare("SELECT id, name, username FROM users WHERE $col = ? LIMIT 1");
                                $st->execute([(string)$user['id']]);
                                $row = $st->fetch(PDO::FETCH_ASSOC);
                                if ($row) {
                                    $steps[] = ['✅', 'کاربر در جدول users وجود دارد (id=' . (int)$row['id'] . ')'];
                                } else {
                                    $steps[] = ['⚠️', 'کاربر هنوز در جدول users ثبت نشده؛ در اولین ورودِ موفق ثبت می‌شود.'];
                                }
                            } catch (Throwable $e) {
                                $steps[] = ['❌', 'خطا در بررسی جدول users: ' . $e->getMessage()];
                            }
                        }
                    }
                }
            }
        }
    }

    echo json_encode(['success' => true, 'steps' => $steps], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- اطلاعات نشست ---------- */
$sessionInfo = [
    'session_id'      => session_id(),
    'session_status'  => session_status() === PHP_SESSION_ACTIVE ? 'فعال' : 'غیرفعال',
    'telegram_id'     => (string)($_SESSION['reg_telegram_id'] ?? ''),
    'bale_id'         => (string)($_SESSION['reg_bale_id'] ?? ''),
    'user_phone'      => (string)($_SESSION['user_phone'] ?? ''),
    'is_admin'        => !empty($_SESSION['is_admin']) ? 'بله' : 'خیر',
];
$identity = melkinoCurrentIdentity();
$sessionInfo['شناسایی کاربر'] = !empty($identity['user_id'])
    ? '✅ شناسایی شد (user_id=' . (int)$identity['user_id'] . ')'
    : '❌ شناسایی نشد (نشست معتبر نیست یا کاربر در جدول users نیست)';

$cookieParams = session_get_cookie_params();
$httpsOn = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>عیب‌یاب ورود مینی‌اپ</title>
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" media="print" onload="this.media='all'" />
<style>
    * { box-sizing: border-box; font-family: 'Vazirmatn', Tahoma, sans-serif; }
    body { margin:0; background:#0D1413; color:#F3F4F6; padding:24px 16px; line-height:1.9; }
    .wrap { max-width: 780px; margin: 0 auto; }
    h1 { font-size: 20px; margin: 0 0 6px; }
    .sub { color:#A8B1AE; font-size: 13px; margin: 0 0 22px; }
    .card { background:#16211F; border:1px solid #223330; border-radius:16px; padding:18px 20px; margin-bottom:16px; }
    .card h2 { font-size:15px; margin:0 0 12px; color:#7FD1BE; }
    table { width:100%; border-collapse: collapse; font-size:13px; }
    td { padding:6px 8px; border-bottom:1px solid #223330; vertical-align: top; }
    td:first-child { color:#A8B1AE; width: 42%; }
    .ok { color:#4ADE80; } .bad { color:#F87171; } .warn { color:#FBBF24; }
    button {
        background:#1F6F5C; color:#fff; border:none; border-radius:10px;
        padding:11px 18px; font-size:14px; font-weight:700; cursor:pointer;
        font-family: inherit;
    }
    button:disabled { opacity:.5; cursor:not-allowed; }
    pre { background:#0D1413; border:1px solid #223330; border-radius:10px; padding:12px;
          font-size:12px; overflow-x:auto; white-space:pre-wrap; word-break:break-word; color:#9FB3AF; }
    .steps div { padding:4px 0; font-size:13px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>🔍 عیب‌یاب ورودِ مینی‌اپ</h1>
    <p class="sub">
        این آدرس را درست همان‌طور که مینی‌اپ را باز می‌کنی (از داخل تلگرام) باز کن
        تا ببینی تلگرام چه داده‌ای به سایت می‌دهد.
    </p>

    <div class="card">
        <h2>۱. داده‌ی دریافتی از تلگرام/بله (در همین لحظه)</h2>
        <pre id="liveData">در حال بررسی...</pre>
        <div style="margin-top:12px;">
            <button id="btnTest" onclick="runTest()">🧪 تستِ ورود با این داده</button>
        </div>
        <div class="steps" id="steps" style="margin-top:14px;"></div>
    </div>

    <div class="card">
        <h2>۲. تنظیمات ربات</h2>
        <table>
            <tr><td>توکن تلگرام</td>
                <td><?= $tgTokenOk ? '<span class="ok">✅ تنظیم شده</span>' : '<span class="bad">❌ تنظیم نشده</span>' ?></td></tr>
            <tr><td>صحت توکن (getMe)</td>
                <td><?php
                    if (!$tgTokenOk) echo '<span class="bad">—</span>';
                    elseif ($tgMeResult) echo '<span class="ok">✅ معتبر — @' . htmlspecialchars((string)($tgMeResult['username'] ?? '')) . '</span>';
                    else echo '<span class="bad">❌ ' . htmlspecialchars($tgMeError) . '</span>';
                ?></td></tr>
            <tr><td>نام کاربری ربات (ثبت‌شده در تنظیمات)</td>
                <td><?= htmlspecialchars((string)($botSettings['telegram_bot_username'] ?? '—')) ?></td></tr>
            <tr><td>توکن بله</td>
                <td><?= $baleTokenOk ? '<span class="ok">✅ تنظیم شده</span>' : '<span class="warn">⚠️ تنظیم نشده</span>' ?></td></tr>
            <tr><td>نام کاربری ربات بله</td>
                <td><?= htmlspecialchars((string)($botSettings['bale_bot_username'] ?? '—')) ?></td></tr>
        </table>
    </div>

    <div class="card">
        <h2>۳. وضعیت نشست (Session)</h2>
        <table>
            <?php foreach ($sessionInfo as $k => $v): ?>
                <tr>
                    <td><?= htmlspecialchars((string)$k) ?></td>
                    <td class="<?= (strpos((string)$v, '✅') === 0) ? 'ok' : ((strpos((string)$v, '❌') === 0) ? 'bad' : '') ?>">
                        <?= htmlspecialchars((string)$v) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <h2>۴. محیط</h2>
        <table>
            <tr><td>پروتکل</td><td><?= $httpsOn ? '<span class="ok">✅ HTTPS</span>' : '<span class="bad">❌ HTTP — تلگرام فقط مینی‌اپِ HTTPS را می‌پذیرد</span>' ?></td></tr>
            <tr><td>هاست</td><td><?= htmlspecialchars((string)($_SERVER['HTTP_HOST'] ?? '')) ?></td></tr>
            <tr><td>کوکی‌ی نشست (path)</td><td><?= htmlspecialchars((string)$cookieParams['path']) ?></td></tr>
            <tr><td>کوکی‌ی نشست (httponly / secure)</td>
                <td><?= ($cookieParams['httponly'] ? 'true' : 'false') ?> / <?= ($cookieParams['secure'] ? 'true' : 'false') ?></td></tr>
            <tr><td>نسخه PHP</td><td><?= htmlspecialchars(PHP_VERSION) ?></td></tr>
        </table>
    </div>

    <div class="card">
        <h2>۵. راهنما</h2>
        <div style="font-size:13px;color:#C6CFCC;">
            اگر بخش ۱ می‌گوید «initData خالی است» یعنی تلگرام هویت را به صفحه نداده است.
            سه دلیلِ رایج دارد:
            <br>۱. برنامه از <b>دکمه‌ی منوی ربات</b> باز نشده (باز کردن لینک در مرورگرِ داخلی کافی نیست).
            <br>۲. آدرسِ مینی‌اپ در تنظیمات ربات با این آدرس یکی نیست (دامنه باید دقیقاً برابر باشد).
            <br>۳. سایت با HTTPS در دسترس نیست.
        </div>
    </div>
</div>

<script src="https://telegram.org/js/telegram-web-app.js" async onerror="window.__tgSdkFailed=true"></script>
<script>
    function uaHas(p) { try { return new RegExp(p, 'i').test(navigator.userAgent || ''); } catch (e) { return false; } }

    function fromHash() {
        var hash = (location.hash || '').replace(/^#/, '');
        if (!hash) return { data: '', platform: '' };
        try {
            var params = new URLSearchParams(hash);
            var raw = params.get('tgWebAppData') || '';
            if (!raw) return { data: '', platform: '' };
            return { data: raw, platform: uaHas('bale') ? 'bale' : 'telegram' };
        } catch (e) { return { data: '', platform: '' }; }
    }

    function collect() {
        var tg = '', bl = '', platform = '';
        try { if (window.Telegram && window.Telegram.WebApp) tg = window.Telegram.WebApp.initData || ''; } catch (e) {}
        try { if (window.Bale && window.Bale.WebApp) bl = window.Bale.WebApp.initData || ''; } catch (e) {}
        if (tg) return { data: tg, platform: 'telegram', source: 'SDK تلگرام' };
        if (bl) return { data: bl, platform: 'bale', source: 'SDK بله' };
        var h = fromHash();
        if (h.data) return { data: h.data, platform: h.platform || 'telegram', source: 'هشِ آدرس' };

        var ua = navigator.userAgent || '';
        return {
            data: '',
            platform: '',
            source: 'هیچ‌کدام',
            note: uaHas('telegram')
                ? 'مرورگرِ داخلیِ تلگرام تشخیص داده شد، ولی هیچ داده‌ی هویتی ارسال نشده است.'
                : (uaHas('bale') ? 'مرورگرِ داخلیِ بله تشخیص داده شد، ولی داده‌ای ارسال نشده است.'
                                 : 'این صفحه در مرورگر معمولی باز شده است.')
        };
    }

    var found = { data: '', platform: '', source: '' };

    function render() {
        var info = collect();
        found = info;
        var text = '';
        text += 'منبع داده: ' + info.source + '\n';
        text += 'پلتفرم: ' + (info.platform || '—') + '\n';
        text += 'طول initData: ' + (info.data ? info.data.length + ' کاراکتر' : 'خالی ❌') + '\n';
        if (info.note) text += 'توضیح: ' + info.note + '\n';
        text += '\nآدرس کامل صفحه:\n' + location.href + '\n';
        text += '\nعامل کاربر (UA):\n' + (navigator.userAgent || '') + '\n';
        if (info.data) {
            text += '\nنمونه‌ی داده (۲۰۰ کاراکتر اول):\n' + info.data.substring(0, 200) + '...';
        }
        document.getElementById('liveData').textContent = text;
        document.getElementById('btnTest').disabled = !info.data;
    }

    function runTest() {
        var steps = document.getElementById('steps');
        steps.innerHTML = '<div>در حال تست...</div>';
        fetch('login-diagnose.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ init_data: found.data, platform: found.platform })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.steps) { steps.innerHTML = '<div class="bad">پاسخ نامعتبر</div>'; return; }
            var html = '';
            data.steps.forEach(function (s) {
                var cls = s[0] === '✅' ? 'ok' : (s[0] === '❌' ? 'bad' : 'warn');
                html += '<div class="' + cls + '">' + s[0] + ' ' + s[1].replace(/</g, '&lt;') + '</div>';
            });
            steps.innerHTML = html;
        })
        .catch(function () { steps.innerHTML = '<div class="bad">خطا در ارتباط با سرور</div>'; });
    }

    render();
    var tries = 0;
    var timer = setInterval(function () {
        tries++;
        render();
        if ((found.data || tries > 25)) clearInterval(timer);
    }, 200);
</script>
</body>
</html>
