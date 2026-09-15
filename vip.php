<?php
session_start();

require_once __DIR__ . '/config.php';

// قبلاً این مقدار از یک فایل JSON قدیمی (settings/global.json) خونده می‌شد
// که هیچ‌جای کد هرگز آپدیت نمی‌شد؛ یعنی وقتی ادمین از پنل «نمایش قیمت‌ها»
// رو تغییر می‌داد (که در دیتابیس ذخیره می‌شه)، این صفحه هنوز مقدار قدیمی
// رو نشون می‌داد. حالا مستقیم از همون تنظیمات دیتابیسی خونده می‌شه.
$__globalPriceSettings = getGlobalSettings();
$__hideAllPublicPrices = (!$__globalPriceSettings['show_prices'] || !empty($__globalPriceSettings['hide_all_prices']));


/* =====================================================
   GET SITE LOGO URL
   ===================================================== */

function getSiteLogoUrl(): string
{
    $metaFile = __DIR__ . '/uploads/onboarding-logo.json';
    if (file_exists($metaFile)) {
        $meta = json_decode(file_get_contents($metaFile), true);
        if (is_array($meta) && !empty($meta['url'])) {
            return $meta['url'];
        }
    }

    $files = glob(__DIR__ . '/uploads/onboarding-logo.*');
    if (!empty($files)) {
        return 'uploads/' . basename($files[0]);
    }

    return '';
}


/* =====================================================
   تنظیمات
   ===================================================== */

$isMockMode =
    defined('MOCK_MODE')
        ? MOCK_MODE
        : true;


/* =====================================================
   دریافت اولین تصویر
   ===================================================== */

function getVipFirstImage($images)
{
    if (is_string($images)) {
        $decoded = json_decode($images, true);
        $images = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($images)) {
        return null;
    }

    foreach ($images as $image) {

        if (!is_string($image)) {
            continue;
        }

        $image = trim($image);

        if ($image === '') {
            continue;
        }

        $path = __DIR__ . '/' . ltrim($image, '/');

        if (file_exists($path)) {
            return $image;
        }
    }

    return null;
}


/* =====================================================
   JSON Decode Helper
   ===================================================== */

function vipDecodeArray($value)
{
    if (is_array($value)) {
        return $value;
    }

    if (is_string($value) && trim($value) !== '') {

        $decoded =
            json_decode(
                $value,
                true
            );

        return is_array($decoded)
            ? $decoded
            : [];
    }

    return [];
}


/* =====================================================
   قیمت — اصلاح‌شده برای نمایش رهن | اجاره
   ===================================================== */

function vipDisplayPrice($ad)
{
    global $__hideAllPublicPrices;
    if ($__hideAllPublicPrices || !empty($ad['price_hidden'])) {
        return 'برای استعلام قیمت تماس بگیرید';
    }

    $price =
        $ad['display_price']
        ?? '';

    if (
        $price === '' ||
        $price === '0' ||
        $price === 0
    ) {

        $price =
            $ad['price_sell']
            ?? '';

    }

    if (
        $price === '' ||
        $price === '0' ||
        $price === 0
    ) {

        $price =
            $ad['total_price']
            ?? '';

    }

    if (
        $price === '' ||
        $price === '0' ||
        $price === 0
    ) {

        $deposit =
            $ad['deposit']
            ?? '';

        $rent =
            $ad['rent_monthly']
            ?? '';

        if (
            $deposit !== '' &&
            $deposit !== '0'
        ) {

            $formatted = number_format((float)$deposit) . ' تومان ودیعه';

            if (
                $rent !== '' &&
                $rent !== '0'
            ) {
                $formatted .= ' · ' . number_format((float)$rent) . ' تومان اجاره';
            }

            return $formatted;
        }
    }

    if (
        $price !== '' &&
        $price !== '0'
    ) {

        // اگر شامل '|' باشد، رهن و اجاره هر دو هستند
        if (strpos($price, '|') !== false) {
            return htmlspecialchars($price, ENT_QUOTES, 'UTF-8');
        }

        if (
            is_numeric(
                str_replace(
                    ',',
                    '',
                    (string)$price
                )
            )
        ) {

            return
                number_format(
                    (float)str_replace(
                        ',',
                        '',
                        (string)$price
                    )
                )
                . ' تومان';
        }

        return (string)$price;
    }

    return 'تماس بگیرید';
}


