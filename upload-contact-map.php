<?php
/*
|--------------------------------------------------------------------------
| آپلودِ تصویرِ نقشه‌ی دفتر برای صفحه‌ی «ارتباط با ما»
|--------------------------------------------------------------------------
| ادمین از نقشه اسکرین‌شات می‌گیرد و اینجا آپلود می‌کند؛ همین عکس در
| صفحه‌ی «ارتباط با ما» نمایش داده می‌شود (جایگزین نقشه‌ی زنده‌ی نشان).
| فایل در پوشه‌ی uploads/contact ذخیره و مسیرِ نسبیِ آن در تنظیماتِ
| تماس ذخیره می‌گردد.
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_FILES['map']) || ($_FILES['map']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'message' => 'هیچ فایلی انتخاب نشده است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['map'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $map = [
        UPLOAD_ERR_INI_SIZE   => 'حجم فایل بیش از حد مجازِ سرور است.',
        UPLOAD_ERR_FORM_SIZE  => 'حجم فایل بیش از حد مجاز است.',
        UPLOAD_ERR_PARTIAL    => 'فایل ناقص آپلود شد.',
        UPLOAD_ERR_NO_TMP_DIR => 'پوشه‌ی موقت روی سرور موجود نیست.',
        UPLOAD_ERR_CANT_WRITE => 'نوشتن فایل روی سرور ممکن نشد.',
    ];
    echo json_encode([
        'success' => false,
        'message' => $map[$file['error']] ?? 'خطای ناشناخته در آپلود.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$maxFileSize = 3 * 1024 * 1024; // ۳ مگابایت

if ($file['size'] > $maxFileSize) {
    echo json_encode(['success' => false, 'message' => 'حجم فایل باید کمتر از ۳ مگابایت باشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- بررسیِ واقعیِ نوع فایل ---------- */

$allowedExtensions = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
$extension         = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($extension, $allowedExtensions, true)) {
    echo json_encode([
        'success' => false,
        'message' => 'فرمت مجاز نیست. فقط png, jpg, jpeg, webp, gif قابل قبول است.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$imageInfo = @getimagesize($file['tmp_name']);

if ($imageInfo === false) {
    echo json_encode(['success' => false, 'message' => 'فایل انتخابی یک تصویر معتبر نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedMimes = [
    'image/png',
    'image/jpeg',
    'image/webp',
    'image/gif',
];

if (!in_array($imageInfo['mime'], $allowedMimes, true)) {
    echo json_encode(['success' => false, 'message' => 'نوع تصویر پشتیبانی نمی‌شود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- پوشه‌ی مقصد ---------- */

$targetDir = __DIR__ . '/uploads/contact/';

if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0755, true);
}

if (!is_dir($targetDir) || !is_writable($targetDir)) {
    echo json_encode([
        'success' => false,
        'message' => 'پوشه‌ی uploads/contact روی سرور قابل نوشتن نیست (دسترسی ۷۵۵ لازم است).',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- نامِ امن ---------- */

$safeName = 'map_' . bin2hex(random_bytes(8)) . '.' . $extension;
$target   = $targetDir . $safeName;

if (!move_uploaded_file($file['tmp_name'], $target)) {
    echo json_encode(['success' => false, 'message' => 'انتقال فایل به پوشه‌ی مقصد انجام نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

@chmod($target, 0644);

echo json_encode([
    'success' => true,
    'message' => 'تصویر نقشه با موفقیت بارگذاری شد.',
    'path'    => 'uploads/contact/' . $safeName,
    'url'     => 'uploads/contact/' . $safeName,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
