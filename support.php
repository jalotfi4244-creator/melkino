<?php

/*
|--------------------------------------------------------------------------
| MELKINO SUPPORT / TICKET SYSTEM
|--------------------------------------------------------------------------
| کاربر:
| - ایجاد تیکت
| - مشاهده تیکت‌های قبلی
| - مشاهده گفتگو
| - ارسال پیام جدید
|
| ادمین:
| - پاسخ‌ها در همین تیکت از طریق admin-support.php
|
| این فایل مستقل است و به پنل بزرگ admin-panel.php وابسته نیست.
|--------------------------------------------------------------------------
*/

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    die('اتصال به دیتابیس برقرار نیست.');
}

/* =========================================================
   IDENTITY
   ========================================================= */

$identity = melkinoCurrentIdentity();

$userId = !empty($identity['user_id'])
    ? trim((string)$identity['user_id'])
    : '';

$telegramId = !empty($identity['telegram_id'])
    ? trim((string)$identity['telegram_id'])
    : '';

$userName = !empty($identity['user']['name'])
    ? trim((string)$identity['user']['name'])
    : trim((string)($_SESSION['user_name'] ?? ''));

$userPhone = !empty($identity['phone'])
    ? trim((string)$identity['phone'])
    : trim((string)($_SESSION['user_phone'] ?? ''));

if ($userName === '') {
    $userName = 'کاربر ملکینو';
}

/*
 * اگر هیچ شناسه‌ای نداریم، کاربر لاگین/شناسایی نشده است.
 */
if ($userId === '' && $telegramId === '' && $userPhone === '') {
    http_response_code(401);
    die('برای استفاده از پشتیبانی ابتدا وارد حساب کاربری شوید.');
}

/* =========================================================
   DATABASE TABLES
   ========================================================= */

if (!melkinoEnsureSupportTables()) {

    http_response_code(500);

    echo '<div style="
        direction:rtl;
        font-family:Tahoma,sans-serif;
        padding:30px;
        color:#b91c1c;
        background:#fff5f5;
        margin:20px;
        border-radius:15px;
    ">';

    echo '<h3>خطا در آماده‌سازی سیستم پشتیبانی</h3>';
    echo '<p>ارتباط با دیتابیس برقرار نشد یا جدول‌های پشتیبانی ساخته نشدند.</p>';

    echo '</div>';

    exit;
}

/* =========================================================
   CSRF
   ========================================================= */

