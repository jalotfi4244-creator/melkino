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

require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/bot-settings.php';

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
    // توکن می‌تواند از پنل ادمین (دیتابیس) یا فایل محرمانه بیاید
    $token = function_exists('melkinoTelegramToken') ? melkinoTelegramToken() : (defined('BOT_TOKEN') ? (string)BOT_TOKEN : '');
    if ($token === '' || $token === 'توکن_ربات_تلگرام') {
        return ['success' => false, 'message' => 'توکن تلگرام تنظیم نشده است.', 'message_id' => null];
    }
    if ($chatId === '') {
        return ['success' => false, 'message' => 'chat_id نامعتبر است.', 'message_id' => null];
    }

    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
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
    /**
     * یک درخواست POST ساده با curl (یا فال‌بک file_get_contents).
     * اگر ادمین در تب «ربات و کانال» یک پروکسی ثبت کرده باشد، درخواست از
     * آن عبور می‌کند — این برای هاست‌هایی که به api.telegram.org دسترسی
     * مستقیم ندارند ضروری است.
     */
    function melkinoHttpPost(string $url, string $postFields, array $extraHeaders = []): ?string
    {
        // خواندنِ پروکسیِ تنظیم‌شده (در صورت وجود)
        $proxy = function_exists('melkinoProxy') ? melkinoProxy() : '';
        $proxy = trim((string)$proxy);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_HTTPHEADER => $extraHeaders,
            ]);

            if ($proxy !== '') {
                // پشتیبانی از انواع پروکسی: http و socks5/socks4
                if (defined('CURLOPT_PROXY')) {
                    curl_setopt($ch, CURLOPT_PROXY, $proxy);
                }
                $lower = strtolower($proxy);
                if (strpos($lower, 'socks5h://') === 0 && defined('CURLPROXY_SOCKS5_HOSTNAME')) {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
                } elseif (strpos($lower, 'socks5://') === 0 && defined('CURLPROXY_SOCKS5')) {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                } elseif (strpos($lower, 'socks4://') === 0 && defined('CURLPROXY_SOCKS4')) {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
                }
            }

            $response = curl_exec($ch);
            $curlErr = curl_errno($ch);
            curl_close($ch);
            return $response === false ? null : $response;
        }

        $httpOptions = [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n" . implode("\r\n", $extraHeaders),
            'content' => $postFields,
            'timeout' => 20,
        ];

        if ($proxy !== '') {
            // فال‌بکِ stream فقط از پروکسیِ HTTP پشتیبانی می‌کند
            $httpOptions['proxy'] = preg_replace('#^https?://#i', 'tcp://', $proxy);
            $httpOptions['request_fulluri'] = true;
        }

        $context = stream_context_create(['http' => $httpOptions]);
        $response = @file_get_contents($url, false, $context);
        return $response === false ? null : $response;
    }
}
