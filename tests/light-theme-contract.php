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
$home=file_get_contents($root.'/app/views/store/home.php');
foreach(['Luxury slider navigation','top:50%','left:50%','translate(-50%,-50%)','inset:4px','linear-gradient(145deg,#344660','game-slider-arrows button::before','product-slider-controls button::before',':focus-visible','prefers-reduced-motion:reduce'] as $needle){
 if(!str_contains($theme,$needle))throw new RuntimeException('Premium slider arrow contract missing: '.$needle);
}
foreach(['data-slider-prev','data-slider-next','data-product-prev','data-product-next'] as $hook){
 if(!str_contains($home,$hook))throw new RuntimeException('Slider navigation hook missing: '.$hook);
}

foreach(['Premium storefront product and category cards','linear-gradient(155deg,#fff 0%,#fbfcff 100%)','product-link h3{','product-buy-link:hover','home-categories .chip:hover'] as $needle){
 if(!str_contains($theme,$needle))throw new RuntimeException('Premium product/category card contract missing: '.$needle);
}
\nforeach(["Premium product detail and purchase experience","product-detail::before","product-detail-info>.eyebrow","detail-price{display:inline-flex","buy-form button::after","stock-note{","description-panel h2"] as $needle){\n if(!str_contains($theme,$needle))throw new RuntimeException('Premium product detail contract missing: '.$needle);\n}\n$productView=file_get_contents($root.'/app/views/store/product.php');\nforeach(['productVariant','variantPrice','variantStockNote','addToCartButton','cart/add'] as $hook){\n if(!str_contains($productView,$hook))throw new RuntimeException('Product purchase behavior hook missing: '.$hook);\n}\necho "Light theme, premium navigation, slider arrows, product cards and product detail contract checks passed.\n";
