<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$cases=[
 ['root base','/var/www/html','/var/www/html','/index.php',''],
 ['subdirectory base','/var/www/html/epin','/var/www/html','/index.php','/epin'],
 ['nested base','/var/www/html/apps/epin','/var/www/html','/index.php','/apps/epin'],
 ['script fallback','/not-a-real-path','/different-root','/epin/index.php','/epin'],
 ['path-info fallback','/not-a-real-path','/different-root','/epin/index.php/login','/epin'],
];
foreach($cases as [$label,$app,$doc,$script,$expected]){
 $actual=app_base_path_from_paths($app,$doc,$script);
 if($actual!==$expected)throw new RuntimeException($label.': expected '.$expected.', got '.$actual);
}
$routes=[
 ['/login','/epin','login'],
 ['/epin/login','/epin','login'],
 ['/epin/admin/products','/epin','admin/products'],
 ['/apps/epin/product/game-card','/apps/epin','product/game-card'],
 ['/','', ''],
 ['/epin/','/epin',''],
 ['/epin-other/login','/epin','epin-other/login'],
];
foreach($routes as [$uri,$base,$expected]){
 $actual=app_route_path_from_uri($uri,$base);
 if($actual!==$expected)throw new RuntimeException('route '.$uri.' with base '.$base.': expected '.$expected.', got '.$actual);
}

if(!app_is_home_path('home')||!app_is_home_path('')||app_is_home_path('pc-oyunlari'))throw new RuntimeException('homepage route detection failed');
$categoryCases=[
 ['plain category slug','pc-oyunlari','pc-oyunlari'],
 ['nested path is not a category','pc-oyunlari/alt',null],
 ['reserved home alias','home',null],
 ['reserved legacy categor route','categor',null],
 ['reserved cart route','cart',null],
 ['reserved admin route','admin',null],
 ['reserved product route','product',null],
 ['reserved page route','page',null],
 ['reserved legacy category route','category',null],
 ['empty path','',null],
];
foreach($categoryCases as [$label,$path,$expected]){
 $actual=category_route_slug_from_path($path);
 if($actual!==$expected)throw new RuntimeException($label.': expected '.var_export($expected,true).', got '.var_export($actual,true));
}
$_SERVER['DOCUMENT_ROOT']=APP_ROOT;$_SERVER['SCRIPT_NAME']='/index.php';
if(category_url('pc-oyunlari')!=='/pc-oyunlari')throw new RuntimeException('category_url must generate a root-level URL');
foreach(['cart','admin','home','categor','product','category','payment','order','page','seller','account','login','search','checkout'] as $reserved){
 if(!category_slug_is_reserved($reserved))throw new RuntimeException('reserved category slug was accepted: '.$reserved);
}
echo "Root and subdirectory routing checks passed.\n";
