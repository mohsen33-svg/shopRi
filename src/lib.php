<?php
declare(strict_types=1);

function env(string $k, $d = null) {
    static $f;
    if ($f === null) {
        $f = [];
        $p = __DIR__ . '/../.env';
        if (is_file($p)) {
            foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
                if ($l[0] === '#' || !str_contains($l, '=')) continue;
                [$a, $b] = explode('=', $l, 2);
                $f[trim($a)] = trim($b, "\"' ");
            }
        }
    }
    $v = getenv($k);
    return ($v !== false && $v !== '') ? $v : ($f[$k] ?? $d);
}

function db(): PDO {
    static $p;
    return $p ??= new PDO(
        'pgsql:host=' . env('DB_HOST') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_DATABASE') . ';sslmode=' . env('DB_SSLMODE', 'require'),
        env('DB_USERNAME'),
        env('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => true]
    );
}

function q(string $s, array $a = []): PDOStatement {
    $st = db()->prepare($s);
    $st->execute($a);
    return $st;
}

function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function tnd($n): string {
    return number_format((float)$n, 3, ',', ' ') . ' TND';
}

function go(string $u) {
    header('Location: ' . $u, true, 303);
    exit;
}

function slugify(string $s): string {
    $s = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $s)), '-'));
    return $s ?: 'item';
}

function st_load() {
    if (!env('APP_KEY')) throw new RuntimeException('APP_KEY manquant dans .env');
    $s = [];
    $c = $_COOKIE['st'] ?? '';
    if (substr_count($c, '.') === 1) {
        [$d, $m] = explode('.', $c);
        if (hash_equals(hash_hmac('sha256', $d, env('APP_KEY')), $m)) {
            $s = json_decode(base64_decode($d), true) ?: [];
        }
    }
    $s['csrf'] ??= bin2hex(random_bytes(16));
    $GLOBALS['S'] = $s;
}

function st_save() {
    if (headers_sent()) return;
    $d = base64_encode(json_encode($GLOBALS['S']));
    setcookie('st', $d . '.' . hash_hmac('sha256', $d, env('APP_KEY')), [
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    ]);
}

function csrf(): string {
    return '<input type="hidden" name="_t" value="' . e($GLOBALS['S']['csrf']) . '">';
}

function csrf_check() {
    if (!hash_equals($GLOBALS['S']['csrf'], (string)($_POST['_t'] ?? ''))) {
        http_response_code(419);
        exit('Session expirée, rechargez la page.');
    }
}

function flash(?string $m = null) {
    if ($m !== null) {
        $GLOBALS['S']['flash'] = $m;
        return '';
    }
    $f = $GLOBALS['S']['flash'] ?? '';
    unset($GLOBALS['S']['flash']);
    return $f ? '<div class="flash"><span>✓</span> <div>' . e($f) . '</div></div>' : '';
}

function admin() {
    if (empty($GLOBALS['S']['admin'])) go('/admin/login');
}

function img(?string $u, int $w = 600): string {
    if (!$u) return '';
    if (str_contains($u, 'res.cloudinary.com') || str_contains($u, '/upload/')) {
        return str_replace('/upload/', '/upload/f_auto,q_auto,w_' . $w . '/', $u);
    }
    return $u;
}

function upload_image(array $f): ?string {
    if (($f['error'] ?? 1) !== 0 || ($f['size'] ?? 0) > 5 * 1048576) {
        return null;
    }
    $mime = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($f['tmp_name']);
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($f['tmp_name']);
    }
    if (!$mime || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        return null;
    }

    $cloudName = env('CLOUDINARY_CLOUD_NAME');
    $apiKey = env('CLOUDINARY_API_KEY');
    $apiSecret = env('CLOUDINARY_API_SECRET');

    // Essayer Cloudinary si configuré et non par défaut
    if ($cloudName && $apiKey && $apiSecret && strtolower($cloudName) !== 'root' && function_exists('curl_init')) {
        $ts = time();
        $sig = sha1("folder=shop&timestamp={$ts}" . $apiSecret);
        $ch = curl_init('https://api.cloudinary.com/v1_1/' . $cloudName . '/image/upload');
        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($f['tmp_name'], $mime),
                'api_key' => $apiKey,
                'timestamp' => $ts,
                'folder' => 'shop',
                'signature' => $sig
            ]
        ]);
        $resStr = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($resStr && !$curlErr) {
            $r = json_decode((string)$resStr, true);
            if (!empty($r['secure_url'])) {
                return $r['secure_url'];
            }
        }
    }

    // Fallback stockage local dans public/uploads
    $uploadDir = __DIR__ . '/../public/uploads';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $ext = $extMap[$mime] ?? 'jpg';
    $filename = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . '/' . $filename;

    if (@move_uploaded_file($f['tmp_name'], $targetPath)) {
        return '/uploads/' . $filename;
    }

    return null;
}

