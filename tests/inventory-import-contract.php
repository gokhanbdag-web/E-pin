<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$admin=file_get_contents($root.'/app/admin.php');
$view=file_get_contents($root.'/app/views/admin/manage.php');
$bootstrap=file_get_contents($root.'/app/bootstrap.php');
foreach(['beginTransaction()','rollBack()','count($codes)>500','inventory_encrypt($code)','INSERT IGNORE INTO inventory_codes','Mevcut teslimat yöntemi korundu'] as $needle){if(!str_contains($admin,$needle))throw new RuntimeException('Inventory import contract missing: '.$needle);}
if(!str_contains($bootstrap,"'aes-256-gcm'"))throw new RuntimeException('Encrypted inventory storage missing');
if(!str_contains($view,'en fazla 500'))throw new RuntimeException('Inventory limit UI missing');
