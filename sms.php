<?php
/*
|--------------------------------------------------------------------------
| ارسال پیامک (اختیاری)
|--------------------------------------------------------------------------
| این پروژه به هیچ سرویس پیامکی خاصی متصل نیست، چون هزینه‌بره و نیاز
| به قرارداد جداگانه داره. این تابع فقط یک اسکلت آماده‌ست: اگر
| SMS_API_KEY/SMS_API_URL در config.php پر بشه، سعی می‌کنه از یک API
| عمومی و رایج (Kavenegar-style REST) پیامک بفرسته. اگر پر نباشه یا
| ارسال ناموفق باشه، false برمی‌گردونه تا فراخوان (api/request-otp.php)
| به‌جای پیامک، کد رو مستقیم روی صفحه نشون بده.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/telegram.php'; // برای melkinoHttpPost مشترک

/**
 * @return array ['success'=>bool, 'message'=>string]
 */
function smsSendCode(string $phone, string $code): array
{
    if (!defined('SMS_API_KEY') || SMS_API_KEY === '' || !defined('SMS_API_URL') || SMS_API_URL === '') {
        return ['success' => false, 'message' => 'سرویس پیامک تنظیم نشده است.'];
    }

    $text = 'کد ورود ملکینو: ' . $code . ' (اعتبار ۲ دقیقه)';

    $postFields = http_build_query([
        'apikey' => SMS_API_KEY,
        'sender' => defined('SMS_SENDER_LINE') ? SMS_SENDER_LINE : '',
        'receptor' => $phone,
        'message' => $text,
    ]);

    $response = melkinoHttpPost(SMS_API_URL, $postFields);
    if ($response === null) {
        return ['success' => false, 'message' => 'اتصال به سرویس پیامک برقرار نشد.'];
    }

    // فرمت پاسخ بین سرویس‌های مختلف پیامکی فرق می‌کنه؛ اینجا فقط
    // بررسی می‌کنیم درخواست بدون خطای HTTP انجام شده. اگر سرویس
    // واقعی‌ات فرمت پاسخ متفاوتی داره، این بخش رو با مستندات همون
    // سرویس تطبیق بده.
    return ['success' => true, 'message' => 'پیامک ارسال شد.'];
}
