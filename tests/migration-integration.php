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
assert_true(migration_count($multi) === 5, 'Multi-vendor install must apply all five migrations.');
assert_true(has_table($multi, 'seller_profiles') && has_table($multi, 'seller_ledger') && has_table($multi, 'seller_withdrawals') && has_table($multi, 'seller_withdrawal_events'), 'Marketplace tables are missing.');
assert_true(has_column($multi, 'products', 'seller_id'), 'Marketplace product seller column is missing.');
assert_true(has_index($multi, 'seller_ledger', 'uq_seller_ledger_order_entry'), 'Marketplace ledger unique index is missing.');
assert_true(has_column($multi, 'seller_ledger', 'withdrawal_id'), 'Seller withdrawal ledger reference is missing.');
assert_true(has_index($multi, 'seller_ledger', 'uq_seller_ledger_withdrawal_entry'), 'Seller withdrawal idempotency index is missing.');
app_migrate($multi);
assert_true(migration_count($multi) === 5, 'Repeated multi-vendor migration changed applied versions.');

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
assert_true(migration_count($partial) === 5, 'Marketplace migrations did not resume after the duplicate was resolved through the application bootstrap.');
assert_true(has_index($partial, 'seller_ledger', 'uq_seller_ledger_order_entry'), 'Resumed migration did not create the ledger unique index.');
db();
assert_true(migration_count($partial) === 5, 'Repeated resumed migration through the application bootstrap is not idempotent.');
// A paid withdrawal must have a durable, unique ledger reference to its request.
$partial->prepare("INSERT INTO seller_withdrawals(seller_id,amount,note) VALUES(?,5,'test payout')")->execute([$sellerId]);
$withdrawalId=(int)$partial->lastInsertId();
$withdrawalLedger=$partial->prepare("INSERT INTO seller_ledger(seller_id,order_id,withdrawal_id,entry_type,amount,status) VALUES(?,NULL,?,'withdrawal',-5,'available')");
$withdrawalLedger->execute([$sellerId,$withdrawalId]);
$duplicateWithdrawalBlocked=false;
try{$withdrawalLedger->execute([$sellerId,$withdrawalId]);}catch(PDOException $e){$duplicateWithdrawalBlocked=true;}
assert_true($duplicateWithdrawalBlocked,'The database must reject duplicate ledger debits for the same withdrawal request.');
$withdrawalRef=$partial->prepare("SELECT withdrawal_id,amount FROM seller_ledger WHERE seller_id=? AND withdrawal_id=? AND entry_type='withdrawal'");
$withdrawalRef->execute([$sellerId,$withdrawalId]);$withdrawalEntry=$withdrawalRef->fetch(PDO::FETCH_ASSOC);
assert_true($withdrawalEntry!==false&&(int)$withdrawalEntry['withdrawal_id']===$withdrawalId&&(float)$withdrawalEntry['amount']===-5.0,'Withdrawal debit must point to the exact payout request.');
$partial->prepare("INSERT INTO seller_withdrawal_events(withdrawal_id,admin_user_id,from_status,to_status,note) VALUES(?,?,'pending','approved','review test')")->execute([$withdrawalId,$userId]);
$eventCheck=$partial->prepare('SELECT from_status,to_status,note FROM seller_withdrawal_events WHERE withdrawal_id=?');$eventCheck->execute([$withdrawalId]);$event=$eventCheck->fetch(PDO::FETCH_ASSOC);
assert_true($event!==false&&$event['from_status']==='pending'&&$event['to_status']==='approved'&&$event['note']==='review test','Withdrawal state changes must have an auditable actor-linked history.');


