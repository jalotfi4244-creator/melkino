<?php

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/promotions.php';


/* =====================================================
   Fallback: getFirstImage
   ===================================================== */

if (!function_exists('getFirstImage')) {

    function getFirstImage($selectedImages)
    {
        $images = [];

        $collect = static function ($value) use (&$images, &$collect) {

            if (is_string($value)) {

                $value = trim($value);

                if ($value === '') {
                    return;
                }

                $decoded = json_decode($value, true);

                if (is_array($decoded)) {
                    $collect($decoded);
                    return;
                }

                if (
                    strpos($value, ',') !== false ||
                    strpos($value, '|') !== false
                ) {

                    foreach (
                        preg_split('/[,|]+/', $value)
                        as $part
                    ) {
                        $collect($part);
                    }

                    return;
                }

                $images[] = $value;
                return;
            }


            if (is_array($value)) {

                foreach ($value as $item) {

                    if (is_array($item)) {

                        foreach (
                            ['url', 'path', 'src', 'image', 'file']
                            as $key
                        ) {

                            if (isset($item[$key])) {

                                $collect($item[$key]);
                                break;
                            }
                        }

                    } else {

                        $collect($item);
                    }
                }
            }
        };


        $collect($selectedImages);


        foreach ($images as $image) {

            $image = trim(
                str_replace('\\', '/', (string)$image)
            );

            if ($image === '') {
                continue;
            }


            /*
             * URL کامل
             */
            if (
                preg_match(
                    '#^https?://#i',
                    $image
                )
            ) {
                return $image;
            }


            $relative = ltrim(
                $image,
                '/'
            );

            $candidates = [
                $relative
            ];


            if (
                stripos($relative, 'melkino/') === 0
            ) {

                $candidates[] =
                    substr($relative, 8);
            }


            if (
                stripos($relative, './') === 0
            ) {

                $candidates[] =
                    ltrim(
                        substr($relative, 2),
                        '/'
                    );
            }


            foreach (
                array_unique($candidates)
                as $candidate
            ) {

                if (
                    file_exists(
                        __DIR__ . '/' . $candidate
                    )
                ) {
                    return $candidate;
                }
            }
        }


        return null;
    }
}


/* =====================================================
   دریافت آگهی‌های منتشر شده
   ===================================================== */

$publishedAds = [];


$isMockMode =
    defined('MOCK_MODE')
        ? MOCK_MODE
        : true;


