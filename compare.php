<?php
/*
|--------------------------------------------------------------------------
| مقایسهٔ ملک‌ها (API)
|--------------------------------------------------------------------------
| کاربر از روی کارت‌ها یا صفحهٔ جزئیات، ملک را به مقایسه اضافه می‌کند؛
| در ۳ دستهٔ قابل‌تغییرنام (هرکدام حداکثر ۵ ملک) و سپس در صفحهٔ مستقلِ
| مقایسه (compare-page.php) کنار هم دیده و امتیازدهی می‌شوند.
|
| actions:
|   add            افزودن ملک (group=1..3 یا auto)
|   remove         حذف ملک از مقایسه
|   move           جابه‌جایی ملک بین دسته‌ها
|   list           فهرست دسته‌ها + ملک‌ها
|   rename_group   تغییر نام دسته
|   clear_group    خالی کردن یک دسته
|   score          امتیازدهی و مقایسهٔ کنارهم یک دسته (از ۱۰۰)
|   count          تعداد کل ملک‌های مقایسه
|
| نکته: همهٔ منطق در compare-lib.php است تا این فایل و صفحهٔ مقایسه
| رفتار یکسانی داشته باشند.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/compare-lib.php';

global $pdo;

if (!isset($_GET['action'])) {
    melkinoJsonResponse(['success' => false, 'message' => 'عملیات نامعتبر است.'], 400);
}

if (!$pdo instanceof PDO) {
    melkinoJsonResponse(['success' => false, 'message' => 'اتصال دیتابیس برقرار نیست.'], 500);
}

try {
    melkinoEnsureCompareTables($pdo);
} catch (Throwable $e) {
    melkinoJsonResponse(['success' => false, 'message' => 'خطا در آماده‌سازی جدول‌های مقایسه.'], 500);
}

$identity = melkinoCurrentIdentity();
melkinoCompareMergeGuest($pdo, $identity);

[$ownerWhere, $ownerParams, $ownerUserId, $ownerTelegramId, $guestToken] = melkinoCompareOwner($identity, 'ci');
[$ownerWherePlain, $ownerParamsPlain] = melkinoCompareOwner($identity);

$action = trim((string)$_GET['action']);

/* ---------------------------------------------------------------
   عملیات مشترک
   --------------------------------------------------------------- */

$respondWithGroups = function (array $extra = []) use ($pdo, $ownerWhere, $ownerParams, $ownerWherePlain, $ownerParamsPlain): void {
    $groups = melkinoCompareGroups($pdo, $ownerWhere, $ownerParams, $ownerWherePlain, $ownerParamsPlain);
    $total = 0;
    foreach ($groups as $g) {
        $total += (int)$g['count'];
    }
    melkinoJsonResponse(array_merge([
        'success' => true,
        'groups'  => $groups,
        'total'   => $total,
    ], $extra));
};

/* ---------------------------------------------------------------
   count — تعداد کل
   --------------------------------------------------------------- */
if ($action === 'count') {
    melkinoJsonResponse([
        'success' => true,
        'count'   => melkinoCompareCount($pdo, $ownerWhere, $ownerParams),
    ]);
}

/* ---------------------------------------------------------------
   list — فهرست کامل
   --------------------------------------------------------------- */
if ($action === 'list') {
    $respondWithGroups();
}

/* ---------------------------------------------------------------
   add — افزودن ملک
   --------------------------------------------------------------- */
