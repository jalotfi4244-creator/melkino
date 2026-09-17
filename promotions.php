<?php
/**
|--------------------------------------------------------------------------
| تبلیغاتِ بین کارت‌ها
|--------------------------------------------------------------------------
| ادمین می‌تواند بنرهایی تعریف کند که در فهرست آگهی‌ها (خانه،
| جستجو، املاک، ویژه) بعد از یک شماره‌ی کارت مشخص نمایش داده شوند.
|--------------------------------------------------------------------------
*/

if (!function_exists('melkinoEnsurePromotionsTable')) {
    function melkinoEnsurePromotionsTable(): void
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return;
        }

        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS promotions (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    title VARCHAR(255) NOT NULL DEFAULT '',
                    image_url VARCHAR(500) NOT NULL DEFAULT '',
                    link_url VARCHAR(500) NOT NULL DEFAULT '',
                    button_text VARCHAR(100) NOT NULL DEFAULT 'مشاهده',
                    description VARCHAR(1000) NOT NULL DEFAULT '',
                    placement VARCHAR(50) NOT NULL DEFAULT 'all',
                    position_after INT UNSIGNED NOT NULL DEFAULT 3,
                    repeat_every INT UNSIGNED NOT NULL DEFAULT 0,
                    start_date DATETIME NULL,
                    end_date DATETIME NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    views INT UNSIGNED NOT NULL DEFAULT 0,
                    clicks INT UNSIGNED NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_promotions_active (is_active),
                    KEY idx_promotions_placement (placement)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            // نبود دسترسی ایجاد جدول نباید صفحه را خراب کند
        }
    }
}

if (!function_exists('melkinoActivePromotions')) {
    function melkinoActivePromotions(string $placement): array
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return [];
        }

        melkinoEnsurePromotionsTable();

        try {
            $st = $pdo->prepare(
                "SELECT * FROM promotions
                  WHERE is_active = 1
                    AND (placement = 'all' OR placement = ?)
                    AND (start_date IS NULL OR start_date <= NOW())
                    AND (end_date IS NULL OR end_date >= NOW())
                  ORDER BY position_after ASC, id ASC"
            );
            $st->execute([$placement]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }

        // ثبت بازدید (یک‌بار در هر بار لود صفحه)
        if ($rows) {
            try {
                $ids = implode(',', array_map('intval', array_column($rows, 'id')));
                $pdo->exec("UPDATE promotions SET views = views + 1 WHERE id IN ($ids)");
            } catch (Throwable $e) {
            }
        }

        return $rows;
    }
}

if (!function_exists('melkinoPromotionAfter')) {
    /**
     * اگر تبلیغی باید بعد از کارتِ شماره‌ی $cardIndex (یک-پایه) نمایش
     * داده شود، HTML آن را برمی‌گرداند؛ در غیر این صورت رشته‌ی خالی.
     */
    function melkinoPromotionAfter(string $placement, int $cardIndex, array &$cache = null): string
    {
        static $loaded = [];

        if (!isset($loaded[$placement])) {
            $loaded[$placement] = melkinoActivePromotions($placement);
        }
        $promotions = $loaded[$placement];

        if (!$promotions) {
            return '';
        }

        foreach ($promotions as $promo) {
            $first = max(1, (int)($promo['position_after'] ?: 3));
            $repeat = (int)($promo['repeat_every'] ?: 0);

            $shouldShow = ($cardIndex === $first);
            if (!$shouldShow && $repeat > 0 && $cardIndex > $first) {
                $shouldShow = (($cardIndex - $first) % $repeat) === 0;
            }

            if ($shouldShow) {
                return melkinoRenderPromotion($promo);
            }
        }

        return '';
    }
}

if (!function_exists('melkinoRenderPromotion')) {
    function melkinoRenderPromotion(array $promo): string
    {
        $title = trim((string)($promo['title'] ?? ''));
        $desc = trim((string)($promo['description'] ?? ''));
        $image = trim((string)($promo['image_url'] ?? ''));
        $link = trim((string)($promo['link_url'] ?? ''));
        $button = trim((string)($promo['button_text'] ?? '')) ?: 'مشاهده';
        $id = (int)($promo['id'] ?? 0);

        if ($link === '') {
            $link = '#';
        }

        $escapedLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $clickUrl = 'promotion-click.php?id=' . $id;

        $html = '<article class="property-card promo-card" data-promo-id="' . $id . '">';
        $html .= '<div class="promo-badge">تبلیغ</div>';

        if ($image !== '') {
            $html .= '<div class="promo-media"><img src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" loading="lazy"></div>';
        }

        $html .= '<div class="promo-body">';
        if ($title !== '') {
            $html .= '<h3 class="promo-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>';
        }
        if ($desc !== '') {
            $html .= '<p class="promo-desc">' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        $html .= '<a class="promo-cta" href="' . $escapedLink . '"'
            . ($link !== '#' ? ' target="_blank" rel="noopener nofollow"' : '')
            . ' data-click-url="' . htmlspecialchars($clickUrl, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($button, ENT_QUOTES, 'UTF-8') . '</a>';
        $html .= '</div></article>';

        return $html;
    }
}

if (!function_exists('melkinoPromotionStyles')) {
    function melkinoPromotionStyles(): string
    {
        return <<<'CSS'
<style>
.promo-card{position:relative;display:flex;flex-direction:column;overflow:hidden;border:1px dashed var(--primary-light, #0F766E);border-radius:var(--radius-md, 16px);background:var(--surface);box-shadow:var(--shadow-card, 0 2px 8px rgba(0,0,0,.06))}
.promo-badge{position:absolute;top:10px;right:10px;z-index:2;background:var(--gold, #D4AF37);color:#111827;font-size:11px;font-weight:800;padding:4px 10px;border-radius:999px}
.promo-media{width:100%;aspect-ratio:16/9;overflow:hidden;background:var(--bg-secondary)}
.promo-media img{width:100%;height:100%;object-fit:cover;display:block}
.promo-body{padding:14px 14px 16px;display:flex;flex-direction:column;gap:8px;flex:1}
.promo-title{margin:0;font-size:15px;font-weight:800;color:var(--text-primary)}
.promo-desc{margin:0;font-size:13px;line-height:1.8;color:var(--text-secondary)}
.promo-cta{margin-top:auto;display:inline-block;text-align:center;background:var(--primary);color:#fff;border-radius:10px;padding:10px 14px;font-size:13px;font-weight:700;text-decoration:none}
.promo-cta:hover{opacity:.9}
</style>
CSS;
    }
}
