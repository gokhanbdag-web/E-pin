<?php
declare(strict_types=1);
$s=file_get_contents(dirname(__DIR__).'/app/admin.php');
$needles=[
 'count($codes)!==count(array_unique($codes))',
 "'processing','partially_delivered'",
 'if($remaining<=0)',
 'if($ins->rowCount()!==1)',
 "if(\$item['payment_status']!=='paid')",
 '$pdo->beginTransaction()',
 'if($pdo->inTransaction())$pdo->rollBack()',
];
foreach($needles as $needle){
 if(!str_contains($s,$needle))throw new RuntimeException('Manual delivery guard missing: '.$needle);
}
