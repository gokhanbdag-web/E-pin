<?php
declare(strict_types=1);

/**
 * Composer-free payment transport and signature helpers.
 * Provider secrets are read from encrypted settings rows.
 */
function secure_setting_encrypt(string $plain):string {
    if($plain==='') return '';
    $key=hash('sha256',(string)config('app_key',config('db_pass')),true);
    $nonce=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag);
    if($cipher===false) throw new RuntimeException('Ayar şifrelenemedi.');
    return 'enc:'.base64_encode($nonce.$tag.$cipher);
}
function payment_secret(string $name): string {
    $q=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $q->execute(['payment_'.$name]);
    $value=$q->fetchColumn();
    if($value===false) return '';
    $value=(string)$value;
    if(!str_starts_with($value,'enc:')) return $value;
    $raw=base64_decode(substr($value,4),true);
    if($raw===false || strlen($raw)<29) return '';
    $key=hash('sha256',(string)config('app_key',config('db_pass')),true);
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
    return $plain===false?'':$plain;
}
function payment_amount_matches(mixed $left,mixed $right):bool {
    if(!is_numeric($left)||!is_numeric($right)) return false;
    return (int)round((float)$left*100)===(int)round((float)$right*100);
}
function payment_http_post(string $url,array $payload,array $headers=[]):array {
    if(!function_exists('curl_init')) throw new RuntimeException('Ödeme bağlantısı için sunucuda PHP cURL eklentisi etkin olmalı.');
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$headers]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($body===false) throw new RuntimeException('Ödeme sağlayıcısına bağlanılamadı: '.$err);
    return ['status'=>$status,'body'=>(string)$body];
}
function cancel_unpaid_order(int $orderId,?int $ownerId=null):void {
    $pdo=db();$pdo->beginTransaction();
    try {
        $sql='SELECT id,payment_status,status,coupon_code FROM orders WHERE id=?'.($ownerId!==null?' AND user_id=?':'').' FOR UPDATE';
        $q=$pdo->prepare($sql);$q->execute($ownerId!==null?[$orderId,$ownerId]:[$orderId]);$order=$q->fetch();
        if(!$order)throw new RuntimeException('Sipariş bulunamadı.');
        if($order['payment_status']==='paid')throw new RuntimeException('Ödemesi alınmış sipariş iptal edilemez; iade talebi oluşturun.');
        if(!in_array($order['status'],['pending','payment_failed'],true))throw new RuntimeException('Bu sipariş artık iptal edilemez.');
        $pdo->prepare("UPDATE inventory_codes SET status='available',reserved_order_id=NULL,reserved_until=NULL WHERE reserved_order_id=? AND status='reserved'")->execute([$orderId]);
        $pdo->prepare("UPDATE orders SET status='cancelled',coupon_usage_released=1 WHERE id=?")->execute([$orderId]);if(!empty($order['coupon_code']))$pdo->prepare('UPDATE coupons SET usage_count=GREATEST(usage_count-1,0) WHERE code=?')->execute([$order['coupon_code']]);
        $pdo->prepare("UPDATE payment_transactions SET status='cancelled' WHERE order_id=? AND status='pending'")->execute([$orderId]);
        $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function payment_attempt_reserve(int $orderId,string $provider,float $amount,string $reference):void {
    if(!in_array($provider,['paytr','iyzico'],true)||$reference==='') throw new RuntimeException('Geçersiz ödeme denemesi.');
    $pdo=db();$pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT payment_status,status FROM orders WHERE id=? FOR UPDATE');
        $q->execute([$orderId]);$order=$q->fetch();
        if(!$order||$order['payment_status']==='paid'||!in_array($order['status'],['pending','payment_failed'],true)) throw new RuntimeException('Sipariş yeni bir ödemeye uygun değil.');
        $pdo->prepare("UPDATE payment_transactions SET status='expired' WHERE order_id=? AND status='pending' AND created_at<DATE_SUB(NOW(),INTERVAL 30 MINUTE)")->execute([$orderId]);
        $q=$pdo->prepare("SELECT id FROM payment_transactions WHERE order_id=? AND status='pending' LIMIT 1 FOR UPDATE");
        $q->execute([$orderId]);
        if($q->fetchColumn()) throw new RuntimeException('Bu sipariş için zaten bekleyen bir ödeme oturumu var. Önceki ödeme denemesinin sonuçlanmasını bekleyin.');
        $pdo->prepare("INSERT INTO payment_transactions(order_id,provider,provider_reference,amount,status) VALUES(?,?,?,?, 'pending')")->execute([$orderId,$provider,$reference,$amount]);
        $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function payment_attempt_update_reference(int $orderId,string $provider,string $oldReference,string $newReference):void {
    if($newReference==='') throw new RuntimeException('Ödeme sağlayıcısı geçersiz işlem referansı döndürdü.');
    $q=db()->prepare("UPDATE payment_transactions SET provider_reference=? WHERE order_id=? AND provider=? AND provider_reference=? AND status='pending'");
    $q->execute([$newReference,$orderId,$provider,$oldReference]);
    if($q->rowCount()!==1) throw new RuntimeException('Ödeme oturumu işlem kaydı güncellenemedi.');
}
function payment_attempt_fail(int $orderId,string $provider,string $reference):void {
    if($reference==='') return;
    db()->prepare("UPDATE payment_transactions SET status='failed' WHERE order_id=? AND provider=? AND provider_reference=? AND status='pending'")->execute([$orderId,$provider,$reference]);
}
function payment_mark_paid(int $orderId,string $provider,string $reference=''):void {
    $pdo=db();$pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT id,payment_status,status,coupon_code FROM orders WHERE id=? FOR UPDATE');$q->execute([$orderId]);$o=$q->fetch();
        if(!$o) throw new RuntimeException('Sipariş bulunamadı.');
        $q=$pdo->prepare("SELECT status FROM refund_requests WHERE order_id=? LIMIT 1 FOR UPDATE");$q->execute([$orderId]);$refundStatus=$q->fetchColumn();
        if($o['status']==='refunded') {
            $pdo->commit();
            error_log('Payment callback ignored for refunded order '.$orderId.'.');
            return;
        }
        if(in_array($refundStatus,['requested','approved'],true)) {
            if($o['payment_status']!=='paid') {
                if(!empty($o['coupon_code']))$pdo->prepare('UPDATE coupons SET usage_count=usage_count+1 WHERE code=?')->execute([$o['coupon_code']]);
                $pdo->prepare("UPDATE orders SET payment_status='paid',status='manual_review' WHERE id=?")->execute([$orderId]);
            } else {
                $pdo->prepare("UPDATE orders SET status='manual_review' WHERE id=?")->execute([$orderId]);
            }
            $pdo->prepare("UPDATE payment_transactions SET status='success' WHERE order_id=? AND provider=? AND provider_reference=?")->execute([$orderId,$provider,$reference]);
            $pdo->commit();
            error_log('Payment received while refund request is open for order '.$orderId.'; manual review required.');
            return;
        }
        if($o['payment_status']!=='paid' && in_array($o['status'],['cancelled','expired'],true)) {
            if(!empty($o['coupon_code']))$pdo->prepare('UPDATE coupons SET usage_count=usage_count+1 WHERE code=?')->execute([$o['coupon_code']]);
            $pdo->prepare("UPDATE orders SET payment_status='paid',status='manual_review' WHERE id=?")->execute([$orderId]);
            $pdo->prepare("UPDATE payment_transactions SET status='success' WHERE order_id=? AND provider=? AND provider_reference=?")->execute([$orderId,$provider,$reference]);
            $pdo->commit();
            error_log('Late payment for cancelled/expired order '.$orderId.' requires manual review.');
            return;
        }
        if($o['payment_status']!=='paid') {
            $pdo->prepare("UPDATE orders SET payment_status='paid',status='processing' WHERE id=?")->execute([$orderId]);
            $pdo->prepare("UPDATE payment_transactions SET status='success' WHERE order_id=? AND provider=? AND provider_reference=?")->execute([$orderId,$provider,$reference]);
        }
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
    try { fulfill_paid_order($orderId); seller_record_paid_order($orderId); } catch(Throwable $deliveryError) { db()->prepare("UPDATE orders SET status='manual_review' WHERE id=? AND payment_status='paid'")->execute([$orderId]); error_log('Paid order '.$orderId.' requires manual fulfilment: '.$deliveryError->getMessage()); }
}
function paytr_signature_matches(array $post,string $key,string $salt):bool {
    if($key===''||$salt==='') return false;
    $raw=(string)($post['merchant_oid']??'').$salt.(string)($post['status']??'').(string)($post['total_amount']??'');
    $expected=base64_encode(hash_hmac('sha256',$raw,$key,true));
    return isset($post['hash'])&&hash_equals($expected,(string)$post['hash']);
}
function paytr_callback_valid(array $post):bool {
    return paytr_signature_matches($post,payment_secret('paytr_merchant_key'),payment_secret('paytr_merchant_salt'));
}
function iyzico_checkout_result_valid(array $result,string $orderNo,mixed $total):bool {
    return ($result['status']??'')==='success'
        && ($result['paymentStatus']??'')==='SUCCESS'
        && in_array($result['fraudStatus']??null,[1,'1'],true)
        && strtoupper((string)($result['currency']??''))==='TRY'
        && hash_equals($orderNo,(string)($result['conversationId']??''))
        && payment_amount_matches($result['paidPrice']??null,$total);
}
function iyzico_request(string $path,array $payload):array {
    $apiKey=payment_secret('iyzico_api_key');$secret=payment_secret('iyzico_secret_key');
    if($apiKey===''||$secret==='') throw new RuntimeException('iyzico API anahtarı ve gizli anahtarı girilmelidir.');
    if(!function_exists('curl_init')) throw new RuntimeException('iyzico bağlantısı için PHP cURL eklentisi etkin olmalı.');
    $sandbox=payment_secret('iyzico_sandbox')==='1';
    $host=$sandbox?'https://sandbox-api.iyzipay.com':'https://api.iyzipay.com';
    $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $rnd=(string)round(microtime(true)*1000).bin2hex(random_bytes(5));
    $signature=hash_hmac('sha256',$rnd.$path.$body,$secret);
    $auth=base64_encode('apiKey:'.$apiKey.'&randomKey:'.$rnd.'&signature:'.$signature);
    $ch=curl_init($host.$path);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Authorization: IYZWSv2 '.$auth,'x-iyzi-rnd: '.$rnd,'Content-Type: application/json','Accept: application/json']]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($response===false) throw new RuntimeException('iyzico bağlantı hatası: '.$err);
    $decoded=json_decode((string)$response,true);
    if(!is_array($decoded)) throw new RuntimeException('iyzico geçersiz yanıt döndürdü (HTTP '.$status.').');
    return $decoded+['_http_status'=>$status];
}
