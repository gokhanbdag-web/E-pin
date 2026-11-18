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

foreach(["Premium product detail and purchase experience","product-detail::before","product-detail-info>.eyebrow","detail-price{display:inline-flex","buy-form button::after","stock-note{","description-panel h2"] as $needle){
 if(!str_contains($theme,$needle))throw new RuntimeException('Premium product detail contract missing: '.$needle);
}
foreach(["Premium search and category filter controls","search-toolbar::before","category-toolbar label{display:grid","search-toolbar input[type=search]","category-toolbar button:hover","prefers-reduced-motion:reduce"] as $needle){
 if(!str_contains($theme,$needle))throw new RuntimeException('Premium search/filter toolbar contract missing: '.$needle);
}
$searchView=file_get_contents($root.'/app/views/store/search.php');
$categoryView=file_get_contents($root.'/app/views/store/category.php');
foreach(['name="q"','name="sort"','name="min_price"','name="max_price"','name="sort"'] as $hook){
 if(!str_contains($searchView.$categoryView,$hook))throw new RuntimeException('Search/filter form hook missing: '.$hook);
}
echo "Light theme, premium navigation, slider arrows, product/category cards and premium search/filter contract checks passed.
";

$cart=file_get_contents($root.'/app/views/store/cart.php');
$checkout=file_get_contents($root.'/app/views/store/checkout.php');
$order=file_get_contents($root.'/app/views/store/order.php');
foreach(['Premium cart, checkout and order journey','cart-line:hover','cart-summary>.primary-link','checkout-panel::before','order-panel>.admin-form','order-panel>.delivered-code','prefers-reduced-motion:reduce'] as $needle){
 if(!str_contains($theme,$needle))throw new RuntimeException('Premium cart/checkout/order style contract missing: '.$needle);
}
foreach([['cart',$cart,['cart-layout','cart-line','cart-summary','coupon-form','cart/remove','cart/coupon']],['checkout',$checkout,['checkout-panel','_token','Siparişi oluştur']],['order',$order,['order-panel','payment/start/','name="provider"','delivered-code','refund_request']]] as [$name,$view,$hooks]){
 foreach($hooks as $hook)if(!str_contains($view,$hook))throw new RuntimeException('Cart/checkout/order '.$name.' contract missing: '.$hook);
}
echo "Light theme, premium navigation, slider arrows, product cards and product detail contract checks passed.
";
