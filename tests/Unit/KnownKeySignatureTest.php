<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Verification\Catalogue;
use Aithos\Agentenblick\Verification\HttpSignature;
use Aithos\Agentenblick\Verification\Jwk;
use Aithos\Agentenblick\Verification\KeyDirectory;
use Aithos\Agentenblick\Verification\WebBotAuth;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

final class KnownKeySignatureTest extends TestCase
{
    public function testMissingSignatureAgentVerifiesWithAnAlreadyKnownPublicKey(): void
    {
        [$request, $key] = $this->signed();
        $catalogue = $this->catalogue();
        $result = (new WebBotAuth())->verify($request, $catalogue, static fn (): array => ['keys' => [$key]], 1791972000, static fn (string $keyId): ?string => $keyId === Jwk::thumbprint($key) ? 'https://signature-agent.test' : null);
        self::assertSame(Evidence::Signature, $result->evidence);
        self::assertSame('SignedTestAgent', $result->agent);
    }

    public function testUnknownKeyWithoutSignatureAgentDoesNotProbeAnyDirectory(): void
    {
        [$request] = $this->signed();
        $calls = 0;
        $fetch = static function () use (&$calls): array {
            ++$calls;
            return ['keys' => []];
        };
        $result = (new WebBotAuth())->verify($request, $this->catalogue(), $fetch, 1791972000, static fn (): ?string => null);
        self::assertSame('unknown_key', $result->reason);
        self::assertSame(Evidence::Declared, $result->evidence);
        self::assertSame(0, $calls);
    }

    public function testPresentSignatureAgentMustStillBeSigned(): void
    {
        [$request, $key] = $this->signed();
        $request->headers->set('Signature-Agent', '"https://signature-agent.test"');
        $result = (new WebBotAuth())->verify($request, $this->catalogue(), static fn (): array => ['keys' => [$key]], 1791972000, static fn (): string => 'https://signature-agent.test');
        self::assertSame('bad_signature', $result->reason);
        self::assertSame(Evidence::Declared, $result->evidence);
    }

    public function testKnownOriginAndCachedOnlyRetrievalAreReadOnly(): void
    {
        [, $key] = $this->signed();
        $document = json_encode(['keys' => [$key]], JSON_THROW_ON_ERROR);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturn([['origin' => 'https://signature-agent.test', 'document_json' => $document]]);
        $connection->expects(self::once())->method('fetchAssociative')->willReturn(['document_json' => $document, 'fetched_at' => gmdate('Y-m-d H:i:s', time() - 7200)]);
        $connection->expects(self::never())->method('executeStatement');
        $config = $this->createMock(SystemConfigService::class);
        $config->expects(self::never())->method('getBool');
        $directory = new KeyDirectory($connection, $config, 'test');
        self::assertSame('https://signature-agent.test', $directory->knownOrigin(Jwk::thumbprint($key), $this->catalogue()));
        self::assertSame(['keys' => [$key]], $directory->get('https://signature-agent.test', $this->catalogue(), true));
    }

    public function testExpiredAndFutureCacheCannotSupplyKnownKeys(): void
    {
        [, $key] = $this->signed();
        foreach ([time() - 86401, time() + 3600] as $fetchedAt) {
            $connection = $this->createMock(Connection::class);
            $connection->method('fetchAssociative')->willReturn(['document_json' => json_encode(['keys' => [$key]], JSON_THROW_ON_ERROR), 'fetched_at' => gmdate('Y-m-d H:i:s', $fetchedAt)]);
            $config = $this->createMock(SystemConfigService::class);
            $config->expects(self::never())->method('getBool');
            self::assertSame(['keys' => []], (new KeyDirectory($connection, $config, 'test'))->get('https://signature-agent.test', $this->catalogue(), true));
        }
    }

    public function testUncataloguedAndAmbiguousOriginsCannotBeInferred(): void
    {
        [, $key] = $this->signed();
        $document = json_encode(['keys' => [$key]], JSON_THROW_ON_ERROR);
        foreach ([['https://not-catalogued.test'], ['https://signature-agent.test', 'http://127.0.0.1:18081']] as $origins) {
            $connection = $this->createMock(Connection::class);
            $connection->method('fetchAllAssociative')->willReturn(array_map(static fn (string $origin): array => ['origin' => $origin, 'document_json' => $document], $origins));
            $config = $this->createMock(SystemConfigService::class);
            self::assertNull((new KeyDirectory($connection, $config, 'test'))->knownOrigin(Jwk::thumbprint($key), $this->catalogue()));
        }
    }

    public function testEmbeddedKeyIsUsableWithoutDirectoryLookup(): void
    {
        [, $key] = $this->signed();
        $document = json_decode(file_get_contents(__DIR__ . '/../fixtures/catalog.json'), true, 32, JSON_THROW_ON_ERROR);
        foreach ($document['agents'] as &$agent) {
            if (($agent['origin'] ?? null) === 'https://signature-agent.test') {
                $agent['keys'] = [$key];
            }
        }
        unset($agent);
        $path = tempnam(sys_get_temp_dir(), 'agentenblick-public-key-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
            $catalogue = new Catalogue($path, 'test');
            $connection = $this->createMock(Connection::class);
            $connection->method('fetchAllAssociative')->willReturn([]);
            $connection->method('fetchAssociative')->willReturn(false);
            $config = $this->createMock(SystemConfigService::class);
            $config->expects(self::never())->method('getBool');
            $directory = new KeyDirectory($connection, $config, 'test');
            self::assertSame('https://signature-agent.test', $directory->knownOrigin(Jwk::thumbprint($key), $catalogue));
            self::assertSame(['keys' => [$key]], $directory->get('https://signature-agent.test', $catalogue, true));
        } finally {
            unlink($path);
        }
    }

    private function catalogue(): Catalogue
    {
        return new Catalogue(__DIR__ . '/../fixtures/catalog.json', 'test');
    }

    /** @return array{Request, array<string, string>} */
    private function signed(): array
    {
        $pair = sodium_crypto_sign_keypair();
        $key = ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => Jwk::encode(sodium_crypto_sign_publickey($pair))];
        $request = Request::create('https://shop.test/');
        $input = '("@authority");created=1791972000;expires=1791972060;keyid="' . Jwk::thumbprint($key) . '";alg="ed25519";tag="web-bot-auth"';
        $http = new HttpSignature();
        $candidate = $http->parse('sig=' . $input, 'sig=:YWJj:')['sig'];
        $signature = sodium_crypto_sign_detached($http->base($request, $candidate['input']), sodium_crypto_sign_secretkey($pair));
        $request->headers->add(['Signature-Input' => 'sig=' . $input, 'Signature' => 'sig=:' . base64_encode($signature) . ':']);
        return [$request, $key];
    }
}