if ($isMockMode) {

    /* =================================================
       MOCK MODE
       ================================================= */

    $jsonFile =
        __DIR__ . '/ads.json';

    $adsFromJson = [];


    if (file_exists($jsonFile)) {

        $jsonContent =
            file_get_contents($jsonFile);

        $decodedAds =
            json_decode(
                $jsonContent,
                true
            );

        if (is_array($decodedAds)) {
            $adsFromJson =
                $decodedAds;
        }
    }


    foreach ($adsFromJson as $ad) {

        if (
            ($ad['status'] ?? '') !==
            'published'
        ) {
            continue;
        }


        $selectedImages =
            $ad['selected_images']
            ?? ($ad['selectedImages'] ?? []);


        if (is_string($selectedImages)) {

            $selectedImages =
                json_decode(
                    $selectedImages,
                    true
                ) ?: [];
        }


        if (!is_array($selectedImages)) {
            $selectedImages = [];
        }


        foreach (
            [
                'images',
                'photos',
                'gallery',
                'gallery_images',
                'image'
            ] as $imageField
        ) {

            if (
                array_key_exists(
                    $imageField,
                    $ad
                ) &&
                !empty($ad[$imageField])
            ) {

                $source =
                    $ad[$imageField];


                if (is_array($source)) {

                    $selectedImages =
                        array_merge(
                            $selectedImages,
                            $source
                        );

                } elseif (is_string($source)) {

                    $decodedSource =
                        json_decode(
                            $source,
                            true
                        );

                    $selectedImages =
                        array_merge(
                            $selectedImages,
                            is_array($decodedSource)
                                ? $decodedSource
                                : [$source]
                        );
                }
            }
        }


        $details =
            $ad['property_details']
            ?? [];


        if (is_string($details)) {

            $details =
                json_decode(
                    $details,
                    true
                ) ?: [];
        }


        if (!is_array($details)) {
            $details = [];
        }


        $amenities =
            $ad['amenities']
            ?? [];


        if (is_string($amenities)) {

            $amenities =
                json_decode(
                    $amenities,
                    true
                ) ?: [];
        }


        if (!is_array($amenities)) {
            $amenities = [];
        }


        $deposit =
            $ad['deposit']
            ?? '';

        $rentMonthly =
            $ad['rent_monthly']
            ?? '';

        $priceSell =
            $ad['price_sell']
            ?? '';

        $totalPrice =
            $ad['total_price']
            ?? '';

        $displayPrice =
            $ad['display_price']
            ?? '';


        if (
            $displayPrice === '' ||
            $displayPrice === '0' ||
            $displayPrice === null
        ) {

            if (
                !empty($priceSell) &&
                $priceSell !== '0'
            ) {

                $displayPrice =
                    $priceSell;

            } elseif (
                !empty($totalPrice) &&
                $totalPrice !== '0'
            ) {

                $displayPrice =
                    $totalPrice;

            } elseif (
                !empty($deposit) &&
                $deposit !== '0'
            ) {

                $displayPrice =
                    $deposit;


                if (
                    !empty($rentMonthly) &&
                    $rentMonthly !== '0'
                ) {

                    $displayPrice .=
                        ' | ' . $rentMonthly;
                }

            } elseif (
                !empty($rentMonthly) &&
                $rentMonthly !== '0'
            ) {

                $displayPrice =
                    $rentMonthly;
            }
        }

        // خواندن کلید نخورده
        $isNotKeyed = 0;
        if (isset($ad['is_not_keyed'])) {
            $isNotKeyed = (int)$ad['is_not_keyed'];
        } elseif (isset($details['is_not_keyed'])) {
            $isNotKeyed = (int)$details['is_not_keyed'];
        } elseif (isset($ad['key_not_turned'])) {
            $isNotKeyed = (int)$ad['key_not_turned'];
        }


        $publishedAds[] = [

            'id' =>
                (string)(
                    $ad['id'] ?? ''
                ),

            'title' =>
                $ad['title']
                ?? 'ملک بدون عنوان',

            'transaction_type' =>
                $ad['transaction_type']
                ?? $ad['transactionType']
                ?? 'فروش',

            'property_type' =>
                $ad['property_type']
                ?? $ad['propertyType']
                ?? 'آپارتمان',

            'price' =>
                $displayPrice,

            'location' =>
                $ad['location']
                ?? '',

            'description' =>
                $ad['description']
                ?? '',

            'selected_images' =>
                $selectedImages,

            'details' =>
                $details,

            'amenities' =>
                $amenities,

            'created_at' =>
                $ad['created_at']
                ?? date('Y-m-d H:i:s'),

            'deposit' =>
                $deposit,

            'rent_monthly' =>
                $rentMonthly,

            'price_sell' =>
                $priceSell,

            'price_hidden' =>
                !empty($ad['price_hidden']),

            'total_price' =>
                $totalPrice,

            'full_rent' =>
                $ad['full_rent']
                ?? '',

            'full_rent_enabled' =>
                $ad['full_rent_enabled']
                ?? '0',

            'is_not_keyed' =>
                $isNotKeyed,
        ];
    }


    /*
     * جدیدترین آگهی ابتدا
     */
    usort(
        $publishedAds,
        static function ($a, $b) {

            return strcmp(
                (string)(
                    $b['created_at']
                    ?? ''
                ),

                (string)(
                    $a['created_at']
                    ?? ''
                )
            );
        }
    );

} else {

    /* =================================================
       DATABASE MODE
       ================================================= */

    try {

        if (
            !isset($pdo) ||
            !($pdo instanceof PDO)
        ) {
            throw new RuntimeException(
                'اتصال به دیتابیس برقرار نشد.'
            );
        }


        $stmt = $pdo->query(
            "SELECT *
             FROM ads
             WHERE status = 'published'
             ORDER BY created_at DESC"
        );


        $adsFromDB =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        if ($adsFromDB) {

            $imageStmt =
                $pdo->prepare(
                    "SELECT
                        filename,
                        is_selected,
                        is_primary,
                        publish_publicly
                     FROM images
                     WHERE ad_id = ?
                     ORDER BY
                        is_primary DESC,
                        sort_order ASC,
                        id ASC"
                );


            $amenityStmt =
                $pdo->prepare(
                    "SELECT am.name
                     FROM ad_amenities aa
                     INNER JOIN amenities am
                         ON am.id = aa.amenity_id
                     WHERE
                        aa.ad_id = ?
                        AND am.is_active = 1
                     ORDER BY
                        am.sort_order ASC,
                        am.id ASC"
                );


            foreach ($adsFromDB as $ad) {

                $selectedImages = [];
                $allPublicImages = [];


                $imageStmt->execute([
                    (string)$ad['id']
                ]);


                $imageRows =
                    $imageStmt->fetchAll(
                        PDO::FETCH_ASSOC
                    );


                foreach ($imageRows as $imageRow) {

                    $filename =
                        trim(
                            (string)(
                                $imageRow['filename']
                                ?? ''
                            )
                        );


                    if ($filename === '') {
                        continue;
                    }


                    if (
                        !empty(
                            $imageRow['publish_publicly']
                        )
                    ) {

                        $allPublicImages[] =
                            $filename;
                    }


                    if (
                        !empty(
                            $imageRow['is_selected']
                        ) &&
                        !empty(
                            $imageRow['publish_publicly']
                        )
                    ) {

                        $selectedImages[] =
                            $filename;
                    }
                }


                /*
                 * اگر تصویر انتخاب‌شده‌ای وجود نداشت،
                 * تصاویر عمومی را نمایش بده.
                 */
                if (!$selectedImages) {

                    $selectedImages =
                        $allPublicImages;
                }


                $details =
                    $ad['property_details']
                    ?? [];


                if (is_string($details)) {

                    $decoded =
                        json_decode(
                            $details,
                            true
                        );

                    $details =
                        is_array($decoded)
                            ? $decoded
                            : [];
                }


                if (!is_array($details)) {
                    $details = [];
                }


                $amenities = [];


                try {

                    $amenityStmt->execute([
                        (string)$ad['id']
                    ]);


                    $amenities =
                        array_values(
                            array_filter(
                                array_map(
                                    static function ($row) {

                                        return trim(
                                            (string)(
                                                $row['name']
                                                ?? ''
                                            )
                                        );
                                    },

                                    $amenityStmt->fetchAll(
                                        PDO::FETCH_ASSOC
                                    )
                                )
                            )
                        );

                } catch (Throwable $amenityError) {

                    $amenities = [];
                }


                /*
                 * داده‌های قدیمی
                 */
                if (
                    !$amenities &&
                    !empty(
                        $ad['custom_fields']
                    )
                ) {

                    $custom =
                        is_string(
                            $ad['custom_fields']
                        )
                            ? json_decode(
                                $ad['custom_fields'],
                                true
                            )
                            : $ad['custom_fields'];


                    if (
                        is_array($custom) &&
                        !empty(
                            $custom['amenities']
                        ) &&
                        is_array(
                            $custom['amenities']
                        )
                    ) {

                        $amenities =
                            $custom['amenities'];
                    }
                }


                $deposit =
                    $ad['deposit']
                    ?? '';

                $rentMonthly =
                    $ad['rent_monthly']
                    ?? '';

                $priceSell =
                    $ad['price_sell']
                    ?? '';

                $totalPrice =
                    $ad['total_price']
                    ?? '';

                $displayPrice =
                    $ad['display_price']
                    ?? '';


                if (
                    $displayPrice === '' ||
                    $displayPrice === null ||
                    $displayPrice == 0
                ) {

                    if (
                        $priceSell !== '' &&
                        $priceSell != 0
                    ) {

                        $displayPrice =
                            $priceSell;

                    } elseif (
                        $totalPrice !== '' &&
                        $totalPrice != 0
                    ) {

                        $displayPrice =
                            $totalPrice;

                    } elseif (
                        $deposit !== '' &&
                        $deposit != 0
                    ) {

                        $displayPrice =
                            $deposit;

                    } elseif (
                        $rentMonthly !== '' &&
                        $rentMonthly != 0
                    ) {

                        $displayPrice =
                            $rentMonthly;

                    } else {

                        $displayPrice = '';
                    }
                }

                // خواندن کلید نخورده از ستون is_not_keyed
                $isNotKeyed = isset($ad['is_not_keyed']) ? (int)$ad['is_not_keyed'] : 0;


                $publishedAds[] = [

                    'id' =>
                        (string)(
                            $ad['id'] ?? ''
                        ),

                    'title' =>
                        $ad['title']
                        ?? 'ملک بدون عنوان',

                    'transaction_type' =>
                        $ad['transaction_type']
                        ?? 'فروش',

                    'property_type' =>
                        $ad['property_type']
                        ?? 'آپارتمان',

                    'price' =>
                        $displayPrice,

                    'location' =>
                        $ad['location']
                        ?? '',

                    'description' =>
                        $ad['description']
                        ?? '',

                    'selected_images' =>
                        $selectedImages,

                    'details' =>
                        $details,

                    'amenities' =>
                        $amenities,

                    'created_at' =>
                        $ad['created_at']
                        ?? date('Y-m-d H:i:s'),

                    'deposit' =>
                        $deposit,

                    'rent_monthly' =>
                        $rentMonthly,

                    'price_sell' =>
                        $priceSell,

                    'price_hidden' =>
                        !empty(
                            $ad['price_hidden']
                        ),

                    'total_price' =>
                        $totalPrice,

                    'full_rent' =>
                        $ad['full_rent']
                        ?? '',

                    'full_rent_enabled' =>
                        $ad['full_rent_enabled']
                        ?? 0,

                    'is_not_keyed' =>
                        $isNotKeyed,
                ];
            }
        }

    } catch (Throwable $e) {

        error_log(
            'Melkino home database error: ' .
            $e->getMessage()
        );

        $publishedAds = [];
    }
}


