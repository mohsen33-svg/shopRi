<?php
// Inclus par public/index.php ($path, $M, $P disponibles)

$ST = [
    'nouvelle' => 'Nouvelle',
    'confirmee' => 'Confirmée',
    'en_preparation' => 'En préparation',
    'expediee' => 'Expédiée',
    'livree' => 'Livrée',
    'annulee' => 'Annulée'
];

$ST_BADGES = [
    'nouvelle' => 'background:#dbeafe; color:#1e40af;',
    'confirmee' => 'background:#dcfce7; color:#166534;',
    'en_preparation' => 'background:#fef3c7; color:#92400e;',
    'expediee' => 'background:#f3e8ff; color:#6b21a8;',
    'livree' => 'background:#ecfdf5; color:#047857;',
    'annulee' => 'background:#fee2e2; color:#991b1b;'
];

$activeTab = fn($p) => str_starts_with($_SERVER['REQUEST_URI'], $p) ? 'background:var(--primary); color:#fff; border-color:var(--primary);' : '';

$nav = '<div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:12px 16px; margin-bottom:24px; display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:12px; box-shadow:var(--shadow);">
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <a class="btn s" style="' . $activeTab('/admin') . '" href="/admin">📋 Commandes</a>
    <a class="btn s" style="' . $activeTab('/admin/produits') . '" href="/admin/produits">📦 Produits</a>
    <a class="btn s" style="' . $activeTab('/admin/categories') . '" href="/admin/categories">🏷️ Catégories</a>
  </div>
  <form method="post" action="/admin/logout" style="margin:0;">
    ' . csrf() . '
    <button class="btn s" style="color:var(--danger); border-color:#fecaca;">Déconnexion 🚪</button>
  </form>
</div>';

if ($path === '/admin/login') {
    $err = '';
    if ($M === 'POST') {
        $u = q("SELECT * FROM users WHERE email=? AND is_admin", [$P('email')])->fetch();
        usleep(200000);
        if ($u && password_verify($P('password'), $u['password'])) {
            $GLOBALS['S']['admin'] = (int)$u['id'];
            go('/admin');
        }
        $err = '<div class="err">Identifiants d\'administration invalides.</div>';
    }
    
    $loginForm = '<div style="max-width:400px; margin:40px auto; background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:28px; box-shadow:var(--shadow);">
      <h1 style="font-size:1.5rem; font-weight:800; margin-bottom:16px; text-align:center;">Administration</h1>
      ' . $err . '
      <form method="post">
        ' . csrf() . '
        <label style="margin-top:0;">Email admin</label>
        <input type="email" name="email" required placeholder="admin@example.com">
        
        <label>Mot de passe</label>
        <input type="password" name="password" required placeholder="••••••••">
        
        <button class="btn btn-full" style="margin-top:20px;">Se connecter</button>
      </form>
    </div>';
    
    page('Connexion Admin', $loginForm);
    return;
}

admin();

if ($path === '/admin/logout') {
    unset($GLOBALS['S']['admin']);
    go('/');
}

if ($path === '/admin') {
    $r = q("SELECT * FROM orders ORDER BY created_at DESC LIMIT 100")->fetchAll();
    $h = $nav . '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h1 style="font-size:1.6rem; font-weight:800;">Commandes Récentes</h1>
      <span style="color:var(--text-muted); font-size:13px;">' . count($r) . ' commande(s)</span>
    </div>
    <div class="ov">
      <table>
        <thead>
          <tr>
            <th>N° Commandes</th>
            <th>Client</th>
            <th>Gouvernorat</th>
            <th>Total</th>
            <th>Statut</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>';
    foreach ($r as $o) {
        $badgeStyle = $ST_BADGES[$o['status']] ?? '';
        $h .= '<tr>
          <td style="font-weight:700; color:var(--primary);">' . e($o['number']) . '</td>
          <td><b>' . e($o['full_name']) . '</b><br><span style="font-size:12px; color:var(--text-muted);">' . e($o['phone']) . '</span></td>
          <td>' . e($o['governorate']) . '</td>
          <td style="font-weight:700;">' . tnd($o['total']) . '</td>
          <td><span class="bd" style="' . $badgeStyle . '">' . e($ST[$o['status']] ?? $o['status']) . '</span></td>
          <td><a class="btn s" href="/admin/commandes/' . $o['id'] . '">Détails →</a></td>
        </tr>';
    }
    $h .= '</tbody></table></div>';
    page('Gestion des Commandes', $h);
    return;
}