// Financial summary reconciles pending credits, net available ledger, reservations and historical payouts.
$beforeFinance=seller_financial_summary($sellerId);
$partial->prepare("INSERT INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,NULL,'sale_credit',12,'pending')")->execute([$sellerId]);
$partial->prepare("INSERT INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,NULL,'sale_credit',100,'available')")->execute([$sellerId]);
$partial->prepare("INSERT INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,NULL,'refund_debit',-20,'available')")->execute([$sellerId]);
$partial->prepare("INSERT INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,NULL,'platform_commission',-5,'settled')")->execute([$sellerId]);
$partial->prepare("INSERT INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,NULL,'commission_reversal',2,'settled')")->execute([$sellerId]);
$partial->prepare("INSERT INTO seller_withdrawals(seller_id,amount,status,note) VALUES(?,15,'pending','pending summary test'),(?,5,'approved','approved summary test'),(?,30,'paid','paid summary test')")->execute([$sellerId,$sellerId,$sellerId]);
$afterFinance=seller_financial_summary($sellerId);
assert_true(abs(($afterFinance['pendingEarnings']-$beforeFinance['pendingEarnings'])-12)<0.001,'Pending seller earnings must be reported separately.');
assert_true(abs(($afterFinance['ledgerBalance']-$beforeFinance['ledgerBalance'])-80)<0.001,'Net available ledger must include refund debits but exclude settled commission rows.');
assert_true(abs(($afterFinance['reservedBalance']-$beforeFinance['reservedBalance'])-20)<0.001,'Pending and approved withdrawals must reserve funds.');
assert_true(abs(($afterFinance['withdrawnTotal']-$beforeFinance['withdrawnTotal'])-30)<0.001,'Paid withdrawals must be reported as historical payouts.');
assert_true(abs(($afterFinance['refundedTotal']-$beforeFinance['refundedTotal'])-20)<0.001,'Refund debits must be shown as positive refunded totals.');
assert_true(abs(($afterFinance['commissionNet']-$beforeFinance['commissionNet'])-(-3))<0.001,'Commission reversals must offset platform commission.');

// Withdrawal request creation serializes on the seller row and includes existing reservations.
$withdrawalCapacity=seller_financial_summary($sellerId)['availableBalance'];
assert_true($withdrawalCapacity>=1,'Test seller must have funds available for withdrawal reservation checks.');
$firstRequest=seller_request_withdrawal($sellerId,$withdrawalCapacity);
assert_true($firstRequest>0,'A valid withdrawal request should be created.');
$overspendRejected=false;
try{seller_request_withdrawal($sellerId,0.01);}catch(RuntimeException $e){$overspendRejected=str_contains($e->getMessage(),'yetersiz');}
assert_true($overspendRejected,'A second withdrawal request must not reserve funds already committed by a pending request.');
$requestedRow=$partial->prepare('SELECT amount,status FROM seller_withdrawals WHERE id=? AND seller_id=?');$requestedRow->execute([$firstRequest,$sellerId]);$requested=$requestedRow->fetch(PDO::FETCH_ASSOC);
assert_true($requested!==false&&(float)$requested['amount']===$withdrawalCapacity&&$requested['status']==='pending','A withdrawal request must persist its rounded amount in pending state.');
$creationEvent=$partial->prepare("SELECT from_status,to_status,admin_user_id,note FROM seller_withdrawal_events WHERE withdrawal_id=? ORDER BY id LIMIT 1");$creationEvent->execute([$firstRequest]);$createdAudit=$creationEvent->fetch(PDO::FETCH_ASSOC);
assert_true($createdAudit!==false&&$createdAudit['from_status']==='created'&&$createdAudit['to_status']==='pending'&&$createdAudit['admin_user_id']===null&&$createdAudit['note']===null,'Withdrawal creation must be recorded as a system audit event.');
$invalidAmountRejected=false;
try{seller_request_withdrawal($sellerId,NAN);}catch(RuntimeException $e){$invalidAmountRejected=true;}
assert_true($invalidAmountRejected,'Non-finite withdrawal amounts must be rejected.');
 
