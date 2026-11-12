<?php
declare(strict_types=1);
function app_migrate(PDO $pdo): void
{
    $dir = __DIR__ . '/database/migrations';
    if (!is_dir($dir)) throw new RuntimeException('Veritabanı migration klasörü bulunamadı.');
    $dbName = (string)($GLOBALS['app_config']['db_name'] ?? '');
    $lockName = 'epin_schema_' . substr(hash('sha256', $dbName), 0, 32);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 20)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Veritabanı güncelleme kilidi alınamadı. Biraz sonra tekrar deneyin.');
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(190) NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $version = basename($file);
            $sql = file_get_contents($file);
            if ($sql === false) throw new RuntimeException('Migration dosyası okunamadı: ' . $version);
            $mode = (string)($GLOBALS['app_config']['business_mode'] ?? 'single_vendor');
            if (preg_match('/^-- @mode ([a-z_]+)\s*$/m', $sql, $m) && $m[1] !== $mode) continue;
            $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=?');
            $check->execute([$version]);
            if ($check->fetchColumn()) continue;
            $buffer = '';
            foreach (preg_split('/\R/', $sql) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '-- @mode')) continue;
                if (preg_match('/^-- @add-column ([a-zA-Z0-9_]+) ([a-zA-Z0-9_]+) (.+)$/', $line, $m)) {
                    if (!app_schema_column_exists($pdo, $m[1], $m[2])) $pdo->exec('ALTER TABLE ' . app_schema_ident($m[1]) . ' ADD ' . app_schema_ident($m[2]) . ' ' . $m[3]);
                    continue;
                }
                if (preg_match('/^-- @add-foreign-key ([a-zA-Z0-9_]+) ([a-zA-Z0-9_]+) ([a-zA-Z0-9_]+) ([a-zA-Z0-9_]+) ([a-zA-Z0-9_]+) ([A-Z]+)$/', $line, $m)) {
                    $check = $pdo->prepare('SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
                    $check->execute([$m[1], $m[2]]);
                    if (!$check->fetchColumn()) {
                        $pdo->exec('ALTER TABLE ' . app_schema_ident($m[1]) . ' ADD CONSTRAINT ' . app_schema_ident($m[2]) . ' FOREIGN KEY (' . app_schema_ident($m[3]) . ') REFERENCES ' . app_schema_ident($m[4]) . '(' . app_schema_ident($m[5]) . ') ON DELETE ' . $m[6]);
                    }
                    continue;
                }
                if (preg_match('/^-- @unique-index ([a-zA-Z0-9_]+) ([a-zA-Z0-9_]+) ([a-zA-Z0-9_,]+)$/', $line, $m)) {
                    if (!app_schema_index_exists($pdo, $m[1], $m[2])) {
                        $columns = explode(',', $m[3]);
                        $group = implode(',', array_map('app_schema_ident', $columns));
                        $notNull = implode(' AND ', array_map(static fn($column) => app_schema_ident($column) . ' IS NOT NULL', $columns));
                        $dupes = $pdo->query('SELECT COUNT(*) FROM (SELECT 1 FROM ' . app_schema_ident($m[1]) . ' WHERE ' . $notNull . ' GROUP BY ' . $group . ' HAVING COUNT(*) > 1) migration_duplicates')->fetchColumn();
                        if ((int)$dupes > 0) {
                            throw new RuntimeException('Migration durduruldu: ' . $m[1] . ' tablosunda benzersiz olması gereken alanlarda mükerrer kayıtlar var. Hiçbir kayıt silinmedi; veri güvenliği için destek gereklidir.');
                        }
                        $quotedColumns = implode(',', array_map('app_schema_ident', $columns));
                        $pdo->exec('ALTER TABLE ' . app_schema_ident($m[1]) . ' ADD UNIQUE KEY ' . app_schema_ident($m[2]) . '(' . $quotedColumns . ')');
                    }
                    continue;
                }
                if (str_starts_with($line, '--')) continue;
                $buffer .= $line . "\n";
                if (str_ends_with($line, ';')) { $statement = trim($buffer); $buffer = ''; if ($statement !== '') $pdo->exec($statement); }
            }
            if (trim($buffer) !== '') $pdo->exec(trim($buffer));
            $record = $pdo->prepare('INSERT INTO schema_migrations(version) VALUES(?)');
            $record->execute([$version]);
        }
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}
function app_schema_ident(string $identifier): string
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) throw new RuntimeException('Geçersiz migration tanımlayıcısı.');
    return chr(96) . $identifier . chr(96);
}
function app_schema_column_exists(PDO $pdo, string $table, string $column): bool
{
    $q = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table, $column]); return (bool)$q->fetchColumn();
}
function app_schema_index_exists(PDO $pdo, string $table, string $index): bool
{
    $q = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $q->execute([$table, $index]); return (bool)$q->fetchColumn();
}
