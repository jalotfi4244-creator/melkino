<?php
/*
|--------------------------------------------------------------------------
| خروج از حساب کاربری
|--------------------------------------------------------------------------
| قبلاً این فایل اصلاً وجود نداشت و دکمه‌ی «خروج از حساب کاربری» در
| پروفایل با خطای 404 مواجه می‌شد. این فایل هویت کاربر را از سشن سرور
| پاک می‌کند و سپس با یک اسکریپت کوتاه، اطلاعات ذخیره‌شده در مرورگر
| (localStorage/sessionStorage) را هم پاک می‌کند تا واقعاً خارج شود.
|--------------------------------------------------------------------------
*/

session_start();

unset($_SESSION['user_phone']);
unset($_SESSION['user_name']);
unset($_SESSION['reg_telegram_id']);
unset($_SESSION['reg_bale_id']);

setcookie('melkino_access_token', '', time() - 3600, '/');

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>خروج از حساب...</title>
</head>
<body style="font-family:Tahoma,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#0D1413;color:#F3F4F6;">
<p>در حال خروج...</p>
<script>
    try {
        localStorage.removeItem('melkino_user_phone');
        localStorage.removeItem('melkino_telegram_id');
        localStorage.removeItem('melkino_bale_id');
        sessionStorage.removeItem('reg_telegram_id');
        sessionStorage.removeItem('reg_phone');
        sessionStorage.removeItem('melkino_identified');
    } catch (e) {}
    window.location.href = 'profile.php';
</script>
</body>
</html>
