<?php
/**
|--------------------------------------------------------------------------
| عیب‌یاب جامع ملکینو
|--------------------------------------------------------------------------
| این فایل همه‌ی اجزای سایت را به‌صورت زنده بررسی می‌کند و می‌گوید اگر
| مشکلی وجود دارد، از کدام بخش است:
|
|   ۱. محیط سرور (نسخه PHP، افزونه‌ها، تنظیمات، فضای دیسک)
|   ۲. پایگاه داده (اتصال، charset، جدول‌ها، ستون‌ها، ایندکس‌ها)
|   ۳. فایل‌های پروژه (وجود، نسخه، مجوزها، .htaccess)
|   ۴. ربات و کانال (توکن، اتصال زنده به تلگرام و بله، دسترسی کانال)
|   ۵. احراز هویت و کاربران (تنظیمات ورود، کاربران، آخرین ورودها)
|   ۶. محتوای سایت (آگهی‌ها، تصاویر، تصاویر یتیم، درخواست‌ها)
|   ۷. امنیت (HTTPS، محافظت از فایل‌های حساس، حالت تعمیرات)
|
| نحوه‌ی استفاده: تب «عیب‌یاب» در پنل ادمین → دکمه‌ی «شروع تست»
| خروجی JSON: admin-diagnostics.php?action=run
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/bot-settings.php';
require_once __DIR__ . '/telegram.php';

$melkinoDiagAction = (string)($_GET['action'] ?? $_POST['action'] ?? '');

/* =========================================================
   ابزارها
   ========================================================= */

if (!function_exists('melkinoDiagItem')) {
    function melkinoDiagItem(string $group, string $name, string $status, string $message, string $hint = ''): array
    {
        return [
            'group'   => $group,
            'name'    => $name,
            'status'  => $status,   // ok | warn | fail
            'message' => $message,
            'hint'    => $hint,
        ];
    }
}

if (!function_exists('melkinoDiagPdo')) {
    function melkinoDiagPdo(): ?PDO
    {
        global $pdo;
        return ($pdo instanceof PDO) ? $pdo : null;
    }
}

