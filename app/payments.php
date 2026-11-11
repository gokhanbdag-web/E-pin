<?php
declare(strict_types=1);

/**
 * Composer-free payment transport and signature helpers.
 * Provider secrets are read from encrypted settings rows.
 */
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
function payment_http_post(string $url,array $payload,array $headers=[]):array {
    if(!function_exists('curl_init')) throw new RuntimeException('Ödeme bağlantısı için sunucuda PHP cURL eklentisi etkin olmalı.');
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$headers]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($body===false) throw new RuntimeException('Ödeme sağlayıcısına bağlanılamadı: '.$err);
    return ['status'=>$status,'body'=>(string)$body];
}
function payment_mark_paid(int $orderId,string $provider,string $reference=''):void {
    $pdo=db();$pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT id,payment_status FROM orders WHERE id=? FOR UPDATE');$q->execute([$orderId]);$o=$q->fetch();
        if(!$o) throw new RuntimeException('Sipariş bulunamadı.');
        if($o['payment_status']!=='paid') {
            $pdo->prepare("UPDATE orders SET payment_status='paid',status='processing' WHERE id=?")->execute([$orderId]);
            $pdo->prepare("UPDATE payment_transactions SET status='success',provider_reference=? WHERE order_id=? AND provider=?")->execute([$reference,$orderId,$provider]);
        }
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
    fulfill_paid_order($orderId);
}
function paytr_callback_valid(array $post):bool {
    $key=payment_secret('paytr_merchant_key');$salt=payment_secret('paytr_merchant_salt');
    if($key===''||$salt==='') return false;
    $raw=(string)($post['merchant_oid']??'').$salt.(string)($post['status']??'').(string)($post['total_amount']??'');
    $expected=base64_encode(hash_hmac('sha256',$raw,$key,true));
    return isset($post['hash'])&&hash_equals($expected,(string)$post['hash']);
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
