<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$index=file_get_contents($root.'/index.php');
$login=file_get_contents($root.'/app/views/auth/login.php');
$forgot=file_get_contents($root.'/app/views/auth/forgot-password.php');
$reset=file_get_contents($root.'/app/views/auth/reset-password.php');
$migration=file_get_contents($root.'/database/migrations/011_password_resets.sql');
foreach(["if(\$path==='forgot-password'&&\$_SERVER['REQUEST_METHOD']==='POST')","if(\$path==='reset-password'&&\$_SERVER['REQUEST_METHOD']==='POST')","random_bytes(32)","hash('sha256',\$token)","expires_at>NOW()","used_at IS NULL","password_hash(\$new,PASSWORD_DEFAULT)","UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL","DATE_ADD(NOW(),INTERVAL 1 HOUR)","mail(\$account['email']","(\$emailHash OR ip_hash=?)"] as $needle){
 if(!str_contains($index,$needle))throw new RuntimeException('Customer password recovery security contract missing: '.$needle);
}
if(!str_contains($index,"flash('success',\$generic)"))throw new RuntimeException('Password recovery must not disclose whether an email address exists.');
if(!str_contains($index,"if(!mail(\$account['email']"))throw new RuntimeException('Failed password recovery email must invalidate its token.');
if(str_contains($index,"SELECT token FROM password_resets"))throw new RuntimeException('Password reset tokens must never be stored in plaintext.');
if(!str_contains($login,"url('forgot-password')")||!str_contains($forgot,"name=\"_token\"")||!str_contains($reset,"name=\"_token\""))throw new RuntimeException('Customer password recovery forms or login link are missing CSRF protection.');
if(!str_contains($reset,'minlength="10"')||!str_contains($index,"strlen(\$new)<10"))throw new RuntimeException('Password reset must enforce the minimum password length server-side.');
foreach(['token_hash CHAR(64)','UNIQUE KEY uq_password_reset_token','expires_at DATETIME','used_at DATETIME','FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE'] as $needle){
 if(!str_contains($migration,$needle))throw new RuntimeException('Password reset migration is missing: '.$needle);
}
echo "Customer password recovery contract checks passed.\n";
