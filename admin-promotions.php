<?php
/**
|--------------------------------------------------------------------------
| مدیریت تبلیغات (پنل ادمین)
|--------------------------------------------------------------------------
| actions: list | save | delete | toggle | upload
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/admin-guard.php';

$melkinoPromoAction = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($melkinoPromoAction !== '') {
    melkinoRequireAdminJson();
    require_once __DIR__ . '/promotions.php';
    melkinoEnsurePromotionsTable();

    global $pdo;

    switch ($melkinoPromoAction) {
        case 'list':
            $rows = $pdo->query("SELECT * FROM promotions ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
            melkinoAdminJson(['success' => true, 'promotions' => $rows]);

        case 'save':
            $data = melkinoAdminJsonBody();
            $id = (int)($data['id'] ?? 0);

            $fields = [
                'title'         => trim((string)($data['title'] ?? '')),
                'image_url'     => trim((string)($data['image_url'] ?? '')),
                'link_url'      => trim((string)($data['link_url'] ?? '')),
                'button_text'   => trim((string)($data['button_text'] ?? '')) ?: 'مشاهده',
                'description'   => trim((string)($data['description'] ?? '')),
                'placement'     => trim((string)($data['placement'] ?? 'all')),
                'position_after'=> max(1, (int)($data['position_after'] ?? 3)),
                'repeat_every'  => max(0, (int)($data['repeat_every'] ?? 0)),
                'start_date'    => trim((string)($data['start_date'] ?? '')) ?: null,
                'end_date'      => trim((string)($data['end_date'] ?? '')) ?: null,
                'is_active'     => !empty($data['is_active']) ? 1 : 0,
            ];

            $allowedPlacements = ['all', 'home', 'properties', 'search', 'vip'];
            if (!in_array($fields['placement'], $allowedPlacements, true)) {
                $fields['placement'] = 'all';
            }

            try {
                if ($id > 0) {
                    $sql = "UPDATE promotions SET title=?, image_url=?, link_url=?, button_text=?, description=?,
                            placement=?, position_after=?, repeat_every=?, start_date=?, end_date=?, is_active=? WHERE id=?";
                    $params = array_values($fields);
                    $params[] = $id;
                    $pdo->prepare($sql)->execute($params);
                } else {
                    $sql = "INSERT INTO promotions (title, image_url, link_url, button_text, description,
                            placement, position_after, repeat_every, start_date, end_date, is_active)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?)";
                    $pdo->prepare($sql)->execute(array_values($fields));
                    $id = (int)$pdo->lastInsertId();
                }
                melkinoAdminJson(['success' => true, 'id' => $id, 'message' => 'تبلیغ ذخیره شد.']);
            } catch (Throwable $e) {
                melkinoAdminJson(['success' => false, 'message' => 'خطا در ذخیره‌سازی تبلیغ.'], 500);
            }

        case 'toggle':
            $data = melkinoAdminJsonBody();
            $id = (int)($data['id'] ?? 0);
            $active = !empty($data['is_active']) ? 1 : 0;
            if ($id <= 0) {
                melkinoAdminJson(['success' => false, 'message' => 'شناسه نامعتبر'], 422);
            }
            $pdo->prepare("UPDATE promotions SET is_active=? WHERE id=?")->execute([$active, $id]);
            melkinoAdminJson(['success' => true, 'message' => $active ? 'تبلیغ فعال شد.' : 'تبلیغ غیرفعال شد.']);

        case 'delete':
            $data = melkinoAdminJsonBody();
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) {
                melkinoAdminJson(['success' => false, 'message' => 'شناسه نامعتبر'], 422);
            }
            $pdo->prepare("DELETE FROM promotions WHERE id=?")->execute([$id]);
            melkinoAdminJson(['success' => true, 'message' => 'تبلیغ حذف شد.']);

        case 'stats':
            $row = $pdo->query("SELECT COUNT(*) AS total, SUM(views) AS views, SUM(clicks) AS clicks FROM promotions")->fetch(PDO::FETCH_ASSOC);
            melkinoAdminJson(['success' => true, 'stats' => $row ?: []]);

        case 'upload':
            if (empty($_FILES['image'])) {
                melkinoAdminJson(['success' => false, 'message' => 'فایلی دریافت نشد.'], 422);
            }
            $dir = __DIR__ . '/uploads/promotions';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                melkinoAdminJson(['success' => false, 'message' => 'پوشه آپلود در دسترس نیست.'], 500);
            }
            $f = $_FILES['image'];
            if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) {
                melkinoAdminJson(['success' => false, 'message' => 'خطا در آپلود یا حجم بیش از ۵ مگابایت.'], 422);
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $f['tmp_name']);
            finfo_close($finfo);
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (!in_array($mime, $allowedMimes, true) || !in_array($ext, $allowedExt, true)) {
                melkinoAdminJson(['success' => false, 'message' => 'فرمت تصویر مجاز نیست.'], 422);
            }
            $name = 'promo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
                melkinoAdminJson(['success' => false, 'message' => 'ذخیره فایل ناموفق بود.'], 500);
            }
            melkinoAdminJson(['success' => true, 'url' => 'uploads/promotions/' . $name]);

        default:
            melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
    }
}
?>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title">📢 مدیریت تبلیغات</span>
        <button type="button" class="btn-primary" style="padding:6px 14px;font-size:12px;" onclick="openPromotionEditor()">
            ➕ تبلیغ جدید
        </button>
    </div>
    <div style="padding:0 16px 8px;color:var(--text-secondary);font-size:12px;line-height:1.9;">
        تبلیغ‌ها در فهرست آگهی‌ها، بعد از شماره‌ی کارتی که مشخص می‌کنی نمایش داده می‌شوند.
    </div>
    <div id="promotionsListContainer" style="padding:0 16px 16px;"></div>
</div>

<!-- ویرایشگر تبلیغ -->
<div class="modal-overlay" id="promotionModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="promotionModalTitle">تبلیغ جدید</h3>
            <button class="modal-close" onclick="closePromotionModal()">✕</button>
        </div>

        <div id="promotionFormBody" style="display:flex;flex-direction:column;gap:var(--space-2);">
            <input type="hidden" id="promoId" value="">

            <label class="admin-field-label">عنوان تبلیغ</label>
            <input type="text" id="promoTitle" class="admin-input" placeholder="مثال: وام مسکن ملکینو">

            <label class="admin-field-label">توضیح کوتاه</label>
            <textarea id="promoDescription" class="admin-input" rows="2" placeholder="یک جمله درباره‌ی تبلیغ"></textarea>

            <label class="admin-field-label">تصویر بنر</label>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="file" id="promoImageFile" accept="image/*" style="font-size:12px;">
                <button type="button" class="btn-secondary" style="padding:6px 12px;font-size:12px;" onclick="uploadPromotionImage()">⬆️ آپلود</button>
            </div>
            <input type="text" id="promoImageUrl" class="admin-input" dir="ltr" placeholder="uploads/promotions/... یا آدرس کامل">

            <label class="admin-field-label">لینک مقصد</label>
            <input type="text" id="promoLinkUrl" class="admin-input" dir="ltr" placeholder="https://...">

            <label class="admin-field-label">متن دکمه</label>
            <input type="text" id="promoButtonText" class="admin-input" placeholder="مشاهده">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <label class="admin-field-label">محل نمایش</label>
                    <select id="promoPlacement" class="admin-input">
                        <option value="all">همه صفحه‌ها</option>
                        <option value="home">صفحه اصلی</option>
                        <option value="properties">فهرست املاک</option>
                        <option value="search">نتایج جستجو</option>
                        <option value="vip">ملک‌های ویژه</option>
                    </select>
                </div>
                <div>
                    <label class="admin-field-label">نمایش بعد از کارت شماره</label>
                    <input type="number" id="promoPosition" class="admin-input" min="1" value="3">
                </div>
                <div>
                    <label class="admin-field-label">تکرار هر چند کارت</label>
                    <input type="number" id="promoRepeat" class="admin-input" min="0" value="0" title="۰ یعنی فقط یک‌بار">
                </div>
                <div>
                    <label class="admin-field-label">وضعیت</label>
                    <select id="promoActive" class="admin-input">
                        <option value="1">فعال</option>
                        <option value="0">غیرفعال</option>
                    </select>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <label class="admin-field-label">شروع نمایش</label>
                    <input type="datetime-local" id="promoStart" class="admin-input" dir="ltr">
                </div>
                <div>
                    <label class="admin-field-label">پایان نمایش</label>
                    <input type="datetime-local" id="promoEnd" class="admin-input" dir="ltr">
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="button" class="btn-primary" onclick="savePromotion()">💾 ذخیره</button>
                <button type="button" class="btn-secondary" onclick="closePromotionModal()">انصراف</button>
                <span id="promoFormStatus" class="admin-status-msg"></span>
            </div>
        </div>
    </div>
</div>
