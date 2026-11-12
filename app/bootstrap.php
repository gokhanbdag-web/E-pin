<?php
declare(strict_types=1);
define('APP_ROOT',dirname(__DIR__));
if (!defined('EPIN_INTERNAL_MIGRATOR')) define('EPIN_INTERNAL_MIGRATOR', true);
$GLOBALS['app_config']=is_file(APP_ROOT.'/config.php')?(require APP_ROOT.'/config.php'):[];
if(session_status()!==PHP_SESSION_ACTIVE){$secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>$secure]);session_start();}
function config(string $key,mixed $default=null):mixed{return $GLOBALS['app_config'][$key]??$default;}
function db():PDO{
 static $pdo=null;static $ready=false;
 if($pdo instanceof PDO && $ready)return $pdo;
 if(!config('db_name'))throw new RuntimeException('Veritabanı yapılandırılmamış.');
 if(!$pdo instanceof PDO)$pdo=new PDO('mysql:host='.config('db_host').';dbname='.config('db_name').';charset=utf8mb4',(string)config('db_user'),(string)config('db_pass'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 if(!$ready){
  require_once APP_ROOT.'/migrator.php';
  app_migrate($pdo);
  $pdo->beginTransaction();
  try{
   $pdo->exec("UPDATE orders o SET o.status='expired' WHERE o.payment_status<>'paid' AND o.status IN ('pending','payment_failed') AND (o.created_at<DATE_SUB(NOW(),INTERVAL 30 MINUTE) OR EXISTS(SELECT 1 FROM inventory_codes i WHERE i.reserved_order_id=o.id AND i.status='reserved' AND i.reserved_until<NOW()))");
   $pdo->exec("UPDATE coupons c JOIN (SELECT coupon_code,COUNT(*) AS expired_count FROM orders WHERE status='expired' AND payment_status<>'paid' AND coupon_code IS NOT NULL AND coupon_usage_released=0 GROUP BY coupon_code) x ON x.coupon_code=c.code SET c.usage_count=GREATEST(c.usage_count-x.expired_count,0)");
   $pdo->exec("UPDATE orders SET coupon_usage_released=1 WHERE status='expired' AND payment_status<>'paid' AND coupon_usage_released=0");
   $pdo->exec("UPDATE inventory_codes i JOIN orders o ON o.id=i.reserved_order_id SET i.status='available',i.reserved_order_id=NULL,i.reserved_until=NULL WHERE i.status='reserved' AND o.status='expired' AND o.payment_status<>'paid'");
   $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  $ready=true;
 }
 return $pdo;
}
