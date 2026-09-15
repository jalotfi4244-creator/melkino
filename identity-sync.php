<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
// نکته: قبلاً این فایل db_helpers.php را require نمی‌کرد؛ یعنی
// melkinoUpsertUser() اصلاً تعریف نشده بود و هر فراخوانی این
// endpoint با خطای «تابع تعریف‌نشده» رد می‌خورد.

if (($_GET['action'] ?? '') === 'list') {
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rows = $pdo->query("SELECT id,telegram_id,username,name,phone,is_active,first_login,last_login,login_count,created_at FROM users ORDER BY last_login DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'users' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['action'] ?? '') === 'history') {
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $uid = (int)($_GET['user_id'] ?? 0);
    if ($uid <= 0) {
        echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $st = $pdo->prepare("SELECT id,telegram_id,username,name,ip_address,user_agent,created_at FROM login_events WHERE user_id=? ORDER BY created_at DESC LIMIT 200");
    $st->execute([$uid]);
    echo json_encode(['success' => true, 'events' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

$p = json_decode(file_get_contents('php://input'), true);
if (!is_array($p)) $p = [];

$phone = trim((string)($p['phone'] ?? ''));
$rawInitData = (string)($p['init_data'] ?? '');

// نکته‌ی امنیتی مهم: قبلاً هر مقدار telegram_id/username/name که
// کلاینت خودش می‌فرستاد مستقیماً پذیرفته می‌شد — یعنی هرکسی می‌تونست
// مستقیم به این endpoint درخواست بزنه و ادعا کنه فلان آی‌دی تلگرام
// رو داره. حالا فقط initData خام رو می‌گیریم و با امضای رمزنگاری‌شده‌ی
// تلگرام (که فقط خودِ تلگرام می‌تونه درست بسازدش) تأییدش می‌کنیم.
$tg = '';
$name = '';
$u = '';

if ($rawInitData !== '') {
    $verified = melkinoVerifyTelegramInitData($rawInitData);
    if ($verified !== null) {
        $tg = $verified['id'];
        $u = $verified['username'];
        $name = trim($verified['first_name'] . ' ' . $verified['last_name']);
    }
}

if ($tg === '' && $phone === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'اطلاعات هویتی معتبر ارسال نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);

$providedToken = trim((string)($_COOKIE['melkino_access_token'] ?? ''));
$identity = melkinoUpsertUser($tg, $phone, $name, $u, $providedToken);
$uid = $identity['id'];

if ($uid && ($tg !== '' || $phone !== '')) {
    $le = $pdo->prepare('INSERT INTO login_events (user_id,telegram_id,username,name,ip_address,user_agent) VALUES (?,?,?,?,?,?)');
    $le->execute([$uid, $tg !== '' ? $tg : null, $u, $name, $ip, $ua]);
}

$existingPhone = '';

if (!empty($identity['trusted'])) {
    if ($tg !== '') {
        $_SESSION['reg_telegram_id'] = $tg;
    }
    if ($name !== '') {
        $_SESSION['user_name'] = $name;
    }
    $existingPhone = $phone;
    if ($uid) {
        $row = $pdo->prepare('SELECT phone FROM users WHERE id=?');
        $row->execute([$uid]);
        $fromDb = trim((string)$row->fetchColumn());
        if ($fromDb !== '') $existingPhone = $fromDb;
    }
    if ($existingPhone !== '') {
        $_SESSION['user_phone'] = $existingPhone;
    }
    if (!empty($identity['token'])) {
        setcookie('melkino_access_token', $identity['token'], time() + 31536000, '/', '', false, true);
    }
}

echo json_encode([
    'success' => !empty($identity['trusted']),
    'status' => $identity['status'],
    'user_id' => $uid,
    'phone' => $existingPhone,
], JSON_UNESCAPED_UNICODE);
