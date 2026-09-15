<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'اتصال دیتابیس برقرار نیست.'], JSON_UNESCAPED_UNICODE); exit; }
try {
  $stmt=$pdo->query("SELECT a.* FROM ads a WHERE a.status='published' ORDER BY a.created_at DESC");
  $ads=$stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach($ads as &$ad){
    $ad['selected_images']=[];
    $img=$pdo->prepare("SELECT filename FROM images WHERE ad_id=? AND is_selected=1 AND publish_publicly=1 ORDER BY is_primary DESC,sort_order ASC,id ASC");$img->execute([$ad['id']]);$ad['selected_images']=array_column($img->fetchAll(PDO::FETCH_ASSOC),'filename');
    $amen=$pdo->prepare("SELECT am.name FROM ad_amenities aa INNER JOIN amenities am ON am.id=aa.amenity_id WHERE aa.ad_id=? ORDER BY am.sort_order,am.id");$amen->execute([$ad['id']]);$ad['amenities']=$amen->fetchAll(PDO::FETCH_COLUMN);
    $ad['property_details']=is_string($ad['property_details']??null)?json_decode($ad['property_details'],true):($ad['property_details']??[]);
    $ad['tags']=is_string($ad['tags']??null)?json_decode($ad['tags'],true):($ad['tags']??[]);
  } unset($ad);
  echo json_encode($ads,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(500);echo json_encode(['success'=>false,'message'=>'خطا در دریافت آگهی‌ها.'],JSON_UNESCAPED_UNICODE);}
