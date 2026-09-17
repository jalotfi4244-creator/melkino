<?php
// ========== کنترل‌کننده‌ی مرکزی خطا ==========
// قبلاً وقتی یک خطای واقعی PHP رخ می‌داد، چون display_errors خاموش
// بود، کاربر فقط یک صفحه‌ی خالی یا «HTTP ERROR 500» بی‌اطلاعات از
// خود هاست می‌دید و هیچ‌کس نمی‌فهمید دقیقاً کدوم خط از کدوم فایل
// خطا داده. حالا خطا همیشه در لاگ سرور ثبت می‌شود، و اگر ادمین
// وارد شده باشد (یا آدرس با ?melkino_debug=1 باز شود) متن دقیق خطا
// روی صفحه هم نشان داده می‌شود.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function melkinoShouldShowErrorDetails(): bool
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }
    return isset($_GET['melkino_debug']) && $_GET['melkino_debug'] === '1';
}

function melkinoRenderError(string $message, string $file, int $line): void
{
    if (!headers_sent()) {
        http_response_code(500);
    }

    if (!melkinoShouldShowErrorDetails()) {
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
            . '<title>خطا</title></head><body style="font-family:Tahoma,sans-serif;'
            . 'text-align:center;padding:60px 20px;">'
            . '<h2>مشکلی پیش آمد</h2>'
            . '<p>لطفاً کمی بعد دوباره تلاش کنید.</p></body></html>';
        return;
    }

    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
        . '<title>خطای PHP</title></head><body style="font-family:Tahoma,sans-serif;'
        . 'direction:rtl;padding:30px;background:#1a1a1a;color:#f5f5f5;">'
        . '<h2 style="color:#ff6b6b;">یک خطا رخ داد</h2>'
        . '<p style="font-size:16px;background:#2a2a2a;padding:15px;border-radius:8px;'
        . 'white-space:pre-wrap;word-break:break-word;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="color:#aaa;">فایل: ' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '<br>خط: ' . $line . '</p>'
        . '</body></html>';
}

set_exception_handler(function (Throwable $e) {
    error_log('[melkino] Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    melkinoRenderError($e->getMessage(), $e->getFile(), $e->getLine());
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[melkino] Fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) {
            melkinoRenderError($err['message'], $err['file'], $err['line']);
        }
    }
});

require_once __DIR__ . '/db-settings.php';

// =========================================================
// بارگذاری مقادیر محرمانه از خارج از کدِ تحتِ کنترل نسخه
// =========================================================
// هرگز رمزها/توکن‌ها را داخل فایل‌هایی که در گیت commit می‌شوند نگذار.
// اولویت خواندن:
//   1) فایل config.secrets.php در همین پوشه (در .gitignore است)
//   2) متغیرهای محیطی سرور (ENV)
//   3) مقدار پیش‌فرضِ خالی/عمومی (بدون هیچ رمز واقعی)
if (is_file(__DIR__ . '/config.secrets.php')) {
    require_once __DIR__ . '/config.secrets.php';
}

if (!function_exists('melkinoEnv')) {
    function melkinoEnv(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        return ($value === null || $value === '') ? $default : (string)$value;
    }
}

// ========== تنظیمات ربات تلگرام ==========
if (!defined('BOT_TOKEN')) {
    define('BOT_TOKEN', melkinoEnv('MELKINO_BOT_TOKEN', ''));
}
if (!defined('CHANNEL_ID')) {
    define('CHANNEL_ID', melkinoEnv('MELKINO_CHANNEL_ID', '@melkino_shahrood'));
}

// ========== تنظیمات ربات بله ==========
// از @botfather_bale (یا پنل توسعه‌دهندگان بله) توکن بگیر و در
// config.secrets.php یا متغیر محیطی MELKINO_BALE_BOT_TOKEN قرار بده.
if (!defined('BALE_BOT_TOKEN')) {
    define('BALE_BOT_TOKEN', melkinoEnv('MELKINO_BALE_BOT_TOKEN', ''));
}

