-- Make the cart and administrator shortcut explicit, reorderable menu records.
INSERT INTO menus(title,url,location,sort_order,status,menu_type)
SELECT 'Sepet','#','header',COALESCE((SELECT MAX(sort_order)+1 FROM menus WHERE location='header'),1),'active','cart'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menus WHERE location='header' AND menu_type='cart');
INSERT INTO menus(title,url,location,sort_order,status,menu_type)
SELECT 'Yönetim','#','header',COALESCE((SELECT MAX(sort_order)+1 FROM menus WHERE location='header'),1),'active','admin'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menus WHERE location='header' AND menu_type='admin');
