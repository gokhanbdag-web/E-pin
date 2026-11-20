<?php
declare(strict_types=1);
$i=file_get_contents(dirname(__DIR__).'/index.php');$v=file_get_contents(dirname(__DIR__).'/app/views/store/order.php');
foreach(["user_id=?","Cache-Control: private, no-store","csrf_check();","reveal_order_codes","'showDeliveredCodes'=>$showDeliveredCodes"] as $needle){if(!str_contains($i,$needle))throw new RuntimeException('Customer code reveal guard missing: '.$needle);}
foreach(['Teslimat bildirimi','Teslim edilen kodlarımı göster','if(!($showDeliveredCodes??false))','inventory_decrypt($code)'] as $needle){if(!str_contains($v,$needle))throw new RuntimeException('Customer code display missing: '.$needle);}
