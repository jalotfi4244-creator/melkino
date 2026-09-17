<?php
// ==============================================
// property-request.php
// نسخه اصلاح‌شده نهایی
// - رفع خطای addMonth
// - محدود کردن تاریخ از امروز تا ۶ ماه بعد
// - تبدیل تاریخ شمسی انتخاب‌شده به میلادی قبل از ذخیره در DB
// - حفظ منطق تطبیق، امکانات، نوتیفیکیشن و فرم چندمرحله‌ای
// - فیلدهای قیمت/ودیعه/اجاره اجباری شدند
// - اصلاح: اگر قیمت آگهی از حداکثر قیمت کاربر بیشتر باشد، کل امتیاز صفر می‌شود
// - اضافه شدن گزینه "سرمایه‌گذاری" به نوع معامله
// - در صورت انتخاب سرمایه‌گذاری، مرحله نوع ملک به انتخاب اولویت‌ها تغییر می‌کند
// - امکان انتخاب ۳ اولویت (با ترتیب) یا انتخاب "بدون اولویت" (پذیرش همه نوع ملک)
// - سیستم تطبیق بر اساس اولویت‌ها امتیازدهی می‌کند
// - علاوه بر آگهی‌های تکی، ترکیب‌هایی از آگهی‌ها که مجموع قیمتشان در بازه بودجه قرار می‌گیرد نیز پیشنهاد می‌شود
// - در فیلترهای جستجو برای سرمایه‌گذاری فقط حداقل و حداکثر قیمت نمایش داده می‌شود
// ==============================================

