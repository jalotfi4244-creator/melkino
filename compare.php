<?php
/*
|--------------------------------------------------------------------------
| مقایسه ملک‌ها (API)
|--------------------------------------------------------------------------
| کاربر از روی کارت‌ها یا صفحه جزئیات، ملک را به مقایسه اضافه می‌کند؛
| در پروفایل ۳ دسته (قابل تغییرنام) وجود دارد که هر دسته حداکثر ۵ ملک
| می‌گیرد و کاربر مشخص می‌کند کدام ملک‌ها با هم مقایسه شوند.
|
| actions:
|   add            افزودن ملک (group=1..3 یا auto)
|   remove         حذف ملک از مقایسه
|   move           جابه‌جایی ملک بین دسته‌ها
|   list           فهرست دسته‌ها + ملک‌ها
|   rename_group   تغییر نام دسته
|   clear_group    خالی کردن یک دسته
|   score          امتیازدهی و مقایسه کنارهم یک دسته (از ۱۰۰)
|   count          تعداد کل ملک‌های مقایسه
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db_helpers.php';

global $pdo;

if (!function_exists('melkinoEnsureCompareTables')) {
    function melkinoEnsureCompareTables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS compare_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                telegram_id VARCHAR(64) NULL,
                ad_id VARCHAR(64) NOT NULL,
                group_no TINYINT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_owner (user_id, telegram_id),
                KEY idx_ad (ad_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS compare_groups (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                telegram_id VARCHAR(64) NULL,
                group_no TINYINT NOT NULL,
                name VARCHAR(60) NOT NULL DEFAULT '',
                KEY idx_owner (user_id, telegram_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('melkinoCompareOwner')) {
    /**
     * شرط مالکیت ردیف‌های مقایسه.
     * @return array{0:string,1:array,2:?int,3:string} [where, params, userId, telegramId]
     */
    function melkinoCompareOwner(array $identity, string $alias = ''): array
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $conds = [];
        $params = [];
        $userId = !empty($identity['user_id']) ? (int)$identity['user_id'] : null;
        $telegramId = trim((string)($identity['telegram_id'] ?? ''));
        if ($userId) {
            $conds[] = $prefix . 'user_id = ?';
            $params[] = $userId;
        }
        if ($telegramId !== '') {
            $conds[] = $prefix . 'telegram_id = ?';
            $params[] = $telegramId;
        }
        if (!$conds) {
            return ['', [], null, ''];
        }
        return ['(' . implode(' OR ', $conds) . ')', $params, $userId, $telegramId];
    }
}

if (!function_exists('melkinoCompareGroupName')) {
    function melkinoCompareGroupName(PDO $pdo, string $ownerWhere, array $ownerParams, int $groupNo): string
    {
        $defaults = [1 => 'گروه ۱', 2 => 'گروه ۲', 3 => 'گروه ۳'];
        $stmt = $pdo->prepare("SELECT name FROM compare_groups WHERE $ownerWhere AND group_no = ? ORDER BY id DESC LIMIT 1");
        $params = $ownerParams;
        $params[] = $groupNo;
        $stmt->execute($params);
        $name = trim((string)$stmt->fetchColumn());
        return $name !== '' ? $name : $defaults[$groupNo];
    }
}

