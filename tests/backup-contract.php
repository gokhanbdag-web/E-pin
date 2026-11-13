<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$source = file_get_contents($root . '/app/backup.php');
$admin = file_get_contents($root . '/app/admin.php');
$view = file_get_contents($root . '/app/views/admin/manage.php');
$layout = file_get_contents($root . '/app/views/layout-admin.php');
foreach (['database_backup_chunks','SHOW FULL TABLES','SHOW CREATE TABLE','PDO::MYSQL_ATTR_USE_BUFFERED_QUERY','Cache-Control','Content-Disposition','0x'] as $needle) {
    if (!str_contains($source, $needle)) throw new RuntimeException('Database backup is missing: ' . $needle);
}
if (str_contains($source, 'file_put_contents') || str_contains($source, 'fopen(')) throw new RuntimeException('Database backup must not persist dump files on the web server.');
if (!str_contains($admin, "require_login('admin')") || !str_contains($admin, 'csrf_check()') || !str_contains($admin, 'stream_database_backup(db())')) throw new RuntimeException('Backup export must be protected by admin authentication and CSRF.');
if (!str_contains($view, 'name="action" value="export"') || !str_contains($layout, 'admin?section=backup')) throw new RuntimeException('Backup screen is not linked to the protected export action.');
echo "Database backup contract checks passed.\n";
