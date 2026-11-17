<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/backup.php';

$host = getenv('EPIN_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('EPIN_TEST_DB_PORT') ?: 3306);
$user = getenv('EPIN_TEST_DB_USER') ?: 'root';
$pass = getenv('EPIN_TEST_DB_PASS') ?: '';
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$suffix = bin2hex(random_bytes(4));
$goodDb = 'epin_restore_' . $suffix;
$badDb = 'epin_restore_bad_' . $suffix;
$dump = tempnam(sys_get_temp_dir(), 'epin-restore-');
$badDump = tempnam(sys_get_temp_dir(), 'epin-restore-bad-');
if ($dump === false || $badDump === false) throw new RuntimeException('Unable to create SQL test fixtures.');

try {
    $sql = "-- E-pin database backup\n-- Created: test\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n-- Structure for restore_probe\nDROP TABLE IF EXISTS `restore_probe`;\nCREATE TABLE `restore_probe` (`id` int NOT NULL AUTO_INCREMENT, `value` varchar(255) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\nINSERT INTO `restore_probe` (`id`,`value`) VALUES (1,'semicolon; and -- inside text'),(2,'doubled '' quote and backslash \\\\');\nSET FOREIGN_KEY_CHECKS=1;\n";
    file_put_contents($dump, $sql);
    $server->exec("CREATE DATABASE `{$goodDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $target = new PDO("mysql:host={$host};port={$port};dbname={$goodDb};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $count = restore_database_backup($target, $dump);
    if ($count !== 1) throw new RuntimeException('Expected exactly one restored table.');
    $values = $target->query('SELECT value FROM restore_probe ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    if (count($values) !== 2 || $values[0] !== 'semicolon; and -- inside text' || !str_contains($values[1], "doubled ' quote")) throw new RuntimeException('Restored values did not survive SQL statement parsing.');

    $refused = false;
    try { restore_database_backup($target, $dump); } catch (RuntimeException $e) { $refused = str_contains($e->getMessage(), 'tamamen boş'); }
    if (!$refused || (int)$target->query('SELECT COUNT(*) FROM restore_probe')->fetchColumn() !== 2) throw new RuntimeException('Restore must refuse a populated target without changing it.');

    $server->exec("CREATE DATABASE `{$badDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $badTarget = new PDO("mysql:host={$host};port={$port};dbname={$badDb};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    file_put_contents($badDump, "-- E-pin database backup\nDROP TABLE IF EXISTS `partial_probe`;\nCREATE TABLE `partial_probe` (`id` int NOT NULL) ENGINE=InnoDB;\nDELETE FROM `partial_probe`;\n");
    $rejected = false;
    try { restore_database_backup($badTarget, $badDump); } catch (RuntimeException $e) { $rejected = str_contains($e->getMessage(), 'izin verilmeyen SQL'); }
    if (!$rejected || $badTarget->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM)) throw new RuntimeException('Rejected SQL must trigger cleanup of tables created during the failed restore.');
    echo "Database restore integration checks passed.\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$goodDb}`");
    $server->exec("DROP DATABASE IF EXISTS `{$badDb}`");
    @unlink($dump);
    @unlink($badDump);
}
