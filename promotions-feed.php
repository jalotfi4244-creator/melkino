<?php
/**
|--------------------------------------------------------------------------
| خوراک تبلیغ‌ها برای صفحاتی که کارت‌ها را با جاوااسکریپت می‌سازند
|--------------------------------------------------------------------------
| خروجی: { success, promotions: [{ position_after, repeat_every, html }] }
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/promotions.php';

header('Content-Type: application/json; charset=utf-8');

$placement = trim((string)($_GET['placement'] ?? 'all'));
$allowed = ['all', 'home', 'properties', 'search', 'vip'];
if (!in_array($placement, $allowed, true)) {
    $placement = 'all';
}

$rows = melkinoActivePromotions($placement);

$out = [];
foreach ($rows as $row) {
    $out[] = [
        'id' => (int)$row['id'],
        'position_after' => (int)($row['position_after'] ?: 3),
        'repeat_every' => (int)($row['repeat_every'] ?: 0),
        'html' => melkinoRenderPromotion($row),
    ];
}

echo json_encode(['success' => true, 'promotions' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
