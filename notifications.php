<?php
require_once __DIR__ . '/db_helpers.php';
global $pdo;

if (isset($_GET['action'])) {
    $identity = melkinoCurrentIdentity($_GET['telegram_id'] ?? null);

    if (!$pdo instanceof PDO) {
        melkinoJsonResponse([
            'success' => false,
            'message' => 'اتصال دیتابیس برقرار نیست.'
        ], 500);
    }

    // ===== اکشن count =====
    if ($_GET['action'] === 'count') {
        $conds = [];
        $params = [];

        if (!empty($identity['user_id'])) {
            $conds[] = 'n.user_id = ?';
            $params[] = $identity['user_id'];
        }

        if (($identity['telegram_id'] ?? '') !== '') {
            $conds[] = 'n.telegram_id = ?';
            $params[] = $identity['telegram_id'];
        }

        if (!$conds) {
            melkinoJsonResponse([
                'success' => true,
                'unread' => 0
            ]);
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM notifications n
             WHERE (' . implode(' OR ', $conds) . ')
             AND n.is_read = 0'
        );

        $stmt->execute($params);
        $unread = (int)$stmt->fetchColumn();

        melkinoJsonResponse([
            'success' => true,
            'unread' => $unread
        ]);
    }

    // ===== اکشن send =====
    if ($_GET['action'] === 'send') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            melkinoJsonResponse([
                'success' => false,
                'message' => 'داده نامعتبر'
            ], 400);
        }

        $user_id = $input['user_id'] ?? null;
        $telegram_id = $input['telegram_id'] ?? null;
        $type = $input['type'] ?? 'system';
        $title = $input['title'] ?? '';
        $message = $input['message'] ?? '';
        $url = $input['url'] ?? null;
        $ad_id = $input['ad_id'] ?? null;
        $request_id = $input['request_id'] ?? null;
        $match_percent = $input['match_percent'] ?? null;

        $result = sendNotification(
            $user_id,
            $telegram_id,
            $type,
            $title,
            $message,
            $url,
            $ad_id,
            $request_id,
            $match_percent
        );

        melkinoJsonResponse([
            'success' => $result
        ]);
    }

    // ===== اکشن list =====
    if ($_GET['action'] === 'list') {
        $conds = [];
        $params = [];

        if (!empty($identity['user_id'])) {
            $conds[] = 'n.user_id = ?';
            $params[] = $identity['user_id'];
        }

        if (($identity['telegram_id'] ?? '') !== '') {
            $conds[] = 'n.telegram_id = ?';
            $params[] = $identity['telegram_id'];
        }

        if (!$conds) {
            melkinoJsonResponse([
                'success' => true,
                'notifications' => [],
                'unread' => 0
            ]);
        }

        $stmt = $pdo->prepare(
            'SELECT n.*
             FROM notifications n
             WHERE ' . implode(' OR ', $conds) . '
             ORDER BY n.created_at DESC
             LIMIT 100'
        );

        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $unread = 0;

        foreach ($rows as $r) {
            if ((int)$r['is_read'] === 0) {
                $unread++;
            }
        }

        melkinoJsonResponse([
            'success' => true,
            'notifications' => $rows,
            'unread' => $unread
        ]);
    }

    // ===== اکشن read =====
    if ($_GET['action'] === 'read') {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

        if (!$id) {
            melkinoJsonResponse([
                'success' => false
            ], 422);
        }

        $owners = [];
        $params = [$id];

        if (!empty($identity['user_id'])) {
            $owners[] = 'user_id = ?';
            $params[] = $identity['user_id'];
        }

        if (($identity['telegram_id'] ?? '') !== '') {
            $owners[] = 'telegram_id = ?';
            $params[] = $identity['telegram_id'];
        }

        if (!$owners) {
            melkinoJsonResponse([
                'success' => false
            ], 403);
        }

        $stmt = $pdo->prepare(
            'UPDATE notifications
             SET is_read = 1, read_at = NOW()
             WHERE id = ?
             AND (' . implode(' OR ', $owners) . ')'
        );

        $stmt->execute($params);

        melkinoJsonResponse([
            'success' => true
        ]);
    }

    // ===== اکشن delete =====
    if ($_GET['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

        if (!$id) {
            melkinoJsonResponse([
                'success' => false,
                'message' => 'شناسه اعلان نامعتبر است.'
            ], 422);
        }

        $owners = [];
        $params = [$id];

        if (!empty($identity['user_id'])) {
            $owners[] = 'user_id = ?';
            $params[] = $identity['user_id'];
        }

        if (($identity['telegram_id'] ?? '') !== '') {
            $owners[] = 'telegram_id = ?';
            $params[] = $identity['telegram_id'];
        }

        if (!$owners) {
            melkinoJsonResponse([
                'success' => false,
                'message' => 'دسترسی غیرمجاز.'
            ], 403);
        }

        $stmt = $pdo->prepare(
            'DELETE FROM notifications
             WHERE id = ?
             AND (' . implode(' OR ', $owners) . ')'
        );

        $stmt->execute($params);

        melkinoJsonResponse([
            'success' => $stmt->rowCount() > 0
        ]);
    }

    // ===== اکشن delete_all =====
    if ($_GET['action'] === 'delete_all') {
        $owners = [];
        $params = [];

        if (!empty($identity['user_id'])) {
            $owners[] = 'user_id = ?';
            $params[] = $identity['user_id'];
        }

        if (($identity['telegram_id'] ?? '') !== '') {
            $owners[] = 'telegram_id = ?';
            $params[] = $identity['telegram_id'];
        }

        if (!$owners) {
            melkinoJsonResponse([
                'success' => false
            ], 403);
        }

        $stmt = $pdo->prepare(
            'DELETE FROM notifications
             WHERE ' . implode(' OR ', $owners)
        );

        $stmt->execute($params);

        melkinoJsonResponse([
            'success' => true,
            'deleted' => $stmt->rowCount()
        ]);
    }

    // ===== اکشن mark_all =====
    if ($_GET['action'] === 'mark_all') {
        $owners = [];
        $params = [];

        if (!empty($identity['user_id'])) {
            $owners[] = 'user_id = ?';
            $params[] = $identity['user_id'];
        }

        if (($identity['telegram_id'] ?? '') !== '') {
            $owners[] = 'telegram_id = ?';
            $params[] = $identity['telegram_id'];
        }

        if (!$owners) {
            melkinoJsonResponse([
                'success' => false
            ], 403);
        }

        $stmt = $pdo->prepare(
            'UPDATE notifications
             SET is_read = 1, read_at = NOW()
             WHERE ' . implode(' OR ', $owners)
        );

        $stmt->execute($params);

        melkinoJsonResponse([
            'success' => true
        ]);
    }

    melkinoJsonResponse([
        'success' => false,
        'message' => 'عملیات نامعتبر است.'
    ], 400);
}