if (!function_exists('melkinoCompareItems')) {
    /** ملک‌های منتشرشده یک دسته (به‌همراه عکس اصلی). */
    function melkinoCompareItems(PDO $pdo, string $ownerWhere, array $ownerParams, int $groupNo): array
    {
        $stmt = $pdo->prepare(
            "SELECT a.id, a.title, a.transaction_type, a.property_type, a.location,
                    a.area, a.rooms, a.year, a.price_sell, a.total_price,
                    a.deposit, a.rent_monthly, a.price_hidden, ci.created_at,
                    i.filename AS image
             FROM compare_items ci
             INNER JOIN ads a ON a.id = ci.ad_id AND a.status = 'published'
             LEFT JOIN images i ON i.id = (
                 SELECT i2.id FROM images i2
                 WHERE i2.ad_id = a.id AND i2.is_selected = 1 AND i2.publish_publicly = 1
                 ORDER BY i2.is_primary DESC, i2.sort_order ASC, i2.id ASC LIMIT 1
             )
             WHERE $ownerWhere AND ci.group_no = ?
             ORDER BY ci.created_at ASC"
        );
        $params = $ownerParams;
        $params[] = $groupNo;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('melkinoCompareRefAmount')) {
    /** مبلغ مرجع قابل‌مقایسه: فروش ← قیمت فروش؛ اجاره ← ودیعه + ۱۲ ماه اجاره. */
    function melkinoCompareRefAmount(array $ad): ?float
    {
        $num = function ($v): ?float {
            if ($v === null || $v === '') {
                return null;
            }
            $n = (float)str_replace(',', '', (string)$v);
            return $n > 0 ? $n : null;
        };
        $tx = trim((string)($ad['transaction_type'] ?? ''));
        $isRent = mb_strpos($tx, 'اجاره') !== false || mb_strpos($tx, 'رهن') !== false;
        if ($isRent) {
            $dep = $num($ad['deposit'] ?? null);
            $rent = $num($ad['rent_monthly'] ?? null);
            if ($dep === null && $rent === null) {
                return null;
            }
            return ($dep ?? 0) + ($rent ?? 0) * 12;
        }
        return $num($ad['price_sell'] ?? null)
            ?? $num($ad['total_price'] ?? null)
            ?? $num($ad['deposit'] ?? null);
    }
}

if (!function_exists('melkinoScoreCompareGroup')) {
    /**
     * موتور امتیازدهی مقایسه — شفاف و نسبی درون گروه، از ۱۰۰ نمره:
     *   قیمت (هر متر ارزان‌تر بهتر) ۳۵ + متراژ ۱۵ + خواب ۱۰ +
     *   سال ساخت (نوساز بهتر) ۱۰ + امکانات ۱۵ + کیفیت آگهی ۱۵
     */
    function melkinoScoreCompareGroup(array $ads): array
    {
        $n = count($ads);
        if ($n === 0) {
            return ['scores' => [], 'winner' => null, 'highlights' => [], 'mixed_types' => false];
        }

        $txs = [];
        foreach ($ads as $ad) {
            $t = trim((string)($ad['transaction_type'] ?? ''));
            if ($t !== '') {
                $txs[$t] = true;
            }
        }

        // پیش‌محاسبات
        $perM = [];
        $areas = [];
        $rooms = [];
        $years = [];
        $amenCounts = [];
        foreach ($ads as $ad) {
            $id = (string)$ad['id'];
            $amount = melkinoCompareRefAmount($ad);
            $area = (float)($ad['area'] ?? 0) > 0 ? (float)$ad['area'] : null;
            $perM[$id] = ($amount !== null && $area !== null) ? $amount / $area : $amount;
            $areas[$id] = $area;
            $r = (int)($ad['rooms'] ?? 0);
            $rooms[$id] = $r > 0 ? $r : null;
            $y = (int)($ad['year'] ?? 0);
            $years[$id] = $y > 1300 && $y < 1500 ? $y : null;
            $amenCounts[$id] = max(0, (int)($ad['amenity_count'] ?? 0));
        }

        $minPerM = null;
        foreach ($perM as $v) {
            if ($v !== null && ($minPerM === null || $v < $minPerM)) {
                $minPerM = $v;
            }
        }
        $maxArea = null;
        foreach ($areas as $v) {
            if ($v !== null && ($maxArea === null || $v > $maxArea)) {
                $maxArea = $v;
            }
        }
        $maxRooms = null;
        foreach ($rooms as $v) {
            if ($v !== null && ($maxRooms === null || $v > $maxRooms)) {
                $maxRooms = $v;
            }
        }
        $knownYears = array_values(array_filter($years, fn($v) => $v !== null));
        $minYear = $knownYears ? min($knownYears) : null;
        $maxYear = $knownYears ? max($knownYears) : null;
        $maxAmen = max($amenCounts);
        if ($maxAmen <= 0) {
            $maxAmen = null;
        }

        $scores = [];
        foreach ($ads as $ad) {
            $id = (string)$ad['id'];

            // ۱) قیمت (۳۵): ارزان‌ترین هر متر = ۳۵
            if ($perM[$id] !== null && $minPerM !== null && $perM[$id] > 0) {
                $priceScore = 35 * ($minPerM / $perM[$id]);
            } else {
                $priceScore = 5;
            }

            // ۲) متراژ (۱۵)
            if ($areas[$id] !== null && $maxArea !== null && $maxArea > 0) {
                $areaScore = 15 * ($areas[$id] / $maxArea);
            } else {
                $areaScore = 4;
            }

            // ۳) خواب (۱۰)
            if ($rooms[$id] !== null && $maxRooms !== null && $maxRooms > 0) {
                $roomScore = 10 * ($rooms[$id] / $maxRooms);
            } else {
                $roomScore = 3;
            }

            // ۴) سال ساخت (۱۰): نوساز بهتر
            if ($years[$id] !== null && $minYear !== null && $maxYear !== null) {
                $span = $maxYear - $minYear;
                $yearScore = $span > 0 ? 4 + 6 * (($years[$id] - $minYear) / $span) : 7;
            } else {
                $yearScore = 4;
            }

            // ۵) امکانات (۱۵)
            if ($maxAmen !== null) {
                $amenScore = 15 * ($amenCounts[$id] / $maxAmen);
            } else {
                $amenScore = 5;
            }

            // ۶) کیفیت آگهی (۱۵): عکس + توضیحات
            $imgCount = max(0, (int)($ad['img_count'] ?? 0));
            $descLen = function_exists('mb_strlen')
                ? mb_strlen(trim((string)($ad['description'] ?? '')), 'UTF-8')
                : strlen(trim((string)($ad['description'] ?? '')));
            $qualityScore = min(7, $imgCount * 1.2) + min(8, ($descLen / 300) * 8);

            $total = $priceScore + $areaScore + $roomScore + $yearScore + $amenScore + $qualityScore;
            $scores[$id] = [
                'total' => round($total, 1),
                'breakdown' => [
                    'price' => round($priceScore, 1),
                    'area' => round($areaScore, 1),
                    'rooms' => round($roomScore, 1),
                    'year' => round($yearScore, 1),
                    'amenities' => round($amenScore, 1),
                    'quality' => round($qualityScore, 1),
                ],
                'price_per_m' => $perM[$id] !== null ? round($perM[$id]) : null,
            ];
        }

        // برنده + نکات برجسته
        $winner = null;
        $best = -1;
        foreach ($scores as $id => $s) {
            if ($s['total'] > $best) {
                $best = $s['total'];
                $winner = $id;
            }
        }

        $titles = [];
        foreach ($ads as $ad) {
            $titles[(string)$ad['id']] = trim((string)($ad['title'] ?? '')) !== ''
                ? trim((string)$ad['title'])
                : ('ملک ' . (string)$ad['id']);
        }
        $highlights = [];
        if ($n >= 2) {
            if ($minPerM !== null) {
                $bestId = array_search($minPerM, $perM, true);
                if ($bestId !== false) {
                    $highlights[] = 'بهترین قیمت هر متر: ' . $titles[$bestId];
                }
            }
            if ($maxArea !== null) {
                $bestId = array_search($maxArea, $areas, true);
                if ($bestId !== false) {
                    $highlights[] = 'بزرگ‌ترین متراژ: ' . $titles[$bestId];
                }
            }
            if ($maxYear !== null) {
                $bestId = array_search($maxYear, $years, true);
                if ($bestId !== false) {
                    $highlights[] = 'نوسازترین ملک: ' . $titles[$bestId];
                }
            }
            if ($maxAmen !== null) {
                $bestId = array_search($maxAmen, $amenCounts, true);
                if ($bestId !== false) {
                    $highlights[] = 'بیشترین امکانات: ' . $titles[$bestId];
                }
            }
        }

        return [
            'scores' => $scores,
            'winner' => $winner,
            'highlights' => $highlights,
            'mixed_types' => count($txs) > 1,
        ];
    }
}

/* =====================================================
   ACTIONS
   ===================================================== */

if (!isset($_GET['action'])) {
    melkinoJsonResponse(['success' => false, 'message' => 'عملیات نامعتبر است.'], 400);
}

if (!$pdo instanceof PDO) {
    melkinoJsonResponse(['success' => false, 'message' => 'اتصال دیتابیس برقرار نیست.'], 500);
}

try {
    melkinoEnsureCompareTables($pdo);
} catch (Throwable $e) {
    melkinoJsonResponse(['success' => false, 'message' => 'خطا در آماده‌سازی جدول‌ها.'], 500);
}

$identity = melkinoCurrentIdentity();
[$ownerWhere, $ownerParams, $ownerUserId, $ownerTelegramId] = melkinoCompareOwner($identity, 'ci');
[$ownerWherePlain, $ownerParamsPlain] = melkinoCompareOwner($identity);
$action = trim((string)$_GET['action']);

if ($ownerWhere === '') {
    melkinoJsonResponse(['success' => false, 'message' => 'برای مقایسه، اول وارد حساب شو.'], 401);
}

if ($action === 'count') {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM compare_items ci
         INNER JOIN ads a ON a.id = ci.ad_id AND a.status = 'published'
         WHERE $ownerWhere"
    );
    $stmt->execute($ownerParams);
    melkinoJsonResponse(['success' => true, 'count' => (int)$stmt->fetchColumn()]);
}

