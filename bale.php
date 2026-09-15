<?php
/*
|--------------------------------------------------------------------------
| توابع کمکی بله: ارسال پیام و تأیید initData
|--------------------------------------------------------------------------
| طبق مستندات رسمی بله (docs.bale.ai/miniapp)، الگوریتم تأیید initData
| دقیقاً همون HMAC-SHA256 تلگرامه؛ فقط توکن بات و آدرس API فرق داره
| (API بله روی https://tapi.bale.ai است، نه api.telegram.org).
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../db_helpers.php';
require_once __DIR__ . '/telegram.php'; // برای استفاده از melkinoHttpPost مشترک

/**
 * initData خام دریافتی از Bale.WebApp.initData را تأیید می‌کند.
 * خروجی: ['id','username','first_name','last_name'] یا null.
 */
function baleVerifyInitData(string $initData): ?array
{
    return melkinoVerifyBaleInitData($initData);
}

/**
 * یک پیام متنی به یک chat_id مشخص از طریق بات بله ارسال می‌کند.
 *
 * @return array ['success'=>bool, 'message'=>string, 'message_id'=>?int]
 */
function baleSendMessage(string $chatId, string $text): array
{
    if (!defined('BALE_BOT_TOKEN') || BALE_BOT_TOKEN === '' || BALE_BOT_TOKEN === 'توکن_ربات_بله') {
        return ['success' => false, 'message' => 'BALE_BOT_TOKEN تنظیم نشده است.', 'message_id' => null];
    }
    if ($chatId === '') {
        return ['success' => false, 'message' => 'chat_id نامعتبر است.', 'message_id' => null];
    }

    $url = 'https://tapi.bale.ai/bot' . BALE_BOT_TOKEN . '/sendMessage';
    $postFields = http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
    ]);

    $response = melkinoHttpPost($url, $postFields);
    if ($response === null) {
        return ['success' => false, 'message' => 'اتصال به سرور بله برقرار نشد.', 'message_id' => null];
    }

    $result = json_decode($response, true);
    if (empty($result['ok'])) {
        return ['success' => false, 'message' => $result['description'] ?? 'خطای نامشخص از بله', 'message_id' => null];
    }

    return ['success' => true, 'message' => 'ارسال شد', 'message_id' => $result['result']['message_id'] ?? null];
}
