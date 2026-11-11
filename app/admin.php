<?php
declare(strict_types=1);
require_login('admin');
$resource=(string)($_GET['section']??'overview');
$meta=[
'categories'=>['title'=>'Oyun kategorileri','table'=>'categories','fields'=>['name'=>'Kategori adı','slug'=>'URL kısa adı','description'=>'Açıklama','sort_order'=>'Sıralama','status'=>'Durum']],
'products'=>['title'=>'Ürünler','table'=>'products','fields'=>['name'=>'Ürün adı','slug'=>'URL kısa adı','category_id'=>'Kategori','short_description'=>'Kısa açıklama','description'=>'Ürün açıklaması','price'=>'Fiyat','image'=>'Görsel URL','delivery_type'=>'Teslimat türü','status'=>'Durum','sort_order'=>'Sıralama']],
'pages'=>['title'=>'Sayfalar','table'=>'pages','fields'=>['title'=>'Sayfa başlığı','slug'=>'URL kısa adı','content'=>'Sayfa içeriği','status'=>'Durum']],
'menus'=>['title'=>'Menüler','table'=>'menus','fields'=>['title'=>'Menü başlığı','url'=>'Bağlantı','location'=>'Konum','sort_order'=>'Sıralama','status'=>'Durum']]
];
if(config('business_mode')!=='multi_vendor'&&$resource==='sellers')$resource='overview';
if(!isset($meta[$resource])&&!in_array($resource,['overview','settings','inventory','payments','orders','tickets','sellers','seller_payments'],true))$resource='overview';
if($_SERVER['REQUEST_METHOD']==='POST'){csrf_check();$action=(string)($_POST['action']??'');try{
 if($resource==='sellers'&&config('business_mode')==='multi_vendor'&&$action==='review'){
 $id=(int)($_POST['id']??0);$decision=(string)($_POST['decision']??'');
 if(!in_array($decision,['approved','rejected'],true))throw new RuntimeException('Geçersiz başvuru kararı.');
 $pdo=db();$pdo->beginTransaction();
 try{
  $q=$pdo->prepare('SELECT user_id FROM seller_profiles WHERE id=? FOR UPDATE');$q->execute([$id]);$profile=$q->fetch();
  if(!$profile)throw new RuntimeException('Satıcı başvurusu bulunamadı.');
  $pdo->prepare('UPDATE seller_profiles SET status=? WHERE id=?')->execute([$decision,$id]);
  $role=$decision==='approved'?'seller':'customer';
  $pdo->prepare("UPDATE users SET role=? WHERE id=? AND role<>'admin'")->execute([$role,(int)$profile['user_id']]);
  $pdo->commit();flash('success',$decision==='approved'?'Satıcı başvurusu onaylandı.':'Satıcı başvurusu reddedildi.');
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
elseif($resource==='sellers'&&config('business_mode')==='multi_vendor'&&$action==='commission'){
 $id=(int)($_POST['id']??0);$rate=(float)($_POST['commission_rate']??-1);if($rate<0||$rate>50)throw new RuntimeException('Komisyon oranı 0 ile 50 arasında olmalı.');db()->prepare('UPDATE seller_profiles SET commission_rate=? WHERE id=?')->execute([number_format($rate,2,'.',''),$id]);flash('success','Satıcı komisyon oranı güncellendi.');
}
elseif($resource==='seller_payments'&&config('business_mode')==='multi_vendor'&&$action==='withdrawal_review'){
 $id=(int)($_POST['id']??0);$decision=(string)($_POST['decision']??'');if(!in_array($decision,['approved','rejected','paid'],true))throw new RuntimeException('Geçersiz ödeme kararı.');$pdo=db();$pdo->beginTransaction();try{$q=$pdo->prepare('SELECT * FROM seller_withdrawals WHERE id=? FOR UPDATE');$q->execute([$id]);$w=$q->fetch();if(!$w)throw new RuntimeException('Ödeme talebi bulunamadı.');if($decision==='approved'){if($w['status']!=='pending')throw new RuntimeException('Yalnızca bekleyen talepler onaylanabilir.');$pdo->prepare("UPDATE seller_withdrawals SET status='approved',admin_note=? WHERE id=?")->execute([substr(trim((string)($_POST['admin_note']??'')),0,500),$id]);}
 elseif($decision==='rejected'){if(!in_array($w['status'],['pending','approved'],true))throw new RuntimeException('Bu talep artık reddedilemez.');$pdo->prepare("UPDATE seller_withdrawals SET status='rejected',admin_note=? WHERE id=?")->execute([substr(trim((string)($_POST['admin_note']??'')),0,500),$id]);}
 else{if(!in_array($w['status'],['pending','approved'],true))throw new RuntimeException('Bu talep daha önce sonuçlandırılmış.');$pdo->prepare("UPDATE seller_withdrawals SET status='paid',admin_note=? WHERE id=?")->execute([substr(trim((string)($_POST['admin_note']??'')),0,500),$id]);$pdo->prepare("INSERT INTO seller_ledger(seller_id,order_id,entry_type,amount,status) VALUES(?,NULL,'withdrawal',?,'available')")->execute([(int)$w['seller_id'],-(float)$w['amount']]);}
 $pdo->commit();flash('success',$decision==='paid'?'Ödeme yapıldı olarak kaydedildi.':($decision==='approved'?'Ödeme talebi onaylandı.':'Ödeme talebi reddedildi.'));
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
elseif($resource==='tickets'&&$action==='reply'){$id=(int)($_POST['id']??0);$reply=trim((string)($_POST['admin_reply']??''));$status=(string)($_POST['status']??'open');if(!in_array($status,['open','in_progress','closed'],true))throw new RuntimeException('Geçersiz talep durumu.');if(strlen($reply)<2)throw new RuntimeException('Yanıt metni en az 2 karakter olmalı.');db()->prepare('UPDATE support_tickets SET admin_reply=?,status=? WHERE id=?')->execute([$reply,$status,$id]);flash('success','Destek talebi yanıtlandı.');}
 elseif($resource==='payments'&&$action==='save'){
 $keys=['paytr_enabled','paytr_sandbox','paytr_merchant_id','paytr_merchant_key','paytr_merchant_salt','iyzico_enabled','iyzico_sandbox','iyzico_api_key','iyzico_secret_key'];
 foreach($keys as $k){
  $v=trim((string)($_POST[$k]??''));
  if(str_ends_with($k,'enabled')||str_ends_with($k,'sandbox')) $v=isset($_POST[$k])?'1':'0';
  if(in_array($k,['paytr_merchant_key','paytr_merchant_salt','iyzico_api_key','iyzico_secret_key'],true)&&$v==='') continue;
  $st=db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
  $st->execute(['payment_'.$k,secure_setting_encrypt($v)]);
 }
 flash('success','Ödeme ayarları kaydedildi.');
}
elseif($resource==='inventory'&&$action==='import_codes'){$productId=(int)($_POST['product_id']??0);$st=db()->prepare("SELECT id FROM products WHERE id=?");$st->execute([$productId]);if(!$st->fetch())throw new RuntimeException('Ürün seçin.');$lines=preg_split('/\r\n|\r|\n/',(string)($_POST['codes']??''));$added=0;$duplicates=0;$ins=db()->prepare("INSERT IGNORE INTO inventory_codes(product_id,code_ciphertext,code_nonce,code_tag,code_hash,status) VALUES(?,?,?,?,?,'available')");foreach($lines as $line){$code=trim($line);if($code==='')continue;if(strlen($code)<3||strlen($code)>250)throw new RuntimeException('Kod uzunluğu 3–250 karakter arasında olmalı.');[$cipher,$nonce,$tag,$hash]=inventory_encrypt($code);$ins->execute([$productId,$cipher,$nonce,$tag,$hash]);if($ins->rowCount())$added++;else $duplicates++;}db()->prepare("UPDATE products SET delivery_type='automatic' WHERE id=?")->execute([$productId]);flash('success',$added.' kod stoğa eklendi. Tekrarlanan kod: '.$duplicates.'. Ürün otomatik teslimata alındı.');}
 elseif($resource==='settings'&&$action==='save'){$st=db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');foreach(['site_title','home_heading','home_description','footer_text'] as $k)$st->execute([$k,trim((string)($_POST[$k]??''))]);flash('success','Mağaza ayarları kaydedildi.');}
 elseif(isset($meta[$resource])&&$action==='save'){$m=$meta[$resource];$id=(int)($_POST['id']??0);$data=[];foreach($m['fields'] as $field=>$label){$v=trim((string)($_POST[$field]??''));if($field==='price'){if(!is_numeric($v)||(float)$v<0)throw new RuntimeException('Geçerli fiyat girin.');$v=number_format((float)$v,2,'.','');}if($field==='sort_order')$v=(string)(int)$v;if($field==='category_id')$v=$v===''?null:(string)(int)$v;if($field==='slug'){$ascii=function_exists('iconv')?iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v):$v;$v=trim(preg_replace('/[^a-z0-9-]+/','-',strtolower($ascii?:$v)),'-');}$data[$field]=$v;}if($id){$set=implode(',',array_map(fn($k)=>$k.'=?',array_keys($data)));$vals=array_values($data);$vals[]=$id;db()->prepare('UPDATE '.$m['table'].' SET '.$set.' WHERE id=?')->execute($vals);}else{$keys=array_keys($data);db()->prepare('INSERT INTO '.$m['table'].'('.implode(',',$keys).') VALUES('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_values($data));}flash('success',$m['title'].' kaydedildi.');}
 elseif(isset($meta[$resource])&&$action==='delete'){$id=(int)($_POST['id']??0);if($id)db()->prepare('DELETE FROM '.$meta[$resource]['table'].' WHERE id=?')->execute([$id]);flash('success','Kayıt silindi.');}
 else throw new RuntimeException('Geçersiz işlem.');
}catch(Throwable $e){flash('error',$e->getMessage());}redirect(url('admin?section='.$resource));}
$edit=null;$rows=[];$categories=[];$settings=[];$stats=[];
if(isset($meta[$resource])){$m=$meta[$resource];$rows=db()->query('SELECT * FROM '.$m['table'].' ORDER BY id DESC LIMIT 250')->fetchAll();if($resource==='products')$categories=db()->query('SELECT id,name FROM categories ORDER BY name')->fetchAll();if(isset($_GET['edit'])){$st=db()->prepare('SELECT * FROM '.$m['table'].' WHERE id=?');$st->execute([(int)$_GET['edit']]);$edit=$st->fetch()?:null;}}
$sellerRows=[];if(($resource==='sellers'||$resource==='seller_payments')&&config('business_mode')==='multi_vendor'){$sellerRows=db()->query("SELECT s.*,u.name AS owner_name,u.email AS owner_email FROM seller_profiles s JOIN users u ON u.id=s.user_id ORDER BY FIELD(s.status,'pending','approved','rejected'),s.id DESC LIMIT 300")->fetchAll();}
$withdrawalRows=[];if($resource==='seller_payments'&&config('business_mode')==='multi_vendor'){$withdrawalRows=db()->query("SELECT w.*,s.shop_name,u.name AS owner_name,u.email AS owner_email FROM seller_withdrawals w JOIN seller_profiles s ON s.id=w.seller_id JOIN users u ON u.id=s.user_id ORDER BY FIELD(w.status,'pending','approved','paid','rejected'),w.id DESC LIMIT 300")->fetchAll();}
$ticketRows=[];if($resource==='tickets'){$ticketRows=db()->query("SELECT t.*,u.name AS customer_name,u.email AS customer_email FROM support_tickets t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC LIMIT 300")->fetchAll();}
$orderRows=[];if($resource==='orders'){$orderRows=db()->query("SELECT o.*,u.name AS customer_name,u.email AS customer_email,(SELECT provider FROM payment_transactions pt WHERE pt.order_id=o.id ORDER BY pt.id DESC LIMIT 1) AS payment_provider FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 300")->fetchAll();}
$ticketRows=[];if($resource==='tickets'){$ticketRows=db()->query("SELECT t.*,u.name AS customer_name,u.email AS customer_email FROM support_tickets t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC LIMIT 300")->fetchAll();}
$inventoryProducts=[];$inventoryRows=[];if($resource==='inventory'){$inventoryProducts=db()->query("SELECT id,name FROM products ORDER BY name")->fetchAll();$inventoryRows=db()->query("SELECT p.id,p.name,p.delivery_type,SUM(i.status='available') AS available,SUM(i.status='reserved') AS reserved,SUM(i.status='sold') AS sold FROM products p LEFT JOIN inventory_codes i ON i.product_id=p.id GROUP BY p.id,p.name,p.delivery_type ORDER BY p.name")->fetchAll();}
if($resource==='settings'||$resource==='payments')foreach(db()->query('SELECT setting_key,setting_value FROM settings')->fetchAll() as $r)$settings[$r['setting_key']]=$r['setting_value'];
if($resource==='overview'){foreach(['Kullanıcı'=>'users','Kategori'=>'categories','Ürün'=>'products','Sipariş'=>'orders'] as $label=>$table)$stats[$label]=(int)db()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();$stats['Yayındaki ürün']=(int)db()->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();}
render('admin/manage',['title'=>$meta[$resource]['title']??($resource==='settings'?'Mağaza ayarları':($resource==='payments'?'Ödeme ayarları':($resource==='orders'?'Siparişler':($resource==='sellers'?'Satıcı başvuruları':($resource==='seller_payments'?'Satıcı ödemeleri':'Genel bakış'))))),'resource'=>$resource,'meta'=>$meta,'rows'=>$rows,'edit'=>$edit,'categories'=>$categories,'settings'=>$settings,'stats'=>$stats,'inventoryProducts'=>$inventoryProducts,'inventoryRows'=>$inventoryRows,'sellerRows'=>$sellerRows,'paymentSettings'=>['paytr_enabled'=>payment_secret('paytr_enabled'),'paytr_sandbox'=>payment_secret('paytr_sandbox'),'paytr_merchant_id'=>payment_secret('paytr_merchant_id'),'iyzico_enabled'=>payment_secret('iyzico_enabled'),'iyzico_sandbox'=>payment_secret('iyzico_sandbox')],'ticketRows'=>$ticketRows,'withdrawalRows'=>$withdrawalRows??[]]);