/* =====================================================
   Display Price
   ===================================================== */

function normalizeMoneyValue($value): float
{
    if (
        is_array($value) ||
        is_object($value)
    ) {
        return 0;
    }


    $value =
        trim(
            (string)$value
        );


    if (
        $value === '' ||
        $value === '0'
    ) {
        return 0;
    }


    $value =
        strtr(
            $value,
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
                '۹' => '9',
                '٬' => ',',
                '،' => ',',
                ' ' => '',
                'تومان' => '',
            ]
        );


    $value =
        preg_replace(
            '/[^0-9.\-]/',
            '',
            $value
        );


    if (
        $value === '' ||
        !is_numeric($value)
    ) {
        return 0;
    }


    return (float)$value;
}


function toPersianDigits(
    string $value
): string
{
    return strtr(
        $value,
        [
            '0' => '۰',
            '1' => '۱',
            '2' => '۲',
            '3' => '۳',
            '4' => '۴',
            '5' => '۵',
            '6' => '۶',
            '7' => '۷',
            '8' => '۸',
            '9' => '۹',
        ]
    );
}


function formatMoneyFa($value): string
{
    $number =
        normalizeMoneyValue(
            $value
        );


    if ($number <= 0) {
        return '';
    }


    $formatted =
        number_format(
            $number,
            0,
            '.',
            ','
        );


    return toPersianDigits(
        $formatted
    );
}


