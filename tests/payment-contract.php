<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/payments.php';

function payment_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$key = 'test-merchant-key';
$salt = 'test-merchant-salt';
$post = [
    'merchant_oid' => 'EP261112A1B2C3D4',
    'status' => 'success',
    'total_amount' => '129900',
];
$raw = $post['merchant_oid'] . $salt . $post['status'] . $post['total_amount'];
$post['hash'] = base64_encode(hash_hmac('sha256', $raw, $key, true));

payment_assert(paytr_signature_matches($post, $key, $salt), 'A correctly signed PayTR callback must be accepted.');
payment_assert(!paytr_signature_matches($post, '', $salt), 'PayTR callback must be rejected when merchant credentials are missing.');
$tampered = $post;
$tampered['total_amount'] = '1';
payment_assert(!paytr_signature_matches($tampered, $key, $salt), 'A modified PayTR amount must invalidate the signature.');
$tampered = $post;
$tampered['status'] = 'failed';
payment_assert(!paytr_signature_matches($tampered, $key, $salt), 'A modified PayTR status must invalidate the signature.');

$iyzico = [
    'status' => 'success',
    'paymentStatus' => 'SUCCESS',
    'fraudStatus' => 1,
    'currency' => 'TRY',
    'conversationId' => 'EP261112A1B2C3D4',
    'paidPrice' => '1299.00',
];
payment_assert(iyzico_checkout_result_valid($iyzico, 'EP261112A1B2C3D4', '1299.00'), 'A fully verified iyzico payment must be accepted.');
foreach ([
    'status' => 'failure',
    'paymentStatus' => 'FAILURE',
    'fraudStatus' => 0,
    'currency' => 'USD',
    'conversationId' => 'ANOTHER-ORDER',
    'paidPrice' => '1298.99',
] as $field => $value) {
    $invalid = $iyzico;
    $invalid[$field] = $value;
    payment_assert(!iyzico_checkout_result_valid($invalid, 'EP261112A1B2C3D4', '1299.00'), 'iyzico callback must reject invalid ' . $field . '.');
}
payment_assert(!iyzico_checkout_result_valid($iyzico, 'EP261112A1B2C3D4', '1299.01'), 'iyzico callback must match the exact order total.');

echo "Payment callback contract checks passed.\n";
