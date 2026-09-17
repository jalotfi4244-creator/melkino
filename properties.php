<?php
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

$siteLogoUrl = getSiteLogoUrl();

// قبلاً این مقدار از یک فایل JSON قدیمی (settings/global.json) خونده می‌شد که
// هیچ‌وقت آپدیت نمی‌شد و config.php هم اصلاً در این صفحه require نشده بود.
// نتیجه‌اش این بود که تغییر «نمایش قیمت‌ها» در پنل ادمین (که در دیتابیس
// ذخیره می‌شه) روی این صفحه اصلاً اثر نداشت. حالا مستقیم از دیتابیس می‌خونه.
require_once __DIR__ . '/config.php';
$__gp = getGlobalSettings();
$__hideAllPublicPrices = (!$__gp['show_prices'] || !empty($__gp['hide_all_prices']));

require_once __DIR__ . '/header.php';
?>

<style>
    .main-content {
        flex: 1;
        overflow-y: auto;
        padding-bottom: 80px;
        background: var(--bg);
    }

    .properties-page {
        width: 100%;
        max-width: 1100px;
        margin: 0 auto;
    }

    /* =========================================================
       HERO
    ========================================================== */

    .properties-hero {
        position: relative;
        margin: var(--space-2) var(--space-3);
        padding: 22px 20px;
        border-radius: var(--radius-md);
        overflow: hidden;

        background:
            radial-gradient(
                circle at 85% 15%,
                rgba(212,175,55,.16),
                transparent 30%
            ),
            linear-gradient(
                135deg,
                #073737,
                #052727 70%,
                #031C1C
            );

        border: 1px solid rgba(212,175,55,.12);
        box-shadow: var(--shadow-card);
    }

    .properties-hero::after {
        content: "";
        position: absolute;
        width: 180px;
        height: 180px;
        left: -100px;
        bottom: -110px;
        border-radius: 50%;
        background: rgba(255,255,255,.025);
        pointer-events: none;
    }

    .properties-hero-content {
        position: relative;
        z-index: 2;
    }

    .properties-kicker {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 8px;
        color: #F0D36A;
        font-size: 10px;
        font-weight: 800;
    }

    .properties-kicker-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: #D4AF37;
        box-shadow: 0 0 10px rgba(212,175,55,.5);
    }

    .properties-title {
        margin: 0;
        color: #fff;
        font-size: clamp(22px, 6vw, 34px);
        line-height: 1.35;
        font-weight: 900;
    }

    .properties-title span {
        color: #F0D36A;
    }

    .properties-subtitle {
        margin: 7px 0 0;
        max-width: 650px;
        color: rgba(255,255,255,.58);
        font-size: 11px;
        line-height: 1.95;
    }

    /* =========================================================
       TOOLBAR
    ========================================================== */

    .properties-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;

        padding: 0 var(--space-3);
        margin-bottom: var(--space-2);
        flex-wrap: wrap;
    }

    .properties-count {
        color: var(--text-secondary);
        font-size: 13px;
        font-weight: 600;
    }

    .sort-box {
        display: flex;
        align-items: center;
        gap: 7px;
    }

    .sort-label {
        color: var(--text-secondary);
        font-size: 11px;
        white-space: nowrap;
    }

    .sort-select {
        min-width: 150px;
        height: 40px;
        padding: 0 12px;

        border: 1px solid var(--border);
        border-radius: var(--radius-sm);

        background: var(--surface);
        color: var(--text-primary);

        font-family: 'Vazirmatn', sans-serif;
        font-size: 12px;

        outline: none;
    }

    .sort-select:focus {
        border-color: var(--primary);
    }

    /* =========================================================
       LIST
    ========================================================== */

    .properties-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: var(--space-2);
        padding: 0 var(--space-3);
    }

    .property-card {
        position: relative;

        overflow: hidden;

        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-md);

        box-shadow: var(--shadow-card);

        transition:
            transform .2s ease,
            box-shadow .2s ease,
            border-color .2s ease;
    }

    .property-card:hover {
        transform: translateY(-3px);
        border-color: rgba(212,175,55,.18);
    }

    /* =========================================================
       IMAGE
    ========================================================== */

    .property-image {
        position: relative;

        width: 100%;
        height: 210px;

        background:
            linear-gradient(
                145deg,
                rgba(212,175,55,.12),
                rgba(255,255,255,.025)
            );

        display: flex;
        align-items: center;
        justify-content: center;

        color: var(--text-secondary);
        overflow: hidden;
    }

    .property-image img {
        width: 100%;
        height: 100%;
        display: block;
        object-fit: cover;

        transition:
            transform .45s ease,
            filter .3s ease;
    }

    .property-card:hover .property-image img {
        transform: scale(1.035);
    }

    .property-image-placeholder {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 7px;
        width: 100%;
        height: 100%;
        color: var(--text-secondary);
        font-size: 12px;
    }

    .property-image-placeholder svg {
        opacity: .45;
    }

    .property-badges {
        position: absolute;
        top: 10px;
        right: 10px;

        display: flex;
        gap: 5px;
        flex-wrap: wrap;
        max-width: calc(100% - 20px);
    }

    .property-badge {
        padding: 4px 8px;
        border-radius: 999px;

        background: rgba(0,0,0,.56);
        color: #fff;

        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);

        font-size: 9px;
        font-weight: 700;
    }

    .property-badge.gold {
        background: rgba(212,175,55,.94);
        color: #173131;
    }

    /* =========================================================
       CARD CONTENT
    ========================================================== */

    .property-code {
        margin-top: 12px;
        color: var(--text-secondary);
        font-size: 10px;
    }

    .property-content {
        padding: 0 12px 12px;
    }

    .property-title-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;

        margin-top: 5px;
    }

    .property-title {
        min-width: 0;
        color: var(--text-primary);
        font-size: 14px;
        line-height: 1.7;
        font-weight: 800;
    }

    .property-price {
        flex: 0 0 auto;
        color: var(--gold, #D4AF37);
        font-size: 14px;
        line-height: 1.5;
        font-weight: 900;
        text-align: left;
        max-width: 45%;
        word-break: break-word;
    }

    .property-location {
        margin-top: 5px;
        color: var(--text-secondary);
        font-size: 11px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .property-features {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;

        margin-top: 10px;
        padding-top: 10px;

        border-top: 1px solid var(--border);
    }

    .property-feature {
        display: inline-flex;
        align-items: center;
        gap: 3px;

        padding: 4px 7px;

        border-radius: 7px;
        background: var(--bg);

        color: var(--text-secondary);
        font-size: 9px;
        white-space: nowrap;
    }

    .property-feature.primary {
        color: var(--primary);
    }

    .property-footer {
        display: flex;
        align-items: center;
        gap: 7px;
        margin-top: 11px;
    }

    .property-detail {
        flex: 1;

        height: 42px;

        display: flex;
        align-items: center;
        justify-content: center;

        border-radius: var(--radius-sm);

        background: var(--bg);
        color: var(--primary);

        border: 1px solid var(--border);

        text-decoration: none;

        font-size: 11px;
        font-weight: 750;

        transition:
            background .2s ease,
            transform .2s ease;
    }

    .property-detail:hover {
        background: var(--surface);
    }

    .property-detail:active {
        transform: scale(.98);
    }

    .property-like {
        width: 42px;
        height: 42px;

        flex: 0 0 auto;

        display: flex;
        align-items: center;
        justify-content: center;

        border-radius: var(--radius-sm);

        border: 1px solid var(--border);
        background: var(--surface);

        color: var(--text-secondary);

        cursor: pointer;
    }

    .property-compare {
        font-size: 18px;
        line-height: 1;
    }

    .property-like.in-compare {
        color: var(--primary);
        border-color: var(--primary);
    }

    /* =========================================================
       EMPTY
    ========================================================== */

    .properties-empty {
        margin: 0 var(--space-3);
        padding: 55px 20px;

        text-align: center;

        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-md);

        color: var(--text-secondary);
    }

    .properties-empty-icon {
        width: 70px;
        height: 70px;
        margin: 0 auto 15px;

        display: flex;
        align-items: center;
        justify-content: center;

        border-radius: 22px;

        background: rgba(212,175,55,.07);
        color: var(--gold, #D4AF37);
    }

    .properties-empty h3 {
        margin: 0;
        color: var(--text-primary);
        font-size: 16px;
    }

    .properties-empty p {
        margin: 8px auto 0;
        max-width: 400px;
        line-height: 1.9;
        font-size: 12px;
    }

    /* =========================================================
       ERROR
    ========================================================== */

    .properties-error {
        margin: 0 var(--space-3);
        padding: 35px 20px;

        text-align: center;

        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-md);

        color: var(--text-secondary);
    }

    .properties-error strong {
        display: block;
        color: var(--text-primary);
        margin-bottom: 7px;
    }

    /* =========================================================
       LOADING
    ========================================================== */

    .properties-loading {
        grid-column: 1 / -1;

        min-height: 230px;

        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;

        gap: 12px;

        color: var(--text-secondary);
        font-size: 12px;
    }

    .properties-loading-spinner {
        width: 30px;
        height: 30px;

        border-radius: 50%;

        border:
            3px solid rgba(212,175,55,.15);

        border-top-color:
            var(--gold, #D4AF37);

        animation:
            propertiesSpin .8s linear infinite;
    }

    @keyframes propertiesSpin {
        to {
            transform: rotate(360deg);
        }
    }

    /* =========================================================
       RESPONSIVE
    ========================================================== */

    @media (max-width: 700px) {

        .properties-list {
            grid-template-columns: 1fr;
        }

        .property-image {
            height: 205px;
        }

    }

    @media (max-width: 480px) {

        .properties-hero {
            margin: 10px 12px 12px;
            padding: 18px 15px;
        }

        .properties-title {
            font-size: 23px;
        }

        .properties-subtitle {
            font-size: 10px;
        }

        .properties-toolbar {
            align-items: stretch;
            flex-direction: column;
            padding: 0 12px;
        }

        .sort-box {
            width: 100%;
        }

        .sort-select {
            flex: 1;
            min-width: 0;
        }

        .properties-list {
            padding: 0 12px;
        }

        .property-image {
            height: 210px;
        }

        .property-title-row {
            flex-direction: column;
            gap: 3px;
        }

        .property-price {
            max-width: none;
            text-align: right;
            font-size: 15px;
        }

    }

    @media (max-width: 380px) {

        .property-image {
            height: 190px;
        }

        .property-title {
            font-size: 13px;
        }

        .property-price {
            font-size: 14px;
        }

    }

    /* =========================================================
       DARK MODE
    ========================================================== */

    [data-theme="dark"] .properties-hero {
        background:
            radial-gradient(
                circle at 85% 15%,
                rgba(212,175,55,.10),
                transparent 30%
            ),
            linear-gradient(
                135deg,
                #073535,
                #092727 70%,
                #071A1A
            );
        border-color: rgba(212,175,55,.13);
    }

    [data-theme="dark"] .property-card {
        background: #142525;
        border-color: #294646;
    }

    [data-theme="dark"] .property-detail {
        background: #1B3232;
        border-color: #2E4A4A;
    }

    [data-theme="dark"] .property-feature {
        background: #1B3232;
        color: #CFE0DD;
    }

    [data-theme="dark"] .property-feature.primary {
        color: #72D2CC;
    }

    [data-theme="dark"] .property-like {
        background: #142525;
        border-color: #2E4A4A;
    }
</style>


<div class="main-content">

    <div class="properties-page">

        <!-- =====================================================
             HERO
        ====================================================== -->

        <section class="properties-hero">

            <div class="properties-hero-content">

                <div class="properties-kicker">
                    <span class="properties-kicker-dot"></span>
                    ویترین ملکینو
                </div>

                <h1 class="properties-title">
                    همه آگهی‌ها،
                    <span>یک‌جا</span>
                </h1>

                <p class="properties-subtitle">
                    فایل‌های منتشرشده ملکینو را ببین،
                    گزینه‌ها را مقایسه کن و ملک مناسب خودت را پیدا کن.
                </p>

            </div>

        </section>


        <!-- =====================================================
             TOOLBAR
        ====================================================== -->

        <div class="properties-toolbar">

            <div
                class="properties-count"
                id="propertiesCount"
            >
                در حال بارگذاری...
            </div>

            <div class="sort-box">

                <span class="sort-label">
                    مرتب‌سازی:
                </span>

                <select
                    id="sortSelect"
                    class="sort-select"
                >

                    <option value="newest">
                        جدیدترین
                    </option>

                    <option value="oldest">
                        قدیمی‌ترین
                    </option>

                    <option value="price_low">
                        ارزان‌ترین
                    </option>

                    <option value="price_high">
                        گران‌ترین
                    </option>

                    <option value="area_high">
                        بیشترین متراژ
                    </option>

                    <option value="area_low">
                        کمترین متراژ
                    </option>

                </select>

            </div>

        </div>


        <!-- =====================================================
             LIST
        ====================================================== -->

        <div
            id="propertiesList"
            class="properties-list"
        >

            <div class="properties-loading">

                <div class="properties-loading-spinner"></div>

                <span>
                    در حال بارگذاری آگهی‌ها...
                </span>

            </div>

        </div>

    </div>

</div>



<script>
(function () {

    'use strict';


    /* =========================================================
       DOM
    ========================================================== */

    var propertiesList =
        document.getElementById(
            'propertiesList'
        );

    var propertiesCount =
        document.getElementById(
            'propertiesCount'
        );

    var sortSelect =
        document.getElementById(
            'sortSelect'
        );


    /* =========================================================
       DATA
    ========================================================== */

    var allProperties = [];
    var siteLogoUrl = '<?= $siteLogoUrl ?>';


    /* =========================================================
       TYPE MAPS
    ========================================================== */

    var propertyTypeMap = {

        apartment: 'آپارتمان',
        villa: 'ویلا',
        commercial: 'تجاری',
        land: 'زمین',
        garden: 'باغ',
        office: 'اداری'

    };


    var transactionTypeMap = {

        sell: 'فروش',
        pre_sell: 'پیش فروش',
        rent: 'اجاره',
        mortgage: 'رهن کامل'

    };


    /* =========================================================
       DIGITS
    ========================================================== */

    function normalizeDigits(value) {

        return String(value || '')
            .replace(/[۰-۹]/g, function (digit) {

                return '۰۱۲۳۴۵۶۷۸۹'
                    .indexOf(digit);

            })
            .replace(/[٠-٩]/g, function (digit) {

                return '٠١٢٣٤٥٦٧٨٩'
                    .indexOf(digit);

            });

    }


    /* =========================================================
       NUMBER
    ========================================================== */

    function parseNumber(value) {

        var normalized =
            normalizeDigits(value)
                .replace(
                    /[,\s٬،]/g,
                    ''
                );

        if (!normalized) {
            return 0;
        }

        return (
            parseInt(
                normalized,
                10
            ) || 0
        );

    }


    /* =========================================================
       HTML ESCAPE
    ========================================================== */

    function escapeHtml(value) {

        return String(
            value ?? ''
        )
            .replace(
                /&/g,
                '&amp;'
            )
            .replace(
                /</g,
                '&lt;'
            )
            .replace(
                />/g,
                '&gt;'
            )
            .replace(
                /"/g,
                '&quot;'
            )
            .replace(
                /'/g,
                '&#039;'
            );

    }


    /* =========================================================
       IMAGE NORMALIZER
       ========================================================== */

    function normalizeImages(value) {

        if (Array.isArray(value)) {

            return value.filter(
                function (item) {

                    return (
                        typeof item === 'string' &&
                        item.trim() !== ''
                    );

                }
            );

        }

        if (
            typeof value === 'string' &&
            value.trim() !== ''
        ) {

            try {

                var decoded =
                    JSON.parse(value);

                if (
                    Array.isArray(decoded)
                ) {

                    return decoded.filter(
                        function (item) {

                            return (
                                typeof item === 'string' &&
                                item.trim() !== ''
                            );

                        }
                    );

                }

            } catch (error) {

                if (
                    value.indexOf('/') !== -1 ||
                    value.indexOf('.') !== -1
                ) {

                    return [
                        value.trim()
                    ];

                }

            }

        }

        return [];

    }


    /* =========================================================
       IMAGE URL
    ========================================================== */

    function normalizeImageUrl(url) {

        if (!url) return '';

        var imageUrl = String(url).trim();
        if (!imageUrl) return '';

        imageUrl = imageUrl.replace(/\\/g, '/');

        if (/^(https?:)?\/\//i.test(imageUrl)) {
            return imageUrl;
        }

        imageUrl = imageUrl.replace(/^\.\//, '');
        imageUrl = imageUrl.replace(/^\/+/, '');
        imageUrl = imageUrl.replace(/^melkino\//i, '');

        return imageUrl;
    }

    function collectImageCandidates(value, out) {

        out = out || [];

        if (value === null || value === undefined) {
            return out;
        }

        if (Array.isArray(value)) {
            value.forEach(function (item) {
                collectImageCandidates(item, out);
            });
            return out;
        }

        if (typeof value === 'object') {
            ['url', 'path', 'src', 'file', 'image', 'image_url', 'file_url'].forEach(function (key) {
                if (value[key]) {
                    collectImageCandidates(value[key], out);
                }
            });
            return out;
        }

        if (typeof value === 'string') {
            var raw = value.trim();
            if (!raw) return out;

            if (raw.charAt(0) === '[' || raw.charAt(0) === '{') {
                try {
                    var decoded = JSON.parse(raw);
                    if (decoded && decoded !== value) {
                        collectImageCandidates(decoded, out);
                        return out;
                    }
                } catch (e) {}
            }

            out.push(raw);
        }

        return out;
    }

    function getImageUrlCandidates(value) {

        var rawCandidates = collectImageCandidates(value, []);
        var result = [];

        rawCandidates.forEach(function (raw) {

            var normalized = normalizeImageUrl(raw);
            if (!normalized) return;

            var variants = [normalized];

            if (!/^(https?:)?\/\//i.test(normalized)) {
                variants.push('/' + normalized);
                variants.push('/melkino/' + normalized);

                if (normalized.indexOf('uploads/') !== 0) {
                    variants.push('/melkino/uploads/' + normalized);
                }
            }

            variants.forEach(function (variant) {
                if (result.indexOf(variant) === -1) {
                    result.push(variant);
                }
            });
        });

        return result;
    }

    function getFirstValidImage(ad) {

        var sources = [
            ad.selected_images,
            ad.selectedImages,
            ad.images,
            ad.photos,
            ad.gallery,
            ad.gallery_images,
            ad.image,
            ad.photo,
            ad.image_url,
            ad.photo_url
        ];

        for (var i = 0; i < sources.length; i++) {
            var candidates = getImageUrlCandidates(sources[i]);
            if (candidates.length) {
                return candidates[0];
            }
        }

        return '';
    }


    /* =========================================================
       NORMALIZE AD
    ========================================================== */

    function normalizeAd(ad) {

        var transactionType =
            ad.transactionType ||
            ad.transaction_type ||
            ad.type ||
            '';

        var propertyType =
            ad.propertyType ||
            ad.property_type ||
            '';

        if (
            transactionTypeMap[
                transactionType
            ]
        ) {

            transactionType =
                transactionTypeMap[
                    transactionType
                ];

        }

        if (
            propertyTypeMap[
                propertyType
            ]
        ) {

            propertyType =
                propertyTypeMap[
                    propertyType
                ];

        }

        // ===== تغییر: گرفتن فیلدهای قیمتی =====
        var deposit = ad.deposit || '';
        var rentMonthly = ad.rent_monthly || '';
        var priceSell = ad.price_sell || '';
        var totalPrice = ad.total_price || '';

        var displayPrice =
            ad.display_price ||
            ad.price_sell ||
            ad.total_price ||
            ad.price ||
            ad.deposit ||
            ad.rent_monthly ||
            '۰';

        if (displayPrice === '' || displayPrice === '0') {
            if (priceSell && priceSell !== '0') {
                displayPrice = priceSell;
            } else if (totalPrice && totalPrice !== '0') {
                displayPrice = totalPrice;
            } else if (deposit && deposit !== '0') {
                displayPrice = deposit;
                if (rentMonthly && rentMonthly !== '0') {
                    displayPrice += ' | ' + rentMonthly;
                }
            }
        }

        var priceNumeric =
            parseNumber(
                ad.priceNumeric ||
                ad.price_sell ||
                ad.total_price ||
                ad.price ||
                0
            );

        var area =
            parseNumber(
                ad.area ||
                ad.land_area ||
                ad.office_area ||
                ad.building_area ||
                ad.property_area ||
                0
            );

        var location =
            ad.location ||
            ad.neighborhood ||
            'نامشخص';

        var neighborhood =
            ad.neighborhood ||
            (
                location
                    ? String(location)
                        .split('،')[0]
                        .trim()
                    : ''
            );

        var selectedImages = [];
        [
            ad.selected_images,
            ad.selectedImages,
            ad.images,
            ad.photos,
            ad.gallery,
            ad.gallery_images,
            ad.image,
            ad.photo
        ].forEach(function (source) {
            collectImageCandidates(source, selectedImages);
        });

        var timestamp =
            ad.created_at
                ? new Date(
                    ad.created_at
                ).getTime()
                : (
                    ad.timestamp ||
                    Date.now()
                );

        var details =
            ad.property_details ||
            ad.propertyDetails ||
            ad.details ||
            {};

        if (
            typeof details === 'string'
        ) {

            try {

                details =
                    JSON.parse(
                        details
                    );

            } catch (error) {

                details = {};

            }

        }

        if (
            !details ||
            typeof details !== 'object'
        ) {

            details = {};

        }

        var amenities =
            ad.amenities ||
            [];

        if (
            typeof amenities === 'string'
        ) {

            try {

                amenities =
                    JSON.parse(
                        amenities
                    );

            } catch (error) {

                amenities = [];

            }

        }

        if (
            !Array.isArray(amenities)
        ) {

            amenities = [];

        }

        if (
            !ad.display_price &&
            ad.deposit &&
            ad.rent_monthly
        ) {

            displayPrice =
                String(
                    ad.deposit
                ) +
                ' | ' +
                String(
                    ad.rent_monthly
                );

        }

        return {

            id:
                ad.id ||
                'N/A',

            title:
                ad.title ||
                'بدون عنوان',

            price:
                String(
                    displayPrice
                ),

            priceNumeric:
                priceNumeric,

            location:
                location,

            neighborhood:
                neighborhood,

            propertyType:
                propertyType ||
                'نامشخص',

            transactionType:
                transactionType ||
                'نامشخص',

            type:
                transactionType ||
                'نامشخص',

            area:
                area,

            bedrooms:
                ad.rooms ||
                ad.bedrooms ||
                details.rooms ||
                details.bedrooms ||
                '-',

            tags:
                Array.isArray(ad.tags)
                    ? ad.tags
                    : [],

            parking:
                ad.parking === true ||
                ad.parking === 1 ||
                ad.parking === '1' ||
                details.parking === true ||
                details.parking === 1 ||
                details.parking === '1',

            elevator:
                ad.elevator === true ||
                ad.elevator === 1 ||
                ad.elevator === '1' ||
                details.elevator === true ||
                details.elevator === 1 ||
                details.elevator === '1',

            amenities:
                amenities,

            details:
                details,

            selectedImages:
                selectedImages,

            status:
                ad.status ||
                'pending',

            timestamp:
                isNaN(timestamp)
                    ? Date.now()
                    : timestamp,

            // ===== اضافه کردن فیلدهای قیمتی =====
            deposit: deposit,
            rent_monthly: rentMonthly,
            price_sell: priceSell,
            total_price: totalPrice,
            price_hidden: !!(ad.price_hidden || ad.priceHidden),

        };

    }


    window.handleCardImageError = function (img) {
        try {
            var candidates = JSON.parse(img.getAttribute('data-image-candidates') || '[]');
            var current = img.getAttribute('src') || '';
            var index = candidates.indexOf(current);
            var next = index >= 0 ? candidates[index + 1] : candidates[0];

            if (next && next !== current) {
                img.setAttribute('src', next);
                return;
            }
        } catch (e) {}

        img.style.display = 'none';
        if (img.nextElementSibling) {
            img.nextElementSibling.style.display = 'flex';
        }
    };

    /* =========================================================
       LOAD ADS
    ========================================================== */

    function loadProperties() {

        fetch(
            'properties-data.php',
            {
                cache: 'no-store'
            }
        )
        .then(
            function (response) {

                if (!response.ok) {

                    throw new Error(
                        'داده‌های آگهی از دیتابیس دریافت نشد'
                    );

                }

                return response.text();

            }
        )
        .then(
            function (text) {

                if (
                    !text ||
                    !text.trim()
                ) {

                    throw new Error(
                        'هیچ آگهی منتشرشده‌ای پیدا نشد'
                    );

                }

                var data;

                try {

                    data =
                        JSON.parse(text);

                } catch (error) {

                    throw new Error(
                        'ساختار JSON معتبر نیست'
                    );

                }

                if (
                    !Array.isArray(data)
                ) {

                    throw new Error(
                        'پاسخ دیتابیس آرایه نیست'
                    );

                }

                allProperties =
                    data
                        .map(normalizeAd)
                        .filter(
                            function (ad) {

                                return (
                                    ad.status ===
                                    'published'
                                );

                            }
                        );

                renderProperties();

            }
        )
        .catch(
            function (error) {

                console.error(
                    'خطا در بارگذاری آگهی‌ها:',
                    error
                );

                propertiesCount.textContent =
                    'خطا در بارگذاری';

                propertiesList.innerHTML = `

                    <div
                        class="properties-error"
                        style="
                            grid-column:1/-1;
                        "
                    >

                        <strong>
                            بارگذاری آگهی‌ها انجام نشد
                        </strong>

                        <div>
                            اتصال دیتابیس و جدول ads را بررسی کنید.
                        </div>

                    </div>

                `;

            }
        );

    }


    /* =========================================================
       SORT
    ========================================================== */

    function sortProperties(list) {

        var result =
            list.slice();

        switch (
            sortSelect.value
        ) {

            case 'oldest':

                result.sort(
                    function (a, b) {

                        return (
                            a.timestamp -
                            b.timestamp
                        );

                    }
                );

                break;

            case 'price_low':

                result.sort(
                    function (a, b) {

                        if (
                            a.priceNumeric === 0 &&
                            b.priceNumeric !== 0
                        ) {
                            return 1;
                        }

                        if (
                            a.priceNumeric !== 0 &&
                            b.priceNumeric === 0
                        ) {
                            return -1;
                        }

                        return (
                            a.priceNumeric -
                            b.priceNumeric
                        );

                    }
                );

                break;

            case 'price_high':

                result.sort(
                    function (a, b) {

                        return (
                            b.priceNumeric -
                            a.priceNumeric
                        );

                    }
                );

                break;

            case 'area_high':

                result.sort(
                    function (a, b) {

                        return (
                            b.area -
                            a.area
                        );

                    }
                );

                break;

            case 'area_low':

                result.sort(
                    function (a, b) {

                        return (
                            a.area -
                            b.area
                        );

                    }
                );

                break;

            case 'newest':
            default:

                result.sort(
                    function (a, b) {

                        return (
                            b.timestamp -
                            a.timestamp
                        );

                    }
                );

                break;

        }

        return result;

    }


    /* =========================================================
       DISPLAY PRICE — اصلاح‌شده برای نمایش رهن | اجاره
    ========================================================== */

    window.MELKINO_PRICE_VISIBILITY = {hide: <?= $__hideAllPublicPrices ? 'true' : 'false' ?>};

    function displayPrice(ad) {
        if (window.MELKINO_PRICE_VISIBILITY && (window.MELKINO_PRICE_VISIBILITY.hide || ad.price_hidden)) { return 'برای استعلام قیمت تماس بگیرید'; }


        function normalizeMoney(value) {
            if (value === null || value === undefined) return 0;

            var s = String(value)
                .replace(/[۰-۹]/g, function (digit) {
                    return '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit);
                })
                .replace(/[٠-٩]/g, function (digit) {
                    return '٠١٢٣٤٥٦٧٨٩'.indexOf(digit);
                })
                .replace(/[٬،,\s]/g, '')
                .replace(/تومان/g, '');

            var n = Number(s);
            return isFinite(n) ? n : 0;
        }

        function moneyFa(value) {
            var n = normalizeMoney(value);
            return n > 0 ? n.toLocaleString('fa-IR') : '';
        }

        var depositText = moneyFa(ad.deposit || '');
        var rentText = moneyFa(ad.rent_monthly || '');

        /* اجاره: مبلغ واقعی ودیعه و اجاره همیشه مقدم است */
        if (depositText && rentText) {
            return 'ودیعه: ' + depositText + ' تومان | اجاره: ' + rentText + ' تومان';
        }

        if (depositText) {
            return 'ودیعه: ' + depositText + ' تومان';
        }

        if (rentText) {
            return 'اجاره: ' + rentText + ' تومان';
        }

        var price = ad.price || ad.display_price || ad.price_sell || ad.total_price || '';

        if (typeof price === 'string' && price.indexOf('|') !== -1) {
            return price;
        }

        var priceText = moneyFa(price);
        return priceText ? priceText + ' تومان' : 'تماس بگیرید';
    }


    /* =========================================================
       RENDER
    ========================================================== */

    function renderProperties() {

        var sorted =
            sortProperties(
                allProperties
            );

        propertiesCount.textContent =
            sorted.length +
            ' آگهی منتشرشده';

        if (
            sorted.length === 0
        ) {

            propertiesList.innerHTML = `

                <div
                    class="properties-empty"
                    style="
                        grid-column:1/-1;
                    "
                >

                    <div
                        class="properties-empty-icon"
                    >

                        <svg
                            width="32"
                            height="32"
                            viewBox="0 0 24 24"
                            fill="none"
                        >

                            <path
                                d="M3 10L12 3L21 10V20C21 20.55 20.55 21 20 21H4C3.45 21 3 20.55 3 20V10Z"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linejoin="round"
                            />

                            <path
                                d="M9 21V13H15V21"
                                stroke="currentColor"
                                stroke-width="1.5"
                            />

                        </svg>

                    </div>

                    <h3>
                        هنوز آگهی منتشرشده‌ای وجود ندارد
                    </h3>

                    <p>
                        به‌محض انتشار فایل‌های جدید،
                        ویترین ملکینو در اینجا به‌روزرسانی می‌شود.
                    </p>

                </div>

            `;

            return;

        }


        propertiesList.innerHTML = '';

        for (
            var i = 0;
            i < sorted.length;
            i++
        ) {

            propertiesList.appendChild(
                createPropertyCard(
                    sorted[i]
                )
            );

        }

    }


    /* =========================================================
       CREATE PROPERTY CARD
    ========================================================== */

    function createPropertyCard(ad) {

        var card =
            document.createElement(
                'article'
            );

        card.className =
            'property-card';

        var detailsUrl =
            'property-details.php?id=' +
            encodeURIComponent(
                ad.id
            );

        /* =====================================================
           BADGES
        ====================================================== */

        var tagsHtml =
            '';

        if (
            Array.isArray(ad.tags) &&
            ad.tags.length
        ) {

            for (
                var i = 0;
                i < ad.tags.length;
                i++
            ) {

                tagsHtml += `

                    <span class="property-badge">

                        ${escapeHtml(
                            ad.tags[i]
                        )}

                    </span>

                `;

            }

        }

        if (
            ad.transactionType &&
            ad.transactionType !== 'نامشخص'
        ) {

            tagsHtml += `

                <span class="property-badge gold">

                    ${escapeHtml(
                        ad.transactionType
                    )}

                </span>

            `;

        }

        /* =====================================================
           IMAGE — با پشتیبانی از لوگو
        ====================================================== */

        var firstImage = getFirstValidImage(ad);
        var imageCandidates = getImageUrlCandidates(
            [
                ad.selected_images,
                ad.selectedImages,
                ad.images,
                ad.photos,
                ad.gallery,
                ad.gallery_images,
                ad.image,
                ad.photo,
                ad.image_url,
                ad.photo_url
            ]
        );

        var imageHtml = '';

        if (firstImage) {
            imageHtml = `
                <img
                    src="${escapeHtml(firstImage)}"
                    alt="${escapeHtml(ad.title)}"
                    loading="lazy"
                    data-image-candidates="${escapeHtml(JSON.stringify(imageCandidates))}"
                    onerror="handleCardImageError(this);"
                >

                <div
                    class="property-image-placeholder"
                    style="display:none;"
                >

                    <svg
                        width="36"
                        height="36"
                        viewBox="0 0 24 24"
                        fill="none"
                    >

                        <rect
                            x="3"
                            y="4"
                            width="18"
                            height="16"
                            rx="2"
                            stroke="currentColor"
                            stroke-width="1.4"
                        />

                        <circle
                            cx="8"
                            cy="9"
                            r="1.5"
                            stroke="currentColor"
                            stroke-width="1.4"
                        />

                        <path
                            d="M3 17L8 12L12 16L15 13L21 19"
                            stroke="currentColor"
                            stroke-width="1.4"
                            stroke-linejoin="round"
                        />

                    </svg>

                    <span>
                        تصویر در دسترس نیست
                    </span>

                </div>

            `;

        } else if (siteLogoUrl) {

            imageHtml = `

                <img
                    src="${escapeHtml(siteLogoUrl)}"
                    alt="لوگوی ملکینو"
                    style="object-fit:contain;padding:30px;background:var(--surface);"
                    loading="lazy"
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                >

                <div
                    class="property-image-placeholder"
                    style="display:none;"
                >

                    <svg
                        width="36"
                        height="36"
                        viewBox="0 0 24 24"
                        fill="none"
                    >

                        <rect
                            x="3"
                            y="4"
                            width="18"
                            height="16"
                            rx="2"
                            stroke="currentColor"
                            stroke-width="1.4"
                        />

                        <circle
                            cx="8"
                            cy="9"
                            r="1.5"
                            stroke="currentColor"
                            stroke-width="1.4"
                        />

                        <path
                            d="M3 17L8 12L12 16L15 13L21 19"
                            stroke="currentColor"
                            stroke-width="1.4"
                            stroke-linejoin="round"
                        />

                    </svg>

                    <span>
                        تصویر در دسترس نیست
                    </span>

                </div>

            `;

        } else {

            imageHtml = `

                <div class="property-image-placeholder">

                    <svg
                        width="36"
                        height="36"
                        viewBox="0 0 24 24"
                        fill="none"
                    >

                        <rect
                            x="3"
                            y="4"
                            width="18"
                            height="16"
                            rx="2"
                            stroke="currentColor"
                            stroke-width="1.4"
                        />

                        <circle
                            cx="8"
                            cy="9"
                            r="1.5"
                            stroke="currentColor"
                            stroke-width="1.4"
                        />

                        <path
                            d="M3 17L8 12L12 16L15 13L21 19"
                            stroke="currentColor"
                            stroke-width="1.4"
                            stroke-linejoin="round"
                        />

                    </svg>

                    <span>
                        تصویر ملک
                    </span>

                </div>

            `;

        }


        /* =====================================================
           CARD
        ====================================================== */

        card.innerHTML = `

            <div class="property-image">

                ${imageHtml}

                ${
                    tagsHtml
                        ? `
                            <div class="property-badges">
                                ${tagsHtml}
                            </div>
                        `
                        : ''
                }

            </div>


            <div class="property-content">

                <div class="property-code">

                    کد ملک:
                    ${escapeHtml(ad.id)}

                </div>


                <div class="property-title-row">

                    <div class="property-title">

                        ${escapeHtml(
                            ad.title
                        )}

                    </div>


                    <div class="property-price">

                        ${displayPrice(ad)}

                    </div>

                </div>


                <div class="property-location">

                    📍
                    ${escapeHtml(
                        ad.location
                    )}

                </div>


                <div class="property-features">

                    ${
                        ad.propertyType &&
                        ad.propertyType !== 'نامشخص'
                            ? `
                                <span class="property-feature primary">
                                    ${escapeHtml(
                                        ad.propertyType
                                    )}
                                </span>
                            `
                            : ''
                    }


                    ${
                        ad.area > 0
                            ? `
                                <span class="property-feature">
                                    ${ad.area} متر
                                </span>
                            `
                            : ''
                    }


                    ${
                        ad.bedrooms !== '-'
                            ? `
                                <span class="property-feature">
                                    ${escapeHtml(
                                        ad.bedrooms
                                    )} خواب
                                </span>
                            `
                            : ''
                    }


                    ${
                        ad.parking
                            ? `
                                <span class="property-feature">
                                    🚗 پارکینگ
                                </span>
                            `
                            : ''
                    }


                    ${
                        ad.elevator
                            ? `
                                <span class="property-feature">
                                    🛗 آسانسور
                                </span>
                            `
                            : ''
                    }

                </div>


                <div class="property-footer">

                    <a
                        href="${detailsUrl}"
                        class="property-detail"
                    >
                        مشاهده جزئیات
                    </a>


                    <button
                        type="button"
                        class="property-like"
                        aria-label="افزودن به علاقه‌مندی‌ها"
                    >

                        <svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >

                            <path
                                d="
                                    M20.84 4.61
                                    a5.5 5.5 0 0 0-7.78 0
                                    L12 5.67
                                    l-1.06-1.06
                                    a5.5 5.5 0 0 0-7.78 7.78
                                    l1.06 1.06
                                    L12 21.23
                                    l7.78-7.78
                                    1.06-1.06
                                    a5.5 5.5 0 0 0 0-7.78z
                                "
                            ></path>

                        </svg>

                    </button>


                    <button
                        type="button"
                        class="property-like property-compare"
                        data-compare-add="${escapeHtml(ad.id)}"
                        aria-label="افزودن به مقایسه"
                        title="افزودن به مقایسه"
                    >
                        ⚖️
                    </button>

                </div>

            </div>

        `;

        return card;

    }


    /* =========================================================
       SORT EVENT
    ========================================================== */

    sortSelect.addEventListener(
        'change',
        function () {

            renderProperties();

        }
    );


    /* =========================================================
       INITIAL LOAD
    ========================================================== */

    document.addEventListener(
        'DOMContentLoaded',
        function () {

            loadProperties();

        }
    );

})();
</script>


<?php
require_once __DIR__ . '/footer.php';
?>