<?php
session_start();
require_once __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| دریافت لوگوی آپلودشده
|--------------------------------------------------------------------------
*/

$logoUrl = null;

$logoMetaFile = __DIR__ . '/uploads/onboarding-logo.json';

if (file_exists($logoMetaFile)) {
    $logoMeta = json_decode(
        (string) file_get_contents($logoMetaFile),
        true
    );

    if (is_array($logoMeta) && !empty($logoMeta['url'])) {
        $candidate = ltrim((string) $logoMeta['url'], '/\\');

        if (file_exists(__DIR__ . '/' . $candidate)) {
            $logoUrl = $candidate;
        }
    }
}

/*
|--------------------------------------------------------------------------
| fallback
|--------------------------------------------------------------------------
*/

if (!$logoUrl) {
    foreach (['png', 'jpg', 'webp'] as $ext) {
        $candidate = 'uploads/onboarding-logo.' . $ext;

        if (file_exists(__DIR__ . '/' . $candidate)) {
            $logoUrl = $candidate;
            break;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover"
    >

    <meta name="theme-color" content="#031D1D">

    <title>ملکینو | انتخابی فراتر از یک ملک</title>

    <link
        href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css"
        rel="stylesheet"
    >

    <style>

        :root {
            --mk-teal: #052B2B;
            --mk-teal-dark: #031D1D;
            --mk-teal-deep: #021515;

            --mk-gold: #D4AF37;
            --mk-gold-light: #F0D36A;
            --mk-gold-soft: #F7E9A7;

            --mk-white: #FFFFFF;

            --mk-text: rgba(255,255,255,.92);
            --mk-muted: rgba(255,255,255,.58);
            --mk-muted-soft: rgba(255,255,255,.32);

            --mk-border: rgba(255,255,255,.075);

            --mk-ease: cubic-bezier(.22,.61,.36,1);
        }


        /* =========================================================
           RESET
        ========================================================== */

        * {
            box-sizing: border-box;
        }


        html,
        body {
            width: 100%;
            height: 100%;

            margin: 0;
            padding: 0;

            background: var(--mk-teal-deep);

            font-family: "Vazirmatn", sans-serif;
        }


        body {
            overflow: hidden;
        }


        button,
        a {
            font-family: inherit;
            -webkit-tap-highlight-color: transparent;
        }


        button {
            border: 0;
        }


        /* =========================================================
           PAGE
        ========================================================== */

        .mk-page {
            position: relative;

            width: 100%;
            height: 100svh;
            min-height: 100svh;

            overflow: hidden;

            color: var(--mk-text);

            background:

                radial-gradient(
                    circle at 78% 12%,
                    rgba(212,175,55,.095),
                    transparent 25%
                ),

                radial-gradient(
                    circle at 10% 88%,
                    rgba(255,255,255,.03),
                    transparent 24%
                ),

                linear-gradient(
                    145deg,
                    #073737 0%,
                    #052D2D 32%,
                    #031F1F 68%,
                    #021515 100%
                );
        }


        /* =========================================================
           BACKGROUND
        ========================================================== */

        .mk-grid {
            position: absolute;

            inset: 0;

            pointer-events: none;

            opacity: .22;

            background-image:

                linear-gradient(
                    rgba(255,255,255,.025) 1px,
                    transparent 1px
                ),

                linear-gradient(
                    90deg,
                    rgba(255,255,255,.025) 1px,
                    transparent 1px
                );

            background-size: 38px 38px;

            mask-image:
                linear-gradient(
                    to bottom,
                    transparent,
                    black 18%,
                    black 82%,
                    transparent
                );
        }


        .mk-glow {
            position: absolute;

            border-radius: 50%;

            pointer-events: none;
        }


        .mk-glow-one {
            width: 420px;
            height: 420px;

            top: -250px;
            right: -250px;

            background:
                radial-gradient(
                    circle,
                    rgba(212,175,55,.11),
                    transparent 68%
                );
        }


        .mk-glow-two {
            width: 330px;
            height: 330px;

            left: -240px;
            bottom: -240px;

            background:
                radial-gradient(
                    circle,
                    rgba(255,255,255,.035),
                    transparent 70%
                );
        }


        .mk-ring {
            position: absolute;

            width: min(580px, 95vw);
            height: min(580px, 95vw);

            right: -270px;
            top: 50%;

            transform: translateY(-50%);

            border-radius: 50%;

            border:
                1px solid rgba(212,175,55,.045);

            pointer-events: none;
        }


        .mk-ring::before {
            content: "";

            position: absolute;

            inset: 52px;

            border-radius: 50%;

            border:
                1px solid rgba(255,255,255,.022);
        }


        /* =========================================================
           TOP BAR
        ========================================================== */

        .mk-topbar {
            position: absolute;

            z-index: 60;

            top: 0;
            left: 0;
            right: 0;

            display: flex;

            align-items: center;
            justify-content: space-between;

            padding:
                max(16px, env(safe-area-inset-top))
                clamp(15px, 4vw, 32px)
                12px;
        }


        .mk-brand {
            display: inline-flex;

            align-items: center;

            gap: 9px;
        }


        .mk-logo-box {
            width: 43px;
            height: 43px;

            flex: 0 0 auto;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 13px;

            background:
                rgba(255,255,255,.035);

            border:
                1px solid rgba(212,175,55,.18);

            box-shadow:
                inset 0 1px 0 rgba(255,255,255,.06);
        }


        .mk-logo-box img {
            width: 84%;
            height: 84%;

            display: block;

            object-fit: contain;
        }


        .mk-fallback-logo {
            width: 24px;
            height: 24px;

            color: var(--mk-gold-light);
        }


        .mk-brand-text {
            display: flex;

            flex-direction: column;

            gap: 2px;
        }


        .mk-brand-name {
            font-size: 14px;

            line-height: 1;

            color: var(--mk-white);

            font-weight: 900;
        }


        .mk-brand-sub {
            font-size: 8px;

            color: rgba(255,255,255,.31);
        }


        .mk-skip {
            cursor: pointer;

            padding: 8px 0;

            color:
                rgba(255,255,255,.34);

            background: transparent;

            font-size: 9px;

            transition: color .2s ease;
        }


        .mk-skip:hover {
            color:
                rgba(255,255,255,.62);
        }


        /* =========================================================
           SLIDER
        ========================================================== */

        .mk-slider {
            position: relative;

            z-index: 10;

            width: 100%;
            height: 100%;

            display: flex;

            direction: ltr;

            transition:
                transform .6s var(--mk-ease);

            touch-action: pan-y;
        }


        .mk-slide {
            width: 100%;
            height: 100%;

            flex: 0 0 100%;

            direction: rtl;

            display: flex;

            align-items: center;
            justify-content: center;

            padding:
                76px 18px 210px;
        }


        .mk-slide-inner {
            width: min(570px, 100%);

            display: flex;

            flex-direction: column;

            align-items: center;

            text-align: center;

            animation:
                mkReveal .7s var(--mk-ease) both;
        }


        @keyframes mkReveal {

            from {
                opacity: 0;

                transform:
                    translateY(20px);
            }

            to {
                opacity: 1;

                transform:
                    translateY(0);
            }
        }


        /* =========================================================
           VISUAL
        ========================================================== */

        .mk-visual {
            position: relative;

            width: min(300px, 72vw);
            aspect-ratio: 1;

            display: flex;

            align-items: center;
            justify-content: center;

            margin-bottom: 21px;
        }


        .mk-visual::before {
            content: "";

            position: absolute;

            inset: 2%;

            border-radius: 50%;

            border:
                1px solid rgba(212,175,55,.075);

            box-shadow:
                0 0 0 22px rgba(212,175,55,.012),
                0 0 0 44px rgba(212,175,55,.008);
        }


        .mk-visual::after {
            content: "";

            position: absolute;

            width: 72%;
            height: 72%;

            border-radius: 50%;

            background:
                radial-gradient(
                    circle,
                    rgba(212,175,55,.09),
                    transparent 69%
                );
        }


        .mk-visual-card {
            position: relative;

            z-index: 3;

            width: 62%;

            aspect-ratio: 1;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 29px;

            background:
                linear-gradient(
                    145deg,
                    rgba(255,255,255,.075),
                    rgba(255,255,255,.018)
                );

            border:
                1px solid rgba(255,255,255,.085);

            box-shadow:
                0 28px 70px rgba(0,0,0,.25),
                inset 0 1px 0 rgba(255,255,255,.065);

            backdrop-filter:
                blur(14px);

            -webkit-backdrop-filter:
                blur(14px);
        }


        .mk-visual-card img {
            width: 83%;
            height: 83%;

            object-fit: contain;

            filter:
                drop-shadow(
                    0 18px 27px rgba(0,0,0,.23)
                );
        }


        .mk-icon {
            width: 74px;
            height: 74px;

            color: var(--mk-gold-light);
        }


        /* =========================================================
           MINI DECOR
        ========================================================== */

        .mk-mini {
            position: absolute;

            z-index: 7;

            width: 44px;
            height: 44px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 50%;

            color: var(--mk-gold-light);

            background:
                rgba(3,29,29,.86);

            border:
                1px solid rgba(212,175,55,.14);

            box-shadow:
                0 12px 30px rgba(0,0,0,.17);

            animation:
                mkFloat 5s ease-in-out infinite;

            font-size: 15px;
        }


        .mk-mini-one {
            top: 8%;
            right: 7%;
        }


        .mk-mini-two {
            left: 7%;
            bottom: 11%;

            animation-delay: -2s;
        }


        .mk-mini-three {
            right: 14%;
            bottom: 4%;

            animation-delay: -3.5s;
        }


        @keyframes mkFloat {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-7px);
            }
        }


        /* =========================================================
           CONTENT
        ========================================================== */

        .mk-step {
            display: inline-flex;

            align-items: center;

            gap: 7px;

            margin-bottom: 10px;

            padding:
                7px 11px;

            border-radius: 999px;

            color:
                var(--mk-gold-light);

            background:
                rgba(212,175,55,.045);

            border:
                1px solid rgba(212,175,55,.14);

            font-size: 8px;

            font-weight: 850;
        }


        .mk-step-dot {
            width: 6px;
            height: 6px;

            border-radius: 50%;

            background:
                var(--mk-gold);

            box-shadow:
                0 0 10px rgba(212,175,55,.48);
        }


        .mk-title {
            margin: 0;

            color:
                var(--mk-white);

            font-size:
                clamp(28px, 8.6vw, 47px);

            line-height: 1.42;

            font-weight: 950;

            letter-spacing: -.8px;
        }


        .mk-title span {
            display: block;

            color:
                var(--mk-gold-light);
        }


        .mk-description {
            max-width: 480px;

            margin:
                10px auto 0;

            color:
                var(--mk-muted);

            font-size:
                clamp(10px, 2.9vw, 13px);

            line-height: 2;

        }


        .mk-tagline {
            margin-top: 12px;

            color:
                var(--mk-muted-soft);

            font-size: 8px;
        }


        .mk-tagline strong {
            color:
                rgba(240,211,106,.68);

            font-weight: 850;
        }


        /* =========================================================
           FIXED NAVIGATION AREA
        ========================================================== */

        .mk-controls {
            position: absolute;

            z-index: 55;

            left: 0;
            right: 0;

            bottom:
                max(69px, env(safe-area-inset-bottom) + 60px);

            display: flex;

            justify-content: center;

            align-items: center;

            gap: 9px;

            padding:
                0 18px;
        }


        .mk-nav-btn {
            width: 105px;
            height: 42px;

            display: inline-flex;

            align-items: center;
            justify-content: center;

            gap: 7px;

            border-radius: 13px;

            border:
                1px solid rgba(255,255,255,.07);

            background:
                rgba(255,255,255,.035);

            color:
                rgba(255,255,255,.63);

            font-size: 9px;

            font-weight: 750;

            cursor: pointer;

            transition:
                transform .2s ease,
                background .2s ease,
                border-color .2s ease,
                opacity .2s ease;
        }


        .mk-nav-btn:hover {
            transform:
                translateY(-2px);

            background:
                rgba(255,255,255,.055);

            border-color:
                rgba(212,175,55,.16);

            color:
                rgba(255,255,255,.86);
        }


        .mk-nav-btn:active {
            transform:
                scale(.97);
        }


        .mk-nav-btn.primary {
            color:
                #183131;

            border:
                0;

            background:
                linear-gradient(
                    135deg,
                    var(--mk-gold-soft),
                    var(--mk-gold-light),
                    var(--mk-gold)
                );

            box-shadow:
                0 12px 27px rgba(212,175,55,.13);
        }


        .mk-nav-btn.primary:hover {
            background:
                linear-gradient(
                    135deg,
                    #fff0a5,
                    var(--mk-gold-light),
                    var(--mk-gold)
                );

            color:
                #122727;
        }


        .mk-nav-btn:disabled {
            cursor:
                default;

            opacity:
                .24;

            transform:
                none;

            background:
                rgba(255,255,255,.025);

            border-color:
                rgba(255,255,255,.05);

            color:
                rgba(255,255,255,.3);
        }


        .mk-nav-arrow {
            font-size: 13px;
            line-height: 1;
        }


        /* =========================================================
           BOTTOM PROGRESS
        ========================================================== */

        .mk-footer {
            position: absolute;

            z-index: 50;

            left: 0;
            right: 0;

            bottom: 0;

            padding:
                0 18px
                max(15px, env(safe-area-inset-bottom));
        }


        .mk-progress {
            width:
                min(200px, 52vw);

            height: 3px;

            margin:
                0 auto 8px;

            overflow: hidden;

            border-radius: 999px;

            background:
                rgba(255,255,255,.07);
        }


        .mk-progress-bar {
            width: 25%;
            height: 100%;

            border-radius: inherit;

            background:
                linear-gradient(
                    90deg,
                    var(--mk-gold),
                    var(--mk-gold-light)
                );

            box-shadow:
                0 0 10px rgba(212,175,55,.24);

            transition:
                width .55s var(--mk-ease);
        }


        .mk-footer-row {
            height: 21px;

            display: flex;

            align-items: center;
            justify-content: center;

            position: relative;
        }


        .mk-count {
            position: absolute;

            left: 0;

            color:
                rgba(255,255,255,.18);

            font-size: 7px;

            direction: ltr;
        }


        .mk-hint {
            color:
                rgba(255,255,255,.20);

            font-size: 7px;
        }


        /* =========================================================
           MOBILE
        ========================================================== */

        @media (max-width: 600px) {

            .mk-slide {
                padding:
                    76px 15px 202px;
            }


            .mk-visual {
                width:
                    min(270px, 69vw);

                margin-bottom: 17px;
            }


            .mk-visual-card {
                border-radius: 26px;
            }


            .mk-icon {
                width: 62px;
                height: 62px;
            }


            .mk-mini {
                width: 39px;
                height: 39px;

                font-size: 12px;
            }


            .mk-title {
                font-size:
                    clamp(26px, 8.6vw, 37px);
            }


            .mk-description {
                max-width: 340px;

                font-size: 10px;

                line-height: 1.95;
            }


            .mk-tagline {
                font-size: 7px;
            }


            .mk-controls {
                bottom:
                    max(62px, env(safe-area-inset-bottom) + 53px);

                gap: 7px;
            }


            .mk-nav-btn {
                width: 94px;
                height: 39px;

                border-radius: 12px;

                font-size: 8px;
            }


            .mk-progress {
                width: 175px;
            }


            .mk-hint {
                display: none;
            }


            .mk-brand-logo {
                width: 40px;
                height: 40px;

                border-radius: 12px;
            }


            .mk-brand-name {
                font-size: 12px;
            }


            .mk-brand-sub {
                font-size: 7px;
            }


            .mk-skip {
                font-size: 8px;
            }
        }


        /* =========================================================
           VERY SMALL PHONES
        ========================================================== */

        @media (
            max-width: 390px
        ) and (
            max-height: 720px
        ) {

            .mk-slide {
                padding-top: 66px;
                padding-bottom: 193px;
            }


            .mk-visual {
                width:
                    min(220px, 60vw);

                margin-bottom: 12px;
            }


            .mk-title {
                font-size: 25px;
            }


            .mk-description {
                font-size: 9px;
                line-height: 1.85;
            }


            .mk-tagline {
                display: none;
            }


            .mk-controls {
                bottom:
                    max(59px, env(safe-area-inset-bottom) + 50px);
            }


            .mk-nav-btn {
                width: 87px;
                height: 37px;
            }
        }


        /* =========================================================
           DESKTOP
        ========================================================== */

        @media (min-width: 800px) {

            .mk-slide {
                padding-bottom: 205px;
            }


            .mk-slide-inner {
                width: 690px;
            }


            .mk-visual {
                width: 350px;
            }


            .mk-title {
                font-size: 53px;
            }


            .mk-description {
                font-size: 13px;
            }


            .mk-nav-btn {
                width: 110px;
                height: 43px;
            }
        }


        /* =========================================================
           REDUCED MOTION
        ========================================================== */

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation-duration:
                    .01ms !important;

                animation-iteration-count:
                    1 !important;

                transition-duration:
                    .01ms !important;
            }
        }

    </style>