if (preg_match('#^/admin/commandes/(\d+)$#', $path, $m)) {
    $o = q("SELECT * FROM orders WHERE id=?", [$m[1]])->fetch();
    if (!$o) {
        http_response_code(404);
        return;
    }
    if ($M === 'POST' && isset($ST[$P('status')])) {
        q("UPDATE orders SET status=? WHERE id=?", [$P('status'), $o['id']]);
        flash('Statut de la commande mis à jour avec succès.');
        go($path);
    }
    
    $l = '';
    foreach (q("SELECT * FROM order_items WHERE order_id=?", [$o['id']])->fetchAll() as $i) {
        $l .= '<div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--border);">
          <span>' . e($i['name']) . ' × <b>' . $i['qty'] . '</b></span>
          <span style="font-weight:700;">' . tnd($i['price'] * $i['qty']) . '</span>
        </div>';
    }
    
    $op = '';
    foreach ($ST as $k => $v) {
        $op .= '<option value="' . $k . '"' . ($o['status'] === $k ? ' selected' : '') . '>' . $v . '</option>';
    }

    $h = $nav . '<div style="max-width:700px; margin:0 auto; background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px; box-shadow:var(--shadow);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h1 style="font-size:1.5rem; font-weight:800; color:var(--primary);">Commande ' . e($o['number']) . '</h1>
        <span class="bd" style="' . ($ST_BADGES[$o['status']] ?? '') . ' font-size:13px; padding:6px 12px;">' . e($ST[$o['status']]) . '</span>
      </div>
      
      <div style="background:#f8fafc; border:1px solid var(--border); border-radius:var(--radius-sm); padding:16px; margin-bottom:20px; font-size:14px; line-height:1.6;">
        <div><b>Client :</b> ' . e($o['full_name']) . '</div>
        <div><b>Téléphone :</b> <a href="tel:' . e($o['phone']) . '" style="color:var(--primary); font-weight:700;">' . e($o['phone']) . '</a></div>
        <div><b>Adresse :</b> ' . e($o['address']) . ', ' . e($o['city']) . ', ' . e($o['governorate']) . '</div>
        ' . ($o['notes'] ? '<div style="margin-top:8px; color:var(--accent-hover);"><b>Notes :</b> ' . e($o['notes']) . '</div>' : '') . '
      </div>

      <h3 style="font-size:1.1rem; font-weight:700; margin-bottom:12px;">Articles commandés</h3>
      ' . $l . '
      <div style="display:flex; justify-content:space-between; font-weight:800; font-size:1.3rem; margin-top:16px; margin-bottom:24px; color:var(--text);">
        <span>Total :</span>
        <span style="color:var(--primary);">' . tnd($o['total']) . '</span>
      </div>

      <form method="post" style="border-top:1px solid var(--border); padding-top:20px;">
        ' . csrf() . '
        <label style="margin-top:0;">Modifier le statut de la commande</label>
        <div style="display:flex; gap:12px;">
          <select name="status" style="flex:1;">' . $op . '</select>
          <button class="btn">Mettre à jour</button>
        </div>
      </form>
    </div>';

    page('Commande ' . $o['number'], $h);
    return;
}

if ($path === '/admin/categories') {
    if ($M === 'POST') {
        if ($P('del')) {
            q("DELETE FROM categories WHERE id=?", [(int)$P('del')]);
            flash('Catégorie supprimée.');
        } elseif ($P('name')) {
            if ($P('id')) {
                q("UPDATE categories SET name=?, slug=? WHERE id=?", [$P('name'), slugify($P('name')), (int)$P('id')]);
                flash('Catégorie modifiée.');
            } else {
                q("INSERT INTO categories(name, slug) VALUES(?,?) ON CONFLICT DO NOTHING", [$P('name'), slugify($P('name'))]);
                flash('Catégorie ajoutée.');
            }
        }
        go($path);
    }

    $h = $nav . '<div style="max-width:600px; margin:0 auto;">
      <h1 style="font-size:1.6rem; font-weight:800; margin-bottom:16px;">Gestion des Catégories</h1>
      
      <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:20px; box-shadow:var(--shadow); margin-bottom:24px;">
        <h3 style="font-size:1.1rem; font-weight:700; margin-bottom:12px;">Ajouter une catégorie</h3>
        <form method="post" style="display:flex; gap:10px;">
          ' . csrf() . '
          <input name="name" required placeholder="Nom de la catégorie (ex: Électronique)">
          <button class="btn" style="white-space:nowrap;">+ Ajouter</button>
        </form>
      </div>

      <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:20px; box-shadow:var(--shadow);">
        <h3 style="font-size:1.1rem; font-weight:700; margin-bottom:12px;">Catégories existantes</h3>';
        
    $cats = q("SELECT * FROM categories ORDER BY name")->fetchAll();
    if (!$cats) {
        $h .= '<p style="color:var(--text-muted);">Aucune catégorie.</p>';
    } else {
        foreach ($cats as $c) {
            $h .= '<form method="post" style="display:flex; gap:8px; margin-bottom:10px;">
              ' . csrf() . '
              <input type="hidden" name="id" value="' . $c['id'] . '">
              <input name="name" value="' . e($c['name']) . '">
              <button class="btn s">Modifier</button>
              <button class="btn s" name="del" value="' . $c['id'] . '" onclick="return confirm(\'Supprimer cette catégorie ?\')" style="color:var(--danger); border-color:#fecaca;">Supprimer</button>
            </form>';
        }
    }

    $h .= '</div></div>';
    page('Catégories', $h);
    return;
}

