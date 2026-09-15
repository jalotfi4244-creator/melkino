<?php
/*
|--------------------------------------------------------------------------
| درخواست کد یک‌بارمصرف (OTP)
|--------------------------------------------------------------------------
| اولویت ارسال: تلگرام (اگر این شماره قبلاً از تلگرام وارد شده و
| chat_id شناخته‌شده دارد) → بله (همین حالت) → پیامک (در صورت تنظیم
| سرویس) → نمایش مستقیم روی صفحه (فقط وقتی هیچ‌کدام از سه مورد بالا
| در دسترس نیست؛ برای این‌که کاربر هیچ‌وقت کاملاً گیر نکند).
|--------------------------------------------------------------------------
*/

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/telegram.php';
require_once __DIR__ . '/../helpers/bale.php';
require_once __DIR__ . '/../helpers/sms.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

$rawPhone = trim((string)($body['phone'] ?? ''));

// نرمال‌سازی: تبدیل ارقام فارسی/عربی به انگلیسی
$phone = strtr($rawPhone, [
    '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
    '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
]);
$phone = preg_replace('/\D/', '', $phone);
if (strpos($phone, '98') === 0 && strlen($phone) === 12) {
    $phone = '0' . substr($phone, 2);
}

if (!preg_match('/^09\d{9}$/', $phone)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'شماره موبایل معتبر نیست (فرمت درست: 09123456789).'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // محدودیت نرخ: حداکثر ۳ درخواست کد برای یک شماره در ۱۰ دقیقه‌ی اخیر،
    // تا از سوءاستفاده (اسپم کردن پیامک/پیام دیگران) جلوگیری شود.
    $rateCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM otp_codes WHERE phone = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)"
    );
    $rateCheck->execute([$phone]);
    if ((int)$rateCheck->fetchColumn() >= 3) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'تعداد درخواست کد برای این شماره زیاد بوده؛ چند دقیقه‌ی دیگر دوباره امتحان کن.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', time() + 120); // اعتبار ۲ دقیقه

    // پیدا کردن کانال ارسال: آیا این شماره قبلاً با تلگرام یا بله وارد شده؟
    $known = $pdo->prepare('SELECT telegram_id, bale_id FROM users WHERE phone = ? LIMIT 1');
    $known->execute([$phone]);
    $knownRow = $known->fetch(PDO::FETCH_ASSOC) ?: [];

    $channel = 'screen';
    $telegramChatId = null;
    $baleChatId = null;
    $showOnScreen = false;
    $sendResult = null;

    if (!empty($knownRow['telegram_id'])) {
        $sendResult = telegramSendMessage($knownRow['telegram_id'], "کد ورود ملکینو: <b>$code</b>\nاین کد تا ۲ دقیقه معتبر است.");
        if ($sendResult['success']) {
            $channel = 'telegram';
            $telegramChatId = $knownRow['telegram_id'];
        }
    }

    if ($channel === 'screen' && !empty($knownRow['bale_id'])) {
        $sendResult = baleSendMessage($knownRow['bale_id'], "کد ورود ملکینو: $code\nاین کد تا ۲ دقیقه معتبر است.");
        if ($sendResult['success']) {
            $channel = 'bale';
            $baleChatId = $knownRow['bale_id'];
        }
    }

    if ($channel === 'screen') {
        $smsResult = smsSendCode($phone, $code);
        if ($smsResult['success']) {
            $channel = 'sms';
        }
    }

    if ($channel === 'screen') {
        // نه تلگرام/بله شناخته‌شده بود، نه پیامک تنظیم شده — طبق
        // نیازمندی، کد مستقیم در پاسخ برگردانده می‌شود تا کاربر
        // گیر نکند (این فقط برای زمانی است که هیچ کانال دیگری
        // پیکربندی نشده باشد؛ روی سایت واقعی و در حالت production
        // پیشنهاد می‌شود حتماً یکی از سه کانال را فعال کنی).
        $showOnScreen = true;
    }

    $insert = $pdo->prepare(
        "INSERT INTO otp_codes (phone, code, channel, telegram_chat_id, bale_chat_id, attempts, is_used, expires_at)
         VALUES (?, ?, ?, ?, ?, 0, 0, ?)"
    );
    $insert->execute([$phone, $code, $channel, $telegramChatId, $baleChatId, $expiresAt]);

    $response = [
        'success' => true,
        'channel' => $channel,
        'message' => match ($channel) {
            'telegram' => 'کد به تلگرام شما ارسال شد.',
            'bale' => 'کد به بله شما ارسال شد.',
            'sms' => 'کد با پیامک ارسال شد.',
            default => 'کانال ارسال پیامی در دسترس نیست؛ کد به‌صورت موقت روی همین صفحه نمایش داده می‌شود.',
        },
    ];
    if ($showOnScreen) {
        $response['code'] = $code;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطا در ارسال کد.'], JSON_UNESCAPED_UNICODE);
}
