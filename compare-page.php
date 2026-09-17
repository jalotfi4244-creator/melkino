<?php
/*
|--------------------------------------------------------------------------
| صفحهٔ مستقل «مقایسهٔ ملک‌ها»
|--------------------------------------------------------------------------
| این صفحه جایگزین/تکمیل‌کنندهٔ بخش مقایسه در پروفایل است:
|   - ۳ دستهٔ قابل تغییرنام، هرکدام تا ۵ ملک
|   - حذف، جابه‌جایی بین دسته‌ها، خالی‌کردن دسته
|   - جدول امتیازدهی از ۱۰۰ (قیمت، متراژ، خواب، سال ساخت، امتیازات، کیفیت)
|
| همه‌چیز سمت سرور رندر می‌شود؛ بنابراین حتی اگر مرورگر/جاوااسکریپت
| درست کار نکند، کاربر همچنان می‌تواند مقایسه کند.
|
| نکته: مقایسه برای مهمان‌ها هم کار می‌کند (شناسهٔ مهمان در کوکی) و
| به‌محض ورود کاربر، به حساب او منتقل می‌شود.
|--------------------------------------------------------------------------
*/

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/compare-lib.php';

global $pdo;

$dbReady = $pdo instanceof PDO;
$tableError = '';
if ($dbReady) {
    try {
        melkinoEnsureCompareTables($pdo);
    } catch (Throwable $e) {
        $tableError = 'آماده‌سازی جدول‌های مقایسه ناموفق بود.';
    }
}

$identity = $dbReady ? melkinoCurrentIdentity() : ['user_id' => null, 'telegram_id' => ''];
if ($dbReady) {
    melkinoCompareMergeGuest($pdo, $identity);
}

[$ownerWhere, $ownerParams, $ownerUserId, $ownerTelegramId, $guestToken] = $dbReady
    ? melkinoCompareOwner($identity, 'ci')
    : ['', [], null, '', ''];
[$ownerWherePlain, $ownerParamsPlain] = $dbReady
    ? melkinoCompareOwner($identity)
    : ['', []];

$flash = trim((string)($_GET['msg'] ?? ''));
$flashType = trim((string)($_GET['mt'] ?? 'ok'));
$maxPerGroup = melkinoCompareMaxPerGroup();

/* =========================================================
   پردازش عملیات (POST + ریدایرکت تا ارسال دوباره رخ ندهد)
   ========================================================= */
