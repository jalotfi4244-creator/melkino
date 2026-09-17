<?php
/*
|--------------------------------------------------------------------------
| همگام‌سازی پروفایل کاربر (profile-sync)
|--------------------------------------------------------------------------
| هدف: به‌محض اینکه کاربر وارد مینی‌اپ می‌شود، اطلاعات و پروفایل او در
| جدول users ساخته یا به‌روزرسانی شود — حتی اگر هرگز آگهی یا درخواستی
| ثبت نکند و فقط سایت را ببیند.
|
| مسیرهای ورود اطلاعات:
|   1) auth-telegram.php / auth-bale.php  → هنگام ورود (احراز هویت)
|   2) این فایل                          → در هر بازدید، برای به‌روزرسانی
|                                           نام، نام کاربری، عکس، زبان،
|                                           پلتفرم و زمان آخرین بازدید
|
| امنیت: هویت فقط از initDataـی که با امضای رمزنگاری‌شده‌ی خودِ تلگرام/بله
| تأیید شده باشد پذیرفته می‌شود؛ ارسالِ دستیِ telegram_id پذیرفته نیست.
|--------------------------------------------------------------------------
*/

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/bot-settings.php';
require_once __DIR__ . '/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

/* ------------------------------------------------------------------
   به‌روزرسانی اطلاعات تماس (نام و شماره) توسط خودِ کاربر
   ------------------------------------------------------------------
   وقتی کاربر در فرم ثبت ملک یا درخواست، نام یا شماره‌اش را وارد یا
   اصلاح می‌کند، اینجا در پروفایلش ذخیره می‌شود تا از این پس به‌طور
   خودکار پر شود. این بخش از مسیرِ ورود (که نیازمند initData است)
   جداست و فقط برای کاربرِ واردشده کار می‌کند.

   امنیت: هویت فقط از سشنِ سروری خوانده می‌شود و هیچ شناسه‌ای از سمت
   مرورگر پذیرفته نمی‌شود؛ بنابراین امکان دستکشیِ پروفایلِ دیگران نیست.
------------------------------------------------------------------ */
if (($body['action'] ?? '') === 'update_contact') {

    if (!function_exists('melkinoCurrentIdentity')) {
        echo json_encode(['success' => false, 'message' => 'امکان به‌روزرسانی فراهم نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $identity = melkinoCurrentIdentity();
    $userId   = (int)($identity['user_id'] ?? 0);

    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'ابتدا وارد شوید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newName  = trim((string)($body['name'] ?? ''));
    $newPhone = trim((string)($body['phone'] ?? ''));

    if ($newPhone !== '' && function_exists('melkinoNormalizePhone')) {
        $newPhone = melkinoNormalizePhone($newPhone);
    }

    if ($newPhone !== '' && preg_match('/^09\d{9}$/', $newPhone) !== 1) {
        echo json_encode([
            'success' => false,
            'message' => 'شماره موبایل معتبر نیست (مثال: ۰۹۱۲۳۴۵۶۷۸۹).',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        echo json_encode(['success' => false, 'message' => 'پایگاه داده در دسترس نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $sets = [];
        $args = [];

        if ($newName !== '') {
            $sets[] = 'name = ?';
            $args[] = $newName;
            $_SESSION['user_name'] = $newName;
        }

        if ($newPhone !== '') {
            $sets[] = 'phone = ?';
            $args[] = $newPhone;
            $_SESSION['user_phone'] = $newPhone;
        }

        if ($sets) {
            $sets[] = 'updated_at = NOW()';
            $args[] = $userId;
            $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        }

        echo json_encode([
            'success' => true,
            'message' => 'اطلاعات تماس ذخیره شد.',
            'name'    => $newName,
            'phone'   => $newPhone,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'خطا در ذخیره‌سازی.'], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

$initData = trim((string)($body['init_data'] ?? ''));
$platform = (($body['platform'] ?? '') === 'bale') ? 'bale' : 'telegram';

if ($initData === '') {
    echo json_encode(['success' => false, 'message' => 'داده‌ی هویت ارسال نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- تأیید امضا ---------- */
$verified = $platform === 'bale'
    ? (function_exists('melkinoVerifyBaleInitData') ? melkinoVerifyBaleInitData($initData) : null)
    : (function_exists('melkinoVerifyTelegramInitData') ? melkinoVerifyTelegramInitData($initData) : null);

// اگر تشخیص پلتفرم اشتباه بود، پلتفرم دیگر هم امتحان می‌شود
if ($verified === null && $platform === 'bale' && function_exists('melkinoVerifyTelegramInitData')) {
    $verified = melkinoVerifyTelegramInitData($initData);
    $platform = 'telegram';
} elseif ($verified === null && $platform === 'telegram' && function_exists('melkinoVerifyBaleInitData')) {
    $verified = melkinoVerifyBaleInitData($initData);
    $platform = 'bale';
}

if ($verified === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'امضای پیام‌رسان معتبر نیست یا منقضی شده.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- استخراج اطلاعات از initData ---------- */
parse_str($initData, $parsedData);
$rawUser = null;
if (!empty($parsedData['user'])) {
    $rawUser = json_decode((string)$parsedData['user'], true);
    if (!is_array($rawUser)) {
        $rawUser = null;
    }
}

$telegramId = $platform === 'bale' ? '' : (string)$verified['id'];
$baleId     = $platform === 'bale' ? (string)$verified['id'] : '';
$name       = trim($verified['first_name'] . ' ' . $verified['last_name']);
$username   = (string)$verified['username'];
$photoUrl   = is_array($rawUser) ? (string)($rawUser['photo_url'] ?? '') : '';
$language   = is_array($rawUser) ? (string)($rawUser['language_code'] ?? '') : '';

/* ---------- ساخت یا به‌روزرسانی پروفایل ---------- */
if (function_exists('melkinoEnsureUserProfileColumns')) {
    melkinoEnsureUserProfileColumns();
}

$identity = melkinoUpsertUser(
    $telegramId !== '' ? $telegramId : null,
    null,
    $name,
    $username,
    null,
    $baleId !== '' ? $baleId : null
);

$userId = $identity['id'] ?? null;

if (empty($userId)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ساخت پروفایل ناموفق بود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- به‌روزرسانی ستون‌های تکمیلی ---------- */
global $pdo;
if ($pdo instanceof PDO) {
    try {
        $sets = [];
        $params = [];

        if ($photoUrl !== '') {
            $sets[] = 'photo_url = ?';
            $params[] = substr($photoUrl, 0, 500);
        }
        if ($language !== '') {
            $sets[] = 'language_code = ?';
            $params[] = substr($language, 0, 10);
        }
        $sets[] = 'last_platform = ?';
        $params[] = $platform;

        $sets[] = 'user_agent = ?';
        $params[] = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);

        $sets[] = 'last_ip = ?';
        $params[] = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);

        $sets[] = 'last_login = NOW()';

        $params[] = (int)$userId;

        $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($params);
    } catch (Throwable $e) {
        // به‌روزرسانیِ ستون‌های تکمیلی اختیاری است؛ اصل پروفایل ذخیره شده
    }
}

/* ---------- ثبت رویداد ورود (فقط یک‌بار در هر نشست) ---------- */
if (empty($_SESSION['melkino_login_recorded']) && function_exists('melkinoRecordLoginInfo')) {
    melkinoRecordLoginInfo((int)$userId, $platform, [
        'telegram_id' => $telegramId,
        'bale_id'     => $baleId,
        'username'    => $username,
        'name'        => $name,
    ]);
    $_SESSION['melkino_login_recorded'] = time();
}

/* ---------- تکمیل نشست (اگر از قبل وارد نشده باشد) ---------- */
if (empty($_SESSION['reg_telegram_id']) && empty($_SESSION['reg_bale_id'])) {
    if ($telegramId !== '') {
        $_SESSION['reg_telegram_id'] = $telegramId;
    }
    if ($baleId !== '') {
        $_SESSION['reg_bale_id'] = $baleId;
    }
    if ($name !== '') {
        $_SESSION['user_name'] = $name;
    }
}

/* ---------- پاسخ ---------- */
$phone = '';
if ($pdo instanceof PDO) {
    try {
        $st = $pdo->prepare('SELECT phone, name FROM users WHERE id = ? LIMIT 1');
        $st->execute([(int)$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $phone = trim((string)($row['phone'] ?? ''));
            if ($phone !== '') {
                $_SESSION['user_phone'] = $phone;
            }
            if ($name === '' && !empty($row['name'])) {
                $name = (string)$row['name'];
            }
        }
    } catch (Throwable $e) {
        // نادیده گرفته می‌شود
    }
}

echo json_encode([
    'success'  => true,
    'user_id'  => $userId,
    'name'     => $name,
    'phone'    => $phone,
    'platform' => $platform,
], JSON_UNESCAPED_UNICODE);