require_once __DIR__ . '/header.php';
?>

<style>
/* =========================================================
   Notifications Page
   Responsive + Scroll Fix
   ========================================================= */

* {
    box-sizing: border-box;
}

.notifications-page {
    width: min(100%, 980px);
    margin: 0 auto;
    padding: 22px 18px 130px;

    /*
     * مهم:
     * اجازه اسکرول عمودی صفحه در موبایل و دسکتاپ
     */
    min-height: 100dvh;
    overflow-y: auto;
    overflow-x: hidden;

    -webkit-overflow-scrolling: touch;
    overscroll-behavior-y: contain;
    scroll-behavior: smooth;
}

/* ===== Header ===== */

.notifications-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.notifications-heading {
    min-width: 0;
}

.notifications-title {
    font-size: 24px;
    font-weight: 900;
    color: var(--text-primary);
    line-height: 1.4;
}

.notifications-sub {
    font-size: 13px;
    color: var(--text-secondary);
    margin-top: 5px;
    line-height: 1.8;
}

.notifications-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}

/* ===== Buttons ===== */

.notif-btn {
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text-primary);

    min-height: 40px;
    padding: 8px 14px;

    border-radius: 10px;

    font-family: inherit;
    font-size: 12px;
    font-weight: 700;

    cursor: pointer;

    transition:
        transform .18s ease,
        box-shadow .18s ease,
        border-color .18s ease,
        background .18s ease;
}

