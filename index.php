<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
if(!is_file(APP_ROOT.'/config.php')){require APP_ROOT.'/install/index.php';exit;}
$path=trim(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/','/');
$base=trim(str_replace('\\\\','/',dirname($_SERVER['SCRIPT_NAME']??'/')),'/');
if($base!==''&&str_starts_with($path,$base))$path=trim(substr($path,strlen($base)),'/');
$path=$path?:'home';
if($path==='logout'){$_SESSION=[];session_destroy();redirect(url('login'));}
if($path==='login'&&$_SERVER['REQUEST_METHOD']==='POST'){csrf_check();$email=trim((string)($_POST['email']??''));$st=db()->prepare('SELECT id,name,email,password_hash,role,status FROM users WHERE email=? LIMIT 1');$st->execute([$email]);$u=$st->fetch();if($u&&$u['status']==='active'&&password_verify((string)($_POST['password']??''),$u['password_hash'])){session_regenerate_id(true);$_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']];redirect(url($u['role']==='admin'?'admin':'account'));}flash('error','Giriş bilgileri doğrulanamadı.');redirect(url('login'));}
if($path==='login'){render('auth/login',['title'=>'Giriş yap']);exit;}
if($path==='admin'||str_starts_with($path,'admin/')){require APP_ROOT.'/app/admin.php';exit;}
if($path==='seller'||str_starts_with($path,'seller/')){if(config('business_mode')!=='multi_vendor'){http_response_code(404);exit('Sayfa bulunamadı');}require_login('seller');render('account/dashboard',['title'=>'Satıcı paneli']);exit;}
if($path==='account'){require_login();render('account/dashboard',['title'=>'Hesabım']);exit;}
if(str_starts_with($path,'page/')){$slug=substr($path,5);$st=db()->prepare("SELECT title,content FROM pages WHERE slug=? AND status='active'");$st->execute([$slug]);$page=$st->fetch();if(!$page){http_response_code(404);$page=['title'=>'Sayfa bulunamadı','content'=>''];}render('store/page',['title'=>$page['title'],'page'=>$page]);exit;}
if(str_starts_with($path,'category/')){$slug=rawurldecode(substr($path,9));$st=db()->prepare("SELECT id,name,slug,description FROM categories WHERE slug=? AND status='active' LIMIT 1");$st->execute([$slug]);$category=$st->fetch();if(!$category){http_response_code(404);render('store/not-found',['title'=>'Kategori bulunamadı']);exit;}$st=db()->prepare("SELECT id,name,slug,short_description,price,image FROM products WHERE category_id=? AND status='active' ORDER BY sort_order,id DESC");$st->execute([(int)$category['id']]);$products=$st->fetchAll();render('store/category',['title'=>$category['name'],'category'=>$category,'products'=>$products]);exit;}
if(str_starts_with($path,'product/')){$slug=rawurldecode(substr($path,8));$st=db()->prepare("SELECT p.*,c.name AS category_name,c.slug AS category_slug FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.slug=? AND p.status='active' LIMIT 1");$st->execute([$slug]);$product=$st->fetch();if(!$product){http_response_code(404);render('store/not-found',['title'=>'Ürün bulunamadı']);exit;}$related=[];if(!empty($product['category_id'])){$st=db()->prepare("SELECT id,name,slug,short_description,price,image FROM products WHERE category_id=? AND id<>? AND status='active' ORDER BY sort_order,id DESC LIMIT 4");$st->execute([(int)$product['category_id'],(int)$product['id']]);$related=$st->fetchAll();}render('store/product',['title'=>$product['name'],'product'=>$product,'related'=>$related]);exit;}
$settings=[];foreach(db()->query('SELECT setting_key,setting_value FROM settings')->fetchAll() as $r)$settings[$r['setting_key']]=$r['setting_value'];
$categories=db()->query("SELECT id,name,slug FROM categories WHERE status='active' ORDER BY sort_order,name")->fetchAll();
$products=db()->query("SELECT id,name,slug,short_description,price,image FROM products WHERE status='active' ORDER BY sort_order,id DESC LIMIT 12")->fetchAll();
render('store/home',['title'=>$settings['site_title']??'E-pin mağazası','settings'=>$settings,'categories'=>$categories,'products'=>$products]);