if ($action === 'list') {
    $groups = [];
    $total = 0;
    for ($g = 1; $g <= 3; $g++) {
        $items = melkinoCompareItems($pdo, $ownerWhere, $ownerParams, $g);
        $total += count($items);
        $groups[] = [
            'no' => $g,
            'name' => melkinoCompareGroupName($pdo, $ownerWherePlain, $ownerParamsPlain, $g),
            'count' => count($items),
            'items' => $items,
        ];
    }
    melkinoJsonResponse(['success' => true, 'groups' => $groups, 'total' => $total]);
}

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
        melkinoJsonResponse(['success' => false, 'message' => 'آگهی پیدا نشد یا منتشر نیست.'], 404);
    }

    // تکراری؟
    $stmt = $pdo->prepare("SELECT group_no FROM compare_items ci WHERE $ownerWhere AND ci.ad_id = ? LIMIT 1");
    $p = $ownerParams;
    $p[] = $adId;
    $stmt->execute($p);
    $existingGroup = $stmt->fetchColumn();
    if ($existingGroup !== false) {
        melkinoJsonResponse([
            'success' => true,
            'already' => true,
            'group' => (int)$existingGroup,
            'group_name' => melkinoCompareGroupName($pdo, $ownerWherePlain, $ownerParamsPlain, (int)$existingGroup),
            'message' => 'این ملک قبلاً به مقایسه اضافه شده.',
        ]);
    }

    // انتخاب دسته: auto یعنی اولین دسته‌ای که جا دارد
    $countIn = function (int $g) use ($pdo, $ownerWhere, $ownerParams): int {
        $s = $pdo->prepare(
            "SELECT COUNT(*) FROM compare_items ci
             INNER JOIN ads a ON a.id = ci.ad_id AND a.status = 'published'
             WHERE $ownerWhere AND ci.group_no = ?"
        );
        $pp = $ownerParams;
        $pp[] = $g;
        $s->execute($pp);
        return (int)$s->fetchColumn();
    };
    if ($groupRaw === 'auto' || $groupRaw === '' || $groupRaw === '0') {
        $groupNo = 0;
        for ($g = 1; $g <= 3; $g++) {
            if ($countIn($g) < 5) {
                $groupNo = $g;
                break;
            }
        }
        if ($groupNo === 0) {
            melkinoJsonResponse(['success' => false, 'message' => 'هر ۳ دسته مقایسه پر است (حداکثر ۵ ملک در هر دسته).'], 422);
        }
    } else {
        $groupNo = (int)$groupRaw;
        if ($groupNo < 1 || $groupNo > 3) {
            melkinoJsonResponse(['success' => false, 'message' => 'دسته نامعتبر است.'], 422);
        }
        if ($countIn($groupNo) >= 5) {
            melkinoJsonResponse(['success' => false, 'message' => 'این دسته پر است (حداکثر ۵ ملک در هر دسته).'], 422);
        }
    }

    $stmt = $pdo->prepare('INSERT INTO compare_items (user_id, telegram_id, ad_id, group_no) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $ownerUserId,
        $ownerTelegramId !== '' ? $ownerTelegramId : null,
        $adId,
        $groupNo,
    ]);
    melkinoJsonResponse([
        'success' => true,
        'group' => $groupNo,
        'group_name' => melkinoCompareGroupName($pdo, $ownerWherePlain, $ownerParamsPlain, $groupNo),
        'message' => 'به مقایسه اضافه شد.',
    ]);
}

