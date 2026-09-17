<?php
// ==============================================
// بارگذاری محتوای صفحات هدایت از config.json
// ==============================================
$configPath = 'config.json';
$onboardingData = [];
$onboardingLogo = '';

if (file_exists($configPath)) {
    $configString = file_get_contents($configPath);
    $configData = json_decode($configString, true);
    
    $onboardingLogo = $configData['onboarding']['logo'] ?? '';
    $onboardingData = $configData['onboarding']['pages'] ?? [];
}

// اگر در کانفیگ داده‌ای نبود، یک داده پیش‌فرض ایجاد می‌کنیم
if (empty($onboardingData)) {
    $onboardingData = [
        ['title' => 'به ملکینو خوش آمدید!', 'text' => 'سامانه جامع جستجو و ثبت ملک. تجربه‌ای جدید در خرید و فروش املاک.', 'icon' => '🏠'],
        ['title' => 'جستجوی هوشمند', 'text' => 'با فیلترهای پیشرفته، املاک مورد نظر خود را به سادگی پیدا کنید.', 'icon' => '🔍'],
        ['title' => 'ثبت آگهی آسان', 'text' => 'با فرم‌های ویزاردی ما، ملک خود را در چند مرحله ساده ثبت کنید.', 'icon' => '📝'],
        ['title' => 'همیشه در کنار شما', 'text' => 'از پشتیبانی ۲۴ ساعته و تیم حرفه‌ای ملکینو لذت ببرید.', 'icon' => '🤝']
    ];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>ملکینو - خوش آمدید</title>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" media="print" onload="this.media='all'" />
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="design-pro.css">
    <style>
        /* استایل صفحات هدایت (کاملاً اختصاصی و بدون هدر/فوتر) */
        body, html {
            height: 100%;
            margin: 0;
            padding: 0;
            background: var(--bg);
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: 'Vazirmatn', sans-serif;
            transition: background 0.3s ease;
        }
        .onboarding-wrapper { 
            background: var(--surface); 
            border-radius: var(--radius-lg); 
            padding: var(--space-4); 
            box-shadow: var(--shadow-card); 
            width: 100%; 
            max-width: 400px; 
            text-align: center; 
            border: 1px solid var(--border);
            margin: var(--space-2);
            transition: background 0.3s ease, border-color 0.3s ease;
        }
        .onboarding-logo { 
            width: 80px; 
            height: 80px; 
            object-fit: contain; 
            margin-bottom: var(--space-2); 
            display: block; 
            margin-left: auto; 
            margin-right: auto; 
        }
        .onboarding-icon { 
            font-size: 64px; 
            margin-bottom: var(--space-2); 
            display: block; 
        }
        .onboarding-title { 
            font-size: 24px; 
            font-weight: 800; 
            color: var(--text-primary); 
            margin-bottom: var(--space-1); 
        }
        .onboarding-text { 
            font-size: 16px; 
            color: var(--text-secondary); 
            line-height: 1.6; 
        }
        .onboarding-dots { 
            display: flex; 
            justify-content: center; 
            gap: var(--space-1); 
            margin: var(--space-2) 0; 
        }
        .dot { 
            width: 10px; 
            height: 10px; 
            border-radius: 50%; 
            background: var(--border); 
            transition: 0.3s; 
        }
        .dot.active { 
            background: var(--primary); 
            width: 20px; 
            border-radius: 10px; 
        }
        .onboarding-btn { 
            width: 100%; 
            height: 52px; 
            border: none; 
            border-radius: var(--radius-md); 
            background: var(--primary); 
            color: #fff; 
            font-weight: 700; 
            font-size: 16px; 
            font-family: 'Vazirmatn', sans-serif; 
            cursor: pointer; 
            transition: 0.2s; 
        }
        .onboarding-btn:active { 
            transform: scale(0.98); 
            opacity: 0.8; 
        }
        .onboarding-skip { 
            display: block; 
            margin-top: var(--space-2); 
            color: var(--text-secondary); 
            text-decoration: none; 
            font-size: 14px; 
        }
    </style>
</head>
<body>

    <div class="onboarding-wrapper" id="onboardingWrapper">
        <!-- محتوای متغیر -->
        <div id="stepContent">
            <div id="logoContainer"></div>
            <h2 class="onboarding-title" id="stepTitle">عنوان</h2>
            <p class="onboarding-text" id="stepText">توضیحات</p>
        </div>

        <!-- نشانگر‌های مرحله -->
        <div class="onboarding-dots" id="dotsContainer"></div>

        <!-- دکمه‌ها -->
        <button class="onboarding-btn" id="actionBtn" onclick="nextStep()">مرحله بعد</button>
        <a href="home.php" class="onboarding-skip">رد کردن و رفتن به خانه</a>
    </div>

    <script>
        // داده‌های مراحل و لوگو از PHP آمده
        const onboardingLogo = '<?= htmlspecialchars($onboardingLogo) ?>';
        const steps = <?= json_encode($onboardingData) ?>;
        let currentStep = 0;
        const totalSteps = steps.length;

        const titleEl = document.getElementById('stepTitle');
        const textEl = document.getElementById('stepText');
        const logoContainer = document.getElementById('logoContainer');
        const btnEl = document.getElementById('actionBtn');
        const dotsContainer = document.getElementById('dotsContainer');

        function renderStep(index) {
            const step = steps[index];
            if (!step) return;

            titleEl.innerText = step.title || 'بدون عنوان';
            textEl.innerText = step.text || 'بدون توضیحات';

            if (onboardingLogo) {
                logoContainer.innerHTML = `<img src="${onboardingLogo}" class="onboarding-logo" alt="لوگو">`;
            } else {
                logoContainer.innerHTML = `<span class="onboarding-icon">${step.icon || '🏠'}</span>`;
            }

            // به‌روزرسانی نقطه‌ها
            document.querySelectorAll('.dot').forEach((dot, i) => {
                dot.classList.toggle('active', i === index);
            });

            // تغییر متن دکمه در مرحله آخر
            if (index === totalSteps - 1) {
                btnEl.innerText = 'ورود به خانه';
                btnEl.onclick = function() {
                    window.location.href = 'home.php';
                };
            } else {
                btnEl.innerText = 'مرحله بعد';
                btnEl.onclick = function() {
                    nextStep();
                };
            }
        }

        function nextStep() {
            if (currentStep < totalSteps - 1) {
                currentStep++;
                renderStep(currentStep);
            }
        }

        // تولید دکمه‌های نقطه‌ای
        function initDots() {
            dotsContainer.innerHTML = '';
            for (let i = 0; i < totalSteps; i++) {
                const dot = document.createElement('div');
                dot.className = 'dot' + (i === 0 ? ' active' : '');
                dotsContainer.appendChild(dot);
            }
        }

        // ==============================================
        // اسکریپت مدیریت تم (برای اینکه در صفحه مستقل کار کند)
        // ==============================================
        function toggleTheme() {
            const h = document.documentElement;
            const c = h.getAttribute('data-theme');
            const n = c === 'dark' ? 'light' : 'dark';
            h.setAttribute('data-theme', n);
            localStorage.setItem('melkino_theme', n);
            // آیکون تم حذف شده، پس فقط تم را تغییر می‌دهیم
        }

        document.addEventListener('DOMContentLoaded', function() {
            // اعمال تم ذخیره شده
            const savedTheme = localStorage.getItem('melkino_theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);

            // راه‌اندازی صفحات هدایت
            if (totalSteps > 0) {
                initDots();
                renderStep(0);
            } else {
                // اگر هیچ مرحله‌ای تعریف نشده بود، مستقیم به خانه برود
                window.location.href = 'home.php';
            }
        });
    </script>
</body>
</html>