/* =====================================================
   دریافت آگهی‌های VIP
   ===================================================== */

$vipAds = [];


/* =====================================================
   MOCK MODE
   ===================================================== */

if ($isMockMode) {

    $jsonFile =
        __DIR__ . '/ads.json';

    $adsFromJson = [];


    if (
        file_exists(
            $jsonFile
        )
    ) {

        $content =
            file_get_contents(
                $jsonFile
            );

        $decoded =
            json_decode(
                $content,
                true
            );

        if (
            is_array(
                $decoded
            )
        ) {

            $adsFromJson =
                $decoded;
        }
    }


    foreach (
        $adsFromJson
        as $ad
    ) {

        if (
            ($ad['status'] ?? '')
            !== 'published'
        ) {
            continue;
        }

        $isVip =
            (
                ($ad['is_vip'] ?? false)
                === true
                ||
                ($ad['is_vip'] ?? false)
                === 1
                ||
                ($ad['is_vip'] ?? false)
                === '1'
            );

        if (!$isVip) {
            continue;
        }

        $selectedImages =
            vipDecodeArray(
                $ad['selected_images']
                ??
                $ad['selectedImages']
                ??
                []
            );

        $details =
            vipDecodeArray(
                $ad['property_details']
                ?? []
            );

        $amenities =
            vipDecodeArray(
                $ad['amenities']
                ?? []
            );

        $vipAds[] = [

            'id' =>
                (string)(
                    $ad['id']
                    ?? ''
                ),

            'title' =>
                $ad['title']
                ??
                'ملک بدون عنوان',

            'transaction_type' =>
                $ad['transaction_type']
                ??
                $ad['transactionType']
                ??
                'فروش',

            'property_type' =>
                $ad['property_type']
                ??
                $ad['propertyType']
                ??
                'آپارتمان',

            'location' =>
                $ad['location']
                ?? '',

            'display_price' =>
                $ad['display_price']
                ?? '',

            'price_sell' =>
                $ad['price_sell']
                ?? '',

            'total_price' =>
                $ad['total_price']
                ?? '',

            'deposit' =>
                $ad['deposit']
                ?? '',

            'rent_monthly' =>
                $ad['rent_monthly'],
            'price_hidden' => !empty($ad['price_hidden']),

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
                ??
                date(
                    'Y-m-d H:i:s'
                ),

        ];
    }

}


/* =====================================================
   DATABASE MODE
   ===================================================== */

else {
    try {
        if (!isset($pdo) || !$pdo instanceof PDO) { throw new PDOException('اتصال به دیتابیس برقرار نشد.'); }
        $stmt = $pdo->query("SELECT * FROM ads WHERE status = 'published' AND is_vip = 1 ORDER BY created_at DESC");
        $adsFromDB = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($adsFromDB as $ad) {
            $details = vipDecodeArray($ad['property_details'] ?? []);
            $imgStmt = $pdo->prepare("SELECT filename FROM images WHERE ad_id = ? AND is_selected = 1 AND publish_publicly = 1 ORDER BY is_primary DESC, sort_order ASC, id ASC");
            $imgStmt->execute([$ad['id']]);
            $selectedImages = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
            $amenStmt = $pdo->prepare("SELECT am.name FROM ad_amenities aa INNER JOIN amenities am ON am.id = aa.amenity_id WHERE aa.ad_id = ? ORDER BY am.sort_order, am.id");
            $amenStmt->execute([$ad['id']]);
            $amenities = $amenStmt->fetchAll(PDO::FETCH_COLUMN);
            $vipAds[] = [
                'id' => (string)$ad['id'], 'title' => $ad['title'] ?? 'ملک بدون عنوان',
                'transaction_type' => $ad['transaction_type'] ?? 'فروش', 'property_type' => $ad['property_type'] ?? 'آپارتمان',
                'location' => $ad['location'] ?? '', 'display_price' => $ad['display_price'] ?? '',
                'price_sell' => $ad['price_sell'] ?? '', 'total_price' => $ad['total_price'] ?? '',
                'deposit' => $ad['deposit'] ?? '', 'rent_monthly' => $ad['rent_monthly'] ?? '',
                'price_hidden' => !empty($ad['price_hidden']), 'description' => $ad['description'] ?? '',
                'selected_images' => $selectedImages, 'details' => $details, 'amenities' => $amenities,
                'created_at' => $ad['created_at'] ?? date('Y-m-d H:i:s')
            ];
        }
    } catch (PDOException $e) { $vipAds = []; }
}


