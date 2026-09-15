<?php
// ==========================================================
// property-request-matches.php
// نمایش فایل‌های مطابق درخواست
// با فیلتر سخت‌گیرانه مبلغ + نمایش ترکیب‌های سرمایه‌گذاری
// ==========================================================

require_once __DIR__ . '/db_helpers.php';

global $pdo;

$trackingCode = trim((string)($_GET['code'] ?? ''));

$selected = null;
$matches = [];
$combinations = [];

// ==========================================================
// توابع کمکی
// ==========================================================

function reqMatchDigitsToEnglish($value)
{
    $value = (string)$value;

    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];

    return str_replace(
        array_merge($fa, $ar),
        array_merge(range(0, 9), range(0, 9)),
        $value
    );
}

function reqMatchNumber($value)
{
    if ($value === null) {
        return null;
    }

    $value = reqMatchDigitsToEnglish($value);

    $value = str_replace(
        [',', '٬', ' تومان', 'تومان', ' '],
        '',
        $value
    );

    $value = preg_replace(
        '/[^0-9.]/',
        '',
        $value
    );

    if ($value === '') {
        return null;
    }

    return (float)$value;
}

function reqMatchNormalizeTransaction($value)
{
    $value = trim((string)$value);

    $value = str_replace(
        ['ي', 'ى', 'ئ'],
        'ی',
        $value
    );

    $value = str_replace(
        ['ك'],
        'ک',
        $value
    );

    $value = preg_replace(
        '/\s+/u',
        ' ',
        $value
    );

    if (
        in_array(
            $value,
            ['اجاره', 'رهن و اجاره', 'رهن واجاره'],
            true
        )
    ) {
        return 'اجاره';
    }

    if (
        in_array(
            $value,
            ['رهن کامل', 'رهنکامل'],
            true
        )
    ) {
        return 'رهن کامل';
    }

    if (
        in_array(
            $value,
            ['پیش فروش', 'پیشفروش'],
            true
        )
    ) {
        return 'پیش فروش';
    }

    if ($value === 'فروش') {
        return 'فروش';
    }

    return $value;
}

/**
 * بررسی اینکه یک مبلغ داخل بازه انتخابی کاربر باشد.
 *
 * اگر min خالی باشد فقط max بررسی می‌شود.
 * اگر max خالی باشد فقط min بررسی می‌شود.
 * اگر هر دو خالی باشند true است.
 */
function reqMatchAmountInRange($actual, $min, $max)
{
    $actual = reqMatchNumber($actual);
    $min    = reqMatchNumber($min);
    $max    = reqMatchNumber($max);

    // اگر قیمت آگهی قابل تشخیص نیست، نمی‌توانیم
    // با اطمینان آن را مناسب بدانیم.
    if ($actual === null) {
        return false;
    }

    // هیچ محدودیتی تعیین نشده
    if ($min === null && $max === null) {
        return true;
    }

    // اگر کاربر اشتباهی حداقل را بیشتر از حداکثر زده
    if (
        $min !== null &&
        $max !== null &&
        $min > $max
    ) {
        [$min, $max] = [$max, $min];
    }

    if (
        $min !== null &&
        $actual < $min
    ) {
        return false;
    }

    if (
        $max !== null &&
        $actual > $max
    ) {
        return false;
    }

    return true;
}

/**
 * کنترل سختگیرانه مبلغ آگهی نسبت به درخواست.
 *
 * فروش / پیش فروش:
 *   price_sell یا total_price
 *
 * اجاره:
 *   deposit و rent_monthly
 *
 * رهن کامل:
 *   full_rent یا deposit
 */
