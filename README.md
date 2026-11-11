# E-pin Commerce

Native PHP and MySQL digital gaming product storefront. No Composer, SSH, Node, or command-line installation is required.

## Current foundation
- Browser-based first-run installer.
- Single Vendor / Multi Vendor installation choice.
- Seller-specific tables and seller product relation are created only in Multi Vendor mode.
- PDO database connection, password hashing, CSRF token, session hardening.
- Root/subdirectory-aware URL generation.
- Initial homepage categories, products, and site text are loaded from MySQL.
- Initial admin login and role-aware route guards.

## Requirements
PHP 8.1+ (8.2 recommended), PDO MySQL, MySQL 5.7+/8 or compatible MariaDB.

## Install
1. Upload repository contents to the desired web directory.
2. Create an empty MySQL database and database user in the hosting control panel.
3. Visit the site URL; the installer opens automatically.
4. Select Single Vendor or Multi Vendor and enter database/admin details.
5. Sign in at /login. Admin dashboard: /admin.

The web server must allow PHP to write config.php in the application root during installation. Remove write permission after installation if your hosting workflow permits.

## Important status
This is the initial foundation, not a finished commerce platform. Product CRUD, secure e-pin stock handling, checkout, order delivery, PayTR/iyzico payment flows, seller workflows, and admin CMS are not implemented yet. Payment integrations will not be marked complete until sandbox callbacks and signature validation are tested.