// ========== تنظیمات پیامک (اختیاری) ==========
// اگر سرویس پیامکی داری، این مقادیر رو با اطلاعات API واقعی جایگزین کن.
// اگر خالی بمونه، سیستم به‌جای ارسال پیامک، کد رو مستقیم روی صفحه نشون می‌ده.
if (!defined('SMS_API_KEY')) {
    define('SMS_API_KEY', melkinoEnv('MELKINO_SMS_API_KEY', ''));
}
if (!defined('SMS_API_URL')) {
    define('SMS_API_URL', melkinoEnv('MELKINO_SMS_API_URL', ''));
}
if (!defined('SMS_SENDER_LINE')) {
    define('SMS_SENDER_LINE', melkinoEnv('MELKINO_SMS_SENDER_LINE', ''));
}

// ========== تنظیمات دیتابیس ==========
define('MOCK_MODE', false);

// ========== ثابت‌های دیتابیس ==========
// نکته: هر ثابت جداگانه بررسی می‌شود تا اگر فقط یکی از آن‌ها در
// config.secrets.php تعریف شده بود، بقیه بی‌تعریف نمانند.
if (!defined('DB_HOST')) {
    define('DB_HOST', melkinoEnv('DB_HOST', 'sql303.infinityfree.com'));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', melkinoEnv('DB_NAME', 'if0_42615627_melkino'));
}
if (!defined('DB_USER')) {
    define('DB_USER', melkinoEnv('DB_USER', 'if0_42615627'));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', melkinoEnv('DB_PASS', ''));
}

// ========== مسیر تنظیمات ==========
if (!defined('SETTINGS_DIR')) {
    define('SETTINGS_DIR', __DIR__ . '/settings');
}

// ========== SETTINGS_DIR رو نگه می‌داریم چون هنوز برای تم و لوگو استفاده می‌شه ==========

if (!is_dir(SETTINGS_DIR)) {
    @mkdir(SETTINGS_DIR, 0755, true);
}

// ========== تنظیمات مشاور از MySQL ==========
if (!function_exists('getConsultantSettings')) {
    function getConsultantSettings(): array {
        global $pdo;
        $defaults = ['name'=>'مشاور ملکینو','phone'=>'','telegram_username'=>'','telegram_link'=>''];
        if (!$pdo) return $defaults;
        foreach ($defaults as $k => $v) {
            $x = dbSettingGet($pdo, 'consultant_default', $k, $v);
            $defaults[$k] = is_string($x) ? $x : $v;
        }
        return array_merge($defaults, ['updated_at'=>date('Y-m-d H:i:s')]);
    }
}
if (!function_exists('saveConsultantSettings')) {
    function saveConsultantSettings(array $settings): bool {
        global $pdo; if (!$pdo) return false;
        $vals = [
          'name'=>trim((string)($settings['name']??'')) ?: 'مشاور ملکینو',
          'phone'=>trim((string)($settings['phone']??'')),
          'telegram_username'=>ltrim(trim((string)($settings['telegram_username']??'')),'@'),
          'telegram_link'=>trim((string)($settings['telegram_link']??''))
        ];
        if ($vals['telegram_link']!=='' && !preg_match('#^https?://t\.me/#i',$vals['telegram_link'])) return false;
        foreach($vals as $k=>$v) if(!dbSettingSet($pdo,'consultant_default',$k,$v,'string')) return false;
        return true;
    }
}

// ========== شماره تماس مشاور ==========
if (!function_exists('getConsultantPhone')) {
    function getConsultantPhone(): string {
        $settings = getConsultantSettings();
        return trim((string)($settings['phone'] ?? ''));
    }
}

// ========== نام مشاور ==========
if (!function_exists('getConsultantName')) {
    function getConsultantName(): string {
        $settings = getConsultantSettings();
        return trim((string)($settings['name'] ?? 'مشاور ملکینو')) ?: 'مشاور ملکینو';
    }
}

