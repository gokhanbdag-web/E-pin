<?php
declare(strict_types=1);

if (!extension_loaded('pdo_mysql')) throw new RuntimeException('pdo_mysql extension is required.');
$host = getenv('EPIN_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('EPIN_TEST_DB_PORT') ?: 3306);
$user = getenv('EPIN_TEST_DB_USER') ?: 'root';
$pass = getenv('EPIN_TEST_DB_PASS') ?: '';
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'epin_payment_lifecycle_test';
$server->exec("DROP DATABASE IF EXISTS `{$database}`");
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

require_once dirname(__DIR__) . '/app/bootstrap.php';
$GLOBALS['app_config'] = [
    'db_host' => $host,
    'db_name' => $database,
    'db_user' => $user,
    'db_pass' => $pass,
    'app_key' => str_repeat('k', 64),
    'business_mode' => 'single_vendor',
];
require_once dirname(__DIR__) . '/app/payments.php';

function payment_lifecycle_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$pdo = db();
$pdo->exec("INSERT INTO users(name,email,password_hash,role) VALUES('Lifecycle Test','lifecycle@example.test','x','customer')");
$userId = (int)$pdo->lastInsertId();

function lifecycle_order(PDO $pdo, int $userId, string $orderNo, string $status = 'pending'): int {
    $q = $pdo->prepare('INSERT INTO orders(user_id,order_no,total,status,payment_status) VALUES(?,?,10.00,?,\'unpaid\')');
    $q->execute([$userId, $orderNo, $status]);
    return (int)$pdo->lastInsertId();
}
function lifecycle_attempt(PDO $pdo, int $orderId, string $reference, float $amount = 10.00): void {
    $q = $pdo->prepare("INSERT INTO payment_transactions(order_id,provider,provider_reference,amount,status) VALUES(?,'iyzico',?,?,'pending')");
    $q->execute([$orderId, $reference, $amount]);
}

// Replaying the exact same verified callback must be idempotent.
$orderId = lifecycle_order($pdo, $userId, 'LIFECYCLE-REPLAY');
lifecycle_attempt($pdo, $orderId, 'TX-REPLAY');
payment_mark_paid($orderId, 'iyzico', 'TX-REPLAY');
payment_mark_paid($orderId, 'iyzico', 'TX-REPLAY');
$order = $pdo->query("SELECT payment_status,status FROM orders WHERE id={$orderId}")->fetch(PDO::FETCH_ASSOC);
$attempt = $pdo->query("SELECT status FROM payment_transactions WHERE order_id={$orderId} AND provider_reference='TX-REPLAY'")->fetchColumn();
payment_lifecycle_assert($order['payment_status'] === 'paid' && $order['status'] === 'processing', 'A replayed callback must leave the paid order in its original processing state.');
payment_lifecycle_assert($attempt === 'success', 'A replayed callback must not change a successful payment attempt.');

// A second successful provider transaction for an already-paid order must be flagged.
lifecycle_attempt($pdo, $orderId, 'TX-DUPLICATE');
payment_mark_paid($orderId, 'iyzico', 'TX-DUPLICATE');
$orderStatus = $pdo->query("SELECT status FROM orders WHERE id={$orderId}")->fetchColumn();
$duplicateStatus = $pdo->query("SELECT status FROM payment_transactions WHERE order_id={$orderId} AND provider_reference='TX-DUPLICATE'")->fetchColumn();
payment_lifecycle_assert($orderStatus === 'manual_review', 'A second charge against an already-paid order must require manual review.');
payment_lifecycle_assert($duplicateStatus === 'duplicate_payment', 'A second charge must be marked as a duplicate payment.');

// A verified payment arriving after cancellation must never trigger fulfilment.
$cancelledId = lifecycle_order($pdo, $userId, 'LIFECYCLE-LATE-CANCELLED', 'cancelled');
lifecycle_attempt($pdo, $cancelledId, 'TX-LATE-CANCELLED');
payment_mark_paid($cancelledId, 'iyzico', 'TX-LATE-CANCELLED');
$cancelled = $pdo->query("SELECT payment_status,status FROM orders WHERE id={$cancelledId}")->fetch(PDO::FETCH_ASSOC);
payment_lifecycle_assert($cancelled['payment_status'] === 'paid' && $cancelled['status'] === 'manual_review', 'A late payment for a cancelled order must be recorded and held for manual review.');

// A payment received while a refund request is open must also be held.
$refundId = lifecycle_order($pdo, $userId, 'LIFECYCLE-OPEN-REFUND');
$pdo->prepare("INSERT INTO refund_requests(order_id,user_id,reason,status) VALUES(?,?,'Lifecycle integration test','requested')")->execute([$refundId, $userId]);
lifecycle_attempt($pdo, $refundId, 'TX-OPEN-REFUND');
payment_mark_paid($refundId, 'iyzico', 'TX-OPEN-REFUND');
$refundOrder = $pdo->query("SELECT payment_status,status FROM orders WHERE id={$refundId}")->fetch(PDO::FETCH_ASSOC);
payment_lifecycle_assert($refundOrder['payment_status'] === 'paid' && $refundOrder['status'] === 'manual_review', 'A payment received during an open refund request must require manual review.');

// Transaction amount mismatch must not mutate the order or mark the attempt successful.
$mismatchId = lifecycle_order($pdo, $userId, 'LIFECYCLE-AMOUNT-MISMATCH');
lifecycle_attempt($pdo, $mismatchId, 'TX-AMOUNT-MISMATCH', 9.99);
$mismatchRejected = false;
try {
    payment_mark_paid($mismatchId, 'iyzico', 'TX-AMOUNT-MISMATCH');
} catch (RuntimeException $e) {
    $mismatchRejected = str_contains($e->getMessage(), 'tutarı');
}
$mismatchOrder = $pdo->query("SELECT payment_status,status FROM orders WHERE id={$mismatchId}")->fetch(PDO::FETCH_ASSOC);
$mismatchAttempt = $pdo->query("SELECT status FROM payment_transactions WHERE order_id={$mismatchId}")->fetchColumn();
payment_lifecycle_assert($mismatchRejected, 'A transaction with a mismatched amount must be rejected.');
payment_lifecycle_assert($mismatchOrder['payment_status'] === 'unpaid' && $mismatchOrder['status'] === 'pending' && $mismatchAttempt === 'pending', 'An amount mismatch must leave the order and payment attempt unchanged.');

echo "Payment lifecycle integration checks passed.\n";
