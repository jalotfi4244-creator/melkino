<?php

// ============================================================
// روی سرور واقعی خطاها فقط لاگ می‌شوند، نه چاپ در صفحه (تا خروجی
// JSON این فایل با یک Warning/Notice ساده خراب نشود) و دیگر در یک
// فایل لاگ عمومیِ داخل ریشه‌ی سایت نوشته نمی‌شوند.
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/match-engine.php';

global $pdo;

$identity = melkinoCurrentIdentity(
    $_GET['telegram_id'] ?? $_POST['telegram_id'] ?? null
);


// ============================================================
// HELPER FUNCTIONS
// ============================================================

function mm8Digits($v): string
{
    return strtr(
        (string)$v,
        [
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
        ]
    );
}


function mm8Phone($v): string
{
    return preg_replace('/\D+/', '', mm8Digits($v));
}


function mm8OwnRequest(array $r, array $id): bool
{
    if (
        !empty($id['user_id']) &&
        (int)($r['user_id'] ?? 0) === (int)$id['user_id']
    ) {
        return true;
    }

    $t1 = trim((string)($r['telegram_id'] ?? ''));
    $t2 = trim((string)($id['telegram_id'] ?? ''));

    if (
        $t1 !== '' &&
        $t2 !== '' &&
        $t1 === $t2
    ) {
        return true;
    }

    $p1 = mm8Phone($r['phone'] ?? '');
    $p2 = mm8Phone($id['phone'] ?? '');

    return (
        $p1 !== '' &&
        $p2 !== '' &&
        $p1 === $p2
    );
}


function mm8OwnRequests(array $id): array
{
    global $pdo;

    $rows = $pdo
        ->query(
            'SELECT * FROM property_requests ORDER BY created_at DESC, id DESC'
        )
        ->fetchAll(PDO::FETCH_ASSOC);

    return array_values(
        array_filter(
            $rows,
            fn($r) => mm8OwnRequest($r, $id)
        )
    );
}


function mm8Money($v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }

    $s = mm8Digits($v);

    $s = str_replace(
        [',', '٬', '،', ' ', 'تومان', 'ریال'],
        '',
        $s
    );

    $s = preg_replace(
        '/[^0-9.\-]/u',
        '',
        $s
    );

    if (
        $s === '' ||
        !is_numeric($s)
    ) {
        return null;
    }

    $n = (float)$s;

    if ($n <= 0) {
        return null;
    }

    return number_format(
        $n,
        0,
        '.',
        ','
    );
}


function mm8Num($v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }

    $s = mm8Digits($v);

    $s = str_replace(
        [',', '٬', '،', ' '],
        '',
        $s
    );

    $s = preg_replace(
        '/[^0-9.\-]/u',
        '',
        $s
    );

    if (
        $s === '' ||
        !is_numeric($s)
    ) {
        return null;
    }

    $n = (float)$s;

    if ($n <= 0) {
        return null;
    }

    return rtrim(
        rtrim(
            number_format(
                $n,
                2,
                '.',
                ','
            ),
            '0'
        ),
        '.'
    );
}


function mm8Json($v): array
{
    if (is_array($v)) {
        return $v;
    }

    $d = json_decode(
        (string)$v,
        true
    );

    return is_array($d) ? $d : [];
}


function mm8Label($key): string
{
    static $map = [

        'area' => 'متراژ',
        'built_area' => 'زیربنا',
        'land_area' => 'مساحت زمین',
        'garden_area' => 'مساحت باغ',
        'rooms' => 'تعداد اتاق',
        'floor' => 'طبقه',
        'year' => 'سال ساخت',
        'unit_count' => 'تعداد واحد',
        'units' => 'تعداد واحد',
        'bedrooms' => 'تعداد خواب',
        'bathrooms' => 'تعداد سرویس',
        'parking_count' => 'تعداد پارکینگ',
        'parking' => 'پارکینگ',
        'storage' => 'انباری',
        'elevator' => 'آسانسور',
        'balcony' => 'بالکن / تراس',
        'cabinet' => 'کابینت',
        'flooring' => 'کف',
        'cooling' => 'سیستم سرمایش',
        'heating' => 'سیستم گرمایش',
        'document_status' => 'وضعیت سند',
        'document_type' => 'نوع سند',
        'usage' => 'نوع کاربری',
        'land_usage' => 'نوع کاربری',
        'front_width' => 'عرض بر',
        'length' => 'طول',
        'width' => 'عرض',
        'direction' => 'جهت ملک',
        'shape' => 'شکل زمین',
        'partition_status' => 'وضعیت تفکیک',
        'front_count' => 'تعداد بر',
        'master' => 'اتاق مستر',
        'guard' => 'نگهبانی',
        'pool' => 'استخر',
        'sauna' => 'سونا',
        'jacuzzi' => 'جکوزی',
        'roof_garden' => 'روف گاردن',
        'laundry' => 'لاندری روم',
        'closet' => 'کلوزت',
        'private_yard' => 'حیاط اختصاصی',
        'kitchen' => 'مطبخ',
        'deal_type' => 'نوع معامله',
        'tree_age' => 'سن درختان',
        'tree_count' => 'تعداد درختان',
        'tree_types' => 'نوع درختان',
        'irrigation_type' => 'نوع آبیاری',
        'water_source' => 'منبع آب',
        'water_share' => 'سهم آب',
        'has_well' => 'چاه آب',
        'has_pond' => 'استخر ذخیره آب',
        'has_building' => 'بنا / خانه باغ',
        'building_area' => 'متراژ بنا',
        'land_type' => 'نوع زمین',
        'land_width' => 'عرض زمین',
        'land_length' => 'طول زمین',
        'land_front_width' => 'عرض بر',
        'land_blocks' => 'تعداد بر',
        'land_direction' => 'جهت ملک',
        'land_shape' => 'شکل زمین',
        'land_deed_status' => 'وضعیت سند',
        'land_deed_type' => 'نوع سند',
        'land_division_status' => 'وضعیت تفکیک',
        'land_setback_status' => 'وضعیت عقب‌نشینی',
        'land_ownership' => 'وضعیت مالکیت',
        'office_floor' => 'طبقه',
        'office_units_per_floor' => 'تعداد واحد در طبقه',
        'office_rooms' => 'تعداد اتاق',
        'office_year' => 'سال ساخت',
        'office_condition' => 'وضعیت واحد',
        'office_orientation' => 'موقعیت واحد',
        'office_usage' => 'کاربری',
        'front' => 'بر مغازه',
        'wall' => 'پوشش دیوار',
        'location_type' => 'موقعیت',
        'jobs_comm' => 'مناسب برای مشاغل',
        'well_name' => 'نام چاه آب',
        'unit' => 'شماره واحد',
        'total_units' => 'تعداد کل واحدها',

        'area_apt' => 'متراژ',
        'floor_apt' => 'طبقه',
        'rooms_apt' => 'تعداد اتاق',
        'year_apt' => 'سال ساخت',
        'flooring_apt' => 'کف',
        'cabinet_apt' => 'کابینت',
        'cooling_apt' => 'سرمایش',
        'heating_apt' => 'گرمایش',

        'land_villa' => 'متراژ زمین',
        'built_villa' => 'زیربنا',
        'rooms_villa' => 'تعداد اتاق',
        'year_villa' => 'سال ساخت',
        'flooring_villa' => 'کف',
        'cabinet_villa' => 'کابینت',
        'cooling_villa' => 'سرمایش',
        'heating_villa' => 'گرمایش',

        'area_comm' => 'متراژ',
        'front_comm' => 'بر مغازه',
        'floor_comm' => 'کف',
        'wall_comm' => 'دیوار',
        'cabinet_comm' => 'کابینت',
        'cooling_comm' => 'سرمایش',
        'heating_comm' => 'گرمایش',

        'office_area' => 'متراژ واحد'
    ];

    if (isset($map[$key])) {
        return $map[$key];
    }

    $key = str_replace(
        ['_', '-'],
        ' ',
        (string)$key
    );

    return trim($key);
}