// ========== لینک تلگرام مشاور ==========
if (!function_exists('getConsultantTelegramLink')) {
    function getConsultantTelegramLink(): string {
        $settings = getConsultantSettings();

        $link = trim((string)($settings['telegram_link'] ?? ''));
        if ($link !== '' && preg_match('#^https?://#i', $link)) {
            return $link;
        }

        $username = ltrim(trim((string)($settings['telegram_username'] ?? '')), '@');
        return $username !== '' ? 'https://t.me/' . $username : '';
    }
}

// ========== تعریف کلاس PDO ساختگی (فقط در حالت MOCK) ==========
if (MOCK_MODE && !class_exists('PDO')) {
    class PDO {
        public function __construct($dsn, $user, $pass) {}
        public function setAttribute($attr, $value) {}
        public function query($sql) { return new MockStatement(); }
        public function prepare($sql) { return new MockStatement(); }
    }

    class MockStatement {
        public function execute($params = []) { return true; }
        public function fetchAll($mode = null) { return []; }
        public function fetch($mode = null) { return null; }
    }
}


// ========== تنظیمات عمومی قیمت ==========
if (!function_exists('getGlobalSettings')) {
    function getGlobalSettings(): array {
        global $pdo;
        $defaults=['site_name'=>'ملکینو','city'=>'شاهرود','slogan'=>'ملکینو؛ انتخابی فراتر از یک ملک','show_prices'=>true,'hide_all_prices'=>false,'enable_favorites'=>true,'enable_property_requests'=>true,'enable_notifications'=>true,'items_per_page'=>4,'default_theme'=>'dark','maintenance_mode'=>false];
        if (!$pdo) return $defaults;
        foreach ($defaults as $k=>$v) {
            $defaults[$k]=dbSettingGet($pdo,'global',$k,$v);
        }
        return $defaults;
    }
}

if (!function_exists('shouldHidePublicPrice')) {
    function shouldHidePublicPrice(array $ad): bool {
        $settings = getGlobalSettings();
        return !empty($settings['hide_all_prices']) ||
            empty($settings['show_prices']) ||
            !empty($ad['price_hidden']);
    }
}

// ========== تابع کمکی برای دریافت اولین تصویر ==========
if (!function_exists('getFirstImage')) {
    function getFirstImage($selectedImages = null, $pdo = null, $adId = null) {
        if (is_array($selectedImages) && count($selectedImages) > 0) {
            return $selectedImages[0];
        }

        if (is_string($selectedImages) && !empty($selectedImages)) {
            $decoded = json_decode($selectedImages, true);
            if (is_array($decoded) && count($decoded) > 0) {
                return $decoded[0];
            }
        }

        if (MOCK_MODE) {
            return 'default-house.jpg';
        }

        if ($pdo && $adId) {
            try {
                $stmt = $pdo->prepare("SELECT filename FROM images WHERE ad_id = ? ORDER BY id ASC LIMIT 1");
                $stmt->execute([$adId]);
                $img = $stmt->fetch(PDO::FETCH_ASSOC);
                return $img ? $img['filename'] : null;
            } catch (Exception $e) {
                return null;
            }
        }

        return null;
    }
}

// ========== اتصال به دیتابیس (حالت واقعی) ==========
require_once __DIR__ . '/bot-settings.php';

$pdo = null;

if (!MOCK_MODE) {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS
        );

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    } catch (Exception $e) {
        $pdo = null;
    }
}