/* =====================================================
   جدیدترین VIP ابتدا
   ===================================================== */

usort(
    $vipAds,
    static function (
        $a,
        $b
    ) {

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


/* =====================================================
   تعداد
   ===================================================== */

$vipCount =
    count($vipAds);

?>
<!DOCTYPE html>

<html
    lang="fa"
    dir="rtl"
>

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"
    >

    <title>
        فایل‌های VIP | ملکینو
    </title>


    <!-- Vazirmatn -->

    <link
        href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css"
        rel="stylesheet"
    >


    <!-- Global CSS -->

    <link
        rel="stylesheet"
        href="style.css"
    >


    <style>

        /* =====================================================
           VIP PAGE
           ===================================================== */

        :root {

            --vip-primary: #075b59;

            --vip-primary-dark: #043f3e;

            --vip-gold: #c9a227;

            --vip-gold-light: #e5c75a;

            --vip-bg: #f6f8f7;

            --vip-surface: #ffffff;

            --vip-border: #e4e9e7;

            --vip-text: #172222;

            --vip-muted: #6c7977;

            --vip-shadow:
                0 10px 30px
                rgba(18, 42, 40, .08);

        }


        /* =====================================================
           Container
           ===================================================== */

        .vip-page {

            min-height: 100%;

            overflow-y: auto;

            padding:
                16px
                16px
                105px;

            background:
                var(--vip-bg);

        }


        /* =====================================================
           Hero
           ===================================================== */

        .vip-hero {

            position: relative;

            overflow: hidden;

            border-radius: 24px;

            padding: 22px;

            margin-bottom: 20px;

            background:
                linear-gradient(
                    135deg,
                    #075b59 0%,
                    #043f3e 100%
                );

            color: #fff;

            box-shadow:
                0 16px 36px
                rgba(4, 63, 62, .18);

        }


        .vip-hero::before {

            content: "";

            position: absolute;

            width: 180px;

            height: 180px;

            left: -70px;

            top: -90px;

            border-radius: 50%;

            border:
                1px solid
                rgba(229,199,90,.25);

        }


        .vip-hero::after {

            content: "★";

            position: absolute;

            left: 22px;

            bottom: -30px;

            font-size: 130px;

            line-height: 1;

            color:
                rgba(229,199,90,.07);

        }


        .vip-hero-content {

            position: relative;

            z-index: 2;

        }


        .vip-hero-top {

            display: flex;

            align-items: center;

            gap: 12px;

            margin-bottom: 12px;

        }


        .vip-star {

            width: 48px;

            height: 48px;

            flex: 0 0 48px;

            display: flex;

            align-items: center;

            justify-content: center;

            border-radius: 16px;

            background:
                linear-gradient(
                    145deg,
                    var(--vip-gold-light),
                    var(--vip-gold)
                );

            color: #17302e;

            box-shadow:
                0 8px 20px
                rgba(0,0,0,.15);

        }


        .vip-hero-title {

            margin: 0;

            font-size: 22px;

            font-weight: 900;

        }


        .vip-hero-subtitle {

            margin: 3px 0 0;

            color:
                rgba(255,255,255,.72);

            font-size: 12px;

        }


        .vip-hero-description {

            position: relative;

            z-index: 2;

            margin: 0;

            max-width: 520px;

            line-height: 1.9;

            font-size: 13px;

            color:
                rgba(255,255,255,.86);

        }


        /* =====================================================
           Header
           ===================================================== */

        .vip-section-header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 12px;

            margin:
                4px
                2px
                14px;

        }


        .vip-section-title {

            display: flex;

            align-items: center;

            gap: 8px;

            margin: 0;

            font-size: 18px;

            font-weight: 850;

            color:
                var(--vip-text);

        }


        .vip-section-title::before {

            content: "";

            width: 4px;

            height: 21px;

            border-radius: 99px;

            background:
                linear-gradient(
                    180deg,
                    var(--vip-gold-light),
                    var(--vip-gold)
                );

        }


        .vip-count {

            padding:
                6px
                11px;

            border-radius: 99px;

            background:
                rgba(201,162,39,.10);

            color:
                var(--vip-gold);

            border:
                1px solid
                rgba(201,162,39,.20);

            font-size: 12px;

            font-weight: 800;

            white-space: nowrap;

        }


        /* =====================================================
           Grid
           ===================================================== */

        .vip-grid {

            display: grid;

            grid-template-columns:
                repeat(
                    3,
                    minmax(0, 1fr)
                );

            gap: 16px;

        }


        /* =====================================================
           Card
           ===================================================== */

        .vip-card {

            display: block;

            overflow: hidden;

            text-decoration: none;

            color: inherit;

            background:
                var(--vip-surface);

            border:
                1px solid
                var(--vip-border);

            border-radius: 22px;

            box-shadow:
                var(--vip-shadow);

            transition:
                transform .22s ease,
                box-shadow .22s ease;

        }


        .vip-card:hover {

            transform:
                translateY(-4px);

            box-shadow:
                0 18px 40px
                rgba(18,42,40,.13);

        }


        .vip-card:active {

            transform:
                scale(.985);

        }


        /* =====================================================
           Image
           ===================================================== */

        .vip-card-image {

            position: relative;

            height: 220px;

            overflow: hidden;

            background:
                #e9efed;

        }


        .vip-card-image img {

            width: 100%;

            height: 100%;

            object-fit: cover;

            display: block;

            transition:
                transform .45s ease;

        }


        .vip-card:hover
        .vip-card-image img {

            transform:
                scale(1.045);

        }


        .vip-no-image {

            width: 100%;

            height: 100%;

            display: flex;

            align-items: center;

            justify-content: center;

            flex-direction: column;

            gap: 8px;

            color:
                var(--vip-muted);

            font-size: 13px;

            background:
                linear-gradient(
                    145deg,
                    #eef3f1,
                    #e3ebe8
                );

        }


        /* =====================================================
           VIP Badge
           ===================================================== */

        .vip-badge {

            position: absolute;

            top: 12px;

            right: 12px;

            display: flex;

            align-items: center;

            gap: 5px;

            padding:
                6px
                10px;

            border-radius: 99px;

            background:
                rgba(255,255,255,.94);

            color:
                #9b7813;

            border:
                1px solid
                rgba(201,162,39,.28);

            font-size: 11px;

            font-weight: 900;

            box-shadow:
                0 5px 15px
                rgba(0,0,0,.12);

            backdrop-filter:
                blur(8px);

        }


        .vip-transaction {

            position: absolute;

            bottom: 12px;

            right: 12px;

            padding:
                6px
                10px;

            border-radius: 99px;

            background:
                rgba(4,63,62,.90);

            color: #fff;

            font-size: 11px;

            font-weight: 700;

            backdrop-filter:
                blur(8px);

        }


        /* =====================================================
           Body
           ===================================================== */

        .vip-card-body {

            padding:
                15px 16px 14px;

        }


        .vip-card-title {

            margin-bottom: 7px;

            color:
                var(--vip-text);

            font-size: 16px;

            font-weight: 850;

            line-height: 1.7;

        }


        .vip-location {

            display: flex;

            align-items: center;

            gap: 5px;

            margin-bottom: 11px;

            color:
                var(--vip-muted);

            font-size: 12px;

            white-space: nowrap;

            overflow: hidden;

            text-overflow: ellipsis;

        }


        .vip-details {

            display: flex;

            flex-wrap: wrap;

            gap: 6px;

            margin-bottom: 12px;

        }


        .vip-detail {

            padding:
                5px
                9px;

            border-radius: 9px;

            background:
                var(--vip-bg);

            border:
                1px solid
                var(--vip-border);

            color:
                var(--vip-muted);

            font-size: 10px;

            font-weight: 650;

        }


        .vip-price {

            color:
                var(--vip-primary);

            font-size: 17px;

            font-weight: 900;

            line-height: 1.6;

        }


        /* =====================================================
           Footer Card
           ===================================================== */

        .vip-card-footer {

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding:
                10px
                16px;

            border-top:
                1px solid
                var(--vip-border);

            color:
                var(--vip-muted);

            font-size: 10px;

        }


        .vip-view {

            color:
                var(--vip-primary);

            font-weight: 800;

        }


        /* =====================================================
           Empty State
           ===================================================== */

        .vip-empty {

            padding:
                55px
                20px;

            text-align: center;

            background:
                var(--vip-surface);

            border:
                1px solid
                var(--vip-border);

            border-radius: 24px;

            box-shadow:
                var(--vip-shadow);

        }


        .vip-empty-icon {

            width: 70px;

            height: 70px;

            display: flex;

            align-items: center;

            justify-content: center;

            margin:
                0
                auto
                16px;

            border-radius: 22px;

            background:
                rgba(201,162,39,.10);

            color:
                var(--vip-gold);

        }


        .vip-empty-title {

            margin: 0 0 7px;

            color:
                var(--vip-text);

            font-size: 17px;

            font-weight: 850;

        }


        .vip-empty-text {

            margin: 0;

            color:
                var(--vip-muted);

            font-size: 12px;

            line-height: 1.9;

        }


        /* =====================================================
           Mobile
           ===================================================== */

        @media (max-width: 900px) {

            .vip-grid {

                grid-template-columns:
                    repeat(
                        2,
                        minmax(0, 1fr)
                    );

            }

        }


        @media (max-width: 600px) {

            .vip-page {

                padding:
                    12px
                    12px
                    100px;

            }


            .vip-hero {

                padding: 18px;

                border-radius: 20px;

            }


            .vip-hero-title {

                font-size: 19px;

            }


            .vip-hero-description {

                font-size: 12px;

            }


            .vip-grid {

                grid-template-columns:
                    1fr;

                gap: 13px;

            }


            .vip-card {

                border-radius: 20px;

            }


            .vip-card-image {

                height: 210px;

            }

        }


        @media (max-width: 380px) {

            .vip-card-image {

                height: 195px;

            }

        }


        /* =====================================================
           VIP FILTERS
           ===================================================== */

        .vip-filters {
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr)) auto;
            gap:10px;
            margin:0 0 16px;
            padding:12px;
            background:var(--vip-surface);
            border:1px solid var(--vip-border);
            border-radius:18px;
            box-shadow:var(--vip-shadow);
        }

        .vip-filter-group {
            display:flex;
            flex-direction:column;
            gap:5px;
        }

        .vip-filter-group label {
            color:var(--vip-muted);
            font-size:10px;
            font-weight:800;
        }

        .vip-filter-group select {
            width:100%;
            height:42px;
            padding:0 11px;
            border:1px solid var(--vip-border);
            border-radius:10px;
            background:var(--vip-bg);
            color:var(--vip-text);
            font-family:'Vazirmatn',sans-serif;
            font-size:12px;
            outline:none;
            cursor:pointer;
        }

        .vip-filter-group select:focus {
            border-color:var(--vip-primary);
        }

        .vip-filter-reset {
            align-self:end;
            height:42px;
            padding:0 14px;
            border:1px solid var(--vip-border);
            border-radius:10px;
            background:transparent;
            color:var(--vip-primary);
            font-family:'Vazirmatn',sans-serif;
            font-size:11px;
            font-weight:800;
            cursor:pointer;
        }

        .vip-filter-reset:hover {
            background:var(--vip-bg);
        }

        .vip-filter-empty {
            display:none;
            margin-top:14px;
            padding:25px 15px;
            text-align:center;
            color:var(--vip-muted);
            background:var(--vip-surface);
            border:1px solid var(--vip-border);
            border-radius:18px;
            font-size:12px;
        }

        @media (max-width:600px) {
            .vip-filters {
                grid-template-columns:1fr 1fr;
            }
            .vip-filter-reset {
                grid-column:1 / -1;
                width:100%;
            }
        }

        @media (max-width:380px) {
            .vip-filters {
                grid-template-columns:1fr;
            }
            .vip-filter-reset {
                grid-column:auto;
            }
        }

        /* =====================================================
           DARK MODE
           ===================================================== */

        [data-theme="dark"] {

            --vip-bg: #0c1818;

            --vip-surface: #142525;

            --vip-border: #294343;

            --vip-text: #eef6f4;

            --vip-muted: #9db1ae;

            --vip-primary: #69c8c1;

            --vip-primary-dark: #4baaa4;

            --vip-gold: #e1bd4d;

            --vip-gold-light: #f0d36b;

            --vip-shadow:
                0 12px 32px
                rgba(0,0,0,.30);

        }


        [data-theme="dark"]
        .vip-page {

            background:
                radial-gradient(
                    circle at 100% 0%,
                    rgba(225,189,77,.055),
                    transparent 28%
                ),
                #0c1818;

        }


        [data-theme="dark"]
        .vip-card {

            box-shadow:
                0 12px 32px
                rgba(0,0,0,.28);

        }


        [data-theme="dark"]
        .vip-card-image {

            background:
                #1a3030;

        }


        [data-theme="dark"]
        .vip-no-image {

            background:
                linear-gradient(
                    145deg,
                    #1a3030,
                    #162929
                );

            color:
                #8fa6a3;

        }


        [data-theme="dark"]
        .vip-detail {

            background:
                #1a3030;

            border-color:
                #315050;

            color:
                #b7c9c6;

        }


        [data-theme="dark"]
        .vip-badge {

            background:
                rgba(20,37,37,.94);

            color:
                #e8ca62;

            border-color:
                rgba(225,189,77,.30);

        }


        [data-theme="dark"]
        .vip-transaction {

            background:
                rgba(4,25,25,.92);

        }


        [data-theme="dark"]
        .vip-card-footer {

            background:
                rgba(0,0,0,.10);

        }


        [data-theme="dark"]
        .vip-empty {

            background:
                #142525;

        }


        /* =====================================================
           Reduced Motion
           ===================================================== */

        @media (prefers-reduced-motion: reduce) {

            .vip-card,
            .vip-card-image img {

                transition: none;

            }

        }

    </style>

