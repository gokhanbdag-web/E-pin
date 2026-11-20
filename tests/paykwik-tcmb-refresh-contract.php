<?php
declare(strict_types=1);
$s=file_get_contents(dirname(__DIR__).'/app/paykwik.php');
foreach(["substr(\$lastAttempt, 0, 10) === \$today","!\$attemptedToday","\$attemptedToday","\$cached['stale'] = true","paykwik_fetch_tcmb_rate()","paykwik_store_rate(\$pdo, \$fresh)"] as $needle){if(!str_contains($s,$needle))throw new RuntimeException('TCMB refresh contract missing: '.$needle);}
if(str_contains($s,'$lastAttempt !== $today')||str_contains($s,'$lastAttempt === $today'))throw new RuntimeException('Timestamp compared to date without normalization');
