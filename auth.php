<?php
/**
|--------------------------------------------------------------------------
| احراز هویت یکپارچهٔ ملکینو
|--------------------------------------------------------------------------
| بعد از این‌که ورود فقط از طریق تلگرام و بله انجام می‌شود، هر صفحه‌ای
| که نیاز به هویت کاربر دارد (ثبت ملک، ثبت درخواست و...) باید از این
| فایل استفاده کند.
|
| توابع:
|   melkinoIsLoggedIn()        آیا کاربر وارد شده است؟
|   melkinoLoggedInIdentity()  هویت کامل کاربر (user_id، تلگرام، بله، تلفن)
|   melkinoRequireLogin()      اگر وارد نشده باشد: ریدایرکت به صفحه ورود
|                              (یا پاسخ ۴۰۱ برای درخواست‌های AJAX)
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db_helpers.php';

if (!function_exists('melkinoIsLoggedIn')) {
    function melkinoIsLoggedIn(): bool
    {
        $identity = melkinoCurrentIdentity();
        return !empty($identity['user_id']);
    }
}

if (!function_exists('melkinoLoggedInIdentity')) {
    function melkinoLoggedInIdentity(): array
    {
        return melkinoCurrentIdentity();
    }
}

if (!function_exists('melkinoIsApiRequest')) {
    function melkinoIsApiRequest(): bool
    {
        if (($_POST['action'] ?? '') !== '' || ($_GET['action'] ?? '') !== '') {
            return true;
        }
        return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }
}

if (!function_exists('melkinoLoginUrl')) {
    function melkinoLoginUrl(?string $returnTo = null): string
    {
        $returnTo = $returnTo ?? (string)($_SERVER['REQUEST_URI'] ?? '');
        // فقط مسیرهای داخلی مجاز هستند (جلوگیری از Open Redirect)
        if ($returnTo !== '' && preg_match('#^/[^/\\\\]|^[a-zA-Z0-9_.-]+\.php#', $returnTo) !== 1) {
            $returnTo = '';
        }
        return $returnTo !== '' ? 'login.php?redirect=' . urlencode($returnTo) : 'login.php';
    }
}

if (!function_exists('melkinoRequireLogin')) {
    function melkinoRequireLogin(?string $returnTo = null): array
    {
        $identity = melkinoCurrentIdentity();

        if (!empty($identity['user_id'])) {
            return $identity;
        }

        if (melkinoIsApiRequest()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'need_login' => true,
                'login_url' => melkinoLoginUrl($returnTo),
                'message' => 'برای انجام این کار باید با تلگرام یا بله وارد شوید.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $target = melkinoLoginUrl($returnTo);

        if (!headers_sent()) {
            header('Location: ' . $target, true, 302);
        } else {
            echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '">';
        }
        exit;
    }
}

/**
 * ستون‌های بیشتری از پروفایل کاربر را در صورت نبودن به جدول users اضافه
 * می‌کند تا پنل ادمین بتواند اطلاعات کامل ورود را نمایش بدهد
 * (پلتفرم، مرورگر، زبان، عکس پروفایل و...).
 *
 * این تابع فقط ستون‌هایی را اضافه می‌کند که وجود ندارند، پس برای
 * دیتابیسِ فعلی بی‌خطر است.
 */
if (!function_exists('melkinoEnsureUserProfileColumns')) {
    function melkinoEnsureUserProfileColumns(): void
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return;
        }

        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $wanted = [
            'last_platform' => "VARCHAR(20) NULL",
            'user_agent'    => "VARCHAR(1000) NULL",
            'photo_url'     => "VARCHAR(500) NULL",
            'language_code' => "VARCHAR(10) NULL",
            'bale_username' => "VARCHAR(191) NULL",
            'updated_at'    => "DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];

        try {
            $existing = [];
            foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $existing[strtolower((string)$col['Field'])] = true;
            }

            foreach ($wanted as $column => $definition) {
                if (!isset($existing[strtolower($column)])) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN `$column` $definition");
                }
            }
        } catch (Throwable $e) {
            // نبودِ دسترسی ALTER یا جدول نباید مانع ورود کاربر شود
        }
    }
}

/**
 * ثبت کاملِ اطلاعات هر بار ورود کاربر:
 *   - به‌روزرسانی ستون‌های پروفایل در جدول users
 *   - درج یک ردیف در login_events (اگر جدول وجود داشته باشد)
 */
if (!function_exists('melkinoRecordLoginInfo')) {
    function melkinoRecordLoginInfo(?int $userId, string $platform, array $profile = []): void
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return;
        }

        melkinoEnsureUserProfileColumns();

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);

        // زمان شروع این نشست (برای ابطال خودکار هنگام فعال شدن حالت تعمیرات)
        if (PHP_SESSION_ACTIVE === session_status()) {
            $_SESSION['melkino_session_started_at'] = time();
        }

        if ($userId !== null && $userId > 0) {
            try {
                $st = $pdo->prepare(
                    "UPDATE users
                        SET last_platform = ?,
                            user_agent = ?,
                            last_ip = ?,
                            last_login = NOW(),
                            login_count = login_count + 1
                      WHERE id = ?"
                );
                $st->execute([$platform !== '' ? $platform : null, $ua !== '' ? $ua : null, $ip, $userId]);
            } catch (Throwable $e) {
                // ستون‌ها ممکن است هنوز اضافه نشده باشند؛ ادامه می‌دهیم
            }

            try {
                $ev = $pdo->prepare(
                    "INSERT INTO login_events (user_id, telegram_id, bale_id, username, name, ip_address, user_agent, platform, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                );
                $ev->execute([
                    $userId,
                    !empty($profile['telegram_id']) ? (string)$profile['telegram_id'] : null,
                    !empty($profile['bale_id']) ? (string)$profile['bale_id'] : null,
                    (string)($profile['username'] ?? ''),
                    (string)($profile['name'] ?? ''),
                    $ip,
                    $ua,
                    $platform !== '' ? $platform : null,
                ]);
            } catch (Throwable $e) {
                // تلاش برای نسخه‌ی قدیمی‌ترِ جدول (بدون ستون platform/bale_id)
                try {
                    $ev = $pdo->prepare(
                        "INSERT INTO login_events (user_id, telegram_id, username, name, ip_address, user_agent, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, NOW())"
                    );
                    $ev->execute([
                        $userId,
                        !empty($profile['telegram_id']) ? (string)$profile['telegram_id'] : null,
                        (string)($profile['username'] ?? ''),
                        (string)($profile['name'] ?? ''),
                        $ip,
                        $ua,
                    ]);
                } catch (Throwable $e2) {
                    // ثبت رویداد اختیاری است
                }
            }
        }
    }
}