function displayPrice($ad): string
{
    if (
        function_exists(
            'shouldHidePublicPrice'
        ) &&
        shouldHidePublicPrice(
            (array)$ad
        )
    ) {
        return 'برای استعلام قیمت تماس بگیرید';
    }


    $toNumber =
        static function ($value): float {

            if (
                $value === null ||
                $value === ''
            ) {
                return 0;
            }


            $value =
                strtr(
                    (string)$value,
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
                        '۹' => '9',
                    ]
                );


            $value =
                str_replace(
                    [
                        ',',
                        '٬',
                        '،',
                        ' '
                    ],
                    '',
                    $value
                );


            return is_numeric($value)
                ? (float)$value
                : 0;
        };


    $details =
        $ad['details']
        ?? $ad['property_details']
        ?? [];


    if (is_string($details)) {

        $decoded =
            json_decode(
                $details,
                true
            );

        $details =
            is_array($decoded)
                ? $decoded
                : [];
    }


    if (!is_array($details)) {
        $details = [];
    }


    $deposit =
        $ad['deposit']
        ?? '';


    if (
        $deposit === '' ||
        $deposit === null
    ) {
        $deposit =
            $details['deposit']
            ?? '';
    }


    $rentMonthly =
        $ad['rent_monthly']
        ?? '';


    if (
        $rentMonthly === '' ||
        $rentMonthly === null
    ) {
        $rentMonthly =
            $details['rent_monthly']
            ?? '';
    }


    $fullRent =
        $ad['full_rent']
        ?? '';


    if (
        $fullRent === '' ||
        $fullRent === null
    ) {
        $fullRent =
            $details['full_rent']
            ?? '';
    }


    $fullRentEnabled =
        $ad['full_rent_enabled']
        ?? '';


    if (
        $fullRentEnabled === '' ||
        $fullRentEnabled === null
    ) {
        $fullRentEnabled =
            $details['full_rent_enabled']
            ?? '0';
    }


    $depositNum =
        $toNumber($deposit);

    $rentNum =
        $toNumber($rentMonthly);

    $fullRentNum =
        $toNumber($fullRent);


    /*
     * رهن کامل
     */
    if (
        (string)$fullRentEnabled === '1' &&
        $fullRentNum > 0
    ) {

        return
            toPersianDigits(
                number_format(
                    $fullRentNum,
                    0,
                    '.',
                    ','
                )
            )
            .
            ' تومان رهن کامل';
    }


    /*
     * ودیعه + اجاره
     */
    $parts = [];


    if ($depositNum > 0) {

        $parts[] =
            'ودیعه: ' .
            number_format(
                $depositNum,
                0,
                '.',
                ','
            ) .
            ' تومان';
    }


    if ($rentNum > 0) {

        $parts[] =
            'اجاره: ' .
            number_format(
                $rentNum,
                0,
                '.',
                ','
            ) .
            ' تومان';
    }


    if (!empty($parts)) {

        return implode(
            "\n",
            $parts
        );
    }


    /*
     * فروش / پیش فروش
     */
    $price =
        $ad['price']
        ?? '';


    $transactionType =
        trim(
            (string)(
                $ad['transaction_type']
                ?? $ad['transactionType']
                ?? ''
            )
        );


    if (
        $transactionType === 'فروش' &&
        !empty($ad['price_sell'])
    ) {

        $price =
            $ad['price_sell'];

    } elseif (
        $transactionType === 'پیش فروش' &&
        !empty($ad['total_price'])
    ) {

        $price =
            $ad['total_price'];
    }


    $priceNum =
        $toNumber($price);


    if ($priceNum > 0) {

        return
            toPersianDigits(
                number_format(
                    $priceNum,
                    0,
                    '.',
                    ','
                )
            )
            .
            ' تومان';
    }


    return 'تماس بگیرید';
}


/* =====================================================
   Transaction Label
   ===================================================== */

function getTransactionLabel($type)
{
    $labels = [
        'فروش' =>
            'فروش',

        'پیش فروش' =>
            'پیش فروش',

        'اجاره' =>
            'اجاره'
    ];


    return
        $labels[$type]
        ?? $type;
}


/* =====================================================
   Onboarding Logo
   ===================================================== */

$onboardingLogoUrl = '';


$logoMetaFile =
    __DIR__ .
    '/uploads/onboarding-logo.json';


if (file_exists($logoMetaFile)) {

    $logoMeta =
        json_decode(
            file_get_contents(
                $logoMetaFile
            ),
            true
        );


    if (
        is_array($logoMeta) &&
        !empty($logoMeta['url'])
    ) {

        $candidateLogo =
            ltrim(
                $logoMeta['url'],
                '/'
            );


        $candidateLogoPath =
            __DIR__ .
            '/' .
            $candidateLogo;


        if (
            file_exists(
                $candidateLogoPath
            )
        ) {

            $onboardingLogoUrl =
                $logoMeta['url'];
        }
    }
}


if (
    $onboardingLogoUrl === ''
) {

    $logoFiles =
        glob(
            __DIR__ .
            '/uploads/onboarding-logo.*'
        );


    if (!empty($logoFiles)) {

        $logoFile =
            $logoFiles[0];


        $onboardingLogoUrl =
            'uploads/' .
            basename($logoFile);
    }
}

?>


<?php
require_once __DIR__ . '/header.php';
?>


<style>

/* =====================================================
   HOME PAGE
   ===================================================== */

.home-content {
    flex: 1 1 auto;

    min-height: 0;

    width: 100%;

    overflow-y: auto;
    overflow-x: hidden;

    box-sizing: border-box;

    padding:
        14px
        14px
        104px;

    background:
        radial-gradient(
            circle at 100% 0%,
            rgba(212,175,55,.08),
            transparent 24%
        ),
        linear-gradient(
            180deg,
            var(--bg) 0%,
            color-mix(
                in srgb,
                var(--bg) 94%,
                var(--primary) 6%
            ) 100%
        );

    -webkit-overflow-scrolling: touch;
}


/* =====================================================
   Shortcut Menu
   ===================================================== */

.home-shortcuts {

    display: grid;

    grid-template-columns:
        repeat(6, minmax(0, 1fr));

    gap: 10px;

    padding: 14px;

    margin:
        8px
        0
        2px;

    background:
        rgba(255,255,255,.62);

    border:
        1px solid
        rgba(6,78,78,.08);

    border-radius: 24px;

    box-shadow:
        0 12px 34px
        rgba(0,0,0,.055);

    backdrop-filter:
        blur(12px);

    -webkit-backdrop-filter:
        blur(12px);
}


