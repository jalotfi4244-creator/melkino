<?php
/**
|--------------------------------------------------------------------------
| تنظیمات ربات‌ها و کانال
|--------------------------------------------------------------------------
| ادمین می‌تواند توکن ربات، شناسه کانال و نام کاربری ربات را از پنل
| تغییر بدهد. مقادیر در جدول settings ذخیره می‌شوند.
|
| اولویت خواندن توکن:
|   1) مقدار ذخیره‌شده در دیتابیس (تنظیم‌شده توسط ادمین)
|   2) مقدار فایل config.secrets.php یا متغیر محیطی
|--------------------------------------------------------------------------
*/

// اطمینان از اینکه توابع dbSettingGet / dbSettingSet همیشه در دسترس باشند
// (این فایل ممکن است جایی لود شود که config.php هنوز اجرا نشده باشد)
require_once __DIR__ . '/db-settings.php';

if (!function_exists('melkinoBotSetting')) {
    function melkinoBotSetting(string $key, string $default = ''): string
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return $default;
        }
        try {
            $value = dbSettingGet($pdo, 'bots', $key, null);
        } catch (Throwable $e) {
            return $default;
        }
        return ($value === null || $value === '') ? $default : (string)$value;
    }
}

if (!function_exists('melkinoBotSettings')) {
    function melkinoBotSettings(): array
    {
        return [
            'telegram_token'        => melkinoBotSetting('telegram_token'),
            'telegram_channel'      => melkinoBotSetting('telegram_channel', defined('CHANNEL_ID') ? CHANNEL_ID : ''),
            'telegram_bot_username' => ltrim(melkinoBotSetting('telegram_bot_username'), '@'),
            'bale_token'            => melkinoBotSetting('bale_token'),
            'bale_channel'          => melkinoBotSetting('bale_channel'),
            'bale_bot_username'     => ltrim(melkinoBotSetting('bale_bot_username'), '@'),
            'http_proxy'            => melkinoBotSetting('http_proxy'),
            'updated_at'            => melkinoBotSetting('updated_at'),
        ];
    }
}