// ========== اجرای واقعی سوییچ «اجبار HTTPS» ==========
// این بخش مقدار security.force_https را از دیتابیس می‌خواند و در صورت
// فعال بودن، کاربر را به نسخه‌ی HTTPS همان آدرس ریدایرکت می‌کند.
// قبلاً این تنظیم فقط ذخیره می‌شد ولی هیچ‌جا اعمال نمی‌شد.
if ($pdo instanceof PDO && php_sapi_name() !== 'cli') {
    $melkinoIsHttps =
        (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    if (!$melkinoIsHttps) {
        try {
            $melkinoForceHttps = dbSettingGet($pdo, 'security', 'force_https', false);
        } catch (Throwable $e) {
            $melkinoForceHttps = false;
        }

        if ($melkinoForceHttps) {
            $melkinoHost = $_SERVER['HTTP_HOST'] ?? '';
            $melkinoUri = $_SERVER['REQUEST_URI'] ?? '/';

            if ($melkinoHost !== '') {
                header('Location: https://' . $melkinoHost . $melkinoUri, true, 301);
                exit;
            }
        }
    }
}
// ========== اجرای واقعی «حالت تعمیرات» ==========
// این سوییچ قبلاً فقط ذخیره می‌شد و هیچ صفحه‌ای چک‌اش نمی‌کرد.
// حالا اگر فعال باشد، هر صفحه‌ی عمومی سایت یک پیام «در حال تعمیر» نشان
// می‌دهد؛ پنل ادمین و فایل‌های مربوط به ورود/API آن همیشه در دسترس
// می‌مانند تا خود ادمین بتواند دوباره این حالت را خاموش کند.
if ($pdo instanceof PDO && php_sapi_name() !== 'cli') {
    $melkinoMaintenanceAllowlist = [
        'admin-login.php',
        'admin-panel.php',
        'admin-ads.php',
        'admin-ads-modals.php',
        'admin-requests.php',
        'admin-property-revisions.php',
        'admin-support.php',
        'save_admin_password.php',
        'save_config.php',
        'save_consultant_settings.php',
        'save_consultants.php',
        'save_global_settings.php',
        'save_security_settings.php',
        'save_theme.php',
        'support-api.php',
        'upload_onboarding_logo.php',
        'identity-sync.php',
        'page-visits.php',
        'db-settings.php',
        'publish-to-telegram.php',
        'admin-upload-image.php',
    ];

    $melkinoCurrentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }

    try {
        $melkinoMaintenanceOn = dbSettingGet($pdo, 'global', 'maintenance_mode', false);
        $melkinoMaintenanceSince = (int) dbSettingGet($pdo, 'global', 'maintenance_started_at', 0);
    } catch (Throwable $e) {
        $melkinoMaintenanceOn = false;
        $melkinoMaintenanceSince = 0;
    }

    // =========================================================
    // وقتی حالت تعمیرات فعال می‌شود، همه‌ی نشست‌های قبلی باطل می‌شوند
    // (هم کاربران عادی و هم ادمین‌ها باید دوباره وارد شوند)
    // =========================================================
    if ($melkinoMaintenanceOn && $melkinoMaintenanceSince > 0) {
        $melkinoSessionStarted = (int)($_SESSION['melkino_session_started_at'] ?? 0);
        if ($melkinoSessionStarted <= 0 || $melkinoSessionStarted < $melkinoMaintenanceSince) {
            unset(
                $_SESSION['is_admin'],
                $_SESSION['user_role'],
                $_SESSION['admin_id'],
                $_SESSION['admin_username'],
                $_SESSION['admin_display_name'],
                $_SESSION['admin_login_at'],
                $_SESSION['reg_telegram_id'],
                $_SESSION['reg_bale_id'],
                $_SESSION['user_phone'],
                $_SESSION['user_name']
            );
            // توکن دسترسی ذخیره‌شده در مرورگر هم باطل می‌شود
            if (isset($_COOKIE['melkino_access_token'])) {
                setcookie('melkino_access_token', '', time() - 3600, '/');
                unset($_COOKIE['melkino_access_token']);
            }
        }
    }

    $melkinoIsAdminSession = !empty($_SESSION['is_admin'] ?? null);

    if (!in_array($melkinoCurrentScript, $melkinoMaintenanceAllowlist, true) && !$melkinoIsAdminSession) {
        if ($melkinoMaintenanceOn) {
            http_response_code(503);
            header('Retry-After: 3600');
            echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
                . '<title>در حال تعمیر</title>'
                . '<style>body{font-family:Tahoma,sans-serif;background:#0D1413;color:#F3F4F6;'
                . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:20px}'
                . '.box{max-width:420px}h1{font-size:22px;margin-bottom:10px}p{color:#A8B1AE;line-height:1.9}</style></head>'
                . '<body><div class="box"><div style="font-size:48px;margin-bottom:12px;">🛠️</div>'
                . '<h1>ملکینو موقتاً در دسترس نیست</h1>'
                . '<p>سایت در حال تعمیر است. لطفاً بعداً مراجعه نمایید.</p></div></body></html>';
            exit;
        }
    }
}