.home-shortcut {

    display: flex;

    flex-direction: column;

    align-items: center;

    gap: 7px;

    width: auto;

    min-width: 0;

    padding:
        6px
        2px
        4px;

    border-radius: 18px;

    text-decoration: none;

    color: inherit;

    transition:
        transform .22s ease,
        background .22s ease;
}


.home-shortcut:hover {

    transform:
        translateY(-2px);

    background:
        rgba(255,255,255,.72);
}


.home-shortcut:active {

    transform:
        scale(.97);
}


.home-shortcut-icon {

    width: 58px;
    height: 58px;

    border-radius: 19px;

    display: flex;

    justify-content: center;
    align-items: center;

    background:
        linear-gradient(
            145deg,
            #ffffff,
            #f2f5f3
        );

    color:
        var(--primary);

    box-shadow:
        0 9px 20px
        rgba(0,0,0,.06),
        inset
        0 0 0 1px
        rgba(6,78,78,.06);
}


.home-shortcut.primary
.home-shortcut-icon {

    background:
        linear-gradient(
            145deg,
            var(--gold),
            #f0d673
        );

    color:
        #172121;

    box-shadow:
        0 10px 22px
        rgba(212,175,55,.25);
}


.home-shortcut-label {

    font-size: 10px;

    line-height: 1.5;

    font-weight: 700;

    color:
        var(--text-secondary);

    text-align: center;
}


.home-shortcut.primary
.home-shortcut-label {

    color:
        var(--primary);
}


/* =====================================================
   ADS SECTION
   ===================================================== */

.ads-section {

    width: 100%;

    display: grid;

    grid-template-columns:
        repeat(
            3,
            minmax(
                0,
                1fr
            )
        );

    gap: 16px;

    padding:
        16px
        2px
        0;

    align-items: start;
}


/* =====================================================
   ADS HEADER
   ===================================================== */

.ads-header {

    grid-column:
        1 / -1;

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

    width: 100%;

    margin:
        3px
        2px
        0;

    padding:
        0
        2px;

    box-sizing:
        border-box;
}


.ads-title {

    position: relative;

    padding-right: 12px;

    font-size: 20px;

    font-weight: 800;

    letter-spacing:
        -.2px;

    color:
        var(--text-primary);
}


.ads-title::before {

    content: "";

    position: absolute;

    right: 0;

    top: 50%;

    width: 4px;

    height: 22px;

    transform:
        translateY(-50%);

    border-radius:
        999px;

    background:
        linear-gradient(
            180deg,
            var(--gold),
            #f0d673
        );
}


.ads-count {

    padding:
        7px
        11px;

    border-radius:
        999px;

    background:
        rgba(212,175,55,.10);

    border:
        1px solid
        rgba(212,175,55,.18);

    color:
        var(--primary);

    font-size: 12px;

    font-weight:
        700;

    white-space:
        nowrap;
}


/* =====================================================
   AD CARD LINK
   ===================================================== */

.ads-section > a {

    display:
        block;

    width:
        100%;

    min-width:
        0;

    color:
        inherit;

    text-decoration:
        none;
}


/* =====================================================
   AD CARD
   ===================================================== */

.ad-card {

    width:
        100%;

    height:
        100%;

    margin:
        0;

    overflow:
        hidden;

    background:
        rgba(255,255,255,.78);

    border:
        1px solid
        rgba(6,78,78,.09);

    border-radius:
        24px;

    box-shadow:
        0 14px 36px
        rgba(0,0,0,.07);

    cursor:
        pointer;

    transition:
        transform .25s ease,
        box-shadow .25s ease;
}


.ad-card:hover {

    transform:
        translateY(-4px);

    box-shadow:
        0 20px 44px
        rgba(0,0,0,.10);
}


.ad-card:active {

    transform:
        scale(.985);
}


/* =====================================================
   IMAGE
   ===================================================== */

.ad-card-image {

    width:
        100%;

    height:
        220px;

    overflow:
        hidden;

    position:
        relative;

    background:
        linear-gradient(
            135deg,
            rgba(212,175,55,.15),
            rgba(6,78,78,.06)
        ),
        var(--gold-bg);
}


.ad-card-image img {

    width:
        100%;

    height:
        100%;

    object-fit:
        cover;

    display:
        block;

    transition:
        transform .55s ease,
        filter .35s ease;
}


.ad-card:hover
.ad-card-image img {

    transform:
        scale(1.045);

    filter:
        saturate(1.04);
}


.ad-card-image .no-image {

    display:
        flex;

    justify-content:
        center;

    align-items:
        center;

    height:
        100%;

    color:
        var(--text-secondary);

    font-size:
        14px;
}


/* =====================================================
   BADGES (نوع معامله و کلید نخورده)
   ===================================================== */

.ad-card-badges {
    position: absolute;
    top: 12px;
    right: 12px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    z-index: 2;
}


.ad-card-badge {
    padding: 7px 12px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    backdrop-filter: blur(8px);
    box-shadow: 0 7px 18px rgba(0,0,0,.15);
    white-space: nowrap;
    display: inline-block;
}


.ad-card-badge.transaction {
    background: rgba(6,78,78,.88);
    color: #fff;
}


.ad-card-badge.key-not-turned {
    background: var(--gold);
    color: #172121;
    border: 1px solid rgba(255,255,255,.2);
}


/* =====================================================
   CARD BODY
   ===================================================== */

.ad-card-body {

    padding:
        15px
        16px
        13px;
}


