<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Persistence\DailyKey;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DailyKeyTest extends TestCase
{
    public function testDelayedPreviousDayRequestDoesNotReplaceCurrentKey(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $key = str_repeat('a', 32);
        $row = ['value' => base64_encode($key), 'updated_at' => $today->format('Y-m-d H:i:s')];
        $updates = 0;
        $keys = new DailyKey($this->statefulConnection($row, $updates));

        self::assertSame($key, $keys->current($today->modify('-1 day')->format('Y-m-d')));
        self::assertSame($key, $keys->current($today->format('Y-m-d')));
        self::assertSame(0, $updates);
        self::assertSame($today->format('Y-m-d H:i:s'), $row['updated_at']);
    }

    public function testRotationHappensOnceDespiteLateTermination(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $yesterday = $today->modify('-1 day')->format('Y-m-d');
        $old = str_repeat('b', 32);
        $row = ['value' => base64_encode($old), 'updated_at' => $yesterday . ' 00:00:00'];
        $updates = 0;
        $keys = new DailyKey($this->statefulConnection($row, $updates));

        $current = $keys->current($today->format('Y-m-d'));
        self::assertNotSame($old, $current);
        self::assertSame(32, strlen($current));
        self::assertSame($current, $keys->current($yesterday));
        self::assertSame($current, $keys->current($today->format('Y-m-d')));
        self::assertSame(1, $updates);
        self::assertSame($today->format('Y-m-d H:i:s'), $row['updated_at']);
    }

    public function testAStoredNewerDayCannotBeMovedBackward(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $newerDay = $today->modify('+1 day')->format('Y-m-d');
        $key = str_repeat('c', 32);
        $row = ['value' => base64_encode($key), 'updated_at' => $newerDay . ' 00:00:00'];
        $updates = 0;
        $keys = new DailyKey($this->statefulConnection($row, $updates));

        self::assertSame($key, $keys->current($today->format('Y-m-d')));
        self::assertSame(0, $updates);
        self::assertSame($newerDay . ' 00:00:00', $row['updated_at']);
    }

    /** @param array{value: string, updated_at: string} $row */
    private function statefulConnection(array &$row, int &$updates): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback($connection));
        $connection->method('fetchAssociative')->willReturnCallback(static function () use (&$row): array {
            return $row;
        });
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $parameters = []) use (&$row, &$updates): int {
            if (str_starts_with($sql, 'UPDATE ')) {
                ++$updates;
                $row = ['value' => $parameters[0], 'updated_at' => $parameters[1]];
                return 1;
            }
            return 0;
        });

        return $connection;
    }
}
