<?php
require_once __DIR__ . '/config.php';

function getConsultantsList(): array {
    global $pdo; if (!$pdo) return [];
    $rows=$pdo->query("SELECT c.id,c.name,c.phone,c.telegram_username,c.telegram_link,c.is_active AS active,c.sort_order AS priority FROM consultants c ORDER BY c.sort_order ASC,c.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $out=[];
    $sp=$pdo->prepare("SELECT property_type,transaction_type FROM consultant_specialties WHERE consultant_id=? ORDER BY id ASC");
    foreach($rows as $r){$sp->execute([(int)$r['id']]);$r['active']=(bool)$r['active'];$r['priority']=(int)$r['priority'];$r['specialties']=$sp->fetchAll(PDO::FETCH_ASSOC);$r['is_default']=false;$out[]=$r;}
    $def=(string)dbSettingGet($pdo,'consultant_default','consultant_id','');
    foreach($out as &$c){if($def!=='' && (string)$c['id']===$def)$c['is_default']=true;} unset($c);
    return $out;
}
function findConsultantForAd($property_type,$transaction_type){
    $active=array_values(array_filter(getConsultantsList(),fn($c)=>!empty($c['active'])));
    usort($active,fn($a,$b)=>(int)($a['priority']??999)<=>(int)($b['priority']??999));
    foreach($active as $c){foreach($c['specialties']??[] as $s){if(($s['property_type']??'')===$property_type && ($s['transaction_type']??'')===$transaction_type)return $c;}}
    foreach($active as $c) if(!empty($c['is_default'])) return $c;
    return $active[0]??null;
}
function getConsultantDisplayData($consultant){if(!$consultant)return null;return ['name'=>$consultant['name']??'مشاور ملکینو','phone'=>$consultant['phone']??'','telegram_link'=>$consultant['telegram_link']??'','telegram_username'=>$consultant['telegram_username']??''];}
