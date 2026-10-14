<?php declare(strict_types=1);

// Integration bench provides Shopware and its locked Symfony/DBAL dependencies.
$loader = require getenv('SHOPWARE_AUTOLOAD') ?: '/var/www/html/vendor/autoload.php';
$loader->addPsr4('Aithos\\Agentenblick\\', dirname(__DIR__) . '/src/');
$loader->addPsr4('Aithos\\Agentenblick\\Tests\\', __DIR__ . '/');