</head>


<body>

<div class="mk-page">

    <!-- Background -->
    <div class="mk-grid"></div>
    <div class="mk-glow mk-glow-one"></div>
    <div class="mk-glow mk-glow-two"></div>
    <div class="mk-ring"></div>


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <header class="mk-topbar">

        <div class="mk-brand">

            <div class="mk-logo-box">

                <?php if ($logoUrl): ?>

                    <img
                        src="<?= htmlspecialchars(
                            $logoUrl,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        alt="لوگوی ملکینو"
                    >

                <?php else: ?>

                    <svg
                        class="mk-fallback-logo"
                        viewBox="0 0 24 24"
                        fill="none"
                    >

                        <path
                            d="M3 10L12 3L21 10V20C21 20.55 20.55 21 20 21H4C3.45 21 3 20.55 3 20V10Z"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linejoin="round"
                        />

                        <path
                            d="M9 21V13H15V21"
                            stroke="currentColor"
                            stroke-width="1.6"
                        />

                    </svg>

                <?php endif; ?>

            </div>


            <div class="mk-brand-text">

                <div class="mk-brand-name">
                    ملکینو
                </div>

                <div class="mk-brand-sub">
                    اولین پلتفرم ملکی شاهرود
                </div>

            </div>

        </div>


        <button
            type="button"
            class="mk-skip"
            id="skipBtn"
        >
            رد کردن
        </button>

    </header>



    <!-- =========================================================
         SLIDER
    ========================================================== -->

    <main
        class="mk-slider"
        id="slider"
    >


        <!-- =====================================================
             SLIDE 1
        ====================================================== -->

        <section class="mk-slide">

            <div class="mk-slide-inner">

                <div class="mk-visual">

                    <div class="mk-visual-card">

                        <?php if ($logoUrl): ?>

                            <img
                                src="<?= htmlspecialchars(
                                    $logoUrl,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                alt="ملکینو"
                            >

                        <?php else: ?>

                            <svg
                                class="mk-icon"
                                viewBox="0 0 24 24"
                                fill="none"
                            >

                                <path
                                    d="M4 18H20"
                                    stroke="currentColor"
                                    stroke-width="1.4"
                                    stroke-linecap="round"
                                />

                                <path
                                    d="M5 18L4 7L9 11L12 5L15 11L20 7L19 18"
                                    stroke="currentColor"
                                    stroke-width="1.4"
                                    stroke-linejoin="round"
                                />

                            </svg>

                        <?php endif; ?>

                    </div>


                    <div class="mk-mini mk-mini-one">
                        ✦
                    </div>

                    <div class="mk-mini mk-mini-two">
                        ◇
                    </div>

                </div>


                <div class="mk-step">

                    <span class="mk-step-dot"></span>

                    ملکینو

                </div>


                <h1 class="mk-title">

                    انتخابی فراتر

                    <span>
                        از یک ملک
                    </span>

                </h1>


                <p class="mk-description">

                    اولین پلتفرم ملکی شاهرود؛
                    جایی برای پیدا کردن، معرفی و انتخاب
                    ملک مناسب، ساده‌تر و حرفه‌ای‌تر از همیشه.

                </p>


                <div class="mk-tagline">

                    <strong>ملکینو</strong>
                    ؛ مسیر ساده‌تر رسیدن به ملک مناسب

                </div>

            </div>

        </section>



        <!-- =====================================================
             SLIDE 2
        ====================================================== -->

        <section class="mk-slide">

            <div class="mk-slide-inner">

                <div class="mk-visual">

                    <div class="mk-visual-card">

                        <svg
                            class="mk-icon"
                            viewBox="0 0 24 24"
                            fill="none"
                        >

                            <path
                                d="M3 10L12 3L21 10V20C21 20.55 20.55 21 20 21H4C3.45 21 3 20.55 3 20V10Z"
                                stroke="currentColor"
                                stroke-width="1.4"
                                stroke-linejoin="round"
                            />

                            <path
                                d="M9 21V13H15V21"
                                stroke="currentColor"
                                stroke-width="1.4"
                            />

                            <path
                                d="M12 8V11"
                                stroke="currentColor"
                                stroke-width="1.3"
                                stroke-linecap="round"
                            />

                            <path
                                d="M10.5 9.5H13.5"
                                stroke="currentColor"
                                stroke-width="1.3"
                                stroke-linecap="round"
                            />

                        </svg>

                    </div>


                    <div class="mk-mini mk-mini-one">
                        ✓
                    </div>

                    <div class="mk-mini mk-mini-two">
                        ◌
                    </div>

                </div>


                <div class="mk-step">

                    <span class="mk-step-dot"></span>

                    ثبت ملک

                </div>


                <h1 class="mk-title">

                    ملک شما

                    <span>
                        مشتری خودش را دارد
                    </span>

                </h1>


                <p class="mk-description">

                    ملک خودت را در ملکینو ثبت کن
                    تا اطلاعات و تصاویر آن در معرض دید
                    مشتریانی قرار بگیرد که به دنبال چنین ملکی هستند.

                </p>


                <div class="mk-tagline">

                    <strong>ملک خوب</strong>
                    باید درست دیده شود.

                </div>

            </div>

        </section>



        <!-- =====================================================
             SLIDE 3
        ====================================================== -->

        <section class="mk-slide">

            <div class="mk-slide-inner">

                <div class="mk-visual">

                    <div class="mk-visual-card">

                        <svg
                            class="mk-icon"
                            viewBox="0 0 24 24"
                            fill="none"
                        >

                            <circle
                                cx="10.8"
                                cy="10.8"
                                r="6.5"
                                stroke="currentColor"
                                stroke-width="1.5"
                            />

                            <path
                                d="M16 16L21 21"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linecap="round"
                            />

                            <path
                                d="M8 10.8H13.5"
                                stroke="currentColor"
                                stroke-width="1.4"
                                stroke-linecap="round"
                            />

                            <path
                                d="M10.75 8V13.5"
                                stroke="currentColor"
                                stroke-width="1.4"
                                stroke-linecap="round"
                            />

                        </svg>

                    </div>


                    <div class="mk-mini mk-mini-one">
                        ⌕
                    </div>

                    <div class="mk-mini mk-mini-three">
                        ♥
                    </div>

                </div>


                <div class="mk-step">

                    <span class="mk-step-dot"></span>

                    جستجوی ملک

                </div>


                <h1 class="mk-title">

                    دنبال ملک می‌گردی؟

                    <span>
                        اینجا پیداش کن
                    </span>

                </h1>


                <p class="mk-description">

                    در بخش جستجوی ملک،
                    بین فایل‌های منتشرشده بگرد،
                    مشخصات و امکانات را بررسی کن
                    و گزینه‌ای را پیدا کن که بیشتر به نیازت نزدیک است.

                </p>


                <div class="mk-tagline">

                    از خانه و آپارتمان تا زمین، باغ و ملک تجاری

                </div>

            </div>

        </section>



        <!-- =====================================================
             SLIDE 4
        ====================================================== -->

        <section class="mk-slide">

            <div class="mk-slide-inner">

                <div class="mk-visual">

                    <div class="mk-visual-card">

                        <svg
                            class="mk-icon"
                            viewBox="0 0 24 24"
                            fill="none"
                        >

                            <rect
                                x="4"
                                y="4"
                                width="16"
                                height="16"
                                rx="3"
                                stroke="currentColor"
                                stroke-width="1.4"
                            />

                            <path
                                d="M8 9H16"
                                stroke="currentColor"
                                stroke-width="1.4"
                                stroke-linecap="round"
                            />

                            <path
                                d="M8 13H14"
                                stroke="currentColor"
                                stroke-width="1.4"
                                stroke-linecap="round"
                            />

                            <path
                                d="M8 17H11"
                                stroke="currentColor"
                                stroke-width="1.4"
                                stroke-linecap="round"
                            />

                            <path
                                d="M17 15V19"
                                stroke="currentColor"
                                stroke-width="1.4"
                                stroke-linecap="round"
                            />

                            <path
                                d="M15 17H19"
                                stroke="currentColor"
                                stroke-width="1.4"
                                stroke-linecap="round"
                            />

                        </svg>

                    </div>


                    <div class="mk-mini mk-mini-one">
                        !
                    </div>

                    <div class="mk-mini mk-mini-two">
                        ✓
                    </div>

                </div>


                <div class="mk-step">

                    <span class="mk-step-dot"></span>

                    ثبت درخواست

                </div>


                <h1 class="mk-title">

                    ملکی که می‌خوای

                    <span>
                        پیدا نکردی؟
                    </span>

                </h1>


                <p class="mk-description">

                    خواسته‌ات را برای ما بنویس؛
                    از نوع ملک و محدوده گرفته تا بودجه
                    و ویژگی‌هایی که برایت مهم است.
                    ما کمک می‌کنیم زودتر به گزینه مناسب برسی.

                </p>


                <div class="mk-tagline">

                    <strong>تو فقط خواسته‌ات را بگو؛</strong>
                    مسیر پیدا کردنش را ساده‌تر می‌کنیم.

                </div>

            </div>

        </section>

    </main>



    <!-- =========================================================
         FIXED NAVIGATION
    ========================================================== -->

    <div class="mk-controls">

        <button
            type="button"
            class="mk-nav-btn"
            id="prevBtn"
        >

            <span class="mk-nav-arrow">
                →
            </span>

            قبلی

        </button>


        <button
            type="button"
            class="mk-nav-btn primary"
            id="nextBtn"
        >

            بعدی

            <span class="mk-nav-arrow">
                ←
            </span>

        </button>

    </div>



    <!-- =========================================================
         PROGRESS
    ========================================================== -->

    <div class="mk-footer">

        <div class="mk-progress">

            <div
                class="mk-progress-bar"
                id="progressBar"
            ></div>

        </div>


        <div class="mk-footer-row">

            <div class="mk-hint">
                معرفی ملکینو
            </div>


            <div
                class="mk-count"
                id="count"
            >
                01 / 04
            </div>

        </div>

    </div>

