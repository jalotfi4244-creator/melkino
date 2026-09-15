<?php
declare(strict_types=1);

if (!function_exists('dbSettingGet')) {
    function dbSettingGet(PDO $pdo, string $group, string $key, $default = null) {
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
        if ($type === 'json') $value = json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        elseif ($type === 'boolean') $value = $value ? 'true' : 'false';
        else $value = (string)$value;
        $st = $pdo->prepare("INSERT INTO settings (setting_group,setting_key,setting_value,value_type,updated_by_admin_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type=VALUES(value_type),updated_by_admin_id=VALUES(updated_by_admin_id)");
        return $st->execute([$group,$key,$value,$type,$adminId]);
    }
}
