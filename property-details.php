<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/consultant_helper.php';

$propertyId = isset($_GET['id'])
    ? trim((string) $_GET['id'])
    : '';

$propertyData = null;

$isMockMode =
    defined('MOCK_MODE')
        ? MOCK_MODE
        : false; // فرض می‌کنیم ماک مود غیرفعال است چون از دیتابیس استفاده می‌کنیم

/* =====================================================
   CONSULTANT SETTINGS (Fallback)
   ===================================================== */
$consultantName = function_exists('getConsultantName')
    ? getConsultantName()
    : 'مشاور ملکینو';

$consultantPhone = function_exists('getConsultantPhone')
    ? getConsultantPhone()
    : '';

$consultantTelegram = function_exists('getConsultantTelegramLink')
    ? getConsultantTelegramLink()
    : '';


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

    $defaultLogo = __DIR__ . '/assets/logo.png';
    if (file_exists($defaultLogo)) {
        return 'assets/logo.png';
    }

    return '';
}


/* =====================================================
   JSON ARRAY HELPER
   ===================================================== */

function normalizeArrayValue($value): array
{
    if (is_array($value)) {
        return $value;
    }

    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}


/* =====================================================
   محاسبه قیمت هر متر مربع
   ===================================================== */
function calculatePricePerSquareMeter(array $ad): ?string
{
    $area = $ad['area'] ?? null;
    if (!$area || $area <= 0) {
        return null;
    }

    $price = null;
    $priceKeys = ['price_sell', 'total_price', 'display_price'];
    foreach ($priceKeys as $key) {
        if (isset($ad[$key]) && is_numeric(str_replace(',', '', (string)$ad[$key])) && (float)str_replace(',', '', (string)$ad[$key]) > 0) {
            $price = (float) str_replace(',', '', (string)$ad[$key]);
            break;
        }
    }

    if (!$price) {
        return null;
    }

    $pricePerMeter = $price / $area;
    $formatted = number_format($pricePerMeter, 0, '.', ',');

    return $formatted . ' تومان';
}


/* =====================================================
   PUBLIC SPECS — بازنویسی کامل با پوشش همه کلیدهای تخصصی
   ===================================================== */

function buildPublicSpecs(array $ad): array
{
    $specDefinitions = [
        'کلید نخورده' => ['key_not_turned', 'is_not_keyed', 'is_new', 'new_building', 'never_lived'],

        'متراژ (متر مربع)' => ['area', 'area_apt', 'area_comm', 'office_area', 'built_area'],
        'متراژ زمین (متر مربع)' => ['land_area', 'land_villa', 'garden_area'],
        'زیربنا (متر مربع)' => ['built_area', 'built_villa'],
        'مساحت زمین (متر مربع)' => ['land_area', 'land_villa', 'garden_area'],
        'مساحت باغ (متر مربع)' => ['garden_area', 'land_area'],
        'متراژ خانه باغ (متر مربع)' => ['building_area'],

        'طبقه' => ['floor', 'floor_apt', 'office_floor'],
        'تعداد اتاق' => ['rooms', 'rooms_apt', 'rooms_villa', 'office_rooms'],
        'سال ساخت' => ['year', 'year_apt', 'year_villa', 'office_year'],
        'تعداد کل واحدها' => ['units_per_floor_apt', 'total_units', 'units_total', 'units_per_floor', 'number_of_units'],
        'تعداد واحد در طبقه' => ['units_per_floor', 'office_units_per_floor'],
        'نوع پوشش کف' => ['floor_covering', 'flooring_apt', 'flooring_villa', 'floor_comm', 'office_flooring', 'flooring', 'floor_type', 'floor_cover'],
        'نوع کابینت' => ['cabinet_type', 'cabinet_apt', 'cabinet_villa', 'cabinet_comm', 'office_cabinet', 'cabinet'],
        'سیستم سرمایش' => ['cooling_system', 'cooling_apt', 'cooling_villa', 'cooling_comm', 'office_cooling', 'cooling'],
        'سیستم گرمایش' => ['heating_system', 'heating_apt', 'heating_villa', 'heating_comm', 'office_heating', 'heating'],
        'وضعیت ملک' => ['condition', 'condition_apt', 'condition_villa', 'office_condition'],
        'جهت ملک' => ['orientation', 'orientation_apt', 'land_direction', 'office_orientation'],
        'کاربری' => ['usage', 'land_usage', 'office_usage', 'usage_comm'],
        'بر مغازه (متر)' => ['front_width', 'front_comm'],
        'بر زمین (متر)' => ['front_width', 'land_front_width'],
        'کوچه یا معبر (متر)' => ['length', 'land_length'],
        'تعداد بر' => ['blocks', 'land_blocks'],
        'وضعیت عقب‌نشینی' => ['setback_status', 'land_setback_status'],
        'نوع درختان' => ['tree_types'],
        'آب ملکی' => ['irrigation_source'],
        'نوع آبیاری' => ['irrigation_type'],
        'استخر' => ['has_pond'],
        'نوع سند' => ['document_type', 'deed_type', 'land_deed_type'],
        'پوشش دیوارها' => ['wall_covering', 'wall_comm'],
        'سرمایش' => ['cooling_system', 'cooling_comm'],
        'گرمایش' => ['heating_system', 'heating_comm'],
        'موقعیت' => ['orientation', 'orientation_comm'],
        'مناسب برای' => ['usage', 'usage_comm'],
        'وضعیت واحد' => ['condition', 'office_condition'],
    ];

    $propertyType = trim((string)($ad['property_type'] ?? ''));
    if ($propertyType === '') {
        $propertyType = 'آپارتمان';
    }

    $existing = $ad['property_details'] ?? [];
    if (is_string($existing)) {
        $decoded = json_decode($existing, true);
        $existing = is_array($decoded) ? $decoded : [];
    }
    $existing = is_array($existing) ? $existing : [];

    $allData = array_merge($existing, $ad);

    $desiredLabels = [];
    switch ($propertyType) {
        case 'آپارتمان':
            $desiredLabels = [
                'کلید نخورده',
                'متراژ (متر مربع)',
                'طبقه',
                'تعداد اتاق',
                'سال ساخت',
                'تعداد کل واحدها',
                'نوع پوشش کف',
                'نوع کابینت',
                'سیستم سرمایش',
                'سیستم گرمایش',
            ];
            break;
        case 'ویلا':
            $desiredLabels = [
                'متراژ زمین (متر مربع)',
                'زیربنا (متر مربع)',
                'تعداد اتاق',
                'سال ساخت',
                'وضعیت ملک',
                'نوع پوشش کف',
                'نوع کابینت',
                'سیستم سرمایش',
                'سیستم گرمایش',
            ];
            break;
        case 'زمین':
            $desiredLabels = [
                'مساحت زمین (متر مربع)',
                'کاربری',
                'جهت ملک',
                'بر زمین (متر)',
                'کوچه یا معبر (متر)',
                'تعداد بر',
                'وضعیت عقب‌نشینی',
            ];
            break;
        case 'باغ':
            $desiredLabels = [
                'مساحت باغ (متر مربع)',
                'نوع درختان',
                'آب ملکی',
                'نوع آبیاری',
                'متراژ خانه باغ (متر مربع)',
                'استخر',
                'نوع سند',
            ];
            break;
        case 'تجاری':
            $desiredLabels = [
                'متراژ (متر مربع)',
                'بر مغازه (متر)',
                'پوشش کف',
                'پوشش دیوارها',
                'سرمایش',
                'گرمایش',
                'موقعیت',
                'مناسب برای',
            ];
            break;
        case 'اداری':
            $desiredLabels = [
                'کلید نخورده',
                'متراژ (متر مربع)',
                'طبقه',
                'تعداد واحد در طبقه',
                'تعداد اتاق',
                'سال ساخت',
                'وضعیت واحد',
                'نوع پوشش کف',
                'نوع کابینت',
                'سیستم سرمایش',
                'سیستم گرمایش',
            ];
            break;
        default:
            $desiredLabels = array_keys($specDefinitions);
            break;
    }

    $result = [];

    foreach ($desiredLabels as $label) {
        $possibleKeys = $specDefinitions[$label] ?? [];
        $value = null;
        $foundKey = null;

        foreach ($possibleKeys as $key) {
            if (array_key_exists($key, $allData)) {
                $val = $allData[$key];
                if ($val !== null && $val !== '' && $val !== '0' && $val !== 0 && $val !== '[]') {
                    $value = $val;
                    $foundKey = $key;
                    break;
                }
            }
        }

        if ($value === null || $value === '' || $value === '0' || $value === 0 || $value === '[]') {
            continue;
        }

        // تبدیل مقادیر بولی برای برخی کلیدها
        if (in_array($foundKey, ['parking', 'elevator', 'warehouse', 'balcony', 'renovated', 'has_well', 'has_pond', 'has_building', 'is_vip', 'featured', 'urgent', 'key_not_turned', 'is_not_keyed', 'is_new', 'new_building', 'never_lived'])) {
            $value = ($value == 1 || $value === true || $value === '1' || $value === 'true') ? 'دارد' : 'ندارد';
        }

        // تغییر ویژه برای کلید نخورده: به جای «دارد» جمله کامل نمایش داده شود
        if (in_array($foundKey, ['is_not_keyed', 'key_not_turned', 'is_new', 'new_building', 'never_lived']) && $value === 'دارد') {
            $value = 'این ملک کلید نخورده است';
        }

        $result[$label] = $value;
    }

    return $result;
}


