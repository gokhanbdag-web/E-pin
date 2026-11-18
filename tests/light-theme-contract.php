<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$theme=file_get_contents($root.'/assets/css/light-theme.css');
$store=file_get_contents($root.'/app/views/layout.php');
$admin=file_get_contents($root.'/app/views/layout-admin.php');
$install=file_get_contents($root.'/install/index.php');
foreach(['color-scheme:light','--bg:#f5f7fb','--panel:#ffffff','--text:#172033','input:focus','@media(max-width:800px)'] as $needle){
 if(!str_contains($theme,$needle))throw new RuntimeException('Light theme contract missing: '.$needle);
}
foreach([['storefront',$store],['admin',$admin],['installer',$install]] as [$name,$layout]){
 if(!str_contains($layout,'light-theme.css'))throw new RuntimeException('Light theme is not loaded by '.$name.' layout.');
}
if(preg_match('/color-scheme:dark|--bg:#0b1020|--panel:#141c31/',$theme))throw new RuntimeException('Dark theme base tokens remain in the light theme.');

foreach(['Premium mobile navigation','rgba(255,255,255,.98)','storefront-menu-toggle[aria-expanded=true]','storefront-body.storefront-menu-open::before','prefers-reduced-motion:reduce'] as $needle){
 if(!str_contains($theme,$needle))throw new RuntimeException('Premium mobile navigation contract missing: '.$needle);
}
if(!str_contains($store,'storefront-menu-toggle')||!str_contains($store,'storefront-navigation'))throw new RuntimeException('Storefront mobile navigation hooks are missing.');
echo "Light theme and premium mobile navigation contract checks passed.\n";
