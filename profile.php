<?php
session_start();

// نکته: config.php باید پیش از هر خروجی لود شود تا ریدایرکت‌های آن
// (اجبار HTTPS، حالت تعمیرات) و هندلر مرکزی خطا درست کار کنند.
// قبلاً header.php اول لود می‌شد (که خروجی HTML می‌دهد) و بعد config.php.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/bot-settings.php';
require_once __DIR__ . '/compare-lib.php';

$userName  = trim((string)($_SESSION['user_name'] ?? '')) ?: 'کاربر ملکینو';
$userPhone = trim((string)($_SESSION['user_phone'] ?? ''));

/*
|--------------------------------------------------------------------------
| آمار فعلی
|--------------------------------------------------------------------------
| در این نسخه فعلاً از داده‌های موجود استفاده می‌کنیم.
| بعداً می‌توانیم این اعداد را مستقیماً از ads.json / requests.json
| یا دیتابیس محاسبه کنیم.
|--------------------------------------------------------------------------
*/

$myPropertiesCount = 0;
$myRequestsCount   = 0;
$favoriteCount     = 0;
$notificationCount = 0;
$matchCount = 0;

$identity = melkinoCurrentIdentity();
if (!empty($identity['user']['name'])) { $userName=trim((string)$identity['user']['name']); $_SESSION['user_name']=$userName; }
if ($userPhone==='' && !empty($identity['phone'])) { $userPhone=trim((string)$identity['phone']); $_SESSION['user_phone']=$userPhone; }

