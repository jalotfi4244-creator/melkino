<?php
// ==============================================
// روی سرور واقعی، خطاها فقط لاگ می‌شوند نه چاپ در صفحه
// (قبلاً display_errors روشن بود؛ همین باعث می‌شد یک Warning یا
// Notice ساده، خروجی JSON آپلود تصویر یا فرآیند ثبت را خراب کند و
// به‌عنوان «خطای دیتابیس» به کاربر نمایش داده شود)
// ==============================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';


// ==============================================
// پردازش آپلود تصاویر از طریق AJAX
// ==============================================
if (isset($_POST['action']) && $_POST['action'] === 'upload_images') {
    header('Content-Type: application/json');
    
    $uploadedImages = [];
    $uploadErrors = [];
    $targetDir = __DIR__ . "/uploads/";
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $maxFileSize = 5 * 1024 * 1024;

    // قبلاً این بخش هر خطایی (پوشه‌ی غیرقابل‌نوشتن، فرمت نامعتبر، حجم زیاد)
    // را بی‌صدا نادیده می‌گرفت و همیشه success:true با images خالی برمی‌گرداند؛
    // یعنی کاربر می‌دید «عکس آپلود نشد» بدون اینکه هیچ دلیلی نشان داده شود.
    // حالا دلیل دقیق در پاسخ JSON برگردانده می‌شود.

    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
    }

    if (!is_dir($targetDir) || !is_writable($targetDir)) {
        echo json_encode([
            'success' => false,
            'message' => 'پوشه‌ی uploads روی سرور وجود ندارد یا قابل نوشتن نیست. لطفاً از طریق File Manager هاست، به پوشه‌ی uploads دسترسی نوشتن (chmod 755) بده.',
            'images' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $adId = 'AD-' . date('Ymd') . '-' . rand(1000, 9999);

    if (isset($_FILES['images']) && is_array($_FILES['images']['name']) && count($_FILES['images']['name']) > 0) {
        for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
            if (empty($_FILES['images']['name'][$i])) continue;

            if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) {
                $uploadErrors[] = $_FILES['images']['name'][$i] . ': کد خطای آپلود ' . $_FILES['images']['error'][$i];
                continue;
            }

            if ($_FILES['images']['size'][$i] > $maxFileSize) {
                $uploadErrors[] = $_FILES['images']['name'][$i] . ': حجم فایل بیش از ۵ مگابایت است.';
                continue;
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $_FILES['images']['tmp_name'][$i]);
            finfo_close($finfo);

            $fileExt = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));

            if (!in_array($mimeType, $allowedMimes) || !in_array($fileExt, $allowedExtensions)) {
                $uploadErrors[] = $_FILES['images']['name'][$i] . ': فرمت تصویر مجاز نیست.';
                continue;
            }

            $uniqueId = uniqid() . '_' . bin2hex(random_bytes(4));
            $fileName = $adId . '_' . date('Ymd_His') . '_' . $uniqueId . '.' . $fileExt;
            $fileName = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $fileName);
            $targetFile = $targetDir . $fileName;

            if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $targetFile)) {
                $uploadedImages[] = 'uploads/' . $fileName;
            } else {
                $uploadErrors[] = $_FILES['images']['name'][$i] . ': ذخیره‌ی فایل روی سرور ناموفق بود.';
            }
        }
    } else {
        $uploadErrors[] = 'هیچ فایلی در درخواست ارسال دریافت نشد.';
    }

    echo json_encode([
        'success' => count($uploadedImages) > 0,
        'images' => $uploadedImages,
        'adId' => $adId,
        'errors' => $uploadErrors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==============================================
// فرم ثبت ملک - مخصوص باغ
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_property'])) {
    
    // دریافت اطلاعات از فرم (از فیلدهای مخفی)
    // قبلاً این مقدار از $_SESSION['reg_telegram_id'] خونده می‌شد که هیچ‌وقت
    // ست نمی‌شد (همیشه خالی بود)؛ حالا از همون فیلد مخفی فرم می‌خونیم.
    // نکته‌ی امنیتی: مقدار خودِ فیلد فرم (hidden_telegram_id) دیگر
    // برای هویت معتبر نیست، چون کلاینت می‌تونست هر عددی توش بذاره.
    // آی‌دی تلگرام معتبر فقط همونیه که سرور قبلاً (با بررسی امضای
    // واقعی تلگرام در identity-sync.php) در سشن ثبت کرده.
    $telegram_id = trim((string)($_SESSION['reg_telegram_id'] ?? ''));
    $gender = $_POST['gender'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $transaction_type = $_POST['transaction_type'] ?? 'فروش';
    $property_type = 'باغ';
    
    // دریافت اطلاعات از فرم
    $title = $_POST['title'] ?? '';
    $location = $_POST['location'] ?? '';
    $address = $_POST['address'] ?? '';
    $description = $_POST['full_description'] ?? '';
    $publish_photos = $_POST['publish_photos'] ?? 'yes';
    
    // دریافت مسیر تصاویر از فیلد مخفی
    $imagePaths = isset($_POST['uploaded_images']) ? explode(',', $_POST['uploaded_images']) : [];
    $uploadedImages = array_filter($imagePaths);

    // ==============================================
    // دریافت فیلدهای قیمت بر اساس نوع معامله
    // ==============================================
    $price_sell = '0';
    $price_condition = '';
    $deposit = '0';
    $rent_monthly = '0';
    $full_rent_enabled = '0';
    $full_rent = '0';
    $total_price = '0';
    $down_payment = '0';
    $payment_terms = '';
    $exchange_interested = isset($_POST['exchange_interested']) ? '1' : '0';
    $exchange_with = trim((string)($_POST['exchange_with'] ?? ''));

    if ($transaction_type === 'فروش') {
        $price_sell = $_POST['price_sell'] ?? '0';
        $price_condition = isset($_POST['price_condition']) ? $_POST['price_condition'] : 'negotiable';
    } elseif ($transaction_type === 'اجاره') {
        $deposit = $_POST['deposit'] ?? '0';
        $rent_monthly = $_POST['rent_monthly'] ?? '0';
        $full_rent_enabled = isset($_POST['full_rent_enabled']) ? '1' : '0';
        $full_rent = $_POST['full_rent'] ?? '0';
    } elseif ($transaction_type === 'پیش فروش') {
        $total_price = $_POST['total_price'] ?? '0';
        $down_payment = $_POST['down_payment'] ?? '0';
        $payment_terms = $_POST['payment_terms'] ?? '';
    }

    // ==============================================
    // اعتبارسنجی سمت سرور
    // ==============================================
    $errors = [];

    // گام ۱: اطلاعات تماس (به جز لوکیشن اجباری)
    if (empty($gender) || !in_array($gender, ['آقا', 'خانم'])) $errors[] = 'لطفاً جنسیت خود را انتخاب کنید.';
    if (empty($last_name)) $errors[] = 'لطفاً نام خانوادگی خود را وارد کنید.';
    if (empty($phone)) $errors[] = 'لطفاً شماره تماس خود را وارد کنید.';

    // گام ۲: مشخصات اجباری باغ (فیلدهای جدید به‌روزرسانی شد)
    if (empty($_POST['garden_area'])) $errors[] = 'لطفاً مساحت باغ را وارد کنید.';
    if (empty($_POST['document_type'])) $errors[] = 'لطفاً نوع سند را انتخاب کنید.';

    // اگر آب ملکی دارد، فیلدهای مربوطه اجباری می‌شوند
    if (isset($_POST['has_well']) && $_POST['has_well'] === '1') {
        if (empty($_POST['water_share'])) $errors[] = 'لطفاً مقدار ساعت آب را وارد کنید.';
        if (empty($_POST['well_name'])) $errors[] = 'لطفاً نام چاه آب را وارد کنید.';
    }

    // گام ۴: قیمت بر اساس نوع معامله
    $isSell = ($transaction_type === 'فروش');
    $isPreSell = ($transaction_type === 'پیش فروش');
    $isRent = ($transaction_type === 'اجاره');

    if ($isSell && empty($_POST['price_sell'])) {
        $errors[] = 'لطفاً قیمت فروش را وارد کنید.';
    }
    if ($isPreSell && empty($_POST['total_price'])) {
        $errors[] = 'لطفاً قیمت کل (پیش فروش) را وارد کنید.';
    }
    if ($isRent) {
        $full_rent_enabled = isset($_POST['full_rent_enabled']) ? '1' : '0';
        if ($full_rent_enabled === '1') {
            if (empty($_POST['full_rent'])) {
                $errors[] = 'لطفاً مبلغ رهن کامل را وارد کنید.';
            }
        } else {
            if (empty($_POST['deposit'])) {
                $errors[] = 'لطفاً مبلغ ودیعه را وارد کنید.';
            }
            if (empty($_POST['rent_monthly'])) {
                $errors[] = 'لطفاً مبلغ اجاره ماهانه را وارد کنید.';
            }
        }
    }

    // اگر خطایی وجود داشت، نمایش داده و متوقف کن
    if (!empty($errors)) {
        echo "<!DOCTYPE html>
        <html>
        <head><meta charset='UTF-8'><title>خطا در ثبت</title></head>
        <body style='font-family: Vazirmatn, sans-serif; text-align: center; padding: 50px; direction: rtl; background: #f3f4f6;'>
            <div style='background: #fff; max-width: 500px; margin: 0 auto; padding: 30px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 4px 10px rgba(0,0,0,0.05);'>
                <h3 style='color: #ef4444; margin-bottom: 20px;'>❌ اطلاعات ناقص است!</h3>
                <div style='background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px; padding: 15px; text-align: right; margin-bottom: 20px;'>";
        foreach ($errors as $err) {
            echo "<p style='margin: 5px 0; color: #b91c1c; font-weight: 600;'>- $err</p>";
        }
        echo "    </div>
                <a href='javascript:history.back()' style='display: inline-block; padding: 12px 24px; background: #064e4e; color: #fff; text-decoration: none; border-radius: 8px;'>بازگشت و اصلاح</a>
            </div>
        </body>
        </html>";
        exit;
    }

    // ==============================================
    // ذخیره اصلی در دیتابیس MySQL
    // ==============================================
    $adId = 'AD-' . date('Ymd') . '-' . rand(1000, 9999);
    $newAd = [
        'id' => $adId,
        'title' => $title,
        'location' => $location,
        'address' => $address,
        'status' => 'pending',
        'tags' => [],
        'gender' => $gender,
        'last_name' => $last_name,
        'phone' => $phone,
        'propertyType' => $property_type,
        'transactionType' => $transaction_type,
        // فیلدهای اختصاصی باغ (فیلدهای حذف شده حذف شدند)
        'garden_area' => $_POST['garden_area'] ?? '0',
        'tree_types' => $_POST['tree_types'] ?? '',
        'tree_age' => $_POST['tree_age'] ?? '',
        'irrigation_type' => $_POST['irrigation_type'] ?? '',
        'has_well' => isset($_POST['has_well']) && $_POST['has_well'] === '1' ? '1' : '0',
        'water_share' => $_POST['water_share'] ?? '',
        'well_name' => $_POST['well_name'] ?? '',
        'has_pond' => isset($_POST['has_pond']) && $_POST['has_pond'] === '1' ? '1' : '0',
        'has_building' => isset($_POST['has_building']) ? '1' : '0',
        'building_area' => $_POST['building_area'] ?? '0',
        'document_type' => $_POST['document_type'] ?? '',
        'amenities' => isset($_POST['garden_amenities']) ? array_values($_POST['garden_amenities']) : [],
        'description' => $description,
        'images' => $uploadedImages,
        'selectedImages' => $uploadedImages,
        'publish_photos' => $publish_photos,
        'created_at' => date('Y-m-d H:i:s'),
        // فیلدهای قیمت
        'price_sell' => $price_sell,
        'price_condition' => $price_condition,
        'deposit' => $deposit,
        'rent_monthly' => $rent_monthly,
        'full_rent_enabled' => $full_rent_enabled,
        'full_rent' => $full_rent,
        'total_price' => $total_price,
        'down_payment' => $down_payment,
        'payment_terms' => $payment_terms,
        'display_price' => $price_sell ?: ($total_price ?: ($deposit ?: $rent_monthly)),
        'exchange_interested' => $exchange_interested,
        'exchange_with' => $exchange_with,
    ];

    // ==============================================
    // ذخیره اصلی آگهی در MySQL
    // JSON دیگر منبع ذخیره‌سازی نیست.
    // ==============================================
    require_once __DIR__ . '/property-db-helper.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        http_response_code(500);
        echo "<div style='direction:rtl;text-align:center;padding:40px;font-family:Vazirmatn,sans-serif'>❌ اتصال به دیتابیس برقرار نشد.</div>";
        exit;
    }

    $dbResult = savePropertyToDatabase($pdo, $newAd);
    if ($dbResult['success']) {
        if ($telegram_id !== '') {
            $_SESSION['reg_telegram_id'] = $telegram_id;
        }

        // نکته‌ی امنیتی: تایپ‌کردن یک شماره در فرم، اثبات مالکیت آن
        // نیست. قبلاً اینجا $_SESSION['user_phone'] بی‌قیدوشرط با
        // همین $phone پر می‌شد؛ یعنی اگه کسی به‌جای شماره‌ی خودش
        // شماره‌ی یک نفر دیگه رو تایپ می‌کرد، سشنش به هویت اون فرد
        // وصل می‌شد و «ملک‌های من»/«درخواست‌های من» اون فرد رو
        // می‌دید! حالا این وصل‌شدن فقط وقتی انجام می‌شه که واقعاً
        // خودِ این مرورگر صاحب اون شماره باشه (شماره‌ی تازه، یا توکن
        // معتبرِ ذخیره‌شده از ثبت‌نام قبلی همین مرورگر).
        $providedToken = $_COOKIE['melkino_access_token'] ?? '';
        $identity = melkinoUpsertUser($telegram_id, $phone, $last_name, '', $providedToken);

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
    }
    if (!$dbResult['success']) {
        http_response_code(500);
        echo "<!DOCTYPE html><html lang='fa' dir='rtl'><head><meta charset='UTF-8'><title>خطا در ثبت</title></head><body style='font-family:Vazirmatn,sans-serif;text-align:center;padding:50px;background:#f3f4f6;'><div style='background:#fff;max-width:650px;margin:auto;padding:30px;border-radius:14px;border:1px solid #e5e7eb;'><h3 style='color:#dc2626'>❌ ثبت آگهی انجام نشد</h3><p style='line-height:2;color:#374151'>خطایی هنگام ذخیره اطلاعات در دیتابیس رخ داد. اطلاعات فرم تغییر نکرده است.</p><a href='javascript:history.back()' style='display:inline-block;padding:12px 24px;background:#064e4e;color:#fff;text-decoration:none;border-radius:8px'>بازگشت و اصلاح</a></div></body></html>";
        exit;
    }



    // نمایش پیام موفقیت
    echo "<!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'><title>ثبت موفق</title></head>
    <body style='font-family: Vazirmatn, sans-serif; text-align: center; padding: 50px; direction: rtl;'>
        <h2 style='color: green;'>✅ آگهی باغ با موفقیت ثبت شد!</h2>
        <p>شناسه پیگیری: <strong style='direction: ltr; display: inline-block;'>" . $newAd['id'] . "</strong></p>
        <p>اطلاعات شما ذخیره شد. به زودی با شما تماس می‌گیریم.</p>
        <a href='home.php' style='display: inline-block; margin-top: 20px; padding: 12px 24px; background: #064e4e; color: #fff; text-decoration: none; border-radius: 8px;'>بازگشت به خانه</a>
        <script>setTimeout(function() { window.location.href = 'home.php'; }, 3000);</script>
    </body>
    </html>";
    exit;
}

require_once 'header.php';
?>
<style>
    /* ===== تمام استایل‌های مشابه سایر انواع ===== */
    .main-content { flex: 1; overflow-y: auto; background: var(--bg); display: flex; flex-direction: column; padding-bottom: 75px; }
    .stepper-container { padding: var(--space-2) var(--space-3); display: flex; align-items: center; gap: var(--space-1); background: var(--surface); flex-shrink: 0; }
    .step-content { display: none; padding: 0 var(--space-3); flex-direction: column; gap: var(--space-2); animation: fadeIn 0.3s ease forwards; }
    .step-content.active { display: flex; }
    
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .step-title { font-size: 20px; font-weight: 800; color: var(--text-primary); margin-bottom: var(--space-1); }
    .step-subtitle { font-size: 14px; color: var(--text-secondary); margin-bottom: var(--space-2); }
    
    .preview-section { margin-bottom: var(--space-3); }
    .preview-card { background: var(--surface); border-radius: var(--radius-md); padding: var(--space-3); border: 1px solid var(--border); margin-bottom: var(--space-2); }
    .preview-card-title { font-size: 16px; font-weight: 700; color: var(--primary); margin-bottom: var(--space-2); border-bottom: 1px solid var(--border); padding-bottom: var(--space-1); }
    .preview-row { display: flex; justify-content: space-between; padding: var(--space-1) 0; border-bottom: 1px solid var(--border); }
    .preview-row:last-child { border-bottom: none; }
    .preview-label { color: var(--text-secondary); font-weight: 600; }
    .preview-value { color: var(--text-primary); font-weight: 500; text-align: left; direction: ltr; }
    .preview-value.rtl { direction: rtl; text-align: right; }
    
    .image-preview-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: var(--space-1); margin-top: var(--space-2); }
    .image-preview-item { position: relative; width: 100%; aspect-ratio: 1/1; border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; background: var(--bg); }
    .image-preview-item img { width: 100%; height: 100%; object-fit: cover; }
    .image-preview-item .remove-btn { position: absolute; top: 2px; right: 2px; background: rgba(255,255,255,0.9); border: none; border-radius: 50%; width: 20px; height: 20px; cursor: pointer; color: var(--danger); font-weight: bold; display: flex; justify-content: center; align-items: center; }

    .form-group { display: flex; flex-direction: column; gap: var(--space-1); margin-bottom: var(--space-2); }
    .form-group label { font-size: 14px; font-weight: 600; color: var(--text-primary); }
    .form-input, .form-select, .form-textarea { width: 100%; height: 50px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--bg); padding: 0 var(--space-2); font-size: 15px; font-family: 'Vazirmatn', sans-serif; color: var(--text-primary); outline: none; transition: border 0.2s ease; }
    .form-textarea { height: 100px; padding-top: var(--space-2); resize: none; }
    .row-half { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-2); }
    
    .upload-area { width: 100%; height: 120px; border: 2px dashed var(--border); border-radius: var(--radius-md); background: var(--bg); display: flex; flex-direction: column; justify-content: center; align-items: center; color: var(--text-secondary); gap: var(--space-1); cursor: pointer; transition: 0.3s; }
    .upload-area:hover { border-color: var(--primary); }
    
    .bottom-actions {
        position: sticky;
        bottom: 0;        
        width: 100%;
        padding: var(--space-2) var(--space-3);
        background: var(--surface);
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: center;
        gap: var(--space-2);
        z-index: 999;
        box-sizing: border-box;
        margin-top: var(--space-3);
        margin-bottom: 35px;
    }
    .btn-secondary, .btn-primary-full { height: 52px; border-radius: var(--radius-md); font-weight: 700; font-size: 16px; display: flex; justify-content: center; align-items: center; padding: 0 var(--space-2); box-sizing: border-box; border: none; flex: 1; transition: all 0.2s ease; }
    .btn-secondary { background: var(--bg); color: var(--text-secondary); border: 1px solid var(--border); }
    .btn-primary-full { background: var(--primary); color: #ffffff; }
    #fullRentGroup { display: none; }
    #fullRentGroup.visible { display: block; }
    #exchangeGroup { display: none; }
    #exchangeGroup.visible { display: block; }
    #wellWaterGroup { display: none; }
    #wellWaterGroup.visible { display: block; }
    
    .radio-group { display: flex; gap: var(--space-3); margin-top: var(--space-1); flex-wrap: wrap; }
    .radio-label { display: flex; align-items: center; gap: var(--space-1); font-size: 14px; color: var(--text-primary); cursor: pointer; }
    .radio-label input[type="radio"] { width: 18px; height: 18px; accent-color: var(--primary); }
</style>

<div class="stepper-container">
    <span class="step-text" id="stepText">مرحله ۱ از ۵</span>
    <div class="step-line active" id="line1"></div>
    <div class="step-line" id="line2"></div>
    <div class="step-line" id="line3"></div>
    <div class="step-line" id="line4"></div>
</div>

<div class="main-content" id="mainContent">
    <form method="POST" action="" id="propertyForm" enctype="multipart/form-data" novalidate>
        <!-- ===== فیلدهای مخفی برای اطلاعات کاربر ===== -->
        <input type="hidden" name="gender" id="hidden_gender" value="">
        <input type="hidden" name="last_name" id="hidden_last_name" value="">
        <input type="hidden" name="phone" id="hidden_phone" value="">
        <input type="hidden" name="telegram_id" id="hidden_telegram_id" value="">
        <input type="hidden" name="transaction_type" id="hidden_transaction_type" value="">
        <input type="hidden" name="uploaded_images" id="uploaded_images" value="">
        
        <!-- STEP 1: اطلاعات پایه و موقعیت -->
        <div class="step-content active" id="step1">
            <h2 class="step-title">اطلاعات پایه و موقعیت</h2>
            <div class="form-group"><label>عنوان آگهی</label><input type="text" class="form-input" id="regTitle" name="title" placeholder="باغ ۱۰۰۰ متری با استخر و درختان میوه" required></div>
            <div class="form-group"><label>محله / خیابان اصلی</label><input type="text" class="form-input" id="regLocation" name="location" placeholder="خیابان بهار" required></div>
            <div class="form-group"><label>آدرس دقیق</label><input type="text" class="form-input" id="regAddress" name="address" placeholder="خ بهار کوچه بیستم..." required></div>
        </div>

        <!-- STEP 2: مشخصات باغ -->
        <div class="step-content" id="step2">
            <h2 class="step-title">مشخصات باغ</h2>
            
            <div class="row-half">
                <div class="form-group"><label>مساحت باغ (متر مربع)</label><input type="text" class="form-input numeric-input" id="regGardenArea" name="garden_area" placeholder="۱۰۰۰" required></div>
                <div class="form-group"><label>نوع درختان</label><input type="text" class="form-input" id="regTreeTypes" name="tree_types" placeholder="گردو، سیب، گیلاس، ..."></div>
            </div>
            
            <div class="form-group"><label>سن درختان</label><input type="text" class="form-input" id="regTreeAge" name="tree_age" placeholder="بین ۵ تا ۱۵ سال"></div>
            
            <div class="row-half">
                <div class="form-group"><label>نوع آبیاری</label><select class="form-select" id="regIrrigationType" name="irrigation_type">
                    <option value="">انتخاب کنید</option>
                    <option>قطره‌ای</option>
                    <option>بارانی</option>
                    <option>جوی و پشته</option>
                    <option>آبیاری تحت فشار</option>
                    <option>سطحی</option>
                    <option>ترکیبی</option>
                    <option>غرقابی</option>
                </select></div>
                <div class="form-group"><label>آب ملکی</label>
                    <div class="radio-group">
                        <label class="radio-label"><input type="radio" name="has_well" value="1" onchange="toggleWellWater()"> دارد</label>
                        <label class="radio-label"><input type="radio" name="has_well" value="0" checked onchange="toggleWellWater()"> ندارد</label>
                    </div>
                </div>
            </div>
            
            <div id="wellWaterGroup">
                <div class="form-group"><label>مقدار ساعت آب</label><input type="text" class="form-input" id="regWaterShare" name="water_share" placeholder="مثلاً ۶ ساعت در هفته"></div>
                <div class="form-group"><label>نام چاه آب</label><input type="text" class="form-input" id="regWellName" name="well_name" placeholder="مثلاً چاه اصلی یا چاه شماره ۲"></div>
            </div>
            
            <div class="row-half">
                <div class="form-group">
                    <label>استخر ذخیره آب</label>
                    <div class="radio-group">
                        <label class="radio-label"><input type="radio" name="has_pond" value="1"> دارد</label>
                        <label class="radio-label"><input type="radio" name="has_pond" value="0" checked> ندارد</label>
                    </div>
                </div>
                <div class="form-group">
                    <label>بنا / خانه باغ</label>
                    <div class="radio-group">
                        <label class="radio-label"><input type="radio" name="has_building" value="1" onchange="toggleBuilding()"> دارد</label>
                        <label class="radio-label"><input type="radio" name="has_building" value="0" checked onchange="toggleBuilding()"> ندارد</label>
                    </div>
                </div>
            </div>
            
            <div class="row-half">
                <div class="form-group" id="buildingAreaGroup" style="display:none;">
                    <label>متراژ بنا</label>
                    <input type="text" class="form-input numeric-input" id="regBuildingArea" name="building_area" placeholder="۱۵۰">
                </div>
                <div class="form-group">
                    <label>نوع سند</label>
                    <div class="radio-group">
                        <label class="radio-label"><input type="radio" name="document_type" value="سند" required> سند</label>
                        <label class="radio-label"><input type="radio" name="document_type" value="قولنامه" required> قولنامه</label>
                    </div>
                </div>
            </div>
        </div>

        <!-- STEP 3: امکانات باغ -->
        <div class="step-content" id="step3">
            <h2 class="step-title">امکانات باغ</h2>
            <div class="amenities-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-1);">
                <label class="checkbox-label"><input type="checkbox" name="garden_amenities[]" value="سرویس بهداشتی"> سرویس بهداشتی</label>
                <label class="checkbox-label"><input type="checkbox" name="garden_amenities[]" value="پارکینگ"> پارکینگ</label>
                <label class="checkbox-label"><input type="checkbox" name="garden_amenities[]" value="انباری"> انباری</label>
                <label class="checkbox-label"><input type="checkbox" name="garden_amenities[]" value="آلاچیق"> آلاچیق</label>
                <label class="checkbox-label"><input type="checkbox" name="garden_amenities[]" value="استخر"> استخر</label>
                <label class="checkbox-label"><input type="checkbox" name="garden_amenities[]" value="سونا"> سونا</label>
                <label class="checkbox-label"><input type="checkbox" name="garden_amenities[]" value="باربیکیو"> باربیکیو</label>
                <label class="checkbox-label"><input type="checkbox" name="garden_amenities[]" value="برق"> برق</label>
                <label class="checkbox-label"><input type="checkbox" name="garden_amenities[]" value="گاز"> گاز</label>
                <label class="checkbox-label"><input type="checkbox" name="garden_amenities[]" value="آب شهری"> آب شهری</label>
                <label class="checkbox-label"><input type="checkbox" name="garden_amenities[]" value="دیوارکشی"> دیوارکشی</label>
                <label class="checkbox-label"><input type="checkbox" name="garden_amenities[]" value="نگهبانی"> نگهبانی</label>
                <label class="checkbox-label"><input type="checkbox" name="garden_amenities[]" value="درب ورودی خودرو"> درب ورودی خودرو</label>
            </div>
        </div>

        <!-- STEP 4: قیمت (بدون required) -->
        <div class="step-content" id="step4">
            <h2 class="step-title">قیمت</h2>
            
            <div id="price-sell" style="display:none;">
                <div class="form-group"><label>قیمت (تومان)</label><input type="text" class="form-input price-input" name="price_sell" placeholder="۲,۸۰۰,۰۰۰,۰۰۰"></div>
                <div style="display:flex;gap:var(--space-2);margin-top:var(--space-1);">
                    <label><input type="checkbox" name="price_condition" value="negotiable" onchange="togglePriceCondition(this,'fixed')"> قابل مذاکره</label>
                    <label><input type="checkbox" name="price_condition" value="fixed" onchange="togglePriceCondition(this,'negotiable')"> مقطوع</label>
                </div>
                <div style="margin-top:var(--space-2);">
                    <label style="display:flex;align-items:center;gap:var(--space-1);">
                        <input type="checkbox" id="exchangeCheckbox" name="exchange_interested" value="1" onchange="toggleExchange()">
                        مایل به معاوضه هستم
                    </label>
                    <div id="exchangeGroup" style="margin-top:var(--space-1);">
                        <div class="form-group">
                            <label>معاوضه با:</label>
                            <textarea class="form-textarea" name="exchange_with" placeholder="مثلاً: معاوضه با باغ بزرگ‌تر یا معاوضه با ملک دیگر یا ... برای ما شرایط خود را توضیح دهید." rows="3" disabled></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div id="price-rent" style="display:none;">
                <div class="row-half">
                    <div class="form-group"><label>ودیعه</label><input type="text" class="form-input price-input" name="deposit" placeholder="۵۰۰,۰۰۰,۰۰۰"></div>
                    <div class="form-group"><label>اجاره ماهانه</label><input type="text" class="form-input price-input" name="rent_monthly" placeholder="۳۰,۰۰۰,۰۰۰"></div>
                </div>
                <div style="margin-top:var(--space-1);"><label><input type="checkbox" id="fullRentCheckbox" onchange="toggleFullRent()"> رهن کامل</label></div>
                <div id="fullRentGroup"><div class="form-group"><label>مبلغ رهن کامل</label><input type="text" class="form-input price-input" name="full_rent" placeholder="۱,۰۰۰,۰۰۰,۰۰۰"></div></div>
            </div>

            <div id="price-pre-sell" style="display:none;">
                <div class="form-group"><label>قیمت کل</label><input type="text" class="form-input price-input" name="total_price" placeholder="۳,۰۰۰,۰۰۰,۰۰۰"></div>
                <div class="form-group"><label>پیش‌پرداخت</label><input type="text" class="form-input price-input" name="down_payment" placeholder="۹۰۰,۰۰۰,۰۰۰"></div>
                <div class="form-group"><label>شرایط پرداخت</label><textarea class="form-textarea" name="payment_terms" rows="3" placeholder="مثال: ۳۰٪ قرارداد، ۲۰٪ اسکلت..."></textarea></div>
            </div>

            <script>
                const step4TransType = sessionStorage.getItem('reg_transaction_type') || 'فروش';
                if (step4TransType === 'فروش') {
                    document.getElementById('price-sell').style.display = 'block';
                    document.getElementById('exchangeGroup').classList.remove('visible');
                    document.querySelector('#exchangeGroup textarea').disabled = true;
                } else if (step4TransType === 'اجاره') {
                    document.getElementById('price-rent').style.display = 'block';
                    const fullRentCheckbox = document.getElementById('fullRentCheckbox');
                    if (fullRentCheckbox) {
                        toggleFullRent();
                    }
                } else if (step4TransType === 'پیش فروش') {
                    document.getElementById('price-pre-sell').style.display = 'block';
                }
            </script>
        </div>

        <!-- STEP 5: تصاویر، توضیحات و ثبت نهایی -->
        <div class="step-content" id="step5">
            <h2 class="step-title">تصاویر، توضیحات و ثبت نهایی</h2>
            
            <!-- بخش اول: آپلود تصاویر با AJAX -->
            <div class="form-group">
                <label>آپلود تصاویر (حداکثر ۵ مگابایت، فرمت JPG, PNG, WEBP)</label>
                <div id="dropZone" class="upload-area" style="cursor:pointer; text-align:center; padding:20px;" onclick="document.getElementById('fileInput').click()">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                    <br>
                    <span>برای آپلود تصاویر کلیک کنید یا بکشید و رها کنید</span>
                    <br>
                    <small style="color:var(--text-secondary);">حداکثر ۵ مگابایت - فرمت‌های مجاز: JPG, PNG, WEBP</small>
                </div>
                <input type="file" id="fileInput" name="images[]" accept="image/jpeg,image/png,image/webp" multiple style="display:none;" onchange="handleImageUpload(this)">
                <div id="uploadProgress" style="display:none; margin-top:8px;">
                    <div style="width:100%; height:6px; background:var(--border); border-radius:3px; overflow:hidden;">
                        <div id="progressBar" style="width:0%; height:100%; background:var(--primary); transition:width 0.3s;"></div>
                    </div>
                    <span id="progressText" style="font-size:13px; color:var(--text-secondary);">در حال آپلود...</span>
                </div>
                <div id="imagePreviewContainer" class="image-preview-grid"></div>
            </div>

            <!-- ===== گزینه جدید: انتخاب حالت انتشار تصاویر (بعد از آپلود و فقط در صورت وجود عکس) ===== -->
            <div id="publishOptionsContainer" style="display: none; margin-top: var(--space-2);">
                <div class="form-group">
                    <label>انتخاب حالت انتشار تصاویر</label>
                    <div class="radio-group">
                        <label class="radio-label"><input type="radio" name="publish_photos" value="yes" checked> مایل به انتشار عکس‌ها در آگهی هستم</label>
                        <label class="radio-label"><input type="radio" name="publish_photos" value="no"> مایل به انتشار عکس‌ها در آگهی نیستم و فقط عکس‌ها به مشتریان حضوری نمایش داده شود</label>
                    </div>
                </div>
            </div>

            <!-- بخش دوم: توضیحات تکمیلی -->
            <div class="form-group">
                <label>توضیحات تکمیلی (حداکثر ۱۰۰۰ کاراکتر)</label>
                <textarea class="form-textarea" id="regFullDesc" name="full_description" placeholder="شرح کامل امکانات و شرایط باغ..." maxlength="1000" oninput="document.getElementById('charCount').innerText = this.value.length"></textarea>
                <small style="color:var(--text-secondary);">کاراکتر وارد شده: <span id="charCount">0</span> / ۱۰۰۰</small>
            </div>

            <!-- بخش سوم: پیش‌نمایش نهایی -->
            <div id="finalPreviewContainer" style="display:none;">
                <div class="preview-card">
                    <div class="preview-card-title">📋 پیش‌نمایش نهایی آگهی</div>
                    <div id="previewContent"></div>
                </div>

                <div style="display:flex; gap:var(--space-2); flex-wrap:wrap; margin-top:var(--space-3);">
                    <button type="button" class="btn-secondary" onclick="editPreview()" style="flex:1;max-width:none;">ویرایش اطلاعات</button>
                    <button type="submit" name="submit_property" class="btn-primary-full" style="flex:1;max-width:none;">ثبت نهایی آگهی</button>
                </div>
            </div>
        </div>

        <!-- دکمه‌های ناوبری -->
        <div class="bottom-actions">
            <button type="button" class="btn-secondary" id="prevBtn" onclick="changeStep(-1)" style="display:none;">مرحله قبل</button>
            <button type="button" class="btn-primary-full" id="nextBtn" onclick="changeStep(1)">مرحله بعد</button>
        </div>
    </form>
</div>

<script>
    // ==============================================
    // خواندن اطلاعات کاربر از sessionStorage
    // ==============================================
    document.addEventListener('DOMContentLoaded', function() {
        var gender = sessionStorage.getItem('reg_gender') || 'آقا';
        var lastName = sessionStorage.getItem('reg_last_name') || '';
        var phone = sessionStorage.getItem('reg_phone') || '';
        var telegramId = sessionStorage.getItem('reg_telegram_id') || '';
        var transactionType = sessionStorage.getItem('reg_transaction_type') || 'فروش';
        
        document.getElementById('hidden_gender').value = gender;
        document.getElementById('hidden_last_name').value = lastName;
        document.getElementById('hidden_phone').value = phone;
        document.getElementById('hidden_telegram_id').value = telegramId;
        document.getElementById('hidden_transaction_type').value = transactionType;
        
        console.log('اطلاعات کاربر:', { gender, lastName, phone, transactionType });
        
        if (!lastName || !phone) {
            alert('لطفاً ابتدا اطلاعات تماس خود را در مرحله اول ثبت کنید.');
            window.location.href = 'register-step1.php';
        }
        
        // ==============================================
        // Drag and Drop
        // ==============================================
        var dropZone = document.getElementById('dropZone');
        
        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.style.borderColor = 'var(--primary)';
            this.style.background = 'var(--gold-bg)';
        });
        
        dropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.style.borderColor = 'var(--border)';
            this.style.background = 'var(--bg)';
        });
        
        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.borderColor = 'var(--border)';
            this.style.background = 'var(--bg)';
            
            var files = e.dataTransfer.files;
            if (files.length > 0) {
                var fileInput = document.getElementById('fileInput');
                fileInput.files = files;
                handleImageUpload(fileInput);
            }
        });
    });

    var currentStep = 1;
    var totalSteps = 5;
    var transType = sessionStorage.getItem('reg_transaction_type') || 'فروش';
    var uploadedImages = [];

    // ===================== نمایش/مخفی کردن متراژ بنا =====================
    function toggleBuilding() {
        var hasBuilding = document.querySelector('input[name="has_building"]:checked');
        var buildingGroup = document.getElementById('buildingAreaGroup');
        if (hasBuilding && hasBuilding.value === '1') {
            buildingGroup.style.display = 'block';
            document.getElementById('regBuildingArea').required = true;
        } else {
            buildingGroup.style.display = 'none';
            document.getElementById('regBuildingArea').required = false;
            document.getElementById('regBuildingArea').value = '';
        }
    }

    // ===================== نمایش/مخفی کردن فیلدهای آب ملکی =====================
    function toggleWellWater() {
        var hasWell = document.querySelector('input[name="has_well"]:checked');
        var wellGroup = document.getElementById('wellWaterGroup');
        var waterShareInput = document.getElementById('regWaterShare');
        var wellNameInput = document.getElementById('regWellName');
        
        if (hasWell && hasWell.value === '1') {
            wellGroup.classList.add('visible');
            waterShareInput.required = true;
            wellNameInput.required = true;
        } else {
            wellGroup.classList.remove('visible');
            waterShareInput.required = false;
            waterShareInput.value = '';
            wellNameInput.required = false;
            wellNameInput.value = '';
        }
    }

    // ===================== توابع قیمت =====================
    function togglePriceCondition(el, otherVal) {
        var parent = el.closest('div');
        var other = parent.querySelector('input[value="' + otherVal + '"]');
        if (el.checked && other) other.checked = false;
    }

    function toggleFullRent() {
        var chk = document.getElementById('fullRentCheckbox');
        var grp = document.getElementById('fullRentGroup');
        var inp = grp.querySelector('input');
        var depositInput = document.querySelector('input[name="deposit"]');
        var rentInput = document.querySelector('input[name="rent_monthly"]');

        if (chk.checked) {
            grp.classList.add('visible');
            inp.disabled = false;
            depositInput.required = false;
            rentInput.required = false;
        } else {
            grp.classList.remove('visible');
            inp.disabled = true;
            inp.required = false;
            inp.value = '';
            depositInput.required = false;
            rentInput.required = false;
        }
    }

    function toggleExchange() {
        var chk = document.getElementById('exchangeCheckbox');
        var grp = document.getElementById('exchangeGroup');
        var inp = grp.querySelector('textarea');
        
        if (chk.checked) {
            grp.classList.add('visible');
            inp.disabled = false;
            inp.required = false;
        } else {
            grp.classList.remove('visible');
            inp.disabled = true;
            inp.required = false;
            inp.value = '';
        }
    }

    // ===================== مدیریت نمایش گزینه‌های انتشار بر اساس وجود عکس =====================
    function togglePublishOptions() {
        var container = document.getElementById('publishOptionsContainer');
        if (uploadedImages.length > 0) {
            container.style.display = 'block';
        } else {
            container.style.display = 'none';
        }
    }

    // ==============================================
    // آپلود تصاویر با AJAX (مطمئن‌ترین روش)
    // ==============================================
    function handleImageUpload(input) {
        var files = Array.from(input.files);
        var maxSize = 5 * 1024 * 1024;
        var allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

        if (files.length === 0) {
            alert('لطفاً حداقل یک تصویر انتخاب کنید.');
            return;
        }

        // بررسی فایل‌ها
        var validFiles = [];
        files.forEach(function(file) {
            if (allowedTypes.indexOf(file.type) === -1) {
                alert('فرمت ' + file.type + ' مجاز نیست. فقط JPG, PNG, WEBP مجاز است.');
                return;
            }
            if (file.size > maxSize) {
                alert('حجم فایل ' + file.name + ' بیش از ۵ مگابایت است.');
                return;
            }
            validFiles.push(file);
        });

        if (validFiles.length === 0) return;

        // نمایش پیش‌نمایش
        validFiles.forEach(function(file) {
            var reader = new FileReader();
            reader.onload = function(e) {
                var container = document.getElementById('imagePreviewContainer');
                var div = document.createElement('div');
                div.className = 'image-preview-item';
                div.innerHTML = `
                    <img src="${e.target.result}" alt="تصویر ${uploadedImages.length + 1}">
                    <button class="remove-btn" onclick="removeImage(${uploadedImages.length})">✕</button>
                `;
                container.appendChild(div);
            };
            reader.readAsDataURL(file);
        });

        // ارسال فایل‌ها با AJAX
        var formData = new FormData();
        validFiles.forEach(function(file) {
            formData.append('images[]', file);
        });
        formData.append('action', 'upload_images');

        var progressDiv = document.getElementById('uploadProgress');
        var progressBar = document.getElementById('progressBar');
        var progressText = document.getElementById('progressText');
        
        progressDiv.style.display = 'block';
        progressBar.style.width = '0%';
        progressText.innerText = 'در حال آپلود...';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.href, true);
        
        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                var percent = Math.round((e.loaded / e.total) * 100);
                progressBar.style.width = percent + '%';
                progressText.innerText = 'آپلود: ' + percent + '%';
            }
        };
        
        xhr.onload = function() {
            progressDiv.style.display = 'none';
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        uploadedImages = uploadedImages.concat(response.images);
                        document.getElementById('uploaded_images').value = uploadedImages.join(',');
                        togglePublishOptions();
                        console.log('✅ تصاویر آپلود شدند:', uploadedImages);
                    } else {
                        alert('خطا در آپلود تصاویر');
                    }
                } catch (e) {
                    alert('خطا در پردازش پاسخ سرور');
                }
            } else {
                alert('خطا در ارتباط با سرور');
            }
            input.value = '';
        };
        
        xhr.onerror = function() {
            progressDiv.style.display = 'none';
            alert('خطا در آپلود تصاویر');
            input.value = '';
        };
        
        xhr.send(formData);
    }

    function removeImage(index) {
        uploadedImages.splice(index, 1);
        document.getElementById('uploaded_images').value = uploadedImages.join(',');
        
        // حذف از پیش‌نمایش
        var container = document.getElementById('imagePreviewContainer');
        var items = container.querySelectorAll('.image-preview-item');
        if (items[index]) {
            items[index].remove();
        }
        
        togglePublishOptions();
    }

    // ===================== پیش‌نمایش نهایی =====================
    function updatePreview() {
        var previewContainer = document.getElementById('finalPreviewContainer');
        previewContainer.style.display = 'block';

        var gender = document.getElementById('hidden_gender').value || '-';
        var lastName = document.getElementById('hidden_last_name').value || '-';
        var phone = document.getElementById('hidden_phone').value || '-';
        var transTypeLabel = transType === 'فروش' ? 'خرید و فروش' : transType === 'پیش فروش' ? 'پیش فروش' : 'اجاره';
        var propertyType = 'باغ';
        var title = document.getElementById('regTitle').value || '-';
        var location = document.getElementById('regLocation').value || '-';
        var address = document.getElementById('regAddress').value || '-';
        var description = document.getElementById('regFullDesc').value || '-';
        
        var publishPhotos = document.querySelector('input[name="publish_photos"]:checked');
        var publishStatus = publishPhotos ? (publishPhotos.value === 'yes' ? '✅ منتشر شود' : '❌ منتشر نشود (فقط حضوری)') : '-';

        // جمع‌آوری مشخصات
        var specsHtml = '';
        document.querySelectorAll('#step2 .form-group').forEach(function(group) {
            var label = group.querySelector('label') ? group.querySelector('label').innerText : '';
            var value = '';
            var input = group.querySelector('input, select');
            if (input) {
                if (input.tagName === 'SELECT') {
                    value = input.options[input.selectedIndex] ? input.options[input.selectedIndex].innerText : '-';
                } else if (input.type === 'radio') {
                    var checkedRadio = group.querySelector('input[type="radio"]:checked');
                    value = checkedRadio ? (checkedRadio.value === '1' ? 'دارد' : 'ندارد') : '-';
                } else {
                    value = input.value || '-';
                }
            } else {
                // برای فیلد نوع سند که رادیویی است
                var radioGroup = group.querySelector('.radio-group');
                if (radioGroup) {
                    var checkedRadio = radioGroup.querySelector('input[type="radio"]:checked');
                    value = checkedRadio ? checkedRadio.value : '-';
                }
            }
            specsHtml += '<div class="preview-row"><span class="preview-label">' + label + '</span><span class="preview-value rtl">' + value + '</span></div>';
        });

        // اضافه کردن فیلدهای آب ملکی به پیش‌نمایش
        var hasWell = document.querySelector('input[name="has_well"]:checked');
        if (hasWell && hasWell.value === '1') {
            var waterShare = document.getElementById('regWaterShare').value || '-';
            var wellName = document.getElementById('regWellName').value || '-';
            specsHtml += '<div class="preview-row"><span class="preview-label">مقدار ساعت آب</span><span class="preview-value rtl">' + waterShare + '</span></div>';
            specsHtml += '<div class="preview-row"><span class="preview-label">نام چاه آب</span><span class="preview-value rtl">' + wellName + '</span></div>';
        }

        var amenitiesHtml = '';
        document.querySelectorAll('#step3 input[type="checkbox"]:checked').forEach(function(cb) {
            amenitiesHtml += '<span style="display:inline-block;background:var(--bg);padding:2px 8px;border-radius:4px;border:1px solid var(--border);margin:2px;">' + cb.value + '</span> ';
        });
        if (!amenitiesHtml) amenitiesHtml = 'هیچکدام';

        var priceHtml = '';
        if (transType === 'فروش') {
            var price = document.querySelector('input[name="price_sell"]') ? document.querySelector('input[name="price_sell"]').value : '-';
            var condition = document.querySelector('input[name="price_condition"]:checked') ? document.querySelector('input[name="price_condition"]:checked').value : 'negotiable';
            var exchangeChk = document.getElementById('exchangeCheckbox');
            var exchangeVal = exchangeChk && exchangeChk.checked ? document.querySelector('textarea[name="exchange_with"]').value : '-';
            priceHtml = '<div class="preview-row"><span class="preview-label">قیمت</span><span class="preview-value">' + price + ' تومان</span></div>';
            priceHtml += '<div class="preview-row"><span class="preview-label">وضعیت</span><span class="preview-value rtl">' + (condition === 'negotiable' ? 'قابل مذاکره' : 'مقطوع') + '</span></div>';
            if (exchangeChk && exchangeChk.checked) {
                priceHtml += '<div class="preview-row"><span class="preview-label">معاوضه با</span><span class="preview-value rtl">' + exchangeVal + '</span></div>';
            }
        } else if (transType === 'اجاره') {
            var deposit = document.querySelector('input[name="deposit"]') ? document.querySelector('input[name="deposit"]').value : '-';
            var rent = document.querySelector('input[name="rent_monthly"]') ? document.querySelector('input[name="rent_monthly"]').value : '-';
            var fullRentChk = document.getElementById('fullRentCheckbox');
            var fullRentVal = fullRentChk.checked ? document.querySelector('input[name="full_rent"]').value : '-';
            priceHtml = '<div class="preview-row"><span class="preview-label">ودیعه</span><span class="preview-value">' + deposit + ' تومان</span></div>';
            priceHtml += '<div class="preview-row"><span class="preview-label">اجاره ماهانه</span><span class="preview-value">' + rent + ' تومان</span></div>';
            if (fullRentChk.checked) {
                priceHtml += '<div class="preview-row"><span class="preview-label">رهن کامل</span><span class="preview-value">' + fullRentVal + ' تومان</span></div>';
            }
        } else if (transType === 'پیش فروش') {
            var total = document.querySelector('input[name="total_price"]') ? document.querySelector('input[name="total_price"]').value : '-';
            var down = document.querySelector('input[name="down_payment"]') ? document.querySelector('input[name="down_payment"]').value : '-';
            var terms = document.querySelector('textarea[name="payment_terms"]') ? document.querySelector('textarea[name="payment_terms"]').value : '-';
            priceHtml = '<div class="preview-row"><span class="preview-label">قیمت کل</span><span class="preview-value">' + total + ' تومان</span></div>';
            priceHtml += '<div class="preview-row"><span class="preview-label">پیش‌پرداخت</span><span class="preview-value">' + down + ' تومان</span></div>';
            priceHtml += '<div class="preview-row"><span class="preview-label">شرایط پرداخت</span><span class="preview-value rtl">' + terms + '</span></div>';
        }

        document.getElementById('previewContent').innerHTML = 
            '<div class="preview-card-title" style="font-size:14px;margin-top:0;">اطلاعات تماس</div>' +
            '<div class="preview-row"><span class="preview-label">جنسیت</span><span class="preview-value rtl">' + gender + '</span></div>' +
            '<div class="preview-row"><span class="preview-label">نام خانوادگی</span><span class="preview-value rtl">' + lastName + '</span></div>' +
            '<div class="preview-row"><span class="preview-label">شماره تماس</span><span class="preview-value">' + phone + '</span></div>' +
            
            '<div class="preview-card-title" style="font-size:14px;">نوع معامله و ملک</div>' +
            '<div class="preview-row"><span class="preview-label">نوع معامله</span><span class="preview-value rtl">' + transTypeLabel + '</span></div>' +
            '<div class="preview-row"><span class="preview-label">نوع ملک</span><span class="preview-value rtl">' + propertyType + '</span></div>' +

            '<div class="preview-card-title" style="font-size:14px;">اطلاعات پایه</div>' +
            '<div class="preview-row"><span class="preview-label">عنوان</span><span class="preview-value rtl">' + title + '</span></div>' +
            '<div class="preview-row"><span class="preview-label">محله</span><span class="preview-value rtl">' + location + '</span></div>' +
            '<div class="preview-row"><span class="preview-label">آدرس دقیق</span><span class="preview-value rtl">' + address + '</span></div>' +

            '<div class="preview-card-title" style="font-size:14px;">مشخصات باغ</div>' +
            specsHtml +

            '<div class="preview-card-title" style="font-size:14px;">امکانات</div>' +
            '<div class="preview-row"><span class="preview-label">انتخاب شده</span><span class="preview-value rtl" style="font-weight:normal;">' + amenitiesHtml + '</span></div>' +

            '<div class="preview-card-title" style="font-size:14px;">قیمت</div>' +
            priceHtml +

            '<div class="preview-card-title" style="font-size:14px;">تصاویر</div>' +
            '<div class="preview-row"><span class="preview-label">وضعیت انتشار</span><span class="preview-value rtl">' + publishStatus + '</span></div>' +
            '<div id="previewImages" class="image-preview-grid"></div>' +

            '<div class="preview-card-title" style="font-size:14px;">توضیحات</div>' +
            '<div class="preview-row"><span class="preview-label">متن</span><span class="preview-value rtl" style="font-weight:normal;white-space:pre-wrap;">' + description + '</span></div>';
        
        renderPreviewImages();
    }

    function renderPreviewImages() {
        var container = document.getElementById('previewImages');
        container.innerHTML = '';
        uploadedImages.forEach(function(imgPath, index) {
            var div = document.createElement('div');
            div.className = 'image-preview-item';
            div.innerHTML = '<img src="' + imgPath + '" alt="تصویر ' + (index+1) + '">';
            container.appendChild(div);
        });
    }

    // ===================== دکمه‌های مرحله ۵ =====================
    function editPreview() {
        document.getElementById('finalPreviewContainer').style.display = 'none';
        document.getElementById('prevBtn').style.display = (currentStep === 1) ? 'none' : 'flex';
        document.getElementById('nextBtn').style.display = 'flex';
        document.getElementById('nextBtn').innerText = 'مرحله بعد';
        changeStep(-4);
    }

    // ===================== تغییر گام‌ها =====================
    function changeStep(direction) {
        if (currentStep === totalSteps && direction === 1) {
            document.getElementById('prevBtn').style.display = 'none';
            document.getElementById('nextBtn').style.display = 'none';
            updatePreview();
            return;
        }

        document.getElementById('step' + currentStep).classList.remove('active');
        currentStep += direction;
        if (currentStep < 1) currentStep = 1;
        if (currentStep > totalSteps) currentStep = totalSteps;
        document.getElementById('step' + currentStep).classList.add('active');
        document.getElementById('stepText').innerText = 'مرحله ' + currentStep + ' از ' + totalSteps;
        for (var i=1; i<totalSteps; i++) {
            var line = document.getElementById('line' + i);
            if (i < currentStep) line.classList.add('active');
            else line.classList.remove('active');
        }

        document.getElementById('prevBtn').style.display = (currentStep === 1) ? 'none' : 'flex';
        document.getElementById('nextBtn').innerText = (currentStep === totalSteps) ? 'پیش‌نمایش و ثبت' : 'مرحله بعد';
        document.getElementById('mainContent').scrollTop = 0;
    }

    // ===================== تبدیل اعداد و فرمت قیمت =====================
    function toEnglishDigits(str) {
        var persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        var arabicDigits = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        for (var i = 0; i < 10; i++) {
            str = str.replaceAll(persianDigits[i], i);
            str = str.replaceAll(arabicDigits[i], i);
        }
        return str;
    }

    function formatPrice(input) {
        var val = toEnglishDigits(input.value);
        val = val.replace(/,/g, '').replace(/[^0-9]/g, '');
        if (val === '') { input.value = ''; return; }
        var num = parseInt(val, 10);
        input.value = num.toLocaleString('en-US');
    }

    function normalizeNumeric(input) {
        var val = toEnglishDigits(input.value);
        val = val.replace(/[^0-9.]/g, '');
        if (val === '') { input.value = ''; return; }
        if ((val.match(/\./g) || []).length > 1) {
            val = val.replace(/\.(?=.*\.)/g, '');
        }
        input.value = val;
    }

    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('price-input')) {
            formatPrice(e.target);
        }
        if (e.target.classList.contains('numeric-input')) {
            normalizeNumeric(e.target);
        }
    });
</script>
<?php require_once 'footer.php'; ?>