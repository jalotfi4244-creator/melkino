<?php
/**
|--------------------------------------------------------------------------
| ملک‌های مشابه — نمایش در انتهای صفحه‌ی جزئیات هر ملک
|--------------------------------------------------------------------------
| امتیازدهی بر اساس:
|   نوع ملک (۴۰) · نوع معامله (۲۵) · محله/موقعیت (۲۰) · نزدیکی متراژ (۱۵)
|   نزدیکی قیمت (۱۰) · تازگی آگهی (تا ۵)
|--------------------------------------------------------------------------
*/

if (!function_exists('melkinoAdNumericPrice')) {
    function melkinoAdNumericPrice(array $ad): float
    {
        $raw = null;

        if (($ad['transaction_type'] ?? '') === 'اجاره') {
            $raw = $ad['deposit'] ?? $ad['total_price'] ?? null;
        } else {
            $raw = $ad['total_price'] ?? $ad['price_sell'] ?? $ad['price'] ?? $ad['deposit'] ?? null;
        }

        if ($raw === null || $raw === '') {
            return 0.0;
        }
        $raw = str_replace([',', '٬', ' '], '', (string)$raw);
        return is_numeric($raw) ? (float)$raw : 0.0;
    }
}

if (!function_exists('melkinoSimilarProperties')) {
    function melkinoSimilarProperties(string $currentId, int $limit = 6): array
    {
        global $pdo;
        if (!($pdo instanceof PDO) || $currentId === '') {
            return [];
        }

        try {
            $st = $pdo->prepare("SELECT * FROM ads WHERE id = ? LIMIT 1");
            $st->execute([$currentId]);
            $current = $st->fetch(PDO::FETCH_ASSOC);
            if (!$current) {
                return [];
            }

            // مقادیر ملک مرجع باید پیش از اجرای کوئری مشخص شوند
            $currentType = (string)($current['property_type'] ?? '');
            $currentDeal = (string)($current['transaction_type'] ?? '');

            // فقط ملک‌هایی با همان نوع معامله (خرید/فروش در برابر اجاره/رهن)
            $candidates = $pdo->prepare(
                "SELECT * FROM ads
                  WHERE status = 'published'
                    AND id <> ?
                    AND transaction_type = ?
                  ORDER BY created_at DESC
                  LIMIT 200"
            );
            $candidates->execute([$currentId, $currentDeal]);
            $rows = $candidates->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }

        $currentLocation = (string)($current['location'] ?? ($current['neighborhood'] ?? ''));
        $currentArea = isset($current['area']) ? (float)$current['area'] : 0.0;
        $currentPrice = melkinoAdNumericPrice($current);
        $isRent = ($currentDeal === 'اجاره');

        $scored = [];

        foreach ($rows as $row) {
            $score = 0;

            if ((string)($row['property_type'] ?? '') === $currentType) {
                $score += 40;
            }
            if ($currentLocation !== '' && (string)($row['location'] ?? '') === $currentLocation) {
                $score += 20;
            }

            $area = isset($row['area']) ? (float)$row['area'] : 0.0;
            if ($currentArea > 0 && $area > 0) {
                $diff = abs($area - $currentArea) / $currentArea;
                if ($diff <= 0.15) $score += 15;
                elseif ($diff <= 0.35) $score += 8;
            }

            // در اجاره، ودیعه/رهن ملاک است؛ در فروش، قیمت کل
            $price = melkinoAdNumericPrice($row);
            if ($isRent) {
                $currentRent = isset($current['rent_monthly']) ? (float)$current['rent_monthly'] : 0.0;
                $rowRent = isset($row['rent_monthly']) ? (float)$row['rent_monthly'] : 0.0;
                if ($currentPrice > 0 && $price > 0) {
                    $diff = abs($price - $currentPrice) / $currentPrice;
                    if ($diff <= 0.25) $score += 10;
                    elseif ($diff <= 0.5) $score += 5;
                }
                if ($currentRent > 0 && $rowRent > 0) {
                    $diffRent = abs($rowRent - $currentRent) / $currentRent;
                    if ($diffRent <= 0.25) $score += 8;
                    elseif ($diffRent <= 0.5) $score += 4;
                }
            } else {
                if ($currentPrice > 0 && $price > 0) {
                    $diff = abs($price - $currentPrice) / $currentPrice;
                    if ($diff <= 0.15) $score += 10;
                    elseif ($diff <= 0.35) $score += 5;
                }
            }

            // تازگی آگهی
            $created = strtotime((string)($row['created_at'] ?? ''));
            if ($created > 0) {
                $days = max(0, (time() - $created) / 86400);
                $score += max(0, 5 - (int)($days / 14));
            }

            if ($score <= 0) {
                continue;
            }

            $row['__similarity'] = $score;
            $scored[] = $row;
        }

        usort($scored, fn($a, $b) => ($b['__similarity'] ?? 0) <=> ($a['__similarity'] ?? 0));

        return array_slice($scored, 0, max(1, $limit));
    }
}

