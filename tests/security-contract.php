<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$boot = file_get_contents($root . '/app/bootstrap.php');
$admin = file_get_contents($root . '/app/admin.php');
$view = file_get_contents($root . '/app/views/admin/manage.php');
$start = strpos($boot, 'function absolute_url');
$end = strpos($boot, 'function e(', $start);
$absolute = substr($boot, $start, $end - $start);
if (!str_contains($absolute, "setting_key='site_url'")) throw new RuntimeException('Canonical site URL must be read from saved settings.');
if (str_contains($absolute, 'HTTP_HOST')) throw new RuntimeException('Absolute callback URLs must not trust the request Host header.');
if (!str_contains($admin, "'site_url','site_title'")) throw new RuntimeException('Canonical site URL must be editable in store settings.');
if (!str_contains($view, 'name="site_url"')) throw new RuntimeException('Canonical site URL input is missing.');
$adminSource = file_get_contents($root . '/app/admin.php');
if (!str_contains($adminSource, 'SELECT o.payment_status,o.status,oi.id,oi.quantity,p.delivery_type FROM orders o')) throw new RuntimeException('Manual delivery must lock and inspect the order status.');
if (!str_contains($adminSource, "in_array(" . '$item' . "['status'],['processing','partially_delivered'],true)")) throw new RuntimeException('Manual delivery must reject refunded, cancelled, expired and review orders.');
if (!str_contains($admin, 'seller_reverse_refunded_order((int)$r[\'order_id\'])')) throw new RuntimeException('Processed refunds must reverse seller ledger entries in the same transaction.');
$sessionCookie = substr($boot, strpos($boot, "session_set_cookie_params"), 260);
if (!str_contains($sessionCookie, "'samesite'=>\$secure?'None':'Lax'") || !str_contains($sessionCookie, "'secure'=>\$secure")) throw new RuntimeException('HTTPS payment returns must retain the browser session with a Secure SameSite=None cookie.');
$checkout = file_get_contents($root . '/app/views/store/checkout.php');
if (str_contains($checkout, 'ödeme bağlantısı henüz kurulmadığı')) throw new RuntimeException('Checkout must not claim that the configured payment integrations are unavailable.');
$index = file_get_contents($root . '/index.php');
if (!str_contains($index, '$cartQuantity+$qty')) throw new RuntimeException('Adding an item must validate the existing cart quantity plus the requested quantity against available stock.');
if (!str_contains($index, "if(\$price===false)throw new RuntimeException('Sepetinizde artık satışta olmayan bir ürün veya seçenek var.')")) throw new RuntimeException('Coupon calculation must reject unavailable or invalid cart items instead of silently omitting them.');
if (!str_contains($index, 'SELECT id FROM inventory_codes WHERE product_id=?') || !str_contains($index, 'ORDER BY id LIMIT ') || !str_contains($index, ' FOR UPDATE");')) throw new RuntimeException('Checkout must lock available inventory rows before reserving codes.');
if (!str_contains($index, "status='reserved',reserved_order_id=?,reserved_until=DATE_ADD(NOW(),INTERVAL 30 MINUTE)")) throw new RuntimeException('Checkout must reserve digital codes before payment.');
if (!str_contains($boot, "i.status='reserved' AND o.status='expired'")) throw new RuntimeException('Expired unpaid orders must release reserved digital codes.');
if (!str_contains($index, 'coupon_quote($pdo,(string)$_SESSION[\'coupon_code\'],$subtotal,true)')) throw new RuntimeException('Checkout must revalidate and lock the coupon before calculating the final order total.');
echo "Security contract checks passed.\n";

$recovery = file_get_contents($root . '/recover-admin.php');
if (!str_contains($recovery, 'hash_equals((string)$config[$key], $value)')) throw new RuntimeException('Admin recovery must verify the supplied database credentials against the installed config.');
if (!str_contains($recovery, "\$account['role'] !== 'admin'")) throw new RuntimeException('Admin recovery must never promote a non-admin account.');
if (!str_contains($recovery, 'password_hash($newPassword, PASSWORD_DEFAULT)')) throw new RuntimeException('Admin recovery must store a password hash.');
if (!str_contains($recovery, 'unlink(__FILE__)')) throw new RuntimeException('Admin recovery must remove its entry point after success.');
if (!str_contains($recovery, 'HTTPS') || !str_contains($recovery, 'strlen($newPassword) < 12')) throw new RuntimeException('Admin recovery must require HTTPS and a strong password.');

