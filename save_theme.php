<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cssFile = __DIR__ . '/style.css';
$settingsDir = __DIR__ . '/settings';
$metaFile = $settingsDir . '/theme.json';

if (!is_dir($settingsDir)) {
    @mkdir($settingsDir, 0755, true);
}

$allowed = [
        '--primary' => '--primary',
        '--primary-dark' => '--primary-dark',
        '--primary-light' => '--primary-light',
        '--secondary' => '--secondary',
        '--gold' => '--gold',
        '--gold-light' => '--gold-light',
        '--gold-dark' => '--gold-dark',
        '--gold-bg' => '--gold-bg',
        '--bg' => '--bg',
        '--bg-secondary' => '--bg-secondary',
        '--bg-tertiary' => '--bg-tertiary',
        '--surface' => '--surface',
        '--surface-elevated' => '--surface-elevated',
        '--surface-soft' => '--surface-soft',
        '--text-primary' => '--text-primary',
        '--text-secondary' => '--text-secondary',
        '--text-muted' => '--text-muted',
        '--border' => '--border',
        '--border-light' => '--border-light',
        '--border-strong' => '--border-strong',
        '--success' => '--success',
        '--success-bg' => '--success-bg',
        '--warning' => '--warning',
        '--warning-bg' => '--warning-bg',
        '--danger' => '--danger',
        '--danger-bg' => '--danger-bg',
        '--info' => '--info',
        '--info-bg' => '--info-bg'
    ];
$defaultThemes = [
    'light' => [
        '--primary' => '#064E4E',
        '--primary-dark' => '#043B3B',
        '--primary-light' => '#0F766E',
        '--secondary' => '#0F766E',
        '--gold' => '#D4AF37',
        '--gold-light' => '#E6C766',
        '--gold-dark' => '#A98416',
        '--gold-bg' => '#FEF3C7',
        '--bg' => '#FAFAF7',
        '--bg-secondary' => '#F3F4EF',
        '--bg-tertiary' => '#ECEEE8',
        '--surface' => '#FFFFFF',
        '--surface-elevated' => '#FFFFFF',
        '--surface-soft' => '#F8F9F6',
        '--text-primary' => '#111827',
        '--text-secondary' => '#6B7280',
        '--text-muted' => '#9CA3AF',
        '--border' => '#E5E7EB',
        '--border-light' => '#EEF0EC',
        '--border-strong' => '#D1D5DB',
        '--success' => '#22C55E',
        '--success-bg' => '#DCFCE7',
        '--warning' => '#F59E0B',
        '--warning-bg' => '#FEF3C7',
        '--danger' => '#EF4444',
        '--danger-bg' => '#FEE2E2',
        '--info' => '#0EA5E9',
        '--info-bg' => '#E0F2FE'
    ],
    'dark' => [
        '--primary' => '#159B8F',
        '--primary-dark' => '#0E6C63',
        '--primary-light' => '#34B7A9',
        '--secondary' => '#0F766E',
        '--gold' => '#E5B842',
        '--gold-light' => '#F1D477',
        '--gold-dark' => '#B98F19',
        '--gold-bg' => '#3D2E0A',
        '--bg' => '#0D1413',
        '--bg-secondary' => '#111C1A',
        '--bg-tertiary' => '#182420',
        '--surface' => '#16201E',
        '--surface-elevated' => '#1B2724',
        '--surface-soft' => '#192521',
        '--text-primary' => '#F3F4F6',
        '--text-secondary' => '#A8B1AE',
        '--text-muted' => '#7F8A87',
        '--border' => '#293633',
        '--border-light' => '#222E2B',
        '--border-strong' => '#394945',
        '--success' => '#4ADE80',
        '--success-bg' => '#123722',
        '--warning' => '#FBBF24',
        '--warning-bg' => '#3D2E0A',
        '--danger' => '#F87171',
        '--danger-bg' => '#401919',
        '--info' => '#38BDF8',
        '--info-bg' => '#123445'
    ],
];