</head>


<body>


<div class="app-container">


    <!-- =================================================
         Header
         ================================================= -->

    <?php require_once __DIR__ . '/header.php'; ?>


    <!-- =================================================
         Main
         ================================================= -->

    <main class="vip-page">


        <!-- =================================================
             Hero
             ================================================= -->

        <section class="vip-hero">

            <div class="vip-hero-content">

                <div class="vip-hero-top">

                    <div class="vip-star">

                        <svg
                            width="25"
                            height="25"
                            viewBox="0 0 24 24"
                            fill="currentColor"
                        >
                            <path
                                d="M12 2.8l2.86 5.79 6.39.93-4.62 4.5 1.09 6.36L12 17.38l-5.72 3 1.09-6.36-4.62-4.5 6.39-.93L12 2.8z"
                            />
                        </svg>

                    </div>


                    <div>

                        <h1 class="vip-hero-title">
                            فایل‌های VIP
                        </h1>

                        <p class="vip-hero-subtitle">
                            انتخاب ویژه ملکینو
                        </p>

                    </div>

                </div>


                <p class="vip-hero-description">

                    مجموعه‌ای از فایل‌های منتخب و ویژه
                    که توسط کارشناسان ملکینو برای شما
                    انتخاب شده‌اند.

                </p>

            </div>

        </section>


        <!-- =================================================
             Section Header
             ================================================= -->

        <div class="vip-section-header">

            <h2 class="vip-section-title">
                فایل‌های ویژه
            </h2>


            <span class="vip-count">

                <?= $vipCount ?>

                فایل VIP

            </span>

        </div>


        <!-- =================================================
             VIP Ads
             ================================================= -->

        <div class="vip-filters" aria-label="فیلتر فایل‌های VIP">

            <div class="vip-filter-group">
                <label for="vipPropertyFilter">نوع ملک</label>
                <select id="vipPropertyFilter">
                    <option value="">همه املاک</option>
                    <option value="آپارتمان">آپارتمان</option>
                    <option value="ویلا">ویلا</option>
                    <option value="تجاری">تجاری</option>
                    <option value="زمین">زمین</option>
                    <option value="باغ">باغ</option>
                    <option value="اداری">اداری</option>
                </select>
            </div>

            <div class="vip-filter-group">
                <label for="vipTransactionFilter">نوع معامله</label>
                <select id="vipTransactionFilter">
                    <option value="">همه معاملات</option>
                    <option value="فروش">خرید و فروش</option>
                    <option value="پیش فروش">پیش فروش</option>
                    <option value="اجاره">رهن و اجاره</option>
                    <option value="رهن کامل">رهن کامل</option>
                </select>
            </div>

            <button type="button" class="vip-filter-reset" id="vipFilterReset">حذف فیلترها</button>

        </div>

        <?php if (empty($vipAds)): ?>


            <section class="vip-empty">

                <div class="vip-empty-icon">

                    <svg
                        width="34"
                        height="34"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
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
                        />

                    </svg>

                </div>


                <h3 class="vip-empty-title">

                    هنوز فایل VIP ثبت نشده است

                </h3>


                <p class="vip-empty-text">

                    به‌محض اینکه یک آگهی از پنل مدیریت
                    به عنوان VIP انتخاب شود، اینجا نمایش داده خواهد شد.

                </p>

            </section>


        <?php else: ?>


            <section class="vip-grid">


                <?php foreach ($vipAds as $ad): ?>


                    <?php

                    $logoUrl = getSiteLogoUrl();

                    $firstImage =
                        getVipFirstImage(
                            $ad['selected_images']
                        );

                    $details =
                        is_array(
                            $ad['details']
                        )
                            ? $ad['details']
                            : [];

                    $area =
                        $details['area']
                        ??
                        $details['built_area']
                        ??
                        $details['land_area']
                        ??
                        $details['office_area']
                        ??
                        '';

                    $rooms =
                        $details['rooms']
                        ??
                        $details['bedrooms']
                        ??
                        '';

                    $parking =
                        $details['parking']
                        ?? false;

                    $elevator =
                        $details['elevator']
                        ?? false;

                    ?>


                    <a
                        class="vip-card"
                        data-property-type="<?= htmlspecialchars((string)($ad['property_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-transaction-type="<?= htmlspecialchars((string)($ad['transaction_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        href="property-details.php?id=<?= urlencode((string)$ad['id']) ?>"
                    >


                        <!-- Image -->
                        <div class="vip-card-image">


                            <?php if ($firstImage): ?>


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
                                >


                            <?php elseif (!empty($logoUrl)): ?>


                                <img
                                    src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>"
                                    alt="لوگوی ملکینو"
                                    style="object-fit:contain;padding:30px;background:var(--surface);"
                                    loading="lazy"
                                >


                            <?php else: ?>


                                <div class="vip-no-image">

                                    <svg
                                        width="32"
                                        height="32"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.5"
                                    >

                                        <rect
                                            x="3"
                                            y="3"
                                            width="18"
                                            height="18"
                                            rx="2"
                                        />

                                        <circle
                                            cx="8.5"
                                            cy="8.5"
                                            r="1.5"
                                        />

                                        <path
                                            d="M21 15l-5-5L5 21"
                                        />

                                    </svg>


                                    <span>
                                        بدون تصویر
                                    </span>

                                </div>


                            <?php endif; ?>


                            <!-- VIP -->

                            <span class="vip-badge">

                                ★ VIP

                            </span>


                            <!-- Transaction -->

                            <span class="vip-transaction">

                                <?= htmlspecialchars(
                                    $ad['transaction_type']
                                    ?: 'فروش',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </span>


                        </div>


                        <!-- Body -->

                        <div class="vip-card-body">


                            <div class="vip-card-title">

                                <?= htmlspecialchars(
                                    $ad['title'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </div>


                            <div class="vip-location">

                                <svg
                                    width="14"
                                    height="14"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >

                                    <path
                                        d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"
                                    />

                                    <circle
                                        cx="12"
                                        cy="10"
                                        r="3"
                                    />

                                </svg>


                                <span>

                                    <?= htmlspecialchars(
                                        $ad['location']
                                        ?: 'موقعیت نامشخص',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </span>

                            </div>


                            <div class="vip-details">


                                <span class="vip-detail">

                                    <?= htmlspecialchars(
                                        $ad['property_type'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </span>


                                <?php if ($area !== ''): ?>

                                    <span class="vip-detail">

                                        <?= htmlspecialchars(
                                            (string)$area,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                        متر

                                    </span>

                                <?php endif; ?>


                                <?php if ($rooms !== ''): ?>

                                    <span class="vip-detail">

                                        <?= htmlspecialchars(
                                            (string)$rooms,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                        اتاق

                                    </span>

                                <?php endif; ?>


                                <?php if ($parking): ?>

                                    <span class="vip-detail">
                                        پارکینگ
                                    </span>

                                <?php endif; ?>


                                <?php if ($elevator): ?>

                                    <span class="vip-detail">
                                        آسانسور
                                    </span>

                                <?php endif; ?>


                            </div>


                            <div class="vip-price">

                                <?= htmlspecialchars(
                                    vipDisplayPrice($ad),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </div>


                        </div>


                        <!-- Footer -->

                        <div class="vip-card-footer">


                            <span>

                                <?= date(
                                    'Y/m/d',
                                    strtotime(
                                        $ad['created_at']
                                    )
                                ) ?>

                            </span>


                            <span class="vip-view">

                                مشاهده جزئیات
                                ←

                            </span>


                        </div>


                    </a>


                <?php endforeach; ?>


            </section>


        <?php endif; ?>


    </main>


    <!-- =================================================
         Footer
         ================================================= -->

    <?php require_once __DIR__ . '/footer.php'; ?>


</div>



<script>
(function () {
    'use strict';

    const propertyFilter = document.getElementById('vipPropertyFilter');
    const transactionFilter = document.getElementById('vipTransactionFilter');
    const resetButton = document.getElementById('vipFilterReset');

    if (!propertyFilter || !transactionFilter) {
        return;
    }

    const cards = Array.from(document.querySelectorAll('.vip-card'));
    const emptyBox = document.createElement('div');
    emptyBox.className = 'vip-filter-empty';
    emptyBox.textContent = 'برای فیلتر انتخاب‌شده فایل VIP موجود نیست.';

    const grid = document.querySelector('.vip-grid');
    if (grid) {
        grid.parentNode.insertBefore(emptyBox, grid.nextSibling);
    }

    function normalize(value) {
        return String(value || '').trim();
    }

    function matches(card, propertyType, transactionType) {
        const cardProperty = normalize(card.dataset.propertyType);
        const cardTransaction = normalize(card.dataset.transactionType);

        const propertyMatch = !propertyType || cardProperty === propertyType;
        const transactionMatch = !transactionType || cardTransaction === transactionType;

        return propertyMatch && transactionMatch;
    }

    function applyVipFilters() {
        const propertyType = normalize(propertyFilter.value);
        const transactionType = normalize(transactionFilter.value);

        let visibleCount = 0;

        cards.forEach(function (card) {
            const show = matches(card, propertyType, transactionType);
            card.style.display = show ? '' : 'none';
            if (show) {
                visibleCount++;
            }
        });

        if (emptyBox) {
            emptyBox.style.display = visibleCount === 0 ? 'block' : 'none';
        }

        const count = document.querySelector('.vip-count');
        if (count) {
            count.textContent = visibleCount + ' فایل VIP';
        }
    }

    propertyFilter.addEventListener('change', applyVipFilters);
    transactionFilter.addEventListener('change', applyVipFilters);

    if (resetButton) {
        resetButton.addEventListener('click', function () {
            propertyFilter.value = '';
            transactionFilter.value = '';
            applyVipFilters();
        });
    }

    applyVipFilters();
})();
</script>

</body>

</html>