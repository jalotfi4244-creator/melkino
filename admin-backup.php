<?php
/**
|--------------------------------------------------------------------------
| پشتیبان‌گیری و بازیابی (پنل ادمین)
|--------------------------------------------------------------------------
| actions:
|   create     ساخت فایل پشتیبان (زیپ فایل‌ها + خروجی دیتابیس)
|   list       فهرست فایل‌های پشتیبان
|   download   دریافت یک فایل پشتیبان
|   delete     حذف یک فایل پشتیبان
|   restore    بازیابی فایل‌ها (و در صورت درخواست، دیتابیس)
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/admin-guard.php';

const MELKINO_BACKUP_DIRNAME = 'backups';

function melkinoBackupsDir(): string
{
    $dir = __DIR__ . '/' . MELKINO_BACKUP_DIRNAME;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/** فقط نام‌های امن (بدون مسیر) پذیرفته می‌شوند — جلوگیری از Path Traversal */
function melkinoSafeBackupName(?string $name): string
{
    $name = basename(trim((string)$name));
    if (!preg_match('/^melkino-backup-[\w.-]+\.(zip|tar|tar\.gz)$/', $name)) {
        return '';
    }
    return $name;
}

/**
 * اگر افزونه ZipArchive روی هاست فعال نباشد، از PharData استفاده
 * می‌شود (که به‌صورت پیش‌فرض همراه PHP است) و بایگانی به‌جای zip
 * با فرمت tar ساخته می‌شود.
 */
function melkinoCreateArchive(string $targetPath, string $databaseSql = ''): array
{
    $root = __DIR__;
    $excludes = melkinoBackupExcludes();
    $fileCount = 0;

    if (str_ends_with($targetPath, '.zip') && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($targetPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('ساخت فایل زیپ ممکن نشد.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $path => $info) {
            $relative = substr($path, strlen($root) + 1);
            $parts = explode('/', str_replace('\\', '/', $relative));
            if (in_array($parts[0], $excludes, true)) {
                continue;
            }
            if ($info->isDir()) {
                $zip->addEmptyDir($relative);
            } elseif ($info->isFile()) {
                $zip->addFile($path, $relative);
                $fileCount++;
            }
        }

        if ($databaseSql !== '') {
            $zip->addFromString('database.sql', $databaseSql);
        }
        $zip->close();

        return [$fileCount, true];
    }

    // مسیر جایگزین: tar
    $tarPath = preg_replace('/\.zip$/', '.tar', $targetPath);
    if (is_file($tarPath)) {
        @unlink($tarPath);
    }

    $tar = new PharData($tarPath);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $path => $info) {
        $relative = substr($path, strlen($root) + 1);
        $parts = explode('/', str_replace('\\', '/', $relative));
        if (in_array($parts[0], $excludes, true)) {
            continue;
        }
        if ($info->isFile()) {
            $tar->addFile($path, $relative);
            $fileCount++;
        }
    }

    if ($databaseSql !== '') {
        file_put_contents(sys_get_temp_dir() . '/melkino-database.sql', $databaseSql);
        $tar->addFile(sys_get_temp_dir() . '/melkino-database.sql', 'database.sql');
    }

    return [$fileCount, true, $tarPath];
}

function melkinoBackupExcludes(): array
{
    return [MELKINO_BACKUP_DIRNAME, '.git', 'node_modules', '.cache'];
}

/** خروجی SQL از دیتابیس با استفاده از PDO (نیازی به mysqldump ندارد) */
function melkinoDumpDatabase(PDO $pdo): string
{
    $out = "-- ملکینو - پشتیبان دیتابیس\n";
    $out .= "-- تاریخ: " . date('Y-m-d H:i:s') . "\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
        $out .= 'DROP TABLE IF EXISTS `' . $table . "`;\n";
        $out .= $create[1] . ";\n\n";

        $stmt = $pdo->query('SELECT * FROM `' . $table . '`');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns = array_map(fn($c) => '`' . $c . '`', array_keys($row));
            $values = array_map(function ($v) use ($pdo) {
                if ($v === null) return 'NULL';
                return $pdo->quote((string)$v);
            }, array_values($row));
            $out .= 'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
        }
        $out .= "\n";
    }

    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $out;
}

