-- Add a configurable menu type. Category dropdown entries are generated from active categories.
-- @add-column menus menu_type VARCHAR(30) NOT NULL DEFAULT 'link'
INSERT INTO menus(title,url,location,sort_order,status,menu_type)
SELECT 'Kategoriler','#','header',COALESCE((SELECT MAX(sort_order)+1 FROM menus WHERE location='header'),1),'active','categories'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menus WHERE location='header' AND menu_type='categories');