if (empty($_SESSION['support_csrf'])) {
    $_SESSION['support_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['support_csrf'];

/* =========================================================
   HELPERS
   ========================================================= */

function supportCurrentUserWhere(&$params)
{
    global $userId, $telegramId, $userPhone;

    $parts = [];

    if ($userId !== '') {
        $parts[] = 'user_id = ?';
        $params[] = $userId;
    }

    if ($telegramId !== '') {
        $parts[] = 'telegram_id = ?';
        $params[] = $telegramId;
    }

    if ($userPhone !== '') {
        $normalizedPhone = preg_replace('/[\s\-\(\)]/', '', $userPhone);

        $parts[] = "
            REPLACE(
                REPLACE(
                    REPLACE(phone, ' ', ''),
                    '-',
                    ''
                ),
                '(',
                ''
            ) = ?
        ";

        $params[] = $normalizedPhone;
    }

    if (!$parts) {
        $parts[] = '1 = 0';
    }

    return '(' . implode(' OR ', $parts) . ')';
}


function supportTicketBelongsToUser($ticketId)
{
    global $pdo;

    $params = [];

    $where = supportCurrentUserWhere($params);

    $params[] = (int)$ticketId;

    $stmt = $pdo->prepare("
        SELECT *
        FROM support_tickets
        WHERE $where
          AND id = ?
        LIMIT 1
    ");

    $stmt->execute($params);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}


function supportStatusLabel($status)
{
    switch ($status) {

        case 'open':
            return 'در انتظار بررسی';

        case 'answered':
            return 'پاسخ داده شده';

        case 'pending':
            return 'در حال پیگیری';

        case 'closed':
            return 'بسته شده';

        default:
            return 'در انتظار بررسی';
    }
}


function supportStatusClass($status)
{
    switch ($status) {

        case 'open':
            return 'status-open';

        case 'answered':
            return 'status-answered';

        case 'pending':
            return 'status-pending';

        case 'closed':
            return 'status-closed';

        default:
            return 'status-open';
    }
}


/* =========================================================
   POST ACTIONS
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json; charset=utf-8');

    try {

        $postedToken = (string)($_POST['csrf_token'] ?? '');

        if (
            $postedToken === '' ||
            !hash_equals($csrfToken, $postedToken)
        ) {
            throw new RuntimeException('درخواست نامعتبر است. صفحه را مجدداً باز کنید.');
        }

        $action = trim((string)($_POST['action'] ?? ''));

        /* =====================================================
           CREATE TICKET
           ===================================================== */

        if ($action === 'create_ticket') {

            $subject = trim((string)($_POST['subject'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));

            if ($subject === '') {
                throw new RuntimeException('لطفاً موضوع درخواست را وارد کنید.');
            }

            if (mb_strlen($subject) > 255) {
                throw new RuntimeException('موضوع درخواست بیش از حد طولانی است.');
            }

            if ($message === '') {
                throw new RuntimeException('لطفاً مشکل یا سوال خود را بنویسید.');
            }

            if (mb_strlen($message) > 10000) {
                throw new RuntimeException('متن پیام بیش از حد طولانی است.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO support_tickets
                (
                    user_id,
                    telegram_id,
                    phone,
                    user_name,
                    subject,
                    status
                )
                VALUES (?, ?, ?, ?, ?, 'open')
            ");

            $stmt->execute([
                $userId !== '' ? $userId : null,
                $telegramId !== '' ? $telegramId : null,
                $userPhone !== '' ? $userPhone : null,
                $userName,
                $subject
            ]);

            $ticketId = (int)$pdo->lastInsertId();

            $senderId = $userId !== ''
                ? $userId
                : ($telegramId !== '' ? $telegramId : $userPhone);

            $stmt = $pdo->prepare("
                INSERT INTO support_messages
                (
                    ticket_id,
                    sender_type,
                    sender_id,
                    sender_name,
                    message,
                    is_read
                )
                VALUES (?, 'user', ?, ?, ?, 0)
            ");

            $stmt->execute([
                $ticketId,
                $senderId !== '' ? $senderId : null,
                $userName,
                $message
            ]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'درخواست شما با موفقیت برای پشتیبانی ارسال شد.',
                'ticket_id' => $ticketId
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }


        /* =====================================================
           SEND MESSAGE
           ===================================================== */

        if ($action === 'send_message') {

            $ticketId = (int)($_POST['ticket_id'] ?? 0);
            $message = trim((string)($_POST['message'] ?? ''));

            if ($ticketId <= 0) {
                throw new RuntimeException('شناسه درخواست نامعتبر است.');
            }

            if ($message === '') {
                throw new RuntimeException('لطفاً پیام خود را وارد کنید.');
            }

            if (mb_strlen($message) > 10000) {
                throw new RuntimeException('متن پیام بیش از حد طولانی است.');
            }

            $ticket = supportTicketBelongsToUser($ticketId);

            if (!$ticket) {
                throw new RuntimeException('این درخواست پشتیبانی متعلق به شما نیست.');
            }

            if ($ticket['status'] === 'closed') {
                throw new RuntimeException(
                    'این درخواست بسته شده است. برای مشکل جدید یک درخواست جدید ایجاد کنید.'
                );
            }

            $pdo->beginTransaction();

            $senderId = $userId !== ''
                ? $userId
                : ($telegramId !== '' ? $telegramId : $userPhone);

            $stmt = $pdo->prepare("
                INSERT INTO support_messages
                (
                    ticket_id,
                    sender_type,
                    sender_id,
                    sender_name,
                    message,
                    is_read
                )
                VALUES (?, 'user', ?, ?, ?, 0)
            ");

            $stmt->execute([
                $ticketId,
                $senderId !== '' ? $senderId : null,
                $userName,
                $message
            ]);

            $stmt = $pdo->prepare("
                UPDATE support_tickets
                SET status = 'open',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            $stmt->execute([$ticketId]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'پیام شما ارسال شد.'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }


        /* =====================================================
           CLOSE TICKET
           ===================================================== */

        if ($action === 'close_ticket') {

            $ticketId = (int)($_POST['ticket_id'] ?? 0);

            if ($ticketId <= 0) {
                throw new RuntimeException('شناسه درخواست نامعتبر است.');
            }

            $ticket = supportTicketBelongsToUser($ticketId);

            if (!$ticket) {
                throw new RuntimeException('درخواست پیدا نشد.');
            }

            $stmt = $pdo->prepare("
                UPDATE support_tickets
                SET status = 'closed',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            $stmt->execute([$ticketId]);

            echo json_encode([
                'success' => true,
                'message' => 'درخواست پشتیبانی بسته شد.'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }


        throw new RuntimeException('عملیات نامعتبر است.');

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}


/* =========================================================
   SELECT TICKETS
   ========================================================= */

$userParams = [];

$userWhere = supportCurrentUserWhere($userParams);

$stmt = $pdo->prepare("
    SELECT
        t.*,
        (
            SELECT COUNT(*)
            FROM support_messages sm
            WHERE sm.ticket_id = t.id
        ) AS message_count
    FROM support_tickets t
    WHERE $userWhere
    ORDER BY t.updated_at DESC, t.id DESC
");

$stmt->execute($userParams);

$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   SELECT ACTIVE TICKET
   ========================================================= */

$activeTicketId = (int)($_GET['ticket'] ?? 0);

$activeTicket = null;
$activeMessages = [];

if ($activeTicketId > 0) {

    $activeTicket = supportTicketBelongsToUser($activeTicketId);

    if ($activeTicket) {

        $stmt = $pdo->prepare("
            SELECT *
            FROM support_messages
            WHERE ticket_id = ?
            ORDER BY id ASC
        ");

        $stmt->execute([$activeTicketId]);

        $activeMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}


/* =========================================================
   HEADER
   ========================================================= */

require_once __DIR__ . '/header.php';

?>

<style>

    .main-content {
        flex: 1;
        overflow-y: auto;
        padding:
            0
            var(--space-3)
            100px
            var(--space-3);
        background: var(--bg);
    }

    .support-page {
        width: 100%;
        max-width: 1050px;
        margin: 0 auto;
    }

    .support-hero {
        margin-top: 12px;
        padding: 22px;
        border-radius: 22px;
        background:
            linear-gradient(
                135deg,
                #174e48,
                #0b3531
            );
        color: #fff;
        box-shadow: 0 15px 35px rgba(8,45,41,.18);
        position: relative;
        overflow: hidden;
    }

    .support-hero::after {
        content: "";
        position: absolute;
        width: 180px;
        height: 180px;
        left: -70px;
        bottom: -100px;
        border-radius: 50%;
        background: rgba(215,180,96,.08);
    }

    .support-hero-content {
        position: relative;
        z-index: 2;
    }

    .support-hero h1 {
        margin: 0;
        font-size: 23px;
        font-weight: 850;
    }

    .support-hero p {
        margin: 7px 0 0;
        color: rgba(255,255,255,.72);
        font-size: 12px;
        line-height: 1.8;
    }

    .support-layout {
        display: grid;
        grid-template-columns: 310px minmax(0,1fr);
        gap: 14px;
        margin-top: 14px;
        align-items: start;
    }

    .support-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 18px;
        box-shadow: var(--shadow-card);
        overflow: hidden;
    }

    .support-card-header {
        padding: 15px 16px;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }

    .support-card-header h2 {
        margin: 0;
        color: var(--text-primary);
        font-size: 14px;
        font-weight: 850;
    }

    .support-new-btn {
        border: 0;
        border-radius: 10px;
        min-height: 36px;
        padding: 0 12px;
        background: var(--primary);
        color: #fff;
        font-family: 'Vazirmatn', sans-serif;
        font-size: 11px;
        font-weight: 800;
        cursor: pointer;
    }

    .ticket-list {
        padding: 8px;
    }

    .ticket-item {
        display: block;
        padding: 13px;
        margin-bottom: 7px;
        border-radius: 13px;
        border: 1px solid transparent;
        background: var(--bg);
        text-decoration: none;
        color: inherit;
        transition: .2s ease;
    }

    .ticket-item:last-child {
        margin-bottom: 0;
    }

    .ticket-item:hover {
        border-color: rgba(191,157,87,.35);
        transform: translateY(-1px);
    }

    .ticket-item.active {
        border-color: rgba(6,78,78,.28);
        background: rgba(6,78,78,.06);
    }

    .ticket-item-top {
        display: flex;
        justify-content: space-between;
        gap: 8px;
        align-items: center;
    }

    .ticket-subject {
        min-width: 0;
        color: var(--text-primary);
        font-size: 12px;
        font-weight: 800;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ticket-date {
        flex: 0 0 auto;
        color: var(--text-secondary);
        font-size: 9px;
        direction: ltr;
    }

    .ticket-meta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-top: 8px;
    }

    .ticket-count {
        color: var(--text-secondary);
        font-size: 9px;
    }

    .ticket-status {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 4px 8px;
        font-size: 8px;
        font-weight: 800;
    }

    .status-open {
        color: #92400e;
        background: #fef3c7;
    }

    .status-answered {
        color: #075985;
        background: #e0f2fe;
    }

    .status-pending {
        color: #5b21b6;
        background: #ede9fe;
    }

    .status-closed {
        color: #166534;
        background: #dcfce7;
    }

    .empty-tickets {
        padding: 35px 20px;
        text-align: center;
        color: var(--text-secondary);
        font-size: 11px;
        line-height: 2;
    }

    .empty-tickets-icon {
        font-size: 32px;
        margin-bottom: 7px;
    }

    .conversation {
        min-height: 500px;
        display: flex;
        flex-direction: column;
    }

    .conversation-header {
        padding: 15px 17px;
        border-bottom: 1px solid var(--border);
    }

    .conversation-header h2 {
        margin: 0;
        color: var(--text-primary);
        font-size: 15px;
        font-weight: 850;
    }

    .conversation-header-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 7px;
    }

    .conversation-body {
        flex: 1;
        padding: 16px;
        background:
            radial-gradient(
                circle at top right,
                rgba(201,166,95,.045),
                transparent 35%
            );
    }

    .message {
        display: flex;
        margin-bottom: 12px;
    }

    .message.user {
        justify-content: flex-start;
    }

    .message.admin {
        justify-content: flex-end;
    }

    .message-bubble {
        max-width: 78%;
        padding: 11px 13px;
        border-radius: 15px;
        font-size: 12px;
        line-height: 1.9;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .message.user .message-bubble {
        background: var(--bg);
        border: 1px solid var(--border);
        color: var(--text-primary);
        border-bottom-right-radius: 5px;
    }

    .message.admin .message-bubble {
        background: var(--primary);
        color: #fff;
        border-bottom-left-radius: 5px;
    }

    .message-name {
        margin-bottom: 3px;
        font-size: 9px;
        font-weight: 800;
        opacity: .72;
    }

    .message-time {
        margin-top: 5px;
        font-size: 8px;
        opacity: .58;
        direction: ltr;
    }

    .conversation-form {
        padding: 12px;
        border-top: 1px solid var(--border);
        background: var(--surface);
    }

    .conversation-form textarea {
        width: 100%;
        min-height: 80px;
        resize: vertical;
        box-sizing: border-box;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 11px;
        background: var(--bg);
        color: var(--text-primary);
        font-family: 'Vazirmatn', sans-serif;
        font-size: 12px;
        outline: none;
    }

    .conversation-form textarea:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(6,78,78,.08);
    }

    .conversation-form-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-top: 8px;
    }

    .conversation-hint {
        color: var(--text-secondary);
        font-size: 9px;
    }

    .send-btn {
        min-height: 40px;
        padding: 0 16px;
        border: 0;
        border-radius: 11px;
        background: var(--primary);
        color: #fff;
        font-family: 'Vazirmatn', sans-serif;
        font-size: 11px;
        font-weight: 800;
        cursor: pointer;
    }

    .send-btn:disabled {
        opacity: .55;
        cursor: not-allowed;
    }

    .closed-box {
        padding: 13px;
        border-radius: 11px;
        background: rgba(22,101,52,.06);
        color: #166534;
        font-size: 10px;
        line-height: 1.8;
        text-align: center;
    }

    .new-ticket-form {
        padding: 16px;
    }

    .form-group {
        margin-bottom: 13px;
    }

    .form-group label {
        display: block;
        margin-bottom: 6px;
        color: var(--text-primary);
        font-size: 11px;
        font-weight: 800;
    }

    .form-control {
        width: 100%;
        box-sizing: border-box;
        min-height: 44px;
        border: 1px solid var(--border);
        border-radius: 11px;
        padding: 9px 11px;
        background: var(--bg);
        color: var(--text-primary);
        font-family: 'Vazirmatn', sans-serif;
        font-size: 12px;
        outline: none;
    }

    textarea.form-control {
        min-height: 120px;
        resize: vertical;
    }

    .form-control:focus {
        border-color: var(--primary);
    }

    .form-actions {
        display: flex;
        gap: 8px;
    }

    .form-btn {
        flex: 1;
        min-height: 44px;
        border-radius: 11px;
        font-family: 'Vazirmatn', sans-serif;
        font-size: 11px;
        font-weight: 800;
        cursor: pointer;
    }

    .form-btn.cancel {
        border: 1px solid var(--border);
        background: var(--bg);
        color: var(--text-secondary);
    }

    .form-btn.submit {
        border: 0;
        background: var(--primary);
        color: #fff;
    }

    .info-box {
        margin-top: 14px;
        padding: 15px;
        border-radius: 15px;
        background: var(--surface);
        border: 1px solid var(--border);
        box-shadow: var(--shadow-card);
    }

    .info-box h3 {
        margin: 0 0 8px;
        color: var(--text-primary);
        font-size: 13px;
        font-weight: 850;
    }

    .info-box p {
        margin: 0;
        color: var(--text-secondary);
        font-size: 10px;
        line-height: 1.9;
    }

    .toast {
        position: fixed;
        left: 20px;
        bottom: 90px;
        z-index: 9999;
        max-width: 330px;
        padding: 12px 15px;
        border-radius: 12px;
        background: #173f3a;
        color: #fff;
        box-shadow: 0 12px 30px rgba(0,0,0,.2);
        font-size: 11px;
        display: none;
    }

    .toast.show {
        display: block;
        animation: supportToastIn .2s ease;
    }

    @keyframes supportToastIn {
        from {
            opacity: 0;
            transform: translateY(8px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @media (max-width: 760px) {

        .support-layout {
            grid-template-columns: 1fr;
        }

        .conversation {
            min-height: 430px;
        }

        .message-bubble {
            max-width: 88%;
        }
    }

    @media (max-width: 500px) {

        .main-content {
            padding-left: 10px;
            padding-right: 10px;
            padding-bottom: 130px;
        }

        .support-hero {
            padding: 18px;
        }

        .support-hero h1 {
            font-size: 20px;
        }

        .conversation-body {
            padding: 12px;
        }

        .conversation-form-footer {
            align-items: stretch;
            flex-direction: column;
        }

        .send-btn {
            width: 100%;
        }

    }

</style>


<div class="main-content">

    <div class="support-page">

        <!-- =====================================================
             HERO
        ====================================================== -->

        <section class="support-hero">

            <div class="support-hero-content">

                <h1>
                    پشتیبانی ملکینو
                </h1>

                <p>
                    سوال یا مشکلی دارید؟ درخواست خود را برای تیم پشتیبانی ارسال کنید.
                    پاسخ شما در همین صفحه قابل مشاهده خواهد بود.
                </p>

            </div>

        </section>


        <!-- =====================================================
             LAYOUT
        ====================================================== -->

        <div class="support-layout">

            <!-- =================================================
                 TICKET LIST
            ================================================== -->

            <section class="support-card">

                <div class="support-card-header">

                    <h2>
                        درخواست‌های من
                    </h2>

                    <button
                        type="button"
                        class="support-new-btn"
                        onclick="showNewTicket()"
                    >
                        + درخواست جدید
                    </button>

                </div>


                <div class="ticket-list">

                    <?php if (!$tickets): ?>

                        <div class="empty-tickets">

                            <div class="empty-tickets-icon">
                                💬
                            </div>

                            هنوز درخواست پشتیبانی ثبت نکرده‌اید.

                            <br>

                            برای شروع روی «درخواست جدید» بزنید.

                        </div>

                    <?php else: ?>

                        <?php foreach ($tickets as $ticket): ?>

                            <?php

                            $isActive =
                                $activeTicket &&
                                (int)$activeTicket['id'] === (int)$ticket['id'];

                            $subject = trim((string)$ticket['subject']);

                            if ($subject === '') {
                                $subject = 'درخواست پشتیبانی';
                            }

                            ?>

                            <a
                                href="support.php?ticket=<?= (int)$ticket['id'] ?>"
                                class="ticket-item <?= $isActive ? 'active' : '' ?>"
                            >

                                <div class="ticket-item-top">

                                    <div class="ticket-subject">

                                        <?= htmlspecialchars(
                                            $subject,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    </div>

                                    <div class="ticket-date">

                                        <?= htmlspecialchars(
                                            date(
                                                'Y/m/d',
                                                strtotime($ticket['updated_at'])
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    </div>

                                </div>


                                <div class="ticket-meta">

                                    <span
                                        class="ticket-status <?= htmlspecialchars(
                                            supportStatusClass($ticket['status']),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                    >

                                        <?= htmlspecialchars(
                                            supportStatusLabel($ticket['status']),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    </span>


                                    <span class="ticket-count">

                                        <?= (int)$ticket['message_count'] ?>
                                        پیام

                                    </span>

                                </div>

                            </a>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </section>


            <!-- =================================================
                 CONVERSATION
            ================================================== -->

            <section class="support-card conversation">

                <?php if ($activeTicket): ?>

                    <div class="conversation-header">

                        <h2>

                            <?= htmlspecialchars(
                                $activeTicket['subject'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </h2>


                        <div class="conversation-header-meta">

                            <span
                                class="ticket-status <?= htmlspecialchars(
                                    supportStatusClass($activeTicket['status']),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >

                                <?= htmlspecialchars(
                                    supportStatusLabel($activeTicket['status']),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </span>

                            <span
                                style="
                                    color:var(--text-secondary);
                                    font-size:9px;
                                "
                            >
                                درخواست #<?= (int)$activeTicket['id'] ?>
                            </span>

                        </div>

                    </div>


                    <div class="conversation-body">

                        <?php if (!$activeMessages): ?>

                            <div
                                style="
                                    text-align:center;
                                    color:var(--text-secondary);
                                    padding:50px 10px;
                                    font-size:11px;
                                "
                            >
                                هنوز پیامی در این درخواست وجود ندارد.
                            </div>

                        <?php else: ?>

                            <?php foreach ($activeMessages as $message): ?>

                                <?php
                                $isAdmin =
                                    $message['sender_type'] === 'admin';

                                ?>

                                <div
                                    class="message <?= $isAdmin ? 'admin' : 'user' ?>"
                                >

                                    <div class="message-bubble">

                                        <div class="message-name">

                                            <?= $isAdmin
                                                ? 'پشتیبانی ملکینو'
                                                : 'شما'
                                            ?>

                                        </div>


                                        <?= nl2br(
                                            htmlspecialchars(
                                                (string)$message['message'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            )
                                        ) ?>


                                        <div class="message-time">

                                            <?= htmlspecialchars(
                                                date(
                                                    'Y/m/d H:i',
                                                    strtotime($message['created_at'])
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                        </div>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>


                    <div class="conversation-form">

                        <?php if ($activeTicket['status'] === 'closed'): ?>

                            <div class="closed-box">

                                این درخواست توسط پشتیبانی بسته شده است.

                                <br>

                                اگر مشکل جدیدی دارید، یک درخواست جدید ایجاد کنید.

                            </div>

                        <?php else: ?>

                            <form
                                id="replyForm"
                                onsubmit="sendReply(event)"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= htmlspecialchars(
                                        $csrfToken,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="send_message"
                                >

                                <input
                                    type="hidden"
                                    name="ticket_id"
                                    value="<?= (int)$activeTicket['id'] ?>"
                                >


                                <textarea
                                    name="message"
                                    id="replyMessage"
                                    placeholder="پیام خود را برای پشتیبانی بنویسید..."
                                    maxlength="10000"
                                    required
                                ></textarea>


                                <div class="conversation-form-footer">

                                    <div class="conversation-hint">

                                        پیام شما مستقیماً برای تیم پشتیبانی ارسال می‌شود.

                                    </div>


                                    <button
                                        type="submit"
                                        class="send-btn"
                                        id="replyButton"
                                    >
                                        ارسال پیام
                                    </button>

                                </div>

                            </form>

                        <?php endif; ?>

                    </div>

                <?php else: ?>

                    <div
                        style="
                            flex:1;
                            display:flex;
                            align-items:center;
                            justify-content:center;
                            text-align:center;
                            padding:30px;
                        "
                    >

                        <div>

                            <div
                                style="
                                    font-size:42px;
                                    margin-bottom:10px;
                                "
                            >
                                💬
                            </div>

                            <h2
                                style="
                                    margin:0;
                                    color:var(--text-primary);
                                    font-size:16px;
                                "
                            >
                                پشتیبانی ملکینو
                            </h2>

                            <p
                                style="
                                    margin:8px 0 16px;
                                    color:var(--text-secondary);
                                    font-size:10px;
                                    line-height:1.8;
                                "
                            >
                                برای مشاهده گفتگو، یکی از درخواست‌های خود را انتخاب کنید
                                یا یک درخواست جدید بسازید.
                            </p>

                            <button
                                type="button"
                                class="support-new-btn"
                                onclick="showNewTicket()"
                            >
                                + ایجاد درخواست جدید
                            </button>

                        </div>

                    </div>

                <?php endif; ?>

            </section>

        </div>


        <!-- =====================================================
             INFO
        ====================================================== -->

        <div class="info-box">

            <h3>
                قبل از ارسال درخواست
            </h3>

            <p>
                لطفاً مشکل خود را تا حد امکان دقیق توضیح دهید.
                اگر مشکل مربوط به یک ملک، درخواست یا آگهی خاص است،
                شماره یا عنوان آن را نیز در پیام خود بنویسید تا پشتیبانی
                سریع‌تر بتواند موضوع را بررسی کند.
            </p>

        </div>

    </div>

</div>


<!-- =========================================================
     NEW TICKET MODAL
========================================================== -->

<div
    id="newTicketModal"
    style="
        display:none;
        position:fixed;
        inset:0;
        z-index:9000;
        align-items:center;
        justify-content:center;
        padding:15px;
        background:rgba(0,0,0,.48);
        backdrop-filter:blur(5px);
    "
>

    <div
        style="
            width:100%;
            max-width:480px;
            background:var(--surface);
            border:1px solid var(--border);
            border-radius:20px;
            overflow:hidden;
            box-shadow:0 25px 70px rgba(0,0,0,.25);
        "
    >

        <div
            style="
                display:flex;
                align-items:center;
                justify-content:space-between;
                padding:15px 17px;
                border-bottom:1px solid var(--border);
            "
        >

            <h3
                style="
                    margin:0;
                    color:var(--text-primary);
                    font-size:15px;
                    font-weight:850;
                "
            >
                درخواست پشتیبانی جدید
            </h3>


            <button
                type="button"
                onclick="hideNewTicket()"
                style="
                    width:34px;
                    height:34px;
                    border:0;
                    border-radius:9px;
                    background:var(--bg);
                    color:var(--text-secondary);
                    cursor:pointer;
                "
            >
                ✕
            </button>

        </div>


        <form
            id="newTicketForm"
            class="new-ticket-form"
            onsubmit="createTicket(event)"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $csrfToken,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="create_ticket"
            >


            <div class="form-group">

                <label for="ticketSubject">
                    موضوع درخواست
                </label>

                <select
                    id="ticketSubject"
                    name="subject"
                    class="form-control"
                    required
                >

                    <option value="">
                        انتخاب موضوع
                    </option>

                    <option value="مشکل در ثبت ملک">
                        مشکل در ثبت ملک
                    </option>

                    <option value="مشکل در درخواست ملکی">
                        مشکل در درخواست ملکی
                    </option>

                    <option value="مشکل در حساب کاربری">
                        مشکل در حساب کاربری
                    </option>

                    <option value="مشکل در پیشنهادها و تطبیق">
                        مشکل در پیشنهادها و تطبیق
                    </option>

                    <option value="مشکل فنی">
                        مشکل فنی
                    </option>

                    <option value="سوال درباره ملکینو">
                        سوال درباره ملکینو
                    </option>

                    <option value="سایر">
                        سایر
                    </option>

                </select>

            </div>


            <div class="form-group">

                <label for="ticketMessage">
                    توضیح مشکل
                </label>

                <textarea
                    id="ticketMessage"
                    name="message"
                    class="form-control"
                    maxlength="10000"
                    placeholder="مشکل یا سوال خود را با جزئیات بنویسید..."
                    required
                ></textarea>

            </div>


            <div class="form-actions">

                <button
                    type="button"
                    class="form-btn cancel"
                    onclick="hideNewTicket()"
                >
                    انصراف
                </button>


                <button
                    type="submit"
                    class="form-btn submit"
                    id="createTicketButton"
                >
                    ارسال درخواست
                </button>

            </div>

        </form>

    </div>

</div>


<div
    id="supportToast"
    class="toast"
></div>


<script>

    /* =========================================================
       TOAST
    ========================================================= */

    function supportToast(message) {

        const toast =
            document.getElementById('supportToast');

        if (!toast) {
            return;
        }

        toast.textContent = message;

        toast.classList.add('show');

        clearTimeout(
            window.supportToastTimer
        );

        window.supportToastTimer =
            setTimeout(
                function() {

                    toast.classList.remove('show');

                },
                3000
            );
    }


    /* =========================================================
       NEW TICKET
    ========================================================= */

    function showNewTicket() {

        const modal =
            document.getElementById('newTicketModal');

        if (!modal) {
            return;
        }

        modal.style.display = 'flex';

        setTimeout(
            function() {

                const subject =
                    document.getElementById('ticketSubject');

                if (subject) {
                    subject.focus();
                }

            },
            80
        );
    }


    function hideNewTicket() {

        const modal =
            document.getElementById('newTicketModal');

        if (modal) {
            modal.style.display = 'none';
        }

    }


    /* =========================================================
       CREATE TICKET
    ========================================================= */

    async function createTicket(event) {

        event.preventDefault();

        const form =
            document.getElementById('newTicketForm');

        const button =
            document.getElementById('createTicketButton');

        if (!form || !button) {
            return;
        }

        const originalText =
            button.textContent;

        button.disabled = true;

        button.textContent =
            'در حال ارسال...';

        try {

            const response =
                await fetch(
                    'support.php',
                    {
                        method: 'POST',
                        body: new FormData(form)
                    }
                );

            const result =
                await response.json();

            if (!result || result.success !== true) {

                throw new Error(
                    result?.message ||
                    'ارسال درخواست انجام نشد.'
                );

            }

            supportToast(
                result.message ||
                'درخواست شما ارسال شد.'
            );

            hideNewTicket();

            window.location.href =
                'support.php?ticket=' +
                encodeURIComponent(
                    result.ticket_id
                );

        } catch (error) {

            console.error(error);

            supportToast(
                '❌ ' +
                (
                    error.message ||
                    'خطا در ارسال درخواست.'
                )
            );

        } finally {

            button.disabled = false;

            button.textContent =
                originalText;

        }

    }


    /* =========================================================
       SEND REPLY
    ========================================================= */

    async function sendReply(event) {

        event.preventDefault();

        const form =
            document.getElementById('replyForm');

        const button =
            document.getElementById('replyButton');

        const textarea =
            document.getElementById('replyMessage');

        if (!form || !button || !textarea) {
            return;
        }

        if (!textarea.value.trim()) {
            supportToast(
                'لطفاً پیام خود را وارد کنید.'
            );
            return;
        }

        const originalText =
            button.textContent;

        button.disabled = true;

        button.textContent =
            'در حال ارسال...';

        try {

            const response =
                await fetch(
                    'support.php',
                    {
                        method: 'POST',
                        body: new FormData(form)
                    }
                );

            const result =
                await response.json();

            if (!result || result.success !== true) {

                throw new Error(
                    result?.message ||
                    'ارسال پیام انجام نشد.'
                );

            }

            textarea.value = '';

            supportToast(
                result.message ||
                'پیام ارسال شد.'
            );

            setTimeout(
                function() {
                    window.location.reload();
                },
                500
            );

        } catch (error) {

            console.error(error);

            supportToast(
                '❌ ' +
                (
                    error.message ||
                    'خطا در ارسال پیام.'
                )
            );

        } finally {

            button.disabled = false;

            button.textContent =
                originalText;

        }

    }


    /* =========================================================
       CLOSE MODAL ON BACKDROP
    ========================================================= */

    const newTicketModal =
        document.getElementById(
            'newTicketModal'
        );

    if (newTicketModal) {

        newTicketModal.addEventListener(
            'click',
            function(event) {

                if (
                    event.target ===
                    newTicketModal
                ) {

                    hideNewTicket();

                }

            }
        );

    }


    /* =========================================================
       ESCAPE
    ========================================================= */

    document.addEventListener(
        'keydown',
        function(event) {

            if (event.key === 'Escape') {
                hideNewTicket();
            }

        }
    );

</script>


<?php

require_once __DIR__ . '/footer.php';

?>