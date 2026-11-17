<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/backup.php';

$host = getenv('EPIN_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('EPIN_TEST_DB_PORT') ?: 3306);
$user = getenv('EPIN_TEST_DB_USER') ?: 'root';
$pass = getenv('EPIN_TEST_DB_PASS') ?: '';
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$suffix = bin2hex(random_bytes(4));
$sourceDb = 'epin_export_' . $suffix;
$targetDb = 'epin_roundtrip_' . $suffix;
$dump = tempnam(sys_get_temp_dir(), 'epin-roundtrip-');
if ($dump === false) throw new RuntimeException('Unable to create round-trip SQL fixture.');

try {
    $server->exec("CREATE DATABASE `{$sourceDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $server->exec("CREATE DATABASE `{$targetDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $source = new PDO("mysql:host={$host};port={$port};dbname={$sourceDb};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $target = new PDO("mysql:host={$host};port={$port};dbname={$targetDb};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $source->exec("CREATE TABLE export_probe (
        id INT NOT NULL AUTO_INCREMENT,
        label VARCHAR(190) NOT NULL,
        payload LONGBLOB NULL,
        optional_text TEXT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $insert = $source->prepare('INSERT INTO export_probe(label,payload,optional_text) VALUES(?,?,?)');
    $insert->execute(["Türkçe içerik; satır\n-- açıklama", "\x00\xFF\x10binary\\payload", null]);

    $sql = implode('', iterator_to_array(database_backup_chunks($source), false));
    if (!str_starts_with($sql, '-- E-pin database backup') || !str_contains($sql, 'CREATE TABLE')) {
        throw new RuntimeException('Backup exporter did not produce a recognizable E-pin SQL dump.');
    }
    file_put_contents($dump, $sql);
    $count = restore_database_backup($target, $dump);
    if ($count !== 1) throw new RuntimeException('Expected exactly one round-trip restored table.');

    $row = $target->query('SELECT label,HEX(payload) AS payload_hex,optional_text FROM export_probe WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if (!$row
        || $row['label'] !== "Türkçe içerik; satır\n-- açıklama"
        || $row['payload_hex'] !== strtoupper(bin2hex("\x00\xFF\x10binary\\payload"))
        || $row['optional_text'] !== null) {
        throw new RuntimeException('Backup and restore round-trip changed text, binary, or NULL values.');
    }
    echo "Database backup/restore round-trip integration checks passed.\n";
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$sourceDb}`");
    $server->exec("DROP DATABASE IF EXISTS `{$targetDb}`");
    @unlink($dump);
}
