<?php
require_once __DIR__ . '/db_helpers.php';

global $pdo;

/* =====================================================
   دریافت آدرس لوگو (همانند صفحه VIP)
   ===================================================== */
function getSiteLogoUrl(): string
{
    $metaFile = __DIR__ . '/uploads/onboarding-logo.json';
    if (file_exists($metaFile)) {
        $meta = json_decode(file_get_contents($metaFile), true);
        if (is_array($meta) && !empty($meta['url'])) {
            return $meta['url'];
        }
    }

    $files = glob(__DIR__ . '/uploads/onboarding-logo.*');
    if (!empty($files)) {
        return 'uploads/' . basename($files[0]);
    }

    return '';
}

/* =====================================================
   پردازش درخواست‌های AJAX
   ===================================================== */
if (isset($_GET['action'])) {
    $action = trim((string)$_GET['action']);
    // هویت فقط از سشن سروری خوانده می‌شود؛ پارامتر URL نادیده گرفته
    // می‌شود تا امکان دیدن علاقه‌مندی‌های دیگران وجود نداشته باشد.
    $identity = melkinoCurrentIdentity();
    $userId = $identity['user_id'];
    $telegramId = $identity['telegram_id'];

    if (!$pdo instanceof PDO) melkinoJsonResponse(['success'=>false,'message'=>'اتصال دیتابیس برقرار نیست.'],500);

    if ($action === 'list') {
        $conditions = [];
        $params = [];
        if ($userId) { $conditions[] = 'f.user_id = ?'; $params[] = $userId; }
        if ($telegramId !== '') { $conditions[] = 'f.telegram_id = ?'; $params[] = $telegramId; }
        if (!$conditions) melkinoJsonResponse(['success'=>true,'favorites'=>[]]);
        $where = implode(' OR ', array_map(fn($c)=>'('.$c.')',$conditions));
        $sql = "SELECT a.*, f.created_at AS favorited_at,
                    i.filename AS image
                FROM favorites f
                INNER JOIN ads a ON a.id = f.ad_id
                LEFT JOIN images i ON i.id = (
                    SELECT i2.id FROM images i2
                    WHERE i2.ad_id = a.id AND i2.is_selected = 1 AND i2.publish_publicly = 1
                    ORDER BY i2.is_primary DESC, i2.sort_order ASC, i2.id ASC LIMIT 1
                )
                WHERE ($where) AND a.status = 'published'
                ORDER BY f.created_at DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ads as &$ad) {
            $ad['image'] = $ad['image'] ?: '';
        }
        unset($ad);
        melkinoJsonResponse(['success'=>true,'favorites'=>$ads]);
    }

    if ($action === 'toggle') {
        $adId = trim((string)($_POST['ad_id'] ?? $_GET['ad_id'] ?? ''));
        if ($adId === '') melkinoJsonResponse(['success'=>false,'message'=>'شناسه آگهی الزامی است.'],422);
        if (!$userId && $telegramId === '') melkinoJsonResponse(['success'=>false,'message'=>'شناسه کاربر در دسترس نیست.'],422);
        $stmt = $pdo->prepare("SELECT id FROM ads WHERE id = ? AND status = 'published' LIMIT 1");
        $stmt->execute([$adId]);
        if (!$stmt->fetchColumn()) melkinoJsonResponse(['success'=>false,'message'=>'آگهی پیدا نشد.'],404);
        $q = 'SELECT id FROM favorites WHERE ad_id = ? AND ' . ($userId ? 'user_id = ?' : 'telegram_id = ?') . ' LIMIT 1';
        $stmt = $pdo->prepare($q); $stmt->execute([$adId, $userId ?: $telegramId]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            $q = 'DELETE FROM favorites WHERE id = ?';
            $pdo->prepare($q)->execute([$existing]);
            melkinoJsonResponse(['success'=>true,'favorited'=>false]);
        }
        $stmt = $pdo->prepare('INSERT INTO favorites (user_id, telegram_id, ad_id) VALUES (?, ?, ?)');
        $stmt->execute([$userId ?: null, $telegramId !== '' ? $telegramId : null, $adId]);
        melkinoJsonResponse(['success'=>true,'favorited'=>true]);
    }

    if ($action === 'count') {
        $conditions=[];$params=[];
        if ($userId) {$conditions[]='user_id = ?';$params[]=$userId;}
        if ($telegramId!=='') {$conditions[]='telegram_id = ?';$params[]=$telegramId;}
        if (!$conditions) melkinoJsonResponse(['success'=>true,'count'=>0]);
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM favorites WHERE '.implode(' OR ',$conditions));$stmt->execute($params);
        melkinoJsonResponse(['success'=>true,'count'=>(int)$stmt->fetchColumn()]);
    }

    melkinoJsonResponse(['success'=>false,'message'=>'عملیات نامعتبر است.'],400);
}