.notif-btn:hover {
    transform: translateY(-1px);
    border-color: var(--primary);
    box-shadow: 0 5px 14px rgba(0, 0, 0, .06);
}

.notif-btn:active {
    transform: translateY(0);
}

.notif-btn.primary {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}

/* ===== List ===== */

.notif-list {
    display: flex;
    flex-direction: column;
    gap: 12px;

    width: 100%;

    /*
     * عمداً ارتفاع محدود ندارد
     * تا همه اعلان‌ها قابل اسکرول باشند.
     */
    max-height: none;
    overflow: visible;
}

/* ===== Notification Card ===== */

.notif-card {
    display: block;
    width: 100%;

    text-decoration: none;
    color: inherit;

    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;

    padding: 17px 18px;

    transition:
        border-color .2s ease,
        box-shadow .2s ease,
        transform .2s ease;
}

.notif-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 7px 24px rgba(0, 0, 0, .07);
}

.notif-card.unread {
    border-color: rgba(11, 93, 91, .38);

    background:
        linear-gradient(
            135deg,
            var(--surface),
            rgba(11, 93, 91, .055)
        );
}

.notif-card.notif-welcome {
    border-color: rgba(212, 175, 55, .45);
    background:
        linear-gradient(
            135deg,
            var(--surface),
            rgba(212, 175, 55, .09)
        );
}

.notif-icon {
    font-size: 26px;
    line-height: 1;
    flex-shrink: 0;
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: var(--bg-secondary, rgba(0,0,0,.04));
    margin-inline-end: 12px;
}

.notif-welcome .notif-icon {
    background: rgba(212, 175, 55, .16);
}

/* ===== Main row ===== */

.notif-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;

    gap: 18px;
}

.notif-content {
    min-width: 0;
    flex: 1 1 auto;
}

.notif-title {
    font-size: 15px;
    font-weight: 900;

    color: var(--text-primary);

    line-height: 1.75;

    overflow-wrap: anywhere;
    word-break: break-word;
}

.notif-message {
    font-size: 13px;
    line-height: 1.95;

    color: var(--text-secondary);

    margin-top: 6px;

    overflow-wrap: anywhere;
    word-break: break-word;
}

.notif-code {
    font-size: 10px;
    color: var(--text-secondary);

    margin-top: 6px;

    overflow-wrap: anywhere;
    word-break: break-word;
}

.notif-chip {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    color: var(--text-secondary);
    background: var(--bg-secondary, rgba(0,0,0,.04));
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 2px 8px;
    margin-bottom: 6px;
}

.notif-percent {
    flex: 0 0 auto;

    font-size: 18px;
    font-weight: 900;

    color: var(--primary);

    white-space: nowrap;

    padding-top: 1px;
}

/* ===== Date ===== */

.notif-date {
    font-size: 10px;
    color: var(--text-secondary);

    margin-top: 9px;
}

/* ===== Card footer ===== */

.notif-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;

    gap: 10px;
    margin-top: 12px;
}

.notif-footer-hint {
    font-size: 10px;
    line-height: 1.7;

    color: var(--text-secondary);
}

.notif-delete {
    flex: 0 0 auto;
}

/* ===== Empty ===== */

.notif-empty {
    width: 100%;

    text-align: center;

    padding: 60px 20px;

    color: var(--text-secondary);

    background: var(--surface);

    border: 1px dashed var(--border);
    border-radius: 16px;

    line-height: 1.9;
}