/** شمارش امنِ ردیف‌های یک جدول (در صورت نبود جدول یا خطا: null) */
if (!function_exists('melkinoDiagCount')) {
    function melkinoDiagCount(?PDO $pdo, string $table): ?int
    {
        if (!$pdo) {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return null;
        }
        try {
            return (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('melkinoDiagColumns')) {
    function melkinoDiagColumns(?PDO $pdo, string $table): array
    {
        if (!$pdo) {
            return [];
        }
        try {
            $rows = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $out[strtolower((string)$r['Field'])] = $r;
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('melkinoDiagIndexes')) {
    function melkinoDiagIndexes(?PDO $pdo, string $table): array
    {
        if (!$pdo) {
            return [];
        }
        try {
            $rows = $pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $key = strtolower((string)$r['Key_name']);
                $out[$key]['unique'] = ((int)$r['Non_unique'] === 0);
                $out[$key]['columns'][] = strtolower((string)$r['Column_name']);
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

/** آیا یک ایندکسِ یکتا روی این ستون(ها) وجود دارد؟ */
if (!function_exists('melkinoDiagHasUniqueOn')) {
    function melkinoDiagHasUniqueOn(?PDO $pdo, string $table, array $columns): bool
    {
        foreach (melkinoDiagIndexes($pdo, $table) as $idx) {
            if (empty($idx['unique'])) {
                continue;
            }
            $cols = array_map('strtolower', $columns);
            $have = array_map('strtolower', $idx['columns'] ?? []);
            sort($cols);
            sort($have);
            if ($cols === $have) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('melkinoDiagApiCall')) {
    /**
     * یک فراخوانی سریع به API پیام‌رسان‌ها با مهلت کوتاه (۸ ثانیه)
     * تا اجرای کل عیب‌یاب بیش از حد طولانی نشود.
     */
    function melkinoDiagApiCall(string $url): array
    {
        // تلاش اول: curl با مهلت کوتاه
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '',
            ]);

            $diagProxy = function_exists('melkinoProxy') ? trim((string)melkinoProxy()) : '';
            if ($diagProxy !== '' && defined('CURLOPT_PROXY')) {
                curl_setopt($ch, CURLOPT_PROXY, $diagProxy);
                $lower = strtolower($diagProxy);
                if (strpos($lower, 'socks5h://') === 0 && defined('CURLPROXY_SOCKS5_HOSTNAME')) {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
                } elseif (strpos($lower, 'socks5://') === 0 && defined('CURLPROXY_SOCKS5')) {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                } elseif (strpos($lower, 'socks4://') === 0 && defined('CURLPROXY_SOCKS4')) {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
                }
            }

            $raw = curl_exec($ch);
            curl_close($ch);

            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    if (empty($decoded['ok'])) {
                        return ['ok' => false, 'description' => (string)($decoded['description'] ?? 'خطای نامشخص')];
                    }
                    return ['ok' => true, 'result' => $decoded['result'] ?? []];
                }
            }
        }

        $response = function_exists('melkinoHttpPost') ? melkinoHttpPost($url, '') : null;
        if ($response === null || $response === '') {
            return ['ok' => false, 'description' => 'ارتباط برقرار نشد (خروجی سرور یا فیلترینگ)'];
        }
        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'description' => 'پاسخ نامعتبر از سرور'];
        }
        if (empty($data['ok'])) {
            return ['ok' => false, 'description' => (string)($data['description'] ?? 'خطای نامشخص')];
        }
        return ['ok' => true, 'result' => $data['result'] ?? []];
    }
}

/* =========================================================
   اجرای همه‌ی بررسی‌ها
   ========================================================= */

if (!function_exists('melkinoRunDiagnostics')) {
    function melkinoRunDiagnostics(): array
    {
        $checks = [];
        $pdo = melkinoDiagPdo();
        $root = __DIR__;

        /* ---------------- ۱. محیط سرور ---------------- */
        $g = '۱. محیط سرور';

        $phpVersion = PHP_VERSION;
        $versionOk = version_compare($phpVersion, '7.4.0', '>=');
        $checks[] = melkinoDiagItem(
            $g,
            'نسخه PHP',
            $versionOk ? 'ok' : 'fail',
            $phpVersion . ($versionOk ? ' — پشتیبانی می‌شود' : ' — بسیار قدیمی است'),
            $versionOk ? '' : 'نسخه‌ی PHP باید حداقل ۷.۴ باشد. از هاست بخواهید آن را به‌روزرسانی کند.'
        );

        $requiredExt = [
            'pdo'       => ['fail', 'بدون آن هیچ ارتباطی با پایگاه داده برقرار نمی‌شود.'],
            'pdo_mysql' => ['fail', 'درایور MySQL برای PDO نصب نیست؛ سایت اصلاً کار نمی‌کند.'],
            'curl'      => ['warn', 'بدون آن ارسال پیام به تلگرام/بله با روش پشتیبان انجام می‌شود (کندتر و کم‌دوام‌تر).'],
            'json'      => ['fail', 'بدون آن بیشتر بخش‌های سایت از کار می‌افتند.'],
            'mbstring'  => ['warn', 'برای پردازش دقیق متن‌های فارسی توصیه می‌شود.'],
            'gd'        => ['warn', 'بدون آن تغییر اندازه و فشرده‌سازی تصاویر انجام نمی‌شود.'],
            'openssl'   => ['warn', 'بدون آن برخی ارتباط‌های امن (https) ممکن است ناموفق شود.'],
        ];
        foreach ($requiredExt as $ext => [$severity, $hint]) {
            $loaded = extension_loaded($ext);
            $checks[] = melkinoDiagItem(
                $g,
                'افزونه ' . $ext,
                $loaded ? 'ok' : $severity,
                $loaded ? 'نصب شده' : 'نصب نیست',
                $loaded ? '' : $hint
            );
        }

        $zipOk = class_exists('ZipArchive');
        $checks[] = melkinoDiagItem(
            $g,
            'افزونه zip (برای پشتیبان‌گیری)',
            $zipOk ? 'ok' : 'warn',
            $zipOk ? 'موجود — پشتیبان‌ها به صورت zip ذخیره می‌شوند' : 'موجود نیست — پشتیبان‌ها به صورت tar ذخیره می‌شوند',
            $zipOk ? '' : 'امکانات سایت محدود نمی‌شود؛ فقط فرمت فایل پشتیبان متفاوت است.'
        );

        $displayErrors = (bool)(int)ini_get('display_errors');
        $checks[] = melkinoDiagItem(
            $g,
            'نمایش خطاها در صفحه',
            $displayErrors ? 'warn' : 'ok',
            $displayErrors ? 'روشن است' : 'خاموش است (صحیح)',
            $displayErrors ? 'نمایش خطاها در سایت اصلی باید خاموش باشد؛ در غیر این صورت خطاها به کاربران نشان داده می‌شود.' : ''
        );

        $uploadsOn = (bool)(int)ini_get('file_uploads');
        $checks[] = melkinoDiagItem(
            $g,
            'امکان بارگذاری فایل',
            $uploadsOn ? 'ok' : 'fail',
            $uploadsOn ? 'فعال' : 'غیرفعال',
            $uploadsOn ? '' : 'بارگذاری تصاویر آگهی غیرممکن است. از هاست بخواهید file_uploads را فعال کند.'
        );

        $maxUpload = (string)ini_get('upload_max_filesize');
        $maxPost = (string)ini_get('post_max_size');
        $checks[] = melkinoDiagItem(
            $g,
            'محدودیت حجم بارگذاری',
            'ok',
            'هر فایل: ' . $maxUpload . ' — کل درخواست: ' . $maxPost,
            'اگر تصاویر بزرگ آپلود نمی‌شوند، این مقادیر را در هاست افزایش دهید.'
        );

        $timeLimit = (int)ini_get('max_execution_time');
        $checks[] = melkinoDiagItem(
            $g,
            'مهلت اجرای اسکریپت',
            $timeLimit > 0 && $timeLimit < 30 ? 'warn' : 'ok',
            $timeLimit > 0 ? $timeLimit . ' ثانیه' : 'بدون محدودیت',
            $timeLimit > 0 && $timeLimit < 30 ? 'برای پشتیبان‌گیری و ارسال تصویر به تلگرام ممکن است کم باشد.' : ''
        );

        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $checks[] = melkinoDiagItem(
            $g,
            'پروتکل HTTPS',
            $isHttps ? 'ok' : 'fail',
            $isHttps ? 'فعال' : 'غیرفعال — سایت با http باز شده است',
            $isHttps ? '' : 'مینی‌اپ تلگرام و بله فقط روی HTTPS کار می‌کند؛ نصب گواهی SSL الزامی است.'
        );

        $free = @disk_free_space($root);
        if ($free !== false && $free !== null) {
            $freeMb = (int)floor($free / 1048576);
            $checks[] = melkinoDiagItem(
                $g,
                'فضای آزاد دیسک',
                $freeMb < 100 ? 'warn' : 'ok',
                number_format($freeMb) . ' مگابایت',
                $freeMb < 100 ? 'فضا کم است؛ آپلود تصویر و پشتیبان‌گیری ممکن است ناموفق شود.' : ''
            );
        }

        /* ---------------- ۲. پایگاه داده ---------------- */
        $g = '۲. پایگاه داده';

        if (!$pdo) {
            $checks[] = melkinoDiagItem($g, 'اتصال به پایگاه داده', 'fail', 'برقرار نیست', 'اطلاعات اتصال در config.secrets.php را بررسی کنید (هاست، نام دیتابیس، کاربر، رمز).');
        } else {
            $checks[] = melkinoDiagItem($g, 'اتصال به پایگاه داده', 'ok', 'برقرار است');

            try {
                $version = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
                $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
                $checks[] = melkinoDiagItem($g, 'مشخصات پایگاه داده', 'ok', 'MySQL/MariaDB ' . $version . ' — پایگاه داده: ' . $dbName);
            } catch (Throwable $e) {
                $checks[] = melkinoDiagItem($g, 'مشخصات پایگاه داده', 'warn', 'دریافت نشد');
            }

            try {
                $row = $pdo->query("SELECT @@character_set_database AS cs, @@collation_database AS cl")->fetch(PDO::FETCH_ASSOC);
                $cs = strtolower((string)($row['cs'] ?? ''));
                $checks[] = melkinoDiagItem(
                    $g,
                    'رمزگذاری پایگاه داده (charset)',
                    strpos($cs, 'utf8mb4') === 0 ? 'ok' : 'warn',
                    (string)($row['cs'] ?? '?') . ' / ' . (string)($row['cl'] ?? '?'),
                    strpos($cs, 'utf8mb4') === 0 ? '' : 'برای ذخیره‌ی درست متن‌های فارسی و ایموجی، utf8mb4 توصیه می‌شود.'
                );
            } catch (Throwable $e) {
                // نادیده گرفته می‌شود
            }

            // جداول
            $coreTables = ['users' => 'کاربران', 'ads' => 'آگهی‌ها', 'images' => 'تصاویر', 'settings' => 'تنظیمات'];

            try {
                $existing = [];
                foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
                    $existing[strtolower((string)$t)] = true;
                }

                foreach ($coreTables as $table => $label) {
                    $has = isset($existing[$table]);
                    $count = $has ? melkinoDiagCount($pdo, $table) : null;
                    $checks[] = melkinoDiagItem(
                        $g,
                        'جدول «' . $label . '» (' . $table . ')',
                        $has ? 'ok' : 'fail',
                        $has ? ('موجود — ' . number_format((int)$count) . ' ردیف') : 'موجود نیست',
                        $has ? '' : 'این جدول برای کارکرد سایت حیاتی است.'
                    );
                }

                $importantTables = [
                    'property_requests'  => 'درخواست‌های ملک',
                    'request_matches'    => 'تطبیق درخواست‌ها',
                    'support_tickets'    => 'تیکت‌های پشتیبانی',
                    'support_messages'   => 'پیام‌های پشتیبانی',
                    'promotions'         => 'تبلیغات',
                    'notifications'      => 'اعلان‌ها',
                    'favorites'          => 'علاقه‌مندی‌ها',
                    'login_events'       => 'رویدادهای ورود',
                    'login_tokens'       => 'توکن‌های ورود',
                    'amenities'          => 'امکانات ملک',
                    'ad_amenities'       => 'امکانات آگهی',
                    'admins'             => 'مدیران',
                ];
                $missingImportant = [];
                foreach ($importantTables as $table => $label) {
                    if (!isset($existing[$table])) {
                        $missingImportant[] = $label . ' (' . $table . ')';
                    }
                }
                $checks[] = melkinoDiagItem(
                    $g,
                    'جداولِ بخش‌های دیگر',
                    $missingImportant ? 'warn' : 'ok',
                    $missingImportant ? ('ناقص: ' . implode('، ', $missingImportant)) : 'همه موجود هستند',
                    $missingImportant ? 'این جدول‌ها معمولاً هنگام استفاده از هر بخش ساخته می‌شوند؛ اگر آن بخش کار نمی‌کند، اینجا را بررسی کنید.' : ''
                );
            } catch (Throwable $e) {
                $checks[] = melkinoDiagItem($g, 'فهرست جدول‌ها', 'fail', 'دریافت نشد: ' . $e->getMessage());
            }

            // ستون‌های حساس
            $userCols = melkinoDiagColumns($pdo, 'users');
            if ($userCols) {
                $needed = ['telegram_id' => 'آیدی تلگرام', 'bale_id' => 'آیدی بله', 'phone' => 'شماره تماس', 'name' => 'نام'];
                $missing = [];
                foreach ($needed as $col => $label) {
                    if (!isset($userCols[$col])) {
                        $missing[] = $label . ' (' . $col . ')';
                    }
                }
                $checks[] = melkinoDiagItem(
                    $g,
                    'ستون‌های جدول کاربران',
                    $missing ? 'fail' : 'ok',
                    $missing ? ('ناقص: ' . implode('، ', $missing)) : 'کامل است',
                    $missing ? 'بدون این ستون‌ها ورود و ذخیره‌ی پروفایل کاربر انجام نمی‌شود.' : ''
                );

                $uniqTg = melkinoDiagHasUniqueOn($pdo, 'users', ['telegram_id']);
                $checks[] = melkinoDiagItem(
                    $g,
                    'ایندکس یکتا روی telegram_id',
                    $uniqTg ? 'ok' : 'fail',
                    $uniqTg ? 'وجود دارد' : 'وجود ندارد',
                    $uniqTg ? '' : 'بدون این ایندکس، ذخیره‌ی کاربر به‌جای به‌روزرسانی، ردیف تکراری می‌سازد و ورود خراب می‌شود.'
                );
            }

            $settingsIdx = melkinoDiagIndexes($pdo, 'settings');
            $settingsUniq = false;
            foreach ($settingsIdx as $idx) {
                if (empty($idx['unique'])) {
                    continue;
                }
                $have = array_map('strtolower', $idx['columns'] ?? []);
                sort($have);
                if ($have === ['setting_group', 'setting_key']) {
                    $settingsUniq = true;
                }
            }
            $checks[] = melkinoDiagItem(
                $g,
                'ایندکس یکتا روی تنظیمات (group, key)',
                $settingsUniq ? 'ok' : 'fail',
                $settingsUniq ? 'وجود دارد' : 'وجود ندارد',
                $settingsUniq ? '' : 'بدون آن، ذخیره‌ی توکن ربات ردیف تکراری می‌سازد و تنظیمات پاک می‌شود.'
            );

            $adCols = melkinoDiagColumns($pdo, 'ads');
            if ($adCols) {
                $missing = [];
                foreach (['telegram_message_id' => 'شناسه پیام تلگرام', 'bale_message_id' => 'شناسه پیام بله'] as $col => $label) {
                    if (!isset($adCols[$col])) {
                        $missing[] = $label;
                    }
                }
                $checks[] = melkinoDiagItem(
                    $g,
                    'ستون‌های انتشار در پیام‌رسان‌ها',
                    $missing ? 'warn' : 'ok',
                    $missing ? ('ناقص: ' . implode('، ', $missing)) : 'کامل است',
                    $missing ? 'این ستون‌ها هنگام اولین انتشار ساخته می‌شوند؛ اگر انتشار انجام نمی‌شود آن را بررسی کنید.' : ''
                );
            }
        }

        /* ---------------- ۳. فایل‌های پروژه ---------------- */
        $g = '۳. فایل‌های پروژه';

        $criticalFiles = [
            'config.php'           => 'تنظیمات اصلی',
            'config.secrets.php'   => 'رمزها و توکن‌ها',
            'db_helpers.php'       => 'توابع پایگاه داده',
            'db-settings.php'      => 'ذخیره‌ی تنظیمات',
            'bot-settings.php'     => 'تنظیمات ربات',
            'telegram.php'         => 'ارتباط با تلگرام',
            'auth.php'             => 'احراز هویت',
            'admin-guard.php'      => 'محافظ پنل ادمین',
            'admin-panel.php'      => 'پنل ادمین',
            'header.php'           => 'هدر مشترک',
            'index.php'            => 'صفحه اصلی',
            'login.php'            => 'صفحه ورود',
            'style.css'            => 'شیوه‌نامه اصلی',
            'design-pro.css'       => 'شیوه‌نامه لوکس',
        ];
        $missingFiles = [];
        foreach ($criticalFiles as $file => $label) {
            if (!is_file($root . '/' . $file)) {
                $missingFiles[] = $label . ' (' . $file . ')';
            }
        }
        $checks[] = melkinoDiagItem(
            $g,
            'فایل‌های حیاتی',
            $missingFiles ? 'fail' : 'ok',
            $missingFiles ? ('مفقود: ' . implode('، ', $missingFiles)) : 'همه‌ی ' . count($criticalFiles) . ' فایل موجود هستند',
            $missingFiles ? 'فایل‌های مفقود را دوباره از زیپ پروژه آپلود کنید.' : ''
        );

        // بررسی نسخه: آیا اصلاحاتِ مهم آپلود شده‌اند؟
        $versionMarkers = [
            ['db-settings.php', 'melkinoEnsureSettingsTable', 'fail', 'رفع اشکالِ «پاک شدن توکن ربات» هنوز آپلود نشده است.', 'فایل db-settings.php را از زیپ جدید آپلود کنید.'],
            ['bot-settings.php', 'melkinoProxy', 'warn', 'پشتیبانی از پروکسی هنوز آپلود نشده است.', 'اگر هاست به api.telegram.org دسترسی ندارد، این فایل را آپلود کنید.'],
            ['db_helpers.php', 'melkinoProfilePrefill', 'warn', 'پر کردن خودکار فرم‌ها هنوز آپلود نشده است.', 'فایل db_helpers.php را آپلود کنید.'],
            ['header.php', 'MELKINO_PROFILE', 'warn', 'اطلاعات پروفایل در صفحات در دسترس نیست.', 'فایل header.php را آپلود کنید.'],
            ['telegram.php', 'CURLOPT_PROXY', 'warn', 'پشتیبانی از پروکسی در ارسال درخواست‌ها فعال نیست.', 'فایل telegram.php را آپلود کنید.'],
        ];
        foreach ($versionMarkers as [$file, $marker, $severity, $msg, $hint]) {
            $path = $root . '/' . $file;
            $has = is_file($path) && strpos((string)@file_get_contents($path), $marker) !== false;
            $checks[] = melkinoDiagItem(
                $g,
                'نسخه‌ی ' . $file,
                $has ? 'ok' : $severity,
                $has ? 'به‌روز است' : $msg,
                $has ? '' : $hint
            );
        }

        // نشانگرهای جاوااسکریپت
        $jsMarkers = [
            ['admin-ads.js', 'publishToBale', 'دکمه‌ی انتشار در بله'],
            ['admin-panel.php', 'tab-diagnostics', 'اتصال تب عیب‌یاب به پنل'],
        ];
        foreach ($jsMarkers as [$file, $marker, $label]) {
            $path = $root . '/' . $file;
            $has = is_file($path) && strpos((string)@file_get_contents($path), $marker) !== false;
            $checks[] = melkinoDiagItem(
                $g,
                $label . ' (' . $file . ')',
                $has ? 'ok' : 'warn',
                $has ? 'موجود' : 'یافت نشد',
                $has ? '' : 'این فایل را از زیپ جدید آپلود کنید.'
            );
        }

        // اسکریپتِ مسدودکننده (عامل صفحه‌ی سفید)
        $headerPath = $root . '/header.php';
        $headerContent = is_file($headerPath) ? (string)@file_get_contents($headerPath) : '';
        $blocking = (strpos($headerContent, 'src="https://telegram.org/js/telegram-web-app.js"') !== false)
            || (strpos($headerContent, 'src="https://tapi.bale.ai/miniapp.js') !== false);
        $checks[] = melkinoDiagItem(
            $g,
            'لودِ اسکریپت‌های مینی‌اپ',
            $blocking ? 'fail' : 'ok',
            $blocking ? 'اسکریپت‌ها به‌صورت مسدودکننده لود می‌شوند (عامل صفحه‌ی سفید)' : 'به‌صورت غیرِ مسدودکننده لود می‌شوند',
            $blocking ? 'نسخه‌ی جدید header.php را آپلود کنید؛ در غیر این صورت در شبکه‌هایی که telegram.org در دسترس نیست صفحه سفید می‌ماند.' : ''
        );

        // پوشه‌ها و مجوزها
        $uploadsDir = $root . '/uploads';
        if (!is_dir($uploadsDir)) {
            $checks[] = melkinoDiagItem($g, 'پوشه‌ی uploads', 'fail', 'وجود ندارد', 'این پوشه را بسازید و مجوز نوشتن (۷۵۵ یا ۷۷۵) به آن بدهید.');
        } else {
            $writable = is_writable($uploadsDir);
            $checks[] = melkinoDiagItem(
                $g,
                'دسترسی نوشتن در uploads',
                $writable ? 'ok' : 'fail',
                $writable ? 'قابل نوشتن' : 'غیرقابل نوشتن',
                $writable ? '' : 'آپلود تصویر انجام نمی‌شود. مجوز پوشه را روی ۷۵۵ یا ۷۷۵ تنظیم کنید.'
            );
        }

        foreach (['settings', 'backups'] as $dir) {
            $path = $root . '/' . $dir;
            if (is_dir($path)) {
                $writable = is_writable($path);
                $checks[] = melkinoDiagItem(
                    $g,
                    'دسترسی نوشتن در پوشه‌ی ' . $dir,
                    $writable ? 'ok' : 'warn',
                    $writable ? 'قابل نوشتن' : 'غیرقابل نوشتن',
                    $writable ? '' : 'ذخیره‌ی تنظیمات یا پشتیبان‌گیری ممکن است ناموفق شود.'
                );
            }
        }

        /* ---------------- ۴. ربات و کانال ---------------- */
        $g = '۴. ربات و کانال';

        $tgToken = function_exists('melkinoTelegramToken') ? (string)melkinoTelegramToken() : '';
        $tgTokenOk = ($tgToken !== '' && $tgToken !== 'توکن_ربات_تلگرام');
        $checks[] = melkinoDiagItem(
            $g,
            'توکن ربات تلگرام',
            $tgTokenOk ? 'ok' : 'fail',
            $tgTokenOk ? ('تنظیم شده (' . substr($tgToken, 0, 6) . '••••' . substr($tgToken, -4) . ')') : 'تنظیم نشده',
            $tgTokenOk ? '' : 'به تب «ربات و کانال» بروید، توکن را وارد و ذخیره کنید. بدون آن ورود کاربران و انتشار آگهی کار نمی‌کند.'
        );

        if ($tgTokenOk) {
            $r = melkinoDiagApiCall('https://api.telegram.org/bot' . $tgToken . '/getMe');
            $checks[] = melkinoDiagItem(
                $g,
                'اتصال زنده به تلگرام (getMe)',
                $r['ok'] ? 'ok' : 'warn',
                $r['ok'] ? ('✅ ربات: @' . (string)($r['result']['username'] ?? '')) : ('⚠️ ' . $r['description']),
                $r['ok'] ? '' : ('سرورِ هاست نتوانست به api.telegram.org وصل شود. بعضی هاست‌ها (مانند InfinityFree) ارتباط خروجی با تلگرام را مسدود کرده‌اند؛ در این صورت این هشدار طبیعی است. بررسیِ «ارتباط مستقیم از مرورگر» در همین گروه تعیین‌کننده است: اگر آن سبز باشد، توکن شما سالم است و انتشار آگهی از مرورگرِ ادمین انجام می‌شود. در غیر این صورت توکن را بررسی کنید یا در تب «ربات و کانال» یک پروکسی ثبت کنید.')
            );
        }

        $tgChannel = function_exists('melkinoChannelId') ? (string)melkinoChannelId() : '';
        $checks[] = melkinoDiagItem(
            $g,
            'شناسه کانال تلگرام',
            $tgChannel !== '' ? 'ok' : 'warn',
            $tgChannel !== '' ? $tgChannel : 'تنظیم نشده',
            $tgChannel !== '' ? '' : 'برای انتشار آگهی در کانال، شناسه را در تب «ربات و کانال» وارد کنید.'
        );

        if ($tgTokenOk && $tgChannel !== '' && $tgChannel !== '@آیدی_کانال') {
            $r = melkinoDiagApiCall('https://api.telegram.org/bot' . $tgToken . '/getChat?chat_id=' . urlencode($tgChannel));
            $checks[] = melkinoDiagItem(
                $g,
                'دسترسی به کانال تلگرام',
                $r['ok'] ? 'ok' : 'fail',
                $r['ok'] ? ('✅ ' . (string)($r['result']['title'] ?? $tgChannel)) : ('❌ ' . $r['description']),
                $r['ok'] ? '' : 'ربات را در کانال به‌عنوان ادمین اضافه کنید و شناسه را درست وارد کنید.'
            );
        }

        $baleToken = function_exists('melkinoBaleToken') ? (string)melkinoBaleToken() : '';
        $baleTokenOk = ($baleToken !== '' && $baleToken !== 'توکن_ربات_بله');
        $checks[] = melkinoDiagItem(
            $g,
            'توکن ربات بله',
            $baleTokenOk ? 'ok' : 'warn',
            $baleTokenOk ? ('تنظیم شده (' . substr($baleToken, 0, 6) . '••••' . substr($baleToken, -4) . ')') : 'تنظیم نشده',
            $baleTokenOk ? '' : 'برای ورود کاربران از بله و انتشار در کانال بله لازم است.'
        );

        if ($baleTokenOk) {
            $r = melkinoDiagApiCall('https://tapi.bale.ai/bot' . $baleToken . '/getMe');
            $checks[] = melkinoDiagItem(
                $g,
                'اتصال زنده به بله (getMe)',
                $r['ok'] ? 'ok' : 'warn',
                $r['ok'] ? ('✅ ربات: @' . (string)($r['result']['username'] ?? '')) : ('⚠️ ' . $r['description']),
                $r['ok'] ? '' : ('سرورِ هاست نتوانست به tapi.bale.ai وصل شود. این می‌تواند به‌دلیل محدودیتِ خروجیِ هاست یا فیلترینگ باشد. بررسیِ «ارتباط مستقیم از مرورگر» در همین گروه تعیین‌کننده است؛ اگر آن سبز باشد توکن سالم است و انتشار از مرورگرِ ادمین انجام می‌شود.')
            );
        }

        $baleChannel = function_exists('melkinoBotSetting') ? melkinoBotSetting('bale_channel') : '';
        if ($baleChannel !== '') {
            $r = melkinoDiagApiCall('https://tapi.bale.ai/bot' . $baleToken . '/getChat?chat_id=' . urlencode($baleChannel));
            $checks[] = melkinoDiagItem(
                $g,
                'دسترسی به کانال بله',
                $r['ok'] ? 'ok' : 'warn',
                $r['ok'] ? ('✅ ' . (string)($r['result']['title'] ?? $baleChannel)) : ('❌ ' . $r['description']),
                $r['ok'] ? '' : 'ربات را در کانال بله ادمین کنید و شناسه را بررسی کنید.'
            );
        }

        $proxy = function_exists('melkinoProxy') ? (string)melkinoProxy() : '';
        $checks[] = melkinoDiagItem(
            $g,
            'پروکسی ارتباط',
            'ok',
            $proxy !== '' ? ('تنظیم شده: ' . $proxy) : 'تنظیم نشده (ارتباط مستقیم)',
            'اگر تست اتصال تلگرام ناموفق است و هاست در ایران قرار دارد، یک پروکسی ثبت کنید.'
        );

        /* ---------------- ۵. احراز هویت و کاربران ---------------- */
        $g = '۵. احراز هویت و کاربران';

        $settingsOk = false;
        if ($pdo) {
            try {
                $pdo->query('SELECT 1 FROM settings LIMIT 1');
                $settingsOk = true;
            } catch (Throwable $e) {
                $settingsOk = false;
            }
        }
        $checks[] = melkinoDiagItem(
            $g,
            'ذخیره‌سازی تنظیمات (جدول settings)',
            $settingsOk ? 'ok' : 'fail',
            $settingsOk ? 'سالم — توکن‌ها و تنظیمات ذخیره می‌شوند' : 'کار نمی‌کند',
            $settingsOk ? '' : 'این مهم‌ترین بخش است: بدون آن توکن ربات بعد از هر بار خروج از پنل پاک می‌شود.'
        );

        if ($pdo) {
            $users = melkinoDiagCount($pdo, 'users');
            $checks[] = melkinoDiagItem(
                $g,
                'تعداد کاربران',
                'ok',
                $users === null ? 'نامشخص' : (number_format($users) . ' کاربر'),
                ''
            );

            try {
                $tgUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE telegram_id IS NOT NULL AND telegram_id <> ''")->fetchColumn();
                $baleUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE bale_id IS NOT NULL AND bale_id <> ''")->fetchColumn();
                $checks[] = melkinoDiagItem(
                    $g,
                    'کاربران بر پایه‌ی پلتفرم',
                    ($tgUsers + $baleUsers) > 0 ? 'ok' : 'warn',
                    'تلگرام: ' . number_format($tgUsers) . ' — بله: ' . number_format($baleUsers),
                    ($tgUsers + $baleUsers) > 0 ? '' : 'هیچ کاربری از طریق مینی‌اپ وارد نشده است. توکن، آدرس مینی‌اپ و تنظیمات ربات را بررسی کنید.'
                );
            } catch (Throwable $e) {
                // نادیده گرفته می‌شود
            }

            try {
                $last = $pdo->query("SELECT MAX(last_login) FROM users")->fetchColumn();
                if ($last) {
                    $days = (int)floor((time() - strtotime((string)$last)) / 86400);
                    $checks[] = melkinoDiagItem(
                        $g,
                        'آخرین ورود کاربر',
                        $days <= 7 ? 'ok' : 'warn',
                        (string)$last . ' (' . $days . ' روز پیش)',
                        $days <= 7 ? '' : 'مدتی است کسی وارد نشده است؛ اگر انتظار دارید کاربران فعال باشند، بخش ربات و مینی‌اپ را بررسی کنید.'
                    );
                }
            } catch (Throwable $e) {
                // نادیده گرفته می‌شود
            }

            try {
                $logins = melkinoDiagCount($pdo, 'login_events');
                if ($logins !== null) {
                    $checks[] = melkinoDiagItem($g, 'رویدادهای ورود ثبت‌شده', 'ok', number_format($logins) . ' مورد');
                }
            } catch (Throwable $e) {
                // نادیده گرفته می‌شود
            }
        }

        /* ---------------- ۶. محتوا ---------------- */
        $g = '۶. محتوای سایت';

        if ($pdo) {
            $ads = melkinoDiagCount($pdo, 'ads');
            if ($ads !== null) {
                $checks[] = melkinoDiagItem($g, 'تعداد آگهی‌ها', 'ok', number_format($ads) . ' آگهی');
            }

            $imgs = melkinoDiagCount($pdo, 'images');
            if ($imgs !== null) {
                $checks[] = melkinoDiagItem($g, 'تعداد تصاویر ثبت‌شده', 'ok', number_format($imgs) . ' تصویر');
            }

            $reqs = melkinoDiagCount($pdo, 'property_requests');
            if ($reqs !== null) {
                $checks[] = melkinoDiagItem($g, 'تعداد درخواست‌ها', 'ok', number_format($reqs) . ' درخواست');
            }

            // تصاویر یتیم
            try {
                $used = [];
                foreach ($pdo->query("SELECT filename FROM images")->fetchAll(PDO::FETCH_COLUMN) as $f) {
                    $used[basename((string)$f)] = true;
                }
                $orphan = 0;
                $scanned = 0;
                if (is_dir($root . '/uploads')) {
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/uploads', FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $file) {
                        if (!$file->isFile()) {
                            continue;
                        }
                        $scanned++;
                        if ($scanned > 2000) {
                            break;
                        }
                        if (!isset($used[$file->getFilename()])) {
                            $orphan++;
                        }
                    }
                }
                $checks[] = melkinoDiagItem(
                    $g,
                    'تصاویر بدون استفاده (یتیم)',
                    $orphan > 50 ? 'warn' : 'ok',
                    $orphan . ' فایل از ' . $scanned . ' فایل بررسی‌شده',
                    $orphan > 50 ? 'از تب «تصاویر» می‌توانید این فایل‌ها را پاک کنید تا فضای هاست آزاد شود.' : ''
                );
            } catch (Throwable $e) {
                // نادیده گرفته می‌شود
            }
        }

        /* ---------------- ۷. امنیت ---------------- */
        $g = '۷. امنیت';

        $htaccess = $root . '/.htaccess';
        $hasHtaccess = is_file($htaccess);
        $protects = $hasHtaccess && strpos((string)@file_get_contents($htaccess), 'config.secrets.php') !== false;
        $checks[] = melkinoDiagItem(
            $g,
            'محافظت از فایل‌های حساس (.htaccess)',
            $protects ? 'ok' : 'warn',
            $protects ? 'قانون مسدودسازی وجود دارد' : ($hasHtaccess ? 'فایل هست ولی قانون مسدودسازی config.secrets.php در آن نیست' : 'فایل .htaccess وجود ندارد'),
            $protects ? '' : 'فایل .htaccess را از زیپ پروژه آپلود کنید تا دسترسی مستقیم به فایل‌های حساس بسته شود.'
        );

        $checks[] = melkinoDiagItem(
            $g,
            'پروتکل امن (تکرار برای اطمینان)',
            $isHttps ? 'ok' : 'fail',
            $isHttps ? 'HTTPS فعال است' : 'HTTPS فعال نیست',
            $isHttps ? '' : 'بدون HTTPS مینی‌اپ تلگرام و بله کار نمی‌کند.'
        );

        try {
            $maintenance = $pdo ? (bool)dbSettingGet($pdo, 'global', 'maintenance_mode', false) : false;
            $checks[] = melkinoDiagItem(
                $g,
                'حالت تعمیرات',
                $maintenance ? 'warn' : 'ok',
                $maintenance ? 'فعال — سایت برای کاربران بسته است' : 'غیرفعال',
                $maintenance ? 'اگر نمی‌خواهید سایت بسته باشد، از تب «تنظیمات عمومی» آن را خاموش کنید.' : ''
            );
        } catch (Throwable $e) {
            // نادیده گرفته می‌شود
        }

        $legacyPass = defined('MELKINO_LEGACY_ADMIN_PASSWORD');
        $checks[] = melkinoDiagItem(
            $g,
            'رمز پیش‌فرض ادمین',
            $legacyPass ? 'warn' : 'ok',
            $legacyPass ? 'یک رمز پشتیبان در config.secrets.php تعریف شده است' : 'تعریف نشده',
            $legacyPass ? 'حتماً رمز عبور ادمین را به یک رمز قوی تغییر دهید.' : ''
        );

        return $checks;
    }
}

if ($melkinoDiagAction === 'run') {
    melkinoRequireAdminJson();

    $startedAt = microtime(true);
    $checks = melkinoRunDiagnostics();

    $ok = 0;
    $warn = 0;
    $fail = 0;
    foreach ($checks as $c) {
        if ($c['status'] === 'ok') {
            $ok++;
        } elseif ($c['status'] === 'warn') {
            $warn++;
        } else {
            $fail++;
        }
    }

    melkinoAdminJson([
        'success'      => true,
        'checks'       => $checks,
        'summary'      => ['ok' => $ok, 'warn' => $warn, 'fail' => $fail, 'total' => count($checks)],
        'duration_ms'  => (int)round((microtime(true) - $startedAt) * 1000),
        'generated_at' => date('Y-m-d H:i:s'),
    ]);
}

/* =========================================================
   خروجی HTML تب
   ========================================================= */
?>
<div class="admin-card">
    <div class="card-header">
        <span class="card-title">🔍 عیب‌یاب جامع ملکینو</span>
        <button type="button" class="btn-primary-full" style="width:auto;padding:8px 20px;" id="diagRunBtn" onclick="runDiagnostics()">
            ▶️ شروع تست
        </button>
    </div>

    <div style="padding:0 16px 16px;">
        <div class="admin-field-help" style="line-height:2;">
            با زدن دکمه‌ی «شروع تست»، همه‌ی بخش‌های سایت — سرور، پایگاه داده،
            فایل‌ها، ربات تلگرام، ربات بله، کانال‌ها، ورود کاربران، محتوا و امنیت
            — به‌صورت زنده بررسی می‌شوند و هر موردی که مشکل داشته باشد با
            نشانیِ دقیقِ بخشِ معیوب نشان داده می‌شود.
        </div>

        <div id="diagSummary" style="display:none;gap:8px;flex-wrap:wrap;margin-top:14px;"></div>
        <div id="diagProgress" style="display:none;margin-top:14px;color:var(--text-secondary);font-size:13px;">
            ⏳ در حال بررسی... (ارتباط با تلگرام و بله ممکن است چند ثانیه طول بکشد)
        </div>

        <div id="diagResults" style="margin-top:16px;"></div>

        <div id="diagActions" style="display:none;gap:8px;flex-wrap:wrap;margin-top:16px;">
            <button type="button" class="btn-secondary" style="padding:8px 16px;font-size:13px;" onclick="copyDiagnosticsReport()">
                📋 کپی گزارش
            </button>
            <button type="button" class="btn-secondary" style="padding:8px 16px;font-size:13px;" onclick="downloadDiagnosticsReport()">
                ⬇️ دانلود گزارش
            </button>
        </div>
    </div>
</div>

<script>
    window.__melkinoLastDiagnostics = null;

    window.runDiagnostics = async function () {
        const btn = document.getElementById('diagRunBtn');
        const results = document.getElementById('diagResults');
        const progress = document.getElementById('diagProgress');
        const summary = document.getElementById('diagSummary');
        const actions = document.getElementById('diagActions');

        if (!btn || !results) return;

        btn.disabled = true;
        btn.textContent = '⏳ در حال بررسی...';
        results.innerHTML = '';
        summary.style.display = 'none';
        actions.style.display = 'none';
        progress.style.display = 'block';

        try {
            const res = await fetch('admin-diagnostics.php?action=run', { cache: 'no-store' });
            const data = await res.json();

            if (!data.success) {
                results.innerHTML = '<div class="admin-field-help" style="color:var(--danger)">خطا در اجرای بررسی‌ها.</div>';
                return;
            }

            // بررسی‌های سمت مرورگر: روی هاست‌هایی که ارتباط خروجی‌شان
            // با پیام‌رسان‌ها مسدود است، این موارد تعیین‌کننده‌اند.
            try {
                data = await melkinoBrowserChecks(data);
            } catch (e) { /* بی‌صدا: بررسیِ مرورگر اختیاری است */ }

            window.__melkinoLastDiagnostics = data;
            renderDiagnostics(data);
        } catch (e) {
            results.innerHTML = '<div class="admin-field-help" style="color:var(--danger)">خطا در ارتباط با سرور.</div>';
        } finally {
            progress.style.display = 'none';
            btn.disabled = false;
            btn.textContent = '▶️ شروع تست';
            if (actions) actions.style.display = 'flex';
        }
    };

    /**
     * بررسی ارتباط مستقیمِ «مرورگرِ ادمین» با تلگرام و بله
     *
     * چرا لازم است؟ چون روی هاست‌هایی مثل InfinityFree که ارتباط خروجیِ سرور
     * با api.telegram.org مسدود شده، بررسیِ سمتِ سرور همیشه ناموفق است —
     * حتی وقتی توکن کاملاً سالم باشد. این بررسی حقیقت را نشان می‌دهد.
     */
    async function melkinoBrowserChecks(data) {
        if (typeof window.melkinoApiCall !== 'function') {
            return data;
        }

        // نکته: checks یک «آرایه‌ی تخت» است که هر عضو آن یک فیلد group دارد
        data.checks = data.checks || [];

        // نام گروهی که مربوط به ربات و کانال است را از نتایجِ سرور پیدا می‌کنیم
        let groupKey = '۴. ربات و کانال';
        for (let k = 0; k < data.checks.length; k++) {
            const g = String(data.checks[k].group || '');
            if (g.indexOf('ربات') !== -1) { groupKey = g; break; }
        }

        const targets = [
            { platform: 'telegram', label: 'تلگرام', host: 'api.telegram.org' },
            { platform: 'bale', label: 'بله', host: 'tapi.bale.ai' }
        ];

        const items = [];

        for (let i = 0; i < targets.length; i++) {
            const t = targets[i];
            let item = {
                group: groupKey,
                name: 'ارتباط مستقیم از مرورگر با ' + t.label,
                status: 'warn',
                message: '',
                hint: ''
            };

            try {
                const res = await window.melkinoApiCall(t.platform, 'getMe', {}, { clientOnly: true });

                if (res && res.ok && res.result) {
                    item.status = 'ok';
                    item.message = '✅ ربات: @' + (res.result.username || 'بدون نام‌کاربری');
                    item.hint = 'مرورگر شما مستقیماً با ' + t.label + ' ارتباط برقرار کرد. بنابراین انتشار آگهی و تست اتصال از همین مسیر انجام می‌شود، حتی اگر هاست شما دسترسیِ خروجی نداشته باشد.';
                } else if (res && res.description && res.description.indexOf('در دسترس نیست') !== -1) {
                    item.status = 'warn';
                    item.message = '⚠️ ' + res.description;
                    item.hint = 'توکن ' + t.label + ' هنوز ذخیره نشده است. از تب «ربات و کانال» آن را وارد و ذخیره کن.';
                } else {
                    item.status = 'warn';
                    item.message = '⚠️ ' + ((res && res.description) ? res.description : 'ارتباط برقرار نشد');
                    item.hint = 'مرورگر شما نتوانست به ' + t.host + ' وصل شود. اگر در ایران هستید، احتمالاً نیاز به فیلترشکن دارید. نتیجه: انتشارِ دستی از پنل کار نمی‌کند (مگر از طریق پروکسی در تب «ربات و کانال»).';
                }
            } catch (e) {
                item.status = 'warn';
                item.message = '⚠️ خطا در بررسی';
                item.hint = 'امکان بررسی از مرورگر وجود نداشت.';
            }

            items.push(item);
        }

        // افزودن به انتهای آرایه‌ی تخت؛ renderDiagnostics خودش گروه‌بندی می‌کند
        for (let i = 0; i < items.length; i++) {
            data.checks.push(items[i]);
        }

        // به‌روزرسانی خلاصه
        data.summary = data.summary || { ok: 0, warn: 0, fail: 0, total: 0 };
        for (let i = 0; i < items.length; i++) {
            data.summary.total += 1;
            if (items[i].status === 'ok') { data.summary.ok += 1; }
            else if (items[i].status === 'fail') { data.summary.fail += 1; }
            else { data.summary.warn += 1; }
        }

        return data;
    }

    function renderDiagnostics(data) {
        const results = document.getElementById('diagResults');
        const summary = document.getElementById('diagSummary');
        if (!results) return;

        const s = data.summary || { ok: 0, warn: 0, fail: 0, total: 0 };

        // خلاصه
        summary.style.display = 'flex';
        summary.innerHTML =
            '<span class="admin-badge" style="background:rgba(34,197,94,.15);color:#22C55E;padding:6px 14px;border-radius:20px;font-size:13px;">✅ سالم: ' + s.ok + '</span>' +
            '<span class="admin-badge" style="background:rgba(251,191,36,.15);color:#FBBF24;padding:6px 14px;border-radius:20px;font-size:13px;">⚠️ هشدار: ' + s.warn + '</span>' +
            '<span class="admin-badge" style="background:rgba(248,113,113,.15);color:#F87171;padding:6px 14px;border-radius:20px;font-size:13px;">❌ خطا: ' + s.fail + '</span>' +
            '<span style="color:var(--text-secondary);font-size:12px;align-self:center;">' + (data.duration_ms || 0) + ' میلی‌ثانیه — ' + (data.generated_at || '') + '</span>';

        // گروه‌بندی
        const groups = {};
        (data.checks || []).forEach(function (c) {
            if (!groups[c.group]) groups[c.group] = [];
            groups[c.group].push(c);
        });

        let html = '';
        Object.keys(groups).forEach(function (groupName) {
            const items = groups[groupName];
            const fails = items.filter(function (i) { return i.status === 'fail'; }).length;
            const warns = items.filter(function (i) { return i.status === 'warn'; }).length;

            let badge = '✅';
            if (fails) badge = '❌';
            else if (warns) badge = '⚠️';

            html += '<div style="border:1px solid var(--border);border-radius:12px;margin-bottom:12px;overflow:hidden;">';
            html += '<div style="background:var(--bg-secondary,#16211F);padding:10px 14px;font-weight:700;font-size:14px;display:flex;justify-content:space-between;align-items:center;">';
            html += '<span>' + badge + ' ' + escapeHtmlDiag(groupName) + '</span>';
            html += '<span style="font-size:12px;color:var(--text-secondary);">' + items.length + ' مورد</span>';
            html += '</div>';
            html += '<div style="padding:6px 14px 12px;">';

            items.forEach(function (c) {
                const icon = c.status === 'ok' ? '✅' : (c.status === 'warn' ? '⚠️' : '❌');
                const color = c.status === 'ok' ? 'var(--text-primary)' : (c.status === 'warn' ? '#FBBF24' : '#F87171');
                html += '<div style="padding:8px 0;border-bottom:1px solid var(--border);">';
                html += '<div style="font-size:13px;font-weight:600;">' + icon + ' ' + escapeHtmlDiag(c.name) + '</div>';
                html += '<div style="font-size:12px;color:' + color + ';margin:2px 20px 0;line-height:1.9;">' + escapeHtmlDiag(c.message) + '</div>';
                if (c.hint) {
                    html += '<div style="font-size:11px;color:var(--text-secondary);margin:4px 20px 0;line-height:1.9;">💡 ' + escapeHtmlDiag(c.hint) + '</div>';
                }
                html += '</div>';
            });

            html += '</div></div>';
        });

        results.innerHTML = html;
    }

    function escapeHtmlDiag(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function diagnosticsReportText() {
        const data = window.__melkinoLastDiagnostics;
        if (!data) return '';
        let out = 'گزارش عیب‌یابی ملکینو — ' + (data.generated_at || '') + '\n';
        out += '════════════════════════════════════════\n\n';
        const s = data.summary || {};
        out += 'خلاصه: ✅ ' + (s.ok || 0) + ' سالم | ⚠️ ' + (s.warn || 0) + ' هشدار | ❌ ' + (s.fail || 0) + ' خطا\n\n';

        let lastGroup = '';
        (data.checks || []).forEach(function (c) {
            if (c.group !== lastGroup) {
                lastGroup = c.group;
                out += '\n── ' + c.group + ' ──\n';
            }
            const icon = c.status === 'ok' ? '[✅]' : (c.status === 'warn' ? '[⚠️]' : '[❌]');
            out += icon + ' ' + c.name + ': ' + c.message + '\n';
            if (c.hint) out += '     💡 ' + c.hint + '\n';
        });
        return out;
    }

    window.copyDiagnosticsReport = function () {
        const text = diagnosticsReportText();
        if (!text) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                alert('گزارش کپی شد.');
            }).catch(function () { fallbackCopyDiag(text); });
        } else {
            fallbackCopyDiag(text);
        }
    };

    function fallbackCopyDiag(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); alert('گزارش کپی شد.'); } catch (e) { alert('کپی خودکار نشد.'); }
        document.body.removeChild(ta);
    }

    window.downloadDiagnosticsReport = function () {
        const text = diagnosticsReportText();
        if (!text) return;
        const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'melkino-diagnostics-' + Date.now() + '.txt';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(a.href);
    };
</script>