$ordersView = file_get_contents($root . '/app/views/admin/manage.php');
if (!str_contains($ordersView, 'foreach(($orderRows??[]) as $o)')) throw new RuntimeException('Orders view must safely handle a missing orderRows variable.');
if (!str_contains($ordersView, 'if(empty($orderRows))')) throw new RuntimeException('Orders view must show the empty state when there are no orders.');
if (!str_contains($adminSource, '$orderRows=[];$orderDetail=null;$orderDetailItems=[];')) throw new RuntimeException('Admin controller must initialize order data before rendering the orders view.');

if (!str_contains($adminSource, "if(isset(\$meta[\$resource])&&\$resource!=='backup')")) throw new RuntimeException('Backup page must not enter the generic resource-table query with an empty table name.');


$backupSource = file_get_contents($root . '/app/backup.php');
if (!str_contains($backupSource, 'function restore_database_backup(PDO $pdo')) throw new RuntimeException('Database restore entry point is missing.');
if (!str_contains($backupSource, "str_starts_with(\$sql, '-- E-pin database backup')")) throw new RuntimeException('Restore must accept only E-pin backup files.');
if (!str_contains($backupSource, "SHOW FULL TABLES")) throw new RuntimeException('Restore must refuse non-empty databases.');
if (!str_contains($backupSource, '$size > $maxBytes')) throw new RuntimeException('Restore must enforce an upload size limit.');
if (!str_contains($backupSource, 'Yedek dosyasında izin verilmeyen SQL komutu bulundu.')) throw new RuntimeException('Restore must reject SQL statements outside the export format.');
if (!str_contains($backupSource, 'isset($tablesCreated[$m[1]])')) throw new RuntimeException('Restore inserts must be restricted to tables created by the same backup.');
if (!str_contains($backupSource, 'E-pin restore cleanup failed')) throw new RuntimeException('Restore must attempt cleanup after a failed import.');
if (!str_contains($adminSource, 'if($resource===\'backup\'&&$action===\'restore\')')) throw new RuntimeException('Admin backup restore action is missing.');
if (!str_contains($adminSource, 'target_db_host') || !str_contains($adminSource, 'new PDO(\'mysql:host=\'')) throw new RuntimeException('Restore must connect to a separately supplied target database.');
if (!str_contains($view, 'enctype="multipart/form-data"') || !str_contains($view, 'name="backup_file"') || !str_contains($view, 'name="target_db_pass"')) throw new RuntimeException('Backup restore upload form is missing.');

$rootHtaccess = file_get_contents($root . '/.htaccess');
foreach (['app','storage','database','tests','ops','.github'] as $privatePath) {
    if (!str_contains($rootHtaccess, $privatePath)) throw new RuntimeException('Public web access must be denied for private project path: ' . $privatePath);
}
if (!str_contains($rootHtaccess, 'RewriteRule ^(README\\.md|\\.gitignore)$ - [F,L,NC]')) throw new RuntimeException('Repository metadata files must not be served publicly.');
if (!str_contains($rootHtaccess, 'RewriteRule (^|/)\\. - [F,L]')) throw new RuntimeException('Hidden dotfiles must not be served publicly.');

$markerCreatePos = strpos($recovery, "\$markerHandle = @fopen(\$marker, 'x')");
$commitPos = strpos($recovery, '$pdo->commit();');
if ($markerCreatePos === false || $commitPos === false || $markerCreatePos > $commitPos) throw new RuntimeException('Admin recovery must create an exclusive deny marker before committing the password change.');
if (!str_contains($recovery, "if (\$markerHandle === false)")) throw new RuntimeException('Admin recovery must fail closed when the deny marker cannot be created.');
if (!str_contains($recovery, "@chmod(\$marker, 0600)")) throw new RuntimeException('Admin recovery deny marker must use restrictive file permissions.');

