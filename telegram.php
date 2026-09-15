<?php
/*
|--------------------------------------------------------------------------
| توابع کمکی تلگرام: ارسال پیام و تأیید initData
|--------------------------------------------------------------------------
| توابع اصلی (melkinoVerifyTelegramInitData و melkinoUpsertUser) در
| db_helpers.php تعریف شده‌اند تا در کل پروژه (از جمله فایل‌های قدیمی‌تر
| که پیش از این ساختار api/helpers ساخته شده بودند) قابل استفاده باشند.
| این فایل یک لایه‌ی نازک روی همان توابع است، به‌علاوه‌ی تابع ارسال پیام.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db_helpers.php';

/**
 * initData خام دریافتی از Telegram.WebApp.initData را تأیید می‌کند.
 * خروجی: ['id','username','first_name','last_name'] یا null.
 */
function telegramVerifyInitData(string $initData): ?array
{
    return melkinoVerifyTelegramInitData($initData);
}

/**
 * یک پیام متنی به یک chat_id مشخص (کاربر یا گروه/کانال) از طریق بات
 * تلگرام ارسال می‌کند.
 *
 * @param string $chatId  آیدی عددی چت، یا @username برای کانال عمومی
 * @param string $text    متن پیام (از پیش HTML-escape شده در صورت نیاز)
 * @return array ['success'=>bool, 'message'=>string, 'message_id'=>?int]
 */
function telegramSendMessage(string $chatId, string $text): array
{
    if (!defined('BOT_TOKEN') || BOT_TOKEN === '' || BOT_TOKEN === 'توکن_ربات_تلگرام') {
        return ['success' => false, 'message' => 'BOT_TOKEN تنظیم نشده است.', 'message_id' => null];
    }
    if ($chatId === '') {
        return ['success' => false, 'message' => 'chat_id نامعتبر است.', 'message_id' => null];
    }

    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage';
    $postFields = http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ]);

    $response = melkinoHttpPost($url, $postFields);
    if ($response === null) {
        return ['success' => false, 'message' => 'اتصال به سرور تلگرام برقرار نشد.', 'message_id' => null];
    }

    $result = json_decode($response, true);
    if (empty($result['ok'])) {
        return ['success' => false, 'message' => $result['description'] ?? 'خطای نامشخص از تلگرام', 'message_id' => null];
    }

    return ['success' => true, 'message' => 'ارسال شد', 'message_id' => $result['result']['message_id'] ?? null];
}

/**
 * یک درخواست POST ساده با curl (یا فال‌بک file_get_contents) انجام می‌دهد.
 * چون در چند فایل (تلگرام، بله) به همین شکل نیاز است، اینجا مشترک شده.
 */
if (!function_exists('melkinoHttpPost')) {
    function melkinoHttpPost(string $url, string $postFields, array $extraHeaders = []): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_HTTPHEADER => $extraHeaders,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            return $response === false ? null : $response;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n" . implode("\r\n", $extraHeaders),
                'content' => $postFields,
                'timeout' => 20,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        return $response === false ? null : $response;
    }
}
