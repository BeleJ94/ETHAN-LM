<?php

declare(strict_types=1);

// Usage : php bin/create-admin.php email@domaine.com "Nom complet"
// Le mot de passe est demandé de façon interactive (ou lu sur STDIN), jamais passé en argument.

use App\Core\Database;
use App\Core\Env;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Env::load($root . '/.env');

[$_, $email, $name] = $argv + [null, null, null];
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$name) {
    fwrite(STDERR, "Usage : php bin/create-admin.php email \"Nom complet\"\n");
    exit(1);
}
fwrite(STDOUT, 'Mot de passe (10 caractères min.) : ');
$password = trim((string) fgets(STDIN));
if (mb_strlen($password) < 10) {
    fwrite(STDERR, "Mot de passe trop court.\n");
    exit(1);
}

Database::pdo()->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, \'admin\')
    ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), role = \'admin\', active = 1')
    ->execute([$name, mb_strtolower($email), password_hash($password, PASSWORD_DEFAULT)]);
echo "Administrateur $email prêt.\n";
