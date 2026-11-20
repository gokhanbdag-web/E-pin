<?php
declare(strict_types=1);
$s=file_get_contents(dirname(__DIR__).'/app/bootstrap.php');
$m=file_get_contents(dirname(__DIR__).'/database/migrations/009_automatic_delivery_ledger.sql');
foreach(['function fulfill_paid_order(int $orderId):void','SELECT id,payment_status,status FROM orders WHERE id=? FOR UPDATE',"reserved_order_id=? AND status='reserved'", "AND status='reserved' AND reserved_order_id=?",'INSERT INTO order_deliveries(',"if(\$delivery->rowCount()!==1)",'if($pdo->inTransaction())$pdo->rollBack()'] as $needle){if(!str_contains($s,$needle))throw new RuntimeException('Atomic delivery guard missing: '.$needle);}
if(!str_contains($m,'@unique-index order_deliveries uq_delivery_inventory_code inventory_code_id'))throw new RuntimeException('Missing unique inventory delivery index');
