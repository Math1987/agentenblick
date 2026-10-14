<?php

declare(strict_types=1);

use Aithos\Agentenblick\Capture\Minimizer;
use Aithos\Agentenblick\Domain\Classification;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Domain\Kind;
use Aithos\Agentenblick\Persistence\ObservationStore;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\HttpFoundation\Request;

if (getenv('AGENTENBLICK_DB_TEST') !== '1' || count($argv) !== 4
    || !preg_match('/^ffffffffffffffff[a-f0-9]{16}$/D', $argv[1])
    || !preg_match('/^[a-f0-9]{64}$/D', $argv[2])) {
    fwrite(STDERR, "This worker requires the disposable integration test runner.\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap.php';
$params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse('mysql://root:root@127.0.0.1/shopware');
$connection = DriverManager::getConnection($params);
$store = new ObservationStore($connection);
$request = Request::create('https://shop.test/', server: ['REMOTE_ADDR' => '203.0.113.20', 'HTTP_USER_AGENT' => 'IntegrationAgent/1.0']);
$request->attributes->set('sw-sales-channel-id', $argv[1]);
$classification = new Classification('test', 'IntegrationConcurrencyAgent', Kind::SearchCrawler, Evidence::Declared);
$row = (new Minimizer())->minimize($request, $classification, str_repeat('c', 32), new DateTimeImmutable($argv[3], new DateTimeZone('UTC')), 200);
$row['session_key'] = $argv[2];

for ($index = 0; $index < 5; ++$index) {
    $store->record($row);
}
$connection->close();
