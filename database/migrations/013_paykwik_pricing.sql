-- Paykwik EUR pricing and catalogue
-- @add-column products paykwik_eur_amount DECIMAL(10,2) NULL
-- @add-column products paykwik_margin_percent DECIMAL(7,3) NOT NULL DEFAULT 20.000
-- @add-column products paykwik_enabled TINYINT(1) NOT NULL DEFAULT 0
CREATE TABLE IF NOT EXISTS paykwik_exchange_rates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 currency CHAR(3) NOT NULL DEFAULT 'EUR',
 rate DECIMAL(12,6) NOT NULL,
 rate_type VARCHAR(24) NOT NULL DEFAULT 'ForexSelling',
 rate_date DATE NOT NULL,
 fetched_at DATETIME NOT NULL,
 source_url VARCHAR(500) NOT NULL,
 UNIQUE KEY uq_paykwik_rate_currency_date(currency,rate_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO categories(name,slug,description,sort_order,status)
SELECT 'Paykwik Kodları','paykwik-kodlari','Euro tutarına göre Paykwik dijital kod seçenekleri.',90,'active'
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE slug='paykwik-kodlari');
INSERT INTO products(category_id,name,slug,short_description,description,price,image,delivery_type,status,sort_order,paykwik_eur_amount,paykwik_margin_percent,paykwik_enabled)
SELECT c.id,CONCAT('Paykwik ',CAST(d.amount AS UNSIGNED),' Euro'),CONCAT('paykwik-',CAST(d.amount AS UNSIGNED)),
 CONCAT('Paykwik ',d.amount,' Euro dijital kod talebi. Sipariş ve teslimat detayları için müşteri temsilcimiz sizinle iletişime geçer.'),
 ROUND(d.amount*55.3446*1.20,2),NULL,'manual','active',d.sort_order,d.amount,20.000,1
FROM categories c
JOIN (
 SELECT 10.00 AS amount,1 AS sort_order UNION ALL SELECT 20.00,2 UNION ALL SELECT 50.00,3 UNION ALL
 SELECT 100.00,4 UNION ALL SELECT 250.00,5 UNION ALL SELECT 500.00,6 UNION ALL SELECT 1000.00,7
) d
WHERE c.slug='paykwik-kodlari'
AND NOT EXISTS (SELECT 1 FROM products p WHERE p.slug=CONCAT('paykwik-',CAST(d.amount AS UNSIGNED)));
INSERT INTO paykwik_exchange_rates(currency,rate,rate_type,rate_date,fetched_at,source_url)
VALUES('EUR',55.344600,'ForexSelling','2026-11-18',NOW(),'https://www.tcmb.gov.tr/kurlar/today.xml')
ON DUPLICATE KEY UPDATE rate=VALUES(rate),rate_type=VALUES(rate_type),fetched_at=VALUES(fetched_at),source_url=VALUES(source_url);
