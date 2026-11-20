<?php
declare(strict_types=1);
$s=file_get_contents(dirname(__DIR__).'/app/payments.php');
foreach(["FOR UPDATE","if(\$attempt['status']!=='pending'){\$pdo->commit();return;}","if(\$attempt['status']==='success'){\$pdo->commit();return;}","['pending','failed','expired']","late_payment_review","duplicate_payment","payment_amount_matches(\$attempt['amount'],\$o['total'])","\$pdo->rollBack()"] as $needle){if(!str_contains($s,$needle))throw new RuntimeException('Webhook safety contract missing: '.$needle);}
$i=file_get_contents(dirname(__DIR__).'/index.php');
foreach(['paytr_callback_valid($post)','paytr_callback_details_valid($post,$o[\'total\'])','iyzico_checkout_result_valid($result,','payment_mark_paid((int)','payment_mark_failed((int)'] as $needle){if(!str_contains($i,$needle))throw new RuntimeException('Webhook verification missing: '.$needle);}
