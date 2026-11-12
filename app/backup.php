<?php
declare(strict_types=1);
/** Streams a SQL snapshot directly to an authenticated administrator. */
function stream_database_backup(PDO $pdo): never
{
    $quoteIdentifier = static function (string $name): string {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('Yedekleme sırasında geçersiz tablo/alan adı bulundu.');
        return chr(96) . $name . chr(96);
    };
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    if (!$tables) throw new RuntimeException('Yedeklenecek veritabanı tablosu bulunamadı.');
    if (headers_sent()) throw new RuntimeException('Yedek indirme başlatılamadı; beklenmeyen çıktı üretildi.');
    $filename = 'epin-database-' . date('Ymd-His') . '.sql';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $write = static function (string $chunk): void { echo $chunk; if (function_exists('flush')) flush(); };
    $write("-- E-pin database backup\n-- Created: " . date(DATE_ATOM) . "\n-- Restore only into a trusted, empty database.\n\n");
    $write("SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    foreach ($tables as $tableRow) {
        $table = (string)$tableRow[0]; $quotedTable = $quoteIdentifier($table);
        $create = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
        if (!$create || !isset($create[1])) throw new RuntimeException('Tablo yapısı okunamadı: ' . $table);
        $write("-- Structure for " . $table . "\nDROP TABLE IF EXISTS " . $quotedTable . ";\n" . $create[1] . ";\n");
        $columnRows = $pdo->query('SHOW FULL COLUMNS FROM ' . $quotedTable)->fetchAll(PDO::FETCH_ASSOC);
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
            $write('INSERT INTO ' . $quotedTable . ' (' . $quotedColumns . ') VALUES (' . implode(',', $values) . ");\n");
            $rowCount++;
        }
        $select->closeCursor(); $write("-- Rows exported: " . $rowCount . "\n\n");
    }
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    $write("SET FOREIGN_KEY_CHECKS=1;\n");
    exit;
}
