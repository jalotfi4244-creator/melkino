<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
if (!$pdo instanceof PDO) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'اتصال دیتابیس برقرار نیست.'], JSON_UNESCAPED_UNICODE); exit; }
try {
  $stmt=$pdo->query("SELECT a.* FROM ads a WHERE a.status='published' ORDER BY a.created_at DESC");
  $ads=$stmt->fetchAll(PDO::FETCH_ASSOC);
  // بارگذاری یک‌جای تصاویر و امکانات (به‌جای دو کوئری برای هر آگهی)
  $imagesByAd=[];
  try{
    $allImages=$pdo->query("SELECT ad_id,filename,is_selected,publish_publicly FROM images WHERE is_selected=1 AND publish_publicly=1 ORDER BY is_primary DESC,sort_order ASC,id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach($allImages as $im){$imagesByAd[(string)$im['ad_id']][]=$im['filename'];}
  }catch(Throwable $e){$imagesByAd=[];}

  $amenitiesByAd=[];
  try{
    $allAmen=$pdo->query("SELECT aa.ad_id AS ad_id, am.name AS name FROM ad_amenities aa INNER JOIN amenities am ON am.id=aa.amenity_id ORDER BY am.sort_order,am.id")->fetchAll(PDO::FETCH_ASSOC);
    foreach($allAmen as $am){$amenitiesByAd[(string)$am['ad_id']][]=$am['name'];}
  }catch(Throwable $e){$amenitiesByAd=[];}

  foreach($ads as &$ad){
    $ad['selected_images']=$imagesByAd[(string)$ad['id']]??[];
    $ad['amenities']=$amenitiesByAd[(string)$ad['id']]??[];
    $ad['property_details']=is_string($ad['property_details']??null)?json_decode($ad['property_details'],true):($ad['property_details']??[]);
    $ad['tags']=is_string($ad['tags']??null)?json_decode($ad['tags'],true):($ad['tags']??[]);
  } unset($ad);
  echo json_encode($ads,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(500);echo json_encode(['success'=>false,'message'=>'خطا در دریافت آگهی‌ها.'],JSON_UNESCAPED_UNICODE);}
