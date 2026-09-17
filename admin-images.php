<?php
/**
|--------------------------------------------------------------------------
| مدیریت تصاویر آگهی‌ها (پنل ادمین)
|--------------------------------------------------------------------------
| actions:
|   list            فهرست تصاویر (با جستجو و فیلتر آگهی)
|   delete          حذف یک تصویر (فایل + ردیف دیتابیس)
|   delete_orphans  پاکسازی فایل‌های بدون استفاده در پوشه uploads
|   orphans         فقط شمارش/فهرست فایل‌های بدون استفاده
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/admin-guard.php';

/** مسیر فیزیکی یک تصویر روی سرور (با پشتیبانی از فرمت‌های مختلف ذخیره‌سازی) */
function melkinoImageAbsolutePath(array $row): string
{
    $raw = trim((string)($row['storage_path'] ?? ''));
    if ($raw === '') {
        $raw = trim((string)($row['filename'] ?? ''));
    }
    if ($raw === '') {
        return '';
    }

    $raw = str_replace('\\', '/', $raw);

    // اگر مسیر کامل روی سرور است
    if (strpos($raw, '/') === 0 && is_file($raw)) {
        return $raw;
    }

    // اگر آدرس اینترنتی است
    if (preg_match('#^https?://#i', $raw)) {
        return '';
    }

    $candidates = [
        __DIR__ . '/' . ltrim($raw, '/'),
        __DIR__ . '/uploads/' . ltrim($raw, '/'),
        __DIR__ . '/uploads/' . basename($raw),
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return '';
}

/** آدرس وبِ نمایش تصویر */
function melkinoImageWebPath(array $row): string
{
    $raw = trim((string)($row['filename'] ?? ''));
    if ($raw === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $raw)) {
        return $raw;
    }
    $raw = str_replace('\\', '/', $raw);
    if (strpos($raw, 'uploads/') === 0) {
        return $raw;
    }
    return 'uploads/' . ltrim($raw, '/');
}

