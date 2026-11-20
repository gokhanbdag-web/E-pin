<?php
declare(strict_types=1);
$a=file_get_contents(dirname(__DIR__).'/app/admin.php');$v=file_get_contents(dirname(__DIR__).'/app/views/admin/manage.php');$m=file_get_contents(dirname(__DIR__).'/database/migrations/018_manual_delivery_audit.sql');
foreach(['admin_user_id',"(int)\$_SESSION['user']['id']",'FOR UPDATE','rowCount()!==1','if($remaining<=0)','inventory_code_id IS NULL'] as $needle){if(!str_contains($a,$needle))throw new RuntimeException('Manual delivery audit guard missing: '.$needle);}
foreach(['Manuel teslimat işlem geçmişi','admin_name','Kod değerleri güvenlik nedeniyle'] as $needle){if(!str_contains($v,$needle))throw new RuntimeException('Audit view missing: '.$needle);}
if(!str_contains($m,'@add-column order_deliveries admin_user_id'))throw new RuntimeException('Audit migration missing');