function reqMatchPriceAllowed($request, $ad)
{
    $transaction = reqMatchNormalizeTransaction(
        $request['transaction_type'] ?? ''
    );

    // ======================================================
    // فروش / پیش فروش
    // ======================================================

    if (
        $transaction === 'فروش' ||
        $transaction === 'پیش فروش'
    ) {
        $actualPrice =
            $ad['price_sell']
            ?? $ad['total_price']
            ?? null;

        return reqMatchAmountInRange(
            $actualPrice,
            $request['min_price'] ?? null,
            $request['max_price'] ?? null
        );
    }

    // ======================================================
    // رهن کامل
    // ======================================================

    if ($transaction === 'رهن کامل') {

        $actualDeposit =
            $ad['full_rent']
            ?? $ad['deposit']
            ?? null;

        $minDeposit =
            $request['min_deposit'] !== ''
                ? $request['min_deposit']
                : ($request['min_price'] ?? null);

        $maxDeposit =
            $request['max_deposit'] !== ''
                ? $request['max_deposit']
                : ($request['max_price'] ?? null);

        return reqMatchAmountInRange(
            $actualDeposit,
            $minDeposit,
            $maxDeposit
        );
    }

    // ======================================================
    // اجاره
    // ======================================================

    if ($transaction === 'اجاره') {

        /*
         * اگر کاربر گزینه «رهن کامل» را زده باشد،
         * مبلغ رهن کنترل می‌شود و اجاره ماهانه نباید
         * به عنوان شرط جداگانه اجباری شود.
         */
        $rahnKamal =
            reqMatchDigitsToEnglish(
                $request['rahn_kamal'] ?? ''
            );

        $isFullRahne =
            in_array(
                $rahnKamal,
                ['1', 'بله', 'true'],
                true
            );

        if ($isFullRahne) {

            $actualDeposit =
                $ad['full_rent']
                ?? $ad['deposit']
                ?? null;

            $minDeposit =
                $request['min_deposit'] ?? null;

            $maxDeposit =
                $request['max_deposit'] ?? null;

            // اگر برای ودیعه بازه‌ای ثبت شده
            if (
                reqMatchNumber($minDeposit) !== null ||
                reqMatchNumber($maxDeposit) !== null
            ) {
                return reqMatchAmountInRange(
                    $actualDeposit,
                    $minDeposit,
                    $maxDeposit
                );
            }

            // اگر کاربر از min_price/max_price استفاده کرده
            return reqMatchAmountInRange(
                $actualDeposit,
                $request['min_price'] ?? null,
                $request['max_price'] ?? null
            );
        }

        // --------------------------------------------------
        // اجاره عادی
        // --------------------------------------------------

        $hasDepositRange =
            reqMatchNumber(
                $request['min_deposit'] ?? null
            ) !== null
            ||
            reqMatchNumber(
                $request['max_deposit'] ?? null
            ) !== null;

        $hasRentRange =
            reqMatchNumber(
                $request['min_rent'] ?? null
            ) !== null
            ||
            reqMatchNumber(
                $request['max_rent'] ?? null
            ) !== null;

        // اگر کاربر ودیعه تعیین کرده،
        // آگهی باید دقیقاً در محدوده ودیعه باشد.
        if ($hasDepositRange) {

            $depositAllowed =
                reqMatchAmountInRange(
                    $ad['deposit']
                        ?? $ad['full_rent']
                        ?? null,
                    $request['min_deposit'] ?? null,
                    $request['max_deposit'] ?? null
                );

            if (!$depositAllowed) {
                return false;
            }
        }

        // اگر کاربر اجاره ماهانه تعیین کرده،
        // آگهی باید دقیقاً در محدوده اجاره باشد.
        if ($hasRentRange) {

            $rentAllowed =
                reqMatchAmountInRange(
                    $ad['rent_monthly'] ?? null,
                    $request['min_rent'] ?? null,
                    $request['max_rent'] ?? null
                );

            if (!$rentAllowed) {
                return false;
            }
        }

        // اگر هیچ محدودیت مالی ثبت نشده باشد
        return true;
    }

    // ======================================================
    // نوع معامله ناشناخته
    // ======================================================

    return true;
}

// ==========================================================
// دریافت درخواست
// ==========================================================