if ($action === 'add') {
    $adId = trim((string)($_POST['ad_id'] ?? $_GET['ad_id'] ?? ''));
    $groupRaw = trim((string)($_POST['group'] ?? $_GET['group'] ?? 'auto'));

    if ($adId === '') {
        melkinoJsonResponse(['success' => false, 'message' => 'شناسه آگهی الزامی است.'], 422);
    }

    $stmt = $pdo->prepare("SELECT id, title FROM ads WHERE id = ? AND status = 'published' LIMIT 1");
    $stmt->execute([$adId]);
    $ad = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ad) {
        melkinoJsonResponse(['success' => false, 'message' => 'آگهی پیدا نشد یا هنوز منتشر نشده است.'], 404);
    }

    // تکراری؟
    $stmt = $pdo->prepare("SELECT group_no FROM compare_items ci WHERE $ownerWhere AND ci.ad_id = ? LIMIT 1");
    $p = $ownerParams;
    $p[] = $adId;
    $stmt->execute($p);
    $existingGroup = $stmt->fetchColumn();
    if ($existingGroup !== false) {
        melkinoJsonResponse([
            'success'    => true,
            'already'    => true,
            'group'      => (int)$existingGroup,
            'group_name' => melkinoCompareGroupName($pdo, $ownerWherePlain, $ownerParamsPlain, (int)$existingGroup),
            'message'    => 'این ملک قبلاً به مقایسه اضافه شده.',
        ]);
    }

    $max = melkinoCompareMaxPerGroup();

    if ($groupRaw === 'auto' || $groupRaw === '' || $groupRaw === '0') {
        $groupNo = 0;
        for ($g = 1; $g <= 3; $g++) {
            if (melkinoCompareCountInGroup($pdo, $ownerWhere, $ownerParams, $g) < $max) {
                $groupNo = $g;
                break;
            }
        }
        if ($groupNo === 0) {
            melkinoJsonResponse(['success' => false, 'message' => 'هر ۳ دسته پر است (حداکثر ' . $max . ' ملک در هر دسته).'], 422);
        }
    } else {
        $groupNo = (int)$groupRaw;
        if ($groupNo < 1 || $groupNo > 3) {
            melkinoJsonResponse(['success' => false, 'message' => 'دسته نامعتبر است.'], 422);
        }
        if (melkinoCompareCountInGroup($pdo, $ownerWhere, $ownerParams, $groupNo) >= $max) {
            melkinoJsonResponse(['success' => false, 'message' => 'این دسته پر است (حداکثر ' . $max . ' ملک).'], 422);
        }
    }

    $stmt = $pdo->prepare('INSERT INTO compare_items (user_id, telegram_id, guest_token, ad_id, group_no) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $ownerUserId,
        $ownerTelegramId !== '' ? $ownerTelegramId : null,
        $guestToken !== '' ? $guestToken : null,
        $adId,
        $groupNo,
    ]);

    melkinoJsonResponse([
        'success'    => true,
        'group'      => $groupNo,
        'group_name' => melkinoCompareGroupName($pdo, $ownerWherePlain, $ownerParamsPlain, $groupNo),
        'count'      => melkinoCompareCount($pdo, $ownerWhere, $ownerParams),
        'message'    => 'به مقایسه اضافه شد.',
    ]);
}

/* ---------------------------------------------------------------
   remove — حذف ملک
   --------------------------------------------------------------- */
if ($action === 'remove') {
    $adId = trim((string)($_POST['ad_id'] ?? $_GET['ad_id'] ?? ''));
    if ($adId === '') {
        melkinoJsonResponse(['success' => false, 'message' => 'شناسه آگهی الزامی است.'], 422);
    }
    $stmt = $pdo->prepare("DELETE FROM compare_items WHERE $ownerWherePlain AND ad_id = ?");
    $p = $ownerParamsPlain;
    $p[] = $adId;
    $stmt->execute($p);
    melkinoJsonResponse([
        'success' => true,
        'removed' => $stmt->rowCount() > 0,
        'count'   => melkinoCompareCount($pdo, $ownerWhere, $ownerParams),
    ]);
}

/* ---------------------------------------------------------------
   move — جابه‌جایی بین دسته‌ها
   --------------------------------------------------------------- */
