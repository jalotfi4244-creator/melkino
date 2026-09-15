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
// ========== تنظیمات ربات تلگرام ==========
define('BOT_TOKEN', '8942418934:AAEi81P4LISH40EDMScg_V9hc2GbbMHlFJs');
define('CHANNEL_ID', '@melkino_shahrood');

// ========== تنظیمات ربات بله ==========
// از @botfather_bale (یا پنل توسعه‌دهندگان بله) توکن بگیر و اینجا جایگزین کن.
define('BALE_BOT_TOKEN', '387417012:-ZJCL66dm8xQHrRe-Hmcc4vtdB_tLfaUhmE');

// ========== تنظیمات پیامک (اختیاری) ==========
// اگر سرویس پیامکی داری، این مقادیر رو با اطلاعات API واقعی جایگزین کن.
// اگر خالی بمونه، سیستم به‌جای ارسال پیامک، کد رو مستقیم روی صفحه نشون می‌ده.
define('SMS_API_KEY', '');
define('SMS_API_URL', '');
define('SMS_SENDER_LINE', '');

// ========== تنظیمات دیتابیس ==========
define('MOCK_MODE', false);

// ========== ثابت‌های دیتابیس (اینفینیتی‌فری) ==========
if (!defined('DB_HOST')) {
    define('DB_HOST', 'sql303.infinityfree.com');
    define('DB_NAME', 'if0_42615627_melkino');
    define('DB_USER', 'if0_42615627');
    define('DB_PASS', 'Javad4244');
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

    $melkinoIsAdminSession = !empty($_SESSION['is_admin'] ?? null);

    if (!in_array($melkinoCurrentScript, $melkinoMaintenanceAllowlist, true) && !$melkinoIsAdminSession) {
        try {
            $melkinoMaintenanceOn = dbSettingGet($pdo, 'global', 'maintenance_mode', false);
        } catch (Throwable $e) {
            $melkinoMaintenanceOn = false;
        }

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
                . '<p>سایت در حال انجام یک به‌روزرسانی است. لطفاً کمی بعد دوباره سر بزنید.</p></div></body></html>';
            exit;
        }
    }
}
?>