/* =========================================================
   نمایش خطاهای پنهان برای ادمین (فقط با ?debug=1)
   =========================================================
   چون نمایش خطاها در سایت خاموش است، هر خطای مرگبارِ PHP به‌جای
   پیام، یک «صفحه‌ی سفید» نشان می‌دهد. با افزودنِ ?debug=1 به آدرس
   (و فقط در صورتی که با حساب ادمین وارد شده باشی) خطاها نمایش
   داده می‌شوند تا علتِ صفحه‌ی سفید مشخص شود.
   ========================================================= */
if (php_sapi_name() !== 'cli'
    && (($_GET['debug'] ?? '') === '1')
    && !empty($_SESSION['is_admin'])) {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    @ini_set('error_reporting', (string)E_ALL);
    @ini_set('log_errors', '1');
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array((int)$e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            echo '<div dir="rtl" style="direction:rtl;text-align:right;font-family:Tahoma;background:#2A0F0F;'
                . 'color:#FFD7D7;border:1px solid #7F1D1D;border-radius:10px;padding:14px;margin:14px;line-height:1.9">'
                . '<b>خطای مرگبار (علت صفحه‌ی سفید):</b><br>'
                . htmlspecialchars((string)$e['message']) . '<br><br>'
                . '<b>فایل:</b> ' . htmlspecialchars((string)$e['file'])
                . ' &nbsp; <b>خط:</b> ' . (int)$e['line']
                . '</div>';
        }
    });
}

/* =========================================================
   توکن ورودِ پشتیبان (برای مرورگرهای داخلی تلگرام/بله)
   =========================================================
   در بعضی دستگاه‌ها (مخصوصاً آیفون) مرورگرِ داخلی تلگرام کوکیِ
   نشست را نگه نمی‌دارد؛ در نتیجه کاربر با وجود ورودِ موفق،
   در هر بار باز کردنِ مینی‌اپ دوباره به صفحه‌ی ورود برمی‌گردد.
   برای حل این مشکل، هنگام ورودِ موفق یک توکنِ یک‌بارمصرفِ
   طولانی‌مدت صادر می‌شود که می‌تواند نشست را دوباره برقرار کند.
   ========================================================= */

