<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Integration;

use Aithos\Agentenblick\Benchmark\Drafts;
use Aithos\Agentenblick\Benchmark\Lifecycle;
use Aithos\Agentenblick\Benchmark\Manager;
use Aithos\Agentenblick\Benchmark\Payload;
use Aithos\Agentenblick\Benchmark\Period;
use Aithos\Agentenblick\Benchmark\SecretVault;
use Aithos\Agentenblick\Benchmark\State;
use Aithos\Agentenblick\Benchmark\Transport;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BenchmarkTest extends TestCase
{
    private ?Connection $connection = null;
    private State $state;
    private SystemConfigService $configuration;
    private bool $managedOffline = false;
    private const CHANNEL = 'fffffffffffffffffffffffffffffffa';

    protected function setUp(): void
    {
        if (getenv('AGENTENBLICK_DB_TEST') !== '1') {
            self::markTestSkipped('Requires the disposable MySQL bench.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse('mysql://root:root@127.0.0.1/shopware'));
        // MySQL temporary tables cannot declare the live system_config foreign key.
        $this->db()->executeStatement('CREATE TEMPORARY TABLE system_config (id BINARY(16) NOT NULL PRIMARY KEY, configuration_key VARCHAR(255) NOT NULL, configuration_value JSON NOT NULL, sales_channel_id BINARY(16) DEFAULT NULL, created_at DATETIME NOT NULL)');
        foreach (['agentenblick_daily', 'agentenblick_benchmark_draft'] as $table) {
            $definition = $this->db()->fetchAssociative('SHOW CREATE TABLE ' . $table);
            self::assertNotFalse($definition);
            $this->db()->executeStatement('CREATE TEMPORARY TABLE ' . substr($definition['Create Table'], strlen('CREATE TABLE ')));
        }
        $config = $this->createMock(SystemConfigService::class);
        $config->method('set')->willReturnCallback(fn (string $key, mixed $value) => $this->config($key, $value));
        $config->method('getBool')->willReturnCallback(fn (string $key): bool => $key === 'AithosAgentenblick.config.offline' && $this->managedOffline);
        $this->configuration = $config;
        $this->state = new State($this->db(), $config, new SecretVault('test-benchmark-kernel-secret'));
        [$start] = Period::bounds(Period::previous());
        foreach ([['human_or_unknown', 'none', 'unknown', 90], ['training_crawler', 'verified_ip', 'openai', 10]] as [$kind, $proof, $operator, $count]) {
            $this->db()->insert('agentenblick_daily', ['day' => $start, 'sales_channel_id' => self::CHANNEL, 'kind' => $kind, 'evidence' => $proof, 'operator' => $operator, 'agent' => 'test', 'requests' => $count, 'sessions' => $count]);
        }
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
    }

    public function testOffByDefaultPreviewIsLocalFrozenAndExactlyMatchesLaterPost(): void
    {
        $calls = [];
        $manager = $this->manager(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url, $options['body']];
            if (str_ends_with($url, '/installations')) {
                $body = json_decode($options['body'], true, 32, JSON_THROW_ON_ERROR);
                return new MockResponse(json_encode(['installationId' => $body['installationId']], JSON_THROW_ON_ERROR), ['http_code' => 201]);
            }
            return new MockResponse('{"accepted":true}', ['http_code' => 201]);
        });
        self::assertFalse($manager->status()['enabled']);
        self::assertSame('disabled', $manager->send()['status']);
        self::assertFalse($manager->comparison()['available']);
        $preview = $manager->preview();
        $bytes = $preview['items'][0]['json'];
        self::assertSame([], $calls);
        self::assertStringNotContainsString(self::CHANNEL, $bytes);
        self::assertSame(hash('sha256', $bytes), $preview['items'][0]['sha256']);
        $this->db()->executeStatement('UPDATE agentenblick_daily SET sessions = 100000');
        self::assertSame($bytes, $manager->preview()['items'][0]['json']);
        self::assertTrue($manager->enable('en-GB')['registered']);
        self::assertSame('sent', $manager->send()['status']);
        self::assertCount(2, $calls);
        self::assertSame($bytes, $calls[1][2]);
        self::assertSame('no_pending_documents', $manager->send()['status']);
        self::assertSame('sent', $manager->history()['items'][0]['status']);
        self::assertSame($bytes, $manager->history()['items'][0]['json']);
        $state = $this->state->read();
        self::assertStringNotContainsString($this->state->secret($state), json_encode([$manager->status(), $manager->preview(), $manager->history()], JSON_THROW_ON_ERROR));
    }

    public function testFailedWithdrawalStopsSendingFirstAndRetainsSecretUntilRetrySucceeds(): void
    {
        $deleteAttempts = 0;
        $manager = $this->manager(function (string $method, string $url, array $options) use (&$deleteAttempts): MockResponse {
            if ($method === 'DELETE') {
                self::assertFalse($this->state->read()['enabled']);
                ++$deleteAttempts;
                return new MockResponse('', ['http_code' => $deleteAttempts === 1 ? 503 : 204]);
            }
            $body = json_decode($options['body'], true, 32, JSON_THROW_ON_ERROR);
            return new MockResponse(json_encode(['installationId' => $body['installationId']], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        });
        $manager->preview();
        $manager->enable('de-DE');
        $secret = $this->state->read()['secretCipher'];
        $disabled = $manager->disable();
        self::assertFalse($disabled['enabled']);
        self::assertTrue($disabled['pendingWithdrawal']);
        self::assertSame($secret, $this->state->read()['secretCipher']);
        self::assertSame('withdrawn', $manager->send()['status']);
        self::assertSame('', $this->state->read()['secretCipher']);
        self::assertSame('disabled', $manager->send()['status']);
        self::assertCount(1, $manager->history()['items']);
        self::assertSame('withdrawn', $manager->history()['items'][0]['status']);
    }

    public function testOfflineMakesNoRequestsIncludingConsentAndPendingWithdrawal(): void
    {
        $manager = $this->manager(static function (): never {
            self::fail('Offline mode must make zero network requests.');
        });
        $this->config('AithosAgentenblick.config.offline', true);
        $manager->preview();
        self::assertTrue($manager->enable('de-DE')['enabled']);
        self::assertFalse($manager->status()['registered']);
        self::assertSame('offline', $manager->send()['status']);
        self::assertFalse($manager->comparison()['available']);
        self::assertTrue($manager->disable()['pendingWithdrawal']);
        self::assertSame('offline', $manager->run()['status']);
        self::assertNotSame('', $this->state->read()['secretCipher']);
    }

    public function testRegistrationRetryKeepsPreallocatedIdSecretAndReviewedBytes(): void
    {
        $calls = [];
        $manager = $this->manager(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = $options['body'];
            $body = json_decode($options['body'], true, 32, JSON_THROW_ON_ERROR);
            return new MockResponse(count($calls) === 1 ? '{}' : json_encode(['installationId' => $body['installationId']], JSON_THROW_ON_ERROR), ['http_code' => count($calls) === 1 ? 503 : 200]);
        });
        $before = $manager->preview();
        self::assertFalse($manager->enable('en-GB')['registered']);
        self::assertSame('registered', $manager->send()['status']);
        self::assertSame($calls[0], $calls[1]);
        self::assertSame($before['items'][0]['json'], $manager->preview()['items'][0]['json']);
    }

    public function testManagedOfflineConfigurationWinsWithoutDatabaseSetting(): void
    {
        $this->managedOffline = true;
        $manager = $this->manager(static function (): never {
            self::fail('Managed offline mode must block transport.');
        });
        self::assertTrue($manager->status()['offline']);
        $manager->preview();
        $manager->enable('en-GB');
        self::assertSame('offline', $manager->run()['status']);
        self::assertTrue($manager->disable()['pendingWithdrawal']);
    }

    public function testTooManyChannelsAreRejectedBeforeAnyFrozenDocumentOrNetworkRequest(): void
    {
        [$start] = Period::bounds(Period::previous());
        for ($i = 1; $i <= 32; ++$i) {
            $this->db()->insert('agentenblick_daily', ['day' => $start, 'sales_channel_id' => str_pad(dechex($i), 32, '0', STR_PAD_LEFT), 'kind' => 'human_or_unknown', 'evidence' => 'none', 'operator' => 'unknown', 'agent' => 'test', 'requests' => 10, 'sessions' => 10]);
        }
        $manager = $this->manager(static function (): never {
            self::fail('Preview must be local.');
        });
        try {
            $manager->preview();
            self::fail('The service supports at most 32 channels per week.');
        } catch (\RuntimeException $error) {
            self::assertSame('benchmark_too_many_channels', $error->getMessage());
        }
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM agentenblick_benchmark_draft'));
    }

    public function testPermanentRejectionDoesNotBlockTheOtherChannel(): void
    {
        [$start] = Period::bounds(Period::previous());
        $this->db()->insert('agentenblick_daily', ['day' => $start, 'sales_channel_id' => 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 'kind' => 'human_or_unknown', 'evidence' => 'none', 'operator' => 'unknown', 'agent' => 'test', 'requests' => 100, 'sessions' => 100]);
        $posts = 0;
        $manager = $this->manager(static function (string $method, string $url, array $options) use (&$posts): MockResponse {
            if (str_ends_with($url, '/installations')) {
                $document = json_decode($options['body'], true, 32, JSON_THROW_ON_ERROR);
                return new MockResponse(json_encode(['installationId' => $document['installationId']], JSON_THROW_ON_ERROR), ['http_code' => 201]);
            }
            ++$posts;
            return new MockResponse($posts === 1 ? '{}' : '{"accepted":true}', ['http_code' => $posts === 1 ? 409 : 201]);
        });
        $manager->enable('en-GB');
        self::assertSame('failed', $manager->send()['status']);
        self::assertSame('sent', $manager->send()['status']);
        self::assertSame('no_pending_documents', $manager->send()['status']);
        self::assertSame(2, $posts);
        self::assertContains('rejected', array_column($manager->history()['items'], 'status'));
    }

    public function testFiveWeekDraftRemainsSendableButFourteenWeekDraftExpiresExplicitly(): void
    {
        $id = Payload::uuid();
        $drafts = new Drafts($this->db(), $this->state, '6.7.15.0');
        $fiveWeeks = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-5 weeks')->format('o-\WW');
        self::assertCount(2, Period::bounds($fiveWeeks));
        foreach ([$fiveWeeks, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-14 weeks')->format('o-\WW')] as $period) {
            $this->db()->insert('agentenblick_benchmark_draft', ['installation_id' => $id, 'channel_id' => Payload::uuid(), 'period' => $period, 'payload' => '{}', 'sha256' => hash('sha256', '{}'), 'status' => 'draft', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        }
        self::assertSame($fiveWeeks, $drafts->next($id)['period']);
        $rows = $drafts->rows($id);
        self::assertSame(['draft', 'expired'], array_column($rows, 'status'));
        self::assertSame('period_expired', $rows[1]['resultCode']);
    }

    public function testTestEnvironmentDefaultsToDemoAndCannotOperateOnClonedRealIdentity(): void
    {
        $testState = new State($this->db(), $this->configuration, new SecretVault('test-benchmark-kernel-secret'), 'test');
        self::assertTrue($testState->read()['demo']);
        $prepared = $testState->prepare($testState->read());
        self::assertTrue($prepared['demo']);
        $prepared['demo'] = false;
        $prepared['enabled'] = true;
        $prepared['registered'] = true;
        $prepared['consentAt'] = gmdate('c');
        $testState->save($prepared);
        $manager = new Manager($this->db(), $testState, new Drafts($this->db(), $testState, '6.7.15.0'), new Transport(new MockHttpClient(static function (): never {
            self::fail('A cloned real identity may not make requests in test.');
        })));
        self::assertSame('test_identity_conflict', $manager->status()['reasonCode']);
        self::assertSame('test_identity_conflict', $manager->send()['status']);
        self::assertTrue($manager->disable()['pendingWithdrawal']);
        self::assertSame($prepared['secretCipher'], $testState->read()['secretCipher']);
        $this->expectException(\RuntimeException::class);
        $manager->preview();
    }

    public function testUninstallCanStopParticipationAfterPluginServicesAreRemoved(): void
    {
        $container = new Container();
        $container->set(Connection::class, $this->db());
        $container->set(SystemConfigService::class, $this->configuration);
        $container->setParameter('kernel.secret', 'test-benchmark-kernel-secret');
        $container->setParameter('kernel.shopware_version', '6.7.15.0');
        $container->setParameter('kernel.environment', 'test');
        self::assertFalse($container->has(Manager::class));
        $state = $this->state->prepare($this->state->read());
        $state['enabled'] = true;
        $state['consentAt'] = gmdate('c');
        $this->state->save($state);
        $this->config('AithosAgentenblick.config.offline', true);
        $status = Lifecycle::manager($container)->disable();
        self::assertFalse($status['enabled']);
        self::assertTrue($status['pendingWithdrawal']);
        self::assertSame($state['secretCipher'], $this->state->read()['secretCipher']);
    }

    public function testConcurrentMutationCannotPassTheDatabaseLockOrReachTransport(): void
    {
        $name = 'agentenblick.benchmark.' . substr(hash('sha256', (string) $this->db()->getDatabase()), 0, 24);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT GET_LOCK(?, 0)', [$name]));
        $second = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse('mysql://root:root@127.0.0.1/shopware'));
        try {
            $settings = new State($second, $this->configuration, new SecretVault('test-benchmark-kernel-secret'));
            $manager = new Manager($second, $settings, new Drafts($second, $settings, '6.7.15.0'), new Transport(new MockHttpClient(static function (): never {
                self::fail('Contended operation must not reach transport.');
            })));
            try {
                $manager->disable();
                self::fail('A second database connection must not enter the mutation.');
            } catch (ConflictHttpException) {
                self::assertFalse($this->state->read()['enabled']);
            }
        } finally {
            $second->close();
            $this->db()->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    private function db(): Connection
    {
        return $this->connection ?? throw new \LogicException('Missing database.');
    }

    private function config(string $key, mixed $value): void
    {
        $this->db()->delete('system_config', ['configuration_key' => $key]);
        $this->db()->insert('system_config', ['id' => random_bytes(16), 'configuration_key' => $key, 'configuration_value' => json_encode(['_value' => $value], JSON_THROW_ON_ERROR), 'created_at' => gmdate('Y-m-d H:i:s')]);
    }

    private function manager(callable $responses): Manager
    {
        return new Manager($this->db(), $this->state, new Drafts($this->db(), $this->state, '6.7.15.0'), new Transport(new MockHttpClient($responses)));
    }
}