function mm8DisplayValue($v): string
{
    if (is_bool($v)) {
        return $v ? 'دارد' : 'ندارد';
    }

    if (is_array($v)) {
        return implode(
            '، ',
            array_map(
                'strval',
                $v
            )
        );
    }

    if ($v === null) {
        return '';
    }

    return trim((string)$v);
}


function mm8RawAmount($v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }

    $x = mm8Digits($v);

    $x = str_replace(
        [',', '٬', '،', ' ', 'تومان', 'ریال'],
        '',
        $x
    );

    $x = preg_replace(
        '/[^0-9.\-]/u',
        '',
        $x
    );

    if (
        $x === '' ||
        !is_numeric($x)
    ) {
        return null;
    }

    $n = (float)$x;

    return $n > 0 ? $n : null;
}


function mm8IsInvestment($v): bool
{
    $x = strtr(
        trim((string)$v),
        [
            'ي' => 'ی',
            'ك' => 'ک',
            '‌' => ' '
        ]
    );

    $x = preg_replace(
        '/\s+/u',
        ' ',
        $x
    );

    $x = str_replace(
        ' ',
        '',
        $x
    );

    return
        strpos($x, 'سرمایهگذاری') !== false ||
        strpos($x, 'سرمایهگزاری') !== false ||
        strpos($x, 'سرمایگزاری') !== false ||
        strpos($x, 'سرمایهگزاری') !== false;
}


// ============================================================
// ACTIONS
// ============================================================

