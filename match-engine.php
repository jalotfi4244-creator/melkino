<?php

require_once __DIR__ . '/db_helpers.php';

global $pdo;


/* =====================================================
   Ensure feedback table exists
   ===================================================== */

function m5EnsureFeedbackTable(): void
{
    global $pdo;

    static $done = false;

    if ($done || !($pdo instanceof PDO)) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS request_match_feedback (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_match_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            telegram_id VARCHAR(128) NULL,
            feedback ENUM('like','dislike') NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            UNIQUE KEY uq_rmf_match_user
                (request_match_id, user_id, telegram_id),

            KEY idx_rmf_match
                (request_match_id),

            KEY idx_rmf_user
                (user_id),

            KEY idx_rmf_telegram
                (telegram_id),

            CONSTRAINT fk_rmf_match
                FOREIGN KEY (request_match_id)
                REFERENCES request_matches(id)
                ON DELETE CASCADE
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    $done = true;
}


/* =====================================================
   Normalize Persian text
   ===================================================== */

function m5norm($v): string
{
    $v = trim((string)$v);

    $v = strtr($v, [
        'ي' => 'ی',
        'ى' => 'ی',
        'ك' => 'ک',
        'ة' => 'ه',
        'ؤ' => 'و',
        'إ' => 'ا',
        'أ' => 'ا',
        'آ' => 'ا',
        'ۀ' => 'ه',
        '‌' => ' '
    ]);

    $v = preg_replace('/\s+/u', ' ', $v);

    return mb_strtolower($v, 'UTF-8');
}


/* =====================================================
   Normalize numeric values
   ===================================================== */

function m5num($v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }

    $v = strtr((string)$v, [
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9'
    ]);

    $v = str_replace(
        [
            ',',
            '٬',
            '،',
            ' ',
            'تومان',
            'ریال'
        ],
        '',
        $v
    );

    $v = preg_replace('/[^0-9.\-]/u', '', $v);

    return ($v !== '' && is_numeric($v))
        ? (float)$v
        : null;
}


/* =====================================================
   JSON helper
   ===================================================== */

function m5json($v): array
{
    if (is_array($v)) {
        return $v;
    }

    $x = json_decode((string)$v, true);

    return is_array($x) ? $x : [];
}


/* =====================================================
   Canonical transaction type
   ===================================================== */

function m5canonicalTx($v): string
{
    $v = m5norm($v);

    /*
     * سرمایه‌گذاری
     * همه حالت‌های رایج نوشتاری باید به یک مقدار واحد
     * تبدیل شوند تا موتور تطبیق بتواند فروش و پیش‌فروش را
     * برای درخواست سرمایه‌گذاری پیشنهاد دهد.
     */
    $investmentVariants = [
        'سرمایه گذاری',
        'سرمایه‌گذاری',
        'سرمایه گزاری',
        'سرمایه‌گزاری',
        'سرمایهگزاری',
        'سرمایهگذاری'
    ];

    foreach ($investmentVariants as $variant) {
        if (
            $v === m5norm($variant) ||
            str_contains($v, m5norm($variant))
        ) {
            return 'سرمایه‌گذاری';
        }
    }

    if (
        str_contains($v, 'پیش فروش') ||
        str_contains($v, 'پیش‌فروش')
    ) {
        return 'پیش فروش';
    }

    if (str_contains($v, 'رهن کامل')) {
        return 'رهن کامل';
    }

    if (
        str_contains($v, 'رهن') &&
        str_contains($v, 'اجاره')
    ) {
        return 'رهن و اجاره';
    }

    if (str_contains($v, 'اجاره')) {
        return 'اجاره';
    }

    if (str_contains($v, 'فروش')) {
        return 'فروش';
    }

    return $v;
}


/* =====================================================
   Canonical property type
   ===================================================== */

function m5canonicalProperty($v): string
{
    $v = m5norm($v);

    $map = [
        'ویلایی' => 'ویلا',
        'ویلا' => 'ویلا',

        'اپارتمان' => 'آپارتمان',
        'آپارتمان' => 'آپارتمان',

        'مغازه' => 'تجاری',
        'تجاری' => 'تجاری',

        'دفتر اداری' => 'اداری',
        'دفتر' => 'اداری',
        'اداری' => 'اداری',

        'باغ و باغچه' => 'باغ',
        'باغ و باغچه ها' => 'باغ',
        'باغ' => 'باغ',

        'زمین' => 'زمین',
        'سوله' => 'سوله',
    ];

    return $map[$v] ?? $v;
}