</div>



<script>
(function () {

    const slider =
        document.getElementById('slider');

    const progressBar =
        document.getElementById('progressBar');

    const count =
        document.getElementById('count');

    const prevBtn =
        document.getElementById('prevBtn');

    const nextBtn =
        document.getElementById('nextBtn');

    const skipBtn =
        document.getElementById('skipBtn');


    const total = 4;


    let current = 0;

    let startX = null;
    let startY = null;

    let swiping = false;

    let wheelLock = false;


    function clamp(value) {

        return Math.max(
            0,
            Math.min(total - 1, value)
        );

    }


    function render() {

        current =
            clamp(current);


        /*
        |--------------------------------------------------------------------------
        | Slider
        |--------------------------------------------------------------------------
        */

        slider.style.transform =
            `translateX(-${current * 100}%)`;


        /*
        |--------------------------------------------------------------------------
        | Progress
        |--------------------------------------------------------------------------
        */

        progressBar.style.width =
            `${((current + 1) / total) * 100}%`;


        /*
        |--------------------------------------------------------------------------
        | Counter
        |--------------------------------------------------------------------------
        */

        count.textContent =
            `${String(current + 1).padStart(2, '0')} / ${String(total).padStart(2, '0')}`;


        /*
        |--------------------------------------------------------------------------
        | Previous
        |--------------------------------------------------------------------------
        */

        prevBtn.disabled =
            current === 0;


        /*
        |--------------------------------------------------------------------------
        | Next
        |--------------------------------------------------------------------------
        */

        if (current === total - 1) {

            nextBtn.innerHTML =
                `
                    ورود به ملکینو
                    <span class="mk-nav-arrow">←</span>
                `;

            skipBtn.textContent =
                'ورود به ملکینو';

        } else {

            nextBtn.innerHTML =
                `
                    بعدی
                    <span class="mk-nav-arrow">←</span>
                `;

            skipBtn.textContent =
                'رد کردن';

        }


        /*
        |--------------------------------------------------------------------------
        | Animate current slide
        |--------------------------------------------------------------------------
        */

        const slides =
            document.querySelectorAll('.mk-slide');

        const active =
            slides[current]
                ? slides[current].querySelector('.mk-slide-inner')
                : null;


        if (active) {

            active.style.animation =
                'none';

            requestAnimationFrame(() => {

                active.style.animation = '';

            });

        }

    }


    function next() {

        if (current < total - 1) {

            current += 1;

            render();

        } else {

            window.location.href =
                'home.php';

        }

    }


    function prev() {

        if (current > 0) {

            current -= 1;

            render();

        }

    }


    /*
    |--------------------------------------------------------------------------
    | Buttons
    |--------------------------------------------------------------------------
    */

    nextBtn.addEventListener(
        'click',
        next
    );


    prevBtn.addEventListener(
        'click',
        prev
    );


    skipBtn.addEventListener(
        'click',
        function () {

            window.location.href =
                'home.php';

        }
    );


    /*
    |--------------------------------------------------------------------------
    | Touch swipe
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'touchstart',
        function (event) {

            if (
                !event.touches ||
                event.touches.length !== 1
            ) {
                return;
            }


            startX =
                event.touches[0].clientX;

            startY =
                event.touches[0].clientY;

            swiping = true;

        },
        {
            passive: true
        }
    );


    document.addEventListener(
        'touchend',
        function (event) {

            if (
                !swiping ||
                startX === null ||
                startY === null
            ) {
                return;
            }


            const endX =
                event.changedTouches[0].clientX;

            const endY =
                event.changedTouches[0].clientY;


            const diffX =
                endX - startX;

            const diffY =
                endY - startY;


            swiping = false;

            startX = null;
            startY = null;


            if (
                Math.abs(diffX) < 50 ||
                Math.abs(diffX) < Math.abs(diffY)
            ) {
                return;
            }


            /*
            | RTL:
            | swipe left => next
            | swipe right => previous
            */

            if (diffX < 0) {

                next();

            } else {

                prev();

            }

        },
        {
            passive: true
        }
    );


    /*
    |--------------------------------------------------------------------------
    | Keyboard
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key === 'ArrowLeft' ||
                event.key === 'PageDown'
            ) {

                next();

            }


            if (
                event.key === 'ArrowRight' ||
                event.key === 'PageUp'
            ) {

                prev();

            }


            if (event.key === 'Escape') {

                window.location.href =
                    'home.php';

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | Wheel / Trackpad
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'wheel',
        function (event) {

            if (wheelLock) {
                return;
            }


            if (Math.abs(event.deltaY) < 25) {
                return;
            }


            wheelLock = true;


            if (event.deltaY > 0) {

                next();

            } else {

                prev();

            }


            setTimeout(
                function () {

                    wheelLock = false;

                },
                650
            );

        },
        {
            passive: true
        }
    );


    /*
    |--------------------------------------------------------------------------
    | Initialize
    |--------------------------------------------------------------------------
    */

    render();

})();
</script>

</body>
</html>