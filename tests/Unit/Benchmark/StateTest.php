<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit\Benchmark;

use Aithos\Agentenblick\Benchmark\SecretVault;
use Aithos\Agentenblick\Benchmark\State;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class StateTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $stored = [];

    #[DataProvider('modes')]
    public function testPreparedIdentityKeepsItsModeWhenConfigChanges(bool $demo): void
    {
        $this->stored['AithosAgentenblick.config.benchmarkDemo'] = $demo;
        $state = $this->state();
        $prepared = $state->prepare($state->read());
        self::assertSame($demo, $prepared['demo']);
        self::assertSame(State::TRANSPORT_VERSION, $prepared['transportVersion']);
        $this->stored['AithosAgentenblick.config.benchmarkDemo'] = !$demo;
        self::assertSame($prepared, $state->prepare($state->read()));
        self::assertFalse($state->transportMigrationRequired($state->read()));
    }

    public function testLegacyDemoCannotBePreparedForTheNewService(): void
    {
        $state = $this->state();
        $legacy = $state->prepare($state->read());
        $legacy['demo'] = true;
        unset($legacy['transportVersion']);
        $this->stored[State::KEY] = $legacy;
        self::assertTrue($state->transportMigrationRequired($state->read()));
        try {
            $state->prepare($state->read());
            self::fail('A legacy demonstration identity requires an explicit migration.');
        } catch (\RuntimeException $error) {
            self::assertSame('benchmark_transport_migration_required', $error->getMessage());
        }
        self::assertSame($legacy, $this->stored[State::KEY]);
    }

    public function testLegacyRealIdentityKeepsItsOriginalDestination(): void
    {
        $state = $this->state();
        $legacy = $state->prepare($state->read());
        unset($legacy['transportVersion']);
        $this->stored[State::KEY] = $legacy;
        $this->stored['AithosAgentenblick.config.benchmarkDemo'] = true;
        $prepared = $state->prepare($state->read());
        self::assertFalse($prepared['demo']);
        self::assertFalse($state->transportMigrationRequired($prepared));
        self::assertSame($legacy['installationId'], $prepared['installationId']);
        self::assertSame($legacy['secretCipher'], $prepared['secretCipher']);
    }

    public function testExistingIdentityWithoutPersistedModeCannotInheritConfig(): void
    {
        $state = $this->state();
        $legacy = $state->prepare($state->read());
        unset($legacy['demo']);
        $this->stored[State::KEY] = $legacy;
        $this->stored['AithosAgentenblick.config.benchmarkDemo'] = true;
        self::assertFalse($state->read()['demo']);
        self::assertTrue($state->transportMigrationRequired($state->read()));
    }

    public function testNewTestIdentityUsesDemoButClonedRealIdentityRemainsBlocked(): void
    {
        $state = $this->state('test');
        $prepared = $state->prepare($state->read());
        self::assertTrue($prepared['demo']);
        self::assertSame(State::TRANSPORT_VERSION, $prepared['transportVersion']);
        $prepared['demo'] = false;
        $this->stored[State::KEY] = $prepared;
        self::assertTrue($state->testIdentityConflict($state->read()));
        $this->expectExceptionMessage('benchmark_test_identity_conflict');
        $state->prepare($state->read());
    }

    /** @return iterable<string, array{bool}> */
    public static function modes(): iterable
    {
        yield 'production' => [false];
        yield 'demo' => [true];
    }

    private function state(string $environment = 'prod'): State
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $parameters): string|false {
            return array_key_exists($parameters[0], $this->stored) ? json_encode(['_value' => $this->stored[$parameters[0]]], JSON_THROW_ON_ERROR) : false;
        });
        $config = $this->createMock(SystemConfigService::class);
        $config->method('getBool')->willReturn(false);
        $config->method('set')->willReturnCallback(function (string $key, mixed $value): void {
            $this->stored[$key] = $value;
        });
        return new State($connection, $config, new SecretVault('fixture-kernel-secret'), $environment);
    }
}
