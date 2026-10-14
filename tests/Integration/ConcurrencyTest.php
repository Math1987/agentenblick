<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ConcurrencyTest extends TestCase
{
    public function testConcurrentRequestsKeepEveryRequestAndOneSession(): void
    {
        if (getenv('AGENTENBLICK_DB_TEST') !== '1') {
            self::markTestSkipped('Requires AGENTENBLICK_DB_TEST=1 on the disposable MySQL bench.');
        }
        $params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse('mysql://root:root@127.0.0.1/shopware');
        $connection = DriverManager::getConnection($params);
        // The workers need shared committed rows: reserve a random synthetic channel,
        // and always clean only that channel. Existing kit observations are untouched.
        $channel = 'ffffffffffffffff' . bin2hex(random_bytes(8));
        $sessionKey = bin2hex(random_bytes(32));
        $observedAt = gmdate('Y-m-d H:i:s');
        $processes = [];
        try {
            for ($index = 0; $index < 4; ++$index) {
                $process = new Process([PHP_BINARY, __DIR__ . '/concurrent-record.php', $channel, $sessionKey, $observedAt]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput() . $process->getOutput());
            }

            self::assertSame(20, (int) $connection->fetchOne('SELECT COUNT(*) FROM agentenblick_request WHERE sales_channel_id = ?', [$channel]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM agentenblick_session WHERE sales_channel_id = ?', [$channel]));
            self::assertSame(20, (int) $connection->fetchOne('SELECT requests FROM agentenblick_session WHERE sales_channel_id = ?', [$channel]));
            self::assertSame(20, (int) $connection->fetchOne('SELECT SUM(requests) FROM agentenblick_daily WHERE sales_channel_id = ?', [$channel]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT SUM(sessions) FROM agentenblick_daily WHERE sales_channel_id = ?', [$channel]));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (['request', 'session', 'daily'] as $table) {
                $connection->executeStatement('DELETE FROM agentenblick_' . $table . ' WHERE sales_channel_id = ?', [$channel]);
            }
            $connection->close();
        }
    }
}