/* =====================================================
   Location score
   ===================================================== */

function m5locationScore($want, $have): float
{
    $w = m5norm($want);
    $h = m5norm($have);

    if ($w === '' || $h === '') {
        return 1.0;
    }

    if ($w === $h) {
        return 1.0;
    }

    $parts = preg_split(
        '/[،,\-\/|]+/u',
        $w,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    foreach ($parts as $p) {

        $p = trim($p);

        if (
            $p !== '' &&
            mb_stripos($h, $p, 0, 'UTF-8') !== false
        ) {
            return 0.95;
        }
    }

    if (
        mb_stripos($w, $h, 0, 'UTF-8') !== false ||
        mb_stripos($h, $w, 0, 'UTF-8') !== false
    ) {
        return 0.8;
    }

    return 0.0;
}


/* =====================================================
   Range score
   Soft score only
   ===================================================== */

function m5RangeScore($min, $max, $value): float
{
    $v = m5num($value);
    $min = m5num($min);
    $max = m5num($max);

    if ($min === null && $max === null) {
        return 1.0;
    }

    if ($v === null) {
        return 0.15;
    }

    if ($min !== null && $v < $min) {

        return max(
            0.0,
            1.0 - (($min - $v) / max(1.0, $min))
        );
    }

    if ($max !== null && $v > $max) {

        return max(
            0.0,
            1.0 - (($v - $max) / max(1.0, $max))
        );
    }

    return 1.0;
}


/* =====================================================
   HARD RANGE CHECK
   Used ONLY for price.
   ===================================================== */

function m5HardRangeCheck($min, $max, $value): bool
{
    $v = m5num($value);
    $min = m5num($min);
    $max = m5num($max);

    /*
     * No range specified.
     */
    if ($min === null && $max === null) {
        return true;
    }

    /*
     * Range specified but ad has no numeric value.
     * Do not consider it a valid price match.
     */
    if ($v === null) {
        return false;
    }

    /*
     * Below minimum.
     */
    if ($min !== null && $v < $min) {
        return false;
    }

    /*
     * Above maximum.
     */
    if ($max !== null && $v > $max) {
        return false;
    }

    return true;
}


/* =====================================================
   Get property area
   IMPORTANT:
   Area is NOT a hard filter.
   ===================================================== */

function m5Area(array $ad, array $details): ?float
{
    foreach (
        [
            'area',
            'built_area',
            'land_area',
            'garden_area',
            'building_area'
        ] as $k
    ) {

        if (
            isset($ad[$k]) &&
            m5num($ad[$k]) !== null
        ) {
            return m5num($ad[$k]);
        }

        if (
            isset($details[$k]) &&
            m5num($details[$k]) !== null
        ) {
            return m5num($details[$k]);
        }
    }

    return null;
}


/* =====================================================
   HARD PRICE FILTER
   ONLY PRICE IS HARD FILTERED
   ===================================================== */

function m5PriceHardMatch(
    array $req,
    array $ad,
    string $tx
): bool {

    // ======================================================
    // شرط جدید برای سرمایه‌گذاری:
    // فقط آگهی‌هایی که قیمتشان از max_price بیشتر است حذف می‌شوند.
    // آگهی‌های بدون قیمت یا با قیمت 0 نیز مجاز نیستند.
    // ======================================================
    if ($tx === 'سرمایه‌گذاری') {
        $min = m5num($req['min_price'] ?? null);
        $max = m5num($req['max_price'] ?? null);

        // سرمایه‌گذاری می‌تواند فایل فروش یا پیش‌فروش را پیشنهاد دهد.
        // برای پیش‌فروش، قیمت اصلی از display_price خوانده می‌شود؛
        // (در صورت نبود، از total_price به‌عنوان fallback استفاده می‌شود)
        $adTx = m5canonicalTx($ad['transaction_type'] ?? '');

        if ($adTx === 'پیش فروش') {
            $value =
                m5num($ad['display_price'] ?? null)
                ?? m5num($ad['total_price'] ?? null)
                ?? m5num($ad['price_sell'] ?? null);
        } else {
            $value =
                m5num($ad['price_sell'] ?? null)
                ?? m5num($ad['total_price'] ?? null);
        }

        // اگر کاربر بازه قیمت تعیین کرده، فایل بدون قیمت معتبر نیست.
        if (($min !== null || $max !== null) && ($value === null || $value <= 0)) {
            return false;
        }

        // ===== حذف شرط حداقل قیمت برای سرمایه‌گذاری (فایل‌های ارزان‌تر نیز مجاز شوند) =====
        // if ($min !== null && $value < $min) {
        //     return false;
        // }

        if ($max !== null && $value > $max) {
            return false;
        }

        return true;
    }

    /* -------------------------------------------------
       فروش / پیش فروش
       ------------------------------------------------- */

    if (
        $tx === 'فروش' ||
        $tx === 'پیش فروش'
    ) {

        $min = m5num(
            $req['min_price'] ?? null
        );

        $max = m5num(
            $req['max_price'] ?? null
        );

        /*
         * If user has not specified a price range,
         * there is no hard price restriction.
         */
        if (
            $min === null &&
            $max === null
        ) {
            return true;
        }

        $value =
            ($tx === 'پیش فروش')
                ? (
                    m5num($ad['display_price'] ?? null)
                    ?? m5num($ad['total_price'] ?? null)
                    ?? m5num($ad['price_sell'] ?? null)
                )
                : (
                    m5num($ad['price_sell'] ?? null)
                    ?? m5num($ad['total_price'] ?? null)
                );

        return m5HardRangeCheck(
            $min,
            $max,
            $value
        );
    }


    /* -------------------------------------------------
       رهن کامل
       ------------------------------------------------- */

    if ($tx === 'رهن کامل') {

        $min = m5num(
            $req['min_deposit'] ?? null
        );

        $max = m5num(
            $req['max_deposit'] ?? null
        );

        /*
         * If deposit range is not present,
         * fallback to generic price fields.
         */
        if (
            $min === null &&
            $max === null
        ) {

            $min = m5num(
                $req['min_price'] ?? null
            );

            $max = m5num(
                $req['max_price'] ?? null
            );
        }

        /*
         * No limitation specified.
         */
        if (
            $min === null &&
            $max === null
        ) {
            return true;
        }

        $value =
            m5num($ad['full_rent'] ?? null)
            ?? m5num($ad['deposit'] ?? null);

        return m5HardRangeCheck(
            $min,
            $max,
            $value
        );
    }


    /* -------------------------------------------------
       اجاره / رهن و اجاره
       ------------------------------------------------- */

    if (
        $tx === 'اجاره' ||
        $tx === 'رهن و اجاره'
    ) {

        $dmin = m5num(
            $req['min_deposit'] ?? null
        );

        $dmax = m5num(
            $req['max_deposit'] ?? null
        );

        $rmin = m5num(
            $req['min_rent'] ?? null
        );

        $rmax = m5num(
            $req['max_rent'] ?? null
        );


        /*
         * Deposit restriction
         */
        if (
            $dmin !== null ||
            $dmax !== null
        ) {

            $deposit =
                m5num($ad['deposit'] ?? null)
                ?? m5num($ad['full_rent'] ?? null);

            if (
                !m5HardRangeCheck(
                    $dmin,
                    $dmax,
                    $deposit
                )
            ) {
                return false;
            }
        }


        /*
         * Monthly rent restriction
         */
        if (
            $rmin !== null ||
            $rmax !== null
        ) {

            $rent = m5num(
                $ad['rent_monthly'] ?? null
            );

            if (
                !m5HardRangeCheck(
                    $rmin,
                    $rmax,
                    $rent
                )
            ) {
                return false;
            }
        }


        /*
         * If no deposit/rent range exists,
         * use generic price fields if available.
         */
        if (
            $dmin === null &&
            $dmax === null &&
            $rmin === null &&
            $rmax === null
        ) {

            $min = m5num(
                $req['min_price'] ?? null
            );

            $max = m5num(
                $req['max_price'] ?? null
            );

            if (
                $min === null &&
                $max === null
            ) {
                return true;
            }

            $value =
                m5num($ad['price_sell'] ?? null)
                ?? m5num($ad['total_price'] ?? null)
                ?? m5num($ad['deposit'] ?? null)
                ?? m5num($ad['rent_monthly'] ?? null);

            return m5HardRangeCheck(
                $min,
                $max,
                $value
            );
        }

        return true;
    }


    /*
     * Unknown transaction type:
     * If generic price range exists, check it.
     */
    $min = m5num(
        $req['min_price'] ?? null
    );

    $max = m5num(
        $req['max_price'] ?? null
    );

    if (
        $min === null &&
        $max === null
    ) {
        return true;
    }

    $value =
        m5num($ad['price_sell'] ?? null)
        ?? m5num($ad['total_price'] ?? null)
        ?? m5num($ad['full_rent'] ?? null)
        ?? m5num($ad['deposit'] ?? null)
        ?? m5num($ad['rent_monthly'] ?? null);

    return m5HardRangeCheck(
        $min,
        $max,
        $value
    );
}


/* =====================================================
   Price score
   Called AFTER hard price filter.
   ===================================================== */

function m5PriceScore(
    array $req,
    array $ad,
    string $tx
): float {

    /*
     * فروش / پیش فروش
     */
    if (
        $tx === 'فروش' ||
        $tx === 'پیش فروش'
    ) {

        $min = m5num(
            $req['min_price'] ?? null
        );

        $max = m5num(
            $req['max_price'] ?? null
        );

        $v =
            ($tx === 'پیش فروش')
                ? (
                    $ad['display_price']
                    ?? $ad['total_price']
                    ?? $ad['price_sell']
                    ?? null
                )
                : (
                    $ad['price_sell']
                    ?? $ad['total_price']
                    ?? null
                );

        return m5RangeScore(
            $min,
            $max,
            $v
        );
    }


    /*
     * رهن کامل
     */
    if ($tx === 'رهن کامل') {

        $min = m5num(
            $req['min_deposit'] ?? null
        );

        $max = m5num(
            $req['max_deposit'] ?? null
        );

        if (
            $min === null &&
            $max === null
        ) {

            $min = m5num(
                $req['min_price'] ?? null
            );

            $max = m5num(
                $req['max_price'] ?? null
            );
        }

        $v =
            $ad['full_rent']
            ?? $ad['deposit']
            ?? null;

        return m5RangeScore(
            $min,
            $max,
            $v
        );
    }


    /*
     * اجاره / رهن و اجاره
     */
    if (
        $tx === 'اجاره' ||
        $tx === 'رهن و اجاره'
    ) {

        $dmin = m5num(
            $req['min_deposit'] ?? null
        );

        $dmax = m5num(
            $req['max_deposit'] ?? null
        );

        $rmin = m5num(
            $req['min_rent'] ?? null
        );

        $rmax = m5num(
            $req['max_rent'] ?? null
        );

        $ds = m5RangeScore(
            $dmin,
            $dmax,
            $ad['deposit']
                ?? $ad['full_rent']
                ?? null
        );

        $rs = m5RangeScore(
            $rmin,
            $rmax,
            $ad['rent_monthly']
                ?? null
        );

        $hasD =
            ($dmin !== null || $dmax !== null);

        $hasR =
            ($rmin !== null || $rmax !== null);

        if ($hasD && $hasR) {
            return ($ds + $rs) / 2;
        }

        if ($hasD) {
            return $ds;
        }

        if ($hasR) {
            return $rs;
        }

        return 1.0;
    }


    /*
     * Generic price (including investment)
     */
    $min = m5num(
        $req['min_price'] ?? null
    );

    $max = m5num(
        $req['max_price'] ?? null
    );

    $v =
        $ad['price_sell']
        ?? $ad['total_price']
        ?? $ad['full_rent']
        ?? $ad['deposit']
        ?? $ad['rent_monthly']
        ?? null;

    return m5RangeScore(
        $min,
        $max,
        $v
    );
}


/* =====================================================
   Load published ads
   ===================================================== */

function m5LoadPublishedAds(): array
{
    global $pdo;

    $stmt = $pdo->query("
        SELECT *
        FROM ads
        WHERE status='published'
        ORDER BY created_at DESC, id DESC
    ");

    $ads = $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    if (!$ads) {
        return [];
    }


    /*
     * Load amenities
     */
    $am = $pdo->query("
        SELECT
            aa.ad_id,
            a.name
        FROM ad_amenities aa
        JOIN amenities a
            ON a.id = aa.amenity_id
        WHERE a.is_active=1
        ORDER BY aa.ad_id, a.id
    ")->fetchAll(
        PDO::FETCH_ASSOC
    );


    $map = [];

    foreach ($am as $r) {

        $map[
            (string)$r['ad_id']
        ][] = $r['name'];
    }


    foreach ($ads as &$ad) {

        $ad['_details'] =
            m5json(
                $ad['property_details']
                ?? []
            );

        $ad['_amenities'] =
            $map[
                (string)$ad['id']
            ] ?? [];
    }

    unset($ad);

    return $ads;
}


/* =====================================================
   Score Request vs Ad
   ===================================================== */

function m5ScoreRequestAd(
    array $req,
    array $ad
): array {

    $tx = m5canonicalTx(
        $req['transaction_type'] ?? ''
    );

    $adTx = m5canonicalTx(
        $ad['transaction_type'] ?? ''
    );


    $pt = m5canonicalProperty(
        $req['property_type'] ?? ''
    );

    $adPt = m5canonicalProperty(
        $ad['property_type'] ?? ''
    );


    /*
     * Transaction match
     */
    $txMatch = 0;

    if ($tx !== '') {
        if ($tx === 'سرمایه‌گذاری') {
            // برای سرمایه‌گذاری، فروش و پیش‌فروش قابل قبول هستند
            if (in_array($adTx, ['فروش', 'پیش فروش'], true)) {
                $txMatch = 1;
            }
        } else {
            // سایر نوع معاملات: تطابق دقیق
            if ($tx === $adTx) {
                $txMatch = 1;
            }
        }
    }


    /*
     * Property type match
     */
    $ptMatch = 0.0;

    if ($tx === 'سرمایه‌گذاری') {
        // محاسبه بر اساس اولویت‌ها
        $noPriority = !empty($req['_no_priority']);
        if ($noPriority) {
            $ptMatch = 1.0;
        } else {
            $priorities = $req['_priorities'] ?? ['', '', ''];
            $found = false;
            foreach ($priorities as $level => $pType) {
                if (!empty($pType) && m5canonicalProperty($pType) === $adPt) {
                    $found = true;
                    if ($level == 0) $ptMatch = 1.0;      // اولویت اول
                    elseif ($level == 1) $ptMatch = 0.75; // اولویت دوم
                    elseif ($level == 2) $ptMatch = 0.5;  // اولویت سوم
                    break;
                }
            }
            if (!$found) $ptMatch = 0.0;
        }
    } else {
        // حالت عادی: تطابق دقیق نوع ملک الزامی است
        if ($pt !== '' && $pt === $adPt) {
            $ptMatch = 1.0;
        }
    }


    /*
     * Location
     */
    $loc = m5locationScore(
        $req['location'] ?? '',
        $ad['location'] ?? ''
    );


    /*
     * Area
     *
     * IMPORTANT:
     * This is SOFT only.
     * It does NOT remove the property.
     */
    $area = m5RangeScore(
        $req['min_area'] ?? null,
        $req['max_area'] ?? null,
        m5Area(
            $ad,
            $ad['_details'] ?? []
        )
    );


    /*
     * Price
     *
     * Price hard filter has already passed
     * before this function is called.
     */
    $budget = m5PriceScore(
        $req,
        $ad,
        $tx
    );


    /*
     * Amenities
     */
    $wanted = array_values(
        array_filter(
            array_map(
                'm5norm',
                m5json(
                    $req['_amenities']
                    ?? $req['amenities']
                    ?? []
                )
            )
        )
    );


    $have = array_values(
        array_filter(
            array_map(
                'm5norm',
                $ad['_amenities']
                ?? []
            )
        )
    );


    $amen =
        $wanted
            ? count(
                array_intersect(
                    $wanted,
                    $have
                )
            ) / count($wanted)
            : 1.0;


    /*
     * Final score
     * ===== تغییر برای سرمایه‌گذاری =====
     */
    if ($tx === 'سرمایه‌گذاری') {
        // نادیده گرفتن موقعیت، متراژ و امکانات
        $loc = 1.0;
        $area = 1.0;
        $amen = 1.0;
        // وزن‌های جدید: نوع معامله ۳۰، نوع ملک ۳۵، قیمت ۳۵
        $score = ($txMatch * 30) + ($ptMatch * 35) + ($budget * 35);
    } else {
        $score = ($txMatch * 30) + ($ptMatch * 25) + ($loc * 15) + ($area * 15) + ($budget * 10) + ($amen * 5);
    }


    return [
        'percent' => (int)round(
            min(100, max(0, $score))
        ),

        'tx' => $txMatch,

        'pt' => $ptMatch,

        'loc' => $loc * 100,

        'area' => $area * 100,

        'budget' => $budget * 100,

        'amen' => $amen * 100,
    ];
}


/* =====================================================
   Ensure request matches
   ===================================================== */

function m5EnsureRequestMatches(
    int $requestId
): array {

    global $pdo;

    m5EnsureFeedbackTable();


    /*
     * Load request
     */
    $st = $pdo->prepare("
        SELECT *
        FROM property_requests
        WHERE id=?
        LIMIT 1
    ");

    $st->execute([
        $requestId
    ]);

    $req = $st->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$req) {
        return [];
    }


    /*
     * Request amenities
     */
    $ra = $pdo->prepare("
        SELECT a.name
        FROM request_amenities ra
        JOIN amenities a
            ON a.id=ra.amenity_id
        WHERE ra.request_id=?
    ");

    $ra->execute([
        $requestId
    ]);

    $req['_amenities'] =
        array_column(
            $ra->fetchAll(
                PDO::FETCH_ASSOC
            ),
            'name'
        );


    /*
     * Extract additional fields (priorities for investment)
     */
    $req['_additional'] = m5json($req['additional'] ?? '');
    $req['_no_priority'] = !empty($req['_additional']['no_priority']) ? 1 : 0;
    $req['_priorities'] = [
        $req['_additional']['priority_1'] ?? '',
        $req['_additional']['priority_2'] ?? '',
        $req['_additional']['priority_3'] ?? ''
    ];


    /*
     * Published ads
     */
    $ads = m5LoadPublishedAds();

    if (!$ads) {
        return [];
    }


    /*
     * Disliked ads
     */
    $dislikedAds = [];

    try {

        $dq = $pdo->prepare("
            SELECT rm.ad_id
            FROM request_matches rm
            JOIN request_match_feedback f
                ON f.request_match_id = rm.id
            WHERE rm.request_id = ?
              AND f.feedback = 'dislike'
        ");

        $dq->execute([
            $requestId
        ]);

        foreach (
            $dq->fetchAll(PDO::FETCH_COLUMN)
            as $aid
        ) {

            $dislikedAds[
                (string)$aid
            ] = true;
        }

    } catch (Throwable $e) {

        /*
         * Ignore feedback errors.
         */
    }


    /*
     * User requested transaction
     */
    $reqTx = m5canonicalTx(
        $req['transaction_type'] ?? ''
    );


    /*
     * User requested property type
     */
    $reqPt = m5canonicalProperty(
        $req['property_type'] ?? ''
    );


    /*
     * Price constraints
     */
    $hasPriceConstraint =
        (
            m5num(
                $req['min_price'] ?? null
            ) !== null
            ||
            m5num(
                $req['max_price'] ?? null
            ) !== null
            ||
            m5num(
                $req['min_deposit'] ?? null
            ) !== null
            ||
            m5num(
                $req['max_deposit'] ?? null
            ) !== null
            ||
            m5num(
                $req['min_rent'] ?? null
            ) !== null
            ||
            m5num(
                $req['max_rent'] ?? null
            ) !== null
        );


    /*
     * Scored results
     */
    $scored = [];


    foreach ($ads as $ad) {

        /*
         * -------------------------------------------------
         * Disliked
         * -------------------------------------------------
         */

        if (
            !empty(
                $dislikedAds[
                    (string)$ad['id']
                ]
            )
        ) {
            continue;
        }


        /*
         * -------------------------------------------------
         * HARD FILTER #1
         * TRANSACTION TYPE
         *
         * If user selected a transaction type,
         * ad MUST have exactly the same canonical type.
         * -------------------------------------------------
         */

        if ($reqTx !== '') {

            $adTx = m5canonicalTx(
                $ad['transaction_type'] ?? ''
            );

            // --- تغییر برای سرمایه‌گذاری ---
            if ($reqTx === 'سرمایه‌گذاری') {
                // سرمایه‌گذاری: فروش و پیش‌فروش قابل قبول هستند
                if (!in_array($adTx, ['فروش', 'پیش فروش'], true)) {
                    continue;
                }
            } else {
                // سایر نوع معاملات: تطابق دقیق
                if ($adTx !== $reqTx) {
                    continue;
                }
            }
        }


        /*
         * -------------------------------------------------
         * HARD FILTER #2
         * PROPERTY TYPE
         *
         * If user selected a property type,
         * ad MUST have exactly the same canonical type.
         * -------------------------------------------------
         */

        // --- تغییر برای سرمایه‌گذاری: فیلتر نوع ملک حذف می‌شود ---
        if ($reqPt !== '' && $reqTx !== 'سرمایه‌گذاری') {
            $adPt = m5canonicalProperty(
                $ad['property_type'] ?? ''
            );
            if ($adPt !== $reqPt) {
                continue;
            }
        }


        /*
         * -------------------------------------------------
         * HARD FILTER #3
         * PRICE
         *
         * If user specified any price limitation,
         * ad MUST be inside the requested price range.
         * -------------------------------------------------
         */

        if ($hasPriceConstraint) {

            if (
                !m5PriceHardMatch(
                    $req,
                    $ad,
                    $reqTx
                )
            ) {
                continue;
            }
        }


        /*
         * -------------------------------------------------
         * IMPORTANT:
         *
         * NO HARD FILTER FOR:
         * - area
         * - location
         * - amenities
         *
         * These only affect the final score.
         * -------------------------------------------------
         */


        /*
         * Calculate score
         */
        $s = m5ScoreRequestAd(
            $req,
            $ad
        );


        /*
         * Since transaction/property/price have already
         * passed the HARD filters, this item is valid.
         *
         * ===== تغییر جدید برای سرمایه‌گذاری =====
         * آستانه امتیاز برای سرمایه‌گذاری به ۲۰ کاهش یافته
         * تا آگهی‌های پیش‌فروش با امتیاز پایین‌تر نیز نمایش داده شوند.
         */
        $threshold = ($reqTx === 'سرمایه‌گذاری') ? 20 : 30;

        if ($s['percent'] >= $threshold) {

            $scored[] = [
                'ad' => $ad,
                'score' => $s
            ];
        }
    }


    /*
     * Sort by score descending
     */
    usort(
        $scored,
        static function ($a, $b) {

            if (
                $a['score']['percent']
                !==
                $b['score']['percent']
            ) {

                return
                    $b['score']['percent']
                    <=>
                    $a['score']['percent'];
            }

            return strcmp(
                (string)$a['ad']['id'],
                (string)$b['ad']['id']
            );
        }
    );


    /*
     * Select maximum 12
     *
     * برای سرمایه‌گذاری فقط ۱۲ فایل اول کافی نیست؛
     * چون ممکن است همه آن‌ها «فروش» باشند و هیچ «پیش‌فروشی»
     * به مرحله ترکیب‌سازی نرسد.
     * بنابراین برای سرمایه‌گذاری تا ۳ فایل پیش‌فروشِ واجد شرایط
     * را در مجموعه نهایی تضمین می‌کنیم (هر تعداد که واقعاً موجود باشد).
     */
    if ($reqTx === 'سرمایه‌گذاری') {
        $selected = array_slice($scored, 0, 12);

        $preSales = array_values(array_filter(
            $scored,
            static fn($item) => m5canonicalTx($item['ad']['transaction_type'] ?? '') === 'پیش فروش'
        ));

        $targetPreSales = min(3, count($preSales), 12);
        $selectedPreSales = 0;

        foreach ($selected as $item) {
            if (m5canonicalTx($item['ad']['transaction_type'] ?? '') === 'پیش فروش') {
                $selectedPreSales++;
            }
        }

        if ($targetPreSales > $selectedPreSales) {
            $selectedIds = [];
            foreach ($selected as $item) {
                $selectedIds[(string)$item['ad']['id']] = true;
            }

            $replaceIndexes = [];
            for ($i = count($selected) - 1; $i >= 0; $i--) {
                if (m5canonicalTx($selected[$i]['ad']['transaction_type'] ?? '') === 'فروش') {
                    $replaceIndexes[] = $i;
                }
            }

            $replacementIndex = 0;
            foreach ($preSales as $preSale) {
                if ($selectedPreSales >= $targetPreSales) {
                    break;
                }

                $preId = (string)$preSale['ad']['id'];
                if (isset($selectedIds[$preId])) {
                    continue;
                }

                if (!isset($replaceIndexes[$replacementIndex])) {
                    break;
                }

                $idx = $replaceIndexes[$replacementIndex++];
                $oldId = (string)$selected[$idx]['ad']['id'];
                unset($selectedIds[$oldId]);

                $selected[$idx] = $preSale;
                $selectedIds[$preId] = true;
                $selectedPreSales++;
            }

            usort(
                $selected,
                static function ($a, $b) {
                    if ($a['score']['percent'] !== $b['score']['percent']) {
                        return $b['score']['percent'] <=> $a['score']['percent'];
                    }
                    return strcmp((string)$a['ad']['id'], (string)$b['ad']['id']);
                }
            );
        }
    } else {
        $selected = array_slice($scored, 0, 12);
    }


    /*
     * Save matches
     */
    $up = $pdo->prepare("
        INSERT INTO request_matches
        (
            request_id,
            ad_id,
            match_percent,
            matched_transaction,
            matched_property_type,
            location_score,
            area_score,
            budget_score,
            amenities_score,
            is_notified,
            created_at
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            NOW()
        )
        ON DUPLICATE KEY UPDATE

            match_percent =
                VALUES(match_percent),

            matched_transaction =
                VALUES(matched_transaction),

            matched_property_type =
                VALUES(matched_property_type),

            location_score =
                VALUES(location_score),

            area_score =
                VALUES(area_score),

            budget_score =
                VALUES(budget_score),

            amenities_score =
                VALUES(amenities_score)
    ");


    $seen = [];


    foreach ($selected as $item) {

        $ad = $item['ad'];
        $s = $item['score'];

        $seen[
            (string)$ad['id']
        ] = 1;

        // =============== تغییر اعمال شده (راه‌حل شماره ۳) ===============
        // برای سرمایه‌گذاری، صرف‌نظر از امتیاز، is_notified = 1 قرار می‌دهیم
        $isNotified = ($s['percent'] >= 70 || $reqTx === 'سرمایه‌گذاری') ? 1 : 0;
        // ================================================================

        $up->execute([
            $requestId,
            $ad['id'],
            $s['percent'],
            $s['tx'],
            $s['pt'],
            $s['loc'],
            $s['area'],
            $s['budget'],
            $s['amen'],
            $isNotified
        ]);
    }


    /*
     * Remove stale matches.
     *
     * BUT never delete a match that has feedback.
     */
    $existing = $pdo->prepare("
        SELECT
            rm.id,
            rm.ad_id,

            EXISTS(
                SELECT 1
                FROM request_match_feedback f
                WHERE f.request_match_id = rm.id
            ) AS has_feedback

        FROM request_matches rm

        WHERE rm.request_id = ?
    ");

    $existing->execute([
        $requestId
    ]);


    $del = $pdo->prepare("
        DELETE FROM request_matches
        WHERE id=?
    ");


    foreach (
        $existing->fetchAll(
            PDO::FETCH_ASSOC
        ) as $m
    ) {

        if (
            empty(
                $seen[
                    (string)$m['ad_id']
                ]
            )
            &&
            (int)$m['has_feedback'] === 0
        ) {

            $del->execute([
                (int)$m['id']
            ]);
        }
    }


    return $seen;
}