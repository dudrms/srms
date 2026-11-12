<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Db;
use App\Core\Migrator;
use App\Services\Seeder;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$applied = Migrator::run();
echo $applied === [] ? "up to date\n" : 'applied: ' . implode(', ', $applied) . "\n";

Seeder::seedPermissions();
foreach (Db::all('SELECT company_cd FROM company ORDER BY company_cd') as $c) {
    Seeder::ensureDefaults((string)$c['company_cd']);
}
echo "defaults ensured\n";
