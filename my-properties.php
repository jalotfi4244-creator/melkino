<?php
declare(strict_types=1);

require_once __DIR__ . '/db_helpers.php';

global $pdo;

/*
|--------------------------------------------------------------------------
| Identity
|--------------------------------------------------------------------------
*/
$identity = melkinoCurrentIdentity(
    $_GET['telegram_id'] ?? $_POST['telegram_id'] ?? null
);


/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

function mpDigits(string $value): string
{
    return strtr(trim($value), [
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
    ]);
}


function mpNormalizePhone($value): string
{
    $value = mpDigits((string)$value);

    return preg_replace('/\D+/', '', $value) ?? '';
}


function mpNum($value): ?float
{
    $value = strtr((string)$value, [
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
    ]);

    $value = str_replace(
        [',', '٬', '،', ' ', 'تومان', 'ریال'],
        '',
        $value
    );

    $value = preg_replace('/[^0-9.\-]/u', '', $value) ?? '';

    return ($value !== '' && is_numeric($value))
        ? (float)$value
        : null;
}


function mpOwnerMatches(array $ad, array $identity): bool
{
    /*
     * اولویت با user_id
     */
    if (
        !empty($identity['user_id']) &&
        isset($ad['owner_user_id']) &&
        (int)$ad['owner_user_id'] === (int)$identity['user_id']
    ) {
        return true;
    }

    /*
     * سپس شماره تلفن
     */
    $adPhone = mpNormalizePhone($ad['phone'] ?? '');
    $userPhone = mpNormalizePhone($identity['phone'] ?? '');

    if (
        $adPhone !== '' &&
        $userPhone !== '' &&
        $adPhone === $userPhone
    ) {
        return true;
    }

    return false;
}


/*
|--------------------------------------------------------------------------
| Load user's own ads
|--------------------------------------------------------------------------
*/
function mpLoadOwnAds(array $identity): array
{
    global $pdo;

    $stmt = $pdo->query(
        "SELECT a.*
         FROM ads a
         ORDER BY a.created_at DESC"
    );

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];

    foreach ($rows as $row) {
        if (mpOwnerMatches($row, $identity)) {
            $result[] = $row;
        }
    }

    return $result;
}