if (isset($_GET['action'])) {

    if (!$pdo instanceof PDO) {
        melkinoJsonResponse(
            [
                'success' => false,
                'message' => 'اتصال دیتابیس برقرار نیست.'
            ],
            500
        );
    }


    // ========================================================
    // REGENERATE COMBINATIONS
    // ========================================================

    if ($_GET['action'] === 'regenerate_combos') {

        $requestId = (int)(
            $_POST['request_id'] ?? 0
        );

        if (!$requestId) {
            melkinoJsonResponse(
                [
                    'success' => false,
                    'message' => 'شناسه درخواست نامعتبر است.'
                ],
                422
            );
        }

        if (
            empty($identity['user_id']) &&
            empty($identity['telegram_id'])
        ) {
            melkinoJsonResponse(
                [
                    'success' => false,
                    'message' => 'کاربر شناسایی نشد.'
                ],
                401
            );
        }

        $q = $pdo->prepare(
            'SELECT * FROM property_requests WHERE id=? LIMIT 1'
        );

        $q->execute([$requestId]);

        $req = $q->fetch(
            PDO::FETCH_ASSOC
        );

        if (
            !$req ||
            !mm8OwnRequest(
                $req,
                $identity
            )
        ) {
            melkinoJsonResponse(
                [
                    'success' => false,
                    'message' => 'این درخواست برای حساب شما نیست.'
                ],
                403
            );
        }

        $additional = mm8Json(
            $req['additional'] ?? ''
        );

        unset(
            $additional['combinations']
        );

        $additional['combinations_last_generated'] = 0;

        $newAdditional = json_encode(
            $additional,
            JSON_UNESCAPED_UNICODE
        );

        $upd = $pdo->prepare(
            'UPDATE property_requests SET additional=? WHERE id=?'
        );

        $upd->execute(
            [
                $newAdditional,
                $requestId
            ]
        );

        melkinoJsonResponse(
            [
                'success' => true,
                'message' => 'ترکیب‌ها با موفقیت برای تولید مجدد آماده شدند.'
            ]
        );
    }


    // ========================================================
    // DATA
    // ========================================================

    if ($_GET['action'] === 'data') {

        try {

            $requests = mm8OwnRequests(
                $identity
            );

            try {
                m5EnsureFeedbackTable();
            } catch (Throwable $e) {
            }

            $feedback = [];

            if (!empty($identity['user_id'])) {

                $q = $pdo->prepare(
                    'SELECT request_match_id, feedback
                     FROM request_match_feedback
                     WHERE user_id=?'
                );

                $q->execute(
                    [$identity['user_id']]
                );

                foreach (
                    $q->fetchAll(PDO::FETCH_ASSOC)
                    as $f
                ) {
                    $feedback[
                        (int)$f['request_match_id']
                    ] = (string)$f['feedback'];
                }

            } elseif (!empty($identity['telegram_id'])) {

                $q = $pdo->prepare(
                    'SELECT request_match_id, feedback
                     FROM request_match_feedback
                     WHERE telegram_id=?'
                );

                $q->execute(
                    [$identity['telegram_id']]
                );

                foreach (
                    $q->fetchAll(PDO::FETCH_ASSOC)
                    as $f
                ) {
                    $feedback[
                        (int)$f['request_match_id']
                    ] = (string)$f['feedback'];
                }
            }


            $groups = [];


            // ====================================================
            // EACH REQUEST
            // ====================================================

            foreach ($requests as $r) {

                try {
                    m5EnsureRequestMatches(
                        (int)$r['id']
                    );
                } catch (Throwable $e) {
                }


                // =================================================
                // GET MATCHES
                // =================================================

                $rm = $pdo->prepare(
                    "SELECT
                        rm.id AS match_id,
                        rm.ad_id,
                        rm.match_percent,

                        a.title,
                        a.property_type,
                        a.transaction_type,
                        a.location,
                        a.address,
                        a.area,
                        a.land_area,
                        a.built_area,
                        a.rooms,
                        a.floor,
                        a.year,

                        a.price_sell,
                        a.price_condition,
                        a.deposit,
                        a.rent_monthly,
                        a.full_rent,
                        a.total_price,
                        a.display_price,
                        a.down_payment,
                        a.payment_terms,

                        a.description,
                        a.tags,
                        a.property_details,

                        (
                            SELECT GROUP_CONCAT(
                                am.name
                                ORDER BY am.id
                                SEPARATOR '||'
                            )
                            FROM ad_amenities aa
                            JOIN amenities am
                                ON am.id=aa.amenity_id
                            WHERE
                                aa.ad_id=a.id
                                AND am.is_active=1
                        ) AS amenities_csv,

                        (
                            SELECT i.filename
                            FROM images i
                            WHERE
                                i.ad_id=a.id
                                AND i.is_selected=1
                                AND i.publish_publicly=1
                            ORDER BY
                                i.is_primary DESC,
                                i.sort_order ASC,
                                i.id ASC
                            LIMIT 1
                        ) AS image,

                        (
                            SELECT GROUP_CONCAT(
                                i.filename
                                ORDER BY
                                    i.is_primary DESC,
                                    i.sort_order ASC,
                                    i.id ASC
                                SEPARATOR '||'
                            )
                            FROM images i
                            WHERE
                                i.ad_id=a.id
                                AND i.is_selected=1
                                AND i.publish_publicly=1
                        ) AS images_csv

                    FROM request_matches rm

                    JOIN ads a
                        ON a.id=rm.ad_id
                        AND a.status='published'

                    WHERE rm.request_id=?

                    ORDER BY
                        rm.match_percent DESC,
                        rm.id DESC"
                );

                $rm->execute(
                    [(int)$r['id']]
                );


                $matches = [];
                $matchesWithPrice = [];


                // =================================================
                // PREPARE MATCH OBJECTS
                // =================================================

                foreach (
                    $rm->fetchAll(PDO::FETCH_ASSOC)
                    as $row
                ) {

                    if (
                        ($feedback[
                            (int)$row['match_id']
                        ] ?? '') === 'dislike'
                    ) {
                        continue;
                    }


                    $details = mm8Json(
                        $row['property_details']
                    );


                    $images = array_values(
                        array_filter(
                            explode(
                                '||',
                                (string)(
                                    $row['images_csv'] ?? ''
                                )
                            )
                        )
                    );


                    $price = null;
                    $priceValue = null;

                    $tx = trim(
                        (string)(
                            $row['transaction_type'] ?? ''
                        )
                    );


                    // ---------------------------------------------
                    // SALE
                    // ---------------------------------------------

                    if ($tx === 'فروش') {

                        $x = mm8Money(
                            $row['price_sell']
                        );

                        if ($x) {

                            $price =
                                "قیمت فروش: {$x} تومان";

                            $priceValue =
                                (float)str_replace(
                                    ',',
                                    '',
                                    $x
                                );
                        }


                    // ---------------------------------------------
                    // FULL RENT
                    // ---------------------------------------------

                    } elseif ($tx === 'رهن کامل') {

                        $x = mm8Money(
                            $row['full_rent']
                            ?: $row['deposit']
                        );

                        if ($x) {

                            $price =
                                "رهن کامل: {$x} تومان";

                            $priceValue =
                                (float)str_replace(
                                    ',',
                                    '',
                                    $x
                                );
                        }


                    // ---------------------------------------------
                    // RENT
                    // ---------------------------------------------

                    } elseif (
                        $tx === 'اجاره' ||
                        $tx === 'رهن و اجاره'
                    ) {

                        $d = mm8Money(
                            $row['deposit']
                        );

                        $rr = mm8Money(
                            $row['rent_monthly']
                        );

                        $parts = [];

                        if ($d) {
                            $parts[] =
                                "ودیعه: {$d} تومان";
                        }

                        if ($rr) {
                            $parts[] =
                                "اجاره: {$rr} تومان";
                        }

                        if ($parts) {
                            $price =
                                implode(
                                    ' | ',
                                    $parts
                                );
                        }

                        if ($d) {
                            $priceValue =
                                (float)str_replace(
                                    ',',
                                    '',
                                    $d
                                );
                        }


                    // ---------------------------------------------
                    // PRE-SALE
                    // ---------------------------------------------

                    } elseif (
                        $tx === 'پیش‌فروش' ||
                        $tx === 'پیش فروش'
                    ) {

                        $x = mm8Money(
                            $row['display_price']
                            ?? $row['total_price']
                        );

                        if ($x) {

                            $price =
                                "قیمت کل: {$x} تومان";

                            $priceValue =
                                (float)str_replace(
                                    ',',
                                    '',
                                    $x
                                );
                        }
                    }


                    $core = [

                        'match_id' =>
                            (int)$row['match_id'],

                        'ad_id' =>
                            $row['ad_id'],

                        'match_percent' =>
                            (int)$row['match_percent'],

                        'title' =>
                            $row['title']
                            ?: 'ملک مناسب',

                        'property_type' =>
                            $row['property_type'],

                        'transaction_type' =>
                            $row['transaction_type'],

                        'location' =>
                            $row['location'],

                        'area' =>
                            $row['area']
                            ?: (
                                $row['built_area']
                                ?: (
                                    $row['land_area']
                                    ?: (
                                        $details['area']
                                        ?? $details['built_area']
                                        ?? $details['land_area']
                                        ?? ''
                                    )
                                )
                            ),

                        'built_area' =>
                            $row['built_area']
                            ?: (
                                $details['built_area']
                                ?? ''
                            ),

                        'land_area' =>
                            $row['land_area']
                            ?: (
                                $details['land_area']
                                ?? ''
                            ),

                        'rooms' =>
                            $row['rooms']
                            ?: (
                                $details['rooms']
                                ?? ''
                            ),

                        'floor' =>
                            $row['floor']
                            ?: (
                                $details['floor']
                                ?? ''
                            ),

                        'year' =>
                            $row['year']
                            ?: (
                                $details['year']
                                ?? ''
                            ),

                        'price' =>
                            $price,

                        'price_value' =>
                            $priceValue,

                        'description' =>
                            $row['description'],

                        'images' =>
                            $images,

                        'details' =>
                            $details,

                        'tags' =>
                            mm8Json(
                                $row['tags'] ?? []
                            ),

                        'amenities' =>
                            array_values(
                                array_filter(
                                    explode(
                                        '||',
                                        (string)(
                                            $row['amenities_csv']
                                            ?? ''
                                        )
                                    )
                                )
                            )
                    ];


                    $matches[] = $core;


                    if (
                        $priceValue !== null &&
                        $priceValue > 0
                    ) {

                        $matchesWithPrice[] =
                            $core;
                    }
                }


                // =================================================
                // INVESTMENT ENGINE — تولید ترکیب‌های چندملکی
                // =================================================

                $additional = mm8Json(
                    $r['additional'] ?? ''
                );

                $dislikedCombos =
                    (
                        !empty($additional['disliked_combos']) &&
                        is_array($additional['disliked_combos'])
                    )
                    ? $additional['disliked_combos']
                    : [];


                $isInvestmentRequest =
                    mm8IsInvestment(
                        $r['transaction_type'] ?? ''
                    );


                $combinations = [];


                // =================================================
                // PRIORITIES
                // =================================================

                $prioritiesList = [];

                $noPriority = false;


                if ($isInvestmentRequest) {

                    $noPriorityValue =
                        $additional['no_priority']
                        ?? '';

                    $noPriority =
                        $noPriorityValue === 'بله' ||
                        $noPriorityValue === '1' ||
                        $noPriorityValue === 1 ||
                        $noPriorityValue === true;


                    if (!$noPriority) {

                        foreach (
                            [
                                'priority_1',
                                'priority_2',
                                'priority_3'
                            ]
                            as $priorityKey
                        ) {

                            $ptype =
                                trim(
                                    (string)(
                                        $additional[
                                            $priorityKey
                                        ] ?? ''
                                    )
                                );

                            if ($ptype === '') {
                                continue;
                            }

                            if (
                                !in_array(
                                    $ptype,
                                    $prioritiesList,
                                    true
                                )
                            ) {

                                $prioritiesList[] =
                                    $ptype;
                            }
                        }
                    }
                }


                error_log(
                    "REQUEST {$r['id']} PRIORITIES: " .
                    (
                        !empty($prioritiesList)
                            ? implode(
                                ' | ',
                                $prioritiesList
                            )
                            : 'NONE'
                    )
                );


                // =================================================
                // BUDGET
                // =================================================

                $minBudget =
                    mm8RawAmount(
                        $r['min_price'] ?? null
                    ) ?? 0;

                $maxBudget =
                    mm8RawAmount(
                        $r['max_price'] ?? null
                    ) ?? 0;


                if ($maxBudget <= 0) {
                    $maxBudget = PHP_INT_MAX;
                }


                error_log(
                    "REQUEST {$r['id']} BUDGET => MIN={$minBudget}, MAX={$maxBudget}"
                );


                // =================================================
                // INVESTMENT POOL (فقط فروش و پیش‌فروش)
                // =================================================

                $investmentPool = [];


                if ($isInvestmentRequest) {

                    foreach (
                        $matchesWithPrice
                        as $item
                    ) {

                        $price =
                            (float)(
                                $item['price_value']
                                ?? 0
                            );


                        if ($price <= 0) {
                            continue;
                        }


                        $investmentPool[] =
                            $item;
                    }
                }


                error_log(
                    "REQUEST {$r['id']} INVESTMENT POOL (ALL) = " .
                    count($investmentPool) .
                    " items"
                );

                // لاگ قیمت فایل‌های موجود در investmentPool
                $poolPrices = array_map(
                    function($item) {
                        return (float)($item['price_value'] ?? 0);
                    },
                    $investmentPool
                );
                sort($poolPrices);
                error_log(
                    "REQUEST {$r['id']} INVESTMENT POOL PRICES: " .
                    implode(', ', $poolPrices)
                );


                // =================================================
                // ۱. تفکیک فایل‌ها بر اساس اولویت‌ها
                // =================================================

                $priorityGroups = [];

                if (!$noPriority && !empty($prioritiesList)) {
                    foreach ($prioritiesList as $ptype) {
                        $group = array_filter(
                            $investmentPool,
                            function ($item) use ($ptype) {
                                return trim($item['property_type'] ?? '') === trim($ptype);
                            }
                        );
                        // مرتب‌سازی هر گروه بر اساس امتیاز تطابق (نزولی)
                        usort($group, function ($a, $b) {
                            return ($b['match_percent'] ?? 0) <=> ($a['match_percent'] ?? 0);
                        });
                        $priorityGroups[] = array_values($group);
                    }
                } else {
                    // بدون اولویت: همه در یک گروه
                    $priorityGroups[] = $investmentPool;
                }

                // حذف گروه‌های خالی
                $priorityGroups = array_values(array_filter($priorityGroups, function($g) {
                    return !empty($g);
                }));

                // لاگ تعداد و اندازه گروه‌ها
                $groupSizes = array_map('count', $priorityGroups);
                error_log(
                    "REQUEST {$r['id']} PRIORITY GROUPS: " .
                    count($priorityGroups) .
                    " groups, sizes: " .
                    implode(', ', $groupSizes)
                );

                // =================================================
                // ۲. تابع‌های کمکی برای تولید ترکیب‌ها
                // =================================================

                // تولید ترکیب‌های k تایی از یک مجموعه
                function getCombinations($items, $k) {
                    $result = [];
                    $n = count($items);
                    if ($k > $n) return $result;
                    $indices = range(0, $k - 1);
                    while (true) {
                        $combo = [];
                        foreach ($indices as $idx) {
                            $combo[] = $items[$idx];
                        }
                        $result[] = $combo;
                        $i = $k - 1;
                        while ($i >= 0 && $indices[$i] == $n - $k + $i) $i--;
                        if ($i < 0) break;
                        $indices[$i]++;
                        for ($j = $i + 1; $j < $k; $j++) {
                            $indices[$j] = $indices[$j - 1] + 1;
                        }
                    }
                    return $result;
                }

                // تابع ساخت شیء ترکیب
                $buildCombo = function (array $items) {
                    $sum = 0;
                    $score = 0;
                    foreach ($items as $item) {
                        $sum += (float)($item['price_value'] ?? 0);
                        $score += (float)($item['match_percent'] ?? 0);
                    }
                    $count = count($items);
                    if ($count <= 0) return null;
                    return [
                        'items' => $items,
                        'total_price' => number_format($sum, 0, '.', ','),
                        'total_score' => $score / $count,
                        'count' => $count,
                        'summary' => implode(' · ', array_map(fn($it) => ($it['transaction_type'] ?? '') . ' ' . ($it['property_type'] ?? ''), $items))
                    ];
                };

                // ===== تغییر: حذف شرط حداقل بودجه برای ترکیب‌ها =====
                $addCombo = function($items) use (&$generated, $buildCombo, $maxBudget) {
                    $count = count($items);
                    if ($count < 2) return;
                    $sum = 0;
                    foreach ($items as $item) {
                        $sum += (float)($item['price_value'] ?? 0);
                    }
                    // فقط شرط سقف بودجه (حداقل بودجه برای ترکیب‌ها اعمال نمی‌شود)
                    if ($sum > $maxBudget) {
                        return;
                    }
                    $combo = $buildCombo($items);
                    if ($combo) {
                        $generated[] = $combo;
                    }
                };

                $generated = [];
                $maxSize = 4;

                // =================================================
                // ۳. تولید ترکیب‌ها از اولویت اول (فقط)
                // =================================================

                if (!empty($priorityGroups[0])) {
                    $group1 = $priorityGroups[0];
                    error_log("REQUEST {$r['id']} GROUP1 SIZE: " . count($group1));
                    for ($s = 2; $s <= $maxSize; $s++) {
                        $combos = getCombinations($group1, $s);
                        error_log("REQUEST {$r['id']} COMBOS SIZE $s: " . count($combos) . " combinations");
                        foreach ($combos as $comboItems) {
                            $addCombo($comboItems);
                        }
                    }
                }

                // =================================================
                // ۴. تولید ترکیب‌های مختلط از اولویت اول + دوم
                // =================================================

                if (count($priorityGroups) >= 2 && !empty($priorityGroups[0]) && !empty($priorityGroups[1])) {
                    $group1 = $priorityGroups[0];
                    $group2 = $priorityGroups[1];
                    error_log("REQUEST {$r['id']} GROUP1+2: group1=" . count($group1) . ", group2=" . count($group2));
                    for ($s = 2; $s <= $maxSize; $s++) {
                        for ($a = 1; $a < $s; $a++) {
                            $b = $s - $a;
                            if ($a > count($group1) || $b > count($group2)) continue;
                            $combos1 = getCombinations($group1, $a);
                            $combos2 = getCombinations($group2, $b);
                            $totalCombos = count($combos1) * count($combos2);
                            error_log("REQUEST {$r['id']} MIXED size=$s, a=$a, b=$b, total=$totalCombos");
                            foreach ($combos1 as $c1) {
                                foreach ($combos2 as $c2) {
                                    $comboItems = array_merge($c1, $c2);
                                    $addCombo($comboItems);
                                }
                            }
                        }
                    }
                }

                // =================================================
                // ۵. تولید ترکیب‌های مختلط از اولویت اول + دوم + سوم
                // =================================================

                if (count($priorityGroups) >= 3 && !empty($priorityGroups[0]) && !empty($priorityGroups[1]) && !empty($priorityGroups[2])) {
                    $group1 = $priorityGroups[0];
                    $group2 = $priorityGroups[1];
                    $group3 = $priorityGroups[2];
                    error_log("REQUEST {$r['id']} GROUP1+2+3: " . count($group1) . ", " . count($group2) . ", " . count($group3));
                    for ($s = 3; $s <= $maxSize; $s++) {
                        for ($a = 1; $a <= $s - 2; $a++) {
                            for ($b = 1; $b <= $s - $a - 1; $b++) {
                                $c = $s - $a - $b;
                                if ($c < 1) continue;
                                if ($a > count($group1) || $b > count($group2) || $c > count($group3)) continue;
                                $combos1 = getCombinations($group1, $a);
                                $combos2 = getCombinations($group2, $b);
                                $combos3 = getCombinations($group3, $c);
                                $totalCombos = count($combos1) * count($combos2) * count($combos3);
                                error_log("REQUEST {$r['id']} MIXED3 size=$s, a=$a, b=$b, c=$c, total=$totalCombos");
                                foreach ($combos1 as $c1) {
                                    foreach ($combos2 as $c2) {
                                        foreach ($combos3 as $c3) {
                                            $comboItems = array_merge($c1, $c2, $c3);
                                            $addCombo($comboItems);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                error_log(
                    "REQUEST {$r['id']} FINAL GENERATED (before dedup) = " .
                    count($generated)
                );

                // =================================================
                // ۶. حذف ترکیب‌های تکراری (بر اساس مجموعه ad_id)
                // =================================================

                $unique = [];
                $seenHashes = [];
                foreach ($generated as $combo) {
                    $adIds = [];
                    foreach ($combo['items'] as $item) {
                        if (isset($item['ad_id'])) {
                            $adIds[] = $item['ad_id'];
                        }
                    }
                    sort($adIds);
                    $hash = md5(implode(',', $adIds));
                    if (!in_array($hash, $seenHashes)) {
                        $seenHashes[] = $hash;
                        $unique[] = $combo;
                    }
                }
                $generated = $unique;

                error_log(
                    "REQUEST {$r['id']} FINAL COMBINATIONS (after dedup) = " .
                    count($generated)
                );

                // اگر ترکیب تولید شد، لاگ مجموع قیمت‌ها
                if (!empty($generated)) {
                    $comboPrices = array_map(
                        function($c) {
                            return (float)str_replace(',', '', (string)($c['total_price'] ?? 0));
                        },
                        $generated
                    );
                    sort($comboPrices);
                    error_log(
                        "REQUEST {$r['id']} COMBO PRICES: " .
                        implode(', ', $comboPrices)
                    );
                }

                // =================================================
                // ۷. ذخیره‌سازی ترکیب‌ها
                // =================================================

                $additional['combinations'] = $generated;
                $additional['combinations_last_generated'] = time();

                $newAdditional = json_encode($additional, JSON_UNESCAPED_UNICODE);
                $upd = $pdo->prepare('UPDATE property_requests SET additional=? WHERE id=?');
                $upd->execute([$newAdditional, (int)$r['id']]);


                // =================================================
                // READ SAVED COMBINATIONS (برای خروجی)
                // =================================================

                if (
                    !empty(
                        $additional['combinations']
                    )
                ) {

                    foreach (
                        $additional['combinations']
                        as $combo
                    ) {

                        $adIds = [];


                        foreach (
                            $combo['items']
                            as $item
                        ) {

                            if (
                                isset(
                                    $item['ad_id']
                                )
                            ) {

                                $adIds[] =
                                    $item['ad_id'];
                            }
                        }


                        if (
                            empty($adIds)
                        ) {
                            continue;
                        }


                        sort($adIds);


                        $comboHash =
                            md5(
                                implode(
                                    ',',
                                    $adIds
                                )
                            );


                        if (
                            in_array(
                                $comboHash,
                                $dislikedCombos,
                                true
                            )
                        ) {
                            continue;
                        }


                        $combinations[] = [

                            'items' =>
                                $combo['items'],

                            'total_price' =>
                                $combo[
                                    'total_price'
                                ] ?? 0,

                            'total_score' =>
                                $combo[
                                    'total_score'
                                ] ?? 0,

                            'count' =>
                                count(
                                    $combo['items']
                                ),

                            'combo_hash' =>
                                $comboHash,

                            'summary' =>
                                $combo[
                                    'summary'
                                ]
                                ??
                                implode(
                                    ' · ',
                                    array_map(
                                        fn($it) =>
                                            (
                                                $it[
                                                    'transaction_type'
                                                ]
                                                ?? ''
                                            )
                                            .
                                            ' '
                                            .
                                            (
                                                $it[
                                                    'property_type'
                                                ]
                                                ?? ''
                                            ),
                                        $combo['items']
                                    )
                                )
                        ];
                    }
                }

                // =================================================
                // فیلتر فایل‌های تکی بر اساس حداقل بودجه (فقط برای سرمایه‌گذاری)
                // =================================================

                if ($isInvestmentRequest && $minBudget > 0) {
                    $matches = array_filter($matches, function($item) use ($minBudget) {
                        $price = (float)($item['price_value'] ?? 0);
                        return $price >= $minBudget;
                    });
                    $matches = array_values($matches);
                }

                // =================================================
                // REQUEST GROUP
                // =================================================

                $groups[] = [

                    'request_id' =>
                        (int)$r['id'],

                    'tracking_code' =>
                        $r['tracking_code'],

                    'created_at' =>
                        $r['created_at'],

                    'req_transaction' =>
                        $r['transaction_type'],

                    'req_property' =>
                        $r['property_type'],

                    'req_location' =>
                        $r['location'],

                    'matches' =>
                        $matches,

                    'combinations' =>
                        $combinations
                ];
            }


            melkinoJsonResponse(
                [
                    'success' => true,
                    'requests' => $groups
                ]
            );


        } catch (Throwable $e) {

            melkinoJsonResponse(
                [

                    'success' => false,

                    'message' =>
                        'خطای داخلی: ' .
                        $e->getMessage() .
                        ' در خط ' .
                        $e->getLine() .
                        ' فایل ' .
                        $e->getFile()
                ],
                500
            );
        }
    }


    // ========================================================
    // NOT SUITABLE
    // ========================================================

    if (
        $_GET['action'] === 'not_suitable'
    ) {

        $matchId =
            (int)(
                $_POST['match_id'] ?? 0
            );


        if (!$matchId) {

            melkinoJsonResponse(
                [
                    'success' => false,
                    'message' => 'فایل نامعتبر است.'
                ],
                422
            );
        }


        if (
            empty($identity['user_id']) &&
            empty($identity['telegram_id'])
        ) {

            melkinoJsonResponse(
                [
                    'success' => false,
                    'message' => 'کاربر شناسایی نشد.'
                ],
                401
            );
        }


        $q =
            $pdo->prepare(
                'SELECT r.*
                 FROM request_matches rm
                 JOIN property_requests r
                    ON r.id=rm.request_id
                 WHERE rm.id=?
                 LIMIT 1'
            );


        $q->execute(
            [$matchId]
        );


        $req =
            $q->fetch(
                PDO::FETCH_ASSOC
            );


        if (
            !$req ||
            !mm8OwnRequest(
                $req,
                $identity
            )
        ) {

            melkinoJsonResponse(
                [
                    'success' => false,
                    'message' =>
                        'این فایل برای حساب شما نیست.'
                ],
                403
            );
        }


        try {

            m5EnsureFeedbackTable();

        } catch (Throwable $e) {

            melkinoJsonResponse(
                [
                    'success' => false,
                    'message' =>
                        'ثبت انتخاب امکان‌پذیر نیست.'
                ],
                500
            );
        }


        if (
            !empty(
                $identity['user_id']
            )
        ) {

            $sql =
                "INSERT INTO request_match_feedback
                (
                    request_match_id,
                    user_id,
                    telegram_id,
                    feedback
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    'dislike'
                )
                ON DUPLICATE KEY UPDATE
                    feedback='dislike',
                    updated_at=NOW(),
                    telegram_id=VALUES(telegram_id)";


            $params = [

                $matchId,

                $identity['user_id'],

                $identity['telegram_id']
                    ?: null
            ];

        } else {

            $sql =
                "INSERT INTO request_match_feedback
                (
                    request_match_id,
                    telegram_id,
                    feedback
                )
                VALUES
                (
                    ?,
                    ?,
                    'dislike'
                )
                ON DUPLICATE KEY UPDATE
                    feedback='dislike',
                    updated_at=NOW()";


            $params = [

                $matchId,

                $identity['telegram_id']
            ];
        }


        $st =
            $pdo->prepare($sql);

        $st->execute($params);


        melkinoJsonResponse(
            [
                'success' => true,
                'message' =>
                    'این فایل دیگر به شما پیشنهاد نمی‌شود.'
            ]
        );
    }


    // ========================================================
    // DELETE COMBO
    // ========================================================

    if (
        $_GET['action'] === 'delete_combo'
    ) {

        $requestId =
            (int)(
                $_POST['request_id'] ?? 0
            );

        $comboHash =
            trim(
                (string)(
                    $_POST['combo_hash'] ?? ''
                )
            );


        if (
            !$requestId ||
            !$comboHash
        ) {

            melkinoJsonResponse(
                [
                    'success' => false,
                    'message' =>
                        'اطلاعات ناقص است.'
                ],
                422
            );
        }


        if (
            empty($identity['user_id']) &&
            empty($identity['telegram_id'])
        ) {

            melkinoJsonResponse(
                [
                    'success' => false,
                    'message' =>
                        'کاربر شناسایی نشد.'
                ],
                401
            );
        }


        $q =
            $pdo->prepare(
                'SELECT *
                 FROM property_requests
                 WHERE id=?
                 LIMIT 1'
            );


        $q->execute(
            [$requestId]
        );


        $req =
            $q->fetch(
                PDO::FETCH_ASSOC
            );


        if (
            !$req ||
            !mm8OwnRequest(
                $req,
                $identity
            )
        ) {

            melkinoJsonResponse(
                [
                    'success' => false,
                    'message' =>
                        'این درخواست برای حساب شما نیست.'
                ],
                403
            );
        }


        $additional =
            mm8Json(
                $req['additional'] ?? ''
            );


        if (
            !isset(
                $additional[
                    'disliked_combos'
                ]
            ) ||
            !is_array(
                $additional[
                    'disliked_combos'
                ]
            )
        ) {

            $additional[
                'disliked_combos'
            ] = [];
        }


        if (
            !in_array(
                $comboHash,
                $additional[
                    'disliked_combos'
                ],
                true
            )
        ) {

            $additional[
                'disliked_combos'
            ][] =
                $comboHash;
        }


        $newAdditional =
            json_encode(
                $additional,
                JSON_UNESCAPED_UNICODE
            );


        $upd =
            $pdo->prepare(
                'UPDATE property_requests
                 SET additional=?
                 WHERE id=?'
            );


        $upd->execute(
            [
                $newAdditional,
                $requestId
            ]
        );


        melkinoJsonResponse(
            [
                'success' => true,
                'message' =>
                    'ترکیب مورد نظر حذف شد.'
            ]
        );
    }


    melkinoJsonResponse(
        [
            'success' => false,
            'message' =>
                'عملیات نامعتبر است.'
        ],
        400
    );
}


// ============================================================
// PAGE
// ============================================================

require_once __DIR__ . '/header.php';

?>

<style>

.matches-shell{
    flex:1;
    min-height:0;
    display:flex;
    flex-direction:column;
    overflow:hidden;
    background:var(--bg)
}

.matches-scroll{
    flex:1;
    min-height:0;
    overflow-y:auto;
    overflow-x:hidden;
    -webkit-overflow-scrolling:touch;
    padding:clamp(14px,2vw,28px)
        clamp(12px,3vw,38px)
        calc(var(--bottom-nav-height) + 30px)
}

.matches-page{
    width:100%;
    max-width:1240px;
    margin:0 auto
}

.head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:15px;
    margin-bottom:16px
}

.title{
    font-size:clamp(20px,2.1vw,27px);
    font-weight:900;
    color:var(--text-primary)
}

.sub{
    font-size:12px;
    color:var(--text-secondary);
    margin-top:5px
}

.back{
    border:1px solid var(--border);
    background:var(--surface);
    color:var(--text-primary);
    padding:9px 12px;
    border-radius:10px;
    text-decoration:none;
    white-space:nowrap
}

.request-block{
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:18px;
    padding:14px;
    margin-bottom:16px
}

.request-top{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:12px
}

.code{
    font-weight:900;
    color:var(--primary)
}

.summary{
    font-size:11px;
    color:var(--text-secondary);
    margin-top:5px
}

.match-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
    margin-top:12px
}

.match-card{
    background:var(--bg);
    border:1px solid var(--border);
    border-radius:15px;
    padding:11px;
    display:grid;
    grid-template-columns:132px minmax(0,1fr);
    gap:11px;
    align-items:start
}

.thumb-wrap{
    display:flex;
    flex-direction:column;
    gap:7px
}

.thumb{
    width:132px;
    height:92px;
    object-fit:cover;
    border-radius:10px;
    background:var(--surface);
    border:1px solid var(--border)
}

.thumb-empty{
    width:132px;
    height:92px;
    border-radius:10px;
    background:var(--surface);
    display:grid;
    place-items:center;
    color:var(--text-secondary);
    font-size:10px
}

.card-main{
    min-width:0
}

.match-line{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:8px
}

.match-title{
    font-weight:900;
    color:var(--text-primary);
    font-size:14px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap
}

.score{
    font-weight:900;
    color:var(--primary);
    font-size:13px;
    white-space:nowrap
}

.meta{
    font-size:10.5px;
    color:var(--text-secondary);
    line-height:1.8;
    margin-top:4px
}

.price{
    font-size:11px;
    color:var(--text-primary);
    font-weight:800;
    margin-top:5px
}

.detail-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:5px;
    margin-top:8px
}

.detail{
    background:var(--surface);
    border-radius:8px;
    padding:5px 6px;
    font-size:9px;
    color:var(--text-secondary);
    min-width:0
}

.detail b{
    display:block;
    color:var(--text-primary);
    font-size:10px;
    margin-top:1px;
    word-break:break-word
}

.all-details{
    display:flex;
    flex-wrap:wrap;
    gap:5px;
    margin-top:7px
}

.pill{
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:7px;
    padding:4px 6px;
    font-size:9px;
    color:var(--text-secondary)
}

.description{
    font-size:10px;
    line-height:1.8;
    color:var(--text-secondary);
    margin-top:7px
}

.actions{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
    margin-top:8px
}

.act{
    border:1px solid var(--border);
    background:var(--surface);
    color:var(--text-primary);
    padding:7px 9px;
    border-radius:9px;
    text-decoration:none;
    cursor:pointer;
    font:inherit;
    font-size:10px
}

.act.primary{
    background:var(--primary);
    border-color:var(--primary);
    color:#fff
}

.act.danger{
    color:#b42318
}

.empty{
    text-align:center;
    padding:42px 18px;
    background:var(--surface);
    border:1px dashed var(--border);
    border-radius:16px;
    color:var(--text-secondary);
    grid-column:1/-1
}

.combo-section{
    margin-bottom:16px
}

.combo-header{
    font-size:16px;
    font-weight:800;
    color:var(--primary);
    margin-bottom:10px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-wrap:wrap
}

.combo-header .act{
    font-size:11px;
    padding:4px 10px
}

.combo-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
    margin-top:10px
}

.combo-card{
    background:var(--surface);
    border:2px solid var(--primary);
    border-radius:15px;
    padding:14px;
    display:flex;
    flex-direction:column;
    gap:10px;
    position:relative
}

.combo-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px
}

.combo-count{
    font-size:13px;
    font-weight:800;
    color:var(--primary)
}

.combo-delete{
    background:transparent;
    border:1px solid #e5e7eb;
    color:#b42318;
    padding:4px 8px;
    border-radius:6px;
    font-size:11px;
    cursor:pointer;
    transition:all .2s
}

.combo-delete:hover{
    background:#fef2f2;
    border-color:#b42318
}

.combo-summary{
    font-size:11px;
    color:var(--text-secondary);
    padding:4px 0;
    border-bottom:1px solid var(--border);
    margin-bottom:4px
}

.combo-price{
    font-weight:800;
    color:var(--primary);
    font-size:16px;
    border-bottom:1px solid var(--border);
    padding-bottom:8px;
    margin-bottom:4px
}

.combo-items{
    display:grid;
    grid-template-columns:1fr;
    gap:8px
}

@media(max-width:650px){

    .combo-grid{
        grid-template-columns:1fr
    }

}

@media(max-width:850px){

    .match-grid{
        grid-template-columns:1fr
    }

}

</style>


<main class="matches-shell">

<div class="matches-scroll">

<div class="matches-page">


<div class="head">

    <div>

        <div class="title">
            📂 فایل‌های مناسب من
        </div>

        <div class="sub">
            درصد مناسب‌بودن هر فایل بر اساس درخواست فعلی شما محاسبه شده است.
        </div>

    </div>

    <a
        class="back"
        href="profile.php"
    >
        ← پروفایل
    </a>

</div>


<div id="requests"></div>


</div>

</div>

</main>


<script>

const esc = v =>
    String(v ?? '')
        .replace(
            /[&<>"']/g,
            c => ({
                '&':'&amp;',
                '<':'&lt;',
                '>':'&gt;',
                '"':'&quot;',
                "'":'&#039;'
            }[c])
        );


const money = v => {

    if (
        v === null ||
        v === undefined ||
        v === ''
    ) {
        return '۰';
    }

    const s =
        String(v)
            .replace(
                /[^0-9.\-]/g,
                ''
            );

    const n =
        Number(s);

    return (
        Number.isFinite(n) &&
        n > 0
    )
        ? n.toLocaleString('en-US')
        : '۰';
};


const labels = {

    area:'متراژ',
    built_area:'زیربنا',
    land_area:'مساحت زمین',
    garden_area:'مساحت باغ',
    rooms:'اتاق',
    floor:'طبقه',
    year:'سال ساخت',
    unit_count:'تعداد واحد',
    bedrooms:'خواب',
    bathrooms:'سرویس',
    parking_count:'پارکینگ',
    parking:'پارکینگ',
    storage:'انباری',
    elevator:'آسانسور',
    balcony:'بالکن / تراس',
    cabinet:'کابینت',
    flooring:'کف',
    cooling:'سرمایش',
    heating:'گرمایش',
    document_status:'وضعیت سند',
    document_type:'نوع سند',
    usage:'کاربری',
    land_usage:'کاربری زمین',
    front_width:'عرض بر',
    length:'طول',
    width:'عرض',
    direction:'جهت',
    shape:'شکل زمین',
    partition_status:'وضعیت تفکیک',
    front_count:'تعداد بر',
    master:'اتاق مستر',
    guard:'نگهبانی',
    pool:'استخر',
    sauna:'سونا',
    jacuzzi:'جکوزی',
    roof_garden:'روف گاردن',
    laundry:'لاندری روم',
    closet:'کلوزت',
    private_yard:'حیاط اختصاصی',
    kitchen:'مطبخ',
    deal_type:'نوع معامله',
    tree_age:'سن درختان',
    tree_count:'تعداد درختان',
    tree_types:'نوع درختان',
    irrigation_type:'نوع آبیاری',
    water_source:'منبع آب',
    water_share:'سهم آب',
    has_well:'چاه آب',
    has_pond:'استخر ذخیره آب',
    has_building:'بنا / خانه باغ',
    building_area:'متراژ بنا',
    land_type:'نوع زمین',
    land_width:'عرض زمین',
    land_length:'طول زمین',
    land_front_width:'عرض بر',
    land_blocks:'تعداد بر',
    land_direction:'جهت ملک',
    land_shape:'شکل زمین',
    land_deed_status:'وضعیت سند',
    land_deed_type:'نوع سند',
    land_division_status:'وضعیت تفکیک',
    land_setback_status:'وضعیت عقب‌نشینی',
    land_ownership:'وضعیت مالکیت',
    office_floor:'طبقه',
    office_units_per_floor:'تعداد واحد در طبقه',
    office_rooms:'تعداد اتاق',
    office_year:'سال ساخت',
    office_condition:'وضعیت واحد',
    office_orientation:'موقعیت واحد',
    office_usage:'کاربری',
    front:'بر مغازه',
    wall:'پوشش دیوار',
    location_type:'موقعیت',
    jobs_comm:'مناسب برای مشاغل',
    well_name:'نام چاه آب',
    unit:'شماره واحد',
    total_units:'تعداد کل واحدها',
    area_apt:'متراژ',
    floor_apt:'طبقه',
    rooms_apt:'تعداد اتاق',
    year_apt:'سال ساخت',
    flooring_apt:'کف',
    cabinet_apt:'کابینت',
    cooling_apt:'سرمایش',
    heating_apt:'گرمایش',
    land_villa:'متراژ زمین',
    built_villa:'زیربنا',
    rooms_villa:'تعداد اتاق',
    year_villa:'سال ساخت',
    flooring_villa:'کف',
    cabinet_villa:'کابینت',
    cooling_villa:'سرمایش',
    heating_villa:'گرمایش',
    area_comm:'متراژ',
    front_comm:'بر مغازه',
    floor_comm:'کف',
    wall_comm:'دیوار',
    cabinet_comm:'کابینت',
    cooling_comm:'سرمایش',
    heating_comm:'گرمایش',
    office_area:'متراژ واحد'
};


function label(k){

    return (
        labels[k] ||
        String(k).replace(
            /[\_-]/g,
            ' '
        )
    );
}


function val(v){

    if (
        typeof v === 'boolean'
    ) {
        return v
            ? 'دارد'
            : 'ندارد';
    }

    if (
        Array.isArray(v)
    ) {
        return v.join('، ');
    }

    return String(
        v ?? ''
    ).trim();
}


function renderMatch(m, index){

    const details = [];


    [
        [
            'متراژ',
            m.area
                ? m.area + ' متر'
                : '',
            m.area
        ],

        [
            'زیربنا',
            m.built_area
                ? m.built_area + ' متر'
                : '',
            m.built_area
        ],

        [
            'زمین',
            m.land_area
                ? m.land_area + ' متر'
                : '',
            m.land_area
        ],

        [
            'اتاق',
            m.rooms,
            'rooms'
        ],

        [
            'طبقه',
            m.floor,
            'floor'
        ],

        [
            'سال ساخت',
            m.year,
            'year'
        ]

    ].forEach(
        x => {

            if (
                x[2] !== '' &&
                x[2] !== null &&
                x[2] !== undefined
            ) {

                details.push(
                    `
                    <div class="detail">
                        ${esc(x[0])}
                        <b>
                            ${esc(x[1] || '—')}
                        </b>
                    </div>
                    `
                );
            }
        }
    );


    const dyn = [];


    Object.entries(
        m.details || {}
    ).forEach(
        ([k,v]) => {

            const text =
                val(v);

            if (!text) {
                return;
            }

            const key =
                String(k);

            if (
                [
                    'area',
                    'built_area',
                    'land_area',
                    'rooms',
                    'floor',
                    'year'
                ].includes(key)
            ) {
                return;
            }

            dyn.push(
                `
                <span class="pill">
                    ${esc(label(key))}:
                    ${esc(text)}
                </span>
                `
            );
        }
    );


    const amenities =
        Array.isArray(m.amenities)
            ? m.amenities
            : [];


    amenities.forEach(
        a => {

            dyn.push(
                `
                <span class="pill">
                    ${esc(a)}
                </span>
                `
            );
        }
    );


    const tx =
        String(
            m.transaction_type || ''
        );


    const image =
        m.images?.[0] || '';


    const numberLabel = index !== undefined && index !== null
        ? `<span style="display:inline-block;background:var(--primary);color:#fff;border-radius:50%;width:24px;height:24px;text-align:center;line-height:24px;font-size:12px;font-weight:900;margin-left:6px;flex-shrink:0;">${index + 1}</span>`
        : '';


    return `

    <article
        class="match-card"
        data-match-id="${Number(m.match_id)}"
    >

        <div class="thumb-wrap">

            ${
                image
                ?
                `
                <img
                    class="thumb"
                    src="${esc(image)}"
                    alt=""
                >
                `
                :
                `
                <div class="thumb-empty">
                    بدون تصویر
                </div>
                `
            }

        </div>


        <div class="card-main">

            <div class="match-line">

                <div class="match-title" style="display:flex;align-items:center;gap:4px;">
                    ${numberLabel}
                    ${esc(
                        m.title ||
                        'فایل مناسب'
                    )}
                </div>

                <div class="score">
                    ${Number(
                        m.match_percent || 0
                    )}٪ مناسب شماست
                </div>

            </div>


            <div class="meta">

                ${esc(
                    [
                        m.property_type,
                        tx,
                        m.location
                    ]
                    .filter(Boolean)
                    .join(' · ')
                )}

            </div>


            ${
                m.price
                ?
                `
                <div class="price">
                    ${esc(m.price)}
                </div>
                `
                :
                ''
            }


            ${
                details.length
                ?
                `
                <div class="detail-grid">
                    ${details.join('')}
                </div>
                `
                :
                ''
            }


            ${
                dyn.length
                ?
                `
                <div class="all-details">
                    ${dyn.join('')}
                </div>
                `
                :
                ''
            }


            ${
                m.description
                ?
                `
                <div class="description">
                    ${esc(m.description)}
                </div>
                `
                :
                ''
            }


            <div class="actions">

                <button
                    class="act danger"
                    type="button"
                    onclick="notSuitable(
                        ${Number(m.match_id)},
                        this
                    )"
                >
                    مناسب من نیست
                </button>


                <a
                    class="act primary"
                    href="property-details.php?id=${encodeURIComponent(
                        m.ad_id
                    )}"
                >
                    مشاهده کامل فایل
                </a>

            </div>

        </div>

    </article>
    `;
}


function renderCombo(
    combo,
    requestId,
    index
){

    const total =
        money(
            combo.total_price
        );


    const count =
        combo.count ||
        combo.items.length ||
        0;


    const itemsHtml =
        combo.items
            .map(item =>
                renderMatch(item)
            )
            .join('');


    const summary =
        esc(
            combo.summary || ''
        );

    const numberLabel = index !== undefined && index !== null
        ? `<span style="display:inline-block;background:var(--gold);color:#111827;border-radius:50%;width:24px;height:24px;text-align:center;line-height:24px;font-size:12px;font-weight:900;margin-left:6px;flex-shrink:0;">${index + 1}</span>`
        : '';


    return `

    <div
        class="combo-card"
        data-combo-hash="${esc(
            combo.combo_hash
        )}"
    >

        <div class="combo-top">

            <div class="combo-count" style="display:flex;align-items:center;gap:4px;">
                ${numberLabel}
                🧩 شامل ${count} ملک
            </div>


            <button
                class="combo-delete"
                type="button"
                onclick="deleteCombo(
                    ${requestId},
                    '${esc(
                        combo.combo_hash
                    )}',
                    this
                )"
            >
                ✕ حذف ترکیب
            </button>

        </div>


        ${
            summary
            ?
            `
            <div class="combo-summary">
                ${summary}
            </div>
            `
            :
            ''
        }


        <div class="combo-price">
            💰 مجموع قیمت:
            ${total}
            تومان
        </div>


        <div class="combo-items">

            ${itemsHtml}

        </div>

    </div>

    `;
}


async function load(){

    const root =
        document.getElementById(
            'requests'
        );


    root.innerHTML =
        `
        <div class="empty">
            در حال دریافت فایل‌های مناسب...
        </div>
        `;


    try {

        const res =
            await fetch(
                'my-request-matches.php?action=data',
                {
                    cache:'no-store'
                }
            );


        const d =
            await res.json();


        if (!d.success) {

            root.innerHTML =
                `
                <div class="empty">
                    ${esc(
                        d.message ||
                        'خطا در دریافت فایل‌های مناسب'
                    )}
                </div>
                `;

            return;
        }


        const groups =
            d.requests || [];


        if (!groups.length) {

            root.innerHTML =
                `
                <div class="empty">
                    هنوز درخواست فعالی برای شما ثبت نشده است.
                </div>
                `;

            return;
        }


        root.innerHTML =
            groups
                .map(
                    g => {

                        let matchHtml =
                            '';


                        if (
                            g.matches &&
                            g.matches.length
                        ) {

                            matchHtml =
                                `
                                <div class="match-grid">

                                    ${g.matches
                                        .map((m, idx) => renderMatch(m, idx))
                                        .join('')}

                                </div>
                                `;
                        }


                        let comboHtml =
                            '';


                        const isInvestment =
                            g.req_transaction &&
                            (
                                g.req_transaction.includes(
                                    'سرمایه'
                                ) ||
                                g.req_transaction.includes(
                                    'سرمای‌ه'
                                )
                            );


                        if (
                            g.combinations &&
                            g.combinations.length
                        ) {

                            comboHtml =
                                `
                                <div class="combo-section">

                                    <div class="combo-header">

                                        <span>
                                            💰 ترکیب‌های پیشنهادی برای سرمایه‌گذاری
                                        </span>


                                        ${
                                            isInvestment
                                            ?
                                            `
                                            <button
                                                class="act primary"
                                                onclick="regenerateCombos(
                                                    ${g.request_id},
                                                    this
                                                )"
                                            >
                                                🔄 بروزرسانی ترکیب‌ها
                                            </button>
                                            `
                                            :
                                            ''
                                        }

                                    </div>


                                    <div class="combo-grid">

                                        ${g.combinations
                                            .map((c, idx) => renderCombo(c, g.request_id, idx))
                                            .join('')}

                                    </div>

                                </div>
                                `;

                        } else if (
                            isInvestment
                        ) {

                            comboHtml =
                                `
                                <div class="combo-section">

                                    <div class="combo-header">

                                        <span>
                                            💰 ترکیب‌های پیشنهادی برای سرمایه‌گذاری
                                        </span>


                                        <button
                                            class="act primary"
                                            onclick="regenerateCombos(
                                                ${g.request_id},
                                                this
                                            )"
                                        >
                                            🔄 بروزرسانی ترکیب‌ها
                                        </button>

                                    </div>


                                    <div
                                        class="empty"
                                        style="
                                            grid-column:1/-1;
                                            margin:10px 0;
                                        "
                                    >
                                        هنوز ترکیبی ساخته نشده است.
                                        برای ساخت ترکیب‌ها کلیک کنید.
                                    </div>

                                </div>
                                `;
                        }


                        if (
                            !comboHtml &&
                            !matchHtml
                        ) {

                            matchHtml =
                                `
                                <div class="empty">
                                    برای این درخواست فعلاً فایل مناسبی پیدا نشد.
                                </div>
                                `;
                        }


                        return `

                        <section
                            class="request-block"
                        >

                            <div class="request-top">

                                <div>

                                    <div class="code">
                                        ${esc(
                                            g.tracking_code
                                        )}
                                    </div>


                                    <div class="summary">
                                        ${esc(
                                            [
                                                g.req_transaction,
                                                g.req_property,
                                                g.req_location
                                            ]
                                            .filter(Boolean)
                                            .join(' · ')
                                        )}
                                    </div>

                                </div>

                            </div>

                            ${matchHtml}

                            ${comboHtml}

                        </section>

                        `;
                    }
                )
                .join('');


    } catch(e) {

        root.innerHTML =
            `
            <div class="empty">
                ارتباط با سرور برقرار نشد.
                لطفاً دوباره تلاش کنید.
            </div>
            `;
    }
}


async function notSuitable(
    id,
    btn
){

    if (
        !confirm(
            'این فایل دیگر به شما پیشنهاد نشود؟'
        )
    ) {
        return;
    }


    const old =
        btn.textContent;


    btn.disabled =
        true;

    btn.textContent =
        'در حال ثبت...';


    try {

        const res =
            await fetch(
                'my-request-matches.php?action=not_suitable',
                {
                    method:'POST',

                    headers:{
                        'Content-Type':
                            'application/x-www-form-urlencoded'
                    },

                    body:
                        new URLSearchParams(
                            {
                                match_id:
                                    String(id)
                            }
                        )
                }
            );


        const d =
            await res.json();


        if (!d.success) {

            alert(
                d.message ||
                'ثبت انتخاب انجام نشد.'
            );

            return;
        }


        const card =
            document.querySelector(
                `[data-match-id="${id}"]`
            );


        if (card) {
            card.remove();
        }


    } catch(e) {

        alert(
            'ارتباط با سرور برقرار نشد.'
        );

    } finally {

        btn.disabled =
            false;

        btn.textContent =
            old;
    }
}


async function deleteCombo(
    requestId,
    comboHash,
    btn
){

    if (
        !confirm(
            'آیا از حذف این ترکیب مطمئن هستید؟'
        )
    ) {
        return;
    }


    const old =
        btn.textContent;


    btn.disabled =
        true;

    btn.textContent =
        'در حال حذف...';


    try {

        const res =
            await fetch(
                'my-request-matches.php?action=delete_combo',
                {
                    method:'POST',

                    headers:{
                        'Content-Type':
                            'application/x-www-form-urlencoded'
                    },

                    body:
                        new URLSearchParams(
                            {
                                request_id:
                                    String(
                                        requestId
                                    ),

                                combo_hash:
                                    comboHash
                            }
                        )
                }
            );


        const d =
            await res.json();


        if (!d.success) {

            alert(
                d.message ||
                'حذف ترکیب انجام نشد.'
            );

            return;
        }


        const card =
            btn.closest(
                '.combo-card'
            );


        if (card) {
            card.remove();
        }


        const section =
            card?.closest(
                '.combo-section'
            );


        if (
            section &&
            !section.querySelector(
                '.combo-card'
            )
        ) {

            section.remove();
        }


    } catch(e) {

        alert(
            'ارتباط با سرور برقرار نشد.'
        );

    } finally {

        btn.disabled =
            false;

        btn.textContent =
            old;
    }
}


async function regenerateCombos(
    requestId,
    btn
){

    if (
        !confirm(
            'ترکیب‌های فعلی حذف و مجدداً بر اساس آخرین فایل‌ها تولید می‌شوند. ادامه؟'
        )
    ) {
        return;
    }


    const old =
        btn.textContent;


    btn.disabled =
        true;

    btn.textContent =
        'در حال بروزرسانی...';


    try {

        const res =
            await fetch(
                'my-request-matches.php?action=regenerate_combos',
                {
                    method:'POST',

                    headers:{
                        'Content-Type':
                            'application/x-www-form-urlencoded'
                    },

                    body:
                        new URLSearchParams(
                            {
                                request_id:
                                    String(
                                        requestId
                                    )
                            }
                        )
                }
            );


        const d =
            await res.json();


        if (!d.success) {

            alert(
                d.message ||
                'خطا در بروزرسانی'
            );

            return;
        }


        location.reload();


    } catch(e) {

        alert(
            'ارتباط با سرور برقرار نشد.'
        );

    } finally {

        btn.disabled =
            false;

        btn.textContent =
            old;
    }
}


load();

</script>


<?php

require_once __DIR__ . '/footer.php';

?>