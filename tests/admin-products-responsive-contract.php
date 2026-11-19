<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$layout = file_get_contents($root . '/app/views/layout-admin.php');
$theme = file_get_contents($root . '/assets/css/light-theme.css');
$checks = [
 'resource-specific admin body class' => str_contains($layout, 'admin-page-<?= e($resource) ?>'),
 'product editor desktop grid' => str_contains($theme, 'grid-template-columns:repeat(2,minmax(0,1fr))'),
 'product fields can shrink' => str_contains($theme, '.admin-page-products .admin-form input,'),
 'long description and upload span form' => str_contains($theme, '.admin-form>label:has(textarea)') && str_contains($theme, '.admin-form>label:has(input[type="file"])'),
 'mobile editor becomes one column' => str_contains($theme, 'grid-template-columns:minmax(0,1fr)'),
 'mobile actions fit viewport' => str_contains($theme, '.admin-page-products .admin-form>.form-actions button,'),
 'table scroll is contained' => str_contains($theme, '.admin-page-products .table-wrap{') && str_contains($theme, 'overscroll-behavior-x:contain'),
 'table has readable scroll width' => str_contains($theme, '.admin-page-products .table-wrap table{width:100%;min-width:620px}'),
];
foreach ($checks as $label => $passed) {
 if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "Admin product responsive layout contract checks passed (" . count($checks) . ").\n";