if ($dbReady && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $redirectGroup = max(1, min(3, (int)($_POST['group'] ?? 1)));
    $message = '';
    $messageType = 'ok';

    try {
        if ($action === 'remove') {
            $adId = trim((string)($_POST['ad_id'] ?? ''));
            if ($adId !== '') {
                $stmt = $pdo->prepare("DELETE FROM compare_items WHERE $ownerWherePlain AND ad_id = ?");
                $p = $ownerParamsPlain;
                $p[] = $adId;
                $stmt->execute($p);
                $message = $stmt->rowCount() > 0 ? 'ملک از مقایسه حذف شد.' : 'این ملک در مقایسه نبود.';
            }
        } elseif ($action === 'move') {
            $adId = trim((string)($_POST['ad_id'] ?? ''));
            $target = (int)($_POST['target_group'] ?? 0);
            if ($adId !== '' && $target >= 1 && $target <= 3) {
                $stmt = $pdo->prepare("SELECT id, group_no FROM compare_items WHERE $ownerWherePlain AND ad_id = ? LIMIT 1");
                $p = $ownerParamsPlain;
                $p[] = $adId;
                $stmt->execute($p);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && (int)$row['group_no'] !== $target) {
                    if (melkinoCompareCountInGroup($pdo, $ownerWhere, $ownerParams, $target) >= $maxPerGroup) {
                        $message = 'دستهٔ مقصد پر است (حداکثر ' . $maxPerGroup . ' ملک).';
                        $messageType = 'warn';
                    } else {
                        $pdo->prepare('UPDATE compare_items SET group_no = ? WHERE id = ?')->execute([$target, (int)$row['id']]);
                        $message = 'ملک به «' . melkinoCompareGroupName($pdo, $ownerWherePlain, $ownerParamsPlain, $target) . '» منتقل شد.';
                        $redirectGroup = $target;
                    }
                }
            }
        } elseif ($action === 'rename') {
            $target = (int)($_POST['group'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($target >= 1 && $target <= 3) {
                $name = function_exists('mb_substr') ? mb_substr($name, 0, 40, 'UTF-8') : substr($name, 0, 40);
                $stmt = $pdo->prepare("SELECT id FROM compare_groups WHERE $ownerWherePlain AND group_no = ? LIMIT 1");
                $p = $ownerParamsPlain;
                $p[] = $target;
                $stmt->execute($p);
                $existing = $stmt->fetchColumn();
                if ($existing) {
                    $pdo->prepare('UPDATE compare_groups SET name = ? WHERE id = ?')->execute([$name, (int)$existing]);
                } else {
                    $pdo->prepare('INSERT INTO compare_groups (user_id, telegram_id, guest_token, group_no, name) VALUES (?, ?, ?, ?, ?)')
                        ->execute([
                            $ownerUserId,
                            $ownerTelegramId !== '' ? $ownerTelegramId : null,
                            $guestToken !== '' ? $guestToken : null,
                            $target,
                            $name,
                        ]);
                }
                $message = 'نام دسته ذخیره شد.';
                $redirectGroup = $target;
            }
        } elseif ($action === 'clear') {
            $target = (int)($_POST['group'] ?? 0);
            if ($target >= 1 && $target <= 3) {
                $stmt = $pdo->prepare("DELETE FROM compare_items WHERE $ownerWherePlain AND group_no = ?");
                $p = $ownerParamsPlain;
                $p[] = $target;
                $stmt->execute($p);
                $message = $stmt->rowCount() . ' ملک از این دسته حذف شد.';
                $redirectGroup = $target;
            }
        }
    } catch (Throwable $e) {
        $message = 'خطا در انجام عملیات: ' . $e->getMessage();
        $messageType = 'err';
    }

    $qs = 'group=' . $redirectGroup;
    if ($message !== '') {
        $qs .= '&msg=' . rawurlencode($message) . '&mt=' . rawurlencode($messageType);
    }
    if (!headers_sent()) {
        header('Location: compare-page.php?' . $qs);
        exit;
    }
}

/* =========================================================
   دادهٔ نمایش
   ========================================================= */
$groups = [];
$total = 0;
if ($dbReady && $ownerWhere !== '') {
    try {
        $groups = melkinoCompareGroups($pdo, $ownerWhere, $ownerParams, $ownerWherePlain, $ownerParamsPlain);
        foreach ($groups as $g) {
            $total += (int)$g['count'];
        }
    } catch (Throwable $e) {
        $tableError = 'خواندن دادهٔ مقایسه ناموفق بود.';
    }
}

$activeGroupNo = max(1, min(3, (int)($_GET['group'] ?? 1)));
$activeGroup = null;
foreach ($groups as $g) {
    if ((int)$g['no'] === $activeGroupNo) {
        $activeGroup = $g;
        break;
    }
}
if ($activeGroup === null) {
    $activeGroup = ['no' => $activeGroupNo, 'name' => 'گروه ' . $activeGroupNo, 'count' => 0, 'items' => []];
}

$scoreData = null;
$scoreError = '';
if ($dbReady && $ownerWhere !== '' && (int)$activeGroup['count'] >= 2) {
    try {
        $scoreData = melkinoCompareScoreData($pdo, $ownerWhere, $ownerParams, $activeGroupNo);
        if (count($scoreData['ads']) < 2) {
            $scoreData = null;
            $scoreError = 'برای امتیازدهی حداقل ۲ ملک منتشرشده در این دسته لازم است.';
        }
    } catch (Throwable $e) {
        $scoreError = 'امتیازدهی ممکن نشد.';
    }
} elseif ((int)$activeGroup['count'] === 1) {
    $scoreError = 'برای مقایسه و امتیازدهی، حداقل یک ملک دیگر هم به این دسته اضافه کن.';
}

/** قیمت هر ملک، به‌صورت خوانا */
$priceText = function (array $ad): string {
    $num = function ($v): string {
        $raw = str_replace(',', '', trim((string)$v));
        if ($raw === '' || !is_numeric($raw) || (float)$raw == 0.0) {
            return '';
        }
        return number_format((float)$raw);
    };
    $tx = trim((string)($ad['transaction_type'] ?? ''));
    $isRent = mb_strpos($tx, 'اجاره') !== false || mb_strpos($tx, 'رهن') !== false;
    if ($isRent) {
        $dep = $num($ad['deposit'] ?? '');
        $rent = $num($ad['rent_monthly'] ?? '');
        if ($dep === '' && $rent === '') {
            return 'تماس بگیرید';
        }
        $parts = [];
        if ($dep !== '') {
            $parts[] = 'ودیعه ' . $dep;
        }
        if ($rent !== '') {
            $parts[] = 'اجاره ' . $rent;
        }
        return implode(' + ', $parts) . ' تومان';
    }
    $sell = $num($ad['price_sell'] ?? '') ?: $num($ad['total_price'] ?? '') ?: $num($ad['deposit'] ?? '');
    return $sell !== '' ? $sell . ' تومان' : 'تماس بگیرید';
};

/** آدرس تصویر ملک */
$imageUrl = function (array $ad): string {
    $name = trim((string)($ad['image'] ?? ''));
    if ($name === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $name)) {
        return $name;
    }
    return ltrim($name, '/');
};

