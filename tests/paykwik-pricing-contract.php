<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/paykwik.php';
$cases = [
 [10.0,55.0,20.0,660.0],
 [20.0,55.0,20.0,1320.0],
 [50.0,55.3446,20.0,3320.68],
 [100.0,55.3446,0.0,5534.46],
];
foreach ($cases as [$eur,$rate,$margin,$expected]) {
 $actual=paykwik_calculate_price($eur,$rate,$margin);
 if (abs($actual-$expected)>0.001) throw new RuntimeException('Paykwik pricing calculation mismatch.');
}
foreach ([[0,55,20], [10,0,20], [10,55,-1], [10,55,501], [NAN,55,20]] as $bad) {
 $rejected=false; try { paykwik_calculate_price((float)$bad[0],(float)$bad[1],(float)$bad[2]); } catch (InvalidArgumentException) { $rejected=true; }
 if (!$rejected) throw new RuntimeException('Invalid Paykwik pricing input was accepted.');
}
$source=file_get_contents(dirname(__DIR__).'/database/migrations/013_paykwik_pricing.sql');
foreach (['paykwik_eur_amount','paykwik_margin_percent','paykwik_exchange_rates','Paykwik 1000 Euro','55.3446'] as $needle) if (!str_contains($source,$needle)) throw new RuntimeException('Paykwik migration contract missing '.$needle);
echo "Paykwik pricing contract checks passed.\n";
