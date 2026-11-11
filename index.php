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
$settings=[];foreach(db()->query('SELECT setting_key,setting_value FROM settings')->fetchAll() as $r)$settings[$r['setting_key']]=$r['setting_value'];
$categories=db()->query("SELECT id,name,slug FROM categories WHERE status='active' ORDER BY sort_order,name")->fetchAll();
$products=db()->query("SELECT id,name,slug,short_description,price,image FROM products WHERE status='active' ORDER BY sort_order,id DESC LIMIT 12")->fetchAll();
render('store/home',['title'=>$settings['site_title']??'E-pin mağazası','settings'=>$settings,'categories'=>$categories,'products'=>$products]);
