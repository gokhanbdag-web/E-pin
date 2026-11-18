<?php
declare(strict_types=1);

$css = file_get_contents(__DIR__ . '/../assets/css/storefront.css');
$js = file_get_contents(__DIR__ . '/../assets/js/storefront.js');

$checks = [
    'mobile menu uses fixed viewport positioning' => str_contains($css, 'position:fixed!important;'),
    'mobile menu has a bounded height' => str_contains($css, 'max-height:var(--storefront-menu-max-height'),
    'mobile menu enables vertical scrolling' => str_contains($css, 'overflow-y:auto!important;'),
    'mobile menu supports iOS momentum scrolling' => str_contains($css, '-webkit-overflow-scrolling:touch!important;'),
    'mobile menu preserves vertical touch gestures' => str_contains($css, 'touch-action:pan-y!important;'),
    'mobile menu contains scroll chaining' => str_contains($css, 'overscroll-behavior-y:contain!important;'),
    'script measures visible viewport height' => str_contains($js, 'window.visualViewport.height'),
    'script synchronizes menu position to header bottom' => str_contains($js, 'header.getBoundingClientRect().bottom'),
    'script updates menu height on viewport resize' => str_contains($js, "window.addEventListener('resize',syncMenuViewport"),
    'script accounts for mobile browser viewport changes' => str_contains($js, 'visualViewport.addEventListener'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS" : "FAIL") . " - {$label}\n";
    if (!$passed) $failed[] = $label;
}
if ($failed) {
    fwrite(STDERR, count($failed) . " mobile navigation contract check(s) failed.\n");
    exit(1);
}
echo count($checks) . " mobile navigation contract checks passed.\n";
