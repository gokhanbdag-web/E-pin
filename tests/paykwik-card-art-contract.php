<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$migration=file_get_contents($root.'/database/migrations/014_paykwik_card_art.sql');
foreach ([10,20,50,100,250,500,1000] as $amount) {
 $path=$root.'/assets/images/paykwik-'.$amount.'.svg';
 if (!is_file($path)) throw new RuntimeException('Missing Paykwik artwork: '.$amount);
 $svg=file_get_contents($path);
 if (!str_contains($svg,'Paykwik '.$amount.' Euro') || !str_contains($svg,'>'.$amount.'</text>')) throw new RuntimeException('Paykwik artwork denomination mismatch: '.$amount);
 if (!str_contains($migration,"assets/images/paykwik-{$amount}.svg")) throw new RuntimeException('Migration mapping missing: '.$amount);
}
foreach (['app/views/store/home.php','app/views/store/category.php','app/views/store/product.php','app/views/store/search.php','app/views/store/cart.php'] as $view) {
 $content=file_get_contents($root.'/'.$view);
 if (!str_contains($content,"url((string)$")) throw new RuntimeException('Local image URL resolver missing: '.$view);
}
echo "Paykwik card artwork contract checks passed.\\n";
