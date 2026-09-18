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
        $sms = melkinoSmsSettings();
        return [
            'telegram_token'        => melkinoBotSetting('telegram_token'),
            'telegram_channel'      => melkinoBotSetting('telegram_channel', defined('CHANNEL_ID') ? CHANNEL_ID : ''),
            'telegram_bot_username' => ltrim(melkinoBotSetting('telegram_bot_username'), '@'),
            'bale_token'            => melkinoBotSetting('bale_token'),
            'bale_channel'          => melkinoBotSetting('bale_channel'),
            'bale_bot_username'     => ltrim(melkinoBotSetting('bale_bot_username'), '@'),
            'http_proxy'            => melkinoBotSetting('http_proxy'),
            'sms_enabled'           => $sms['enabled'] ? '1' : '0',
            'sms_api_key'           => $sms['api_key'],
            'sms_api_url'           => $sms['api_url'],
            'sms_sender_line'       => $sms['sender_line'],
            'updated_at'            => melkinoBotSetting('updated_at'),
        ];
    }
}

/**
 * --------------------------------------------------------------------------
 * تنظیمات پنل پیامک
 * --------------------------------------------------------------------------
 * اولویت خواندن: مقدار ذخیره‌شده در دیتابیس (پنل ادمین) و در صورت نبودن،
 * ثابت‌های config.php. اگر ادمین هرگز چیزی در پنل ذخیره نکرده باشد،
 * فعال‌بودن بر اساس پر بودن ثابت‌هاست (سازگاری با رفتار قبلی).
 */
if (!function_exists('melkinoSmsSettings')) {
    function melkinoSmsSettings(): array
    {
        $enabledDb = melkinoBotSetting('sms_enabled', '');
        if ($enabledDb === '') {
            $enabled = defined('SMS_API_KEY') && (string)SMS_API_KEY !== ''
                && defined('SMS_API_URL') && (string)SMS_API_URL !== '';
        } else {
            $enabled = $enabledDb === '1';
        }

        return [
            'enabled'     => $enabled,
            'api_key'     => melkinoBotSetting('sms_api_key', defined('SMS_API_KEY') ? (string)SMS_API_KEY : ''),
            'api_url'     => melkinoBotSetting('sms_api_url', defined('SMS_API_URL') ? (string)SMS_API_URL : ''),
            'sender_line' => melkinoBotSetting('sms_sender_line', defined('SMS_SENDER_LINE') ? (string)SMS_SENDER_LINE : ''),
        ];
    }
}

/**
 * --------------------------------------------------------------------------
 * فیلدهای قابل انتشار آگهی در کانال
 * --------------------------------------------------------------------------
 * ادمین برای هر پلتفرم (تلگرام/بله) جداگانه انتخاب می‌کند کدام فیلدها در
 * متن پیام منتشرشده بیایند و چه متن ثابتی بالا/پایین همه‌ی آگهی‌ها باشد.
 */
if (!function_exists('melkinoPublishFieldDefs')) {
    function melkinoPublishFieldDefs(): array
    {
        return [
            'title'         => ['emoji' => '🏠', 'label' => 'عنوان آگهی'],
            'transaction'   => ['emoji' => '📌', 'label' => 'نوع معامله'],
            'property_type' => ['emoji' => '🏷️', 'label' => 'نوع ملک'],
            'location'      => ['emoji' => '📍', 'label' => 'موقعیت'],
            'address'       => ['emoji' => '🗺️', 'label' => 'آدرس'],
            'area'          => ['emoji' => '📐', 'label' => 'متراژ'],
            'rooms'         => ['emoji' => '🛏️', 'label' => 'تعداد اتاق'],
            'floor'         => ['emoji' => '🏢', 'label' => 'طبقه'],
            'year'          => ['emoji' => '📅', 'label' => 'سال ساخت'],
            'price'         => ['emoji' => '💰', 'label' => 'قیمت'],
            'description'   => ['emoji' => '📝', 'label' => 'توضیحات'],
            'contact'       => ['emoji' => '👤', 'label' => 'نام تماس‌گیرنده'],
            'phone'         => ['emoji' => '📞', 'label' => 'شماره تماس آگهی'],
            'consultant'    => ['emoji' => '☎️', 'label' => 'شماره مشاور ملکینو'],
            'ad_id'         => ['emoji' => '🔗', 'label' => 'کد آگهی'],
        ];
    }
}

if (!function_exists('melkinoPublishPlatform')) {
    function melkinoPublishPlatform(string $platform): string
    {
        return strtolower(trim($platform)) === 'bale' ? 'bale' : 'telegram';
    }
}

