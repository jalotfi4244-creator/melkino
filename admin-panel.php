<?php
// ==============================================
// محافظ امنیتی
// ==============================================
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    session_start();
}

// =====================================================
// محافظ پاسخ‌های AJAX / JSON
// جلوگیری از ورود Warning / Notice / HTML به JSON
// =====================================================
ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once 'config.php';

// نشست امن ادمین: timeout قابل تنظیم
// قبلاً این مقدار از یک فایل JSON قدیمی (settings/security.json) خونده می‌شد
// که با ذخیره‌ی تنظیمات امنیتی از پنل (save_security_settings.php، که در
// دیتابیس ذخیره می‌کند) هرگز آپدیت نمی‌شد؛ یعنی این سوییچ عملاً بی‌اثر بود.
$securitySettings = [];
if ($pdo instanceof PDO) {
    foreach (['lockout', 'admin_login_log', 'session_timeout', 'force_https'] as $__sk) {
        $securitySettings[$__sk] = dbSettingGet($pdo, 'security', $__sk, true);
    }
}
if (!empty($_SESSION['is_admin']) && !empty($securitySettings['session_timeout'])) {
    $timeoutSeconds = 30 * 60;
    if (!empty($_SESSION['admin_last_activity']) && (time() - (int)$_SESSION['admin_last_activity']) > $timeoutSeconds) {
        session_unset();
        session_destroy();
        header('Location: admin-login.php?timeout=1');
        exit;
    }
}
$_SESSION['admin_last_activity'] = time();

if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: admin-login.php');
    exit;
}

function adminPanelJsonResponse(array $payload, int $status = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/* ====== ویرایش‌های کاربران: فهرست/تأیید/رد ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_revision_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $a = trim((string)$_POST['user_revision_action']);
    $rid = (int)($_POST['revision_id'] ?? 0);
    try {
        $rs = $pdo->prepare('SELECT * FROM ad_revisions WHERE id=? LIMIT 1'); $rs->execute([$rid]); $rev = $rs->fetch(PDO::FETCH_ASSOC);
        if (!$rev) throw new RuntimeException('ویرایش پیدا نشد.');
        $snap = json_decode((string)$rev['snapshot'], true);
        if (!is_array($snap) || ($snap['review_status'] ?? 'pending') !== 'pending') throw new RuntimeException('این ویرایش قبلاً بررسی شده است.');
        if ($a === 'approve') {
            $x=$snap['after']??[];
            $up=$pdo->prepare("UPDATE ads SET title=?,area=?,price_sell=?,deposit=?,rent_monthly=?,description=?,status='published',updated_at=NOW(),published_at=COALESCE(published_at,NOW()) WHERE id=?");
            $up->execute([$x['title']??null,$x['area']??null,$x['price_sell']??null,$x['deposit']??null,$x['rent_monthly']??null,$x['description']??null,$rev['ad_id']]);
            $snap['review_status']='approved'; $snap['reviewed_at']=date('Y-m-d H:i:s');
            $u=$pdo->prepare('UPDATE ad_revisions SET snapshot=? WHERE id=?'); $u->execute([json_encode($snap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$rid]);
            echo json_encode(['success'=>true,'message'=>'ویرایش تأیید و آگهی دوباره منتشر شد.'],JSON_UNESCAPED_UNICODE); exit;
        }
        if ($a === 'reject') {
            $snap['review_status']='rejected'; $snap['reviewed_at']=date('Y-m-d H:i:s');
            $u=$pdo->prepare('UPDATE ad_revisions SET snapshot=? WHERE id=?'); $u->execute([json_encode($snap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$rid]);
            $prev=$snap['before']??[]; $pdo->prepare('UPDATE ads SET status=?,updated_at=NOW() WHERE id=?')->execute([$prev['status']??'published',$rev['ad_id']]);
            echo json_encode(['success'=>true,'message'=>'ویرایش رد شد و اطلاعات قبلی حفظ شد.'],JSON_UNESCAPED_UNICODE); exit;
        }
        throw new RuntimeException('عملیات نامعتبر است.');
    } catch(Throwable $e) { http_response_code(400); echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); exit; }
}

$isMockMode = defined('MOCK_MODE') && MOCK_MODE === true;


/* =========================================================
   ذخیره تغییرات آگهی‌ها در MySQL
   ========================================================= */
function dbCleanNumber($value): ?float {
    if ($value === null || $value === '') return null;
    $v = str_replace([',', '٬', ' ', 'تومان', 'ریال'], '', (string)$value);
    return is_numeric($v) ? (float)$v : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_GET['ad_db_action'] ?? $_POST['ad_db_action'] ?? '') === 'bulk_sync') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new RuntimeException('داده‌های آگهی معتبر نیستند.');
        }
        $pdo->beginTransaction();
        $update = $pdo->prepare("UPDATE ads SET title=?, transaction_type=?, property_type=?, status=?, location=?, address=?, gender=?, last_name=?, phone=?, price_sell=?, price_condition=?, deposit=?, rent_monthly=?, full_rent=?, full_rent_enabled=?, total_price=?, down_payment=?, payment_terms=?, price_hidden=?, description=?, publish_photos=?, is_vip=?, tags=?, property_details=?, custom_fields=?, is_not_keyed=?, delivery_date=?, vacancy_date=?, is_vacant=?, exchange_interested=?, exchange_with=?, visit_hours=?, is_old=?, is_renovated=?, water_share=?, well_name=?, updated_at=NOW(), published_at=CASE WHEN ?='published' THEN COALESCE(published_at,NOW()) ELSE NULL END, sold_at=CASE WHEN ?='sold' THEN COALESCE(sold_at,NOW()) ELSE NULL END WHERE id=?");
        $delAmen = $pdo->prepare("DELETE FROM ad_amenities WHERE ad_id=?");
        $findAmen = $pdo->prepare("SELECT id FROM amenities WHERE name=? LIMIT 1");
        $insAmen = $pdo->prepare("INSERT INTO amenities (name, is_active) VALUES (?,1)");
        $linkAmen = $pdo->prepare("INSERT IGNORE INTO ad_amenities (ad_id, amenity_id) VALUES (?,?)");
        $resetImages = $pdo->prepare("UPDATE images SET is_selected=0, publish_publicly=0, is_primary=0 WHERE ad_id=?");
        $updImage = $pdo->prepare("UPDATE images SET is_selected=?, publish_publicly=?, is_primary=?, sort_order=? WHERE ad_id=? AND filename=?");
        $insImage = $pdo->prepare("INSERT INTO images (ad_id, filename, storage_path, sort_order, is_selected, is_primary, publish_publicly, created_at) VALUES (?,?,?,?,?,?,?,NOW())");

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
                json_encode($ad['tags'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                json_encode($ad['custom_fields'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                !empty($ad['is_not_keyed']) ? 1 : 0,
                (!empty($ad['delivery_date']) ? (string)$ad['delivery_date'] : null),
                (!empty($ad['vacancy_date']) ? (string)$ad['vacancy_date'] : null),
                !empty($ad['is_vacant']) ? 1 : 0,
                !empty($ad['exchange_interested']) ? 1 : 0,
                (!empty($ad['exchange_with']) ? (string)$ad['exchange_with'] : null),
                (!empty($ad['visit_hours']) ? (string)$ad['visit_hours'] : null),
                !empty($ad['is_old']) ? 1 : 0,
                !empty($ad['is_renovated']) ? 1 : 0,
                (!empty($ad['water_share']) ? (string)$ad['water_share'] : null),
                (!empty($ad['well_name']) ? (string)$ad['well_name'] : null),
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
            $sortOrder = 0;
            foreach ($allImages as $file) {
                $file = trim((string)$file);
                if ($file === '') continue;
                $chosen = $publishPhotos && in_array($file, $selected, true);
                $isPrimary = ($chosen && $firstPrimary) ? 1 : 0;

                $updImage->execute([$chosen ? 1 : 0, $chosen ? 1 : 0, $isPrimary, $sortOrder, $id, $file]);

                if ($updImage->rowCount() === 0) {
                    // این عکس هنوز ردیفی در جدول images نداشت (مثلاً همین
                    // الان از پنل ادمین آپلود شده) — قبلاً این حالت به‌کلی
                    // نادیده گرفته می‌شد و عکس‌های جدید هیچ‌وقت ذخیره
                    // نمی‌شدند. حالا برایش یک ردیف تازه ساخته می‌شود.
                    $insImage->execute([$id, $file, $file, $sortOrder, $chosen ? 1 : 0, $isPrimary, $chosen ? 1 : 0]);
                }

                if ($chosen) $firstPrimary = false;
                $sortOrder++;
            }
        }
        $pdo->commit();
        adminPanelJsonResponse(['success'=>true], 200);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Melkino admin-panel bulk_sync error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        adminPanelJsonResponse([
            'success' => false,
            'message' => 'ذخیره تغییرات انجام نشد.',
            'error' => $e->getMessage()
        ], 500);
    }
    exit;
}

/* =========================================================
   مدیریت وضعیت و یادداشت پیگیری درخواست‌ها
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $requestAction = (string)($_POST['request_action'] ?? '');

    if ($requestAction === 'delete_request') {
        $trackingCode = trim((string)($_POST['tracking_code'] ?? ''));

        if ($trackingCode === '') {
            echo json_encode(['ok'=>false,'message'=>'کد پیگیری معتبر نیست.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $find = $pdo->prepare("SELECT id FROM property_requests WHERE tracking_code = ? LIMIT 1");
            $find->execute([$trackingCode]);
            $reqId = $find->fetchColumn();

            if (!$reqId) {
                echo json_encode(['ok'=>false,'message'=>'درخواست موردنظر پیدا نشد.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $pdo->beginTransaction();

            // قبلاً این پاک‌سازی با ON DELETE CASCADE در خودِ دیتابیس
            // انجام می‌شد؛ چون هاست فعلی دسترسی REFERENCES نمی‌دهد،
            // این کار باید دستی و از همینجا انجام شود.
            $pdo->prepare(
                "DELETE rmf FROM request_match_feedback rmf
                 INNER JOIN request_matches rm ON rm.id = rmf.request_match_id
                 WHERE rm.request_id = ?"
            )->execute([$reqId]);

            $pdo->prepare("DELETE FROM request_matches WHERE request_id = ?")->execute([$reqId]);
            $pdo->prepare("DELETE FROM request_amenities WHERE request_id = ?")->execute([$reqId]);
            $pdo->prepare("DELETE FROM property_requests WHERE id = ?")->execute([$reqId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok'=>false,'message'=>'حذف درخواست انجام نشد.','error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['ok'=>true,'message'=>'درخواست حذف شد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($requestAction !== 'update_request_meta') {
        echo json_encode(['ok'=>false,'message'=>'عملیات نامعتبر است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $trackingCode = trim((string)($_POST['tracking_code'] ?? ''));
    $status = trim((string)($_POST['status'] ?? 'new'));
    $followupNote = trim((string)($_POST['followup_note'] ?? ''));

    $allowedStatuses = [
        'new' => 'جدید',
        'tracking' => 'در حال پیگیری',
        'archived' => 'بایگانی',
        'closed' => 'بسته شده',
    ];

    if ($trackingCode === '' || !isset($allowedStatuses[$status])) {
        echo json_encode(['ok'=>false,'message'=>'اطلاعات درخواست معتبر نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE property_requests SET status = ?, property_details = JSON_SET(COALESCE(property_details, JSON_OBJECT()), '$.followup_note', ?), updated_at = NOW() WHERE tracking_code = ? LIMIT 1");
        $stmt->execute([$status, $followupNote, $trackingCode]);
        if ($stmt->rowCount() < 1) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false,'message'=>'درخواست موردنظر پیدا نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok'=>false,'message'=>'ذخیره‌سازی انجام نشد.','error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok'=>true,'message'=>'ذخیره شد.','status'=>$status,'status_label'=>$allowedStatuses[$status],'followup_note'=>$followupNote], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==============================================
// داده‌های نمونه (فقط برای حالت MOCK)
// ==============================================
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

// ==============================================
// بارگذاری آگهی‌ها
// ==============================================
$adsData = [];

function normalizeAdsData($ads) {
    if (!is_array($ads)) return [];

    $propertyFieldMap = [
        'آپارتمان' => [
            'area',
            'floor',
            'unit',
            'total_units',
            'rooms',
            'year',
            'flooring',
            'cabinet',
            'cooling',
            'heating'
        ],
        'ویلا' => [
            'land_area',
            'area',
            'rooms',
            'year',
            'flooring',
            'cabinet',
            'cooling',
            'heating'
        ],
        'زمین' => [
            'land_area',
            'land_usage',
            'land_type',
            'land_width',
            'land_length',
            'land_front_width',
            'land_blocks',
            'land_direction',
            'land_shape',
            'land_deed_status',
            'land_deed_type',
            'land_division_status',
            'land_setback_status',
            'land_ownership'
        ],
        'باغ' => [
            'garden_area',
            'tree_count',
            'tree_types',
            'tree_age',
            'irrigation_type',
            'water_source',
            'water_share',
            'has_well',
            'has_pond',
            'has_building',
            'building_area',
            'document_type'
        ],
        'اداری' => [
            'office_area',
            'office_floor',
            'office_units_per_floor',
            'office_rooms',
            'office_year',
            'office_condition',
            'office_orientation',
            'office_usage'
        ],
        'تجاری' => [
            'area',
            'front',
            'flooring',
            'wall',
            'cabinet',
            'cooling',
            'heating',
            'balcony',
            'basement',
            'balcony_area',
            'basement_area',
            'location_type_1',
            'location_type_2',
            'jobs'
        ]
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

        $ad['transaction_type'] =
            $ad['transaction_type'] ??
            $ad['transactionType'] ??
            '';

        $ad['property_type'] =
            $ad['property_type'] ??
            $ad['propertyType'] ??
            '';

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

        $ad['selected_images'] =
            normalizeAdminJsonArray(
                $ad['selected_images'] ??
                ($ad['selectedImages'] ?? [])
            );

        $type = $ad['property_type'];

        $amenities = [];

        foreach (($amenityKeys[$type] ?? ['amenities']) as $key) {

            if (!array_key_exists($key, $ad)) {
                continue;
            }

            $candidate =
                normalizeAdminJsonArray($ad[$key]);

            if ($candidate) {
                $amenities = $candidate;
                break;
            }
        }

        if (
            !$amenities &&
            isset($ad['amenities']) &&
            is_string($ad['amenities'])
        ) {
            $decoded =
                json_decode(
                    $ad['amenities'],
                    true
                );

            if (is_array($decoded)) {
                $amenities = $decoded;
            }
        }

        $ad['amenities'] =
            array_values(
                array_unique(
                    array_filter(
                        $amenities,
                        static fn($v) => $v !== ''
                    )
                )
            );

        $details =
            normalizeAdminJsonObject(
                $ad['property_details'] ?? []
            );

        foreach (($propertyFieldMap[$type] ?? []) as $field) {

            if (!array_key_exists($field, $ad)) {
                continue;
            }

            $value = $ad[$field];

            if (
                $value !== '' &&
                $value !== null &&
                $value !== '0' &&
                $value !== 0
            ) {
                $details[$field] = $value;
            }
        }

        $aliases = [
            'آپارتمان' => [
                'area' => 'area_apt',
                'rooms' => 'rooms_apt',
                'year' => 'year_apt',
                'flooring' => 'flooring_apt',
                'cabinet' => 'cabinet_apt',
                'cooling' => 'cooling_apt',
                'heating' => 'heating_apt'
            ],
            'ویلا' => [
                'land_area' => 'land_villa',
                'area' => 'built_villa',
                'rooms' => 'rooms_villa',
                'year' => 'year_villa',
                'flooring' => 'flooring_villa',
                'cabinet' => 'cabinet_villa',
                'cooling' => 'cooling_villa',
                'heating' => 'heating_villa'
            ],
            'تجاری' => [
                'area' => 'area_comm',
                'front' => 'front_comm',
                'flooring' => 'floor_comm',
                'wall' => 'wall_comm',
                'cabinet' => 'cabinet_comm',
                'cooling' => 'cooling_comm',
                'heating' => 'heating_comm',
                'balcony_area' => 'balcony_comm',
                'basement_area' => 'basement_comm',
                'jobs' => 'jobs_comm'
            ],
        ];

        foreach (($aliases[$type] ?? []) as $standard => $source) {

            if (
                (!isset($details[$standard]) ||
                $details[$standard] === '') &&
                isset($ad[$standard]) &&
                $ad[$standard] !== ''
            ) {
                $details[$standard] =
                    $ad[$standard];
            }

            if (
                (!isset($details[$standard]) ||
                $details[$standard] === '') &&
                isset($ad[$source]) &&
                $ad[$source] !== ''
            ) {
                $details[$standard] =
                    $ad[$source];
            }
        }

        $ad['property_details'] = $details;
    }

    unset($ad);

    return $ads;
}

function normalizeAdminJsonArray($value) {

    if (is_array($value)) {
        return $value;
    }

    if (
        !is_string($value) ||
        trim($value) === ''
    ) {
        return [];
    }

    $decoded =
        json_decode($value, true);

    return is_array($decoded)
        ? $decoded
        : [];
}

function normalizeAdminJsonObject($value) {

    if (
        is_array($value)
    ) {
        return $value;
    }

    if (
        !is_string($value) ||
        trim($value) === ''
    ) {
        return [];
    }

    $decoded =
        json_decode($value, true);

    return is_array($decoded)
        ? $decoded
        : [];
}

if ($isMockMode) {

    $jsonFile =
        __DIR__ . '/ads.json';

    if (file_exists($jsonFile)) {

        $content =
            file_get_contents(
                $jsonFile
            );

        $adsFromJson =
            json_decode(
                $content,
                true
            );

        if (
            is_array($adsFromJson) &&
            count($adsFromJson) > 0
        ) {
            $adsData =
                normalizeAdsData(
                    $adsFromJson
                );
        } else {
            $adsData =
                normalizeAdsData(
                    getMockAds()
                );
        }

    } else {

        $adsData =
            normalizeAdsData(
                getMockAds()
            );
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

        $adsData =
            normalizeAdsData(
                getMockAds()
            );
    }
}


// ==============================================
// توابع کمکی آگهی‌ها
// ==============================================

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

    return $classes[$status] ??
        'status-pending';
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

    if (
        is_array($ad['amenities'])
    ) {
        return $ad['amenities'];
    }

    if (
        is_string($ad['amenities'])
    ) {

        $decoded =
            json_decode(
                $ad['amenities'],
                true
            );

        return is_array($decoded)
            ? $decoded
            : [];
    }

    return [];
}


// ==============================================
// درخواست‌های ملک
// ==============================================

$requestsData = [];
if (!$isMockMode) {
    try {
        $stmt = $pdo->query("SELECT * FROM property_requests ORDER BY created_at DESC, id DESC");
        $requestsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matchStmt = $pdo->query("SELECT request_id, ad_id, match_percent, matched_transaction, matched_property_type, location_score, area_score, budget_score, amenities_score, is_notified FROM request_matches ORDER BY request_id ASC, match_percent DESC, id ASC");
        $matchesByRequest = [];
        foreach ($matchStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $rid = (string)$m['request_id'];
            $matchesByRequest[$rid][] = [
                'ad_id' => (string)$m['ad_id'],
                'match_percent' => (int)$m['match_percent'],
                'matched_transaction' => (int)$m['matched_transaction'],
                'matched_property_type' => (int)$m['matched_property_type'],
                'location_score' => (float)$m['location_score'],
                'area_score' => (float)$m['area_score'],
                'budget_score' => (float)$m['budget_score'],
                'amenities_score' => (float)$m['amenities_score'],
                'is_notified' => (int)$m['is_notified'],
            ];
        }

        $reqAmenStmt = $pdo->query("SELECT ra.request_id, am.name FROM request_amenities ra INNER JOIN amenities am ON am.id = ra.amenity_id ORDER BY ra.request_id ASC, am.sort_order ASC, am.id ASC");
        $amenitiesByRequest = [];
        foreach ($reqAmenStmt->fetchAll(PDO::FETCH_ASSOC) as $ra) {
            $rid = (string)$ra['request_id'];
            $amenitiesByRequest[$rid][] = (string)$ra['name'];
        }

        foreach ($requestsData as &$req) {
            $rid = (string)$req['id'];
            $details = [];
            if (!empty($req['property_details'])) {
                $decoded = json_decode((string)$req['property_details'], true);
                if (is_array($decoded)) $details = $decoded;
            }
            $req['followup_note'] = (string)($details['followup_note'] ?? '');
            $req['matches'] = $matchesByRequest[$rid] ?? [];
            $req['amenities'] = $amenitiesByRequest[$rid] ?? [];

            // ترکیب‌های چندفایلی که به کاربر پیشنهاد شده (my-request-matches.php آن‌ها
            // را در ستون additional ذخیره می‌کند) — قبلاً در پنل ادمین نمایش داده نمی‌شدند.
            $additionalData = [];
            if (!empty($req['additional'])) {
                $decodedAdditional = json_decode((string)$req['additional'], true);
                if (is_array($decodedAdditional)) $additionalData = $decodedAdditional;
            }
            $req['combinations'] = is_array($additionalData['combinations'] ?? null) ? $additionalData['combinations'] : [];

            unset($req['additional'], $req['property_details']);
        }
        unset($req);
    } catch (Throwable $e) {
        $requestsData = [];
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover"
    >

    <title>
        ملکینو - پنل مدیریت
    </title>

    <link
        href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css"
        rel="stylesheet"
        type="text/css"
    />

    <link
        rel="stylesheet"
        href="style.css"
    >

<style>

.admin-body {
    background: var(--bg);
}

.main-content {
    flex: 1;
    overflow-y: auto;
    background: var(--bg);
    padding: 0 var(--space-3) var(--space-3);
    display: flex;
    flex-direction: column;
}

.tabs-container {
    display: flex;
    border-bottom: 2px solid var(--border);
    margin-bottom: var(--space-3);
    background: var(--surface);
    border-radius: var(--radius-md) var(--radius-md) 0 0;
    padding: 0 var(--space-2);
    overflow-x: auto;
    flex-shrink: 0;
}

.tab-btn {
    flex: 0 0 auto;
    padding: var(--space-2);
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    font-weight: 700;
    color: var(--text-secondary);
    cursor: pointer;
    font-family: 'Vazirmatn', sans-serif;
    font-size: 14px;
    transition: 0.2s;
    white-space: nowrap;
}

.tab-btn.active {
    color: var(--primary);
    border-bottom: 3px solid var(--primary);
    background: rgba(6, 78, 78, 0.05);
}

.tab-content {
    display: none;
    animation: fadeIn 0.3s ease forwards;
}

.tab-content.active {
    display: flex;
    flex: 1;
    flex-direction: column;
}

.admin-card {
    background: var(--surface);
    border-radius: var(--radius-md);
    padding: var(--space-2);
    box-shadow: var(--shadow-card);
    border: 1px solid var(--border);
    margin-bottom: var(--space-2);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-2);
    flex-wrap: wrap;
    gap: var(--space-1);
}

.card-title {
    font-size: 18px;
    font-weight: 800;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: var(--space-1);
}

.option-tag {
    display: inline-flex;
    align-items: center;
    background: var(--bg);
    border: 1px solid var(--border);
    padding: 4px 12px;
    border-radius: 20px;
    margin: var(--space-1) var(--space-1) 0 0;
    font-size: 13px;
    gap: 8px;
}

.option-tag button {
    background: none;
    border: none;
    color: var(--danger);
    cursor: pointer;
    font-size: 14px;
    padding: 0;
    display: flex;
    align-items: center;
}

.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0,0,0,0.5);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 1000;
    backdrop-filter: blur(4px);
}

.modal-overlay.active {
    display: flex;
}

.modal-box {
    background: var(--surface);
    width: 95%;
    max-width: 700px;
    border-radius: var(--radius-md);
    padding: var(--space-3);
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    position: relative;
    max-height: 95vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-2);
    border-bottom: 1px solid var(--border);
    padding-bottom: var(--space-2);
}

.modal-header h3 {
    font-size: 20px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--text-secondary);
}

.form-row-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    margin-bottom: var(--space-2);
}

.form-row-group label {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}

.form-row-group input,
.form-row-group select,
.form-row-group textarea {
    width: 100%;
    padding: var(--space-1);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'Vazirmatn', sans-serif;
    font-size: 14px;
    background: var(--bg);
    color: var(--text-primary);
}

.form-row-group textarea {
    min-height: 60px;
    resize: vertical;
}

.btn-icon-sm {
    background: var(--bg);
    border: 1px solid var(--border);
    padding: 6px 12px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: 0.2s;
    font-family: 'Vazirmatn', sans-serif;
    font-weight: 600;
    font-size: 13px;
}

.btn-icon-sm.primary {
    background: var(--primary);
    color: #fff;
    border: none;
}

.btn-icon-sm.gold {
    background: var(--gold);
    color: #111827;
    border: none;
}

.btn-icon-sm.danger {
    color: var(--danger);
    border-color: var(--danger);
}

.btn-icon-sm.success {
    background: #059669;
    color: #fff;
    border: none;
}

.btn-icon-sm.teal {
    background: #0088cc;
    color: #fff;
    border: none;
}

.btn-save {
    background: var(--primary);
    color: #fff;
    width: 100%;
    height: 56px;
    border: none;
    border-radius: var(--radius-md);
    font-weight: 800;
    font-size: 18px;
    margin-top: var(--space-2);
    cursor: pointer;
}

.btn-save:active {
    transform: scale(0.98);
}

.ad-filter {
    display: flex;
    gap: var(--space-1);
    margin-bottom: var(--space-2);
    flex-wrap: wrap;
}

.ad-card {
    background: var(--surface);
    border-radius: var(--radius-md);
    padding: var(--space-2);
    box-shadow: var(--shadow-card);
    border: 1px solid var(--border);
    margin-bottom: var(--space-2);
}

.ad-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-1);
}

.ad-title {
    font-weight: 700;
    font-size: 16px;
    color: var(--text-primary);
}

.ad-status {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.status-pending {
    background: #FEF3C7;
    color: #D97706;
}

.status-published {
    background: #D1FAE5;
    color: #059669;
}

.status-sold {
    background: #DBEAFE;
    color: #2563EB;
}

.status-suspended {
    background: #FEE2E2;
    color: #DC2626;
}

.status-rejected {
    background: #FEE2E2;
    color: #DC2626;
}

.ad-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px 16px;
    font-size: 14px;
    margin: var(--space-1) 0;
}

.ad-info-grid .info-item {
    display: flex;
    justify-content: space-between;
    padding: 2px 0;
    border-bottom: 1px dashed var(--border);
}

.ad-info-grid .info-label {
    color: var(--text-secondary);
    font-weight: 500;
}

.ad-info-grid .info-value {
    color: var(--text-primary);
    font-weight: 600;
}

.ad-price {
    font-size: 16px;
    color: var(--gold);
    font-weight: 700;
    margin: var(--space-1) 0;
}

.ad-actions {
    display: flex;
    gap: var(--space-1);
    flex-wrap: wrap;
    margin-top: var(--space-1);
    border-top: 1px solid var(--border);
    padding-top: var(--space-1);
}

.btn-sm-ad {
    padding: 4px 12px;
    border-radius: var(--radius-sm);
    border: none;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    font-family: 'Vazirmatn', sans-serif;
    transition: all 0.2s;
}

.btn-sm-ad:active {
    transform: scale(0.95);
}

.btn-sm-ad.approve {
    background: #059669;
    color: #fff;
}

.btn-sm-ad.edit {
    background: var(--primary);
    color: #fff;
}

.btn-sm-ad.delete {
    background: var(--bg);
    color: var(--text-secondary);
    border: 1px solid var(--border);
}

.btn-sm-ad.sold {
    background: #3B82F6;
    color: #fff;
}

.btn-sm-ad.suspend {
    background: #F59E0B;
    color: #fff;
}

.btn-sm-ad.republish {
    background: #8B5CF6;
    color: #fff;
}

.btn-sm-ad.telegram {
    background: #0088cc;
    color: #fff;
}

.btn-secondary {
    background: var(--bg);
    color: var(--text-secondary);
    border: 1px solid var(--border);
    padding: 6px 12px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-family: 'Vazirmatn', sans-serif;
    font-weight: 600;
    font-size: 13px;
}

.btn-primary-full {
    width: 100%;
    height: 48px;
    border-radius: var(--radius-md);
    border: none;
    background: var(--primary);
    color: #fff;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    font-family: 'Vazirmatn', sans-serif;
    transition: all 0.2s;
}

.btn-primary-full:active {
    transform: scale(0.98);
}

.empty-state-ads {
    text-align: center;
    padding: var(--space-4);
    color: var(--text-secondary);
}

.empty-state-ads svg {
    margin-bottom: var(--space-2);
}

.empty-state-ads h3 {
    color: var(--text-primary);
    margin-top: var(--space-2);
}

.badge-mock {
    background: #F59E0B;
    color: #fff;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.price-section {
    display: none;
    padding: var(--space-1);
    background: var(--bg);
    border-radius: var(--radius-sm);
    margin-bottom: var(--space-2);
}

.price-section.active {
    display: block;
}

.checkbox-group {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
    margin-top: var(--space-1);
}

.checkbox-group label {
    font-weight: 400;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}

.checkbox-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--primary);
}

.section-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--primary);
    margin: var(--space-2) 0 var(--space-1);
    border-bottom: 1px solid var(--border);
    padding-bottom: var(--space-1);
}

.row-half {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-2);
}

.img-check-grid {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-1);
}

.img-check-item {
    position: relative;
    width: 80px;
    height: 80px;
    border: 2px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
    display: flex;
    justify-content: center;
    align-items: center;
    background: var(--bg);
}

.img-check-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.img-check-item input {
    position: absolute;
    top: 4px;
    left: 4px;
    accent-color: var(--primary);
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.img-check-item .img-label {
    position: absolute;
    bottom: 2px;
    right: 2px;
    font-size: 9px;
    color: #fff;
    background: rgba(0,0,0,0.6);
    padding: 1px 6px;
    border-radius: 4px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: var(--space-2);
    margin-bottom: var(--space-3);
}

.stat-card {
    background: var(--surface);
    border-radius: var(--radius-md);
    padding: var(--space-2);
    text-align: center;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-card);
}

.stat-card .number {
    font-size: 28px;
    font-weight: 800;
    color: var(--primary);
}

.stat-card .label {
    font-size: 14px;
    color: var(--text-secondary);
    margin-top: 4px;
}

.ads-toolbar {
    display:grid;
    grid-template-columns:minmax(220px,2fr) repeat(3,minmax(130px,1fr));
    gap:10px;
    margin-bottom:12px;
}

.ads-toolbar input,
.ads-toolbar select {
    width:100%;
    min-height:42px;
    border:1px solid var(--border);
    border-radius:10px;
    background:var(--bg);
    color:var(--text-primary);
    padding:0 12px;
    font-family:'Vazirmatn',sans-serif;
}

.ads-toolbar-actions {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
    margin-bottom:12px;
}

.bulk-bar {
    display:none;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:10px 12px;
    background:var(--bg);
    border:1px solid var(--border);
    border-radius:10px;
    margin-bottom:12px;
}

.bulk-bar.active {
    display:flex;
}

.bulk-actions {
    display:flex;
    gap:7px;
    flex-wrap:wrap;
}

.btn-filter {
    background:var(--bg);
    color:var(--text-secondary);
    border:1px solid var(--border);
    padding:7px 12px;
    border-radius:8px;
    cursor:pointer;
    font-family:'Vazirmatn',sans-serif;
    font-weight:600;
}

.btn-filter.active {
    background:var(--primary);
    color:#fff;
    border-color:var(--primary);
}

.ads-table-wrap {
    overflow-x:auto;
    border:1px solid var(--border);
    border-radius:12px;
    background:var(--surface);
}

.ads-table {
    width:100%;
    border-collapse:collapse;
    min-width:980px;
}

.ads-table th,
.ads-table td {
    padding:10px 9px;
    border-bottom:1px solid var(--border);
    text-align:right;
    vertical-align:middle;
    font-size:13px;
}

.ads-table th {
    background:var(--bg);
    color:var(--text-secondary);
    font-weight:700;
    white-space:nowrap;
    position:sticky;
    top:0;
    z-index:1;
}

.ads-table tr:last-child td {
    border-bottom:none;
}

.ads-table tr:hover td {
    background:rgba(6,78,78,.03);
}

.ad-row-main {
    display:flex;
    align-items:center;
    gap:9px;
    min-width:270px;
}

.ad-row-thumb {
    width:54px;
    height:44px;
    border-radius:8px;
    object-fit:cover;
    border:1px solid var(--border);
    background:var(--bg);
    flex:0 0 auto;
}

.ad-row-title {
    font-weight:800;
    color:var(--text-primary);
    line-height:1.5;
}

.ad-row-sub {
    color:var(--text-secondary);
    font-size:11px;
    margin-top:2px;
}

.table-actions {
    display:flex;
    gap:5px;
    align-items:center;
    flex-wrap:wrap;
}

.table-action {
    width:34px;
    height:34px;
    border-radius:8px;
    border:1px solid var(--border);
    background:var(--bg);
    color:var(--text-primary);
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

.table-action:hover {
    background:var(--surface);
}

.table-action.primary {
    background:var(--primary);
    color:#fff;
    border-color:var(--primary);
}

.table-action.danger {
    color:var(--danger);
}

.pagination-bar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-top:12px;
    flex-wrap:wrap;
}

.pagination {
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.page-btn {
    min-width:36px;
    height:36px;
    border-radius:8px;
    border:1px solid var(--border);
    background:var(--bg);
    cursor:pointer;
    color:var(--text-primary);
    font-family:'Vazirmatn',sans-serif;
}

.page-btn.active {
    background:var(--primary);
    color:#fff;
    border-color:var(--primary);
}

.selection-check {
    width:17px;
    height:17px;
    accent-color:var(--primary);
    cursor:pointer;
}

.empty-table {
    text-align:center;
    padding:48px 20px;
    color:var(--text-secondary);
}

.ad-quick-status {
    font-size:11px;
    color:var(--text-secondary);
    margin-top:3px;
}

@media (max-width: 900px) {
    .ads-toolbar {
        grid-template-columns:1fr 1fr;
    }
}

@media (max-width: 600px) {
    .ads-toolbar {
        grid-template-columns:1fr;
    }

    .bulk-bar {
        flex-direction:column;
        align-items:stretch;
    }
}


/* =========================================================
   ویرایش حرفه‌ای آگهی
   ========================================================= */

#adEditModal .modal-box {
    max-width: 1050px;
    width: 96%;
    padding: 0;
    overflow: hidden;
}

