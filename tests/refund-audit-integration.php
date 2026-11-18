<?php
declare(strict_types=1);

if (!extension_loaded('pdo_mysql')) throw new RuntimeException('pdo_mysql extension is required.');
$host = getenv('EPIN_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('EPIN_TEST_DB_PORT') ?: 3306);
$user = getenv('EPIN_TEST_DB_USER') ?: 'root';
$pass = getenv('EPIN_TEST_DB_PASS') ?: '';
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'epin_refund_audit_test';
$server->exec("DROP DATABASE IF EXISTS `{$database}`");
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

require_once dirname(__DIR__) . '/app/bootstrap.php';
$GLOBALS['app_config'] = [
    'db_host' => $host,
    'db_name' => $database,
    'db_user' => $user,
    'db_pass' => $pass,
    'app_key' => str_repeat('r', 64),
    'business_mode' => 'single_vendor',
];
require_once dirname(__DIR__) . '/app/payments.php';

function refund_audit_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$pdo = db();
$pdo->exec("INSERT INTO users(name,email,password_hash,role) VALUES('Refund Customer','refund-customer@example.test','x','customer')");
$customerId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users(name,email,password_hash,role) VALUES('Refund Admin','refund-admin@example.test','x','admin')");
$adminId = (int)$pdo->lastInsertId();

function create_refund_case(PDO $pdo, int $customerId, string $orderNo, string $paymentStatus, string $orderStatus): int {
    $q = $pdo->prepare('INSERT INTO orders(user_id,order_no,total,status,payment_status) VALUES(?,?,25.00,?,?)');
    $q->execute([$customerId, $orderNo, $orderStatus, $paymentStatus]);
    $orderId = (int)$pdo->lastInsertId();
    $q = $pdo->prepare("INSERT INTO refund_requests(order_id,user_id,reason,status) VALUES(?,?,'Audit test','requested')");
    $q->execute([$orderId, $customerId]);
    return (int)$pdo->lastInsertId();
}

// Approval followed by processing records both transitions, actors and notes.
$refundId = create_refund_case($pdo, $customerId, 'REFUND-AUDIT-PAID', 'paid', 'processing');
$pdo->prepare("INSERT INTO payment_transactions(order_id,provider,provider_reference,amount,status) VALUES(?,'iyzico','REFUND-AUDIT-TX',25.00,'success')")
    ->execute([(int)$pdo->query("SELECT order_id FROM refund_requests WHERE id={$refundId}")->fetchColumn()]);
$pdo->beginTransaction();
refund_transition($pdo, $refundId, 'approved', $adminId, 'Approved after review');
$pdo->commit();
$pdo->beginTransaction();
refund_transition($pdo, $refundId, 'processed', $adminId, 'Provider refund confirmed');
$pdo->commit();

$events = $pdo->prepare('SELECT from_status,to_status,admin_user_id,note FROM refund_request_events WHERE refund_id=? ORDER BY id');
$events->execute([$refundId]);
$history = $events->fetchAll(PDO::FETCH_ASSOC);
refund_audit_assert(count($history) === 2, 'Both refund status transitions must have audit events.');
refund_audit_assert($history[0]['from_status'] === 'requested' && $history[0]['to_status'] === 'approved', 'Approval audit transition is incorrect.');
refund_audit_assert($history[1]['from_status'] === 'approved' && $history[1]['to_status'] === 'processed', 'Processing audit transition is incorrect.');
refund_audit_assert((int)$history[1]['admin_user_id'] === $adminId && $history[1]['note'] === 'Provider refund confirmed', 'Refund audit must retain the acting administrator and decision note.');
$orderId = (int)$pdo->query("SELECT order_id FROM refund_requests WHERE id={$refundId}")->fetchColumn();
$order = $pdo->query("SELECT status,payment_status FROM orders WHERE id={$orderId}")->fetch(PDO::FETCH_ASSOC);
$paymentStatus = $pdo->query("SELECT status FROM payment_transactions WHERE order_id={$orderId}")->fetchColumn();
refund_audit_assert($order['status'] === 'refunded' && $order['payment_status'] === 'paid', 'Processed refund must update the order without erasing its payment history.');
refund_audit_assert($paymentStatus === 'refund_recorded', 'Processed refund must update the related payment transaction.');

// Rejected requests must also be auditable.
$rejectedId = create_refund_case($pdo, $customerId, 'REFUND-AUDIT-REJECTED', 'paid', 'processing');
$pdo->beginTransaction();
refund_transition($pdo, $rejectedId, 'rejected', $adminId, 'Policy does not allow refund');
$pdo->commit();
$rejected = $pdo->prepare('SELECT from_status,to_status,note FROM refund_request_events WHERE refund_id=?');
$rejected->execute([$rejectedId]);
$rejectedEvent = $rejected->fetch(PDO::FETCH_ASSOC);
refund_audit_assert($rejectedEvent !== false && $rejectedEvent['from_status'] === 'requested' && $rejectedEvent['to_status'] === 'rejected' && $rejectedEvent['note'] === 'Policy does not allow refund', 'Rejected refund decisions must be audited.');

// Invalid processing of an unpaid order must leave both state and audit history untouched.
$unpaidId = create_refund_case($pdo, $customerId, 'REFUND-AUDIT-UNPAID', 'unpaid', 'pending');
$pdo->prepare("UPDATE refund_requests SET status='approved' WHERE id=?")->execute([$unpaidId]);
$rejectedUnpaid = false;
$pdo->beginTransaction();
try {
    refund_transition($pdo, $unpaidId, 'processed', $adminId, 'Should fail');
    $pdo->commit();
} catch (RuntimeException $e) {
    $rejectedUnpaid = str_contains($e->getMessage(), 'Ödenmiş olmayan');
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$unpaidStatus = $pdo->query("SELECT status FROM refund_requests WHERE id={$unpaidId}")->fetchColumn();
$unpaidEvents = $pdo->query("SELECT COUNT(*) FROM refund_request_events WHERE refund_id={$unpaidId}")->fetchColumn();
refund_audit_assert($rejectedUnpaid && $unpaidStatus === 'approved' && (int)$unpaidEvents === 0, 'Invalid unpaid refund processing must not mutate state or create an audit event.');

echo "Refund audit integration checks passed.\n";