.ad-card-title {

    margin-bottom:
        8px;

    color:
        var(--text-primary);

    font-size:
        17px;

    font-weight:
        800;

    line-height:
        1.7;

    overflow-wrap:
        anywhere;
}


.ad-card-location {

    display:
        flex;

    align-items:
        center;

    gap:
        4px;

    margin-bottom:
        9px;

    color:
        var(--text-secondary);

    font-size:
        12px;

    line-height:
        1.7;
}


.ad-card-details {

    display:
        flex;

    flex-wrap:
        wrap;

    gap:
        6px;

    margin-bottom:
        10px;

    font-size:
        11px;
}


.ad-card-details span {

    padding:
        5px
        9px;

    border-radius:
        999px;

    background:
        #f7f8f6;

    border:
        1px solid
        rgba(6,78,78,.07);

    color:
        var(--text-secondary);

    font-size:
        11px;

    font-weight:
        600;
}


.ad-card-price {

    margin-top:
        4px;

    color:
        var(--primary);

    font-size:
        19px;

    font-weight:
        900;

    line-height:
        1.9;

    white-space:
        pre-line;

    overflow-wrap:
        anywhere;
}


/* =====================================================
   CARD FOOTER
   ===================================================== */

.ad-card-footer {

    display:
        flex;

    justify-content:
        space-between;

    align-items:
        center;

    gap:
        8px;

    padding:
        11px
        16px;

    border-top:
        1px solid
        rgba(6,78,78,.07);

    background:
        rgba(6,78,78,.025);

    color:
        var(--text-secondary);

    font-size:
        10px;
}


/* =====================================================
   EMPTY
   ===================================================== */

.ads-empty {

    grid-column:
        1 / -1;

    padding:
        50px
        20px;

    text-align:
        center;

    color:
        var(--text-secondary);
}


/* =====================================================
   TABLET
   ===================================================== */

@media (min-width: 601px)
and (max-width: 1000px) {

    .home-content {

        padding:
            12px
            12px
            104px;
    }


    .home-shortcuts {

        grid-template-columns:
            repeat(
                3,
                minmax(
                    0,
                    1fr
                )
            );
    }


    .ads-section {

        grid-template-columns:
            repeat(
                2,
                minmax(
                    0,
                    1fr
                )
            );

        gap:
            14px;

        padding:
            14px
            0
            0;
    }


    .ad-card-image {

        height:
            210px;
    }
}


/* =====================================================
   MOBILE
   ===================================================== */

@media (max-width: 600px) {

    .home-content {

        padding:
            9px
            9px
            104px;
    }


    .home-shortcuts {

        grid-template-columns:
            repeat(
                3,
                minmax(
                    0,
                    1fr
                )
            );

        gap:
            8px;

        padding:
            12px
            9px;

        border-radius:
            20px;
    }


    .home-shortcut-icon {

        width:
            54px;

        height:
            54px;

        border-radius:
            17px;
    }


    .home-shortcut-label {

        font-size:
            9px;
    }


    .ads-section {

        grid-template-columns:
            1fr;

        gap:
            12px;

        padding:
            14px
            0
            0;
    }


    .ads-header {

        margin:
            0
            2px
            0;
    }


    .ads-title {

        font-size:
            18px;
    }


    .ads-count {

        font-size:
            10px;

        padding:
            6px
            9px;
    }


    .ad-card {

        border-radius:
            20px;
    }


    .ad-card-image {

        height:
            205px;
    }


    .ad-card-body {

        padding:
            14px
            14px
            12px;
    }


    .ad-card-title {

        font-size:
            16px;
    }


    .ad-card-price {

        font-size:
            18px;
    }


    .ad-card-footer {

        padding:
            10px
            14px;
    }
}


/* =====================================================
   VERY SMALL MOBILE
   ===================================================== */

@media (max-width: 430px) {

    .home-shortcuts {

        gap:
            6px;

        padding:
            11px
            7px;
    }


    .home-shortcut-icon {

        width:
            50px;

        height:
            50px;

        border-radius:
            15px;
    }


    .home-shortcut-label {

        font-size:
            8.5px;
    }


    .ads-section {

        gap:
            10px;
    }


    .ad-card-image {

        height:
            195px;
    }
}


/* =====================================================
   DARK MODE
   ===================================================== */

[data-theme="dark"]
.home-content {

    background:
        radial-gradient(
            circle at 100% 0%,
            rgba(229,184,66,.07),
            transparent 24%
        ),
        linear-gradient(
            180deg,
            #0B1616 0%,
            #0F1D1D 100%
        );
}


[data-theme="dark"]
.home-shortcuts {

    background:
        #152727;

    border-color:
        #294646;

    box-shadow:
        0 12px 34px
        rgba(0,0,0,.28);
}


[data-theme="dark"]
.home-shortcut {

    background:
        transparent;
}


[data-theme="dark"]
.home-shortcut:hover {

    background:
        rgba(255,255,255,.045);
}


[data-theme="dark"]
.home-shortcut-icon {

    background:
        linear-gradient(
            145deg,
            #203737,
            #182D2D
        );

    color:
        #72D2CC;

    box-shadow:
        0 8px 18px
        rgba(0,0,0,.28),

        inset
        0 0 0 1px
        rgba(114,210,204,.09);
}


[data-theme="dark"]
.home-shortcut.primary
.home-shortcut-icon {

    background:
        linear-gradient(
            145deg,
            #E7C65A,
            #CBA83B
        );

    color:
        #142020;

    box-shadow:
        0 10px 22px
        rgba(212,175,55,.22);
}


