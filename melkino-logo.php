<?php
require_once __DIR__.'/config.php';
function melkinoLogoUrl(): string {
  global $pdo; $fallback='assets/images/melkino-logo.png';
  if(!$pdo) return $fallback; $data=dbSettingGet($pdo,'branding','logos',[]);
  if(!is_array($data)) return $fallback; $meta=$data['primary_logo']??[]; $url=(string)($meta['url']??''); if($url===''||!is_file(__DIR__.'/'.$url)) return $fallback; return $url;
}