// =========================================================
// اتصال و نشست باید پیش از هر چیزی بارگذاری شود.
// قبلاً فایل ابتدا $_SESSION را می‌خواند و بعد (در میانه‌ی فایل)
// config.php را لود می‌کرد؛ در نتیجه نشست اصلاً شروع نشده بود و
// هویت کاربر همیشه خالی می‌ماند (درخواست بدون مالک ذخیره می‌شد).
// =========================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// ثبت درخواست ملک فقط برای کاربران واردشده (تلگرام یا بله) مجاز است.
melkinoRequireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    // =========================================================
    // درخواست ملکینو: ذخیره درخواست + تولید کد رهگیری + تطبیق
    // =========================================================

    // نکته‌ی امنیتی: مقدار خودِ فیلد فرم (reqTelegramId) دیگر برای
    // هویت معتبر نیست. آی‌دی تلگرام معتبر فقط همونیه که سرور قبلاً
    // (با بررسی امضای واقعی تلگرام در identity-sync.php) در سشن
    // ثبت کرده.
    $telegram_id = trim((string)($_SESSION['reg_telegram_id'] ?? ''));
    $gender = trim((string)($_POST['gender'] ?? ''));
    $last_name = trim((string)($_POST['last_name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $transaction_type = trim((string)($_POST['transaction_type'] ?? ''));
    $property_type = trim((string)($_POST['property_type'] ?? ''));
    $location = trim((string)($_POST['location'] ?? ''));
    $urgency = trim((string)($_POST['urgency'] ?? ''));
    
    // تاریخ نمایشی شمسی
    $date_needed = trim((string)($_POST['date_needed'] ?? ''));

    // تاریخ میلادی آماده‌شده توسط JavaScript
    $date_needed_gregorian = trim((string)($_POST['date_needed_gregorian'] ?? ''));

    $rahn_kamal = isset($_POST['rahn_kamal']) ? 'بله' : 'خیر';

    // =========================================================
    // فیلدهای اصلی
    // =========================================================

    $min_area = trim((string)($_POST['min_area'] ?? ''));
    $max_area = trim((string)($_POST['max_area'] ?? ''));
    $min_price = trim((string)($_POST['min_price'] ?? ''));
    $max_price = trim((string)($_POST['max_price'] ?? ''));
    $min_deposit = trim((string)($_POST['min_deposit'] ?? ''));
    $max_deposit = trim((string)($_POST['max_deposit'] ?? ''));
    $min_rent = trim((string)($_POST['min_rent'] ?? ''));
    $max_rent = trim((string)($_POST['max_rent'] ?? ''));

    // سن بنا
    $min_age = trim((string)($_POST['min_age'] ?? ''));
    $max_age = trim((string)($_POST['max_age'] ?? ''));

    $is_not_keyed = isset($_POST['is_not_keyed']) ? 'بله' : 'خیر';

    // امکانات
    $amenities = isset($_POST['amenities']) && is_array($_POST['amenities'])
        ? array_values(array_filter(array_map('trim', $_POST['amenities'])))
        : [];

    // =========================================================
    // فیلدهای اولویت‌بندی (ویژه سرمایه‌گذاری)
    // =========================================================
    $priority1 = trim((string)($_POST['priority_1'] ?? ''));
    $priority2 = trim((string)($_POST['priority_2'] ?? ''));
    $priority3 = trim((string)($_POST['priority_3'] ?? ''));
    $no_priority = isset($_POST['no_priority']) ? 1 : 0;

    // =========================================================
    // اعتبارسنجی سرور برای فیلدهای اجباری قیمت/ودیعه/اجاره
    // =========================================================
    $errors = [];
    if ($transaction_type === 'فروش' || $transaction_type === 'پیش فروش') {
        if (trim($min_price) === '') {
            $errors[] = 'حداقل قیمت الزامی است.';
        }
        if (trim($max_price) === '') {
            $errors[] = 'حداکثر قیمت الزامی است.';
        }
    } elseif ($transaction_type === 'اجاره') {
        if (trim($min_deposit) === '') {
            $errors[] = 'حداقل ودیعه الزامی است.';
        }
        if (trim($max_deposit) === '') {
            $errors[] = 'حداکثر ودیعه الزامی است.';
        }
        if (trim($min_rent) === '') {
            $errors[] = 'حداقل اجاره ماهانه الزامی است.';
        }
        if (trim($max_rent) === '') {
            $errors[] = 'حداکثر اجاره ماهانه الزامی است.';
        }
    } elseif ($transaction_type === 'سرمایه‌گذاری') {
        if (!$no_priority) {
            if (empty($priority1) && empty($priority2) && empty($priority3)) {
                $errors[] = 'حداقل یک اولویت برای نوع ملک انتخاب کنید یا گزینه "بدون اولویت" را فعال کنید.';
            }
        }
        if (trim($min_price) === '') {
            $errors[] = 'حداقل قیمت الزامی است.';
        }
        if (trim($max_price) === '') {
            $errors[] = 'حداکثر قیمت الزامی است.';
        }
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo '<div dir="rtl" style="padding:20px;background:#fff;color:#b00020;font-family:Tahoma;">';
        echo '<h3>خطا در ثبت درخواست</h3><ul>';
        foreach ($errors as $err) {
            echo '<li>' . htmlspecialchars($err) . '</li>';
        }
        echo '</ul></div>';
        exit;
    }

    // =========================================================
    // فیلدهای اضافی
    // =========================================================

    $additional = [];

    $excluded = [
        'telegram_id',
        'gender',
        'last_name',
        'phone',
        'transaction_type',
        'property_type',
        'location',
        'urgency',
        'date_needed',
        'date_needed_gregorian',
        'submit_request',
        'request_form_submit',
        'rahn_kamal',
        'min_area',
        'max_area',
        'min_price',
        'max_price',
        'min_deposit',
        'max_deposit',
        'min_rent',
        'max_rent',
        'amenities',
        'min_age',
        'max_age',
        'is_not_keyed',
        'additional_notes',
        'priority_1',
        'priority_2',
        'priority_3',
        'no_priority'
    ];

    foreach ($_POST as $key => $value) {

        if (in_array($key, $excluded, true)) {
            continue;
        }

        if (is_array($value)) {
            $value = implode('، ', $value);
        }

        $additional[$key] = trim((string)$value);
    }

    // توضیحات تکمیلی
    $additional_notes = trim((string)($_POST['additional_notes'] ?? ''));

    if ($additional_notes !== '') {
        $additional['additional_notes'] = $additional_notes;
    }

    // ذخیره اولویت‌ها و وضعیت no_priority در additional
    if ($transaction_type === 'سرمایه‌گذاری') {
        $additional['priority_1'] = $priority1;
        $additional['priority_2'] = $priority2;
        $additional['priority_3'] = $priority3;
        $additional['no_priority'] = $no_priority ? 'بله' : 'خیر';
    }

    // =========================================================
    // توابع کمکی
    // =========================================================

    function reqDigitsToEnglish($value)
    {
        $value = (string)$value;

        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];

        return str_replace(
            array_merge($fa, $ar),
            array_merge(range(0, 9), range(0, 9)),
            $value
        );
    }

    function reqNumber($value)
    {
        $value = reqDigitsToEnglish($value);

        $value = str_replace(
            [',', '٬', ' تومان', 'تومان'],
            '',
            $value
        );

        $value = preg_replace('/[^0-9.]/', '', $value);

        return $value === '' ? null : (float)$value;
    }

    function reqJsonArray($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    function reqJsonObject($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    function reqLower($value)
    {
        $value = (string)$value;

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    function reqContains($haystack, $needle)
    {
        if ($needle === '') {
            return true;
        }

        if (function_exists('mb_strpos')) {
            return mb_strpos(
                (string)$haystack,
                (string)$needle,
                0,
                'UTF-8'
            ) !== false;
        }

        return strpos(
            (string)$haystack,
            (string)$needle
        ) !== false;
    }

    function reqLength($value)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen((string)$value, 'UTF-8');
        }

        return strlen((string)$value);
    }

    function reqNormalizeText($value)
    {
        $value = reqLower(trim((string)$value));

        $value = str_replace(
            ['ي', 'ى', 'ئ'],
            'ی',
            $value
        );

        $value = str_replace(
            ['ك'],
            'ک',
            $value
        );

        $value = preg_replace(
            '/\s+/u',
            ' ',
            $value
        );

        return $value;
    }

    function reqLocationScore($wanted, $actual)
    {
        $wanted = reqNormalizeText($wanted);
        $actual = reqNormalizeText($actual);

        if ($wanted === '' || $actual === '') {
            return 1.0;
        }

        if (
            $wanted === $actual ||
            reqContains($actual, $wanted) ||
            reqContains($wanted, $actual)
        ) {
            return 1.0;
        }

        $tokens = preg_split(
            '/[\s،,\/\-]+/u',
            $wanted,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if (!$tokens) {
            return 0.0;
        }

        $hits = 0;

        foreach ($tokens as $token) {

            if (
                reqLength($token) >= 2 &&
                reqContains($actual, $token)
            ) {
                $hits++;
            }
        }

        return min(
            1.0,
            $hits / max(1, count($tokens))
        );
    }

    function reqRangeScore($wantedMin, $wantedMax, $actual)
    {
        $a = reqNumber($actual);
        $min = reqNumber($wantedMin);
        $max = reqNumber($wantedMax);

        if (
            $a === null ||
            ($min === null && $max === null)
        ) {
            return 1.0;
        }

        if (
            $min !== null &&
            $max !== null &&
            $min > $max
        ) {
            [$min, $max] = [$max, $min];
        }

        if (
            $min !== null &&
            $a < $min
        ) {

            $distance = $min > 0
                ? abs($a - $min) / $min
                : 1;

            return $distance <= 0.20
                ? 0.55
                : 0.0;
        }

        if (
            $max !== null &&
            $a > $max
        ) {
            // اگر قیمت از حداکثر بیشتر باشد، امتیاز صفر
            return 0.0;
        }

        return 1.0;
    }

    function reqPriceScore($wantedMin, $wantedMax, $actual)
    {
        return reqRangeScore(
            $wantedMin,
            $wantedMax,
            $actual
        );
    }

    function reqCanonicalTransaction($value)
    {
        $v = reqNormalizeText($value);

        if (in_array($v, ['فروش'], true)) {
            return 'فروش';
        }

        if (in_array($v, ['پیش فروش', 'پیشفروش'], true)) {
            return 'پیش فروش';
        }

        if (
            in_array(
                $v,
                ['اجاره', 'رهن و اجاره', 'رهن واجاره'],
                true
            )
        ) {
            return 'اجاره';
        }

        if (
            in_array(
                $v,
                ['رهن کامل', 'رهنکامل'],
                true
            )
        ) {
            return 'رهن کامل';
        }

        if (in_array($v, ['سرمایه‌گذاری', 'سرمایه گذاری'], true)) {
            return 'سرمایه‌گذاری';
        }

        return $v;
    }

    function reqCanonicalProperty($value)
    {
        $v = reqNormalizeText($value);

        $aliases = [
            'ویلایی' => 'ویلا',
            'ویلا' => 'ویلا',
            'دفتر اداری' => 'اداری',
            'اداری' => 'اداری',
            'مغازه' => 'تجاری',
            'تجاری' => 'تجاری',
        ];

        return $aliases[$v] ?? $v;
    }

    function reqIsRentTransaction($value)
    {
        return in_array(
            reqCanonicalTransaction($value),
            ['اجاره', 'رهن کامل'],
            true
        );
    }

    function reqAdArea($ad, $details)
    {
        $keys = [
            'area',
            'area_apt',
            'built_area',
            'built_villa',
            'land_area',
            'garden_area',
            'office_area',
            'area_comm'
        ];

        foreach ($keys as $key) {

            if (
                isset($details[$key]) &&
                reqNumber($details[$key]) !== null
            ) {
                return $details[$key];
            }

            if (
                isset($ad[$key]) &&
                reqNumber($ad[$key]) !== null
            ) {
                return $ad[$key];
            }
        }

        return '';
    }

    function reqAdPrice($ad, $transaction)
    {
        if ($transaction === 'اجاره') {

            return [
                'deposit' => $ad['deposit']
                    ?? $ad['full_rent']
                    ?? '',
                'rent' => $ad['rent_monthly']
                    ?? ''
            ];
        }

        if ($transaction === 'پیش فروش') {

            return [
                'price' => $ad['total_price']
                    ?? $ad['price_sell']
                    ?? ''
            ];
        }

        return [
            'price' => $ad['price_sell']
                ?? $ad['total_price']
                ?? ''
        ];
    }

    // =========================================================
    // توابع تولید ترکیب‌های سرمایه‌گذاری
    // =========================================================
    function getCombinations($array, $size) {
        $result = [];
        $n = count($array);
        if ($size > $n) return $result;
        if ($size == 0) return [[]];
        for ($i = 0; $i < $n - $size + 1; $i++) {
            $first = $array[$i];
            $rest = array_slice($array, $i + 1);
            $subCombos = getCombinations($rest, $size - 1);
            foreach ($subCombos as $combo) {
                $result[] = array_merge([$first], $combo);
            }
        }
        return $result;
    }

    function generateCombinations($ads, $budgetMin, $budgetMax, $maxCombos = 10) {
        $eligible = [];
        foreach ($ads as $ad) {
            $price = (float)reqNumber($ad['price'] ?? 0);
            if ($price > 0 && $price <= $budgetMax && ($ad['match_score'] ?? 0) > 0) {
                $eligible[] = $ad;
            }
        }
        if (empty($eligible)) return [];

        $combos = [];
        $n = count($eligible);
        $maxSubsetSize = min(4, $n);
        for ($size = 1; $size <= $maxSubsetSize; $size++) {
            $indices = range(0, $n - 1);
            $combinations = getCombinations($indices, $size);
            foreach ($combinations as $comboIndices) {
                $totalPrice = 0;
                $totalScore = 0;
                $items = [];
                foreach ($comboIndices as $idx) {
                    $ad = $eligible[$idx];
                    $price = (float)reqNumber($ad['price'] ?? 0);
                    $totalPrice += $price;
                    $totalScore += $ad['match_score'] ?? 0;
                    $items[] = $ad;
                }
                if ($totalPrice >= $budgetMin && $totalPrice <= $budgetMax) {
                    $combos[] = [
                        'items' => $items,
                        'total_price' => $totalPrice,
                        'total_score' => $totalScore,
                        'count' => count($items)
                    ];
                }
            }
        }

        usort($combos, function($a, $b) {
            if ($a['total_score'] != $b['total_score']) return $b['total_score'] - $a['total_score'];
            return $a['count'] - $b['count'];
        });

        return array_slice($combos, 0, $maxCombos);
    }

    // =========================================================
    // تابع تطبیق با پشتیبانی از اولویت‌بندی (برای سرمایه‌گذاری)
    // =========================================================
    function reqMatchAd($request, $ad)
    {
        if (($ad['status'] ?? '') !== 'published') {
            return 0;
        }

        $reqTrans = reqCanonicalTransaction($request['transaction_type']);
        $adTrans = reqCanonicalTransaction($ad['transaction_type'] ?? '');

        // ======================================================
        // شرط جدید برای سرمایه‌گذاری
        // ======================================================
        if ($reqTrans === 'سرمایه‌گذاری') {
            $allowedTrans = ['فروش', 'پیش فروش'];
            if (!in_array($adTrans, $allowedTrans, true)) {
                return 0; // آگهی‌های اجاره/رهن کامل برای سرمایه‌گذاری قابل قبول نیستند
            }
            // به آگهی‌های فروش امتیاز ۲۵، به پیش‌فروش امتیاز ۲۰ می‌دهیم
            $transactionScore = ($adTrans === 'فروش') ? 25 : 20;
        } else {
            // حالت عادی: تطابق دقیق نوع معامله الزامی است
            if ($reqTrans !== $adTrans) {
                return 0;
            }
            $transactionScore = 25;
        }

        $score = 0.0;
        $budgetScore = 0.0;
        $propertyScore = 0;

        // ---- امتیاز نوع ملک (برای سرمایه‌گذاری با اولویت، یا حالت عادی) ----
        $requestProperty = reqCanonicalProperty($request['property_type'] ?? '');
        $adProperty = reqCanonicalProperty($ad['property_type'] ?? '');

        if ($request['transaction_type'] === 'سرمایه‌گذاری') {
            $noPriority = isset($request['no_priority']) && $request['no_priority'];
            if ($noPriority) {
                // همه نوع ملک پذیرفته می‌شود، امتیاز کامل
                $propertyScore = 20;
            } else {
                $priorities = [
                    1 => $request['priority_1'] ?? '',
                    2 => $request['priority_2'] ?? '',
                    3 => $request['priority_3'] ?? ''
                ];
                // اگر همه اولویت‌ها خالی باشند، همه را بپذیر (امتیاز کامل)
                if (empty($priorities[1]) && empty($priorities[2]) && empty($priorities[3])) {
                    $propertyScore = 20;
                } else {
                    $found = false;
                    foreach ($priorities as $level => $pType) {
                        if (!empty($pType) && reqCanonicalProperty($pType) === $adProperty) {
                            $found = true;
                            if ($level == 1) $propertyScore = 20;
                            elseif ($level == 2) $propertyScore = 15;
                            elseif ($level == 3) $propertyScore = 10;
                            break;
                        }
                    }
                    if (!$found) $propertyScore = 0;
                }
            }
        } else {
            // حالت عادی: تطابق دقیق نوع ملک الزامی است
            if ($requestProperty !== $adProperty) return 0;
            $propertyScore = 20;
        }

        if ($propertyScore == 0) return 0; // اگر نوع ملک قابل قبول نباشد

        // امتیاز نوع معامله (۲۵ یا ۲۰)
        $score += $transactionScore;

        // 20% نوع ملک (با امتیاز محاسبه‌شده)
        $score += $propertyScore;

        // 15% محدوده
        $score +=
            reqLocationScore(
                $request['location'],
                $ad['location'] ?? ''
            ) * 15;

        // 15% متراژ
        $details = reqJsonObject(
            $ad['property_details'] ?? []
        );

        $score +=
            reqRangeScore(
                $request['min_area'],
                $request['max_area'],
                reqAdArea($ad, $details)
            ) * 15;

        // 15% بودجه
        $transaction = reqCanonicalTransaction(
            $request['transaction_type']
        );

        if ($transaction === 'اجاره') {

            $depositScore = reqPriceScore(
                $request['min_deposit'],
                $request['max_deposit'],
                $ad['deposit']
                    ?? $ad['full_rent']
                    ?? ''
            );

            $rentScore = reqPriceScore(
                $request['min_rent'],
                $request['max_rent'],
                $ad['rent_monthly']
                    ?? ''
            );

            $hasDepositRange =
                reqNumber($request['min_deposit']) !== null ||
                reqNumber($request['max_deposit']) !== null;

            $hasRentRange =
                reqNumber($request['min_rent']) !== null ||
                reqNumber($request['max_rent']) !== null;

            if (
                $hasDepositRange &&
                $hasRentRange
            ) {
                $budgetScore = ($depositScore + $rentScore) / 2;
                $score += $budgetScore * 15;
            } elseif ($hasDepositRange) {
                $budgetScore = $depositScore;
                $score += $budgetScore * 15;
            } elseif ($hasRentRange) {
                $budgetScore = $rentScore;
                $score += $budgetScore * 15;
            } else {
                $budgetScore = 1.0;
                $score += 15;
            }

        } elseif ($transaction === 'رهن کامل') {

            $budgetScore = reqPriceScore(
                $request['min_deposit']
                    ?: $request['min_price'],
                $request['max_deposit']
                    ?: $request['max_price'],
                $ad['full_rent']
                    ?? $ad['deposit']
                    ?? ''
            );
            $score += $budgetScore * 15;

        } else {
            // فروش، پیش فروش، سرمایه‌گذاری (همگی قیمت خرید)
            $price = reqAdPrice($ad, $transaction)['price'] ?? '';
            $budgetScore = reqPriceScore($request['min_price'], $request['max_price'], $price);
            $score += $budgetScore * 15;
        }

        // اگر امتیاز بودجه صفر باشد، یعنی قیمت خارج از محدوده است، کل امتیاز را صفر می‌کنیم
        if ($budgetScore == 0) {
            return 0;
        }

        // 10% امکانات
        $wantedAmenities = array_map(
            'reqNormalizeText',
            $request['amenities']
        );

        $adAmenities = reqJsonArray(
            $ad['amenities'] ?? []
        );

        $adAmenities = array_map(
            'reqNormalizeText',
            $adAmenities
        );

        if (!$wantedAmenities) {

            $score += 10;

        } else {

            $hits = 0;

            foreach ($wantedAmenities as $wanted) {

                if (
                    $wanted !== '' &&
                    in_array(
                        $wanted,
                        $adAmenities,
                        true
                    )
                ) {
                    $hits++;
                }
            }

            $score +=
                ($hits / count($wantedAmenities)) * 10;
        }

        return (int)round(
            min(
                100,
                max(0, $score)
            )
        );
    }

    function reqGenerateTrackingCode($requests)
    {
        $date = date('Ymd');
        $max = 0;

        foreach ($requests as $item) {

            $code = (string)(
                $item['tracking_code']
                ?? ''
            );

            if (
                preg_match(
                    '/^REQ-' .
                    $date .
                    '-(\d{4})$/',
                    $code,
                    $m
                )
            ) {
                $max = max(
                    $max,
                    (int)$m[1]
                );
            }
        }

        return 'REQ-' .
            $date .
            '-' .
            str_pad(
                (string)($max + 1),
                4,
                '0',
                STR_PAD_LEFT
            );
    }

    // =========================================================
    // ساخت درخواست اولیه
    // =========================================================

    $request = [
        'tracking_code' => '',
        'created_at' => date('Y-m-d H:i:s'),
        'telegram_id' => $telegram_id,
        'gender' => $gender,
        'last_name' => $last_name,
        'phone' => $phone,
        'transaction_type' => $transaction_type,
        'property_type' => $property_type,
        'location' => $location,
        'urgency' => $urgency,
        'date_needed' => $date_needed,
        'date_needed_gregorian' => $date_needed_gregorian,
        'rahn_kamal' => $rahn_kamal,
        'min_area' => $min_area,
        'max_area' => $max_area,
        'min_price' => $min_price,
        'max_price' => $max_price,
        'min_deposit' => $min_deposit,
        'max_deposit' => $max_deposit,
        'min_rent' => $min_rent,
        'max_rent' => $max_rent,
        'min_age' => $min_age,
        'max_age' => $max_age,
        'is_not_keyed' => $is_not_keyed,
        'amenities' => $amenities,
        'additional' => $additional,
        'status' => 'new',
        'matches' => [],
        'notifications' => [],
        'priority_1' => $priority1,
        'priority_2' => $priority2,
        'priority_3' => $priority3,
        'no_priority' => $no_priority
    ];

    // =========================================================
    // اتصال به دیتابیس
    // این فایل قبلاً یک اتصال PDO کاملاً جداگانه (علاوه بر همان $pdo که
    // config.php می‌سازد) باز می‌کرد. روی هاست‌هایی مثل InfinityFree که
    // تعداد اتصال هم‌زمان به دیتابیس محدود است، این کار غیرضروری و پرخطر
    // بود. حالا فقط از همان اتصال مشترک config.php استفاده می‌شود.
    // =========================================================

    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/db_helpers.php';

    if (!($pdo instanceof PDO)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['success' => false, 'message' => 'اتصال به دیتابیس برقرار نشد.'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    try {

        // =====================================================
        // نرمال‌سازی تاریخ میلادی
        // =====================================================

        $normalizeDate = static function ($value) {

            $value = trim((string)$value);

            if ($value === '') {
                return null;
            }

            return preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $value
            )
                ? $value
                : null;
        };

        // اگر JS تاریخ میلادی ارسال نکرده بود،
        // فعلاً مقدار date_needed بررسی می‌شود.
        // در حالت عادی date_needed_gregorian مقدار اصلی است.
        $databaseDateNeeded = $date_needed_gregorian !== ''
            ? $date_needed_gregorian
            : $date_needed;

        // =====================================================
        // ساخت / به‌روزرسانی کاربر
        // قبلاً این کار فقط وقتی telegram_id موجود بود انجام می‌شد؛
        // یعنی درخواست‌های ثبت‌شده از مرورگر عادی (فقط با شماره)
        // هیچ‌وقت در جدول users و در بخش «کاربران» پنل ادمین دیده
        // نمی‌شدند. حالا از تابع مشترک استفاده می‌شود که هر دو حالت
        // را پوشش می‌دهد.
        // =====================================================

        $providedToken = $_COOKIE['melkino_access_token'] ?? '';
        $identity = melkinoUpsertUser($telegram_id, $phone, $last_name, '', $providedToken);
        $userId = $identity['id'];

        // =====================================================
        // تولید کد رهگیری
        // =====================================================

        $dateKey = date('Ymd');

        $stmt = $pdo->prepare(
            "SELECT tracking_code
             FROM property_requests
             WHERE tracking_code LIKE :prefix
             ORDER BY id DESC
             LIMIT 1"
        );

        $stmt->execute([
            ':prefix' => 'REQ-' . $dateKey . '-%'
        ]);

        $lastCode = (string)(
            $stmt->fetchColumn() ?: ''
        );

        $nextNumber = 1;

        if (
            preg_match(
                '/^REQ-' .
                preg_quote($dateKey, '/') .
                '-(\d{4})$/',
                $lastCode,
                $m
            )
        ) {
            $nextNumber =
                ((int)$m[1]) + 1;
        }

        $trackingCode =
            'REQ-' .
            $dateKey .
            '-' .
            str_pad(
                (string)$nextNumber,
                4,
                '0',
                STR_PAD_LEFT
            );

        // جلوگیری از تکرار در شرایط همزمانی
        for (
            $attempt = 0;
            $attempt < 5;
            $attempt++
        ) {

            $check = $pdo->prepare(
                'SELECT 1
                 FROM property_requests
                 WHERE tracking_code = :code
                 LIMIT 1'
            );

            $check->execute([
                ':code' => $trackingCode
            ]);

            if (!$check->fetchColumn()) {
                break;
            }

            $nextNumber++;

            $trackingCode =
                'REQ-' .
                $dateKey .
                '-' .
                str_pad(
                    (string)$nextNumber,
                    4,
                    '0',
                    STR_PAD_LEFT
                );
        }

        // =====================================================
        // ساخت نهایی درخواست
        // =====================================================

        $request = [
            'tracking_code' => $trackingCode,
            'created_at' => date('Y-m-d H:i:s'),
            'telegram_id' => $telegram_id,
            'gender' => $gender,
            'last_name' => $last_name,
            'phone' => $phone,
            'transaction_type' => $transaction_type,
            'property_type' => $property_type,
            'location' => $location,
            'urgency' => $urgency,
            'date_needed' => $date_needed,
            'date_needed_gregorian' => $date_needed_gregorian,
            'rahn_kamal' => $rahn_kamal,
            'min_area' => $min_area,
            'max_area' => $max_area,
            'min_price' => $min_price,
            'max_price' => $max_price,
            'min_deposit' => $min_deposit,
            'max_deposit' => $max_deposit,
            'min_rent' => $min_rent,
            'max_rent' => $max_rent,
            'min_age' => $min_age,
            'max_age' => $max_age,
            'is_not_keyed' => $is_not_keyed,
            'amenities' => $amenities,
            'additional' => $additional,
            'status' => 'new',
            'matches' => [],
            'notifications' => [],
            'priority_1' => $priority1,
            'priority_2' => $priority2,
            'priority_3' => $priority3,
            'no_priority' => $no_priority
        ];

        // =====================================================
        // شروع تراکنش
        // =====================================================

        $pdo->beginTransaction();

        // =====================================================
        // ذخیره درخواست
        // =====================================================

        $stmt = $pdo->prepare(
            'INSERT INTO property_requests
            (
                tracking_code,
                user_id,
                telegram_id,
                gender,
                last_name,
                phone,
                transaction_type,
                property_type,
                location,
                urgency,
                date_needed,
                rahn_kamal,
                min_area,
                max_area,
                min_price,
                max_price,
                min_deposit,
                max_deposit,
                min_rent,
                max_rent,
                min_age,
                max_age,
                is_not_keyed,
                status,
                additional,
                property_details,
                created_at
            )
            VALUES
            (
                :tracking_code,
                :user_id,
                :telegram_id,
                :gender,
                :last_name,
                :phone,
                :transaction_type,
                :property_type,
                :location,
                :urgency,
                :date_needed,
                :rahn_kamal,
                :min_area,
                :max_area,
                :min_price,
                :max_price,
                :min_deposit,
                :max_deposit,
                :min_rent,
                :max_rent,
                :min_age,
                :max_age,
                :is_not_keyed,
                :status,
                :additional,
                :property_details,
                :created_at
            )'
        );

        $stmt->execute([
            ':tracking_code' => $trackingCode,

            ':user_id' => $userId,

            ':telegram_id' =>
                $telegram_id !== ''
                    ? $telegram_id
                    : null,

            ':gender' =>
                $gender !== ''
                    ? $gender
                    : null,

            ':last_name' =>
                $last_name !== ''
                    ? $last_name
                    : null,

            ':phone' =>
                $phone !== ''
                    ? $phone
                    : null,

            ':transaction_type' =>
                $transaction_type !== ''
                    ? $transaction_type
                    : null,

            ':property_type' =>
                $property_type !== ''
                    ? $property_type
                    : null,

            ':location' =>
                $location !== ''
                    ? $location
                    : null,

            ':urgency' =>
                $urgency !== ''
                    ? $urgency
                    : null,

            ':date_needed' =>
                $normalizeDate($databaseDateNeeded),

            ':rahn_kamal' =>
                $rahn_kamal === 'بله'
                    ? 1
                    : 0,

            ':min_area' =>
                reqNumber($min_area),

            ':max_area' =>
                reqNumber($max_area),

            ':min_price' =>
                reqNumber($min_price),

            ':max_price' =>
                reqNumber($max_price),

            ':min_deposit' =>
                reqNumber($min_deposit),

            ':max_deposit' =>
                reqNumber($max_deposit),

            ':min_rent' =>
                reqNumber($min_rent),

            ':max_rent' =>
                reqNumber($max_rent),

            ':min_age' =>
                $min_age !== ''
                    ? $min_age
                    : null,

            ':max_age' =>
                $max_age !== ''
                    ? $max_age
                    : null,

            ':is_not_keyed' =>
                $is_not_keyed === 'بله'
                    ? 1
                    : 0,

            ':status' => 'new',

            ':additional' =>
                json_encode(
                    $additional,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                ),

            ':property_details' =>
                json_encode(
                    [],
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                ),

            ':created_at' =>
                $request['created_at'],
        ]);

        $requestId = (int)$pdo->lastInsertId();

        // نکته‌ی امنیتی: تایپ‌کردن یک شماره در فرم، اثبات مالکیت آن
        // نیست. قبلاً اینجا $_SESSION['user_phone'] بی‌قیدوشرط پر
        // می‌شد؛ یعنی اگه کسی به‌جای شماره‌ی خودش شماره‌ی یک نفر
        // دیگه رو تایپ می‌کرد، سشنش به هویت اون فرد وصل می‌شد و
        // «ملک‌های من»/«درخواست‌های من» اون فرد رو می‌دید! حالا این
        // وصل‌شدن فقط وقتی انجام می‌شه که واقعاً خودِ این مرورگر صاحب
        // اون شماره باشه.
        if ($telegram_id !== '') {
            $_SESSION['reg_telegram_id'] = $telegram_id;
        }
        if (!empty($identity['trusted'])) {
            if ($phone !== '') {
                $_SESSION['user_phone'] = $phone;
            }
            if ($last_name !== '') {
                $_SESSION['user_name'] = $last_name;
            }
            if (!empty($identity['token'])) {
                setcookie('melkino_access_token', $identity['token'], time() + 31536000, '/', '', false, true);
            }
        }

        // =====================================================
        // دریافت آگهی‌های منتشرشده
        // =====================================================

        $adsStmt = $pdo->query(
            "SELECT
                id,
                title,
                status,
                transaction_type,
                property_type,
                location,
                address,
                deposit,
                rent_monthly,
                full_rent,
                price_sell,
                total_price,
                price_condition,
                property_details,
                price_hidden
             FROM ads
             WHERE status = 'published'
             ORDER BY created_at DESC, id DESC"
        );

        $ads = $adsStmt->fetchAll();

        // =====================================================
        // امکانات هر آگهی از جدول رابطه‌ای
        // =====================================================

        if ($ads) {

            $amenityStmt = $pdo->query(
                "SELECT
                    aa.ad_id,
                    a.name
                 FROM ad_amenities aa
                 INNER JOIN amenities a
                    ON a.id = aa.amenity_id
                 ORDER BY aa.ad_id, a.id"
            );

            foreach (
                $amenityStmt->fetchAll()
                as $row
            ) {

                $adId =
                    (string)$row['ad_id'];

                foreach (
                    $ads as &$adRef
                ) {

                    if (
                        (string)$adRef['id'] === $adId
                    ) {

                        if (
                            !isset($adRef['amenities']) ||
                            !is_array($adRef['amenities'])
                        ) {
                            $adRef['amenities'] = [];
                        }

                        $adRef['amenities'][] =
                            $row['name'];

                        break;
                    }
                }

                unset($adRef);
            }
        }

        // =====================================================
        // محاسبه تطبیق برای هر آگهی
        // =====================================================

        $matches = [];

        foreach ($ads as $ad) {

            $ad['amenities'] =
                $ad['amenities'] ?? [];

            $matchPercent =
                reqMatchAd(
                    $request,
                    $ad
                );

            if ($matchPercent >= 50) {

                $matches[] = [
                    'ad_id' =>
                        $ad['id'] ?? '',

                    'title' =>
                        $ad['title']
                            ??
                        (
                            ($ad['property_type'] ?? 'ملک') .
                            ' در ' .
                            ($ad['location'] ?? '')
                        ),

                    'property_type' =>
                        $ad['property_type'] ?? '',

                    'transaction_type' =>
                        $ad['transaction_type'] ?? '',

                    'location' =>
                        $ad['location'] ?? '',

                    'match_percent' =>
                        $matchPercent,

                    'price' =>
                        $ad['price_sell'] ?? $ad['total_price'] ?? 0
                ];
            }
        }

        usort(
            $matches,
            static function ($a, $b) {
                return
                    $b['match_percent']
                    <=>
                    $a['match_percent'];
            }
        );

        $matches =
            array_slice(
                $matches,
                0,
                5
            );

        $request['matches'] =
            $matches;

        // =====================================================
        // تولید ترکیب‌های سرمایه‌گذاری (در صورت نیاز)
        // =====================================================
        $combinations = [];
        if ($transaction_type === 'سرمایه‌گذاری') {
            $allAdsWithScore = [];
            foreach ($ads as $ad) {
                $score = reqMatchAd($request, $ad);
                if ($score > 0) {
                    $price = (float)reqNumber($ad['price_sell'] ?? $ad['total_price'] ?? 0);
                    if ($price > 0) {
                        $allAdsWithScore[] = [
                            'id' => $ad['id'],
                            'title' => $ad['title'] ?? (($ad['property_type'] ?? 'ملک') . ' در ' . ($ad['location'] ?? '')),
                            'property_type' => $ad['property_type'] ?? '',
                            'price' => $price,
                            'match_score' => $score,
                            'ad_data' => $ad
                        ];
                    }
                }
            }
            $budgetMin = (float)reqNumber($min_price) ?: 0;
            $budgetMax = (float)reqNumber($max_price) ?: PHP_INT_MAX;
            $combinations = generateCombinations($allAdsWithScore, $budgetMin, $budgetMax, 10);
            
            // ذخیره ترکیب‌ها در additional
            $additional['combinations'] = $combinations;
        }

        // =====================================================
        // ذخیره تطبیق‌ها
        // =====================================================

        $matchStmt = $pdo->prepare(
            'INSERT INTO request_matches
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
                :request_id,
                :ad_id,
                :match_percent,
                :matched_transaction,
                :matched_property_type,
                :location_score,
                :area_score,
                :budget_score,
                :amenities_score,
                :is_notified,
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
                    VALUES(amenities_score),

                is_notified =
                    VALUES(is_notified)'
        );

        foreach ($matches as $match) {

            $adRow = null;

            foreach ($ads as $candidate) {

                if (
                    (string)(
                        $candidate['id'] ?? ''
                    )
                    ===
                    (string)(
                        $match['ad_id']
                    )
                ) {
                    $adRow = $candidate;
                    break;
                }
            }

            if (!$adRow) {
                continue;
            }

            $details =
                reqJsonObject(
                    $adRow['property_details']
                        ?? []
                );

            $transactionMatched =
                reqCanonicalTransaction(
                    $request['transaction_type']
                )
                ===
                reqCanonicalTransaction(
                    $adRow['transaction_type']
                        ?? ''
                )
                ? 1
                : 0;

            $propertyMatched = 1; // قبلاً در reqMatchAd بررسی شده

            $locationScore =
                reqLocationScore(
                    $request['location'],
                    $adRow['location']
                        ?? ''
                ) * 100;

            $areaScore =
                reqRangeScore(
                    $request['min_area'],
                    $request['max_area'],
                    reqAdArea(
                        $adRow,
                        $details
                    )
                ) * 100;

            $canonicalTx =
                reqCanonicalTransaction(
                    $request['transaction_type']
                );

            if ($canonicalTx === 'اجاره') {

                $depScore =
                    reqPriceScore(
                        $request['min_deposit'],
                        $request['max_deposit'],
                        $adRow['deposit']
                            ?? $adRow['full_rent']
                            ?? ''
                    );

                $rentScore =
                    reqPriceScore(
                        $request['min_rent'],
                        $request['max_rent'],
                        $adRow['rent_monthly']
                            ?? ''
                    );

                $hasDepositRange =
                    reqNumber(
                        $request['min_deposit']
                    ) !== null
                    ||
                    reqNumber(
                        $request['max_deposit']
                    ) !== null;

                $hasRentRange =
                    reqNumber(
                        $request['min_rent']
                    ) !== null
                    ||
                    reqNumber(
                        $request['max_rent']
                    ) !== null;

                if (
                    $hasDepositRange &&
                    $hasRentRange
                ) {

                    $budgetScore =
                        (
                            (
                                $depScore +
                                $rentScore
                            ) / 2
                        ) * 100;

                } elseif ($hasDepositRange) {

                    $budgetScore =
                        $depScore * 100;

                } elseif ($hasRentRange) {

                    $budgetScore =
                        $rentScore * 100;

                } else {

                    $budgetScore = 100;
                }

            } elseif (
                $canonicalTx === 'رهن کامل'
            ) {

                $budgetScore =
                    reqPriceScore(
                        $request['min_deposit']
                            ?: $request['min_price'],
                        $request['max_deposit']
                            ?: $request['max_price'],
                        $adRow['full_rent']
                            ?? $adRow['deposit']
                            ?? ''
                    ) * 100;

            } else {

                $budgetScore =
                    reqPriceScore(
                        $request['min_price'],
                        $request['max_price'],
                        reqAdPrice(
                            $adRow,
                            $canonicalTx
                        )['price']
                            ?? ''
                    ) * 100;
            }

            // اگر budgetScore صفر باشد، یعنی قیمت آگهی خارج از محدوده است، این آگهی را نادیده می‌گیریم
            if ($budgetScore == 0) {
                continue;
            }

            $wantedAmenities =
                array_map(
                    'reqNormalizeText',
                    $request['amenities']
                );

            $adAmenities =
                array_map(
                    'reqNormalizeText',
                    $adRow['amenities']
                        ?? []
                );

            $amenitiesScore =
                !$wantedAmenities
                    ? 100
                    : (
                        count($wantedAmenities)
                            ? (
                                count(
                                    array_intersect(
                                        $wantedAmenities,
                                        $adAmenities
                                    )
                                )
                                /
                                count($wantedAmenities)
                            ) * 100
                            : 100
                    );

            $matchStmt->execute([
                ':request_id' =>
                    $requestId,

                ':ad_id' =>
                    $match['ad_id'],

                ':match_percent' =>
                    (int)$match['match_percent'],

                ':matched_transaction' =>
                    $transactionMatched,

                ':matched_property_type' =>
                    $propertyMatched,

                ':location_score' =>
                    min(
                        100,
                        max(
                            0,
                            $locationScore
                        )
                    ),

                ':area_score' =>
                    min(
                        100,
                        max(
                            0,
                            $areaScore
                        )
                    ),

                ':budget_score' =>
                    min(
                        100,
                        max(
                            0,
                            $budgetScore
                        )
                    ),

                ':amenities_score' =>
                    min(
                        100,
                        max(
                            0,
                            $amenitiesScore
                        )
                    ),

                ':is_notified' =>
                    $match['match_percent'] >= 70
                        ? 1
                        : 0,
            ]);
        }

        // =====================================================
        // ذخیره امکانات درخواست
        // =====================================================

        if ($amenities) {

            $amenityLookup = $pdo->prepare(
                'SELECT id
                 FROM amenities
                 WHERE name = :name
                 LIMIT 1'
            );

            $requestAmenityInsert = $pdo->prepare(
                'INSERT IGNORE INTO request_amenities
                 (
                    request_id,
                    amenity_id
                 )
                 VALUES
                 (
                    :request_id,
                    :amenity_id
                 )'
            );

            foreach ($amenities as $amenityName) {

                $amenityLookup->execute([
                    ':name' =>
                        $amenityName
                ]);

                $amenityId =
                    $amenityLookup->fetchColumn();

                if ($amenityId !== false) {

                    $requestAmenityInsert->execute([
                        ':request_id' =>
                            $requestId,

                        ':amenity_id' =>
                            (int)$amenityId,
                    ]);
                }
            }
        }

        // =====================================================
        // نوتیفیکیشن‌های مچ بالای 70٪
        // =====================================================

        $request['notifications'] = [];

        $notificationStmt = $pdo->prepare(
            'INSERT INTO notifications
            (
                user_id,
                telegram_id,
                request_id,
                ad_id,
                type,
                title,
                message,
                url,
                match_percent,
                is_read,
                created_at
            )
            VALUES
            (
                :user_id,
                :telegram_id,
                :request_id,
                :ad_id,
                :type,
                :title,
                :message,
                :url,
                :match_percent,
                0,
                NOW()
            )'
        );

        foreach ($matches as $match) {

            if (
                ($match['match_percent'] ?? 0)
                < 70
            ) {
                continue;
            }

            $notification = [
                'id' =>
                    uniqid(
                        'NTF-',
                        true
                    ),

                'type' =>
                    'property_match',

                'created_at' =>
                    date('Y-m-d H:i:s'),

                'read' =>
                    false,

                'title' =>
                    'ملک مناسب برای شما پیدا شد',

                'message' =>
                    'ملک «' .
                    $match['title'] .
                    '» با درخواست شما ' .
                    $match['match_percent'] .
                    '٪ مطابقت دارد.',

                'ad_id' =>
                    $match['ad_id'],

                'match_percent' =>
                    $match['match_percent']
            ];

            $request['notifications'][] =
                $notification;

            $notificationStmt->execute([
                ':user_id' =>
                    $userId,

                ':telegram_id' =>
                    $telegram_id !== ''
                        ? $telegram_id
                        : null,

                ':request_id' =>
                    $requestId,

                ':ad_id' =>
                    $match['ad_id'],

                ':type' =>
                    'property_match',

                ':title' =>
                    $notification['title'],

                ':message' =>
                    $notification['message'],

                ':url' =>
                    'property-details.php?id=' .
                    urlencode(
                        (string)$match['ad_id']
                    ) .
                    '&from=request-matches&code=' .
                    urlencode(
                        $trackingCode
                    ),

                ':match_percent' =>
                    (int)$match['match_percent'],
            ]);
        }

        // به‌روزرسانی additional در صورت وجود ترکیب‌ها
        if ($transaction_type === 'سرمایه‌گذاری' && !empty($combinations)) {
            $updateStmt = $pdo->prepare('UPDATE property_requests SET additional = :additional WHERE id = :id');
            $updateStmt->execute([
                ':additional' => json_encode($additional, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id' => $requestId
            ]);
        }

        // =====================================================
        // پایان تراکنش
        // =====================================================

        $pdo->commit();

    } catch (Throwable $e) {

        if (
            isset($pdo) &&
            $pdo instanceof PDO &&
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        http_response_code(500);

        echo
            '<div dir="rtl"
                style="
                    font-family:Tahoma;
                    padding:30px;
                    background:#fff;
                    color:#222
                ">'

            . '<h2 style="color:#b00020">
                    خطا در ثبت درخواست
               </h2>'

            . '<p>
                    در ذخیره درخواست در دیتابیس
                    مشکلی پیش آمد.
               </p>'

            . '<pre
                style="
                    direction:ltr;
                    text-align:left;
                    background:#f5f5f5;
                    padding:15px;
                    border-radius:10px;
                    white-space:pre-wrap
                ">'

            . htmlspecialchars(
                $e->getMessage(),
                ENT_QUOTES,
                'UTF-8'
            )

            . '</pre></div>';

        exit;
    }

    // =========================================================
    // صفحه موفقیت
    // =========================================================

    $topMatches = $matches;
    $combos = $combinations ?? [];
    $trackingCode =
        $request['tracking_code'];

    echo "
<!DOCTYPE html>
<html lang='fa' dir='rtl'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport'
          content='width=device-width, initial-scale=1.0'>

    <title>درخواست شما ثبت شد</title>

    <style>

        body{
            margin:0;
            background:#f5f1e8;
            color:#182322;
            font-family:Vazirmatn,Tahoma,sans-serif;
        }

        .success-page{
            max-width:680px;
            margin:0 auto;
            padding:35px 18px 50px;
        }

        .success-card{
            background:#fff;
            border:1px solid #e2ded3;
            border-radius:20px;
            padding:28px;
            box-shadow:
                0 12px 35px rgba(0,0,0,.07);
        }

        .success-icon{
            width:72px;
            height:72px;
            border-radius:50%;
            display:flex;
            align-items:center;
            justify-content:center;
            background:#0b5d5b;
            color:#fff;
            font-size:34px;
            margin:0 auto 18px;
        }

        h1{
            text-align:center;
            font-size:24px;
            margin:0 0 10px;
        }

        .muted{
            text-align:center;
            color:#6b7472;
            line-height:1.9;
        }

        .tracking{
            margin:24px 0;
            padding:18px;
            border-radius:15px;
            background:#f4f0e5;
            text-align:center;
        }

        .tracking small{
            display:block;
            color:#737a78;
            margin-bottom:7px;
        }

        .tracking strong{
            font-size:24px;
            color:#0b5d5b;
            letter-spacing:1px;
            direction:ltr;
            display:block;
        }

        .match-title{
            font-size:18px;
            font-weight:800;
            margin:25px 0 12px;
        }

        .match{
            display:block;
            padding:14px;
            border:1px solid #e3e0d7;
            border-radius:14px;
            margin:10px 0;
            background:#fff;
            text-decoration:none;
            color:inherit;
            transition:.2s;
        }

        .match:hover{
            transform:translateY(-1px);
            box-shadow:
                0 8px 18px rgba(0,0,0,.06);
        }

        .match-head{
            display:flex;
            justify-content:space-between;
            gap:10px;
            align-items:center;
        }

        .match-name{
            font-weight:800;
        }

        .match-percent{
            font-weight:900;
            color:#0b5d5b;
        }

        .match-meta{
            font-size:13px;
            color:#737a78;
            margin-top:7px;
        }

        .empty{
            padding:18px;
            border-radius:14px;
            background:#f7f6f2;
            color:#6b7472;
            line-height:1.9;
        }

        .matches-btn{
            display:block;
            text-align:center;
            margin-top:16px;
            padding:13px;
            border-radius:13px;
            background:#f4f0e5;
            color:#0b5d5b;
            text-decoration:none;
            font-weight:800;
            border:1px solid #d8d1bf;
        }

        .home-btn{
            display:block;
            text-align:center;
            margin-top:10px;
            padding:14px;
            border-radius:13px;
            background:#0b5d5b;
            color:#fff;
            text-decoration:none;
            font-weight:800;
        }

        .combo-card{
            border:2px solid #0b5d5b;
            border-radius:14px;
            padding:14px;
            margin:12px 0;
            background:#f0f7f6;
        }

        .combo-price{
            font-weight:800;
            color:#0b5d5b;
            font-size:16px;
        }

        .combo-items{
            margin-top:8px;
        }

        .combo-item{
            display:inline-block;
            background:#fff;
            padding:4px 12px;
            border-radius:30px;
            margin:4px;
            border:1px solid #d8d1bf;
        }

        .request-match-badge{
            display:inline-flex;
            align-items:center;
            gap:5px;
            padding:5px 10px;
            border-radius:999px;
            background:rgba(11,93,91,.10);
            color:#0b5d5b;
            font-size:12px;
            font-weight:800;
        }

    </style>
</head>

<body>

    <main class='success-page'>

        <section class='success-card'>

            <div class='success-icon'>✓</div>

            <h1>
                درخواست شما با موفقیت ثبت شد
            </h1>

            <p class='muted'>
                درخواست شما برای بررسی و پیدا کردن
                ملک مناسب ثبت شد.
                نتیجه تطبیق نیز در همین لحظه بررسی شده است.
            </p>

            <div class='tracking'>

                <small>
                    کد رهگیری درخواست شما
                </small>

                <strong>"
                    . htmlspecialchars(
                        $trackingCode,
                        ENT_QUOTES,
                        'UTF-8'
                    )
                . "</strong>

            </div>
";

    // نمایش ترکیب‌های سرمایه‌گذاری
    if ($transaction_type === 'سرمایه‌گذاری' && !empty($combos)) {
        echo "<div class='match-title'>💰 ترکیب‌های پیشنهادی برای سرمایه‌گذاری</div>";
        foreach ($combos as $combo) {
            $totalPrice = number_format($combo['total_price']);
            echo "<div class='combo-card'>";
            echo "<div class='combo-price'>مجموع قیمت: {$totalPrice} تومان</div>";
            echo "<div class='combo-items'>";
            foreach ($combo['items'] as $item) {
                $title = htmlspecialchars($item['title'] ?? 'ملک');
                $price = number_format($item['price']);
                echo "<span class='combo-item'>{$title} - {$price} تومان</span>";
            }
            echo "</div></div>";
        }
    }

    echo "
            <div class='match-title'>
                🔎 نزدیک‌ترین فایل‌ها به درخواست شما
            </div>
";

    if ($topMatches) {

        foreach ($topMatches as $match) {

            echo "
            <a class='match'
               href='property-details.php?id="
                . urlencode(
                    (string)(
                        $match['ad_id'] ?? ''
                    )
                )
                . "&from=request-matches&code="
                . urlencode(
                    $trackingCode
                )
                . "'>

                <div class='match-head'>

                    <span class='match-name'>"
                        . htmlspecialchars(
                            $match['title'],
                            ENT_QUOTES,
                            'UTF-8'
                        )
                    . "</span>

                    <span class='match-percent'>"
                        . (int)$match['match_percent']
                        . "٪ تطبیق</span>

                </div>

                <div class='match-meta'>"
                    . htmlspecialchars(
                        (
                            ($match['property_type'] ?? '') .
                            ' · ' .
                            ($match['transaction_type'] ?? '') .
                            ' · ' .
                            ($match['location'] ?? '')
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    )
                    . " · مشاهده آگهی ←</div>

            </a>
";
        }

    } else {

        echo "
        <div class='empty'>
            فعلاً فایل مناسبی با معیارهای شما پیدا نشد.
            به محض ثبت یا انتشار فایل نزدیک به درخواست شما،
            نتیجه به شما اعلام خواهد شد.
        </div>
";
    }

    echo "
            <a class='matches-btn'
               href='property-request-matches.php?code="
        . urlencode($trackingCode)
        . "'>
                🔎 مشاهده همه فایل‌های مطابق
            </a>

            <a class='home-btn'
               href='home.php'>
                بازگشت به خانه
            </a>

        </section>

    </main>

    <script>

    (function(){

        var telegramId = "
            . json_encode(
                $telegram_id,
                JSON_UNESCAPED_UNICODE
            )
            . ";

        var trackingCode = "
            . json_encode(
                $trackingCode,
                JSON_UNESCAPED_UNICODE
            )
            . ";

        var matches = "
            . json_encode(
                $topMatches,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
            . ";

        try {

            if (telegramId) {

                sessionStorage.setItem(
                    'reg_telegram_id',
                    String(telegramId)
                );

                localStorage.setItem(
                    'melkino_telegram_id',
                    String(telegramId)
                );
            }

            var key =
                'melkino_notifications';

            var list = [];

            try {

                list = JSON.parse(
                    localStorage.getItem(key) || '[]'
                );

                if (!Array.isArray(list)) {
                    list = [];
                }

            } catch (e) {
                list = [];
            }

            var fresh = [];

            matches.forEach(
                function(match, index){

                    var percent =
                        Number(
                            match.match_percent || 0
                        );

                    var adId =
                        String(
                            match.ad_id || ''
                        );

                    if (
                        percent < 70 ||
                        !adId
                    ) {
                        return;
                    }

                    var duplicate =
                        list.some(
                            function(existing){

                                return existing &&
                                    existing.type ===
                                        'property_match' &&
                                    String(
                                        existing.tracking_code || ''
                                    ) === String(
                                        trackingCode
                                    ) &&
                                    String(
                                        existing.ad_id || ''
                                    ) === adId;
                            }
                        );

                    if (duplicate) {
                        return;
                    }

                    fresh.push({
                        id:
                            'match-' +
                            Date.now() +
                            '-' +
                            index,

                        type:
                            'property_match',

                        text:
                            '🏠 ' +
                            (
                                match.title ||
                                'ملک مناسب'
                            ) +
                            ' با درخواست شما ' +
                            percent +
                            '٪ مطابقت دارد.',

                        time:
                            'همین الان',

                        timestamp:
                            Date.now() +
                            index,

                        read:
                            false,

                        ad_id:
                            adId,

                        match_percent:
                            percent,

                        tracking_code:
                            trackingCode,

                        telegram_id:
                            telegramId,

                        url:
                            'property-details.php?id=' +
                            encodeURIComponent(adId) +
                            '&from=request-matches&code=' +
                            encodeURIComponent(
                                trackingCode
                            )
                    });
                }
            );

            localStorage.setItem(
                key,
                JSON.stringify(
                    fresh
                    .concat(list)
                    .slice(0, 100)
                )
            );

            localStorage.setItem(
                'melkino_last_request_code',
                trackingCode
            );

        } catch (e) {

            console.error(
                'Melkino notification error:',
                e
            );
        }

    })();

    </script>

</body>
</html>
";

    exit;
}

require_once __DIR__ . '/header.php';
?>

<style>

.main-content{
    flex:1;
    overflow-y:auto;
    background:var(--bg);
    padding:0 var(--space-3);
    padding-bottom:150px;
    display:flex;
    flex-direction:column;
}

.step-content{
    display:none;
    flex-direction:column;
    gap:var(--space-2);
    animation:fadeIn .3s ease forwards;
}

.step-content.active{
    display:flex;
}

@keyframes fadeIn{
    from{
        opacity:0;
        transform:translateY(10px);
    }

    to{
        opacity:1;
        transform:translateY(0);
    }
}

.step-title{
    font-size:20px;
    font-weight:800;
    color:var(--text-primary);
    margin-top:var(--space-2);
    margin-bottom:var(--space-1);
}

.step-subtitle{
    font-size:14px;
    color:var(--text-secondary);
    margin-bottom:var(--space-2);
}

.summary-card{
    background:var(--surface);
    border-radius:var(--radius-md);
    padding:var(--space-2);
    border:1px solid var(--border);
    margin-bottom:var(--space-2);
}

.summary-row{
    display:flex;
    justify-content:space-between;
    gap:12px;
    font-size:14px;
    padding:var(--space-1) 0;
    border-bottom:1px solid var(--border);
}

.summary-row:last-child{
    border-bottom:none;
}

.summary-label{
    color:var(--text-secondary);
}

.summary-value{
    color:var(--text-primary);
    font-weight:600;
    text-align:left;
}

.bottom-actions{
    position:fixed;
    right:0;
    left:0;
    bottom:70px;
    width:100%;
    padding:var(--space-2) var(--space-3);
    background:var(--surface);
    border-top:1px solid var(--border);
    display:flex;
    gap:var(--space-2);
    z-index:9999;
    box-sizing:border-box;
    box-shadow:
        0 -4px 15px rgba(0,0,0,.08);
}

.bottom-actions
.btn-secondary,
.bottom-actions
.btn-primary-full{
    flex:1;
    height:56px;
    min-height:56px;
    min-width:80px;
    border-radius:var(--radius-md);
    font-weight:700;
    font-size:16px;
    display:flex;
    justify-content:center;
    align-items:center;
    border:none;
    padding:0 var(--space-2);
    box-sizing:border-box;
}

.bottom-actions
.btn-secondary{
    background:var(--bg);
    color:var(--text-secondary);
    border:1px solid var(--border);
}

.bottom-actions
.btn-primary-full{
    background:var(--primary);
    color:#fff;
}

.final-actions{
    display:none;
    gap:var(--space-2);
    margin-top:var(--space-3);
}

.btn-edit{
    flex:1;
    height:56px;
    border-radius:var(--radius-md);
    font-weight:700;
    border:1px solid var(--border);
    background:var(--bg);
    color:var(--text-secondary);
    cursor:pointer;
    font-family:'Vazirmatn',sans-serif;
}

.btn-submit{
    flex:1;
    height:56px;
    border-radius:var(--radius-md);
    font-weight:700;
    border:none;
    background:var(--gold);
    color:#111827;
    cursor:pointer;
    font-family:'Vazirmatn',sans-serif;
    font-size:16px;
}

.btn-submit:disabled{
    opacity:.65;
    cursor:not-allowed;
}

.final-bottom-actions{
    width:100%;
    display:none;
    gap:var(--space-2);
}

.final-bottom-actions button{
    flex:1;
    height:56px;
    min-height:56px;
    border-radius:var(--radius-md);
    font-family:'Vazirmatn',sans-serif;
    font-size:16px;
    font-weight:700;
    cursor:pointer;
}

.rahn-kamal-wrapper{
    display:flex;
    align-items:center;
    gap:var(--space-1);
    margin-top:var(--space-1);
    background:var(--gold-bg);
    padding:var(--space-1) var(--space-2);
    border-radius:var(--radius-sm);
    border:1px solid var(--gold);
}

.rahn-kamal-wrapper
input[type="checkbox"]{
    width:18px;
    height:18px;
    accent-color:var(--gold);
    cursor:pointer;
}

.rahn-kamal-wrapper label{
    font-weight:600;
    color:var(--text-primary);
    font-size:14px;
    cursor:pointer;
}

.amenities-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:var(--space-1);
    margin-top:var(--space-1);
}

.checkbox-label{
    display:flex;
    align-items:center;
    gap:var(--space-1);
    font-size:14px;
    color:var(--text-primary);
    cursor:pointer;
}

.checkbox-label
input[type="checkbox"]{
    width:18px;
    height:18px;
    accent-color:var(--primary);
}

.form-group{
    display:flex;
    flex-direction:column;
    gap:var(--space-1);
    margin-bottom:var(--space-2);
}

.form-group label{
    font-size:14px;
    font-weight:600;
    color:var(--text-primary);
}

.form-input,
.form-select{
    width:100%;
    height:50px;
    border-radius:var(--radius-sm);
    border:1px solid var(--border);
    background:var(--bg);
    padding:0 var(--space-2);
    font-size:15px;
    font-family:'Vazirmatn',sans-serif;
    color:var(--text-primary);
    outline:none;
    transition:border .2s ease;
    box-sizing:border-box;
}

.form-input:focus,
.form-select:focus{
    border-color:var(--primary);
}

.form-textarea{
    width:100%;
    border-radius:var(--radius-sm);
    border:1px solid var(--border);
    background:var(--bg);
    padding:12px var(--space-2);
    font-size:15px;
    font-family:'Vazirmatn',sans-serif;
    color:var(--text-primary);
    outline:none;
    resize:vertical;
    box-sizing:border-box;
}

.form-textarea:focus{
    border-color:var(--primary);
}

.options-group{
    display:flex;
    flex-wrap:wrap;
    gap:var(--space-1);
}

.option-btn{
    padding:10px 16px;
    border-radius:var(--radius-sm);
    border:1px solid var(--border);
    background:var(--bg);
    font-family:'Vazirmatn',sans-serif;
    font-size:14px;
    font-weight:500;
    color:var(--text-secondary);
    cursor:pointer;
    transition:all .2s ease;
}

.option-btn.selected{
    background:rgba(6,78,78,.1);
    border-color:var(--primary);
    color:var(--primary);
}

.option-btn:active{
    transform:scale(.95);
}

.row-half{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:var(--space-2);
}

.advanced-toggle{
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--radius-sm);
    padding:10px 16px;
    font-family:'Vazirmatn',sans-serif;
    font-size:14px;
    font-weight:600;
    color:var(--text-secondary);
    cursor:pointer;
    transition:.3s;
    display:flex;
    align-items:center;
    justify-content:space-between;
    width:100%;
    margin:var(--space-2) 0 var(--space-1);
}

.advanced-toggle:hover{
    border-color:var(--primary);
    color:var(--primary);
}

.advanced-toggle .arrow{
    transition:transform .3s;
    display:inline-block;
}

.advanced-toggle .arrow.open{
    transform:rotate(180deg);
}

.advanced-content{
    display:none;
    padding:var(--space-2);
    background:var(--bg);
    border-radius:var(--radius-sm);
    border:1px solid var(--border);
    margin-bottom:var(--space-2);
}

.advanced-content.open{
    display:block;
}

.not-keyed-wrapper{
    display:flex;
    align-items:center;
    gap:var(--space-1);
    margin:var(--space-1) 0;
    padding:var(--space-1) var(--space-2);
    background:var(--gold-bg);
    border-radius:var(--radius-sm);
    border:1px solid var(--gold);
}

.not-keyed-wrapper
input[type="checkbox"]{
    width:18px;
    height:18px;
    accent-color:var(--gold);
    cursor:pointer;
}

.not-keyed-wrapper label{
    font-weight:600;
    color:var(--text-primary);
    font-size:14px;
    cursor:pointer;
}

.persian-datepicker{
    direction:rtl;
}

.persian-datepicker .picker{
    font-family:'Vazirmatn',sans-serif !important;
}

@media (max-width:480px){

    .bottom-actions{
        bottom:64px;
        padding:10px 12px;
    }

    .bottom-actions .btn-secondary,
    .bottom-actions .btn-primary-full,
    .final-bottom-actions button{
        height:52px;
        min-height:52px;
        font-size:14px;
    }

    .row-half{
        grid-template-columns:1fr;
        gap:0;
    }

    .amenities-grid{
        grid-template-columns:1fr;
    }
}

/* استایل‌های جدید برای اولویت‌ها */
.priority-group{
    display:flex;
    flex-direction:column;
    gap:8px;
    margin-top:8px;
}

.priority-row{
    display:flex;
    align-items:center;
    gap:12px;
}

.priority-row label{
    font-weight:600;
    min-width:80px;
}

.priority-row select{
    flex:1;
}

.no-priority-check{
    display:flex;
    align-items:center;
    gap:8px;
    margin-top:12px;
}

.no-priority-check input{
    width:20px;
    height:20px;
    accent-color:var(--primary);
}

</style>

<!-- =========================================================
     کتابخانه‌های مورد نیاز تاریخ شمسی
========================================================= -->

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/persian-date@1.0.0/dist/persian-date.min.js"></script>

<link
    rel="stylesheet" media="print" onload="this.media='all'"
    href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.min.css"
>

<script src="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js"></script>

<div class="main-content" id="mainContent">

    <form
        method="POST"
        action="property-request.php"
        id="requestForm"
        style="
            flex:1;
            display:flex;
            flex-direction:column;
        "
    >

        <input
            type="hidden"
            name="request_form_submit"
            value="1"
        >

        <!-- تاریخ میلادی برای دیتابیس -->
        <input
            type="hidden"
            name="date_needed_gregorian"
            id="dateNeededGregorian"
            value=""
        >

        <!-- =====================================================
             STEP 1
        ====================================================== -->

        <div
            class="step-content active"
            id="reqStep1"
        >

            <h2 class="step-title">
                اطلاعات تماس
            </h2>

            <input
                type="hidden"
                id="reqTelegramId"
                name="telegram_id"
                value=""
            >

            <div class="form-group">

                <label>
                    جنسیت
                </label>

                <div
                    class="options-group"
                    id="reqGender"
                >

                    <div
                        class="option-btn selected"
                        onclick="selectOption(this,'reqGender')"
                        data-value="آقا"
                    >
                        آقا
                    </div>

                    <div
                        class="option-btn"
                        onclick="selectOption(this,'reqGender')"
                        data-value="خانم"
                    >
                        خانم
                    </div>

                </div>

                <input
                    type="hidden"
                    name="gender"
                    id="genderInput"
                    value="آقا"
                >

            </div>

            <div class="form-group">

                <label>
                    نام خانوادگی
                </label>

                <input
                    type="text"
                    class="form-input"
                    id="reqLastName"
                    name="last_name"
                    placeholder="نام خانوادگی خود را وارد کنید"
                    required
                >

            </div>

            <div class="form-group">

                <label>
                    شماره تماس
                </label>

                <input
                    type="tel"
                    class="form-input"
                    id="reqPhone"
                    name="phone"
                    placeholder="مثلاً ۰۹۱۲۳۴۵۶۷۸۹"
                    required
                >

            </div>

        </div>

        <!-- =====================================================
             STEP 2
        ====================================================== -->

        <div
            class="step-content"
            id="reqStep2"
        >

            <h2 class="step-title">
                نوع معامله
            </h2>

            <div class="form-group">

                <label>
                    نوع معامله
                </label>

                <div
                    class="options-group"
                    id="reqTrans"
                >

                    <div
                        class="option-btn selected"
                        onclick="selectTransaction('فروش',this)"
                        data-value="فروش"
                    >
                        خرید
                    </div>

                    <div
                        class="option-btn"
                        onclick="selectTransaction('پیش فروش',this)"
                        data-value="پیش فروش"
                    >
                        پیش فروش
                    </div>

                    <div
                        class="option-btn"
                        onclick="selectTransaction('اجاره',this)"
                        data-value="اجاره"
                    >
                        اجاره
                    </div>

                    <!-- گزینه جدید سرمایه‌گذاری -->
                    <div
                        class="option-btn"
                        onclick="selectTransaction('سرمایه‌گذاری',this)"
                        data-value="سرمایه‌گذاری"
                    >
                        سرمایه‌گذاری
                    </div>

                </div>

                <input
                    type="hidden"
                    name="transaction_type"
                    id="transactionTypeInput"
                    value="فروش"
                >

            </div>

        </div>

        <!-- =====================================================
             STEP 3
        ====================================================== -->

        <div
            class="step-content"
            id="reqStep3"
        >

            <h2 class="step-title" id="step3Title">
                نوع ملک
            </h2>

            <!-- بخش انتخاب نوع ملک (حالت عادی) -->
            <div id="propertyTypeSelection">

                <div class="form-group">

                    <label>
                        نوع ملک
                    </label>

                    <div
                        class="options-group"
                        id="reqProp"
                    >

                        <div
                            class="option-btn selected"
                            onclick="selectPropertyType('آپارتمان',this)"
                            data-value="آپارتمان"
                        >
                            آپارتمان
                        </div>

                        <div
                            class="option-btn"
                            onclick="selectPropertyType('ویلا',this)"
                            data-value="ویلا"
                        >
                            ویلا
                        </div>

                        <div
                            class="option-btn"
                            id="reqPropLand"
                            onclick="selectPropertyType('زمین',this)"
                            data-value="زمین"
                        >
                            زمین
                        </div>

                        <div
                            class="option-btn"
                            onclick="selectPropertyType('باغ',this)"
                            data-value="باغ"
                        >
                            باغ
                        </div>

                        <div
                            class="option-btn"
                            onclick="selectPropertyType('اداری',this)"
                            data-value="اداری"
                        >
                            اداری
                        </div>

                        <div
                            class="option-btn"
                            onclick="selectPropertyType('تجاری',this)"
                            data-value="تجاری"
                        >
                            تجاری
                        </div>

                    </div>

                    <input
                        type="hidden"
                        name="property_type"
                        id="propertyTypeInput"
                        value="آپارتمان"
                    >

                </div>

            </div>

            <!-- بخش انتخاب اولویت‌ها (برای سرمایه‌گذاری) -->
            <div id="prioritySelection" style="display:none;">

                <div class="form-group">

                    <label>
                        اولویت‌های نوع ملک (به ترتیب اهمیت)
                    </label>

                    <div class="priority-group">

                        <div class="priority-row">
                            <label>اولویت اول</label>
                            <select name="priority_1" id="priority_1" class="form-select">
                                <option value="">انتخاب کنید</option>
                                <option value="آپارتمان">آپارتمان</option>
                                <option value="ویلا">ویلا</option>
                                <option value="زمین">زمین</option>
                                <option value="باغ">باغ</option>
                                <option value="اداری">اداری</option>
                                <option value="تجاری">تجاری</option>
                            </select>
                        </div>

                        <div class="priority-row">
                            <label>اولویت دوم</label>
                            <select name="priority_2" id="priority_2" class="form-select">
                                <option value="">انتخاب کنید</option>
                                <option value="آپارتمان">آپارتمان</option>
                                <option value="ویلا">ویلا</option>
                                <option value="زمین">زمین</option>
                                <option value="باغ">باغ</option>
                                <option value="اداری">اداری</option>
                                <option value="تجاری">تجاری</option>
                            </select>
                        </div>

                        <div class="priority-row">
                            <label>اولویت سوم</label>
                            <select name="priority_3" id="priority_3" class="form-select">
                                <option value="">انتخاب کنید</option>
                                <option value="آپارتمان">آپارتمان</option>
                                <option value="ویلا">ویلا</option>
                                <option value="زمین">زمین</option>
                                <option value="باغ">باغ</option>
                                <option value="اداری">اداری</option>
                                <option value="تجاری">تجاری</option>
                            </select>
                        </div>

                    </div>

                    <div class="no-priority-check">
                        <input type="checkbox" id="noPriority" name="no_priority" value="1">
                        <label for="noPriority">بدون اولویت (همه نوع ملک قابل قبول)</label>
                    </div>

                </div>

            </div>

            <div class="form-group">

                <label>
                    محله / منطقه
                </label>

                <input
                    type="text"
                    class="form-input"
                    id="reqLocation"
                    name="location"
                    placeholder="محله یا منطقه مورد نظر را وارد کنید"
                >

            </div>

        </div>

        <!-- =====================================================
             STEP 4
        ====================================================== -->

        <div
            class="step-content"
            id="reqStep4"
        >

            <h2
                class="step-title"
                id="step4Title"
            >
                فیلترهای جستجو
            </h2>

            <div id="dynamicStep4"></div>

            <div
                id="rahnKamalContainer"
                style="display:none;"
            >

                <div class="rahn-kamal-wrapper">

                    <input
                        type="checkbox"
                        id="reqRahnKamal"
                        name="rahn_kamal"
                    >

                    <label for="reqRahnKamal">
                        دنبال رهن کامل می‌گردم
                    </label>

                </div>

            </div>

        </div>

        <!-- =====================================================
             STEP 5
        ====================================================== -->

        <div
            class="step-content"
            id="reqStep5"
        >

            <h2 class="step-title">
                زمان‌بندی و ثبت نهایی
            </h2>

            <div class="form-group">

                <label>
                    تاریخ نیاز
                </label>

                <input
                    type="text"
                    class="form-input"
                    id="reqDate"
                    name="date_needed"
                    style="padding:0 var(--space-2);"
                    placeholder="تاریخ را انتخاب کنید"
                    autocomplete="off"
                >

            </div>

            <div class="form-group">

                <label>
                    فوریت درخواست
                </label>

                <div
                    class="options-group"
                    id="reqUrgency"
                >

                    <div
                        class="option-btn selected"
                        onclick="selectOption(this,'reqUrgency')"
                        data-value="فوری"
                    >
                        فوری
                    </div>

                    <div
                        class="option-btn"
                        onclick="selectOption(this,'reqUrgency')"
                        data-value="ظرف یک‌ماه"
                    >
                        ظرف یک‌ماه
                    </div>

                    <div
                        class="option-btn"
                        onclick="selectOption(this,'reqUrgency')"
                        data-value="بدون عجله"
                    >
                        بدون عجله
                    </div>

                </div>

                <input
                    type="hidden"
                    name="urgency"
                    id="urgencyInput"
                    value="فوری"
                >

            </div>

            <div class="form-group">

                <label>
                    توضیحات تکمیلی
                </label>

                <textarea
                    class="form-textarea"
                    name="additional_notes"
                    rows="3"
                    placeholder="اگر نیاز یا خواسته‌ای دارید که در فرم وجود ندارد با ما در میان بگذارید."
                ></textarea>

            </div>

            <div
                class="summary-card"
                id="finalSummary"
            >

                <h3
                    style="
                        font-size:16px;
                        font-weight:700;
                        color:var(--text-primary);
                    "
                >
                    خلاصه درخواست
                </h3>

                <div id="summaryContainer"></div>

            </div>

            <div
                class="final-actions"
                id="finalActionsInline"
            >

                <button
                    type="button"
                    class="btn-edit"
                    onclick="editRequest()"
                >
                    ✏️ ویرایش اطلاعات
                </button>

                <button
                    type="submit"
                    class="btn-submit"
                    id="finalSubmitInline"
                >
                    📩 ثبت نهایی درخواست
                </button>

            </div>

        </div>

    </form>

    <!-- =====================================================
         Bottom Navigation
    ====================================================== -->

    <div
        class="bottom-actions"
        id="bottomNav"
    >

        <button
            type="button"
            class="btn-secondary"
            id="prevReqBtn"
            onclick="changeReqStep(-1)"
            style="display:none;"
        >
            مرحله قبل
        </button>

        <button
            type="button"
            class="btn-primary-full"
            id="nextReqBtn"
            onclick="changeReqStep(1)"
        >
            مرحله بعد
        </button>

        <div
            class="final-bottom-actions"
            id="finalBottomActions"
        >

            <button
                type="button"
                class="btn-edit"
                onclick="editRequest()"
            >
                ✏️ ویرایش
            </button>

            <button
                type="button"
                class="btn-submit"
                id="finalSubmitBtn"
                onclick="submitRequestForm()"
            >
                📩 ثبت نهایی
            </button>

        </div>

    </div>

</div>

<script>

var currentReqStep = 1;
var totalReqSteps = 5;

var selectedTransaction = 'فروش';
var selectedProperty = 'آپارتمان';

var ageRanges = [

    {
        value:'0-5',
        label:'۰ تا ۵ سال'
    },

    {
        value:'5-10',
        label:'۵ تا ۱۰ سال'
    },

    {
        value:'10-15',
        label:'۱۰ تا ۱۵ سال'
    },

    {
        value:'15-20',
        label:'۱۵ تا ۲۰ سال'
    },

    {
        value:'20-plus',
        label:'۲۰ سال و بیشتر'
    }

];

/* =========================================================
   Telegram
========================================================= */

function loadTelegramId(){

    var tgId =
        sessionStorage.getItem(
            'reg_telegram_id'
        );

    if(!tgId){

        try{

            var tg =
                window.Telegram &&
                window.Telegram.WebApp;

            if(tg){

                var user =
                    tg.initDataUnsafe &&
                    tg.initDataUnsafe.user;

                if(user && user.id){
                    tgId = user.id;
                }
            }

        }catch(e){}
    }

    // قبلاً وقتی سایت داخل تلگرام باز نمی‌شد، یک آی‌دی ساختگی و ثابت
    // (۱۲۳۴۵۶۷۸۹) برای همه ثبت می‌شد و همه یک نفر به‌حساب می‌آمدند.
    // حالا اگر تلگرام در دسترس نبود، این فیلد خالی می‌ماند و شناسایی
    // فقط از طریق شماره تماس واقعی (که پایین‌تر پر می‌شود) انجام می‌شود.

    var el =
        document.getElementById(
            'reqTelegramId'
        );

    if(el && tgId){
        el.value = tgId;
    }

    var phoneEl =
        document.getElementById(
            'reqPhone'
        );

    var savedPhone =
        localStorage.getItem(
            'melkino_user_phone'
        );

    if(phoneEl && !phoneEl.value && savedPhone){
        phoneEl.value = savedPhone;
    }
}

/* =========================================================
   انتخاب‌ها
========================================================= */

function selectOption(el,groupId){

    var parent =
        document.getElementById(
            groupId
        );

    if(!parent){
        return;
    }

    var btns =
        parent.querySelectorAll(
            '.option-btn'
        );

    for(
        var i=0;
        i<btns.length;
        i++
    ){
        btns[i]
            .classList
            .remove('selected');
    }

    el.classList.add('selected');

    var value =
        el.getAttribute(
            'data-value'
        )
        ||
        el.innerText;

    if(groupId === 'reqGender'){

        document.getElementById(
            'genderInput'
        ).value = value;

    }else if(
        groupId === 'reqUrgency'
    ){

        document.getElementById(
            'urgencyInput'
        ).value = value;
    }
}

/* =========================================================
   انتخاب نوع معامله (با پشتیبانی از سرمایه‌گذاری)
========================================================= */

function selectTransaction(type,el){

    selectedTransaction = type;

    var btns =
        document
            .getElementById('reqTrans')
            .querySelectorAll(
                '.option-btn'
            );

    for(
        var i=0;
        i<btns.length;
        i++
    ){
        btns[i]
            .classList
            .remove('selected');
    }

    el.classList.add('selected');

    document.getElementById(
        'transactionTypeInput'
    ).value = type;

    // =========================================================
    // تغییر نمایش مرحله ۳ بر اساس نوع معامله
    // =========================================================
    var isInvestment = (type === 'سرمایه‌گذاری');
    var propertyTypeSel = document.getElementById('propertyTypeSelection');
    var prioritySel = document.getElementById('prioritySelection');
    var step3Title = document.getElementById('step3Title');

    if (isInvestment) {
        propertyTypeSel.style.display = 'none';
        prioritySel.style.display = 'block';
        step3Title.innerText = 'اولویت‌های نوع ملک';
        // خالی کردن property_type چون در این حالت استفاده نمی‌شود
        document.getElementById('propertyTypeInput').value = '';
    } else {
        propertyTypeSel.style.display = 'block';
        prioritySel.style.display = 'none';
        step3Title.innerText = 'نوع ملک';
        // اگر قبلاً انتخاب نشده، آپارتمان پیش‌فرض
        if (!document.getElementById('propertyTypeInput').value) {
            document.getElementById('propertyTypeInput').value = 'آپارتمان';
        }
    }

    updateRahnKamalVisibility();
    updatePropertyTypeVisibility();
    updateSpecsStep();

    if(
        currentReqStep === 5
    ){
        updateSummary();
    }
}

function selectPropertyType(type,el){

    selectedProperty = type;

    var btns =
        document
            .getElementById('reqProp')
            .querySelectorAll(
                '.option-btn'
            );

    for(
        var i=0;
        i<btns.length;
        i++
    ){
        btns[i]
            .classList
            .remove('selected');
    }

    el.classList.add('selected');

    document.getElementById(
        'propertyTypeInput'
    ).value = type;

    updateSpecsStep();

    if(
        currentReqStep === 5
    ){
        updateSummary();
    }
}

/* =========================================================
   مخفی کردن زمین برای پیش فروش
========================================================= */

function updatePropertyTypeVisibility(){

    var landBtn =
        document.getElementById(
            'reqPropLand'
        );

    if(!landBtn){
        return;
    }

    if(
        selectedTransaction ===
        'پیش فروش'
    ){

        landBtn.style.display =
            'none';

        if(
            landBtn.classList
                .contains('selected')
        ){

            landBtn.classList
                .remove('selected');

            var firstVisible =
                document.querySelector(
                    '#reqProp .option-btn:not([style*="display: none"])'
                );

            if(firstVisible){

                firstVisible
                    .classList
                    .add('selected');

                selectPropertyType(
                    firstVisible.getAttribute(
                        'data-value'
                    ),
                    firstVisible
                );
            }
        }

    }else{

        landBtn.style.display =
            '';
    }
}

/* =========================================================
   اعتبارسنجی
========================================================= */

function validateStep1(){

    var gender =
        document.getElementById(
            'genderInput'
        ).value;

    var lastName =
        document.getElementById(
            'reqLastName'
        ).value.trim();

    var phone =
        document.getElementById(
            'reqPhone'
        ).value.trim();

    if(!gender){

        alert(
            'لطفاً جنسیت خود را انتخاب کنید.'
        );

        return false;
    }

    if(lastName === ''){

        alert(
            'لطفاً نام خانوادگی خود را وارد کنید.'
        );

        return false;
    }

    if(phone === ''){

        alert(
            'لطفاً شماره تماس خود را وارد کنید.'
        );

        return false;
    }

    var telegramIdField =
        document.getElementById(
            'reqTelegramId'
        );

    var hasTelegramId =
        telegramIdField &&
        telegramIdField.value.trim() !== '';

    // اگر از تلگرام باز نشده (آی‌دی تلگرام نداریم)، شماره باید یک
    // موبایل واقعی ایرانی باشد؛ وگرنه شناسایی کاربر بی‌معنی می‌شود.
    if(!hasTelegramId){

        var normalizedPhone =
            phone.replace(/[۰-۹]/g, function(d){
                return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);
            });

        if(!/^09\d{9}$/.test(normalizedPhone)){

            alert(
                'لطفاً یک شماره موبایل معتبر وارد کنید (مثلاً ۰۹۱۲۳۴۵۶۷۸۹).'
            );

            return false;
        }

        localStorage.setItem(
            'melkino_user_phone',
            phone
        );
    }

    return true;
}

/* =========================================================
   سن بنا
========================================================= */

function renderAgeSelect(
    name,
    placeholder
){

    var html =
        '<select class="form-select" name="' +
        name +
        '">' +
        '<option value="">' +
        placeholder +
        '</option>';

    for(
        var i=0;
        i<ageRanges.length;
        i++
    ){

        html +=
            '<option value="' +
            ageRanges[i].value +
            '">' +
            ageRanges[i].label +
            '</option>';
    }

    html += '</select>';

    return html;
}

/* =========================================================
   Step 4 - فیلترهای جستجو
   برای سرمایه‌گذاری فقط حداقل و حداکثر قیمت نمایش داده می‌شود
========================================================= */

function updateSpecsStep(){

    var container =
        document.getElementById(
            'dynamicStep4'
        );

    if(!container){
        return;
    }

    var html = '';

    // اگر نوع معامله سرمایه‌گذاری است، فقط قیمت‌ها نمایش داده شوند
    if (selectedTransaction === 'سرمایه‌گذاری') {
        html +=
            '<div class="row-half">' +
            '<div class="form-group">' +
            '<label>حداقل قیمت (تومان)</label>' +
            '<input type="text" class="form-input price-input" name="min_price" placeholder="۵۰۰,۰۰۰,۰۰۰" required>' +
            '</div>' +
            '<div class="form-group">' +
            '<label>حداکثر قیمت (تومان)</label>' +
            '<input type="text" class="form-input price-input" name="max_price" placeholder="۲,۰۰۰,۰۰۰,۰۰۰" required>' +
            '</div>' +
            '</div>';

        container.innerHTML = html;
        return;
    }

    // حالت عادی (غیر سرمایه‌گذاری) - نمایش تمام فیلدها
    html +=
        '<div class="form-group">' +
        '<label>فیلدهای اصلی جستجو</label>' +
        '</div>';

    html +=
        '<div class="row-half">' +

        '<div class="form-group">' +
        '<label>حداقل متراژ (متر مربع)</label>' +
        '<input type="number" ' +
        'class="form-input" ' +
        'name="min_area" ' +
        'placeholder="۶۰">' +
        '</div>' +

        '<div class="form-group">' +
        '<label>حداکثر متراژ (متر مربع)</label>' +
        '<input type="number" ' +
        'class="form-input" ' +
        'name="max_area" ' +
        'placeholder="۱۲۰">' +
        '</div>' +

        '</div>';

    var showAge =
        selectedProperty === 'آپارتمان' ||
        selectedProperty === 'ویلا' ||
        selectedProperty === 'اداری' ||
        selectedProperty === 'تجاری';

    if(showAge){

        html +=
            '<div class="row-half">' +

            '<div class="form-group">' +
            '<label>حداقل سن بنا</label>' +
            renderAgeSelect(
                'min_age',
                'حداقل سن'
            ) +
            '</div>' +

            '<div class="form-group">' +
            '<label>حداکثر سن بنا</label>' +
            renderAgeSelect(
                'max_age',
                'حداکثر سن'
            ) +
            '</div>' +

            '</div>';

        html +=
            '<div class="not-keyed-wrapper">' +

            '<input ' +
            'type="checkbox" ' +
            'id="isNotKeyed" ' +
            'name="is_not_keyed" ' +
            'value="1">' +

            '<label for="isNotKeyed">' +
            'کلید نخورده' +
            '</label>' +

            '</div>';
    }

    if(
        selectedTransaction === 'فروش' ||
        selectedTransaction === 'پیش فروش'
    ){

        html +=
            '<div class="row-half">' +

            '<div class="form-group">' +
            '<label>حداقل قیمت (تومان)</label>' +
            '<input ' +
            'type="text" ' +
            'class="form-input price-input" ' +
            'name="min_price" ' +
            'placeholder="۵۰۰,۰۰۰,۰۰۰" required>' +
            '</div>' +

            '<div class="form-group">' +
            '<label>حداکثر قیمت (تومان)</label>' +
            '<input ' +
            'type="text" ' +
            'class="form-input price-input" ' +
            'name="max_price" ' +
            'placeholder="۲,۰۰۰,۰۰۰,۰۰۰" required>' +
            '</div>' +

            '</div>';

    }else if(
        selectedTransaction === 'اجاره'
    ){

        html +=
            '<div class="row-half">' +

            '<div class="form-group">' +
            '<label>حداقل ودیعه (تومان)</label>' +
            '<input ' +
            'type="text" ' +
            'class="form-input price-input" ' +
            'name="min_deposit" ' +
            'placeholder="۲۰۰,۰۰۰,۰۰۰" required>' +
            '</div>' +

            '<div class="form-group">' +
            '<label>حداکثر ودیعه (تومان)</label>' +
            '<input ' +
            'type="text" ' +
            'class="form-input price-input" ' +
            'name="max_deposit" ' +
            'placeholder="۵۰۰,۰۰۰,۰۰۰" required>' +
            '</div>' +

            '</div>' +

            '<div class="row-half">' +

            '<div class="form-group">' +
            '<label>حداقل اجاره ماهانه (تومان)</label>' +
            '<input ' +
            'type="text" ' +
            'class="form-input price-input" ' +
            'name="min_rent" ' +
            'placeholder="۲۰,۰۰۰,۰۰۰" required>' +
            '</div>' +

            '<div class="form-group">' +
            '<label>حداکثر اجاره ماهانه (تومان)</label>' +
            '<input ' +
            'type="text" ' +
            'class="form-input price-input" ' +
            'name="max_rent" ' +
            'placeholder="۵۰,۰۰۰,۰۰۰" required>' +
            '</div>' +

            '</div>';
    }

    html +=
        '<button ' +
        'type="button" ' +
        'class="advanced-toggle" ' +
        'onclick="toggleAdvanced()">' +

        '🔍 جستجوی پیشرفته ' +
        '<span class="arrow" id="advancedArrow">▼</span>' +

        '</button>';

    html +=
        '<div ' +
        'class="advanced-content" ' +
        'id="advancedContent">';

    /* ---------- آپارتمان ---------- */

    if(
        selectedProperty === 'آپارتمان'
    ){

        html += `
            <div class="row-half">

                <div class="form-group">
                    <label>طبقه</label>
                    <input
                        type="number"
                        class="form-input"
                        name="floor"
                        placeholder="۳"
                    >
                </div>

                <div class="form-group">
                    <label>تعداد اتاق</label>

                    <select
                        class="form-select"
                        name="rooms"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option value="۱">۱</option>
                        <option value="۲">۲</option>
                        <option value="۳">۳</option>
                        <option value="۴">۴</option>
                        <option value="۵">۵</option>
                    </select>
                </div>

            </div>

            <div class="row-half">

                <div class="form-group">
                    <label>سال ساخت</label>
                    <input
                        type="number"
                        class="form-input"
                        name="year"
                        placeholder="۱۴۰۲"
                    >
                </div>

                <div class="form-group">

                    <label>نوع کفپوش</label>

                    <select
                        class="form-select"
                        name="flooring"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>سرامیک</option>
                        <option>پارکت</option>
                        <option>موکت</option>
                        <option>سنگ</option>
                        <option>کفپوش</option>
                    </select>

                </div>

            </div>

            <div class="row-half">

                <div class="form-group">

                    <label>نوع کابینت</label>

                    <select
                        class="form-select"
                        name="cabinet"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>ام دی اف</option>
                        <option>هایگلاس</option>
                        <option>چوبی</option>
                        <option>فلزی</option>
                    </select>

                </div>

                <div class="form-group">

                    <label>سیستم سرمایش</label>

                    <select
                        class="form-select"
                        name="cooling"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>کولر آبی</option>
                        <option>اسپیلت</option>
                        <option>داکت اسپلیت</option>
                        <option>چیلر</option>
                        <option>پنکه سقفی</option>
                    </select>

                </div>

            </div>

            <div class="form-group">

                <label>سیستم گرمایش</label>

                <select
                    class="form-select"
                    name="heating"
                >
                    <option value="">
                        انتخاب کنید
                    </option>
                    <option>بخاری</option>
                    <option>شوفاژ</option>
                    <option>پکیج رادیاتور</option>
                </select>

            </div>
        `;

    /* ---------- ویلا ---------- */

    }else if(
        selectedProperty === 'ویلا'
    ){

        html += `
            <div class="row-half">

                <div class="form-group">
                    <label>زیربنا (متر مربع)</label>
                    <input
                        type="number"
                        class="form-input"
                        name="built_area"
                        placeholder="۲۵۰"
                    >
                </div>

                <div class="form-group">

                    <label>تعداد اتاق</label>

                    <select
                        class="form-select"
                        name="rooms"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option value="۱">۱</option>
                        <option value="۲">۲</option>
                        <option value="۳">۳</option>
                        <option value="۴">۴</option>
                        <option value="۵">۵</option>
                    </select>

                </div>

            </div>

            <div class="row-half">

                <div class="form-group">
                    <label>سال ساخت</label>
                    <input
                        type="number"
                        class="form-input"
                        name="year"
                        placeholder="۱۴۰۲"
                    >
                </div>

                <div class="form-group">

                    <label>نوع کفپوش</label>

                    <select
                        class="form-select"
                        name="flooring"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>سرامیک</option>
                        <option>پارکت</option>
                        <option>موکت</option>
                        <option>سنگ</option>
                        <option>کفپوش</option>
                    </select>

                </div>

            </div>

            <div class="row-half">

                <div class="form-group">

                    <label>نوع کابینت</label>

                    <select
                        class="form-select"
                        name="cabinet"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>ام دی اف</option>
                        <option>هایگلاس</option>
                        <option>چوبی</option>
                        <option>فلزی</option>
                    </select>

                </div>

                <div class="form-group">

                    <label>سیستم سرمایش</label>

                    <select
                        class="form-select"
                        name="cooling"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>کولر آبی</option>
                        <option>اسپیلت</option>
                        <option>داکت اسپلیت</option>
                        <option>چیلر</option>
                        <option>پنکه سقفی</option>
                    </select>

                </div>

            </div>

            <div class="form-group">

                <label>سیستم گرمایش</label>

                <select
                    class="form-select"
                    name="heating"
                >
                    <option value="">
                        انتخاب کنید
                    </option>
                    <option>بخاری</option>
                    <option>شوفاژ</option>
                    <option>پکیج رادیاتور</option>
                </select>

            </div>
        `;

    /* ---------- زمین ---------- */

    }else if(
        selectedProperty === 'زمین'
    ){

        html += `
            <div class="form-group">

                <label>نوع کاربری</label>

                <input
                    type="text"
                    class="form-input"
                    name="usage"
                    placeholder="مسکونی، تجاری، ..."
                >

            </div>

            <div class="row-half">

                <div class="form-group">

                    <label>نوع زمین</label>

                    <select
                        class="form-select"
                        name="land_type"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>مسکونی</option>
                        <option>تجاری</option>
                        <option>اداری</option>
                        <option>کشاورزی</option>
                        <option>باغی</option>
                    </select>

                </div>

                <div class="form-group">

                    <label>عرض زمین (متر)</label>

                    <input
                        type="number"
                        class="form-input"
                        name="width"
                        placeholder="۱۲"
                    >

                </div>

            </div>

            <div class="row-half">

                <div class="form-group">

                    <label>طول زمین (متر)</label>

                    <input
                        type="number"
                        class="form-input"
                        name="length"
                        placeholder="۴۲"
                    >

                </div>

                <div class="form-group">

                    <label>وضعیت سند</label>

                    <select
                        class="form-select"
                        name="deed_status"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>سند رسمی</option>
                        <option>سند عادی</option>
                        <option>قولنامه</option>
                        <option>در دست اقدام</option>
                    </select>

                </div>

            </div>

            <div class="form-group">

                <label>وضعیت مالکیت</label>

                <select
                    class="form-select"
                    name="ownership"
                >
                    <option value="">
                        انتخاب کنید
                    </option>
                    <option>شش‌دانگ</option>
                    <option>مشاع</option>
                </select>

            </div>
        `;

    /* ---------- باغ ---------- */

    }else if(
        selectedProperty === 'باغ'
    ){

        html += `
            <div class="row-half">

                <div class="form-group">

                    <label>تعداد درختان</label>

                    <input
                        type="number"
                        class="form-input"
                        name="tree_count"
                        placeholder="۵۰"
                    >

                </div>

                <div class="form-group">

                    <label>نوع درختان</label>

                    <input
                        type="text"
                        class="form-input"
                        name="tree_types"
                        placeholder="گردو، سیب، ..."
                    >

                </div>

            </div>

            <div class="row-half">

                <div class="form-group">

                    <label>نوع آبیاری</label>

                    <select
                        class="form-select"
                        name="irrigation"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>قطره‌ای</option>
                        <option>بارانی</option>
                        <option>جوی و پشته</option>
                        <option>تحت فشار</option>
                        <option>سطحی</option>
                    </select>

                </div>

                <div class="form-group">

                    <label>منبع آب</label>

                    <select
                        class="form-select"
                        name="water_source"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>چاه</option>
                        <option>قنات</option>
                        <option>آب سطحی</option>
                        <option>آب شهری</option>
                        <option>سد</option>
                    </select>

                </div>

            </div>
        `;

    /* ---------- اداری ---------- */

    }else if(
        selectedProperty === 'اداری'
    ){

        html += `
            <div class="row-half">

                <div class="form-group">
                    <label>طبقه</label>
                    <input
                        type="number"
                        class="form-input"
                        name="office_floor"
                        placeholder="۳"
                    >
                </div>

                <div class="form-group">
                    <label>تعداد واحد در طبقه</label>
                    <input
                        type="number"
                        class="form-input"
                        name="units_per_floor"
                        placeholder="۴"
                    >
                </div>

            </div>

            <div class="row-half">

                <div class="form-group">

                    <label>تعداد اتاق</label>

                    <select
                        class="form-select"
                        name="rooms"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option value="۱">۱</option>
                        <option value="۲">۲</option>
                        <option value="۳">۳</option>
                        <option value="۴">۴</option>
                        <option value="۵">۵</option>
                        <option value="۶">۶</option>
                    </select>

                </div>

                <div class="form-group">

                    <label>سال ساخت</label>

                    <input
                        type="number"
                        class="form-input"
                        name="year"
                        placeholder="۱۴۰۲"
                    >

                </div>

            </div>

            <div class="row-half">

                <div class="form-group">

                    <label>وضعیت واحد</label>

                    <select
                        class="form-select"
                        name="condition"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>نوساز</option>
                        <option>بازسازی‌شده</option>
                        <option>قدیمی</option>
                    </select>

                </div>

                <div class="form-group">

                    <label>موقعیت واحد</label>

                    <select
                        class="form-select"
                        name="orientation"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>شمالی</option>
                        <option>جنوبی</option>
                        <option>شرقی</option>
                        <option>غربی</option>
                    </select>

                </div>

            </div>

            <div class="form-group">

                <label>کاربری</label>

                <select
                    class="form-select"
                    name="usage"
                >
                    <option value="">
                        انتخاب کنید
                    </option>
                    <option>اداری</option>
                    <option>دفتر کار</option>
                    <option>تجاری-اداری</option>
                </select>

            </div>
        `;

    /* ---------- تجاری ---------- */

    }else if(
        selectedProperty === 'تجاری'
    ){

        html += `
            <div class="row-half">

                <div class="form-group">

                    <label>بر مغازه (متر)</label>

                    <input
                        type="number"
                        class="form-input"
                        name="front"
                        placeholder="۶"
                    >

                </div>

                <div class="form-group">

                    <label>موقعیت</label>

                    <select
                        class="form-select"
                        name="location_type"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>خیابان اصلی</option>
                        <option>خیابان فرعی</option>
                        <option>پاساژ</option>
                        <option>گاراژ</option>
                    </select>

                </div>

            </div>

            <div class="row-half">

                <div class="form-group">

                    <label>پوشش دیوار</label>

                    <select
                        class="form-select"
                        name="wall"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>کاغذ دیواری</option>
                        <option>رنگ</option>
                        <option>پنل</option>
                        <option>گچ</option>
                        <option>سرامیک</option>
                        <option>سنگ</option>
                    </select>

                </div>

                <div class="form-group">

                    <label>نوع کفپوش</label>

                    <select
                        class="form-select"
                        name="flooring"
                    >
                        <option value="">
                            انتخاب کنید
                        </option>
                        <option>سرامیک</option>
                        <option>موکت</option>
                        <option>سنگ</option>
                        <option>موزاییک</option>
                        <option>سیمان</option>
                        <option>کفپوش</option>
                    </select>

                </div>

            </div>
        `;
    }

    html +=
        '</div>';

    container.innerHTML =
        html;
}

/* =========================================================
   Advanced
========================================================= */

function toggleAdvanced(){

    var content =
        document.getElementById(
            'advancedContent'
        );

    var arrow =
        document.getElementById(
            'advancedArrow'
        );

    if(content){

        content
            .classList
            .toggle('open');

        if(arrow){

            arrow
                .classList
                .toggle('open');
        }
    }
}

/* =========================================================
   رهن کامل
========================================================= */

function updateRahnKamalVisibility(){

    var container =
        document.getElementById(
            'rahnKamalContainer'
        );

    if(!container){
        return;
    }

    if(
        selectedTransaction ===
        'اجاره'
    ){

        container.style.display =
            'block';

    }else{

        container.style.display =
            'none';

        var chk =
            document.getElementById(
                'reqRahnKamal'
            );

        if(chk){
            chk.checked = false;
        }
    }
}

/* =========================================================
   امکانات (با پشتیبانی از سرمایه‌گذاری - نمایش همه امکانات)
========================================================= */

function updateAmenitiesStep(){

    var container =
        document.getElementById(
            'dynamicStep5'
        );

    if(!container){
        return;
    }

    var html = '';

    // اگر نوع معامله سرمایه‌گذاری است، همه امکانات را نمایش بده
    if (selectedTransaction === 'سرمایه‌گذاری') {
        html = `
            <div class="amenities-grid">

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="آسانسور"> آسانسور
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="پارکینگ"> پارکینگ
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="انباری"> انباری
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="استخر"> استخر
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="سونا"> سونا
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="جکوزی"> جکوزی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="حیاط اختصاصی"> حیاط اختصاصی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="روف گاردن"> روف گاردن
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="نگهبانی"> نگهبانی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="زیرزمین"> زیرزمین
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="گلخانه"> گلخانه
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="آب"> آب
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="برق"> برق
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="گاز"> گاز
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="تلفن"> تلفن
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="فاضلاب"> فاضلاب
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="آب شهری"> آب شهری
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="چاه"> چاه
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="دیوارکشی"> دیوارکشی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="درب ورودی"> درب ورودی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="دسترسی به خیابان اصلی"> دسترسی به خیابان اصلی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="سرویس بهداشتی"> سرویس بهداشتی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="آلاچیق"> آلاچیق
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="باربیکیو"> باربیکیو
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="لابی"> لابی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="دوربین مداربسته"> دوربین مداربسته
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="سیستم اعلام حریق"> سیستم اعلام حریق
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="اطفای حریق"> اطفای حریق
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="سیستم سرمایش"> سیستم سرمایش
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="سیستم گرمایش"> سیستم گرمایش
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="اینترنت"> اینترنت
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="آبدارخانه"> آبدارخانه
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="اتاق جلسات"> اتاق جلسات
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="شیشه سکوریت"> شیشه سکوریت
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="درب اتوماتیک"> درب اتوماتیک
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="درب فلزی"> درب فلزی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="کرکره برقی"> کرکره برقی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="کرکره معمولی"> کرکره معمولی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="بالابر"> بالابر
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="ویترین"> ویترین
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="نورپردازی"> نورپردازی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="اسپیلت"> اسپیلت
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="کولر آبی"> کولر آبی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="پکیج"> پکیج
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="بخاری"> بخاری
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="مطبخ"> مطبخ
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="بالکن"> بالکن
                </label>

                <label class="checkbox-label">
                    <input type="checkbox" name="amenities[]" value="حیاط"> حیاط
                </label>

            </div>
        `;
    } else if (
        selectedProperty ===
        'آپارتمان'
    ){

        html = `
            <div class="amenities-grid">

                <label class="checkbox-label">
                    <input
                        type="checkbox"
                        name="amenities[]"
                        value="آسانسور"
                    >
                    آسانسور
                </label>

                <label class="checkbox-label">
                    <input
                        type="checkbox"
                        name="amenities[]"
                        value="پارکینگ"
                    >
                    پارکینگ
                </label>

                <label class="checkbox-label">
                    <input
                        type="checkbox"
                        name="amenities[]"
                        value="انباری"
                    >
                    انباری
                </label>

                <label class="checkbox-label">
                    <input
                        type="checkbox"
                        name="amenities[]"
                        value="مطبخ"
                    >
                    مطبخ
                </label>

                <label class="checkbox-label">
                    <input
                        type="checkbox"
                        name="amenities[]"
                        value="بالکن"
                    >
                    بالکن
                </label>

                <label class="checkbox-label">
                    <input
                        type="checkbox"
                        name="amenities[]"
                        value="حیاط"
                    >
                    حیاط
                </label>

                <label class="checkbox-label">
                    <input
                        type="checkbox"
                        name="amenities[]"
                        value="استخر"
                    >
                    استخر
                </label>

                <label class="checkbox-label">
                    <input
                        type="checkbox"
                        name="amenities[]"
                        value="سونا"
                    >
                    سونا
                </label>

                <label class="checkbox-label">
                    <input
                        type="checkbox"
                        name="amenities[]"
                        value="جکوزی"
                    >
                    جکوزی
                </label>

                <label class="checkbox-label">
                    <input
                        type="checkbox"
                        name="amenities[]"
                        value="نگهبانی"
                    >
                    نگهبانی
                </label>

            </div>
        `;

    }else if(
        selectedProperty ===
        'ویلا'
    ){

        html = `
            <div class="amenities-grid">

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="آسانسور">
                    آسانسور
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="پارکینگ">
                    پارکینگ
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="انباری">
                    انباری
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="استخر">
                    استخر
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="سونا">
                    سونا
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="جکوزی">
                    جکوزی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="حیاط اختصاصی">
                    حیاط اختصاصی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="روف گاردن">
                    روف گاردن
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="نگهبانی">
                    نگهبانی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="زیرزمین">
                    زیرزمین
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="گلخانه">
                    گلخانه
                </label>

            </div>
        `;

    }else if(
        selectedProperty ===
        'زمین'
    ){

        html = `
            <div class="amenities-grid">

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="آب">
                    آب
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="برق">
                    برق
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="گاز">
                    گاز
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="تلفن">
                    تلفن
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="فاضلاب">
                    فاضلاب
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="آب شهری">
                    آب شهری
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="چاه">
                    چاه
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="دیوارکشی">
                    دیوارکشی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="درب ورودی">
                    درب ورودی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="دسترسی به خیابان اصلی">
                    دسترسی به خیابان اصلی
                </label>

            </div>
        `;

    }else if(
        selectedProperty ===
        'باغ'
    ){

        html = `
            <div class="amenities-grid">

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="سرویس بهداشتی">
                    سرویس بهداشتی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="پارکینگ">
                    پارکینگ
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="انباری">
                    انباری
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="آلاچیق">
                    آلاچیق
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="استخر">
                    استخر
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="سونا">
                    سونا
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="باربیکیو">
                    باربیکیو
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="برق">
                    برق
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="گاز">
                    گاز
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="آب شهری">
                    آب شهری
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="دیوارکشی">
                    دیوارکشی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="نگهبانی">
                    نگهبانی
                </label>

            </div>
        `;

    }else if(
        selectedProperty ===
        'اداری'
    ){

        html = `
            <div class="amenities-grid">

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="آسانسور">
                    آسانسور
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="پارکینگ">
                    پارکینگ
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="انباری">
                    انباری
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="لابی">
                    لابی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="نگهبانی">
                    نگهبانی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="دوربین مداربسته">
                    دوربین مداربسته
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="سیستم اعلام حریق">
                    سیستم اعلام حریق
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="اطفای حریق">
                    اطفای حریق
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="سیستم سرمایش">
                    سیستم سرمایش
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="سیستم گرمایش">
                    سیستم گرمایش
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="اینترنت">
                    اینترنت
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="تلفن">
                    تلفن
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="آبدارخانه">
                    آبدارخانه
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="سرویس بهداشتی">
                    سرویس بهداشتی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="اتاق جلسات">
                    اتاق جلسات
                </label>

            </div>
        `;

    }else if(
        selectedProperty ===
        'تجاری'
    ){

        html = `
            <div class="amenities-grid">

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="شیشه سکوریت">
                    شیشه سکوریت
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="درب اتوماتیک">
                    درب اتوماتیک
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="درب فلزی">
                    درب فلزی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="کرکره برقی">
                    کرکره برقی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="کرکره معمولی">
                    کرکره معمولی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="سرویس بهداشتی">
                    سرویس بهداشتی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="آسانسور">
                    آسانسور
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="بالابر">
                    بالابر
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="ویترین">
                    ویترین
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="نورپردازی">
                    نورپردازی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="اسپیلت">
                    اسپیلت
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="کولر آبی">
                    کولر آبی
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="پکیج">
                    پکیج
                </label>

                <label class="checkbox-label">
                    <input type="checkbox"
                           name="amenities[]"
                           value="بخاری">
                    بخاری
                </label>

            </div>
        `;
    }

    container.innerHTML =
        html;
}

/* =========================================================
   Navigation
========================================================= */

function updateRequestNavigation(){

    var prevBtn =
        document.getElementById(
            'prevReqBtn'
        );

    var nextBtn =
        document.getElementById(
            'nextReqBtn'
        );

    var bottomNav =
        document.getElementById(
            'bottomNav'
        );

    var finalActions =
        document.getElementById(
            'finalBottomActions'
        );

    if(
        currentReqStep === 1
    ){

        prevBtn.style.display =
            'none';

    }else{

        prevBtn.style.display =
            'flex';
    }

    if(
        currentReqStep ===
        totalReqSteps
    ){

        nextBtn.style.display =
            'none';

        finalActions.style.display =
            'flex';

        bottomNav.style.display =
            'flex';

        updateSummary();

    }else{

        nextBtn.style.display =
            'flex';

        finalActions.style.display =
            'none';

        bottomNav.style.display =
            'flex';

        nextBtn.innerText =
            'مرحله بعد';
    }
}

/* =========================================================
   اعتبارسنجی مرحله ۴ (فیلدهای قیمت/ودیعه/اجاره و اولویت‌ها)
========================================================= */

function validateStep4() {
    var trans = document.getElementById('transactionTypeInput').value;

    // =========================================================
    // اعتبارسنجی ویژه سرمایه‌گذاری: بررسی اولویت‌ها
    // =========================================================
    if (trans === 'سرمایه‌گذاری') {
        var noP = document.getElementById('noPriority');
        var p1 = document.getElementById('priority_1');
        var p2 = document.getElementById('priority_2');
        var p3 = document.getElementById('priority_3');
        if (!noP.checked && !p1.value && !p2.value && !p3.value) {
            alert('لطفاً حداقل یک اولویت انتخاب کنید یا گزینه "بدون اولویت" را فعال کنید.');
            return false;
        }
    }

    // =========================================================
    // اعتبارسنجی فیلدهای قیمت
    // =========================================================
    if (trans === 'فروش' || trans === 'پیش فروش' || trans === 'سرمایه‌گذاری') {
        var minPrice = document.querySelector('input[name="min_price"]');
        var maxPrice = document.querySelector('input[name="max_price"]');
        if (!minPrice || !minPrice.value.trim()) {
            alert('لطفاً حداقل قیمت را وارد کنید.');
            return false;
        }
        if (!maxPrice || !maxPrice.value.trim()) {
            alert('لطفاً حداکثر قیمت را وارد کنید.');
            return false;
        }
    } else if (trans === 'اجاره') {
        var minDeposit = document.querySelector('input[name="min_deposit"]');
        var maxDeposit = document.querySelector('input[name="max_deposit"]');
        var minRent = document.querySelector('input[name="min_rent"]');
        var maxRent = document.querySelector('input[name="max_rent"]');

        if (!minDeposit || !minDeposit.value.trim()) {
            alert('لطفاً حداقل ودیعه را وارد کنید.');
            return false;
        }
        if (!maxDeposit || !maxDeposit.value.trim()) {
            alert('لطفاً حداکثر ودیعه را وارد کنید.');
            return false;
        }
        if (!minRent || !minRent.value.trim()) {
            alert('لطفاً حداقل اجاره ماهانه را وارد کنید.');
            return false;
        }
        if (!maxRent || !maxRent.value.trim()) {
            alert('لطفاً حداکثر اجاره ماهانه را وارد کنید.');
            return false;
        }
    }
    return true;
}

function changeReqStep(
    direction
){

    if(
        direction === 1 &&
        currentReqStep === 1
    ){

        if(!validateStep1()){
            return;
        }
    }

    // اعتبارسنجی مرحله ۴ هنگام رفتن به مرحله بعد
    if (direction === 1 && currentReqStep === 4) {
        if (!validateStep4()) {
            return;
        }
    }

    if(
        currentReqStep ===
        totalReqSteps &&
        direction === 1
    ){
        return;
    }

    var current =
        document.getElementById(
            'reqStep' +
            currentReqStep
        );

    if(current){
        current.classList.remove(
            'active'
        );
    }

    currentReqStep +=
        direction;

    if(
        currentReqStep < 1
    ){
        currentReqStep = 1;
    }

    if(
        currentReqStep >
        totalReqSteps
    ){
        currentReqStep =
            totalReqSteps;
    }

    var next =
        document.getElementById(
            'reqStep' +
            currentReqStep
        );

    if(next){
        next.classList.add(
            'active'
        );
    }

    updateRequestNavigation();

    var mainContent =
        document.getElementById(
            'mainContent'
        );

    if(mainContent){
        mainContent.scrollTop =
            0;
    }
}

function editRequest(){

    currentReqStep = 1;

    var steps =
        document.querySelectorAll(
            '.step-content'
        );

    for(
        var i=0;
        i<steps.length;
        i++
    ){
        steps[i]
            .classList
            .remove('active');
    }

    document.getElementById(
        'reqStep1'
    ).classList.add(
        'active'
    );

    updateRequestNavigation();

    var mainContent =
        document.getElementById(
            'mainContent'
        );

    if(mainContent){
        mainContent.scrollTop =
            0;
    }
}

/* =========================================================
   Summary (با نمایش اولویت‌ها در صورت سرمایه‌گذاری)
========================================================= */

function updateSummary(){

    var container =
        document.getElementById(
            'summaryContainer'
        );

    if(!container){
        return;
    }

    var tgId =
        document.getElementById(
            'reqTelegramId'
        ).value || '-';

    var genderEl =
        document.querySelector(
            '#reqGender .option-btn.selected'
        );

    var gender =
        genderEl
            ? genderEl.innerText
            : '-';

    var lname =
        document.getElementById(
            'reqLastName'
        ).value || '-';

    var phone =
        document.getElementById(
            'reqPhone'
        ).value || '-';

    var transEl =
        document.querySelector(
            '#reqTrans .option-btn.selected'
        );

    var trans =
        transEl
            ? transEl.getAttribute('data-value')
            : '-';

    var propEl =
        document.querySelector(
            '#reqProp .option-btn.selected'
        );

    var prop =
        propEl
            ? propEl.innerText
            : '-';

    // اگر نوع معامله سرمایه‌گذاری است، اولویت‌ها را نمایش بده
    if (trans === 'سرمایه‌گذاری') {
        var p1 = document.getElementById('priority_1').value || 'انتخاب نشده';
        var p2 = document.getElementById('priority_2').value || 'انتخاب نشده';
        var p3 = document.getElementById('priority_3').value || 'انتخاب نشده';
        var noP = document.getElementById('noPriority').checked ? 'بله' : 'خیر';
        prop = 'اولویت‌ها: ' + p1 + '، ' + p2 + '، ' + p3 + ' (بدون اولویت: ' + noP + ')';
    }

    var loc =
        document.getElementById(
            'reqLocation'
        ).value || '-';

    var date =
        document.getElementById(
            'reqDate'
        ).value || '';

    var dateDisplay =
        date || '-';

    var urgencyEl =
        document.querySelector(
            '#reqUrgency .option-btn.selected'
        );

    var urgency =
        urgencyEl
            ? urgencyEl.innerText
            : '-';

    var minAreaEl =
        document.querySelector(
            'input[name="min_area"]'
        );

    var maxAreaEl =
        document.querySelector(
            'input[name="max_area"]'
        );

    var minArea =
        minAreaEl
            ? minAreaEl.value || '-'
            : '-';

    var maxArea =
        maxAreaEl
            ? maxAreaEl.value || '-'
            : '-';

    var minAgeEl =
        document.querySelector(
            'select[name="min_age"]'
        );

    var maxAgeEl =
        document.querySelector(
            'select[name="max_age"]'
        );

    var minAge =
        minAgeEl
            ? minAgeEl.value || '-'
            : '-';

    var maxAge =
        maxAgeEl
            ? maxAgeEl.value || '-'
            : '-';

    var notKeyedEl =
        document.getElementById(
            'isNotKeyed'
        );

    var isNotKeyed =
        notKeyedEl &&
        notKeyedEl.checked
            ? 'بله'
            : 'خیر';

    var priceText = '';

    if(
        trans === 'فروش' ||
        trans === 'پیش فروش' ||
        trans === 'سرمایه‌گذاری'
    ){

        var minPriceEl =
            document.querySelector(
                'input[name="min_price"]'
            );

        var maxPriceEl =
            document.querySelector(
                'input[name="max_price"]'
            );

        var minPrice =
            minPriceEl
                ? minPriceEl.value || '-'
                : '-';

        var maxPrice =
            maxPriceEl
                ? maxPriceEl.value || '-'
                : '-';

        priceText =
            minPrice +
            ' تا ' +
            maxPrice +
            ' تومان';

    }else if(
        trans === 'اجاره'
    ){

        var minDepositEl =
            document.querySelector(
                'input[name="min_deposit"]'
            );

        var maxDepositEl =
            document.querySelector(
                'input[name="max_deposit"]'
            );

        var minRentEl =
            document.querySelector(
                'input[name="min_rent"]'
            );

        var maxRentEl =
            document.querySelector(
                'input[name="max_rent"]'
            );

        var minDeposit =
            minDepositEl
                ? minDepositEl.value || '-'
                : '-';

        var maxDeposit =
            maxDepositEl
                ? maxDepositEl.value || '-'
                : '-';

        var minRent =
            minRentEl
                ? minRentEl.value || '-'
                : '-';

        var maxRent =
            maxRentEl
                ? maxRentEl.value || '-'
                : '-';

        priceText =
            'ودیعه: ' +
            minDeposit +
            ' تا ' +
            maxDeposit +
            ' تومان | اجاره: ' +
            minRent +
            ' تا ' +
            maxRent +
            ' تومان';
    }

    var selectedAmenities = [];

    var checkboxes =
        document.querySelectorAll(
            'input[name="amenities[]"]:checked'
        );

    for(
        var i=0;
        i<checkboxes.length;
        i++
    ){

        selectedAmenities.push(
            checkboxes[i].value
        );
    }

    var amenitiesText =
        selectedAmenities.length > 0
            ? selectedAmenities.join('، ')
            : 'هیچکدام';

    var notesEl =
        document.querySelector(
            'textarea[name="additional_notes"]'
        );

    var additionalNotes =
        notesEl
            ? notesEl.value || '-'
            : '-';

    container.innerHTML =

        '<div class="summary-row">' +
            '<span class="summary-label">' +
                'آیدی تلگرام' +
            '</span>' +
            '<span class="summary-value" ' +
                  'style="direction:ltr;">' +
                escapeHtml(tgId) +
            '</span>' +
        '</div>' +

        '<div class="summary-row">' +
            '<span class="summary-label">' +
                'جنسیت' +
            '</span>' +
            '<span class="summary-value">' +
                escapeHtml(gender) +
            '</span>' +
        '</div>' +

        '<div class="summary-row">' +
            '<span class="summary-label">' +
                'نام خانوادگی' +
            '</span>' +
            '<span class="summary-value">' +
                escapeHtml(lname) +
            '</span>' +
        '</div>' +

        '<div class="summary-row">' +
            '<span class="summary-label">' +
                'شماره تماس' +
            '</span>' +
            '<span class="summary-value">' +
                escapeHtml(phone) +
            '</span>' +
        '</div>' +

        '<div class="summary-row">' +
            '<span class="summary-label">' +
                'نوع معامله' +
            '</span>' +
            '<span class="summary-value">' +
                escapeHtml(trans) +
            '</span>' +
        '</div>' +

        '<div class="summary-row">' +
            '<span class="summary-label">' +
                'نوع ملک / اولویت‌ها' +
            '</span>' +
            '<span class="summary-value">' +
                escapeHtml(prop) +
            '</span>' +
        '</div>' +

        '<div class="summary-row">' +
            '<span class="summary-label">' +
                'محله' +
            '</span>' +
            '<span class="summary-value">' +
                escapeHtml(loc) +
            '</span>' +
        '</div>' +

        '<div class="summary-row">' +
            '<span class="summary-label">' +
                'بازه متراژ' +
            '</span>' +
            '<span class="summary-value">' +
                escapeHtml(minArea) +
                ' تا ' +
                escapeHtml(maxArea) +
                ' متر مربع' +
            '</span>' +
        '</div>' +

        (
            minAge !== '-'
                ?
                '<div class="summary-row">' +
                    '<span class="summary-label">' +
                        'سن بنا' +
                    '</span>' +
                    '<span class="summary-value">' +
                        escapeHtml(minAge) +
                        ' تا ' +
                        escapeHtml(maxAge) +
                        ' سال' +
                    '</span>' +
                '</div>'
                :
                ''
        ) +

        '<div class="summary-row">' +
            '<span class="summary-label">' +
                'کلید نخورده' +
            '</span>' +
            '<span class="summary-value">' +
                escapeHtml(isNotKeyed) +
            '</span>' +
        '</div>' +

        '<div class="summary-row">' +
            '<span class="summary-label">' +
                'بازه قیمت' +
            '</span>' +
            '<span class="summary-value">' +
                escapeHtml(priceText) +
            '</span>' +
        '</div>' +

        '<div class="summary-row">' +
            '<span class="summary-label">' +
                'امکانات مورد نظر' +
            '</span>' +
            '<span class="summary-value">' +
                escapeHtml(amenitiesText) +
            '</span>' +
        '</div>' +

        '<div class="summary-row">' +
            '<span class="summary-label">' +
                'تاریخ نیاز' +
            '</span>' +
            '<span class="summary-value">' +
                escapeHtml(dateDisplay) +
            '</span>' +
        '</div>' +

        '<div class="summary-row">' +
            '<span class="summary-label">' +
                'فوریت' +
            '</span>' +
            '<span class="summary-value">' +
                escapeHtml(urgency) +
            '</span>' +
        '</div>' +

        '<div class="summary-row">' +
            '<span class="summary-label">' +
                'توضیحات تکمیلی' +
            '</span>' +
            '<span class="summary-value">' +
                escapeHtml(additionalNotes) +
            '</span>' +
        '</div>';
}

/* =========================================================
   جلوگیری از HTML داخل Summary
========================================================= */

function escapeHtml(value){

    return String(value)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

/* =========================================================
   قیمت
========================================================= */

function formatPrice(input){

    var val =
        input.value
            .replace(/,/g,'')
            .replace(/[^0-9]/g,'');

    if(val === ''){

        input.value = '';

        return;
    }

    var num =
        parseInt(
            val,
            10
        );

    input.value =
        num.toLocaleString(
            'en-US'
        );
}

document.addEventListener(
    'input',
    function(e){

        if(
            e.target.classList
                .contains('price-input')
        ){

            formatPrice(
                e.target
            );
        }
    }
);

/* =========================================================
   تبدیل اعداد فارسی به انگلیسی
========================================================= */

function digitsToEnglish(value){

    if(!value){
        return '';
    }

    var fa =
        '۰۱۲۳۴۵۶۷۸۹';

    var ar =
        '٠١٢٣٤٥٦٧٨٩';

    var en =
        '0123456789';

    var str =
        String(value);

    for(
        var i=0;
        i<10;
        i++
    ){

        str =
            str.split(
                fa[i]
            ).join(
                en[i]
            );

        str =
            str.split(
                ar[i]
            ).join(
                en[i]
            );
    }

    return str;
}

/* =========================================================
   تبدیل تاریخ شمسی انتخاب‌شده به میلادی
========================================================= */

function convertSelectedJalaliToGregorian(){

    var input =
        document.getElementById(
            'reqDate'
        );

    var hidden =
        document.getElementById(
            'dateNeededGregorian'
        );

    if(!input || !hidden){
        return false;
    }

    var value =
        (input.value || '').trim();

    if(value === ''){

        hidden.value = '';

        return true;
    }

    value =
        digitsToEnglish(value);

    var parts =
        value.split('-');

    if(parts.length !== 3){

        hidden.value = '';

        return false;
    }

    var jy =
        parseInt(parts[0],10);

    var jm =
        parseInt(parts[1],10);

    var jd =
        parseInt(parts[2],10);

    if(
        !jy ||
        !jm ||
        !jd ||
        jm < 1 ||
        jm > 12 ||
        jd < 1 ||
        jd > 31
    ){

        hidden.value = '';

        return false;
    }

    try{

        var jalali =
            new persianDate(
                [jy,jm,jd]
            );

        var gregorian =
            jalali
                .toCalendar(
                    'gregorian'
                )
                .toLocale('en')
                .format(
                    'YYYY-MM-DD'
                );

        gregorian =
            digitsToEnglish(
                gregorian
            );

        if(
            /^\d{4}-\d{2}-\d{2}$/.test(
                gregorian
            )
        ){

            hidden.value =
                gregorian;

            return true;
        }

    }catch(e){

        console.error(
            'Jalali to Gregorian error:',
            e
        );
    }

    hidden.value = '';

    return false;
}

/* =========================================================
   ارسال فرم
========================================================= */

function submitRequestForm(){

    if(!validateStep1()){

        changeReqStep(
            -(currentReqStep - 1)
        );

        return;
    }

    // اعتبارسنجی مرحله ۴ قبل از ارسال نهایی
    if (!validateStep4()) {
        // کاربر را به مرحله ۴ ببرید
        var diff = 4 - currentReqStep;
        if (diff !== 0) changeReqStep(diff);
        return;
    }

    // تبدیل تاریخ شمسی به میلادی
    var dateOk =
        convertSelectedJalaliToGregorian();

    if(!dateOk){

        alert(
            'لطفاً تاریخ نیاز را به‌درستی انتخاب کنید.'
        );

        return;
    }

    var form =
        document.getElementById(
            'requestForm'
        );

    if(!form){

        alert(
            'فرم درخواست پیدا نشد.'
        );

        return;
    }

    var submitter =
        document.getElementById(
            'finalSubmitBtn'
        );

    if(submitter){

        submitter.disabled =
            true;

        submitter.innerText =
            '⏳ در حال ثبت...';
    }

    if(
        typeof form.requestSubmit ===
        'function'
    ){

        form.requestSubmit();

    }else{

        form.submit();
    }
}

/* =========================================================
   DOM Ready
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function(){

        loadTelegramId();

        document.getElementById(
            'reqStep1'
        ).classList.add(
            'active'
        );

        document.getElementById(
            'prevReqBtn'
        ).style.display =
            'none';

        document.getElementById(
            'genderInput'
        ).value =
            'آقا';

        document.getElementById(
            'transactionTypeInput'
        ).value =
            'فروش';

        document.getElementById(
            'propertyTypeInput'
        ).value =
            'آپارتمان';

        document.getElementById(
            'urgencyInput'
        ).value =
            'فوری';

        // پیش‌فرض: نمایش propertyTypeSelection و مخفی prioritySelection
        document.getElementById('propertyTypeSelection').style.display = 'block';
        document.getElementById('prioritySelection').style.display = 'none';

        updateSpecsStep();
            updateRahnKamalVisibility();
        updateRequestNavigation();
        updatePropertyTypeVisibility();

        /* =================================================
           تقویم شمسی
        ================================================= */

        try{

            var todayPd =
                new persianDate();

            var todayTimestamp =
                todayPd.valueOf();

            var sixMonthsLaterTimestamp =
                new persianDate()
                    .add(
                        'month',
                        6
                    )
                    .valueOf();

            $('#reqDate').persianDatepicker({

                format:
                    'YYYY-MM-DD',

                autoClose:
                    true,

                direction:
                    'rtl',

                minDate:
                    todayTimestamp,

                maxDate:
                    sixMonthsLaterTimestamp,

                calendarType:
                    'persian',

                persianDigit:
                    false,

                toolbox:{
                    enabled:true,

                    todayButton:{
                        enabled:true,
                        text:{
                            fa:'امروز',
                            en:'Today'
                        }
                    },

                    submitButton:{
                        enabled:true,
                        text:{
                            fa:'تایید',
                            en:'Submit'
                        }
                    }
                },

                onSelect:
                    function(unixDate){

                        // مقدار اصلی input شمسی است.
                        // تبدیل میلادی هنگام submit انجام می‌شود.
                        convertSelectedJalaliToGregorian();

                        if(
                            currentReqStep === 5
                        ){
                            updateSummary();
                        }
                    }
            });

        }catch(e){

            console.error(
                'Persian datepicker initialization error:',
                e
            );
        }

        var requestForm =
            document.getElementById(
                'requestForm'
            );

        if(requestForm){

            requestForm.setAttribute(
                'action',
                'property-request.php'
            );

            requestForm.setAttribute(
                'method',
                'POST'
            );

            requestForm.addEventListener(
                'submit',
                function(){

                    // اطمینان نهایی از تبدیل تاریخ
                    convertSelectedJalaliToGregorian();

                    var submitBtn =
                        document.getElementById(
                            'finalSubmitBtn'
                        );

                    var inlineBtn =
                        document.getElementById(
                            'finalSubmitInline'
                        );

                    if(submitBtn){

                        submitBtn.disabled =
                            true;

                        submitBtn.innerText =
                            '⏳ در حال ثبت...';
                    }

                    if(inlineBtn){

                        inlineBtn.disabled =
                            true;

                        inlineBtn.innerText =
                            '⏳ در حال ثبت...';
                    }
                }
            );
        }

    }
);

</script>

<?php require_once __DIR__ . '/footer.php'; ?>