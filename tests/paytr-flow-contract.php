<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/payments.php';
$key='paytr-test-key';$salt='paytr-test-salt';$oid='EP-TEST-123';$amount='15025';
$sign=static function(string $status,string $value)use($oid,$key,$salt):array{
 $post=['merchant_oid'=>$oid,'status'=>$status,'total_amount'=>$value];
 $post['hash']=base64_encode(hash_hmac('sha256',$oid.$salt.$status.$value,$key,true));
 return $post;
};
$ok=$sign('success',$amount);
if(!paytr_signature_matches($ok,$key,$salt)||!paytr_callback_details_valid($ok,150.25))throw new RuntimeException('PayTR success callback rejected');
$failed=$sign('failed',$amount);
if(!paytr_signature_matches($failed,$key,$salt)||!paytr_callback_details_valid($failed,150.25))throw new RuntimeException('PayTR failure callback rejected');
$tampered=$ok;$tampered['total_amount']='15026';
if(paytr_signature_matches($tampered,$key,$salt))throw new RuntimeException('PayTR tampered amount accepted');
$tampered=$ok;$tampered['status']='failed';
if(paytr_signature_matches($tampered,$key,$salt))throw new RuntimeException('PayTR tampered status accepted');
if(paytr_callback_details_valid($ok,150.26)||paytr_callback_details_valid($sign('pending',$amount),150.25)||paytr_callback_details_valid($ok,-1))throw new RuntimeException('PayTR invalid callback accepted');
$index=file_get_contents(dirname(__DIR__).'/index.php');
foreach(['payment_attempt_reserve($orderId,\'paytr\'','paytr_callback_valid($post)','paytr_callback_details_valid($post,$o[\'total\'])','payment_mark_paid((int)$o[\'id\'],\'paytr\'','payment_mark_failed((int)$o[\'id\'],\'paytr\''] as $needle){if(!str_contains($index,$needle))throw new RuntimeException('PayTR flow missing: '.$needle);}
