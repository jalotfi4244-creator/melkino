<?php
/*
|--------------------------------------------------------------------------
| ارسال پیامک (اختیاری)
|--------------------------------------------------------------------------
| تنظیمات از پنل ادمین (تب «ربات و کانال» → کارت پیامک) خوانده می‌شود و
| در صورت نبودن، از ثابت‌های SMS_API_KEY/SMS_API_URL در config.php.
| اگر پنل پیامک غیرفعال باشد یا چیزی تنظیم نشده باشد، false برمی‌گردد تا
| فراخوان (request-otp.php) به‌جای پیامک، کد را مستقیم روی صفحه نشان بدهد.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/telegram.php'; // برای melkinoHttpPost مشترک
require_once __DIR__ . '/bot-settings.php'; // برای melkinoSmsSettings

/**
 * خواندن تنظیمات موثر پیامک (پنل ادمین با fallback به ثابت‌ها).
 *
 * @return array ['enabled'=>bool, 'api_key'=>string, 'api_url'=>string, 'sender_line'=>string]
 */
function smsEffectiveSettings(): array
{
    if (function_exists('melkinoSmsSettings')) {
        return melkinoSmsSettings();
    }
    return [
        'enabled'     => defined('SMS_API_KEY') && (string)SMS_API_KEY !== ''
                      && defined('SMS_API_URL') && (string)SMS_API_URL !== '',
        'api_key'     => defined('SMS_API_KEY') ? (string)SMS_API_KEY : '',
        'api_url'     => defined('SMS_API_URL') ? (string)SMS_API_URL : '',
        'sender_line' => defined('SMS_SENDER_LINE') ? (string)SMS_SENDER_LINE : '',
    ];
}

/**
 * ارسال یک متن دلخواه با پنل پیامک.
 *
 * @return array ['success'=>bool, 'message'=>string]
 */
function smsSendText(string $phone, string $text): array
{
    $settings = smsEffectiveSettings();

    if (!$settings['enabled']) {
        return ['success' => false, 'message' => 'پنل پیامک غیرفعال است. از تب «ربات و کانال» آن را فعال کن.'];
    }
    if ($settings['api_key'] === '' || $settings['api_url'] === '') {
        return ['success' => false, 'message' => 'سرویس پیامک تنظیم نشده است.'];
    }

    $postFields = http_build_query([
        'apikey'   => $settings['api_key'],
        'sender'   => $settings['sender_line'],
        'receptor' => $phone,
        'message'  => $text,
    ]);

    $response = melkinoHttpPost($settings['api_url'], $postFields);
    if ($response === null) {
        return ['success' => false, 'message' => 'اتصال به سرویس پیامک برقرار نشد.'];
    }

    // فرمت پاسخ بین سرویس‌های مختلف پیامکی فرق می‌کنه؛ اینجا فقط
    // بررسی می‌کنیم درخواست بدون خطای HTTP انجام شده. اگر سرویس
    // واقعی‌ات فرمت پاسخ متفاوتی داره، این بخش رو با مستندات همون
    // سرویس تطبیق بده.
    return ['success' => true, 'message' => 'پیامک ارسال شد.'];
}

/**
 * @return array ['success'=>bool, 'message'=>string]
 */
function smsSendCode(string $phone, string $code): array
{
    return smsSendText($phone, 'کد ورود ملکینو: ' . $code . ' (اعتبار ۲ دقیقه)');
}
