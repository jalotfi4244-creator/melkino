<?php
// هدر مشترک ملکینو
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" id="melkinoRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>ملکینو</title>

    <!--
        بدون این اسکریپت رسمیِ خودِ تلگرام، شیء window.Telegram.WebApp
        اصلاً وجود نداره — حتی اگه سایت از داخل خودِ تلگرام باز بشه.
        همین یک خط، دلیل اصلیِ ثبت‌نشدنِ آیدی تلگرام کاربرها بود.
    -->
    <script src="https://telegram.org/js/telegram-web-app.js"></script>

    <!-- همین موضوع برای بله: بدون این اسکریپت، window.Bale.WebApp وجود نداره -->
    <script src="https://tapi.bale.ai/miniapp.js?3"></script>

    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <link rel="stylesheet" href="style.css">

    <!-- Theme -->
    <script>
    (function () {
        try {
            var savedTheme = localStorage.getItem('melkino_theme');
            var theme = savedTheme === 'dark' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', theme);
        } catch (e) {
            document.documentElement.setAttribute('data-theme', 'light');
        }

        window.melkinoTheme = {
            get: function () {
                return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            },
            set: function (theme) {
                theme = theme === 'dark' ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', theme);
                try {
                    localStorage.setItem('melkino_theme', theme);
                } catch (e) {}
                window.dispatchEvent(new CustomEvent('melkino:themechange', { detail: { theme: theme } }));
            },
            toggle: function () {
                this.set(this.get() === 'dark' ? 'light' : 'dark');
            }
        };

        window.toggleTheme = function () {
            window.melkinoTheme.toggle();
        };
    })();
    </script>