/* =====================================================
   PUBLIC IMAGES
   ===================================================== */

function normalizePublicImages($images): array
{
    $images = normalizeArrayValue($images);
    $result = [];

    foreach ($images as $img) {
        if (!is_string($img)) continue;
        $img = trim($img);
        if ($img === '') continue;
        if (!in_array($img, $result, true)) {
            $result[] = $img;
        }
    }

    return $result;
}


/* =====================================================
   IMAGE PATH
   ===================================================== */

function normalizeImagePath(string $path): string
{
    $path = trim($path);
    if ($path === '') return '';

    if (preg_match('#^https?://#i', $path)) return $path;
    if (strpos($path, 'data:image/') === 0) return $path;
    if (strpos($path, '/') === 0) return $path;

    return $path;
}


/* =====================================================
   TELEGRAM CONTACT
   ===================================================== */

function findTelegramLink(array $ad): string
{
    $possibleValues = [
        $ad['telegram_link'] ?? '',
        $ad['telegram'] ?? '',
        $ad['consultant_telegram'] ?? '',
        $ad['consultant_telegram_link'] ?? '',
        $ad['contact_telegram'] ?? '',
        $ad['telegram_username'] ?? ''
    ];

    foreach ($possibleValues as $value) {
        $value = trim((string) $value);
        if ($value === '') continue;

        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }
        if (strpos($value, '@') === 0) {
            return 'https://t.me/' . ltrim($value, '@');
        }
        if (preg_match('/^[A-Za-z0-9_]{4,}$/', $value)) {
            return 'https://t.me/' . $value;
        }
    }

    $configCandidates = [
        defined('CONSULTANT_TELEGRAM') ? CONSULTANT_TELEGRAM : '',
        defined('TELEGRAM_CONSULTANT') ? TELEGRAM_CONSULTANT : '',
        defined('CONSULTANT_TELEGRAM_LINK') ? CONSULTANT_TELEGRAM_LINK : ''
    ];

    foreach ($configCandidates as $value) {
        $value = trim((string) $value);
        if ($value === '') continue;

        if (preg_match('#^https?://#i', $value)) return $value;
        if (strpos($value, '@') === 0) {
            return 'https://t.me/' . ltrim($value, '@');
        }
        return 'https://t.me/' . $value;
    }

    return '';
}


/* =====================================================
   LOAD DATA
   ===================================================== */

