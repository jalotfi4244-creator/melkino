<?php
/*
|--------------------------------------------------------------------------
| ورود با مینی‌اپ تلگرام
|--------------------------------------------------------------------------
| کلاینت، initData خام (window.Telegram.WebApp.initData) رو می‌فرسته.
| هیچ کد یا رمزی لازم نیست چون خودِ امضای تلگرام هویت رو تضمین می‌کنه.
|--------------------------------------------------------------------------
*/

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

$initData = (string)($body['init_data'] ?? '');
if ($initData === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'initData ارسال نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$verified = telegramVerifyInitData($initData);
if ($verified === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'امضای تلگرام معتبر نیست یا منقضی شده.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$name = trim($verified['first_name'] . ' ' . $verified['last_name']);
$identity = melkinoUpsertUser($verified['id'], '', $name, $verified['username']);

// ثبت کامل اطلاعات این ورود (IP، مرورگر، پلتفرم) برای پنل ادمین
melkinoRecordLoginInfo($identity['id'] ?? null, 'telegram', [
    'telegram_id' => $verified['id'],
    'username'    => $verified['username'],
    'name'        => $name,
]);

if (empty($identity['trusted'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ثبت هویت ناموفق بود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// نکته‌ی امنیتی: هر بار که یک نشست ورودِ جدید و معتبر برقرار می‌شود،
// شناسه‌ی نشست (session id) از نو تولید می‌شود تا از حملات تثبیت
// نشست (Session Fixation) جلوگیری شود.
session_regenerate_id(true);

$_SESSION['reg_telegram_id'] = $verified['id'];
if ($name !== '') {
    $_SESSION['user_name'] = $name;
}

$phoneRow = $pdo->prepare('SELECT phone FROM users WHERE id = ?');
$phoneRow->execute([$identity['id']]);
$phone = trim((string)$phoneRow->fetchColumn());
if ($phone !== '') {
    $_SESSION['user_phone'] = $phone;
}

// توکنِ پشتیبانِ ورود: برای مرورگرهای داخلی که کوکیِ نشست را نگه نمی‌دارند
$loginToken = function_exists('melkinoMintLoginToken')
    ? melkinoMintLoginToken($identity['id'] ?? null, $verified['id'], null)
    : '';

echo json_encode([
    'success' => true,
    'user_id' => $identity['id'],
    'name' => $name,
    'phone' => $phone,
    'login_token' => $loginToken,
], JSON_UNESCAPED_UNICODE);