// Exercise the same withdrawal decision service used by the admin route.
seller_review_withdrawal($firstRequest,'approved',$userId,'Approved in integration test');
$approvedState=$partial->prepare('SELECT status,admin_note FROM seller_withdrawals WHERE id=?');$approvedState->execute([$firstRequest]);$approvedRow=$approvedState->fetch(PDO::FETCH_ASSOC);
assert_true($approvedRow!==false&&$approvedRow['status']==='approved'&&$approvedRow['admin_note']==='Approved in integration test','Admin approval must move a pending withdrawal to approved and persist its note.');
seller_review_withdrawal($firstRequest,'paid',$userId,'Payout sent');
$paidState=$partial->prepare('SELECT status,admin_note FROM seller_withdrawals WHERE id=?');$paidState->execute([$firstRequest]);$paidRow=$paidState->fetch(PDO::FETCH_ASSOC);
assert_true($paidRow!==false&&$paidRow['status']==='paid'&&$paidRow['admin_note']==='Payout sent','Admin payout completion must move an approved withdrawal to paid.');
$payoutCount=$partial->prepare("SELECT COUNT(*) FROM seller_ledger WHERE withdrawal_id=? AND entry_type='withdrawal'");$payoutCount->execute([$firstRequest]);
assert_true((int)$payoutCount->fetchColumn()===1,'Completing a payout must create exactly one withdrawal ledger debit.');
$paidEventCount=$partial->prepare("SELECT COUNT(*) FROM seller_withdrawal_events WHERE withdrawal_id=? AND to_status IN ('approved','paid')");$paidEventCount->execute([$firstRequest]);
assert_true((int)$paidEventCount->fetchColumn()===2,'Approval and payout completion must each create an audit event.');
$invalidTransitionRejected=false;
try{seller_review_withdrawal($firstRequest,'rejected',$userId,'Invalid after payout');}catch(RuntimeException $e){$invalidTransitionRejected=true;}
assert_true($invalidTransitionRejected,'A paid withdrawal must not transition to rejected.');
$partial->prepare("INSERT INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,NULL,'sale_credit',7,'available')")->execute([$sellerId]);
$rejectRequest=seller_request_withdrawal($sellerId,7,'Reject path test');
$beforeReject=seller_financial_summary($sellerId)['reservedBalance'];
seller_review_withdrawal($rejectRequest,'rejected',$userId,'Rejected in integration test');
$rejectedState=$partial->prepare('SELECT status,admin_note FROM seller_withdrawals WHERE id=?');$rejectedState->execute([$rejectRequest]);$rejectedRow=$rejectedState->fetch(PDO::FETCH_ASSOC);
assert_true($rejectedRow!==false&&$rejectedRow['status']==='rejected'&&$rejectedRow['admin_note']==='Rejected in integration test','Admin rejection must persist the rejected state and note.');
$afterReject=seller_financial_summary($sellerId)['reservedBalance'];
assert_true(abs(($beforeReject-$afterReject)-7)<0.001,'Rejecting a withdrawal must release its reserved balance.');
$rejectEvent=$partial->prepare("SELECT from_status,to_status,admin_user_id,note FROM seller_withdrawal_events WHERE withdrawal_id=? ORDER BY id DESC LIMIT 1");$rejectEvent->execute([$rejectRequest]);$rejectAudit=$rejectEvent->fetch(PDO::FETCH_ASSOC);
assert_true($rejectAudit!==false&&$rejectAudit['from_status']==='pending'&&$rejectAudit['to_status']==='rejected'&&(int)$rejectAudit['admin_user_id']===$userId&&$rejectAudit['note']==='Rejected in integration test','Withdrawal rejection must record its actor, transition and note.');
 
