<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/paykwik.php';
foreach ([[10,55,20,660],[20,55,20,1320],[50,55.3446,20,3320.68],[100,55.3446,0,5534.46],[10,55,500,3300]] as [$eur,$rate,$margin,$expected]){
 if(abs(paykwik_calculate_price($eur,$rate,$margin)-$expected)>0.001)throw new RuntimeException('Paykwik margin pricing mismatch');
}
foreach ([[10,55,-1],[10,55,501],[10,INF,20],[10,55,NAN],[100000,100000,20],[PHP_FLOAT_MAX,PHP_FLOAT_MAX,20]] as [$eur,$rate,$margin]){
 try{paykwik_calculate_price((float)$eur,(float)$rate,(float)$margin);throw new RuntimeException('Invalid price accepted');}
 catch(InvalidArgumentException $e){}
}
$admin=file_get_contents(dirname(__DIR__).'/app/admin.php');
foreach (["paykwik_calculate_price((float)\$eur","paykwik_calculate_price((float)\$data['paykwik_eur_amount']","paykwik_margin_percent"] as $needle)if(!str_contains($admin,$needle))throw new RuntimeException('Product-specific margin integration missing');
