<?php
session_start();
require_once __DIR__ . '/config.php';

const LEGACY_ADMIN_PASSWORD = 'melkinoAdmin';

$security = [];
foreach (['lockout', 'admin_login_log'] as $k) {
    $security[$k] = (bool) dbSettingGet($pdo, 'security', $k, true);
}

$state = dbSettingGet($pdo, 'security', 'admin_attempt_state', ['count' => 0, 'locked_until' => 0]);
if (!is_array($state)) {
    $state = ['count' => 0, 'locked_until' => 0];
}

$error = false;
$errorMessage = '';

if ($security['lockout'] && (int) ($state['locked_until'] ?? 0) > time()) {
    $remaining = max(1, ceil(((int) $state['locked_until'] - time()) / 60));
    $error = true;
    $errorMessage = 'به‌دلیل چند ورود ناموفق، ورود موقتاً قفل شده است. حدود ' . $remaining . ' دقیقه دیگر دوباره تلاش کنید.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputPass = (string) ($_POST['password'] ?? '');

    $st = $pdo->prepare('SELECT id, username, password_hash, is_active, display_name FROM admins WHERE username = :username LIMIT 1');
    $st->execute(['username' => 'admin']);
    $admin = $st->fetch(PDO::FETCH_ASSOC);

    $valid = $admin && !empty($admin['is_active']) && password_verify($inputPass, (string) $admin['password_hash']);

    if (!$valid && !$admin) {
        $valid = hash_equals(LEGACY_ADMIN_PASSWORD, $inputPass);
    }

    if ($valid) {
        $_SESSION['is_admin'] = true;
        $_SESSION['user_role'] = 'admin';
        $_SESSION['admin_id'] = (int) ($admin['id'] ?? 0);
        $_SESSION['admin_username'] = (string) ($admin['username'] ?? 'admin');
        $_SESSION['admin_display_name'] = (string) ($admin['display_name'] ?? '');
        $_SESSION['admin_login_at'] = time();
        $_SESSION['admin_last_activity'] = time();

        dbSettingSet($pdo, 'security', 'admin_attempt_state', ['count' => 0, 'locked_until' => 0], 'json', $_SESSION['admin_id'] ?: null);

        if ($security['admin_login_log']) {
            $lg = $pdo->prepare('INSERT INTO admin_login_attempts (username, success, ip_address, user_agent, failure_reason) VALUES (:username, :success, :ip, :ua, :reason)');
            $lg->execute([
                'username' => 'admin',
                'success' => 1,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
                'reason' => null
            ]);
        }

        header('Location: admin-panel.php');
        exit;
    }

    $count = ((int) ($state['count'] ?? 0)) + 1;
    $locked = 0;

    if ($security['lockout'] && $count >= 5) {
        $locked = time() + 900;
        $count = 0;
    }

    $state = ['count' => $count, 'locked_until' => $locked];
    dbSettingSet($pdo, 'security', 'admin_attempt_state', $state, 'json');

    if ($security['admin_login_log']) {
        $lg = $pdo->prepare('INSERT INTO admin_login_attempts (username, success, ip_address, user_agent, failure_reason) VALUES (:username, :success, :ip, :ua, :reason)');
        $lg->execute([
            'username' => 'admin',
            'success' => 0,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
            'reason' => 'invalid_password'
        ]);
    }

    $error = true;
    $errorMessage = $locked
        ? '۵ ورود ناموفق ثبت شد. ورود پنل برای ۱۵ دقیقه قفل شد.'
        : 'رمز عبور اشتباه است! لطفاً دوباره تلاش کنید.';
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>ملکینو - ورود ادمین</title>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <link rel="stylesheet" href="style.css">
    <style>
        .main-content { flex: 1; display: flex; justify-content: center; align-items: center; background: var(--bg); padding: var(--space-3); }
        .login-box { background: var(--surface); border-radius: var(--radius-lg); padding: var(--space-4); box-shadow: var(--shadow-card); border: 1px solid var(--border); width: 100%; max-width: 380px; }
        .login-title { font-size: 24px; font-weight: 800; color: var(--primary); text-align: center; margin-bottom: var(--space-1); }
        .login-sub { font-size: 14px; color: var(--text-secondary); text-align: center; margin-bottom: var(--space-3); }
        .login-error { background: #FEE2E2; color: var(--danger); padding: var(--space-1); border-radius: var(--radius-sm); text-align: center; font-size: 14px; margin-bottom: var(--space-2); }
        .login-input { width: 100%; height: 50px; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 0 var(--space-2); font-size: 16px; font-family: 'Vazirmatn', sans-serif; margin-bottom: var(--space-2); outline: none; background: var(--bg); }
        .login-input:focus { border-color: var(--primary); }
        .login-btn { width: 100%; height: 52px; background: var(--primary); color: #fff; border: none; border-radius: var(--radius-md); font-weight: 700; font-size: 18px; cursor: pointer; }
        .login-btn:active { transform: scale(0.98); opacity: 0.8; }
        .login-back { display: block; text-align: center; margin-top: var(--space-2); color: var(--text-secondary); text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>
    <div class="app-container" style="padding-bottom: 0;">
        <header class="topbar">
            <a href="profile.php" class="topbar-action"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"></polyline></svg></a>
            <span class="topbar-title">ورود به پنل مدیریت</span>
            <div style="width:24px;"></div>
        </header>
        <div class="main-content">
            <div class="login-box">
                <div class="login-title">🔐 ملکینو</div>
                <div class="login-sub">برای دسترسی به تنظیمات، رمز عبور ادمین را وارد کنید.</div>
                
                <?php if ($error): ?>
                <div class="login-error">رمز عبور اشتباه است! لطفاً دوباره تلاش کنید.</div>
                <?php endif; ?>

                <form method="POST">
                    <input type="password" class="login-input" name="password" placeholder="رمز عبور ادمین را وارد کنید" required>
                    <button type="submit" class="login-btn">ورود به پنل</button>
                </form>

                <a href="profile.php" class="login-back">بازگشت به پروفایل</a>
            </div>
        </div>
    </div>
</body>
</html>