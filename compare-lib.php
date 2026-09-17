<?php
/*
|--------------------------------------------------------------------------
| کتابخانهٔ مشترک «مقایسهٔ ملک‌ها»
|--------------------------------------------------------------------------
| این فایل قلبِ قابلیت مقایسه است و هم توسط API (compare.php) و هم توسط
| صفحهٔ مستقل مقایسه (compare-page.php) استفاده می‌شود تا منطق در یک جا
| باشد و رفتار هر دو مسیر یکسان بماند.
|
| چرا مهمان (Guest) هم می‌تواند مقایسه کند؟
|   نسخهٔ قبلی فقط با «هویت سشن» کار می‌کرد؛ یعنی اگر کاربر وارد نشده بود،
|   همهٔ درخواست‌ها ۴۰۱ می‌شد و دکمهٔ «افزودن به مقایسه» هیچ کاری نمی‌کرد
|   (همان «مقایسه کار نمی‌کند»). حالا هر بازدیدکننده یک شناسهٔ تصادفیِ
|   امن در کوکی می‌گیرد و مقایسه‌اش همان‌جا ذخیره می‌شود؛ به‌محض ورود،
|   ردیف‌های مهمان به حساب کاربری‌اش منتقل می‌شود.
|--------------------------------------------------------------------------
*/

if (!function_exists('melkinoCompareGuestCookieName')) {
    function melkinoCompareGuestCookieName(): string
    {
        return 'melkino_cmp_guest';
    }
}

/**
 * شناسهٔ مهمان از کوکی خوانده می‌شود و اگر نبود، ساخته و ست می‌شود.
 * فقط مقدارهای هگزِ ۳۲ کاراکتری پذیرفته می‌شوند (جلوگیری از تزریق).
 */
if (!function_exists('melkinoCompareGuestToken')) {
    function melkinoCompareGuestToken(bool $create = true): string
    {
        $name = melkinoCompareGuestCookieName();
        $value = trim((string)($_COOKIE[$name] ?? ''));

        if ($value !== '' && preg_match('/^[a-f0-9]{32}$/', $value) !== 1) {
            $value = '';
        }

        if ($value !== '') {
            return $value;
        }

        if (!$create) {
            return '';
        }

        try {
            $value = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $value = md5(uniqid('cmp', true));
        }

        $_COOKIE[$name] = $value;

        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
                || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

            setcookie($name, $value, [
                'expires'  => time() + 60 * 60 * 24 * 365,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return $value;
    }
}

/** در صورت نیاز، ستون guest_token را به جدول‌های مقایسه اضافه می‌کند. */
if (!function_exists('melkinoCompareEnsureColumn')) {
    function melkinoCompareEnsureColumn(PDO $pdo, string $table): void
    {
        try {
            $columns = [];
            foreach ($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[strtolower((string)($col['Field'] ?? ''))] = true;
            }
            if (!isset($columns['guest_token'])) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN guest_token VARCHAR(64) NULL");
            }
            if (!isset($columns['updated_at'])) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN updated_at DATETIME NULL");
            }
        } catch (Throwable $e) {
            // اگر کاربرِ دیتابیس اجازهٔ ALTER نداشت، مقایسه برای کاربرانِ
            // واردشده همچنان کار می‌کند و خطای مرگبار نمی‌دهیم.
        }
    }
}

