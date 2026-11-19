<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$layout = file_get_contents($root . '/app/views/layout-admin.php');
$view = file_get_contents($root . '/app/views/admin/manage.php');
$theme = file_get_contents($root . '/assets/css/light-theme.css');
$checks = [
 'resource-specific admin body class' => str_contains($layout, 'admin-page-<?= e($resource) ?>'),
 'product editor desktop grid' => str_contains($theme, 'grid-template-columns:repeat(2,minmax(0,1fr))'),
 'product fields can shrink' => str_contains($theme, '.admin-page-products .admin-form input,'),
 'long description and upload span form' => str_contains($theme, '.admin-form>label:has(textarea)') && str_contains($theme, '.admin-form>label:has(input[type="file"])'),
 'mobile editor becomes one column' => str_contains($theme, 'grid-template-columns:minmax(0,1fr)'),
 'mobile actions fit viewport' => str_contains($theme, '.admin-page-products .admin-form>.form-actions button,'),
 'product table has dedicated responsive wrapper' => str_contains($view, "products-table-wrap") && str_contains($view, "products-table"),
 'product cells expose mobile labels' => str_contains($view, "data-label=\"<?= \$resource==='products'?'Ürün':'' ?>\"") && str_contains($view, "data-label=\"<?= \$resource==='products'?'Durum':'' ?>\"") && str_contains($view, "data-label=\"<?= \$resource==='products'?'İşlem':'' ?>\""),
 'mobile list becomes stacked cards' => str_contains($theme, '.admin-page-products .products-table tbody{display:grid;') && str_contains($theme, '.admin-page-products .products-table tbody tr{'),
 'mobile product table removes desktop minimum width' => str_contains($theme, '.admin-page-products .products-table{') && str_contains($theme, 'min-width:0!important'),
 'mobile table headers remain accessible but visually hidden' => str_contains($theme, '.admin-page-products .products-table thead{') && str_contains($theme, 'clip:rect(0,0,0,0)'),
 'product action buttons are touch sized' => str_contains($theme, '.admin-page-products .products-table tbody td.actions a,') && str_contains($theme, 'min-height:42px'),
 'product image caption stays horizontal on mobile' => str_contains($theme, '.admin-page-products .admin-image-preview>small{') && str_contains($theme, 'writing-mode:horizontal-tb') && str_contains($theme, 'white-space:nowrap') && str_contains($theme, 'word-break:normal') && str_contains($theme, 'max-width:calc(100% - 16px)'),
 'admin stylesheet cache version refreshed' => str_contains($layout, '?v=20261119-4'),
];
foreach ($checks as $label => $passed) {
 if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "Admin product responsive layout contract checks passed (" . count($checks) . ").\n";
