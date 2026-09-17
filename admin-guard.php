<?php
/**
|--------------------------------------------------------------------------
| محافظ مشترکِ endpointهای پنل ادمین
|--------------------------------------------------------------------------
| هر فایل API جدیدِ پنل، ابتدا این فایل را require می‌کند.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    session_start();
}

require_once __DIR__ . '/config.php';

if (!function_exists('melkinoRequireAdminJson')) {
    function melkinoRequireAdminJson(): void
    {
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

if (!function_exists('melkinoAdminJson')) {
    function melkinoAdminJson(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('melkinoAdminJsonBody')) {
    function melkinoAdminJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode((string)$raw, true);
        if (is_array($data)) {
            return $data;
        }
        return $_POST;
    }
}