.edit-shell {
    display:flex;
    flex-direction:column;
    max-height:90vh;
}

.edit-top {
    padding:16px 18px;
    border-bottom:1px solid var(--border);
    background:var(--surface);
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:center;
}

.edit-top-title {
    font-weight:800;
    font-size:18px;
    color:var(--text-primary);
}

.edit-top-meta {
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
}

.edit-tabs {
    display:flex;
    gap:4px;
    padding:8px 12px;
    overflow-x:auto;
    border-bottom:1px solid var(--border);
    background:var(--bg);
}

.edit-tab {
    border:1px solid transparent;
    background:transparent;
    color:var(--text-secondary);
    padding:9px 13px;
    border-radius:9px;
    cursor:pointer;
    font-family:'Vazirmatn',sans-serif;
    font-size:13px;
    font-weight:700;
    white-space:nowrap;
}

.edit-tab.active {
    background:var(--surface);
    color:var(--primary);
    border-color:var(--border);
    box-shadow:0 2px 8px rgba(0,0,0,.04);
}

.edit-body {
    overflow:auto;
    padding:16px;
    background:var(--bg);
}

.edit-pane {
    display:none;
}

.edit-pane.active {
    display:block;
}

.edit-section {
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:12px;
    padding:15px;
    margin-bottom:14px;
}

.edit-section-title {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    margin-bottom:13px;
    font-size:14px;
    font-weight:800;
    color:var(--text-primary);
}

.edit-section-title span {
    color:var(--primary);
}

.edit-grid {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:11px;
}

.edit-field {
    display:flex;
    flex-direction:column;
    gap:6px;
}

.edit-field.full {
    grid-column:1/-1;
}

.edit-field label {
    font-size:12px;
    color:var(--text-secondary);
    font-weight:700;
}

.edit-field input,
.edit-field select,
.edit-field textarea {
    width:100%;
    box-sizing:border-box;
    padding:9px 10px;
    border:1px solid var(--border);
    border-radius:8px;
    background:var(--bg);
    color:var(--text-primary);
    font-family:'Vazirmatn',sans-serif;
    font-size:13px;
    outline:none;
}

.edit-field textarea {
    min-height:100px;
    resize:vertical;
}

.edit-field input:focus,
.edit-field select:focus,
.edit-field textarea:focus {
    border-color:var(--primary);
    box-shadow:0 0 0 2px rgba(6,78,78,.08);
}

.edit-checks {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:8px;
}

.edit-check {
    display:flex;
    align-items:center;
    gap:7px;
    padding:8px 9px;
    border:1px solid var(--border);
    border-radius:8px;
    background:var(--bg);
    font-size:12px;
    cursor:pointer;
}

