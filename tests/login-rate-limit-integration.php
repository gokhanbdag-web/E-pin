<?php
declare(strict_types=1);
if (!extension_loaded('pdo_mysql')) throw new RuntimeException('pdo_mysql extension is required.');
$host=getenv('EPIN_TEST_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('EPIN_TEST_DB_PORT')?:3306);
$user=getenv('EPIN_TEST_DB_USER')?:'root';$pass=getenv('EPIN_TEST_DB_PASS')?:'';
$server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='epin_login_rate_test';
$server->exec("DROP DATABASE IF EXISTS `{$name}`");
$server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
 require_once dirname(__DIR__).'/app/bootstrap.php';
 $GLOBALS['app_config']=['db_host'=>$host,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass,'app_key'=>str_repeat('k',64),'business_mode'=>'single_vendor'];
 $pdo=db();
 for($i=0;$i<8;$i++)login_record_failure($pdo,'admin@example.test','192.0.2.10');
 $stored=$pdo->query('SELECT ip_hash,email_hash FROM login_attempts ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
 if(!$stored||!preg_match('/^[a-f0-9]{64}$/',$stored['ip_hash'])||!preg_match('/^[a-f0-9]{64}$/',$stored['email_hash']))throw new RuntimeException('Login attempt records must store HMAC hashes rather than raw IP addresses or email addresses.');
 if(!login_is_rate_limited($pdo,'admin@example.test','192.0.2.10'))throw new RuntimeException('Eight failures for the same IP/email pair must trigger the temporary limit.');
 if(login_is_rate_limited($pdo,'admin@example.test','192.0.2.11'))throw new RuntimeException('A different IP must not inherit another IP/email pair limit.');
 for($i=0;$i<22;$i++)login_record_failure($pdo,'other'.$i.'@example.test','192.0.2.10');
 if(!login_is_rate_limited($pdo,'new@example.test','192.0.2.10'))throw new RuntimeException('Thirty failures from one IP must trigger the IP-wide temporary limit.');
 login_clear_failures($pdo,'admin@example.test','192.0.2.10');
 if(!login_is_rate_limited($pdo,'new@example.test','192.0.2.10'))throw new RuntimeException('Clearing a successful account/IP pair must not erase the IP-wide abuse counter.');
 echo "Login rate-limit integration tests passed.\n";
} finally {
 $server->exec("DROP DATABASE IF EXISTS `{$name}`");
}