/* =========================================================
   Desktop
   ========================================================= */

@media (min-width: 900px) {
    .notifications-page {
        padding-left: 24px;
        padding-right: 24px;
    }

    .notifications-title {
        font-size: 26px;
    }

    .notif-card {
        padding: 20px 22px;
    }

    .notif-title {
        font-size: 16px;
    }

    .notif-message {
        font-size: 14px;
    }
}

/* =========================================================
   Tablet
   ========================================================= */

@media (max-width: 700px) {
    .notifications-page {
        padding: 16px 12px 120px;
    }

    .notifications-head {
        align-items: flex-start;
    }

    .notifications-title {
        font-size: 21px;
    }

    .notifications-sub {
        font-size: 12px;
    }

    .notif-card {
        border-radius: 14px;
        padding: 14px;
    }
}

/* =========================================================
   Mobile
   ========================================================= */

@media (max-width: 520px) {
    .notifications-page {
        width: 100%;

        padding:
            14px 10px
            calc(105px + env(safe-area-inset-bottom));
    }

    .notifications-head {
        display: block;
        margin-bottom: 14px;
    }

    .notifications-actions {
        width: 100%;
        margin-top: 12px;
    }

    .notifications-actions .notif-btn {
        flex: 1 1 0;
        min-width: 0;
    }

    .notif-card {
        padding: 14px 13px;
    }

    .notif-row {
        gap: 10px;
    }

    .notif-title {
        font-size: 14px;
    }

    .notif-message {
        font-size: 12px;
        line-height: 1.9;
    }

    .notif-code {
        font-size: 9px;
    }

    .notif-percent {
        font-size: 16px;
    }

    .notif-footer {
        align-items: flex-end;
    }

    .notif-footer-hint {
        font-size: 9px;
    }
}

/* =========================================================
   Very Small Mobile
   ========================================================= */

@media (max-width: 360px) {
    .notif-row {
        display: block;
    }

    .notif-percent {
        display: inline-block;
        margin-top: 10px;
    }

    .notif-footer {
        flex-direction: column;
        align-items: stretch;
    }

    .notif-delete {
        width: 100%;
    }
}

/* =========================================================
   Accessibility
   ========================================================= */

.notif-btn:focus-visible,
.notif-card:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

/*
 * اگر قالب اصلی روی body یا html overflow را محدود کرده باشد،
 * این تنظیم کمک می‌کند صفحه اعلان در موبایل/دسکتاپ قابل مشاهده بماند.
 */
html,
body {
    max-width: 100%;
    overflow-x: hidden;
}
</style>

<main class="notifications-page">

    <div class="notifications-head">

        <div class="notifications-heading">
            <div class="notifications-title">
                🔔 اعلان‌ها
            </div>

            <div class="notifications-sub">
                رویدادهای ملک‌های شما، درخواست‌ها و اطلاعیه‌های عمومی ملکینو
            </div>
        </div>

        <div class="notifications-actions">

            <button
                class="notif-btn primary"
                type="button"
                id="markAll"
            >
                خواندن همه
            </button>

            <button
                class="notif-btn"
                type="button"
                id="deleteAll"
            >
                حذف همه
            </button>

        </div>
    </div>

    <div
        id="notificationsList"
        class="notif-list"
    ></div>

</main>