if ($action === 'remove') {
    $adId = trim((string)($_POST['ad_id'] ?? $_GET['ad_id'] ?? ''));
    if ($adId === '') {
        melkinoJsonResponse(['success' => false, 'message' => 'شناسه آگهی الزامی است.'], 422);
    }
    $stmt = $pdo->prepare("DELETE FROM compare_items WHERE $ownerWherePlain AND ad_id = ?");
    $p = $ownerParamsPlain;
    $p[] = $adId;
    $stmt->execute($p);
    melkinoJsonResponse(['success' => true, 'removed' => $stmt->rowCount() > 0]);
}

if ($action === 'move') {
    $adId = trim((string)($_POST['ad_id'] ?? ''));
    $groupNo = (int)($_POST['group'] ?? 0);
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
        $s = $pdo->prepare(
            "SELECT COUNT(*) FROM compare_items ci
             INNER JOIN ads a ON a.id = ci.ad_id AND a.status = 'published'
             WHERE $ownerWhere AND ci.group_no = ?"
        );
        $pp = $ownerParams;
        $pp[] = $groupNo;
        $s->execute($pp);
        if ((int)$s->fetchColumn() >= 5) {
            melkinoJsonResponse(['success' => false, 'message' => 'دسته مقصد پر است (حداکثر ۵ ملک).'], 422);
        }
        $pdo->prepare('UPDATE compare_items SET group_no = ? WHERE id = ?')->execute([$groupNo, (int)$row['id']]);
    }
    melkinoJsonResponse(['success' => true, 'group' => $groupNo]);
}

