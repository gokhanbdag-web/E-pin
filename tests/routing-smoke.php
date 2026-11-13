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
echo "Root and subdirectory routing checks passed.\n";
