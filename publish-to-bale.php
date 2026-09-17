<?php
/*
|--------------------------------------------------------------------------
| انتشار آگهی در کانال/گروه بله
|--------------------------------------------------------------------------
| همسان با publish-to-telegram.php، اما از طریق Bot API بله
| (https://tapi.bale.ai) و با استفاده از توکن و کانالی که ادمین در تب
| «ربات و کانال» تنظیم کرده است.
|
| ترتیب ارسال:
|   ۱. اگر تصویری برای آگهی ثبت شده باشد → sendPhoto همراه با توضیحات
|   ۲. در صورت نبود تصویر یا خطا → sendMessage (متن کامل آگهی)
|--------------------------------------------------------------------------
*/

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
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
if (!is_array($payload)) {
    $payload = [];
}
$adId = trim((string)($payload['id'] ?? $_POST['id'] ?? ''));

if ($adId === '') {
    echo json_encode(['success' => false, 'message' => 'شناسه‌ی آگهی نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
   بررسی تنظیمات ربات بله
   ========================================================= */

$token = function_exists('melkinoBaleToken') ? melkinoBaleToken() : (defined('BALE_BOT_TOKEN') ? (string)BALE_BOT_TOKEN : '');
$channel = function_exists('melkinoBotSetting') ? melkinoBotSetting('bale_channel') : '';
$channel = trim($channel);

if ($token === '' || $token === 'توکن_ربات_بله') {
    echo json_encode([
        'success' => false,
        'message' => 'توکن ربات بله تنظیم نشده است. از تب «ربات و کانال» در پنل ادمین توکن را وارد و ذخیره کن.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($channel === '') {
    echo json_encode([
        'success' => false,
        'message' => 'شناسه کانال بله تنظیم نشده است. از تب «ربات و کانال» در پنل ادمین شناسه کانال (مثل @melkino) را ذخیره کن.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
   خواندن اطلاعات آگهی
   ========================================================= */

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
   ساخت متن پیام (برای بله از متن ساده استفاده می‌شود
   تا در همه‌ی کلاینت‌ها درست نمایش داده شود)
   ========================================================= */

function baleMoney($value): string
{
    $raw = trim((string)$value);
    if ($raw === '' || !is_numeric(str_replace(',', '', $raw))) {
        return '';
    }
    return number_format((float)str_replace(',', '', $raw), 0, '.', ',') . ' تومان';
}

$lines = [];
$lines[] = '🏠 ' . (string)($ad['title'] ?: 'آگهی ملک');
$lines[] = '';
$lines[] = '📌 نوع معامله: ' . (string)($ad['transaction_type'] ?: '-');
$lines[] = '🏷️ نوع ملک: ' . (string)($ad['property_type'] ?: '-');

if (!empty($ad['location'])) {
    $lines[] = '📍 موقعیت: ' . (string)$ad['location'];
}
if (!empty($ad['address'])) {
    $lines[] = '🗺️ آدرس: ' . (string)$ad['address'];
}
if (!empty($ad['area'])) {
    $lines[] = '📐 متراژ: ' . (string)$ad['area'] . ' متر';
}
if (!empty($ad['rooms'])) {
    $lines[] = '🛏️ تعداد اتاق: ' . (string)$ad['rooms'];
}
if (!empty($ad['floor'])) {
    $lines[] = '🏢 طبقه: ' . (string)$ad['floor'];
}
if (!empty($ad['year'])) {
    $lines[] = '📅 سال ساخت: ' . (string)$ad['year'];
}

if (empty($ad['price_hidden'])) {
    $priceLine = '';
    if (!empty($ad['price_sell'])) {
        $priceLine = '💰 قیمت فروش: ' . baleMoney($ad['price_sell']);
    } elseif (!empty($ad['full_rent_enabled']) && !empty($ad['full_rent'])) {
        $priceLine = '💰 اجاره کامل: ' . baleMoney($ad['full_rent']);
    } elseif (!empty($ad['deposit']) || !empty($ad['rent_monthly'])) {
        $priceLine = '💰 ودیعه: ' . baleMoney($ad['deposit']) . ' | اجاره: ' . baleMoney($ad['rent_monthly']);
    } elseif (!empty($ad['total_price'])) {
        $priceLine = '💰 قیمت کل: ' . baleMoney($ad['total_price']);
    }
    if ($priceLine !== '') {
        $lines[] = $priceLine;
    }
} else {
    $lines[] = '💰 قیمت: توافقی (تماس بگیرید)';
}

if (!empty($ad['description'])) {
    $lines[] = '';
    $lines[] = '📝 ' . (string)$ad['description'];
}

/**
 * نکته‌ی امنیتی و حریم خصوصی:
 *   نام و شماره تماسِ ثبت‌کننده‌ی آگهی هرگز منتشر نمی‌شود؛
 *   به‌جای آن شماره‌ی مشاوری که در پنل ادمین ثبت شده درج می‌شود.
 *   تابع melkinoAdMessageText تابعِ مشترک با سایر مسیرهاست تا خروجی
 *   همه‌ی مسیرهای انتشار دقیقاً یکسان باشد.
 *   (برای بله از parse_mode استفاده نمی‌کنیم، پس html=false است)
 */
$messageText = function_exists('melkinoAdMessageText')
    ? melkinoAdMessageText($ad, false)
    : implode("\n", $lines);

if (!function_exists('melkinoAdMessageText')) {
    $lines[] = '';
    $lines[] = '📞 جهت اطلاعات بیشتر با ما تماس بگیرید:';
    if (function_exists('getConsultantPhone')) {
        $cPhone = trim((string)getConsultantPhone());
        if ($cPhone !== '') {
            $lines[] = '☎️ ' . $cPhone;
        }
    }
    $lines[] = '';
    $lines[] = '🔗 کد آگهی: ' . (string)$ad['id'];
    $messageText = implode("\n", $lines);
}

/* =========================================================
   یافتن تصویر آگهی (در صورت وجود)
   ========================================================= */

$imagePath = '';
try {
    $imgStmt = $pdo->prepare(
        "SELECT filename FROM images
          WHERE ad_id = ? AND is_selected = 1 AND publish_publicly = 1
          ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1"
    );
    $imgStmt->execute([$adId]);
    $imageName = trim((string)$imgStmt->fetchColumn());

    if ($imageName !== '') {
        $candidates = [
            __DIR__ . '/' . ltrim(str_replace('\\', '/', $imageName), '/'),
            __DIR__ . '/uploads/' . ltrim(str_replace('\\', '/', $imageName), '/'),
            __DIR__ . '/uploads/' . basename($imageName),
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $imagePath = $candidate;
                break;
            }
        }
    }
} catch (Throwable $e) {
    $imagePath = '';
}

/* =========================================================
   ارسال به بله
   ========================================================= */

$apiBase = 'https://tapi.bale.ai/bot' . $token;
$result = null;
$sentWithPhoto = false;

// تلاش اول: ارسال تصویر همراه با توضیحات
if ($imagePath !== '' && function_exists('curl_init')) {
    $postFields = [
        'chat_id' => $channel,
        'caption' => $messageText,
        'photo' => new CURLFile($imagePath),
    ];

    $ch = curl_init($apiBase . '/sendPhoto');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => $postFields,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode((string)$response, true);
    if (is_array($decoded) && !empty($decoded['ok'])) {
        $result = $decoded;
        $sentWithPhoto = true;
    }
}

// تلاش دوم: ارسال متن ساده
if ($result === null) {
    $postFields = http_build_query([
        'chat_id' => $channel,
        'text' => $messageText,
    ]);

    $response = function_exists('melkinoHttpPost')
        ? melkinoHttpPost($apiBase . '/sendMessage', $postFields)
        : false;

    if ($response === false || $response === null) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $postFields,
                'timeout' => 25,
            ],
        ]);
        $response = @file_get_contents($apiBase . '/sendMessage', false, $context);
    }

    if ($response === false || $response === null || $response === '') {
        echo json_encode([
            'success' => false,
            'message' => 'ارتباط با سرور بله برقرار نشد. توکن و اتصال اینترنت سرور را بررسی کن.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = json_decode((string)$response, true);
}

if (!is_array($result) || empty($result['ok'])) {
    $baleError = is_array($result)
        ? ($result['description'] ?? 'خطای نامشخص از سمت بله')
        : 'پاسخ نامعتبر از سرور بله';

    echo json_encode([
        'success' => false,
        'message' => 'بله درخواست را رد کرد: ' . $baleError,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$messageId = $result['result']['message_id'] ?? null;

/* =========================================================
   ثبت در دیتابیس (در صورت نبود ستون، ستون‌ها ساخته می‌شوند)
   ========================================================= */

try {
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM ads')->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $columns[strtolower((string)$col['Field'])] = true;
    }

    if (!isset($columns['bale_message_id'])) {
        $pdo->exec("ALTER TABLE ads ADD COLUMN bale_message_id VARCHAR(100) NULL");
    }
    if (!isset($columns['bale_channel_id'])) {
        $pdo->exec("ALTER TABLE ads ADD COLUMN bale_channel_id VARCHAR(191) NULL");
    }
    if (!isset($columns['bale_published_at'])) {
        $pdo->exec("ALTER TABLE ads ADD COLUMN bale_published_at DATETIME NULL");
    }

    $update = $pdo->prepare(
        "UPDATE ads SET bale_message_id = ?, bale_channel_id = ?, bale_published_at = NOW() WHERE id = ?"
    );
    $update->execute([$messageId, $channel, $adId]);
} catch (Throwable $e) {
    // پیام با موفقیت ارسال شده؛ فقط ثبت آن در دیتابیس ناموفق بود
}

echo json_encode([
    'success' => true,
    'message' => $sentWithPhoto
        ? 'آگهی با تصویر در کانال بله منتشر شد.'
        : 'آگهی در کانال بله منتشر شد.',
    'bale_message_id' => $messageId,
    'with_photo' => $sentWithPhoto,
], JSON_UNESCAPED_UNICODE);
