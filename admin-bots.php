<?php
/**
|--------------------------------------------------------------------------
| تنظیمات ربات‌ها و کانال (پنل ادمین)
|--------------------------------------------------------------------------
| هم UI تب را می‌سازد و هم endpointهای AJAX را سرو می‌دهد:
|   ?action=get              دریافت تنظیمات
|   ?action=save             ذخیره تنظیمات
|   ?action=test_telegram    تست اتصال تلگرام
|   ?action=test_bale        تست اتصال بله
|   ?action=test_channel     تست دسترسی به کانال
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/admin-guard.php';

$melkinoBotAction = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($melkinoBotAction !== '') {
    melkinoRequireAdminJson();
    require_once __DIR__ . '/bot-settings.php';
    require_once __DIR__ . '/telegram.php';

    switch ($melkinoBotAction) {
        case 'get':
            $settings = melkinoBotSettings();
            // توکن‌ها فقط به‌صورت ماسک نمایش داده می‌شوند
            foreach (['telegram_token', 'bale_token'] as $k) {
                if ($settings[$k] !== '') {
                    $settings[$k . '_masked'] = substr($settings[$k], 0, 6) . '••••••' . substr($settings[$k], -4);
                } else {
                    $settings[$k . '_masked'] = '';
                }
                unset($settings[$k]);
            }
            melkinoAdminJson(['success' => true, 'settings' => $settings]);

        case 'save':
            $data = melkinoAdminJsonBody();
            try {
                $ok = melkinoSaveBotSettings($data);
                melkinoAdminJson([
                    'success' => $ok,
                    'message' => $ok ? 'تنظیمات ربات‌ها ذخیره شد.' : 'ذخیره‌سازی ناموفق بود.',
                ], $ok ? 200 : 500);
            } catch (InvalidArgumentException $e) {
                melkinoAdminJson(['success' => false, 'message' => $e->getMessage()], 422);
            }

        case 'test_telegram':
            $data = melkinoAdminJsonBody();
            $result = melkinoTestTelegramConnection($data['token'] ?? null);
            melkinoAdminJson($result, $result['success'] ? 200 : 400);

        case 'test_bale':
            $data = melkinoAdminJsonBody();
            $result = melkinoTestBaleConnection($data['token'] ?? null);
            melkinoAdminJson($result, $result['success'] ? 200 : 400);

        case 'test_channel':
            $data = melkinoAdminJsonBody();
            $channel = trim((string)($data['channel'] ?? ''));
            if ($channel === '') {
                melkinoAdminJson(['success' => false, 'message' => 'شناسه کانال وارد نشده است.'], 422);
            }
            $token = melkinoTelegramToken();
            if ($token === '') {
                melkinoAdminJson(['success' => false, 'message' => 'ابتدا توکن تلگرام را ذخیره کن.'], 422);
            }
            $url = 'https://api.telegram.org/bot' . $token . '/getChat?chat_id=' . urlencode($channel);
            $response = function_exists('melkinoHttpPost') ? melkinoHttpPost($url, '') : @file_get_contents($url);
            $decoded = json_decode((string)$response, true);
            if (!is_array($decoded) || empty($decoded['ok'])) {
                $desc = is_array($decoded) ? ($decoded['description'] ?? 'پاسخ نامعتبر') : 'ارتباط برقرار نشد';
                melkinoAdminJson(['success' => false, 'message' => 'کانال: ' . $desc], 400);
            }
            melkinoAdminJson([
                'success' => true,
                'message' => 'کانال در دسترس است: ' . ($decoded['result']['title'] ?? $channel),
            ]);

        /* --------------------------------------------------------------
           تست دسترسی به کانالِ بله
           --------------------------------------------------------------
           در اینجا شناسه ابتدا پاک‌سازی می‌شود؛ چون اگر مقدارِ خالی یا
           پیش‌فرض به سرورِ بله فرستاده شود، پاسخِ «chat_id: must be a
           valid value» برمی‌گردد که پیامِ گمراه‌کننده‌ای است.
        -------------------------------------------------------------- */
        case 'test_bale_channel':
            $data = melkinoAdminJsonBody();
            $channel = trim((string)($data['channel'] ?? ''));

            $placeholders = ['', '@آیدی_کانال', 'آیدی_کانال', '@', '-', '0'];
            if (in_array($channel, $placeholders, true)) {
                melkinoAdminJson([
                    'success' => false,
                    'message' => 'شناسه کانال بله وارد نشده است. شناسه را وارد و ذخیره کن.',
                ], 422);
            }

            $token = function_exists('melkinoBaleToken') ? (string)melkinoBaleToken() : '';
            if ($token === '' || in_array($token, ['توکن_ربات_بله'], true)) {
                melkinoAdminJson([
                    'success' => false,
                    'message' => 'ابتدا توکن ربات بله را وارد و ذخیره کن.',
                ], 422);
            }

            $url = 'https://tapi.bale.ai/bot' . $token . '/getChat?chat_id=' . urlencode($channel);
            $response = function_exists('melkinoHttpPost') ? melkinoHttpPost($url, '') : @file_get_contents($url);
            $decoded = json_decode((string)$response, true);

            if (!is_array($decoded) || empty($decoded['ok'])) {
                $desc = is_array($decoded) ? ($decoded['description'] ?? 'پاسخ نامعتبر') : 'ارتباط با بله برقرار نشد';

                // پرتکرارترین خطا هنگامِ انتشار همین است؛ علتش را روشن می‌گوییم
                // تا ادمین به‌جای حدس زدن، بداند دقیقاً چه باید بکند.
                $hint = '';
                $d = strtolower($desc);
                if (strpos($d, 'no such group or user') !== false
                    || strpos($d, 'chat not found') !== false
                    || strpos($d, 'group not found') !== false) {
                    $hint = ' — یعنی ربات این شناسه را نمی‌شناسد: یا آیدی اشتباه است، یا ربات هنوز عضو/ادمینِ '
                          . 'این کانال نیست. از دکمه‌ی «یافتن شناسه‌ی کانال» در پایین استفاده کن تا شناسه‌ی '
                          . 'عددیِ درست را پیدا کنی.';
                }

                melkinoAdminJson(['success' => false, 'message' => 'کانال بله: ' . $desc . $hint], 400);
            }

            melkinoAdminJson([
                'success' => true,
                'message' => 'کانال بله در دسترس است: ' . ($decoded['result']['title'] ?? $channel),
            ]);

        /* ---------------------------------------------------------------
           یافتنِ شناسه‌ی کانال از روی پیام‌های اخیر ربات
           ---------------------------------------------------------------
           چرا لازم است؟ پرتکرارترین خطا هنگامِ انتشار، «no such group or
           user» است؛ یعنی شناسه‌ای که ادمین وارد کرده برای ربات قابلِ
           شناسایی نیست. مطمئن‌ترین راه این است که از خودِ بله بپرسیم ربات
           اخیراً چه گفتگوهایی را دیده است و شناسه‌ی عددیِ همان‌ها را
           نشان بدهیم تا ادمین مستقیماً انتخاب کند.
        --------------------------------------------------------------- */
        case 'bale_find_chats':

            $token = function_exists('melkinoBaleToken') ? (string)melkinoBaleToken() : '';
            if ($token === '' || in_array($token, ['توکن_ربات_بله'], true)) {
                melkinoAdminJson([
                    'success' => false,
                    'message' => 'ابتدا توکن ربات بله را وارد و ذخیره کن.',
                ], 422);
            }

            $url      = 'https://tapi.bale.ai/bot' . $token . '/getUpdates?limit=100';
            $response = function_exists('melkinoHttpPost') ? melkinoHttpPost($url, '') : @file_get_contents($url);
            $decoded  = json_decode((string)$response, true);

            if (!is_array($decoded) || empty($decoded['ok'])) {
                $desc = is_array($decoded) ? ($decoded['description'] ?? 'پاسخ نامعتبر') : 'ارتباط با بله برقرار نشد';
                melkinoAdminJson([
                    'success' => false,
                    'message' => 'دریافتِ به‌روزرسانی‌ها ناموفق بود: ' . $desc,
                ], 400);
            }

            $chats = [];
            foreach ((array)($decoded['result'] ?? []) as $upd) {
                $candidates = [];
                if (!empty($upd['message']['chat']))             { $candidates[] = $upd['message']['chat']; }
                if (!empty($upd['channel_post']['chat']))        { $candidates[] = $upd['channel_post']['chat']; }
                if (!empty($upd['my_chat_member']['chat']))      { $candidates[] = $upd['my_chat_member']['chat']; }
                if (!empty($upd['edited_message']['chat']))      { $candidates[] = $upd['edited_message']['chat']; }
                if (!empty($upd['edited_channel_post']['chat'])) { $candidates[] = $upd['edited_channel_post']['chat']; }

                foreach ($candidates as $c) {
                    $id = (string)($c['id'] ?? '');
                    if ($id === '' || isset($chats[$id])) { continue; }
                    $title = (string)($c['title'] ?? '');
                    if ($title === '') {
                        $title = trim(((string)($c['first_name'] ?? '')) . ' ' . ((string)($c['last_name'] ?? '')));
                    }
                    $chats[$id] = [
                        'id'       => $id,
                        'type'     => (string)($c['type'] ?? ''),
                        'title'    => $title,
                        'username' => (string)($c['username'] ?? ''),
                    ];
                }
            }

            if (!$chats) {
                melkinoAdminJson([
                    'success' => true,
                    'chats'   => [],
                    'message' => 'هیچ گفتگویی در به‌روزرسانی‌های اخیر پیدا نشد. برای این‌که ربات کانال را '
                               . 'بشناسد: ربات را به کانال اضافه کن و آن را ادمین (با اجازه‌ی ارسال پیام) '
                               . 'کن؛ سپس یک پیام در کانال بفرست و دوباره این دکمه را بزن.',
                ]);
            }

            melkinoAdminJson([
                'success' => true,
                'chats'   => array_values($chats),
                'message' => count($chats) . ' گفتگو پیدا شد. روی شناسه‌ی عددیِ کانال بزن تا در فیلد قرار بگیرد.',
            ]);

        /* ---------------------------------------------------------------
           بررسیِ توکنِ بله: آیا بله این توکن را قبول دارد؟
           ---------------------------------------------------------------
           خطای «Unauthorized» یعنی خودِ توکن پذیرفته نشده است (برخلافِ
           «no such group or user» که مربوط به شناسه‌ی کانال است). این
           action با فراخوانیِ getMe هویتِ ربات را می‌گیرد تا ادمین مطمئن
           شود توکنِ درستی ذخیره شده است. توکن هرگز به‌طور کامل نمایش
           داده نمی‌شود.
        --------------------------------------------------------------- */
        case 'bale_whoami':

            $token = function_exists('melkinoBaleToken') ? (string)melkinoBaleToken() : '';
            if ($token === '' || in_array($token, ['توکن_ربات_بله'], true)) {
                melkinoAdminJson([
                    'success' => false,
                    'message' => 'توکن ربات بله تنظیم نشده است. ابتدا توکن را وارد و ذخیره کن.',
                ], 422);
            }

            $masked = strlen($token) > 12
                ? substr($token, 0, 6) . '…' . substr($token, -4) . ' (طول: ' . strlen($token) . ')'
                : '(کوتاه)';

            $url      = 'https://tapi.bale.ai/bot' . $token . '/getMe';
            $response = function_exists('melkinoHttpPost') ? melkinoHttpPost($url, '') : @file_get_contents($url);
            $decoded  = json_decode((string)$response, true);

            if (!is_array($decoded) || empty($decoded['ok'])) {
                $desc = is_array($decoded) ? ($decoded['description'] ?? 'پاسخ نامعتبر') : 'ارتباط با بله برقرار نشد';
                $hint = '';
                if (stripos($desc, 'unauthorized') !== false) {
                    $hint = ' یعنی بله این توکن را به‌رسمیت نمی‌شناسد. توکن را دوباره از پنل '
                          . 'توسعه‌دهندگانِ بله (یا @botfather_bale) بگیر و اینجا ذخیره کن؛ '
                          . 'توجه کن که توکنِ تلگرام و توکنِ بله دو چیز کاملاً متفاوت‌اند و '
                          . 'جایگزینِ هم نیستند.';
                }
                melkinoAdminJson([
                    'success' => false,
                    'masked'  => $masked,
                    'message' => 'بله توکن را نپذیرفت: ' . $desc . $hint,
                ], 400);
            }

            $r = $decoded['result'] ?? [];
            melkinoAdminJson([
                'success' => true,
                'masked'  => $masked,
                'message' => 'توکن معتبر است. ربات: '
                           . (isset($r['username']) ? '@' . $r['username'] : '(بدون نام کاربری)')
                           . (isset($r['first_name']) ? ' — ' . $r['first_name'] : '')
                           . (isset($r['id']) ? ' — شناسه: ' . $r['id'] : ''),
            ]);

        default:
            melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
    }
}

