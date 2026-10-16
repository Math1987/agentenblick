<?php

declare(strict_types=1);

// Only the disposable CI database used by the integration tests is accepted.
if (getenv('AGENTENBLICK_DB_TEST') !== '1') {
    throw new RuntimeException('Requires explicit disposable database opt-in.');
}
require dirname(__DIR__) . '/tests/bootstrap.php';
$connection = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => '127.0.0.1',
    'user' => 'root',
    'password' => 'root',
    'dbname' => 'shopware',
]);
foreach (glob(dirname(__DIR__) . '/src/Migration/Migration*.php') ?: [] as $file) {
    $class = 'Aithos\\Agentenblick\\Migration\\' . basename($file, '.php');
    (new $class())->update($connection);
}
echo "Disposable Agentenblick schema ready.\n";
