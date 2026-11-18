-- Paykwik denomination card artwork
UPDATE products
SET image = CASE CAST(paykwik_eur_amount AS UNSIGNED)
 WHEN 10 THEN 'assets/images/paykwik-10.svg'
 WHEN 20 THEN 'assets/images/paykwik-20.svg'
 WHEN 50 THEN 'assets/images/paykwik-50.svg'
 WHEN 100 THEN 'assets/images/paykwik-100.svg'
 WHEN 250 THEN 'assets/images/paykwik-250.svg'
 WHEN 500 THEN 'assets/images/paykwik-500.svg'
 WHEN 1000 THEN 'assets/images/paykwik-1000.svg'
 ELSE image
END
WHERE paykwik_enabled=1
 AND paykwik_eur_amount IN (10,20,50,100,250,500,1000)
 AND (image IS NULL OR image='');
