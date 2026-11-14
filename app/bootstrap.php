<?php
declare(strict_types=1);
define('APP_ROOT',dirname(__DIR__));
define('EPIN_INTERNAL_MIGRATOR', true);
$GLOBALS['app_config']=is_file(APP_ROOT.'/config.php')?(require APP_ROOT.'/config.php'):[];
if(session_status()!==PHP_SESSION_ACTIVE){$secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');session_set_cookie_params(['httponly'=>true,'samesite'=>$secure?'None':'Lax','secure'=>$secure]);session_start();}
function config(string $key,mixed $default=null):mixed{return $GLOBALS['app_config'][$key]??$default;}
function expire_stale_orders(PDO $pdo):void{
 if(!$pdo->inTransaction())throw new RuntimeException('Sipariş zaman aşımı temizliği açık bir veritabanı işlemi içinde yürütülmelidir.');
 $pdo->exec("UPDATE orders o SET o.status='expired' WHERE o.payment_status<>'paid' AND o.status IN ('pending','payment_failed') AND (o.created_at<DATE_SUB(NOW(),INTERVAL 30 MINUTE) OR EXISTS(SELECT 1 FROM inventory_codes i WHERE i.reserved_order_id=o.id AND i.status='reserved' AND i.reserved_until<NOW()))");
 $pdo->exec("UPDATE coupons c JOIN (SELECT coupon_code,COUNT(*) AS expired_count FROM orders WHERE status='expired' AND payment_status<>'paid' AND coupon_code IS NOT NULL AND coupon_usage_released=0 GROUP BY coupon_code) x ON x.coupon_code=c.code SET c.usage_count=GREATEST(c.usage_count-x.expired_count,0)");
 $pdo->exec("UPDATE orders SET coupon_usage_released=1 WHERE status='expired' AND payment_status<>'paid' AND coupon_usage_released=0");
 $pdo->exec("UPDATE inventory_codes i JOIN orders o ON o.id=i.reserved_order_id SET i.status='available',i.reserved_order_id=NULL,i.reserved_until=NULL WHERE i.status='reserved' AND o.status='expired' AND o.payment_status<>'paid'");
 $pdo->exec("UPDATE payment_transactions pt JOIN orders o ON o.id=pt.order_id SET pt.status='expired' WHERE o.status='expired' AND o.payment_status<>'paid' AND pt.status='pending'");
}
function db():PDO{
 static $pdo=null;static $ready=false;
 if($pdo instanceof PDO && $ready)return $pdo;
 if(!config('db_name'))throw new RuntimeException('Veritabanı yapılandırılmamış.');
 if(!($pdo instanceof PDO))$pdo=new PDO('mysql:host='.config('db_host').';dbname='.config('db_name').';charset=utf8mb4',(string)config('db_user'),(string)config('db_pass'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 if(!$ready){
  require_once APP_ROOT.'/migrator.php';
  app_migrate($pdo);
  $pdo->beginTransaction();
  try{
   expire_stale_orders($pdo);
   $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  $ready=true;
 }
 return $pdo;
}
function coupon_quote(PDO $pdo,string $code,float $subtotal,bool $lock=false):array{
 $code=strtoupper(trim($code));if($code===''||strlen($code)>64)throw new RuntimeException('Kupon kodu geçersiz.');
 $sql="SELECT * FROM coupons WHERE code=? AND status='active'".($lock?' FOR UPDATE':'');$q=$pdo->prepare($sql);$q->execute([$code]);$c=$q->fetch();
 if(!$c)throw new RuntimeException('Kupon bulunamadı veya kullanıma kapalı.');
 $now=time();if(!empty($c['starts_at'])&&strtotime($c['starts_at'])>$now)throw new RuntimeException('Bu kupon henüz kullanıma açılmadı.');
 if(!empty($c['expires_at'])&&strtotime($c['expires_at'])<$now)throw new RuntimeException('Kuponun kullanım süresi dolmuş.');
 if((int)$c['usage_limit']>0&&(int)$c['usage_count']>=(int)$c['usage_limit'])throw new RuntimeException('Kupon kullanım limiti dolmuş.');
 if($subtotal<(float)$c['min_order'])throw new RuntimeException('Bu kupon için sepet tutarı en az '.number_format((float)$c['min_order'],2,',','.').' ₺ olmalı.');
 if($c['discount_type']==='percent'&&(float)$c['discount_value']>100)throw new RuntimeException('Kupon indirim oranı geçersiz.');
 $discount=$c['discount_type']==='fixed'?(float)$c['discount_value']:$subtotal*(float)$c['discount_value']/100;
 if($c['max_discount']!==null&&(float)$c['max_discount']>0)$discount=min($discount,(float)$c['max_discount']);
 $discount=round(min($subtotal,max(0,$discount)),2);if($discount<=0)throw new RuntimeException('Kupon indirimi hesaplanamadı.');
 return ['coupon'=>$c,'discount'=>$discount,'total'=>round(max(0,$subtotal-$discount),2)];
}
function cart_item_parts(string|int $key):array{$raw=(string)$key;if(preg_match('/^(\\d+)(?::(\\d+))?$/',$raw,$m))return [(int)$m[1],isset($m[2])?(int)$m[2]:0];return [0,0];}
function app_base_path_from_paths(string $appRoot,string $documentRoot,string $scriptName):string{
 $normalize=static function(string $path):string{$path=str_replace('\\\\','/',$path);$path=preg_replace('~/+~','/',$path)??$path;return rtrim($path,'/');};
 $app=$normalize(realpath($appRoot)?:$appRoot);$doc=$normalize(realpath($documentRoot)?:$documentRoot);
 if($app!==''&&$doc!==''&&($app===$doc||str_starts_with($app,$doc.'/')))return $app===$doc?'':'/'.trim(substr($app,strlen($doc)),'/');
 $script=parse_url($scriptName,PHP_URL_PATH)?:$scriptName;$script=str_replace('\\\\','/',$script);
 if(preg_match('~^(.*?)/index\\.php(?:/.*)?$~i',$script,$match))$dir=$match[1];else $dir=dirname($script);
 $dir=rtrim(str_replace('\\\\','/',$dir),'/.');
 return $dir===''||$dir==='.'?'':($dir[0]==='/'?$dir:'/'.$dir);
}
function app_base_path():string{return app_base_path_from_paths(APP_ROOT,(string)($_SERVER['DOCUMENT_ROOT']??''),(string)($_SERVER['SCRIPT_NAME']??'/'));}
function app_route_path_from_uri(string $requestUri,string $basePath):string{
 $path=parse_url($requestUri,PHP_URL_PATH)?:'/';$path=trim($path,'/');$base=trim(str_replace('\\\\','/',$basePath),'/');
 if($base!==''&&($path===$base||str_starts_with($path,$base.'/')))$path=trim(substr($path,strlen($base)),'/');
 return $path;
}
function base_url():string{return app_base_path();}
function url(string $path=''):string{return base_url().'/'.ltrim($path,'/');}
function absolute_url(string $path=''):string{
 $q=db()->prepare("SELECT setting_value FROM settings WHERE setting_key='site_url' LIMIT 1");$q->execute();$base=rtrim(trim((string)$q->fetchColumn()),'/');$parts=parse_url($base);
 if(!$parts||!isset($parts['scheme'],$parts['host'])||!in_array(strtolower($parts['scheme']),['https','http'],true)||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||filter_var($base,FILTER_VALIDATE_URL)===false)throw new RuntimeException('Ödeme bağlantıları için Mağaza ayarlarında geçerli Site adresini tanımlayın.');
 $host=(string)$parts['host'];$validHost=preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i',$host)||filter_var($host,FILTER_VALIDATE_IP)!==false;
 if(!$validHost)throw new RuntimeException('Site adresindeki alan adı geçersiz.');
 foreach(explode('/',(string)($parts['path']??'')) as $segment)if($segment==='..')throw new RuntimeException('Site adresinde geçersiz alt dizin yolu var.');
 $route=ltrim($path,'/');if(str_contains($route,"\r")||str_contains($route,"\n")||preg_match('~(^|/)\.\.(/|$)~',$route))throw new RuntimeException('Geçersiz callback yolu.');
 return $base.'/'.$route;
}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function redirect(string $to):never{header('Location: '.$to);exit;}
function csrf_token():string{if(empty($_SESSION['_token']))$_SESSION['_token']=bin2hex(random_bytes(32));return $_SESSION['_token'];}
function csrf_check():void{if(!hash_equals(csrf_token(),(string)($_POST['_token']??''))){http_response_code(419);exit('Güvenlik doğrulaması başarısız.');}}
function flash(string $key,?string $message=null):?string{if($message!==null){$_SESSION['_flash'][$key]=$message;return null;}$v=$_SESSION['_flash'][$key]??null;unset($_SESSION['_flash'][$key]);return $v;}
function require_login(?string $role=null):void{$u=$_SESSION['user']??null;if(!$u)redirect(url('login'));if($role!==null&&($u['role']??'')!==$role){http_response_code(403);exit('Bu alana erişim yetkiniz yok.');}}
function render(string $view,array $data=[]):void{if(!isset($data['settings'])){$data['settings']=[];if(is_file(APP_ROOT.'/config.php'))foreach(db()->query('SELECT setting_key,setting_value FROM settings')->fetchAll() as $r)$data['settings'][$r['setting_key']]=$r['setting_value'];}if(!isset($data['categories'])&&is_file(APP_ROOT.'/config.php'))$data['categories']=db()->query("SELECT id,name,slug FROM categories WHERE status='active' ORDER BY sort_order,name")->fetchAll();if(!isset($data['menus'])&&is_file(APP_ROOT.'/config.php'))$data['menus']=db()->query("SELECT title,url,menu_type FROM menus WHERE status='active' AND location='header' AND menu_type NOT IN ('cart','account') AND url NOT IN ('cart','account','login') ORDER BY sort_order,id")->fetchAll();if(!isset($data['actionMenus'])&&is_file(APP_ROOT.'/config.php'))$data['actionMenus']=db()->query("SELECT title,menu_type FROM menus WHERE status='active' AND location='header' AND menu_type IN ('cart','account') ORDER BY FIELD(menu_type,'account','cart'),sort_order,id")->fetchAll();if(!isset($data['footerMenus'])&&is_file(APP_ROOT.'/config.php'))$data['footerMenus']=db()->query("SELECT title,url FROM menus WHERE status='active' AND location='footer' ORDER BY sort_order,id")->fetchAll();extract($data,EXTR_SKIP);$layout=str_starts_with($view,'admin/')?'layout-admin.php':'layout.php';require APP_ROOT.'/app/views/'.$layout;}

function seller_record_paid_order(int $orderId):void{
 if(config('business_mode')!=='multi_vendor')return;
 $pdo=db();$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
 try{
  $state=$pdo->prepare('SELECT status,payment_status FROM orders WHERE id=? FOR UPDATE');$state->execute([$orderId]);$order=$state->fetch();
  if(!$order||$order['payment_status']!=='paid')throw new RuntimeException('Ödenmemiş sipariş için satıcı muhasebesi oluşturulamaz.');
  if(in_array($order['status'],['cancelled','expired','refunded'],true))throw new RuntimeException('İptal veya iade edilmiş sipariş için satıcı kazancı oluşturulamaz.');
  $creditStatus=$order['status']==='delivered'?'available':'pending';
  $q=$pdo->prepare("SELECT seller_id,quantity,unit_price,seller_commission_rate,discount_amount FROM order_items WHERE order_id=? AND seller_id IS NOT NULL ORDER BY seller_id FOR UPDATE");$q->execute([$orderId]);$groups=[];
  foreach($q->fetchAll() as $it){$sid=(int)$it['seller_id'];$gross=max(0,(float)$it['unit_price']*(int)$it['quantity']-(float)($it['discount_amount']??0));$commission=$gross*max(0,min(100,(float)$it['seller_commission_rate']))/100;if(!isset($groups[$sid]))$groups[$sid]=['net'=>0.0,'commission'=>0.0];$groups[$sid]['net']+=$gross-$commission;$groups[$sid]['commission']+=$commission;}
  $credit=$pdo->prepare("INSERT IGNORE INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,?,'sale_credit',?,?)");
  $fee=$pdo->prepare("INSERT IGNORE INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,?,'platform_commission',?,'settled')");
  foreach($groups as $sid=>$v){$credit->execute([(int)$sid,$orderId,round($v['net'],2),$creditStatus]);$fee->execute([(int)$sid,$orderId,-round($v['commission'],2)]);if($creditStatus==='available')$pdo->prepare("UPDATE seller_ledger SET status='available' WHERE order_id=? AND seller_id=? AND entry_type='sale_credit' AND status='pending'")->execute([$orderId,(int)$sid]);}
  if($ownsTransaction)$pdo->commit();
 }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function seller_reverse_refunded_order(int $orderId):void{
 if(config('business_mode')!=='multi_vendor')return;
 $pdo=db();
 if(!$pdo->inTransaction())throw new RuntimeException('Satıcı iade muhasebesi sipariş iade işlemiyle aynı veritabanı işleminde yürütülmelidir.');
 $lock=$pdo->prepare("SELECT id FROM seller_profiles WHERE id IN (SELECT DISTINCT seller_id FROM seller_ledger WHERE order_id=? AND entry_type IN ('sale_credit','platform_commission')) ORDER BY id FOR UPDATE");
 $lock->execute([$orderId]);
 $rows=$pdo->prepare("SELECT id,seller_id,entry_type,amount,status FROM seller_ledger WHERE order_id=? AND entry_type IN ('sale_credit','platform_commission') ORDER BY seller_id,id FOR UPDATE");
 $rows->execute([$orderId]);
 $debit=$pdo->prepare("INSERT IGNORE INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,?,'refund_debit',?,'available')");
 $commissionReversal=$pdo->prepare("INSERT IGNORE INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,?,'commission_reversal',?,'settled')");
 foreach($rows->fetchAll() as $entry){
  $sellerId=(int)$entry['seller_id'];$amount=(float)$entry['amount'];
  if($entry['entry_type']==='sale_credit'){
   if($entry['status']==='pending')$pdo->prepare("UPDATE seller_ledger SET status='reversed' WHERE id=? AND status='pending'")->execute([(int)$entry['id']]);
   elseif($entry['status']==='available'&&$amount>0)$debit->execute([$sellerId,$orderId,-round($amount,2)]);
  }elseif($entry['entry_type']==='platform_commission'&&$amount<0){
   $commissionReversal->execute([$sellerId,$orderId,round(abs($amount),2)]);
  }
 }
}

function seller_request_withdrawal(int $sellerId,float $amount,string $note=''):int{
 if($sellerId<1)throw new InvalidArgumentException('Geçersiz satıcı hesabı.');
 if(!is_finite($amount))throw new RuntimeException('Geçerli tutar girin.');
 $amount=round($amount,2);
 if($amount<=0)throw new RuntimeException('Geçerli tutar girin.');
 $pdo=db();$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
 try{
  $lock=$pdo->prepare('SELECT id FROM seller_profiles WHERE id=? FOR UPDATE');$lock->execute([$sellerId]);
  if(!$lock->fetchColumn())throw new RuntimeException('Satıcı hesabı bulunamadı.');
  $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM seller_ledger WHERE seller_id=? AND status='available'");$q->execute([$sellerId]);$balance=(float)$q->fetchColumn();
  $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM seller_withdrawals WHERE seller_id=? AND status IN ('pending','approved')");$q->execute([$sellerId]);$reserved=(float)$q->fetchColumn();
  if($amount>max(0,$balance-$reserved))throw new RuntimeException('Kullanılabilir bakiyeniz yetersiz.');
  $insert=$pdo->prepare('INSERT INTO seller_withdrawals(seller_id,amount,note) VALUES(?,?,?)');$insert->execute([$sellerId,number_format($amount,2,'.',''),substr(trim($note),0,500)]);
  $id=(int)$pdo->lastInsertId();
  $event=$pdo->prepare("INSERT INTO seller_withdrawal_events(withdrawal_id,admin_user_id,from_status,to_status,note) VALUES(?,NULL,'created','pending',?)");
  $event->execute([$id,substr(trim($note),0,500)?:null]);
  if($ownsTransaction)$pdo->commit();return $id;
 }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function seller_review_withdrawal(int $withdrawalId,string $decision,int $adminUserId,string $note=''):void{
 if($withdrawalId<1||$adminUserId<1||!in_array($decision,['approved','rejected','paid'],true))throw new RuntimeException('Geçersiz ödeme kararı.');
 $pdo=db();$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
 try{
  $q=$pdo->prepare('SELECT seller_id FROM seller_withdrawals WHERE id=?');$q->execute([$withdrawalId]);$sellerId=(int)$q->fetchColumn();
  if(!$sellerId)throw new RuntimeException('Ödeme talebi bulunamadı.');
  $sellerLock=$pdo->prepare('SELECT id FROM seller_profiles WHERE id=? FOR UPDATE');$sellerLock->execute([$sellerId]);
  if(!$sellerLock->fetchColumn())throw new RuntimeException('Satıcı hesabı bulunamadı.');
  $q=$pdo->prepare('SELECT * FROM seller_withdrawals WHERE id=? AND seller_id=? FOR UPDATE');$q->execute([$withdrawalId,$sellerId]);$withdrawal=$q->fetch();
  if(!$withdrawal)throw new RuntimeException('Ödeme talebi bulunamadı.');
  $from=(string)$withdrawal['status'];$allowed=['approved'=>['pending'],'rejected'=>['pending','approved'],'paid'=>['approved']];
  if(!in_array($from,$allowed[$decision],true))throw new RuntimeException($decision==='paid'?'Ödeme yapılmadan önce talep onaylanmalıdır.':'Bu talep bu aşamadan ilerletilemez.');
  $note=substr(trim($note),0,500);
  $update=$pdo->prepare('UPDATE seller_withdrawals SET status=?,admin_note=? WHERE id=? AND status=?');
  $update->execute([$decision,$note,$withdrawalId,$from]);
  if($update->rowCount()!==1)throw new RuntimeException('Ödeme talebi eşzamanlı olarak değiştirildi. Sayfayı yenileyip tekrar deneyin.');
  if($decision==='paid'){
   $debit=$pdo->prepare("INSERT INTO seller_ledger(seller_id,order_id,withdrawal_id,entry_type,amount,status) VALUES(?,NULL,?,'withdrawal',?,'available')");
   $debit->execute([$sellerId,$withdrawalId,-(float)$withdrawal['amount']]);
  }
  $event=$pdo->prepare('INSERT INTO seller_withdrawal_events(withdrawal_id,admin_user_id,from_status,to_status,note) VALUES(?,?,?,?,?)');
  $event->execute([$withdrawalId,$adminUserId,$from,$decision,$note!==''?$note:null]);
  if($ownsTransaction)$pdo->commit();
 }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function seller_financial_summary(int $sellerId):array{
 if($sellerId<1)throw new InvalidArgumentException('Geçersiz satıcı hesabı.');
 $pdo=db();
 $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type='sale_credit' AND status='pending' THEN amount ELSE 0 END),0) AS pending_earnings, COALESCE(SUM(CASE WHEN status='available' THEN amount ELSE 0 END),0) AS ledger_balance, COALESCE(SUM(CASE WHEN entry_type='refund_debit' THEN -amount ELSE 0 END),0) AS refunded_total, COALESCE(SUM(CASE WHEN entry_type IN ('platform_commission','commission_reversal') THEN amount ELSE 0 END),0) AS commission_net FROM seller_ledger WHERE seller_id=?");
 $q->execute([$sellerId]);$ledger=$q->fetch(PDO::FETCH_ASSOC)?:[];
 $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status IN ('pending','approved') THEN amount ELSE 0 END),0) AS reserved_balance, COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) AS withdrawn_total FROM seller_withdrawals WHERE seller_id=?");
 $q->execute([$sellerId]);$withdrawals=$q->fetch(PDO::FETCH_ASSOC)?:[];
 $balance=(float)($ledger['ledger_balance']??0);$reserved=(float)($withdrawals['reserved_balance']??0);$net=$balance-$reserved;
 return ['pendingEarnings'=>(float)($ledger['pending_earnings']??0),'ledgerBalance'=>$balance,'reservedBalance'=>$reserved,'availableBalance'=>max(0,$net),'negativeBalance'=>max(0,-$net),'withdrawnTotal'=>(float)($withdrawals['withdrawn_total']??0),'refundedTotal'=>(float)($ledger['refunded_total']??0),'commissionNet'=>(float)($ledger['commission_net']??0)];
}
function inventory_encrypt(string $plain):array{$key=hash('sha256',(string)config('app_key',config('db_pass')),true);$nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag);if($cipher===false)throw new RuntimeException('Kod şifrelenemedi.');return [base64_encode($cipher),bin2hex($nonce),bin2hex($tag),hash('sha256',$plain)];}
function inventory_decrypt(array $row):string{$key=hash('sha256',(string)config('app_key',config('db_pass')),true);$plain=openssl_decrypt(base64_decode((string)$row['code_ciphertext'],true),'aes-256-gcm',$key,OPENSSL_RAW_DATA,hex2bin((string)$row['code_nonce']),hex2bin((string)$row['code_tag']));if($plain===false)throw new RuntimeException('Dijital kod çözülemedi.');return $plain;}
function fulfill_paid_order(int $orderId):void{$pdo=db();$pdo->beginTransaction();try{$st=$pdo->prepare("SELECT id,payment_status,status FROM orders WHERE id=? FOR UPDATE");$st->execute([$orderId]);$order=$st->fetch();if(!$order||$order['payment_status']!=='paid')throw new RuntimeException('Ödeme onayı bulunmayan sipariş teslim edilemez.');if($order['status']==='delivered'){$pdo->commit();return;}if(!in_array($order['status'],['processing','partially_delivered'],true))throw new RuntimeException('Bu sipariş durumunda otomatik teslimat yapılamaz.');$st=$pdo->prepare("SELECT oi.product_id,oi.variant_id,oi.quantity,p.delivery_type FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? ORDER BY oi.id");$st->execute([$orderId]);$hasManual=false;$hasAutomatic=false;foreach($st->fetchAll() as $item){if(($item['delivery_type']??'')!=='automatic'){$hasManual=true;continue;}$hasAutomatic=true;$q=(int)$item['quantity'];$vid=$item['variant_id']!==null?(int)$item['variant_id']:null;$sold=$pdo->prepare("SELECT COUNT(*) FROM inventory_codes WHERE product_id=? AND ((variant_id IS NULL AND ? IS NULL) OR variant_id=?) AND sold_order_id=? AND status='sold'");$sold->execute([(int)$item['product_id'],$vid,$vid,$orderId]);$soldCount=(int)$sold->fetchColumn();$needed=max(0,$q-$soldCount);if($needed===0)continue;$codes=$pdo->prepare("SELECT id FROM inventory_codes WHERE product_id=? AND ((variant_id IS NULL AND ? IS NULL) OR variant_id=?) AND reserved_order_id=? AND status='reserved' ORDER BY id LIMIT ".$needed." FOR UPDATE");$codes->execute([(int)$item['product_id'],$vid,$vid,$orderId]);$ids=$codes->fetchAll(PDO::FETCH_COLUMN);if(count($ids)<$needed)throw new RuntimeException('Ödeme alınmış sipariş için ayrılmış kod stoğu yetersiz.');$upd=$pdo->prepare("UPDATE inventory_codes SET status='sold',sold_order_id=?,reserved_order_id=NULL,reserved_until=NULL WHERE id=? AND status='reserved'");foreach($ids as $id)$upd->execute([$orderId,(int)$id]);}$newStatus=$hasManual?'partially_delivered':($hasAutomatic?'delivered':'processing');$pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$newStatus,$orderId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}}
function restore_paid_order_reservations(int $orderId):void{$pdo=db();$pdo->beginTransaction();try{$q=$pdo->prepare("SELECT id,payment_status,status FROM orders WHERE id=? FOR UPDATE");$q->execute([$orderId]);$order=$q->fetch();if(!$order||$order['payment_status']!=='paid')throw new RuntimeException('Yalnızca ödemesi doğrulanmış siparişler yeniden işlenebilir.');if(in_array($order['status'],['cancelled','expired','refunded'],true))throw new RuntimeException('İptal edilmiş veya iade edilmiş sipariş yeniden teslim edilemez.');$q=$pdo->prepare("SELECT oi.product_id,oi.variant_id,oi.quantity FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? AND p.delivery_type='automatic' ORDER BY oi.id");$q->execute([$orderId]);foreach($q->fetchAll() as $item){$productId=(int)$item['product_id'];$variantId=$item['variant_id']!==null?(int)$item['variant_id']:null;$quantity=(int)$item['quantity'];$count=$pdo->prepare("SELECT SUM(status='sold' AND sold_order_id=?),SUM(status='reserved' AND reserved_order_id=?) FROM inventory_codes WHERE product_id=? AND ((variant_id IS NULL AND ? IS NULL) OR variant_id=?)");$count->execute([$orderId,$orderId,$productId,$variantId,$variantId]);$counts=$count->fetch(PDO::FETCH_NUM)?:[0,0];$sold=(int)($counts[0]??0);$reserved=(int)($counts[1]??0);$missing=max(0,$quantity-$sold-$reserved);if($missing===0)continue;$available=$pdo->prepare("SELECT id FROM inventory_codes WHERE product_id=? AND ((variant_id IS NULL AND ? IS NULL) OR variant_id=?) AND status='available' ORDER BY id LIMIT ".$missing." FOR UPDATE");$available->execute([$productId,$variantId,$variantId]);$ids=$available->fetchAll(PDO::FETCH_COLUMN);if(count($ids)<$missing)throw new RuntimeException('Yeterli boş stok yok. Gerekli: '.$missing.', bulunan: '.count($ids).'. Hiçbir stok değiştirilmedi.');$reserve=$pdo->prepare("UPDATE inventory_codes SET status='reserved',reserved_order_id=?,reserved_until=DATE_ADD(NOW(),INTERVAL 30 MINUTE) WHERE id=? AND status='available'");foreach($ids as $id)$reserve->execute([$orderId,(int)$id]);}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}}