/** برش امن متن (بدون وابستگی قطعی به mbstring) */
$cut = function ($text, int $len): string {
    $text = (string)$text;
    return function_exists('mb_substr') ? mb_substr($text, 0, $len, 'UTF-8') : substr($text, 0, $len);
};

$numFa = function ($v): string {
    $raw = str_replace(',', '', trim((string)$v));
    if ($raw === '' || !is_numeric($raw) || (float)$raw == 0.0) {
        return '—';
    }
    return number_format((float)$raw);
};

$criteria = [
    'price'     => '💰 قیمت (۳۵)',
    'area'      => '📐 متراژ (۱۵)',
    'rooms'     => '🛏️ خواب (۱۰)',
    'year'      => '📅 سال ساخت (۱۰)',
    'amenities' => '✨ امکانات (۱۵)',
    'quality'   => '🖼 کیفیت آگهی (۱۵)',
];

require_once __DIR__ . '/header.php';
?>
<style>
.compare-page { max-width: 1100px; margin: 0 auto; padding: 14px 14px 110px; }
.cmp-hero { padding: 20px; border-radius: 22px; margin-bottom: 14px; background: radial-gradient(circle at 85% 15%, rgba(212,175,55,.15), transparent 30%), linear-gradient(135deg,#073737,#052727 70%,#031c1c); border: 1px solid rgba(212,175,55,.12); box-shadow: var(--shadow-card); }
.cmp-hero h1 { margin: 0; color: #fff; font-size: clamp(21px, 5.4vw, 30px); font-weight: 900; }
.cmp-hero h1 span { color: #f0d36a; }
.cmp-hero p { margin: 8px 0 0; color: rgba(255,255,255,.6); font-size: 11px; line-height: 1.9; }
.cmp-flash { margin: 0 0 12px; padding: 11px 14px; border-radius: 12px; font-size: 12px; font-weight: 700; line-height: 1.8; }
.cmp-flash.ok { background: rgba(16,185,129,.12); color: #0f9d76; border: 1px solid rgba(16,185,129,.25); }
.cmp-flash.warn { background: rgba(245,158,11,.12); color: #b45309; border: 1px solid rgba(245,158,11,.25); }
.cmp-flash.err { background: rgba(220,38,38,.10); color: #dc2626; border: 1px solid rgba(220,38,38,.22); }
.cmp-tabs { display: flex; gap: 8px; overflow-x: auto; padding: 4px 2px 10px; }
.cmp-tab { flex: 0 0 auto; display: flex; align-items: center; gap: 7px; padding: 10px 14px; border-radius: 14px; background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary); font-family: inherit; font-size: 12px; font-weight: 800; text-decoration: none; white-space: nowrap; }
.cmp-tab.active { background: linear-gradient(135deg, var(--primary), #0b5d5b); color: #fff; border-color: transparent; }
.cmp-tab .badge { padding: 2px 7px; border-radius: 999px; background: rgba(0,0,0,.12); font-size: 10px; }
.cmp-tab.active .badge { background: rgba(255,255,255,.22); }
.cmp-toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
.cmp-toolbar form { display: flex; gap: 6px; align-items: center; margin: 0; flex-wrap: wrap; }
.cmp-input { flex: 1 1 150px; min-width: 0; height: 42px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--border); background: var(--bg); color: var(--text-primary); font-family: inherit; font-size: 12px; }
.cmp-btn { height: 42px; padding: 0 15px; border-radius: 12px; border: 1px solid var(--border); background: var(--surface); color: var(--text-primary); font-family: inherit; font-size: 12px; font-weight: 800; cursor: pointer; white-space: nowrap; }
.cmp-btn.primary { background: var(--primary); color: #fff; border-color: transparent; }
.cmp-btn.danger { background: rgba(220,38,38,.08); color: #dc2626; border-color: rgba(220,38,38,.2); }
.cmp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; margin-bottom: 16px; }
.cmp-card { background: var(--surface); border: 1px solid var(--border); border-radius: 18px; overflow: hidden; box-shadow: var(--shadow-card); display: flex; flex-direction: column; }
.cmp-card-img { height: 150px; background: linear-gradient(145deg, rgba(212,175,55,.12), rgba(255,255,255,.02)); display: flex; align-items: center; justify-content: center; color: var(--text-secondary); font-size: 30px; overflow: hidden; }
.cmp-card-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
.cmp-card-body { padding: 12px; display: flex; flex-direction: column; gap: 7px; flex: 1; }
.cmp-card-title { font-size: 13px; font-weight: 800; color: var(--text-primary); line-height: 1.7; }
.cmp-card-title a { color: inherit; text-decoration: none; }
.cmp-card-sub { font-size: 10px; color: var(--text-secondary); }
.cmp-card-price { font-size: 13px; font-weight: 900; color: var(--gold,#d4af37); }
.cmp-chips { display: flex; flex-wrap: wrap; gap: 5px; }
.cmp-chip { padding: 3px 7px; border-radius: 8px; background: var(--bg); border: 1px solid var(--border); color: var(--text-secondary); font-size: 9px; }
.cmp-card-actions { display: flex; gap: 6px; margin-top: auto; flex-wrap: wrap; }
.cmp-card-actions select { flex: 1 1 96px; height: 38px; border-radius: 10px; border: 1px solid var(--border); background: var(--bg); color: var(--text-secondary); font-family: inherit; font-size: 10px; padding: 0 6px; }
.cmp-card-actions button { height: 38px; padding: 0 12px; border-radius: 10px; border: none; background: rgba(220,38,38,.09); color: #dc2626; font-family: inherit; font-size: 10px; font-weight: 800; cursor: pointer; }
.cmp-empty { text-align: center; padding: 48px 18px; background: var(--surface); border: 1px dashed var(--border); border-radius: 20px; color: var(--text-secondary); font-size: 12px; line-height: 2; }
.cmp-empty .big { font-size: 34px; margin-bottom: 8px; }
.cmp-section-title { margin: 18px 2px 10px; font-size: 14px; font-weight: 900; color: var(--text-primary); }
.cmp-score-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 18px; background: var(--surface); }
.cmp-table { width: 100%; border-collapse: collapse; min-width: 560px; }
.cmp-table th, .cmp-table td { padding: 10px 9px; border-bottom: 1px solid var(--border); font-size: 11px; text-align: center; white-space: nowrap; }
.cmp-table thead th { background: var(--bg); color: var(--text-secondary); font-weight: 800; }
.cmp-table tbody th { text-align: right; color: var(--text-secondary); font-weight: 700; background: var(--bg); }
.cmp-table tbody tr:last-child th, .cmp-table tbody tr:last-child td { border-bottom: none; }
.cmp-table tr.winner td, .cmp-table tr.winner th { background: rgba(212,175,55,.10); }
.cmp-table td.total { font-weight: 900; color: var(--gold,#d4af37); font-size: 13px; }
.cmp-highlights { margin-top: 12px; padding: 13px 15px; border-radius: 16px; background: var(--surface); border: 1px solid var(--border); font-size: 11px; line-height: 2.1; color: var(--text-secondary); }
.cmp-highlights b { color: var(--text-primary); }
.cmp-warn { margin-top: 10px; padding: 11px 14px; border-radius: 14px; background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.25); color: #b45309; font-size: 11px; line-height: 1.9; }
.cmp-note { margin-top: 10px; font-size: 10px; color: var(--text-secondary); line-height: 1.9; }
@media (max-width: 520px) {
  .cmp-grid { grid-template-columns: 1fr; }
  .cmp-hero { padding: 16px; }
}
</style>

<div class="main-content">
  <div class="compare-page">

    <section class="cmp-hero">
      <h1>⚖️ مقایسهٔ <span>ملک‌ها</span></h1>
      <p>
        تا <?= (int)$maxPerGroup ?> ملک در هر دسته؛ خودت انتخاب کن کدام ملک‌ها با هم مقایسه شوند.
        <?= $total > 0 ? 'در حال حاضر ' . (int)$total . ' ملک در مقایسه داری.' : 'از روی کارت هر ملک دکمهٔ ⚖️ مقایسه را بزن.' ?>
      </p>
    </section>

    <?php if ($flash !== ''): ?>
      <div class="cmp-flash <?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') === 'ok' ? 'ok' : (htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') === 'warn' ? 'warn' : 'err') ?>">
        <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if ($tableError !== ''): ?>
      <div class="cmp-flash err"><?= htmlspecialchars($tableError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="cmp-tabs">
      <?php foreach ($groups as $g): ?>
        <a class="cmp-tab<?= (int)$g['no'] === $activeGroupNo ? ' active' : '' ?>"
           href="compare-page.php?group=<?= (int)$g['no'] ?>">
          <span><?= htmlspecialchars((string)$g['name'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="badge"><?= (int)$g['count'] ?>/<?= (int)$maxPerGroup ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="cmp-toolbar">
      <form method="post" action="compare-page.php">
        <input type="hidden" name="action" value="rename">
        <input type="hidden" name="group" value="<?= (int)$activeGroupNo ?>">
        <input class="cmp-input" type="text" name="name" maxlength="40"
               value="<?= htmlspecialchars((string)$activeGroup['name'], ENT_QUOTES, 'UTF-8') ?>"
               placeholder="نام این دسته (مثلاً «آپارتمان‌های ۱۰۰ متری»)">
        <button class="cmp-btn" type="submit">✏️ ذخیرهٔ نام</button>
      </form>
      <?php if ((int)$activeGroup['count'] > 0): ?>
        <form method="post" action="compare-page.php" onsubmit="return confirm('همهٔ ملک‌های این دسته حذف شود؟');">
          <input type="hidden" name="action" value="clear">
          <input type="hidden" name="group" value="<?= (int)$activeGroupNo ?>">
          <button class="cmp-btn danger" type="submit">🗑 خالی کردن دسته</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if ((int)$activeGroup['count'] === 0): ?>
      <div class="cmp-empty">
        <div class="big">⚖️</div>
        <div>هنوز ملکی در «<?= htmlspecialchars((string)$activeGroup['name'], ENT_QUOTES, 'UTF-8') ?>» نیست.</div>
        <div>از صفحهٔ <a href="properties.php" style="color:var(--primary);font-weight:800;">ملک‌ها</a> یا <a href="favorites.php" style="color:var(--primary);font-weight:800;">علاقه‌مندی‌ها</a> دکمهٔ «⚖️ مقایسه» را بزن.</div>
      </div>
    <?php else: ?>
      <div class="cmp-grid">
        <?php foreach ($activeGroup['items'] as $item): ?>
          <?php $img = $imageUrl($item); ?>
          <article class="cmp-card">
            <div class="cmp-card-img">
              <?php if ($img !== ''): ?>
                <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($item['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
              <?php else: ?>
                🏠
              <?php endif; ?>
            </div>
            <div class="cmp-card-body">
              <div class="cmp-card-title">
                <a href="property-details.php?id=<?= urlencode((string)$item['id']) ?>">
                  <?= htmlspecialchars(trim((string)($item['title'] ?? '')) !== '' ? (string)$item['title'] : 'ملک بدون عنوان', ENT_QUOTES, 'UTF-8') ?>
                </a>
              </div>
              <div class="cmp-card-sub">کد: <?= htmlspecialchars((string)$item['id'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="cmp-chips">
                <?php if (!empty($item['transaction_type'])): ?><span class="cmp-chip"><?= htmlspecialchars((string)$item['transaction_type'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                <?php if (!empty($item['property_type'])): ?><span class="cmp-chip"><?= htmlspecialchars((string)$item['property_type'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                <?php if (!empty($item['location'])): ?><span class="cmp-chip">📍 <?= htmlspecialchars((string)$item['location'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                <?php if (!empty($item['area'])): ?><span class="cmp-chip"><?= $numFa($item['area']) ?> متر</span><?php endif; ?>
                <?php if (!empty($item['rooms'])): ?><span class="cmp-chip"><?= $numFa($item['rooms']) ?> خواب</span><?php endif; ?>
              </div>
              <div class="cmp-card-price"><?= htmlspecialchars($priceText($item), ENT_QUOTES, 'UTF-8') ?></div>
              <div class="cmp-card-actions">
                <form method="post" action="compare-page.php" style="display:flex;gap:6px;flex:1 1 100%;">
                  <input type="hidden" name="action" value="move">
                  <input type="hidden" name="ad_id" value="<?= htmlspecialchars((string)$item['id'], ENT_QUOTES, 'UTF-8') ?>">
                  <select name="target_group" onchange="this.form.submit()" aria-label="انتقال به دستهٔ دیگر">
                    <option value="">انتقال به…</option>
                    <?php foreach ($groups as $g): ?>
                      <?php if ((int)$g['no'] !== $activeGroupNo): ?>
                        <option value="<?= (int)$g['no'] ?>"><?= htmlspecialchars((string)$g['name'], ENT_QUOTES, 'UTF-8') ?></option>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </select>
                </form>
                <form method="post" action="compare-page.php" style="display:flex;flex:1 1 100%;">
                  <input type="hidden" name="action" value="remove">
                  <input type="hidden" name="ad_id" value="<?= htmlspecialchars((string)$item['id'], ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="group" value="<?= (int)$activeGroupNo ?>">
                  <button type="submit" style="flex:1;">✖ حذف از مقایسه</button>
                </form>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($scoreData !== null): ?>
      <?php
      $winnerId = (string)($scoreData['winner'] ?? '');
      $adById = [];
      foreach ($scoreData['ads'] as $ad) {
          $adById[(string)$ad['id']] = $ad;
      }
      ?>
      <div class="cmp-section-title">📊 جدول امتیازدهی (از ۱۰۰)</div>
      <div class="cmp-score-wrap">
        <table class="cmp-table">
          <thead>
            <tr>
              <th>معیار</th>
              <?php foreach ($scoreData['ads'] as $ad): ?>
                <th>
                  <?= htmlspecialchars(trim((string)($ad['title'] ?? '')) !== '' ? $cut($ad['title'], 22) : 'ملک ' . $ad['id'], ENT_QUOTES, 'UTF-8') ?>
                  <?php if ((string)$ad['id'] === $winnerId): ?> 🏆<?php endif; ?>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($criteria as $key => $label): ?>
              <tr>
                <th><?= $label ?></th>
                <?php foreach ($scoreData['ads'] as $ad): ?>
                  <td><?= htmlspecialchars((string)($scoreData['scores'][(string)$ad['id']]['breakdown'][$key] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
            <tr>
              <th>💵 قیمت هر متر</th>
              <?php foreach ($scoreData['ads'] as $ad): ?>
                <?php $ppm = $scoreData['scores'][(string)$ad['id']]['price_per_m'] ?? null; ?>
                <td><?= $ppm !== null ? $numFa($ppm) : '—' ?></td>
              <?php endforeach; ?>
            </tr>
            <tr class="winner">
              <th>🏆 امتیاز کل</th>
              <?php foreach ($scoreData['ads'] as $ad): ?>
                <td class="total"><?= htmlspecialchars((string)($scoreData['scores'][(string)$ad['id']]['total'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
              <?php endforeach; ?>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="cmp-highlights">
        <b>💰 قیمت:</b>
        <?php foreach ($scoreData['ads'] as $ad): ?>
          <?= htmlspecialchars(trim((string)($ad['title'] ?? '')) !== '' ? $cut($ad['title'], 18) : (string)$ad['id'], ENT_QUOTES, 'UTF-8') ?>
          → <?= htmlspecialchars($priceText($ad), ENT_QUOTES, 'UTF-8') ?><br>
        <?php endforeach; ?>
        <?php if (!empty($scoreData['highlights'])): ?>
          <div style="margin-top:8px;">
            <?php foreach ($scoreData['highlights'] as $h): ?>
              ✅ <?= htmlspecialchars((string)$h, ENT_QUOTES, 'UTF-8') ?><br>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if (!empty($scoreData['mixed_types'])): ?>
        <div class="cmp-warn">
          ⚠️ در این دسته ملک‌هایی با نوع معاملهٔ متفاوت (فروش/اجاره) وجود دارد؛ امتیاز قیمت برای همه بر پایهٔ «مبلغ مرجع» محاسبه شده
          (فروش: قیمت کل، اجاره: ودیعه + ۱۲ ماه اجاره)، پس مقایسه را با احتیاط ببین.
        </div>
      <?php endif; ?>

      <div class="cmp-note">
        امتیازها نسبی و درون همین دسته محاسبه می‌شوند (ارزان‌ترین قیمت هر متر، بزرگ‌ترین متراژ و... نمرهٔ کامل می‌گیرند).
      </div>
    <?php elseif ($scoreError !== ''): ?>
      <div class="cmp-flash warn" style="margin-top:14px;"><?= htmlspecialchars($scoreError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
