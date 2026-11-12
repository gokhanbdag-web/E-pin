<?php
declare(strict_types=1);

if (!extension_loaded('pdo_mysql')) throw new RuntimeException('pdo_mysql extension is required.');
$host = getenv('EPIN_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('EPIN_TEST_DB_PORT') ?: 3306);
$user = getenv('EPIN_TEST_DB_USER') ?: 'root';
$pass = getenv('EPIN_TEST_DB_PASS') ?: '';
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root = dirname(__DIR__);
require_once $root . '/migrator.php';

function test_db(PDO $server, string $name, string $mode): PDO {
    if (!preg_match('/^[a-z0-9_]+$/', $name)) throw new RuntimeException('Unsafe test database name.');
    $server->exec("DROP DATABASE IF EXISTS `{$name}`");
    $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO("mysql:host=" . (getenv('EPIN_TEST_DB_HOST') ?: '127.0.0.1') . ";port=" . (int)(getenv('EPIN_TEST_DB_PORT') ?: 3306) . ";dbname={$name};charset=utf8mb4", getenv('EPIN_TEST_DB_USER') ?: 'root', getenv('EPIN_TEST_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $GLOBALS['app_config'] = ['db_name' => $name, 'business_mode' => $mode];
    return $pdo;
}
function assert_true(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function migration_count(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
}
function has_table(PDO $pdo, string $table): bool {
    $q = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]); return (bool)$q->fetchColumn();
}
function has_index(PDO $pdo, string $table, string $index): bool {
    $q = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $q->execute([$table, $index]); return (bool)$q->fetchColumn();
}
function has_column(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table, $column]); return (bool)$q->fetchColumn();
}

// Clean single-vendor install, followed by a second idempotent migration pass.
$single = test_db($server, 'epin_single_test', 'single_vendor');
app_migrate($single);
assert_true(migration_count($single) === 2, 'Single-vendor install must apply exactly two migrations.');
assert_true(has_table($single, 'products') && has_table($single, 'inventory_codes'), 'Core and extended tables are missing.');
assert_true(!has_table($single, 'seller_profiles'), 'Single-vendor install must not create marketplace tables.');
app_migrate($single);
assert_true(migration_count($single) === 2, 'Repeated single-vendor migration changed applied versions.');

// Clean multi-vendor install.
$multi = test_db($server, 'epin_multi_test', 'multi_vendor');
app_migrate($multi);
assert_true(migration_count($multi) === 3, 'Multi-vendor install must apply all three migrations.');
assert_true(has_table($multi, 'seller_profiles') && has_table($multi, 'seller_ledger') && has_table($multi, 'seller_withdrawals'), 'Marketplace tables are missing.');
assert_true(has_column($multi, 'products', 'seller_id'), 'Marketplace product seller column is missing.');
assert_true(has_index($multi, 'seller_ledger', 'uq_seller_ledger_order_entry'), 'Marketplace ledger unique index is missing.');
app_migrate($multi);
assert_true(migration_count($multi) === 3, 'Repeated multi-vendor migration changed applied versions.');

