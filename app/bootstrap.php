<?php
declare(strict_types=1);
define('APP_ROOT',dirname(__DIR__));
$GLOBALS['app_config']=is_file(APP_ROOT.'/config.php')?(require APP_ROOT.'/config.php'):[];
if(session_status()!==PHP_SESSION_ACTIVE){$secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>$secure]);session_start();}
function config(string $key,mixed $default=null):mixed{return $GLOBALS['app_config'][$key]??$default;}
function db():PDO{static $pdo=null;if($pdo instanceof PDO)return $pdo;if(!config('db_name'))throw new RuntimeException('Veritabanı yapılandırılmamış.');$pdo=new PDO('mysql:host='.config('db_host').';dbname='.config('db_name').';charset=utf8mb4',(string)config('db_user'),(string)config('db_pass'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);return $pdo;}
function base_url():string{$p=str_replace('\\\\','/',dirname($_SERVER['SCRIPT_NAME']??'/'));$p=rtrim($p,'/');return $p===''||$p==='.'?'':$p;}
function url(string $path=''):string{return base_url().'/'.ltrim($path,'/');}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function redirect(string $to):never{header('Location: '.$to);exit;}
function csrf_token():string{if(empty($_SESSION['_token']))$_SESSION['_token']=bin2hex(random_bytes(32));return $_SESSION['_token'];}
function csrf_check():void{if(!hash_equals(csrf_token(),(string)($_POST['_token']??''))){http_response_code(419);exit('Güvenlik doğrulaması başarısız.');}}
function flash(string $key,?string $message=null):?string{if($message!==null){$_SESSION['_flash'][$key]=$message;return null;}$v=$_SESSION['_flash'][$key]??null;unset($_SESSION['_flash'][$key]);return $v;}
function require_login(?string $role=null):void{$u=$_SESSION['user']??null;if(!$u)redirect(url('login'));if($role!==null&&($u['role']??'')!==$role){http_response_code(403);exit('Bu alana erişim yetkiniz yok.');}}
function render(string $view,array $data=[]):void{if(!isset($data['settings'])){$data['settings']=[];if(is_file(APP_ROOT.'/config.php'))foreach(db()->query('SELECT setting_key,setting_value FROM settings')->fetchAll() as $r)$data['settings'][$r['setting_key']]=$r['setting_value'];}if(!isset($data['categories'])&&is_file(APP_ROOT.'/config.php'))$data['categories']=db()->query("SELECT id,name,slug FROM categories WHERE status='active' ORDER BY sort_order,name")->fetchAll();if(!isset($data['menus'])&&is_file(APP_ROOT.'/config.php'))$data['menus']=db()->query("SELECT title,url FROM menus WHERE status='active' AND location='header' ORDER BY sort_order,id")->fetchAll();extract($data,EXTR_SKIP);require APP_ROOT.'/app/views/layout.php';}