function readCssBlock(string $css, string $selector): string {
    $pos = stripos($css, $selector);
    if ($pos === false) return '';
    $open = strpos($css, '{', $pos);
    if ($open === false) return '';
    $depth = 0;
    $len = strlen($css);
    for ($i = $open; $i < $len; $i++) {
        if ($css[$i] === '{') $depth++;
        elseif ($css[$i] === '}') {
            $depth--;
            if ($depth === 0) return substr($css, $open + 1, $i - $open - 1);
        }
    }
    return '';
}

function extractThemeMap(string $css, string $selector, array $allowed): array {
    $block = readCssBlock($css, $selector);
    $out = [];
    foreach ($allowed as $key) {
        if (preg_match('/'.preg_quote($key,'/').'\s*:\s*([^;]+);/', $block, $m)) {
            $out[$key] = trim($m[1]);
        }
    }
    return $out;
}

function validColor(string $value): bool {
    return (bool)preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\))$/', trim($value));
}

function mergeTheme(array $defaults, $incoming): array {
    $incoming = is_array($incoming) ? $incoming : [];
    $result = $defaults;
    foreach ($incoming as $key => $value) {
        if (is_string($key) && array_key_exists($key, $defaults) && is_string($value) && validColor($value)) {
            $result[$key] = trim($value);
        }
    }
    return $result;
}

function replaceCssBlock(string $css, string $selector, array $values): string {
    $pos = stripos($css, $selector);
    if ($pos === false) return $css;
    $open = strpos($css, '{', $pos);
    if ($open === false) return $css;
    $depth = 0;
    $len = strlen($css);
    $close = null;
    for ($i = $open; $i < $len; $i++) {
        if ($css[$i] === '{') $depth++;
        elseif ($css[$i] === '}') {
            $depth--;
            if ($depth === 0) { $close = $i; break; }
        }
    }
    if ($close === null) return $css;
    $block = substr($css, $open + 1, $close - $open - 1);
    foreach ($values as $key => $value) {
        $pattern = '/'.preg_quote($key,'/').'\s*:\s*[^;]+;/';
        $replacement = '    '.$key.': '.$value.';';
        if (preg_match($pattern, $block)) $block = preg_replace($pattern, $replacement, $block, 1);
        else $block .= "\n".$replacement."\n";
    }
    return substr($css, 0, $open + 1) . $block . substr($css, $close);
}

$css = is_file($cssFile) ? (string)@file_get_contents($cssFile) : '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $themes = [
        'light' => array_merge($defaultThemes['light'], extractThemeMap($css, ':root', $allowed)),
        'dark'  => array_merge($defaultThemes['dark'], extractThemeMap($css, '[data-theme="dark"]', $allowed)),
    ];
    echo json_encode(['success'=>true,'themes'=>$themes], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'ساختار رنگ‌ها نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$existing = [
    'light' => array_merge($defaultThemes['light'], extractThemeMap($css, ':root', $allowed)),
    'dark'  => array_merge($defaultThemes['dark'], extractThemeMap($css, '[data-theme="dark"]', $allowed)),
];
$light = mergeTheme($existing['light'], $payload['themes']['light'] ?? []);
$dark = mergeTheme($existing['dark'], $payload['themes']['dark'] ?? []);

$css = replaceCssBlock($css, ':root', $light);
$css = replaceCssBlock($css, '[data-theme="dark"]', $dark);

if (@file_put_contents($cssFile, $css, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'نوشتن style.css ممکن نشد. دسترسی نوشتن فایل را بررسی کن.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stored = ['themes'=>['light'=>$light,'dark'=>$dark], 'updated_at'=>date('Y-m-d H:i:s')];
if (@file_put_contents($metaFile, json_encode($stored, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
    // CSS ذخیره شده؛ ناتوانی در متادیتا مانع موفقیت اصلی نمی‌شود.
}

echo json_encode(['success'=>true,'themes'=>$stored['themes']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);