if ($pdo instanceof PDO) {

    $identity = melkinoCurrentIdentity(
        $_GET['telegram_id'] ?? null
    );

    // ------------------------------------------------------
    // حالت اول: دریافت با کد رهگیری
    // ------------------------------------------------------

    if ($trackingCode !== '') {

        $stmt = $pdo->prepare(
            'SELECT *
             FROM property_requests
             WHERE tracking_code = ?
             LIMIT 1'
        );

        $stmt->execute([
            $trackingCode
        ]);

        $selected =
            $stmt->fetch(PDO::FETCH_ASSOC)
            ?: null;
    }

    // ------------------------------------------------------
    // حالت دوم: دریافت آخرین درخواست کاربر
    // ------------------------------------------------------

    elseif (
        $identity['user_id']
        ||
        $identity['telegram_id'] !== ''
    ) {

        $conds = [];
        $params = [];

        if ($identity['user_id']) {

            $conds[] = 'user_id = ?';

            $params[] =
                $identity['user_id'];
        }

        if (
            $identity['telegram_id'] !== ''
        ) {

            $conds[] = 'telegram_id = ?';

            $params[] =
                $identity['telegram_id'];
        }

        if ($conds) {

            $stmt = $pdo->prepare(
                'SELECT *
                 FROM property_requests
                 WHERE ' .
                 implode(' OR ', $conds) .
                 '
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1'
            );

            $stmt->execute(
                $params
            );

            $selected =
                $stmt->fetch(PDO::FETCH_ASSOC)
                ?: null;
        }
    }

    // ======================================================
    // دریافت مچ‌ها
    // ======================================================

    if ($selected) {

        $stmt = $pdo->prepare(
            "SELECT
                rm.*,
                a.title,
                a.property_type,
                a.transaction_type,
                a.location,
                a.deposit,
                a.rent_monthly,
                a.full_rent,
                a.price_sell,
                a.total_price,
                a.price_condition,
                a.price_hidden,
                a.property_details
             FROM request_matches rm
             INNER JOIN ads a
                ON a.id = rm.ad_id
               AND a.status = 'published'
             WHERE rm.request_id = ?
             ORDER BY
                rm.match_percent DESC,
                rm.created_at DESC"
        );

        $stmt->execute([
            (int)$selected['id']
        ]);

        $allMatches =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        // ==================================================
        // فیلتر سخت‌گیرانه مبلغ
        // ==================================================

        $matches = [];

        // تشخیص سرمایه‌گذاری
        $isInvestment = (reqMatchNormalizeTransaction($selected['transaction_type'] ?? '') === 'سرمایه‌گذاری');

        foreach ($allMatches as $match) {

            // ------------------------------------------------
            // اگر سرمایه‌گذاری است، فیلتر قیمت را نادیده بگیر
            // ------------------------------------------------
            if ($isInvestment) {
                $matches[] = $match;
                continue;
            }

            // ------------------------------------------------
            // برای سایر نوع معاملات، فیلتر قیمت اعمال شود
            // ------------------------------------------------
            if (!reqMatchPriceAllowed($selected, $match)) {
                continue;
            }

            $matches[] = $match;
        }

        // ==================================================
        // مرتب‌سازی نهایی
        // ==================================================

        usort(
            $matches,
            static function ($a, $b) {

                $percentCompare =
                    (
                        (int)(
                            $b['match_percent']
                            ?? 0
                        )
                    )
                    <=>
                    (
                        (int)(
                            $a['match_percent']
                            ?? 0
                        )
                    );

                if (
                    $percentCompare !== 0
                ) {
                    return $percentCompare;
                }

                return
                    (
                        strtotime(
                            $b['created_at']
                            ?? '1970-01-01'
                        )
                    )
                    <=>
                    (
                        strtotime(
                            $a['created_at']
                            ?? '1970-01-01'
                        )
                    );
            }
        );

        // ==================================================
        // استخراج ترکیب‌های سرمایه‌گذاری
        // ==================================================

        $additional = [];
        if (!empty($selected['additional'])) {
            $additional = json_decode($selected['additional'], true);
            if (!is_array($additional)) {
                $additional = [];
            }
        }

        if (!empty($additional['combinations'])) {
            $combinations = $additional['combinations'];
        }
    }
}

// ==========================================================
// Header
// ==========================================================

require_once __DIR__ . '/header.php';
?>

<style>

.request-matches-page{
    padding:18px 14px 115px;
    max-width:760px;
    margin:0 auto;
    background:var(--bg);
    min-height:100vh;
    overflow-y:auto;
    display:flex;
    flex-direction:column;
}

.request-matches-title{
    font-size:21px;
    font-weight:900;
    color:var(--text-primary);
}

.request-matches-sub{
    font-size:12px;
    line-height:1.8;
    color:var(--text-secondary);
    margin-top:4px;
}

.request-code{
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:13px;
    padding:12px;
    margin:16px 0;
}

.request-code small{
    display:block;
    color:var(--text-secondary);
    font-size:11px;
    margin-bottom:4px;
}

.request-code strong{
    color:var(--primary);
    font-size:18px;
    direction:ltr;
    display:block;
}

.request-summary{
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:15px;
    padding:14px;
    margin-bottom:15px;
    line-height:2;
    font-size:13px;
    color:var(--text-secondary);
}

/* استایل ترکیب‌ها */
.combo-section{
    margin-bottom:15px;
}
.combo-title{
    font-size:16px;
    font-weight:800;
    color:var(--primary);
    margin-bottom:10px;
}
.combo-grid{
    display:flex;
    flex-direction:column;
    gap:10px;
}
.combo-card{
    border:2px solid var(--primary);
    background:var(--surface);
    border-radius:14px;
    padding:12px;
    display:flex;
    flex-direction:column;
    gap:8px;
}
.combo-price{
    font-weight:800;
    color:var(--primary);
    font-size:14px;
}
.combo-items{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
}
.combo-item{
    background:var(--bg);
    border:1px solid var(--border);
    padding:4px 10px;
    border-radius:20px;
    font-size:11px;
    text-decoration:none;
    color:var(--text-primary);
}
.combo-item:hover{
    background:var(--gold-bg);
    border-color:var(--gold);
}