// ---------------------------------------------------------------
// خروجی HTML تب
// ---------------------------------------------------------------
?>
<div class="admin-card">
    <div class="card-header">
        <span class="card-title">🤖 ربات تلگرام</span>
        <button type="button" class="btn-secondary" style="padding:6px 14px;font-size:12px;" onclick="testBotConnection('telegram')">
            🔌 تست اتصال
        </button>
    </div>

    <div style="padding:0 16px 16px;">
        <label class="admin-field-label">توکن ربات تلگرام</label>
        <input type="text" id="botTelegramToken" class="admin-input" dir="ltr" placeholder="123456789:AAE..." autocomplete="off">
        <div class="admin-field-help">از @BotFather دریافت می‌شود. پس از ذخیره، امضای ورود کاربران با این توکن بررسی می‌شود.</div>

        <label class="admin-field-label">شناسه کانال</label>
        <input type="text" id="botTelegramChannel" class="admin-input" dir="ltr" placeholder="@melkino_shahrood">
        <div class="admin-field-help">آگهی‌های منتشرشده می‌توانند به این کانال ارسال شوند.</div>

        <label class="admin-field-label">نام کاربری ربات (بدون @)</label>
        <input type="text" id="botTelegramUsername" class="admin-input" dir="ltr" placeholder="melkino_bot">
        <div class="admin-field-help">برای ساخت دکمه‌ی «ورود از طریق تلگرام» در مرورگر معمولی استفاده می‌شود.</div>

        <button type="button" class="btn-secondary" style="padding:8px 16px;font-size:13px;margin-top:8px;" onclick="testChannelConnection()">
            📢 تست دسترسی به کانال
        </button>
    </div>