if (!function_exists('melkinoSimilarFirstImage')) {
    function melkinoSimilarFirstImage(PDO $pdo, string $adId): string
    {
        try {
            $st = $pdo->prepare(
                "SELECT filename FROM images
                  WHERE ad_id = ? AND is_selected = 1 AND publish_publicly = 1
                  ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1"
            );
            $st->execute([$adId]);
            $name = $st->fetchColumn();
            return $name ? 'uploads/' . ltrim((string)$name, '/') : '';
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('melkinoRenderSimilarProperties')) {
    function melkinoRenderSimilarProperties(string $currentId, int $limit = 6): string
    {
        global $pdo;

        $items = melkinoSimilarProperties($currentId, $limit);
        if (!$items) {
            return '';
        }

        $html = '<style>
.similar-section{margin:28px 16px 0;padding-bottom:120px;direction:rtl}
.similar-title{font-size:16px;font-weight:800;color:var(--text-primary);margin:0 0 4px}
.similar-sub{font-size:12px;color:var(--text-secondary);margin:0 0 14px}
.similar-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
.similar-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden;text-decoration:none;color:inherit;display:flex;flex-direction:column;transition:transform .18s ease,box-shadow .18s ease}
.similar-card:hover{transform:translateY(-3px);box-shadow:0 10px 22px rgba(0,0,0,.10)}
.similar-media{width:100%;height:130px;background:var(--bg-secondary);overflow:hidden}
.similar-media img{width:100%;height:100%;object-fit:cover;display:block}
.similar-noimg{width:100%;height:130px;display:flex;align-items:center;justify-content:center;font-size:30px;background:linear-gradient(135deg,rgba(212,175,55,.15),rgba(6,78,78,.06)),var(--gold-bg)}
.similar-body{padding:10px 12px 12px;display:flex;flex-direction:column;gap:5px;flex:1}
.similar-name{font-size:13px;font-weight:700;color:var(--text-primary);margin:0;line-height:1.6;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.similar-meta{font-size:11px;color:var(--text-secondary);margin:0}
.similar-badge{align-self:flex-start;font-size:10px;font-weight:700;background:var(--gold-bg);color:var(--gold-dark);padding:3px 8px;border-radius:999px}
</style>';

        $html .= '<section class="similar-section">';
        $html .= '<h2 class="similar-title">🏘️ ملک‌های مشابه</h2>';
        $html .= '<p class="similar-sub">بر اساس نوع ملک، محدوده، متراژ و قیمت این آگهی انتخاب شده‌اند.</p>';
        $html .= '<div class="similar-grid">';

        foreach ($items as $ad) {
            $id = (string)($ad['id'] ?? '');
            $title = trim((string)($ad['title'] ?? '')) ?: 'ملک بدون عنوان';
            $type = trim((string)($ad['property_type'] ?? ''));
            $deal = trim((string)($ad['transaction_type'] ?? ''));
            $location = trim((string)($ad['location'] ?? ''));
            $image = ($pdo instanceof PDO) ? melkinoSimilarFirstImage($pdo, $id) : '';

            $html .= '<a class="similar-card" href="property-details.php?id=' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">';

            if ($image !== '') {
                $html .= '<div class="similar-media"><img src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" loading="lazy"></div>';
            } else {
                $html .= '<div class="similar-noimg">🏠</div>';
            }

            $html .= '<div class="similar-body">';
            if ($type !== '') {
                $html .= '<span class="similar-badge">' . htmlspecialchars($type . ($deal !== '' ? ' · ' . $deal : ''), ENT_QUOTES, 'UTF-8') . '</span>';
            }
            $html .= '<h3 class="similar-name">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>';
            if ($location !== '') {
                $html .= '<p class="similar-meta">📍 ' . htmlspecialchars($location, ENT_QUOTES, 'UTF-8') . '</p>';
            }
            $html .= '</div></a>';
        }

        $html .= '</div></section>';

        return $html;
    }
}