require_once __DIR__ . '/header.php';

// دریافت آدرس لوگو برای استفاده در جاوااسکریپت
$logoUrl = getSiteLogoUrl();
?>
<style>
.main-content{flex:1;overflow-y:auto;background:var(--bg);padding-bottom:90px}.favorites-page{max-width:1100px;margin:auto;padding:14px}.favorites-hero{padding:20px;border-radius:22px;margin-bottom:14px;background:radial-gradient(circle at 85% 15%,rgba(212,175,55,.15),transparent 30%),linear-gradient(135deg,#073737,#052727 70%,#031c1c);border:1px solid rgba(212,175,55,.12);box-shadow:var(--shadow-card)}.favorites-kicker{font-size:10px;color:#f0d36a;font-weight:800;margin-bottom:7px}.favorites-title{margin:0;color:#fff;font-size:clamp(23px,6vw,34px);font-weight:900}.favorites-title span{color:#f0d36a}.favorites-subtitle{margin:7px 0 0;color:rgba(255,255,255,.58);font-size:10px;line-height:1.9}.favorites-count{margin:10px 2px;color:var(--text-secondary);font-size:12px;font-weight:700}.favorites-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.favorite-card{overflow:hidden;background:var(--surface);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow-card)}.favorite-image{position:relative;height:210px;background:linear-gradient(145deg,rgba(212,175,55,.12),rgba(255,255,255,.025));display:flex;align-items:center;justify-content:center;overflow:hidden}.favorite-image img{width:100%;height:100%;display:block;object-fit:cover}.favorite-image .no-image-logo{width:80px;height:auto;opacity:0.4;filter:grayscale(1);object-fit:contain}.favorite-badge{position:absolute;top:10px;right:10px;padding:5px 9px;border-radius:999px;background:rgba(0,0,0,.55);color:#fff;font-size:9px;font-weight:700}.favorite-body{padding:12px}.favorite-code{font-size:9px;color:var(--text-secondary)}.favorite-title{margin-top:5px;color:var(--text-primary);font-size:14px;font-weight:800;line-height:1.7}.favorite-location{margin-top:5px;color:var(--text-secondary);font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.favorite-meta{display:flex;flex-wrap:wrap;gap:5px;margin-top:9px;padding-top:9px;border-top:1px solid var(--border)}.favorite-chip{padding:4px 7px;border-radius:7px;background:var(--bg);color:var(--text-secondary);font-size:9px}.favorite-price{margin-top:9px;color:var(--gold,#d4af37);font-size:15px;font-weight:900}.favorite-actions{display:flex;gap:7px;margin-top:10px}.favorite-btn{flex:1;height:40px;display:flex;align-items:center;justify-content:center;border-radius:10px;text-decoration:none;font-family:inherit;font-size:10px;font-weight:800}.favorite-btn.detail{background:var(--bg);color:var(--primary);border:1px solid var(--border)}.favorite-btn.remove{background:rgba(220,38,38,.08);color:#dc2626;border:1px solid rgba(220,38,38,.14);cursor:pointer}.favorite-btn.compare{background:var(--bg);color:var(--primary);border:1px solid var(--border);cursor:pointer}.favorite-btn.compare.in-compare{border-color:var(--primary)}.favorites-empty{text-align:center;padding:60px 20px;background:var(--surface);border:1px solid var(--border);border-radius:20px;color:var(--text-secondary)}.favorites-empty-icon{width:72px;height:72px;margin:0 auto 14px;border-radius:22px;display:flex;align-items:center;justify-content:center;background:rgba(212,175,55,.07);color:#d4af37}@media(max-width:700px){.favorites-list{grid-template-columns:1fr}.favorite-image{height:205px}}
</style>
<div class="main-content"><div class="favorites-page"><section class="favorites-hero"><div class="favorites-kicker">♡ ملک‌های ذخیره‌شده</div><h1 class="favorites-title">علاقه‌مندی‌های <span>من</span></h1><p class="favorites-subtitle">ملک‌هایی که پسندیدی اینجا ذخیره می‌شوند تا هر زمان خواستی دوباره بررسی‌شان کنی.</p></section><div class="favorites-count" id="favoritesCount">در حال بارگذاری...</div><div class="favorites-list" id="favoritesList"></div></div></div>

<script>
// ارسال آدرس لوگو به جاوااسکریپت
const logoUrl = <?= json_encode($logoUrl) ?>;

(function(){
 const list=document.getElementById('favoritesList'), count=document.getElementById('favoritesCount');
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 const tid=()=>String(localStorage.getItem('melkino_telegram_id')||sessionStorage.getItem('reg_telegram_id')||'');
 const num=v=>{const n=Number(String(v??'').replace(/,/g,''));return Number.isFinite(n)&&n!==0?new Intl.NumberFormat('en-US',{maximumFractionDigits:0}).format(n):''};
 async function load(){const r=await fetch('favorites.php?action=list&telegram_id='+encodeURIComponent(tid()),{cache:'no-store'});const d=await r.json();const ads=d.favorites||[];count.textContent=ads.length+' ملک ذخیره‌شده';if(!ads.length){list.innerHTML='<div class="favorites-empty" style="grid-column:1/-1"><h3>هنوز ملکی ذخیره نکرده‌ای</h3><p>وقتی روی قلب یک آگهی بزنی، آن ملک اینجا نمایش داده می‌شود.</p></div>';return;}list.innerHTML=ads.map(a=>{const area=a.area||a.land_area||a.built_area||'';let price='';if(a.transaction_type==='فروش')price=num(a.price_sell||a.display_price||a.total_price);else if(a.transaction_type==='رهن کامل')price=num(a.full_rent||a.deposit);else if(a.transaction_type==='رهن و اجاره'||a.transaction_type==='اجاره'){const d=num(a.deposit),r=num(a.rent_monthly);price=(d?('ودیعه: '+d):'')+(d&&r?' | ':'')+(r?('اجاره: '+r):'');}else price=num(a.display_price||a.price_sell||a.total_price);
 // استفاده از آدرس لوگوی واقعی سایت (همانند صفحه VIP)
 const imageHtml = a.image 
   ? `<img src="${esc(a.image)}" alt="${esc(a.title)}" loading="lazy">`
   : (logoUrl 
        ? `<img src="${esc(logoUrl)}" alt="لوگو" class="no-image-logo">`
        : `<div class="vip-no-image" style="display:flex;flex-direction:column;align-items:center;gap:6px;color:#8fa6a3;font-size:13px;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <rect x="3" y="3" width="18" height="18" rx="2"/>
              <circle cx="8.5" cy="8.5" r="1.5"/>
              <path d="M21 15l-5-5L5 21"/>
            </svg>
            <span>بدون تصویر</span>
           </div>`
      );
 return `<article class="favorite-card"><div class="favorite-image">${imageHtml}<span class="favorite-badge">${esc(a.transaction_type||'ملک')}</span></div><div class="favorite-body"><div class="favorite-code">کد ملک: ${esc(a.id)}</div><div class="favorite-title">${esc(a.title||'ملک بدون عنوان')}</div><div class="favorite-location">📍 ${esc(a.location||'موقعیت نامشخص')}</div><div class="favorite-meta">${a.property_type?`<span class="favorite-chip">${esc(a.property_type)}</span>`:''}${area?`<span class="favorite-chip">${esc(String(area).replace(/\.00$/,''))} متر</span>`:''}${a.rooms?`<span class="favorite-chip">${esc(a.rooms)} خواب</span>`:''}</div><div class="favorite-price">${price?esc(price)+' تومان':'تماس بگیرید'}</div><div class="favorite-actions"><a class="favorite-btn detail" href="property-details.php?id=${encodeURIComponent(a.id)}">مشاهده جزئیات</a><button class='favorite-btn compare' type='button' data-compare-add='${esc(a.id)}' title='افزودن به مقایسه'>⚖️ مقایسه</button><button class="favorite-btn remove" type="button" data-id="${esc(a.id)}">حذف ♥</button></div></div></article>`}).join('');list.querySelectorAll('.remove').forEach(b=>b.onclick=async()=>{await fetch('favorites.php?action=toggle&telegram_id='+encodeURIComponent(tid()),{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ad_id='+encodeURIComponent(b.dataset.id)});load();});}
 load();
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>