<?php
declare(strict_types=1);
$s=file_get_contents(dirname(__DIR__).'/app/views/admin/manage.php');
foreach(['Bekliyor — ödeme onayı','Hazırlanıyor — kısmi teslimat','Teslim edildi','Sorunlu — manuel inceleme','Sorunlu — işlem kapalı',"'payment_status'","'manual_review'","'partially_delivered'","'delivered'"] as $needle){if(!str_contains($s,$needle))throw new RuntimeException('Manual order status label missing: '.$needle);}
if(substr_count($s,'$manualOrderStatusLabel(')<3)throw new RuntimeException('Status label not used across order views');