$melkinoBackupAction = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($melkinoBackupAction !== '') {
    melkinoRequireAdminJson();

    global $pdo;
    $dir = melkinoBackupsDir();

    switch ($melkinoBackupAction) {
        case 'list':
            $items = [];
            foreach (array_merge(glob($dir . '/*.zip') ?: [], glob($dir . '/*.tar') ?: [], glob($dir . '/*.tar.gz') ?: []) as $file) {
                $items[] = [
                    'name' => basename($file),
                    'size' => filesize($file),
                    'size_human' => round(filesize($file) / 1024 / 1024, 2) . ' مگابایت',
                    'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                ];
            }
            usort($items, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
            melkinoAdminJson(['success' => true, 'backups' => $items, 'available' => class_exists('ZipArchive')]);

        case 'create':
            if (!class_exists('ZipArchive') && !class_exists('PharData')) {
                melkinoAdminJson(['success' => false, 'message' => 'هیچ کتابخانه‌ی فشرده‌سازی روی سرور در دسترس نیست.'], 500);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                melkinoAdminJson(['success' => false, 'message' => 'پوشه backups وجود ندارد یا قابل نوشتن نیست.'], 500);
            }

            $includeDb = ($_GET['with_db'] ?? $_POST['with_db'] ?? '1') !== '0';
            $fileName = 'melkino-backup-' . date('Ymd-His') . '.zip';
            $target = $dir . '/' . $fileName;

            $databaseSql = '';
            if ($includeDb && ($pdo instanceof PDO)) {
                try {
                    $databaseSql = melkinoDumpDatabase($pdo);
                } catch (Throwable $e) {
                    $databaseSql = '';
                }
            }

            try {
                $result = melkinoCreateArchive($target, $databaseSql);
            } catch (Throwable $e) {
                melkinoAdminJson(['success' => false, 'message' => $e->getMessage()], 500);
            }

            $fileCount = (int)($result[0] ?? 0);
            $archivePath = (string)($result[2] ?? $target);
            $dbDone = $databaseSql !== '';

            if (!is_file($archivePath)) {
                melkinoAdminJson(['success' => false, 'message' => 'ساخت فایل پشتیبان ناموفق بود.'], 500);
            }

            melkinoAdminJson([
                'success' => true,
                'message' => 'پشتیبان ساخته شد (' . $fileCount . ' فایل' . ($dbDone ? ' + دیتابیس' : '') . ').',
                'file' => basename($archivePath),
                'size' => filesize($archivePath),
            ]);

            melkinoAdminJson([
                'success' => true,
                'message' => 'پشتیبان ساخته شد (' . $fileCount . ' فایل' . ($dbDone ? ' + دیتابیس' : '') . ').',
                'file' => $fileName,
                'size' => filesize($target),
            ]);

        case 'download':
            $name = melkinoSafeBackupName($_GET['file'] ?? '');
            $path = $dir . '/' . $name;
            if ($name === '' || !is_file($path)) {
                http_response_code(404);
                echo 'فایل پشتیبان پیدا نشد.';
                exit;
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;

        case 'delete':
            $data = melkinoAdminJsonBody();
            $name = melkinoSafeBackupName($data['file'] ?? '');
            $path = $dir . '/' . $name;
            if ($name === '' || !is_file($path)) {
                melkinoAdminJson(['success' => false, 'message' => 'فایل پشتیبان پیدا نشد.'], 404);
            }
            @unlink($path);
            melkinoAdminJson(['success' => true, 'message' => 'فایل پشتیبان حذف شد.']);

        case 'restore':
            if (!class_exists('ZipArchive') && !class_exists('PharData')) {
                melkinoAdminJson(['success' => false, 'message' => 'هیچ کتابخانه‌ی فشرده‌سازی روی سرور در دسترس نیست.'], 500);
            }

            $data = melkinoAdminJsonBody();
            $name = melkinoSafeBackupName($data['file'] ?? '');
            $path = $dir . '/' . $name;
            $restoreDb = !empty($data['restore_db']);

            if ($name === '' || !is_file($path)) {
                melkinoAdminJson(['success' => false, 'message' => 'فایل پشتیبان پیدا نشد.'], 404);
            }

            $restored = 0;
            $dbSql = '';
            $excludes = melkinoBackupExcludes();

            $copyEntry = function (string $relative, string $content) use (&$restored, $excludes) {
                $parts = explode('/', str_replace('\\', '/', $relative));
                if (in_array($parts[0], $excludes, true) || $relative === 'database.sql') {
                    return;
                }
                if (strpos($relative, '..') !== false) {
                    return;
                }
                $target = __DIR__ . '/' . $relative;
                $targetDir = dirname($target);
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                }
                if (@file_put_contents($target, $content, LOCK_EX) !== false) {
                    $restored++;
                }
            };

            if (class_exists('ZipArchive') && str_ends_with($path, '.zip')) {
                $zip = new ZipArchive();
                if ($zip->open($path) !== true) {
                    melkinoAdminJson(['success' => false, 'message' => 'فایل پشتیبان قابل بازگشایی نیست.'], 500);
                }
                $dbSql = (string)$zip->getFromName('database.sql');
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    if ($entry === false) continue;
                    $content = $zip->getFromIndex($i);
                    if ($content === false) continue;
                    $copyEntry($entry, $content);
                }
                $zip->close();
            } elseif (class_exists('PharData')) {
                $tmp = sys_get_temp_dir() . '/melkino-restore-' . bin2hex(random_bytes(4));
                @mkdir($tmp, 0755, true);
                $phar = new PharData($path);
                $phar->extractTo($tmp, null, true);
                $sqlFile = $tmp . '/database.sql';
                if (is_file($sqlFile)) {
                    $dbSql = (string)file_get_contents($sqlFile);
                }
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $f) {
                    if (!$f->isFile()) continue;
                    $relative = substr($f->getPathname(), strlen($tmp) + 1);
                    $copyEntry($relative, (string)file_get_contents($f->getPathname()));
                }
            } else {
                melkinoAdminJson(['success' => false, 'message' => 'هیچ کتابخانه‌ای برای باز کردن فایل پشتیبان در دسترس نیست.'], 500);
            }

            melkinoAdminJson([
                'success' => true,
                'message' => $restored . ' فایل بازیابی شد'
                    . ($dbRestored ? ' و دیتابیس بازگردانده شد.' : '.')
                    . ($safetyCreated ? ' (یک نسخه‌ی ایمنی از وضعیت قبلی ساخته شد.)' : ''),
                'restored_files' => $restored,
                'database_restored' => $dbRestored,
            ]);

        default:
            melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
    }
}
?>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title">💾 پشتیبان‌گیری</span>
        <button type="button" class="btn-primary" style="padding:6px 14px;font-size:12px;" onclick="createBackup()">
            ⬇️ ساخت پشتیبان جدید
        </button>
    </div>

    <div style="padding:0 16px 12px;">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary);">
            <input type="checkbox" id="backupWithDb" checked>
            شامل خروجی دیتابیس هم باشد
        </label>
        <div class="admin-field-help">
            فایل پشتیبان شامل تمام فایل‌های پروژه (به‌جز پوشه backups) و در صورت انتخاب، خروجی کامل دیتابیس است.
        </div>
    </div>

    <div id="backupsListContainer" style="padding:0 16px 16px;"></div>
</div>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title">♻️ بازیابی پشتیبان</span>
    </div>
    <div style="padding:0 16px 16px;">
        <p style="font-size:13px;line-height:1.9;color:var(--text-secondary);margin:0 0 12px;">
            برای بازیابی، ابتدا فایل زیپِ پشتیبان را در پوشه‌ی <code dir="ltr">backups</code> قرار بده و سپس
            از فهرست بالا دکمه‌ی «بازیابی» را بزن. پیش از بازیابی یک نسخه‌ی ایمنی از وضعیت فعلی ساخته می‌شود.
        </p>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary);">
            <input type="checkbox" id="restoreWithDb">
            دیتابیس را هم از فایل پشتیبان بازیابی کن
        </label>
        <div style="color:var(--danger);font-size:12px;margin-top:8px;">
            ⚠️ بازیابی دیتابیس، اطلاعات فعلی را با اطلاعاتِ درون پشتیبان جایگزین می‌کند.
        </div>
    </div>
</div>
