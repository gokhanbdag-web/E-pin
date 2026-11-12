<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$boot = file_get_contents($root . '/app/bootstrap.php');
$admin = file_get_contents($root . '/app/admin.php');
$view = file_get_contents($root . '/app/views/admin/manage.php');
$start = strpos($boot, 'function absolute_url');
$end = strpos($boot, 'function e(', $start);
$absolute = substr($boot, $start, $end - $start);
if (!str_contains($absolute, "setting_key='site_url'")) throw new RuntimeException('Canonical site URL must be read from saved settings.');
if (str_contains($absolute, 'HTTP_HOST')) throw new RuntimeException('Absolute callback URLs must not trust the request Host header.');
if (!str_contains($admin, "'site_url','site_title'")) throw new RuntimeException('Canonical site URL must be editable in store settings.');
if (!str_contains($view, 'name="site_url"')) throw new RuntimeException('Canonical site URL input is missing.');
$adminSource = file_get_contents($root . '/app/admin.php');
if (!str_contains($adminSource, 'SELECT o.payment_status,o.status,oi.id,oi.quantity,p.delivery_type FROM orders o')) throw new RuntimeException('Manual delivery must lock and inspect the order status.');
if (!str_contains($adminSource, "in_array(" . '$item' . "['status'],['processing','partially_delivered'],true)")) throw new RuntimeException('Manual delivery must reject refunded, cancelled, expired and review orders.');
if (!str_contains($admin, "seller_reverse_refunded_order((int)$r['order_id'])")) throw new RuntimeException('Processed refunds must reverse seller ledger entries in the same transaction.');
echo "Security contract checks passed.\n";
