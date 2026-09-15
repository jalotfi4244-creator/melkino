<?php
session_start(); header('Content-Type: application/json; charset=utf-8'); require_once __DIR__.'/config.php'; require_once __DIR__.'/consultant_helper.php';
if(empty($_SESSION['is_admin'])){http_response_code(403);echo json_encode(['success'=>false,'message'=>'دسترسی غیرمجاز'],JSON_UNESCAPED_UNICODE);exit;}
if($_SERVER['REQUEST_METHOD']==='GET'){echo json_encode(['success'=>true,'consultants'=>getConsultantsList()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$data=json_decode(file_get_contents('php://input'),true);if(!is_array($data['consultants']??null)){http_response_code(400);echo json_encode(['success'=>false,'message'=>'داده‌های ارسالی نامعتبر'],JSON_UNESCAPED_UNICODE);exit;}
$pdo->beginTransaction();try{
 $pdo->exec('DELETE FROM consultant_specialties'); $pdo->exec('DELETE FROM consultants');
 foreach(array_values($data['consultants']) as $i=>$c){
  $name=trim((string)($c['name']??'مشاور ملکینو'));$phone=trim((string)($c['phone']??''));$tg=ltrim(trim((string)($c['telegram_username']??'')),'@');$link=trim((string)($c['telegram_link']??''));$active=!empty($c['active'])?1:0;$priority=(int)($c['priority']??($i+1));
  $st=$pdo->prepare('INSERT INTO consultants (name,phone,telegram_username,telegram_link,is_active,sort_order) VALUES (?,?,?,?,?,?)');$st->execute([$name,$phone,$tg,$link,$active,$priority]);$id=(int)$pdo->lastInsertId();
  foreach(($c['specialties']??[]) as $s){$pt=trim((string)($s['property_type']??''));$tt=trim((string)($s['transaction_type']??''));if($pt!==''||$tt!==''){$sp=$pdo->prepare('INSERT IGNORE INTO consultant_specialties (consultant_id,property_type,transaction_type) VALUES (?,?,?)');$sp->execute([$id,$pt,$tt]);}}
  if(!empty($c['is_default'])) dbSettingSet($pdo,'consultant_default','consultant_id',(string)$id,'string',null);
 }
 $pdo->commit();echo json_encode(['success'=>true,'consultants'=>getConsultantsList()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(500);echo json_encode(['success'=>false,'message'=>'ذخیره مشاوران ناموفق بود.'],JSON_UNESCAPED_UNICODE);}