if ($action === 'move') {
    $adId = trim((string)($_POST['ad_id'] ?? $_GET['ad_id'] ?? ''));
    $groupNo = (int)($_POST['group'] ?? $_GET['group'] ?? 0);
    if ($adId === '' || $groupNo < 1 || $groupNo > 3) {
        melkinoJsonResponse(['success' => false, 'message' => 'اطلاعات نامعتبر است.'], 422);
    }

    $stmt = $pdo->prepare("SELECT id, group_no FROM compare_items WHERE $ownerWherePlain AND ad_id = ? LIMIT 1");
    $p = $ownerParamsPlain;
    $p[] = $adId;
    $stmt->execute($p);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        melkinoJsonResponse(['success' => false, 'message' => 'این ملک در مقایسه نیست.'], 404);
    }

    if ((int)$row['group_no'] !== $groupNo) {
        $max = melkinoCompareMaxPerGroup();
        if (melkinoCompareCountInGroup($pdo, $ownerWhere, $ownerParams, $groupNo) >= $max) {
            melkinoJsonResponse(['success' => false, 'message' => 'دستهٔ مقصد پر است (حداکثر ' . $max . ' ملک).'], 422);
        }
        $pdo->prepare('UPDATE compare_items SET group_no = ? WHERE id = ?')->execute([$groupNo, (int)$row['id']]);
    }

    melkinoJsonResponse(['success' => true, 'group' => $groupNo]);
}

/* ---------------------------------------------------------------
   rename_group — تغییر نام دسته
   --------------------------------------------------------------- */
if ($action === 'rename_group') {
    $groupNo = (int)($_POST['group'] ?? $_GET['group'] ?? 0);
    $name = trim((string)($_POST['name'] ?? $_GET['name'] ?? ''));
    if ($groupNo < 1 || $groupNo > 3) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسته نامعتبر است.'], 422);
    }
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 40, 'UTF-8') : substr($name, 0, 40);

    $stmt = $pdo->prepare("SELECT id FROM compare_groups WHERE $ownerWherePlain AND group_no = ? LIMIT 1");
    $p = $ownerParamsPlain;
    $p[] = $groupNo;
    $stmt->execute($p);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $pdo->prepare('UPDATE compare_groups SET name = ? WHERE id = ?')->execute([$name, (int)$existing]);
    } else {
        $pdo->prepare('INSERT INTO compare_groups (user_id, telegram_id, guest_token, group_no, name) VALUES (?, ?, ?, ?, ?)')
            ->execute([
                $ownerUserId,
                $ownerTelegramId !== '' ? $ownerTelegramId : null,
                $guestToken !== '' ? $guestToken : null,
                $groupNo,
                $name,
            ]);
    }

    melkinoJsonResponse(['success' => true, 'name' => $name]);
}

/* ---------------------------------------------------------------
   clear_group — خالی کردن دسته
   --------------------------------------------------------------- */
if ($action === 'clear_group') {
    $groupNo = (int)($_POST['group'] ?? $_GET['group'] ?? 0);
    if ($groupNo < 1 || $groupNo > 3) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسته نامعتبر است.'], 422);
    }
    $stmt = $pdo->prepare("DELETE FROM compare_items WHERE $ownerWherePlain AND group_no = ?");
    $p = $ownerParamsPlain;
    $p[] = $groupNo;
    $stmt->execute($p);
    melkinoJsonResponse([
        'success' => true,
        'deleted' => $stmt->rowCount(),
        'count'   => melkinoCompareCount($pdo, $ownerWhere, $ownerParams),
    ]);
}

/* ---------------------------------------------------------------
   score — امتیازدهی یک دسته
   --------------------------------------------------------------- */
if ($action === 'score') {
    $groupNo = (int)($_POST['group'] ?? $_GET['group'] ?? 0);
    if ($groupNo < 1 || $groupNo > 3) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسته نامعتبر است.'], 422);
    }

    $data = melkinoCompareScoreData($pdo, $ownerWhere, $ownerParams, $groupNo);

    if (count($data['ads']) < 2) {
        melkinoJsonResponse(['success' => false, 'message' => 'برای مقایسه حداقل ۲ ملک در این دسته لازم است.'], 422);
    }

    melkinoJsonResponse([
        'success'      => true,
        'group'        => $groupNo,
        'group_name'   => melkinoCompareGroupName($pdo, $ownerWherePlain, $ownerParamsPlain, $groupNo),
        'ads'          => $data['ads'],
        'scores'       => $data['scores'],
        'winner'       => $data['winner'],
        'highlights'   => $data['highlights'],
        'mixed_types'  => $data['mixed_types'],
    ]);
}

melkinoJsonResponse(['success' => false, 'message' => 'عملیات نامعتبر است.'], 400);
