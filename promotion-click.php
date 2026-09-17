<?php
/**
|--------------------------------------------------------------------------
| ثبت کلیک روی تبلیغ و هدایت به مقصد
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';

$id = (int)($_GET['id'] ?? 0);
$fallback = 'index.php';

if ($id > 0 && ($pdo instanceof PDO)) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS promotions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL DEFAULT '',
            image_url VARCHAR(500) NOT NULL DEFAULT '',
            link_url VARCHAR(500) NOT NULL DEFAULT '',
            button_text VARCHAR(100) NOT NULL DEFAULT 'مشاهده',
            description VARCHAR(1000) NOT NULL DEFAULT '',
            placement VARCHAR(50) NOT NULL DEFAULT 'all',
            position_after INT UNSIGNED NOT NULL DEFAULT 3,
            repeat_every INT UNSIGNED NOT NULL DEFAULT 0,
            start_date DATETIME NULL,
            end_date DATETIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            clicks INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $st = $pdo->prepare("SELECT link_url FROM promotions WHERE id = ? AND is_active = 1 LIMIT 1");
        $st->execute([$id]);
        $link = trim((string)$st->fetchColumn());

        $pdo->prepare("UPDATE promotions SET clicks = clicks + 1 WHERE id = ?")->execute([$id]);

        if ($link !== '') {
            $fallback = $link;
        }
    } catch (Throwable $e) {
        // در صورت خطا، کاربر به صفحه اصلی هدایت می‌شود
    }
}

// فقط مقصدهای امن (http/https) پذیرفته می‌شوند
if (!preg_match('#^https?://#i', $fallback)) {
    $fallback = 'index.php';
}

header('Location: ' . $fallback, true, 302);
exit;
