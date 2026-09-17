<?php
/**
 * Shared database writer for Melkino property registration forms.
 * Source of truth: MySQL. JSON files are no longer used for saving new ads.
 */

if (!function_exists('melkino_normalize_digits')) {
    function melkino_normalize_digits($value): string {
        $value = (string)$value;
        $map = [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ];
        return strtr($value, $map);
    }
}

if (!function_exists('melkino_numeric')) {
    function melkino_numeric($value, ?float $default = null): ?float {
        if ($value === null) return $default;
        if (is_array($value) || is_object($value)) return $default;
        $value = trim(melkino_normalize_digits($value));
        if ($value === '') return $default;
        $value = str_replace([',', '٬', ' '], '', $value);
        if (!is_numeric($value)) return $default;
        return (float)$value;
    }
}

if (!function_exists('melkino_integer')) {
    function melkino_integer($value, ?int $default = null): ?int {
        $n = melkino_numeric($value, null);
        return $n === null ? $default : (int)$n;
    }
}

if (!function_exists('melkino_ad_id')) {
    function melkino_ad_id(PDO $pdo): string {
        do {
            $id = 'AD-' . date('Ymd') . '-' . random_int(1000, 9999);
            $stmt = $pdo->prepare('SELECT 1 FROM ads WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
        } while ($stmt->fetchColumn());
        return $id;
    }
}

if (!function_exists('melkino_amenity_id')) {
    function melkino_amenity_id(PDO $pdo, string $name, string $category): ?int {
        $name = trim($name);
        if ($name === '') return null;

        $stmt = $pdo->prepare('SELECT id FROM amenities WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) return (int)$existing;

        $slug = 'a-' . substr(hash('sha256', $category . '|' . $name), 0, 24);
        $insert = $pdo->prepare(
            'INSERT INTO amenities (name, slug, category, is_active, sort_order) VALUES (?, ?, ?, 1, 0)'
        );
        $insert->execute([$name, $slug, $category]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('melkino_extract_normalized_fields')) {
    function melkino_extract_normalized_fields(array $newAd): array {
        $propertyType = trim((string)($newAd['propertyType'] ?? $newAd['property_type'] ?? ''));
        $transactionType = trim((string)($newAd['transactionType'] ?? $newAd['transaction_type'] ?? ''));

        $area = melkino_numeric($newAd['area'] ?? null);
        $landArea = melkino_numeric($newAd['land_area'] ?? null);
        $builtArea = melkino_numeric($newAd['built_area'] ?? null);

        if ($propertyType === 'آپارتمان') {
            $area = $area ?? melkino_numeric($newAd['area_apt'] ?? null);
            $builtArea = $builtArea ?? $area;
        } elseif ($propertyType === 'ویلایی') {
            $landArea = $landArea ?? melkino_numeric($newAd['land_villa'] ?? null);
            $builtArea = $builtArea ?? melkino_numeric($newAd['built_villa'] ?? null);
            $area = $builtArea;
        } elseif ($propertyType === 'زمین') {
            $landArea = $landArea ?? melkino_numeric($newAd['land_area'] ?? null);
            $area = $landArea;
        } elseif ($propertyType === 'باغ') {
            $landArea = $landArea ?? melkino_numeric($newAd['garden_area'] ?? null);
            $area = $landArea;
        } elseif ($propertyType === 'اداری') {
            $area = $area ?? melkino_numeric($newAd['office_area'] ?? null);
            $builtArea = $builtArea ?? $area;
        } elseif ($propertyType === 'تجاری') {
            $area = $area ?? melkino_numeric($newAd['area_comm'] ?? null);
            $builtArea = $builtArea ?? $area;
        }

        $rooms = melkino_integer($newAd['rooms'] ?? null);
        if ($rooms === null) {
            $rooms = melkino_integer($newAd['rooms_apt'] ?? $newAd['rooms_villa'] ?? $newAd['office_rooms'] ?? null);
        }

        $floor = $newAd['floor'] ?? $newAd['office_floor'] ?? null;
        if (is_array($floor)) $floor = null;
        $floor = trim((string)($floor ?? ''));

        $year = melkino_integer($newAd['year'] ?? $newAd['year_apt'] ?? $newAd['year_villa'] ?? $newAd['office_year'] ?? null);

        return [
            'transaction_type' => $transactionType !== '' ? $transactionType : null,
            'property_type' => $propertyType !== '' ? $propertyType : null,
            'area' => $area,
            'land_area' => $landArea,
            'built_area' => $builtArea,
            'rooms' => $rooms,
            'floor' => $floor !== '' ? $floor : null,
            'year' => $year,
        ];
    }
}

if (!function_exists('savePropertyToDatabase')) {
    /**
     * @return array{success:bool,id?:string,error?:string}
     */
    function savePropertyToDatabase(PDO $pdo, array $newAd): array {
        $id = trim((string)($newAd['id'] ?? ''));
        if ($id === '') $id = melkino_ad_id($pdo);

        $norm = melkino_extract_normalized_fields($newAd);
        $transactionType = $norm['transaction_type'] ?? null;

        $priceSell = melkino_numeric($newAd['price_sell'] ?? null, 0);
        $deposit = melkino_numeric($newAd['deposit'] ?? null, 0);
        $rentMonthly = melkino_numeric($newAd['rent_monthly'] ?? null, 0);
        $fullRent = melkino_numeric($newAd['full_rent'] ?? null, 0);
        $fullRentEnabled = !empty($newAd['full_rent_enabled']) ? 1 : 0;
        $totalPrice = melkino_numeric($newAd['total_price'] ?? null, 0);
        $downPayment = melkino_numeric($newAd['down_payment'] ?? null, 0);
        $displayPrice = melkino_numeric($newAd['display_price'] ?? null, 0);

        $details = $newAd;
        foreach ([
            'id','title','location','address','location_received','status','tags','gender','last_name','phone',
            'propertyType','property_type','transactionType','transaction_type','description','images','selectedImages',
            'publish_photos','created_at','price_sell','price_condition','deposit','rent_monthly','full_rent_enabled',
            'full_rent','total_price','down_payment','payment_terms','display_price','amenities',
            'is_not_keyed','delivery_date','vacancy_date','is_vacant','exchange_interested','exchange_with','visit_hours',
            'is_old','is_renovated','water_share','well_name'
        ] as $remove) {
            unset($details[$remove]);
        }

        $tags = $newAd['tags'] ?? [];
        if (!is_array($tags)) $tags = [$tags];

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ads (
                    id, title, status, transaction_type, property_type, gender, last_name, phone,
                    location, address, location_received, area, land_area, built_area, rooms, floor, year,
                    price_sell, price_condition, deposit, rent_monthly, full_rent_enabled, full_rent,
                    total_price, down_payment, payment_terms, display_price, description, publish_photos,
                    price_hidden, tags, property_details, custom_fields, created_at,
                    is_not_keyed, delivery_date, vacancy_date, is_vacant, exchange_interested, exchange_with, visit_hours,
                    is_old, is_renovated, water_share, well_name
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )'
            );

            $stmt->execute([
                $id,
                trim((string)($newAd['title'] ?? '')),
                trim((string)($newAd['status'] ?? 'pending')) ?: 'pending',
                $norm['transaction_type'],
                $norm['property_type'],
                trim((string)($newAd['gender'] ?? '')) ?: null,
                trim((string)($newAd['last_name'] ?? '')) ?: null,
                trim((string)($newAd['phone'] ?? '')) ?: null,
                trim((string)($newAd['location'] ?? '')) ?: null,
                trim((string)($newAd['address'] ?? '')) ?: null,
                trim((string)($newAd['location_received'] ?? '0')),
                $norm['area'],
                $norm['land_area'],
                $norm['built_area'],
                $norm['rooms'],
                $norm['floor'],
                $norm['year'],
                $priceSell,
                trim((string)($newAd['price_condition'] ?? '')) ?: null,
                $deposit,
                $rentMonthly,
                $fullRentEnabled,
                $fullRent,
                $totalPrice,
                $downPayment,
                trim((string)($newAd['payment_terms'] ?? '')) ?: null,
                $displayPrice,
                trim((string)($newAd['description'] ?? '')) ?: null,
                trim((string)($newAd['publish_photos'] ?? 'yes')),
                !empty($newAd['price_hidden']) ? 1 : 0,
                json_encode(array_values($tags), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode([], JSON_UNESCAPED_UNICODE),
                !empty($newAd['created_at']) ? $newAd['created_at'] : date('Y-m-d H:i:s'),
                // فیلدهای جدید:
                !empty($newAd['is_not_keyed']) ? 1 : 0,
                !empty($newAd['delivery_date']) ? $newAd['delivery_date'] : null,
                !empty($newAd['vacancy_date']) ? $newAd['vacancy_date'] : null,
                !empty($newAd['is_vacant']) ? 1 : 0,
                !empty($newAd['exchange_interested']) ? 1 : 0,
                !empty($newAd['exchange_with']) ? (string)$newAd['exchange_with'] : null,
                !empty($newAd['visit_hours']) ? (string)$newAd['visit_hours'] : null,
                !empty($newAd['is_old']) ? 1 : 0,
                !empty($newAd['is_renovated']) ? 1 : 0,
                !empty($newAd['water_share']) ? (string)$newAd['water_share'] : null,
                !empty($newAd['well_name']) ? (string)$newAd['well_name'] : null,
            ]);

            $images = $newAd['selectedImages'] ?? $newAd['images'] ?? [];
            if (is_string($images)) {
                $images = array_values(array_filter(array_map('trim', explode(',', $images))));
            }
            if (!is_array($images)) $images = [];

            $insertImage = $pdo->prepare(
                'INSERT INTO images (ad_id, filename, storage_path, sort_order, is_selected, is_primary, publish_publicly)
                 VALUES (?, ?, ?, ?, 1, ?, ?)'
            );
            $publicImages = strtolower(trim((string)($newAd['publish_photos'] ?? 'yes'))) === 'yes' ? 1 : 0;
            foreach (array_values($images) as $index => $filename) {
                $filename = trim((string)$filename);
                if ($filename === '') continue;
                $insertImage->execute([$id, $filename, $filename, $index, $index === 0 ? 1 : 0, $publicImages]);
            }

            $amenities = $newAd['amenities'] ?? [];
            if (!is_array($amenities)) $amenities = [$amenities];
            $propertyCategory = $norm['property_type'] ?? 'ملک';
            $linkAmenity = $pdo->prepare('INSERT IGNORE INTO ad_amenities (ad_id, amenity_id) VALUES (?, ?)');
            foreach ($amenities as $amenity) {
                if (is_array($amenity)) continue;
                $amenityId = melkino_amenity_id($pdo, (string)$amenity, $propertyCategory);
                if ($amenityId !== null) $linkAmenity->execute([$id, $amenityId]);
            }

            // Optional Telegram user linkage when telegram_id is supplied by a future form version.
            $telegramId = trim((string)($newAd['telegram_id'] ?? ''));
            if ($telegramId !== '') {
                $userStmt = $pdo->prepare('SELECT id FROM users WHERE telegram_id = ? LIMIT 1');
                $userStmt->execute([$telegramId]);
                $userId = $userStmt->fetchColumn();
                if ($userId !== false) {
                    $pdo->prepare('UPDATE ads SET owner_user_id = ? WHERE id = ?')->execute([(int)$userId, $id]);
                }
            }

            $pdo->commit();

            // اعلان «آگهی شما ثبت شد» برای ثبت‌کننده.
            // بی‌صدا انجام می‌شود تا شکستش روند ثبت را خراب نکند.
            try {
                if (!function_exists('melkinoNotifyByPhone')) {
                    require_once __DIR__ . '/db_helpers.php';
                }
                if (function_exists('melkinoNotifyByPhone')) {
                    $ownerPhone = trim((string)($newAd['phone'] ?? ''));
                    $adTitle = trim((string)($newAd['title'] ?? ''));
                    if ($ownerPhone !== '') {
                        melkinoNotifyByPhone(
                            $ownerPhone,
                            'ad_submitted',
                            '📝 آگهی شما ثبت شد',
                            'آگهی «' . ($adTitle !== '' ? $adTitle : $id) . '» با موفقیت ثبت شد و پس از بررسی کارشناسان منتشر می‌شود. کد پیگیری: ' . $id,
                            'my-properties.php',
                            $id
                        );
                    }
                }
            } catch (Throwable $e) {
                // ignore — notification must never break registration
            }

            return ['success' => true, 'id' => $id];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}