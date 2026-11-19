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
 'mobile upload button remains touch sized' => str_contains($theme, '.admin-page-products .admin-form>label>input[type="file"]::file-selector-button{') && str_contains($theme, 'min-height:44px') && str_contains($theme, 'display:block;width:100%;margin:0 0 8px'),
 'image removal checkbox keeps a usable width' => str_contains($theme, '.admin-page-products .admin-form .image-remove input[type="checkbox"]{') && str_contains($theme, 'flex:0 0 20px'),
 'small-screen product form breakpoint' => str_contains($theme, '@media(max-width:480px){') && str_contains($theme, '.admin-page-products .admin-form>label{'),
 'mobile product inputs prevent zoom and overflow' => str_contains($theme, '.admin-page-products .admin-form>label>textarea{') && str_contains($theme, 'box-sizing:border-box;font-size:16px'),
 'mobile product text remains horizontal and readable' => str_contains($theme, 'overflow-wrap:break-word;word-break:normal'),
 'small-screen action buttons wrap labels' => str_contains($theme, 'white-space:normal;overflow-wrap:break-word'),
 'admin stylesheet cache version refreshed' => str_contains($layout, '?v=20261119-7'),
];
foreach ($checks as $label => $passed) {
 if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "Admin product responsive layout contract checks passed (" . count($checks) . ").\n";