if (!function_exists('melkinoEnsureLoginTokenTable')) {
    function melkinoEnsureLoginTokenTable(): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return false;
        }
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS login_tokens (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    token CHAR(64) NOT NULL,
                    user_id BIGINT UNSIGNED NULL,
                    telegram_id VARCHAR(191) NULL,
                    bale_id VARCHAR(191) NULL,
                    user_agent VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    first_used_at DATETIME NULL,
                    expires_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_login_tokens_token (token),
                    KEY idx_login_tokens_user (user_id),
                    KEY idx_login_tokens_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('melkinoMintLoginToken')) {
    /** یک توکن ورودِ جدید می‌سازد و خودِ توکن را برمی‌گرداند (در خطا: رشته‌ی خالی) */
    function melkinoMintLoginToken($userId, ?string $telegramId = null, ?string $baleId = null, int $days = 60): string
    {
        global $pdo;
        if (!melkinoEnsureLoginTokenTable()) {
            return '';
        }
        try {
            $token = function_exists('random_bytes') ? bin2hex(random_bytes(32)) : md5(uniqid('', true) . microtime(true) . mt_rand());

            $st = $pdo->prepare(
                "INSERT INTO login_tokens (token, user_id, telegram_id, bale_id, user_agent, expires_at)
                 VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))"
            );
            $st->execute([
                $token,
                $userId ? (int)$userId : null,
                $telegramId !== null && $telegramId !== '' ? (string)$telegramId : null,
                $baleId !== null && $baleId !== '' ? (string)$baleId : null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                max(1, min(365, $days)),
            ]);

            // پاک‌سازی توکن‌های منقضی‌شده
            $pdo->exec("DELETE FROM login_tokens WHERE expires_at < NOW()");
            return $token;
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('melkinoConsumeLoginToken')) {
    /**
     * با استفاده از توکن، نشست کاربر را برقرار می‌کند.
     * خروجی: ['user_id','telegram_id','bale_id'] یا آرایه‌ی خالی در صورت نامعتبر بودن
     */
    function melkinoConsumeLoginToken(string $token): array
    {
        global $pdo;
        $token = trim((string)$token);
        if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return [];
        }
        if (!melkinoEnsureLoginTokenTable()) {
            return [];
        }
        try {
            $st = $pdo->prepare(
                "SELECT id, user_id, telegram_id, bale_id FROM login_tokens
                  WHERE token = ? AND expires_at > NOW() LIMIT 1"
            );
            $st->execute([$token]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return [];
            }
            if (empty($row['first_used_at'])) {
                $upd = $pdo->prepare("UPDATE login_tokens SET first_used_at = NOW() WHERE id = ?");
                $upd->execute([$row['id']]);
            }
            return [
                'user_id'     => $row['user_id'] !== null ? (int)$row['user_id'] : null,
                'telegram_id' => $row['telegram_id'] !== null ? (string)$row['telegram_id'] : '',
                'bale_id'     => $row['bale_id'] !== null ? (string)$row['bale_id'] : '',
            ];
        } catch (Throwable $e) {
            return [];
        }
    }
}

/* =========================================================
   برقراری خودکارِ نشست از روی توکنِ ورود (?t=...)
   =========================================================
   اگر کاربر از قبل وارد شده باشد کاری انجام نمی‌شود. این بخش
   فقط زمانی فعال است که پارامتر t در آدرس وجود داشته باشد.
   ========================================================= */
if ($pdo instanceof PDO && php_sapi_name() !== 'cli') {
    $melkinoLoginToken = trim((string)($_GET['t'] ?? ''));

    if ($melkinoLoginToken !== '') {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }

        if (session_status() === PHP_SESSION_ACTIVE
            && empty($_SESSION['reg_telegram_id'])
            && empty($_SESSION['reg_bale_id'])) {

            $melkinoTokenData = melkinoConsumeLoginToken($melkinoLoginToken);

            if (!empty($melkinoTokenData['telegram_id'])) {
                $_SESSION['reg_telegram_id'] = (string)$melkinoTokenData['telegram_id'];
            }
            if (!empty($melkinoTokenData['bale_id'])) {
                $_SESSION['reg_bale_id'] = (string)$melkinoTokenData['bale_id'];
            }
            if (!empty($melkinoTokenData['user_id']) && ($pdo instanceof PDO)) {
                try {
                    $st = $pdo->prepare("SELECT phone FROM users WHERE id = ? LIMIT 1");
                    $st->execute([(int)$melkinoTokenData['user_id']]);
                    $phone = trim((string)$st->fetchColumn());
                    if ($phone !== '') {
                        $_SESSION['user_phone'] = $phone;
                    }
                } catch (Throwable $e) {
                    // نادیده گرفته می‌شود
                }
            }
        }
    }
}

?>
