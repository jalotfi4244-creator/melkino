<?php
/*
|--------------------------------------------------------------------------
| تأیید کد یک‌بارمصرف (OTP) و ورود
|--------------------------------------------------------------------------
*/

session_start();
header('Content-Type: application/json; charset=utf-8');

// =========================================================
// ورود با شماره موبایل / کد یک‌بارمصرف غیرفعال شده است.
// تنها مسیر ورود، مینی‌اپ تلگرام یا بله است.
// (برای فعال‌سازی دوباره، این بلوک را حذف کن)
// =========================================================
http_response_code(403);
echo json_encode([
    'success' => false,
    'message' => 'ورود فقط از طریق تلگرام و پیام‌رسان بله امکان‌پذیر است.',
], JSON_UNESCAPED_UNICODE);
exit;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

$phone = strtr((string)($body['phone'] ?? ''), [
    '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
]);
$phone = preg_replace('/\D/', '', $phone);
$code = trim((string)($body['code'] ?? ''));
$code = strtr($code, [
    '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
]);

if (!preg_match('/^09\d{9}$/', $phone) || !preg_match('/^\d{6}$/', $code)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'شماره یا کد نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT * FROM otp_codes
         WHERE phone = ? AND is_used = 0
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$phone]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'کدی برای این شماره درخواست نشده یا قبلاً استفاده شده.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (strtotime($row['expires_at']) < time()) {
        echo json_encode(['success' => false, 'message' => 'کد منقضی شده؛ دوباره درخواست کد بده.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // محدودیت تلاش: حداکثر ۳ بار می‌توان کد اشتباه وارد کرد
    if ((int)$row['attempts'] >= 3) {
        echo json_encode(['success' => false, 'message' => 'تعداد تلاش‌های مجاز تمام شده؛ دوباره درخواست کد بده.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!hash_equals((string)$row['code'], $code)) {
        $pdo->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        $remaining = 3 - ((int)$row['attempts'] + 1);
        echo json_encode([
            'success' => false,
            'message' => $remaining > 0 ? "کد اشتباه است. $remaining تلاش دیگر باقی مانده." : 'کد اشتباه است و تلاش‌هایت تمام شد؛ دوباره درخواست کد بده.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // کد درست است — یک‌بارمصرف بودنش را همین‌جا قفل می‌کنیم
    $pdo->prepare('UPDATE otp_codes SET is_used = 1 WHERE id = ?')->execute([$row['id']]);

    $issued = melkinoIssueTokenForVerifiedPhone($phone);

    if (empty($issued['id'])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'ورود ناموفق بود.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // نکته‌ی امنیتی: بعد از هر ورود موفق و تازه، شناسه‌ی نشست از نو
    // ساخته می‌شود (جلوگیری از Session Fixation).
    session_regenerate_id(true);

    $_SESSION['user_phone'] = $phone;

    // کوکیِ توکن دسترسی: HttpOnly (غیرقابل‌دسترس از جاوااسکریپت) و
    // روی اتصال HTTPS واقعی، Secure هم فعال می‌شود.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    setcookie('melkino_access_token', $issued['token'], [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    echo json_encode(['success' => true, 'user_id' => $issued['id']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطا در تأیید کد.'], JSON_UNESCAPED_UNICODE);
}
