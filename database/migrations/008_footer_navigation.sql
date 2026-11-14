-- Put informational pages in the footer navigation; the menu manager remains the source of truth.
UPDATE menus SET location='footer',sort_order=1 WHERE url='page/sss' AND title='Sıkça Sorulanlar';
UPDATE menus SET location='footer',sort_order=2 WHERE url='page/teslimat-iade' AND title='Teslimat & İade';
UPDATE menus SET location='footer',sort_order=3 WHERE url='page/iletisim' AND title='İletişim';
INSERT INTO menus(title,url,location,sort_order,status,menu_type)
SELECT 'Sıkça Sorulanlar','page/sss','footer',1,'active','link' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menus WHERE location='footer' AND url='page/sss');
INSERT INTO menus(title,url,location,sort_order,status,menu_type)
SELECT 'Teslimat & İade','page/teslimat-iade','footer',2,'active','link' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menus WHERE location='footer' AND url='page/teslimat-iade');
INSERT INTO menus(title,url,location,sort_order,status,menu_type)
SELECT 'İletişim','page/iletisim','footer',3,'active','link' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM menus WHERE location='footer' AND url='page/iletisim');
