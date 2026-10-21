<?php

declare(strict_types=1);

// Usage : php bin/migrate.php  — crée la base si besoin puis applique les migrations en attente.

use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Env::load($root . '/.env');

$name = (string) Env::get('DB_NAME', 'ethan_lm');
if (!preg_match('/^\w+$/', $name)) {
    fwrite(STDERR, "DB_NAME invalide.\n");
    exit(1);
}
$server = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_PORT', '3306')),
    Env::get('DB_USER', 'root'),
    Env::get('DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$applied = (new Migrator(Database::pdo(), $root . '/database/migrations'))->run();
echo $applied ? 'Appliquées : ' . implode(', ', $applied) . "\n" : "Base à jour.\n";