if (!function_exists('melkinoPublishSettings')) {
    function melkinoPublishSettings(string $platform): array
    {
        global $pdo;
        $platform = melkinoPublishPlatform($platform);
        $defaults = array_keys(melkinoPublishFieldDefs());
        if ($platform === 'bale') {
            // پیش‌فرض بله: نام و شماره‌ی ثبت‌کننده منتشر نمی‌شود (همان رفتار
            // قبلی مسیر مستقیم بله)؛ ادمین می‌تواند آن‌ها را فعال کند.
            $defaults = array_values(array_diff($defaults, ['contact', 'phone']));
        }

        $fields = $defaults;
        $header = '';
        $footer = '';

        if ($pdo instanceof PDO) {
            try {
                $stored = dbSettingGet($pdo, 'publish', 'fields_' . $platform, null);
                if (is_array($stored)) {
                    // فقط کلیدهای معتبر نگه داشته می‌شوند؛ ترتیب همان ترتیب پیش‌فرض است
                    $fields = array_values(array_intersect($defaults, $stored));
                }
                $header = (string)dbSettingGet($pdo, 'publish', 'header_' . $platform, '');
                $footer = (string)dbSettingGet($pdo, 'publish', 'footer_' . $platform, '');
            } catch (Throwable $e) {
                // در صورت خطا، پیش‌فرض‌ها برگردانده می‌شوند
            }
        }

        return ['fields' => $fields, 'header' => $header, 'footer' => $footer];
    }
}

if (!function_exists('melkinoSavePublishSettings')) {
    function melkinoSavePublishSettings(string $platform, array $data): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return false;
        }

        $platform = melkinoPublishPlatform($platform);
        $defaults = array_keys(melkinoPublishFieldDefs());
        $adminId = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;

        $fields = $data['fields'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }
        $fields = array_values(array_intersect($defaults, array_map('strval', $fields)));

        $header = mb_substr(trim((string)($data['header'] ?? '')), 0, 2000);
        $footer = mb_substr(trim((string)($data['footer'] ?? '')), 0, 2000);

        return dbSettingSet($pdo, 'publish', 'fields_' . $platform, $fields, 'json', $adminId)
            && dbSettingSet($pdo, 'publish', 'header_' . $platform, $header, 'string', $adminId)
            && dbSettingSet($pdo, 'publish', 'footer_' . $platform, $footer, 'string', $adminId);
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

        // نکته‌ی مهم: ورودیِ خالی برای توکن‌ها/کلید به‌معنی «نگه‌داشتن مقدار
        // قبلی» است، چون در فرم فقط نسخه‌ی ماسک‌شده نمایش داده می‌شود و خودِ
        // اینپوت همیشه خالی است. قبلاً هر ذخیره با اینپوت خالی، توکن ذخیره‌شده
        // را پاک می‌کرد! برای پاک‌کردنِ عمدی، یک خط تیره (-) وارد کن.
        $keepSecret = function (string $key, string $input) {
            if ($input === '') {
                return melkinoBotSetting($key);
            }
            if ($input === '-') {
                return '';
            }
            return $input;
        };

        $values = [
            'telegram_token'        => $keepSecret('telegram_token', trim((string)($data['telegram_token'] ?? ''))),
            'telegram_channel'      => trim((string)($data['telegram_channel'] ?? '')),
            'telegram_bot_username' => ltrim(trim((string)($data['telegram_bot_username'] ?? '')), '@'),
            'bale_token'            => $keepSecret('bale_token', trim((string)($data['bale_token'] ?? ''))),
            'bale_channel'          => trim((string)($data['bale_channel'] ?? '')),
            'bale_bot_username'     => ltrim(trim((string)($data['bale_bot_username'] ?? '')), '@'),
            'http_proxy'            => trim((string)($data['http_proxy'] ?? '')),
            'sms_enabled'           => !empty($data['sms_enabled']) ? '1' : '0',
            'sms_api_key'           => $keepSecret('sms_api_key', trim((string)($data['sms_api_key'] ?? ''))),
            'sms_api_url'           => trim((string)($data['sms_api_url'] ?? '')),
            'sms_sender_line'       => trim((string)($data['sms_sender_line'] ?? '')),
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
            if ($k === 'sms_api_url' && $v !== '' && !preg_match('#^https?://#i', $v)) {
                throw new InvalidArgumentException('نشانی API پیامک باید با http:// یا https:// شروع شود.');
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