[data-theme="dark"]
.home-shortcut-label {

    color:
        #E3EFED;

    text-shadow:
        0 1px 2px
        rgba(0,0,0,.28);
}


[data-theme="dark"]
.home-shortcut.primary
.home-shortcut-label {

    color:
        #E7C65A;
}


[data-theme="dark"]
.ads-title {

    color:
        #F4F8F7;
}


[data-theme="dark"]
.ads-count {

    color:
        #E7C65A;

    background:
        rgba(231,198,90,.10);

    border-color:
        rgba(231,198,90,.22);
}


[data-theme="dark"]
.ad-card {

    background:
        #142525;

    border-color:
        #294646;

    box-shadow:
        0 14px 36px
        rgba(0,0,0,.28);
}


[data-theme="dark"]
.ad-card:hover {

    box-shadow:
        0 20px 44px
        rgba(0,0,0,.38);
}


[data-theme="dark"]
.ad-card-details span {

    background:
        #1B3232;

    border-color:
        #2E4A4A;

    color:
        #CFE0DD;
}


[data-theme="dark"]
.ad-card-price {

    color:
        #E7C65A;
}


[data-theme="dark"]
.ad-card-footer {

    background:
        #102020;

    border-top-color:
        #294646;
}


/* =====================================================
   LAYOUT FIX
   ===================================================== */

html,
body {

    min-height:
        100%;
}


body {

    margin:
        0;
}


.app-container {

    min-height:
        100dvh;

    display:
        flex;

    flex-direction:
        column;

    overflow:
        hidden;
}


.home-content {

    flex:
        1 1 auto;

    min-height:
        0;

    width:
        100%;

    box-sizing:
        border-box;

    overflow-y:
        auto;

    overflow-x:
        hidden;

    -webkit-overflow-scrolling:
        touch;
}


.home-content + .bottom-nav,
.home-content + footer {

    flex:
        0 0 auto;
}

</style>


