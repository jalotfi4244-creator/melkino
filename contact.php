<?php
require_once __DIR__ . '/header.php';

// ==============================================
// اطلاعاتِ تماسِ ذخیره‌شده روی سرور
// (پیش از این فقط در localStorage بود و بازدیدکنندگان آن را نمی‌دیدند)
// ==============================================
$melkinoServerContact = null;

try {

    if (($pdo instanceof PDO) && function_exists('dbSettingGet')) {

        $melkinoServerContact =
            dbSettingGet($pdo, 'contact', 'info', null);
    }

} catch (Throwable $e) {

    error_log(
        'contact.php: خطا در خواندن اطلاعات تماس — ' . $e->getMessage()
    );

    $melkinoServerContact = null;
}

// ==============================================
// پیدا کردن مسیر لوگو (هماهنگ با هدر)
// ==============================================
$logoPath = 'uploads/onboarding-logo.png';
$logoMetaFile = __DIR__ . '/uploads/onboarding-logo.json';

if (file_exists($logoMetaFile)) {
    $meta = json_decode(file_get_contents($logoMetaFile), true);
    if (!empty($meta['url'])) {
        $logoPath = $meta['url'];
    }
}

if (!file_exists($logoPath)) {
    $logoFiles = glob(__DIR__ . '/uploads/onboarding-logo.*');
    if (!empty($logoFiles)) {
        $logoPath = 'uploads/' . basename($logoFiles[0]);
    }
}
?>

