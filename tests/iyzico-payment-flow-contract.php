<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/payments.php';
$valid=['status'=>'success','paymentStatus'=>'SUCCESS','fraudStatus'=>1,'currency'=>'TRY','conversationId'=>'EP-TEST','basketId'=>'EP-TEST','price'=>'120.50','paidPrice'=>'120.50'];
if(!iyzico_checkout_result_valid($valid,'EP-TEST',120.50))throw new RuntimeException('Valid iyzico payment rejected');
foreach([
 ['paymentStatus'=>'FAILURE'],['status'=>'failure'],['fraudStatus'=>0],['currency'=>'USD'],['conversationId'=>'OTHER'],['basketId'=>'OTHER'],['price'=>'120.51'],['paidPrice'=>'120.51']
] as $changes){if(iyzico_checkout_result_valid(array_replace($valid,$changes),'EP-TEST',120.50))throw new RuntimeException('Invalid iyzico result accepted');}
if(iyzico_checkout_result_valid($valid,'EP-TEST',-1)||iyzico_checkout_result_valid($valid,'EP-TEST','invalid'))throw new RuntimeException('Invalid iyzico order total accepted');
$i=file_get_contents(dirname(__DIR__).'/index.php');
foreach(["payment_attempt_reserve($orderId,'iyzico'","payment_attempt_update_reference($orderId,'iyzico'","iyzico_request('/payment/iyzipos/checkoutform/initialize/auth/ecom'","iyzico_request('/payment/iyzipos/checkoutform/auth/ecom/detail'","payment_mark_paid((int)\$tx['order_id'],'iyzico'","payment_mark_failed((int)\$tx['order_id'],'iyzico'"] as $needle){if(!str_contains($i,$needle))throw new RuntimeException('iyzico flow missing: '.$needle);}
