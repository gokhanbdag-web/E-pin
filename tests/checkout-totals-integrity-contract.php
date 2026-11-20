<?php
declare(strict_types=1);
$s=file_get_contents(dirname(__DIR__).'/index.php');
foreach(['variantCount','bir seçenek seçmelisiniz','is_finite($price)','$subtotal=round($subtotal+round($price*$qty,2),2)','is_finite($discount)','$discount>$subtotal','$reserve->rowCount()!==1','if($pdo->inTransaction())$pdo->rollBack()'] as $needle){if(!str_contains($s,$needle))throw new RuntimeException('Checkout integrity check missing: '.$needle);}
