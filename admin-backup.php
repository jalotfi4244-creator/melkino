<?php
/**
|--------------------------------------------------------------------------
| پشتیبان‌گیری و بازیابی (پنل ادمین)
|--------------------------------------------------------------------------
| actions:
|   create     ساخت فایل پشتیبان (فایل‌ها + خروجی دیتابیس، هر یک اختیاری)
|   list       فهرست فایل‌های پشتیبان (همراه با جزئیات meta)
|   upload     آپلود یک فایل پشتیبان
|   download   دریافت یک فایل پشتیبان
|   delete     حذف یک فایل پشتیبان
|   restore    بازیابی فایل‌ها و/یا دیتابیس + ساخت نسخه‌ی ایمنی خودکار
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
    melkinoBackupProtectDir($dir);
    return $dir;
}

/**
 * پوشه‌ی بکاپ‌ها حاوی خروجی کامل دیتابیس است و نباید از وب در دسترس باشد.
 * با .htaccess دسترسی مستقیم بسته می‌شود (دانلود فقط از طریق همین فایل و
 * برای ادمین لاگین‌کرده ممکن است) و index.html جلوی فهرست‌شدن را می‌گیرد.
 */
function melkinoBackupProtectDir(string $dir): void
{
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    $ix = $dir . '/index.html';
    if (!is_file($ix)) {
        @file_put_contents($ix, '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body>Forbidden</body></html>');
    }
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
function melkinoCreateArchive(string $targetPath, string $databaseSql = '', bool $withFiles = true, array $tables = []): array
{
    $root = __DIR__;
    $excludes = melkinoBackupExcludes();
    $fileCount = 0;

    $addFiles = function ($addFile, $addDir) use ($root, $excludes, &$fileCount) {
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
                $addDir($relative);
            } elseif ($info->isFile()) {
                $addFile($path, $relative);
                $fileCount++;
            }
        }
    };

    $buildMeta = function () use ($withFiles, $databaseSql, $tables, &$fileCount) {
        return json_encode([
            'version'    => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'with_files' => $withFiles,
            'with_db'    => $databaseSql !== '',
            'file_count' => $fileCount,
            'tables'     => array_values($tables),
            'generator'  => 'melkino-backup',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    };

    if (str_ends_with($targetPath, '.zip') && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($targetPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('ساخت فایل زیپ ممکن نشد.');
        }

        if ($withFiles) {
            $addFiles(
                function ($path, $relative) use ($zip) { $zip->addFile($path, $relative); },
                function ($relative) use ($zip) { $zip->addEmptyDir($relative); }
            );
        }

        $zip->addFromString('meta.json', (string)$buildMeta());
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

    if ($withFiles) {
        $addFiles(
            function ($path, $relative) use ($tar) { $tar->addFile($path, $relative); },
            function ($relative) {}
        );
    }

    $tmpMeta = sys_get_temp_dir() . '/melkino-meta-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($tmpMeta, (string)$buildMeta());
    $tar->addFile($tmpMeta, 'meta.json');
    @unlink($tmpMeta);

    if ($databaseSql !== '') {
        $tmpSql = sys_get_temp_dir() . '/melkino-database-' . bin2hex(random_bytes(4)) . '.sql';
        file_put_contents($tmpSql, $databaseSql);
        $tar->addFile($tmpSql, 'database.sql');
        @unlink($tmpSql);
    }

    return [$fileCount, true, $tarPath];
}

/**
 * خواندن meta.json از داخل یک فایل پشتیبان (برای نمایش جزئیات در فهرست).
 * برای بکاپ‌های قدیمی که متا ندارند، null برمی‌گردد.
 */
function melkinoReadBackupMeta(string $path): ?array
{
    try {
        if (str_ends_with($path, '.zip') && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                return null;
            }
            $raw = $zip->getFromName('meta.json');
            $zip->close();
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $meta = json_decode($raw, true);
            return is_array($meta) ? $meta : null;
        }

        if (class_exists('PharData')) {
            $phar = new PharData($path);
            if (!isset($phar['meta.json'])) {
                return null;
            }
            $meta = json_decode((string)file_get_contents($phar['meta.json']->getPathname()), true);
            return is_array($meta) ? $meta : null;
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
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

/**
 * تفکیک یک اسکریپت SQL به دستورهای جداگانه.
 * نقطه‌ویرگول‌های داخل رشته‌ها (تک/دوکویتیشن)، بک‌تیک‌ها و کامنت‌ها
 * جدا کننده حساب نمی‌شوند.
 */
function melkinoSplitSql(string $sql): array
{
    $stmts = [];
    $buf = '';
    $len = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $n = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($c === "\n") {
                $inLineComment = false;
                $buf .= $c;
            }
            continue;
        }
        if ($inBlockComment) {
            if ($c === '*' && $n === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }
        if ($inSingle) {
            $buf .= $c;
            if ($c === '\\' && $n !== '') {
                $buf .= $n;
                $i++;
            } elseif ($c === "'") {
                $inSingle = false;
            }
            continue;
        }
        if ($inDouble) {
            $buf .= $c;
            if ($c === '\\' && $n !== '') {
                $buf .= $n;
                $i++;
            } elseif ($c === '"') {
                $inDouble = false;
            }
            continue;
        }
        if ($inBacktick) {
            $buf .= $c;
            if ($c === '`') {
                $inBacktick = false;
            }
            continue;
        }
        if ($c === '-' && $n === '-' && ($i + 2 >= $len || strpos(" \t\n\r", $sql[$i + 2]) !== false)) {
            $inLineComment = true;
            $i++;
            continue;
        }
        if ($c === '#') {
            $inLineComment = true;
            continue;
        }
        if ($c === '/' && $n === '*') {
            $inBlockComment = true;
            $i++;
            continue;
        }
        if ($c === "'") {
            $inSingle = true;
            $buf .= $c;
            continue;
        }
        if ($c === '"') {
            $inDouble = true;
            $buf .= $c;
            continue;
        }
        if ($c === '`') {
            $inBacktick = true;
            $buf .= $c;
            continue;
        }
        if ($c === ';') {
            $t = trim($buf);
            if ($t !== '') {
                $stmts[] = $t;
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }

    $t = trim($buf);
    if ($t !== '') {
        $stmts[] = $t;
    }
    return $stmts;
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
                $base = basename($file);
                $meta = melkinoReadBackupMeta($file);
                $items[] = [
                    'name' => $base,
                    'size' => filesize($file),
                    'size_human' => round(filesize($file) / 1024 / 1024, 2) . ' مگابایت',
                    'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                    'is_safety' => strpos($base, 'safety') !== false,
                    'meta' => $meta ? [
                        'with_files' => !empty($meta['with_files']),
                        'with_db'    => !empty($meta['with_db']),
                        'file_count' => (int)($meta['file_count'] ?? 0),
                        'tables'     => is_array($meta['tables'] ?? null) ? count($meta['tables']) : 0,
                    ] : null,
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
            $includeFiles = ($_GET['with_files'] ?? $_POST['with_files'] ?? '1') !== '0';
            if (!$includeDb && !$includeFiles) {
                melkinoAdminJson(['success' => false, 'message' => 'حداقل یکی از «فایل‌ها» یا «دیتابیس» باید انتخاب شود.'], 422);
            }

            // بکاپ ممکن است کمی طول بکشد؛ اگر هاست اجازه بدهد محدودیت زمانی برداشته می‌شود
            try {
                @set_time_limit(0);
                @ignore_user_abort(true);
            } catch (Throwable $e) {
            }

            $fileName = 'melkino-backup-' . date('Ymd-His') . '.zip';
            $target = $dir . '/' . $fileName;

            $databaseSql = '';
            $tables = [];
            if ($includeDb && ($pdo instanceof PDO)) {
                try {
                    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                    $databaseSql = melkinoDumpDatabase($pdo);
                } catch (Throwable $e) {
                    $databaseSql = '';
                    $tables = [];
                }
            }

            try {
                $result = melkinoCreateArchive($target, $databaseSql, $includeFiles, $tables);
            } catch (Throwable $e) {
                melkinoAdminJson(['success' => false, 'message' => $e->getMessage()], 500);
            }

            $fileCount = (int)($result[0] ?? 0);
            $archivePath = (string)($result[2] ?? $target);
            $dbDone = $databaseSql !== '';

            if (!is_file($archivePath)) {
                melkinoAdminJson(['success' => false, 'message' => 'ساخت فایل پشتیبان ناموفق بود.'], 500);
            }

            $parts = [];
            if ($includeFiles) {
                $parts[] = $fileCount . ' فایل';
            }
            if ($dbDone) {
                $parts[] = 'دیتابیس (' . count($tables) . ' جدول)';
            } elseif ($includeDb) {
                $parts[] = 'بدون دیتابیس (خطا در خروجی)';
            }

            melkinoAdminJson([
                'success' => true,
                'message' => 'پشتیبان ساخته شد (' . implode(' + ', $parts) . ').',
                'file' => basename($archivePath),
                'size' => filesize($archivePath),
            ]);

        case 'upload':
            if (!is_dir($dir) || !is_writable($dir)) {
                melkinoAdminJson(['success' => false, 'message' => 'پوشه backups وجود ندارد یا قابل نوشتن نیست.'], 500);
            }
            $file = $_FILES['backup_file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $err = is_array($file) ? (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
                $msg = 'آپلود فایل ناموفق بود.';
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                    $msg = 'حجم فایل از سقف مجاز هاست بیشتر است.';
                } elseif ($err === UPLOAD_ERR_NO_FILE) {
                    $msg = 'فایلی انتخاب نشده است.';
                }
                melkinoAdminJson(['success' => false, 'message' => $msg], 422);
            }

            $origName = (string)($file['name'] ?? '');
            $lower = strtolower($origName);
            $ext = '';
            if (str_ends_with($lower, '.zip')) {
                $ext = '.zip';
            } elseif (str_ends_with($lower, '.tar.gz')) {
                $ext = '.tar.gz';
            } elseif (str_ends_with($lower, '.tar')) {
                $ext = '.tar';
            } else {
                melkinoAdminJson(['success' => false, 'message' => 'فقط فایل‌های zip و tar مجاز هستند.'], 422);
            }

            $safe = melkinoSafeBackupName($origName);
            if ($safe === '' || !str_ends_with(strtolower($safe), $ext)) {
                // نام فایلِ آپلودشده استاندارد نیست؛ با یک نام امن ذخیره می‌شود
                $safe = 'melkino-backup-uploaded-' . date('Ymd-His') . $ext;
            }
            $dest = $dir . '/' . $safe;
            if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
                melkinoAdminJson(['success' => false, 'message' => 'ذخیره‌ی فایل آپلودشده ممکن نشد.'], 500);
            }

            melkinoAdminJson([
                'success' => true,
                'message' => 'فایل آپلود شد و آماده‌ی بازیابی است.',
                'file' => $safe,
                'size' => filesize($dest),
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

            try {
                @set_time_limit(0);
                @ignore_user_abort(true);
            } catch (Throwable $e) {
            }

            $data = melkinoAdminJsonBody();
            $name = melkinoSafeBackupName($data['file'] ?? '');
            $path = $dir . '/' . $name;
            $restoreDb = !empty($data['restore_db']);
            $restoreFiles = !isset($data['restore_files']) || !empty($data['restore_files']);

            if ($name === '' || !is_file($path)) {
                melkinoAdminJson(['success' => false, 'message' => 'فایل پشتیبان پیدا نشد.'], 404);
            }
            if (!$restoreDb && !$restoreFiles) {
                melkinoAdminJson(['success' => false, 'message' => 'حداقل یکی از «فایل‌ها» یا «دیتابیس» باید انتخاب شود.'], 422);
            }

            // مرحله‌ی ۱: خواندن database.sql از داخل آرشیو (پیش از هر تغییری)
            $dbSql = '';
            $useZip = class_exists('ZipArchive') && str_ends_with($path, '.zip');
            if ($useZip) {
                $zip = new ZipArchive();
                if ($zip->open($path) !== true) {
                    melkinoAdminJson(['success' => false, 'message' => 'فایل پشتیبان قابل بازگشایی نیست.'], 500);
                }
                $dbSql = (string)$zip->getFromName('database.sql');
                $zip->close();
            } elseif (class_exists('PharData')) {
                try {
                    $phar = new PharData($path);
                    if (isset($phar['database.sql'])) {
                        $dbSql = (string)file_get_contents($phar['database.sql']->getPathname());
                    }
                } catch (Throwable $e) {
                    melkinoAdminJson(['success' => false, 'message' => 'فایل پشتیبان قابل بازگشایی نیست.'], 500);
                }
            } else {
                melkinoAdminJson(['success' => false, 'message' => 'هیچ کتابخانه‌ای برای باز کردن فایل پشتیبان در دسترس نیست.'], 500);
            }

            if ($restoreDb && $dbSql === '') {
                melkinoAdminJson(['success' => false, 'message' => 'دیتابیس داخل این فایل پشتیبان پیدا نشد؛ بازیابی انجام نشد.'], 422);
            }
            if ($restoreDb && !($pdo instanceof PDO)) {
                melkinoAdminJson(['success' => false, 'message' => 'اتصال دیتابیس در دسترس نیست؛ بازیابی انجام نشد.'], 500);
            }

            // مرحله‌ی ۲: ساخت نسخه‌ی ایمنی از وضعیت فعلی (فایل‌ها + دیتابیس)
            $safetyCreated = false;
            $safetyName = '';
            try {
                $safetySql = '';
                $safetyTables = [];
                if ($pdo instanceof PDO) {
                    try {
                        $safetyTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                        $safetySql = melkinoDumpDatabase($pdo);
                    } catch (Throwable $e) {
                        $safetySql = '';
                    }
                }
                $safetyTarget = $dir . '/melkino-backup-safety-' . date('Ymd-His') . '.zip';
                $safetyResult = melkinoCreateArchive($safetyTarget, $safetySql, true, $safetyTables);
                $safetyPath = (string)($safetyResult[2] ?? $safetyTarget);
                if (is_file($safetyPath)) {
                    $safetyCreated = true;
                    $safetyName = basename($safetyPath);
                }
            } catch (Throwable $e) {
                $safetyCreated = false;
            }
            if (!$safetyCreated) {
                melkinoAdminJson(['success' => false, 'message' => 'ساخت نسخه‌ی ایمنی ناموفق بود؛ برای امنیت، بازیابی انجام نشد.'], 500);
            }

            // مرحله‌ی ۳: بازیابی فایل‌ها
            $restored = 0;
            $excludes = melkinoBackupExcludes();

            if ($restoreFiles) {
                $copyEntry = function (string $relative, string $content) use (&$restored, $excludes) {
                    if ($relative === 'database.sql' || $relative === 'meta.json') {
                        return;
                    }
                    if (strpos($relative, '..') !== false) {
                        return;
                    }
                    $parts = explode('/', str_replace('\\', '/', $relative));
                    if (in_array($parts[0], $excludes, true)) {
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

                if ($useZip) {
                    $zip = new ZipArchive();
                    $zip->open($path);
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $entry = $zip->getNameIndex($i);
                        if ($entry === false || $entry === null) {
                            continue;
                        }
                        $content = $zip->getFromIndex($i);
                        if ($content === false) {
                            continue;
                        }
                        $copyEntry($entry, $content);
                    }
                    $zip->close();
                } else {
                    $tmp = sys_get_temp_dir() . '/melkino-restore-' . bin2hex(random_bytes(4));
                    @mkdir($tmp, 0755, true);
                    $phar = new PharData($path);
                    $phar->extractTo($tmp, null, true);
                    $it = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS)
                    );
                    foreach ($it as $f) {
                        if (!$f->isFile()) {
                            continue;
                        }
                        $relative = substr($f->getPathname(), strlen($tmp) + 1);
                        $copyEntry($relative, (string)file_get_contents($f->getPathname()));
                    }
                }
            }

            // مرحله‌ی ۴: بازیابی دیتابیس (اجرای واقعی دستورهای SQL)
            $dbRestored = false;
            $dbStatements = 0;
            $dbErrors = [];
            if ($restoreDb && $dbSql !== '' && ($pdo instanceof PDO)) {
                $shorten = function (string $m): string {
                    $m = trim((string)preg_replace('/\s+/', ' ', $m));
                    return function_exists('mb_substr') ? mb_substr($m, 0, 160) : substr($m, 0, 160);
                };
                try {
                    $stmts = melkinoSplitSql($dbSql);
                    foreach ($stmts as $stmt) {
                        try {
                            $pdo->exec($stmt);
                            $dbStatements++;
                        } catch (Throwable $e) {
                            if (count($dbErrors) < 3) {
                                $dbErrors[] = $shorten($e->getMessage());
                            }
                        }
                    }
                    $dbRestored = $dbStatements > 0 && count($dbErrors) === 0;
                } catch (Throwable $e) {
                    $dbErrors[] = $shorten($e->getMessage());
                }
            }

            $msgParts = [];
            if ($restoreFiles) {
                $msgParts[] = $restored . ' فایل بازیابی شد';
            }
            if ($restoreDb) {
                if ($dbRestored) {
                    $msgParts[] = 'دیتابیس بازگردانده شد (' . $dbStatements . ' دستور)';
                } else {
                    $msgParts[] = 'بازیابی دیتابیس ناقص ماند (' . $dbStatements . ' دستور موفق'
                        . (count($dbErrors) ? '، خطا: ' . implode(' / ', $dbErrors) : '') . ')';
                }
            }

            melkinoAdminJson([
                'success' => true,
                'message' => implode('؛ ', $msgParts) . '. یک نسخه‌ی ایمنی از وضعیت قبلی ساخته شد (' . $safetyName . ').',
                'restored_files' => $restored,
                'database_restored' => $dbRestored,
                'db_statements' => $dbStatements,
                'safety_file' => $safetyName,
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
            از فهرست بالا دکمه‌ی «بازیابی» را بزن. پیش از بازیابی، یک نسخه‌ی ایمنی 🛡️
            از وضعیت فعلی (فایل‌ها + دیتابیس) ساخته می‌شود تا اگر چیزی اشتباه شد برگردی.
        </p>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary);">
            <input type="checkbox" id="restoreWithFiles" checked>
            فایل‌ها را از فایل پشتیبان بازیابی کن
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary);margin-top:6px;">
            <input type="checkbox" id="restoreWithDb">
            دیتابیس را هم از فایل پشتیبان بازیابی کن
        </label>
        <div style="color:var(--danger);font-size:12px;margin-top:8px;">
            ⚠️ بازیابی دیتابیس، اطلاعات فعلی را با اطلاعاتِ درون پشتیبان جایگزین می‌کند.
        </div>
    </div>
</div>
�زیابی، ابتدا فایل زیپِ پشتیبان را در پوشه‌ی <code dir="ltr">backups</code> قرار بده و سپس
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
