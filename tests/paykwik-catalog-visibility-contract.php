<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$m=file_get_contents($root.'/database/migrations/013_paykwik_pricing.sql');
$art=file_get_contents($root.'/database/migrations/014_paykwik_card_art.sql');
$home=file_get_contents($root.'/app/views/store/home.php');
$index=file_get_contents($root.'/index.php');
foreach([10,20,50,100,250,500,1000] as $eur){
 if(!preg_match('/(?:SELECT|UNION ALL SELECT)\\s+'.preg_quote((string)$eur,'/').'\\.00\\b/',$m))throw new RuntimeException("Paykwik $eur EUR seed missing");
 if(!str_contains($art,"WHEN $eur THEN 'assets/images/paykwik-$eur.svg'"))throw new RuntimeException("Paykwik $eur EUR image mapping missing");
 if(!is_file($root."/assets/images/paykwik-$eur.svg"))throw new RuntimeException("Paykwik $eur EUR artwork missing");
}
foreach(["'paykwik-kodlari'","'manual','active'","paykwik_enabled"] as $needle)if(!str_contains($m,$needle))throw new RuntimeException("Paykwik catalogue seed missing: $needle");
if(str_contains($home,"url('category/'"))throw new RuntimeException('Legacy category prefix remains on homepage');
if(!str_contains($home,"url(rawurlencode(\$c['slug']))"))throw new RuntimeException('Canonical category link missing');
if(!str_contains($index,"WHERE status='active' ORDER BY sort_order,id DESC LIMIT 24"))throw new RuntimeException('Active product storefront query missing');
