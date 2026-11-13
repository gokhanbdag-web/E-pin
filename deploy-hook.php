<?php
declare(strict_types=1);

/**
 * E-pin GitHub deployment webhook.
 * Install this file at the application document root. The deployment script
 * and webhook secret stay outside the public document root.
 */
const EPIN_DEPLOY_BASE = '/opt/epin-deploy';
const EPIN_DEPLOY_SCRIPT = EPIN_DEPLOY_BASE . '/deploy.sh';
const EPIN_WEBHOOK_SECRET = EPIN_DEPLOY_BASE . '/webhook.secret';
const EPIN_MAX_PAYLOAD = 1048576;

function epin_webhook_reply(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message . "\n";
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    epin_webhook_reply(405, 'Method Not Allowed');
}

$event = (string)($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
if (!in_array($event, ['ping', 'push'], true)) {
    epin_webhook_reply(400, 'Unsupported GitHub event');
}

$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length < 0 || $length > EPIN_MAX_PAYLOAD) {
    epin_webhook_reply(413, 'Payload Too Large');
}

$body = file_get_contents('php://input', false, null, 0, EPIN_MAX_PAYLOAD + 1);
if ($body === false || strlen($body) > EPIN_MAX_PAYLOAD) {
    epin_webhook_reply(413, 'Payload Too Large');
}

if (!is_file(EPIN_WEBHOOK_SECRET) || !is_readable(EPIN_WEBHOOK_SECRET)) {
    error_log('E-pin webhook: secret file is missing or unreadable.');
    epin_webhook_reply(500, 'Webhook is not configured');
}
$secret = trim((string)file_get_contents(EPIN_WEBHOOK_SECRET));
$signature = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
$expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
if ($secret === '' || $signature === '' || !hash_equals($expected, $signature)) {
    epin_webhook_reply(403, 'Invalid signature');
}

if ($event === 'ping') {
    epin_webhook_reply(200, 'E-pin webhook is ready');
}

$payload = json_decode($body, true);
if (!is_array($payload)) {
    epin_webhook_reply(400, 'Invalid JSON payload');
}
if (($payload['ref'] ?? '') !== 'refs/heads/main') {
    epin_webhook_reply(202, 'Ignored: only main branch is deployed');
}

if (!is_file(EPIN_DEPLOY_SCRIPT) || !is_executable(EPIN_DEPLOY_SCRIPT)) {
    error_log('E-pin webhook: deployment script is missing or not executable.');
    epin_webhook_reply(500, 'Deployment script is not ready');
}

// The script is fixed and stored outside the public web root. No request data
// is interpolated into this command.
$command = '/usr/bin/nohup /bin/bash ' . escapeshellarg(EPIN_DEPLOY_SCRIPT)
    . ' >> ' . escapeshellarg(EPIN_DEPLOY_BASE . '/deploy.log')
    . ' 2>&1 < /dev/null &';
exec($command, $output, $result);
if ($result !== 0) {
    error_log('E-pin webhook: unable to start deployment process.');
    epin_webhook_reply(500, 'Could not start deployment');
}
epin_webhook_reply(202, 'Deployment accepted');