.match-card{
    display:block;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:16px;
    padding:15px;
    text-decoration:none;
    color:inherit;
    margin-bottom:10px;
    box-shadow:var(--shadow-card);
    transition:.2s ease;
}

.match-card:hover{
    transform:translateY(-1px);
}

.match-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:12px;
}

.match-title{
    font-size:15px;
    font-weight:900;
    color:var(--text-primary);
    line-height:1.7;
}

.match-percent{
    font-size:18px;
    font-weight:900;
    color:var(--primary);
    white-space:nowrap;
}

.match-meta{
    font-size:12px;
    color:var(--text-secondary);
    margin-top:6px;
    line-height:1.8;
}

.match-action{
    font-size:11px;
    color:var(--primary);
    font-weight:800;
    margin-top:9px;
}

.empty{
    text-align:center;
    background:var(--surface);
    border:1px dashed var(--border);
    border-radius:15px;
    padding:45px 18px;
    color:var(--text-secondary);
    line-height:2;
}

.back-btn{
    display:block;
    text-align:center;
    background:var(--primary);
    color:#fff;
    padding:13px;
    border-radius:12px;
    text-decoration:none;
    font-weight:800;
    margin-top:16px;
}

.amount-rule{
    margin-top:8px;
    font-size:11px;
    color:var(--text-secondary);
}

@media(max-width:480px){

    .request-matches-page{
        padding-left:10px;
        padding-right:10px;
    }

    .match-head{
        gap:8px;
    }

    .match-title{
        font-size:14px;
    }

    .match-percent{
        font-size:16px;
    }
}

</style>

