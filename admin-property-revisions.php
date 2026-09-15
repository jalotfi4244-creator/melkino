<?php
session_start();
if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) { http_response_code(403); exit('دسترسی غیرمجاز'); }
require_once __DIR__.'/config.php';
global $pdo;
header('Content-Type: application/json; charset=utf-8');
function arJson($x,$s=200){http_response_code($s);echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(!$pdo instanceof PDO)arJson(['success'=>false,'message'=>'اتصال دیتابیس برقرار نیست.'],500);
$action=$_GET['action']??'list';
if($action==='list'){
 $rows=$pdo->query('SELECT r.id,r.ad_id,r.snapshot,r.change_note,r.created_at,a.title,a.phone,a.status FROM ad_revisions r JOIN ads a ON a.id=r.ad_id ORDER BY r.created_at DESC,r.id DESC')->fetchAll(PDO::FETCH_ASSOC);$out=[];foreach($rows as $r){$s=json_decode((string)$r['snapshot'],true);if(!is_array($s)||($s['review_status']??'pending')!=='pending')continue;$r['snapshot']=$s;$out[]=$r;}arJson(['success'=>true,'revisions'=>$out,'count'=>count($out)]);
}
$rid=(int)($_POST['revision_id']??0);if(!$rid)arJson(['success'=>false,'message'=>'شناسه ویرایش نامعتبر است.'],422);
$st=$pdo->prepare('SELECT * FROM ad_revisions WHERE id=? LIMIT 1');$st->execute([$rid]);$rev=$st->fetch(PDO::FETCH_ASSOC);if(!$rev)arJson(['success'=>false,'message'=>'ویرایش پیدا نشد.'],404);
$s=json_decode((string)$rev['snapshot'],true);if(!is_array($s)||($s['review_status']??'pending')!=='pending')arJson(['success'=>false,'message'=>'این ویرایش قبلاً بررسی شده است.'],409);
if($action==='approve'){
 $a=$s['after']??[]; $up=$pdo->prepare("UPDATE ads SET title=?,area=?,price_sell=?,deposit=?,rent_monthly=?,description=?,status='published',updated_at=NOW(),published_at=COALESCE(published_at,NOW()) WHERE id=?");$up->execute([$a['title']??null,$a['area']??null,$a['price_sell']??null,$a['deposit']??null,$a['rent_monthly']??null,$a['description']??null,$rev['ad_id']]);$s['review_status']='approved';$s['reviewed_at']=date('Y-m-d H:i:s');$u=$pdo->prepare('UPDATE ad_revisions SET changed_by_admin_id=NULL,snapshot=? WHERE id=?');$u->execute([json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$rid]);arJson(['success'=>true,'message'=>'ویرایش تأیید و آگهی دوباره منتشر شد.']);
}
if($action==='reject'){
 $s['review_status']='rejected';$s['reviewed_at']=date('Y-m-d H:i:s');$u=$pdo->prepare('UPDATE ad_revisions SET changed_by_admin_id=NULL,snapshot=? WHERE id=?');$u->execute([json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$rid]);$a=$s['before']??[];$status=$a['status']??'published';$pdo->prepare('UPDATE ads SET status=?,updated_at=NOW() WHERE id=?')->execute([$status,$rev['ad_id']]);arJson(['success'=>true,'message'=>'ویرایش رد شد و اطلاعات قبلی حفظ شد.']);
}
arJson(['success'=>false,'message'=>'عملیات نامعتبر است.'],400);
