<?php

declare(strict_types=1);

// Usage : php bin/purge-licence-imports.php — à planifier (cron quotidien) : supprime les documents des brouillons
// d'import de licence abandonnés depuis plus de 24 h. La purge est aussi déclenchée à chaque nouveau téléversement.

use App\Core\Env;
use App\Services\Licence\LicenceImportService;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Env::load($root . '/.env');

$n = (new LicenceImportService())->purgeExpired();
echo "Brouillons purgés : $n\n";