// The review service must preserve caller-owned transactions and roll back failed audit writes.
$partial->prepare("INSERT INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,NULL,'sale_credit',3,'available')")->execute([$sellerId]);
$rollbackRequest=seller_request_withdrawal($sellerId,3,'Rollback path test');
$reviewPdo=db();
$reviewPdo->beginTransaction();
seller_review_withdrawal($rollbackRequest,'approved',$userId,'Must be rolled back by caller');
assert_true($reviewPdo->inTransaction(),'Withdrawal review service must not commit a transaction it did not start.');
$reviewPdo->rollBack();
$rollbackState=$reviewPdo->prepare('SELECT status FROM seller_withdrawals WHERE id=?');$rollbackState->execute([$rollbackRequest]);
assert_true($rollbackState->fetchColumn()==='pending','Caller rollback must undo the withdrawal status update.');
$rollbackEvents=$reviewPdo->prepare("SELECT COUNT(*) FROM seller_withdrawal_events WHERE withdrawal_id=? AND from_status='pending' AND to_status='approved'");$rollbackEvents->execute([$rollbackRequest]);
assert_true((int)$rollbackEvents->fetchColumn()===0,'Caller rollback must undo the approval audit event.');
$initialEvents=$reviewPdo->prepare("SELECT COUNT(*) FROM seller_withdrawal_events WHERE withdrawal_id=? AND from_status='created' AND to_status='pending'");$initialEvents->execute([$rollbackRequest]);
assert_true((int)$initialEvents->fetchColumn()===1,'Caller rollback must preserve the original withdrawal creation audit event.');
$failedAuditRequest=seller_request_withdrawal($sellerId,3,'Failed audit path test');
$failedAuditRolledBack=false;
try{seller_review_withdrawal($failedAuditRequest,'approved',999999,'Invalid admin foreign key');}catch(PDOException $e){$failedAuditRolledBack=true;}
assert_true($failedAuditRolledBack,'An invalid audit actor must cause the review transaction to fail.');
$failedAuditState=$reviewPdo->prepare('SELECT status FROM seller_withdrawals WHERE id=?');$failedAuditState->execute([$failedAuditRequest]);
assert_true($failedAuditState->fetchColumn()==='pending','Failed audit insertion must roll back the withdrawal status change.');
$failedAuditEvents=$reviewPdo->prepare('SELECT COUNT(*) FROM seller_withdrawal_events WHERE withdrawal_id=?');$failedAuditEvents->execute([$failedAuditRequest]);
assert_true((int)$failedAuditEvents->fetchColumn()===0,'Failed audit insertion must not leave a partial event.');



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
// Refund ledger reversals: pending seller credit is cancelled; available credit is debited.
$partial->prepare("INSERT INTO orders(user_id,order_no,total,status,payment_status) VALUES(?,?,?,?,?)")->execute([$userId,'TEST-REFUND-PENDING',10,'processing','paid']);
$refundPendingOrder=(int)$partial->lastInsertId();
$partial->prepare("INSERT INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,?,'sale_credit',10,'pending')")->execute([$sellerId,$refundPendingOrder]);
$partial->prepare("INSERT INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,?,'platform_commission',-1,'settled')")->execute([$sellerId,$refundPendingOrder]);
$appPdo=db();$appPdo->beginTransaction();seller_reverse_refunded_order($refundPendingOrder);$appPdo->commit();
$pendingLedger=$partial->prepare("SELECT entry_type,amount,status FROM seller_ledger WHERE order_id=? ORDER BY entry_type");$pendingLedger->execute([$refundPendingOrder]);$pendingEntries=$pendingLedger->fetchAll(PDO::FETCH_UNIQUE);
assert_true(($pendingEntries['sale_credit']['status']??'')==='reversed', 'Refund must reverse pending seller credit.');
assert_true((float)($pendingEntries['commission_reversal']['amount']??0)===1.0, 'Refund must reverse the platform commission.');
assert_true(!isset($pendingEntries['refund_debit']), 'Pending seller credit must not create an available-balance debit.');
$partial->prepare("INSERT INTO orders(user_id,order_no,total,status,payment_status) VALUES(?,?,?,?,?)")->execute([$userId,'TEST-REFUND-AVAILABLE',8,'processing','paid']);
$refundAvailableOrder=(int)$partial->lastInsertId();
$partial->prepare("INSERT INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,?,'sale_credit',8,'available')")->execute([$sellerId,$refundAvailableOrder]);
$partial->prepare("INSERT INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,?,'platform_commission',-2,'settled')")->execute([$sellerId,$refundAvailableOrder]);
$appPdo->beginTransaction();seller_reverse_refunded_order($refundAvailableOrder);$appPdo->commit();
$availableLedger=$partial->prepare("SELECT entry_type,amount,status FROM seller_ledger WHERE order_id=? ORDER BY entry_type");$availableLedger->execute([$refundAvailableOrder]);$availableEntries=$availableLedger->fetchAll(PDO::FETCH_UNIQUE);
assert_true((float)($availableEntries['refund_debit']['amount']??0)===-8.0&&($availableEntries['refund_debit']['status']??'')==='available', 'Refund must debit already available seller credit.');
$appPdo->beginTransaction();seller_reverse_refunded_order($refundAvailableOrder);$appPdo->commit();
$reversalCount=$partial->prepare("SELECT COUNT(*) FROM seller_ledger WHERE order_id=? AND entry_type IN ('refund_debit','commission_reversal')");$reversalCount->execute([$refundAvailableOrder]);
assert_true((int)$reversalCount->fetchColumn()===2, 'Repeated refund processing must not duplicate reversal entries.');

