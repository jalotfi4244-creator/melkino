<?php
require_once __DIR__ . '/match-engine.php';
global $pdo;

$identity = melkinoCurrentIdentity($_GET['telegram_id'] ?? $_POST['telegram_id'] ?? null);

function rqDigits($v): string {
    return strtr((string)$v, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
}
function rqPhone($v): string { return preg_replace('/\D+/', '', rqDigits($v)); }
function rqNum($v): ?float {
    $v = rqDigits((string)$v);
    $v = str_replace([',','٬','،',' ','تومان','ریال'], '', $v);
    $v = preg_replace('/[^0-9.\-]/u', '', $v);
    return ($v !== '' && is_numeric($v)) ? (float)$v : null;
}
function rqOwn(array $r, array $id): bool {
    if (!empty($id['user_id']) && (int)($r['user_id'] ?? 0) === (int)$id['user_id']) return true;
    if (($id['telegram_id'] ?? '') !== '' && (string)($r['telegram_id'] ?? '') === (string)$id['telegram_id']) return true;
    $p1 = rqPhone($r['phone'] ?? '');
    $p2 = rqPhone($id['phone'] ?? '');
    return $p1 !== '' && $p2 !== '' && $p1 === $p2;
}
function rqJson($value): array {
    if (is_array($value)) return $value;
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

if (!$pdo instanceof PDO) {
    if (isset($_GET['action'])) melkinoJsonResponse(['success' => false, 'message' => 'اتصال دیتابیس برقرار نیست.'], 500);
}

if (isset($_GET['action'])) {
    $action = (string)$_GET['action'];

    if ($action === 'list') {
        $all = $pdo->query('SELECT * FROM property_requests ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        $requests = [];
        foreach ($all as $r) {
            if (!rqOwn($r, $identity)) continue;
            try { m5EnsureRequestMatches((int)$r['id']); } catch (Throwable $e) {}
            $q = $pdo->prepare('SELECT COUNT(*) FROM request_matches rm JOIN ads a ON a.id=rm.ad_id AND a.status="published" WHERE rm.request_id=?');
            $q->execute([(int)$r['id']]);
            $r['match_count'] = (int)$q->fetchColumn();
            $requests[] = $r;
        }
        melkinoJsonResponse(['success' => true, 'requests' => $requests]);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) melkinoJsonResponse(['success' => false, 'message' => 'شناسه درخواست نامعتبر است.'], 422);

        $st = $pdo->prepare('SELECT * FROM property_requests WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $request = $st->fetch(PDO::FETCH_ASSOC);
        if (!$request || !rqOwn($request, $identity)) {
            melkinoJsonResponse(['success' => false, 'message' => 'این درخواست متعلق به حساب شما نیست یا دسترسی حذف ندارید.'], 403);
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM request_matches WHERE request_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM request_amenities WHERE request_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM property_requests WHERE id=?')->execute([$id]);
            $pdo->commit();
            melkinoJsonResponse(['success' => true, 'message' => 'درخواست با موفقیت حذف شد.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            melkinoJsonResponse(['success' => false, 'message' => 'حذف درخواست انجام نشد. لطفاً دوباره تلاش کنید.'], 500);
        }
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) melkinoJsonResponse(['success' => false, 'message' => 'شناسه درخواست نامعتبر است.'], 422);

        $st = $pdo->prepare('SELECT * FROM property_requests WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $request = $st->fetch(PDO::FETCH_ASSOC);
        if (!$request || !rqOwn($request, $identity)) {
            melkinoJsonResponse(['success' => false, 'message' => 'این درخواست متعلق به حساب شما نیست یا دسترسی ویرایش ندارید.'], 403);
        }

        $allowedTransaction = ['فروش','رهن کامل','رهن و اجاره','اجاره','پیش‌فروش','پیش فروش'];
        $transaction = trim((string)($_POST['transaction_type'] ?? $request['transaction_type'] ?? ''));
        if ($transaction !== '' && !in_array($transaction, $allowedTransaction, true)) $transaction = (string)($request['transaction_type'] ?? '');

        $propertyType = trim((string)($_POST['property_type'] ?? $request['property_type'] ?? ''));
        $location = trim((string)($_POST['location'] ?? $request['location'] ?? ''));
        $urgency = trim((string)($_POST['urgency'] ?? $request['urgency'] ?? ''));
        $dateNeeded = trim((string)($_POST['date_needed'] ?? $request['date_needed'] ?? ''));
        $dateNeeded = $dateNeeded !== '' ? $dateNeeded : null;

        $minArea = rqNum($_POST['min_area'] ?? $request['min_area']);
        $maxArea = rqNum($_POST['max_area'] ?? $request['max_area']);
        $minPrice = rqNum($_POST['min_price'] ?? $request['min_price']);
        $maxPrice = rqNum($_POST['max_price'] ?? $request['max_price']);
        $minDeposit = rqNum($_POST['min_deposit'] ?? $request['min_deposit']);
        $maxDeposit = rqNum($_POST['max_deposit'] ?? $request['max_deposit']);
        $minRent = rqNum($_POST['min_rent'] ?? $request['min_rent']);
        $maxRent = rqNum($_POST['max_rent'] ?? $request['max_rent']);

        $rahnKamal = isset($_POST['rahn_kamal']) ? 1 : 0;
        $isNotKeyed = isset($_POST['is_not_keyed']) ? 1 : 0;

        try {
            // فقط خود درخواست را در یک تراکنش کوتاه ذخیره کن.
            // بازسازی تطبیق‌ها نباید داخل این تراکنش انجام شود؛ چون موتور تطبیق
            // ممکن است جدول feedback را ایجاد/به‌روزرسانی کند و DDL در MySQL
            // باعث COMMIT ضمنی شود. همین موضوع باعث خطای ویرایش قبلی می‌شد.
            $pdo->beginTransaction();
            $up = $pdo->prepare('UPDATE property_requests SET transaction_type=?, property_type=?, location=?, urgency=?, date_needed=?, rahn_kamal=?, min_area=?, max_area=?, min_price=?, max_price=?, min_deposit=?, max_deposit=?, min_rent=?, max_rent=?, is_not_keyed=?, updated_at=NOW() WHERE id=?');
            $up->execute([
                $transaction ?: null, $propertyType ?: null, $location ?: null, $urgency ?: null, $dateNeeded,
                $rahnKamal, $minArea, $maxArea, $minPrice, $maxPrice, $minDeposit, $maxDeposit,
                $minRent, $maxRent, $isNotKeyed, $id
            ]);
            $pdo->commit();

            // حالا که درخواست با موفقیت ذخیره شد، تطبیق‌ها را خارج از تراکنش بازسازی کن.
            // اگر بازسازی شکست خورد، خود ویرایش درخواست همچنان موفق محسوب می‌شود.
            $rematchOk = true;
            try {
                // تطبیق‌های قبلی را نگه می‌داریم تا سابقه «مناسب من نیست» حفظ شود؛
                // موتور تطبیق خودش موارد قدیمیِ بدون بازخورد را حذف و موارد جدید را به‌روزرسانی می‌کند.
                m5EnsureRequestMatches($id);
            } catch (Throwable $matchError) {
                $rematchOk = false;
            }

            $message = $rematchOk
                ? 'درخواست با موفقیت ویرایش شد و فایل‌های مناسب آن به‌روزرسانی شدند.'
                : 'درخواست با موفقیت ویرایش و ذخیره شد؛ به‌روزرسانی فایل‌های مناسب کمی بعد انجام می‌شود.';

            melkinoJsonResponse([
                'success' => true,
                'message' => $message,
                'rematch_ok' => $rematchOk
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            melkinoJsonResponse([
                'success' => false,
                'message' => 'ویرایش درخواست انجام نشد: ' . $e->getMessage()
            ], 500);
        }
    }

    melkinoJsonResponse(['success' => false, 'message' => 'عملیات نامعتبر است.'], 400);
}

require_once __DIR__ . '/header.php';
?>
<style>
/* Full-height responsive page: the shared .app-container is intentionally overflow-hidden, so this page owns its scroll area. */
.requests-page-shell{flex:1;min-height:0;display:flex;flex-direction:column;background:var(--bg);overflow:hidden}
.requests-scroll{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;padding:clamp(14px,2.2vw,28px) clamp(12px,3vw,36px) calc(var(--bottom-nav-height) + 26px)}
.requests-page{width:100%;max-width:1180px;margin:0 auto}
.requests-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin:0 0 18px}
.requests-title{font-size:clamp(20px,2.2vw,28px);font-weight:900;color:var(--text-primary);line-height:1.35}
.requests-sub{font-size:12px;color:var(--text-secondary);margin-top:5px}
.btn{border:1px solid var(--border);background:var(--surface);color:var(--text-primary);padding:10px 13px;border-radius:11px;text-decoration:none;cursor:pointer;font:inherit;white-space:nowrap}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:16px;box-shadow:var(--shadow-xs)}
.row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}.code{font-weight:900;color:var(--primary);font-size:14px}.match{font-weight:900;color:var(--primary);font-size:13px}
.meta{margin-top:8px;font-size:11px;color:var(--text-secondary);line-height:1.9}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.btn.danger{color:#b42318}.btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
.empty{text-align:center;padding:56px 20px;background:var(--surface);border:1px dashed var(--border);border-radius:18px;color:var(--text-secondary)}
.notice{display:none;padding:11px 13px;border-radius:11px;margin:0 0 14px;font-size:12px}.notice.ok{display:block;background:#eaf8ef;color:#19734a}.notice.err{display:block;background:#fff0ef;color:#b42318}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.42);display:none;align-items:center;justify-content:center;padding:16px;z-index:9999}.modal.show{display:flex}
.box{width:min(820px,100%);max-height:min(86dvh,840px);overflow:auto;background:var(--surface);border-radius:20px;padding:18px;box-shadow:var(--shadow-modal)}
.box h3{margin:0 0 12px;font-size:18px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.field label{display:block;font-size:11px;color:var(--text-secondary);margin-bottom:4px}.field input,.field select{width:100%;box-sizing:border-box;padding:10px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text-primary);font:inherit}.field.full{grid-column:1/-1}.check{display:flex;align-items:center;gap:7px;font-size:12px;padding-top:5px}.money{direction:ltr;text-align:left}
@media (max-width:900px){.grid{grid-template-columns:1fr}}
@media (max-width:600px){.requests-scroll{padding-inline:12px}.requests-head{align-items:stretch;flex-direction:column}.actions .btn{flex:1 1 auto;text-align:center}.form-grid{grid-template-columns:1fr}.box{padding:14px}.requests-title{font-size:21px}}
</style>

<main class="requests-page-shell">
  <div class="requests-scroll">
    <div class="requests-page">
      <div class="requests-head">
        <div>
          <div class="requests-title">📋 درخواست‌های من</div>
          <div class="requests-sub">درخواست‌های ثبت‌شده، ویرایش و فایل‌های مناسب شما</div>
        </div>
        <a class="btn" href="profile.php">بازگشت</a>
      </div>
      <div id="notice" class="notice"></div>
      <div id="grid" class="grid"></div>
    </div>
  </div>
</main>

<div class="modal" id="editModal" aria-hidden="true">
  <div class="box">
    <h3>ویرایش درخواست ملک</h3>
    <form id="editForm">
      <input type="hidden" name="id" id="editId">
      <input type="hidden" name="telegram_id" value="<?=htmlspecialchars((string)($identity['telegram_id'] ?? ''), ENT_QUOTES, 'UTF-8')?>">
      <div class="form-grid">
        <div class="field"><label>نوع معامله</label><select name="transaction_type" id="editTransaction"><option value="فروش">فروش</option><option value="رهن کامل">رهن کامل</option><option value="رهن و اجاره">رهن و اجاره</option><option value="اجاره">اجاره</option><option value="پیش‌فروش">پیش‌فروش</option></select></div>
        <div class="field"><label>نوع ملک</label><input name="property_type" id="editProperty" type="text"></div>
        <div class="field full"><label>محله / منطقه</label><input name="location" id="editLocation" type="text"></div>
        <div class="field"><label>حداقل متراژ</label><input class="money" name="min_area" id="editMinArea" inputmode="numeric"></div>
        <div class="field"><label>حداکثر متراژ</label><input class="money" name="max_area" id="editMaxArea" inputmode="numeric"></div>
        <div class="field"><label>حداقل قیمت</label><input class="money" name="min_price" id="editMinPrice" inputmode="numeric"></div>
        <div class="field"><label>حداکثر قیمت</label><input class="money" name="max_price" id="editMaxPrice" inputmode="numeric"></div>
        <div class="field"><label>حداقل ودیعه</label><input class="money" name="min_deposit" id="editMinDeposit" inputmode="numeric"></div>
        <div class="field"><label>حداکثر ودیعه</label><input class="money" name="max_deposit" id="editMaxDeposit" inputmode="numeric"></div>
        <div class="field"><label>حداقل اجاره</label><input class="money" name="min_rent" id="editMinRent" inputmode="numeric"></div>
        <div class="field"><label>حداکثر اجاره</label><input class="money" name="max_rent" id="editMaxRent" inputmode="numeric"></div>
        <div class="field"><label>تاریخ نیاز</label><input name="date_needed" id="editDateNeeded" type="date"></div>
        <div class="field"><label>فوریت</label><select name="urgency" id="editUrgency"><option value="فوری">فوری</option><option value="عادی">عادی</option><option value="کم‌فوری">کم‌فوری</option></select></div>
        <label class="check"><input type="checkbox" name="rahn_kamal" id="editRahn"> رهن کامل</label>
        <label class="check"><input type="checkbox" name="is_not_keyed" id="editNotKeyed"> فایل کلیدی نباشد</label>
      </div>
      <div class="actions" style="margin-top:16px">
        <button type="button" class="btn" onclick="closeEdit()">انصراف</button>
        <button type="submit" class="btn primary" id="saveRequestBtn">ذخیره تغییرات</button>
      </div>
    </form>
  </div>
</div>

<script>
const TG_ID = <?=json_encode((string)($identity['telegram_id'] ?? ''))?>;
const esc = v => String(v ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const money = v => { if(v===null||v===undefined||v==='') return '—'; const n=Number(v); return Number.isFinite(n)?n.toLocaleString('en-US'):esc(v); };
const numOrBlank = v => { if(v===null||v===undefined||v==='') return ''; const n=Number(v); return Number.isFinite(n)?n.toLocaleString('en-US'):String(v); };
const unmoney = v => String(v??'').replace(/,/g,'').replace(/٬/g,'').replace(/،/g,'');
function showNotice(msg,ok=true){ const n=document.getElementById('notice'); n.className='notice '+(ok?'ok':'err'); n.textContent=msg; }
function closeEdit(){ document.getElementById('editModal').classList.remove('show'); }
function openEdit(r){
  document.getElementById('editId').value=r.id||'';
  document.getElementById('editTransaction').value=r.transaction_type||'فروش';
  document.getElementById('editProperty').value=r.property_type||'';
  document.getElementById('editLocation').value=r.location||'';
  document.getElementById('editMinArea').value=numOrBlank(r.min_area);
  document.getElementById('editMaxArea').value=numOrBlank(r.max_area);
  document.getElementById('editMinPrice').value=numOrBlank(r.min_price);
  document.getElementById('editMaxPrice').value=numOrBlank(r.max_price);
  document.getElementById('editMinDeposit').value=numOrBlank(r.min_deposit);
  document.getElementById('editMaxDeposit').value=numOrBlank(r.max_deposit);
  document.getElementById('editMinRent').value=numOrBlank(r.min_rent);
  document.getElementById('editMaxRent').value=numOrBlank(r.max_rent);
  document.getElementById('editDateNeeded').value=r.date_needed||'';
  document.getElementById('editUrgency').value=r.urgency||'فوری';
  document.getElementById('editRahn').checked=Number(r.rahn_kamal)===1;
  document.getElementById('editNotKeyed').checked=Number(r.is_not_keyed)===1;
  document.getElementById('editModal').classList.add('show');
}
function card(r){
  const code=esc(r.tracking_code||'');
  const meta=[r.transaction_type,r.property_type,r.location].filter(Boolean).map(esc).join(' · ');
  return `<article class="card">
    <div class="row"><div class="code">${code}</div><div class="match">${Number(r.match_count||0).toLocaleString('en-US')} فایل مناسب</div></div>
    <div class="meta">${meta||'مشخصات درخواست ثبت نشده است.'}</div>
    <div class="meta">ثبت: ${esc(r.created_at||'—')} · وضعیت: ${esc(r.status||'—')}</div>
    <div class="meta">متراژ: ${money(r.min_area)} تا ${money(r.max_area)} متر · بودجه: ${money(r.min_price)} تا ${money(r.max_price)} تومان</div>
    <div class="actions">
      <a class="btn primary" href="my-request-matches.php?code=${encodeURIComponent(r.tracking_code||'')}">مشاهده فایل‌های مناسب</a>
      <button class="btn" type="button" onclick='openEdit(${JSON.stringify(r).replace(/</g,'\\u003c')})'>ویرایش</button>
      <button class="btn danger" type="button" onclick='deleteRequest(${Number(r.id)})'>حذف</button>
    </div>
  </article>`;
}
async function loadRequests(){
  const grid=document.getElementById('grid');
  try{
    const res=await fetch('requests.php?action=list&telegram_id='+encodeURIComponent(TG_ID),{cache:'no-store'});
    const d=await res.json();
    if(!d.success){ showNotice(d.message||'خطا در دریافت درخواست‌ها.',false); return; }
    const rows=d.requests||[];
    grid.innerHTML=rows.length?rows.map(card).join(''):'<div class="empty">هنوز درخواست ملکی برای شما ثبت نشده است.</div>';
  }catch(e){ showNotice('ارتباط با سرور انجام نشد. لطفاً دوباره تلاش کنید.',false); }
}
async function deleteRequest(id){
  if(!confirm('این درخواست حذف شود؟ این کار تطبیق‌های مرتبط با درخواست را نیز حذف می‌کند.')) return;
  try{
    const body=new URLSearchParams({id:String(id),telegram_id:TG_ID});
    const res=await fetch('requests.php?action=delete',{method:'POST',body});
    const d=await res.json();
    showNotice(d.message||'عملیات انجام شد.',!!d.success);
    if(d.success) loadRequests();
  }catch(e){ showNotice('حذف درخواست انجام نشد. لطفاً دوباره تلاش کنید.',false); }
}

document.querySelectorAll('.money').forEach(inp=>inp.addEventListener('input',()=>{
  const raw=unmoney(inp.value).replace(/\D/g,''); inp.value=raw?Number(raw).toLocaleString('en-US'):'';
}));

document.getElementById('editForm').addEventListener('submit',async e=>{
  e.preventDefault();
  const btn=document.getElementById('saveRequestBtn'); const old=btn.textContent; btn.disabled=true; btn.textContent='در حال ذخیره...';
  try{
    const fd=new FormData(e.target);
    for(const key of ['min_area','max_area','min_price','max_price','min_deposit','max_deposit','min_rent','max_rent']) fd.set(key,unmoney(fd.get(key)));
    const res=await fetch('requests.php?action=update',{method:'POST',body:new URLSearchParams(fd)});
    const d=await res.json(); showNotice(d.message||'عملیات انجام شد.',!!d.success);
    if(d.success){ closeEdit(); loadRequests(); }
  }catch(err){ showNotice('ویرایش درخواست انجام نشد. لطفاً دوباره تلاش کنید.',false); }
  finally{ btn.disabled=false; btn.textContent=old; }
});

loadRequests();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
