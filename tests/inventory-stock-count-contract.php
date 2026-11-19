<?php
declare(strict_types=1);
$admin=file_get_contents(dirname(__DIR__).'/app/admin.php');$view=file_get_contents(dirname(__DIR__).'/app/views/admin/manage.php');foreach(['GROUP BY product_id,variant_id','s.variant_id <=> v.id','WHERE variant_id IS NULL','COALESCE(s.available,0)'] as $needle){if(!str_contains($admin,$needle))throw new RuntimeException('Stock count contract missing: '.$needle);}foreach(['Kullanılabilir','Ayrılmış','Teslim edilmiş','Ana ürün stoğu'] as $needle){if(!str_contains($view,$needle))throw new RuntimeException('Stock UI missing: '.$needle);}
