<?php
declare(strict_types=1);
$s=file_get_contents(dirname(__DIR__).'/app/bootstrap.php');
foreach(['function expire_stale_orders(PDO $pdo):void','if(!$pdo->inTransaction())','reserved_until<=NOW()',"o.payment_status<>'paid'", "o.status IN ('expired','cancelled','refunded')",'reserved_order_id=NULL,reserved_until=NULL','function restore_paid_order_reservations'] as $needle){if(!str_contains($s,$needle))throw new RuntimeException('Reservation contract missing: '.$needle);}
