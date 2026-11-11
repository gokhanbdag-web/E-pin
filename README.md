# E-pin Commerce

Native PHP + MySQL digital gaming product storefront and marketplace. The application is designed for ordinary shared hosting: no Composer, SSH, Node, or command-line installation is required.

## Implemented modules
- Browser-based first-run installer with Single Vendor / Multi Vendor choice.
- Root and subdirectory-aware routing and installation paths.
- Customer registration/login, profile and password management, order history, support tickets.
- Product and category management, dynamic menus/pages, store settings and responsive storefront.
- Cart, checkout, order creation, automatic stock reservation and reservation expiry.
- Encrypted digital code inventory, bulk stock import, automatic and manual delivery.
- PayTR and iyzico payment initialization, provider-side callback verification and amount matching.
- Safe unpaid order cancellation and late-payment manual review.
- Customer refund requests and admin review/recording workflow.
- Multi Vendor seller applications, seller products/orders, commissions, ledger and withdrawal requests.
- PHP syntax check workflow.

## Requirements
- PHP 8.1+ (PHP 8.2+ recommended).
- PDO MySQL and OpenSSL.
- PHP cURL for PayTR / iyzico connections.
- MySQL 5.7+ / 8+ or compatible MariaDB.
- HTTPS is strongly recommended, and required for production payment processing.

## Install on shared hosting
1. Upload the repository contents to the target web directory (root or subdirectory).
2. Create an empty MySQL database and database user in the hosting control panel.
3. Visit the application URL. The browser installer opens automatically.
4. Choose Single Vendor or Multi Vendor, then enter database and administrator details.
5. Sign in at `/login`; manage the store at `/admin`.

The installer needs permission to create `config.php` and the `storage` directory. Keep `config.php` private and remove unnecessary write permissions after installation.

## Payment and refund status
PayTR / iyzico flows are implemented at application level, including callback verification and amount checks, but they must still be tested with merchant sandbox credentials before production use. Refund requests are currently an internal workflow: an administrator records completion only after the refund has actually been completed in the provider dashboard. Direct refund API automation is not yet implemented.

## Verification limits
GitHub Actions runs PHP syntax checks. They do not replace installation tests on real shared hosting, database integration tests, security testing, or live/sandbox payment tests. Do not treat the repository as production-certified until those checks have been completed.


## Discount coupons
- Admin can create fixed-amount or percentage coupons, set minimum cart totals, maximum discounts, activation/expiry dates and global usage limits.
- Coupons are validated again inside the checkout transaction; the discount is saved on the order and allocated across line items for marketplace seller commission calculations.
- Cancelling an unpaid order releases its coupon usage. Expired reserved-stock orders also release coupon usage during bootstrap cleanup.


### Ürün görselleri
- Ürün formundan JPG, PNG, WEBP ve GIF dosyaları doğrudan yüklenebilir (en fazla 5 MB).
- Dosyalar rastgele adlarla `uploads/products/` altında saklanır; SVG ve çalıştırılabilir dosyalar kabul edilmez.
