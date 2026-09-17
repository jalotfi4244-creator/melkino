<?php
/**
 * نمونه‌ی فایل مقادیر محرمانه — این فایل را کپی کن با نام
 * config.secrets.php و مقادیر واقعی را در آن قرار بده.
 *
 * فایل config.secrets.php در .gitignore قرار دارد و هرگز commit نمی‌شود.
 *
 * ترجیح امن‌تر: به‌جای این فایل، متغیرهای محیطی سرور را تنظیم کن
 * (ENV). config.php ابتدا این فایل را می‌خواند، اگر نبود سراغ ENV می‌رود.
 *
 * ⚠️ هشدار: مقادیری که قبلاً داخل config.php بودند در تاریخچه‌ی گیت
 * این مخزن باقی مانده‌اند. حتماً توکن ربات‌ها و رمز دیتابیس را
 * بازنشانی (rotate) کن.
 */

// ---- دیتابیس ----
// define('DB_HOST', 'sql303.infinityfree.com');
// define('DB_NAME', 'if0_42615627_melkino');
// define('DB_USER', 'if0_42615627');
// define('DB_PASS', 'رمز_دیتابیس');

// ---- ربات تلگرام ----
// define('BOT_TOKEN', '123456:ABC-DEF...');
// define('CHANNEL_ID', '@melkino_shahrood');

// ---- ربات بله ----
// define('BALE_BOT_TOKEN', '...');

// ---- پیامک (اختیاری) ----
// define('SMS_API_KEY', '');
// define('SMS_API_URL', '');
// define('SMS_SENDER_LINE', '');

// ---- رمز پشتیبان ادمین (اختیاری و موقت) ----
// فقط زمانی استفاده می‌شود که هیچ ردیفی در جدول admins وجود نداشته باشد.
// بعد از ساخت حساب ادمین در دیتابیس، این خط را حذف کن.
// define('MELKINO_LEGACY_ADMIN_PASSWORD', 'melkinoAdmin');
