<?php

declare(strict_types=1);

/*
 * Réinitialisation complète d'ETHAN LM : tout est effacé SAUF les comptes administrateurs.
 *
 *   php bin/reset.php                         simulation : affiche ce qui serait supprimé, ne touche à rien
 *   php bin/reset.php --execute               réinitialise (confirmation à taper)
 *
 * Options :
 *   --keep-referentials   garde clients, sites de livraison, fournisseurs, articles, catégories,
 *                         banques, agences en douane et entrepôts (seules les opérations sont effacées)
 *   --keep-files          ne supprime pas les fichiers joints de storage/uploads
 *   --env=chemin          utilise un autre fichier .env (ex. base de test)
 *   --force-production    autorise l'exécution quand APP_ENV=production
 *
 * Toujours conservé : comptes de rôle « admin » (et leurs sites), sites, devises et taux de change, unités,
 * familles d'articles, types et règles de documents, table des migrations.
 * Les compteurs de références (sequences) et les identifiants auto-incrémentés repartent de 1.
 *
 * FAITES UNE SAUVEGARDE AVANT : mysqldump -u UTILISATEUR -p BASE > sauvegarde.sql
 */

use App\Core\Database;
use App\Core\Env;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$opts = getopt('', ['execute', 'keep-referentials', 'keep-files', 'env:', 'force-production']);
Env::load($opts['env'] ?? $root . '/.env');
$execute = isset($opts['execute']);
$keepRef = isset($opts['keep-referentials']);
$keepFiles = isset($opts['keep-files']);

// Tables conservées telles quelles (configuration indispensable au fonctionnement)
const KEEP = ['migrations', 'sites', 'currencies', 'exchange_rates', 'units', 'item_families', 'document_types', 'document_rules'];
// Opérations et historique : toujours vidés
const OPERATIONS = [
    'activity_log', 'attachments', 'order_events', 'sequences',
    'customer_orders', 'customer_order_lines', 'supplier_quotes', 'supplier_quote_lines',
    'purchase_orders', 'purchase_order_lines', 'import_licences', 'import_licence_lines', 'licence_imports',
    'inspections', 'inspection_lines', 'deliveries', 'delivery_lines', 'delivery_line_avs',
    'shipment_documents', 'invoices', 'payments', 'stock_levels', 'stock_movements',
];
// Référentiels : vidés sauf --keep-referentials
const REFERENTIALS = ['customers', 'delivery_sites', 'suppliers', 'items', 'categories', 'banks', 'bank_branches', 'customs_agencies', 'warehouses'];
// Traités ligne par ligne : on garde les administrateurs
const USERS = ['users', 'user_sites'];

$pdo = Database::pdo();
$db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$env = (string) Env::get('APP_ENV', 'local');
$count = fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();

$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$wipe = array_values(array_intersect($existing, array_merge(OPERATIONS, $keepRef ? [] : REFERENTIALS)));
$known = array_merge(KEEP, OPERATIONS, REFERENTIALS, USERS);
$unknown = array_values(array_diff($existing, $known));

$admins = $pdo->query("SELECT id, email FROM users WHERE role = 'admin' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$others = $pdo->query("SELECT email, role FROM users WHERE role <> 'admin' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$uploads = $root . '/storage/uploads';
$files = is_dir($uploads) ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST)) : [];
$fileCount = count(array_filter($files, fn ($f) => $f->isFile() && $f->getFilename() !== '.gitkeep'));

echo "\nETHAN LM — réinitialisation" . ($execute ? '' : ' (SIMULATION, rien ne sera modifié)') . "\n";
echo str_repeat('─', 60) . "\n";
echo "Base : $db · environnement : $env\n\n";
echo "Comptes CONSERVÉS (admin) :\n";
foreach ($admins as $a) {
    echo "  ✔ {$a['email']}\n";
}
if (!$admins) {
    fwrite(STDERR, "\nAucun compte administrateur : arrêt (vous ne pourriez plus vous connecter).\nCréez-en un : php bin/create-admin.php email \"Nom\"\n");
    exit(1);
}
echo "\nComptes SUPPRIMÉS : " . (count($others) ?: 'aucun') . "\n";
foreach ($others as $u) {
    echo "  ✘ {$u['email']} ({$u['role']})\n";
}
echo "\nTables VIDÉES :\n";
$total = 0;
foreach ($wipe as $t) {
    $n = $count("SELECT COUNT(*) FROM `$t`");
    $total += $n;
    printf("  %-24s %8d ligne(s)\n", $t, $n);
}
echo "  " . str_repeat('·', 33) . "\n";
printf("  %-24s %8d ligne(s)\n", 'TOTAL', $total);
echo "\nConservé : " . implode(', ', array_intersect($existing, KEEP)) . ($keepRef ? ', ' . implode(', ', array_intersect($existing, REFERENTIALS)) : '') . "\n";
echo 'Fichiers joints : ' . ($keepFiles ? "conservés ($fileCount)" : "$fileCount fichier(s) supprimé(s) dans storage/uploads") . "\n";
if ($unknown) {
    echo "\n⚠ Tables non classées, laissées intactes : " . implode(', ', $unknown) . "\n";
}

if (!$execute) {
    echo "\nSimulation terminée. Pour réinitialiser : php bin/reset.php --execute" . ($keepRef ? ' --keep-referentials' : '') . "\n\n";
    exit(0);
}
if ($env === 'production' && !isset($opts['force-production'])) {
    fwrite(STDERR, "\nAPP_ENV=production : ajoutez --force-production si vous voulez vraiment réinitialiser la production.\n");
    exit(1);
}

echo "\n⚠ Cette opération est IRRÉVERSIBLE. Avez-vous une sauvegarde ?\n";
echo "Tapez REINITIALISER pour confirmer : ";
if (trim((string) fgets(STDIN)) !== 'REINITIALISER') {
    echo "Annulé, rien n'a été modifié.\n";
    exit(1);
}

$adminIds = array_map(fn ($a) => (int) $a['id'], $admins);
$in = implode(',', $adminIds);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
try {
    foreach ($wipe as $t) {
        $pdo->exec("TRUNCATE TABLE `$t`"); // vide et remet l'auto-incrément à 1
    }
    $pdo->exec("DELETE FROM user_sites WHERE user_id NOT IN ($in)");
    $pdo->exec("DELETE FROM users WHERE id NOT IN ($in)");
    $pdo->exec("UPDATE users SET forwarder_id = NULL WHERE id IN ($in)");
    $pdo->exec('ALTER TABLE users AUTO_INCREMENT = ' . (max($adminIds) + 1));
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

$deleted = 0;
if (!$keepFiles) {
    foreach ($files as $f) {
        if ($f->getFilename() === '.gitkeep') {
            continue;
        }
        if ($f->isDir()) {
            @rmdir($f->getPathname());
        } elseif (@unlink($f->getPathname())) {
            $deleted++;
        }
    }
}

echo "\n✔ Réinitialisation terminée : " . count($wipe) . " table(s) vidée(s), " . count($others) . " compte(s) supprimé(s)"
    . ($keepFiles ? '' : ", $deleted fichier(s) supprimé(s)") . ".\n";
echo "  Les références repartent de 000001. Connectez-vous avec un compte administrateur.\n\n";
