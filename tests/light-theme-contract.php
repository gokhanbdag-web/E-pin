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
echo "Light theme contract checks passed.\n";
