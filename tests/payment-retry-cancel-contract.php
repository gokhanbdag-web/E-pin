<?php
declare(strict_types=1);
$s=file_get_contents(dirname(__DIR__).'/app/payments.php');
foreach(["coupon_usage_released FROM orders","(int)\$order['coupon_usage_released']===0","if(\$order['status']==='payment_failed')","Yeniden ödeme için yeterli dijital kod stoğu yok.","\$reserve->rowCount()!==1","status='pending',payment_status='unpaid'","FOR UPDATE"] as $needle){if(!str_contains($s,$needle))throw new RuntimeException('Retry/cancel guard missing: '.$needle);}