// Simulate a partially applied marketplace migration on a legacy single-vendor database.
// DDL before the failing unique-index step must survive and the migration must resume safely.
$partial = test_db($server, 'epin_partial_test', 'single_vendor');
app_migrate($partial);
$partial->exec("CREATE TABLE seller_profiles(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL UNIQUE,shop_name VARCHAR(190) NOT NULL,shop_slug VARCHAR(190) NOT NULL UNIQUE,commission_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00,status VARCHAR(20) NOT NULL DEFAULT 'pending',CONSTRAINT fk_seller_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$partial->exec("CREATE TABLE seller_ledger(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,seller_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NULL,entry_type VARCHAR(30) NOT NULL,amount DECIMAL(12,2) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'pending',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,CONSTRAINT fk_ledger_seller FOREIGN KEY(seller_id) REFERENCES seller_profiles(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$partial->exec("INSERT INTO users(name,email,password_hash,role) VALUES('Test Seller','seller@example.test','x','seller')");
$userId = (int)$partial->lastInsertId();
$partial->prepare("INSERT INTO orders(user_id,order_no) VALUES(?,?)")->execute([$userId, 'TEST-ORDER-1']);
$orderId = (int)$partial->lastInsertId();
$partial->prepare('UPDATE orders SET total=10.00 WHERE id=?')->execute([$orderId]);
$partial->prepare("INSERT INTO seller_profiles(user_id,shop_name,shop_slug) VALUES(?,?,?)")->execute([$userId, 'Test Shop', 'test-shop']);
$sellerId = (int)$partial->lastInsertId();
$ledger = $partial->prepare("INSERT INTO seller_ledger(seller_id,order_id,entry_type,amount) VALUES(?,?,'sale_credit',10)");
$ledger->execute([$sellerId, $orderId]);
$ledger->execute([$sellerId, $orderId]);

require_once $root . '/app/bootstrap.php';
$GLOBALS['app_config'] = ['db_host' => $host, 'db_name' => 'epin_partial_test', 'db_user' => $user, 'db_pass' => $pass, 'business_mode' => 'multi_vendor'];
$failedAsExpected = false;
try { db(); } catch (RuntimeException $e) {
    $failedAsExpected = str_contains($e->getMessage(), 'mükerrer kayıtlar');
}
assert_true($failedAsExpected, 'Migration must stop safely when legacy duplicate ledger entries prevent a unique index.');
$check = $partial->prepare('SELECT 1 FROM schema_migrations WHERE version=?');
$check->execute(['003_multi_vendor.sql']);
assert_true(!$check->fetchColumn(), 'Failed marketplace migration must not be marked as applied.');
assert_true(has_column($partial, 'products', 'seller_id'), 'Earlier successful DDL should remain and be detected on retry.');
$keepId = (int)$partial->query('SELECT MIN(id) FROM seller_ledger')->fetchColumn();
$delete = $partial->prepare('DELETE FROM seller_ledger WHERE id=?');
$delete->execute([$keepId]);
db();
assert_true(migration_count($partial) === 3, 'Marketplace migration did not resume after the duplicate was resolved through the application bootstrap.');
assert_true(has_index($partial, 'seller_ledger', 'uq_seller_ledger_order_entry'), 'Resumed migration did not create the ledger unique index.');
db();
assert_true(migration_count($partial) === 3, 'Repeated resumed migration through the application bootstrap is not idempotent.');

require_once $root . '/app/backup.php';
$single->exec("CREATE TABLE backup_probe(id INT AUTO_INCREMENT PRIMARY KEY,sample_text TEXT NOT NULL,payload VARBINARY(16) NOT NULL,optional_value VARCHAR(20) NULL) ENGINE=InnoDB");
$probe = $single->prepare("INSERT INTO backup_probe(sample_text,payload,optional_value) VALUES(?,?,NULL)");
$probe->execute(["O'Reilly test", hex2bin('00FF10')]);
$dump = implode('', iterator_to_array(database_backup_chunks($single), false));
assert_true(str_contains($dump, 'CREATE TABLE `backup_probe`'), 'Backup must include table structure.');
assert_true(str_contains($dump, "O\\'Reilly test"), 'Backup must safely quote apostrophes in text data.');
assert_true(str_contains($dump, '0x00ff10'), 'Backup must preserve binary data as hex literals.');
assert_true(str_contains($dump, 'SET FOREIGN_KEY_CHECKS=1;'), 'Backup must restore foreign-key checks at the end.');
// Payment callback URLs use the saved canonical origin, never the request Host header.
$partial->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('site_url',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute(['https://shop.example.test/subdir']);
$_SERVER['HTTP_HOST'] = 'attacker.example'; $_SERVER['HTTPS'] = 'off';
$callbackUrl = absolute_url('payment/iyzico-callback');
assert_true($callbackUrl === 'https://shop.example.test/subdir/payment/iyzico-callback', 'Payment callback URL must ignore untrusted Host headers and preserve the configured subdirectory.');
require_once $root . '/app/payments.php';
payment_attempt_reserve($orderId, 'paytr', 10.00, 'TEST-PAYMENT-ATTEMPT-1');
$blockedDuplicate = false;
try { payment_attempt_reserve($orderId, 'iyzico', 10.00, 'TEST-PAYMENT-ATTEMPT-2'); } catch (RuntimeException $e) {
    $blockedDuplicate = str_contains($e->getMessage(), 'bekleyen bir ödeme oturumu');
}
assert_true($blockedDuplicate, 'A second provider session must not be created while a payment attempt is pending.');
payment_attempt_fail($orderId, 'paytr', 'TEST-PAYMENT-ATTEMPT-1');
payment_attempt_reserve($orderId, 'iyzico', 10.00, 'TEST-PAYMENT-ATTEMPT-2');
$partial->exec("UPDATE payment_transactions SET created_at=DATE_SUB(NOW(),INTERVAL 31 MINUTE) WHERE provider_reference='TEST-PAYMENT-ATTEMPT-2'");
payment_attempt_reserve($orderId, 'paytr', 10.00, 'TEST-PAYMENT-ATTEMPT-3');
$attemptStates = $partial->query("SELECT provider_reference,status FROM payment_transactions WHERE order_id={$orderId} ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
assert_true(($attemptStates['TEST-PAYMENT-ATTEMPT-2']??'')==='expired'&&($attemptStates['TEST-PAYMENT-ATTEMPT-3']??'')==='pending', 'Expired payment attempts must release the order for a new session.');
payment_mark_paid($orderId, 'paytr', 'TEST-PAYMENT-ATTEMPT-3');
$paidOrder = $partial->prepare('SELECT payment_status,status FROM orders WHERE id=?');$paidOrder->execute([$orderId]);$paidState=$paidOrder->fetch(PDO::FETCH_ASSOC);
assert_true($paidState['payment_status']==='paid', 'The first verified payment must mark the order paid.');
$partial->prepare("INSERT INTO payment_transactions(order_id,provider,provider_reference,amount,status) VALUES(?, 'iyzico','TEST-DUPLICATE-CHARGE',10,'pending')")->execute([$orderId]);
payment_mark_paid($orderId, 'iyzico', 'TEST-DUPLICATE-CHARGE');
$duplicateState=$partial->prepare("SELECT status FROM payment_transactions WHERE provider_reference='TEST-DUPLICATE-CHARGE'");$duplicateState->execute();
assert_true($duplicateState->fetchColumn()==='duplicate_payment', 'A second provider charge must be flagged for manual refund reconciliation.');
$paidOrder->execute([$orderId]);$afterDuplicate=$paidOrder->fetch(PDO::FETCH_ASSOC);
assert_true($afterDuplicate['payment_status']==='paid'&&$afterDuplicate['status']==='manual_review', 'A duplicate callback must preserve paid status and flag the order for manual reconciliation without re-fulfilment.');
payment_mark_paid($orderId, 'paytr', 'TEST-PAYMENT-ATTEMPT-3');
$firstState=$partial->prepare("SELECT status FROM payment_transactions WHERE provider_reference='TEST-PAYMENT-ATTEMPT-3'");$firstState->execute();
assert_true($firstState->fetchColumn()==='success', 'A repeated callback for the same successful transaction must remain idempotent.');
echo "MySQL migration integration checks passed: single-vendor, multi-vendor, idempotency, and interrupted migration recovery.\n";