<div class="app-container">


    <main class="home-content">


        <!-- =================================================
             Shortcut Menu
             ================================================= -->

        <section class="home-shortcuts">


            <!-- ثبت ملک -->

            <a
                href="register-step1.php"
                class="home-shortcut primary"
            >

                <div class="home-shortcut-icon">

                    <svg
                        width="24"
                        height="24"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >

                        <path d="M12 5v14"></path>

                        <path d="M5 12h14"></path>

                    </svg>

                </div>

                <span class="home-shortcut-label">
                    ثبت ملک
                </span>

            </a>


            <!-- ثبت درخواست -->

            <a
                href="property-request.php"
                class="home-shortcut"
            >

                <div class="home-shortcut-icon">

                    <svg
                        width="24"
                        height="24"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >

                        <path d="M12 2v4"></path>

                        <path d="M12 22v-4"></path>

                        <path d="M4 12H2"></path>

                        <path d="M22 12h-2"></path>

                        <path d="M19.07 4.93l-1.41 1.41"></path>

                        <path d="M4.93 19.07l1.41-1.41"></path>

                        <path d="M19.07 19.07l-1.41-1.41"></path>

                        <path d="M4.93 4.93l1.41 1.41"></path>

                        <circle
                            cx="12"
                            cy="12"
                            r="4"
                        ></circle>

                    </svg>

                </div>

                <span class="home-shortcut-label">
                    ثبت درخواست
                </span>

            </a>


            <!-- جستجو -->

            <a
                href="search.php"
                class="home-shortcut"
            >

                <div class="home-shortcut-icon">

                    <svg
                        width="24"
                        height="24"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >

                        <circle
                            cx="11"
                            cy="11"
                            r="8"
                        ></circle>

                        <line
                            x1="21"
                            y1="21"
                            x2="16.65"
                            y2="16.65"
                        ></line>

                    </svg>

                </div>

                <span class="home-shortcut-label">
                    جستجو
                </span>

            </a>


            <!-- همه آگهی‌ها -->

            <a
                href="properties.php"
                class="home-shortcut"
            >

                <div class="home-shortcut-icon">

                    <svg
                        width="24"
                        height="24"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >

                        <rect
                            x="3"
                            y="3"
                            width="18"
                            height="18"
                            rx="2"
                            ry="2"
                        ></rect>

                        <line
                            x1="8"
                            y1="8"
                            x2="16"
                            y2="8"
                        ></line>

                        <line
                            x1="8"
                            y1="12"
                            x2="16"
                            y2="12"
                        ></line>

                        <line
                            x1="8"
                            y1="16"
                            x2="13"
                            y2="16"
                        ></line>

                    </svg>

                </div>

                <span class="home-shortcut-label">
                    همه آگهی‌ها
                </span>

            </a>


            <!-- VIP -->

            <a
                href="vip.php"
                class="home-shortcut"
            >

                <div class="home-shortcut-icon">

                    <svg
                        width="24"
                        height="24"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >

                        <polygon
                            points="
                                12 2
                                15.09 8.26
                                22 9.27
                                17 14.14
                                18.18 21.02
                                12 17.77
                                5.82 21.02
                                7 14.14
                                2 9.27
                                8.91 8.26
                            "
                        ></polygon>

                    </svg>

                </div>

                <span class="home-shortcut-label">
                    فایل‌های VIP
                </span>

            </a>


            <!-- ارتباط با ما -->

            <a
                href="contact.php"
                class="home-shortcut"
            >

                <div class="home-shortcut-icon">

                    <svg
                        width="24"
                        height="24"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >

                        <path
                            d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"
                        ></path>

                    </svg>

                </div>

                <span class="home-shortcut-label">
                    ارتباط با ما
                </span>

            </a>

        </section>


        <!-- =================================================
             Published Ads
             ================================================= -->

        <section class="ads-section">


            <div class="ads-header">

                <span class="ads-title">
                    آگهی‌های منتشر شده
                </span>

                <span class="ads-count">
                    <?= count($publishedAds) ?> آگهی
                </span>

            </div>


            <?php if (empty($publishedAds)): ?>


                <div class="ads-empty">

                    <svg
                        width="64"
                        height="64"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        style="margin-bottom:12px;"
                    >

                        <rect
                            x="2"
                            y="7"
                            width="20"
                            height="14"
                            rx="2"
                            ry="2"
                        ></rect>

                        <path
                            d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"
                        ></path>

                    </svg>


                    <p
                        style="
                            font-size:16px;
                            font-weight:600;
                            margin:0 0 8px;
                        "
                    >
                        هیچ آگهی منتشر شده‌ای وجود ندارد
                    </p>


                    <p
                        style="
                            font-size:14px;
                            margin:0;
                        "
                    >
                        آگهی‌ها پس از تأیید ادمین در اینجا نمایش داده می‌شوند.
                    </p>

                </div>


            <?php else: ?>


                <?php foreach (
                    $publishedAds
                    as $melkinoAdIndex
                    => $ad
                ): ?>


                    <a
                        href="property-details.php?id=<?= urlencode(
                            (string)$ad['id']
                        ) ?>"
                        style="
                            text-decoration:none;
                            color:inherit;
                            display:block;
                        "
                    >

                        <article class="ad-card">


                            <!-- تصویر -->

                            <div class="ad-card-image">


                                <?php

                                $firstImage =
                                    getFirstImage(
                                        $ad['selected_images']
                                        ?? []
                                    );


                                $fallbackLogo =
                                    $onboardingLogoUrl !== ''
                                        ? $onboardingLogoUrl
                                        : 'assets/logo.png';

                                ?>


                                <?php if (!empty($firstImage)): ?>


                                    <img
                                        src="<?= htmlspecialchars(
                                            $firstImage,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        alt="<?= htmlspecialchars(
                                            $ad['title'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        loading="lazy"
                                        onerror="this.onerror=null;this.src='<?= htmlspecialchars(
                                            $fallbackLogo,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>';this.style.objectFit='contain';this.style.padding='18px';this.style.background='var(--surface)';"
                                    >


                                <?php else: ?>


                                    <img
                                        src="<?= htmlspecialchars(
                                            $fallbackLogo,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        alt="لوگوی ملکینو"
                                        style="
                                            width:100%;
                                            height:100%;
                                            object-fit:contain;
                                            display:block;
                                            padding:18px;
                                            box-sizing:border-box;
                                        "
                                    >


                                <?php endif; ?>


                                <div class="ad-card-badges">

                                    <span class="ad-card-badge transaction">
                                        <?= htmlspecialchars(
                                            getTransactionLabel(
                                                $ad['transaction_type']
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>

                                    <?php if (!empty($ad['is_not_keyed'])): ?>
                                        <span class="ad-card-badge key-not-turned">
                                            🔑 کلید نخورده
                                        </span>
                                    <?php endif; ?>

                                </div>


                            </div>


                            <!-- اطلاعات -->

                            <div class="ad-card-body">


                                <div class="ad-card-title">

                                    <?= htmlspecialchars(
                                        $ad['title'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </div>


                                <div class="ad-card-location">

                                    <svg
                                        width="14"
                                        height="14"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                    >

                                        <path
                                            d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"
                                        ></path>

                                        <circle
                                            cx="12"
                                            cy="10"
                                            r="3"
                                        ></circle>

                                    </svg>


                                    <?= htmlspecialchars(
                                        $ad['location']
                                        ?: 'موقعیت نامشخص',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </div>


                                <div class="ad-card-details">


                                    <span>

                                        <?= htmlspecialchars(
                                            $ad['property_type'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    </span>


                                    <?php if (
                                        !empty(
                                            $ad['details']['area']
                                        )
                                    ): ?>

                                        <span>

                                            <?= htmlspecialchars(
                                                $ad['details']['area'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                            متر

                                        </span>

                                    <?php endif; ?>


                                    <?php if (
                                        !empty(
                                            $ad['details']['rooms']
                                        )
                                    ): ?>

                                        <span>

                                            <?= htmlspecialchars(
                                                $ad['details']['rooms'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                            اتاق

                                        </span>

                                    <?php endif; ?>


                                </div>


                                <div class="ad-card-price">

                                    <?= htmlspecialchars(
                                        displayPrice($ad),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </div>


                            </div>


                            <!-- Footer -->

                            <div class="ad-card-footer">

                                <span>

                                    <?php

                                    $timestamp =
                                        strtotime(
                                            (string)(
                                                $ad['created_at']
                                                ?? ''
                                            )
                                        );

                                    echo $timestamp
                                        ? date(
                                            'Y/m/d',
                                            $timestamp
                                        )
                                        : '—';

                                    ?>

                                </span>


                                <span>
                                    🔍 مشاهده جزئیات
                                </span>

                            </div>


                        </article>

                    </a>


                    <?php
                    // تزریق تبلیغ بین کارت‌ها (بر اساس تنظیمات پنل ادمین)
                    if (function_exists('melkinoPromotionAfter')) {
                        echo melkinoPromotionAfter('home', (int)$melkinoAdIndex + 1);
                    }
                    ?>

                <?php endforeach; ?>


            <?php endif; ?>


        </section>


    </main>


    <?php
    require_once __DIR__ . '/footer.php';
    ?>

</div>