if ($path === '/admin/produits') {
    $h = $nav . '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h1 style="font-size:1.6rem; font-weight:800;">Gestion des Produits</h1>
      <a class="btn" href="/admin/produits/new">+ Ajouter un Produit</a>
    </div>
    <div class="ov">
      <table>
        <thead>
          <tr>
            <th>Nom</th>
            <th>Prix</th>
            <th>Prix Promo</th>
            <th>Stock</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>';
    foreach (q("SELECT * FROM products ORDER BY id DESC")->fetchAll() as $p) {
        $h .= '<tr>
          <td><b>' . e($p['name']) . '</b></td>
          <td style="font-weight:700;">' . tnd($p['price']) . '</td>
          <td>' . ($p['promo_price'] !== null ? '<span style="color:var(--accent-hover); font-weight:700;">' . tnd($p['promo_price']) . '</span>' : '—') . '</td>
          <td>' . ($p['stock'] > 0 ? '<span class="bd">' . $p['stock'] . '</span>' : '<span class="bd r">Rupture</span>') . '</td>
          <td><a class="btn s" href="/admin/produits/' . $p['id'] . '">Modifier ✏️</a></td>
        </tr>';
    }
    $h .= '</tbody></table></div>';
    page('Produits', $h);
    return;
}

if (preg_match('#^/admin/produits/(new|\d+)$#', $path, $m)) {
    $id = $m[1] === 'new' ? 0 : (int)$m[1];
    $p = $id ? q("SELECT * FROM products WHERE id=?", [$id])->fetch() : [
        'name' => '',
        'category_id' => null,
        'price' => '',
        'promo_price' => null,
        'stock' => 0,
        'description' => '',
        'active' => true
    ];
    if ($id && !$p) {
        http_response_code(404);
        return;
    }

    if ($M === 'POST') {
        if ($P('delete') && $id) {
            q("DELETE FROM products WHERE id=?", [$id]);
            flash('Produit supprimé.');
            go('/admin/produits');
        }

        $pr = (float)str_replace(',', '.', $P('price'));
        $pm = $P('promo_price') === '' ? null : (float)str_replace(',', '.', $P('promo_price'));
        $stk = max(0, (int)$P('stock'));
        $cat = $P('category_id') ?: null;

        if ($P('name') === '' || $pr < 0 || ($pm !== null && ($pm < 0 || $pm >= $pr))) {
            flash('Données invalides : vérifiez le nom, le prix et assurez-vous que le prix promo est inférieur au prix normal.');
            go($path);
        }

        if ($id) {
            q("UPDATE products SET name=?, category_id=?, price=?, promo_price=?, stock=?, description=?, active=?, updated_at=now() WHERE id=?", [
                $P('name'), $cat, $pr, $pm, $stk, $P('description'), isset($_POST['active']) ? 'true' : 'false', $id
            ]);
        } else {
            $id = (int)q("INSERT INTO products(name, slug, category_id, price, promo_price, stock, description, active) VALUES(?,?,?,?,?,?,?,?) RETURNING id", [
                $P('name'), slugify($P('name')) . '-' . bin2hex(random_bytes(2)), $cat, $pr, $pm, $stk, $P('description'), isset($_POST['active']) ? 'true' : 'false'
            ])->fetchColumn();
        }

        foreach ((array)($_POST['del_img'] ?? []) as $d) {
            q("DELETE FROM product_images WHERE id=? AND product_id=?", [(int)$d, $id]);
        }

        if (!empty($_FILES['images']['name'][0])) {
            $successCount = 0;
            $failCount = 0;
            foreach ($_FILES['images']['name'] as $i => $name) {
                if (empty($name)) continue;
                $fileInfo = [
                    'name' => $_FILES['images']['name'][$i],
                    'type' => $_FILES['images']['type'][$i] ?? '',
                    'error' => $_FILES['images']['error'][$i],
                    'size' => $_FILES['images']['size'][$i],
                    'tmp_name' => $_FILES['images']['tmp_name'][$i]
                ];
                $u = upload_image($fileInfo);
                if ($u) {
                    q("INSERT INTO product_images(product_id, url, position) VALUES(?,?,?)", [$id, $u, $i]);
                    $successCount++;
                } else {
                    $failCount++;
                }
            }
            if ($failCount > 0) {
                flash("Produit enregistré, mais certaines images n'ont pas pu être téléchargées (Max 5Mo, formats acceptés : JPG, PNG, WebP).");
            }
        }

        if (!isset($GLOBALS['S']['flash'])) {
            flash('Produit enregistré avec succès.');
        }
        go('/admin/produits/' . $id);
    }

    $co = '<option value="">— Sans catégorie —</option>';
    foreach (q("SELECT id, name FROM categories ORDER BY name")->fetchAll() as $c) {
        $co .= '<option value="' . $c['id'] . '"' . ((string)$p['category_id'] === (string)$c['id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
    }

    $im = '';
    if ($id) {
        foreach (q("SELECT id, url FROM product_images WHERE product_id=?", [$id])->fetchAll() as $i) {
            $im .= '<label style="display:inline-block; width:100px; text-align:center; background:#f8fafc; border:1px solid var(--border); border-radius:var(--radius-sm); padding:6px; margin-right:8px; margin-bottom:8px;">
              <img src="' . e(img($i['url'], 150)) . '" style="width:80px; height:80px; object-fit:cover; border-radius:4px;"><br>
              <input type="checkbox" name="del_img[]" value="' . $i['id'] . '" style="width:auto; margin-top:4px;"> <span style="font-size:12px; color:var(--danger);">Supprimer</span>
            </label>';
        }
    }

    $h = $nav . '<div style="max-width:700px; margin:0 auto; background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px; box-shadow:var(--shadow);">
      <h1 style="font-size:1.6rem; font-weight:800; margin-bottom:20px;">' . ($id ? 'Modifier le produit' : 'Nouveau produit') . '</h1>
      
      <form method="post" enctype="multipart/form-data">
        ' . csrf() . '
        
        <label style="margin-top:0;">Nom du produit *</label>
        <input name="name" required value="' . e($p['name']) . '" placeholder="Ex: T-shirt en coton">
        
        <label>Catégorie</label>
        <select name="category_id">' . $co . '</select>
        
        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px;">
          <div>
            <label>Prix (TND) *</label>
            <input name="price" required value="' . e($p['price']) . '" placeholder="29.900">
          </div>
          <div>
            <label>Prix promo (TND)</label>
            <input name="promo_price" value="' . e($p['promo_price']) . '" placeholder="19.900">
          </div>
          <div>
            <label>Stock disponible *</label>
            <input type="number" name="stock" min="0" value="' . e($p['stock']) . '">
          </div>
        </div>
        
        <label>Description du produit</label>
        <textarea name="description" rows="5" placeholder="Description détaillée du produit...">' . e($p['description']) . '</textarea>
        
        <div style="margin:16px 0;">
          <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
            <input type="checkbox" name="active" style="width:auto;" ' . ($p['active'] ? 'checked' : '') . '>
            <span>Produit actif (visible sur la boutique)</span>
          </label>
        </div>
        
        <div style="border-top:1px solid var(--border); padding-top:16px; margin-top:16px;">
          <label style="margin-top:0;">Images du produit</label>
          <input type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp,image/gif" style="margin-bottom:12px;">
          ' . ($im ? '<div style="margin-bottom:16px;">' . $im . '</div>' : '') . '
        </div>
        
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:24px;">
          <button class="btn" style="padding:12px 24px; font-size:15px;">💾 Enregistrer le produit</button>
          ' . ($id ? '<button class="btn s" name="delete" value="1" onclick="return confirm(\'Supprimer définitivement ce produit ?\')" style="color:var(--danger); border-color:#fecaca;">Supprimer le produit</button>' : '') . '
        </div>
      </form>
    </div>';

    page('Éditeur de Produit', $h);
    return;
}

http_response_code(404);