// Expired orders release coupon usage and pending payment attempts exactly once.
$partial->prepare("INSERT INTO coupons(code,discount_type,discount_value,usage_limit,usage_count,status) VALUES('TIMEOUT10','fixed',10,10,1,'active')")->execute();
$partial->prepare("INSERT INTO orders(user_id,order_no,subtotal,discount_total,coupon_code,total,status,payment_status,created_at) VALUES(?,?,?,?,?,?,'pending','unpaid',DATE_SUB(NOW(),INTERVAL 31 MINUTE))")->execute([$userId,'TEST-COUPON-EXPIRE',10,10,'TIMEOUT10',0]);
$expiredCouponOrder=(int)$partial->lastInsertId();
$partial->prepare("INSERT INTO payment_transactions(order_id,provider,provider_reference,amount,status) VALUES(?,'paytr','TEST-EXPIRED-ATTEMPT',0,'pending')")->execute([$expiredCouponOrder]);
$appPdo=db();
$appPdo->beginTransaction();expire_stale_orders($appPdo);$appPdo->commit();
$expiredState=$partial->prepare('SELECT status,coupon_usage_released FROM orders WHERE id=?');$expiredState->execute([$expiredCouponOrder]);$expiredRow=$expiredState->fetch(PDO::FETCH_ASSOC);
assert_true($expiredRow['status']==='expired'&&(int)$expiredRow['coupon_usage_released']===1,'Timed-out order must be expired and coupon release marked.');
assert_true((int)$partial->query("SELECT usage_count FROM coupons WHERE code='TIMEOUT10'")->fetchColumn()===0,'Timed-out order must decrement coupon usage exactly once.');
assert_true($partial->query("SELECT status FROM payment_transactions WHERE provider_reference='TEST-EXPIRED-ATTEMPT'")->fetchColumn()==='expired','Pending payment attempt must expire with its order.');
$appPdo->beginTransaction();expire_stale_orders($appPdo);$appPdo->commit();
assert_true((int)$partial->query("SELECT usage_count FROM coupons WHERE code='TIMEOUT10'")->fetchColumn()===0,'Repeated timeout cleanup must not decrement coupon usage twice.');
// A late verified payment restores the released coupon usage exactly once and requires manual review.
$partial->prepare("INSERT INTO orders(user_id,order_no,subtotal,discount_total,coupon_code,total,status,payment_status,coupon_usage_released) VALUES(?,?,?,?,?,?,'expired','unpaid',1)")->execute([$userId,'TEST-LATE-COUPON',10,5,'TIMEOUT10',5]);
$lateOrder=(int)$partial->lastInsertId();
$partial->prepare("INSERT INTO payment_transactions(order_id,provider,provider_reference,amount,status) VALUES(?,'paytr','TEST-LATE-COUPON-PAY',5,'pending')")->execute([$lateOrder]);
payment_mark_paid($lateOrder,'paytr','TEST-LATE-COUPON-PAY');
$lateState=$partial->prepare('SELECT status,payment_status,coupon_usage_released FROM orders WHERE id=?');$lateState->execute([$lateOrder]);$lateRow=$lateState->fetch(PDO::FETCH_ASSOC);
assert_true($lateRow['status']==='manual_review'&&$lateRow['payment_status']==='paid'&&(int)$lateRow['coupon_usage_released']===0,'Late payment must be paid but held for manual review, with coupon release cleared.');
assert_true((int)$partial->query("SELECT usage_count FROM coupons WHERE code='TIMEOUT10'")->fetchColumn()===1,'Late verified payment must restore coupon usage exactly once.');
payment_mark_paid($lateOrder,'paytr','TEST-LATE-COUPON-PAY');
assert_true((int)$partial->query("SELECT usage_count FROM coupons WHERE code='TIMEOUT10'")->fetchColumn()===1,'Repeated late payment callback must not restore coupon usage twice.');
// Seller earnings are recorded in the same transaction as payment acceptance.
$partial->prepare("INSERT INTO orders(user_id,order_no,total,status,payment_status) VALUES(?,?,?,'pending','unpaid')")->execute([$userId,'TEST-PAID-SELLER-LEDGER',20]);
$sellerPaidOrder=(int)$partial->lastInsertId();
$partial->prepare("INSERT INTO order_items(order_id,product_id,seller_id,seller_commission_rate,discount_amount,product_name,quantity,unit_price) VALUES(?,NULL,?,10,0,'Seller item',1,20)")->execute([$sellerPaidOrder,$sellerId]);
$partial->prepare("INSERT INTO payment_transactions(order_id,provider,provider_reference,amount,status) VALUES(?,'paytr','TEST-SELLER-LEDGER-PAY',20,'pending')")->execute([$sellerPaidOrder]);
payment_mark_paid($sellerPaidOrder,'paytr','TEST-SELLER-LEDGER-PAY');
$sellerCredit=$partial->prepare("SELECT amount,status FROM seller_ledger WHERE order_id=? AND seller_id=? AND entry_type='sale_credit'");$sellerCredit->execute([$sellerPaidOrder,$sellerId]);$sellerCreditRow=$sellerCredit->fetch(PDO::FETCH_ASSOC);
assert_true($sellerCreditRow!==false&&(float)$sellerCreditRow['amount']===18.0,'Successful marketplace payment must create the seller credit before fulfillment can race with a refund.');
// A refund committed before fulfilment must never be overwritten or delivered afterward.
$appPdo->beginTransaction();seller_reverse_refunded_order($sellerPaidOrder);$appPdo->prepare("UPDATE orders SET status='refunded' WHERE id=?")->execute([$sellerPaidOrder]);$appPdo->commit();
$refusedFulfillment=false;try{fulfill_paid_order($sellerPaidOrder);}catch(RuntimeException $e){$refusedFulfillment=str_contains($e->getMessage(),'sipariş durumunda');}
assert_true($refusedFulfillment,'A refunded order must be blocked from late fulfilment.');
assert_true($partial->query("SELECT status FROM orders WHERE id={$sellerPaidOrder}")->fetchColumn()==='refunded','A fulfilment race must not overwrite the refunded order state.');
echo "MySQL migration integration checks passed: single-vendor, multi-vendor, idempotency, and interrupted migration recovery.\n";
