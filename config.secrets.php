<?php
/**
 * مقادیر محرمانه — این فایل در .gitignore است و commit نمی‌شود.
 *
 * این مقادیر از config.php قدیمی منتقل شده‌اند تا دیگر داخل مخزن
 * عمومی نباشند. چون آن‌ها قبلاً در تاریخچه‌ی گیت قرار گرفته‌اند،
 * باید حتماً بازنشانی (rotate) شوند:
 *   - رمز دیتابیس را در پنل هاست عوض کن
 *   - توکن تلگرام را از @BotFather با /revoke بازنشانی کن
 *   - توکن بله را از پنل توسعه‌دهندگان بله بازنشانی کن
 */

// ---- دیتابیس ----
define('DB_HOST', 'sql303.infinityfree.com');
define('DB_NAME', 'if0_42615627_melkino');
define('DB_USER', 'if0_42615627');
define('DB_PASS', 'Javad4244');

// ---- ربات تلگرام ----
define('BOT_TOKEN', '8942418934:AAEi81P4LISH40EDMScg_V9hc2GbbMHlFJs');
define('CHANNEL_ID', '@melkino_shahrood');

// ---- ربات بله ----
define('BALE_BOT_TOKEN', '387417012:-ZJCL66dm8xQHrRe-Hmcc4vtdB_tLfaUhmE');

// ---- رمز پشتیبان ادمین (موقت) ----
// بعد از این‌که مطمئن شدی حساب ادمین در جدول admins ساخته شده،
// این خط را پاک کن.
define('MELKINO_LEGACY_ADMIN_PASSWORD', 'melkinoAdmin');