if ($isMockMode) {
    // Mock mode (اگر فعال باشد)
    $jsonFile = __DIR__ . '/ads.json';
    $ads = [];

    if (file_exists($jsonFile)) {
        $content = file_get_contents($jsonFile);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $ads = $decoded;
        }
    }

    foreach ($ads as $ad) {
        if ((string)($ad['id'] ?? '') !== $propertyId) continue;
        if (($ad['status'] ?? '') !== 'published') continue;

        $images = normalizePublicImages($ad['images'] ?? []);
        $selectedImages = normalizePublicImages($ad['selected_images'] ?? ($ad['selectedImages'] ?? []));

        $publicImages = count($selectedImages) > 0 ? $selectedImages : $images;
        if (count($selectedImages) > 0 && count($images) > 0) {
            $publicImages = normalizePublicImages(array_merge($selectedImages, $images));
        }

        if (empty($publicImages)) {
            $logoUrl = getSiteLogoUrl();
            if (!empty($logoUrl)) {
                $publicImages = [$logoUrl];
            }
        }

        $amenities = normalizeArrayValue($ad['amenities'] ?? []);
        $specs = buildPublicSpecs($ad);

        // استخراج متراژ
        $area = null;
        $possibleAreaKeys = ['area', 'built_area', 'land_area', 'office_area', 'garden_area', 'area_apt', 'area_comm', 'land_villa', 'built_villa'];
        foreach ($possibleAreaKeys as $key) {
            if (isset($ad[$key]) && is_numeric($ad[$key]) && $ad[$key] > 0) {
                $area = (float) $ad[$key];
                break;
            }
        }
        if (!$area) {
            $details = $ad['property_details'] ?? [];
            if (is_string($details)) {
                $details = json_decode($details, true);
            }
            if (is_array($details)) {
                foreach ($possibleAreaKeys as $key) {
                    if (isset($details[$key]) && is_numeric($details[$key]) && $details[$key] > 0) {
                        $area = (float) $details[$key];
                        break;
                    }
                }
            }
        }

        $price = '';
        if (!empty($ad['display_price']) && $ad['display_price'] !== '0') {
            $price = $ad['display_price'];
        } elseif (!empty($ad['price_sell']) && $ad['price_sell'] !== '0') {
            $price = $ad['price_sell'];
        } elseif (!empty($ad['total_price']) && $ad['total_price'] !== '0') {
            $price = $ad['total_price'];
        } elseif (!empty($ad['deposit']) && $ad['deposit'] !== '0') {
            $price = $ad['deposit'];
            if (!empty($ad['rent_monthly']) && $ad['rent_monthly'] !== '0') {
                $price .= ' | ' . $ad['rent_monthly'];
            }
        }

        $telegramLink = findTelegramLink($ad);
        $isNotKeyed = isset($ad['is_not_keyed']) ? (int)$ad['is_not_keyed'] : 0;
        if (!$isNotKeyed) {
            // ممکن است در property_details باشد
            $details = $ad['property_details'] ?? [];
            if (is_string($details)) $details = json_decode($details, true);
            if (is_array($details) && isset($details['is_not_keyed'])) {
                $isNotKeyed = (int)$details['is_not_keyed'];
            }
        }

        $propertyData = [
            'id' => (string) $ad['id'],
            'title' => $ad['title'] ?? 'ملک بدون عنوان',
            'transaction_type' => $ad['transaction_type'] ?? ($ad['transactionType'] ?? 'فروش'),
            'property_type' => $ad['property_type'] ?? ($ad['propertyType'] ?? 'آپارتمان'),
            'price' => $price,
            'priceCondition' => (($ad['price_condition'] ?? '') === 'fixed') ? 'مقطوع' : 'قابل مذاکره',
            'location' => $ad['location'] ?? '',
            'neighborhood' => $ad['neighborhood'] ?? ($ad['location'] ?? ''),
            'description' => $ad['description'] ?? '',
            'images' => $publicImages,
            'amenities' => $amenities,
            'specs' => $specs,
            'isVip' => false,
            'phone' => $ad['phone'] ?? $ad['mobile'] ?? '',
            'last_name' => $ad['last_name'] ?? '',
            'telegram_link' => $telegramLink,
            'deposit'          => $ad['deposit'] ?? '',
            'rent_monthly'     => $ad['rent_monthly'] ?? '',
            'full_rent'        => $ad['full_rent'] ?? '',
            'full_rent_enabled'=> $ad['full_rent_enabled'] ?? 0,
            'price_sell'       => $ad['price_sell'] ?? '',
            'total_price'      => $ad['total_price'] ?? '',
            'area'             => $area,
            'is_not_keyed'     => $isNotKeyed,
        ];

        break;
    }

} else {
    // حالت دیتابیس (اصلی)
    if (isset($pdo) && $propertyId !== '') {
        try {
            $stmt = $pdo->prepare("SELECT * FROM ads WHERE id = ? AND status = 'published' LIMIT 1");
            $stmt->execute([$propertyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $publicImages = [];
                if (isset($pdo)) {
                    $imageStmt = $pdo->prepare("
                        SELECT filename, storage_path
                        FROM images
                        WHERE ad_id = ?
                          AND is_selected = 1
                          AND publish_publicly = 1
                        ORDER BY is_primary DESC, sort_order ASC, id ASC
                    ");
                    $imageStmt->execute([$propertyId]);
                    $imageRows = $imageStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($imageRows as $imageRow) {
                        $path = trim((string)($imageRow['storage_path'] ?? ''));
                        if ($path === '') {
                            $path = trim((string)($imageRow['filename'] ?? ''));
                        }
                        if ($path !== '') {
                            $publicImages[] = $path;
                        }
                    }
                    $publicImages = normalizePublicImages($publicImages);
                }

                if (empty($publicImages)) {
                    $logoUrl = getSiteLogoUrl();
                    if (!empty($logoUrl)) {
                        $publicImages = [$logoUrl];
                    }
                }

                $amenities = [];
                if (isset($pdo)) {
                    $amenStmt = $pdo->prepare("
                        SELECT am.name
                        FROM ad_amenities aa
                        INNER JOIN amenities am ON am.id = aa.amenity_id
                        WHERE aa.ad_id = ?
                        ORDER BY am.sort_order, am.id
                    ");
                    $amenStmt->execute([$propertyId]);
                    $amenities = $amenStmt->fetchAll(PDO::FETCH_COLUMN);
                }
                $amenitiesFromColumn = normalizeArrayValue($row['amenities'] ?? []);
                $amenities = array_unique(array_merge($amenities, $amenitiesFromColumn));

                $row['property_details'] = normalizeArrayValue($row['property_details'] ?? []);

                // استخراج متراژ
                $area = null;
                $possibleAreaKeys = ['area', 'built_area', 'land_area', 'office_area', 'garden_area', 'area_apt', 'area_comm', 'land_villa', 'built_villa'];
                foreach ($possibleAreaKeys as $key) {
                    if (isset($row[$key]) && is_numeric($row[$key]) && $row[$key] > 0) {
                        $area = (float) $row[$key];
                        break;
                    }
                }
                if (!$area) {
                    $details = $row['property_details'] ?? [];
                    if (is_array($details)) {
                        foreach ($possibleAreaKeys as $key) {
                            if (isset($details[$key]) && is_numeric($details[$key]) && $details[$key] > 0) {
                                $area = (float) $details[$key];
                                break;
                            }
                        }
                    }
                }

                $price = $row['display_price'] ?? '';
                if ($price === '' || $price === '0') {
                    $price = $row['price_sell'] ?? '';
                }
                if ($price === '' || $price === '0') {
                    $price = $row['deposit'] ?? '';
                }
                if ($price === '' || $price === '0') {
                    $price = $row['rent_monthly'] ?? '';
                }

                $telegramLink = findTelegramLink($row);

                // خواندن is_not_keyed از ستون جدول
                $isNotKeyed = isset($row['is_not_keyed']) ? (int)$row['is_not_keyed'] : 0;

                $propertyData = [
                    'id' => (string) $row['id'],
                    'title' => $row['title'] ?? 'ملک بدون عنوان',
                    'transaction_type' => $row['transaction_type'] ?? 'فروش',
                    'property_type' => $row['property_type'] ?? 'آپارتمان',
                    'price' => $price,
                    'priceCondition' => (($row['price_condition'] ?? '') === 'fixed') ? 'مقطوع' : 'قابل مذاکره',
                    'location' => $row['location'] ?? '',
                    'neighborhood' => $row['neighborhood'] ?? ($row['location'] ?? ''),
                    'description' => $row['description'] ?? '',
                    'images' => $publicImages,
                    'amenities' => $amenities,
                    'specs' => buildPublicSpecs($row),
                    'isVip' => false,
                    'phone' => $row['phone'] ?? $row['mobile'] ?? '',
                    'last_name' => $row['last_name'] ?? '',
                    'telegram_link' => $telegramLink,
                    'deposit'          => $row['deposit'] ?? '',
                    'rent_monthly'     => $row['rent_monthly'] ?? '',
                    'full_rent'        => $row['full_rent'] ?? '',
                    'full_rent_enabled'=> $row['full_rent_enabled'] ?? 0,
                    'price_sell'       => $row['price_sell'] ?? '',
                    'total_price'      => $row['total_price'] ?? '',
                    'area'             => $area,
                    'is_not_keyed'     => $isNotKeyed,
                ];
            }
        } catch (PDOException $e) {
            $propertyData = null;
        }
    }
}


/* =====================================================
   OVERRIDE CONSULTANT WITH SPECIALIZED ONE
   ===================================================== */
if (is_array($propertyData)) {
    $specializedConsultant = findConsultantForAd(
        $propertyData['property_type'] ?? '',
        $propertyData['transaction_type'] ?? ''
    );

    if ($specializedConsultant) {
        $consultantName = $specializedConsultant['name'] ?? $consultantName;
        $consultantPhone = $specializedConsultant['phone'] ?? $consultantPhone;

        $telegramLink = $specializedConsultant['telegram_link'] ?? '';
        if (empty($telegramLink) && !empty($specializedConsultant['telegram_username'])) {
            $telegramLink = 'https://t.me/' . ltrim($specializedConsultant['telegram_username'], '@');
        }
        if (!empty($telegramLink)) {
            $consultantTelegram = $telegramLink;
        }
    }
}


/* =====================================================
   CENTRAL CONSULTANT OVERRIDE
   ===================================================== */
if (is_array($propertyData)) {
    $propertyData['consultant'] = [
        'name' => $consultantName,
        'phone' => $consultantPhone,
        'telegram' => $consultantTelegram
    ];
}


/* =====================================================
   HEADER
   ===================================================== */

require_once __DIR__ . '/header.php';
?>


<style>

:root {

    --detail-shadow:
        0 12px 36px rgba(15,23,42,.08);

    --detail-soft:
        rgba(6,78,78,.08);

    --detail-gold:
        linear-gradient(
            135deg,
            #c89d32,
            #f0d878
        );
}
/* =========================================================
   FINAL FIX — PROPERTY DETAILS CONTACT BAR
   ========================================================= */

/* فاصله پایین محتوای صفحه */
.main-content {
    padding-bottom: 165px !important;
}


/* نوار تماس مشاور */
body .bottom-actions-fixed {
    position: fixed !important;

    right: 0 !important;
    left: 0 !important;

    /*
     * ارتفاع فوتر فعلی ملکینو حدود 75px است.
     * نوار تماس دقیقاً بالای آن قرار می‌گیرد.
     */
    bottom: 75px !important;

    width: 100% !important;

    margin: 0 !important;

    padding:
        10px
        14px
        calc(
            10px +
            env(safe-area-inset-bottom)
        ) !important;

    box-sizing: border-box !important;

    z-index: 900 !important;

    background:
        rgba(255,255,255,.97) !important;

    border-top:
        1px solid
        var(--border) !important;

    box-shadow:
        0 -8px 24px
        rgba(0,0,0,.08) !important;

    backdrop-filter:
        blur(18px);

    -webkit-backdrop-filter:
        blur(18px);
}


/*
 * خود فوتر باید بالاتر از نوار تماس باشد
 */
body .bottom-nav,
body .bottom-navigation,
body .app-bottom-nav {
    z-index: 1000 !important;
}


/* محتوای نوار تماس */
body .consultant-bar {
    width:
        min(
            760px,
            100%
        ) !important;

    margin:
        0 auto !important;

    display:
        flex !important;

    align-items:
        center !important;

    justify-content:
        space-between !important;

    gap:
        10px !important;
}


/* دکمه‌ها */
body .consultant-actions {
    display:
        flex !important;

    gap:
        8px !important;

    flex:
        0 0 auto !important;
}


body .consultant-btn {
    height:
        44px !important;

    min-width:
        92px !important;

    display:
        flex !important;

    align-items:
        center !important;

    justify-content:
        center !important;

    border-radius:
        13px !important;

    text-decoration:
        none !important;

    font-size:
        11px !important;

    font-weight:
        800 !important;
}


/* حالت موبایل */
@media (max-width: 600px) {

    .main-content {
        padding-bottom:
            160px !important;
    }

    body .bottom-actions-fixed {

        bottom:
            72px !important;

        padding:
            8px
            10px !important;
    }

    body .consultant-info {
        display:
            none !important;
    }

    body .consultant-bar {
        width:
            100% !important;
    }

    body .consultant-actions {
        width:
            100% !important;

        display:
            grid !important;

        grid-template-columns:
            1fr 1fr !important;

        gap:
            8px !important;
    }

    body .consultant-btn {
        width:
            100% !important;

        min-width:
            0 !important;

        height:
            46px !important;
    }
}


/* حالت Dark */
[data-theme="dark"] body .bottom-actions-fixed {

    background:
        rgba(10,22,22,.97) !important;

    border-top-color:
        #294646 !important;

    box-shadow:
        0 -8px 24px
        rgba(0,0,0,.35) !important;
}

/* =========================================================
   MAIN
========================================================= */

.main-content {

    flex: 1;

    overflow-y: auto;

    background: var(--bg);

    padding:
        0 0
        190px;

    display:
        flex;

    flex-direction:
        column;
}


/* =========================================================
   SHELL
========================================================= */

.property-shell {

    padding:
        var(--space-3);

    display:
        flex;

    flex-direction:
        column;

    gap:
        var(--space-3);
}


/* =========================================================
   GALLERY
========================================================= */

.hero-gallery {

    position: relative;

    width: 100%;

    height:
        clamp(
            300px,
            52vw,
            480px
        );

    background:
        #0f172a;

    overflow: hidden;

    border-radius:
        22px;

    box-shadow:
        var(--detail-shadow);
}


.gallery-slide {

    height: 100%;

    display: flex;

    direction: ltr;

    transition:
        transform .45s ease;

    will-change:
        transform;

    touch-action:
        pan-y;
}


.gallery-slide > div {

    flex: 0 0 100%;

    width: 100%;

    height: 100%;

    display: flex;

    align-items: center;

    justify-content: center;

    background: #061B1B;
}


.gallery-slide img {

    width: 100%;

    height: 100%;

    display: block;

    object-fit: contain;

    object-position: center;

    background: #061B1B;

    user-select: none;

    -webkit-user-drag: none;
}


.gallery-slide .no-image {

    width: 100%;

    height: 100%;

    display: flex;

    align-items: center;

    justify-content: center;
}


.hero-gallery::after {

    content: '';

    position:
        absolute;

    right: 0;
    bottom: 0;
    left: 0;

    height: 42%;

    background:
        linear-gradient(
            transparent,
            rgba(0,0,0,.72)
        );

    pointer-events:
        none;
}


.gallery-topbar {

    position:
        absolute;

    top: 16px;
    right: 16px;
    left: 16px;

    display:
        flex;

    justify-content:
        space-between;

    align-items:
        center;

    z-index:
        8;
}


.glass-btn {

    width: 44px;
    height: 44px;

    border:
        1px solid
        rgba(255,255,255,.22);

    background:
        rgba(15,23,42,.44);

    backdrop-filter:
        blur(12px);

    -webkit-backdrop-filter:
        blur(12px);

    color:
        #fff;

    border-radius:
        50%;

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;

    cursor:
        pointer;

    transition:
        transform .18s ease,
        background .18s ease,
        color .18s ease;
}


.glass-btn:hover {

    transform:
        translateY(-2px);
}


.glass-btn:active {

    transform:
        scale(.95);
}


.glass-btn.active {

    color:
        #e11d48;

    background:
        rgba(255,255,255,.94);

    border-color:
        rgba(255,255,255,.9);
}


.gallery-counter {

    background:
        rgba(15,23,42,.52);

    border:
        1px solid
        rgba(255,255,255,.16);

    backdrop-filter:
        blur(12px);

    -webkit-backdrop-filter:
        blur(12px);

    color:
        #fff;

    padding:
        7px 12px;

    border-radius:
        999px;

    font-size:
        12px;

    font-weight:
        700;
}


.gallery-bottom {

    position:
        absolute;

    right:
        16px;

    left:
        16px;

    bottom:
        16px;

    display:
        flex;

    align-items:
        flex-end;

    justify-content:
        space-between;

    gap:
        12px;

    z-index:
        8;
}


.gallery-code {

    color:
        #fff;

    font-size:
        12px;

    padding:
        6px 10px;

    border-radius:
        999px;

    background:
        rgba(15,23,42,.52);

    backdrop-filter:
        blur(12px);

    -webkit-backdrop-filter:
        blur(12px);
}


.gallery-actions {

    display:
        flex;

    gap:
        8px;
}


.gallery-nav {

    width:
        42px;

    height:
        42px;

    border:
        1px solid
        rgba(255,255,255,.18);

    background:
        rgba(255,255,255,.92);

    color:
        #111827;

    border-radius:
        50%;

    font-size:
        24px;

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;

    cursor:
        pointer;

    box-shadow:
        0 6px 18px
        rgba(0,0,0,.18);

    z-index:
        8;
}


.gallery-nav:active {

    transform:
        scale(.94);
}


/* =========================================================
   THUMBS
========================================================= */

.gallery-thumbs {

    display:
        flex;

    gap:
        8px;

    overflow-x:
        auto;

    padding-top:
        2px;

    scrollbar-width:
        none;

    direction:
        rtl;
}


.gallery-thumbs::-webkit-scrollbar {
    display:
        none;
}


.gallery-thumb {

    flex:
        0 0 76px;

    height:
        60px;

    padding:
        0;

    border:
        2px solid
        transparent;

    border-radius:
        10px;

    overflow:
        hidden;

    background:
        var(--surface);

    cursor:
        pointer;

    opacity:
        .68;

    transition:
        .2s ease;
}


.gallery-thumb.active {

    border-color:
        var(--gold);

    opacity:
        1;

    transform:
        translateY(-1px);
}


.gallery-thumb img {

    width:
        100%;

    height:
        100%;

    object-fit:
        cover;

    display:
        block;
}


/* =========================================================
   INFO
========================================================= */

.info-head {

    display:
        flex;

    flex-direction:
        column;

    gap:
        10px;
}


.property-code {

    font-size:
        12px;

    color:
        var(--text-secondary);

    display:
        inline-flex;

    align-items:
        center;

    width:
        max-content;

    padding:
        5px 10px;

    border:
        1px solid
        var(--border);

    border-radius:
        999px;

    background:
        var(--surface);
}


.property-title {

    font-size:
        26px;

    font-weight:
        900;

    line-height:
        1.35;

    color:
        var(--text-primary);

    margin:
        0;
}


.property-meta {

    display:
        flex;

    flex-wrap:
        wrap;

    gap:
        8px;
}


.meta-chip {

    display:
        inline-flex;

    align-items:
        center;

    gap:
        5px;

    padding:
        7px 11px;

    border-radius:
        999px;

    background:
        var(--surface);

    border:
        1px solid
        var(--border);

    color:
        var(--text-secondary);

    font-size:
        12px;

    font-weight:
        700;
}

/* برچسب کلید نخورده */
.meta-chip.key-not-turned {
    background: var(--gold-bg);
    color: var(--gold-dark);
    border-color: var(--gold);
    font-weight: 800;
}

/* =========================================================
   PRICE
========================================================= */

.price-card {

    display:
        flex;

    align-items:
        center;

    justify-content:
        space-between;

    gap:
        12px;

    flex-wrap:
        wrap;

    padding:
        16px;

    border-radius:
        18px;

    background:
        var(--surface);

    border:
        1px solid
        var(--border);

    box-shadow:
        var(--detail-shadow);
}


.price-label {

    font-size:
        12px;

    color:
        var(--text-secondary);

    margin-bottom:
        4px;
}


.price-large {

    font-size:
        28px;

    font-weight:
        900;

    color:
        var(--gold);
}


/* =========================================================
   SECTION
========================================================= */

.section-card {

    background:
        var(--surface);

    border:
        1px solid
        var(--border);

    border-radius:
        18px;

    padding:
        18px;

    box-shadow:
        var(--detail-shadow);
}


.section-title {

    display:
        flex;

    align-items:
        center;

    gap:
        8px;

    font-size:
        18px;

    font-weight:
        800;

    color:
        var(--text-primary);

    margin:
        0 0 14px;
}


.section-title::before {

    content:
        '';

    width:
        4px;

    height:
        20px;

    border-radius:
        4px;

    background:
        var(--gold);
}


/* =========================================================
   SPECS
========================================================= */

.specs-grid {

    display:
        grid;

    grid-template-columns:
        repeat(
            2,
            minmax(
                0,
                1fr
            )
        );

    gap:
        10px;
}


.specs-item {

    min-height:
        56px;

    padding:
        12px 13px;

    border:
        1px solid
        var(--border);

    border-radius:
        12px;

    background:
        var(--bg);

    display:
        flex;

    flex-direction:
        column;

    justify-content:
        center;

    gap:
        3px;
}


.specs-label {

    font-size:
        11px;

    color:
        var(--text-secondary);
}


.specs-value {

    font-size:
        14px;

    font-weight:
        800;

    color:
        var(--text-primary);

    word-break:
        break-word;
}


/* =========================================================
   AMENITIES
========================================================= */

.amenities-grid {

    display:
        grid;

    grid-template-columns:
        repeat(
            2,
            minmax(
                0,
                1fr
            )
        );

    gap:
        10px;
}


.amenity-item {

    display:
        flex;

    align-items:
        center;

    gap:
        8px;

    padding:
        11px 12px;

    border:
        1px solid
        var(--border);

    border-radius:
        12px;

    background:
        var(--bg);
}


.amenity-check {

    width:
        24px;

    height:
        24px;

    border-radius:
        50%;

    background:
        var(--detail-soft);

    color:
        var(--primary);

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;

    font-weight:
        900;

    flex:
        none;
}


.amenity-check.cross {
    background: var(--danger-bg);
    color: var(--danger);
}


.amenity-name {

    font-size:
        13px;

    font-weight:
        700;

    color:
        var(--text-primary);
}


/* =========================================================
   DESCRIPTION
========================================================= */

.description {

    font-size:
        15px;

    line-height:
        2;

    color:
        var(--text-secondary);

    white-space:
        pre-wrap;

    margin:
        0;
}


/* =========================================================
   EMPTY
========================================================= */

.empty-box {

    padding:
        22px;

    text-align:
        center;

    color:
        var(--text-secondary);

    border:
        1px dashed
        var(--border);

    border-radius:
        14px;
}


/* =========================================================
   BOTTOM ACTIONS
========================================================= */

.bottom-actions-fixed {

    position: fixed;

    right: 0;
    left: 0;
    bottom: 0;

    z-index: 1200;

    padding:
        10px
        var(--space-3)
        calc(
            10px + env(safe-area-inset-bottom)
        );

    background:
        rgba(255,255,255,.96);

    backdrop-filter: blur(18px);

    -webkit-backdrop-filter: blur(18px);

    border-top: 1px solid var(--border);

    box-shadow:
        0 -10px 30px rgba(0,0,0,.08);
}


.consultant-bar {

    width: min(760px, 100%);
    margin: 0 auto;

    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}


.consultant-info {

    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 0;
}


.consultant-avatar {

    width: 40px;
    height: 40px;
    flex: 0 0 auto;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 12px;

    background:
        linear-gradient(135deg,#0A5C5C,#063D3D);

    color: #F0D36A;
    font-size: 18px;
}


.consultant-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
    gap: 2px;
}


.consultant-text strong {
    color: var(--text-primary);
    font-size: 11px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}


.consultant-text span {
    color: var(--text-secondary);
    font-size: 8px;
}


.consultant-actions {
    display: flex;
    gap: 7px;
    flex: 0 0 auto;
}


.consultant-btn {
    min-width: 88px;
    height: 42px;

    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;

    border-radius: 12px;
    text-decoration: none;
    font-family: inherit;
    font-size: 10px;
    font-weight: 800;

    transition:
        transform .18s ease,
        opacity .18s ease;
}


.consultant-btn:active {
    transform: scale(.96);
}


.consultant-btn.call {
    background: var(--primary);
    color: #fff;
}


.consultant-btn.telegram {
    background: linear-gradient(135deg,#D4AF37,#F0D36A);
    color: #142020;
}


.consultant-btn.disabled {
    opacity: .45;
    cursor: default;
}


[data-theme="dark"] .bottom-actions-fixed {
    background: rgba(10,22,22,.96);
    border-top-color: #294646;
    box-shadow: 0 -10px 30px rgba(0,0,0,.35);
}


[data-theme="dark"] .consultant-text strong {
    color: #F4F8F7;
}


@media (max-width: 480px) {

    .consultant-bar {
        gap: 8px;
    }

    .consultant-info {
        flex: 0 0 auto;
    }

    .consultant-text {
        display: none;
    }

    .consultant-avatar {
        width: 38px;
        height: 38px;
    }

    .consultant-actions {
        flex: 1;
    }

    .consultant-btn {
        flex: 1;
        min-width: 0;
        height: 44px;
        font-size: 10px;
    }
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 700px) {

    .hero-gallery {
        height: min(62vh, 520px);
        min-height: 260px;
    }
}


@media (max-width: 520px) {

    .main-content {

        padding-bottom:
            175px;
    }


    .property-shell {

        padding:
            12px;

        gap:
            12px;
    }


    .hero-gallery {

        height:
            310px;

        border-radius:
            0;

        margin:
            0 -12px;

        width:
            calc(
                100% + 24px
            );
    }


    .property-title {

        font-size:
            22px;
    }


    .specs-grid,
    .amenities-grid {

        grid-template-columns:
            1fr 1fr;
    }


    .price-large {

        font-size:
            24px;
    }


    .gallery-topbar {

        top:
            12px;

        right:
            12px;

        left:
            12px;
    }


    .gallery-bottom {

        right:
            12px;

        left:
            12px;

        bottom:
            12px;
    }


    .action-btn {

        height:
            48px;

        font-size:
            12px;
    }
}


@media (max-width: 380px) {

    .specs-grid,
    .amenities-grid {

        grid-template-columns:
            1fr;
    }


    .action-row {

        gap:
            7px;
    }


    .action-btn {

        font-size:
            11px;
    }

}


/* =========================================================
   DARK MODE
========================================================= */

[data-theme="dark"] .bottom-actions-fixed {

    background:
        rgba(11,22,22,.95);

    border-top-color:
        #294646;

    box-shadow:
        0 -8px 28px
        rgba(0,0,0,.35);
}


[data-theme="dark"] .property-card {

    background:
        #142525;
}

</style>


<div
    class="main-content"
    id="mainContent"
>

<?php if ($propertyData === null): ?>

    <div
        style="
            text-align:center;
            padding:70px 20px;
        "
    >

        <div
            style="font-size:58px;"
        >
            🏠
        </div>


        <h3
            style="
                color:var(--text-primary);
                margin-top:18px;
            "
        >
            ملک مورد نظر یافت نشد
        </h3>


        <p
            style="
                color:var(--text-secondary);
            "
        >
            ممکن است آگهی حذف یا از حالت انتشار خارج شده باشد.
        </p>


        <a
            href="home.php"
            style="
                display:inline-block;
                margin-top:18px;
                padding:12px 28px;
                background:var(--primary);
                color:#fff;
                border-radius:12px;
                text-decoration:none;
            "
        >
            بازگشت به خانه
        </a>

    </div>

<?php else: ?>


    <div class="property-shell">


        <!-- =====================================================
             GALLERY
        ====================================================== -->

        <div
            class="hero-gallery"
            id="galleryContainer"
        >

            <div
                class="gallery-slide"
                id="gallerySlide"
            ></div>


            <div class="gallery-topbar">

                <div
                    class="gallery-counter"
                    id="galleryCounter"
                >
                    📸 ۱ / ۱
                </div>


                <button
                    type="button"
                    class="glass-btn"
                    id="favoriteBtn"
                    onclick="toggleFavorite()"
                    aria-label="افزودن به علاقه‌مندی"
                    title="افزودن به علاقه‌مندی"
                >

                    <svg
                        width="21"
                        height="21"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
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

            </div>


            <div class="gallery-bottom">

                <div class="gallery-code">

                    کد ملک:
                    <?= htmlspecialchars(
                        $propertyData['id'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>


                <div class="gallery-actions">

                    <button
                        type="button"
                        class="gallery-nav prev"
                        onclick="changeImage(-1)"
                        aria-label="تصویر قبلی"
                    >
                        ‹
                    </button>


                    <button
                        type="button"
                        class="gallery-nav next"
                        onclick="changeImage(1)"
                        aria-label="تصویر بعدی"
                    >
                        ›
                    </button>

                </div>

            </div>

        </div>


        <div
            class="gallery-thumbs"
            id="galleryThumbs"
        ></div>


        <!-- =====================================================
             INFO
        ====================================================== -->

        <div class="info-head">

            <div class="property-code">

                ملکینو •
                <?= htmlspecialchars(
                    $propertyData['property_type']
                    ?? 'ملک',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </div>


            <h1 class="property-title">

                <?= htmlspecialchars(
                    $propertyData['title'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </h1>


            <div class="property-meta">

                <span class="meta-chip">

                    🏷️
                    <?= htmlspecialchars(
                        $propertyData['transaction_type']
                        ?? 'فروش',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </span>


                <span class="meta-chip">

                    📍
                    <?= htmlspecialchars(
                        $propertyData['neighborhood']
                        ?? $propertyData['location']
                        ?? 'موقعیت نامشخص',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </span>

                <?php if (!empty($propertyData['is_not_keyed'])): ?>
                    <span class="meta-chip key-not-turned">
                        🔑 کلید نخورده
                    </span>
                <?php endif; ?>

            </div>

        </div>


        <!-- =====================================================
             PRICE
        ====================================================== -->

        <div class="price-card">

            <div style="flex:1;">

                <div class="price-label">
                    شرایط معامله
                </div>

                <?php
                /*
                 * کنترل نمایش قیمت فقط در همین بخش:
                 * - تنظیم عمومی دیتابیس (settings.global.show_prices)
                 * - مخفی‌سازی اختصاصی آگهی price_hidden
                 *
                 * قیمت اصلی هیچ‌وقت حذف نمی‌شود؛
                 * فقط در نمایش عمومی جایگزین می‌شود.
                 *
                 * قبلاً این مقدار از یک فایل JSON قدیمی (settings/global.json)
                 * خونده می‌شد که هیچ‌وقت با تغییرات پنل ادمین آپدیت نمی‌شد.
                 */

                $globalPriceSettings = getGlobalSettings();

                $globalShowPrices =
                    !empty($globalPriceSettings['show_prices']) &&
                    empty($globalPriceSettings['hide_all_prices']);

                /*
                 * بررسی مخفی بودن قیمت همین آگهی
                 */
                $adPriceHidden = false;

                if ($isMockMode) {

                    $priceAdsFile =
                        __DIR__ . '/ads.json';

                    if (is_file($priceAdsFile)) {

                        $priceAds =
                            json_decode(
                                (string) @file_get_contents($priceAdsFile),
                                true
                            );

                        if (is_array($priceAds)) {

                            foreach ($priceAds as $priceAd) {

                                if (
                                    (string) ($priceAd['id'] ?? '') ===
                                    (string) $propertyId
                                ) {
                                    $adPriceHidden =
                                        !empty($priceAd['price_hidden']);

                                    break;
                                }
                            }
                        }
                    }

                } elseif (
                    isset($pdo) &&
                    $propertyId !== ''
                ) {

                    /*
                     * در حالت دیتابیس، اگر ستون price_hidden وجود داشته باشد
                     * مقدار آن را می‌خوانیم.
                     */
                    try {

                        $priceStmt =
                            $pdo->prepare(
                                "SELECT price_hidden FROM ads WHERE id = ? LIMIT 1"
                            );

                        $priceStmt->execute([
                            $propertyId
                        ]);

                        $priceRow =
                            $priceStmt->fetch(PDO::FETCH_ASSOC);

                        if (is_array($priceRow)) {
                            $adPriceHidden =
                                !empty($priceRow['price_hidden']);
                        }

                    } catch (Throwable $e) {
                        /*
                         * اگر ستون هنوز در دیتابیس وجود نداشته باشد،
                         * نمایش عادی قیمت ادامه پیدا می‌کند.
                         */
                        $adPriceHidden = false;
                    }
                }


                /*
                 * اگر نمایش قیمت عمومی خاموش باشد
                 * یا همین آگهی مخفی شده باشد.
                 */
                if (
                    !$globalShowPrices ||
                    $adPriceHidden
                ) {
                    echo '
                        <div
                            style="
                                font-size:18px;
                                font-weight:800;
                                color:var(--gold);
                            "
                        >
                            برای استعلام قیمت تماس بگیرید
                        </div>
                    ';

                } else {

                    $hasPrice = false;
                    $priceHtml = '';

                    /*
                     * قالب‌بندی مبلغ
                     * - بدون اعشار
                     * - با جداکننده هزارگان
                     */
                    $formatToman = static function ($value): string {
                        if ($value === null || trim((string)$value) === '') {
                            return '0';
                        }

                        $normalized = str_replace(',', '', trim((string)$value));

                        if (!is_numeric($normalized)) {
                            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                        }

                        return number_format((float)$normalized, 0, '.', ',');
                    };

                    $transactionType = trim((string)($propertyData['transaction_type'] ?? ''));

                    /*
                     * فروش: فقط قیمت فروش
                     */
                    if ($transactionType === 'فروش') {
                        $priceSell = $propertyData['price_sell'] ?? 0;
                        $priceSellNumber = (float)str_replace(',', '', (string)$priceSell);

                        if ($priceSellNumber > 0) {
                            $hasPrice = true;

                            $priceHtml .= '
                                <div
                                    style="
                                        font-size:18px;
                                        font-weight:700;
                                        color:var(--gold);
                                        line-height:1.9;
                                    "
                                >
                                    💰 قیمت فروش: ' . $formatToman($priceSell) . ' تومان
                                </div>
                            ';
                        }
                    } else {
                        /*
                         * رهن کامل
                         */
                        $fullRentEnabled = $propertyData['full_rent_enabled'] ?? false;
                        $fullRent = $propertyData['full_rent'] ?? 0;
                        $fullRentNumber = (float)str_replace(',', '', (string)$fullRent);

                        if (!empty($fullRentEnabled) && $fullRentNumber > 0) {
                            $hasPrice = true;

                            $priceHtml .= '
                                <div
                                    style="
                                        font-size:18px;
                                        font-weight:700;
                                        color:var(--gold);
                                        line-height:1.9;
                                    "
                                >
                                    🏠 رهن کامل: ' . $formatToman($fullRent) . ' تومان
                                </div>
                            ';
                        }

                        /*
                         * ودیعه
                         */
                        $deposit = $propertyData['deposit'] ?? 0;
                        $depositNumber = (float)str_replace(',', '', (string)$deposit);

                        if ($depositNumber > 0) {
                            $hasPrice = true;

                            $priceHtml .= '
                                <div
                                    style="
                                        font-size:18px;
                                        font-weight:700;
                                        color:var(--gold);
                                        line-height:1.9;
                                    "
                                >
                                    ودیعه: ' . $formatToman($deposit) . ' تومان
                                </div>
                            ';
                        }

                        /*
                         * اجاره
                         */
                        $rentMonthly = $propertyData['rent_monthly'] ?? 0;
                        $rentMonthlyNumber = (float)str_replace(',', '', (string)$rentMonthly);

                        if ($rentMonthlyNumber > 0) {
                            $hasPrice = true;

                            $priceHtml .= '
                                <div
                                    style="
                                        font-size:18px;
                                        font-weight:700;
                                        color:var(--gold);
                                        line-height:1.9;
                                    "
                                >
                                    اجاره: ' . $formatToman($rentMonthly) . ' تومان
                                </div>
                            ';
                        }
                    }

                    /*
                     * پیش‌فروش
                     */
                    if (
                        !empty($propertyData['total_price']) &&
                        $propertyData['total_price'] != '0'
                    ) {

                        $hasPrice = true;

                        $priceHtml .= '
                            <div
                                style="
                                    font-size:18px;
                                    font-weight:700;
                                    color:var(--gold);
                                    line-height:1.9;
                                "
                            >
                                📋 قیمت کل:
                                ' . $formatToman($propertyData['total_price']) . ' تومان
                            </div>
                        ';
                    }

                    if (!$hasPrice) {

                        $priceHtml = '
                            <div
                                style="
                                    font-size:18px;
                                    font-weight:700;
                                    color:var(--gold);
                                "
                            >
                                تماس بگیرید
                            </div>
                        ';
                    }

                    echo $priceHtml;
                }

                // ===== بخش جدید: محاسبه و نمایش قیمت هر متر مربع =====
                $pricePerMeterText = calculatePricePerSquareMeter($propertyData);
                if ($pricePerMeterText !== null) {
                    echo '<div style="margin-top:10px; padding-top:10px; border-top:1px solid var(--border); font-size:14px; color:var(--text-secondary);">';
                    echo '💰 قیمت هر متر مربع: <strong style="color:var(--gold); font-size:18px;">' . $pricePerMeterText . '</strong>';
                    echo '</div>';
                }
                // ===== پایان بخش جدید =====
                ?>

            </div>

            <!-- قیمت شرایط (قابل مذاکره / مقطوع) به‌طور کامل حذف شد -->

        </div>


        <!-- =====================================================
             SPECS
        ====================================================== -->

        <section class="section-card">

            <h2 class="section-title">
                مشخصات ملک
            </h2>


            <div
                class="specs-grid"
                id="specsGrid"
            ></div>

        </section>


        <!-- =====================================================
             AMENITIES
        ====================================================== -->

        <section class="section-card">

            <h2 class="section-title">
                امکانات ملک
            </h2>


            <div
                class="amenities-grid"
                id="amenitiesGrid"
            ></div>

        </section>


        <!-- =====================================================
             DESCRIPTION
        ====================================================== -->

        <section class="section-card">

            <h2 class="section-title">
                توضیحات
            </h2>


            <p
                class="description"
                id="propertyDescription"
            >

                <?= htmlspecialchars(
                    $propertyData['description']
                    ?? 'توضیحی برای این ملک ثبت نشده است.',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </p>

        </section>


    </div>

<?php endif; ?>

</div>


<!-- =========================================================
     BOTTOM CONTACT BAR
========================================================== -->

<?php if ($propertyData !== null): ?>

    <?php
        $consultantPhoneHref = trim((string)($consultantPhone ?? ''));
        $consultantTelegramHref = trim((string)($consultantTelegram ?? ''));
    ?>

    <div class="bottom-actions-fixed">

        <div class="consultant-bar">

            <div class="consultant-info">

                <div class="consultant-avatar">
                    👤
                </div>

                <div class="consultant-text">

                    <strong>
                        <?= htmlspecialchars(
                            $consultantName,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </strong>

                    <span>مشاور ملکینو</span>

                </div>

            </div>


            <div class="consultant-actions">

                <?php if ($consultantTelegramHref !== ''): ?>
                    <a
                        href="<?= htmlspecialchars($consultantTelegramHref, ENT_QUOTES, 'UTF-8') ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="consultant-btn telegram"
                    >
                        <span>💬</span>
                        پیام به مشاور
                    </a>
                <?php else: ?>
                    <a
                        href="javascript:void(0)"
                        class="consultant-btn telegram disabled"
                        onclick="showToast('لینک تلگرام مشاور تنظیم نشده است.')"
                    >
                        <span>💬</span>
                        پیام به مشاور
                    </a>
                <?php endif; ?>


                <?php if ($consultantPhoneHref !== ''): ?>
                    <a
                        href="tel:<?= htmlspecialchars($consultantPhoneHref, ENT_QUOTES, 'UTF-8') ?>"
                        class="consultant-btn call"
                    >
                        <span>📞</span>
                        تماس با مشاور
                    </a>
                <?php else: ?>
                    <a
                        href="javascript:void(0)"
                        class="consultant-btn call disabled"
                        onclick="showToast('شماره تماس مشاور تنظیم نشده است.')"
                    >
                        <span>📞</span>
                        تماس با مشاور
                    </a>
                <?php endif; ?>

            </div>

        </div>

    </div>


<?php endif; ?>


<script>

/* =========================================================
   PROPERTY DATA
========================================================= */

const propertyData =
    <?= json_encode(
        $propertyData,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>;


/* =========================================================
   GALLERY STATE
========================================================= */

let currentImageIndex = 0;

let galleryImages = [];

let galleryStartX = null;

let galleryStartY = null;


/* =========================================================
   ESCAPE HTML
========================================================= */

function escapeHtml(value) {

    return String(
        value ?? ''
    ).replace(
        /[&<>"']/g,
        function (ch) {

            return {

                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'

            }[ch];

        }
    );

}


/* =========================================================
   NORMALIZE IMAGE PATH
========================================================= */

function normalizeImagePath(path) {

    if (!path) {
        return '';
    }


    path =
        String(path).trim();


    if (!path) {
        return '';
    }


    /*
     * URL کامل
     */

    if (
        /^https?:\/\//i.test(path)
    ) {

        return path;

    }


    /*
     * Data URI
     */

    if (
        path.indexOf(
            'data:image/'
        ) === 0
    ) {

        return path;

    }


    return path;

}


/* =========================================================
   INIT GALLERY
========================================================= */

function initGallery() {

    const slide =
        document.getElementById(
            'gallerySlide'
        );


    const thumbs =
        document.getElementById(
            'galleryThumbs'
        );


    if (!slide || !thumbs) {
        return;
    }


    slide.innerHTML = '';

    thumbs.innerHTML = '';


    /*
     * تصاویر نرمال شده
     */

    galleryImages =
        Array.isArray(
            propertyData?.images
        )
            ? propertyData.images
                .map(
                    normalizeImagePath
                )
                .filter(Boolean)
            : [];


    /*
     * حذف تکراری‌ها
     */

    galleryImages =
        galleryImages.filter(
            function (value, index, self) {

                return (
                    self.indexOf(value) ===
                    index
                );

            }
        );


    currentImageIndex = 0;


    /*
     * بدون تصویر
     */

    if (
        galleryImages.length === 0
    ) {

        slide.innerHTML = `

            <div class="no-image">

                <div
                    style="
                        display:flex;
                        flex-direction:column;
                        align-items:center;
                        gap:10px;
                        color:rgba(255,255,255,.55);
                    "
                >

                    <span
                        style="
                            font-size:45px;
                        "
                    >
                        📷
                    </span>

                    <span>
                        تصویر برای این ملک منتشر نشده است
                    </span>

                </div>

            </div>

        `;


        thumbs.style.display =
            'none';


        updateGalleryCounter();


        return;
    }


    /*
     * ساخت تصاویر
     */

    galleryImages.forEach(
        function (src, index) {

            const wrapper =
                document.createElement(
                    'div'
                );


            wrapper.style.cssText =
                `
                    flex:0 0 100%;
                    width:100%;
                    height:100%;
                    position:relative;
                    background:#111827;
                `;


            const img =
                document.createElement(
                    'img'
                );


            img.src =
                src;


            img.alt =
                `تصویر ملک ${index + 1}`;


            img.loading =
                index === 0
                    ? 'eager'
                    : 'lazy';


            img.decoding =
                'async';


            img.draggable =
                false;


            img.onerror =
                function () {

                    wrapper.innerHTML = `

                        <div
                            class="no-image"
                            style="
                                width:100%;
                                height:100%;
                            "
                        >
                            📷
                            تصویر در دسترس نیست
                        </div>

                    `;

                };


            wrapper.appendChild(
                img
            );


            slide.appendChild(
                wrapper
            );


            /*
             * thumbnail
             */

            const thumb =
                document.createElement(
                    'button'
                );


            thumb.type =
                'button';


            thumb.className =
                'gallery-thumb'
                +
                (
                    index === 0
                        ? ' active'
                        : ''
                );


            thumb.setAttribute(
                'aria-label',
                `تصویر ${index + 1}`
            );


            const thumbImg =
                document.createElement(
                    'img'
                );


            thumbImg.src =
                src;


            thumbImg.alt =
                `تصویر کوچک ${index + 1}`;


            thumbImg.loading =
                'lazy';


            thumb.appendChild(
                thumbImg
            );


            thumb.addEventListener(
                'click',
                function () {

                    currentImageIndex =
                        index;


                    updateGalleryPosition();

                    updateGalleryCounter();

                    centerActiveThumbnail();

                }
            );


            thumbs.appendChild(
                thumb
            );

        }
    );


    thumbs.style.display =
        galleryImages.length > 1
            ? 'flex'
            : 'none';


    updateGalleryPosition();

    updateGalleryCounter();

}


/* =========================================================
   UPDATE GALLERY POSITION
========================================================= */

function updateGalleryPosition() {

    const slide =
        document.getElementById(
            'gallerySlide'
        );


    if (!slide) {
        return;
    }


    slide.style.transform =
        `translateX(-${currentImageIndex * 100}%)`;


    document
        .querySelectorAll(
            '.gallery-thumb'
        )
        .forEach(
            function (el, index) {

                el.classList.toggle(
                    'active',
                    index === currentImageIndex
                );

            }
        );

}


/* =========================================================
   CENTER ACTIVE THUMBNAIL
========================================================= */

function centerActiveThumbnail() {

    const active =
        document.querySelector(
            '.gallery-thumb.active'
        );


    if (!active) {
        return;
    }


    active.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest',
        inline: 'center'
    });

}


/* =========================================================
   COUNTER
========================================================= */

function updateGalleryCounter() {

    const counter =
        document.getElementById(
            'galleryCounter'
        );


    if (!counter) {
        return;
    }


    const total =
        galleryImages.length ||
        1;


    const current =
        galleryImages.length
            ? currentImageIndex + 1
            : 1;


    counter.innerText =
        `📸 ${current} / ${total}`;

}


/* =========================================================
   CHANGE IMAGE
========================================================= */

function changeImage(direction) {

    if (
        galleryImages.length <= 1
    ) {
        return;
    }


    currentImageIndex =
        (
            currentImageIndex +
            direction +
            galleryImages.length
        ) %
        galleryImages.length;


    updateGalleryPosition();

    updateGalleryCounter();

    centerActiveThumbnail();

}


/* =========================================================
   GALLERY SWIPE
========================================================= */

function initGallerySwipe() {

    const gallery =
        document.getElementById(
            'galleryContainer'
        );


    if (!gallery) {
        return;
    }


    gallery.addEventListener(
        'touchstart',
        function (event) {

            if (
                !event.touches ||
                event.touches.length !== 1
            ) {
                return;
            }


            galleryStartX =
                event.touches[0].clientX;


            galleryStartY =
                event.touches[0].clientY;

        },
        {
            passive: true
        }
    );


    gallery.addEventListener(
        'touchend',
        function (event) {

            if (
                galleryStartX === null ||
                galleryStartY === null
            ) {
                return;
            }


            const endX =
                event.changedTouches[0].clientX;


            const endY =
                event.changedTouches[0].clientY;


            const diffX =
                endX -
                galleryStartX;


            const diffY =
                endY -
                galleryStartY;


            galleryStartX = null;

            galleryStartY = null;


            if (
                Math.abs(diffX) < 45 ||
                Math.abs(diffX) <
                Math.abs(diffY)
            ) {
                return;
            }


            /*
             * در گالری:
             * swipe left = next
             * swipe right = previous
             */

            if (
                diffX < 0
            ) {

                changeImage(1);

            } else {

                changeImage(-1);

            }

        },
        {
            passive: true
        }
    );

}


/* =========================================================
   SPECS
========================================================= */

function renderSpecs() {

    const container =
        document.getElementById(
            'specsGrid'
        );


    if (!container) {
        return;
    }


    const specs =
        propertyData?.specs &&
        typeof propertyData.specs === 'object'
            ? propertyData.specs
            : {};


    const entries =
        Object.entries(
            specs
        ).filter(
            function ([key, value]) {

                return (
                    value !== '' &&
                    value !== null &&
                    value !== undefined &&
                    value !== '0' &&
                    value !== 0
                );

            }
        );


    if (
        entries.length === 0
    ) {

        container.innerHTML = `

            <div
                class="empty-box"
                style="
                    grid-column:1/-1;
                "
            >
                مشخصات اختصاصی برای این ملک ثبت نشده است.
            </div>

        `;


        return;
    }


    container.innerHTML =
        entries
            .map(
                function ([key, value]) {

                    return `

                        <div class="specs-item">

                            <span class="specs-label">

                                ${escapeHtml(key)}

                            </span>


                            <span class="specs-value">

                                ${escapeHtml(value)}

                            </span>

                        </div>

                    `;

                }
            )
            .join('');

}


/* =========================================================
   AMENITIES
========================================================= */

function renderAmenities() {
    const container = document.getElementById('amenitiesGrid');
    if (!container) return;

    const amenities = Array.isArray(propertyData?.amenities) ? propertyData.amenities : [];
    const requiredThree = ['آسانسور', 'پارکینگ', 'انباری'];
    const propertyType = propertyData?.property_type || '';

    let items = [];

    if (propertyType === 'آپارتمان') {
        // Apartment: always show the three core amenities
        requiredThree.forEach(function (name) {
            const exists = amenities.indexOf(name) !== -1;
            items.push({
                name: name,
                exists: exists,
                forceCross: false
            });
        });

        // Add any other amenities (not in the core three)
        amenities.forEach(function (name) {
            if (requiredThree.indexOf(name) === -1) {
                items.push({
                    name: name,
                    exists: true,
                    forceCross: false
                });
            }
        });
    } else {
        // Non-apartment: show core amenities only if they exist, but mark them with a cross
        amenities.forEach(function (name) {
            const forceCross = requiredThree.indexOf(name) !== -1;
            items.push({
                name: name,
                exists: true,
                forceCross: forceCross
            });
        });
    }

    if (items.length === 0) {
        container.innerHTML = `
            <div class="empty-box" style="grid-column:1/-1;">
                امکاناتی برای این ملک ثبت نشده است.
            </div>
        `;
        return;
    }

    container.innerHTML = items.map(function (item) {
        // Show ✓ only if exists and not forced to cross
        const checkMark = (item.exists && !item.forceCross) ? '✓' : '✕';
        const extraClass = (item.exists && !item.forceCross) ? '' : ' cross';
        return `
            <div class="amenity-item">
                <span class="amenity-check${extraClass}">
                    ${checkMark}
                </span>
                <span class="amenity-name">
                    ${escapeHtml(item.name)}
                </span>
            </div>
        `;
    }).join('');
}


/* =========================================================
   FAVORITES — DATABASE
========================================================= */

function getFavoriteTelegramId() {
    try {
        return String(localStorage.getItem('melkino_telegram_id') || sessionStorage.getItem('reg_telegram_id') || '');
    } catch (e) { return ''; }
}

async function requestFavorite(action, propertyId) {
    const telegramId = getFavoriteTelegramId();
    const response = await fetch(
        'favorites.php?action=' + encodeURIComponent(action) +
        '&telegram_id=' + encodeURIComponent(telegramId),
        {
            method: action === 'toggle' ? 'POST' : 'GET',
            headers: action === 'toggle' ? {'Content-Type':'application/x-www-form-urlencoded'} : {},
            body: action === 'toggle' ? 'ad_id=' + encodeURIComponent(propertyId) : undefined,
            cache: 'no-store'
        }
    );
    const data = await response.json();
    if (!data.success) throw new Error(data.message || 'عملیات علاقه‌مندی انجام نشد.');
    return data;
}

async function toggleFavorite() {
    if (!propertyData) return;
    const button = document.getElementById('favoriteBtn');
    if (!button) return;
    const propertyId = String(propertyData.id || '').trim();
    if (!propertyId) { showToast('کد ملک معتبر نیست.'); return; }

    button.disabled = true;
    try {
        const data = await requestFavorite('toggle', propertyId);
        button.classList.toggle('active', !!data.favorited);
        button.setAttribute('aria-label', data.favorited ? 'حذف از علاقه‌مندی' : 'افزودن به علاقه‌مندی');
        button.title = data.favorited ? 'حذف از علاقه‌مندی' : 'افزودن به علاقه‌مندی';
        showToast(data.favorited ? 'ملک به علاقه‌مندی‌ها اضافه شد ❤️' : 'ملک از علاقه‌مندی‌ها حذف شد.');
        window.dispatchEvent(new CustomEvent('melkino:favorites-changed', {detail:{propertyId, favorited:!!data.favorited}}));
    } catch (error) {
        console.error('Favorite error:', error);
        showToast(error.message || 'خطا در ذخیره علاقه‌مندی.');
    } finally {
        button.disabled = false;
    }
}

async function checkFavoriteStatus() {
    if (!propertyData) return;
    const button = document.getElementById('favoriteBtn');
    if (!button) return;
    const propertyId = String(propertyData.id || '').trim();
    if (!propertyId) return;
    try {
        const data = await requestFavorite('list', '');
        const exists = Array.isArray(data.favorites) && data.favorites.some(function(ad){ return String(ad.id) === propertyId; });
        button.classList.toggle('active', exists);
        button.setAttribute('aria-label', exists ? 'حذف از علاقه‌مندی' : 'افزودن به علاقه‌مندی');
        button.title = exists ? 'حذف از علاقه‌مندی' : 'افزودن به علاقه‌مندی';
    } catch (error) {
        console.warn('Could not load favorite state:', error);
    }
}

/* =========================================================
   TOAST
========================================================= */

function showToast(message) {

    const oldToast =
        document.getElementById(
            'melkinoDetailToast'
        );


    if (oldToast) {
        oldToast.remove();
    }


    const toast =
        document.createElement(
            'div'
        );


    toast.id =
        'melkinoDetailToast';


    toast.textContent =
        message;


    toast.style.cssText = `

        position:fixed;

        left:50%;

        bottom:
            calc(
                112px +
                env(safe-area-inset-bottom)
            );

        transform:
            translateX(-50%);

        z-index:3000;

        max-width:
            calc(100vw - 36px);

        padding:
            11px 17px;

        border-radius:
            999px;

        background:
            rgba(3,29,29,.95);

        color:#fff;

        border:
            1px solid
            rgba(212,175,55,.22);

        box-shadow:
            0 12px 35px
            rgba(0,0,0,.25);

        backdrop-filter:
            blur(12px);

        font-family:
            "Vazirmatn",
            sans-serif;

        font-size:
            11px;

        font-weight:
            700;

        white-space:
            nowrap;

    `;


    document.body.appendChild(
        toast
    );


    setTimeout(
        function () {

            toast.style.opacity =
                '0';

            toast.style.transition =
                'opacity .2s ease';


            setTimeout(
                function () {

                    toast.remove();

                },
                220
            );

        },
        1800
    );

}


/* =========================================================
   CALL CONSULTANT
========================================================= */

function callConsultant() {

    const phone =
        String(
            propertyData?.consultant?.phone ||
            ''
        ).trim();

    if (phone) {
        window.location.href = 'tel:' + phone;
        return;
    }

    showToast('شماره تماس مشاور تنظیم نشده است.');
}


/* =========================================================
   CHAT CONSULTANT
========================================================= */

function chatWithConsultant() {

    const telegram =
        String(
            propertyData?.consultant?.telegram ||
            ''
        ).trim();

    if (telegram) {
        window.open(telegram, '_blank', 'noopener,noreferrer');
        return;
    }

    showToast('لینک تلگرام مشاور تنظیم نشده است.');
}


/* =========================================================
   ESC / KEYBOARD GALLERY
========================================================= */

document.addEventListener(
    'keydown',
    function (event) {

        if (
            event.key === 'ArrowLeft'
        ) {

            changeImage(1);

        }


        if (
            event.key === 'ArrowRight'
        ) {

            changeImage(-1);

        }

    }
);


/* =========================================================
   INITIALIZE
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function () {

        if (!propertyData) {
            return;
        }


        initGallery();

        initGallerySwipe();

        renderSpecs();

        renderAmenities();

        checkFavoriteStatus();


        if (
            typeof updateBadge ===
            'function'
        ) {

            updateBadge();

        }

    }
);

</script>


<?php
require_once __DIR__ . '/footer.php';
?>