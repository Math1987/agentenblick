<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Persistence\DailyKey;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testCurrentKeyReadsThePrimaryWithoutTakingAWriteLock(): void
    {
        $key = str_repeat('d', 32);
        $primary = false;
        $connection = $this->createMock(PrimaryReadReplicaConnection::class);
        $connection->expects(self::once())->method('ensureConnectedToPrimary')->willReturnCallback(static function () use (&$primary): bool {
            $primary = true;
            return true;
        });
        $connection->expects(self::once())->method('fetchAssociative')->willReturnCallback(static function () use (&$primary, $key): array {
            self::assertTrue($primary, 'A replica may still expose a superseded key.');
            return ['value' => base64_encode($key), 'updated_at' => gmdate('Y-m-d') . ' 00:00:00'];
        });
        $connection->expects(self::never())->method('transactional');
        $connection->expects(self::never())->method('executeStatement');

        self::assertSame($key, (new DailyKey($connection))->current(gmdate('Y-m-d')));
    }

    public function testAnotherWorkerRotationIsReadAgainInsideTheLockedPath(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $current = str_repeat('e', 32);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('fetchAssociative')->willReturn(
            ['value' => base64_encode(str_repeat('f', 32)), 'updated_at' => $today->modify('-1 day')->format('Y-m-d H:i:s')],
            ['value' => base64_encode($current), 'updated_at' => $today->format('Y-m-d H:i:s')],
        );
        $connection->expects(self::once())->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback($connection));
        $connection->expects(self::once())->method('executeStatement')->willReturn(0);

        self::assertSame($current, (new DailyKey($connection))->current($today->format('Y-m-d')));
    }

    public function testOuterTransactionRetainsALockingReadInsteadOfUsingItsSnapshot(): void
    {
        $key = str_repeat('h', 32);
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback($connection));
        $connection->expects(self::once())->method('fetchAssociative')->willReturn(['value' => base64_encode($key), 'updated_at' => gmdate('Y-m-d') . ' 00:00:00']);
        $connection->expects(self::once())->method('executeStatement')->willReturn(0);

        self::assertSame($key, (new DailyKey($connection))->current(gmdate('Y-m-d')));
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidCurrentKeyFailsClosedWithoutReplacingIt(string $encoded): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['value' => $encoded, 'updated_at' => gmdate('Y-m-d') . ' 00:00:00']);
        $connection->expects(self::never())->method('transactional');
        $connection->expects(self::never())->method('executeStatement');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_hmac_key');

        (new DailyKey($connection))->current(gmdate('Y-m-d'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'invalid base64' => ['!not-base64!'];
        yield 'too short' => [base64_encode(str_repeat('g', 31))];
        yield 'too long' => [base64_encode(str_repeat('g', 33))];
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
