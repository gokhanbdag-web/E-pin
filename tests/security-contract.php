<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$boot = file_get_contents($root . '/app/bootstrap.php');
$admin = file_get_contents($root . '/app/admin.php');
$view = file_get_contents($root . '/app/views/admin/manage.php');
$start = strpos($boot, 'function absolute_url');
$end = strpos($boot, 'function e(', $start);
$absolute = substr($boot, $start, $end - $start);
if (!str_contains($absolute, "setting_key='site_url'")) throw new RuntimeException('Canonical site URL must be read from saved settings.');
if (str_contains($absolute, 'HTTP_HOST')) throw new RuntimeException('Absolute callback URLs must not trust the request Host header.');
if (!str_contains($admin, "'site_url','site_title'")) throw new RuntimeException('Canonical site URL must be editable in store settings.');
if (!str_contains($view, 'name="site_url"')) throw new RuntimeException('Canonical site URL input is missing.');
echo "Security contract checks passed.\n";
