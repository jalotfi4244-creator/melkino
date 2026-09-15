<?php
/*
|--------------------------------------------------------------------------
| ثبت مستقیم آگهی توسط ادمین از داخل پنل
|--------------------------------------------------------------------------
| قبلاً هیچ راهی برای ثبت آگهی از خودِ پنل ادمین نبود؛ فقط می‌شد
| آگهی‌هایی که کاربران عادی از طریق فرم‌های ثبت ملک فرستاده بودند را
| ویرایش کرد. این فایل یک آگهیِ خالیِ اولیه می‌سازد (فقط با عنوان و
| نوع ملک/معامله)، بعد ادمین از همان مودال ویرایشِ آشنا و تست‌شده
| (که الان مشکل ذخیره‌ی عکس هم در آن رفع شده) بقیه‌ی جزئیات و عکس‌ها
| را کامل می‌کند.
|--------------------------------------------------------------------------
*/

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/property-db-helper.php';

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

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

$title = trim((string)($body['title'] ?? 'آگهی جدید'));
$transactionType = trim((string)($body['transaction_type'] ?? 'فروش'));
$propertyType = trim((string)($body['property_type'] ?? 'آپارتمان'));

$newAd = [
    'title' => $title !== '' ? $title : 'آگهی جدید',
    'status' => 'published',
    'transaction_type' => $transactionType,
    'property_type' => $propertyType,
    'gender' => 'آقا',
    'last_name' => 'ملکینو',
    'phone' => '',
    'location' => '',
    'address' => '',
    'description' => '',
    'publish_photos' => 'yes',
    'images' => [],
    'selectedImages' => [],
    'tags' => [],
];

try {
    $result = savePropertyToDatabase($pdo, $newAd);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطا در ساخت آگهی.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($result['success'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $result['error'] ?? 'ساخت آگهی ناموفق بود.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT * FROM ads WHERE id = ? LIMIT 1');
    $stmt->execute([$result['id']]);
    $ad = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $ad = null;
}

echo json_encode(['success' => true, 'ad' => $ad], JSON_UNESCAPED_UNICODE);