// قبلاً وقتی هیچ هویتی (نه شماره، نه تلگرام) در دسترس نبود — مثلاً
// درست بعد از خروج از حساب — پروفایل بی‌صدا خالی نمایش داده می‌شد.
// حالا در این حالت یک صفحه‌ی «ورود به حساب» واقعی نشان داده می‌شود.
if ($userPhone === '' && empty($identity['telegram_id']) && empty($identity['user_id'])) {
    require_once __DIR__ . '/header.php';
    ?>
    <div style="max-width:420px;margin:60px auto;padding:36px 28px;background:var(--bg-card,#16211F);border:1px solid var(--border,#223330);border-radius:20px;text-align:center;">
        <div style="font-size:40px;margin-bottom:10px;">👋</div>
        <h2 style="margin:0 0 8px;">به ملکینو خوش اومدی</h2>
        <p style="color:var(--text-secondary,#A8B1AE);line-height:1.9;margin:0 0 24px;">
            برای دیدن ملک‌ها و درخواست‌های ثبت‌شده‌ی خودت، وارد حساب کاربری‌ات شو.
        </p>
        <a href="login.php" style="display:block;padding:14px;border-radius:12px;background:linear-gradient(135deg,var(--primary,#0E7C6E),#0B5D5B);color:#fff;text-decoration:none;font-weight:700;">
            🔑 ورود به حساب کاربری
        </a>

        <!--
            دکمهٔ «تلاش دوباره» برای کاربرانِ مینی‌اپ تلگرام/بله:
            اگر همگام‌سازی هویت یک‌بار ناموفق مانده باشد (نت ضعیف، توکن
            تازه‌ذخیره‌شده و...)، با این دکمه پرچم تلاشِ قبلی پاک و صفحه
            دوباره بارگذاری می‌شود تا پروفایل واقعی باز شود.
        -->
        <button
            type="button"
            onclick="(function(){try{sessionStorage.removeItem('melkino_profile_synced');}catch(e){}location.reload();})();"
            style="display:block;width:100%;margin-top:10px;padding:12px;border-radius:12px;background:transparent;border:1px solid var(--border,#223330);color:var(--text-secondary,#A8B1AE);font-family:inherit;font-size:13px;font-weight:700;cursor:pointer;"
        >
            🔄 تلاش دوباره (کاربران تلگرام / بله)
        </button>
    </div>
    <?php
    require_once __DIR__ . '/footer.php';
    exit;
}
if ($pdo instanceof PDO) {
    if ($userPhone !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ads WHERE phone = ?");
        $stmt->execute([$userPhone]);
        $myPropertiesCount = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM property_requests WHERE phone = ?");
        $stmt->execute([$userPhone]);
        $myRequestsCount = (int)$stmt->fetchColumn();
    } elseif ($identity['user_id'] || $identity['telegram_id'] !== '') {
        $cond = $identity['user_id'] ? 'user_id = ?' : 'telegram_id = ?';
        $val = $identity['user_id'] ?: $identity['telegram_id'];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ads WHERE owner_user_id = ?");
        if ($identity['user_id']) { $stmt->execute([$identity['user_id']]); $myPropertiesCount = (int)$stmt->fetchColumn(); }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM property_requests WHERE $cond"); $stmt->execute([$val]); $myRequestsCount = (int)$stmt->fetchColumn();
    }
    $favParts=[];$favParams=[];$notifParts=[];$notifParams=[];
    if($identity['user_id']){$favParts[]='user_id=?';$favParams[]=$identity['user_id'];$notifParts[]='user_id=?';$notifParams[]=$identity['user_id'];}
    if($identity['telegram_id']!==''){$favParts[]='telegram_id=?';$favParams[]=$identity['telegram_id'];$notifParts[]='telegram_id=?';$notifParams[]=$identity['telegram_id'];}
    if($favParts){$stmt=$pdo->prepare('SELECT COUNT(*) FROM favorites WHERE '.implode(' OR ',$favParts));$stmt->execute($favParams);$favoriteCount=(int)$stmt->fetchColumn();}
    if($notifParts){$stmt=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE is_read=0 AND ('.implode(' OR ',$notifParts).')');$stmt->execute($notifParams);$notificationCount=(int)$stmt->fetchColumn();}

    // Number of currently available matches for this user's requests.
    if ($userPhone !== '' || $identity['user_id'] || $identity['telegram_id'] !== '') {
        $parts=[]; $params=[];
        if ($identity['user_id']) { $parts[]='r.user_id=?'; $params[]=$identity['user_id']; }
        if ($identity['telegram_id'] !== '') { $parts[]='r.telegram_id=?'; $params[]=$identity['telegram_id']; }
        if ($userPhone !== '') { $parts[]="REPLACE(REPLACE(REPLACE(r.phone,' ',''),'-',''),'+','')=?"; $params[]=$userPhone; }
        if ($parts) {
            $stmt=$pdo->prepare("SELECT COUNT(*) FROM request_matches rm JOIN property_requests r ON r.id=rm.request_id JOIN ads a ON a.id=rm.ad_id AND a.status='published' WHERE (".implode(' OR ',$parts).")");
            $stmt->execute($params); $matchCount=(int)$stmt->fetchColumn();
        }
    }
}

/*
|--------------------------------------------------------------------------
| کارت‌های پروفایل: تعداد مقایسه + لینک کانال تلگرام
|--------------------------------------------------------------------------
| تعداد مقایسه اینجا (سمت سرور) خوانده می‌شود تا کارتِ مقایسه حتی بدون
| جاوااسکریپت هم عدد درست را نشان دهد. لینک کانال هم از تنظیماتِ
| «ربات و کانال» پنل ادمین ساخته می‌شود.
|--------------------------------------------------------------------------
*/
$compareCount = 0;
if ($pdo instanceof PDO) {
    try {
        melkinoEnsureCompareTables($pdo);
        melkinoCompareMergeGuest($pdo, $identity);
        [$cmpWhere, $cmpParams] = melkinoCompareOwner($identity, 'ci');
        if ($cmpWhere !== '') {
            $compareCount = melkinoCompareCount($pdo, $cmpWhere, $cmpParams);
        }
    } catch (Throwable $e) {
        $compareCount = 0;
    }
}

$channelUrl = function_exists('melkinoChannelUrl') ? melkinoChannelUrl() : '';

/*
|--------------------------------------------------------------------------
| علاقه‌مندی‌ها
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/header.php';
?>

<style>

    /* =========================================================
       MELKINO PROFILE
       ========================================================= */

    .main-content {
        flex: 1;
        overflow-y: auto;
        padding:
            0
            var(--space-3)
            140px
            var(--space-3);

        background:
            radial-gradient(
                circle at top right,
                rgba(201, 166, 95, 0.07),
                transparent 32%
            ),
            var(--bg);
    }


    .profile-page {
        width: 100%;
        max-width: 980px;
        margin: 0 auto;
    }


    /* =========================================================
       PROFILE HERO
       ========================================================= */

    .profile-hero {

        position: relative;
        overflow: hidden;

        margin-bottom: 16px;
        padding: 22px;

        border-radius: 24px;

        background:
            linear-gradient(
                135deg,
                #174e48,
                #0b3531
            );

        border:
            1px solid
            rgba(255,255,255,.08);

        box-shadow:
            0 15px 35px
            rgba(8,45,41,.18);

        color: #fff;
    }


    .profile-hero::before {

        content: "";

        position: absolute;

        width: 230px;
        height: 230px;

        left: -120px;
        top: -140px;

        border-radius: 50%;

        background:
            rgba(215,180,96,.08);

        pointer-events:
            none;
    }


    .profile-hero::after {

        content: "";

        position: absolute;

        width: 170px;
        height: 170px;

        right: -80px;
        bottom: -95px;

        border-radius: 50%;

        background:
            rgba(255,255,255,.035);

        pointer-events:
            none;
    }


    .profile-hero-content {

        position: relative;
        z-index: 2;

        display: flex;
        align-items: center;
        justify-content: space-between;

        gap: 18px;
    }


    .profile-identity {

        display: flex;
        flex-direction: column;

        min-width: 0;
    }


    .profile-badge {

        display: inline-flex;
        align-items: center;

        width: fit-content;

        gap: 7px;

        padding:
            6px
            10px;

        margin-bottom: 10px;

        border-radius: 999px;

        background:
            rgba(215,180,96,.12);

        border:
            1px solid
            rgba(215,180,96,.18);

        color:
            #e8cd93;

        font-size: 10px;
        font-weight: 800;
    }


    .profile-badge svg {

        width: 14px;
        height: 14px;
    }


    .profile-name {

        margin: 0;

        color: #fff;

        font-size:
            clamp(22px, 4vw, 30px);

        font-weight: 850;

        line-height: 1.4;
    }


    .profile-phone {

        margin-top: 5px;

        color:
            rgba(255,255,255,.68);

        font-size: 13px;

        direction: ltr;
        text-align: right;
    }


    .profile-edit-btn {

        flex: 0 0 auto;

        display: inline-flex;
        align-items: center;

        gap: 7px;

        min-height: 42px;

        padding:
            0
            14px;

        border-radius: 11px;

        border:
            1px solid
            rgba(255,255,255,.12);

        background:
            rgba(255,255,255,.07);

        color: #fff;

        font-family:
            'Vazirmatn',
            sans-serif;

        font-size: 12px;

        font-weight: 800;

        cursor: pointer;

        transition:
            .2s ease;
    }


    .profile-edit-btn:hover {

        background:
            rgba(255,255,255,.12);
    }


    .profile-edit-btn svg {

        width: 16px;
        height: 16px;
    }


    /* =========================================================
       STATS
       ========================================================= */

    .profile-stats {

        display: grid;

        grid-template-columns:
            repeat(
                4,
                minmax(0,1fr)
            );

        gap: 10px;

        margin-bottom: 18px;
    }


    .profile-stat {

        background:
            var(--surface);

        border:
            1px solid
            var(--border);

        border-radius: 16px;

        padding: 14px;

        text-decoration: none;

        color: inherit;

        box-shadow:
            var(--shadow-card);

        transition:
            transform .2s ease,
            border-color .2s ease;
    }


    .profile-stat:hover {

        transform:
            translateY(-3px);

        border-color:
            rgba(191,157,87,.42);
    }


    .profile-stat-top {

        display: flex;
        align-items: center;
        justify-content: space-between;

        gap: 10px;

        margin-bottom: 9px;
    }


    .profile-stat-icon {

        width: 38px;
        height: 38px;

        display: flex;
        align-items: center;
        justify-content: center;

        border-radius: 12px;

        background:
            var(--gold-bg);

        color:
            var(--gold);
    }


    .profile-stat-icon svg {

        width: 18px;
        height: 18px;
    }


    .profile-stat-number {

        color:
            var(--text-primary);

        font-size: 24px;

        font-weight: 850;

        line-height: 1;
    }


    .profile-stat-title {

        color:
            var(--text-primary);

        font-size: 12px;

        font-weight: 800;
    }


    .profile-stat-subtitle {

        margin-top: 3px;

        color:
            var(--text-secondary);

        font-size: 10px;

        line-height: 1.7;
    }


    /* =========================================================
       SECTION
       ========================================================= */

    .profile-section {

        margin-bottom: 17px;
    }


    .profile-section-header {

        display: flex;
        align-items: center;
        justify-content: space-between;

        gap: 10px;

        margin-bottom: 10px;
    }


    .profile-section-title {

        display: flex;
        align-items: center;

        gap: 9px;
    }


    .profile-section-icon {

        width: 36px;
        height: 36px;

        display: flex;
        align-items: center;
        justify-content: center;

        border-radius: 11px;

        background:
            var(--gold-bg);

        color:
            var(--gold);
    }


    .profile-section-icon svg {

        width: 18px;
        height: 18px;
    }


    .profile-section-title h2 {

        margin: 0;

        color:
            var(--text-primary);

        font-size: 16px;

        font-weight: 850;
    }


    .profile-section-title p {

        margin: 2px 0 0;

        color:
            var(--text-secondary);

        font-size: 10px;
    }


    .profile-view-all {

        color:
            var(--primary);

        font-size: 11px;

        font-weight: 800;

        text-decoration: none;
    }


    /* =========================================================
       MENU
       ========================================================= */

    .profile-menu {

        display: grid;

        gap: 9px;
    }


    .profile-menu-item {

        display: flex;
        align-items: center;
        justify-content: space-between;

        gap: 12px;

        padding:
            14px
            15px;

        border-radius: 15px;

        background:
            var(--surface);

        border:
            1px solid
            var(--border);

        color:
            var(--text-primary);

        text-decoration: none;

        box-shadow:
            var(--shadow-card);

        transition:
            transform .2s ease,
            border-color .2s ease,
            background .2s ease;
    }


    .profile-menu-item:hover {

        transform:
            translateX(-3px);

        border-color:
            rgba(191,157,87,.38);
    }


    .profile-menu-left {

        display: flex;
        align-items: center;

        gap: 12px;

        min-width: 0;
    }


    .profile-menu-icon {

        width: 42px;
        height: 42px;

        min-width: 42px;

        display: flex;
        align-items: center;
        justify-content: center;

        border-radius: 13px;

        background:
            var(--gold-bg);

        color:
            var(--gold);
    }


    .profile-menu-icon svg {

        width: 20px;
        height: 20px;
    }


    .profile-menu-title {

        color:
            var(--text-primary);

        font-size: 13px;

        font-weight: 800;
    }


    .profile-menu-description {

        margin-top: 2px;

        color:
            var(--text-secondary);

        font-size: 10px;

        line-height: 1.7;
    }


    .profile-menu-arrow {

        color:
            var(--text-secondary);

        flex: 0 0 auto;
    }


    .profile-menu-arrow svg {

        width: 17px;
        height: 17px;

        transform:
            rotate(180deg);
    }


    /* =========================================================
       ADMIN
       ========================================================= */

    .profile-menu-item.admin {

        border-color:
            rgba(6,78,78,.24);

        background:
            linear-gradient(
                135deg,
                rgba(6,78,78,.07),
                rgba(212,175,55,.07)
            );
    }


    .profile-menu-item.admin
    .profile-menu-icon {

        background:
            rgba(6,78,78,.10);

        color:
            var(--primary);
    }


    .profile-menu-item.admin
    .profile-menu-title {

        color:
            var(--primary);
    }


    /* =========================================================
       LOGOUT
       ========================================================= */

    .logout-card {

        width: 100%;

        min-height: 54px;

        display: flex;
        align-items: center;
        justify-content: center;

        gap: 8px;

        margin-top: 3px;

        border-radius: 14px;

        border:
            1px solid
            rgba(220,38,38,.32);

        background:
            rgba(220,38,38,.035);

        color:
            var(--danger);

        font-family:
            'Vazirmatn',
            sans-serif;

        font-size: 13px;

        font-weight: 800;

        cursor: pointer;

        transition:
            .2s ease;
    }


    .logout-card:hover {

        background:
            rgba(220,38,38,.07);
    }


    .logout-card svg {

        width: 18px;
        height: 18px;
    }


    /* =========================================================
       EDIT PROFILE MODAL
       ========================================================= */

    .profile-modal-overlay {

        position: fixed;

        inset: 0;

        z-index: 5000;

        display: none;

        align-items: center;
        justify-content: center;

        padding: 18px;

        background:
            rgba(0,0,0,.48);

        backdrop-filter:
            blur(5px);
    }


    .profile-modal-overlay.active {

        display: flex;
    }


    .profile-modal {

        width: 100%;
        max-width: 440px;

        border-radius: 20px;

        background:
            var(--surface);

        border:
            1px solid
            var(--border);

        box-shadow:
            0 25px 70px
            rgba(0,0,0,.25);

        overflow: hidden;
    }


    .profile-modal-header {

        display: flex;
        align-items: center;
        justify-content: space-between;

        padding:
            16px 18px;

        border-bottom:
            1px solid
            var(--border);
    }


    .profile-modal-header h3 {

        margin: 0;

        color:
            var(--text-primary);

        font-size: 16px;
        font-weight: 850;
    }


    .profile-modal-close {

        width: 34px;
        height: 34px;

        border: 0;

        border-radius: 10px;

        background:
            var(--bg);

        color:
            var(--text-secondary);

        cursor: pointer;
    }


    .profile-modal-body {

        padding: 18px;
    }


    .profile-form-group {

        display: flex;
        flex-direction: column;

        gap: 7px;

        margin-bottom: 14px;
    }


    .profile-form-group label {

        color:
            var(--text-primary);

        font-size: 12px;

        font-weight: 800;
    }


    .profile-form-control {

        width: 100%;

        box-sizing: border-box;

        min-height: 46px;

        padding:
            0 12px;

        border-radius: 11px;

        border:
            1px solid
            var(--border);

        background:
            var(--bg);

        color:
            var(--text-primary);

        font-family:
            'Vazirmatn',
            sans-serif;

        font-size: 13px;

        outline: none;
    }


    .profile-form-control:focus {

        border-color:
            var(--primary);

        box-shadow:
            0 0 0 3px
            rgba(6,78,78,.08);
    }


    .profile-modal-footer {

        display: flex;

        gap: 8px;

        padding:
            12px 18px;

        border-top:
            1px solid
            var(--border);
    }


    .profile-modal-btn {

        flex: 1;

        min-height: 46px;

        border-radius: 11px;

        font-family:
            'Vazirmatn',
            sans-serif;

        font-size: 12px;

        font-weight: 800;

        cursor: pointer;
    }


    .profile-modal-btn.cancel {

        border:
            1px solid
            var(--border);

        background:
            var(--bg);

        color:
            var(--text-secondary);
    }


    .profile-modal-btn.save {

        border: 0;

        background:
            var(--primary);

        color: #fff;
    }


    /* =========================================================
       RESPONSIVE
       ========================================================= */

    @media (max-width: 960px) {
        .profile-page { max-width: 100%; }
        .profile-stats { grid-template-columns: repeat(2,minmax(0,1fr)); }
        .profile-hero-content { flex-wrap: wrap; }
    }


    @media (max-width: 580px) {

        .main-content {

            padding-left: 10px;
            padding-right: 10px;
            padding-bottom: 150px;
        }


        .profile-hero {

            padding:
                18px;

            border-radius:
                20px;
        }


        .profile-hero-content {

            align-items:
                flex-start;
        }


        .profile-edit-btn {

            width: 42px;
            height: 42px;

            min-height: 42px;

            padding: 0;

            justify-content:
                center;
        }


        .profile-edit-btn span {

            display:
                none;
        }


        .profile-name {

            font-size: 22px;
        }


        .profile-phone {

            font-size: 11px;
        }


        .profile-stat {

            padding:
                12px;
        }


        .profile-stat-number {

            font-size: 21px;
        }


        .profile-menu-item {

            padding:
                12px;
        }
    }


    @media (max-width: 380px) {

        .profile-stats {

            grid-template-columns:
                1fr;

        }
    }


    .match-stat-number { font-size: 24px; line-height: 1; }


</style>


<div class="main-content">

    <div class="profile-page">


        <!-- =====================================================
             PROFILE HERO
             ===================================================== -->

        <section class="profile-hero">

            <div class="profile-hero-content">

                <div class="profile-identity">

                    <div class="profile-badge">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M20 21a8 8 0 0 0-16 0"/>
                            <circle
                                cx="12"
                                cy="7"
                                r="4"
                            />
                        </svg>

                        حساب کاربری ملکینو

                    </div>


                    <h1 class="profile-name">

                        <?= htmlspecialchars(
                            $userName,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    </h1>


                    <div class="profile-phone">

                        <?= htmlspecialchars(
                            $userPhone,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    </div>

                </div>


                <button
                    type="button"
                    class="profile-edit-btn"
                    onclick="openProfileEdit()"
                >

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path d="M12 20h9"/>
                        <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L8 18l-4 1 1-4Z"/>
                    </svg>

                    <span>
                        ویرایش نام
                    </span>

                </button>

            </div>

        </section>


        <!-- =====================================================
             STATS
             ===================================================== -->

        <section class="profile-stats">


            <!-- املاک -->

            <a
                href="my-properties.php"
                class="profile-stat"
            >

                <div class="profile-stat-top">

                    <div class="profile-stat-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="m3 11 9-8 9 8"/>
                            <path d="M5 10v10h14V10"/>
                            <path d="M9 20v-6h6v6"/>
                        </svg>

                    </div>


                    <div class="profile-stat-number">

                        <?= (int)$myPropertiesCount ?>

                    </div>

                </div>


                <div class="profile-stat-title">
                    املاک من
                </div>


                <div class="profile-stat-subtitle">
                    مدیریت فایل‌های ثبت‌شده
                </div>

            </a>


            <!-- درخواست‌ها -->

            <a
                href="requests.php"
                class="profile-stat"
            >

                <div class="profile-stat-top">

                    <div class="profile-stat-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M4 4h16v16H4z"/>
                            <path d="M8 9h8"/>
                            <path d="M8 13h6"/>
                        </svg>

                    </div>


                    <div class="profile-stat-number">

                        <?= (int)$myRequestsCount ?>

                    </div>

                </div>


                <div class="profile-stat-title">
                    درخواست‌های من
                </div>


                <div class="profile-stat-subtitle">
                    درخواست‌های ملکی ثبت‌شده
                </div>

            </a>


            <!-- علاقه‌مندی -->

            <a
                href="favorites.php"
                class="profile-stat"
            >

                <div class="profile-stat-top">

                    <div class="profile-stat-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M20.8 8.8a5.5 5.5 0 0 0-7.8-4.6L12 5.4l-1-1.2a5.5 5.5 0 0 0-7.8 4.6c0 4.2 4.5 7.1 8.8 11 8.3-7.2 8.8-10 8.8-11z"/>
                        </svg>

                    </div>


                    <div
                        class="profile-stat-number"
                        id="profileFavoriteCount"
                    >
                        <?= (int)$favoriteCount ?>
                    </div>

                </div>


                <div class="profile-stat-title">
                    ذخیره‌شده‌ها
                </div>


                <div class="profile-stat-subtitle">
                    فایل‌های مورد علاقه
                </div>

            </a>


            <!-- اعلان -->

            <a
                href="notifications.php"
                class="profile-stat"
            >

                <div class="profile-stat-top">

                    <div class="profile-stat-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
                            <path d="M10 21h4"/>
                        </svg>

                    </div>


                    <div
                        class="profile-stat-number"
                        id="profileNotificationCount"
                    >
                        <?= (int)$notificationCount ?>
                    </div>

                </div>


                <div class="profile-stat-title">
                    اعلان‌ها
                </div>


                <div class="profile-stat-subtitle">
                    پیام‌ها و اطلاع‌رسانی‌ها
                </div>

            </a>

            <a href="my-request-matches.php" class="profile-stat">
                <div class="profile-stat-top">
                    <div class="profile-stat-icon">🔎</div>
                    <div class="profile-stat-number match-stat-number" id="profileMatchCount"><?= (int)$matchCount ?></div>
                </div>
                <div class="profile-stat-title">فایل‌های مناسب من</div>
                <div class="profile-stat-subtitle">مشاهده و مدیریت فایل‌های مطابق</div>
            </a>

        </section>


        <!-- =====================================================
             ACTIVITY
             ===================================================== -->

        <section class="profile-section">

            <div class="profile-section-header">

                <div class="profile-section-title">

                    <div class="profile-section-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M3 12h18"/>
                            <path d="M12 3v18"/>
                        </svg>

                    </div>

                    <div>

                        <h2>
                            فعالیت‌های من
                        </h2>

                        <p>
                            دسترسی سریع به امکانات حساب کاربری
                        </p>

                    </div>

                </div>

            </div>


            <div class="profile-menu">


                <!-- املاک من -->

                <a
                    href="my-properties.php"
                    class="profile-menu-item"
                >

                    <div class="profile-menu-left">

                        <div class="profile-menu-icon">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="m3 11 9-8 9 8"/>
                                <path d="M5 10v10h14V10"/>
                                <path d="M9 20v-6h6v6"/>
                            </svg>

                        </div>


                        <div>

                            <div class="profile-menu-title">
                                املاک من
                            </div>

                            <div class="profile-menu-description">
                                مشاهده، مدیریت و پیگیری آگهی‌های شما
                            </div>

                        </div>

                    </div>


                    <div class="profile-menu-arrow">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <polyline points="15 18 9 12 15 6"/>
                        </svg>

                    </div>

                </a>


                <!-- درخواست‌ها -->

                <a
                    href="requests.php"
                    class="profile-menu-item"
                >

                    <div class="profile-menu-left">

                        <div class="profile-menu-icon">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M4 4h16v16H4z"/>
                                <path d="M8 9h8"/>
                                <path d="M8 13h6"/>
                            </svg>

                        </div>


                        <div>

                            <div class="profile-menu-title">
                                درخواست‌های من
                            </div>

                            <div class="profile-menu-description">
                                درخواست‌های خرید، فروش، رهن و اجاره
                            </div>

                        </div>

                    </div>


                    <div class="profile-menu-arrow">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <polyline points="15 18 9 12 15 6"/>
                        </svg>

                    </div>

                </a>


                <!-- علاقه‌مندی‌ها -->

                <a
                    href="favorites.php"
                    class="profile-menu-item"
                >

                    <div class="profile-menu-left">

                        <div class="profile-menu-icon">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M20.8 8.8a5.5 5.5 0 0 0-7.8-4.6L12 5.4l-1-1.2a5.5 5.5 0 0 0-7.8 4.6c0 4.2 4.5 7.1 8.8 11 8.3-7.2 8.8-10 8.8-11z"/>
                            </svg>

                        </div>


                        <div>

                            <div class="profile-menu-title">
                                علاقه‌مندی‌ها
                            </div>

                            <div class="profile-menu-description">
                                فایل‌هایی که برای بررسی بیشتر ذخیره کرده‌اید
                            </div>

                        </div>

                    </div>


                    <div class="profile-menu-arrow">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <polyline points="15 18 9 12 15 6"/>
                        </svg>

                    </div>

                </a>


                <!-- اعلان‌ها -->

                <a
                    href="notifications.php"
                    class="profile-menu-item"
                >

                    <div class="profile-menu-left">

                        <div class="profile-menu-icon">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
                                <path d="M10 21h4"/>
                            </svg>

                        </div>


                        <div>

                            <div class="profile-menu-title">
                                اعلان‌ها
                            </div>

                            <div class="profile-menu-description">
                                اطلاع از وضعیت آگهی‌ها و درخواست‌های شما
                            </div>

                        </div>

                    </div>


                    <div class="profile-menu-arrow">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <polyline points="15 18 9 12 15 6"/>
                        </svg>

                    </div>

                </a>

                

                <!-- به‌روزرسانی پیشنهادها -->

                <button
                    type="button"
                    id="refreshSuggestionsBtn"
                    class="profile-menu-item"
                    onclick="refreshSuggestions()"
                    style="width:100%;font-family:inherit;text-align:right;cursor:pointer;"
                >

                    <div class="profile-menu-left">

                        <div class="profile-menu-icon">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M21 12a9 9 0 1 1-2.64-6.36"/>
                                <polyline points="21 3 21 9 15 9"/>
                            </svg>

                        </div>


                        <div>

                            <div class="profile-menu-title">
                                به‌روزرسانی پیشنهادها
                            </div>

                            <div class="profile-menu-description">
                                محاسبه‌ی مجدد فایل‌های مناسب درخواست‌های شما
                            </div>

                        </div>

                    </div>


                    <div class="profile-menu-arrow" id="refreshSuggestionsArrow">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <polyline points="15 18 9 12 15 6"/>
                        </svg>

                    </div>

                </button>


            </div>

        </section>


        <!-- =====================================================
             SUPPORT
             ===================================================== -->

        <section class="profile-section">

            <div class="profile-section-header">

                <div class="profile-section-title">

                    <div class="profile-section-icon">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        </svg>

                    </div>

                    <div>

                        <h2>
                            خدمات ملکینو
                        </h2>

                        <p>
                            ارتباط و دریافت راهنمایی
                        </p>

                    </div>

                </div>

            </div>


            <div class="profile-menu">


                <a
                    href="support.php"
                    class="profile-menu-item"
                >

                    <div class="profile-menu-left">

                        <div class="profile-menu-icon">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            </svg>

                        </div>


                        <div>

                            <div class="profile-menu-title">
                                پشتیبانی
                            </div>

                            <div class="profile-menu-description">
                                سوال یا مشکلی دارید؟ با ما در ارتباط باشید
                            </div>

                        </div>

                    </div>


                    <div class="profile-menu-arrow">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <polyline points="15 18 9 12 15 6"/>
                        </svg>

                    </div>

                </a>


                <a
                    href="contact.php"
                    class="profile-menu-item"
                >

                    <div class="profile-menu-left">

                        <div class="profile-menu-icon">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-2-2-2V6c0-1.1.9-2 2-2z"/>
                                <polyline points="22,6 12,13 2,6"/>
                            </svg>

                        </div>


                        <div>

                            <div class="profile-menu-title">
                                ارتباط با ما
                            </div>

                            <div class="profile-menu-description">
                                تماس، شبکه‌های اجتماعی و اطلاعات دفتر ملکینو
                            </div>

                        </div>

                    </div>


                    <div class="profile-menu-arrow">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <polyline points="15 18 9 12 15 6"/>
                        </svg>

                    </div>

                </a>


                <?php if (
                    isset($_SESSION['user_role']) &&
                    $_SESSION['user_role'] === 'admin'
                ): ?>

                    <a
                        href="admin-login.php"
                        class="profile-menu-item admin"
                    >

                        <div class="profile-menu-left">

                            <div class="profile-menu-icon">

                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >
                                    <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/>
                                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                                </svg>

                            </div>


                            <div>

                                <div class="profile-menu-title">
                                    پنل مدیریت
                                </div>

                                <div class="profile-menu-description">
                                    مدیریت آگهی‌ها، درخواست‌ها و تنظیمات ملکینو
                                </div>

                            </div>

                        </div>


                        <div class="profile-menu-arrow">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <polyline points="15 18 9 12 15 6"/>
                            </svg>

                        </div>

                    </a>

                <?php endif; ?>

            </div>

        </section>


        <!-- =====================================================
             LOGOUT
             ===================================================== -->

        <!-- =====================================================
             کارتِ مقایسهٔ ملک‌ها → صفحهٔ مستقل مقایسه
             ===================================================== -->

        <section class="profile-section" id="compareSection">

            <a href="compare-page.php" class="profile-menu-item">

                <div class="profile-menu-left">

                    <div class="profile-menu-icon">
                        <span style="font-size:19px;line-height:1;">⚖️</span>
                    </div>

                    <div>

                        <div class="profile-menu-title">
                            مقایسهٔ ملک‌ها
                        </div>

                        <div class="profile-menu-description">
                            <?php if ($compareCount > 0): ?>
                                <?= (int)$compareCount ?> ملک در مقایسه — برای دیدن جدول امتیازها بزن
                            <?php else: ?>
                                ملک‌ها را به مقایسه اضافه کن و اینجا کنار هم ببین
                            <?php endif; ?>
                        </div>

                    </div>

                </div>

                <div style="display:flex;align-items:center;gap:10px;">

                    <span
                        id="compareCardCount"
                        style="min-width:26px;height:26px;padding:0 8px;border-radius:999px;display:<?= $compareCount > 0 ? 'inline-flex' : 'none' ?>;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--primary),#0b5d5b);color:#fff;font-size:11px;font-weight:800;"
                    ><?= (int)$compareCount ?></span>

                    <div class="profile-menu-arrow">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <polyline points="15 18 9 12 15 6"/>
                        </svg>

                    </div>

                </div>

            </a>

        </section>


        <?php if (!empty($channelUrl)): ?>
        <!-- =====================================================
             ورود به کانال تلگرام ملکینو
             ===================================================== -->

        <section class="profile-section">

            <a
                href="<?= htmlspecialchars($channelUrl, ENT_QUOTES, 'UTF-8') ?>"
                class="profile-menu-item"
                target="_blank"
                rel="noopener"
            >

                <div class="profile-menu-left">

                    <div class="profile-menu-icon">
                        <span style="font-size:19px;line-height:1;">📢</span>
                    </div>

                    <div>

                        <div class="profile-menu-title">
                            کانال تلگرام ملکینو
                        </div>

                        <div class="profile-menu-description">
                            جدیدترین آگهی‌ها را در کانال ببین و عضو شو
                        </div>

                    </div>

                </div>

                <div class="profile-menu-arrow">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>

                </div>

            </a>

        </section>
        <?php endif; ?>


        <section class="profile-section">

            <button
                class="logout-card"
                type="button"
                onclick="logoutUser()"
            >

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                >
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line
                        x1="21"
                        y1="12"
                        x2="9"
                        y2="12"
                    />
                </svg>

                خروج از حساب کاربری

            </button>

        </section>

    </div>

</div>


<!-- =========================================================
     EDIT PROFILE MODAL
     ========================================================= -->

<div
    class="profile-modal-overlay"
    id="profileEditModal"
>

    <div
        class="profile-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="profileEditTitle"
    >

        <div class="profile-modal-header">

            <h3 id="profileEditTitle">
                ویرایش پروفایل
            </h3>


            <button
                type="button"
                class="profile-modal-close"
                onclick="closeProfileEdit()"
                aria-label="بستن"
            >
                ✕
            </button>

        </div>


        <div class="profile-modal-body">

            <div class="profile-form-group">

                <label for="editProfileName">
                    نام کاربری
                </label>

                <input
                    type="text"
                    id="editProfileName"
                    class="profile-form-control"
                    value="<?= htmlspecialchars(
                        $userName,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    autocomplete="name"
                >

            </div>


            <div class="profile-form-group">

                <label>
                    شماره موبایل
                </label>

                <input
                    type="text"
                    class="profile-form-control"
                    value="<?= htmlspecialchars(
                        $userPhone,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    dir="ltr"
                    readonly
                >

            </div>

        </div>


        <div class="profile-modal-footer">

            <button
                type="button"
                class="profile-modal-btn cancel"
                onclick="closeProfileEdit()"
            >
                انصراف
            </button>


            <button
                type="button"
                class="profile-modal-btn save"
                onclick="saveProfileName()"
            >
                ذخیره تغییرات
            </button>

        </div>

    </div>

</div>


<!-- =========================================================
     TOAST
     ========================================================= -->

<div
    id="profileToast"
    style="position:fixed;bottom:26px;right:50%;transform:translateX(50%) translateY(20px);background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:12px 18px;font-size:13px;color:var(--text-primary);box-shadow:0 12px 30px rgba(0,0,0,.18);opacity:0;pointer-events:none;transition:opacity .3s ease,transform .3s ease;z-index:9999;max-width:calc(100vw - 40px);text-align:center;"
></div>


<script>

    /* =========================================================
       PROFILE
       ========================================================= */

    function openProfileEdit() {

        const modal =
            document.getElementById(
                'profileEditModal'
            );


        if (modal) {

            modal.classList.add(
                'active'
            );


            setTimeout(
                function() {

                    const input =
                        document.getElementById(
                            'editProfileName'
                        );

                    if (input) {

                        input.focus();

                        input.select();

                    }

                },
                80
            );
        }
    }


    function closeProfileEdit() {

        const modal =
            document.getElementById(
                'profileEditModal'
            );


        if (modal) {

            modal.classList.remove(
                'active'
            );

        }

    }


    function saveProfileName() {

        const input =
            document.getElementById(
                'editProfileName'
            );


        if (!input) {
            return;
        }


        const name =
            input.value.trim();


        if (!name) {

            alert(
                'لطفاً نام خود را وارد کنید.'
            );

            return;
        }


        /*
         * فعلاً نام در session جاری تغییر می‌کند.
         * برای ذخیره دائمی در دیتابیس می‌توانیم
         * مرحله بعد endpoint اختصاصی بسازیم.
         */

        fetch(
            'update-profile.php',
            {
                method: 'POST',

                headers: {
                    'Content-Type':
                        'application/json'
                },

                body:
                    JSON.stringify({
                        name: name
                    })
            }
        )
        .then(
            response => {

                if (!response.ok) {

                    throw new Error(
                        'خطا در ذخیره نام'
                    );

                }

                return response.json();

            }
        )
        .then(
            result => {

                if (
                    !result ||
                    result.success !== true
                ) {

                    throw new Error(
                        result?.message ||
                        'ذخیره نام انجام نشد.'
                    );

                }


                alert(
                    '✅ نام شما با موفقیت به‌روزرسانی شد.'
                );


                window.location.reload();

            }
        )
        .catch(
            error => {

                console.error(
                    error
                );


                alert(
                    '❌ ' +
                    (
                        error.message ||
                        'خطا در ذخیره اطلاعات'
                    )
                );

            }
        );

    }


    /* =========================================================
       LOGOUT
       ========================================================= */

    function logoutUser() {

        if (
            !confirm(
                'آیا از خروج از حساب کاربری خود مطمئن هستید؟'
            )
        ) {
            return;
        }


        /*
         * در صورت وجود logout.php
         * مستقیماً به آن هدایت می‌شویم.
         */

        window.location.href =
            'logout.php';
    }


    /* =========================================================
       MODAL CLOSE
       ========================================================= */

    const profileEditModal =
        document.getElementById(
            'profileEditModal'
        );


    if (profileEditModal) {

        profileEditModal.addEventListener(
            'click',
            function(event) {

                if (
                    event.target ===
                    profileEditModal
                ) {

                    closeProfileEdit();

                }

            }
        );

    }


    document.addEventListener(
        'keydown',
        function(event) {

            if (
                event.key ===
                'Escape'
            ) {

                closeProfileEdit();

            }

        }
    );


    /* =========================================================
       NOTIFICATION BADGE
       ========================================================= */

    document.addEventListener(
        'DOMContentLoaded',
        function() {

            if (
                typeof updateBadge ===
                'function'
            ) {

                updateBadge();

            }


            /*
             * اگر سیستم علاقه‌مندی فعلی
             * در footer یا صفحه‌های دیگر
             * localStorage استفاده کند،
             * عدد را از آن می‌خوانیم.
             */

            try {

                const favorites =
                    JSON.parse(
                        localStorage.getItem(
                            'melkino_favorites'
                        ) ||
                        '[]'
                    );


                if (
                    Array.isArray(
                        favorites
                    )
                ) {

                    const count =
                        document.getElementById(
                            'profileFavoriteCount'
                        );


                    if (count) {

                        count.textContent =
                            favorites.length;

                    }

                }

            } catch (error) {

                console.warn(
                    'Favorite count error:',
                    error
                );

            }

        }
    );


    /* =========================================================
       TOAST
       ========================================================= */

    function showProfileToast(message, ms) {

        const toast =
            document.getElementById(
                'profileToast'
            );

        if (!toast) {
            return;
        }

        toast.textContent =
            message;

        toast.style.opacity =
            '1';

        toast.style.transform =
            'translateX(50%) translateY(0)';

        clearTimeout(
            window.__profileToastTimer
        );

        window.__profileToastTimer =
            setTimeout(
                function () {

                    toast.style.opacity =
                        '0';

                    toast.style.transform =
                        'translateX(50%) translateY(20px)';

                },
                ms || 3500
            );
    }


    /* =========================================================
       REFRESH SUGGESTIONS
       محاسبه‌ی مجدد فایل‌های مناسب همه‌ی درخواست‌های کاربر
       ========================================================= */

    async function refreshSuggestions() {

        const btn =
            document.getElementById(
                'refreshSuggestionsBtn'
            );

        const arrow =
            document.getElementById(
                'refreshSuggestionsArrow'
            );

        const arrowHtml =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
            '<polyline points="15 18 9 12 15 6"/>' +
            '</svg>';

        if (btn) {
            btn.disabled = true;
        }

        if (arrow) {
            arrow.innerHTML =
                '<span style="font-size:18px;">⏳</span>';
        }

        showProfileToast(
            '⏳ در حال به‌روزرسانی پیشنهادها...',
            90000
        );

        try {

            const tid =
                localStorage.getItem(
                    'melkino_telegram_id'
                ) ||
                sessionStorage.getItem(
                    'reg_telegram_id'
                ) ||
                '';

            const response =
                await fetch(
                    'my-request-matches.php?action=refresh_all&telegram_id=' +
                    encodeURIComponent(tid),
                    { cache: 'no-store' }
                );

            const data =
                await response.json();

            if (data && data.success) {

                const matchCount =
                    document.getElementById(
                        'profileMatchCount'
                    );

                if (
                    matchCount &&
                    typeof data.total_matches === 'number'
                ) {
                    matchCount.textContent =
                        data.total_matches;
                }

                showProfileToast(
                    '✅ ' +
                    (
                        data.message ||
                        'پیشنهادها به‌روزرسانی شد.'
                    )
                );

            } else {

                showProfileToast(
                    '❌ ' +
                    (
                        (data && data.message) ||
                        'به‌روزرسانی ناموفق بود.'
                    )
                );

            }

        } catch (error) {

            showProfileToast(
                '❌ خطا در ارتباط با سرور.'
            );

        }

        if (btn) {
            btn.disabled = false;
        }

        if (arrow) {
            arrow.innerHTML = arrowHtml;
        }
    }


    document.addEventListener(
        'DOMContentLoaded',
        function () {

            /*
             * مدیریتِ کاملِ مقایسه به صفحهٔ مستقلِ «compare-page.php»
             * منتقل شده است؛ اینجا فقط شمارندهٔ کارت تا حد امکان
             * تازه نگه داشته می‌شود.
             */

            var compareCardCount = document.getElementById('compareCardCount');
            if (compareCardCount) {
                fetch('compare.php?action=count', { cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.success) return;
                        var n = Number(data.count) || 0;
                        compareCardCount.textContent = n;
                        compareCardCount.style.display = n > 0 ? 'inline-flex' : 'none';
                    })
                    .catch(function () { /* عدد کارت اختیاری است */ });
            }
        }
    );

</script>


<?php
require_once __DIR__ . '/footer.php';
?>;
?>