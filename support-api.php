<?php

/*
|--------------------------------------------------------------------------
| MELKINO - SUPPORT API
|--------------------------------------------------------------------------
| عملیات:
|
| create_ticket
| send_message
| get_tickets
| get_ticket
| close_ticket
| reopen_ticket
|
|--------------------------------------------------------------------------
*/

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';


/* =========================================================
   BASIC RESPONSE
========================================================= */

function supportApiResponse(
    bool $success,
    string $message = '',
    array $data = [],
    int $statusCode = 200
): void {

    http_response_code($statusCode);

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message
            ],
            $data
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* =========================================================
   DATABASE
========================================================= */

if (!isset($pdo) || !($pdo instanceof PDO)) {

    supportApiResponse(
        false,
        'اتصال به دیتابیس برقرار نیست.',
        [],
        500
    );
}


/* =========================================================
   IDENTITY
========================================================= */

try {

    $identity = melkinoCurrentIdentity();

} catch (Throwable $e) {

    supportApiResponse(
        false,
        'خطا در شناسایی کاربر.',
        [],
        401
    );
}


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


/* =========================================================
   USER VALIDATION
========================================================= */

$isAdminRequest = !empty($_SESSION['is_admin']);

$action =
    trim((string)(
        $_POST['action']
        ??
        $_GET['action']
        ??
        ''
    ));

if (
    !$isAdminRequest &&
    strpos($action, 'admin_') !== 0 &&
    $userId === '' &&
    $telegramId === '' &&
    $userPhone === ''
) {

    supportApiResponse(
        false,
        'کاربر شناسایی نشد. لطفاً ابتدا وارد حساب کاربری شوید.',
        [],
        401
    );
}

if (
    strpos($action, 'admin_') === 0 &&
    !$isAdminRequest
) {

    supportApiResponse(
        false,
        'دسترسی غیرمجاز.',
        [],
        403
    );
}


/* =========================================================
   CSRF
========================================================= */