<script>
(function() {

    'use strict';

    const list = document.getElementById('notificationsList');
    const markAllBtn = document.getElementById('markAll');
    const deleteAllBtn = document.getElementById('deleteAll');

    if (!list) {
        return;
    }

    /* =====================================================
       Telegram ID
       ===================================================== */

    const tid = () => {
        return String(
            localStorage.getItem('melkino_telegram_id') ||
            sessionStorage.getItem('reg_telegram_id') ||
            ''
        );
    };

    /* =====================================================
       Escape HTML
       ===================================================== */

    const esc = (value) => {
        return String(value ?? '').replace(
            /[&<>"']/g,
            (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char])
        );
    };

    /* =====================================================
       زمان نسبی فارسی (۵ دقیقه پیش، دیروز، ...)
       ===================================================== */

    const faDigits = (value) => {
        return String(value).replace(
            /[0-9]/g,
            (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]
        );
    };

    const timeAgoFa = (dateStr) => {
        if (!dateStr) return '';
        const d = new Date(String(dateStr).replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return String(dateStr);
        const diff = Math.max(0, Date.now() - d.getTime());
        const min = Math.floor(diff / 60000);
        if (min < 1) return 'لحظاتی پیش';
        if (min < 60) return faDigits(min) + ' دقیقه پیش';
        const h = Math.floor(min / 60);
        if (h < 24) return faDigits(h) + ' ساعت پیش';
        const days = Math.floor(h / 24);
        if (days === 1) return 'دیروز';
        if (days < 7) return faDigits(days) + ' روز پیش';
        if (days < 30) return faDigits(Math.floor(days / 7)) + ' هفته پیش';
        if (days < 365) return faDigits(Math.floor(days / 30)) + ' ماه پیش';
        return faDigits(Math.floor(days / 365)) + ' سال پیش';
    };

    /* =====================================================
       API URL
       ===================================================== */

    const apiUrl = (action) => {
        return 'notifications.php?action=' +
            encodeURIComponent(action) +
            '&telegram_id=' +
            encodeURIComponent(tid());
    };

    /* =====================================================
       Badge
       ===================================================== */

    function setBadgeCount(count) {

        const badge = document.getElementById('notificationBadge');

        if (!badge) {
            return;
        }

        count = Number(count) || 0;

        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.style.display = 'inline-block';
        } else {
            badge.textContent = '';
            badge.style.display = 'none';
        }
    }

    function updateBadgeCount() {

        fetch(apiUrl('count'), {
            cache: 'no-store'
        })
        .then(res => res.json())
        .then(data => {

            const count = Number(data?.unread || 0);

            setBadgeCount(count);

            window.dispatchEvent(
                new CustomEvent(
                    'melkino:notificationUpdate',
                    {
                        detail: {
                            unread: count
                        }
                    }
                )
            );

        })
        .catch(() => {});
    }

    /* =====================================================
       Load Notifications
       ===================================================== */

    async function load() {

        try {

            const res = await fetch(
                apiUrl('list'),
                {
                    cache: 'no-store'
                }
            );

            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }

            const data = await res.json();

            const rows = Array.isArray(data.notifications)
                ? data.notifications
                : [];

            /* ===== Badge ===== */

            const unreadCount = Number(data.unread || 0);

            setBadgeCount(unreadCount);

            window.dispatchEvent(
                new CustomEvent(
                    'melkino:notificationUpdate',
                    {
                        detail: {
                            unread: unreadCount
                        }
                    }
                )
            );

            /* ===== Empty ===== */

            if (!rows.length) {

                list.innerHTML = `
                    <div class="notif-empty">
                        🔕
                        <br>
                        <br>
                        هنوز اعلانی نداری.
                        <br>
                        رویدادهای ملک‌ها، درخواست‌ها و اطلاعیه‌های
                        عمومی ملکینو اینجا نمایش داده می‌شن.
                    </div>
                `;

                return;
            }

            /* ===== Render ===== */

            list.innerHTML = rows.map((n) => {

                const propertyUrl =
                    n.url ||
                    (
                        n.ad_id
                            ? 'property-details.php?id=' + encodeURIComponent(n.ad_id || '')
                            : 'home.php'
                    );

                const hintByType = {
                    welcome: 'برای مشاهده‌ی آگهی‌ها کلیک کنید',
                    match: 'برای مشاهده آگهی کلیک کنید',
                    property_match: 'برای مشاهده آگهی کلیک کنید',
                    broadcast: 'اطلاعیه عمومی ملکینو',
                    ad_submitted: 'برای پیگیری آگهی کلیک کنید',
                    ad_published: 'برای مشاهده آگهی کلیک کنید',
                    ad_rejected: 'برای پیگیری آگهی کلیک کنید',
                    ad_status: 'برای پیگیری آگهی کلیک کنید',
                    request_submitted: 'برای مشاهده درخواست‌ها کلیک کنید',
                    request_status: 'برای مشاهده درخواست‌ها کلیک کنید',
                };
                const footerHint = hintByType[n.type] || 'برای مشاهده کلیک کنید';

                const isUnread = Number(n.is_read) === 0;

                const matchPercent =
                    n.match_percent !== null &&
                    n.match_percent !== '' &&
                    !Number.isNaN(Number(n.match_percent))
                        ? `
                            <div class="notif-percent">
                                ${Number(n.match_percent)}٪
                            </div>
                          `
                        : '';

                const isWelcome = n.type === 'welcome';

                const iconByType = {
                    welcome: '🎉',
                    match: '🏠',
                    property_match: '🏠',
                    broadcast: '📢',
                    ad_submitted: '📝',
                    ad_published: '✅',
                    ad_rejected: '❌',
                    ad_status: '🔄',
                    request_submitted: '📋',
                    request_status: '🔄',
                    system: '🔔',
                };
                const cardIcon = iconByType[n.type] || '🔔';

                const labelByType = {
                    welcome: 'خوش‌آمد',
                    match: 'فایل مناسب',
                    property_match: 'فایل مناسب',
                    broadcast: 'اطلاعیه عمومی',
                    ad_submitted: 'ثبت آگهی',
                    ad_published: 'انتشار آگهی',
                    ad_rejected: 'رد آگهی',
                    ad_status: 'وضعیت آگهی',
                    request_submitted: 'ثبت درخواست',
                    request_status: 'وضعیت درخواست',
                    system: 'سیستمی',
                };
                const typeChip = labelByType[n.type]
                    ? `<span class="notif-chip">${esc(labelByType[n.type])}</span>`
                    : '';

                const codeLine = (!isWelcome && n.request_id)
                    ? `
                        <div class="notif-code">
                            کد رهگیری درخواست:
                            ${esc(n.request_id)}
                        </div>
                      `
                    : '';

                return `
                    <a
                        class="notif-card ${isUnread ? 'unread' : ''} ${isWelcome ? 'notif-welcome' : ''}"
                        href="${esc(propertyUrl)}"
                        data-id="${esc(n.id)}"
                    >

                        <div class="notif-row">

                            <div class="notif-icon">${cardIcon}</div>

                            <div class="notif-content">

                                ${typeChip}

                                <div class="notif-title">
                                    ${esc(
                                        n.title ||
                                        'ملک مناسب برای شما پیدا شد'
                                    )}
                                </div>

                                <div class="notif-message">
                                    ${esc(n.message || '')}
                                </div>

                                ${codeLine}

                            </div>

                            ${matchPercent}

                        </div>

                        <div class="notif-date" title="${esc(n.created_at || '')}">
                            ${timeAgoFa(n.created_at)}
                        </div>

                        <div class="notif-footer">

                            <span class="notif-footer-hint">
                                ${footerHint}
                            </span>

                            <button
                                type="button"
                                class="notif-btn notif-delete"
                                data-delete="${esc(n.id)}"
                            >
                                حذف
                            </button>

                        </div>

                    </a>
                `;

            }).join('');

            bindEvents();

        } catch (error) {

            console.error('Notifications load error:', error);

            list.innerHTML = `
                <div class="notif-empty">
                    ❌
                    <br>
                    <br>
                    خطا در دریافت اعلان‌ها
                </div>
            `;
        }
    }

    /* =====================================================
       Bind Events
       ===================================================== */

    function bindEvents() {

        /* ===== Card click ===== */

        list
            .querySelectorAll('.notif-card')
            .forEach((card) => {

                card.addEventListener(
                    'click',
                    async function(e) {

                        const deleteButton =
                            e.target.closest('[data-delete]');

                        if (deleteButton) {
                            return;
                        }

                        if (!card.classList.contains('unread')) {
                            return;
                        }

                        const id = card.dataset.id;

                        if (!id) {
                            return;
                        }

                        try {

                            await fetch(
                                apiUrl('read'),
                                {
                                    method: 'POST',

                                    headers: {
                                        'Content-Type':
                                            'application/x-www-form-urlencoded'
                                    },

                                    body:
                                        'id=' +
                                        encodeURIComponent(id)
                                }
                            );

                            card.classList.remove('unread');

                            updateBadgeCount();

                        } catch (error) {
                            console.error(
                                'Mark notification read error:',
                                error
                            );
                        }

                    }
                );
            });

        /* ===== Delete buttons ===== */

        list
            .querySelectorAll('[data-delete]')
            .forEach((button) => {

                button.addEventListener(
                    'click',
                    async function(e) {

                        e.preventDefault();
                        e.stopPropagation();

                        const id = button.dataset.delete;

                        if (!id) {
                            return;
                        }

                        if (
                            !window.confirm(
                                'این اعلان حذف شود؟'
                            )
                        ) {
                            return;
                        }

                        button.disabled = true;

                        try {

                            const response = await fetch(
                                apiUrl('delete'),
                                {
                                    method: 'POST',

                                    headers: {
                                        'Content-Type':
                                            'application/x-www-form-urlencoded'
                                    },

                                    body:
                                        'id=' +
                                        encodeURIComponent(id)
                                }
                            );

                            if (!response.ok) {
                                throw new Error(
                                    'HTTP ' +
                                    response.status
                                );
                            }

                            await load();

                            updateBadgeCount();

                        } catch (error) {

                            console.error(
                                'Delete notification error:',
                                error
                            );

                            button.disabled = false;

                        }

                    }
                );

            });
    }

    /* =====================================================
       Mark All
       ===================================================== */

    if (markAllBtn) {

        markAllBtn.addEventListener(
            'click',
            async function() {

                markAllBtn.disabled = true;

                try {

                    const response = await fetch(
                        apiUrl('mark_all'),
                        {
                            method: 'POST'
                        }
                    );

                    if (!response.ok) {
                        throw new Error(
                            'HTTP ' + response.status
                        );
                    }

                    await load();

                    updateBadgeCount();

                } catch (error) {

                    console.error(
                        'Mark all notification error:',
                        error
                    );

                } finally {

                    markAllBtn.disabled = false;

                }
            }
        );
    }

    /* =====================================================
       Delete All
       ===================================================== */

    if (deleteAllBtn) {

        deleteAllBtn.addEventListener(
            'click',
            async function() {

                if (
                    !window.confirm(
                        'همه اعلان‌ها حذف شوند؟'
                    )
                ) {
                    return;
                }

                deleteAllBtn.disabled = true;

                try {

                    const response = await fetch(
                        apiUrl('delete_all'),
                        {
                            method: 'POST'
                        }
                    );

                    if (!response.ok) {
                        throw new Error(
                            'HTTP ' + response.status
                        );
                    }

                    await load();

                    updateBadgeCount();

                } catch (error) {

                    console.error(
                        'Delete all notifications error:',
                        error
                    );

                } finally {

                    deleteAllBtn.disabled = false;

                }
            }
        );
    }

    /* =====================================================
       External Badge Update
       ===================================================== */

    window.addEventListener(
        'melkino:notificationUpdate',
        function(event) {

            const count = Number(
                event?.detail?.unread || 0
            );

            setBadgeCount(count);
        }
    );

    /* =====================================================
       Initial Load
       ===================================================== */

    load();

    setTimeout(
        updateBadgeCount,
        300
    );

})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
```