<main class="request-matches-page">

    <div class="request-matches-title">
        🔎 فایل‌های مطابق درخواست
    </div>

    <div class="request-matches-sub">
        فایل‌ها براساس میزان تطبیق با درخواست شما مرتب شده‌اند.
        فایل‌هایی که مبلغشان خارج از بازه مالی انتخابی شما باشد نمایش داده نمی‌شوند.
    </div>

    <?php if ($selected): ?>

        <div class="request-code">

            <small>
                کد رهگیری درخواست
            </small>

            <strong>
                <?= htmlspecialchars(
                    (string)$selected['tracking_code'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </strong>

        </div>

        <div class="request-summary">

            <?= htmlspecialchars(
                (
                    ($selected['transaction_type'] ?? '')
                    . ' · ' .
                    ($selected['property_type'] ?? '')
                    . ' · ' .
                    ($selected['location'] ?? '')
                ),
                ENT_QUOTES,
                'UTF-8'
            ) ?>

            <?php

            $summaryTransaction =
                reqMatchNormalizeTransaction(
                    $selected['transaction_type'] ?? ''
                );

            $summaryParts = [];

            if (
                $summaryTransaction === 'فروش' ||
                $summaryTransaction === 'پیش فروش'
            ) {

                $minPrice =
                    reqMatchNumber(
                        $selected['min_price'] ?? null
                    );

                $maxPrice =
                    reqMatchNumber(
                        $selected['max_price'] ?? null
                    );

                if (
                    $minPrice !== null ||
                    $maxPrice !== null
                ) {

                    if (
                        $minPrice !== null &&
                        $maxPrice !== null
                    ) {

                        $summaryParts[] =
                            'بودجه: ' .
                            number_format(
                                $minPrice
                            ) .
                            ' تا ' .
                            number_format(
                                $maxPrice
                            ) .
                            ' تومان';

                    } elseif (
                        $minPrice !== null
                    ) {

                        $summaryParts[] =
                            'حداقل بودجه: ' .
                            number_format(
                                $minPrice
                            ) .
                            ' تومان';

                    } elseif (
                        $maxPrice !== null
                    ) {

                        $summaryParts[] =
                            'حداکثر بودجه: ' .
                            number_format(
                                $maxPrice
                            ) .
                            ' تومان';
                    }
                }
            }

            if (
                $summaryTransaction === 'اجاره'
            ) {

                $minDeposit =
                    reqMatchNumber(
                        $selected['min_deposit'] ?? null
                    );

                $maxDeposit =
                    reqMatchNumber(
                        $selected['max_deposit'] ?? null
                    );

                $minRent =
                    reqMatchNumber(
                        $selected['min_rent'] ?? null
                    );

                $maxRent =
                    reqMatchNumber(
                        $selected['max_rent'] ?? null
                    );

                if (
                    $minDeposit !== null ||
                    $maxDeposit !== null
                ) {

                    if (
                        $minDeposit !== null &&
                        $maxDeposit !== null
                    ) {

                        $summaryParts[] =
                            'ودیعه: ' .
                            number_format(
                                $minDeposit
                            ) .
                            ' تا ' .
                            number_format(
                                $maxDeposit
                            ) .
                            ' تومان';

                    } elseif (
                        $minDeposit !== null
                    ) {

                        $summaryParts[] =
                            'حداقل ودیعه: ' .
                            number_format(
                                $minDeposit
                            ) .
                            ' تومان';

                    } elseif (
                        $maxDeposit !== null
                    ) {

                        $summaryParts[] =
                            'حداکثر ودیعه: ' .
                            number_format(
                                $maxDeposit
                            ) .
                            ' تومان';
                    }
                }

                if (
                    $minRent !== null ||
                    $maxRent !== null
                ) {

                    if (
                        $minRent !== null &&
                        $maxRent !== null
                    ) {

                        $summaryParts[] =
                            'اجاره: ' .
                            number_format(
                                $minRent
                            ) .
                            ' تا ' .
                            number_format(
                                $maxRent
                            ) .
                            ' تومان';

                    } elseif (
                        $minRent !== null
                    ) {

                        $summaryParts[] =
                            'حداقل اجاره: ' .
                            number_format(
                                $minRent
                            ) .
                            ' تومان';

                    } elseif (
                        $maxRent !== null
                    ) {

                        $summaryParts[] =
                            'حداکثر اجاره: ' .
                            number_format(
                                $maxRent
                            ) .
                            ' تومان';
                    }
                }
            }

            if ($summaryParts):
            ?>

                <div class="amount-rule">
                    <?= htmlspecialchars(
                        implode(
                            ' | ',
                            $summaryParts
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>

            <?php endif; ?>

        </div>

        <?php if (!empty($combinations)): ?>
            <div class="combo-section">
                <div class="combo-title">💰 ترکیب‌های پیشنهادی برای سرمایه‌گذاری</div>
                <div class="combo-grid">
                    <?php foreach ($combinations as $combo): ?>
                        <div class="combo-card">
                            <div class="combo-price">
                                مجموع قیمت: <?= number_format((float)$combo['total_price']) ?> تومان
                            </div>
                            <div class="combo-items">
                                <?php foreach ($combo['items'] as $item): 
                                    $price = isset($item['price']) && is_numeric($item['price']) ? (float)$item['price'] : 0;
                                    $title = isset($item['title']) ? $item['title'] : 'ملک';
                                    $adId = isset($item['ad_id']) ? $item['ad_id'] : '';
                                ?>
                                    <a class="combo-item" href="property-details.php?id=<?= urlencode($adId) ?>">
                                        <?= htmlspecialchars($title) ?>
                                        -
                                        <?= number_format($price) ?> تومان
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($matches): ?>

            <?php foreach ($matches as $m): ?>

                <a
                    class="match-card"
                    href="property-details.php?id=<?= urlencode(
                        (string)$m['ad_id']
                    ) ?>&from=request-matches&code=<?= urlencode(
                        (string)$selected['tracking_code']
                    ) ?>"
                >

                    <div class="match-head">

                        <div class="match-title">

                            <?= htmlspecialchars(
                                (string)(
                                    $m['title']
                                    ?? 'ملک بدون عنوان'
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </div>

                        <div class="match-percent">

                            <?= (int)(
                                $m['match_percent']
                                ?? 0
                            ) ?>٪

                        </div>

                    </div>

                    <div class="match-meta">

                        <?= htmlspecialchars(
                            (
                                ($m['property_type'] ?? '')
                                . ' · ' .
                                ($m['transaction_type'] ?? '')
                                . ' · ' .
                                ($m['location'] ?? '')
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    </div>

                    <div class="match-action">
                        مشاهده جزئیات آگهی ←
                    </div>

                </a>

            <?php endforeach; ?>

        <?php else: ?>

            <div class="empty">

                فایل مناسبی با معیارهای فعلی شما پیدا نشد.

                <br>

                <small>
                    فایل‌هایی که مبلغشان خارج از بازه انتخابی شما باشد
                    حتی در صورت داشتن درصد تطبیق بالا نمایش داده نمی‌شوند.
                </small>

            </div>

        <?php endif; ?>

    <?php else: ?>

        <div class="empty">
            درخواست موردنظر پیدا نشد.
        </div>

    <?php endif; ?>

    <a
        class="back-btn"
        href="home.php"
    >
        بازگشت به خانه
    </a>

</main>

<?php
require_once __DIR__ . '/footer.php';
?>