<?php
/*
|--------------------------------------------------------------------------
| ذخیره و بازیابیِ اطلاعاتِ تماس (صفحه‌ی «ارتباط با ما»)
|--------------------------------------------------------------------------
| پیش از این، اطلاعاتِ تماس فقط در localStorageِ مرورگرِ ادمین ذخیره
| می‌شد؛ در نتیجه هیچ بازدیدکننده‌ی دیگری آن را نمی‌دید (مقادیر در
| مرورگرِ خودِ ادمین بود، نه روی سرور).
|
| این فایل اطلاعات را در جدول settings (گروه contact، کلید info) ذخیره
| می‌کند تا برای همه‌ی بازدیدکنندگان یکسان نمایش داده شود.
|
| ساختارِ ذخیره‌شده:
|   {
|     agencyName, address, phone, email, whatsapp, telegram, instagram,
|     linkedin, mapImage, workingHours, bale,
|     customCards: [ {label, messenger, url, icon}, ... ۵ عدد ]
|   }
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-settings.php';

/* نشست باید پیش از بررسیِ $_SESSION شروع شود */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- خواندن (فقط ادمین) ---------- */

if ($method === 'GET') {

    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    global $pdo;

    if (!($pdo instanceof PDO)) {
        echo json_encode(['success' => false, 'message' => 'اتصال به دیتابیس برقرار نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        melkinoEnsureSettingsTable($pdo);
        $data = dbSettingGet($pdo, 'contact', 'info', null);
    } catch (Throwable $e) {
        error_log('save-contact-settings GET error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'خطا در خواندن تنظیمات.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data'    => is_array($data) ? $data : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------- ذخیره ---------- */

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

global $pdo;

if (!($pdo instanceof PDO)) {
    echo json_encode(['success' => false, 'message' => 'اتصال به دیتابیس برقرار نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode((string)$raw, true);

if (!is_array($body)) {
    echo json_encode(['success' => false, 'message' => 'داده‌ی ارسالی نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- پاک‌سازی ---------- */

function melkinoContactCleanText($value, int $maxLength = 300): string
{
    $text = trim((string)($value ?? ''));
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }
    return substr($text, 0, $maxLength);
}

function melkinoContactCleanUrl($value): string
{
    $url = trim((string)($value ?? ''));
    if ($url === '') {
        return '';
    }
    // فقط http/https پذیرفته می‌شود (جلوگیری از javascript: و موارد مشابه)
    if (!preg_match('#^https?://#i', $url)) {
        return '';
    }
    return melkinoContactCleanText($url, 500);
}

/** مختصات جغرافیایی معتبر، یا null */
function melkinoContactCleanCoord($value, float $limit)
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        return null;
    }

    $number = (float)$value;

    if ($number < -$limit || $number > $limit) {
        return null;
    }

    return $number;
}

/** سطح بزرگ‌نمایی بین ۳ تا ۱۹، پیش‌فرض ۱۵ */
function melkinoContactCleanZoom($value): int
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return 15;
    }

    $zoom = (int)$value;

    if ($zoom < 3) {
        return 3;
    }

    if ($zoom > 19) {
        return 19;
    }

    return $zoom;
}

function melkinoContactCleanIcon($value): string
{
    $icon = trim((string)($value ?? ''));
    if ($icon === '') {
        return '';
    }
    // فقط مسیرهای داخلیِ پوشه‌ی uploads مجازند
    if (!preg_match('#^uploads/cards/[A-Za-z0-9_\-]+\.(png|jpe?g|webp|gif|svg)$#i', $icon)) {
        return '';
    }
    return $icon;
}

function melkinoContactCleanMapImage($value): string
{
    $img = trim((string)($value ?? ''));
    if ($img === '') {
        return '';
    }
    // فقط مسیرهای داخلیِ پوشه‌ی uploads/contact مجازند
    if (!preg_match('#^uploads/contact/[A-Za-z0-9_\-]+\.(png|jpe?g|webp|gif)$#i', $img)) {
        return '';
    }
    return $img;
}

$clean = [
    'agencyName'    => melkinoContactCleanText($body['agencyName'] ?? ''),
    'address'       => melkinoContactCleanText($body['address'] ?? ''),
    'phone'         => melkinoContactCleanText($body['phone'] ?? '', 50),
    'email'         => melkinoContactCleanText($body['email'] ?? '', 150),
    'whatsapp'      => melkinoContactCleanText($body['whatsapp'] ?? '', 50),
    'telegram'      => melkinoContactCleanUrl($body['telegram'] ?? ''),
    'instagram'     => melkinoContactCleanUrl($body['instagram'] ?? ''),
    'linkedin'      => melkinoContactCleanUrl($body['linkedin'] ?? ''),
    'workingHours'  => melkinoContactCleanText($body['workingHours'] ?? '', 200),

    /* موقعیت دفتر روی نقشه‌ی نشان (قدیمی؛ نگه داشته شده برای سازگاری) */
    'neshanKey'     => melkinoContactCleanText($body['neshanKey'] ?? '', 200),
    'officeLat'     => melkinoContactCleanCoord($body['officeLat'] ?? null, 90),
    'officeLng'     => melkinoContactCleanCoord($body['officeLng'] ?? null, 180),
    'officeZoom'    => melkinoContactCleanZoom($body['officeZoom'] ?? null),

    /* تصویر نقشه دفتر (جایگزین نقشه زنده) */
    'mapImage'      => melkinoContactCleanMapImage($body['mapImage'] ?? ''),

    'bale'          => melkinoContactCleanUrl($body['bale'] ?? ''),
    'customCards'   => [],
];

/* ---------- کارت‌های سفارشی (دقیقاً ۵ عدد) ---------- */

$incomingCards = $body['customCards'] ?? [];

if (!is_array($incomingCards)) {
    $incomingCards = [];
}

for ($i = 0; $i < 5; $i++) {

    $card = $incomingCards[$i] ?? [];

    if (!is_array($card)) {
        $card = [];
    }

    $clean['customCards'][$i] = [
        'label'     => melkinoContactCleanText($card['label'] ?? '', 80),
        'messenger' => melkinoContactCleanText($card['messenger'] ?? '', 60),
        'url'       => melkinoContactCleanUrl($card['url'] ?? ''),
        'icon'      => melkinoContactCleanIcon($card['icon'] ?? ''),
    ];
}

/* ---------- ذخیره ---------- */

try {

    melkinoEnsureSettingsTable($pdo);

    $adminId = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;

    $ok = dbSettingSet(
        $pdo,
        'contact',
        'info',
        $clean,
        'json',
        $adminId
    );

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'ذخیره‌سازی در دیتابیس ناموفق بود.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'اطلاعات با موفقیت روی سرور ذخیره شد.',
        'data'    => $clean,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {

    error_log('save-contact-settings POST error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'خطا در ذخیره‌سازی: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