function gouvernorats(): array {
    return ['Ariana','Béja','Ben Arous','Bizerte','Gabès','Gafsa','Jendouba','Kairouan','Kasserine','Kébili','Le Kef','Mahdia','La Manouba','Médenine','Monastir','Nabeul','Sfax','Sidi Bouzid','Siliana','Sousse','Tataouine','Tozeur','Tunis','Zaghouan'];
}

function socials(): string {
    $o = '';
    $map = ['FACEBOOK_URL' => 'Facebook', 'INSTAGRAM_URL' => 'Instagram', 'TIKTOK_URL' => 'TikTok'];
    $icons = ['Facebook' => 'f', 'Instagram' => '📷', 'TikTok' => '🎵'];
    foreach ($map as $k => $n) {
        if ($u = env($k)) {
            $o .= '<a class="soc" href="' . e($u) . '" target="_blank" rel="noopener" aria-label="' . $n . '" title="' . $n . '">' . $icons[$n] . '</a>';
        }
    }
    return $o;
}

function page(string $t, string $body, string $desc = '') {
    $n = e(env('APP_NAME', 'Boutique'));
    $c = array_sum($GLOBALS['S']['cart'] ?? []);
    $desc = e($desc ?: 'Boutique en ligne en Tunisie. Produits de qualité, livraison rapide, paiement cash à la livraison.');
    
    $cl = '';
    foreach (q("SELECT name,slug FROM categories ORDER BY name")->fetchAll() as $x) {
        $cl .= '<a href="/produits?cat=' . e($x['slug']) . '">' . e($x['name']) . '</a>';
    }

    $phone = e(env('SHOP_PHONE', ''));
    $email = e(env('SHOP_EMAIL', ''));
    $address = e(env('SHOP_ADDRESS', ''));

    echo '<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . e($t) . ' | ' . $n . '</title>
<meta name="description" content="' . $desc . '">
<meta property="og:title" content="' . e($t) . '">
<meta property="og:description" content="' . $desc . '">
<meta property="og:type" content="website">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 100 100%27%3E%3Ctext y=%27.9em%27 font-size=%2790%27%3E🛍️%3C/text%3E%3C/svg%3E">
<style>
:root {
  --primary: #2563eb;
  --primary-hover: #1d4ed8;
  --primary-light: #eff6ff;
  --accent: #f59e0b;
  --accent-hover: #d97706;
  --success: #10b981;
  --success-bg: #ecfdf5;
  --danger: #ef4444;
  --danger-bg: #fef2f2;
  --warning-bg: #fffbeb;
  --bg: #f8fafc;
  --surface: #ffffff;
  --text: #0f172a;
  --text-muted: #64748b;
  --border: #e2e8f0;
  --radius: 12px;
  --radius-sm: 8px;
  --shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 6px -1px rgba(0,0,0,0.02);
  --shadow-lg: 0 10px 30px -4px rgba(0,0,0,0.1);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: "Plus Jakarta Sans", system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; font-size: 15px; -webkit-font-smoothing: antialiased; }
a { color: inherit; text-decoration: none; }
.w { max-width: 1200px; margin: 0 auto; padding: 0 16px; }

/* Announcement Bar */
.top-bar { background: #0f172a; color: #94a3b8; font-size: 13px; font-weight: 500; padding: 8px 0; text-align: center; border-bottom: 1px solid #1e293b; }
.top-bar span { color: var(--accent); font-weight: 600; }

/* Sticky Navigation */
header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
header .w { display: flex; align-items: center; justify-content: space-between; height: 68px; gap: 16px; }
.logo { font-size: 1.35rem; font-weight: 800; color: var(--primary); display: flex; align-items: center; gap: 8px; letter-spacing: -0.5px; }
.logo-icon { background: var(--primary); color: #fff; width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; font-size: 18px; }

nav.desktop-nav { display: flex; align-items: center; gap: 24px; font-weight: 600; font-size: 14px; color: #334155; }
nav.desktop-nav a:hover { color: var(--primary); }

.dd { position: relative; cursor: pointer; }
.dd-toggle { display: flex; align-items: center; gap: 4px; padding: 6px 0; }
.dd-menu { display: none; position: absolute; top: 100%; left: 0; background: #fff; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px 0; min-width: 180px; box-shadow: var(--shadow-lg); z-index: 10; }
.dd:hover .dd-menu, .dd:focus-within .dd-menu { display: block; animation: fadeIn 0.15s ease; }
.dd-menu a { display: block; padding: 8px 16px; font-weight: 500; color: var(--text); }
.dd-menu a:hover { background: var(--primary-light); color: var(--primary); }

.header-actions { display: flex; align-items: center; gap: 12px; }
.soc { width: 32px; height: 32px; border-radius: 50%; background: #f1f5f9; color: #475569; display: grid; place-items: center; font-size: 13px; transition: all 0.2s; }
.soc:hover { background: var(--primary); color: #fff; transform: translateY(-1px); }

.cart-btn { background: var(--primary); color: #fff; border-radius: 99px; padding: 8px 18px; font-weight: 700; font-size: 14px; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25); transition: all 0.2s; }
.cart-btn:hover { background: var(--primary-hover); transform: translateY(-1px); }
.cart-count { background: #fff; color: var(--primary); font-size: 12px; padding: 2px 7px; border-radius: 99px; font-weight: 800; }

/* Mobile Navigation */
.mobile-toggle { display: none; background: transparent; border: none; font-size: 24px; color: var(--text); cursor: pointer; padding: 4px; }
.mobile-drawer { display: none; background: #fff; border-bottom: 1px solid var(--border); padding: 16px; box-shadow: var(--shadow); position: absolute; top: 68px; left: 0; right: 0; z-index: 99; }
.mobile-drawer.open { display: block; animation: slideDown 0.2s ease; }
.mobile-drawer nav { display: flex; flex-direction: column; gap: 10px; font-weight: 600; }
.mobile-drawer nav a { padding: 10px 14px; border-radius: var(--radius-sm); color: var(--text); background: #f8fafc; }
.mobile-drawer nav a:hover { background: var(--primary-light); color: var(--primary); }

/* Hero Section */
.hero { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #fff; padding: 50px 0 60px; position: relative; overflow: hidden; }
.hero::before { content: ""; position: absolute; top: -50%; right: -10%; width: 450px; height: 450px; background: radial-gradient(circle, rgba(37,99,235,0.3) 0%, rgba(0,0,0,0) 70%); border-radius: 50%; pointer-events: none; }
.hero h1 { font-size: clamp(1.8rem, 5vw, 2.8rem); font-weight: 800; line-height: 1.2; margin-bottom: 12px; letter-spacing: -0.5px; }
.hero p { font-size: clamp(1rem, 2.5vw, 1.15rem); color: #94a3b8; max-width: 600px; margin-bottom: 24px; }
.hero-btns { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }

/* Trust Strip */
.features-strip { background: #fff; border-bottom: 1px solid var(--border); padding: 20px 0; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
.features-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
.feature-item { display: flex; align-items: center; gap: 12px; padding: 8px; }
.feature-icon { width: 42px; height: 42px; border-radius: 10px; background: var(--primary-light); color: var(--primary); display: grid; place-items: center; font-size: 20px; flex-shrink: 0; }
.feature-text h4 { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 2px; }
.feature-text p { font-size: 12px; color: var(--text-muted); }

/* Section Titles */
.section-head { display: flex; align-items: center; justify-content: space-between; margin: 36px 0 20px; }
.section-head h2 { font-size: 1.4rem; font-weight: 800; color: var(--text); letter-spacing: -0.3px; position: relative; }
.section-head h2::after { content: ""; display: block; width: 40px; height: 3px; background: var(--primary); border-radius: 2px; margin-top: 4px; }

/* Grid Layout */
.grid { display: grid; gap: 20px; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }

/* Product Card */
.card { background: var(--surface); border-radius: var(--radius); overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow); display: flex; flex-direction: column; transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1); position: relative; }
.card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); border-color: #cbd5e1; }
.card .im { aspect-ratio: 1; background: #f1f5f9; display: block; position: relative; overflow: hidden; }
.card img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.4s ease; }
.card:hover img { transform: scale(1.06); }
.card .badges { position: absolute; top: 10px; left: 10px; display: flex; flex-direction: column; gap: 4px; z-index: 2; }

.bd { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 99px; background: var(--success-bg); color: #065f46; display: inline-block; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
.bd.r { background: var(--danger-bg); color: #991b1b; }
.bd.p { background: #fef3c7; color: #92400e; }

.card .b { padding: 14px; display: flex; flex-direction: column; gap: 8px; flex: 1; justify-content: space-between; }
.card .cat-tag { font-size: 12px; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
.card .title { font-weight: 700; color: var(--text); font-size: 15px; line-height: 1.3; text-decoration: none; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 40px; }
.card .title:hover { color: var(--primary); }

.pr { font-weight: 800; color: var(--primary); font-size: 1.15rem; display: flex; align-items: baseline; gap: 6px; }
.old { text-decoration: line-through; color: #94a3b8; font-weight: 500; font-size: 0.88rem; }

/* Buttons */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: var(--primary); color: #fff; border: 0; border-radius: var(--radius-sm); padding: 11px 20px; font-family: inherit; font-size: 14px; font-weight: 700; text-decoration: none; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 6px rgba(37,99,235,0.2); }
.btn:hover { background: var(--primary-hover); transform: translateY(-1px); }
.btn.o { background: var(--accent); color: #fff; box-shadow: 0 2px 6px rgba(245,158,11,0.2); }
.btn.o:hover { background: var(--accent-hover); }
.btn.g { background: #25d366; color: #fff; box-shadow: 0 2px 6px rgba(37,211,102,0.2); }
.btn.g:hover { background: #1da851; }
.btn.s { background: #f1f5f9; color: #334155; padding: 8px 14px; font-weight: 600; box-shadow: none; border: 1px solid var(--border); }
.btn.s:hover { background: #e2e8f0; color: #0f172a; }
.btn-full { width: 100%; }

/* Form Controls */
input, select, textarea { width: 100%; padding: 11px 14px; border: 1px solid #cbd5e1; border-radius: var(--radius-sm); font-family: inherit; font-size: 14px; background: #fff; color: var(--text); transition: border-color 0.2s, box-shadow 0.2s; outline: none; }
input:focus, select:focus, textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
label { display: block; margin: 12px 0 5px; font-weight: 600; font-size: 13px; color: #334155; }

/* Filter Box */
.filter-card { background: #fff; border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; margin-bottom: 24px; box-shadow: var(--shadow); }

/* Product Details Page */
.two { display: grid; gap: 30px; grid-template-columns: 1fr; margin-top: 20px; }
@media(min-width: 768px) { .two { grid-template-columns: 1fr 1fr; } }
.gallery-main { width: 100%; border-radius: var(--radius); overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow); background: #fff; }
.gallery-main img { width: 100%; height: auto; display: block; object-fit: cover; }
.gallery-thumbs { display: flex; gap: 10px; margin-top: 12px; overflow-x: auto; padding-bottom: 4px; }
.gallery-thumb { width: 70px; height: 70px; border-radius: var(--radius-sm); border: 2px solid var(--border); cursor: pointer; object-fit: cover; flex-shrink: 0; transition: all 0.2s; }
.gallery-thumb.active, .gallery-thumb:hover { border-color: var(--primary); }

/* Quantity Selector */
.qty-selector { display: inline-flex; align-items: center; border: 1px solid #cbd5e1; border-radius: var(--radius-sm); overflow: hidden; background: #fff; }
.qty-btn { width: 38px; height: 38px; background: #f8fafc; border: none; font-size: 16px; font-weight: 700; cursor: pointer; display: grid; place-items: center; color: #475569; }
.qty-btn:hover { background: #e2e8f0; }
.qty-input { width: 50px; height: 38px; border: none; text-align: center; font-weight: 700; font-size: 15px; -moz-appearance: textfield; }
.qty-input::-webkit-outer-spin-button, .qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

/* Table & Cart */
table { width: 100%; border-collapse: separate; border-spacing: 0; background: #fff; border-radius: var(--radius); overflow: hidden; border: 1px solid var(--border); }
th { background: #f8fafc; padding: 12px 16px; font-weight: 700; text-align: left; font-size: 13px; color: #475569; border-bottom: 1px solid var(--border); text-transform: uppercase; letter-spacing: 0.5px; }
td { padding: 14px 16px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; }
tr:last-child td { border-bottom: none; }
.ov { overflow-x: auto; border-radius: var(--radius); }

/* Cart Summary Box */
.cart-summary { background: #fff; border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; box-shadow: var(--shadow); margin-top: 20px; }

/* Messages & Flashes */
.flash { background: var(--success-bg); color: #065f46; border: 1px solid #a7f3d0; padding: 14px 18px; border-radius: var(--radius-sm); margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
.err { background: var(--danger-bg); color: #991b1b; border: 1px solid #fecaca; padding: 14px 18px; border-radius: var(--radius-sm); margin-bottom: 20px; font-weight: 600; }

/* Pagination */
.pg { display: flex; gap: 6px; margin-top: 24px; justify-content: center; }
.pg a { width: 38px; height: 38px; display: grid; place-items: center; background: #fff; border: 1px solid var(--border); border-radius: var(--radius-sm); font-weight: 600; text-decoration: none; color: var(--text); transition: all 0.2s; }
.pg a.on, .pg a:hover { background: var(--primary); color: #fff; border-color: var(--primary); }

/* Footer */
footer { background: #0f172a; color: #94a3b8; margin-top: 60px; padding: 50px 0 24px; border-top: 1px solid #1e293b; font-size: 14px; }
footer h3 { color: #fff; font-size: 16px; font-weight: 700; margin-bottom: 14px; }
footer a { color: #cbd5e1; text-decoration: none; transition: color 0.2s; }
footer a:hover { color: #fff; }
.footer-bottom { border-top: 1px solid #1e293b; margin-top: 40px; padding-top: 20px; text-align: center; font-size: 13px; color: #64748b; }

/* Keyframes */
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

/* Mobile Specific Tweaks */
@media (max-width: 767px) {
  nav.desktop-nav { display: none; }
  .mobile-toggle { display: block; }
  .grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
  .card .b { padding: 10px; gap: 4px; }
  .card .title { font-size: 13px; min-height: 36px; }
  .pr { font-size: 1rem; }
  .old { font-size: 0.78rem; }
  .btn { padding: 9px 14px; font-size: 13px; }
  .btn.s { padding: 5px 10px; font-size: 12px; }
  .hero { padding: 36px 0; }
  .hero h1 { font-size: 1.6rem; }
  .top-bar { font-size: 11px; }
  header .w { height: 60px; }
}
</style>
</head>
<body>
<div class="top-bar">
  <div class="w">🚚 <span>Livraison express sur toute la Tunisie</span> | 💵 Paiement Cash à la livraison</div>
</div>

<header>
  <div class="w">
    <a class="logo" href="/">
      <div class="logo-icon">🛍️</div>
      <span>' . $n . '</span>
    </a>
    <nav class="desktop-nav">
      <a href="/">Accueil</a>
      <a href="/produits">Produits</a>
      <div class="dd">
        <span class="dd-toggle">Catégories ▾</span>
        <div class="dd-menu">' . $cl . '</div>
      </div>
      <a href="/contact">Contact</a>
    </nav>
    <div class="header-actions">
      ' . socials() . '
      <a class="cart-btn" href="/panier">
        🛒 <span class="cart-text">Panier</span> <span class="cart-count">' . $c . '</span>
      </a>
      <button class="mobile-toggle" id="menuToggle" aria-label="Menu">☰</button>
    </div>
  </div>
  <div class="mobile-drawer" id="mobileDrawer">
    <nav>
      <a href="/">🏠 Accueil</a>
      <a href="/produits">📦 Tous les produits</a>
      <div style="padding: 8px 12px; font-weight:700; color:var(--text-muted); font-size:12px; text-transform:uppercase;">Catégories</div>
      ' . $cl . '
      <a href="/contact">📞 Contact & Support</a>
    </nav>
  </div>
</header>

<main class="w" style="margin-top: 24px; min-height: 60vh;">
' . flash() . $body . '
</main>

<footer>
  <div class="w two" style="margin-top:0;">
    <div>
      <div class="logo" style="color:#fff; margin-bottom:12px;">
        <div class="logo-icon">🛍️</div>
        <span>' . $n . '</span>
      </div>
      <p style="margin-bottom:12px;">Boutique en ligne spécialisée en Tunisie. Vente en ligne avec livraison à domicile rapide et paiement sécurisé à la livraison.</p>
      ' . ($address ? '<p style="margin-bottom:4px;">📍 ' . $address . '</p>' : '') . '
      ' . ($phone ? '<p style="margin-bottom:4px;">📞 ' . $phone . '</p>' : '') . '
      ' . ($email ? '<p style="margin-bottom:12px;">✉️ ' . $email . '</p>' : '') . '
      <div style="display:flex; gap:8px; margin-top:12px;">' . socials() . '</div>
    </div>
    <div style="display:flex; flex-direction:column; gap:8px;">
      <h3>Navigation Rapide</h3>
      <a href="/">Accueil</a>
      <a href="/produits">Tous nos produits</a>
      <a href="/contact">Nous contacter</a>
      <a href="/admin">Espace Administration</a>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="w">
      © ' . date('Y') . ' ' . $n . '. Tous droits réservés. Paiement à la livraison.
    </div>
  </div>
</footer>

<script>
document.addEventListener("DOMContentLoaded", function() {
  var toggle = document.getElementById("menuToggle");
  var drawer = document.getElementById("mobileDrawer");
  if (toggle && drawer) {
    toggle.addEventListener("click", function() {
      drawer.classList.toggle("open");
    });
  }
});
</script>
</body>
</html>';
}

function card(array $p): string {
    $now = $p['promo_price'] ?? $p['price'];
    $isPromo = $p['promo_price'] !== null;
    $inStock = $p['stock'] > 0;
    
    $h = '<article class="card">';
    $h .= '<a class="im" href="/produit/' . e($p['slug']) . '">';
    $h .= '<div class="badges">';
    if ($inStock) {
        $h .= '<span class="bd">En stock</span>';
    } else {
        $h .= '<span class="bd r">Rupture</span>';
    }
    if ($isPromo) {
        $h .= '<span class="bd p">Promo</span>';
    }
    $h .= '</div>';
    if (!empty($p['img'])) {
        $h .= '<img loading="lazy" width="400" height="400" alt="' . e($p['name']) . '" src="' . e(img($p['img'], 400)) . '">';
    } else {
        $h .= '<div style="display:grid;place-items:center;height:100%;color:#cbd5e1;font-size:2rem">🛍️</div>';
    }
    $h .= '</a>';
    $h .= '<div class="b">';
    if (!empty($p['cat'])) {
        $h .= '<div class="cat-tag">' . e($p['cat']) . '</div>';
    }
    $h .= '<a class="title" href="/produit/' . e($p['slug']) . '">' . e($p['name']) . '</a>';
    $h .= '<div class="pr">' . tnd($now) . ($isPromo ? '<span class="old">' . tnd($p['price']) . '</span>' : '') . '</div>';
    if ($inStock) {
        $h .= '<form method="post" action="/panier/add">' . csrf() . '<input type="hidden" name="id" value="' . $p['id'] . '"><button class="btn s btn-full">🛒 Ajouter au panier</button></form>';
    } else {
        $h .= '<button class="btn s btn-full" disabled style="opacity:0.6;cursor:not-allowed">Épuisé</button>';
    }
    $h .= '</div></article>';
    return $h;
}

const PSEL = "SELECT p.*,c.name cat,(SELECT url FROM product_images i WHERE i.product_id=p.id ORDER BY position,id LIMIT 1) img FROM products p LEFT JOIN categories c ON c.id=p.category_id";
