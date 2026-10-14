<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Capture\Minimizer;
use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Verification\Catalogue;
use Aithos\Agentenblick\Verification\HttpSignature;
use Aithos\Agentenblick\Verification\Jwk;
use Aithos\Agentenblick\Verification\SafeSignatureContext;
use Aithos\Agentenblick\Verification\WebBotAuth;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SafeSignatureContextTest extends TestCase
{
    public function testSafeReplayVerifiesAtReceiptEvenAfterSignatureExpiry(): void
    {
        [$request, $key] = $this->signed('https://shop.test/private/person@example.test?secret=query', '"@authority"');
        $catalogue = new Catalogue(__DIR__ . '/../fixtures/catalog.json', 'test');
        $auth = new WebBotAuth();
        $unreachable = $auth->verify($request, $catalogue, static fn () => throw new \RuntimeException(), 1791972000);
        self::assertSame('directory_unreachable', $unreachable->reason);
        $context = (new SafeSignatureContext())->capture($request, $catalogue, $unreachable);
        self::assertNotNull($context);
        $serialized = json_encode($context, JSON_THROW_ON_ERROR);
        foreach (['person@example.test', 'secret=query', 'private-context', 'private-cookie', '203.0.113.42', 'private-body'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialized);
        }
        $restored = (new SafeSignatureContext())->restore($context);
        self::assertSame(Evidence::Signature, $auth->verify($restored, $catalogue, static fn (): array => ['keys' => [$key]], 1791972000)->evidence);
        self::assertSame('expired', $auth->verify($restored, $catalogue, static fn (): array => ['keys' => [$key]], 1791976000)->reason);
    }

    public function testCoveredQueryAndPersonalPathCannotBecomePending(): void
    {
        foreach (['https://shop.test/?secret=query', 'https://shop.test/person@example.test'] as $uri) {
            [$request] = $this->signed($uri, '"@target-uri"');
            $catalogue = new Catalogue(__DIR__ . '/../fixtures/catalog.json', 'test');
            $result = (new WebBotAuth())->verify($request, $catalogue, static fn () => throw new \RuntimeException(), 1791972000);
            $context = (new SafeSignatureContext())->capture($request, $catalogue, $result);
            self::assertNull($context);
            $row = (new Minimizer())->minimize($request, $result, str_repeat('a', 32), new \DateTimeImmutable('@1791972000'), 200, $context);
            self::assertSame('directory_unreachable_privacy', $row['reason']);
            self::assertNull($row['pending_context']);
            self::assertNull($row['signature_input']);
        }
    }

    public function testVerifiedRequestDoesNotRetainUnsignedExtraSignatureFields(): void
    {
        [$request, $key] = $this->signed('https://shop.test/', '"@authority"', true);
        $request->headers->set('Signature-Agent', $request->headers->get('Signature-Agent') . ', leaked="person@example.test"');
        $request->headers->set('Signature-Input', $request->headers->get('Signature-Input') . ', leaked=("cookie");nonce="private-cookie"');
        $request->headers->set('Signature', $request->headers->get('Signature') . ', leaked=:YWJj:');
        $catalogue = new Catalogue(__DIR__ . '/../fixtures/catalog.json', 'test');
        $result = (new WebBotAuth())->verify($request, $catalogue, static fn (): array => ['keys' => [$key]], 1791972000);
        self::assertSame(Evidence::Signature, $result->evidence);
        $context = (new SafeSignatureContext())->capture($request, $catalogue, $result);
        self::assertNotNull($context);
        $row = (new Minimizer())->minimize($request, $result, str_repeat('a', 32), new \DateTimeImmutable('@1791972000'), 200, $context);
        self::assertStringNotContainsString('person@example.test', json_encode($row, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private-cookie', json_encode($row, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('leaked', $row['signature_input']);
        self::assertSame($result->keyId, $row['key_id']);
    }

    public function testOpaqueNonceIsNotRetainedEvenForAuthenticSignature(): void
    {
        [$request, $key] = $this->signed('https://shop.test/', '"@authority"', false, ';nonce="person@example.test"');
        $catalogue = new Catalogue(__DIR__ . '/../fixtures/catalog.json', 'test');
        $result = (new WebBotAuth())->verify($request, $catalogue, static fn (): array => ['keys' => [$key]], 1791972000);
        self::assertSame(Evidence::Signature, $result->evidence);
        self::assertNull((new SafeSignatureContext())->capture($request, $catalogue, $result));
        $row = (new Minimizer())->minimize($request, $result, str_repeat('a', 32), new \DateTimeImmutable('@1791972000'), 200);
        self::assertNull($row['signature_input']);
        self::assertSame($result->keyId, $row['key_id']);
    }

    public function testMalformedJwkDoesNotHideSubsequentValidKey(): void
    {
        [$request, $key] = $this->signed('https://shop.test/', '"@authority"');
        $catalogue = new Catalogue(__DIR__ . '/../fixtures/catalog.json', 'test');
        $result = (new WebBotAuth())->verify($request, $catalogue, static fn (): array => ['keys' => [['kty' => 'OKP'], $key]], 1791972000);
        self::assertSame(Evidence::Signature, $result->evidence);
    }

    /** @return array{Request, array<string, string>} */
    private function signed(string $uri, string $targetComponent, bool $dictionary = false, string $extraParameters = ''): array
    {
        $pair = sodium_crypto_sign_keypair();
        $key = ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($pair)), '+/', '-_'), '=')];
        $request = Request::create($uri, 'GET', [], ['session-' => 'private-cookie'], [], ['REMOTE_ADDR' => '203.0.113.42'], 'private-body');
        $component = $dictionary ? '"signature-agent";key="agent2"' : '"signature-agent"';
        $request->headers->set('Signature-Agent', $dictionary ? 'agent2="https://signature-agent.test"' : '"https://signature-agent.test"');
        $request->headers->set('sw-context-token', 'private-context');
        $input = '(' . $targetComponent . ' ' . $component . ');created=1791972000;expires=1791972060;keyid="' . Jwk::thumbprint($key) . '";alg="ed25519";tag="web-bot-auth"' . $extraParameters;
        $request->headers->set('Signature-Input', 'sig2=' . $input);
        $http = new HttpSignature();
        $candidate = $http->parse('sig2=' . $input, 'sig2=:YWJj:')['sig2'];
        $signature = sodium_crypto_sign_detached($http->base($request, $candidate['input']), sodium_crypto_sign_secretkey($pair));
        $request->headers->set('Signature', 'sig2=:' . base64_encode($signature) . ':');
        return [$request, $key];
    }
}