<style>
    /* =========================================================
       MELKINO - CONTACT PAGE
       ========================================================= */

    .main-content {
        flex: 1;
        overflow-y: auto;
        padding: 0 var(--space-3) calc(var(--space-3) + 20px);
        background:
            radial-gradient(circle at top right, rgba(201, 166, 95, 0.08), transparent 30%),
            var(--bg);
    }

    .contact-page {
        max-width: 1100px;
        margin: 0 auto;
        width: 100%;
    }

    /* =========================================================
       HERO (با لوگوی بزرگ در سمت راست)
       ========================================================= */

    .contact-hero {
        position: relative;
        overflow: hidden;
        margin-bottom: var(--space-3);
        padding: 20px 24px;
        border-radius: 24px;
        background:
            linear-gradient(
                135deg,
                rgba(18, 70, 65, 0.98),
                rgba(10, 42, 39, 0.98)
            );
        color: #fff;
        box-shadow: 0 14px 35px rgba(0, 0, 0, 0.14);
        border: 1px solid rgba(255,255,255,0.08);
        min-height: 200px;
    }

    .contact-hero::before,
    .contact-hero::after {
        content: "";
        position: absolute;
        border-radius: 50%;
        pointer-events: none;
    }

    .contact-hero::before {
        width: 230px;
        height: 230px;
        left: -90px;
        top: -125px;
        background: rgba(214, 181, 106, 0.09);
    }

    .contact-hero::after {
        width: 270px;
        height: 270px;
        right: -125px;
        bottom: -175px;
        background: rgba(255,255,255,0.045);
    }

    .contact-hero-content {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 30px;
        min-height: 180px;
    }

    /* ===== سمت چپ: نوشته‌ها ===== */
    .hero-left {
        flex: 1;
        min-width: 0;
    }

    .hero-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 12px;
        border-radius: 999px;
        background: rgba(212, 177, 102, 0.12);
        border: 1px solid rgba(212, 177, 102, 0.24);
        color: #e8cd93;
        font-size: 12px;
        font-weight: 700;
        margin-bottom: 12px;
    }

    .hero-badge svg {
        width: 15px;
        height: 15px;
        flex: 0 0 auto;
    }

    .contact-hero h1 {
        margin: 0 0 9px;
        font-size: clamp(24px, 4vw, 34px);
        line-height: 1.4;
        font-weight: 850;
        color: #fff;
    }

    .contact-hero p {
        margin: 0;
        max-width: 600px;
        color: rgba(255,255,255,0.78);
        line-height: 1.95;
        font-size: 14px;
    }

    /* ===== سمت راست: لوگوی بزرگ ===== */
    .hero-right {
        flex: 0 0 40%;
        max-width: 45%;
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        min-height: 160px;
    }

    .hero-logo-wrapper {
        width: 100%;
        height: 100%;
        min-height: 140px;
        max-height: 200px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255,255,255,0.06);
        border-radius: 20px;
        border: 1px solid rgba(255,255,255,0.08);
        backdrop-filter: blur(4px);
        padding: 12px;
        overflow: hidden;
    }

    .hero-logo-wrapper img {
        width: 100%;
        height: 100%;
        max-height: 180px;
        object-fit: contain;
        border-radius: 12px;
    }

    /* =========================================================
       SECTION
       ========================================================= */

    .contact-section {
        margin-bottom: var(--space-3);
    }

    .section-heading {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 14px;
    }

    .section-heading-main {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .section-heading-icon {
        width: 40px;
        height: 40px;
        border-radius: 13px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--gold-bg);
        color: var(--gold);
        flex: 0 0 auto;
    }

    .section-heading-icon svg {
        width: 20px;
        height: 20px;
    }

    .section-heading h2 {
        margin: 0;
        color: var(--text-primary);
        font-size: 18px;
        font-weight: 800;
    }

    .section-heading p {
        margin: 3px 0 0;
        font-size: 12px;
        color: var(--text-secondary);
    }

    /* =========================================================
       QUICK CONTACT CARDS
       ========================================================= */

    .quick-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }

    .quick-card {
        position: relative;
        min-height: 145px;
        padding: 17px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 14px;
        border: 1px solid var(--border);
        border-radius: 19px;
        background: var(--surface);
        box-shadow: var(--shadow-card);
        text-decoration: none;
        color: inherit;
        overflow: hidden;
        transition:
            transform .22s ease,
            border-color .22s ease,
            box-shadow .22s ease;
    }

    .quick-card::after {
        content: "";
        position: absolute;
        width: 95px;
        height: 95px;
        border-radius: 50%;
        top: -42px;
        left: -40px;
        background: var(--gold-bg);
        opacity: .45;
        pointer-events: none;
    }

    .quick-card:hover {
        transform: translateY(-4px);
        border-color: rgba(191, 157, 87, 0.42);
        box-shadow: 0 12px 28px rgba(0,0,0,.10);
    }

    .quick-card:active {
        transform: scale(.98);
    }

    .quick-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
    }

    .quick-icon {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--gold-bg);
        color: var(--gold);
        position: relative;
        z-index: 2;
    }

    .quick-icon svg {
        width: 21px;
        height: 21px;
    }

    .quick-arrow {
        color: var(--text-secondary);
        opacity: .65;
        position: relative;
        z-index: 2;
    }

    .quick-arrow svg {
        width: 17px;
        height: 17px;
        transform: rotate(180deg);
    }

    .quick-card h3 {
        margin: 0 0 4px;
        font-size: 14px;
        font-weight: 800;
        color: var(--text-primary);
        position: relative;
        z-index: 2;
    }

    .quick-card p {
        margin: 0;
        color: var(--text-secondary);
        font-size: 12px;
        line-height: 1.7;
        position: relative;
        z-index: 2;
        word-break: break-word;
    }

    /* =========================================================
       OFFICE
       ========================================================= */

    .contact-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 21px;
        padding: 20px;
        box-shadow: var(--shadow-card);
    }

    .office-layout {
        display: grid;
        grid-template-columns: minmax(0, .85fr) minmax(0, 1.15fr);
        gap: 14px;
    }

    .office-info {
        display: flex;
        flex-direction: column;
        gap: 0;
    }

    .office-row {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 0;
        border-bottom: 1px solid var(--border);
    }

    .office-row:first-child {
        padding-top: 0;
    }

    .office-row:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }

    .office-icon {
        width: 39px;
        height: 39px;
        min-width: 39px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--gold-bg);
        color: var(--gold);
    }

    .office-icon svg {
        width: 18px;
        height: 18px;
    }

    .office-content {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .office-content strong {
        color: var(--text-primary);
        font-size: 13px;
        font-weight: 800;
    }

    .office-content span,
    .office-content a {
        color: var(--text-secondary);
        font-size: 12px;
        line-height: 1.8;
        text-decoration: none;
        word-break: break-word;
    }

    .office-content a:hover {
        color: var(--gold);
    }

    /* =========================================================
       MAP
       ========================================================= */

    .map-box {
        min-height: 320px;
        position: relative;
        overflow: hidden;
        border-radius: 18px;
        border: 1px solid var(--border);
        background: var(--bg-secondary);
    }

    #officeMapLink {
        display: block;
        width: 100%;
    }

    #officeMapImage {
        width: 100%;
        min-height: 320px;
        max-height: 460px;
        object-fit: cover;
        display: block;
        cursor: zoom-in;
    }

    .map-placeholder {
        min-height: 320px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 30px;
        text-align: center;
        color: var(--text-secondary);
    }

    .map-placeholder-icon {
        width: 58px;
        height: 58px;
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--gold-bg);
        color: var(--gold);
    }

    .map-placeholder-icon svg {
        width: 26px;
        height: 26px;
    }

    .map-placeholder strong {
        color: var(--text-primary);
        font-size: 14px;
    }

    .map-placeholder span {
        font-size: 12px;
        max-width: 310px;
        line-height: 1.9;
    }

    .map-overlay {
        position: absolute;
        right: 12px;
        bottom: 12px;
        z-index: 3;
        display: none;
    }

    .map-direction-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        border: 1px solid rgba(0,0,0,.08);
        border-radius: 11px;
        background: rgba(255,255,255,.94);
        color: #133e39;
        padding: 9px 12px;
        text-decoration: none;
        font-size: 11px;
        font-weight: 800;
        box-shadow: 0 7px 18px rgba(0,0,0,.14);
        backdrop-filter: blur(8px);
    }

    .map-direction-btn svg {
        width: 16px;
        height: 16px;
    }

    /* =========================================================
       SOCIAL PREMIUM
       ========================================================= */

    .social-premium-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }

    .social-premium-card {
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 20px;
        min-height: 135px;
        border-radius: 20px;
        text-decoration: none;
        color: #fff;
        transition:
            transform .22s ease,
            box-shadow .22s ease;
    }

    .social-premium-card::before {
        content: "";
        position: absolute;
        width: 180px;
        height: 180px;
        border-radius: 50%;
        left: -85px;
        bottom: -110px;
        background: rgba(255,255,255,.08);
    }

    .social-premium-card::after {
        content: "";
        position: absolute;
        width: 90px;
        height: 90px;
        border-radius: 50%;
        right: -30px;
        top: -30px;
        background: rgba(255,255,255,.04);
    }

    .telegram-card {
        background: linear-gradient(135deg, #174d46, #0c3531);
        box-shadow: 0 12px 30px rgba(13, 57, 52, .20);
    }

    .instagram-card {
        background: linear-gradient(135deg, #4b3d32, #231e1a);
        box-shadow: 0 12px 30px rgba(40, 34, 28, .20);
    }

    .social-premium-card:hover {
        transform: translateY(-4px);
    }

    .social-premium-icon {
        position: relative;
        z-index: 2;
        width: 58px;
        height: 58px;
        min-width: 58px;
        border-radius: 17px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255,255,255,.11);
        border: 1px solid rgba(255,255,255,.12);
        backdrop-filter: blur(8px);
    }

    .social-premium-icon svg {
        width: 28px;
        height: 28px;
    }

    .social-premium-content {
        position: relative;
        z-index: 2;
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
    }

    .social-premium-label {
        font-size: 10px;
        color: rgba(255,255,255,.61);
        margin-bottom: 3px;
    }

    .social-premium-content strong {
        font-size: 19px;
        font-weight: 850;
        margin-bottom: 3px;
    }

    .social-premium-description {
        font-size: 11px;
        color: rgba(255,255,255,.72);
        line-height: 1.8;
    }

    .social-premium-arrow {
        position: relative;
        z-index: 2;
        width: 35px;
        height: 35px;
        min-width: 35px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: rgba(255,255,255,.08);
    }

    .social-premium-arrow svg {
        width: 17px;
        height: 17px;
        transform: rotate(180deg);
    }

    /* =========================================================
       TOAST
       ========================================================= */

    .contact-toast {
        position: fixed;
        z-index: 9999;
        right: 20px;
        bottom: 20px;
        width: min(360px, calc(100vw - 40px));
        padding: 14px 16px;
        border-radius: 15px;
        background: var(--surface);
        border: 1px solid var(--border);
        box-shadow: 0 14px 35px rgba(0,0,0,.18);
        display: flex;
        align-items: flex-start;
        gap: 10px;
        transform: translateY(130%);
        opacity: 0;
        pointer-events: none;
        transition: .3s ease;
    }

    .contact-toast.show {
        transform: translateY(0);
        opacity: 1;
    }

    .toast-icon {
        width: 35px;
        height: 35px;
        min-width: 35px;
        border-radius: 11px;
        background: var(--gold-bg);
        color: var(--gold);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .toast-icon svg {
        width: 18px;
        height: 18px;
    }

    .toast-content strong {
        display: block;
        margin-bottom: 2px;
        color: var(--text-primary);
        font-size: 13px;
    }

    .toast-content span {
        color: var(--text-secondary);
        font-size: 11px;
        line-height: 1.7;
    }

    /* =========================================================
       RESPONSIVE
       ========================================================= */

    @media (max-width: 980px) {

        .quick-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .office-layout {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {

        .contact-hero-content {
            flex-direction: column;
            gap: 20px;
        }

        .hero-left {
            width: 100%;
            order: 2;
        }

        .hero-right {
            flex: 0 0 auto;
            max-width: 80%;
            width: 100%;
            min-height: 100px;
            order: 1;
        }

        .hero-logo-wrapper {
            min-height: 100px;
            max-height: 130px;
            padding: 8px;
        }

        .hero-logo-wrapper img {
            max-height: 110px;
        }

        .contact-hero h1 {
            font-size: 22px;
        }

        .contact-hero p {
            font-size: 13px;
        }
    }

    @media (max-width: 650px) {

        .main-content {
            padding-left: 12px;
            padding-right: 12px;
        }

        .contact-hero {
            padding: 18px 16px;
            border-radius: 20px;
            min-height: auto;
        }

        .hero-right {
            max-width: 100%;
            min-height: 80px;
        }

        .hero-logo-wrapper {
            min-height: 80px;
            max-height: 100px;
        }

        .hero-logo-wrapper img {
            max-height: 85px;
        }

        .quick-grid {
            grid-template-columns: 1fr 1fr;
            gap: 9px;
        }

        .quick-card {
            min-height: 130px;
            padding: 13px;
            border-radius: 16px;
        }

        .quick-icon {
            width: 39px;
            height: 39px;
            border-radius: 12px;
        }

        .quick-card h3 {
            font-size: 12px;
        }

        .quick-card p {
            font-size: 10px;
        }

        .contact-card {
            padding: 16px;
            border-radius: 18px;
        }

        .social-premium-grid {
            grid-template-columns: 1fr;
        }

        .social-premium-card {
            min-height: 115px;
            padding: 16px;
        }

        .social-premium-icon {
            width: 52px;
            height: 52px;
            min-width: 52px;
        }

        .social-premium-content strong {
            font-size: 17px;
        }

        .map-box,
        .map-placeholder,
        #officeMapImage {
            min-height: 260px;
        }

        .contact-toast {
            right: 12px;
            bottom: 12px;
            width: calc(100vw - 24px);
        }
    }

    @media (max-width: 380px) {

        .quick-grid {
            grid-template-columns: 1fr;
        }

        .social-premium-card {
            gap: 11px;
        }

        .hero-right {
            min-height: 60px;
        }

        .hero-logo-wrapper {
            min-height: 60px;
            max-height: 70px;
            padding: 4px;
        }

        .hero-logo-wrapper img {
            max-height: 60px;
        }
    }
</style>


<div class="main-content" id="mainContent">

    <div class="contact-page">

        <!-- =====================================================
             HERO (لوگوی بزرگ سمت راست، نوشته‌ها سمت چپ)
             ===================================================== -->

        <section class="contact-hero">

            <div class="contact-hero-content">

                <!-- ===== سمت راست: لوگوی بزرگ (اولین آیتم) ===== -->
                <div class="hero-right">
                    <div class="hero-logo-wrapper">
                        <img src="<?= htmlspecialchars($logoPath) ?>" alt="ملکینو">
                    </div>
                </div>

                <!-- ===== سمت چپ: نوشته‌ها (دومین آیتم) ===== -->
                <div class="hero-left">

                    <div class="hero-badge">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2h-3l-4 4-1.5-4H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2z"/>
                            <path d="M8 8h8"/>
                            <path d="M8 12h5"/>
                        </svg>

                        <span id="heroAgencyName">املاک ملکینو شاهرود</span>

                    </div>

                    <h1>
                        ملکینو؛ انتخابی فراتر از یک ملک
                    </h1>

                    <p>
                        برای خرید، فروش، رهن، اجاره یا ثبت درخواست ملک،
                        کارشناسان ملکینو در کنار شما هستند تا بهترین انتخاب را داشته باشید.
                    </p>

                </div>

            </div>

        </section>


        <!-- =====================================================
             QUICK CONTACT
             ===================================================== -->

        <section class="contact-section">

            <div class="section-heading">

                <div class="section-heading-main">

                    <div class="section-heading-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="9"/>
                            <path d="M12 7v5l3 2"/>
                        </svg>
                    </div>

                    <div>
                        <h2>راه‌های ارتباط سریع</h2>
                        <p>سریع‌ترین راه برای ارتباط با کارشناسان ملکینو</p>
                    </div>

                </div>

            </div>


            <div class="quick-grid">

                <!-- PHONE -->

                <a
                    class="quick-card"
                    id="quickPhone"
                    href="#"
                >

                    <div class="quick-top">

                        <div class="quick-icon">

                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.8 19.8 0 0 1 11.2 18.9a19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.72c.12.9.34 1.78.66 2.62a2 2 0 0 1-.45 2.11L8.09 9.72a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.84.32 1.72.54 2.62.66A2 2 0 0 1 22 16.92z"/>
                            </svg>

                        </div>

                        <div class="quick-arrow">

                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 18l6-6-6-6"/>
                            </svg>

                        </div>

                    </div>


                    <div>

                        <h3>تماس تلفنی</h3>

                        <p id="quickPhoneText">
                            در حال بارگذاری...
                        </p>

                    </div>

                </a>


                <!-- WHATSAPP -->

                <a
                    class="quick-card"
                    id="quickWhatsapp"
                    href="#"
                    target="_blank"
                    rel="noopener noreferrer"
                    style="display:none;"
                >

                    <div class="quick-top">

                        <div class="quick-icon">

                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 11.5a8 8 0 0 1-11.8 7l-4.2 1 1-4A8 8 0 1 1 20 11.5z"/>
                                <path d="M8.5 8.5c.2-.4.4-.4.7-.4h.5c.2 0 .4.1.5.4l.6 1.4c.1.2.1.4-.1.6l-.5.6c-.1.2-.1.3 0 .5.3.6.8 1.1 1.4 1.4.2.1.3.1.5 0l.6-.5c.2-.2.4-.2.6-.1l1.4.6c.3.1.4.3.4.5v.5c0 .3 0 .5-.4.7-.4.2-1.2.4-1.6.2-2.1-.9-3.6-2.4-4.5-4.5-.2-.4 0-1.2.2-1.6z"/>
                            </svg>

                        </div>

                        <div class="quick-arrow">

                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 18l6-6-6-6"/>
                            </svg>

                        </div>

                    </div>


                    <div>

                        <h3>واتساپ</h3>

                        <p>
                            شروع گفتگو با کارشناس
                        </p>

                    </div>

                </a>


                <!-- TELEGRAM -->

                <a
                    class="quick-card"
                    id="quickTelegram"
                    href="#"
                    target="_blank"
                    rel="noopener noreferrer"
                    style="display:none;"
                >

                    <div class="quick-top">

                        <div class="quick-icon">

                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M21.5 4.5L18 20c-.2.9-.8 1.1-1.5.7l-4.5-3.5-2.3 2.2c-.3.3-.6.5-1.1.5l.4-4.6 8.2-7.4c.4-.4-.1-.6-.6-.2L6.5 13.9 2.1 12.5c-1-.3-1-1 .2-1.4L20.5 3.9c.9-.3 1.5.2 1 0.6z"/>
                            </svg>

                        </div>

                        <div class="quick-arrow">

                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 18l6-6-6-6"/>
                            </svg>

                        </div>

                    </div>


                    <div>

                        <h3>تلگرام</h3>

                        <p>
                            ارتباط مستقیم در تلگرام
                        </p>

                    </div>

                </a>


                <!-- INSTAGRAM -->

                <a
                    class="quick-card"
                    id="quickInstagram"
                    href="#"
                    target="_blank"
                    rel="noopener noreferrer"
                    style="display:none;"
                >

                    <div class="quick-top">

                        <div class="quick-icon">

                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <rect x="3" y="3" width="18" height="18" rx="5"/>
                                <circle cx="12" cy="12" r="4"/>
                                <circle cx="17.5" cy="6.5" r=".8" fill="currentColor" stroke="none"/>
                            </svg>

                        </div>

                        <div class="quick-arrow">

                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 18l6-6-6-6"/>
                            </svg>

                        </div>

                    </div>


                    <div>

                        <h3>اینستاگرام</h3>

                        <p>
                            ما را در اینستاگرام دنبال کنید
                        </p>

                    </div>

                </a>

            </div>

        </section>


        <!-- =====================================================
             OFFICE
             ===================================================== -->

        <section class="contact-section">

            <div class="section-heading">

                <div class="section-heading-main">

                    <div class="section-heading-icon">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 21h18"/>
                            <path d="M5 21V7l7-4 7 4v14"/>
                            <path d="M9 21v-8h6v8"/>
                            <path d="M8 9h.01"/>
                            <path d="M12 9h.01"/>
                            <path d="M16 9h.01"/>
                        </svg>

                    </div>

                    <div>

                        <h2>
                            دفتر املاک ملکینو
                        </h2>

                        <p>
                            مشتاق دیدار شما در دفتر ملکینو هستیم
                        </p>

                    </div>

                </div>

            </div>


            <div class="office-layout">

                <div class="contact-card office-info">

                    <!-- ADDRESS -->

                    <div class="office-row">

                        <div class="office-icon">

                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 21s7-6.1 7-12a7 7 0 1 0-14 0c0 5.9 7 12 7 12z"/>
                                <circle cx="12" cy="9" r="2.5"/>
                            </svg>

                        </div>


                        <div class="office-content">

                            <strong>
                                آدرس دفتر
                            </strong>

                            <span id="officeAddress">
                                ثبت نشده
                            </span>

                        </div>

                    </div>


                    <!-- PHONE -->

                    <div class="office-row">

                        <div class="office-icon">

                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.8 19.8 0 0 1 11.2 18.9a19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.72c.12.9.34 1.78.66 2.62a2 2 0 0 1-.45 2.11L8.09 9.72a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.84.32 1.72.54 2.62.66A2 2 0 0 1 22 16.92z"/>
                            </svg>

                        </div>


                        <div class="office-content">

                            <strong>
                                شماره تماس
                            </strong>

                            <a
                                href="#"
                                id="officePhone"
                            >
                                ثبت نشده
                            </a>

                        </div>

                    </div>


                    <!-- HOURS -->

                    <div class="office-row">

                        <div class="office-icon">

                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="16" rx="2"/>
                                <path d="M16 2v4"/>
                                <path d="M8 2v4"/>
                                <path d="M3 9h18"/>
                            </svg>

                        </div>


                        <div class="office-content">

                            <strong>
                                ساعت کاری
                            </strong>

                            <span id="officeHours">
                                ثبت نشده
                            </span>

                        </div>

                    </div>


                    <!-- EMAIL -->

                    <div class="office-row">

                        <div class="office-icon">

                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="16" rx="2"/>
                                <path d="M3 7l9 6 9-6"/>
                            </svg>

                        </div>


                        <div class="office-content">

                            <strong>
                                ایمیل پشتیبانی
                            </strong>

                            <a
                                href="#"
                                id="officeEmail"
                            >
                                ثبت نشده
                            </a>

                        </div>

                    </div>

                </div>


                <!-- MAP IMAGE (admin uploads a screenshot of the map) -->

                <div class="map-box">

                    <div
                        class="map-placeholder"
                        id="mapPlaceholder"
                    >

                        <div class="map-placeholder-icon">

                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M12 21s7-6.1 7-12a7 7 0 1 0-14 0c0 5.9 7 12 7 12z"/>
                                <circle cx="12" cy="9" r="2.5"/>
                            </svg>

                        </div>


                        <strong>
                            موقعیت دفتر ملکینو
                        </strong>


                        <span>
                            تصویر نقشه دفتر هنوز ثبت نشده است.
                        </span>

                    </div>


                    <a
                        id="officeMapLink"
                        href="#"
                        target="_blank"
                        rel="noopener noreferrer"
                        style="display:none;"
                        title="برای مشاهده بزرگ‌تر کلیک کنید"
                    >
                        <img
                            id="officeMapImage"
                            src=""
                            alt="موقعیت دفتر ملکینو روی نقشه"
                        >
                    </a>

                </div>

            </div>

        </section>


        <!-- =====================================================
             SOCIAL MEDIA
             ===================================================== -->

        <section class="contact-section">

            <div class="section-heading">

                <div class="section-heading-main">

                    <div class="section-heading-icon">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                            <polyline points="22,6 12,13 2,6"/>
                        </svg>

                    </div>

                    <div>

                        <h2>
                            ملکینو را دنبال کنید
                        </h2>

                        <p>
                            آخرین فایل‌ها، اخبار و فرصت‌های ملکی
                        </p>

                    </div>

                </div>

            </div>


            <div class="social-premium-grid">

                <!-- TELEGRAM -->

                <a
                    href="#"
                    id="telegramChannelLink"
                    class="social-premium-card telegram-card"
                    target="_blank"
                    rel="noopener noreferrer"
                >

                    <div class="social-premium-icon">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M21.5 4.5L18 20c-.2.9-.8 1.1-1.5.7l-4.5-3.5-2.3 2.2c-.3.3-.6.5-1.1.5l.4-4.6 8.2-7.4c.4-.4-.1-.6-.6-.2L6.5 13.9 2.1 12.5c-1-.3-1-1 .2-1.4L20.5 3.9c.9-.3 1.5.2 1 0.6z"/>
                        </svg>

                    </div>


                    <div class="social-premium-content">

                        <span class="social-premium-label">
                            کانال رسمی ملکینو
                        </span>

                        <strong>
                            تلگرام
                        </strong>

                        <span class="social-premium-description">
                            مشاهده فایل‌ها و جدیدترین آگهی‌های ملکینو
                        </span>

                    </div>


                    <div class="social-premium-arrow">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 18l6-6-6-6"/>
                        </svg>

                    </div>

                </a>


                <!-- INSTAGRAM -->

                <a
                    href="#"
                    id="instagramChannelLink"
                    class="social-premium-card instagram-card"
                    target="_blank"
                    rel="noopener noreferrer"
                >

                    <div class="social-premium-icon">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="3" y="3" width="18" height="18" rx="5"/>
                            <circle cx="12" cy="12" r="4"/>
                            <circle cx="17.5" cy="6.5" r=".8" fill="currentColor" stroke="none"/>
                        </svg>

                    </div>


                    <div class="social-premium-content">

                        <span class="social-premium-label">
                            صفحه رسمی ملکینو
                        </span>

                        <strong>
                            اینستاگرام
                        </strong>

                        <span class="social-premium-description">
                            تصاویر، فایل‌ها و محتوای اختصاصی ملکینو
                        </span>

                    </div>


                    <div class="social-premium-arrow">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 18l6-6-6-6"/>
                        </svg>

                    </div>

                </a>

                <!-- بله -->

                <a
                    href="#"
                    id="baleChannelLink"
                    class="social-premium-card bale-card"
                    target="_blank"
                    rel="noopener noreferrer"
                    style="display:none"
                >

                    <div class="social-premium-icon">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M21.3 4.4L2.9 11.1c-.8.3-.8 1.5.1 1.7l4.5 1.1 1.7 5.3c.2.7 1.1.9 1.6.3l2.5-2.8 4.4 3.2c.6.4 1.4.1 1.6-.6l2.8-13.3c.2-.9-.6-1.6-1.3-1.1z"/>
                        </svg>

                    </div>


                    <div class="social-premium-content">

                        <span class="social-premium-label">
                            کانال رسمی ملکینو
                        </span>

                        <strong>
                            بله
                        </strong>

                        <span class="social-premium-description">
                            مشاهده فایل‌ها و جدیدترین آگهی‌های ملکینو
                        </span>

                    </div>


                    <div class="social-premium-arrow">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 18l6-6-6-6"/>
                        </svg>

                    </div>

                </a>


                <!-- کارت‌های سفارشی — متن، نام پیام‌رسان و آدرس از پنل ادمین تنظیم می‌شود -->

                <a
                    href="#"
                    id="customChannelLink0"
                    class="social-premium-card custom-card"
                    target="_blank"
                    rel="noopener noreferrer"
                    style="display:none"
                >

                    <div class="social-premium-icon">

                        <img
                            id="customChannelIcon0"
                            src=""
                            alt=""
                            style="width:26px;height:26px;object-fit:contain;display:none;border-radius:6px"
                        >

                        <span
                            id="customChannelEmoji0"
                            style="font-size:24px;line-height:1"
                        >🌐</span>

                    </div>


                    <div class="social-premium-content">

                        <span class="social-premium-label" id="customChannelLabel0"></span>

                        <strong id="customChannelName0"></strong>

                        <span class="social-premium-description" id="customChannelDesc0"></span>

                    </div>


                    <div class="social-premium-arrow">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 18l6-6-6-6"/>
                        </svg>

                    </div>

                </a>

                <a
                    href="#"
                    id="customChannelLink1"
                    class="social-premium-card custom-card"
                    target="_blank"
                    rel="noopener noreferrer"
                    style="display:none"
                >

                    <div class="social-premium-icon">

                        <img
                            id="customChannelIcon1"
                            src=""
                            alt=""
                            style="width:26px;height:26px;object-fit:contain;display:none;border-radius:6px"
                        >

                        <span
                            id="customChannelEmoji1"
                            style="font-size:24px;line-height:1"
                        >🌐</span>

                    </div>


                    <div class="social-premium-content">

                        <span class="social-premium-label" id="customChannelLabel1"></span>

                        <strong id="customChannelName1"></strong>

                        <span class="social-premium-description" id="customChannelDesc1"></span>

                    </div>


                    <div class="social-premium-arrow">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 18l6-6-6-6"/>
                        </svg>

                    </div>

                </a>

                <a
                    href="#"
                    id="customChannelLink2"
                    class="social-premium-card custom-card"
                    target="_blank"
                    rel="noopener noreferrer"
                    style="display:none"
                >

                    <div class="social-premium-icon">

                        <img
                            id="customChannelIcon2"
                            src=""
                            alt=""
                            style="width:26px;height:26px;object-fit:contain;display:none;border-radius:6px"
                        >

                        <span
                            id="customChannelEmoji2"
                            style="font-size:24px;line-height:1"
                        >🌐</span>

                    </div>


                    <div class="social-premium-content">

                        <span class="social-premium-label" id="customChannelLabel2"></span>

                        <strong id="customChannelName2"></strong>

                        <span class="social-premium-description" id="customChannelDesc2"></span>

                    </div>


                    <div class="social-premium-arrow">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 18l6-6-6-6"/>
                        </svg>

                    </div>

                </a>

                <a
                    href="#"
                    id="customChannelLink3"
                    class="social-premium-card custom-card"
                    target="_blank"
                    rel="noopener noreferrer"
                    style="display:none"
                >

                    <div class="social-premium-icon">

                        <img
                            id="customChannelIcon3"
                            src=""
                            alt=""
                            style="width:26px;height:26px;object-fit:contain;display:none;border-radius:6px"
                        >

                        <span
                            id="customChannelEmoji3"
                            style="font-size:24px;line-height:1"
                        >🌐</span>

                    </div>


                    <div class="social-premium-content">

                        <span class="social-premium-label" id="customChannelLabel3"></span>

                        <strong id="customChannelName3"></strong>

                        <span class="social-premium-description" id="customChannelDesc3"></span>

                    </div>


                    <div class="social-premium-arrow">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 18l6-6-6-6"/>
                        </svg>

                    </div>

                </a>

                <a
                    href="#"
                    id="customChannelLink4"
                    class="social-premium-card custom-card"
                    target="_blank"
                    rel="noopener noreferrer"
                    style="display:none"
                >

                    <div class="social-premium-icon">

                        <img
                            id="customChannelIcon4"
                            src=""
                            alt=""
                            style="width:26px;height:26px;object-fit:contain;display:none;border-radius:6px"
                        >

                        <span
                            id="customChannelEmoji4"
                            style="font-size:24px;line-height:1"
                        >🌐</span>

                    </div>


                    <div class="social-premium-content">

                        <span class="social-premium-label" id="customChannelLabel4"></span>

                        <strong id="customChannelName4"></strong>

                        <span class="social-premium-description" id="customChannelDesc4"></span>

                    </div>


                    <div class="social-premium-arrow">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 18l6-6-6-6"/>
                        </svg>

                    </div>

                </a>


            </div>

        </section>

    </div>

</div>


<!-- =========================================================
     TOAST
     ========================================================= -->

<div
    class="contact-toast"
    id="contactToast"
>

    <div class="toast-icon">

        <svg
            id="toastIcon"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
        >
            <path d="M20 6L9 17l-5-5"/>
        </svg>

    </div>


    <div class="toast-content">

        <strong id="toastTitle">
            انجام شد
        </strong>

        <span id="toastMessage">
            عملیات با موفقیت انجام شد.
        </span>

    </div>

</div>


<script>

/* =========================================================
   اطلاعاتِ تماس از سمت سرور (تنظیم‌شده در پنل ادمین)
   این مقدار برای همه‌ی بازدیدکنندگان یکسان است.
   ========================================================= */

window.MELKINO_SERVER_CONTACT = <?= json_encode(
    $melkinoServerContact,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

(function () {

    'use strict';

    /* =========================================================
       HELPERS
       ========================================================= */

    function normalizeDigits(value) {

        if (!value) {
            return '';
        }

        return String(value)
            .replace(/[۰-۹]/g, function (digit) {
                return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit));
            })
            .replace(/[٠-٩]/g, function (digit) {
                return String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit));
            });
    }


    function normalizePhone(phone) {

        let value = normalizeDigits(phone)
            .replace(/[^\d+]/g, '');

        if (value.startsWith('+98')) {
            value = '0' + value.substring(3);
        }

        if (
            value.startsWith('98') &&
            value.length >= 11
        ) {
            value = '0' + value.substring(2);
        }

        return value;
    }


    function loadContactInfo() {

        const fallback = {
            agencyName: 'املاک ملکینو شاهرود',
            address: '',
            phone: '',
            email: '',
            whatsapp: '',
            telegram: '',
            instagram: '',
            linkedin: '',
            workingHours: '',
            mapImage: '',
            bale: '',
            customCards: []
        };


        let fromStorage = {};

        try {

            const saved =
                localStorage.getItem('melkino_contact_info');

            if (saved) {

                const parsed = JSON.parse(saved);

                if (parsed && typeof parsed === 'object') {
                    fromStorage = parsed;
                }
            }

        } catch (error) {

            console.warn(
                'Melkino contact info parse error:',
                error
            );
        }


        /* اولویت: سرور > localStorage > پیش‌فرض */

        const merged =
            Object.assign({}, fallback, fromStorage);


        if (
            window.MELKINO_SERVER_CONTACT &&
            typeof window.MELKINO_SERVER_CONTACT === 'object'
        ) {

            const server =
                window.MELKINO_SERVER_CONTACT;

            Object.keys(server).forEach(function (key) {

                const value = server[key];

                if (value === null || value === undefined) {
                    return;
                }

                if (
                    typeof value === 'string' &&
                    value.trim() === ''
                ) {
                    return;
                }

                merged[key] = value;
            });
        }


        if (!Array.isArray(merged.customCards)) {
            merged.customCards = [];
        }

        return merged;
    }


    function createWhatsAppUrl(value) {

        const text = String(value || '').trim();

        if (!text) {
            return '';
        }

        if (
            text.indexOf('http://') === 0 ||
            text.indexOf('https://') === 0
        ) {
            return text;
        }

        let phone = normalizePhone(text);

        if (phone.startsWith('0')) {
            phone = '98' + phone.substring(1);
        }

        return 'https://wa.me/' + phone;
    }


    function showToast(
        title,
        message,
        type
    ) {

        const toast =
            document.getElementById('contactToast');

        const titleElement =
            document.getElementById('toastTitle');

        const messageElement =
            document.getElementById('toastMessage');

        const icon =
            document.getElementById('toastIcon');


        if (!toast) {
            return;
        }


        titleElement.textContent = title;
        messageElement.textContent = message;


        if (type === 'error') {

            icon.innerHTML = `
                <circle cx="12" cy="12" r="9"></circle>
                <path d="M12 8v4"></path>
                <path d="M12 16h.01"></path>
            `;

        } else {

            icon.innerHTML = `
                <path d="M20 6L9 17l-5-5"></path>
            `;
        }


        toast.classList.add('show');


        clearTimeout(
            window.__melkinoContactToastTimer
        );


        window.__melkinoContactToastTimer =
            setTimeout(function () {

                toast.classList.remove('show');

            }, 3500);
    }


    /* =========================================================
       RENDER CONTACT DATA
       ========================================================= */

    function renderContactPage() {

        const data = loadContactInfo();


        const agencyName =
            String(
                data.agencyName ||
                'املاک ملکینو شاهرود'
            ).trim();


        const address =
            String(
                data.address || ''
            ).trim();


        const phone =
            String(
                data.phone || ''
            ).trim();


        const email =
            String(
                data.email || ''
            ).trim();


        const whatsapp =
            String(
                data.whatsapp || ''
            ).trim();


        const telegram =
            String(
                data.telegram || ''
            ).trim();


        const instagram =
            String(
                data.instagram || ''
            ).trim();


        const bale =
            String(
                data.bale || ''
            ).trim();


        const mapImage =
            String(
                data.mapImage || ''
            ).trim();


        const workingHours =
            String(
                data.workingHours ||
                data.hours ||
                data.workHours ||
                ''
            ).trim();


        /* =====================================================
           HERO
           ===================================================== */

        const heroAgencyName =
            document.getElementById(
                'heroAgencyName'
            );


        if (heroAgencyName) {
            heroAgencyName.textContent =
                agencyName;
        }


        /* =====================================================
           OFFICE
           ===================================================== */

        const officeAddress =
            document.getElementById(
                'officeAddress'
            );


        if (officeAddress) {

            officeAddress.textContent =
                address ||
                'آدرس دفتر هنوز ثبت نشده است';
        }


        const officePhone =
            document.getElementById(
                'officePhone'
            );


        if (officePhone) {

            if (phone) {

                officePhone.textContent =
                    phone;

                officePhone.href =
                    'tel:' +
                    normalizePhone(phone);

            } else {

                officePhone.textContent =
                    'شماره تماس ثبت نشده';

                officePhone.removeAttribute(
                    'href'
                );
            }
        }


        const officeEmail =
            document.getElementById(
                'officeEmail'
            );


        if (officeEmail) {

            if (email) {

                officeEmail.textContent =
                    email;

                officeEmail.href =
                    'mailto:' +
                    email;

            } else {

                officeEmail.textContent =
                    'ایمیل ثبت نشده';

                officeEmail.removeAttribute(
                    'href'
                );
            }
        }


        const officeHours =
            document.getElementById(
                'officeHours'
            );


        if (officeHours) {

            officeHours.textContent =
                workingHours ||
                'ساعت کاری ثبت نشده است';
        }


        /* =====================================================
           PHONE CARD
           ===================================================== */

        const quickPhone =
            document.getElementById(
                'quickPhone'
            );


        const quickPhoneText =
            document.getElementById(
                'quickPhoneText'
            );


        if (phone) {

            quickPhone.href =
                'tel:' +
                normalizePhone(phone);

            quickPhoneText.textContent =
                phone;

        } else {

            quickPhone.href = '#';

            quickPhoneText.textContent =
                'شماره تماس ثبت نشده';
        }


        /* =====================================================
           WHATSAPP
           ===================================================== */

        const quickWhatsapp =
            document.getElementById(
                'quickWhatsapp'
            );


        if (quickWhatsapp) {

            if (whatsapp) {

                quickWhatsapp.href =
                    createWhatsAppUrl(
                        whatsapp
                    );

                quickWhatsapp.style.display =
                    'flex';

            } else {

                quickWhatsapp.style.display =
                    'none';
            }
        }


        /* =====================================================
           TELEGRAM
           ===================================================== */

        const quickTelegram =
            document.getElementById(
                'quickTelegram'
            );


        if (quickTelegram) {

            if (telegram) {

                quickTelegram.href =
                    telegram;

                quickTelegram.style.display =
                    'flex';

            } else {

                quickTelegram.style.display =
                    'none';
            }
        }


        /* =====================================================
           INSTAGRAM
           ===================================================== */

        const quickInstagram =
            document.getElementById(
                'quickInstagram'
            );


        if (quickInstagram) {

            if (instagram) {

                quickInstagram.href =
                    instagram;

                quickInstagram.style.display =
                    'flex';

            } else {

                quickInstagram.style.display =
                    'none';
            }
        }


        /* =====================================================
           SOCIAL PREMIUM LINKS
           ===================================================== */

        const telegramChannelLink =
            document.getElementById(
                'telegramChannelLink'
            );


        const instagramChannelLink =
            document.getElementById(
                'instagramChannelLink'
            );


        if (telegramChannelLink) {

            if (telegram) {

                telegramChannelLink.href =
                    telegram;

                telegramChannelLink.style.display =
                    'flex';

            } else {

                telegramChannelLink.href =
                    '#';

                telegramChannelLink.style.display =
                    'none';
            }
        }


        if (instagramChannelLink) {

            if (instagram) {

                instagramChannelLink.href =
                    instagram;

                instagramChannelLink.style.display =
                    'flex';

            } else {

                instagramChannelLink.href =
                    '#';

                instagramChannelLink.style.display =
                    'none';
            }
        }


        /* =====================================================
           BALE CHANNEL
           ===================================================== */

        const baleChannelLink =
            document.getElementById(
                'baleChannelLink'
            );


        if (baleChannelLink) {

            if (bale) {

                baleChannelLink.href =
                    bale;

                baleChannelLink.style.display =
                    'flex';

            } else {

                baleChannelLink.href =
                    '#';

                baleChannelLink.style.display =
                    'none';
            }
        }


        /* =====================================================
           کارت‌های سفارشی (۵ عدد — از پنل ادمین تنظیم می‌شوند)
           ===================================================== */

        const customCards =
            Array.isArray(
                data.customCards
            )
                ? data.customCards
                : [];


        for (let i = 0; i < 5; i++) {

            const link =
                document.getElementById(
                    'customChannelLink' + i
                );

            if (!link) {
                continue;
            }

            const card =
                customCards[i] || {};

            const cardLabel =
                String(
                    card.label || ''
                ).trim();

            const cardMessenger =
                String(
                    card.messenger || ''
                ).trim();

            const cardUrl =
                String(
                    card.url || ''
                ).trim();

            const cardIcon =
                String(
                    card.icon || ''
                ).trim();


            /* بدونِ آدرس یا بدونِ متن، کارت مخفی می‌ماند */

            if (!cardUrl || !cardLabel) {

                link.href =
                    '#';

                link.style.display =
                    'none';

                continue;
            }


            link.href =
                cardUrl;

            link.style.display =
                'flex';


            const labelEl =
                document.getElementById(
                    'customChannelLabel' + i
                );

            const nameEl =
                document.getElementById(
                    'customChannelName' + i
                );

            const descEl =
                document.getElementById(
                    'customChannelDesc' + i
                );

            const iconEl =
                document.getElementById(
                    'customChannelIcon' + i
                );

            const emojiEl =
                document.getElementById(
                    'customChannelEmoji' + i
                );


            if (labelEl) {
                labelEl.textContent =
                    cardLabel;
            }

            if (nameEl) {
                nameEl.textContent =
                    cardMessenger ||
                    cardLabel;
            }

            if (descEl) {
                descEl.textContent =
                    cardMessenger;
            }


            if (iconEl && cardIcon) {

                iconEl.src =
                    cardIcon;

                iconEl.style.display =
                    'block';

                if (emojiEl) {
                    emojiEl.style.display =
                        'none';
                }

            } else {

                if (iconEl) {
                    iconEl.style.display =
                        'none';
                }

                if (emojiEl) {
                    emojiEl.style.display =
                        'inline-block';
                }
            }
        }


        /* =====================================================
           MAP IMAGE (uploaded by admin)
           ===================================================== */

        const officeMapLink =
            document.getElementById(
                'officeMapLink'
            );


        const officeMapImage =
            document.getElementById(
                'officeMapImage'
            );


        const mapPlaceholder =
            document.getElementById(
                'mapPlaceholder'
            );


        if (mapImage) {

            if (officeMapImage) {
                officeMapImage.src = mapImage;
            }

            if (officeMapLink) {
                officeMapLink.href = mapImage;
                officeMapLink.style.display = 'block';
            }

            if (mapPlaceholder) {
                mapPlaceholder.style.display = 'none';
            }

        } else {

            if (officeMapLink) {
                officeMapLink.style.display = 'none';
            }

            if (mapPlaceholder) {
                mapPlaceholder.style.display = 'flex';
            }
        }
    }


    /* =========================================================
       PHONE GUARD
       ========================================================= */

    const quickPhone =
        document.getElementById(
            'quickPhone'
        );


    if (quickPhone) {

        quickPhone.addEventListener(
            'click',
            function (event) {

                const data =
                    loadContactInfo();


                if (
                    !data.phone ||
                    !String(
                        data.phone
                    ).trim()
                ) {

                    event.preventDefault();


                    showToast(
                        'شماره تماس ثبت نشده',
                        'شماره تماس از تنظیمات ملکینو ثبت نشده است.',
                        'error'
                    );
                }
            }
        );
    }


    /* =========================================================
       TELEGRAM GUARD
       ========================================================= */

    const telegramLink =
        document.getElementById(
            'telegramChannelLink'
        );


    if (telegramLink) {

        telegramLink.addEventListener(
            'click',
            function (event) {

                const data =
                    loadContactInfo();


                if (
                    !data.telegram ||
                    !String(
                        data.telegram
                    ).trim()
                ) {

                    event.preventDefault();


                    showToast(
                        'لینک تلگرام ثبت نشده',
                        'لینک کانال تلگرام از تنظیمات ملکینو ثبت نشده است.',
                        'error'
                    );
                }
            }
        );
    }


    /* =========================================================
       INSTAGRAM GUARD
       ========================================================= */

    const instagramLink =
        document.getElementById(
            'instagramChannelLink'
        );


    if (instagramLink) {

        instagramLink.addEventListener(
            'click',
            function (event) {

                const data =
                    loadContactInfo();


                if (
                    !data.instagram ||
                    !String(
                        data.instagram
                    ).trim()
                ) {

                    event.preventDefault();


                    showToast(
                        'لینک اینستاگرام ثبت نشده',
                        'لینک اینستاگرام از تنظیمات ملکینو ثبت نشده است.',
                        'error'
                    );
                }
            }
        );
    }


    /* =========================================================
       INIT
       ========================================================= */

    if (
        document.readyState ===
        'loading'
    ) {

        document.addEventListener(
            'DOMContentLoaded',
            renderContactPage
        );

    } else {

        renderContactPage();

    }

})();
</script>


<?php
require_once __DIR__ . '/footer.php';
?>