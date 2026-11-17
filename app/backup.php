<?php
declare(strict_types=1);
/** Yields a streamed SQL snapshot without creating a server-side dump file. */
function database_backup_chunks(PDO $pdo): Generator
{
    $quoteIdentifier = static function (string $name): string {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('Yedekleme sırasında geçersiz tablo/alan adı bulundu.');
        return chr(96) . $name . chr(96);
    };
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    if (!$tables) throw new RuntimeException('Yedeklenecek veritabanı tablosu bulunamadı.');
    yield "-- E-pin database backup\n-- Created: " . date(DATE_ATOM) . "\n-- Restore only into a trusted, empty database.\n\n";
    yield "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    try {
        foreach ($tables as $tableRow) {
            $table = (string)$tableRow[0]; $quotedTable = $quoteIdentifier($table);
            $createQuery = $pdo->query('SHOW CREATE TABLE ' . $quotedTable);
            $create = $createQuery->fetch(PDO::FETCH_NUM); $createQuery->closeCursor();
            if (!$create || !isset($create[1])) throw new RuntimeException('Tablo yapısı okunamadı: ' . $table);
            yield "-- Structure for " . $table . "\nDROP TABLE IF EXISTS " . $quotedTable . ";\n" . $create[1] . ";\n";
            $columnQuery = $pdo->query('SHOW FULL COLUMNS FROM ' . $quotedTable);
            $columnRows = $columnQuery->fetchAll(PDO::FETCH_ASSOC); $columnQuery->closeCursor();
            $columns = []; $binaryColumns = [];
            foreach ($columnRows as $column) {
                $name = (string)$column['Field'];
                if (str_contains(strtoupper((string)($column['Extra'] ?? '')), 'GENERATED')) continue;
                $columns[] = $name;
                if (preg_match('/^(binary|varbinary|tinyblob|blob|mediumblob|longblob|bit)/i', (string)$column['Type'])) $binaryColumns[$name] = true;
            }
            if (!$columns) continue;
            $select = $pdo->query('SELECT * FROM ' . $quotedTable);
            $quotedColumns = implode(',', array_map($quoteIdentifier, $columns)); $rowCount = 0;
            while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
                $values = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    if ($value === null) $values[] = 'NULL';
                    elseif (isset($binaryColumns[$column])) $values[] = '0x' . bin2hex((string)$value);
                    else { $quoted = $pdo->quote((string)$value); $values[] = $quoted === false ? ('0x' . bin2hex((string)$value)) : $quoted; }
                }
                yield 'INSERT INTO ' . $quotedTable . ' (' . $quotedColumns . ') VALUES (' . implode(',', $values) . ");\n";
                $rowCount++;
            }
            $select->closeCursor(); yield "-- Rows exported: " . $rowCount . "\n\n";
        }
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }
    yield "SET FOREIGN_KEY_CHECKS=1;\n";
}
function stream_database_backup(PDO $pdo): never
{
    if (headers_sent()) throw new RuntimeException('Yedek indirme başlatılamadı; beklenmeyen çıktı üretildi.');
    $filename = 'epin-database-' . date('Ymd-His') . '.sql';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    foreach (database_backup_chunks($pdo) as $chunk) { echo $chunk; if (function_exists('flush')) flush(); }
    exit;
}


/** Restore an E-pin SQL export only into a completely empty database. */
function restore_database_backup(PDO $pdo, string $filePath, int $maxBytes = 33554432): int
{
    if (!is_file($filePath) || !is_readable($filePath)) throw new RuntimeException('Yedek dosyası okunamadı.');
    $size = filesize($filePath);
    if ($size === false || $size < 32 || $size > $maxBytes) throw new RuntimeException('Yedek dosyası boş veya izin verilen 32 MB sınırını aşıyor.');
    $sql = file_get_contents($filePath);
    if (!is_string($sql) || !str_starts_with($sql, '-- E-pin database backup')) throw new RuntimeException('Dosya E-pin tarafından oluşturulmuş geçerli bir SQL yedeği değil.');
    if ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM)) throw new RuntimeException('Geri yükleme yalnızca tamamen boş bir veritabanına yapılabilir. Mevcut mağaza verilerinin üzerine yazılmaz.');

    $statements = []; $buffer = ''; $quote = ''; $escaped = false; $lineComment = false; $blockComment = false;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $ch = $sql[$i]; $next = $i + 1 < $length ? $sql[$i + 1] : '';
        if ($lineComment) { if ($ch === "\n") { $lineComment = false; $buffer .= "\n"; } continue; }
        if ($blockComment) { if ($ch === '*' && $next === '/') { $blockComment = false; $i++; } continue; }
        if ($quote !== '') {
            $buffer .= $ch;
            if ($escaped) { $escaped = false; continue; }
            if ($ch === '\\') { $escaped = true; continue; }
            if ($ch === $quote) { if ($next === $quote && $quote !== chr(96)) { $buffer .= $next; $i++; } else $quote = ''; }
            continue;
        }
        if (($ch === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) || $ch === '#') { $lineComment = true; if ($ch === '-') $i++; continue; }
        if ($ch === '/' && $next === '*') { $blockComment = true; $i++; continue; }
        if ($ch === "'" || $ch === '"' || $ch === chr(96)) { $quote = $ch; $buffer .= $ch; continue; }
        if ($ch === ';') { $statement = trim($buffer); if ($statement !== '') $statements[] = $statement; $buffer = ''; continue; }
        $buffer .= $ch;
    }
    if ($quote !== '' || $blockComment || trim($buffer) !== '') throw new RuntimeException('SQL yedeğinde tamamlanmamış ifade bulundu.');

    $tablesCreated = [];
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($statements as $statement) {
            if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]$/i', $statement) || preg_match("/^SET\s+SQL_MODE\s*=\s*'[^']*'$/i", $statement)) { $pdo->exec($statement); continue; }
            if (preg_match('/^DROP TABLE IF EXISTS \x60([A-Za-z0-9_]+)\x60$/i', $statement, $m)) { $tablesCreated[$m[1]] = true; $pdo->exec($statement); continue; }
            if (preg_match('/^CREATE TABLE \x60([A-Za-z0-9_]+)\x60\s*\(/is', $statement, $m)) { $tablesCreated[$m[1]] = true; $pdo->exec($statement); continue; }
            if (preg_match('/^INSERT INTO \x60([A-Za-z0-9_]+)\x60\s*\(/is', $statement, $m) && isset($tablesCreated[$m[1]])) { $pdo->exec($statement); continue; }
            throw new RuntimeException('Yedek dosyasında izin verilmeyen SQL komutu bulundu.');
        }
        if (!$tablesCreated) throw new RuntimeException('Yedekte geri yüklenecek tablo bulunamadı.');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        return count($tablesCreated);
    } catch (Throwable $e) {
        try { foreach (array_keys($tablesCreated) as $table) if (preg_match('/^[A-Za-z0-9_]+$/', $table)) $pdo->exec('DROP TABLE IF EXISTS ' . chr(96) . $table . chr(96)); }
        catch (Throwable $cleanupError) { error_log('E-pin restore cleanup failed: ' . $cleanupError->getMessage()); }
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
        throw new RuntimeException('Geri yükleme tamamlanamadı. Boş hedef veritabanında oluşturulan tablolar temizlendi. Ayrıntı: ' . $e->getMessage(), 0, $e);
    }
}
