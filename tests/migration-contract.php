<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$dir = $root . '/database/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);
if (count($files) < 3) throw new RuntimeException('Expected ordered core, extended, and marketplace migrations.');
$versions = array_map('basename', $files);
if ($versions !== array_values(array_unique($versions))) throw new RuntimeException('Migration versions must be unique.');
$all = '';
foreach ($files as $file) {
    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') throw new RuntimeException('Empty or unreadable migration: ' . basename($file));
    $all .= "\n" . $sql;
}
$required = [
    'CREATE TABLE IF NOT EXISTS users','CREATE TABLE IF NOT EXISTS products',
    'CREATE TABLE IF NOT EXISTS orders','CREATE TABLE IF NOT EXISTS payment_transactions',
    'CREATE TABLE IF NOT EXISTS product_variants','CREATE TABLE IF NOT EXISTS inventory_codes',
    'CREATE TABLE IF NOT EXISTS order_deliveries','CREATE TABLE IF NOT EXISTS refund_requests',
    'CREATE TABLE IF NOT EXISTS support_tickets','CREATE TABLE IF NOT EXISTS seller_profiles',
    'CREATE TABLE IF NOT EXISTS seller_ledger','CREATE TABLE IF NOT EXISTS seller_withdrawals','CREATE TABLE IF NOT EXISTS seller_withdrawal_events',
    '-- @add-column orders coupon_usage_released','-- @add-column order_items variant_id',
    '-- @add-column products seller_id',
    '-- @add-foreign-key products fk_product_seller seller_id seller_profiles id SET NULL',
    '-- @unique-index payment_transactions uq_payment_provider_reference',
    '-- @unique-index seller_ledger uq_seller_ledger_order_entry',
    '-- @add-column seller_ledger withdrawal_id BIGINT UNSIGNED NULL',
    '-- @unique-index seller_ledger uq_seller_ledger_withdrawal_entry',
];
foreach ($required as $needle) if (!str_contains($all, $needle)) throw new RuntimeException('Missing migration contract: ' . $needle);
if (!str_contains(file_get_contents($files[2]), '-- @mode multi_vendor')) throw new RuntimeException('Marketplace migration must be restricted to multi-vendor mode.');
foreach ($files as $file) {
    $buffer = '';
    foreach (preg_split('/\\R/', (string)file_get_contents($file)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '--')) {
            if (str_starts_with($line, '-- @') && $buffer !== '') throw new RuntimeException('Every SQL statement must end with a semicolon before a migration directive: ' . basename($file));
            continue;
        }
        $buffer .= $line . "\n";
        if (str_ends_with($line, ';')) $buffer = '';
    }
    if (trim($buffer) !== '') throw new RuntimeException('Unterminated SQL statement in migration: ' . basename($file));
}

if (preg_match('/^\s*DELETE\s+/mi', $all)) throw new RuntimeException('Migrations must not silently delete customer data.');
$migrator = file_get_contents($root . '/migrator.php');
foreach (['GET_LOCK','RELEASE_LOCK','schema_migrations','information_schema.COLUMNS','information_schema.STATISTICS','information_schema.REFERENTIAL_CONSTRAINTS'] as $needle) {
    if (!str_contains($migrator, $needle)) throw new RuntimeException('Migration runner is missing safety feature: ' . $needle);
}
if (!str_contains($migrator, "PHP_SAPI !== 'cli' && !defined('EPIN_INTERNAL_MIGRATOR')")) throw new RuntimeException('Migration runner must reject direct web access.');
echo "Migration contract checks passed (" . count($files) . " ordered files).\n";