</div>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title">💬 ربات بله</span>
        <button type="button" class="btn-secondary" style="padding:6px 14px;font-size:12px;" onclick="testBotConnection('bale')">
            🔌 تست اتصال
        </button>
    </div>

    <div style="padding:0 16px 16px;">
        <label class="admin-field-label">توکن ربات بله</label>
        <input type="text" id="botBaleToken" class="admin-input" dir="ltr" placeholder="387417012:..." autocomplete="off">
        <div class="admin-field-help">از پنل توسعه‌دهندگان بله (یا @botfather_bale) دریافت می‌شود.</div>

        <label class="admin-field-label">شناسه کانال بله</label>
        <input type="text" id="botBaleChannel" class="admin-input" dir="ltr" placeholder="@melkino">
        <div class="admin-field-help">
            می‌تواند با @ (مانند <span dir="ltr">@melkino</span>) یا شناسه‌ی عددی کانال باشد.
            ربات باید در کانال، ادمین با اجازه‌ی ارسال باشد.
        </div>

        <label class="admin-field-label">نام کاربری ربات (بدون @)</label>
        <input type="text" id="botBaleUsername" class="admin-input" dir="ltr" placeholder="melkino_bot">

        <button type="button" class="btn-secondary" style="padding:8px 16px;font-size:13px;margin-top:8px;" onclick="baleWhoAmI()">
            🧾 بررسیِ توکن (نمایشِ هویتِ ربات)
        </button>
        <div class="admin-field-help" style="margin-top:6px">
            اگر هنگامِ انتشار خطای
            <span dir="ltr">Unauthorized</span>
            می‌بینی، یعنی خودِ توکن پذیرفته نشده است (نه شناسه‌ی کانال). با
            این دکمه مشخص می‌شود بله این توکن را قبول دارد یا نه، و کدام
            ربات به آن وصل است.
        </div>

        <button type="button" class="btn-secondary" style="padding:8px 16px;font-size:13px;margin-top:8px;" onclick="findBaleChats()">
            🔎 یافتن شناسه‌ی کانال (از پیام‌های اخیرِ ربات)
        </button>
        <div class="admin-field-help" style="margin-top:6px">
            اگر هنگامِ انتشار خطای
            <span dir="ltr">no such group or user</span>
            می‌بینی، یعنی ربات این شناسه را نمی‌شناسد. با این دکمه فهرستِ
            گفتگوهایی که ربات اخیراً دیده را ببین و شناسه‌ی <b>عددیِ</b> کانال را
            انتخاب کن؛ شناسه‌ی عددی از آیدیِ @ بسیار مطمئن‌تر است.
        </div>
        <div id="baleChatsBox" style="display:none;margin-top:10px;padding:10px;border:1px solid #e2e2e2;border-radius:10px;background:#fafafa"></div>

        <button type="button" class="btn-secondary" style="padding:8px 16px;font-size:13px;margin-top:8px;" onclick="testBaleChannelConnection()">
            📢 تست دسترسی به کانال بله
        </button>
    </div>
