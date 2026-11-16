<?php
declare(strict_types=1);

/*
 * One-time administrator account recovery for shared hosting.
 * Requires the operator to re-enter the database credentials from the hosting
 * control panel. It only changes an already-existing active admin account.
 * On success this file removes itself from the deployed application.
 */
$root = __DIR__;
$configFile = $root . '/config.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
header('X-Robots-Tag: noindex, nofollow, noarchive');

if (is_file($root . '/storage/admin-recovery.disabled')) {
    http_response_code(410);
    exit('Tek kullanımlık yönetici kurtarma aracı daha önce kullanılmış ve devre dışı bırakılmış.');
}

if (!is_file($configFile)) {
    http_response_code(404);
    exit('Uygulama henüz kurulmamış.');
}
$config = require $configFile;
if (!is_array($config) || !isset($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass'])) {
    http_response_code(503);
    exit('Veritabanı yapılandırması okunamadı.');
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => $https]);
    session_start();
}
if (empty($_SESSION['epin_recovery_csrf'])) {
    $_SESSION['epin_recovery_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)$_SESSION['epin_recovery_csrf'], (string)($_POST['_token'] ?? ''))) {
        http_response_code(419);
        exit('Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
    }

    $dbHost = trim((string)($_POST['db_host'] ?? ''));
    $dbName = trim((string)($_POST['db_name'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $email = strtolower(trim((string)($_POST['admin_email'] ?? '')));
    $adminName = trim((string)($_POST['admin_name'] ?? ''));
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    try {
        if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
            throw new RuntimeException('Güvenlik için bu işlem HTTPS bağlantısı üzerinden yapılmalıdır.');
        }
        foreach (['db_host' => $dbHost, 'db_name' => $dbName, 'db_user' => $dbUser, 'db_pass' => $dbPass] as $key => $value) {
            if (!hash_equals((string)$config[$key], $value)) {
                throw new RuntimeException('Veritabanı erişim bilgileri doğrulanamadı.');
            }
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($adminName) < 2 || strlen($adminName) > 100) {
            throw new RuntimeException('Yönetici e-postası veya görünen adı geçersiz.');
        }
        if (strlen($newPassword) < 12 || strlen($newPassword) > 200 || $newPassword !== $confirmPassword) {
            throw new RuntimeException('Yeni şifre en az 12 karakter olmalı ve iki alanda aynı girilmelidir.');
        }

        $pdo = new PDO(
            'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4',
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdo->beginTransaction();
        $find = $pdo->prepare("SELECT id, role, status FROM users WHERE email = ? LIMIT 1 FOR UPDATE");
        $find->execute([$email]);
        $account = $find->fetch();
        if (!$account || $account['role'] !== 'admin' || $account['status'] !== 'active') {
            throw new RuntimeException('Bu e-posta ile etkin bir yönetici hesabı bulunamadı. Yeni hesap veya rol yükseltme yapılmadı.');
        }
        $update = $pdo->prepare("UPDATE users SET name = ?, password_hash = ? WHERE id = ? AND role = 'admin' AND status = 'active'");
        $update->execute([$adminName, password_hash($newPassword, PASSWORD_DEFAULT), (int)$account['id']]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Hesap güncellenemedi. Herhangi bir değişiklik uygulanmadı.');
        }
        $pdo->commit();

        // Remove the recovery entry point after a successful one-time use.
        if (!@unlink(__FILE__)) {
            // If the host disallows self-deletion, leave a deny marker and fail closed.
            $marker = $root . '/storage/admin-recovery.disabled';
            $storage = $root . '/storage';
            if (!is_dir($storage)) @mkdir($storage, 0700, true);
            @file_put_contents($marker, date(DATE_ATOM), LOCK_EX);
        }
        $success = true;
        $_SESSION = [];
        session_destroy();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'İşlem tamamlanamadı. Bilgileri ve sunucu günlüklerini kontrol edin.';
    }
}

if (!$success && is_file($root . '/storage/admin-recovery.disabled')) {
    http_response_code(410);
    exit('Tek kullanımlık yönetici kurtarma aracı daha önce kullanılmış ve devre dışı bırakılmış.');
}
function recovery_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?><!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>E-pin yönetici hesabı kurtarma</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:24px;background:#f3f5f9;color:#172033;font:16px system-ui,-apple-system,Segoe UI,sans-serif}.panel{max-width:560px;margin:5vh auto;background:#fff;padding:28px;border:1px solid #e3e7ef;border-radius:16px;box-shadow:0 12px 36px #15213a12}h1{font-size:25px;margin:0 0 8px}p{line-height:1.55;color:#586174}.warning{padding:12px;background:#fff7e6;border-radius:8px;color:#784d00}.error{padding:12px;background:#fff0f0;color:#9b1c1c;border-radius:8px}label{display:block;font-weight:600;margin:14px 0 6px}input{display:block;width:100%;padding:12px;border:1px solid #cbd2df;border-radius:8px;font:inherit}button{margin-top:20px;width:100%;padding:13px;border:0;border-radius:8px;background:#182b53;color:#fff;font-weight:700;font-size:16px}
</style></head><body><main class="panel">
<?php if ($success): ?>
<h1>Yönetici şifresi güncellendi</h1><p>Mevcut yönetici hesabının adı ve şifresi güncellendi. Kurtarma aracı devre dışı bırakıldı.</p><p><a href="./login">Giriş sayfasına git</a></p>
<?php else: ?>
<h1>Yönetici hesabını kurtar</h1>
<p class="warning">Bu araç yalnızca mevcut ve etkin yönetici hesabını günceller. Yeni yönetici oluşturmaz ve kullanıcı rolünü yükseltmez. İşlem başarılı olunca bu dosya kendini siler.</p>
<?php if ($error !== ''): ?><p class="error"><?= recovery_h($error) ?></p><?php endif; ?>
<form method="post" autocomplete="off">
<input type="hidden" name="_token" value="<?= recovery_h((string)$_SESSION['epin_recovery_csrf']) ?>">
<label>Veritabanı sunucusu</label><input name="db_host" required autocomplete="off">
<label>Veritabanı adı</label><input name="db_name" required autocomplete="off">
<label>Veritabanı kullanıcı adı</label><input name="db_user" required autocomplete="off">
<label>Veritabanı şifresi</label><input type="password" name="db_pass" required autocomplete="new-password">
<label>Mevcut yönetici e-postası</label><input type="email" name="admin_email" required autocomplete="username">
<label>Yeni görünen ad</label><input name="admin_name" value="Gokhanadmin" minlength="2" maxlength="100" required>
<label>Yeni şifre (en az 12 karakter)</label><input type="password" name="new_password" minlength="12" maxlength="200" required autocomplete="new-password">
<label>Yeni şifreyi tekrar girin</label><input type="password" name="confirm_password" minlength="12" maxlength="200" required autocomplete="new-password">
<button type="submit">Yönetici şifresini güvenle yenile</button>
</form>
<?php endif; ?>
</main></body></html>