$loginController = file_get_contents($root . '/index.php');
foreach (['login_is_rate_limited($pdo,$email,$ip)','login_record_failure($pdo,$email,$ip)','login_clear_failures($pdo,$email,$ip)',"header('Retry-After: 900')"] as $needle) {
    if (!str_contains($loginController, $needle)) throw new RuntimeException('Login route is missing brute-force protection: ' . $needle);
}
$bootstrap = file_get_contents($root . '/app/bootstrap.php');
foreach (['function login_is_rate_limited','function login_record_failure','function login_clear_failures','scope=1','scope=2',"hash_hmac('sha256'"] as $needle) {
    if (!str_contains($bootstrap, $needle)) throw new RuntimeException('Login rate-limit helper is missing: ' . $needle);
}
if (str_contains($loginController, 'HTTP_X_FORWARDED_FOR')) throw new RuntimeException('Login rate limiting must not trust spoofable forwarded IP headers.');

$acmeRulePos = strpos($rootHtaccess, 'well-known/acme-challenge/');
$dotfileRulePos = strpos($rootHtaccess, 'RewriteRule (^|/)');
if ($acmeRulePos === false || $dotfileRulePos === false || $acmeRulePos > $dotfileRulePos) throw new RuntimeException('HTTP-01 ACME challenge files must remain reachable before the general hidden-file deny rule.');

$storefrontCss = file_get_contents($root . '/assets/css/storefront.css');
$storefrontJs = file_get_contents($root . '/assets/js/storefront.js');
foreach (['position:absolute','top:calc(100% + 1px)','opacity:0','visibility:hidden','transform:translateY(-10px)','transform:translateY(0)','pointer-events:none','prefers-reduced-motion:reduce'] as $needle) {
    if (!str_contains($storefrontCss, $needle)) throw new RuntimeException('Mobile navigation overlay transition is missing: ' . $needle);
}
foreach (['document.addEventListener(\'click\'','e.key===\'Escape\'','setMenuOpen(false)'] as $needle) {
    if (!str_contains($storefrontJs, $needle)) throw new RuntimeException('Mobile navigation close behavior is missing: ' . $needle);
}


$adminCss = file_get_contents($root . '/assets/css/admin.css');
$adminJs = file_get_contents($root . '/assets/js/admin.js');
foreach (['transform:translateX(-102%)','transform:translateX(0)','visibility:hidden','pointer-events:none','prefers-reduced-motion:reduce'] as $needle) {
    if (!str_contains($adminCss, $needle)) throw new RuntimeException('Mobile admin navigation overlay styling is missing: ' . $needle);
}
foreach (['function setMenuOpen(open,restoreFocus)','Yönetim menüsünü kapat','window.addEventListener(\'resize\'','window.innerWidth>760','setMenuOpen(false,true)'] as $needle) {
    if (!str_contains($adminJs, $needle)) throw new RuntimeException('Mobile admin navigation behavior is missing: ' . $needle);
}


$mobileStoreCss = file_get_contents($root . '/assets/css/storefront.css');
foreach (['.product-detail{grid-template-columns:minmax(0,1fr)','aspect-ratio:16/10','grid-template-columns:repeat(2,minmax(0,1fr))','grid-template-columns:minmax(0,1fr)','grid-template-columns:68px minmax(0,1fr)','min-height:48px','@media(max-width:420px)','prefers-reduced-motion:reduce'] as $needle) {
    if (!str_contains($mobileStoreCss, $needle)) throw new RuntimeException('Mobile storefront layout refinement is missing: ' . $needle);
}


$mobileAdminCss = file_get_contents($root . '/assets/css/admin.css');
foreach (['overscroll-behavior-x:contain','-webkit-overflow-scrolling:touch','scrollbar-width:thin','min-height:44px','font-size:16px','min-height:46px','grid-template-columns:minmax(0,1fr)','@media(max-width:420px)'] as $needle) {
    if (!str_contains($mobileAdminCss, $needle)) throw new RuntimeException('Mobile admin table/form usability refinement is missing: ' . $needle);
}


