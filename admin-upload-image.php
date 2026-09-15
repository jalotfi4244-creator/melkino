<?php
/*
|--------------------------------------------------------------------------
| آپلود تصویر برای یک آگهی موجود، از داخل پنل ادمین (بخش ویرایش آگهی)
|--------------------------------------------------------------------------
| قبلاً هیچ راهی برای اضافه‌کردن عکس جدید به یک آگهی از پنل ادمین
| وجود نداشت؛ فقط عکس‌هایی که خودِ کاربر موقع ثبت ملک فرستاده بود
| قابل انتخاب/عدم‌انتخاب بودند. این فایل به ادمین اجازه می‌دهد مستقیم
| از پنل، عکس جدید به هر آگهی اضافه کند.
|--------------------------------------------------------------------------
*/

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

$adId = trim((string)($_POST['ad_id'] ?? ''));

if ($adId === '') {
    echo json_encode(['success' => false, 'message' => 'شناسه‌ی آگهی نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$targetDir = __DIR__ . '/uploads/';
$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$maxFileSize = 5 * 1024 * 1024;

if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0755, true);
}

if (!is_dir($targetDir) || !is_writable($targetDir)) {
    echo json_encode([
        'success' => false,
        'message' => 'پوشه‌ی uploads روی سرور وجود ندارد یا قابل نوشتن نیست (دسترسی ۷۵۵ لازم است).',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploaded = [];
$errors = [];

if (isset($_FILES['images']) && is_array($_FILES['images']['name']) && count($_FILES['images']['name']) > 0) {
    for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
        if (empty($_FILES['images']['name'][$i])) continue;

        if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = $_FILES['images']['name'][$i] . ': کد خطای آپلود ' . $_FILES['images']['error'][$i];
            continue;
        }

        if ($_FILES['images']['size'][$i] > $maxFileSize) {
            $errors[] = $_FILES['images']['name'][$i] . ': حجم فایل بیش از ۵ مگابایت است.';
            continue;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $_FILES['images']['tmp_name'][$i]);
        finfo_close($finfo);

        $fileExt = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));

        if (!in_array($mimeType, $allowedMimes) || !in_array($fileExt, $allowedExtensions)) {
            $errors[] = $_FILES['images']['name'][$i] . ': فرمت تصویر مجاز نیست.';
            continue;
        }

        $uniqueId = uniqid() . '_' . bin2hex(random_bytes(4));
        $safeAdId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $adId);
        $fileName = $safeAdId . '_admin_' . date('Ymd_His') . '_' . $uniqueId . '.' . $fileExt;
        $targetFile = $targetDir . $fileName;

        if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $targetFile)) {
            $uploaded[] = 'uploads/' . $fileName;
        } else {
            $errors[] = $_FILES['images']['name'][$i] . ': ذخیره‌ی فایل روی سرور ناموفق بود.';
        }
    }
} else {
    $errors[] = 'هیچ فایلی دریافت نشد.';
}

echo json_encode([
    'success' => count($uploaded) > 0,
    'images' => $uploaded,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE);
