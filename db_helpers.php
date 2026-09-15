<?php
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    session_start();
}
require_once __DIR__ . '/config.php';

/**
 * تابع مشترک تأیید initData برای Mini App — طبق مستندات رسمی، هم
 * تلگرام و هم بله دقیقاً از همین الگوریتم (HMAC-SHA256 با کلید
 * مشتق‌شده از توکن بات + رشته‌ی ثابت «WebAppData») استفاده می‌کنند؛
 * فقط توکن بات‌شون فرق داره.
 *
 * قبلاً سایت فقط به مقداری که خودِ جاوااسکریپت کلاینت می‌فرستاد
 * اعتماد می‌کرد؛ یعنی هرکسی می‌تونست مستقیم به endpoint سایت درخواست
 * بفرسته و ادعا کنه فلان آی‌دی رو داره. این تابع initData خام (که
 * فقط کلاینت واقعیِ پیام‌رسان می‌تونه درست امضاش کنه) رو با امضای
 * رمزنگاری‌شده بررسی می‌کنه؛ اگر امضا نامعتبر باشه یا قدیمی باشه
 * (بیش از ۲۴ ساعت)، هیچ چیزی پذیرفته نمی‌شه.
 *
 * خروجی: آرایه‌ی کاربر تأییدشده (id, username, first_name, last_name)
 * یا null در صورت نامعتبر بودن.
 */
if (!function_exists('melkinoVerifyMiniAppInitData')) {
    function melkinoVerifyMiniAppInitData(string $initData, string $botToken): ?array
    {
        if ($initData === '' || $botToken === '') {
            return null;
        }

        parse_str($initData, $parsed);
        if (!is_array($parsed) || empty($parsed['hash']) || empty($parsed['user'])) {
            return null;
        }

        $receivedHash = (string)$parsed['hash'];
        unset($parsed['hash']);

        $pairs = [];
        foreach ($parsed as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        sort($pairs, SORT_STRING);
        $dataCheckString = implode("\n", $pairs);

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($computedHash, $receivedHash)) {
            return null;
        }

        $authDate = (int)($parsed['auth_date'] ?? 0);
        if ($authDate <= 0 || (time() - $authDate) > 86400) {
            // امضا معتبر است ولی خیلی قدیمی‌ست (احتمال replay attack)
            return null;
        }

        $user = json_decode((string)$parsed['user'], true);
        if (!is_array($user) || empty($user['id'])) {
            return null;
        }

        return [
            'id' => (string)$user['id'],
            'username' => (string)($user['username'] ?? ''),
            'first_name' => (string)($user['first_name'] ?? ''),
            'last_name' => (string)($user['last_name'] ?? ''),
        ];
    }
}

if (!function_exists('melkinoVerifyTelegramInitData')) {
    function melkinoVerifyTelegramInitData(string $initData): ?array
    {
        if (!defined('BOT_TOKEN') || BOT_TOKEN === '' || BOT_TOKEN === 'توکن_ربات_تلگرام') {
            return null;
        }
        return melkinoVerifyMiniAppInitData($initData, BOT_TOKEN);
    }
}

if (!function_exists('melkinoVerifyBaleInitData')) {
    function melkinoVerifyBaleInitData(string $initData): ?array
    {
        if (!defined('BALE_BOT_TOKEN') || BALE_BOT_TOKEN === '' || BALE_BOT_TOKEN === 'توکن_ربات_بله') {
            return null;
        }
        return melkinoVerifyMiniAppInitData($initData, BALE_BOT_TOKEN);
    }
}

/**
 * مطمئن می‌شود جدول‌های سیستم پشتیبانی (تیکت‌ها و پیام‌ها) وجود دارند.
 * قبلاً این کار فقط داخل support.php انجام می‌شد، به همین دلیل اگر
 * کاربری مستقیم به support-api.php (مثلاً از پنل ادمین) درخواست می‌زد
 * بدون اینکه support.php قبلاً یک‌بار اجرا شده باشد، خطای «جدول وجود
 * ندارد» می‌گرفت. حالا این تابع در همین فایل مشترک (db_helpers.php)
 * قرار دارد و همیشه، صرف‌نظر از نقطه‌ی ورود، اجرا می‌شود.
 */