if ($action === 'rename_group') {
    $groupNo = (int)($_POST['group'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($groupNo < 1 || $groupNo > 3) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسته نامعتبر است.'], 422);
    }
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 40, 'UTF-8');
    } else {
        $name = substr($name, 0, 40);
    }
    $stmt = $pdo->prepare("SELECT id FROM compare_groups WHERE $ownerWherePlain AND group_no = ? LIMIT 1");
    $p = $ownerParamsPlain;
    $p[] = $groupNo;
    $stmt->execute($p);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        $pdo->prepare('UPDATE compare_groups SET name = ? WHERE id = ?')->execute([$name, (int)$existing]);
    } else {
        $pdo->prepare('INSERT INTO compare_groups (user_id, telegram_id, group_no, name) VALUES (?, ?, ?, ?)')
            ->execute([$ownerUserId, $ownerTelegramId !== '' ? $ownerTelegramId : null, $groupNo, $name]);
    }
    melkinoJsonResponse(['success' => true, 'name' => $name]);
}

if ($action === 'clear_group') {
    $groupNo = (int)($_POST['group'] ?? 0);
    if ($groupNo < 1 || $groupNo > 3) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسته نامعتبر است.'], 422);
    }
    $stmt = $pdo->prepare("DELETE FROM compare_items WHERE $ownerWherePlain AND group_no = ?");
    $p = $ownerParamsPlain;
    $p[] = $groupNo;
    $stmt->execute($p);
    melkinoJsonResponse(['success' => true, 'deleted' => $stmt->rowCount()]);
}

if ($action === 'score') {
    $groupNo = (int)($_POST['group'] ?? $_GET['group'] ?? 0);
    if ($groupNo < 1 || $groupNo > 3) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسته نامعتبر است.'], 422);
    }
    $stmt = $pdo->prepare(
        "SELECT a.id, a.title, a.transaction_type, a.property_type, a.location, a.address,
                a.area, a.rooms, a.floor, a.year,
                a.price_sell, a.total_price, a.deposit, a.rent_monthly, a.price_hidden,
                a.description, a.is_vip,
                i.filename AS image,
                (SELECT COUNT(*) FROM images i2 WHERE i2.ad_id = a.id AND i2.is_selected = 1 AND i2.publish_publicly = 1) AS img_count,
                (SELECT COUNT(*) FROM ad_amenities aa WHERE aa.ad_id = a.id) AS amenity_count,
                (SELECT GROUP_CONCAT(am.name SEPARATOR '، ') FROM ad_amenities aa JOIN amenities am ON am.id = aa.amenity_id WHERE aa.ad_id = a.id) AS amenity_names
         FROM compare_items ci
         INNER JOIN ads a ON a.id = ci.ad_id AND a.status = 'published'
         LEFT JOIN images i ON i.id = (
             SELECT i3.id FROM images i3
             WHERE i3.ad_id = a.id AND i3.is_selected = 1 AND i3.publish_publicly = 1
             ORDER BY i3.is_primary DESC, i3.sort_order ASC, i3.id ASC LIMIT 1
         )
         WHERE $ownerWhere AND ci.group_no = ?
         ORDER BY ci.created_at ASC"
    );
    $p = $ownerParams;
    $p[] = $groupNo;
    $stmt->execute($p);
    $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($ads) < 2) {
        melkinoJsonResponse(['success' => false, 'message' => 'برای مقایسه حداقل ۲ ملک در این دسته لازم است.'], 422);
    }
    $result = melkinoScoreCompareGroup($ads);
    melkinoJsonResponse([
        'success' => true,
        'group' => $groupNo,
        'group_name' => melkinoCompareGroupName($pdo, $ownerWherePlain, $ownerParamsPlain, $groupNo),
        'ads' => $ads,
        'scores' => $result['scores'],
        'winner' => $result['winner'],
        'highlights' => $result['highlights'],
        'mixed_types' => $result['mixed_types'],
    ]);
}

melkinoJsonResponse(['success' => false, 'message' => 'عملیات نامعتبر است.'], 400);
