<?php

declare(strict_types=1);

// Usage : php bin/seed-demo.php — données de démonstration (environnement local uniquement).
// Passe par StockService pour que soldes, CMUP et journal soient cohérents.

use App\Core\Database;
use App\Core\Env;
use App\Core\Session;
use App\Services\StockService;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Env::load($root . '/.env');

if (Env::get('APP_ENV') === 'production') {
    fwrite(STDERR, "Refusé en production.\n");
    exit(1);
}
$pdo = Database::pdo();
if ((int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn() > 0) {
    echo "Des articles existent déjà : rien à faire.\n";
    exit(0);
}
$adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
if ($adminId === 0) {
    fwrite(STDERR, "Créez d'abord un administrateur (bin/create-admin.php).\n");
    exit(1);
}
$_SESSION = [];
Session::set('user_id', $adminId);

$pdo->exec("INSERT INTO sites (code, name, city) VALUES ('LUB', 'Lubumbashi', 'Lubumbashi'), ('KOL', 'Kolwezi', 'Kolwezi')");
$pdo->exec("INSERT INTO warehouses (site_id, code, name) VALUES
    ((SELECT id FROM sites WHERE code='LUB'), 'LUB-CEN', 'Entrepôt central'),
    ((SELECT id FROM sites WHERE code='LUB'), 'LUB-DOU', 'Sous douane'),
    ((SELECT id FROM sites WHERE code='KOL'), 'KOL-MAG', 'Magasin Kolwezi')");
$pdo->exec("INSERT INTO categories (name) VALUES ('Consommables'), ('Pièces détachées'), ('EPI'), ('Lubrifiants')");
$pdo->exec("INSERT INTO exchange_rates (currency_code, rate, valid_from) VALUES
    ('EUR', 1.08, CURDATE() - INTERVAL 60 DAY), ('CNY', 0.14, CURDATE() - INTERVAL 60 DAY),
    ('ZAR', 0.055, CURDATE() - INTERVAL 60 DAY), ('CDF', 0.00036, CURDATE() - INTERVAL 60 DAY)");

$unit = fn (string $c) => (int) $pdo->query("SELECT id FROM units WHERE code = '$c'")->fetchColumn();
$cat = fn (string $n) => (int) $pdo->query("SELECT id FROM categories WHERE name = '$n'")->fetchColumn();
$wh = fn (string $c) => (int) $pdo->query("SELECT id FROM warehouses WHERE code = '$c'")->fetchColumn();

$items = [
    ['GAN-NIT-L', 'Gants nitrile taille L', 'EPI', 'CTN', 20, '4015.19'],
    ['CAS-CHA-B', 'Casque de chantier blanc', 'EPI', 'PCE', 30, '6506.10'],
    ['HUI-15W40', 'Huile moteur 15W40', 'Lubrifiants', 'L', 400, '2710.19'],
    ['GRA-EP2', 'Graisse EP2 cartouche', 'Lubrifiants', 'PCE', 50, '2710.19'],
    ['FIL-HUI-01', 'Filtre à huile CAT 1R-0739', 'Pièces détachées', 'PCE', 10, '8421.23'],
    ['FIL-AIR-02', 'Filtre à air primaire', 'Pièces détachées', 'PCE', 8, '8421.31'],
    ['CIM-425', 'Ciment CPJ 42.5', 'Consommables', 'SAC', 200, '2523.29'],
    ['CAB-ACI-16', 'Câble acier 16 mm', 'Consommables', 'M', 100, '7312.10'],
];
$ins = $pdo->prepare('INSERT INTO items (sku, name, category_id, family_id, unit_id, min_stock, hs_code)
    VALUES (?,?,?,(SELECT id FROM item_families WHERE code = "AUT"),?,?,?)');
foreach ($items as [$sku, $name, $c, $u, $min, $hs]) {
    $ins->execute([$sku, $name, $cat($c), $unit($u), $min, $hs]);
}
$item = fn (string $sku) => (int) $pdo->query("SELECT id FROM items WHERE sku = '$sku'")->fetchColumn();

$stock = new StockService();
$day = fn (int $ago) => date('Y-m-d H:i:s', strtotime("-$ago day 10:00"));
$stock->receive($item('GAN-NIT-L'), $wh('LUB-CEN'), 60, 18.5, 'USD', 1, $day(28), 'Stock initial');
$stock->receive($item('CAS-CHA-B'), $wh('LUB-CEN'), 80, 48, 'CNY', 0.14, $day(27), 'Import Chine');
$stock->receive($item('HUI-15W40'), $wh('LUB-CEN'), 1200, 2.9, 'USD', 1, $day(25));
$stock->receive($item('HUI-15W40'), $wh('LUB-CEN'), 800, 3.3, 'USD', 1, $day(12), 'Nouveau fournisseur');
$stock->receive($item('FIL-HUI-01'), $wh('LUB-DOU'), 40, 24, 'EUR', 1.08, $day(20));
$stock->receive($item('CIM-425'), $wh('KOL-MAG'), 500, 165, 'ZAR', 0.055, $day(18));
$stock->receive($item('GRA-EP2'), $wh('LUB-CEN'), 60, 4.2, 'USD', 1, $day(15));
$stock->transfer($item('HUI-15W40'), $wh('LUB-CEN'), $wh('KOL-MAG'), 600, $day(10), 'Approvisionnement Kolwezi');
$stock->transfer($item('FIL-HUI-01'), $wh('LUB-DOU'), $wh('LUB-CEN'), 40, $day(9), 'Dédouané');
$stock->issue($item('GAN-NIT-L'), $wh('LUB-CEN'), 45, $day(6), 'Chantier A');
$stock->issue($item('HUI-15W40'), $wh('KOL-MAG'), 450, $day(4), 'Vidanges flotte');
$stock->issue($item('FIL-HUI-01'), $wh('LUB-CEN'), 33, $day(3));
$stock->issue($item('CIM-425'), $wh('KOL-MAG'), 340, $day(2), 'Dalle atelier');
$stock->adjust($item('GRA-EP2'), $wh('LUB-CEN'), 47, $day(1), 'Inventaire tournant');
$stock->issue($item('CAS-CHA-B'), $wh('LUB-CEN'), 12, date('Y-m-d H:i:s'), 'Nouveaux arrivants');

echo "Données de démonstration créées.\n";