</div>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title">🌐 پروکسی ارتباط با پیام‌رسان‌ها</span>
    </div>

    <div style="padding:0 16px 16px;">
        <label class="admin-field-label">نشانی پروکسی (اختیاری)</label>
        <input type="text" id="botProxy" class="admin-input" dir="ltr" placeholder="http://user:pass@1.2.3.4:8080" autocomplete="off">
        <div class="admin-field-help">
            اگر هاست شما به <span dir="ltr">api.telegram.org</span> دسترسی ندارد
            (برای هاست‌های داخل ایران معمول است)، نشانی یک پروکسی را اینجا وارد کنید تا
            تستِ اتصال و انتشارِ آگهی از طریق آن انجام شود. از
            <span dir="ltr">http://</span> و <span dir="ltr">socks5://</span>
            پشتیبانی می‌شود. در غیر این صورت این فیلد را خالی بگذارید.
        </div>
    </div>
</div>

<div class="admin-card">
    <div class="card-actions" style="padding:16px;">
        <button type="button" class="btn-primary" onclick="saveBotSettings()">💾 ذخیره تنظیمات ربات‌ها</button>
        <span id="botSettingsStatus" class="admin-status-msg"></span>
    </div>
    <div style="padding:0 16px 16px;color:var(--text-muted);font-size:12px;line-height:1.9;">
        توکن‌ها در دیتابیس ذخیره می‌شوند و در خروجی‌های این صفحه هیچ‌وقت به‌صورت کامل نمایش داده نمی‌شوند.
    </div>