if (!function_exists('melkinoEnsureSupportTables')) {
    function melkinoEnsureSupportTables(): bool
    {
        global $pdo;

        static $ensured = false;
        if ($ensured) {
            return true;
        }

        if (!($pdo instanceof PDO)) {
            return false;
        }

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS support_tickets (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id VARCHAR(191) NULL,
                    telegram_id VARCHAR(191) NULL,
                    phone VARCHAR(50) NULL,
                    user_name VARCHAR(255) NOT NULL DEFAULT '',
                    subject VARCHAR(255) NOT NULL DEFAULT '',
                    status VARCHAR(30) NOT NULL DEFAULT 'open',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_support_user_id (user_id),
                    KEY idx_support_telegram_id (telegram_id),
                    KEY idx_support_phone (phone),
                    KEY idx_support_status (status),
                    KEY idx_support_updated_at (updated_at)
                ) ENGINE=InnoDB
                DEFAULT CHARSET=utf8mb4
                COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS support_messages (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    ticket_id BIGINT UNSIGNED NOT NULL,
                    sender_type VARCHAR(20) NOT NULL DEFAULT 'user',
                    sender_id VARCHAR(191) NULL,
                    sender_name VARCHAR(255) NOT NULL DEFAULT '',
                    message TEXT NOT NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_support_messages_ticket (ticket_id),
                    KEY idx_support_messages_sender (sender_type),
                    KEY idx_support_messages_read (is_read),
                    CONSTRAINT fk_support_messages_ticket
                        FOREIGN KEY (ticket_id)
                        REFERENCES support_tickets(id)
                        ON DELETE CASCADE
                ) ENGINE=InnoDB
                DEFAULT CHARSET=utf8mb4
                COLLATE=utf8mb4_unicode_ci
            ");

            $ensured = true;
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

melkinoEnsureSupportTables();

/**
 * کاربر را در جدول users ثبت/به‌روزرسانی می‌کند — چه با آی‌دی تلگرام
 * شناسایی شده باشد، چه فقط با شماره تماس (بازدیدکننده‌ی مرورگر عادی).
 *
 * نکته‌ی امنیتی مهم: فقط تایپ‌کردن یک شماره تلفن، اثبات مالکیت آن
 * شماره نیست. قبلاً هرکسی با وارد کردن شماره‌ی شخص دیگری (مثلاً در
 * فرم ثبت ملک یا صفحه‌ی ورود)، سشن خودش را به همان شماره متصل
 * می‌کرد و می‌توانست «ملک‌های من» و «درخواست‌های من» فرد دیگری را
 * ببیند. حالا شماره‌ی تلفن به‌تنهایی چیزی را باز نمی‌کند: هر کاربرِ
 * شماره‌محور یک «access_token» تصادفی و مخفی می‌گیرد که فقط در
 * کوکی همان مرورگری که اول بار ثبت‌نام کرده ذخیره می‌شود. برای ادعای
 * یک شماره‌ی از قبل ثبت‌شده، باید همان توکن معتبر ارائه شود؛ در غیر
 * این صورت شماره «متعلق به دیگری» اعلام می‌شود و هویتی داده نمی‌شود.
 *
 * آی‌دی تلگرام همچنان مثل قبل معتبر و قابل‌اعتماد است، چون فقط
 * کلاینت واقعی تلگرامِ همان شخص می‌تواند آن را در اختیار سایت بگذارد.
 *
 * خروجی: آرایه‌ای شامل:
 *   - id: شناسه‌ی کاربر (یا null)
 *   - token: توکن معتبر برای ذخیره در کوکی مرورگر (یا null)
 *   - trusted: آیا این هویت برای اتصال سشن قابل‌اعتماد است؟
 *   - status: 'telegram' | 'new_phone' | 'verified_phone' | 'phone_taken' | 'none'
 */
if (!function_exists('melkinoUpsertUser')) {
    function melkinoUpsertUser(?string $telegramId, ?string $phone, ?string $name = null, ?string $username = null, ?string $providedToken = null, ?string $baleId = null): array
    {
        global $pdo;

        $telegramId = trim((string)$telegramId);
        $baleId = trim((string)$baleId);
        $phone = trim((string)$phone);
        $name = trim((string)$name);
        $username = trim((string)$username);
        $providedToken = trim((string)$providedToken);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $result = ['id' => null, 'token' => null, 'trusted' => false, 'status' => 'none'];

        if ($telegramId === '' && $baleId === '' && $phone === '') {
            return $result;
        }
        if (!($pdo instanceof PDO)) {
            return $result;
        }

        try {
            if ($telegramId !== '') {
                // مسیر تلگرام: مثل قبل، همیشه معتبر است.
                $stmt = $pdo->prepare(
                    "INSERT INTO users (telegram_id, username, name, phone, last_ip, first_login, last_login, login_count, is_active)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1, 1)
                     ON DUPLICATE KEY UPDATE
                         username = IF(VALUES(username) <> '', VALUES(username), username),
                         name = IF(VALUES(name) <> '', VALUES(name), name),
                         last_ip = VALUES(last_ip),
                         last_login = NOW(),
                         login_count = login_count + 1"
                );
                $stmt->execute([$telegramId, $username !== '' ? $username : null, $name !== '' ? $name : null, $phone !== '' ? $phone : null, $ip]);
                $isNewUser = $stmt->rowCount() === 1;

                $find = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ? LIMIT 1");
                $find->execute([$telegramId]);
                $id = $find->fetchColumn();
                $id = $id !== false ? (int)$id : null;

                $result = ['id' => $id, 'token' => null, 'trusted' => true, 'status' => 'telegram'];
                melkinoMaybeSendWelcome($pdo, $isNewUser, $id, $telegramId);
                return $result;
            }

            if ($baleId !== '') {
                // مسیر بله: دقیقاً مثل تلگرام، همیشه معتبر است.
                $stmt = $pdo->prepare(
                    "INSERT INTO users (bale_id, username, name, phone, last_ip, first_login, last_login, login_count, is_active)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1, 1)
                     ON DUPLICATE KEY UPDATE
                         username = IF(VALUES(username) <> '', VALUES(username), username),
                         name = IF(VALUES(name) <> '', VALUES(name), name),
                         last_ip = VALUES(last_ip),
                         last_login = NOW(),
                         login_count = login_count + 1"
                );
                $stmt->execute([$baleId, $username !== '' ? $username : null, $name !== '' ? $name : null, $phone !== '' ? $phone : null, $ip]);
                $isNewUser = $stmt->rowCount() === 1;

                $find = $pdo->prepare("SELECT id FROM users WHERE bale_id = ? LIMIT 1");
                $find->execute([$baleId]);
                $id = $find->fetchColumn();
                $id = $id !== false ? (int)$id : null;

                $result = ['id' => $id, 'token' => null, 'trusted' => true, 'status' => 'bale'];
                melkinoMaybeSendWelcome($pdo, $isNewUser, $id, '');
                return $result;
            }

            // مسیر فقط-شماره: اول ببینیم این شماره قبلاً ثبت شده یا نه.
            $existing = $pdo->prepare("SELECT id, access_token FROM users WHERE phone = ? LIMIT 1");
            $existing->execute([$phone]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                // شماره‌ی تازه: یک کاربر و یک توکن جدید ساخته می‌شود.
                $newToken = bin2hex(random_bytes(24));
                $stmt = $pdo->prepare(
                    "INSERT INTO users (phone, username, name, access_token, last_ip, first_login, last_login, login_count, is_active)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1, 1)"
                );
                $stmt->execute([$phone, $username !== '' ? $username : null, $name !== '' ? $name : null, $newToken, $ip]);
                $id = (int)$pdo->lastInsertId();

                $result = ['id' => $id, 'token' => $newToken, 'trusted' => true, 'status' => 'new_phone'];
                melkinoMaybeSendWelcome($pdo, true, $id, '');
                return $result;
            }

            // شماره از قبل وجود دارد؛ فقط با توکن معتبر همان مرورگر قابل تأیید است.
            if ($row['access_token'] !== null && $providedToken !== '' && hash_equals((string)$row['access_token'], $providedToken)) {
                $update = $pdo->prepare(
                    "UPDATE users SET
                        name = IF(? <> '', ?, name),
                        username = IF(? <> '', ?, username),
                        last_ip = ?,
                        last_login = NOW(),
                        login_count = login_count + 1
                     WHERE id = ?"
                );
                $update->execute([$name, $name, $username, $username, $ip, $row['id']]);

                $result = ['id' => (int)$row['id'], 'token' => $row['access_token'], 'trusted' => true, 'status' => 'verified_phone'];
                return $result;
            }

            // توکن معتبر نیست یا اصلاً ارسال نشده: این شماره متعلق به شخص دیگری‌ست.
            $result = ['id' => null, 'token' => null, 'trusted' => false, 'status' => 'phone_taken'];
            return $result;
        } catch (Throwable $e) {
            return $result;
        }
    }
}

if (!function_exists('melkinoMaybeSendWelcome')) {
    function melkinoMaybeSendWelcome(PDO $pdo, bool $isNewUser, ?int $userId, string $telegramId): void
    {
        if (!$isNewUser || $userId === null) return;
        try {
            $welcome = $pdo->prepare(
                "INSERT INTO notifications (user_id, telegram_id, type, title, message, url, is_read, created_at)
                 VALUES (?, ?, 'welcome', ?, ?, 'home.php', 0, NOW())"
            );
            $welcome->execute([
                $userId,
                $telegramId !== '' ? $telegramId : null,
                '🎉 به ملکینو خوش اومدی',
                'خوشحالیم که به جمع ملکینو پیوستی! از اینجا می‌تونی ملک‌های پیشنهادی، درخواست‌ها و آگهی‌های ثبت‌شده‌ات رو دنبال کنی.',
            ]);
        } catch (Throwable $e) {
            // اگر ثبت اعلان ناموفق بود، مانع از ادامه‌ی کار نمی‌شود
        }
    }
}

function melkinoCurrentIdentity(?string $telegramId = null): array
{
    global $pdo;
    // نکته‌ی امنیتی: قبلاً پارامتر $telegramId مستقیماً از $_GET/$_POST
    // در صفحاتی مثل my-properties.php، favorites.php و notifications.php
    // خونده و اینجا بدون هیچ اعتبارسنجی‌ای پذیرفته می‌شد؛ یعنی هرکسی با
    // تغییر آدرس (?telegram_id=...) می‌تونست ملک‌ها/علاقه‌مندی‌های
    // شخص دیگه‌ای رو ببینه. حالا این پارامتر نادیده گرفته می‌شه و
    // هویت فقط از سشن سروری (که خودِ سرور قبلاً تأییدش کرده) خونده می‌شه.
    $telegramId = trim((string)($_SESSION['reg_telegram_id'] ?? ''));
    $baleId = trim((string)($_SESSION['reg_bale_id'] ?? ''));
    $phone = trim((string)($_SESSION['user_phone'] ?? ''));
    $userId = null;

    if ($pdo instanceof PDO) {
        if ($telegramId !== '') {
            $stmt = $pdo->prepare('SELECT id, telegram_id, phone, name, username FROM users WHERE telegram_id = ? LIMIT 1');
            $stmt->execute([$telegramId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $userId = (int)$user['id'];
                $phone = $phone !== '' ? $phone : trim((string)($user['phone'] ?? ''));
                return ['user_id' => $userId, 'telegram_id' => $telegramId, 'phone' => $phone, 'user' => $user];
            }
        }

        if ($baleId !== '') {
            $stmt = $pdo->prepare('SELECT id, bale_id, phone, name, username FROM users WHERE bale_id = ? LIMIT 1');
            $stmt->execute([$baleId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $userId = (int)$user['id'];
                $phone = $phone !== '' ? $phone : trim((string)($user['phone'] ?? ''));
                return ['user_id' => $userId, 'telegram_id' => '', 'bale_id' => $baleId, 'phone' => $phone, 'user' => $user];
            }
        }

        if ($phone !== '') {
            $stmt = $pdo->prepare('SELECT id, telegram_id, phone, name, username FROM users WHERE phone = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$phone]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $userId = (int)$user['id'];
                $telegramId = $telegramId !== '' ? $telegramId : trim((string)($user['telegram_id'] ?? ''));
                return ['user_id' => $userId, 'telegram_id' => $telegramId, 'phone' => $phone, 'user' => $user];
            }
        }
    }

    return ['user_id' => null, 'telegram_id' => $telegramId, 'phone' => $phone, 'user' => null];
}

if (!function_exists('melkinoIssueTokenForVerifiedPhone')) {
    function melkinoIssueTokenForVerifiedPhone(string $phone, ?string $name = null): array
    {
        global $pdo;

        $phone = trim($phone);
        $name = trim((string)$name);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        if ($phone === '' || !($pdo instanceof PDO)) {
            return ['id' => null, 'token' => null];
        }

        $existing = $pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $existing->execute([$phone]);
        $id = $existing->fetchColumn();

        // یک کد یک‌بارمصرفِ درست، خودش قوی‌ترین اثباتِ مالکیت شماره‌ست؛
        // بنابراین همیشه یک توکن دسترسی تازه صادر می‌شود (even اگر قبلاً
        // توکنی برای مرورگر دیگری صادر شده بود) — این دقیقاً معادل
        // «ورود مجدد با احراز هویت واقعی» است.
        $newToken = bin2hex(random_bytes(24));

        if ($id === false) {
            $stmt = $pdo->prepare(
                "INSERT INTO users (phone, name, access_token, last_ip, first_login, last_login, login_count, is_active)
                 VALUES (?, ?, ?, ?, NOW(), NOW(), 1, 1)"
            );
            $stmt->execute([$phone, $name !== '' ? $name : null, $newToken, $ip]);
            $id = (int)$pdo->lastInsertId();
            melkinoMaybeSendWelcome($pdo, true, $id, '');
        } else {
            $id = (int)$id;
            $stmt = $pdo->prepare(
                "UPDATE users SET access_token = ?, name = IF(? <> '', ?, name), last_ip = ?, last_login = NOW(), login_count = login_count + 1 WHERE id = ?"
            );
            $stmt->execute([$newToken, $name, $name, $ip, $id]);
        }

        return ['id' => $id, 'token' => $newToken];
    }
}

function melkinoJsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function melkinoMoney($value): string
{
    $raw = str_replace(',', '', trim((string)$value));
    if ($raw === '' || !is_numeric($raw) || (float)$raw == 0.0) {
        return '';
    }
    return number_format((float)$raw, 0, '.', ',');
}

function melkinoNumber($value): string
{
    $raw = str_replace(',', '', trim((string)$value));
    if ($raw === '' || !is_numeric($raw)) return '';
    $num = (float)$raw;
    if ((int)$num == $num) return number_format($num, 0, '.', ',');
    return rtrim(rtrim(number_format($num, 2, '.', ','), '0'), '.');
}

/**
 * ارسال اعلان برای کاربر
 * 
 * @param int|null $userId
 * @param string|null $telegramId
 * @param string $type
 * @param string $title
 * @param string $message
 * @param string|null $url
 * @param string|null $adId
 * @param int|null $requestId
 * @param int|null $matchPercent
 * @return bool
 */
function sendNotification($userId, $telegramId, $type, $title, $message, $url = null, $adId = null, $requestId = null, $matchPercent = null) {
    global $pdo;
    if (!$pdo instanceof PDO) {
        return false;
    }
    if (empty($userId) && empty($telegramId)) {
        return false;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO notifications 
        (user_id, telegram_id, type, title, message, url, ad_id, request_id, match_percent, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    return $stmt->execute([
        $userId ?: null,
        $telegramId ?: null,
        $type,
        $title,
        $message,
        $url,
        $adId ?: null,
        $requestId ?: null,
        $matchPercent !== null ? (int)$matchPercent : null
    ]);
}