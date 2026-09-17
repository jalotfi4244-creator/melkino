<?php
/*
|--------------------------------------------------------------------------
| انتشار واقعی آگهی در گروه/کانال تلگرام
|--------------------------------------------------------------------------
| قبلاً دکمه‌ی «انتشار در تلگرام» در پنل ادمین فقط یک alert نمایشی
| بود و هیچ پیامی واقعاً ارسال نمی‌شد. این فایل با استفاده از Bot API
| تلگرام، متن کامل آگهی را به گروه/کانال تنظیم‌شده ارسال می‌کند.
|
| پیش‌نیاز: باید ثابت‌های BOT_TOKEN و CHANNEL_ID در config.php با
| مقادیر واقعی جایگزین شوند (نه مقدار پیش‌فرض «توکن_ربات_تلگرام»):
|   ۱. با @BotFather یک بات بساز و توکنش را در BOT_TOKEN بگذار.
|   ۲. همان بات را به گروه/کانال موردنظر، به‌عنوان ادمین اضافه کن.
|   ۳. آیدی عددی گروه/کانال (یا @username در صورت عمومی بودن) را
|      در CHANNEL_ID بگذار.
|--------------------------------------------------------------------------
*/

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/bot-settings.php';

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = [];
$adId = trim((string)($payload['id'] ?? $_POST['id'] ?? ''));

if ($adId === '') {
    echo json_encode(['success' => false, 'message' => 'شناسه‌ی آگهی نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// توکن و کانال از پنل ادمین (دیتابیس) خوانده می‌شوند و در صورت نبودن،
// از ثابت‌های config.php — مثل بقیه‌ی مسیرهای انتشار.
$tgToken = function_exists('melkinoTelegramToken')
    ? (string)melkinoTelegramToken()
    : (defined('BOT_TOKEN') ? (string)BOT_TOKEN : '');
$tgChannel = function_exists('melkinoChannelId')
    ? (string)melkinoChannelId()
    : (defined('CHANNEL_ID') ? (string)CHANNEL_ID : '');

if ($tgToken === '' || $tgToken === 'توکن_ربات_تلگرام') {
    echo json_encode([
        'success' => false,
        'message' => 'توکن ربات تلگرام تنظیم نشده. از تب «ربات و کانال» پنل ادمین آن را وارد و ذخیره کن.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($tgChannel === '' || $tgChannel === '@آیدی_کانال') {
    echo json_encode([
        'success' => false,
        'message' => 'آیدی گروه/کانال تلگرام تنظیم نشده. از تب «ربات و کانال» پنل ادمین آن را وارد و ذخیره کن.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM ads WHERE id = ? LIMIT 1");
    $stmt->execute([$adId]);
    $ad = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'خطا در خواندن اطلاعات آگهی.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$ad) {
    echo json_encode(['success' => false, 'message' => 'آگهی پیدا نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
   ساخت متن پیام از روی اطلاعات آگهی
   =========================================================
   از سازنده‌ی مشترک استفاده می‌شود تا انتخاب فیلدها و متن ثابت
   بالا/پایین آگهی (تنظیم‌شده در تب «ربات و کانال») اعمال شود و خروجی
   همه‌ی مسیرهای انتشار (سرور/مرورگر، تلگرام/بله) یکسان باشد.
   ========================================================= */

$messageText = melkinoAdMessageText($ad, true, 'telegram');

/* =========================================================
   ارسال به تلگرام
   ========================================================= */

$apiUrl = 'https://api.telegram.org/bot' . $tgToken . '/sendMessage';
$postFields = http_build_query([
    'chat_id' => $tgChannel,
    'text' => $messageText,
    'parse_mode' => 'HTML',
]);

$response = false;
$curlError = '';

if (function_exists('curl_init')) {
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => $postFields,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
} else {
    // اگر افزونه‌ی curl روی سرور فعال نباشد
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $postFields,
            'timeout' => 20,
        ],
    ]);
    $response = @file_get_contents($apiUrl, false, $context);
    if ($response === false) {
        $curlError = 'file_get_contents failed';
    }
}

if ($response === false) {
    echo json_encode([
        'success' => false,
        'message' => 'اتصال به سرور تلگرام برقرار نشد: ' . $curlError,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = json_decode($response, true);

if (empty($result['ok'])) {
    $tgError = $result['description'] ?? 'خطای نامشخص از سمت تلگرام';
    echo json_encode([
        'success' => false,
        'message' => 'تلگرام درخواست را رد کرد: ' . $tgError,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$messageId = $result['result']['message_id'] ?? null;

try {
    $update = $pdo->prepare(
        "UPDATE ads SET telegram_message_id = ?, telegram_channel_id = ?, telegram_published_at = NOW() WHERE id = ?"
    );
    $update->execute([$messageId, (string)CHANNEL_ID, $adId]);
} catch (Throwable $e) {
    // پیام با موفقیت ارسال شده؛ فقط ثبتش در دیتابیس ناموفق بود
}

echo json_encode([
    'success' => true,
    'message' => 'آگهی با موفقیت در تلگرام منتشر شد.',
    'telegram_message_id' => $messageId,
], JSON_UNESCAPED_UNICODE);