</div>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title">🧭 راهنمای هاست‌هایی که دسترسیِ خروجی ندارند</span>
    </div>
    <div style="padding:14px 16px;color:var(--text-muted);font-size:13px;line-height:2;">
        <p style="margin:0 0 10px">
            بعضی هاست‌ها — از جمله <b>InfinityFree</b> — ارتباطِ خروجیِ سرور با
            <span dir="ltr">api.telegram.org</span> را به‌طور کامل مسدود کرده‌اند.
            در این حالت هر کاری که «سرور» انجام دهد با خطا مواجه می‌شود،
            <b>حتی وقتی توکن کاملاً سالم است</b>.
        </p>
        <p style="margin:0 0 10px">
            برای حل این مشکل، سامانه به‌صورت خودکار ابتدا از
            <b>مرورگرِ خودِ شما</b> با تلگرام/بله ارتباط برقرار می‌کند و فقط اگر
            مرورگر هم موفق نشد، از سرور امتحان می‌کند. بنابراین:
        </p>
        <ul style="margin:0 18px 10px;padding:0">
            <li>اگر مرورگر شما به تلگرام دسترسی دارد (مثلاً فیلترشکن روشن است)
                → <b>همه چیز کار می‌کند</b>: تست اتصال و انتشار آگهی.</li>
            <li>اگر مرورگر شما هم به تلگرام دسترسی ندارد
                → یک پروکسی در کارتِ بالا ثبت کنید، یا سایت را به هاستی منتقل کنید
                که ارتباطِ خروجیِ آزاد داشته باشد.</li>
        </ul>
        <p style="margin:0">
            برای این‌که بفهمید دقیقاً کدام حالت برقرار است، به تب
            <b>«🔍 عیب‌یاب»</b> بروید و دکمهٔ <b>«شروع تست»</b> را بزنید. در گروه
            «ربات و کانال»، موردِ <b>«ارتباط مستقیم از مرورگر»</b> تعیین‌کننده است.
        </p>
    </div>
</div>