.edit-check input {
    width:16px;
    height:16px;
    accent-color:var(--primary);
}

.edit-image-grid {
    display:grid;
    grid-template-columns:repeat(5,minmax(100px,1fr));
    gap:10px;
}

.edit-image {
    position:relative;
    border:1px solid var(--border);
    border-radius:9px;
    overflow:hidden;
    background:var(--bg);
}

.edit-image img {
    width:100%;
    aspect-ratio:1/1;
    object-fit:cover;
    display:block;
}

.edit-image label {
    display:flex;
    align-items:center;
    gap:6px;
    padding:7px;
    font-size:11px;
}

.edit-image input {
    accent-color:var(--primary);
}

.edit-image.empty {
    min-height:110px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:var(--text-secondary);
    font-size:12px;
}

.edit-footer {
    padding:12px 16px;
    border-top:1px solid var(--border);
    background:var(--surface);
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:center;
}

.edit-footer-actions {
    display:flex;
    gap:8px;
}

.edit-footer .btn-secondary,
.edit-footer .btn-primary-full {
    width:auto;
    min-width:130px;
    padding:0 18px;
}

.edit-note {
    font-size:11px;
    color:var(--text-secondary);
}

.edit-inline {
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
}

.edit-badge {
    display:inline-flex;
    align-items:center;
    padding:4px 8px;
    border-radius:999px;
    background:var(--bg);
    border:1px solid var(--border);
    font-size:11px;
    color:var(--text-secondary);
}

@media (max-width:850px) {

    .edit-grid {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .edit-checks {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .edit-image-grid {
        grid-template-columns:repeat(3,minmax(90px,1fr));
    }
}

@media (max-width:560px) {

    #adEditModal .modal-box {
        width:100%;
        height:100%;
        max-height:100vh;
        border-radius:0;
    }

    .edit-shell {
        max-height:100vh;
    }

    .edit-grid {
        grid-template-columns:1fr;
    }

    .edit-checks {
        grid-template-columns:1fr 1fr;
    }

    .edit-image-grid {
        grid-template-columns:repeat(2,minmax(90px,1fr));
    }

    .edit-footer {
        flex-direction:column;
        align-items:stretch;
    }

    .edit-footer-actions {
        display:grid;
        grid-template-columns:1fr 1fr;
    }

    .edit-footer-actions button {
        width:100%!important;
    }
}

#adDetailModal .modal-box {
    max-width:1050px;
    width:96%;
    padding:0;
    overflow:hidden;
}

.detail-shell {
    background:var(--bg);
    max-height:88vh;
    overflow:auto;
    padding:18px;
}

.detail-hero {
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:flex-start;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:12px;
    padding:15px;
    margin-bottom:12px;
}

.detail-title {
    font-size:20px;
    font-weight:900;
    color:var(--text-primary);
}

.detail-sub {
    font-size:12px;
    color:var(--text-secondary);
    margin-top:4px;
}

.detail-section {
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:12px;
    padding:15px;
    margin-bottom:12px;
}

.detail-section-title {
    font-size:14px;
    font-weight:900;
    color:var(--primary);
    margin-bottom:12px;
}

.detail-kv-grid {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:8px;
}

.detail-kv {
    border:1px solid var(--border);
    background:var(--bg);
    border-radius:9px;
    padding:9px 10px;
    display:flex;
    flex-direction:column;
    gap:5px;
}

.detail-kv span {
    font-size:11px;
    color:var(--text-secondary);
}

.detail-kv strong {
    font-size:13px;
    color:var(--text-primary);
    word-break:break-word;
}

.detail-tags {
    display:flex;
    flex-wrap:wrap;
    gap:7px
}

.detail-tag {
    padding:6px 9px;
    border-radius:999px;
    background:var(--bg);
    border:1px solid var(--border);
    font-size:12px;
}

.detail-muted {
    color:var(--text-secondary);
    font-size:12px;
}

.detail-description {
    white-space:normal;
    line-height:2;
    font-size:13px;
    color:var(--text-primary)
}

.detail-policy,
.image-choice-banner {
    background:var(--bg);
    border:1px solid var(--border);
    border-radius:9px;
    padding:10px;
    font-size:12px;
    line-height:1.9;
    margin-bottom:12px;
}

.detail-policy small,
.image-choice-banner small {
    display:block;
    color:var(--text-secondary);
}

.image-choice-banner.yes {
    border-color:#10b981;
}

.image-choice-banner.no {
    border-color:#ef4444;
}

.detail-gallery,
.manage-image-grid {
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:10px;
}

.detail-image-card,
.manage-image {
    background:var(--bg);
    border:2px solid var(--border);
    border-radius:10px;
    overflow:hidden;
}

.detail-image-card.is-selected,
.manage-image.selected {
    border-color:var(--primary);
}

.detail-image-card img,
.manage-image img {
    width:100%;
    aspect-ratio:1/1;
    object-fit:cover;
    display:block;
}

.detail-image-meta,
.manage-image-footer {
    padding:7px;
    font-size:10px;
    display:flex;
    justify-content:space-between;
    gap:6px;
    align-items:center;
}

.detail-image-meta b {
    color:var(--primary);
}

.detail-empty {
    padding:25px;
    text-align:center;
    color:var(--text-secondary);
    border:1px dashed var(--border);
    border-radius:10px;
}

.detail-actions {
    display:flex;
    justify-content:flex-start;
    gap:8px;
    flex-wrap:wrap;
}

.admin-image-controls {
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
    margin-bottom:10px;
}

.edit-bool {
    display:flex;
    align-items:center;
    gap:8px;
    border:1px solid var(--border);
    background:var(--bg);
    padding:9px 10px;
    border-radius:8px;
    height:38px;
    box-sizing:border-box;
}

.edit-bool input {
    accent-color:var(--primary);
}

.edit-pro-shell {
    display:flex;
    flex-direction:column;
    max-height:90vh;
}

.edit-pro-head {
    padding:16px 18px;
    border-bottom:1px solid var(--border);
    background:var(--surface);
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:12px;
}

.edit-pro-head h2 {
    margin:0;
    font-size:18px;
    color:var(--text-primary);
}

.edit-pro-head p {
    margin:5px 0 0;
    color:var(--text-secondary);
    font-size:11px;
}

.edit-pro-body {
    overflow:auto;
    background:var(--bg);
    padding:15px;
}

.edit-pro-footer {
    padding:12px 15px;
    background:var(--surface);
    border-top:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
}

.edit-pro-footer>div {
    display:flex;
    gap:8px;
}

.edit-price-box {
    display:none;
}

.edit-price-box.active {
    display:block;
}

.edit-description {
    min-height:160px;
}

.manage-image-footer label {
    display:flex;
    align-items:center;
    gap:5px;
    font-size:10px;
}

.manage-image-footer input {
    accent-color:var(--primary);
}

@media(max-width:850px) {

    .detail-kv-grid {
        grid-template-columns:1fr 1fr;
    }

    .detail-gallery,
    .manage-image-grid {
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
}

@media(max-width:560px) {

    #adDetailModal .modal-box,
    #adEditModal .modal-box {
        width:100%;
        height:100%;
        max-height:100vh;
        border-radius:0;
    }

    .detail-shell,
    .edit-pro-shell {
        max-height:100vh;
    }

    .detail-kv-grid,
    .edit-grid {
        grid-template-columns:1fr;
    }

    .detail-gallery,
    .manage-image-grid {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .edit-pro-footer {
        flex-direction:column;
        align-items:stretch;
    }

    .edit-pro-footer>div {
        display:grid;
        grid-template-columns:1fr 1fr;
    }

    .detail-actions {
        display:grid;
        grid-template-columns:1fr;
    }

    .detail-actions button {
        width:100%!important;
    }
}


/* =========================================================
   درخواست‌های ملک
   ========================================================= */

.requests-toolbar {
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:14px;
}

.requests-toolbar input,
.requests-toolbar select {
    height:42px;
    box-sizing:border-box;
    border:1px solid var(--border);
    border-radius:10px;
    background:var(--bg);
    color:var(--text-primary);
    padding:0 12px;
    font-family:'Vazirmatn',sans-serif
}

.requests-toolbar input {
    flex:1;
    min-width:220px
}

.requests-toolbar select {
    min-width:150px
}

.request-admin-card {
    border:1px solid var(--border);
    border-radius:14px;
    padding:15px;
    margin-bottom:12px;
    background:var(--surface)
}

.request-admin-head {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:10px
}

.request-code {
    font-size:16px;
    font-weight:900;
    color:var(--primary)
}

.request-date {
    font-size:11px;
    color:var(--text-secondary);
    margin-top:4px
}

.request-status {
    display:inline-flex;
    padding:5px 10px;
    border-radius:999px;
    background:#FEF3C7;
    color:#B45309;
    font-size:11px;
    font-weight:800
}

.request-admin-grid {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:9px;
    margin-top:13px
}

.request-admin-item {
    background:var(--bg);
    border:1px solid var(--border);
    border-radius:9px;
    padding:9px
}

.request-admin-item small {
    display:block;
    color:var(--text-secondary);
    font-size:10px;
    margin-bottom:4px
}

.request-admin-item strong {
    display:block;
    color:var(--text-primary);
    font-size:12px;
    word-break:break-word
}

.request-matches {
    margin-top:13px;
    padding-top:12px;
    border-top:1px solid var(--border)
}

.request-matches-toggle-row {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:13px;
    padding-top:12px;
    border-top:1px solid var(--border)
}

.request-collapse-toggle {
    display:flex;
    align-items:center;
    gap:6px;
    background:var(--bg-secondary);
    border:1px solid var(--border);
    color:var(--text-primary);
    border-radius:8px;
    padding:7px 12px;
    font-size:12px;
    font-family:inherit;
    cursor:pointer
}

.request-collapse-toggle:hover {
    border-color:var(--primary)
}

.request-collapse-arrow {
    display:inline-block;
    transition:transform .15s ease
}

.request-collapse-toggle.open .request-collapse-arrow {
    transform:rotate(-90deg)
}

.request-combo-row {
    background:var(--bg-secondary);
    border:1px solid var(--border);
    border-radius:10px;
    padding:10px;
    margin-bottom:10px
}

.request-combo-row:last-child {
    margin-bottom:0
}

.request-combo-head {
    display:flex;
    flex-wrap:wrap;
    gap:12px;
    align-items:center;
    font-size:12px;
    margin-bottom:8px;
    padding-bottom:8px;
    border-bottom:1px dashed var(--border)
}

.request-combo-items .request-match-row {
    padding:7px 0
}

.request-match-row {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding:9px 0;
    border-bottom:1px dashed var(--border)
}

.request-match-row:last-child {
    border-bottom:0
}

.request-match-title {
    font-size:13px;
    font-weight:800;
    color:var(--text-primary)
}

.request-match-meta {
    font-size:10px;
    color:var(--text-secondary);
    margin-top:3px
}

.request-match-score {
    font-size:15px;
    font-weight:900;
    color:var(--primary);
    white-space:nowrap
}


/* =========================================================
   REQUEST FOLLOW-UP / MATCH COMPACT UI
   ========================================================= */
.request-status-wrap{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.request-status-select{height:34px;min-width:150px;padding:0 10px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text-primary);font-family:'Vazirmatn',sans-serif;font-size:11px;font-weight:700;outline:none}
.request-status-select:focus,.request-followup-input:focus{border-color:var(--primary);box-shadow:0 0 0 2px rgba(6,78,78,.08)}
.request-followup-box{margin-top:10px;display:flex;gap:8px;align-items:flex-end}
.request-followup-input{flex:1;min-height:42px;max-height:110px;resize:vertical;padding:8px 10px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text-primary);font-family:'Vazirmatn',sans-serif;font-size:11px;outline:none;box-sizing:border-box}
.request-followup-save{height:42px;padding:0 14px;border:0;border-radius:9px;background:var(--primary);color:#fff;font-family:'Vazirmatn',sans-serif;font-size:11px;font-weight:800;cursor:pointer;white-space:nowrap}
.request-followup-state{font-size:10px;color:var(--text-secondary);min-width:90px}
.request-match-row{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px dashed var(--border)}
.request-match-main{flex:1;min-width:0}
.request-match-code{display:inline-flex;align-items:center;gap:4px;color:var(--primary);font-size:11px;font-weight:900;text-decoration:none;margin-bottom:2px}
.request-match-code:hover{text-decoration:underline}
.request-match-inline{display:flex;flex-wrap:wrap;gap:5px 12px;margin-top:5px}
.request-match-inline span{font-size:10px;color:var(--text-secondary)}
.request-match-inline strong{color:var(--text-primary);font-weight:800}
.request-match-score{font-size:15px;font-weight:900;color:var(--primary);white-space:nowrap;flex-shrink:0}
.request-match-detail{display:none}
@media(max-width:680px){.request-followup-box{flex-direction:column;align-items:stretch}.request-followup-save{width:100%}.request-status-select{min-width:130px}}

.request-empty {
    text-align:center;
    padding:42px 15px;
    color:var(--text-secondary)
}

@media(max-width:900px) {

    .request-admin-grid {
        grid-template-columns:repeat(2,minmax(0,1fr))
    }
}

@media(max-width:560px) {

    .request-admin-grid {
        grid-template-columns:1fr
    }

    .request-admin-head {
        flex-direction:column
    }
}


/* =========================================================
   تماس - بخش جدید مدیریت اطلاعات تماس
   ========================================================= */

.contact-admin-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:14px;
}

.contact-admin-field {
    display:flex;
    flex-direction:column;
    gap:7px;
}

.contact-admin-field.full {
    grid-column:1 / -1;
}

.contact-admin-field label {
    font-size:13px;
    font-weight:700;
    color:var(--text-primary);
}

.contact-admin-field input,
.contact-admin-field textarea {
    width:100%;
    box-sizing:border-box;
    border:1px solid var(--border);
    background:var(--bg);
    color:var(--text-primary);
    border-radius:10px;
    padding:11px 12px;
    font-family:'Vazirmatn',sans-serif;
    font-size:13px;
    outline:none;
    transition:.2s ease;
}

.contact-admin-field input {
    min-height:44px;
}

.contact-admin-field textarea {
    min-height:85px;
    resize:vertical;
    line-height:1.9;
}

.contact-admin-field input:focus,
.contact-admin-field textarea:focus {
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(6,78,78,.08);
}

.contact-admin-help {
    font-size:10px;
    color:var(--text-secondary);
    line-height:1.8;
}

.contact-admin-preview {
    margin-top:18px;
    background:var(--bg);
    border:1px solid var(--border);
    border-radius:12px;
    padding:14px;
}

.contact-admin-preview-title {
    font-size:13px;
    font-weight:800;
    color:var(--text-primary);
    margin-bottom:12px;
}

.contact-preview-grid {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:8px;
}

.contact-preview-grid > div {
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:9px;
    padding:10px;
}

.contact-preview-grid span {
    display:block;
    color:var(--text-secondary);
    font-size:10px;
    margin-bottom:5px;
}

.contact-preview-grid strong {
    display:block;
    color:var(--text-primary);
    font-size:12px;
    word-break:break-word;
}

.contact-save-note {
    margin-top:10px;
    font-size:11px;
    color:var(--text-secondary);
    line-height:1.8;
}

@media(max-width:800px) {

    .contact-admin-grid {
        grid-template-columns:1fr;
    }

    .contact-admin-field.full {
        grid-column:auto;
    }

    .contact-preview-grid {
        grid-template-columns:1fr 1fr;
    }
}

@media(max-width:500px) {

    .contact-preview-grid {
        grid-template-columns:1fr;
    }
}

</style>


