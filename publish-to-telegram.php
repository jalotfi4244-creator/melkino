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

if (!defined('BOT_TOKEN') || BOT_TOKEN === '' || BOT_TOKEN === 'توکن_ربات_تلگرام') {
    echo json_encode([
        'success' => false,
        'message' => 'توکن ربات تلگرام تنظیم نشده. در فایل config.php مقدار BOT_TOKEN را با توکن واقعی بات جایگزین کن.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!defined('CHANNEL_ID') || CHANNEL_ID === '' || CHANNEL_ID === '@آیدی_کانال') {
    echo json_encode([
        'success' => false,
        'message' => 'آیدی گروه/کانال تلگرام تنظیم نشده. در فایل config.php مقدار CHANNEL_ID را با آیدی واقعی گروه/کانال جایگزین کن.',
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
   ========================================================= */

function tgEsc(string $text): string
{
    // فرار دادن کاراکترهای ویژه‌ی HTML parse mode تلگرام
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
}

function tgMoney($value): string
{
    $raw = trim((string)$value);
    if ($raw === '' || !is_numeric(str_replace(',', '', $raw))) return '';
    return number_format((float)str_replace(',', '', $raw), 0, '.', ',') . ' تومان';
}

$lines = [];

$lines[] = '🏠 <b>' . tgEsc((string)($ad['title'] ?: 'آگهی ملک')) . '</b>';
$lines[] = '';
$lines[] = '📌 نوع معامله: ' . tgEsc((string)($ad['transaction_type'] ?: '-'));
$lines[] = '🏷️ نوع ملک: ' . tgEsc((string)($ad['property_type'] ?: '-'));

if (!empty($ad['location'])) {
    $lines[] = '📍 موقعیت: ' . tgEsc((string)$ad['location']);
}
if (!empty($ad['address'])) {
    $lines[] = '🗺️ آدرس: ' . tgEsc((string)$ad['address']);
}
if (!empty($ad['area'])) {
    $lines[] = '📐 متراژ: ' . tgEsc((string)$ad['area']) . ' متر';
}
if (!empty($ad['rooms'])) {
    $lines[] = '🛏️ تعداد اتاق: ' . tgEsc((string)$ad['rooms']);
}
if (!empty($ad['floor'])) {
    $lines[] = '🏢 طبقه: ' . tgEsc((string)$ad['floor']);
}
if (!empty($ad['year'])) {
    $lines[] = '📅 سال ساخت: ' . tgEsc((string)$ad['year']);
}

$priceHidden = !empty($ad['price_hidden']);
if (!$priceHidden) {
    $priceLine = '';
    if (!empty($ad['price_sell'])) {
        $priceLine = '💰 قیمت فروش: ' . tgMoney($ad['price_sell']);
    } elseif (!empty($ad['full_rent_enabled']) && !empty($ad['full_rent'])) {
        $priceLine = '💰 اجاره کامل: ' . tgMoney($ad['full_rent']);
    } elseif (!empty($ad['deposit']) || !empty($ad['rent_monthly'])) {
        $priceLine = '💰 ودیعه: ' . tgMoney($ad['deposit']) . ' | اجاره: ' . tgMoney($ad['rent_monthly']);
    } elseif (!empty($ad['total_price'])) {
        $priceLine = '💰 قیمت کل: ' . tgMoney($ad['total_price']);
    }
    if ($priceLine !== '') {
        $lines[] = $priceLine;
    }
} else {
    $lines[] = '💰 قیمت: توافقی (تماس بگیرید)';
}

if (!empty($ad['description'])) {
    $lines[] = '';
    $lines[] = '📝 ' . tgEsc((string)$ad['description']);
}

$lines[] = '';
$lines[] = '👤 تماس: ' . tgEsc((string)($ad['last_name'] ?: '-'));
if (!empty($ad['phone'])) {
    $lines[] = '📞 شماره تماس: ' . tgEsc((string)$ad['phone']);
}

$lines[] = '';
$lines[] = '🔗 کد آگهی: ' . tgEsc((string)$ad['id']);

$messageText = implode("\n", $lines);

/* =========================================================
   ارسال به تلگرام
   ========================================================= */

$apiUrl = 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage';
$postFields = http_build_query([
    'chat_id' => CHANNEL_ID,
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
