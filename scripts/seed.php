<?php
// Données de démonstration + compte admin (ADMIN_EMAIL / ADMIN_PASSWORD depuis .env)
require __DIR__.'/../src/lib.php';
$pw=env('ADMIN_PASSWORD')?:bin2hex(random_bytes(6));
q("INSERT INTO users(name,email,password,is_admin) VALUES('Admin',?,?,true) ON CONFLICT(email) DO UPDATE SET password=EXCLUDED.password,is_admin=true",[env('ADMIN_EMAIL','admin@example.com'),password_hash($pw,PASSWORD_DEFAULT)]);
foreach(['Vêtements','Accessoires','Maison','Beauté'] as $c)q("INSERT INTO categories(name,slug) VALUES(?,?) ON CONFLICT DO NOTHING",[$c,slugify($c)]);
$ids=q("SELECT slug,id FROM categories")->fetchAll(PDO::FETCH_KEY_PAIR);$k=array_values($ids);
for($i=1;$i<=12;$i++){$pr=15+$i*7;q("INSERT INTO products(category_id,name,slug,description,price,promo_price,stock,sales) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(slug) DO NOTHING",[$k[$i%4],"Produit démo $i","produit-demo-$i","Description du produit de démonstration $i.",$pr,$i%3===0?round($pr*0.8,3):null,$i%5===0?0:20,$i*3]);}
echo "Seed OK. Admin: ".env('ADMIN_EMAIL','admin@example.com')." / mot de passe: $pw\n";