<style>
/* =========================================================
   MELKINO ADMIN CONTROL CENTER - NEW LAYER
========================================================= */
.admin-hero{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:20px 22px;margin-bottom:16px;border-radius:20px;background:linear-gradient(135deg,color-mix(in srgb,var(--primary) 96%,#000 4%),color-mix(in srgb,var(--primary-dark) 92%,#000 8%));color:#fff;box-shadow:0 18px 50px rgba(6,78,78,.16);overflow:hidden;position:relative}
.admin-hero::after{content:"";position:absolute;width:230px;height:230px;border-radius:50%;left:-95px;top:-115px;background:rgba(212,175,55,.10)}
.admin-hero-copy{position:relative;z-index:1}.admin-hero-kicker{font-size:10px;letter-spacing:1.2px;opacity:.66;font-weight:800}.admin-hero-title{font-size:24px;font-weight:950;margin-top:5px}.admin-hero-sub{font-size:11px;opacity:.68;margin-top:4px;line-height:1.8}.admin-hero-badge{position:relative;z-index:1;padding:8px 12px;border-radius:999px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.14);font-size:10px;font-weight:800}
.admin-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:14px}.admin-stat-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:14px;box-shadow:var(--shadow-card);position:relative;overflow:hidden}.admin-stat-icon{width:38px;height:38px;border-radius:12px;background:var(--gold-bg);color:var(--gold);display:flex;align-items:center;justify-content:center;font-size:18px}.admin-stat-number{font-size:24px;font-weight:950;color:var(--text-primary);margin-top:11px}.admin-stat-label{font-size:10px;color:var(--text-secondary);margin-top:2px}.admin-stat-note{font-size:9px;color:var(--text-muted);margin-top:8px}.admin-stat-card.accent{border-color:color-mix(in srgb,var(--primary) 20%,var(--border))}.admin-stat-card.warning{border-color:color-mix(in srgb,var(--warning) 20%,var(--border))}.admin-stat-card.danger{border-color:color-mix(in srgb,var(--danger) 20%,var(--border))}
.admin-panel-section{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:16px;margin-bottom:14px;box-shadow:var(--shadow-card)}.admin-section-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap}.admin-section-title{font-size:15px;font-weight:900;color:var(--text-primary)}.admin-section-help{font-size:10px;color:var(--text-secondary);line-height:1.8}.admin-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.admin-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.admin-field{display:flex;flex-direction:column;gap:6px}.admin-field.full{grid-column:1/-1}.admin-field label{font-size:11px;font-weight:800;color:var(--text-secondary)}.admin-field input,.admin-field select,.admin-field textarea{width:100%;min-height:46px;border:1px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text-primary);font-family:inherit;padding:9px 11px;outline:none}.admin-field textarea{min-height:100px;resize:vertical}.admin-field input:focus,.admin-field select:focus,.admin-field textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 10%,transparent)}
.consultants-toolbar{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap}.consultant-manager-list{display:flex;flex-direction:column;gap:12px}.consultant-manager-card{border:1px solid var(--border);border-radius:16px;background:var(--bg);overflow:hidden}.consultant-manager-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 14px;background:color-mix(in srgb,var(--surface) 88%,var(--primary) 12%);flex-wrap:wrap}.consultant-manager-title{display:flex;align-items:center;gap:8px;font-weight:900;color:var(--text-primary);font-size:13px}.consultant-default-pill{padding:5px 9px;border-radius:999px;background:var(--gold-bg);color:var(--gold-dark);font-size:9px;font-weight:900}.consultant-specialties{display:flex;gap:6px;flex-wrap:wrap;padding:0 14px 12px}.consultant-specialty{padding:6px 8px;border-radius:999px;background:var(--surface);border:1px solid var(--border);font-size:9px;color:var(--text-secondary);display:flex;align-items:center;gap:5px}.consultant-specialty button{border:0;background:transparent;color:var(--danger);cursor:pointer}.consultant-add-specialty{display:grid;grid-template-columns:1fr 1fr auto;gap:7px;padding:0 14px 14px}.consultant-manager-actions{display:flex;gap:7px;flex-wrap:wrap}.consultant-empty{padding:22px;border:1px dashed var(--border);border-radius:14px;text-align:center;color:var(--text-secondary);font-size:11px}
.color-theme-panel{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start}.color-theme-card{min-width:0;border:1px solid var(--border);border-radius:18px;padding:16px;background:linear-gradient(180deg,var(--surface),var(--bg));box-shadow:var(--shadow-card)}.color-theme-title{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:14px;font-weight:900;color:var(--text-primary);margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--border)}.color-theme-title::after{content:"ویرایش مستقیم";font-size:9px;font-weight:800;color:var(--text-secondary);padding:4px 8px;border-radius:999px;background:var(--bg-secondary);border:1px solid var(--border)}.color-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.color-field{min-width:0;display:flex;flex-direction:column;gap:7px;padding:10px;border:1px solid var(--border);border-radius:12px;background:var(--surface)}.color-field label{display:block;font-size:10px;line-height:1.6;color:var(--text-primary);font-weight:800;min-width:0}.color-field label span{display:block;margin-top:2px;color:var(--text-muted)!important;font-size:8px!important;direction:ltr;text-align:left}.color-field > div{display:grid!important;grid-template-columns:minmax(0,1fr) 48px!important;gap:6px!important;align-items:center!important}.color-field .theme-text{width:100%!important;min-width:0!important;height:38px!important;padding:0 9px!important;box-sizing:border-box!important;border:1px solid var(--border)!important;border-radius:9px!important;background:var(--bg)!important;color:var(--text-primary)!important;font-family:inherit!important;font-size:11px!important;direction:ltr!important;text-align:left!important;outline:none!important}.color-field .theme-text:focus{border-color:var(--primary)!important;box-shadow:0 0 0 2px color-mix(in srgb,var(--primary) 12%,transparent)!important}.color-field input[type=color]{width:48px!important;height:38px!important;padding:3px!important;border:1px solid var(--border)!important;border-radius:9px!important;background:var(--surface)!important;cursor:pointer!important}.theme-preview{margin-top:10px;border:1px solid var(--border);border-radius:14px;overflow:hidden}.theme-preview-head{padding:9px 11px;font-size:10px;font-weight:900}.theme-preview-body{padding:12px;display:grid;grid-template-columns:1fr 1fr;gap:8px}.theme-preview-card{padding:10px;border-radius:10px;font-size:9px}.theme-preview-button{padding:9px;border-radius:10px;text-align:center;font-size:9px;font-weight:900}
.password-security-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.security-switch{display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid var(--border);border-radius:12px;background:var(--bg)}.security-switch span{font-size:11px;color:var(--text-primary);font-weight:800}.security-switch small{display:block;font-size:9px;color:var(--text-secondary);margin-top:2px}.security-switch input{width:18px;height:18px;accent-color:var(--primary)}
.users-table{width:100%;border-collapse:collapse}.users-table th,.users-table td{padding:10px;border-bottom:1px solid var(--border);font-size:11px;text-align:right}.users-table th{background:var(--bg);color:var(--text-secondary)}.user-status-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--success);margin-left:4px}
.ad-vip-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 7px;border-radius:999px;background:var(--gold-bg);color:var(--gold-dark);font-size:9px;font-weight:900;margin-top:4px}.table-action.vip.active{background:var(--gold);color:#111827;border-color:var(--gold)}
.request-match-detail{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;margin-top:8px}.request-match-detail div{padding:7px 8px;border-radius:9px;background:var(--surface);border:1px solid var(--border);font-size:9px;color:var(--text-secondary)}.request-match-detail strong{display:block;color:var(--text-primary);font-size:10px;margin-top:2px}
@media(max-width:980px){.admin-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.color-theme-panel,.password-security-grid{grid-template-columns:1fr}.admin-grid-3{grid-template-columns:1fr 1fr}}
@media(max-width:680px){.admin-hero{align-items:flex-start;flex-direction:column}.admin-grid-2,.admin-grid-3,.color-grid{grid-template-columns:1fr}.admin-field.full{grid-column:auto}.admin-stat-grid{grid-template-columns:1fr 1fr}.consultant-add-specialty{grid-template-columns:1fr}.request-match-detail{grid-template-columns:1fr}.tabs-container{position:sticky;top:0;z-index:80}}
@media(max-width:480px){.color-theme-card{padding:12px}.color-field{padding:9px}.color-field > div{grid-template-columns:minmax(0,1fr) 46px!important}.color-field input[type=color]{width:46px!important;height:36px!important}.color-field .theme-text{height:36px!important;font-size:10px!important}}


/* =========================================================
   MELKINO ADMIN — PREMIUM V2 VISUAL SHELL
   ========================================================= */
.admin-body{background:#071918!important}
.admin-body .app-container{background:var(--bg)!important}
.admin-body .topbar{background:linear-gradient(135deg,#052d2d,#0a4b49)!important;border-bottom:1px solid rgba(212,175,55,.18)!important;color:#fff!important}
.admin-body .topbar-title{color:#f0d36a!important;font-weight:900!important;letter-spacing:-.2px}
.admin-body .topbar-action{color:rgba(255,255,255,.78)!important}
.admin-body .topbar-action:hover{background:rgba(255,255,255,.08)!important;color:#f0d36a!important}
.admin-body .main-content{padding:0 22px 30px!important;background:radial-gradient(circle at 90% 0%,rgba(212,175,55,.08),transparent 24%),linear-gradient(180deg,#f6f8f5 0%,#eef2ef 100%)!important}
[data-theme="dark"] .admin-body .main-content{background:radial-gradient(circle at 90% 0%,rgba(229,184,66,.08),transparent 24%),linear-gradient(180deg,#0b1717,#0d1f1e)!important}

.admin-command-header{margin:20px 0 14px!important;padding:22px 24px!important;border-radius:26px!important;background:linear-gradient(135deg,#052d2d 0%,#064e4e 58%,#0b5d5b 100%)!important;box-shadow:0 18px 45px rgba(3,38,38,.18)!important;border:1px solid rgba(212,175,55,.22)!important;position:relative;overflow:hidden!important}
.admin-command-header:after{content:"";position:absolute;width:260px;height:260px;border-radius:50%;left:-100px;bottom:-160px;background:rgba(212,175,55,.08);pointer-events:none}
.admin-command-brand{position:relative;z-index:2;display:flex!important;align-items:center!important;gap:14px!important}
.admin-command-mark{width:56px!important;height:56px!important;border-radius:18px!important;display:grid!important;place-items:center!important;background:linear-gradient(135deg,#f4dc7a,#c79f27)!important;color:#163434!important;font-size:28px!important;font-weight:1000!important;box-shadow:0 10px 25px rgba(212,175,55,.22)!important}
.admin-command-kicker{font-size:10px!important;letter-spacing:2px!important;color:#f4dc7a!important;font-weight:900!important}
.admin-command-title{font-size:23px!important;color:#fff!important;font-weight:950!important;margin-top:3px!important}
.admin-command-meta{position:relative;z-index:2;color:rgba(255,255,255,.72)!important;font-size:11px!important;display:flex;align-items:center;gap:8px!important;flex-wrap:wrap}
.admin-live-dot{width:8px!important;height:8px!important;background:#51e39d!important;border-radius:50%!important;box-shadow:0 0 12px rgba(81,227,157,.8)!important}

.admin-body .tabs-container{display:grid!important;grid-template-columns:repeat(9,minmax(0,1fr))!important;gap:8px!important;padding:8px!important;margin-bottom:18px!important;background:rgba(255,255,255,.72)!important;border:1px solid rgba(6,78,78,.08)!important;border-radius:20px!important;box-shadow:0 10px 26px rgba(0,0,0,.05)!important;overflow:visible!important}
.admin-body .tab-btn{padding:11px 8px!important;min-height:48px!important;border:1px solid transparent!important;border-radius:14px!important;background:transparent!important;color:var(--text-secondary)!important;font-size:11px!important;font-weight:850!important;border-bottom:none!important;transition:.2s ease!important;white-space:nowrap}
.admin-body .tab-btn:hover{background:rgba(6,78,78,.055)!important;color:var(--primary)!important;transform:translateY(-1px)!important}
.admin-body .tab-btn.active{background:linear-gradient(135deg,var(--primary),#0b5d5b)!important;color:#fff!important;border-color:rgba(212,175,55,.28)!important;box-shadow:0 8px 18px rgba(6,78,78,.18)!important}
[data-theme="dark"] .admin-body .tabs-container{background:#152524!important;border-color:#294646!important}
[data-theme="dark"] .admin-body .tab-btn:hover{background:rgba(255,255,255,.05)!important}

.admin-body .tab-content.active{gap:16px!important}
.admin-body .admin-card{border-radius:22px!important;border:1px solid rgba(6,78,78,.08)!important;box-shadow:0 12px 34px rgba(0,0,0,.06)!important;background:rgba(255,255,255,.86)!important;padding:18px!important}
[data-theme="dark"] .admin-body .admin-card{background:#142524!important;border-color:#294646!important;box-shadow:0 12px 34px rgba(0,0,0,.25)!important}
.admin-body .card-title{font-size:17px!important;font-weight:950!important}

/* hide old compact dashboard strip — the new dashboard replaces it */
#tab-dashboard > .admin-card:first-child{display:none!important}
.admin-hero{margin:0!important;padding:24px!important;border-radius:24px!important;background:linear-gradient(135deg,rgba(6,78,78,.97),rgba(11,93,91,.90))!important;border:1px solid rgba(212,175,55,.20)!important;box-shadow:0 16px 38px rgba(6,78,78,.15)!important}
.admin-hero-title{font-size:27px!important;font-weight:950!important;color:#fff!important}
.admin-hero-kicker{color:#f4d978!important;font-weight:900!important;letter-spacing:1.4px!important;font-size:10px!important}
.admin-hero-sub{color:rgba(255,255,255,.68)!important;font-size:11px!important;line-height:1.9!important;max-width:620px!important}
.admin-hero-badge{background:rgba(255,255,255,.10)!important;border:1px solid rgba(255,255,255,.16)!important;color:#fff!important;padding:8px 12px!important;border-radius:999px!important;font-size:10px!important;font-weight:800!important}
.admin-stat-grid{grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:12px!important}
.admin-stat-card{min-height:145px!important;padding:18px!important;border-radius:20px!important;background:rgba(255,255,255,.90)!important;border:1px solid rgba(6,78,78,.08)!important;box-shadow:0 12px 30px rgba(0,0,0,.055)!important;position:relative!important;overflow:hidden!important}
.admin-stat-card:after{content:"";position:absolute;width:110px;height:110px;border-radius:50%;left:-58px;bottom:-64px;background:rgba(212,175,55,.08)}
[data-theme="dark"] .admin-stat-card{background:#142524!important;border-color:#294646!important;box-shadow:0 12px 30px rgba(0,0,0,.24)!important}
.admin-stat-icon{width:42px!important;height:42px!important;border-radius:14px!important;display:grid!important;place-items:center!important;background:var(--gold-bg)!important;font-size:18px!important;margin-bottom:10px!important}
.admin-stat-number{font-size:27px!important;font-weight:1000!important;color:var(--text-primary)!important}
.admin-stat-label{font-size:11px!important;font-weight:850!important;color:var(--text-primary)!important;margin-top:2px!important}
.admin-stat-note{font-size:9px!important;color:var(--text-secondary)!important;margin-top:5px!important}

/* settings sections */
#tab-contact .admin-card,#tab-password .admin-card,#tab-global .admin-card,#tab-onboarding .admin-card{padding:22px!important}
#consultantsContainer,#passwordContainer,#globalContainer,#onboardingEditor{margin-top:6px!important}
.admin-field{background:var(--bg)!important;border-radius:14px!important;padding:12px!important;border:1px solid var(--border)!important}
.admin-field label{font-weight:850!important;font-size:11px!important;color:var(--text-primary)!important}
.admin-field input,.admin-field select,.admin-field textarea{border-radius:12px!important;min-height:46px!important;background:var(--surface)!important}

@media(max-width:1100px){.admin-body .tabs-container{grid-template-columns:repeat(5,minmax(0,1fr))!important}.admin-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
@media(max-width:650px){.admin-body .main-content{padding:0 10px 24px!important}.admin-command-header{padding:18px!important;border-radius:20px!important}.admin-command-title{font-size:19px!important}.admin-command-meta{margin-top:12px!important}.admin-body .tabs-container{grid-template-columns:repeat(3,minmax(0,1fr))!important;position:sticky!important;top:0!important;z-index:80!important}.admin-body .tab-btn{font-size:10px!important;padding:9px 5px!important}.admin-stat-grid{grid-template-columns:1fr 1fr!important}.admin-stat-card{min-height:125px!important;padding:14px!important}.admin-stat-number{font-size:22px!important}.admin-hero{padding:18px!important}.admin-hero-title{font-size:22px!important}}
@media(max-width:400px){.admin-body .tabs-container{grid-template-columns:repeat(2,minmax(0,1fr))!important}.admin-stat-grid{grid-template-columns:1fr!important}}

</style>



</head>

<body class="admin-body">

<div
    class="app-container"
    style="padding-bottom:0;"
>

<header class="topbar">

    <a
        href="home.php"
        class="topbar-action"
    >
        <svg
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
        >
            <polyline points="15 18 9 12 15 6"></polyline>
        </svg>
    </a>


    <span class="topbar-title">

        پنل مدیریت ملکینو

        <?php if ($isMockMode): ?>

            <span class="badge-mock">
                MOCK
            </span>

        <?php endif; ?>

    </span>


    <div
        style="display:flex;gap:var(--space-2);"
    >

        <button
            class="topbar-action"
            onclick="saveAndExit()"
            title="ذخیره و خروج"
        >
            <svg
                width="20"
                height="20"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
            >
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>


        <button
            class="topbar-action"
            onclick="adminLogout()"
            title="خروج امن"
        >
            <svg
                width="20"
                height="20"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
            >
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
        </button>

    </div>

</header>


<div
    class="main-content"
    id="mainContent"
>

<div class="admin-command-header">
    <div class="admin-command-brand">
        <div class="admin-command-mark">M</div>
        <div>
            <div class="admin-command-kicker">MELKINO ADMIN</div>
            <div class="admin-command-title">مرکز فرمان مدیریت ملکینو</div>
        </div>
    </div>
    <div class="admin-command-meta">
        <span class="admin-live-dot"></span> سیستم آنلاین
        <span class="admin-command-date" id="adminCommandClock">در حال بررسی…</span>
    </div>
</div>

<div class="tabs-container">

    <button
        class="tab-btn active"
        onclick="switchTab('dashboard')"
    >
        📊 داشبورد
    </button>

    <button
        class="tab-btn"
        onclick="switchTab('ads')"
    >
        📋 آگهی‌ها
    </button>

    <button
        class="tab-btn"
        onclick="switchTab('requests')"
    >
        📩 درخواست‌ها
    </button>

    <button
        class="tab-btn"
        onclick="switchTab('users')"
    >
        👤 کاربران
    </button>

    <button
        class="tab-btn"
        onclick="switchTab('onboarding')"
    >
        🚀 صفحات هدایت
    </button>

    <button
        class="tab-btn"
        onclick="switchTab('global')"
    >
        🧩 عمومی
    </button>

    <button
        class="tab-btn"
        onclick="switchTab('contact')"
    >
        📞 تماس
    </button>

    <button
        class="tab-btn"
        onclick="switchTab('support')"
    >
        🎧 پشتیبانی
    </button>

    <button
        class="tab-btn"
        onclick="switchTab('password')"
    >
        🔑 تغییر رمز
    </button>

</div>


<!-- =========================================================
     DASHBOARD
     ========================================================= -->

<div
    class="tab-content active"
    id="tab-dashboard"
>

    <div class="admin-card">

        <div class="card-header">

            <span class="card-title">
                خلاصه وضعیت سیستم
            </span>

        </div>


        <div
            class="stats-grid"
            id="dashboardStats"
        >

            <div class="stat-card">

                <div
                    class="number"
                    id="dashTotalAds"
                >
                    <?= count($adsData) ?>
                </div>

                <div class="label">
                    کل آگهی‌ها
                </div>

            </div>


            <div class="stat-card">

                <div
                    class="number"
                    id="dashPendingAds"
                >
                    <?= count(array_filter($adsData, fn($a) => ($a['status'] ?? '') === 'pending')) ?>
                </div>

                <div class="label">
                    در انتظار تایید
                </div>

            </div>


            <div class="stat-card">

                <div
                    class="number"
                    id="dashPublishedAds"
                >
                    <?= count(array_filter($adsData, fn($a) => ($a['status'] ?? '') === 'published')) ?>
                </div>

                <div class="label">
                    منتشر شده
                </div>

            </div>


            <div class="stat-card">

                <div
                    class="number"
                    id="dashTotalRequests"
                >
                    <?= count($requestsData) ?>
                </div>

                <div class="label">
                    کل درخواست‌ها
                </div>

            </div>

        </div>

    </div>

    <div class="admin-hero">
        <div class="admin-hero-copy">
            <div class="admin-hero-kicker">MELKINO CONTROL CENTER</div>
            <div class="admin-hero-title">مرکز کنترل ملکینو</div>
            <div class="admin-hero-sub">مدیریت آگهی‌ها، درخواست‌ها، کاربران، مشاوران، امنیت و هویت بصری در یک پنل واحد.</div>
        </div>
        <div class="admin-hero-badge">پنل مدیریت حرفه‌ای</div>
    </div>

    <div class="admin-stat-grid">
        <div class="admin-stat-card accent"><div class="admin-stat-icon">⭐</div><div class="admin-stat-number" id="dashVipAds">0</div><div class="admin-stat-label">فایل‌های VIP</div><div class="admin-stat-note">آگهی‌های ویژه</div></div>
        <div class="admin-stat-card accent"><div class="admin-stat-icon">🎯</div><div class="admin-stat-number" id="dashMatchedRequests">0</div><div class="admin-stat-label">درخواست دارای تطبیق</div><div class="admin-stat-note">درخواست‌هایی که فایل نزدیک دارند</div></div>
        <div class="admin-stat-card accent"><div class="admin-stat-icon">🧩</div><div class="admin-stat-number" id="dashMatchCount">0</div><div class="admin-stat-label">کل تطبیق‌ها</div><div class="admin-stat-note">مجموع فایل‌های پیشنهادی</div></div>
        <div class="admin-stat-card accent"><div class="admin-stat-icon">👥</div><div class="admin-stat-number" id="dashUsers">0</div><div class="admin-stat-label">کاربران ثبت‌شده</div><div class="admin-stat-note">Telegram IDهای ثبت‌شده</div></div>
        <div class="admin-stat-card warning"><div class="admin-stat-icon">👁️</div><div class="admin-stat-number" id="dashVisits24h">0</div><div class="admin-stat-label">بازدید ۲۴ ساعت</div><div class="admin-stat-note">بر اساس tracker فعال</div></div>
        <div class="admin-stat-card"><div class="admin-stat-icon">📈</div><div class="admin-stat-number" id="dashPublishedVip">0</div><div class="admin-stat-label">VIP منتشرشده</div><div class="admin-stat-note">فایل VIP فعال</div></div>
        <div class="admin-stat-card"><div class="admin-stat-icon">📩</div><div class="admin-stat-number" id="dashNewRequests">0</div><div class="admin-stat-label">درخواست‌های اخیر</div><div class="admin-stat-note">۱۰ درخواست آخر</div></div>
        <div class="admin-stat-card danger"><div class="admin-stat-icon">⏳</div><div class="admin-stat-number" id="dashPendingReview">0</div><div class="admin-stat-label">در انتظار بررسی</div><div class="admin-stat-note">آگهی + درخواست</div></div>
        <div class="admin-stat-card danger" style="cursor:pointer;" onclick="switchTab('support')"><div class="admin-stat-icon">🎧</div><div class="admin-stat-number" id="dashSupportOpen">0</div><div class="admin-stat-label">تیکت پشتیبانی باز</div><div class="admin-stat-note" id="dashSupportTotalNote">در انتظار پاسخ</div></div>
    </div>

</div>


<?php require __DIR__ . '/admin-ads.php'; ?>
<?php require __DIR__ . '/admin-requests.php'; ?>
<!-- =========================================================
     USERS
     ========================================================= -->

<div
    class="tab-content"
    id="tab-users"
>

    <div class="admin-card">

        <div class="card-header">

            <span class="card-title">
                مدیریت کاربران
            </span>

        </div>

        <div id="usersListContainer"></div>

    </div>

    <div class="admin-card">

        <div class="card-header">
            <span class="card-title">
                👣 بازدیدهای اخیر سایت (شامل بازدیدکننده‌های ناشناس)
            </span>
            <button type="button" class="btn-secondary" onclick="loadRecentVisits()" style="padding:4px 10px;font-size:12px;">↻ بروزرسانی</button>
        </div>

        <div id="recentVisitsContainer" style="padding:0 16px 16px;"></div>

    </div>

</div>


<!-- =========================================================
     ONBOARDING
     ========================================================= -->

<div
    class="tab-content"
    id="tab-onboarding"
>

    <div class="admin-card">

        <div class="card-header">

            <span class="card-title">
                صفحات هدایت
            </span>

        </div>

        <div id="onboardingEditor"></div>

    </div>

    <!-- =========================================================
         [NEW] کارت جدید: لوگوی اصلی ملکینو
         ========================================================= -->
    <div class="admin-card" style="margin-top: 20px; border: 2px solid var(--primary);">
        <div class="card-header">
            <span class="card-title">🖼️ لوگوی اصلی ملکینو</span>
        </div>
        <div class="card-body" style="display: flex; flex-wrap: wrap; gap: 25px; align-items: center; padding: 15px 0;">
            <!-- ستون پیش‌نمایش -->
            <div style="flex: 0 0 200px; text-align: center;">
                <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px;">پیش‌نمایش فعلی</div>
                <div id="logoPreviewContainer" style="background: #fff; border-radius: 12px; padding: 12px; border: 1px solid var(--border); min-height: 110px; display: flex; align-items: center; justify-content: center;">
                    <img id="logoPreview" src="<?php
                        require_once __DIR__ . '/melkino-logo.php';
                        echo melkinoLogoUrl();
                    ?>" alt="لوگوی ملکینو" style="max-width: 100%; max-height: 100px; object-fit: contain;">
                </div>
                <div id="logoStatus" style="margin-top: 6px; font-size: 0.85rem; color: var(--text-secondary);"></div>
            </div>

            <!-- ستون فرم آپلود -->
            <div style="flex: 1; min-width: 250px;">
                <form id="logoUploadForm" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 14px;">
                    <div>
                        <label for="logoFileInput" style="display: block; margin-bottom: 4px; color: var(--text-primary); font-weight: 500;">انتخاب فایل لوگو</label>
                        <input type="file" id="logoFileInput" accept=".png,.jpg,.jpeg,.webp" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text-primary);">
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">فرمت‌های مجاز: PNG, JPG, JPEG, WEBP – حداکثر ۵ مگابایت</div>
                        <div style="font-size: 12px; color: var(--text-secondary);">💡 پیشنهاد: PNG با پس‌زمینه شفاف</div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <button type="button" id="uploadLogoBtn" class="btn-icon-sm primary" style="background: var(--primary); color: #fff; border: none; padding: 10px 24px; border-radius: 6px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: opacity 0.2s;">
                            ⬆️ آپلود و ذخیره لوگو
                        </button>
                        <span id="fileNameDisplay" style="color: var(--text-secondary); font-size: 0.9rem;"></span>
                    </div>
                </form>
                <div id="uploadProgress" style="display: none; margin-top: 10px;">
                    <span style="color: var(--text-secondary);">در حال آپلود...</span>
                    <div style="width: 100%; height: 6px; background: var(--border); border-radius: 3px; margin-top: 4px; overflow: hidden;">
                        <div id="progressBar" style="width: 0%; height: 100%; background: var(--primary); transition: width 0.3s;"></div>
                    </div>
                </div>
            </div>
        </div>
        <div style="padding: 10px 16px; background: var(--surface); border-top: 1px solid var(--border); font-size: 0.85rem; color: var(--text-secondary); border-radius: 0 0 var(--radius-md) var(--radius-md);">
            💡 لوگوی اصلی سایت از این قسمت مدیریت می‌شود. پس از آپلود، هر صفحه‌ای که از لوگوی مرکزی استفاده کند، همین لوگو را نمایش خواهد داد.
        </div>
    </div>
    <!-- =========================================================
         پایان کارت جدید لوگو
         ========================================================= -->

</div>


<!-- =========================================================
     GLOBAL
     ========================================================= -->

<div
    class="tab-content"
    id="tab-global"
>

    <div class="admin-card">

        <div class="card-header">

            <span class="card-title">
                گزینه‌های عمومی
            </span>

        </div>

        <div id="globalContainer"></div>

    </div>

</div>


<!-- =========================================================
     CONTACT
     ========================================================= -->

<div
    class="tab-content"
    id="tab-contact"
>

    <div class="admin-card">

        <div class="card-header">

            <div>

                <span class="card-title">
                    📞 اطلاعات تماس ملکینو
                </span>

                <div
                    style="
                        font-size:12px;
                        color:var(--text-secondary);
                        margin-top:5px;
                    "
                >
                    اطلاعات این بخش مستقیماً در صفحه «ارتباط با ما» نمایش داده می‌شود.
                </div>

            </div>


            <span
                id="contactSaveState"
                style="
                    font-size:12px;
                    color:var(--text-secondary);
                "
            >
                آماده ویرایش
            </span>

        </div>


        <div id="contactContainer">
<style>
/* =========================================================
   تنظیمات مشاور
   ========================================================= */
.consultant-admin-card{
    margin-top:18px;
    padding:16px;
    border-radius:14px;
    border:1px solid rgba(212,175,55,.22);
    background:
        linear-gradient(145deg,rgba(212,175,55,.055),rgba(6,78,78,.025));
}
.consultant-admin-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    margin-bottom:14px;
    flex-wrap:wrap;
}
.consultant-admin-title{
    font-size:15px;
    font-weight:900;
    color:var(--text-primary);
}
.consultant-admin-help{
    margin-top:4px;
    font-size:11px;
    line-height:1.8;
    color:var(--text-secondary);
}
.consultant-admin-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:11px;
}
.consultant-admin-field{
    display:flex;
    flex-direction:column;
    gap:6px;
}
.consultant-admin-field.full{
    grid-column:1/-1;
}
.consultant-admin-field label{
    font-size:12px;
    color:var(--text-secondary);
    font-weight:700;
}
.consultant-admin-field input{
    width:100%;
    box-sizing:border-box;
    padding:10px 11px;
    border:1px solid var(--border);
    border-radius:9px;
    background:var(--bg);
    color:var(--text-primary);
    font-family:'Vazirmatn',sans-serif;
    font-size:13px;
    outline:none;
}
.consultant-admin-field input:focus{
    border-color:var(--primary);
    box-shadow:0 0 0 2px rgba(6,78,78,.08);
}
.consultant-admin-preview{
    margin-top:14px;
    padding:12px;
    border-radius:11px;
    border:1px solid var(--border);
    background:var(--bg);
}
.consultant-admin-preview-title{
    font-size:12px;
    font-weight:800;
    color:var(--text-primary);
    margin-bottom:9px;
}
.consultant-preview-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:8px;
}
.consultant-preview-item{
    padding:9px 10px;
    border-radius:9px;
    background:var(--surface);
    border:1px solid var(--border);
}
.consultant-preview-item span{
    display:block;
    font-size:10px;
    color:var(--text-secondary);
    margin-bottom:3px;
}
.consultant-preview-item strong{
    display:block;
    font-size:12px;
    color:var(--text-primary);
    word-break:break-word;
}
#consultantSaveState{
    font-size:11px;
    color:var(--text-secondary);
}
@media(max-width:700px){
    .consultant-admin-grid,
    .consultant-preview-grid{
        grid-template-columns:1fr;
    }
}


/* =========================================================
   MELKINO COMMAND CENTER — VISIBLE REDESIGN
   ========================================================= */
.admin-command-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin:0 0 14px;
    padding:14px 18px;
    border-radius:20px;
    background:linear-gradient(135deg,#043b3b 0%,#064e4e 55%,#0a6661 100%);
    border:1px solid rgba(212,175,55,.22);
    box-shadow:0 16px 38px rgba(6,78,78,.18);
    color:#fff;
}
.admin-command-brand{display:flex;align-items:center;gap:11px;min-width:0}
.admin-command-mark{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;background:linear-gradient(145deg,#f0d878,#c89d32);color:#173131;font-size:22px;font-weight:950;box-shadow:0 8px 18px rgba(212,175,55,.22)}
.admin-command-kicker{font-size:9px;letter-spacing:1.4px;opacity:.62;font-weight:900}
.admin-command-title{font-size:17px;font-weight:950;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.admin-command-meta{display:flex;align-items:center;gap:7px;flex:0 0 auto;font-size:10px;font-weight:800;color:rgba(255,255,255,.74)}
.admin-command-date{padding:6px 9px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.10)}
.admin-live-dot{width:7px;height:7px;border-radius:50%;background:#67e8a5;box-shadow:0 0 12px rgba(103,232,165,.7)}
.tabs-container{
    position:sticky !important;
    top:0 !important;
    z-index:120 !important;
    gap:7px !important;
    padding:8px !important;
    border:1px solid var(--border) !important;
    border-radius:18px !important;
    background:color-mix(in srgb,var(--surface) 96%,transparent) !important;
    box-shadow:0 10px 25px rgba(0,0,0,.06) !important;
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
}
.tab-btn{
    min-height:42px !important;
    padding:0 14px !important;
    border:1px solid transparent !important;
    border-bottom:none !important;
    border-radius:12px !important;
    background:transparent !important;
    color:var(--text-secondary) !important;
    font-size:12px !important;
}
.tab-btn:hover{background:var(--bg-secondary) !important;color:var(--primary) !important}
.tab-btn.active{
    background:linear-gradient(135deg,var(--primary),var(--primary-dark)) !important;
    color:#fff !important;
    border-color:transparent !important;
    box-shadow:0 7px 18px rgba(6,78,78,.18) !important;
}
.support-filter-btn.active{background:var(--primary) !important;color:#fff !important;border-color:var(--primary) !important}
.support-status-badge.status-open{background:#FEF3C7 !important;color:#92400E !important}
.support-status-badge.status-answered{background:#DCFCE7 !important;color:#166534 !important}
.support-status-badge.status-pending{background:#DBEAFE !important;color:#1E40AF !important}
.support-status-badge.status-closed{background:#F3F4F6 !important;color:#6B7280 !important}
.admin-static-security{padding:2px 0}
.admin-security-summary{min-height:50px;padding:12px;border:1px solid var(--border);border-radius:12px;background:var(--bg);display:flex;flex-direction:column;justify-content:center;gap:4px}
.admin-security-summary strong{color:var(--primary);font-size:13px}
.admin-security-summary span{color:var(--text-secondary);font-size:10px}
.admin-security-note{margin-top:12px;padding:11px 13px;border-radius:12px;background:var(--gold-bg);color:var(--text-secondary);font-size:10px;line-height:1.9}
@media(max-width:680px){
    .admin-command-header{align-items:flex-start;flex-direction:column}
    .admin-command-title{font-size:15px}
    .admin-command-meta{width:100%;justify-content:space-between}
    .tabs-container{overflow-x:auto;scrollbar-width:none}
    .tabs-container::-webkit-scrollbar{display:none}
    .tab-btn{flex:0 0 auto}
}
[data-theme="dark"] .admin-command-header{box-shadow:0 16px 38px rgba(0,0,0,.32)}
</style>


            <div class="contact-admin-grid">

                <!-- نام مجموعه -->

                <div class="contact-admin-field full">

                    <label for="adminContactAgencyName">
                        نام مجموعه
                    </label>

                    <input
                        type="text"
                        id="adminContactAgencyName"
                        placeholder="مثلاً املاک ملکینو شاهرود"
                    >

                </div>


                <!-- آدرس -->

                <div class="contact-admin-field full">

                    <label for="adminContactAddress">
                        آدرس دفتر
                    </label>

                    <textarea
                        id="adminContactAddress"
                        placeholder="آدرس کامل دفتر ملکینو"
                    ></textarea>

                </div>


                <!-- تلفن -->

                <div class="contact-admin-field">

                    <label for="adminContactPhone">
                        شماره تماس
                    </label>

                    <input
                        type="text"
                        id="adminContactPhone"
                        placeholder="مثلاً ۰۲۳-۳۲۲۲۲۲۲۲"
                        dir="ltr"
                    >

                </div>


                <!-- ایمیل -->

                <div class="contact-admin-field">

                    <label for="adminContactEmail">
                        ایمیل پشتیبانی
                    </label>

                    <input
                        type="email"
                        id="adminContactEmail"
                        placeholder="info@melkino.ir"
                        dir="ltr"
                    >

                </div>


                <!-- ساعت کاری -->

                <div class="contact-admin-field full">

                    <label for="adminContactWorkingHours">
                        ساعت کاری
                    </label>

                    <input
                        type="text"
                        id="adminContactWorkingHours"
                        placeholder="شنبه تا پنجشنبه، ۹ صبح تا ۸ شب"
                    >

                </div>


                <!-- واتساپ -->

                <div class="contact-admin-field">

                    <label for="adminContactWhatsapp">
                        واتساپ
                    </label>

                    <input
                        type="text"
                        id="adminContactWhatsapp"
                        placeholder="شماره یا لینک واتساپ"
                        dir="ltr"
                    >

                </div>


                <!-- تلگرام -->

                <div class="contact-admin-field">

                    <label for="adminContactTelegram">
                        لینک کانال تلگرام
                    </label>

                    <input
                        type="url"
                        id="adminContactTelegram"
                        placeholder="https://t.me/..."
                        dir="ltr"
                    >

                    <div class="contact-admin-help">
                        مثال:
                        https://t.me/melkino
                    </div>

                </div>


                <!-- اینستاگرام -->

                <div class="contact-admin-field">

                    <label for="adminContactInstagram">
                        لینک اینستاگرام
                    </label>

                    <input
                        type="url"
                        id="adminContactInstagram"
                        placeholder="https://instagram.com/..."
                        dir="ltr"
                    >

                    <div class="contact-admin-help">
                        مثال:
                        https://instagram.com/melkino
                    </div>

                </div>


                <!-- لینکدین -->

                <div class="contact-admin-field">

                    <label for="adminContactLinkedin">
                        لینک لینکدین
                    </label>

                    <input
                        type="url"
                        id="adminContactLinkedin"
                        placeholder="https://linkedin.com/..."
                        dir="ltr"
                    >

                </div>


                <!-- نقشه -->

                <div class="contact-admin-field full">

                    <label for="adminContactMapUrl">
                        لینک نقشه / Embed Map
                    </label>

                    <textarea
                        id="adminContactMapUrl"
                        placeholder="لینک Embed نقشه را اینجا وارد کنید..."
                        dir="ltr"
                    ></textarea>

                    <div class="contact-admin-help">
                        لینک Embed نقشه دفتر را وارد کنید تا در صفحه ارتباط با ما نمایش داده شود.
                    </div>

                </div>

            </div>


            <!-- =====================================================
                 مدیریت حرفه‌ای مشاوران
                 ===================================================== -->
            <div class="consultant-admin-card" style="border-style:solid;">
                <div class="consultants-toolbar">
                    <div>
                        <div class="consultant-admin-title">👥 مدیریت مشاوران</div>
                        <div class="consultant-admin-help">حداکثر ۱۰ مشاور، برای هر مشاور حداکثر ۱۰ تخصص؛ هر تخصص با نوع ملک + نوع معامله تعریف می‌شود. اگر چند مشاور یک تخصص را داشته باشند، اولویت کمتر برنده است و در نبود مشاور تخصصی، مشاور پیش‌فرض استفاده می‌شود.</div>
                    </div>
                    <button type="button" class="btn-icon-sm gold" onclick="addConsultant()">＋ افزودن مشاور</button>
                </div>
                <div id="consultantsManager" class="consultant-manager-list"></div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                    <button type="button" class="btn-icon-sm primary" onclick="saveConsultants()" style="min-width:210px;height:46px;font-size:14px;">💾 ذخیره همه مشاوران</button>
                    <button type="button" class="btn-secondary" onclick="loadConsultants()" style="min-height:46px;">↻ بازیابی</button>
                    <span id="consultantsSaveState" style="align-self:center;font-size:11px;color:var(--text-secondary);"></span>
                </div>
            </div>

            <!-- Preview -->

            <div class="contact-admin-preview">

                <div class="contact-admin-preview-title">
                    پیش‌نمایش اطلاعات تماس
                </div>


                <div class="contact-preview-grid">

                    <div>

                        <span>
                            نام مجموعه
                        </span>

                        <strong id="previewAgencyName">
                            -
                        </strong>

                    </div>


                    <div>

                        <span>
                            تلفن
                        </span>

                        <strong id="previewPhone">
                            -
                        </strong>

                    </div>


                    <div>

                        <span>
                            تلگرام
                        </span>

                        <strong id="previewTelegram">
                            ثبت نشده
                        </strong>

                    </div>


                    <div>

                        <span>
                            اینستاگرام
                        </span>

                        <strong id="previewInstagram">
                            ثبت نشده
                        </strong>

                    </div>

                </div>

            </div>


            <div class="contact-save-note">

                تغییرات این بخش با دکمه زیر ذخیره می‌شوند و صفحه «ارتباط با ما»
                به صورت خودکار اطلاعات ذخیره‌شده را نمایش می‌دهد.

            </div>


            <div
                style="
                    display:flex;
                    gap:10px;
                    flex-wrap:wrap;
                    margin-top:18px;
                "
            >

                <button
                    type="button"
                    class="btn-icon-sm primary"
                    onclick="saveContactSettings()"
                    style="
                        min-width:190px;
                        height:46px;
                        font-size:14px;
                    "
                >
                    💾 ذخیره اطلاعات تماس
                </button>


                <button
                    type="button"
                    class="btn-secondary"
                    onclick="loadContactSettings()"
                    style="min-height:46px;"
                >
                    ↻ بازیابی اطلاعات ذخیره‌شده
                </button>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     SUPPORT
     ========================================================= -->

<div
    class="tab-content"
    id="tab-support"
>

    <div class="admin-card">

        <div class="card-header">
            <span class="card-title">🎧 تیکت‌های پشتیبانی</span>
            <span id="supportTicketCount" class="admin-section-help"></span>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap; padding:0 16px 12px;">
            <button type="button" class="btn-secondary support-filter-btn active" data-status="all" onclick="filterSupportTickets('all', this)">همه</button>
            <button type="button" class="btn-secondary support-filter-btn" data-status="open" onclick="filterSupportTickets('open', this)">در انتظار بررسی</button>
            <button type="button" class="btn-secondary support-filter-btn" data-status="answered" onclick="filterSupportTickets('answered', this)">پاسخ داده‌شده</button>
            <button type="button" class="btn-secondary support-filter-btn" data-status="closed" onclick="filterSupportTickets('closed', this)">بسته‌شده</button>
            <button type="button" class="btn-secondary" onclick="loadSupportTickets()" style="margin-inline-start:auto;">↻ بروزرسانی</button>
        </div>

        <div id="supportTicketsList" style="padding:0 16px 16px;">در حال بارگذاری…</div>

    </div>

    <div class="admin-card" id="supportConversationCard" style="display:none;">

        <div class="card-header">
            <span class="card-title" id="supportConversationTitle">گفتگو</span>
            <div style="display:flex; gap:8px;">
                <button type="button" class="btn-secondary" id="supportCloseBtn" onclick="setSupportTicketStatus('close')">بستن تیکت</button>
                <button type="button" class="btn-secondary" id="supportReopenBtn" onclick="setSupportTicketStatus('reopen')" style="display:none;">بازگشایی تیکت</button>
                <button type="button" class="btn-secondary" onclick="document.getElementById('supportConversationCard').style.display='none';">✕ بستن</button>
            </div>
        </div>

        <div id="supportMessages" style="padding:16px; display:flex; flex-direction:column; gap:10px; max-height:420px; overflow-y:auto;"></div>

        <div style="display:flex; gap:8px; padding:16px; border-top:1px solid var(--border);">
            <textarea id="supportReplyText" rows="2" placeholder="پاسخ خود را بنویسید..." style="flex:1; resize:vertical; border:1px solid var(--border); border-radius:8px; padding:10px; font-family:inherit; background:var(--bg); color:var(--text-primary);"></textarea>
            <button type="button" class="btn-primary" onclick="sendSupportReply()">ارسال پاسخ</button>
        </div>

    </div>

</div>


<!-- =========================================================
     PASSWORD
     ========================================================= -->

<div
    class="tab-content"
    id="tab-password"
>

    <div class="admin-card">

        <div class="card-header">

            <span class="card-title">
                تغییر رمز
            </span>

        </div>

        <div id="passwordContainer">
    <div class="admin-static-security">
        <div class="admin-section-head">
            <div>
                <div class="admin-section-title">🔐 امنیت پنل مدیریت</div>
                <div class="admin-section-help">رمز عبور، نشست ادمین و محافظت از ورود از همین بخش مدیریت می‌شود.</div>
            </div>
        </div>
        <div class="admin-grid-2">
            <div class="admin-field"><label>رمز فعلی</label><input type="password" placeholder="رمز فعلی" autocomplete="current-password"></div>
            <div class="admin-field"><label>رمز جدید</label><input type="password" placeholder="حداقل ۸ کاراکتر" autocomplete="new-password"></div>
            <div class="admin-field"><label>تکرار رمز جدید</label><input type="password" placeholder="تکرار رمز جدید" autocomplete="new-password"></div>
            <div class="admin-security-summary"><strong>امنیت فعال</strong><span>قفل ورود • لاگ ورود • Timeout نشست</span></div>
        </div>
        <div class="admin-security-note">برای مدیریت کامل تنظیمات امنیتی، تب تغییر رمز پس از بارگذاری JavaScript تکمیل می‌شود.</div>
    </div>
</div>

    </div>

</div>


<button
    class="btn-save"
    onclick="saveAndExit()"
>
    💾 ذخیره تغییرات و بازگشت به خانه
</button>

</div>
</div>


<?php require __DIR__ . '/admin-ads-modals.php'; ?>
                display:flex;
                flex-direction:column;
                gap:var(--space-2);
            "
        ></div>

    </div>

</div>


<script>

// ==============================================
// داده‌های اولیه
// ==============================================

let adsData =
    <?php
    echo json_encode(
        $adsData,
        JSON_UNESCAPED_UNICODE
    );
    ?>;

let requestsData =
    <?php
    echo json_encode(
        $requestsData,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
    ?>;

const isMockMode =
    <?php echo $isMockMode ? 'true' : 'false'; ?>;

let currentAdFilter = 'all';

let appData = {};


// ==============================================
// اطلاعات تماس
// مستقل از سیستم آگهی‌ها و درخواست‌ها
// ==============================================

const MELKINO_CONTACT_STORAGE_KEY =
    'melkino_contact_info';


function getDefaultContactSettings() {

    return {

        agencyName:
            'املاک ملکینو شاهرود',

        address:
            '',

        phone:
            '',

        email:
            '',

        whatsapp:
            '',

        telegram:
            '',

        instagram:
            '',

        linkedin:
            '',

        mapUrl:
            '',

        workingHours:
            ''
    };
}


function loadContactSettingsData() {

    const defaults =
        getDefaultContactSettings();


    const saved =
        localStorage.getItem(
            MELKINO_CONTACT_STORAGE_KEY
        );


    if (!saved) {
        return defaults;
    }


    try {

        const parsed =
            JSON.parse(saved);


        if (
            !parsed ||
            typeof parsed !== 'object'
        ) {
            return defaults;
        }


        return {
            ...defaults,
            ...parsed
        };

    } catch (error) {

        console.error(
            'خطا در خواندن اطلاعات تماس:',
            error
        );

        return defaults;
    }
}


function setContactFieldValue(
    id,
    value
) {

    const element =
        document.getElementById(id);


    if (!element) {
        return;
    }


    element.value =
        value ?? '';
}


function getContactFieldValue(id) {

    const element =
        document.getElementById(id);


    if (!element) {
        return '';
    }


    return element.value.trim();
}


function updateContactPreview() {

    const agencyName =
        getContactFieldValue(
            'adminContactAgencyName'
        );

    const phone =
        getContactFieldValue(
            'adminContactPhone'
        );

    const telegram =
        getContactFieldValue(
            'adminContactTelegram'
        );

    const instagram =
        getContactFieldValue(
            'adminContactInstagram'
        );


    const previewAgencyName =
        document.getElementById(
            'previewAgencyName'
        );

    const previewPhone =
        document.getElementById(
            'previewPhone'
        );

    const previewTelegram =
        document.getElementById(
            'previewTelegram'
        );

    const previewInstagram =
        document.getElementById(
            'previewInstagram'
        );


    if (previewAgencyName) {

        previewAgencyName.textContent =
            agencyName || '-';
    }


    if (previewPhone) {

        previewPhone.textContent =
            phone || '-';
    }


    if (previewTelegram) {

        previewTelegram.textContent =
            telegram ||
            'ثبت نشده';
    }


    if (previewInstagram) {

        previewInstagram.textContent =
            instagram ||
            'ثبت نشده';
    }
}


function loadContactSettings() {

    const data =
        loadContactSettingsData();


    setContactFieldValue(
        'adminContactAgencyName',
        data.agencyName
    );

    setContactFieldValue(
        'adminContactAddress',
        data.address
    );

    setContactFieldValue(
        'adminContactPhone',
        data.phone
    );

    setContactFieldValue(
        'adminContactEmail',
        data.email
    );

    setContactFieldValue(
        'adminContactWorkingHours',
        data.workingHours
    );

    setContactFieldValue(
        'adminContactWhatsapp',
        data.whatsapp
    );

    setContactFieldValue(
        'adminContactTelegram',
        data.telegram
    );

    setContactFieldValue(
        'adminContactInstagram',
        data.instagram
    );

    setContactFieldValue(
        'adminContactLinkedin',
        data.linkedin
    );

    setContactFieldValue(
        'adminContactMapUrl',
        data.mapUrl
    );


    updateContactPreview();


    const state =
        document.getElementById(
            'contactSaveState'
        );


    if (state) {

        state.textContent =
            'اطلاعات ذخیره‌شده بارگذاری شد';

        state.style.color =
            'var(--text-secondary)';
    }
}


function saveContactSettings() {

    const telegram =
        getContactFieldValue(
            'adminContactTelegram'
        );

    const instagram =
        getContactFieldValue(
            'adminContactInstagram'
        );


    const contactData = {

        agencyName:
            getContactFieldValue(
                'adminContactAgencyName'
            ),

        address:
            getContactFieldValue(
                'adminContactAddress'
            ),

        phone:
            getContactFieldValue(
                'adminContactPhone'
            ),

        email:
            getContactFieldValue(
                'adminContactEmail'
            ),

        whatsapp:
            getContactFieldValue(
                'adminContactWhatsapp'
            ),

        telegram:
            telegram,

        instagram:
            instagram,

        linkedin:
            getContactFieldValue(
                'adminContactLinkedin'
            ),

        mapUrl:
            getContactFieldValue(
                'adminContactMapUrl'
            ),

        workingHours:
            getContactFieldValue(
                'adminContactWorkingHours'
            )
    };


    if (
        telegram &&
        !/^https?:\/\//i.test(telegram)
    ) {

        alert(
            '⚠️ لینک تلگرام باید با http:// یا https:// شروع شود.'
        );

        return;
    }


    if (
        instagram &&
        !/^https?:\/\//i.test(instagram)
    ) {

        alert(
            '⚠️ لینک اینستاگرام باید با http:// یا https:// شروع شود.'
        );

        return;
    }


    try {

        localStorage.setItem(
            MELKINO_CONTACT_STORAGE_KEY,
            JSON.stringify(
                contactData
            )
        );


        updateContactPreview();


        const state =
            document.getElementById(
                'contactSaveState'
            );


        if (state) {

            state.textContent =
                '✅ اطلاعات با موفقیت ذخیره شد';

            state.style.color =
                '#059669';
        }


        alert(
            '✅ اطلاعات تماس ملکینو با موفقیت ذخیره شد.'
        );


    } catch (error) {

        console.error(
            'خطا در ذخیره اطلاعات تماس:',
            error
        );


        alert(
            '❌ ذخیره اطلاعات تماس انجام نشد.'
        );
    }
}


function bindContactLivePreview() {

    const fieldIds = [

        'adminContactAgencyName',
        'adminContactAddress',
        'adminContactPhone',
        'adminContactEmail',
        'adminContactWorkingHours',
        'adminContactWhatsapp',
        'adminContactTelegram',
        'adminContactInstagram',
        'adminContactLinkedin',
        'adminContactMapUrl'

    ];


    fieldIds.forEach(function (id) {

        const element =
            document.getElementById(id);


        if (!element) {
            return;
        }


        element.addEventListener(
            'input',
            updateContactPreview
        );
    });
}


// ==============================================
// مدیریت حرفه‌ای مشاوران
// ==============================================

const CONSULTANT_TYPES = ['آپارتمان','ویلا','زمین','باغ','تجاری','اداری','مغازه'];
const CONSULTANT_TRANSACTIONS = ['فروش','اجاره','رهن کامل','رهن و اجاره','پیش فروش'];
let consultantsData = [];

function consultantEsc(value){
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
}

function consultantDefaultItem(index){
    return {id:'c'+(index+1),name:'',phone:'',telegram_username:'',telegram_link:'',active:true,is_default:false,priority:index+1,specialties:[]};
}

async function loadConsultants(){
    const state=document.getElementById('consultantsSaveState');
    if(state) state.textContent='در حال بارگذاری...';
    try{
        const response=await fetch('save_consultants.php?action=list&t='+Date.now(),{cache:'no-store'});
        const result=await response.json();
        if(!response.ok || !result.success) throw new Error(result.message||'خطا در بارگذاری مشاوران');
        consultantsData=Array.isArray(result.consultants)?result.consultants:[];
    }catch(error){
        consultantsData=[{id:'c1',name:'مشاور پیش‌فرض ملکینو',phone:'',telegram_username:'',telegram_link:'',active:true,is_default:true,priority:1,specialties:[]}];
        if(state) state.textContent='مشاور پیش‌فرض آماده ثبت است';
    }
    renderConsultants();
    if(state && !state.textContent) state.textContent='';
}

function addConsultant(){
    if(consultantsData.length>=10){alert('حداکثر ۱۰ مشاور مجاز است.');return;}
    consultantsData.push(consultantDefaultItem(consultantsData.length));
    if(!consultantsData.some(x=>x.is_default)) consultantsData[consultantsData.length-1].is_default=true;
    renderConsultants();
}

function removeConsultant(index){
    if(consultantsData.length<=1){alert('حداقل یک مشاور باید باقی بماند.');return;}
    const removed=consultantsData.splice(index,1)[0];
    if(removed?.is_default && consultantsData[0]) consultantsData[0].is_default=true;
    renderConsultants();
}

function updateConsultant(index,key,value){
    if(!consultantsData[index]) return;
    consultantsData[index][key]=value;
    if(key==='is_default' && value){consultantsData.forEach((c,i)=>{if(i!==index)c.is_default=false;});}
}

function addConsultantSpecialty(index){
    const c=consultantsData[index]; if(!c)return;
    c.specialties=Array.isArray(c.specialties)?c.specialties:[];
    if(c.specialties.length>=10){alert('برای هر مشاور حداکثر ۱۰ تخصص مجاز است.');return;}
    const pt=document.getElementById('spec-p-'+index)?.value||'';
    const tt=document.getElementById('spec-t-'+index)?.value||'';
    if(!pt||!tt){alert('نوع ملک و نوع معامله را انتخاب کنید.');return;}
    if(c.specialties.some(s=>s.property_type===pt&&s.transaction_type===tt)){alert('این ترکیب قبلاً برای مشاور ثبت شده است.');return;}
    c.specialties.push({property_type:pt,transaction_type:tt});
    renderConsultants();
}

function removeConsultantSpecialty(index,sIndex){
    if(!consultantsData[index])return;
    consultantsData[index].specialties.splice(sIndex,1);
    renderConsultants();
}

function renderConsultants(){
    const container=document.getElementById('consultantsManager'); if(!container)return;
    if(!consultantsData.length){container.innerHTML='<div class="consultant-empty">هنوز مشاوری ثبت نشده است.</div>';return;}
    container.innerHTML=consultantsData.map((c,i)=>{
        const specs=Array.isArray(c.specialties)?c.specialties:[];
        return `<div class="consultant-manager-card">
            <div class="consultant-manager-head">
                <div class="consultant-manager-title"><span>👤</span> مشاور ${i+1} ${c.is_default?'<span class="consultant-default-pill">پیش‌فرض</span>':''}</div>
                <div class="consultant-manager-actions">
                    <button type="button" class="btn-icon-sm" onclick="removeConsultant(${i})">🗑 حذف</button>
                </div>
            </div>
            <div style="padding:14px;display:grid;gap:11px;">
                <div class="admin-grid-2">
                    <div class="admin-field"><label>نام مشاور</label><input value="${consultantEsc(c.name)}" oninput="updateConsultant(${i},'name',this.value)"></div>
                    <div class="admin-field"><label>شماره تماس</label><input dir="ltr" inputmode="tel" value="${consultantEsc(c.phone)}" oninput="updateConsultant(${i},'phone',this.value)"></div>
                    <div class="admin-field"><label>اکانت تلگرام</label><input dir="ltr" placeholder="@username" value="${consultantEsc(c.telegram_username)}" oninput="updateConsultant(${i},'telegram_username',this.value)"></div>
                    <div class="admin-field"><label>لینک مستقیم تلگرام</label><input dir="ltr" placeholder="https://t.me/..." value="${consultantEsc(c.telegram_link)}" oninput="updateConsultant(${i},'telegram_link',this.value)"></div>
                    <div class="admin-field"><label>اولویت</label><input type="number" min="1" max="100" value="${Number(c.priority||i+1)}" oninput="updateConsultant(${i},'priority',Math.max(1,Math.min(100,parseInt(this.value||1,10))))"></div>
                    <div class="security-switch"><div><span>فعال باشد</span><small>مشاور غیرفعال در انتخاب خودکار وارد نمی‌شود.</small></div><input type="checkbox" ${c.active!==false?'checked':''} onchange="updateConsultant(${i},'active',this.checked)"></div>
                    <div class="security-switch"><div><span>مشاور پیش‌فرض</span><small>Fallback برای ترکیب بدون مشاور تخصصی.</small></div><input type="checkbox" ${c.is_default?'checked':''} onchange="updateConsultant(${i},'is_default',this.checked);renderConsultants()"></div>
                </div>
                <div>
                    <div class="admin-section-help" style="margin-bottom:7px;">تخصص‌ها (${specs.length}/10)</div>
                    <div class="consultant-specialties">${specs.length?specs.map((sp,si)=>`<span class="consultant-specialty">${consultantEsc(sp.property_type)} + ${consultantEsc(sp.transaction_type)} <button type="button" onclick="removeConsultantSpecialty(${i},${si})">✕</button></span>`).join(''):'<span class="admin-section-help">تخصصی ثبت نشده است.</span>'}</div>
                    <div class="consultant-add-specialty">
                        <select id="spec-p-${i}" class="form-select">${CONSULTANT_TYPES.map(x=>`<option value="${consultantEsc(x)}">${consultantEsc(x)}</option>`).join('')}</select>
                        <select id="spec-t-${i}" class="form-select">${CONSULTANT_TRANSACTIONS.map(x=>`<option value="${consultantEsc(x)}">${consultantEsc(x)}</option>`).join('')}</select>
                        <button type="button" class="btn-icon-sm gold" onclick="addConsultantSpecialty(${i})">＋ افزودن تخصص</button>
                    </div>
                </div>
            </div>
        </div>`;
    }).join('');
}

async function saveConsultants(){
    const state=document.getElementById('consultantsSaveState');
    const cleaned=consultantsData.map((c,i)=>({...c,id:c.id||('c'+(i+1)),priority:Number(c.priority||i+1),specialties:(Array.isArray(c.specialties)?c.specialties:[]).slice(0,10)})).slice(0,10);
    if(!cleaned.some(c=>c.is_default) && cleaned[0]) cleaned[0].is_default=true;
    if(state){state.textContent='⏳ در حال ذخیره...';state.style.color='var(--text-secondary)';}
    try{
        const response=await fetch('save_consultants.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({consultants:cleaned})});
        const result=await response.json();
        if(!response.ok||!result.success)throw new Error(result.message||'ذخیره مشاوران انجام نشد.');
        consultantsData=result.consultants||cleaned; renderConsultants();
        if(state){state.textContent='✅ ذخیره شد';state.style.color='var(--success)';}
    }catch(error){if(state){state.textContent='❌ '+error.message;state.style.color='var(--danger)';}alert('❌ '+error.message);}
}

// ==============================================
// توابع عمومی
// ==============================================

function closeModal(id) {

    const element =
        document.getElementById(id);

    if (element) {
        element.classList.remove(
            'active'
        );
    }
}


async function saveAdsToFile() {

    try {

        const response =
            await fetch(
                window.location.pathname + '?ad_db_action=bulk_sync',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    cache: 'no-store',
                    body: JSON.stringify(adsData)
                }
            );

        // پاسخ را ابتدا text می‌گیریم تا Warning/HTML باعث SyntaxError نشود.
        const raw = await response.text();
        const cleaned = String(raw || '').trim();

        console.log('[Melkino] admin bulk_sync response:', cleaned);

        let result = null;

        // حالت عادی: JSON خالص
        if (cleaned !== '') {
            try {
                result = JSON.parse(cleaned);
            } catch (jsonError) {
                // اگر PHP قبل از JSON Warning/Notice چاپ کرده باشد، بخش JSON را جدا کن.
                const firstBrace = cleaned.indexOf('{');
                const lastBrace = cleaned.lastIndexOf('}');

                if (firstBrace >= 0 && lastBrace > firstBrace) {
                    const possibleJson = cleaned.slice(firstBrace, lastBrace + 1);
                    try {
                        result = JSON.parse(possibleJson);
                    } catch (innerError) {
                        result = null;
                    }
                }
            }
        }

        // اگر PHP به‌خاطر عبور حجم درخواست از post_max_size خودِ php.ini
        // هشدار خام (HTML) چاپ کرده باشد، به‌جای نمایش آن متن گیج‌کننده،
        // یک پیام فارسی و قابل‌فهم نشان می‌دهیم.
        if (/Content-Length of \d+ bytes exceeds the limit/i.test(cleaned)) {
            throw new Error(
                'حجم اطلاعاتی که برای ذخیره ارسال شد بیشتر از سقف مجاز سرور (post_max_size در php.ini) است.\n' +
                'یا تعداد آگهی‌هایی که هم‌زمان ویرایش می‌کنید را کم کنید، یا از ادمین سرور بخواهید post_max_size و upload_max_filesize را در php.ini افزایش دهد و Apache را ری‌استارت کند.'
            );
        }

        // خطای HTTP
        if (!response.ok) {
            const serverMessage =
                result && (result.error || result.message || result.details)
                    ? (result.error || result.message || result.details)
                    : cleaned.substring(0, 1200);

            throw new Error(
                serverMessage ||
                ('خطای سرور HTTP ' + response.status)
            );
        }

        // پاسخ موفق HTTP ولی غیر JSON
        if (!result || typeof result !== 'object') {
            console.error('[Melkino] Invalid JSON response:', cleaned);

            throw new Error(
                'پاسخ سرور JSON معتبر نیست.\n\n' +
                cleaned.substring(0, 1200)
            );
        }

        // JSON معتبر ولی عملیات ناموفق
        if (result.success !== true) {
            throw new Error(
                result.error ||
                result.message ||
                result.details ||
                'ذخیره تغییرات انجام نشد.'
            );
        }

        return true;

    } catch (e) {

        console.error('[Melkino] saveAdsToFile error:', e);

        alert(
            '❌ خطا در ذخیره تغییرات:\n\n' +
            (e && e.message ? e.message : String(e))
        );

        return false;
    }
}


async function loadRecentVisits(){
    const container=document.getElementById('recentVisitsContainer'); if(!container)return;
    container.innerHTML='<div class="consultant-empty">در حال بارگذاری بازدیدها...</div>';
    try{
        const r=await fetch('page-visits.php?action=list',{cache:'no-store'}); const data=await r.json();
        const visits=Array.isArray(data.visits)?data.visits:[];
        if(!visits.length){container.innerHTML='<div class="consultant-empty">هنوز بازدیدی ثبت نشده.</div>';return;}
        container.innerHTML=`<div class="table-wrap"><table class="users-table"><thead><tr><th>تاریخ و ساعت</th><th>صفحه</th><th>Telegram ID</th><th>IP</th></tr></thead><tbody>${
            visits.map(v=>`<tr><td>${escapeHtml(v.visited_at||'—')}</td><td dir="ltr">${escapeHtml(v.path||'—')}</td><td dir="ltr">${escapeHtml(v.telegram_id||'ناشناس')}</td><td dir="ltr">${escapeHtml(v.ip_address||'—')}</td></tr>`).join('')
        }</tbody></table></div>`;
    }catch(e){container.innerHTML='<div class="consultant-empty">خطا در بارگذاری بازدیدها.</div>';}
}

async function saveAndExit() {

    try {

        const saved = await saveAdsToFile();
        if (!saved) return;
        alert('✅ تغییرات ذخیره شد!');

        window.location.href =
            'home.php';

    } catch(e) {

        alert(
            '❌ خطا'
        );
    }
}



// ==============================================
// کاربران
// ==============================================
async function loadAdminUsers(){
    const container=document.getElementById('usersListContainer'); if(!container)return;
    container.innerHTML='<div class="consultant-empty">در حال بارگذاری کاربران...</div>';
    try{
        const r=await fetch('identity-sync.php?action=list',{cache:'no-store'}); const data=await r.json();
        const users=Array.isArray(data.users)?data.users:[];
        if(!users.length){container.innerHTML='<div class="consultant-empty">هنوز کاربری در لاگ ورود ثبت نشده است.</div>';return;}
        users.sort((a,b)=>String(b.last_login||'').localeCompare(String(a.last_login||'')));
        container.innerHTML=`<div class="table-wrap"><table class="users-table"><thead><tr><th>وضعیت</th><th>Telegram ID</th><th>Username</th><th>نام</th><th>شماره تماس</th><th>اولین ورود</th><th>آخرین ورود</th><th>تعداد ورود</th><th></th></tr></thead><tbody>${
            users.map(u=>{
                const active = Number(u.is_active ?? 1) !== 0;
                return `<tr>
                    <td><span class="user-status-dot" style="background:${active?'#4ADE80':'#7F8A87'}"></span>${active?'فعال':'غیرفعال'}</td>
                    <td dir="ltr">${escapeHtml(u.telegram_id||'—')}</td>
                    <td dir="ltr">${escapeHtml(u.username||'—')}</td>
                    <td>${escapeHtml(u.name||'—')}</td>
                    <td dir="ltr">${escapeHtml(u.phone||'—')}</td>
                    <td>${escapeHtml(u.first_login||u.created_at||'—')}</td>
                    <td>${escapeHtml(u.last_login||'—')}</td>
                    <td>${Number(u.login_count||0)}</td>
                    <td><button type="button" class="btn-secondary" style="padding:4px 10px;font-size:12px;" onclick="toggleUserHistory(${Number(u.id)}, this)">📜 تاریخچه</button></td>
                </tr>
                <tr id="userHistoryRow${Number(u.id)}" style="display:none;">
                    <td colspan="9"><div id="userHistoryBox${Number(u.id)}" style="padding:10px;font-size:12px;"></div></td>
                </tr>`;
            }).join('')
        }</tbody></table></div>`;
    }catch(e){container.innerHTML='<div class="consultant-empty">خطا در بارگذاری کاربران.</div>';}
}

async function toggleUserHistory(userId, btn){
    const row = document.getElementById('userHistoryRow'+userId);
    if (!row) return;

    if (row.style.display !== 'none') {
        row.style.display = 'none';
        return;
    }

    row.style.display = '';
    const box = document.getElementById('userHistoryBox'+userId);
    box.innerHTML = 'در حال بارگذاری تاریخچه...';

    try {
        const r = await fetch('identity-sync.php?action=history&user_id='+userId, {cache:'no-store'});
        const data = await r.json();
        const events = Array.isArray(data.events) ? data.events : [];
        if (!events.length) { box.innerHTML = 'رخدادی برای این کاربر ثبت نشده.'; return; }
        box.innerHTML = '<table class="users-table" style="width:100%;"><thead><tr><th>تاریخ و ساعت ورود</th><th>IP</th></tr></thead><tbody>' +
            events.map(e => `<tr><td>${escapeHtml(e.created_at||'—')}</td><td dir="ltr">${escapeHtml(e.ip_address||'—')}</td></tr>`).join('') +
            '</tbody></table>';
    } catch (e) {
        box.innerHTML = 'خطا در بارگذاری تاریخچه.';
    }
}

// ==============================================
// تنظیمات عمومی
// ==============================================
async function loadGlobalSettings(){
    const c=document.getElementById('globalContainer'); if(!c)return;
    try{const r=await fetch('save_global_settings.php?action=get',{cache:'no-store'});const j=await r.json();renderGlobalSettings(j.settings||{});}catch(e){renderGlobalSettings({});}
}
function renderGlobalSettings(d){
    const c=document.getElementById('globalContainer'); if(!c)return;
    const bool=(key,label,help)=>`<div class="security-switch"><div><span>${label}</span><small>${help}</small></div><input id="g_${key}" type="checkbox" ${d[key]!==false?'checked':''}></div>`;
    c.innerHTML=`<div class="admin-section-head"><div><div class="admin-section-title">⚙️ تنظیمات عمومی</div><div class="admin-section-help">این تنظیمات رفتار عمومی ملکینو را کنترل می‌کنند.</div></div><span id="globalSaveState" class="admin-section-help"></span></div><div class="admin-grid-2"><div class="admin-field"><label>نام سایت</label><input id="g_site_name" value="${escapeHtml(d.site_name||'ملکینو')}"></div><div class="admin-field"><label>شهر</label><input id="g_city" value="${escapeHtml(d.city||'شاهرود')}"></div><div class="admin-field full"><label>شعار</label><input id="g_slogan" value="${escapeHtml(d.slogan||'ملکینو؛ انتخابی فراتر از یک ملک')}"></div><div class="admin-field"><label>تعداد فایل در هر صفحه</label><input id="g_items_per_page" type="number" min="4" max="100" value="${Number(d.items_per_page||12)}"></div><div class="admin-field"><label>تم پیش‌فرض</label><select id="g_default_theme"><option value="light" ${(d.default_theme||'light')==='light'?'selected':''}>روشن</option><option value="dark" ${d.default_theme==='dark'?'selected':''}>تیره</option></select></div></div><div class="password-security-grid" style="margin-top:12px">${bool('show_prices','نمایش قیمت‌ها','قیمت در کارت‌ها و صفحات عمومی نمایش داده شود.')}${bool('enable_favorites','علاقه‌مندی‌ها','قابلیت ذخیره آگهی برای کاربر فعال باشد.')}${bool('enable_property_requests','ثبت درخواست ملک','فرم درخواست برای کاربران فعال باشد.')}${bool('enable_notifications','اعلان‌ها','اعلان‌های تطبیق و رویدادها فعال باشند.')}${bool('maintenance_mode','حالت تعمیرات','سایت در حالت محدود قرار بگیرد.')}</div><div style="display:flex;gap:8px;margin-top:14px"><button type="button" id="globalSaveButton" class="btn-icon-sm primary">💾 ذخیره تنظیمات عمومی</button></div>`;

    const saveButton = document.getElementById('globalSaveButton');
    if (saveButton) {
        saveButton.addEventListener('click', saveGlobalSettings);
    }

}
async function saveGlobalSettings(){
    const ids=['site_name','city','slogan','items_per_page','default_theme','show_prices','enable_favorites','enable_property_requests','enable_notifications','maintenance_mode'];
    const els=Object.fromEntries(ids.map(k=>[k,document.getElementById('g_'+k)]));
    const state=document.getElementById('globalSaveState');

    if (Object.values(els).some(el=>!el)) {
        if (state) state.textContent='❌ فیلدهای تنظیمات پیدا نشد';
        return;
    }

    const payload={
        site_name:els.site_name.value.trim(),
        city:els.city.value.trim(),
        slogan:els.slogan.value.trim(),
        items_per_page:parseInt(els.items_per_page.value||12,10),
        default_theme:els.default_theme.value,
        show_prices:els.show_prices.checked,
        enable_favorites:els.enable_favorites.checked,
        enable_property_requests:els.enable_property_requests.checked,
        enable_notifications:els.enable_notifications.checked,
        maintenance_mode:els.maintenance_mode.checked
    };

    try {
        if (state) state.textContent='⏳ در حال ذخیره...';
        const r=await fetch('./save_global_settings.php',{
            method:'POST',
            headers:{'Content-Type':'application/json','Accept':'application/json'},
            credentials:'same-origin',
            cache:'no-store',
            body:JSON.stringify(payload)
        });
        const text=await r.text();
        let j={};
        try { j=JSON.parse(text); } catch(e) { throw new Error('پاسخ نامعتبر از سرور دریافت شد.'); }
        if (!r.ok || !j.success) throw new Error(j.message||'ذخیره تنظیمات انجام نشد.');
        if (state) state.textContent='✅ با موفقیت ذخیره شد';
    } catch(e) {
        console.error('saveGlobalSettings error:',e);
        if (state) state.textContent='❌ '+e.message;
        else alert('❌ '+e.message);
    }
}


// ==============================================
// امنیت و تغییر رمز
// ==============================================
function renderPasswordSecurity(){
    const c=document.getElementById('passwordContainer');if(!c)return;
    c.innerHTML=`<div class="admin-section-head"><div><div class="admin-section-title">🔐 امنیت پنل مدیریت</div><div class="admin-section-help">رمز جدید با password_hash ذخیره می‌شود و ورود ادمین از هش امن استفاده می‌کند.</div></div><span id="securitySaveState" class="admin-section-help"></span></div><div class="admin-grid-2"><div class="admin-field"><label>رمز فعلی</label><input id="security_current_password" type="password" autocomplete="current-password"></div><div class="admin-field"><label>رمز جدید</label><input id="security_new_password" type="password" autocomplete="new-password"></div><div class="admin-field"><label>تکرار رمز جدید</label><input id="security_repeat_password" type="password" autocomplete="new-password"></div><div class="admin-field"><label>قدرت رمز</label><div id="passwordStrength" class="admin-section-help" style="padding:12px;border:1px solid var(--border);border-radius:10px;background:var(--bg)">حداقل ۸ کاراکتر</div></div></div><div style="display:flex;gap:8px;margin-top:12px"><button class="btn-icon-sm primary" onclick="saveAdminPassword()">🔐 تغییر رمز</button></div><div class="password-security-grid" style="margin-top:14px"><label class="security-switch"><span><b>قفل ورود پس از چند خطا</b><small>برای جلوگیری از brute force</small></span><input id="sec_lockout" type="checkbox" checked></label><label class="security-switch"><span><b>ثبت لاگ ورود ادمین</b><small>برای بررسی رخدادهای امنیتی</small></span><input id="sec_logins" type="checkbox" checked></label><label class="security-switch"><span><b>Timeout نشست</b><small>خروج خودکار پس از عدم فعالیت</small></span><input id="sec_timeout" type="checkbox" checked></label><label class="security-switch"><span><b>Force HTTPS</b><small>در محیط HTTPS فعال شود</small></span><input id="sec_https" type="checkbox"></label></div><div style="display:flex;gap:8px;margin-top:12px"><button class="btn-secondary" onclick="saveSecuritySettings()">💾 ذخیره تنظیمات امنیتی</button></div>`;
    const n=document.getElementById('security_new_password');if(n)n.addEventListener('input',()=>{const v=n.value||'';const st=document.getElementById('passwordStrength');if(!st)return;let score=0;if(v.length>=8)score++;if(/[A-Z]/.test(v)&&/[a-z]/.test(v))score++;if(/\d/.test(v))score++;if(/[^A-Za-z0-9]/.test(v))score++;st.textContent=score>=4?'قوی':score>=2?'متوسط':'ضعیف';st.style.color=score>=4?'var(--success)':score>=2?'var(--warning)':'var(--danger)';});
    fetch('save_security_settings.php?action=get',{cache:'no-store'}).then(r=>r.json()).then(j=>{const d=j.settings||{};const set=(id,v)=>{const e=document.getElementById(id);if(e)e.checked=!!v;};set('sec_lockout',d.lockout);set('sec_logins',d.admin_login_log);set('sec_timeout',d.session_timeout);set('sec_https',d.force_https);}).catch(()=>{});
}
async function saveAdminPassword(){
    const current=document.getElementById('security_current_password').value;const nw=document.getElementById('security_new_password').value;const repeat=document.getElementById('security_repeat_password').value;if(nw!==repeat){alert('❌ تکرار رمز جدید یکسان نیست.');return;}if(nw.length<8){alert('❌ رمز جدید باید حداقل ۸ کاراکتر باشد.');return;}
    try{const r=await fetch('save_admin_password.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({current_password:current,new_password:nw})});const j=await r.json();if(!r.ok||!j.success)throw new Error(j.message||'خطا');alert('✅ رمز با موفقیت تغییر کرد.');document.getElementById('security_current_password').value='';document.getElementById('security_new_password').value='';document.getElementById('security_repeat_password').value='';}catch(e){alert('❌ '+e.message);}
}
async function saveSecuritySettings(){
    const payload={lockout:document.getElementById('sec_lockout').checked,admin_login_log:document.getElementById('sec_logins').checked,session_timeout:document.getElementById('sec_timeout').checked,force_https:document.getElementById('sec_https').checked};
    try{const r=await fetch('save_security_settings.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});const j=await r.json();if(!r.ok||!j.success)throw new Error(j.message||'خطا');alert('✅ تنظیمات امنیتی ذخیره شد.');}catch(e){alert('❌ '+e.message);}
}

function adminLogout() {

    if (
        confirm('خروج؟')
    ) {
        window.location.href =
            'admin-login.php';
    }
}


function switchTab(tabId) {

    document
        .querySelectorAll('.tab-content')
        .forEach(
            el =>
                el.classList.remove(
                    'active'
                )
        );


    document
        .querySelectorAll('.tab-btn')
        .forEach(
            el =>
                el.classList.remove(
                    'active'
                )
        );


    const contentEl =
        document.getElementById(
            'tab-' + tabId
        );


    if (contentEl) {
        contentEl.classList.add(
            'active'
        );
    }


    const btnEl =
        document.querySelector(
            `.tab-btn[onclick*="'${tabId}'"]`
        );


    if (btnEl) {
        btnEl.classList.add(
            'active'
        );
    }


    if (tabId === 'ads') {
        renderAds();
    }


    if (tabId === 'requests') {
        renderRequests();
    }


    if (tabId === 'dashboard') {
        renderDashboard();
    }


    if (tabId === 'contact') {
        loadContactSettings();
        bindContactLivePreview();
        loadConsultants();
    }

    if (tabId === 'users') {
        loadAdminUsers();
        loadRecentVisits();
    }

    if (tabId === 'global') {
        loadGlobalSettings();
    }

    if (tabId === 'colors') {
    }

    if (tabId === 'password') {
        renderPasswordSecurity();
    }

    if (tabId === 'support') {
        loadSupportTickets();
    }
}


/* =========================================================
   SUPPORT TAB
   ========================================================= */

let supportTicketsData = [];
let supportCurrentFilter = 'all';
let supportCurrentTicketId = null;

function supportEscapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function loadSupportTickets() {
    const listEl = document.getElementById('supportTicketsList');
    if (listEl) listEl.innerHTML = 'در حال بارگذاری…';

    fetch('support-api.php?action=admin_get_tickets', { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                if (listEl) listEl.innerHTML = '<div style="padding:12px;color:var(--danger);">' + supportEscapeHtml(data && data.message || 'خطا در دریافت تیکت‌ها') + '</div>';
                return;
            }
            supportTicketsData = Array.isArray(data.tickets) ? data.tickets : [];
            const countEl = document.getElementById('supportTicketCount');
            if (countEl) countEl.innerText = supportTicketsData.length + ' تیکت';
            renderSupportTickets();
        })
        .catch(() => {
            if (listEl) listEl.innerHTML = '<div style="padding:12px;color:var(--danger);">خطا در ارتباط با سرور</div>';
        });
}

function filterSupportTickets(status, btnEl) {
    supportCurrentFilter = status;
    document.querySelectorAll('.support-filter-btn').forEach(b => b.classList.remove('active'));
    if (btnEl) btnEl.classList.add('active');
    renderSupportTickets();
}

function renderSupportTickets() {
    const listEl = document.getElementById('supportTicketsList');
    if (!listEl) return;

    const rows = supportTicketsData.filter(t => supportCurrentFilter === 'all' || t.status === supportCurrentFilter);

    if (!rows.length) {
        listEl.innerHTML = '<div style="padding:12px;color:var(--text-secondary);">تیکتی برای نمایش وجود ندارد.</div>';
        return;
    }

    listEl.innerHTML = rows.map(t => `
        <div onclick="openSupportTicket(${t.id})" style="cursor:pointer;padding:12px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px;display:flex;justify-content:space-between;gap:10px;align-items:center;">
            <div>
                <div style="font-weight:700;">${supportEscapeHtml(t.subject)} ${t.unread_count > 0 ? '<span style="background:var(--danger);color:#fff;border-radius:20px;padding:1px 8px;font-size:11px;margin-inline-start:6px;">' + t.unread_count + ' جدید</span>' : ''}</div>
                <div style="font-size:12px;color:var(--text-secondary);margin-top:4px;">${supportEscapeHtml(t.user_name)} • ${supportEscapeHtml(t.last_message || '')}</div>
            </div>
            <span class="support-status-badge status-${supportEscapeHtml(t.status)}" style="white-space:nowrap;font-size:12px;padding:4px 10px;border-radius:20px;background:var(--bg-secondary);">${supportEscapeHtml(t.status_label)}</span>
        </div>
    `).join('');
}

function openSupportTicket(ticketId) {
    supportCurrentTicketId = ticketId;

    fetch('support-api.php?action=admin_get_ticket&ticket_id=' + encodeURIComponent(ticketId), { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                alert(data && data.message || 'خطا در دریافت گفتگو');
                return;
            }

            const card = document.getElementById('supportConversationCard');
            if (card) card.style.display = '';

            const titleEl = document.getElementById('supportConversationTitle');
            if (titleEl) titleEl.innerText = data.ticket.subject + ' — ' + data.ticket.user_name;

            const closeBtn = document.getElementById('supportCloseBtn');
            const reopenBtn = document.getElementById('supportReopenBtn');
            const isClosed = data.ticket.status === 'closed';
            if (closeBtn) closeBtn.style.display = isClosed ? 'none' : '';
            if (reopenBtn) reopenBtn.style.display = isClosed ? '' : 'none';

            const msgEl = document.getElementById('supportMessages');
            if (msgEl) {
                msgEl.innerHTML = (data.messages || []).map(m => `
                    <div style="align-self:${m.sender_type === 'admin' ? 'flex-start' : 'flex-end'};max-width:80%;background:${m.sender_type === 'admin' ? 'var(--primary)' : 'var(--bg-secondary)'};color:${m.sender_type === 'admin' ? '#fff' : 'var(--text-primary)'};padding:10px 14px;border-radius:12px;">
                        <div style="font-size:11px;opacity:.75;margin-bottom:4px;">${supportEscapeHtml(m.sender_name)}</div>
                        <div style="white-space:pre-wrap;">${supportEscapeHtml(m.message)}</div>
                    </div>
                `).join('');
                msgEl.scrollTop = msgEl.scrollHeight;
            }

            card.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // شمارش تیکت‌های خوانده‌نشده در لیست به‌روزرسانی شود
            loadSupportTickets();
        })
        .catch(() => alert('خطا در ارتباط با سرور'));
}

function sendSupportReply() {
    const textEl = document.getElementById('supportReplyText');
    const message = (textEl?.value || '').trim();

    if (!supportCurrentTicketId) return;
    if (!message) { alert('لطفاً متن پاسخ را وارد کنید.'); return; }

    const body = new URLSearchParams({ action: 'admin_reply', ticket_id: supportCurrentTicketId, message });

    fetch('support-api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                alert(data && data.message || 'خطا در ارسال پاسخ');
                return;
            }
            if (textEl) textEl.value = '';
            openSupportTicket(supportCurrentTicketId);
        })
        .catch(() => alert('خطا در ارتباط با سرور'));
}

function setSupportTicketStatus(action) {
    if (!supportCurrentTicketId) return;

    const body = new URLSearchParams({
        action: action === 'close' ? 'admin_close_ticket' : 'admin_reopen_ticket',
        ticket_id: supportCurrentTicketId
    });

    fetch('support-api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                alert(data && data.message || 'خطا در به‌روزرسانی وضعیت');
                return;
            }
            openSupportTicket(supportCurrentTicketId);
        })
        .catch(() => alert('خطا در ارتباط با سرور'));
}


function renderDashboard() {
    const total=adsData.length;
    const pending=adsData.filter(a=>a.status==='pending').length;
    const published=adsData.filter(a=>a.status==='published').length;
    const vip=adsData.filter(a=>a.is_vip===true||a.is_vip==='1').length;
    const matchedRequests=requestsData.filter(r=>Array.isArray(r.matches)&&r.matches.length>0).length;
    const matchCount=requestsData.reduce((sum,r)=>sum+(Array.isArray(r.matches)?r.matches.length:0),0);
    const recentRequests=requestsData.slice().sort((a,b)=>String(b.created_at||'').localeCompare(String(a.created_at||''))).slice(0,10).length;
    const pendingReview=pending+requestsData.filter(r=>!r.status||r.status==='new'||r.status==='pending').length;
    const set=(id,val)=>{const e=document.getElementById(id);if(e)e.innerText=val;};
    set('dashTotalAds',total);set('dashPendingAds',pending);set('dashPublishedAds',published);set('dashTotalRequests',requestsData.length);
    set('dashVipAds',vip);set('dashMatchedRequests',matchedRequests);set('dashMatchCount',matchCount);set('dashNewRequests',recentRequests);set('dashPendingReview',pendingReview);
    const usersEl=document.getElementById('dashUsers');
    const visitsEl=document.getElementById('dashVisits24h');
    if(usersEl)usersEl.innerText='…'; if(visitsEl)visitsEl.innerText='…';
    Promise.all([
        fetch('identity-sync.php?action=list',{cache:'no-store'}).then(r=>r.ok?r.json():null).catch(()=>null),
        fetch('page-visits.php?action=stats',{cache:'no-store'}).then(r=>r.ok?r.json():null).catch(()=>null)
    ]).then(([u,v])=>{
        if(usersEl)usersEl.innerText=Array.isArray(u?.users)?u.users.length:0;
        if(visitsEl)visitsEl.innerText=Number(v?.last_24h||0);
    });
    set('dashPublishedVip',adsData.filter(a=>(a.is_vip===true||a.is_vip==='1')&&a.status==='published').length);

    fetch('support-api.php?action=admin_get_tickets',{cache:'no-store'})
        .then(r=>r.json())
        .then(data=>{
            const tickets=Array.isArray(data?.tickets)?data.tickets:[];
            const open=tickets.filter(t=>t.status==='open'||t.status==='answered').length;
            set('dashSupportOpen',open);
            const noteEl=document.getElementById('dashSupportTotalNote');
            if(noteEl)noteEl.innerText='از مجموع '+tickets.length+' تیکت';
        })
        .catch(()=>{});
}

// ==============================================
function uploadOnboardingLogo() {
    const file = logoFileInput.files[0];
    if (!file) {
        logoStatus.textContent = '⚠️ لطفاً یک فایل انتخاب کنید.';
        logoStatus.style.color = 'var(--text-secondary)';
        return;
    }

    if (file.size > 5 * 1024 * 1024) {
        logoStatus.textContent = '❌ حجم فایل بیشتر از ۵ مگابایت است.';
        logoStatus.style.color = 'red';
        return;
    }

    const allowedTypes = ['image/png', 'image/jpeg', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        logoStatus.textContent = '❌ فرمت فایل مجاز نیست (فقط PNG, JPG, JPEG, WEBP).';
        logoStatus.style.color = 'red';
        return;
    }

    uploadLogoBtn.disabled = true;
    uploadLogoBtn.style.opacity = '0.6';
    uploadProgress.style.display = 'block';
    progressBar.style.width = '0%';
    logoStatus.textContent = '⏳ در حال آپلود...';
    logoStatus.style.color = 'var(--text-secondary)';

    const formData = new FormData();
    formData.append('onboarding_logo', file);

    // مسیر آپلود - اگر پروژه در پوشه melkino است
    const uploadUrl = window.location.origin + '/melkino/upload_onboarding_logo.php';
    // اگر پروژه در ریشه است، خط بالا رو کامنت کنید و این خط رو فعال کنید:
    // const uploadUrl = window.location.origin + '/upload_onboarding_logo.php';

    fetch(uploadUrl, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => {
                throw new Error(text || 'خطا در پاسخ سرور');
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            const logoUrl = data.logo_url || data.url || '';
            if (logoUrl) {
                logoPreview.src = logoUrl + '?t=' + new Date().getTime();
                logoStatus.textContent = '✅ ' + data.message;
                logoStatus.style.color = 'green';
            } else {
                logoStatus.textContent = '⚠️ لوگو آپلود شد، اما نشانی دریافت نشد. صفحه را رفرش کنید.';
                logoStatus.style.color = 'orange';
            }
            logoFileInput.value = '';
            fileNameDisplay.textContent = '';
            alert('✅ لوگو با موفقیت آپلود و جایگزین شد.');
        } else {
            logoStatus.textContent = '❌ ' + data.message;
            logoStatus.style.color = 'red';
        }
    })
    .catch(error => {
        console.error('Upload error:', error);
        logoStatus.textContent = '❌ خطا: ' + error.message;
        logoStatus.style.color = 'red';
    })
    .finally(() => {
        uploadProgress.style.display = 'none';
        uploadLogoBtn.disabled = false;
        uploadLogoBtn.style.opacity = '1';
    });
}

uploadLogoBtn.addEventListener('click', uploadOnboardingLogo);

logoFileInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        uploadLogoBtn.click();
    }
});


// ==============================================
// بارگذاری اولیه
// ==============================================

document.addEventListener('DOMContentLoaded', function () {
    if (window.location.hash === '#support') {
        switchTab('support');
    }
});

document.addEventListener(
    'DOMContentLoaded',
    function() {

        const search =
            document.getElementById(
                'adsSearch'
            );


        const property =
            document.getElementById(
                'adsPropertyFilter'
            );


        const transaction =
            document.getElementById(
                'adsTransactionFilter'
            );


        const sort =
            document.getElementById(
                'adsSort'
            );


        if (search) {

            search.addEventListener(
                'input',
                function() {

                    adViewState.search =
                        this.value;

                    adViewState.page =
                        1;

                    renderAds();
                }
            );
        }


        if (property) {

            property.addEventListener(
                'change',
                function() {

                    adViewState.propertyType =
                        this.value;

                    adViewState.page =
                        1;

                    renderAds();
                }
            );
        }


        if (transaction) {

            transaction.addEventListener(
                'change',
                function() {

                    adViewState.transactionType =
                        this.value;

                    adViewState.page =
                        1;

                    renderAds();
                }
            );
        }


        if (sort) {

            sort.addEventListener(
                'change',
                function() {

                    adViewState.sort =
                        this.value;

                    adViewState.page =
                        1;

                    renderAds();
                }
            );
        }


        renderDashboard();
        renderAds();
        renderRequests();
        loadContactSettings();
        bindContactLivePreview();
        loadAdminUsers();
        loadRecentVisits();

    }
);


// ==============================================
// بستن مودال با کلیک روی پس‌زمینه
// ==============================================

document
    .querySelectorAll(
        '.modal-overlay'
    )
    .forEach(
        modal => {

            modal.addEventListener(
                'click',
                function(e) {

                    if (
                        e.target ===
                        this
                    ) {

                        this.classList.remove(
                            'active'
                        );
                    }
                }
            );
        }
    );

</script>

<script src="admin-ads.js"></script>
<script src="admin-requests.js"></script>

<script>
(function(){
  function tick(){var e=document.getElementById('adminCommandClock');if(!e)return;var d=new Date();e.textContent=d.toLocaleDateString('fa-IR')+' • '+d.toLocaleTimeString('fa-IR',{hour:'2-digit',minute:'2-digit'});}
  tick();setInterval(tick,30000);
})();
</script>
<div id="userRevisionFab" style="position:fixed;left:18px;bottom:18px;z-index:10000;display:none"><button type="button" id="openUserRevisions" style="border:0;background:#0b5d59;color:#fff;border-radius:999px;padding:12px 16px;font-family:inherit;font-weight:800;box-shadow:0 10px 30px rgba(0,0,0,.18);cursor:pointer">📝 ویرایش‌های در انتظار تأیید <span id="userRevisionCount">0</span></button></div>
<div id="userRevisionModal" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10001;display:none;align-items:center;justify-content:center;padding:16px"><div style="width:min(760px,100%);max-height:85vh;overflow:auto;background:#fff;border-radius:18px;padding:18px;direction:rtl"><div style="display:flex;justify-content:space-between;align-items:center"><h3 style="margin:0">ویرایش‌های در انتظار تأیید</h3><button id="closeUserRevisions" type="button">✕</button></div><div id="userRevisionList" style="margin-top:14px"></div></div></div>
<script>
(function(){
 const fab=document.getElementById('userRevisionFab'), modal=document.getElementById('userRevisionModal'), list=document.getElementById('userRevisionList'), cnt=document.getElementById('userRevisionCount');
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 async function load(){try{const d=await fetch('admin-property-revisions.php?action=list',{cache:'no-store'}).then(r=>r.json());const rows=d.revisions||[];cnt.textContent=rows.length;fab.style.display=rows.length?'block':'none';list.innerHTML=rows.length?rows.map(r=>{const s=r.snapshot||{},x=s.after||{},b=s.before||{};return `<div style="border:1px solid #e2e2e2;border-radius:14px;padding:13px;margin:10px 0"><b>${esc(r.ad_id)} — ${esc(r.title||'')}</b><div style="font-size:12px;color:#666;margin-top:6px">قبل: ${esc(b.title||'—')} | بعد: ${esc(x.title||'—')}</div><div style="font-size:12px;color:#666;margin-top:4px">متراژ: ${esc(b.area??'—')} ← ${esc(x.area??'—')} | قیمت: ${esc(b.price_sell??'—')} ← ${esc(x.price_sell??'—')}</div><div style="display:flex;gap:8px;margin-top:10px"><button type="button" data-rev="${r.id}" data-act="approve">✅ تأیید و انتشار</button><button type="button" data-rev="${r.id}" data-act="reject">❌ رد</button></div></div>`}).join(''):'<div style="text-align:center;color:#666;padding:30px">موردی برای بررسی نیست.</div>';}catch(e){list.innerHTML='<div style="color:#b00020">خطا در بارگذاری ویرایش‌ها.</div>';}}
 document.getElementById('openUserRevisions').onclick=()=>{modal.style.display='flex';load();};document.getElementById('closeUserRevisions').onclick=()=>modal.style.display='none';
 list.onclick=async e=>{const b=e.target.closest('[data-rev]');if(!b)return;if(!confirm(b.dataset.act==='approve'?'این ویرایش تأیید و دوباره منتشر شود؟':'این ویرایش رد شود؟'))return;const d=await fetch('admin-panel.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({user_revision_action:b.dataset.act,revision_id:b.dataset.rev})}).then(r=>r.json());alert(d.message||'');if(d.success)load();};
 load();setInterval(load,30000);
})();
</script>
</body>

</html>