if (empty($_SESSION['support_csrf'])) {

    $_SESSION['support_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    $_SESSION['support_csrf'];


/* =========================================================
   CSRF CHECK
========================================================= */

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&
    !$isAdminRequest &&
    !in_array(
        $action,
        [
            'get_tickets',
            'get_ticket'
        ],
        true
    )
) {

    $postedToken =
        (string)(
            $_POST['csrf_token']
            ?? ''
        );

    if (
        $postedToken === '' ||
        !hash_equals(
            $csrfToken,
            $postedToken
        )
    ) {

        supportApiResponse(
            false,
            'درخواست نامعتبر است. صفحه را مجدداً باز کنید.',
            [],
            419
        );
    }
}


/* =========================================================
   USER WHERE
========================================================= */

function supportApiUserWhere(
    array &$params
): string {

    global
        $userId,
        $telegramId,
        $userPhone;

    $conditions = [];


    if ($userId !== '') {

        $conditions[] =
            'user_id = ?';

        $params[] =
            $userId;
    }


    if ($telegramId !== '') {

        $conditions[] =
            'telegram_id = ?';

        $params[] =
            $telegramId;
    }


    if ($userPhone !== '') {

        $normalizedPhone =
            preg_replace(
                '/[\s\-\(\)]/',
                '',
                $userPhone
            );


        $conditions[] = "
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

        $params[] =
            $normalizedPhone;
    }


    if (!$conditions) {

        return '1 = 0';
    }


    return '(' .
        implode(
            ' OR ',
            $conditions
        ) .
        ')';
}


/* =========================================================
   GET TICKET FOR CURRENT USER
========================================================= */

function supportApiGetUserTicket(
    int $ticketId
): ?array {

    global $pdo;

    $params = [];

    $where =
        supportApiUserWhere(
            $params
        );

    $params[] =
        $ticketId;


    $stmt =
        $pdo->prepare("
            SELECT *
            FROM support_tickets
            WHERE $where
              AND id = ?
            LIMIT 1
        ");


    $stmt->execute(
        $params
    );


    $ticket =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return $ticket ?: null;
}


/* =========================================================
   STATUS LABEL
========================================================= */

function supportApiStatusLabel(
    string $status
): string {

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


/* =========================================================
   CREATE TICKET
========================================================= */

function supportApiCreateTicket(): void {

    global
        $pdo,
        $userId,
        $telegramId,
        $userName,
        $userPhone;


    $subject =
        trim((string)(
            $_POST['subject']
            ?? ''
        ));

    $message =
        trim((string)(
            $_POST['message']
            ?? ''
        ));


    if ($subject === '') {

        supportApiResponse(
            false,
            'لطفاً موضوع درخواست را انتخاب کنید.',
            [],
            422
        );
    }


    if (
        mb_strlen($subject)
        > 255
    ) {

        supportApiResponse(
            false,
            'موضوع درخواست بیش از حد طولانی است.',
            [],
            422
        );
    }


    if ($message === '') {

        supportApiResponse(
            false,
            'لطفاً متن مشکل را وارد کنید.',
            [],
            422
        );
    }


    if (
        mb_strlen($message)
        > 10000
    ) {

        supportApiResponse(
            false,
            'متن پیام بیش از حد طولانی است.',
            [],
            422
        );
    }


    try {

        $pdo->beginTransaction();


        /*
        |--------------------------------------------------------------------------
        | CREATE TICKET
        |--------------------------------------------------------------------------
        */

        $stmt =
            $pdo->prepare("
                INSERT INTO support_tickets
                (
                    user_id,
                    telegram_id,
                    phone,
                    user_name,
                    subject,
                    status,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    'open',
                    NOW(),
                    NOW()
                )
            ");


        $stmt->execute(
            [
                $userId !== ''
                    ? $userId
                    : null,

                $telegramId !== ''
                    ? $telegramId
                    : null,

                $userPhone !== ''
                    ? $userPhone
                    : null,

                $userName,

                $subject
            ]
        );


        $ticketId =
            (int)$pdo->lastInsertId();


        /*
        |--------------------------------------------------------------------------
        | FIRST MESSAGE
        |--------------------------------------------------------------------------
        */

        $senderId =
            $userId !== ''
                ? $userId
                : (
                    $telegramId !== ''
                        ? $telegramId
                        : $userPhone
                );


        $stmt =
            $pdo->prepare("
                INSERT INTO support_messages
                (
                    ticket_id,
                    sender_type,
                    sender_id,
                    sender_name,
                    message,
                    is_read,
                    created_at
                )
                VALUES
                (
                    ?,
                    'user',
                    ?,
                    ?,
                    ?,
                    0,
                    NOW()
                )
            ");


        $stmt->execute(
            [
                $ticketId,

                $senderId !== ''
                    ? $senderId
                    : null,

                $userName,

                $message
            ]
        );


        $pdo->commit();


        supportApiResponse(
            true,
            'درخواست شما با موفقیت ارسال شد.',
            [
                'ticket_id' =>
                    $ticketId
            ]
        );


    } catch (Throwable $e) {

        if (
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }


        supportApiResponse(
            false,
            'خطا در ایجاد درخواست پشتیبانی.',
            [],
            500
        );
    }
}


/* =========================================================
   SEND MESSAGE
========================================================= */

function supportApiSendMessage(): void {

    global
        $pdo,
        $userId,
        $telegramId,
        $userName,
        $userPhone;


    $ticketId =
        (int)(
            $_POST['ticket_id']
            ?? 0
        );


    $message =
        trim((string)(
            $_POST['message']
            ?? ''
        ));


    if ($ticketId <= 0) {

        supportApiResponse(
            false,
            'شناسه درخواست نامعتبر است.',
            [],
            422
        );
    }


    if ($message === '') {

        supportApiResponse(
            false,
            'لطفاً پیام خود را وارد کنید.',
            [],
            422
        );
    }


    if (
        mb_strlen($message)
        > 10000
    ) {

        supportApiResponse(
            false,
            'متن پیام بیش از حد طولانی است.',
            [],
            422
        );
    }


    $ticket =
        supportApiGetUserTicket(
            $ticketId
        );


    if (!$ticket) {

        supportApiResponse(
            false,
            'درخواست پشتیبانی پیدا نشد.',
            [],
            404
        );
    }


    if (
        $ticket['status']
        === 'closed'
    ) {

        supportApiResponse(
            false,
            'این درخواست بسته شده است.',
            [],
            409
        );
    }


    try {

        $pdo->beginTransaction();


        $senderId =
            $userId !== ''
                ? $userId
                : (
                    $telegramId !== ''
                        ? $telegramId
                        : $userPhone
                );


        /*
        |--------------------------------------------------------------------------
        | INSERT MESSAGE
        |--------------------------------------------------------------------------
        */

        $stmt =
            $pdo->prepare("
                INSERT INTO support_messages
                (
                    ticket_id,
                    sender_type,
                    sender_id,
                    sender_name,
                    message,
                    is_read,
                    created_at
                )
                VALUES
                (
                    ?,
                    'user',
                    ?,
                    ?,
                    ?,
                    0,
                    NOW()
                )
            ");


        $stmt->execute(
            [
                $ticketId,

                $senderId !== ''
                    ? $senderId
                    : null,

                $userName,

                $message
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | UPDATE TICKET
        |--------------------------------------------------------------------------
        */

        $stmt =
            $pdo->prepare("
                UPDATE support_tickets
                SET
                    status = 'open',
                    updated_at = NOW()
                WHERE id = ?
            ");


        $stmt->execute(
            [
                $ticketId
            ]
        );


        $messageId =
            (int)$pdo->lastInsertId();


        $pdo->commit();


        supportApiResponse(
            true,
            'پیام شما ارسال شد.',
            [
                'ticket_id' =>
                    $ticketId,

                'message_id' =>
                    $messageId
            ]
        );


    } catch (Throwable $e) {

        if (
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }


        supportApiResponse(
            false,
            'خطا در ارسال پیام.',
            [],
            500
        );
    }
}


/* =========================================================
   GET TICKETS
========================================================= */

function supportApiGetTickets(): void {

    global $pdo;


    $params = [];

    $where =
        supportApiUserWhere(
            $params
        );


    try {

        $stmt =
            $pdo->prepare("
                SELECT
                    t.id,
                    t.subject,
                    t.status,
                    t.created_at,
                    t.updated_at,

                    (
                        SELECT COUNT(*)
                        FROM support_messages sm
                        WHERE
                            sm.ticket_id = t.id
                    ) AS message_count,

                    (
                        SELECT sm.message
                        FROM support_messages sm
                        WHERE
                            sm.ticket_id = t.id
                        ORDER BY sm.id DESC
                        LIMIT 1
                    ) AS last_message

                FROM support_tickets t

                WHERE $where

                ORDER BY
                    t.updated_at DESC,
                    t.id DESC
            ");


        $stmt->execute(
            $params
        );


        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        foreach ($rows as &$row) {

            $row['id'] =
                (int)$row['id'];

            $row['message_count'] =
                (int)$row['message_count'];

            $row['status_label'] =
                supportApiStatusLabel(
                    (string)$row['status']
                );
        }

        unset($row);


        supportApiResponse(
            true,
            '',
            [
                'tickets' =>
                    $rows,

                'count' =>
                    count($rows)
            ]
        );


    } catch (Throwable $e) {

        supportApiResponse(
            false,
            'خطا در دریافت درخواست‌های پشتیبانی.',
            [],
            500
        );
    }
}


/* =========================================================
   GET SINGLE TICKET
========================================================= */

function supportApiGetTicket(): void {

    global $pdo;


    $ticketId =
        (int)(
            $_GET['ticket_id']
            ??
            $_POST['ticket_id']
            ??
            0
        );


    if ($ticketId <= 0) {

        supportApiResponse(
            false,
            'شناسه درخواست نامعتبر است.',
            [],
            422
        );
    }


    $ticket =
        supportApiGetUserTicket(
            $ticketId
        );


    if (!$ticket) {

        supportApiResponse(
            false,
            'درخواست پیدا نشد.',
            [],
            404
        );
    }


    try {

        $stmt =
            $pdo->prepare("
                SELECT
                    id,
                    ticket_id,
                    sender_type,
                    sender_id,
                    sender_name,
                    message,
                    is_read,
                    created_at
                FROM support_messages
                WHERE ticket_id = ?
                ORDER BY id ASC
            ");


        $stmt->execute(
            [
                $ticketId
            ]
        );


        $messages =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        foreach ($messages as &$message) {

            $message['id'] =
                (int)$message['id'];

            $message['ticket_id'] =
                (int)$message['ticket_id'];

            $message['is_read'] =
                (int)$message['is_read'];

        }

        unset($message);


        $ticket['id'] =
            (int)$ticket['id'];

        $ticket['status_label'] =
            supportApiStatusLabel(
                (string)$ticket['status']
            );


        supportApiResponse(
            true,
            '',
            [
                'ticket' =>
                    $ticket,

                'messages' =>
                    $messages
            ]
        );


    } catch (Throwable $e) {

        supportApiResponse(
            false,
            'خطا در دریافت گفتگو.',
            [],
            500
        );
    }
}


/* =========================================================
   CLOSE TICKET
========================================================= */

function supportApiCloseTicket(): void {

    global $pdo;


    $ticketId =
        (int)(
            $_POST['ticket_id']
            ?? 0
        );


    if ($ticketId <= 0) {

        supportApiResponse(
            false,
            'شناسه درخواست نامعتبر است.',
            [],
            422
        );
    }


    $ticket =
        supportApiGetUserTicket(
            $ticketId
        );


    if (!$ticket) {

        supportApiResponse(
            false,
            'درخواست پیدا نشد.',
            [],
            404
        );
    }


    if (
        $ticket['status']
        === 'closed'
    ) {

        supportApiResponse(
            true,
            'این درخواست قبلاً بسته شده است.'
        );
    }


    try {

        $stmt =
            $pdo->prepare("
                UPDATE support_tickets
                SET
                    status = 'closed',
                    updated_at = NOW()
                WHERE id = ?
            ");


        $stmt->execute(
            [
                $ticketId
            ]
        );


        supportApiResponse(
            true,
            'درخواست با موفقیت بسته شد.',
            [
                'ticket_id' =>
                    $ticketId,

                'status' =>
                    'closed',

                'status_label' =>
                    'بسته شده'
            ]
        );


    } catch (Throwable $e) {

        supportApiResponse(
            false,
            'خطا در بستن درخواست.',
            [],
            500
        );
    }
}


/* =========================================================
   REOPEN TICKET
========================================================= */

function supportApiReopenTicket(): void {

    global $pdo;


    $ticketId =
        (int)(
            $_POST['ticket_id']
            ?? 0
        );


    if ($ticketId <= 0) {

        supportApiResponse(
            false,
            'شناسه درخواست نامعتبر است.',
            [],
            422
        );
    }


    $ticket =
        supportApiGetUserTicket(
            $ticketId
        );


    if (!$ticket) {

        supportApiResponse(
            false,
            'درخواست پیدا نشد.',
            [],
            404
        );
    }


    try {

        $stmt =
            $pdo->prepare("
                UPDATE support_tickets
                SET
                    status = 'open',
                    updated_at = NOW()
                WHERE id = ?
            ");


        $stmt->execute(
            [
                $ticketId
            ]
        );


        supportApiResponse(
            true,
            'درخواست مجدداً فعال شد.',
            [
                'ticket_id' =>
                    $ticketId,

                'status' =>
                    'open',

                'status_label' =>
                    'در انتظار بررسی'
            ]
        );


    } catch (Throwable $e) {

        supportApiResponse(
            false,
            'خطا در فعال‌سازی مجدد درخواست.',
            [],
            500
        );
    }
}


/* =========================================================
   ADMIN: GET ALL TICKETS
========================================================= */

function supportApiAdminGetTickets(): void {

    global $pdo;

    $statusFilter =
        trim((string)(
            $_GET['status']
            ?? $_POST['status']
            ?? ''
        ));

    $where = '1 = 1';
    $params = [];

    if ($statusFilter !== '' && $statusFilter !== 'all') {
        $where = 't.status = ?';
        $params[] = $statusFilter;
    }

    try {

        $stmt =
            $pdo->prepare("
                SELECT
                    t.id,
                    t.user_id,
                    t.telegram_id,
                    t.phone,
                    t.user_name,
                    t.subject,
                    t.status,
                    t.created_at,
                    t.updated_at,

                    (
                        SELECT COUNT(*)
                        FROM support_messages sm
                        WHERE sm.ticket_id = t.id
                    ) AS message_count,

                    (
                        SELECT COUNT(*)
                        FROM support_messages sm
                        WHERE sm.ticket_id = t.id
                          AND sm.sender_type = 'user'
                          AND sm.is_read = 0
                    ) AS unread_count,

                    (
                        SELECT sm.message
                        FROM support_messages sm
                        WHERE sm.ticket_id = t.id
                        ORDER BY sm.id DESC
                        LIMIT 1
                    ) AS last_message

                FROM support_tickets t
                WHERE $where
                ORDER BY t.updated_at DESC, t.id DESC
            ");

        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['message_count'] = (int)$row['message_count'];
            $row['unread_count'] = (int)$row['unread_count'];
            $row['status_label'] = supportApiStatusLabel((string)$row['status']);
        }
        unset($row);

        supportApiResponse(true, '', ['tickets' => $rows, 'count' => count($rows)]);

    } catch (Throwable $e) {
        supportApiResponse(false, 'خطا در دریافت لیست تیکت‌ها.', [], 500);
    }
}


/* =========================================================
   ADMIN: GET SINGLE TICKET (بدون محدودیت به کاربر فعلی)
========================================================= */

function supportApiAdminGetTicket(): void {

    global $pdo;

    $ticketId = (int)($_GET['ticket_id'] ?? $_POST['ticket_id'] ?? 0);

    if ($ticketId <= 0) {
        supportApiResponse(false, 'شناسه تیکت نامعتبر است.', [], 422);
    }

    try {

        $stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ? LIMIT 1");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            supportApiResponse(false, 'تیکت پیدا نشد.', [], 404);
        }

        $stmt = $pdo->prepare("
            SELECT id, ticket_id, sender_type, sender_id, sender_name, message, is_read, created_at
            FROM support_messages
            WHERE ticket_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$ticketId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($messages as &$message) {
            $message['id'] = (int)$message['id'];
            $message['ticket_id'] = (int)$message['ticket_id'];
            $message['is_read'] = (int)$message['is_read'];
        }
        unset($message);

        // پیام‌های کاربر که هنوز خوانده نشده، با باز شدن تیکت توسط ادمین خوانده‌شده علامت می‌خورند.
        $mark = $pdo->prepare("
            UPDATE support_messages
            SET is_read = 1
            WHERE ticket_id = ? AND sender_type = 'user' AND is_read = 0
        ");
        $mark->execute([$ticketId]);

        $ticket['id'] = (int)$ticket['id'];
        $ticket['status_label'] = supportApiStatusLabel((string)$ticket['status']);

        supportApiResponse(true, '', ['ticket' => $ticket, 'messages' => $messages]);

    } catch (Throwable $e) {
        supportApiResponse(false, 'خطا در دریافت گفتگو.', [], 500);
    }
}


/* =========================================================
   ADMIN: REPLY TO TICKET
========================================================= */

function supportApiAdminReply(): void {

    global $pdo;

    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $message = trim((string)($_POST['message'] ?? ''));

    if ($ticketId <= 0) {
        supportApiResponse(false, 'شناسه تیکت نامعتبر است.', [], 422);
    }

    if ($message === '') {
        supportApiResponse(false, 'لطفاً متن پاسخ را وارد کنید.', [], 422);
    }

    if (mb_strlen($message) > 10000) {
        supportApiResponse(false, 'متن پیام بیش از حد طولانی است.', [], 422);
    }

    try {

        $stmt = $pdo->prepare("SELECT id FROM support_tickets WHERE id = ? LIMIT 1");
        $stmt->execute([$ticketId]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            supportApiResponse(false, 'تیکت پیدا نشد.', [], 404);
        }

        $adminName =
            trim((string)($_SESSION['admin_display_name'] ?? $_SESSION['admin_username'] ?? 'پشتیبانی ملکینو'));

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO support_messages
                (ticket_id, sender_type, sender_id, sender_name, message, is_read, created_at)
            VALUES (?, 'admin', ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([
            $ticketId,
            $_SESSION['admin_id'] ?? null,
            $adminName,
            $message,
        ]);

        $messageId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            UPDATE support_tickets SET status = 'answered', updated_at = NOW() WHERE id = ?
        ");
        $stmt->execute([$ticketId]);

        $pdo->commit();

        supportApiResponse(true, 'پاسخ ارسال شد.', [
            'ticket_id' => $ticketId,
            'message_id' => $messageId,
            'status' => 'answered',
            'status_label' => supportApiStatusLabel('answered'),
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        supportApiResponse(false, 'خطا در ارسال پاسخ.', [], 500);
    }
}


/* =========================================================
   ADMIN: CLOSE / REOPEN TICKET
========================================================= */

function supportApiAdminSetStatus(string $status): void {

    global $pdo;

    $ticketId = (int)($_POST['ticket_id'] ?? 0);

    if ($ticketId <= 0) {
        supportApiResponse(false, 'شناسه تیکت نامعتبر است.', [], 422);
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?
        ");
        $stmt->execute([$status, $ticketId]);

        supportApiResponse(true, 'وضعیت تیکت به‌روزرسانی شد.', [
            'ticket_id' => $ticketId,
            'status' => $status,
            'status_label' => supportApiStatusLabel($status),
        ]);

    } catch (Throwable $e) {
        supportApiResponse(false, 'خطا در به‌روزرسانی وضعیت تیکت.', [], 500);
    }
}


/* =========================================================
   ROUTER
========================================================= */

try {

    switch ($action) {

        case 'create_ticket':

            supportApiCreateTicket();

            break;


        case 'send_message':

            supportApiSendMessage();

            break;


        case 'get_tickets':

            supportApiGetTickets();

            break;


        case 'get_ticket':

            supportApiGetTicket();

            break;


        case 'close_ticket':

            supportApiCloseTicket();

            break;


        case 'reopen_ticket':

            supportApiReopenTicket();

            break;


        case 'admin_get_tickets':

            supportApiAdminGetTickets();

            break;


        case 'admin_get_ticket':

            supportApiAdminGetTicket();

            break;


        case 'admin_reply':

            supportApiAdminReply();

            break;


        case 'admin_close_ticket':

            supportApiAdminSetStatus('closed');

            break;


        case 'admin_reopen_ticket':

            supportApiAdminSetStatus('open');

            break;


        default:

            supportApiResponse(
                false,
                'عملیات پشتیبانی مشخص نشده است.',
                [
                    'available_actions' => [
                        'create_ticket',
                        'send_message',
                        'get_tickets',
                        'get_ticket',
                        'close_ticket',
                        'reopen_ticket'
                    ]
                ],
                400
            );
    }

} catch (Throwable $e) {

    supportApiResponse(
        false,
        'خطای غیرمنتظره در سیستم پشتیبانی.',
        [],
        500
    );
}