$melkinoImageAction = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($melkinoImageAction !== '') {
    melkinoRequireAdminJson();

    global $pdo;

    switch ($melkinoImageAction) {

        case 'list':
            $q = trim((string)($_GET['q'] ?? ''));
            $adId = trim((string)($_GET['ad_id'] ?? ''));
            $onlyMissing = ($_GET['missing'] ?? '') === '1';

            $where = [];
            $params = [];

            if ($q !== '') {
                $where[] = '(i.filename LIKE ? OR a.title LIKE ? OR i.ad_id LIKE ?)';
                $like = '%' . $q . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            if ($adId !== '') {
                $where[] = 'i.ad_id = ?';
                $params[] = $adId;
            }

            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            try {
                $stmt = $pdo->prepare(
                    "SELECT i.id, i.ad_id, i.filename, i.storage_path, i.is_primary,
                            i.is_selected, i.publish_publicly, i.created_at,
                            a.title AS ad_title
                       FROM images i
                       LEFT JOIN ads a ON a.id = i.ad_id
                       $whereSql
                      ORDER BY i.id DESC
                      LIMIT 300"
                );
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                try {
                    $rows = $pdo->query(
                        "SELECT id, ad_id, filename, storage_path, is_primary, is_selected, publish_publicly
                           FROM images ORDER BY id DESC LIMIT 300"
                    )->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e2) {
                    melkinoAdminJson(['success' => false, 'message' => 'خواندن جدول تصاویر ممکن نشد.'], 500);
                }
            }

            $items = [];
            foreach ($rows as $row) {
                $abs = melkinoImageAbsolutePath($row);
                $exists = $abs !== '' && is_file($abs);

                if ($onlyMissing && $exists) {
                    continue;
                }

                $items[] = [
                    'id' => (int)$row['id'],
                    'ad_id' => (string)($row['ad_id'] ?? ''),
                    'ad_title' => (string)($row['ad_title'] ?? ''),
                    'filename' => basename((string)($row['filename'] ?? '')),
                    'url' => melkinoImageWebPath($row),
                    'exists' => $exists,
                    'size' => $exists ? filesize($abs) : 0,
                    'size_human' => $exists ? round(filesize($abs) / 1024, 1) . ' کیلوبایت' : '—',
                    'is_primary' => (int)($row['is_primary'] ?? 0),
                    'is_selected' => (int)($row['is_selected'] ?? 0),
                    'publish_publicly' => (int)($row['publish_publicly'] ?? 0),
                    'created_at' => (string)($row['created_at'] ?? ''),
                ];
            }

            melkinoAdminJson(['success' => true, 'images' => $items, 'count' => count($items)]);

        case 'delete':
            $data = melkinoAdminJsonBody();
            $id = (int)($data['id'] ?? 0);
            $removeFile = !isset($data['remove_file']) || !empty($data['remove_file']);

            if ($id <= 0) {
                melkinoAdminJson(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
            }

            $stmt = $pdo->prepare("SELECT * FROM images WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                melkinoAdminJson(['success' => false, 'message' => 'تصویری با این شناسه پیدا نشد.'], 404);
            }

            $fileDeleted = false;
            $fileError = '';

            if ($removeFile) {
                $abs = melkinoImageAbsolutePath($row);
                if ($abs !== '' && is_file($abs)) {
                    if (@unlink($abs)) {
                        $fileDeleted = true;
                    } else {
                        $fileError = 'فایل روی سرور حذف نشد (دسترسی پوشه را بررسی کن).';
                    }
                } else {
                    $fileDeleted = true; // فایل از قبل وجود نداشته
                }
            }

            $pdo->prepare("DELETE FROM images WHERE id = ?")->execute([$id]);

            // اگر تصویر حذف‌شده اصلی بود، تصویر بعدی همان آگهی را اصلی کن
            if (!empty($row['is_primary'])) {
                $next = $pdo->prepare("SELECT id FROM images WHERE ad_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
                $next->execute([(string)$row['ad_id']]);
                $nextId = $next->fetchColumn();
                if ($nextId) {
                    $pdo->prepare("UPDATE images SET is_primary = 1 WHERE id = ?")->execute([$nextId]);
                }
            }

            melkinoAdminJson([
                'success' => true,
                'file_deleted' => $fileDeleted,
                'message' => $fileDeleted
                    ? 'تصویر از دیتابیس و سرور حذف شد.'
                    : ('ردیف دیتابیس حذف شد، اما ' . ($fileError ?: 'فایل روی سرور پیدا نشد.')),
            ]);

        case 'orphans':
            // فایل‌هایی که در پوشه uploads هستند ولی هیچ ردیفی در دیتابیس ندارند
            $used = [];
            try {
                foreach ($pdo->query("SELECT filename, storage_path FROM images")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    foreach (['filename', 'storage_path'] as $key) {
                        $v = trim((string)($r[$key] ?? ''));
                        if ($v !== '') {
                            $used[basename(str_replace('\\', '/', $v))] = true;
                        }
                    }
                }
            } catch (Throwable $e) {
                $used = [];
            }

            $extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $orphans = [];
            $totalSize = 0;

            foreach (glob(__DIR__ . '/uploads/*') ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $name = basename($file);
                // فایل‌های سیستمی را حذف نکن
                if (strpos($name, 'onboarding-logo') === 0) {
                    continue;
                }
                if (!in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $extensions, true)) {
                    continue;
                }
                if (isset($used[$name])) {
                    continue;
                }
                $orphans[] = ['name' => $name, 'size' => filesize($file)];
                $totalSize += filesize($file);
            }

            melkinoAdminJson([
                'success' => true,
                'orphans' => $orphans,
                'count' => count($orphans),
                'total_size' => $totalSize,
                'total_size_human' => round($totalSize / 1024 / 1024, 2) . ' مگابایت',
            ]);

        case 'delete_orphans':
            $data = melkinoAdminJsonBody();
            $names = $data['files'] ?? null;

            if (!is_array($names) || !$names) {
                melkinoAdminJson(['success' => false, 'message' => 'فایلی انتخاب نشده است.'], 422);
            }

            $deleted = 0;
            $failed = 0;
            $freed = 0;

            foreach ($names as $name) {
                $safe = basename((string)$name);
                // فقط فایل‌های داخل پوشه uploads و فقط تصاویر
                if ($safe === '' || $safe !== (string)$name) {
                    $failed++;
                    continue;
                }
                if (strpos($safe, 'onboarding-logo') === 0) {
                    $failed++;
                    continue;
                }
                if (!in_array(strtolower(pathinfo($safe, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    $failed++;
                    continue;
                }

                $path = __DIR__ . '/uploads/' . $safe;
                if (!is_file($path)) {
                    $failed++;
                    continue;
                }

                $size = filesize($path);
                if (@unlink($path)) {
                    $deleted++;
                    $freed += $size;
                } else {
                    $failed++;
                }
            }

            melkinoAdminJson([
                'success' => true,
                'deleted' => $deleted,
                'failed' => $failed,
                'freed_human' => round($freed / 1024 / 1024, 2) . ' مگابایت',
                'message' => $deleted . ' فایل حذف شد (' . round($freed / 1024 / 1024, 2) . ' مگابایت آزاد شد).',
            ]);

        default:
            melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
    }
}
?>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title">🖼️ مدیریت تصاویر آگهی‌ها</span>
        <button type="button" class="btn-secondary" style="padding:6px 14px;font-size:12px;" onclick="loadAdminImages()">
            ↻ بروزرسانی
        </button>
    </div>

    <div style="padding:0 16px 12px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div style="flex:1;min-width:200px;">
            <label class="admin-field-label">جستجو (نام فایل، عنوان آگهی یا شناسه آگهی)</label>
            <input type="text" id="imagesSearch" class="admin-input" placeholder="مثال: AD-2024 یا آپارتمان" oninput="loadAdminImages()">
        </div>
        <div>
            <label class="admin-field-label">فقط تصاویرِ فایل‌ندار</label>
            <select id="imagesMissingOnly" class="admin-input" onchange="loadAdminImages()">
                <option value="0">همه</option>
                <option value="1">فقط ردیف‌های بدون فایل</option>
            </select>
        </div>
    </div>

    <div style="padding:0 16px 8px;color:var(--text-secondary);font-size:12px;line-height:1.9;">
        حذف هر تصویر، هم ردیف آن را از دیتابیس پاک می‌کند و هم فایل اصلی را از پوشه‌ی <code dir="ltr">uploads</code> روی سرور.
    </div>

    <div id="adminImagesContainer" style="padding:0 16px 16px;"></div>
</div>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title">🧹 پاکسازی فایل‌های بدون استفاده</span>
        <button type="button" class="btn-secondary" style="padding:6px 14px;font-size:12px;" onclick="scanOrphanImages()">
            🔍 اسکن پوشه uploads
        </button>
    </div>

    <div style="padding:0 16px 8px;color:var(--text-secondary);font-size:12px;line-height:1.9;">
        فایل‌هایی که در پوشه‌ی آپلود مانده‌اند اما به هیچ آگهی‌ای وصل نیستند (مثلاً به‌خاطر حذف آگهی) را پیدا و حذف می‌کند.
        فایل لوگو و تصاویر تبلیغات هرگز حذف نمی‌شوند.
    </div>

    <div id="orphanImagesContainer" style="padding:0 16px 16px;"></div>
</div>