$adminA11yCss = file_get_contents($root . '/assets/css/admin.css');
foreach ([':focus-visible','outline:3px solid #67e8d3','outline-offset:3px','scroll-margin-block:96px'] as $needle) {
    if (!str_contains($adminA11yCss, $needle)) throw new RuntimeException('Admin keyboard focus accessibility styling is missing: ' . $needle);
}
$adminA11yJs = file_get_contents($root . '/assets/js/admin.js');
foreach (['if(e.key!==\'Tab\')return','e.shiftKey','first.focus()','last.focus()','Seçtiğiniz slaytlar kalıcı olarak silinecek'] as $needle) {
    if (!str_contains($adminA11yJs, $needle)) throw new RuntimeException('Admin keyboard navigation or destructive confirmation behavior is missing: ' . $needle);
}
$adminManageView = file_get_contents($root . '/app/views/admin/manage.php');
foreach (['Satıcı başvurusunu reddetmek istediğinize emin misiniz?','Satıcı ödeme talebini reddetmek istediğinize emin misiniz?','İade talebini reddetmek istediğinize emin misiniz?'] as $needle) {
    if (!str_contains($adminManageView, $needle)) throw new RuntimeException('Admin destructive decision confirmation is missing: ' . $needle);
}


$adminLayout = file_get_contents($root . '/app/views/layout-admin.php');
foreach (["flash('error')","flash('success')",'class="alert alert-error" role="alert" aria-live="assertive"','class="alert alert-success" role="status" aria-live="polite"','e($m)','alert-dismiss'] as $needle) {
    if (!str_contains($adminLayout, $needle)) throw new RuntimeException('Accessible admin success/error notification rendering is missing: ' . $needle);
}
$adminFeedbackCss = file_get_contents($root . '/assets/css/admin.css');
foreach (['.admin-content .alert-error','.admin-content .alert-success','.admin-content .alert-dismiss:focus-visible','min-height:36px'] as $needle) {
    if (!str_contains($adminFeedbackCss, $needle)) throw new RuntimeException('Admin notification visual or keyboard styling is missing: ' . $needle);
}
$adminFeedbackJs = file_get_contents($root . '/assets/js/admin.js');
foreach ([".admin-content .alert-dismiss","alert.setAttribute('aria-hidden','true')",'alert.remove()'] as $needle) {
    if (!str_contains($adminFeedbackJs, $needle)) throw new RuntimeException('Admin notification dismiss behavior is missing: ' . $needle);
}


// Server-side authorization and tenant-isolation contracts for admin, seller and customer routes.
$frontController = file_get_contents($root . '/index.php');
$adminController = file_get_contents($root . '/app/admin.php');
foreach ([
    "if(\$path==='admin'||str_starts_with(\$path,'admin/')){require APP_ROOT.'/app/admin.php';exit;}",
    "if(\$path==='seller'||str_starts_with(\$path,'seller/')){",
    "require_login('seller');",
    "SELECT * FROM seller_profiles WHERE user_id=? AND status='approved' LIMIT 1",
    "WHERE seller_id=? AND id=?",
    "SELECT id FROM products WHERE id=? AND seller_id=?",
    "UPDATE products SET delivery_type='automatic' WHERE id=? AND seller_id=?",
    "WHERE p.seller_id=? ORDER BY o.id DESC LIMIT 300",
    "SELECT * FROM seller_profiles WHERE user_id=? LIMIT 1",
    "UPDATE seller_profiles SET shop_name=?,shop_slug=?,status='pending' WHERE user_id=?",
    "SELECT id,order_no,total,status,payment_status,created_at FROM orders WHERE user_id=?",
    "SELECT id,subject,message,status,admin_reply,created_at,updated_at FROM support_tickets WHERE user_id=?"
] as $needle) {
    if (!str_contains($frontController, $needle)) throw new RuntimeException('Seller/customer route authorization or tenant isolation is missing: ' . $needle);
}
if (!str_starts_with($adminController, "<?php\ndeclare(strict_types=1);\nrequire_login('admin');")) {
    throw new RuntimeException('Admin controller must enforce the admin role before processing any route.');
}
if (!str_contains($adminController, "if(\$_SERVER['REQUEST_METHOD']==='POST'){csrf_check();")) {
    throw new RuntimeException('Every admin state-changing request must pass CSRF validation before action dispatch.');
}
if (!str_contains($frontController, "if(\$_SERVER['REQUEST_METHOD']==='POST'){\n   csrf_check();\$action=(string)(\$_POST['action']??'');")) {
    throw new RuntimeException('Seller product mutations must require a valid CSRF token.');
}
