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
if(!isset($meta[$resource])&&!in_array($resource,['overview','settings'],true))$resource='overview';
if($_SERVER['REQUEST_METHOD']==='POST'){csrf_check();$action=(string)($_POST['action']??'');try{
 if($resource==='settings'&&$action==='save'){$st=db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');foreach(['site_title','home_heading','home_description','footer_text'] as $k)$st->execute([$k,trim((string)($_POST[$k]??''))]);flash('success','Mağaza ayarları kaydedildi.');}
 elseif(isset($meta[$resource])&&$action==='save'){$m=$meta[$resource];$id=(int)($_POST['id']??0);$data=[];foreach($m['fields'] as $field=>$label){$v=trim((string)($_POST[$field]??''));if($field==='price'){if(!is_numeric($v)||(float)$v<0)throw new RuntimeException('Geçerli fiyat girin.');$v=number_format((float)$v,2,'.','');}if($field==='sort_order')$v=(string)(int)$v;if($field==='category_id')$v=$v===''?null:(string)(int)$v;if($field==='slug'){$ascii=function_exists('iconv')?iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v):$v;$v=trim(preg_replace('/[^a-z0-9-]+/','-',strtolower($ascii?:$v)),'-');}$data[$field]=$v;}if($id){$set=implode(',',array_map(fn($k)=>$k.'=?',array_keys($data)));$vals=array_values($data);$vals[]=$id;db()->prepare('UPDATE '.$m['table'].' SET '.$set.' WHERE id=?')->execute($vals);}else{$keys=array_keys($data);db()->prepare('INSERT INTO '.$m['table'].'('.implode(',',$keys).') VALUES('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_values($data));}flash('success',$m['title'].' kaydedildi.');}
 elseif(isset($meta[$resource])&&$action==='delete'){$id=(int)($_POST['id']??0);if($id)db()->prepare('DELETE FROM '.$meta[$resource]['table'].' WHERE id=?')->execute([$id]);flash('success','Kayıt silindi.');}
 else throw new RuntimeException('Geçersiz işlem.');
}catch(Throwable $e){flash('error',$e->getMessage());}redirect(url('admin?section='.$resource));}
$edit=null;$rows=[];$categories=[];$settings=[];$stats=[];
if(isset($meta[$resource])){$m=$meta[$resource];$rows=db()->query('SELECT * FROM '.$m['table'].' ORDER BY id DESC LIMIT 250')->fetchAll();if($resource==='products')$categories=db()->query('SELECT id,name FROM categories ORDER BY name')->fetchAll();if(isset($_GET['edit'])){$st=db()->prepare('SELECT * FROM '.$m['table'].' WHERE id=?');$st->execute([(int)$_GET['edit']]);$edit=$st->fetch()?:null;}}
if($resource==='settings')foreach(db()->query('SELECT setting_key,setting_value FROM settings')->fetchAll() as $r)$settings[$r['setting_key']]=$r['setting_value'];
if($resource==='overview'){foreach(['Kullanıcı'=>'users','Kategori'=>'categories','Ürün'=>'products','Sipariş'=>'orders'] as $label=>$table)$stats[$label]=(int)db()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();$stats['Yayındaki ürün']=(int)db()->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();}
render('admin/manage',['title'=>$meta[$resource]['title']??($resource==='settings'?'Mağaza ayarları':'Genel bakış'),'resource'=>$resource,'meta'=>$meta,'rows'=>$rows,'edit'=>$edit,'categories'=>$categories,'settings'=>$settings,'stats'=>$stats]);
