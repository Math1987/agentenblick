<?php

declare(strict_types=1);

// CLI-only, read-only bridge. Never print configuration values or exception messages.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $root = realpath($argv[1] ?? '');
    $expectedDatabase = $argv[2] ?? '';
    $action = $argv[3] ?? '';
    if ($root === false || !str_starts_with($root . '/', '/srv/agentenblick-demo/') || !in_array($action, ['preflight', 'counters'], true)) {
        throw new RuntimeException('invalid_target');
    }
    require $root . '/vendor/autoload.php';
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv($root . '/.env');
    $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '';
    $params = (new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($url);
    if (($params['dbname'] ?? '') !== $expectedDatabase || !in_array($params['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('unexpected_database');
    }
    $connection = Doctrine\DBAL\DriverManager::getConnection($params);
    $active = $connection->fetchOne('SELECT active FROM plugin WHERE name = ?', ['AithosAgentenblick']);
    if ($active === false) {
        throw new RuntimeException('plugin_not_installed');
    }
    if ($action === 'counters') {
        $counts = [];
        foreach ($connection->fetchAllAssociative('SELECT evidence, SUM(requests) AS total FROM agentenblick_daily GROUP BY evidence') as $row) {
            $counts[$row['evidence']] = (int) $row['total'];
        }
        echo json_encode($counts, JSON_THROW_ON_ERROR);
        exit;
    }
    $read = static function (string $key) use ($connection): mixed {
        $stored = $connection->fetchOne('SELECT configuration_value FROM system_config WHERE configuration_key = ? AND sales_channel_id IS NULL', [$key]);
        if (!is_string($stored)) {
            return null;
        }
        $decoded = json_decode($stored, true, 64, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? ($decoded['_value'] ?? null) : null;
    };
    $state = $read('AithosAgentenblick.internal.benchmark');
    $state = is_array($state) ? $state : [];
    $catalogue = json_decode(file_get_contents($root . '/custom/plugins/AithosAgentenblick/src/Resources/catalog/agents.json'), true, 64, JSON_THROW_ON_ERROR);
    echo json_encode([
        'phpCli' => PHP_VERSION,
        'mysql' => $connection->fetchOne('SELECT VERSION()'),
        'environment' => $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '',
        'debug' => filter_var($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOL),
        'pluginActive' => (bool) $active,
        'offlineInDatabase' => $read('AithosAgentenblick.config.offline') === true,
        'heuristicsEnabled' => $read('AithosAgentenblick.config.heuristicsEnabled') === true,
        'benchmarkEnabled' => ($state['enabled'] ?? false) === true,
        'pendingWithdrawal' => ($state['pendingWithdrawal'] ?? false) === true,
        'testCatalogueConfigured' => ($_SERVER['AGENTENBLICK_TEST_CATALOG'] ?? $_ENV['AGENTENBLICK_TEST_CATALOG'] ?? '') !== '',
        'productionCdnRanges' => array_sum(array_map(static fn (array $item): int => count($item['ranges'] ?? []), $catalogue['cdnRanges'] ?? [])),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['error' => 'native_bridge_failed', 'type' => get_debug_type($error)], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