/** ساخت جدول‌های مقایسه در اولین استفاده. */
if (!function_exists('melkinoEnsureCompareTables')) {
    function melkinoEnsureCompareTables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS compare_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                telegram_id VARCHAR(64) NULL,
                guest_token VARCHAR(64) NULL,
                ad_id VARCHAR(64) NOT NULL,
                group_no TINYINT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_owner (user_id, telegram_id),
                KEY idx_guest (guest_token),
                KEY idx_ad (ad_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS compare_groups (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                telegram_id VARCHAR(64) NULL,
                guest_token VARCHAR(64) NULL,
                group_no TINYINT NOT NULL,
                name VARCHAR(60) NOT NULL DEFAULT '',
                KEY idx_owner (user_id, telegram_id),
                KEY idx_guest (guest_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        melkinoCompareEnsureColumn($pdo, 'compare_items');
        melkinoCompareEnsureColumn($pdo, 'compare_groups');
    }
}

if (!function_exists('melkinoCompareMaxPerGroup')) {
    function melkinoCompareMaxPerGroup(): int
    {
        return 5;
    }
}

if (!function_exists('melkinoCompareGroupDefaults')) {
    function melkinoCompareGroupDefaults(): array
    {
        return [1 => 'گروه ۱', 2 => 'گروه ۲', 3 => 'گروه ۳'];
    }
}

/**
 * شرط مالکیت ردیف‌های مقایسه بر اساس هویتِ سشن + شناسهٔ مهمان.
 *
 * @return array{0:string,1:array,2:?int,3:string,4:string} [where, params, userId, telegramId, guestToken]
 */
if (!function_exists('melkinoCompareOwner')) {
    function melkinoCompareOwner(array $identity, string $alias = '', bool $createGuest = true): array
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $conds = [];
        $params = [];

        $userId = !empty($identity['user_id']) ? (int)$identity['user_id'] : null;
        $telegramId = trim((string)($identity['telegram_id'] ?? ''));
        $guestToken = melkinoCompareGuestToken($createGuest);

        if ($userId) {
            $conds[] = $prefix . 'user_id = ?';
            $params[] = $userId;
        }
        if ($telegramId !== '') {
            $conds[] = $prefix . 'telegram_id = ?';
            $params[] = $telegramId;
        }
        if ($guestToken !== '') {
            $conds[] = $prefix . 'guest_token = ?';
            $params[] = $guestToken;
        }

        if (!$conds) {
            return ['', [], null, '', ''];
        }

        return ['(' . implode(' OR ', $conds) . ')', $params, $userId, $telegramId, $guestToken];
    }
}

/**
 * انتقالِ مقایسهٔ مهمان به حساب کاربر (اولین باری که کاربر وارد شده دیده شود).
 * این کار یک‌بار انجام می‌شود؛ بعد از آن کوکیِ مهمان پاک می‌شود.
 */
if (!function_exists('melkinoCompareMergeGuest')) {
    function melkinoCompareMergeGuest(PDO $pdo, array $identity): void
    {
        $userId = !empty($identity['user_id']) ? (int)$identity['user_id'] : null;
        $telegramId = trim((string)($identity['telegram_id'] ?? ''));

        if (!$userId && $telegramId === '') {
            return;
        }

        $guestToken = trim((string)($_COOKIE[melkinoCompareGuestCookieName()] ?? ''));
        if ($guestToken === '' || preg_match('/^[a-f0-9]{32}$/', $guestToken) !== 1) {
            return;
        }

        try {
            $pdo->prepare(
                'UPDATE compare_items SET user_id = ?, telegram_id = ? WHERE guest_token = ? AND user_id IS NULL'
            )->execute([$userId, $telegramId !== '' ? $telegramId : null, $guestToken]);

            $pdo->prepare(
                'UPDATE compare_groups SET user_id = ?, telegram_id = ? WHERE guest_token = ? AND user_id IS NULL'
            )->execute([$userId, $telegramId !== '' ? $telegramId : null, $guestToken]);
        } catch (Throwable $e) {
            return;
        }

        unset($_COOKIE[melkinoCompareGuestCookieName()]);
        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            setcookie(melkinoCompareGuestCookieName(), '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}

if (!function_exists('melkinoCompareGroupName')) {
    function melkinoCompareGroupName(PDO $pdo, string $ownerWhere, array $ownerParams, int $groupNo): string
    {
        $defaults = melkinoCompareGroupDefaults();
        $stmt = $pdo->prepare("SELECT name FROM compare_groups WHERE $ownerWhere AND group_no = ? ORDER BY id DESC LIMIT 1");
        $params = $ownerParams;
        $params[] = $groupNo;
        $stmt->execute($params);
        $name = trim((string)$stmt->fetchColumn());
        return $name !== '' ? $name : ($defaults[$groupNo] ?? ('گروه ' . $groupNo));
    }
}

/** ملک‌های منتشرشدهٔ یک دسته (به‌همراه عکس اصلی). */
if (!function_exists('melkinoCompareItems')) {
    function melkinoCompareItems(PDO $pdo, string $ownerWhere, array $ownerParams, int $groupNo): array
    {
        $stmt = $pdo->prepare(
            "SELECT a.id, a.title, a.transaction_type, a.property_type, a.location,
                    a.area, a.rooms, a.year, a.price_sell, a.total_price,
                    a.deposit, a.rent_monthly, a.price_hidden, ci.group_no, ci.created_at,
                    i.filename AS image
             FROM compare_items ci
             INNER JOIN ads a ON a.id = ci.ad_id AND a.status = 'published'
             LEFT JOIN images i ON i.id = (
                 SELECT i2.id FROM images i2
                 WHERE i2.ad_id = a.id AND i2.is_selected = 1 AND i2.publish_publicly = 1
                 ORDER BY i2.is_primary DESC, i2.sort_order ASC, i2.id ASC LIMIT 1
             )
             WHERE $ownerWhere AND ci.group_no = ?
             ORDER BY ci.created_at ASC, ci.id ASC"
        );
        $params = $ownerParams;
        $params[] = $groupNo;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/** تعداد کل ملک‌های مقایسه. */
if (!function_exists('melkinoCompareCount')) {
    function melkinoCompareCount(PDO $pdo, string $ownerWhere, array $ownerParams): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM compare_items ci
             INNER JOIN ads a ON a.id = ci.ad_id AND a.status = 'published'
             WHERE $ownerWhere"
        );
        $stmt->execute($ownerParams);
        return (int)$stmt->fetchColumn();
    }
}

/** تعداد ملک‌های یک دسته. */
if (!function_exists('melkinoCompareCountInGroup')) {
    function melkinoCompareCountInGroup(PDO $pdo, string $ownerWhere, array $ownerParams, int $groupNo): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM compare_items ci
             INNER JOIN ads a ON a.id = ci.ad_id AND a.status = 'published'
             WHERE $ownerWhere AND ci.group_no = ?"
        );
        $params = $ownerParams;
        $params[] = $groupNo;
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}

/** فهرست کامل سه دسته با نام، تعداد و ملک‌ها. */
if (!function_exists('melkinoCompareGroups')) {
    function melkinoCompareGroups(PDO $pdo, string $ownerWhere, array $ownerParams, string $ownerWherePlain, array $ownerParamsPlain): array
    {
        $groups = [];
        for ($g = 1; $g <= 3; $g++) {
            $items = melkinoCompareItems($pdo, $ownerWhere, $ownerParams, $g);
            $groups[] = [
                'no'    => $g,
                'name'  => melkinoCompareGroupName($pdo, $ownerWherePlain, $ownerParamsPlain, $g),
                'count' => count($items),
                'items' => $items,
            ];
        }
        return $groups;
    }
}

/** مبلغ مرجع قابل‌مقایسه: فروش ← قیمت فروش؛ اجاره ← ودیعه + ۱۲ ماه اجاره. */
if (!function_exists('melkinoCompareRefAmount')) {
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

/**
 * موتور امتیازدهی مقایسه — شفاف و نسبی درون گروه، از ۱۰۰ نمره:
 *   قیمت (هر متر ارزان‌تر بهتر) ۳۵ + متراژ ۱۵ + خواب ۱۰ +
 *   سال ساخت (نوساز بهتر) ۱۰ + امکانات ۱۵ + کیفیت آگهی ۱۵
 */
if (!function_exists('melkinoScoreCompareGroup')) {
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

/**
 * دادهٔ کاملِ امتیازدهی یک دسته: خودِ ملک‌ها + امتیازها + برنده + نکات.
 * خروجی: ['ads'=>[], 'scores'=>[], 'winner'=>?, 'highlights'=>[], 'mixed_types'=>bool]
 */
if (!function_exists('melkinoCompareScoreData')) {
    function melkinoCompareScoreData(PDO $pdo, string $ownerWhere, array $ownerParams, int $groupNo): array
    {
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
             ORDER BY ci.created_at ASC, ci.id ASC"
        );
        $params = $ownerParams;
        $params[] = $groupNo;
        $stmt->execute($params);
        $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = melkinoScoreCompareGroup($ads);

        return [
            'ads'         => $ads,
            'scores'      => $result['scores'],
            'winner'      => $result['winner'],
            'highlights'  => $result['highlights'],
            'mixed_types' => $result['mixed_types'],
        ];
    }
}

/** بهترین‌های یک گروه برای نمایش در کارتِ پروفایل. */
if (!function_exists('melkinoCompareBestGroup')) {
    function melkinoCompareBestGroup(array $groups): ?array
    {
        $best = null;
        foreach ($groups as $g) {
            if (!is_array($g) || ($g['count'] ?? 0) < 2) {
                continue;
            }
            if ($best === null || $g['count'] > $best['count']) {
                $best = $g;
            }
        }
        if ($best === null) {
            foreach ($groups as $g) {
                if (is_array($g) && ($g['count'] ?? 0) > 0) {
                    return $g;
                }
            }
        }
        return $best;
    }
}
