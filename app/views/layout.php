<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? '') ?></title>
    <?php $storefrontCss = APP_ROOT . '/assets/css/storefront.css'; ?>
    <link rel="stylesheet" href="<?= e(url('assets/css/storefront.css')) ?>?v=<?= is_file($storefrontCss) ? (int) filemtime($storefrontCss) : '1' ?>">
</head>
<body class="storefront-body">
<?php if (!empty($actionMenus)): ?>
    <div class="storefront-utility-bar">
    <nav class="storefront-quick-actions" aria-label="Hesap ve sepet kısayolları">
        <?php foreach ($actionMenus as $action): ?>
            <?php if (($action['menu_type'] ?? '') === 'account'): ?>
                <?php if (!empty($_SESSION['user'])): ?>
                <a class="quick-action quick-account" href="<?= e(url('account')) ?>" aria-label="Hesabım">
                    <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M20 21a8 8 0 0 0-16 0M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z"/>
                    </svg>
                </a>
                <?php endif; ?>
            <?php elseif (($action['menu_type'] ?? '') === 'cart'): ?>
                <a class="quick-action quick-cart" href="<?= e(url('cart')) ?>" aria-label="Sepet, <?= (int) array_sum($_SESSION['cart'] ?? []) ?> ürün">
                    <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M3 3h2l2.2 11.2a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 2-1.6L22 7H6M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"/>
                    </svg>
                    <b><?= (int) array_sum($_SESSION['cart'] ?? []) ?></b>
                </a>
            <?php endif; ?>

        <?php endforeach; ?>
            <?php if (!empty($_SESSION['user'])): ?>
            <form class="quick-logout-form" method="post" action="<?= e(url('logout')) ?>">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button class="quick-action quick-logout" type="submit" aria-label="Çıkış yap" title="Çıkış yap">
                    <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M10 17l5-5-5-5M15 12H3M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>
                    </svg>
                </button>
            </form>
        <?php else: ?>
            <a class="quick-action quick-login" href="<?= e(url('login')) ?>" aria-label="Giriş yap" title="Giriş yap">
                <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M14 17l5-5-5-5M19 12H7M12 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6"/>
                </svg>
            </a>
        <?php endif; ?>
    </nav>
    </div>
<?php endif; ?>

<header class="storefront-header">
    <a class="brand<?= !empty($settings['site_logo'])?' brand-has-logo':'' ?>" href="<?= e(url()) ?>" aria-label="<?= e($settings['site_title'] ?? 'Ana sayfa') ?>"><?php if(!empty($settings['site_logo'])): ?><img class="storefront-logo" src="<?= e(url($settings['site_logo'])) ?>" alt="<?= e($settings['site_title'] ?? 'Mağaza logosu') ?>" style="width:<?= max(80,min(220,(int)($settings['site_logo_width']??180))) ?>px"><?php else: ?><?= e($settings['site_title'] ?? 'E-pin') ?><?php endif; ?></a>

    <button class="storefront-menu-toggle" type="button" aria-label="Menüyü aç" aria-expanded="false" aria-controls="storefront-navigation">
        <span></span><span></span><span></span>
    </button>

    <nav id="storefront-navigation" class="storefront-navigation" aria-label="Ana menü">
        <?php foreach (($menus ?? []) as $menu): ?>
            <?php $menuType = $menu['menu_type'] ?? 'link'; ?>
            <?php if ($menuType === 'categories'): ?>
                <details class="nav-category-menu">
                    <summary><?= e($menu['title']) ?></summary>
                    <div class="nav-category-dropdown">
                        <?php foreach (($categories ?? []) as $c): ?>
                            <a href="<?= e(url('category/' . rawurlencode($c['slug']))) ?>"><?= e($c['name']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php elseif ($menuType === 'cart'): ?>
                <a class="nav-link nav-cart-link" href="<?= e(url('cart')) ?>"><?= e($menu['title']) ?> <span>(<?= array_sum($_SESSION['cart'] ?? []) ?>)</span></a>
            <?php elseif ($menuType === 'admin'): ?>
                <?php if (($_SESSION['user']['role'] ?? '') === 'admin'): ?>
                    <a class="nav-link" href="<?= e(url('admin')) ?>"><?= e($menu['title']) ?></a>
                <?php endif; ?>
            <?php elseif ($menuType === 'account'): ?>
                <?php if (!empty($_SESSION['user'])): ?>
                    <a class="nav-link" href="<?= e(url('account')) ?>"><?= e($menu['title']) ?></a>
                <?php else: ?>
                    <a class="nav-link" href="<?= e(url('login')) ?>">Giriş</a>
                <?php endif; ?>
            <?php else: ?>
                <?php $href = preg_match('~^https?://~i', $menu['url']) ? $menu['url'] : url($menu['url']); ?>
                <a class="nav-link" href="<?= e($href) ?>"><?= e($menu['title']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if (!empty($_SESSION['user'])): ?>
            <?php if (($_SESSION['user']['role'] ?? '') === 'seller'): ?>
                <a class="nav-link" href="<?= e(url('seller')) ?>">Satıcı paneli</a>
            <?php elseif (($_SESSION['user']['role'] ?? '') !== 'admin' && config('business_mode') === 'multi_vendor'): ?>
                <a class="nav-link" href="<?= e(url('seller/apply')) ?>">Satıcı ol</a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>

    <form class="header-search" method="get" action="<?= e(url('search')) ?>" role="search">
        <label class="visually-hidden" for="storefront-search">Oyun, ürün veya kategori ara</label>
        <input id="storefront-search" type="search" name="q" value="<?= e($searchTerm ?? '') ?>" minlength="2" maxlength="80" placeholder="Oyun, ürün veya kategori ara" aria-label="Ürün ara">
        <button type="submit" aria-label="Ara">
            <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg>
            <span>Ara</span>
        </button>
    </form>
</header>

<main class="container">
    <?php if ($m = flash('error')): ?>
        <div class="alert"><?= e($m) ?></div>
    <?php endif; ?>
    <?php if ($m = flash('success')): ?>
        <div class="success"><?= e($m) ?></div>
    <?php endif; ?>
    <?php require APP_ROOT . '/app/views/' . $view . '.php'; ?>
</main>

<footer class="footer">
    <div class="footer-inner">
        <span><?= e($settings['footer_text'] ?? '') ?></span>
        <nav class="footer-links" aria-label="Alt bilgi bağlantıları">
            <?php foreach (($footerMenus ?? []) as $menu): ?>
                <?php $footerHref = preg_match('~^https?://~i', $menu['url']) ? $menu['url'] : url($menu['url']); ?>
                <a href="<?= e($footerHref) ?>"><?= e($menu['title']) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</footer>

<script src="<?= e(url('assets/js/storefront.js')) ?>?v=<?= is_file(APP_ROOT . '/assets/js/storefront.js') ? (int) filemtime(APP_ROOT . '/assets/js/storefront.js') : '1' ?>" defer></script>
</body>
</html>