/*
|--------------------------------------------------------------------------
| Find latest pending revision
|--------------------------------------------------------------------------
*/
function mpRevisionPayload(PDO $pdo, string $adId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, snapshot
         FROM ad_revisions
         WHERE ad_id = ?
         ORDER BY id DESC"
    );

    $stmt->execute([$adId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $snapshot = json_decode(
            (string)$row['snapshot'],
            true
        );

        if (
            is_array($snapshot) &&
            ($snapshot['review_status'] ?? 'pending') === 'pending'
        ) {
            return $snapshot;
        }
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| JSON response helper fallback
|--------------------------------------------------------------------------
*/
function mpJson(array $data, int $status = 200): void
{
    if (function_exists('melkinoJsonResponse')) {
        melkinoJsonResponse($data, $status);
    }

    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| AJAX actions
|--------------------------------------------------------------------------
*/

if (isset($_GET['action'])) {

    if (!$pdo instanceof PDO) {
        mpJson([
            'success' => false,
            'message' => 'اتصال دیتابیس برقرار نیست.'
        ], 500);
    }

    $action = trim((string)$_GET['action']);


    /*
    |--------------------------------------------------------------------------
    | LIST
    |--------------------------------------------------------------------------
    */
    if ($action === 'list') {

        try {

            $ads = mpLoadOwnAds($identity);

            foreach ($ads as &$ad) {

                /*
                 * Pending revision
                 */
                $pending = mpRevisionPayload(
                    $pdo,
                    (string)$ad['id']
                );

                if (
                    $pending &&
                    is_array($pending['after'] ?? null)
                ) {

                    $ad = array_merge(
                        $ad,
                        $pending['after']
                    );

                    $ad['review_pending'] = true;

                } else {

                    $ad['review_pending'] = false;
                }


                /*
                 * Numeric fields
                 */
                $numericFields = [
                    'area',
                    'price_sell',
                    'deposit',
                    'rent_monthly',
                    'full_rent',
                    'total_price',
                    'down_payment'
                ];

                foreach ($numericFields as $field) {

                    if (
                        !array_key_exists($field, $ad) ||
                        $ad[$field] === null ||
                        $ad[$field] === ''
                    ) {

                        $ad[$field] = null;

                    } else {

                        $ad[$field] = (float)$ad[$field];
                    }
                }
            }

            unset($ad);

            mpJson([
                'success' => true,
                'ads' => $ads
            ]);

        } catch (Throwable $e) {

            mpJson([
                'success' => false,
                'message' => 'خطا در دریافت لیست املاک.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */
    if ($action === 'delete') {

        $id = trim((string)(
            $_POST['id'] ??
            $_GET['id'] ??
            ''
        ));

        if ($id === '') {
            mpJson([
                'success' => false,
                'message' => 'شناسه آگهی الزامی است.'
            ], 422);
        }


        try {

            $stmt = $pdo->prepare(
                "SELECT *
                 FROM ads
                 WHERE id = ?
                 LIMIT 1"
            );

            $stmt->execute([$id]);

            $ad = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ad) {

                mpJson([
                    'success' => false,
                    'message' => 'آگهی موردنظر پیدا نشد.'
                ], 404);
            }


            if (!mpOwnerMatches($ad, $identity)) {

                mpJson([
                    'success' => false,
                    'message' => 'این آگهی متعلق به حساب شما نیست.'
                ], 403);
            }


            $pdo->beginTransaction();

            try {

                /*
                 * حذف revision های مربوط به آگهی
                 */
                $revisionDelete = $pdo->prepare(
                    "DELETE FROM ad_revisions
                     WHERE ad_id = ?"
                );

                $revisionDelete->execute([$id]);


                /*
                 * حذف داده‌های وابسته به آگهی
                 * قبلاً این حذف‌ها با ON DELETE CASCADE در خودِ دیتابیس
                 * انجام می‌شد؛ چون هاست فعلی (InfinityFree) دسترسی
                 * REFERENCES برای ساخت کلید خارجی نمی‌دهد، این پاک‌سازی
                 * باید دستی و از همینجا انجام شود.
                 */

                $pdo->prepare(
                    "DELETE rmf FROM request_match_feedback rmf
                     INNER JOIN request_matches rm ON rm.id = rmf.request_match_id
                     WHERE rm.ad_id = ?"
                )->execute([$id]);

                $pdo->prepare("DELETE FROM request_matches WHERE ad_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM favorites WHERE ad_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM ad_promotions WHERE ad_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM ad_amenities WHERE ad_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM images WHERE ad_id = ?")->execute([$id]);


                /*
                 * حذف آگهی
                 */
                $delete = $pdo->prepare(
                    "DELETE FROM ads
                     WHERE id = ?"
                );

                $delete->execute([$id]);

                $pdo->commit();


                mpJson([
                    'success' => true,
                    'message' => 'آگهی با موفقیت حذف شد.'
                ]);

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }

        } catch (Throwable $e) {

            mpJson([
                'success' => false,
                'message' => 'حذف آگهی انجام نشد.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */
    if ($action === 'update') {

        $id = trim((string)(
            $_POST['id'] ??
            ''
        ));

        if ($id === '') {

            mpJson([
                'success' => false,
                'message' => 'شناسه آگهی الزامی است.'
            ], 422);
        }


        try {

            /*
             * دریافت آگهی
             */
            $stmt = $pdo->prepare(
                "SELECT *
                 FROM ads
                 WHERE id = ?
                 LIMIT 1"
            );

            $stmt->execute([$id]);

            $ad = $stmt->fetch(PDO::FETCH_ASSOC);


            if (!$ad) {

                mpJson([
                    'success' => false,
                    'message' => 'آگهی موردنظر پیدا نشد.'
                ], 404);
            }


            /*
             * بررسی مالکیت
             */
            if (!mpOwnerMatches($ad, $identity)) {

                mpJson([
                    'success' => false,
                    'message' => 'این آگهی متعلق به حساب شما نیست یا اجازه ویرایش آن را ندارید.'
                ], 403);
            }


            /*
             * اطلاعات جدید
             */
            $after = [

                'title' => trim(
                    (string)(
                        $_POST['title'] ??
                        $ad['title'] ??
                        ''
                    )
                ),

                'area' => mpNum(
                    $_POST['area'] ??
                    $ad['area'] ??
                    null
                ),

                'price_sell' => mpNum(
                    $_POST['price_sell'] ??
                    $ad['price_sell'] ??
                    null
                ),

                'deposit' => mpNum(
                    $_POST['deposit'] ??
                    $ad['deposit'] ??
                    null
                ),

                'rent_monthly' => mpNum(
                    $_POST['rent_monthly'] ??
                    $ad['rent_monthly'] ??
                    null
                ),

                'description' => trim(
                    (string)(
                        $_POST['description'] ??
                        $ad['description'] ??
                        ''
                    )
                )
            ];


            /*
             * Snapshot
             */
            $snapshot = [

                'version' => 2,

                'review_status' => 'pending',

                'before' => [

                    'title' => $ad['title'] ?? null,

                    'area' => $ad['area'] ?? null,

                    'price_sell' => $ad['price_sell'] ?? null,

                    'deposit' => $ad['deposit'] ?? null,

                    'rent_monthly' => $ad['rent_monthly'] ?? null,

                    'description' => $ad['description'] ?? null,

                    'status' => $ad['status'] ?? null
                ],

                'after' => $after,

                'submitted_at' => date('Y-m-d H:i:s')
            ];


            $snapshotJson = json_encode(
                $snapshot,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );


            if ($snapshotJson === false) {

                throw new RuntimeException(
                    'خطا در ساخت اطلاعات ویرایش.'
                );
            }


            $pdo->beginTransaction();

            try {

                /*
                 * اگر revision قبلی pending وجود دارد
                 * همان را بروزرسانی می‌کنیم.
                 */
                $updateRevision = $pdo->prepare(
                    "UPDATE ad_revisions
                     SET snapshot = ?,
                         change_note = ?
                     WHERE ad_id = ?
                     AND JSON_UNQUOTE(
                         JSON_EXTRACT(
                             snapshot,
                             '$.review_status'
                         )
                     ) = 'pending'"
                );

                $updateRevision->execute([
                    $snapshotJson,
                    'ویرایش توسط مالک - در انتظار تأیید ادمین',
                    $id
                ]);


                /*
                 * اگر revision pending نداشتیم
                 * یک revision جدید ایجاد می‌کنیم.
                 */
                $check = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM ad_revisions
                     WHERE ad_id = ?
                     AND JSON_UNQUOTE(
                         JSON_EXTRACT(
                             snapshot,
                             '$.review_status'
                         )
                     ) = 'pending'"
                );

                $check->execute([$id]);

                $pendingCount = (int)$check->fetchColumn();


                if ($pendingCount === 0) {

                    $insert = $pdo->prepare(
                        "INSERT INTO ad_revisions
                        (
                            ad_id,
                            changed_by_admin_id,
                            snapshot,
                            change_note,
                            created_at
                        )
                        VALUES
                        (
                            ?,
                            NULL,
                            ?,
                            ?,
                            NOW()
                        )"
                    );

                    $insert->execute([
                        $id,
                        $snapshotJson,
                        'ویرایش توسط مالک - در انتظار تأیید ادمین'
                    ]);
                }


                /*
                 * وضعیت آگهی pending
                 */
                $updateAd = $pdo->prepare(
                    "UPDATE ads
                     SET status = 'pending',
                         updated_at = NOW()
                     WHERE id = ?"
                );

                $updateAd->execute([$id]);


                $pdo->commit();


                mpJson([
                    'success' => true,
                    'message' => 'تغییرات ذخیره شد و برای تأیید ادمین ارسال شد.',
                    'pending' => true
                ]);

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }

        } catch (Throwable $e) {

            mpJson([
                'success' => false,
                'message' => 'ذخیره ویرایش انجام نشد. لطفاً دوباره تلاش کنید.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Invalid action
    |--------------------------------------------------------------------------
    */
    mpJson([
        'success' => false,
        'message' => 'عملیات نامعتبر است.'
    ], 400);
}


/*
|--------------------------------------------------------------------------
| Page
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/header.php';

$telegramId = (string)(
    $identity['telegram_id'] ?? ''
);

?>

<style>
/* =========================================================
   MY PROPERTIES - PROFESSIONAL RESPONSIVE DESIGN
   ========================================================= */

.mpage {
    width: min(1180px, calc(100% - 28px));
    margin: 0 auto;
    padding: 28px 0 120px;

    /* قبلاً این صفحه هیچ اسکرولی نداشت چون داخل app-container (که
       ارتفاعش ثابت و overflow آن hidden است) قرار می‌گرفت بدون
       این‌که خودش راهی برای اسکرول داشته باشد؛ هر ملکی که از
       ارتفاع صفحه بیشتر می‌شد، به‌سادگی دیده نمی‌شد. همین سه خط
       (که صفحه‌ی اصلی سایت هم از آن استفاده می‌کند) این مشکل را
       حل می‌کند. */
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
}

/* ---------- Header ---------- */

.mp-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 24px;
}

.mp-header-content {
    min-width: 0;
}

.mp-title {
    margin: 0;
    color: var(--text-primary);
    font-size: clamp(24px, 3vw, 32px);
    font-weight: 950;
    letter-spacing: -0.5px;
}

.mp-subtitle {
    margin-top: 8px;
    color: var(--text-secondary);
    font-size: 13px;
    line-height: 1.9;
    max-width: 700px;
}

.mp-summary {
    flex-shrink: 0;
    min-width: 120px;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: var(--surface);
    text-align: center;
    box-shadow: 0 5px 20px rgba(0, 0, 0, .04);
}

.mp-summary-number {
    display: block;
    color: var(--primary);
    font-size: 22px;
    font-weight: 950;
}

.mp-summary-label {
    display: block;
    margin-top: 2px;
    color: var(--text-secondary);
    font-size: 10px;
}


/* ---------- Notice ---------- */

.notice {
    display: none;
    margin-bottom: 18px;
    padding: 13px 15px;
    border-radius: 13px;
    font-size: 12px;
    line-height: 1.8;
}

.notice.ok {
    display: block;
    background: #eaf8ef;
    border: 1px solid #b9e5c9;
    color: #19734a;
}

.notice.err {
    display: block;
    background: #fff0ef;
    border: 1px solid #f3c2bd;
    color: #b42318;
}


/* ---------- Loading ---------- */

.mp-loading {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.mp-skeleton {
    height: 230px;
    border-radius: 18px;
    background: linear-gradient(
        90deg,
        var(--surface),
        rgba(128,128,128,.08),
        var(--surface)
    );
    background-size: 200% 100%;
    animation: mpSkeleton 1.4s infinite;
    border: 1px solid var(--border);
}

@keyframes mpSkeleton {
    from {
        background-position: 200% 0;
    }

    to {
        background-position: -200% 0;
    }
}


/* ---------- Ads Grid ---------- */

.mp-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}


/* ---------- Card ---------- */

.mp-card {
    position: relative;
    overflow: hidden;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 19px;
    padding: 18px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, .045);
    transition:
        transform .2s ease,
        box-shadow .2s ease,
        border-color .2s ease;
}

.mp-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 14px 36px rgba(0, 0, 0, .08);
}


/* ---------- Card Top ---------- */

.mp-card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.mp-card-title-wrap {
    min-width: 0;
}

.mp-card-title {
    margin: 0;
    color: var(--text-primary);
    font-size: 16px;
    font-weight: 950;
    line-height: 1.7;
    overflow-wrap: anywhere;
}

.mp-card-id {
    margin-top: 4px;
    color: var(--text-secondary);
    font-size: 9px;
    direction: ltr;
    text-align: right;
}


/* ---------- Status ---------- */

.mp-status {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 9px;
    font-weight: 800;
    white-space: nowrap;
}

.mp-status.pending {
    color: #8a641b;
    background: rgba(201, 166, 95, .13);
}

.mp-status.active {
    color: #19734a;
    background: rgba(25, 115, 74, .10);
}

.mp-status.rejected {
    color: #b42318;
    background: rgba(180, 35, 24, .09);
}

.mp-status.default {
    color: var(--text-secondary);
    background: rgba(128, 128, 128, .10);
}


/* ---------- Property info ---------- */

.mp-info-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
    margin-top: 16px;
}

.mp-info {
    min-width: 0;
    padding: 10px;
    border-radius: 11px;
    background: var(--bg);
}

.mp-info-label {
    display: block;
    color: var(--text-secondary);
    font-size: 9px;
    line-height: 1.5;
}

.mp-info-value {
    display: block;
    margin-top: 4px;
    color: var(--text-primary);
    font-size: 11px;
    font-weight: 850;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.mp-info-value.money {
    direction: ltr;
    text-align: right;
}


/* ---------- Pending message ---------- */

.mp-pending {
    margin-top: 13px;
    padding: 10px 12px;
    border-radius: 11px;
    background: rgba(201, 166, 95, .08);
    border: 1px solid rgba(201, 166, 95, .18);
    color: var(--text-secondary);
    font-size: 10px;
    line-height: 1.8;
}


/* ---------- Actions ---------- */

.mp-actions {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 8px;
    margin-top: 15px;
}

.mp-btn {
    min-height: 40px;
    border: 1px solid var(--border);
    border-radius: 11px;
    padding: 8px 10px;
    background: var(--surface);
    color: var(--text-primary);
    font: inherit;
    font-size: 11px;
    font-weight: 750;
    text-decoration: none;
    cursor: pointer;
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 5px;
    transition: .18s ease;
}

.mp-btn:hover {
    border-color: var(--primary);
    transform: translateY(-1px);
}

.mp-btn.primary {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
}

.mp-btn.danger {
    color: #b42318;
}

.mp-btn:disabled {
    opacity: .55;
    cursor: not-allowed;
    transform: none;
}


/* ---------- Empty ---------- */

.mp-empty {
    padding: 70px 25px;
    text-align: center;
    border: 1px dashed var(--border);
    border-radius: 19px;
    background: var(--surface);
    color: var(--text-secondary);
}

.mp-empty-icon {
    font-size: 42px;
    margin-bottom: 14px;
}

.mp-empty-title {
    color: var(--text-primary);
    font-size: 16px;
    font-weight: 900;
}

.mp-empty-text {
    margin-top: 7px;
    font-size: 11px;
    line-height: 1.8;
}


/* =========================================================
   MODAL
   ========================================================= */

.mp-modal {
    position: fixed;
    inset: 0;
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(0, 0, 0, .48);
    backdrop-filter: blur(5px);
}

.mp-modal.show {
    display: flex;
}

.mp-modal-box {
    width: min(700px, 100%);
    max-height: min(850px, calc(100vh - 40px));
    overflow-y: auto;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 22px;
    box-shadow: 0 25px 80px rgba(0, 0, 0, .2);
}

.mp-modal-header {
    position: sticky;
    top: 0;
    z-index: 2;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 17px 18px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
}

.mp-modal-title {
    color: var(--text-primary);
    font-size: 17px;
    font-weight: 950;
}

.mp-modal-close {
    width: 36px;
    height: 36px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--bg);
    color: var(--text-primary);
    cursor: pointer;
    font-size: 18px;
}

.mp-modal-body {
    padding: 18px;
}

.mp-form-label {
    display: block;
    color: var(--text-primary);
    font-size: 11px;
    font-weight: 800;
    margin-bottom: 12px;
}

.mp-form-control {
    width: 100%;
    box-sizing: border-box;
    margin-top: 6px;
    padding: 11px 12px;
    border: 1px solid var(--border);
    border-radius: 11px;
    background: var(--bg);
    color: var(--text-primary);
    font: inherit;
    font-size: 12px;
    outline: none;
    transition: border-color .18s ease, box-shadow .18s ease;
}

.mp-form-control:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(11, 93, 91, .08);
}

.mp-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.mp-form-footer {
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    padding-top: 5px;
}

.mp-form-footer .mp-btn {
    min-width: 130px;
}


/* ---------- Mobile ---------- */

@media (max-width: 850px) {

    .mp-list {
        grid-template-columns: 1fr;
    }

    .mp-loading {
        grid-template-columns: 1fr;
    }
}


@media (max-width: 650px) {

    .mpage {
        width: min(100% - 20px, 600px);
        padding-top: 18px;
    }

    .mp-header {
        align-items: stretch;
        flex-direction: column;
        gap: 12px;
    }

    .mp-summary {
        align-self: flex-start;
        min-width: 105px;
    }

    .mp-title {
        font-size: 24px;
    }

    .mp-subtitle {
        font-size: 11px;
    }

    .mp-card {
        padding: 14px;
        border-radius: 16px;
    }

    .mp-card-top {
        gap: 8px;
    }

    .mp-card-title {
        font-size: 14px;
    }

    .mp-info-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .mp-actions {
        grid-template-columns: 1fr 1fr 1fr;
    }

    .mp-btn {
        font-size: 10px;
        padding: 8px 5px;
    }

    .mp-modal {
        align-items: flex-end;
        padding: 0;
    }

    .mp-modal-box {
        width: 100%;
        max-height: 92vh;
        border-radius: 22px 22px 0 0;
    }

    .mp-form-row {
        grid-template-columns: 1fr;
        gap: 0;
    }

    .mp-form-footer {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .mp-form-footer .mp-btn {
        width: 100%;
        min-width: 0;
    }
}


@media (max-width: 400px) {

    .mp-info-grid {
        grid-template-columns: 1fr 1fr;
        gap: 6px;
    }

    .mp-info {
        padding: 8px;
    }

    .mp-info-value {
        font-size: 10px;
    }

    .mp-actions {
        gap: 5px;
    }
}
</style>


<main class="mpage">

    <!-- Header -->
    <header class="mp-header">

        <div class="mp-header-content">

            <h1 class="mp-title">
                🏠 املاک من
            </h1>

            <div class="mp-subtitle">
                آگهی‌های ثبت‌شده شما در این بخش نمایش داده می‌شوند.
                برای ویرایش، تغییرات ابتدا برای بررسی و تأیید ادمین ارسال خواهند شد.
            </div>

        </div>

        <div class="mp-summary">

            <span
                id="propertyCount"
                class="mp-summary-number"
            >
                —
            </span>

            <span class="mp-summary-label">
                آگهی ثبت‌شده
            </span>

        </div>

    </header>


    <!-- Notice -->
    <div
        id="notice"
        class="notice"
    ></div>


    <!-- List -->
    <div
        id="list"
        class="mp-list"
    >

        <div class="mp-loading">

            <div class="mp-skeleton"></div>
            <div class="mp-skeleton"></div>

        </div>

    </div>

</main>


<!-- =========================================================
     EDIT MODAL
     ========================================================= -->

<div
    class="mp-modal"
    id="modal"
    aria-hidden="true"
>

    <div class="mp-modal-box">

        <div class="mp-modal-header">

            <div class="mp-modal-title">
                ✏️ ویرایش آگهی
            </div>

            <button
                type="button"
                class="mp-modal-close"
                id="closeModalBtn"
                aria-label="بستن"
            >
                ×
            </button>

        </div>


        <div class="mp-modal-body">

            <form
                id="form"
                autocomplete="off"
            >

                <input
                    type="hidden"
                    name="id"
                >

                <input
                    type="hidden"
                    name="telegram_id"
                    value="<?= htmlspecialchars(
                        $telegramId,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >


                <label class="mp-form-label">

                    عنوان آگهی

                    <input
                        class="mp-form-control"
                        type="text"
                        name="title"
                        maxlength="255"
                        required
                    >

                </label>


                <div class="mp-form-row">

                    <label class="mp-form-label">

                        متراژ

                        <input
                            class="mp-form-control"
                            type="text"
                            name="area"
                            inputmode="decimal"
                        >

                    </label>


                    <label class="mp-form-label">

                        قیمت فروش

                        <input
                            class="mp-form-control money-input"
                            type="text"
                            name="price_sell"
                            inputmode="numeric"
                        >

                    </label>

                </div>


                <div class="mp-form-row">

                    <label class="mp-form-label">

                        ودیعه

                        <input
                            class="mp-form-control money-input"
                            type="text"
                            name="deposit"
                            inputmode="numeric"
                        >

                    </label>


                    <label class="mp-form-label">

                        اجاره ماهانه

                        <input
                            class="mp-form-control money-input"
                            type="text"
                            name="rent_monthly"
                            inputmode="numeric"
                        >

                    </label>

                </div>


                <label class="mp-form-label">

                    توضیحات

                    <textarea
                        class="mp-form-control"
                        name="description"
                        rows="6"
                    ></textarea>

                </label>


                <div class="mp-form-footer">

                    <button
                        type="button"
                        class="mp-btn"
                        id="cancelBtn"
                    >
                        انصراف
                    </button>

                    <button
                        type="submit"
                        class="mp-btn primary"
                        id="submitBtn"
                    >
                        ارسال برای تأیید
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<script>
(function () {

    'use strict';


    /* =====================================================
       Elements
       ===================================================== */

    const list = document.getElementById('list');
    const notice = document.getElementById('notice');
    const modal = document.getElementById('modal');
    const form = document.getElementById('form');
    const submitBtn = document.getElementById('submitBtn');
    const propertyCount = document.getElementById('propertyCount');

    const telegramId = <?= json_encode(
        $telegramId,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>;


    /* =====================================================
       Helpers
       ===================================================== */

    function esc(value) {

        return String(value ?? '').replace(
            /[&<>"']/g,
            function (char) {

                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[char];

            }
        );
    }


    function formatMoney(value) {

        if (
            value === null ||
            value === undefined ||
            value === ''
        ) {
            return '';
        }

        let str = String(value);

        str = str.replace(
            /[۰-۹]/g,
            function (digit) {
                return '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit);
            }
        );

        str = str.replace(/[^\d]/g, '');

        if (!str) {
            return '';
        }

        return Number(str).toLocaleString('en-US');
    }


    function unformatMoney(value) {

        let str = String(value ?? '');

        str = str.replace(
            /[۰-۹]/g,
            function (digit) {
                return '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit);
            }
        );

        return str.replace(/[^\d.-]/g, '');
    }


    function formatArea(value) {

        if (
            value === null ||
            value === undefined ||
            value === ''
        ) {
            return '—';
        }

        const number = Number(value);

        if (Number.isNaN(number)) {
            return esc(value);
        }

        return number.toLocaleString('en-US') + ' متر';
    }


    function showNotice(message, success = true) {

        notice.className =
            'notice ' +
            (success ? 'ok' : 'err');

        notice.textContent = message;

        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });

        setTimeout(function () {

            if (notice.textContent === message) {
                notice.style.display = 'none';
            }

        }, 6000);
    }


    function statusInfo(ad) {

        if (ad.review_pending) {

            return {
                className: 'pending',
                icon: '⏳',
                text: 'در انتظار تأیید'
            };
        }

        const status = String(
            ad.status || ''
        ).toLowerCase();


        if (
            status === 'active' ||
            status === 'published' ||
            status === 'approved'
        ) {

            return {
                className: 'active',
                icon: '✓',
                text: 'فعال'
            };
        }


        if (
            status === 'rejected' ||
            status === 'declined'
        ) {

            return {
                className: 'rejected',
                icon: '!',
                text: 'رد شده'
            };
        }


        return {
            className: 'default',
            icon: '•',
            text: ad.status || 'نامشخص'
        };
    }


    /* =====================================================
       API
       ===================================================== */

    async function api(action, options = {}) {

        const params = new URLSearchParams();

        params.set(
            'action',
            action
        );

        if (
            options.method === 'POST' &&
            options.body
        ) {

            return fetch(
                'my-properties.php?' + params.toString(),
                {
                    method: 'POST',
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: options.body
                }
            );
        }


        return fetch(
            'my-properties.php?' + params.toString(),
            {
                method: 'GET',
                cache: 'no-store'
            }
        );
    }


    /* =====================================================
       Load ads
       ===================================================== */

    async function load() {

        try {

            list.innerHTML = `
                <div class="mp-loading">
                    <div class="mp-skeleton"></div>
                    <div class="mp-skeleton"></div>
                </div>
            `;


            const response = await fetch(
                'my-properties.php?action=list&telegram_id=' +
                encodeURIComponent(telegramId),
                {
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json'
                    }
                }
            );


            /*
             * اگر PHP خطای 500 بدهد
             */
            if (!response.ok) {

                const text = await response.text();

                console.error(
                    'my-properties.php error:',
                    text
                );

                throw new Error(
                    'HTTP ' + response.status
                );
            }


            const data = await response.json();


            if (!data.success) {

                showNotice(
                    data.message ||
                    'خطا در دریافت املاک.',
                    false
                );

                list.innerHTML = '';

                return;
            }


            const ads = Array.isArray(data.ads)
                ? data.ads
                : [];


            propertyCount.textContent =
                ads.length.toLocaleString('fa-IR');


            if (!ads.length) {

                list.className = '';

                list.innerHTML = `
                    <div class="mp-empty">

                        <div class="mp-empty-icon">
                            🏠
                        </div>

                        <div class="mp-empty-title">
                            هنوز آگهی‌ای ثبت نکرده‌اید
                        </div>

                        <div class="mp-empty-text">
                            بعد از ثبت آگهی، تمام املاک شما
                            در این قسمت نمایش داده می‌شوند.
                        </div>

                    </div>
                `;

                return;
            }


            list.className = 'mp-list';


            list.innerHTML = ads.map(
                function (ad) {

                    const status =
                        statusInfo(ad);


                    const pendingHtml =
                        ad.review_pending
                        ? `
                            <div class="mp-pending">
                                ⏳ تغییرات این آگهی برای بررسی
                                و تأیید ادمین ارسال شده است.
                            </div>
                        `
                        : '';


                    return `
                        <article
                            class="mp-card"
                            data-id="${esc(ad.id)}"
                        >

                            <div class="mp-card-top">

                                <div class="mp-card-title-wrap">

                                    <h2 class="mp-card-title">
                                        ${esc(
                                            ad.title ||
                                            'بدون عنوان'
                                        )}
                                    </h2>

                                    <div class="mp-card-id">
                                        کد آگهی:
                                        ${esc(ad.id || '—')}
                                    </div>

                                </div>


                                <span
                                    class="mp-status ${status.className}"
                                >
                                    ${status.icon}
                                    ${esc(status.text)}
                                </span>

                            </div>


                            <div class="mp-info-grid">

                                <div class="mp-info">

                                    <span class="mp-info-label">
                                        نوع ملک
                                    </span>

                                    <strong class="mp-info-value">
                                        ${esc(
                                            ad.property_type ||
                                            '—'
                                        )}
                                    </strong>

                                </div>


                                <div class="mp-info">

                                    <span class="mp-info-label">
                                        نوع معامله
                                    </span>

                                    <strong class="mp-info-value">
                                        ${esc(
                                            ad.transaction_type ||
                                            '—'
                                        )}
                                    </strong>

                                </div>


                                <div class="mp-info">

                                    <span class="mp-info-label">
                                        متراژ
                                    </span>

                                    <strong class="mp-info-value">
                                        ${formatArea(ad.area)}
                                    </strong>

                                </div>


                                <div class="mp-info">

                                    <span class="mp-info-label">
                                        قیمت فروش
                                    </span>

                                    <strong class="mp-info-value money">
                                        ${
                                            formatMoney(
                                                ad.price_sell
                                            ) || '—'
                                        }
                                    </strong>

                                </div>


                                <div class="mp-info">

                                    <span class="mp-info-label">
                                        ودیعه
                                    </span>

                                    <strong class="mp-info-value money">
                                        ${
                                            formatMoney(
                                                ad.deposit
                                            ) || '—'
                                        }
                                    </strong>

                                </div>


                                <div class="mp-info">

                                    <span class="mp-info-label">
                                        اجاره ماهانه
                                    </span>

                                    <strong class="mp-info-value money">
                                        ${
                                            formatMoney(
                                                ad.rent_monthly
                                            ) || '—'
                                        }
                                    </strong>

                                </div>

                            </div>


                            ${pendingHtml}


                            <div class="mp-actions">

                                <button
                                    type="button"
                                    class="mp-btn"
                                    data-edit
                                >
                                    ✏️ ویرایش
                                </button>


                                <button
                                    type="button"
                                    class="mp-btn danger"
                                    data-delete
                                >
                                    🗑 حذف
                                </button>


                                <a
                                    class="mp-btn primary"
                                    href="property-details.php?id=${encodeURIComponent(
                                        ad.id
                                    )}"
                                >
                                    👁 مشاهده
                                </a>

                            </div>

                        </article>
                    `;
                }
            ).join('');


            /*
             * Bind buttons
             */
            list.querySelectorAll('[data-edit]')
                .forEach(function (button, index) {

                    button.addEventListener(
                        'click',
                        function () {

                            editAd(ads[index]);

                        }
                    );
                });


            list.querySelectorAll('[data-delete]')
                .forEach(function (button, index) {

                    button.addEventListener(
                        'click',
                        function () {

                            removeAd(
                                ads[index].id
                            );

                        }
                    );
                });


        } catch (error) {

            console.error(
                'Load properties error:',
                error
            );


            propertyCount.textContent = '—';


            list.className = '';


            list.innerHTML = `
                <div class="mp-empty">

                    <div class="mp-empty-icon">
                        ⚠️
                    </div>

                    <div class="mp-empty-title">
                        دریافت اطلاعات انجام نشد
                    </div>

                    <div class="mp-empty-text">
                        اتصال به سرور یا پردازش اطلاعات با مشکل مواجه شد.
                        لطفاً صفحه را دوباره بارگذاری کنید.
                    </div>

                    <button
                        type="button"
                        class="mp-btn primary"
                        style="margin-top:16px"
                        id="retryLoad"
                    >
                        🔄 تلاش دوباره
                    </button>

                </div>
            `;


            const retry =
                document.getElementById(
                    'retryLoad'
                );


            if (retry) {

                retry.addEventListener(
                    'click',
                    load
                );
            }


            showNotice(
                'ارتباط با سرور برقرار نشد. جزئیات خطا در Console مرورگر قابل مشاهده است.',
                false
            );
        }
    }


    /* =====================================================
       Edit
       ===================================================== */

    function editAd(ad) {

        form.elements.id.value =
            ad.id || '';


        form.elements.title.value =
            ad.title || '';


        form.elements.area.value =
            ad.area ?? '';


        form.elements.price_sell.value =
            formatMoney(ad.price_sell);


        form.elements.deposit.value =
            formatMoney(ad.deposit);


        form.elements.rent_monthly.value =
            formatMoney(ad.rent_monthly);


        form.elements.description.value =
            ad.description || '';


        modal.classList.add('show');

        modal.setAttribute(
            'aria-hidden',
            'false'
        );


        document.body.style.overflow =
            'hidden';


        setTimeout(
            function () {

                form.elements.title.focus();

            },
            100
        );
    }


    function closeModal() {

        modal.classList.remove('show');

        modal.setAttribute(
            'aria-hidden',
            'true'
        );


        document.body.style.overflow =
            '';
    }


    /* =====================================================
       Delete
       ===================================================== */

    async function removeAd(id) {

        if (!id) {
            return;
        }


        const confirmed = confirm(
            'آیا مطمئن هستید که می‌خواهید این آگهی را حذف کنید؟'
        );


        if (!confirmed) {
            return;
        }


        try {

            const body =
                new URLSearchParams();

            body.set(
                'id',
                id
            );

            body.set(
                'telegram_id',
                telegramId
            );


            const response =
                await fetch(
                    'my-properties.php?action=delete',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type':
                                'application/x-www-form-urlencoded;charset=UTF-8',
                            'Accept':
                                'application/json'
                        },
                        body: body.toString()
                    }
                );


            const data =
                await response.json();


            showNotice(
                data.message ||
                (
                    data.success
                        ? 'عملیات انجام شد.'
                        : 'حذف انجام نشد.'
                ),
                !!data.success
            );


            if (data.success) {
                await load();
            }

        } catch (error) {

            console.error(
                'Delete error:',
                error
            );


            showNotice(
                'حذف آگهی انجام نشد. لطفاً دوباره تلاش کنید.',
                false
            );
        }
    }


    /* =====================================================
       Money inputs
       ===================================================== */

    document.querySelectorAll(
        '.money-input'
    ).forEach(function (input) {

        input.addEventListener(
            'input',
            function () {

                input.value =
                    formatMoney(
                        input.value
                    );
            }
        );

    });


    /* =====================================================
       Submit edit
       ===================================================== */

    form.addEventListener(
        'submit',
        async function (event) {

            event.preventDefault();


            const oldText =
                submitBtn.textContent;


            submitBtn.disabled = true;

            submitBtn.textContent =
                '⏳ در حال ارسال...';


            try {

                const formData =
                    new FormData(form);


                formData.set(
                    'price_sell',
                    unformatMoney(
                        formData.get(
                            'price_sell'
                        )
                    )
                );


                formData.set(
                    'deposit',
                    unformatMoney(
                        formData.get(
                            'deposit'
                        )
                    )
                );


                formData.set(
                    'rent_monthly',
                    unformatMoney(
                        formData.get(
                            'rent_monthly'
                        )
                    )
                );


                const body =
                    new URLSearchParams();


                formData.forEach(
                    function (value, key) {

                        body.set(
                            key,
                            value
                        );

                    }
                );


                const response =
                    await fetch(
                        'my-properties.php?action=update',
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type':
                                    'application/x-www-form-urlencoded;charset=UTF-8',
                                'Accept':
                                    'application/json'
                            },
                            body: body.toString()
                        }
                    );


                const data =
                    await response.json();


                showNotice(
                    data.message ||
                    (
                        data.success
                            ? 'تغییرات ذخیره شد.'
                            : 'ذخیره تغییرات انجام نشد.'
                    ),
                    !!data.success
                );


                if (data.success) {

                    closeModal();

                    await load();
                }


            } catch (error) {

                console.error(
                    'Update error:',
                    error
                );


                showNotice(
                    'ارسال تغییرات انجام نشد. لطفاً اتصال را بررسی کنید.',
                    false
                );

            } finally {

                submitBtn.disabled =
                    false;

                submitBtn.textContent =
                    oldText;
            }

        }
    );


    /* =====================================================
       Modal controls
       ===================================================== */

    document
        .getElementById('closeModalBtn')
        .addEventListener(
            'click',
            closeModal
        );


    document
        .getElementById('cancelBtn')
        .addEventListener(
            'click',
            closeModal
        );


    modal.addEventListener(
        'click',
        function (event) {

            if (
                event.target === modal
            ) {
                closeModal();
            }

        }
    );


    document.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key === 'Escape' &&
                modal.classList.contains('show')
            ) {

                closeModal();
            }

        }
    );


    /* =====================================================
       Initial load
       ===================================================== */

    load();

})();
</script>


<?php
require_once __DIR__ . '/footer.php';
?>