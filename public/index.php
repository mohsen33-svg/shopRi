<?php
declare(strict_types=1);

require __DIR__ . '/../src/lib.php';

ob_start();
st_load();
register_shutdown_function('st_save');

$path = '/' . trim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$M = $_SERVER['REQUEST_METHOD'];

if ($M === 'POST') csrf_check();

$P = fn(string $k) => trim((string)($_POST[$k] ?? ''));

try {
    if ($path === '/health') {
        echo 'ok';
        exit;
    }

    if ($path === '/') {
        $pop = q(PSEL . " WHERE p.active ORDER BY p.sales DESC, p.id DESC LIMIT 8")->fetchAll();
        $new = q(PSEL . " WHERE p.active ORDER BY p.created_at DESC LIMIT 8")->fetchAll();
        $promo = q(PSEL . " WHERE p.active AND p.promo_price IS NOT NULL ORDER BY p.id DESC LIMIT 8")->fetchAll();

        $sec = fn($title, $sub, $rows) => $rows ? '
        <div class="section-head">
          <div>
            <h2>' . $title . '</h2>
            <p style="color:var(--text-muted); font-size:13px; margin-top:2px;">' . $sub . '</p>
          </div>
          <a class="btn s" href="/produits">Tout voir →</a>
        </div>
        <div class="grid">' . implode('', array_map('card', $rows)) . '</div>
        ' : '';

        $wa = env('WHATSAPP_NUMBER');

        $hero = '</main>
        <section class="hero">
          <div class="w">
            <h1>Des produits exceptionnels, livrés chez vous en Tunisie</h1>
            <p>Commandez rapidement en ligne. Livraison express dans les 24 gouvernorats et paiement cash à la livraison.</p>
            <div class="hero-btns">
              <a class="btn o" href="/produits">🛍️ Explorer la boutique</a>
              ' . ($wa ? '<a class="btn g" target="_blank" rel="noopener" href="https://wa.me/' . e(preg_replace('/\D/', '', $wa)) . '">💬 Commander par WhatsApp</a>' : '') . '
            </div>
          </div>
        </section>
        
        <div class="features-strip">
          <div class="w features-grid">
            <div class="feature-item">
              <div class="feature-icon">🚚</div>
              <div class="feature-text">
                <h4>Livraison 24h - 48h</h4>
                <p>Partout en Tunisie</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon">💵</div>
              <div class="feature-text">
                <h4>Paiement à la livraison</h4>
                <p>Payez uniquement à la réception</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon">🛡️</div>
              <div class="feature-text">
                <h4>100% Qualité</h4>
                <p>Produits vérifiés & garantis</p>
              </div>
            </div>
            <div class="feature-item">
              <div class="feature-icon">📞</div>
              <div class="feature-text">
                <h4>Service Client</h4>
                <p>Assistance rapide 7j/7</p>
              </div>
            </div>
          </div>
        </div>
        <main class="w">';

        page('Accueil', $hero . $sec('Promotions Spéciales', 'Profitez de réductions exclusives sur une sélection de produits', $promo) . $sec('Produits Populaires', 'Les articles les plus demandés par nos clients', $pop) . $sec('Nouveautés', 'Derniers arrivages dans notre boutique', $new));
        exit;
    }

    if ($path === '/produits') {
        $w = ['p.active'];
        $a = [];
        if ($s = trim($_GET['q'] ?? '')) {
            $w[] = '(p.name ILIKE :q OR p.description ILIKE :q)';
            $a['q'] = "%$s%";
        }
        if ($c = $_GET['cat'] ?? '') {
            $w[] = 'c.slug = :c';
            $a['c'] = $c;
        }

        $ord = ['asc' => 'p.price ASC', 'desc' => 'p.price DESC'][$_GET['tri'] ?? ''] ?? 'p.id DESC';
        $pp = 12;
        $pg = max(1, (int)($_GET['page'] ?? 1));
        $W = implode(' AND ', $w);

        $tot = (int)q("SELECT count(*) FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE $W", $a)->fetchColumn();
        $rows = q(PSEL . " WHERE $W ORDER BY $ord LIMIT $pp OFFSET " . (($pg - 1) * $pp), $a)->fetchAll();

        $co = '<option value="">Toutes les catégories</option>';
        foreach (q("SELECT name,slug FROM categories ORDER BY name")->fetchAll() as $x) {
            $co .= '<option value="' . e($x['slug']) . '"' . ($c === $x['slug'] ? ' selected' : '') . '>' . e($x['name']) . '</option>';
        }

        $h = '<div style="margin-bottom:20px;">
          <h1 style="font-size:1.8rem; font-weight:800;">Nos Produits</h1>
          <p style="color:var(--text-muted); font-size:14px;">' . $tot . ' produit(s) disponible(s)</p>
        </div>
        
        <form class="filter-card" method="get" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
          <div>
            <label style="margin-top:0;">Recherche</label>
            <input name="q" placeholder="Rechercher un produit…" value="' . e($s) . '">
          </div>
          <div>
            <label style="margin-top:0;">Catégorie</label>
            <select name="cat">' . $co . '</select>
          </div>
          <div>
            <label style="margin-top:0;">Trier par</label>
            <select name="tri">
              <option value="">Plus récents</option>
              <option value="asc"' . (($_GET['tri'] ?? '') === 'asc' ? ' selected' : '') . '>Prix croissant</option>
              <option value="desc"' . (($_GET['tri'] ?? '') === 'desc' ? ' selected' : '') . '>Prix décroissant</option>
            </select>
          </div>
          <div>
            <button class="btn btn-full">🔍 Filtrer</button>
          </div>
        </form>
        
        <div class="grid">' . implode('', array_map('card', $rows)) . '</div>' . ($rows ? '' : '<div style="text-align:center; padding:40px; background:#fff; border-radius:var(--radius); margin-top:20px; color:var(--text-muted);">Aucun produit trouvé pour votre recherche.</div>') . '<div class="pg">';

        $totalPages = max(1, (int)ceil($tot / $pp));
        for ($i = 1; $i <= $totalPages; $i++) {
            $h .= '<a class="' . ($i === $pg ? 'on' : '') . '" href="?' . e(http_build_query(array_merge($_GET, ['page' => $i]))) . '">' . $i . '</a>';
        }
        $h .= '</div>';

        page('Produits', $h);
        exit;
    }

    if (preg_match('#^/produit/([a-z0-9-]+)$#', $path, $m)) {
        $p = q(PSEL . " WHERE p.slug=? AND p.active", [$m[1]])->fetch();
        if (!$p) {
            http_response_code(404);
            page('Introuvable', '<h1 style="text-align:center; margin-top:40px;">Produit introuvable</h1>');
            exit;
        }

        $im = q("SELECT url FROM product_images WHERE product_id=? ORDER BY position,id", [$p['id']])->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($im) && !empty($p['img'])) {
            $im = [$p['img']];
        }

        $mainImg = !empty($im[0]) ? img($im[0], 800) : '';

        $thumbsHtml = '';
        if (count($im) > 1) {
            $thumbsHtml .= '<div class="gallery-thumbs">';
            foreach ($im as $idx => $u) {
                $src = img($u, 200);
                $thumbsHtml .= '<img class="gallery-thumb ' . ($idx === 0 ? 'active' : '') . '" src="' . e($src) . '" alt="" onclick="document.getElementById(\'mainImage\').src=\'' . e(img($u, 800)) . '\'; document.querySelectorAll(\'.gallery-thumb\').forEach(t => t.classList.remove(\'active\')); this.classList.add(\'active\');">';
            }
            $thumbsHtml .= '</div>';
        }

        $now = $p['promo_price'] ?? $p['price'];
        $wa = env('WHATSAPP_NUMBER');

        $h = '<div class="two">
          <div>
            <div class="gallery-main">
              ' . ($mainImg ? '<img id="mainImage" alt="' . e($p['name']) . '" src="' . e($mainImg) . '">' : '<div style="aspect-ratio:1; display:grid; place-items:center; background:#f1f5f9; font-size:4rem;">🛍️</div>') . '
            </div>
            ' . $thumbsHtml . '
          </div>
          <div>
            <div style="font-size:13px; font-weight:700; color:var(--primary); text-transform:uppercase; margin-bottom:4px;">' . e($p['cat'] ?: 'Boutique') . '</div>
            <h1 style="font-size:1.8rem; font-weight:800; margin-bottom:12px; line-height:1.2;">' . e($p['name']) . '</h1>
            
            <div style="display:flex; align-items:baseline; gap:12px; margin-bottom:16px;">
              <span class="pr" style="font-size:1.8rem;">' . tnd($now) . '</span>' .
              ($p['promo_price'] !== null ? '<span class="old" style="font-size:1.2rem;">' . tnd($p['price']) . '</span> <span class="bd p">Économisez ' . tnd($p['price'] - $p['promo_price']) . '</span>' : '') . '
            </div>
            
            <div style="margin-bottom:20px;">
              ' . ($p['stock'] > 0 ? '<span class="bd">✓ En stock (' . $p['stock'] . ' disponibles)</span>' : '<span class="bd r">✕ Rupture de stock</span>') . '
            </div>
            
            <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:20px; margin-bottom:20px; box-shadow:var(--shadow);">';
            
        if ($p['stock'] > 0) {
            $h .= '<form method="post" action="/panier/add">
                ' . csrf() . '
                <input type="hidden" name="id" value="' . $p['id'] . '">
                <label style="margin-top:0;">Quantité</label>
                <div style="display:flex; gap:12px; align-items:center; margin-bottom:16px;">
                  <div class="qty-selector">
                    <button type="button" class="qty-btn" onclick="var i=document.getElementById(\'qtyInput\'); if(i.value>1) i.value--;">-</button>
                    <input type="number" id="qtyInput" name="qty" class="qty-input" value="1" min="1" max="' . $p['stock'] . '">
                    <button type="button" class="qty-btn" onclick="var i=document.getElementById(\'qtyInput\'); if(parseInt(i.value)<' . $p['stock'] . ') i.value++;">+</button>
                  </div>
                </div>
                <button class="btn btn-full" style="font-size:16px; padding:14px;">🛒 Ajouter au panier</button>
              </form>';
        }

        if ($wa) {
            $waMsg = rawurlencode('Bonjour, je souhaite commander le produit : ' . $p['name'] . ' (' . tnd($now) . ')');
            $h .= '<a class="btn g btn-full" style="margin-top:10px; font-size:15px; padding:13px;" target="_blank" rel="noopener" href="https://wa.me/' . e(preg_replace('/\D/', '', $wa)) . '?text=' . $waMsg . '">💬 Commander directement via WhatsApp</a>';
        }

        $h .= '</div>
            
            <div style="background:#f8fafc; border:1px solid var(--border); border-radius:var(--radius-sm); padding:16px; font-size:13px; color:var(--text-muted); display:grid; gap:8px;">
              <div>🚚 <b>Livraison :</b> 24h à 48h dans toute la Tunisie.</div>
              <div>💵 <b>Paiement :</b> Cash à la livraison (Vérification à la réception).</div>
              <div>📞 <b>Besoin d\'aide ?</b> Contactez notre service client.</div>
            </div>
            
            <h3 style="margin-top:24px; margin-bottom:8px; font-size:1.1rem; font-weight:700;">Description</h3>
            <div style="line-height:1.6; color:#334155;">' . nl2br(e($p['description'])) . '</div>
          </div>
        </div>';

        page($p['name'], $h, mb_substr(strip_tags($p['description']), 0, 150));
        exit;
    }

    if ($path === '/panier/add' && $M === 'POST') {
        $id = (int)$P('id');
        $n = max(1, (int)($_POST['qty'] ?? 1));
        $GLOBALS['S']['cart'][$id] = min(99, ($GLOBALS['S']['cart'][$id] ?? 0) + $n);
        flash('Produit ajouté à votre panier avec succès !');
        go('/panier');
    }

    if ($path === '/panier/update' && $M === 'POST') {
        $id = (int)$P('id');
        $n = (int)$P('qty');
        if ($n < 1) {
            unset($GLOBALS['S']['cart'][$id]);
            flash('Produit retiré du panier.');
        } else {
            $GLOBALS['S']['cart'][$id] = min(99, $n);
        }
        go('/panier');
    }

    $cart = function() {
        $c = $GLOBALS['S']['cart'] ?? [];
        if (!$c) return [[], 0.0];
        $in = implode(',', array_map('intval', array_keys($c)));
        $rows = q("SELECT id, name, price, promo_price, stock, (SELECT url FROM product_images WHERE product_id=products.id ORDER BY position,id LIMIT 1) img FROM products WHERE active AND id IN($in)")->fetchAll();
        $t = 0.0;
        foreach ($rows as &$r) {
            $r['qty'] = min($c[$r['id']], $r['stock']);
            $r['u'] = (float)($r['promo_price'] ?? $r['price']);
            $r['sub'] = $r['u'] * $r['qty'];
            $t += $r['sub'];
        }
        return [$rows, $t];
    };

    if ($path === '/panier') {
        [$rows, $t] = $cart();
        $h = '<h1 style="font-size:1.8rem; font-weight:800; margin-bottom:20px;">Mon Panier</h1>';
        if (!$rows) {
            $h .= '<div style="text-align:center; padding:50px 20px; background:#fff; border-radius:var(--radius); border:1px solid var(--border);">
              <div style="font-size:3rem; margin-bottom:12px;">🛒</div>
              <h2 style="font-size:1.3rem; margin-bottom:8px;">Votre panier est vide</h2>
              <p style="color:var(--text-muted); margin-bottom:20px;">Découvrez nos articles et ajoutez-les à votre panier.</p>
              <a class="btn" href="/produits">Découvrir les produits</a>
            </div>';
        } else {
            $h .= '<div class="two" style="grid-template-columns: 1fr 340px;">
              <div>
                <div class="ov">
                  <table>
                    <thead>
                      <tr>
                        <th>Article</th>
                        <th>Prix</th>
                        <th>Quantité</th>
                        <th>Sous-total</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>';
            foreach ($rows as $r) {
                $h .= '<tr>
                  <td>
                    <div style="display:flex; align-items:center; gap:12px;">
                      ' . ($r['img'] ? '<img src="' . e(img($r['img'], 100)) . '" style="width:50px; height:50px; object-fit:cover; border-radius:6px;">' : '') . '
                      <a href="/produit/' . $r['id'] . '" style="font-weight:700;">' . e($r['name']) . '</a>
                    </div>
                  </td>
                  <td style="font-weight:600;">' . tnd($r['u']) . '</td>
                  <td>
                    <form method="post" action="/panier/update" style="display:flex; gap:4px; align-items:center;">
                      ' . csrf() . '
                      <input type="hidden" name="id" value="' . $r['id'] . '">
                      <div class="qty-selector">
                        <input type="number" name="qty" value="' . $r['qty'] . '" min="0" max="' . $r['stock'] . '" class="qty-input" style="width:45px;">
                      </div>
                      <button class="btn s" style="padding:6px 10px;">OK</button>
                    </form>
                  </td>
                  <td style="font-weight:700; color:var(--primary);">' . tnd($r['sub']) . '</td>
                  <td>
                    <form method="post" action="/panier/update">
                      ' . csrf() . '
                      <input type="hidden" name="id" value="' . $r['id'] . '">
                      <input type="hidden" name="qty" value="0">
                      <button class="btn s" style="color:var(--danger); border-color:#fecaca;" title="Supprimer">✕</button>
                    </form>
                  </td>
                </tr>';
            }
            $h .= '</tbody></table></div></div>
            <div>
              <div class="cart-summary">
                <h3 style="font-size:1.1rem; font-weight:700; margin-bottom:16px;">Récapitulatif</h3>
                <div style="display:flex; justify-content:space-between; margin-bottom:10px; color:var(--text-muted);">
                  <span>Articles (' . count($rows) . ')</span>
                  <span>' . tnd($t) . '</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:16px; color:var(--text-muted);">
                  <span>Livraison</span>
                  <span style="color:var(--success); font-weight:600;">Paiement à la réception</span>
                </div>
                <div style="border-top:1px solid var(--border); padding-top:16px; margin-top:16px; display:flex; justify-content:space-between; font-weight:800; font-size:1.2rem; color:var(--text);">
                  <span>Total</span>
                  <span style="color:var(--primary);">' . tnd($t) . '</span>
                </div>
                <a class="btn o btn-full" style="margin-top:20px; padding:14px; font-size:16px;" href="/commande">Passer la commande →</a>
              </div>
            </div>
          </div>';
        }
        page('Panier', $h);
        exit;
    }

    if ($path === '/commande') {
        [$rows, $t] = $cart();
        if (!$rows) go('/panier');
        $err = '';

        if ($M === 'POST') {
            $f = ['full_name', 'phone', 'address', 'governorate', 'city'];
            $ok = true;
            foreach ($f as $k) {
                if ($P($k) === '') $ok = false;
            }
            if (!$ok || !preg_match('/^[0-9+ ]{8,15}$/', $P('phone')) || !in_array($P('governorate'), gouvernorats(), true)) {
                $err = 'Veuillez remplir correctement tous les champs obligatoires et vérifier le numéro de téléphone.';
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $tot = 0.0;
                    foreach ($rows as $r) {
                        if ($r['qty'] < 1 || !q("UPDATE products SET stock=stock-?,sales=sales+? WHERE id=? AND active AND stock>=?", [$r['qty'], $r['qty'], $r['id'], $r['qty']])->rowCount()) {
                            throw new RuntimeException('stock');
                        }
                        $tot += $r['sub'];
                    }
                    $no = 'CMD-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                    q("INSERT INTO orders(number,full_name,phone,address,governorate,city,notes,total) VALUES(?,?,?,?,?,?,?,?)", [
                        $no, $P('full_name'), $P('phone'), $P('address'), $P('governorate'), $P('city'), $P('notes'), $tot
                    ]);
                    $oid = (int)$pdo->lastInsertId('orders_id_seq');
                    foreach ($rows as $r) {
                        q("INSERT INTO order_items(order_id,product_id,name,price,qty) VALUES(?,?,?,?,?)", [$oid, $r['id'], $r['name'], $r['u'], $r['qty']]);
                    }
                    $pdo->commit();
                    $GLOBALS['S']['cart'] = [];
                    go('/confirmation/' . $no);
                } catch (RuntimeException $x) {
                    $pdo->rollBack();
                    $err = 'Stock insuffisant pour un produit de votre panier, merci de vérifier les quantités.';
                }
            }
        }

        $go = '';
        foreach (gouvernorats() as $g) {
            $go .= '<option' . ($P('governorate') === $g ? ' selected' : '') . '>' . e($g) . '</option>';
        }

        $sum = '';
        foreach ($rows as $r) {
            $sum .= '<div style="display:flex; justify-content:space-between; margin-bottom:8px; font-size:14px;">
              <span>' . e($r['name']) . ' × <b>' . $r['qty'] . '</b></span>
              <span style="font-weight:700;">' . tnd($r['sub']) . '</span>
            </div>';
        }

        $h = ($err ? '<div class="err">⚠️ ' . e($err) . '</div>' : '') . '
        <h1 style="font-size:1.8rem; font-weight:800; margin-bottom:20px;">Finaliser votre commande</h1>
        <div class="two" style="grid-template-columns: 1fr 360px;">
          <form method="post" style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px; box-shadow:var(--shadow);">
            ' . csrf() . '
            <h3 style="font-size:1.2rem; font-weight:700; margin-bottom:16px;">Informations de livraison</h3>
            
            <label style="margin-top:0;">Nom et prénom *</label>
            <input name="full_name" required placeholder="Ex: Mohamed Ben Ali" value="' . e($P('full_name')) . '">
            
            <label>Numéro de téléphone (Tunisie) *</label>
            <input name="phone" required type="tel" placeholder="Ex: 98 123 456" value="' . e($P('phone')) . '">
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
              <div>
                <label>Gouvernorat *</label>
                <select name="governorate" required>
                  <option value="">Choisir…</option>' . $go . '
                </select>
              </div>
              <div>
                <label>Ville *</label>
                <input name="city" required placeholder="Ex: Marsa" value="' . e($P('city')) . '">
              </div>
            </div>
            
            <label>Adresse exacte de livraison *</label>
            <textarea name="address" required rows="2" placeholder="Rue, numéro de maison, bâtiment...">' . e($P('address')) . '</textarea>
            
            <label>Remarques ou instructions (optionnel)</label>
            <textarea name="notes" rows="2" placeholder="Informations pour le livreur...">' . e($P('notes')) . '</textarea>
            
            <button class="btn o btn-full" style="margin-top:24px; padding:14px; font-size:16px;">✓ Confirmer ma commande (Paiement cash à la livraison)</button>
          </form>
          
          <div>
            <div class="cart-summary">
              <h3 style="font-size:1.1rem; font-weight:700; margin-bottom:16px;">Votre commande</h3>
              <div style="border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:12px;">
                ' . $sum . '
              </div>
              <div style="display:flex; justify-content:space-between; font-weight:800; font-size:1.25rem; color:var(--text);">
                <span>Total à payer</span>
                <span style="color:var(--primary);">' . tnd($t) . '</span>
              </div>
              <div style="background:var(--success-bg); color:#065f46; border-radius:var(--radius-sm); padding:12px; margin-top:16px; font-size:13px; text-align:center; font-weight:600;">
                💵 Paiement en espèces lors de la réception de votre colis.
              </div>
            </div>
          </div>
        </div>';

        page('Commande', $h);
        exit;
    }

    if (preg_match('#^/confirmation/(CMD-[0-9A-F-]+)$#', $path, $m)) {
        $o = q("SELECT * FROM orders WHERE number=?", [$m[1]])->fetch();
        if (!$o) {
            http_response_code(404);
            exit;
        }
        $it = q("SELECT * FROM order_items WHERE order_id=?", [$o['id']])->fetchAll();
        $l = '';
        foreach ($it as $i) {
            $l .= '<div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px dashed var(--border);">
              <span>' . e($i['name']) . ' × ' . $i['qty'] . '</span>
              <span style="font-weight:700;">' . tnd($i['price'] * $i['qty']) . '</span>
            </div>';
        }

        $h = '<div style="max-width:600px; margin:20px auto; background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:32px; box-shadow:var(--shadow); text-align:center;">
          <div style="width:64px; height:64px; background:var(--success-bg); color:var(--success); border-radius:50%; display:grid; place-items:center; font-size:32px; margin:0 auto 16px;">✓</div>
          <h1 style="font-size:1.8rem; font-weight:800; margin-bottom:8px;">Merci pour votre commande !</h1>
          <p style="color:var(--text-muted); margin-bottom:20px;">Votre commande a bien été enregistrée sous le numéro <b style="color:var(--primary);">' . e($o['number']) . '</b>.</p>
          
          <div style="background:#f8fafc; border:1px solid var(--border); border-radius:var(--radius-sm); padding:20px; text-align:left; margin-bottom:20px;">
            <h3 style="font-size:1rem; font-weight:700; margin-bottom:12px;">Détails du récapitulatif</h3>
            ' . $l . '
            <div style="display:flex; justify-content:space-between; font-weight:800; font-size:1.2rem; margin-top:12px; color:var(--text);">
              <span>Total à payer :</span>
              <span style="color:var(--primary);">' . tnd($o['total']) . '</span>
            </div>
          </div>
          
          <div style="text-align:left; font-size:14px; color:var(--text-muted); line-height:1.6; margin-bottom:24px;">
            📍 <b>Adresse de livraison :</b><br>
            ' . e($o['full_name']) . ' (' . e($o['phone']) . ')<br>
            ' . e($o['address']) . ', ' . e($o['city']) . ', ' . e($o['governorate']) . '
          </div>
          
          <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px;">📞 Notre équipe vous appellera sous peu pour valider l\'expédition de votre colis.</p>
          <a class="btn" href="/">Retour à l\'accueil</a>
        </div>';

        page('Confirmation', $h);
        exit;
    }

    if ($path === '/contact') {
        if ($M === 'POST' && $P('name') && filter_var($P('email'), FILTER_VALIDATE_EMAIL) && $P('body')) {
            q("INSERT INTO messages(name,email,body) VALUES(?,?,?)", [$P('name'), $P('email'), $P('body')]);
            flash('Merci ! Votre message a bien été envoyé. Nous vous répondrons dans les plus brefs délais.');
            go('/contact');
        }

        $phone = e(env('SHOP_PHONE', ''));
        $email = e(env('SHOP_EMAIL', ''));
        $address = e(env('SHOP_ADDRESS', ''));

        $h = '<h1 style="font-size:1.8rem; font-weight:800; margin-bottom:20px;">Contactez-nous</h1>
        <div class="two">
          <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px; box-shadow:var(--shadow);">
            <h3 style="font-size:1.2rem; font-weight:700; margin-bottom:16px;">Informations de contact</h3>
            <p style="margin-bottom:12px;">Nous sommes à votre disposition pour répondre à toutes vos questions.</p>
            ' . ($phone ? '<p style="margin-bottom:8px;">📞 <b>Téléphone :</b> ' . $phone . '</p>' : '') . '
            ' . ($email ? '<p style="margin-bottom:8px;">✉️ <b>Email :</b> ' . $email . '</p>' : '') . '
            ' . ($address ? '<p style="margin-bottom:16px;">📍 <b>Adresse :</b> ' . $address . '</p>' : '') . '
            <div style="margin-top:16px;">' . socials() . '</div>
          </div>
          
          <form method="post" style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px; box-shadow:var(--shadow);">
            ' . csrf() . '
            <h3 style="font-size:1.2rem; font-weight:700; margin-bottom:16px;">Envoyer un message</h3>
            <label style="margin-top:0;">Votre Nom</label>
            <input name="name" required placeholder="Nom et prénom">
            
            <label>Votre Email</label>
            <input type="email" name="email" required placeholder="exemple@email.com">
            
            <label>Votre Message</label>
            <textarea name="body" required rows="4" placeholder="Comment pouvons-nous vous aider ?"></textarea>
            
            <button class="btn btn-full" style="margin-top:20px;">Envoyer le message</button>
          </form>
        </div>';

        page('Contact', $h);
        exit;
    }

    if ($path === '/sitemap.xml') {
        header('Content-Type: application/xml');
        $b = rtrim((string)env('APP_URL', ''), '/');
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' . e($b) . '/</loc></url><url><loc>' . e($b) . '/produits</loc></url>';
        foreach (q("SELECT slug FROM products WHERE active")->fetchAll(PDO::FETCH_COLUMN) as $s) {
            echo '<url><loc>' . e($b) . '/produit/' . e($s) . '</loc></url>';
        }
        echo '</urlset>';
        exit;
    }

    if (str_starts_with($path, '/admin')) {
        require __DIR__ . '/../src/admin.php';
        exit;
    }

    http_response_code(404);
    page('Page introuvable', '<h1 style="text-align:center; margin-top:40px;">Page introuvable (404)</h1>');
} catch (Throwable $x) {
    error_log((string)$x);
    ob_clean();
    http_response_code(500);
    echo 'Erreur serveur.';
}
