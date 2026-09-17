<?php
declare(strict_types=1);

/**
 * جدول settings در هیچ جای پروژه ساخته نمی‌شد؛ به همین دلیل هر بار که ادمین
 * توکن ربات را ذخیره می‌کرد، درج (INSERT) با خطا مواجه می‌شد و مقدار هیچ‌وقت
 * ذخیره نمی‌شد — در نتیجه بعد از خروج از پنل، توکن «پاک شده» به‌نظر می‌رسید
 * و تستِ اتصال همیشه ناموفق بود.
 *
 * این تابع جدول را در صورت نبودن می‌سازد. کلید یکتا روی (group, key) ضروری
 * است تا عبارت ON DUPLICATE KEY UPDATE درست کار کند.
 */
if (!function_exists('melkinoEnsureSettingsTable')) {
    function melkinoEnsureSettingsTable(?PDO $pdo = null): bool
    {
        static $done = null;
        if ($done !== null) {
            return $done;
        }

        if (!($pdo instanceof PDO)) {
            global $pdo;
        }
        if (!($pdo instanceof PDO)) {
            return $done = false;
        }

        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS settings (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    setting_group VARCHAR(64) NOT NULL DEFAULT 'global',
                    setting_key VARCHAR(191) NOT NULL,
                    setting_value LONGTEXT NULL,
                    value_type VARCHAR(32) NOT NULL DEFAULT 'string',
                    updated_by_admin_id BIGINT UNSIGNED NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_settings_group_key (setting_group, setting_key),
                    KEY idx_settings_group (setting_group)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            return $done = true;
        } catch (Throwable $e) {
            return $done = false;
        }
    }
}

if (!function_exists('dbSettingGet')) {
    function dbSettingGet(PDO $pdo, string $group, string $key, $default = null) {
        if (function_exists('melkinoEnsureSettingsTable')) {
            melkinoEnsureSettingsTable($pdo);
        }
        $st = $pdo->prepare("SELECT setting_value, value_type FROM settings WHERE setting_group = ? AND setting_key = ? LIMIT 1");
        $st->execute([$group, $key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $default;
        $v = $row['setting_value'];
        switch ($row['value_type']) {
            case 'boolean': return filter_var($v, FILTER_VALIDATE_BOOLEAN);
            case 'integer': return (int)$v;
            case 'decimal': return (float)$v;
            case 'json':
                $d = json_decode((string)$v, true);
                return is_array($d) ? $d : $default;
            default: return (string)$v;
        }
    }
}
if (!function_exists('dbSettingSet')) {
    function dbSettingSet(PDO $pdo, string $group, string $key, $value, string $type = 'string', ?int $adminId = null): bool {
        if (function_exists('melkinoEnsureSettingsTable')) {
            melkinoEnsureSettingsTable($pdo);
        }
        if ($type === 'json') $value = json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        elseif ($type === 'boolean') $value = $value ? 'true' : 'false';
        else $value = (string)$value;
        $st = $pdo->prepare("INSERT INTO settings (setting_group,setting_key,setting_value,value_type,updated_by_admin_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type=VALUES(value_type),updated_by_admin_id=VALUES(updated_by_admin_id)");
        return $st->execute([$group,$key,$value,$type,$adminId]);
    }
}
