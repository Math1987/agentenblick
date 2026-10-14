<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Verification\HttpSignature;
use Aithos\Agentenblick\Verification\Jwk;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SignatureBoundaryTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function ambiguousComponents(): iterable
    {
        yield 'duplicate component' => ['("@authority" "@authority")'];
        yield 'unsupported body reference' => ['("@authority" "content-digest";req)'];
        yield 'unsupported query parameter' => ['("@query-param";name="token")'];
        yield 'noncanonical header case' => ['("Host")'];
    }

    #[DataProvider('ambiguousComponents')]
    public function testAmbiguousOrUnsupportedComponentsCannotProduceABase(string $components): void
    {
        $http = new HttpSignature();
        $candidate = $http->parse('sig=' . $components, 'sig=:AA==:')['sig'];
        $request = Request::create('https://shop.test/?token=private');
        $request->headers->set('content-digest', 'sha-256=:AA==:');

        $this->expectException(\UnexpectedValueException::class);
        $http->base($request, $candidate['input']);
    }

    public function testOversizedSignatureInputIsRejectedBeforeParsing(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('signature_too_large');
        (new HttpSignature())->parse(str_repeat('x', 16385), 'sig=:AA==:');
    }

    public function testVerificationUsesTheExactEncodedTargetUri(): void
    {
        $http = new HttpSignature();
        $candidate = $http->parse('sig=("@target-uri")', 'sig=:AA==:')['sig'];
        $request = Request::create('https://shop.test/a%2Fb?value=a%20b&value=a+b');

        self::assertSame(
            '"@target-uri": https://shop.test/a%2Fb?value=a%20b&value=a+b' . "\n"
            . '"@signature-params": ("@target-uri")',
            $http->base($request, $candidate['input']),
        );
    }

    public function testAValidSignatureStillRequiresAPublicVerificationKey(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        $signature = sodium_crypto_sign_detached('base', sodium_crypto_sign_secretkey($pair));
        $key = ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => Jwk::encode($public)];

        self::assertTrue(Jwk::verify($key, 'ed25519', 'base', $signature));
        self::assertFalse(Jwk::verify($key + ['d' => 'not-a-public-key'], 'ed25519', 'base', $signature));
        self::assertFalse(Jwk::verify($key + ['use' => 'enc'], 'ed25519', 'base', $signature));
        self::assertFalse(Jwk::verify($key + ['key_ops' => ['sign']], 'ed25519', 'base', $signature));
        self::assertFalse(Jwk::verify($key + ['alg' => 'PS512'], 'ed25519', 'base', $signature));
    }
}
