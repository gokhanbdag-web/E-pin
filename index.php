<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once APP_ROOT.'/app/payments.php';
if(!is_file(APP_ROOT.'/config.php')){require APP_ROOT.'/install/index.php';exit;}
$path=trim(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/','/');
$base=trim(str_replace('\\\\','/',dirname($_SERVER['SCRIPT_NAME']??'/')),'/');
if($base!==''&&str_starts_with($path,$base))$path=trim(substr($path,strlen($base)),'/');
$path=$path?:'home';

if(str_starts_with($path,'payment/start/') && $_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check(); require_login();
    $orderId=(int)substr($path,14);
    $q=db()->prepare('SELECT * FROM orders WHERE id=? AND user_id=?');$q->execute([$orderId,(int)$_SESSION['user']['id']]);$order=$q->fetch();
    if(!$order||$order['payment_status']==='paid'||$order['status']==='expired'){http_response_code(404);exit('Sipariş bulunamadı veya zaten ödendi.');}
    $provider=(string)($_POST['provider']??'');
    $phone=trim((string)($_POST['phone']??''));$address=trim((string)($_POST['address']??''));$city=trim((string)($_POST['city']??''));$zip=trim((string)($_POST['zip']??''));
    $identity=preg_replace('/\D+/','',(string)($_POST['identity']??''));
    if(strlen($phone)<10||strlen($address)<8||$city===''||$zip===''){flash('error','Telefon ve fatura/iletişim adresi bilgilerini tamamlayın.');redirect(url('order/'.$orderId));}
    $user=$_SESSION['user'];$orderNo=(string)$order['order_no'];$amount=(float)$order['total'];$amountText=number_format($amount,2,'.','');
    try{
      if($provider==='paytr'){
        if(payment_secret('paytr_enabled')!=='1')throw new RuntimeException('PayTR şu anda etkin değil.');
        $merchantId=payment_secret('paytr_merchant_id');$merchantKey=payment_secret('paytr_merchant_key');$salt=payment_secret('paytr_merchant_salt');
        if($merchantId===''||$merchantKey===''||$salt==='')throw new RuntimeException('PayTR mağaza bilgileri eksik.');
        $ip=(string)($_SERVER['REMOTE_ADDR']??'127.0.0.1');$basket=[];
        $items=db()->prepare('SELECT product_name,quantity,unit_price FROM order_items WHERE order_id=?');$items->execute([$orderId]);
        foreach($items->fetchAll() as $it)$basket[]=[$it['product_name'],number_format((float)$it['unit_price'],2,'.',''),(int)$it['quantity']];
        $basketEncoded=base64_encode(json_encode($basket,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $test=payment_secret('paytr_sandbox')==='1'?'1':'0';$noInstallment='1';$maxInstallment='0';$currency='TL';
        $hashStr=$merchantId.$ip.$orderNo.$user['email'].(string)round($amount*100).$basketEncoded.$noInstallment.$maxInstallment.$currency.$test.$user['name'].$address.$phone.$salt;
        $token=base64_encode(hash_hmac('sha256',$hashStr,$merchantKey,true));
        $response=payment_http_post('https://www.paytr.com/odeme/api/get-token',[
          'merchant_id'=>$merchantId,'user_ip'=>$ip,'merchant_oid'=>$orderNo,'email'=>$user['email'],'payment_amount'=>(string)round($amount*100),'paytr_token'=>$token,'user_basket'=>$basketEncoded,'debug_on'=>$test,'no_installment'=>$noInstallment,'max_installment'=>$maxInstallment,'user_name'=>$user['name'],'user_address'=>$address,'user_phone'=>$phone,'merchant_ok_url'=>absolute_url('order/'.$orderId),'merchant_fail_url'=>absolute_url('order/'.$orderId),'timeout_limit'=>'30','currency'=>$currency,'test_mode'=>$test,'lang'=>'tr'
        ],['Content-Type: application/x-www-form-urlencoded']);
        $data=json_decode($response['body'],true);
        if(($data['status']??'')!=='success'||empty($data['token']))throw new RuntimeException('PayTR ödeme oturumu oluşturulamadı: '.($data['reason']??'Sağlayıcı yanıtı başarısız.'));
        db()->prepare("INSERT INTO payment_transactions(order_id,provider,provider_reference,amount,status) VALUES(?,?,?,?, 'pending')")->execute([$orderId,'paytr',(string)$data['token'],$amount]);
        redirect('https://www.paytr.com/odeme/guvenli/'.rawurlencode((string)$data['token']));
      } elseif($provider==='iyzico'){
        if(payment_secret('iyzico_enabled')!=='1')throw new RuntimeException('iyzico şu anda etkin değil.');
        if(strlen($identity)!==11)throw new RuntimeException('iyzico için 11 haneli T.C. kimlik numarası gereklidir.');
        $base=absolute_url('payment/iyzico-callback');
        $items=db()->prepare('SELECT id,product_name,quantity,unit_price FROM order_items WHERE order_id=?');$items->execute([$orderId]);$basketItems=[];$idx=1;
        foreach($items->fetchAll() as $it){$basketItems[]=['id'=>(string)$it['id'],'name'=>$it['product_name'],'category1'=>'Dijital Oyun Ürünleri','itemType'=>'VIRTUAL','price'=>number_format((float)$it['unit_price']*(int)$it['quantity'],2,'.','')];$idx++;}
        $buyerName=explode(' ',$user['name']);$surname=count($buyerName)>1?array_pop($buyerName):'Müşteri';$name=implode(' ',$buyerName)?:$user['name'];
        $payload=['locale'=>'tr','conversationId'=>$orderNo,'price'=>$amountText,'paidPrice'=>$amountText,'currency'=>'TRY','basketId'=>$orderNo,'paymentGroup'=>'PRODUCT','callbackUrl'=>$base,'enabledInstallments'=>[1,2,3,6,9],'buyer'=>['id'=>(string)$user['id'],'name'=>$name,'surname'=>$surname,'identityNumber'=>$identity,'email'=>$user['email'],'gsmNumber'=>$phone,'registrationAddress'=>$address,'city'=>$city,'country'=>'Turkey','zipCode'=>$zip,'ip'=>$ip=(string)($_SERVER['REMOTE_ADDR']??'127.0.0.1')],'shippingAddress'=>['contactName'=>$user['name'],'city'=>$city,'country'=>'Turkey','address'=>$address,'zipCode'=>$zip],'billingAddress'=>['contactName'=>$user['name'],'city'=>$city,'country'=>'Turkey','address'=>$address,'zipCode'=>$zip],'basketItems'=>$basketItems];
        $data=iyzico_request('/payment/iyzipos/checkoutform/initialize/auth/ecom',$payload);
        if(($data['status']??'')!=='success'||empty($data['token']))throw new RuntimeException('iyzico ödeme formu oluşturulamadı: '.($data['errorMessage']??'Sağlayıcı yanıtı başarısız.'));
        db()->prepare("INSERT INTO payment_transactions(order_id,provider,provider_reference,amount,status) VALUES(?,?,?,?, 'pending')")->execute([$orderId,'iyzico',(string)$data['token'],$amount]);
        if(!empty($data['paymentPageUrl']))redirect((string)$data['paymentPageUrl']);
        if(!empty($data['checkoutFormContent'])){render('store/iyzico-form',['title'=>'Güvenli ödeme','checkoutFormContent'=>$data['checkoutFormContent']]);exit;}
        throw new RuntimeException('iyzico ödeme formu bağlantısı döndürmedi.');
      } else throw new RuntimeException('Geçersiz ödeme sağlayıcısı.');
    }catch(Throwable $e){flash('error',$e->getMessage());redirect(url('order/'.$orderId));}
}
if($path==='payment/paytr-callback'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $post=$_POST;
    if(!paytr_callback_valid($post)){http_response_code(400);echo 'PAYTR notification failed: bad hash';exit;}
    $orderNo=(string)($post['merchant_oid']??'');$q=db()->prepare("SELECT o.id,o.total,o.payment_status,(SELECT COUNT(*) FROM payment_transactions pt WHERE pt.order_id=o.id AND pt.provider='paytr') AS has_attempt FROM orders o WHERE o.order_no=?");$q->execute([$orderNo]);$o=$q->fetch();
    if(!$o||(int)$o['has_attempt']<1){http_response_code(404);echo 'ORDER NOT FOUND';exit;}
    if(($post['status']??'')==='success'){
      if((int)($post['total_amount']??0)<(int)round((float)$o['total']*100)){http_response_code(400);echo 'AMOUNT MISMATCH';exit;}
      payment_mark_paid((int)$o['id'],'paytr',$orderNo);
    }else{db()->prepare("UPDATE orders SET payment_status='failed',status='payment_failed' WHERE id=? AND payment_status<>'paid'")->execute([(int)$o['id']]);db()->prepare("UPDATE payment_transactions SET status='failed' WHERE order_id=? AND provider='paytr'")->execute([(int)$o['id']]);}
    echo 'OK';exit;
}
if($path==='payment/iyzico-callback'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $token=trim((string)($_POST['token']??''));if($token===''){http_response_code(400);exit('Missing token');}
    $q=db()->prepare("SELECT pt.order_id,o.order_no FROM payment_transactions pt JOIN orders o ON o.id=pt.order_id WHERE pt.provider='iyzico' AND pt.provider_reference=? ORDER BY pt.id DESC LIMIT 1");$q->execute([$token]);$tx=$q->fetch();
    if(!$tx){http_response_code(404);exit('Transaction not found');}
    try{$result=iyzico_request('/payment/iyzipos/checkoutform/auth/ecom/detail',['locale'=>'tr','conversationId'=>$tx['order_no'],'token'=>$token]);if(($result['status']??'')==='success'&&($result['paymentStatus']??'')==='SUCCESS'){payment_mark_paid((int)$tx['order_id'],'iyzico',(string)($result['paymentId']??$token));}else{db()->prepare("UPDATE orders SET payment_status='failed',status='payment_failed' WHERE id=? AND payment_status<>'paid'")->execute([(int)$tx['order_id']]);db()->prepare("UPDATE payment_transactions SET status='failed' WHERE order_id=? AND provider='iyzico'")->execute([(int)$tx['order_id']]);}}
    catch(Throwable $e){error_log('iyzico callback: '.$e->getMessage());http_response_code(500);exit('Callback verification failed');}
    redirect(url('order/'.$tx['order_id']));
}

if($path==='logout'){$_SESSION=[];session_destroy();redirect(url('login'));}

if($path==='cart/add'&&$_SERVER['REQUEST_METHOD']==='POST'){csrf_check();$pid=(int)($_POST['product_id']??0);$qty=max(1,min(20,(int)($_POST['quantity']??1)));$st=db()->prepare("SELECT id,slug,delivery_type,status FROM products WHERE id=? AND status='active'");$st->execute([$pid]);$p=$st->fetch();if(!$p){flash('error','Ürün bulunamadı.');redirect(url());}if($p['delivery_type']==='automatic'){$st=db()->prepare("SELECT COUNT(*) FROM inventory_codes WHERE product_id=? AND status='available'");$st->execute([$pid]);if((int)$st->fetchColumn()<$qty){flash('error','Bu ürün için yeterli dijital kod stoğu bulunmuyor.');redirect(url('product/'.rawurlencode($p['slug'])));}}$_SESSION['cart'][$pid]=min(20,(int)($_SESSION['cart'][$pid]??0)+$qty);flash('success','Ürün sepete eklendi.');redirect(url('cart'));}
if($path==='cart/remove'&&$_SERVER['REQUEST_METHOD']==='POST'){csrf_check();unset($_SESSION['cart'][(int)($_POST['product_id']??0)]);redirect(url('cart'));}
if($path==='cart'){$cart=[];$total=0;foreach(($_SESSION['cart']??[]) as $pid=>$qty){$st=db()->prepare("SELECT id,name,slug,price,image,delivery_type FROM products WHERE id=? AND status='active'");$st->execute([(int)$pid]);if($p=$st->fetch()){$p['quantity']=(int)$qty;$p['line_total']=(float)$p['price']*$p['quantity'];$total+=$p['line_total'];$cart[]=$p;}else unset($_SESSION['cart'][$pid]);}render('store/cart',['title'=>'Alışveriş sepetim','cart'=>$cart,'total'=>$total]);exit;}
if($path==='checkout'){require_login();if($_SERVER['REQUEST_METHOD']==='POST'){csrf_check();$cart=$_SESSION['cart']??[];if(!$cart){flash('error','Sepetiniz boş.');redirect(url('cart'));}$pdo=db();$pdo->beginTransaction();try{$items=[];$total=0;foreach($cart as $pid=>$qty){$st=$pdo->prepare("SELECT id,name,price,delivery_type,status FROM products WHERE id=? AND status='active' FOR UPDATE");$st->execute([(int)$pid]);$p=$st->fetch();if(!$p)throw new RuntimeException('Sepetinizde yayından kaldırılmış bir ürün var.');$qty=max(1,min(20,(int)$qty));$items[]=$p+['quantity'=>$qty];$total+=(float)$p['price']*$qty;}$orderNo='EP'.date('ymd').strtoupper(bin2hex(random_bytes(4)));$st=$pdo->prepare("INSERT INTO orders(user_id,order_no,subtotal,total,status,payment_status) VALUES(?,?,?,?, 'pending','unpaid')");$st->execute([(int)$_SESSION['user']['id'],$orderNo,$total,$total]);$orderId=(int)$pdo->lastInsertId();$ins=$pdo->prepare("INSERT INTO order_items(order_id,product_id,product_name,quantity,unit_price) VALUES(?,?,?,?,?)");foreach($items as $p){$ins->execute([$orderId,(int)$p['id'],$p['name'],(int)$p['quantity'],$p['price']]);if($p['delivery_type']==='automatic'){$q=(int)$p['quantity'];$lock=$pdo->prepare("SELECT id FROM inventory_codes WHERE product_id=? AND status='available' ORDER BY id LIMIT ".$q." FOR UPDATE");$lock->execute([(int)$p['id']]);$ids=$lock->fetchAll(PDO::FETCH_COLUMN);if(count($ids)<$q)throw new RuntimeException($p['name'].' için yeterli kod stoğu kalmadı.');$reserve=$pdo->prepare("UPDATE inventory_codes SET status='reserved',reserved_order_id=?,reserved_until=DATE_ADD(NOW(),INTERVAL 30 MINUTE) WHERE id=? AND status='available'");foreach($ids as $id)$reserve->execute([$orderId,(int)$id]);}}$pdo->commit();$_SESSION['cart']=[];redirect(url('order/'.$orderId));}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect(url('cart'));}}render('store/checkout',['title'=>'Siparişi onayla']);exit;}
if(str_starts_with($path,'order/')){require_login();$orderId=(int)substr($path,6);$st=db()->prepare("SELECT * FROM orders WHERE id=? AND user_id=?");$st->execute([$orderId,(int)$_SESSION['user']['id']]);$order=$st->fetch();if(!$order){http_response_code(404);render('store/not-found',['title'=>'Sipariş bulunamadı']);exit;}$st=db()->prepare("SELECT oi.*,p.delivery_type FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?");$st->execute([$orderId]);$orderItems=$st->fetchAll();$st=db()->prepare("SELECT * FROM inventory_codes WHERE sold_order_id=? AND status='sold' ORDER BY id");$st->execute([$orderId]);$deliveredCodes=$st->fetchAll();render('store/order',['title'=>'Sipariş bilgisi','order'=>$order,'orderItems'=>$orderItems,'deliveredCodes'=>$deliveredCodes]);exit;}
if($path==='login'&&$_SERVER['REQUEST_METHOD']==='POST'){csrf_check();$email=trim((string)($_POST['email']??''));$st=db()->prepare('SELECT id,name,email,password_hash,role,status FROM users WHERE email=? LIMIT 1');$st->execute([$email]);$u=$st->fetch();if($u&&$u['status']==='active'&&password_verify((string)($_POST['password']??''),$u['password_hash'])){session_regenerate_id(true);$_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']];redirect(url($u['role']==='admin'?'admin':'account'));}flash('error','Giriş bilgileri doğrulanamadı.');redirect(url('login'));}
if($path==='login'){render('auth/login',['title'=>'Giriş yap']);exit;}
if($path==='admin'||str_starts_with($path,'admin/')){require APP_ROOT.'/app/admin.php';exit;}
if($path==='seller'||str_starts_with($path,'seller/')){if(config('business_mode')!=='multi_vendor'){http_response_code(404);exit('Sayfa bulunamadı');}require_login('seller');render('account/dashboard',['title'=>'Satıcı paneli']);exit;}

if(str_starts_with($path,'account/')&&$_SERVER['REQUEST_METHOD']==='POST'){
 require_login();csrf_check();$uid=(int)$_SESSION['user']['id'];$action=substr($path,8);
 try{
  if($action==='profile'){
   $name=trim((string)($_POST['name']??''));$email=strtolower(trim((string)($_POST['email']??'')));
   if(strlen($name)<2||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Ad soyad ve geçerli e-posta adresi girin.');
   db()->prepare('UPDATE users SET name=?,email=? WHERE id=?')->execute([$name,$email,$uid]);
   $_SESSION['user']['name']=$name;$_SESSION['user']['email']=$email;flash('success','Profil bilgileriniz güncellendi.');
  }elseif($action==='password'){
   $current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');$confirm=(string)($_POST['confirm_password']??'');
   $q=db()->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$uid]);$hash=(string)$q->fetchColumn();
   if(!password_verify($current,$hash))throw new RuntimeException('Mevcut şifreniz hatalı.');
   if(strlen($new)<10)throw new RuntimeException('Yeni şifre en az 10 karakter olmalı.');
   if($new!==$confirm)throw new RuntimeException('Yeni şifre tekrarı eşleşmiyor.');
   db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$uid]);flash('success','Şifreniz değiştirildi.');
  }elseif($action==='ticket'){
   $subject=trim((string)($_POST['subject']??''));$message=trim((string)($_POST['message']??''));
   if(strlen($subject)<4||strlen($message)<15)throw new RuntimeException('Konu en az 4, mesajınız en az 15 karakter olmalı.');
   db()->prepare("INSERT INTO support_tickets(user_id,subject,message) VALUES(?,?,?)")->execute([$uid,substr($subject,0,190),$message]);flash('success','Destek talebiniz oluşturuldu.');
  }else{http_response_code(404);exit('İşlem bulunamadı.');}
 }catch(PDOException $e){flash('error',$e->getCode()==='23000'?'Bu e-posta adresi başka bir hesapta kullanılıyor.':'Bilgiler kaydedilemedi. Lütfen tekrar deneyin.');}
 catch(Throwable $e){flash('error',$e->getMessage());}
 redirect(url('account'));
}
if($path==='account'){
 require_login();$uid=(int)$_SESSION['user']['id'];
 $q=db()->prepare('SELECT id,order_no,total,status,payment_status,created_at FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 50');$q->execute([$uid]);$accountOrders=$q->fetchAll();
 $q=db()->prepare('SELECT id,subject,message,status,admin_reply,created_at,updated_at FROM support_tickets WHERE user_id=? ORDER BY id DESC LIMIT 30');$q->execute([$uid]);$tickets=$q->fetchAll();
 $q=db()->prepare('SELECT name,email,created_at FROM users WHERE id=?');$q->execute([$uid]);$accountUser=$q->fetch();
 render('account/dashboard',['title'=>'Hesabım','accountUser'=>$accountUser,'accountOrders'=>$accountOrders,'tickets'=>$tickets]);exit;
}
if(str_starts_with($path,'page/')){$slug=substr($path,5);$st=db()->prepare("SELECT title,content FROM pages WHERE slug=? AND status='active'");$st->execute([$slug]);$page=$st->fetch();if(!$page){http_response_code(404);$page=['title'=>'Sayfa bulunamadı','content'=>''];}render('store/page',['title'=>$page['title'],'page'=>$page]);exit;}
if(str_starts_with($path,'category/')){$slug=rawurldecode(substr($path,9));$st=db()->prepare("SELECT id,name,slug,description FROM categories WHERE slug=? AND status='active' LIMIT 1");$st->execute([$slug]);$category=$st->fetch();if(!$category){http_response_code(404);render('store/not-found',['title'=>'Kategori bulunamadı']);exit;}$st=db()->prepare("SELECT id,name,slug,short_description,price,image FROM products WHERE category_id=? AND status='active' ORDER BY sort_order,id DESC");$st->execute([(int)$category['id']]);$products=$st->fetchAll();render('store/category',['title'=>$category['name'],'category'=>$category,'products'=>$products]);exit;}
if(str_starts_with($path,'product/')){$slug=rawurldecode(substr($path,8));$st=db()->prepare("SELECT p.*,c.name AS category_name,c.slug AS category_slug FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.slug=? AND p.status='active' LIMIT 1");$st->execute([$slug]);$product=$st->fetch();if(!$product){http_response_code(404);render('store/not-found',['title'=>'Ürün bulunamadı']);exit;}$related=[];if(!empty($product['category_id'])){$st=db()->prepare("SELECT id,name,slug,short_description,price,image FROM products WHERE category_id=? AND id<>? AND status='active' ORDER BY sort_order,id DESC LIMIT 4");$st->execute([(int)$product['category_id'],(int)$product['id']]);$related=$st->fetchAll();}$st=db()->prepare("SELECT COUNT(*) FROM inventory_codes WHERE product_id=? AND status='available'");$st->execute([(int)$product['id']]);$availableStock=(int)$st->fetchColumn();render('store/product',['title'=>$product['name'],'product'=>$product,'related'=>$related,'available_stock'=>$availableStock]);exit;}
$settings=[];foreach(db()->query('SELECT setting_key,setting_value FROM settings')->fetchAll() as $r)$settings[$r['setting_key']]=$r['setting_value'];
$categories=db()->query("SELECT id,name,slug FROM categories WHERE status='active' ORDER BY sort_order,name")->fetchAll();
$products=db()->query("SELECT id,name,slug,short_description,price,image FROM products WHERE status='active' ORDER BY sort_order,id DESC LIMIT 12")->fetchAll();
render('store/home',['title'=>$settings['site_title']??'E-pin mağazası','settings'=>$settings,'categories'=>$categories,'products'=>$products]);
