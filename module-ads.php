<?php
// =========================================================
// ماژول آگهی‌ها - module-ads.php
// =========================================================

/* =========================================================
   توابع کمکی آگهی‌ها (قبلاً در admin-panel.php بودند)
   =========================================================

   توجه: در حال حاضر این ماژول هیچ‌جا include نمی‌شود و همه‌ی
   توابع آن عیناً داخل admin-panel.php هم وجود دارند. اگر روزی
   هر دو با هم لود شوند، خطای مرگبار «cannot redeclare function»
   رخ می‌دهد. این محافظ جلوی آن را می‌گیرد (در فایلِ include شده،
   دستور return در سطح بالا ادامه‌ی لود شدن فایل را متوقف می‌کند).
   راه‌حل درست: توابع را از admin-panel.php حذف و این فایل را
   require_once کنید.
   ========================================================= */

if (function_exists('dbCleanNumber')) {
    return;
}

function dbCleanNumber($value): ?float {
    if ($value === null || $value === '') return null;
    $v = str_replace([',', '٬', ' ', 'تومان', 'ریال'], '', (string)$value);
    return is_numeric($v) ? (float)$v : null;
}

function normalizeAdminJsonArray($value) {
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function normalizeAdminJsonObject($value) {
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function getStatusLabel($status) {
    $labels = [
        'pending' => 'در انتظار',
        'published' => 'منتشر شده',
        'sold' => 'فروخته شده',
        'suspended' => 'معلق',
        'rejected' => 'رد شده'
    ];
    return $labels[$status] ?? $status;
}

function getStatusClass($status) {
    $classes = [
        'pending' => 'status-pending',
        'published' => 'status-published',
        'sold' => 'status-sold',
        'suspended' => 'status-suspended',
        'rejected' => 'status-rejected'
    ];
    return $classes[$status] ?? 'status-pending';
}

function formatAdminNumber($value): string {
    if ($value === null || $value === '') return '';
    $s = str_replace(['٬', '،', ',', ' '], '', (string)$value);
    if ($s === '' || !is_numeric($s)) return (string)$value;
    $n = (float)$s;
    if (abs($n - round($n)) < 0.000001) {
        return number_format((int)round($n), 0, '.', ',');
    }
    return rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');
}

function getDisplayPrice($ad) {
    $tx = trim((string)($ad['transaction_type'] ?? ''));
    if ($tx === 'فروش') {
        $value = $ad['price_sell'] ?? null;
        if ($value !== null && (float)$value > 0) {
            return '💰 فروش: ' . formatAdminNumber($value) . ' تومان';
        }
        return '';
    }
    if ($tx === 'رهن کامل') {
        $value = ($ad['full_rent'] ?? null) ?: ($ad['deposit'] ?? null);
        if ($value !== null && (float)$value > 0) {
            return '🏠 رهن کامل: ' . formatAdminNumber($value) . ' تومان';
        }
        return '';
    }
    if ($tx === 'رهن و اجاره' || $tx === 'اجاره') {
        $parts = [];
        $deposit = $ad['deposit'] ?? null;
        $rent = $ad['rent_monthly'] ?? null;
        if ($deposit !== null && (float)$deposit > 0) $parts[] = 'ودیعه: ' . formatAdminNumber($deposit) . ' تومان';
        if ($rent !== null && (float)$rent > 0) $parts[] = 'اجاره: ' . formatAdminNumber($rent) . ' تومان';
        return $parts ? '🏠 ' . implode(' | ', $parts) : '';
    }
    if ($tx === 'پیش فروش') {
        $value = $ad['total_price'] ?? null;
        if ($value !== null && (float)$value > 0) {
            return '📋 قیمت کل: ' . formatAdminNumber($value) . ' تومان';
        }
    }
    $value = $ad['price_sell'] ?? $ad['total_price'] ?? null;
    return ($value !== null && (float)$value > 0) ? formatAdminNumber($value) . ' تومان' : '';
}

function getAmenitiesArray($ad) {
    if (is_array($ad['amenities'])) return $ad['amenities'];
    if (is_string($ad['amenities'])) {
        $decoded = json_decode($ad['amenities'], true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

/* =========================================================
   داده‌های نمونه (فقط برای حالت MOCK)
   ========================================================= */

function getMockAds() {
    return [
        [
            'id' => 1,
            'title' => 'آپارتمان لوکس ۱۲۰ متری در نیاوران',
            'transaction_type' => 'فروش',
            'property_type' => 'آپارتمان',
            'price_sell' => '۳,۸۰۰,۰۰۰,۰۰۰',
            'price_condition' => 'negotiable',
            'deposit' => '',
            'rent_monthly' => '',
            'full_rent_enabled' => 0,
            'full_rent' => '',
            'total_price' => '',
            'down_payment' => '',
            'payment_terms' => '',
            'last_name' => 'رضایی',
            'phone' => '۰۹۱۲۳۴۵۶۷۸۹',
            'address' => 'خیابان نیاوران، پلاک ۱۲',
            'location' => 'نیاوران، تهران',
            'description' => 'دوبلکس با نمای شمالی، پارکینگ و انباری، نزدیک به مترو',
            'status' => 'published',
            'property_details' => '{"area":"۱۲۰","floor":"۵","unit":"۳","total_units":"۱۲","rooms":"۳","year":"۱۴۰۲","flooring":"پارکت","cabinet":"ام دی اف","cooling":"اسپیلت","heating":"شوفاژ"}',
            'images' => '["uploads/sample1.jpg"]',
            'selected_images' => '["uploads/sample1.jpg"]',
            'publish_photos' => 'yes',
            'amenities' => ['آسانسور', 'پارکینگ', 'انباری'],
            'created_at' => date('Y-m-d H:i:s')
        ],
        [
            'id' => 2,
            'title' => 'ویلای ۴۰۰ متری در چالوس',
            'transaction_type' => 'فروش',
            'property_type' => 'ویلا',
            'price_sell' => '۱۲,۵۰۰,۰۰۰,۰۰۰',
            'price_condition' => 'fixed',
            'deposit' => '',
            'rent_monthly' => '',
            'full_rent_enabled' => 0,
            'full_rent' => '',
            'total_price' => '',
            'down_payment' => '',
            'payment_terms' => '',
            'last_name' => 'کریمی',
            'phone' => '۰۹۱۲۳۴۵۶۷۸۰',
            'address' => 'چالوس، خیابان دریا، کوچه ۵',
            'location' => 'چالوس، مازندران',
            'description' => 'استخر اختصاصی، باغچه و منظره دریا، سند تک‌برگ',
            'status' => 'published',
            'property_details' => '{"land_area":"۴۰۰","built_area":"۲۵۰","rooms":"۴","year":"۱۴۰۱","flooring":"سنگ","cabinet":"چوبی","cooling":"اسپیلت","heating":"پکیج"}',
            'images' => '["uploads/sample2.jpg"]',
            'selected_images' => '["uploads/sample2.jpg"]',
            'publish_photos' => 'yes',
            'amenities' => ['استخر', 'باغچه', 'سند تک‌برگ'],
            'created_at' => date('Y-m-d H:i:s')
        ]
    ];
}

/* =========================================================
   نرمال‌سازی داده‌های آگهی
   ========================================================= */

function normalizeAdsData($ads) {
    if (!is_array($ads)) return [];

    $propertyFieldMap = [
        'آپارتمان' => ['area', 'floor', 'unit', 'total_units', 'rooms', 'year', 'flooring', 'cabinet', 'cooling', 'heating'],
        'ویلا' => ['land_area', 'area', 'rooms', 'year', 'flooring', 'cabinet', 'cooling', 'heating'],
        'زمین' => ['land_area', 'land_usage', 'land_type', 'land_width', 'land_length', 'land_front_width', 'land_blocks', 'land_direction', 'land_shape', 'land_deed_status', 'land_deed_type', 'land_division_status', 'land_setback_status', 'land_ownership'],
        'باغ' => ['garden_area', 'tree_count', 'tree_types', 'tree_age', 'irrigation_type', 'water_source', 'water_share', 'has_well', 'has_pond', 'has_building', 'building_area', 'document_type'],
        'اداری' => ['office_area', 'office_floor', 'office_units_per_floor', 'office_rooms', 'office_year', 'office_condition', 'office_orientation', 'office_usage'],
        'تجاری' => ['area', 'front', 'flooring', 'wall', 'cabinet', 'cooling', 'heating', 'balcony', 'basement', 'balcony_area', 'basement_area', 'location_type_1', 'location_type_2', 'jobs']
    ];

    $amenityKeys = [
        'آپارتمان' => ['amenities_apt', 'amenities'],
        'ویلا' => ['amenities_villa', 'amenities'],
        'زمین' => ['land_amenities', 'amenities'],
        'باغ' => ['garden_amenities', 'amenities'],
        'اداری' => ['office_amenities', 'amenities'],
        'تجاری' => ['amenities_comm', 'amenities'],
    ];

    foreach ($ads as &$ad) {
        $ad['id'] = $ad['id'] ?? $ad['ad_id'] ?? uniqid('AD-');
        $ad['ad_id'] = $ad['ad_id'] ?? $ad['id'];
        $ad['transaction_type'] = $ad['transaction_type'] ?? $ad['transactionType'] ?? '';
        $ad['property_type'] = $ad['property_type'] ?? $ad['propertyType'] ?? '';
        $ad['gender'] = $ad['gender'] ?? '';
        $ad['last_name'] = $ad['last_name'] ?? '';
        $ad['phone'] = $ad['phone'] ?? '';
        $ad['status'] = $ad['status'] ?? 'pending';
        $ad['price_sell'] = $ad['price_sell'] ?? '';
        $ad['price_condition'] = $ad['price_condition'] ?? '';
        $ad['deposit'] = $ad['deposit'] ?? '';
        $ad['rent_monthly'] = $ad['rent_monthly'] ?? '';
        $ad['full_rent_enabled'] = $ad['full_rent_enabled'] ?? 0;
        $ad['full_rent'] = $ad['full_rent'] ?? '';
        $ad['total_price'] = $ad['total_price'] ?? '';
        $ad['down_payment'] = $ad['down_payment'] ?? '';
        $ad['payment_terms'] = $ad['payment_terms'] ?? '';
        $ad['price_hidden'] = filter_var($ad['price_hidden'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $ad['location'] = $ad['location'] ?? '';
        $ad['address'] = $ad['address'] ?? '';
        $ad['location_received'] = $ad['location_received'] ?? '0';
        $ad['description'] = $ad['description'] ?? '';
        $ad['publish_photos'] = $ad['publish_photos'] ?? 'yes';
        $ad['created_at'] = $ad['created_at'] ?? date('Y-m-d H:i:s');
        $ad['images'] = normalizeAdminJsonArray($ad['images'] ?? []);
        $ad['selected_images'] = normalizeAdminJsonArray($ad['selected_images'] ?? ($ad['selectedImages'] ?? []));

        $type = $ad['property_type'];
        $amenities = [];
        foreach (($amenityKeys[$type] ?? ['amenities']) as $key) {
            if (!array_key_exists($key, $ad)) continue;
            $candidate = normalizeAdminJsonArray($ad[$key]);
            if ($candidate) { $amenities = $candidate; break; }
        }
        if (!$amenities && isset($ad['amenities']) && is_string($ad['amenities'])) {
            $decoded = json_decode($ad['amenities'], true);
            if (is_array($decoded)) $amenities = $decoded;
        }
        $ad['amenities'] = array_values(array_unique(array_filter($amenities, static fn($v) => $v !== '')));

        $details = normalizeAdminJsonObject($ad['property_details'] ?? []);
        foreach (($propertyFieldMap[$type] ?? []) as $field) {
            if (!array_key_exists($field, $ad)) continue;
            $value = $ad[$field];
            if ($value !== '' && $value !== null && $value !== '0' && $value !== 0) {
                $details[$field] = $value;
            }
        }

        $aliases = [
            'آپارتمان' => ['area' => 'area_apt', 'rooms' => 'rooms_apt', 'year' => 'year_apt', 'flooring' => 'flooring_apt', 'cabinet' => 'cabinet_apt', 'cooling' => 'cooling_apt', 'heating' => 'heating_apt'],
            'ویلا' => ['land_area' => 'land_villa', 'area' => 'built_villa', 'rooms' => 'rooms_villa', 'year' => 'year_villa', 'flooring' => 'flooring_villa', 'cabinet' => 'cabinet_villa', 'cooling' => 'cooling_villa', 'heating' => 'heating_villa'],
            'تجاری' => ['area' => 'area_comm', 'front' => 'front_comm', 'flooring' => 'floor_comm', 'wall' => 'wall_comm', 'cabinet' => 'cabinet_comm', 'cooling' => 'cooling_comm', 'heating' => 'heating_comm', 'balcony_area' => 'balcony_comm', 'basement_area' => 'basement_comm', 'jobs' => 'jobs_comm'],
        ];
        foreach (($aliases[$type] ?? []) as $standard => $source) {
            if ((!isset($details[$standard]) || $details[$standard] === '') && isset($ad[$standard]) && $ad[$standard] !== '') {
                $details[$standard] = $ad[$standard];
            }
            if ((!isset($details[$standard]) || $details[$standard] === '') && isset($ad[$source]) && $ad[$source] !== '') {
                $details[$standard] = $ad[$source];
            }
        }
        $ad['property_details'] = $details;
    }
    unset($ad);
    return $ads;
}

/* =========================================================
   دریافت داده‌های آگهی‌ها
   ========================================================= */

function getAdsData($pdo, $isMockMode) {
    $adsData = [];
    if ($isMockMode) {
        $jsonFile = __DIR__ . '/../ads.json';
        if (file_exists($jsonFile)) {
            $content = file_get_contents($jsonFile);
            $adsFromJson = json_decode($content, true);
            if (is_array($adsFromJson) && count($adsFromJson) > 0) {
                $adsData = normalizeAdsData($adsFromJson);
            } else {
                $adsData = normalizeAdsData(getMockAds());
            }
        } else {
            $adsData = normalizeAdsData(getMockAds());
        }
    } else {
        try {
            $stmt = $pdo->query("SELECT * FROM ads ORDER BY created_at DESC, numeric_id DESC");
            $adsFromDB = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $imageStmt = $pdo->prepare("SELECT id, ad_id, filename, sort_order, is_selected, is_primary, publish_publicly FROM images WHERE ad_id = ? ORDER BY sort_order ASC, id ASC");
            $amenityStmt = $pdo->prepare("SELECT am.name FROM ad_amenities aa INNER JOIN amenities am ON am.id = aa.amenity_id WHERE aa.ad_id = ? ORDER BY am.sort_order ASC, am.id ASC");
            foreach ($adsFromDB as &$dbAd) {
                $imageStmt->execute([(string)$dbAd['id']]);
                $imgs = $imageStmt->fetchAll(PDO::FETCH_ASSOC);
                $dbAd['images'] = array_map(static fn($img) => $img['filename'], $imgs);
                $dbAd['selected_images'] = array_values(array_map(static fn($img) => $img['filename'], array_filter($imgs, static fn($img) => (int)$img['is_selected'] === 1 && (int)$img['publish_publicly'] === 1)));
                $amenityStmt->execute([(string)$dbAd['id']]);
                $dbAd['amenities'] = array_values(array_map(static fn($r) => $r['name'], $amenityStmt->fetchAll(PDO::FETCH_ASSOC)));
            }
            unset($dbAd);
            $adsData = normalizeAdsData($adsFromDB);
        } catch (PDOException $e) {
            $adsData = normalizeAdsData(getMockAds());
        }
    }
    return $adsData;
}

/* =========================================================
   پردازش درخواست‌های POST مربوط به آگهی‌ها
   ========================================================= */

function handleAdsPostRequests($pdo) {
    // ====== ویرایش‌های کاربران: فهرست/تأیید/رد ======
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['user_revision_action'])) {
        header('Content-Type: application/json; charset=utf-8');
        $a = trim((string)$_POST['user_revision_action']);
        $rid = (int)($_POST['revision_id'] ?? 0);
        try {
            $rs = $pdo->prepare('SELECT * FROM ad_revisions WHERE id=? LIMIT 1');
            $rs->execute([$rid]);
            $rev = $rs->fetch(PDO::FETCH_ASSOC);
            if (!$rev) throw new RuntimeException('ویرایش پیدا نشد.');
            $snap = json_decode((string)$rev['snapshot'], true);
            if (!is_array($snap) || ($snap['review_status'] ?? 'pending') !== 'pending') throw new RuntimeException('این ویرایش قبلاً بررسی شده است.');
            if ($a === 'approve') {
                $x = $snap['after'] ?? [];
                $up = $pdo->prepare("UPDATE ads SET title=?,area=?,price_sell=?,deposit=?,rent_monthly=?,description=?,status='published',updated_at=NOW(),published_at=COALESCE(published_at,NOW()) WHERE id=?");
                $up->execute([$x['title'] ?? null, $x['area'] ?? null, $x['price_sell'] ?? null, $x['deposit'] ?? null, $x['rent_monthly'] ?? null, $x['description'] ?? null, $rev['ad_id']]);
                $snap['review_status'] = 'approved';
                $snap['reviewed_at'] = date('Y-m-d H:i:s');
                $u = $pdo->prepare('UPDATE ad_revisions SET snapshot=? WHERE id=?');
                $u->execute([json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rid]);
                echo json_encode(['success' => true, 'message' => 'ویرایش تأیید و آگهی دوباره منتشر شد.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($a === 'reject') {
                $snap['review_status'] = 'rejected';
                $snap['reviewed_at'] = date('Y-m-d H:i:s');
                $u = $pdo->prepare('UPDATE ad_revisions SET snapshot=? WHERE id=?');
                $u->execute([json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rid]);
                $prev = $snap['before'] ?? [];
                $pdo->prepare('UPDATE ads SET status=?,updated_at=NOW() WHERE id=?')->execute([$prev['status'] ?? 'published', $rev['ad_id']]);
                echo json_encode(['success' => true, 'message' => 'ویرایش رد شد و اطلاعات قبلی حفظ شد.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            throw new RuntimeException('عملیات نامعتبر است.');
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ====== ذخیره تغییرات آگهی‌ها در MySQL ======
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_GET['ad_db_action'] ?? $_POST['ad_db_action'] ?? '') === 'bulk_sync') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $payload = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($payload)) throw new RuntimeException('داده‌های آگهی معتبر نیستند.');
            $pdo->beginTransaction();
            $update = $pdo->prepare("UPDATE ads SET title=?, transaction_type=?, property_type=?, status=?, location=?, address=?, gender=?, last_name=?, phone=?, price_sell=?, price_condition=?, deposit=?, rent_monthly=?, full_rent=?, full_rent_enabled=?, total_price=?, down_payment=?, payment_terms=?, price_hidden=?, description=?, publish_photos=?, is_vip=?, tags=?, property_details=?, custom_fields=?, updated_at=NOW(), published_at=CASE WHEN ?='published' THEN COALESCE(published_at,NOW()) ELSE NULL END, sold_at=CASE WHEN ?='sold' THEN COALESCE(sold_at,NOW()) ELSE NULL END WHERE id=?");
            $delAmen = $pdo->prepare("DELETE FROM ad_amenities WHERE ad_id=?");
            $findAmen = $pdo->prepare("SELECT id FROM amenities WHERE name=? LIMIT 1");
            $insAmen = $pdo->prepare("INSERT INTO amenities (name, is_active) VALUES (?,1)");
            $linkAmen = $pdo->prepare("INSERT IGNORE INTO ad_amenities (ad_id, amenity_id) VALUES (?,?)");
            $resetImages = $pdo->prepare("UPDATE images SET is_selected=0, publish_publicly=0, is_primary=0 WHERE ad_id=?");
            $updImage = $pdo->prepare("UPDATE images SET is_selected=?, publish_publicly=?, is_primary=? WHERE ad_id=? AND filename=?");

            foreach ($payload as $ad) {
                if (!is_array($ad) || empty($ad['id'])) continue;
                $id = (string)$ad['id'];
                $status = (string)($ad['status'] ?? 'pending');
                $details = is_array($ad['property_details'] ?? null) ? $ad['property_details'] : [];
                $amenities = is_array($ad['amenities'] ?? null) ? $ad['amenities'] : [];
                $update->execute([
                    trim((string)($ad['title'] ?? '')),
                    $ad['transaction_type'] ?? null,
                    $ad['property_type'] ?? null,
                    $status,
                    $ad['location'] ?? null,
                    $ad['address'] ?? null,
                    $ad['gender'] ?? null,
                    $ad['last_name'] ?? null,
                    $ad['phone'] ?? null,
                    dbCleanNumber($ad['price_sell'] ?? null),
                    $ad['price_condition'] ?? null,
                    dbCleanNumber($ad['deposit'] ?? null),
                    dbCleanNumber($ad['rent_monthly'] ?? null),
                    dbCleanNumber($ad['full_rent'] ?? null),
                    !empty($ad['full_rent_enabled']) ? 1 : 0,
                    dbCleanNumber($ad['total_price'] ?? null),
                    dbCleanNumber($ad['down_payment'] ?? null),
                    $ad['payment_terms'] ?? null,
                    !empty($ad['price_hidden']) ? 1 : 0,
                    $ad['description'] ?? null,
                    (string)($ad['publish_photos'] ?? 'yes'),
                    !empty($ad['is_vip']) ? 1 : 0,
                    json_encode($ad['tags'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($ad['custom_fields'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $status,
                    $status,
                    $id
                ]);
                $delAmen->execute([$id]);
                foreach ($amenities as $amenity) {
                    $name = trim((string)$amenity);
                    if ($name === '') continue;
                    $findAmen->execute([$name]);
                    $amenityId = $findAmen->fetchColumn();
                    if (!$amenityId) {
                        $insAmen->execute([$name]);
                        $amenityId = $pdo->lastInsertId();
                    }
                    $linkAmen->execute([$id, (int)$amenityId]);
                }
                $resetImages->execute([$id]);
                $selected = array_map('strval', is_array($ad['selected_images'] ?? null) ? $ad['selected_images'] : []);
                $publishPhotos = (string)($ad['publish_photos'] ?? 'yes') === 'yes';
                $firstPrimary = true;
                $allImages = is_array($ad['images'] ?? null) ? $ad['images'] : [];
                foreach ($allImages as $file) {
                    $file = trim((string)$file);
                    if ($file === '') continue;
                    $chosen = $publishPhotos && in_array($file, $selected, true);
                    $updImage->execute([$chosen ? 1 : 0, $chosen ? 1 : 0, ($chosen && $firstPrimary) ? 1 : 0, $id, $file]);
                    if ($chosen) $firstPrimary = false;
                }
            }
            $pdo->commit();
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'ذخیره تغییرات انجام نشد.', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}
?>