</head>
<body>
    <div class="app-container">
        <header class="topbar">
            <div style="display:flex; align-items:center; gap:var(--space-2); min-width:0; flex:1;">
                <!-- منو همبرگری -->
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>

                <?php if (!empty($_SESSION['is_admin'])): ?>
                <!-- آیکون مدیریت (فقط برای ادمینی که واقعاً وارد شده) -->
                <a href="admin-panel.php" style="display:flex; align-items:center; color:var(--primary); text-decoration:none; transition:0.2s; flex-shrink:0;" title="ورود به پنل مدیریت">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"></path><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.6h.09A1.65 1.65 0 0 0 10 3.09V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09A1.65 1.65 0 0 0 15 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.09A1.65 1.65 0 0 0 20.91 10H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09A1.65 1.65 0 0 0 19.4 15z"></path></svg>
                </a>
                <?php endif; ?>

                <!-- ===== لوگو (مسیر مستقیم) ===== -->
                <a href="home.php" style="display:flex; align-items:center; text-decoration:none; max-width:180px; flex-shrink:1; min-width:0;">
                    <img src="uploads/onboarding-logo.png" alt="ملکینو" style="height:44px; width:auto; max-width:100%; max-height:52px; object-fit:contain;">
                </a>
            </div>

            <div style="display:flex; gap:var(--space-2); align-items:center; flex-shrink:0;">
                <!-- تغییر تم -->
                <button class="theme-toggle-btn" onclick="toggleTheme()" id="themeBtn" type="button" title="تغییر تم" aria-label="تغییر تم">
                    <svg id="themeIcon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></svg>
                </button>

                <!-- نوتیفیکیشن -->
                <a href="notifications.php" class="notif-wrapper" style="text-decoration:none; color:inherit; position:relative;">
                    <div style="position:relative; cursor:pointer;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                        <span class="notif-badge" id="notifBadge" style="display:none; position:absolute; top:-6px; right:-8px; background:var(--danger, #dc3545); color:#fff; border-radius:50%; font-size:10px; padding:1px 6px; min-width:18px; text-align:center; line-height:1.5;">0</span>
                    </div>
                </a>

                <!-- پروفایل -->
                <a href="profile.php" style="display:flex; align-items:center; color:var(--text-secondary);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                </a>
            </div>
        </header>

        <script>
        (function () {
            window.updateThemeButton = function () {
                var icon = document.getElementById('themeIcon');
                if (!icon) return;
                var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                icon.innerHTML = isDark
                    ? '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>'
                    : '<circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="19.78" y1="4.22" x2="18.36" y2="5.64"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="19.78" y1="19.78" x2="18.36" y2="18.36"></line>';
                document.getElementById('themeBtn')?.setAttribute('aria-pressed', isDark ? 'true' : 'false');
                document.getElementById('themeBtn')?.setAttribute('title', isDark ? 'فعال‌سازی حالت روشن' : 'فعال‌سازی حالت تاریک');
            };

            window.addEventListener('melkino:themechange', window.updateThemeButton);
            document.addEventListener('DOMContentLoaded', window.updateThemeButton);
        })();

        // ===== به‌روزرسانی خودکار تعداد نوتیفیکیشن‌ها =====
        (function() {
            function updateNotificationBadge() {
                var tid = localStorage.getItem('melkino_telegram_id') || sessionStorage.getItem('reg_telegram_id') || '';
                var badge = document.getElementById('notifBadge');
                if (!badge) return;
                
                fetch('notifications.php?action=count&telegram_id=' + encodeURIComponent(tid))
                    .then(res => res.json())
                    .then(data => {
                        var count = data.unread || 0;
                        if (count > 0) {
                            badge.textContent = count > 99 ? '99+' : count;
                            badge.style.display = 'inline-block';
                        } else {
                            badge.style.display = 'none';
                        }
                    })
                    .catch(function() {
                        badge.style.display = 'none';
                    });
            }

            // بارگذاری اولیه
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', updateNotificationBadge);
            } else {
                updateNotificationBadge();
            }

            // هر ۳۰ ثانیه به‌روزرسانی
            setInterval(updateNotificationBadge, 30000);

            // گوش دادن به رویداد برای به‌روزرسانی فوری
            window.addEventListener('melkino:notificationUpdate', updateNotificationBadge);
        })();
        </script>

        <script>
        /*
         * جدول visits و endpoint مربوطش از قبل توی پروژه بودن ولی هیچ
         * صفحه‌ای صداشون نمی‌زد؛ یعنی حتی یک بازدید هم ثبت نمی‌شد.
         * این اسکریپت روی همه‌ی صفحات، هر بازدید رو ثبت می‌کنه —
         * حتی بازدیدکننده‌ی کاملاً ناشناسی که نه شماره داده نه از
         * تلگرام اومده؛ IP و User-Agent و صفحه‌ی بازدیدشده ثبت می‌شه.
         */
        (function() {
            try {
                var telegramId = localStorage.getItem('melkino_telegram_id') || sessionStorage.getItem('reg_telegram_id') || '';
                var urlParams = new URLSearchParams(window.location.search);
                fetch('page-visits.php?action=hit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        path: window.location.pathname,
                        ad_id: urlParams.get('id') || '',
                        telegram_id: telegramId
                    })
                }).catch(function() {});
            } catch (e) {}
        })();
        </script>

        <script>
        window.NOTIF_STORAGE_KEY = window.NOTIF_STORAGE_KEY || 'melkino_notifications';
        </script>

        <script>
        /*
         * قبلاً فقط بعد از پر کردن فرم ثبت ملک/درخواست، آی‌دی تلگرام
         * کاربر ذخیره می‌شد. یعنی کاربری که فقط مینی‌اپ را باز می‌کرد
         * و مرور می‌کرد (بدون ثبت فرم)، هیچ‌وقت در دیتابیس ثبت نمی‌شد
         * و صفحه‌ی پروفایلش همیشه خالی می‌ماند. این اسکریپت روی همه‌ی
         * صفحات اجرا می‌شود و همان لحظه‌ی باز شدن سایت، کاربر را هم
         * در مرورگر (localStorage) و هم در دیتابیس ثبت می‌کند —
         * چه از داخل تلگرام باز شده باشد، چه از داخل بله، چه از یک
         * مرورگر عادی که قبلاً یک بار شماره‌اش را وارد کرده.
         */
        (function() {
            try {
                var tg = window.Telegram && window.Telegram.WebApp;
                var tgInitData = (tg && tg.initData) || '';
                var tgUser = tg && tg.initDataUnsafe && tg.initDataUnsafe.user;

                var bl = window.Bale && window.Bale.WebApp;
                var blInitData = (bl && bl.initData) || '';
                var blUser = bl && bl.initDataUnsafe && bl.initDataUnsafe.user;

                // این مقادیر محلی فقط برای نمایش سریع در همین مرورگر است
                // (مثل نشان اعلان‌ها)؛ به‌عنوان مرجع هویت به سرور فرستاده
                // نمی‌شوند — سرور خودش initData خام را با امضای
                // رمزنگاری‌شده‌ی هر پیام‌رسان بررسی می‌کند.
                if (tgUser && tgUser.id) {
                    localStorage.setItem('melkino_telegram_id', String(tgUser.id));
                    sessionStorage.setItem('reg_telegram_id', String(tgUser.id));
                }
                if (blUser && blUser.id) {
                    localStorage.setItem('melkino_bale_id', String(blUser.id));
                }

                var phone = localStorage.getItem('melkino_user_phone') || '';

                var endpoint, payload, identifyKey;

                if (tgInitData) {
                    endpoint = 'api/auth-telegram.php';
                    payload = { init_data: tgInitData };
                    identifyKey = 'tg:' + (tgUser && tgUser.id || '');
                } else if (blInitData) {
                    endpoint = 'api/auth-bale.php';
                    payload = { init_data: blInitData };
                    identifyKey = 'bl:' + (blUser && blUser.id || '');
                } else if (phone) {
                    endpoint = 'identity-sync.php';
                    payload = { phone: phone };
                    identifyKey = phone;
                } else {
                    return;
                }

                if (sessionStorage.getItem('melkino_identified') === identifyKey) return;

                fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }).then(function(r) { return r.json(); })
                  .then(function(data) {
                        sessionStorage.setItem('melkino_identified', identifyKey);
                        if (data && data.phone) {
                            localStorage.setItem('melkino_user_phone', data.phone);
                        }
                        if (!data || !data.success) {
                            // امضا تأیید نشد یا شماره متعلق به کس دیگری بود؛
                            // مقدار محلیِ نمایشی هم پاک می‌شود تا گمراه‌کننده نباشد.
                            if (data && data.status === 'phone_taken') {
                                localStorage.removeItem('melkino_user_phone');
                            }
                        }
                  })
                  .catch(function() {});
            } catch (e) {}
        })();
        </script>