if (!function_exists('melkinoSaveBotSettings')) {
    function melkinoSaveBotSettings(array $data): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return false;
        }

        $adminId = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;

        $values = [
            'telegram_token'        => trim((string)($data['telegram_token'] ?? '')),
            'telegram_channel'      => trim((string)($data['telegram_channel'] ?? '')),
            'telegram_bot_username' => ltrim(trim((string)($data['telegram_bot_username'] ?? '')), '@'),
            'bale_token'            => trim((string)($data['bale_token'] ?? '')),
            'bale_channel'          => trim((string)($data['bale_channel'] ?? '')),
            'bale_bot_username'     => ltrim(trim((string)($data['bale_bot_username'] ?? '')), '@'),
            'http_proxy'            => trim((string)($data['http_proxy'] ?? '')),
        ];

        // اعتبارسنجی سبک
        foreach ($values as $k => $v) {
            if (strpos($k, '_token') !== false && $v !== '' && !preg_match('/^\d{5,}:[\w-]{20,}$/', $v)) {
                throw new InvalidArgumentException('فرمت توکن واردشده معتبر نیست.');
            }
            // نکته: شناسه‌ی کانال می‌تواند آیدیِ متنی (مانند @melkino) یا
            // شناسه‌ی عددی (مانند 123456789- یا 1001234567890-) باشد.
            // قبلاً فقط حالتِ متنی پذیرفته می‌شد و ذخیره کردنِ شناسه‌ی
            // عددی — که مطمئن‌ترین راه برای رفعِ خطای
            // «no such group or user» است — با خطا رد می‌شد.
            if (strpos($k, '_channel') !== false && $v !== '' && !preg_match('/^(@?[\w]{3,}|-?\d{5,})$/', $v)) {
                throw new InvalidArgumentException('فرمت شناسه کانال معتبر نیست. می‌تواند @آیدی یا شناسه‌ی عددی باشد.');
            }
            if ($k === 'http_proxy' && $v !== '' && !preg_match('#^(https?|socks5h?|socks4)://#i', $v)) {
                throw new InvalidArgumentException('فرمت پروکسی باید با http:// یا socks5:// شروع شود.');
            }
        }

        $values['updated_at'] = date('Y-m-d H:i:s');

        foreach ($values as $k => $v) {
            if (!dbSettingSet($pdo, 'bots', $k, $v, 'string', $adminId)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('melkinoTelegramToken')) {
    function melkinoTelegramToken(): string
    {
        $fromDb = melkinoBotSetting('telegram_token');
        if ($fromDb !== '') {
            return $fromDb;
        }
        return defined('BOT_TOKEN') ? (string)BOT_TOKEN : '';
    }
}

if (!function_exists('melkinoBaleToken')) {
    function melkinoBaleToken(): string
    {
        $fromDb = melkinoBotSetting('bale_token');
        if ($fromDb !== '') {
            return $fromDb;
        }
        return defined('BALE_BOT_TOKEN') ? (string)BALE_BOT_TOKEN : '';
    }
}

/**
 * پروکسیِ اختیاری برای ارتباط با سرورهای تلگرام/بله.
 * روی هاست‌هایی که دسترسی مستقیم به api.telegram.org ندارند، ادمین می‌تواند
 * نشانیِ یک پروکسی (مثلاً http://user:pass@1.2.3.4:8080 یا socks5://...) را
 * ثبت کند تا همه‌ی درخواست‌ها از آن عبور کنند.
 */
if (!function_exists('melkinoProxy')) {
    function melkinoProxy(): string
    {
        return melkinoBotSetting('http_proxy');
    }
}

if (!function_exists('melkinoChannelId')) {
    function melkinoChannelId(): string
    {
        $fromDb = melkinoBotSetting('telegram_channel');
        if ($fromDb !== '') {
            return $fromDb;
        }
        return defined('CHANNEL_ID') ? (string)CHANNEL_ID : '';
    }
}

/**
 * تست اتصال به ربات تلگرام (متد getMe)
 * خروجی: ['success'=>bool, 'message'=>string, 'username'=>?string]
 */
if (!function_exists('melkinoTestTelegramConnection')) {
    function melkinoTestTelegramConnection(?string $token = null): array
    {
        $token = $token !== null ? trim($token) : melkinoTelegramToken();
        if ($token === '') {
            return ['success' => false, 'message' => 'توکن تلگرام تنظیم نشده است.', 'username' => null];
        }

        $url = 'https://api.telegram.org/bot' . $token . '/getMe';
        $response = function_exists('melkinoHttpPost')
            ? melkinoHttpPost($url, http_build_query([]))
            : @file_get_contents($url);

        if ($response === null || $response === '' || $response === false) {
            return [
                'success'  => false,
                'message'  => 'ارتباط با سرور تلگرام برقرار نشد. اگر هاست شما به api.telegram.org '
                            . 'دسترسی ندارد (مثلاً داخل ایران)، یک پروکسی در همین بخش ثبت کنید.',
                'username' => null,
            ];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data) || empty($data['ok'])) {
            $desc = is_array($data) ? ($data['description'] ?? 'پاسخ نامعتبر') : 'پاسخ نامعتبر از سرور تلگرام';
            return ['success' => false, 'message' => 'تلگرام: ' . $desc, 'username' => null];
        }

        return [
            'success' => true,
            'message' => 'اتصال به تلگرام برقرار است.',
            'username' => $data['result']['username'] ?? null,
        ];
    }
}

/**
 * تست اتصال به ربات بله (متد getMe)
 */
if (!function_exists('melkinoTestBaleConnection')) {
    function melkinoTestBaleConnection(?string $token = null): array
    {
        $token = $token !== null ? trim($token) : melkinoBaleToken();
        if ($token === '') {
            return ['success' => false, 'message' => 'توکن بله تنظیم نشده است.', 'username' => null];
        }

        $url = 'https://tapi.bale.ai/bot' . $token . '/getMe';
        $response = function_exists('melkinoHttpPost')
            ? melkinoHttpPost($url, http_build_query([]))
            : @file_get_contents($url);

        if ($response === null || $response === '' || $response === false) {
            return [
                'success'  => false,
                'message'  => 'ارتباط با سرور بله برقرار نشد. اتصال اینترنتِ هاست یا تنظیمات پروکسی را بررسی کنید.',
                'username' => null,
            ];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data) || empty($data['ok'])) {
            $desc = is_array($data) ? ($data['description'] ?? 'پاسخ نامعتبر') : 'پاسخ نامعتبر از سرور بله';
            return ['success' => false, 'message' => 'بله: ' . $desc, 'username' => null];
        }

        return [
            'success' => true,
            'message' => 'اتصال به بله برقرار است.',
            'username' => $data['result']['username'] ?? null,
        ];
    }
}
