<?php declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Domain\Evidence;
use Aithos\Agentenblick\Verification\Catalogue;
use Aithos\Agentenblick\Verification\HttpSignature;
use Aithos\Agentenblick\Verification\Jwk;
use Aithos\Agentenblick\Verification\WebBotAuth;
use phpseclib3\Crypt\PublicKeyLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WebBotAuthTest extends TestCase
{
    public static function vectors(): iterable
    {
        foreach (json_decode(file_get_contents(__DIR__ . '/../fixtures/webbotauth-vectors.json'), true, 32, JSON_THROW_ON_ERROR) as $vector) {
            yield $vector['id'] => [$vector];
        }
    }

    #[DataProvider('vectors')]
    public function testPublishedWebBotAuthVector(array $vector): void
    {
        $key = $this->key($vector['algorithm']);
        $request = Request::create('https://example.com/');
        $request->headers->add($vector['headers']);
        $catalogue = new Catalogue(__DIR__ . '/../fixtures/catalog.json', 'test');
        $result = (new WebBotAuth())->verify($request, $catalogue, static fn (): array => ['keys' => [$key]], $vector['now']);
        self::assertSame(Evidence::Signature, $result->evidence, $result->reason);
        self::assertSame(Jwk::thumbprint($key), $result->keyId);
        // The same authentic signature must not verify for another target authority.
        $request->headers->set('Host', 'attacker.example');
        $result = (new WebBotAuth())->verify($request, $catalogue, static fn (): array => ['keys' => [$key]], $vector['now']);
        self::assertSame(Evidence::Declared, $result->evidence);
        self::assertSame('bad_signature', $result->reason);
    }

    public function testExpiryUnknownKeyAndUnavailableDirectoryCannotVerify(): void
    {
        $vectors = iterator_to_array(self::vectors());
        [$vector] = $vectors['webbotauth-00 E.2.2.'];
        $request = Request::create('https://example.com/');
        $request->headers->add($vector['headers']);
        $catalogue = new Catalogue(__DIR__ . '/../fixtures/catalog.json', 'test');
        $auth = new WebBotAuth();
        $key = $this->key('ed25519');
        self::assertSame('expired', $auth->verify($request, $catalogue, static fn (): array => ['keys' => [$key]], 1735693300)->reason);
        self::assertSame('unknown_key', $auth->verify($request, $catalogue, static fn (): array => ['keys' => []], $vector['now'])->reason);
        self::assertSame('directory_unreachable', $auth->verify($request, $catalogue, static fn () => throw new \RuntimeException(), $vector['now'])->reason);
    }

    public function testRfc9421B26Ed25519Vector(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/../fixtures/rfc9421-b26.json'), true, 32, JSON_THROW_ON_ERROR);
        $request = Request::create('https://example.com/foo?param=Value&Pet=dog', 'POST');
        $request->headers->add(['date' => 'Tue, 20 Apr 2021 02:07:55 GMT', 'content-type' => 'application/json', 'content-length' => '18']);
        $http = new HttpSignature();
        $candidate = $http->parse($fixture['headers']['Signature-Input'], $fixture['headers']['Signature'])['sig-b26'];
        $base = $http->base($request, $candidate['input']);
        self::assertTrue(Jwk::verify($this->key('ed25519'), 'ed25519', $base, $candidate['signature']));
        self::assertFalse(Jwk::verify($this->key('ed25519'), 'ed25519', $base . 'x', $candidate['signature']));
    }

    public function testRfc9421B24ResponseCanonicalization(): void
    {
        // B.2.4 is an ECDSA RESPONSE, not an RSA-PSS request. Exercise its exact
        // canonicalization; do not silently add ECDSA to the product's allowed algorithms.
        $fixture = json_decode(file_get_contents(__DIR__ . '/../fixtures/rfc9421-b24.json'), true, 32, JSON_THROW_ON_ERROR);
        $digest = 'sha-512=:mEWXIS7MaLRuGgxOBdODa3xqM1XdEvxoYhvlCFJ41QJgJc4GTsPp29l5oGX69wWdXymyU0rjJuahq4l5aGgfLQ==:';
        $response = new Response('', 200, ['content-type' => 'application/json', 'content-length' => '23', 'content-digest' => $digest]);
        $http = new HttpSignature();
        $candidate = $http->parse($fixture['headers']['Signature-Input'], $fixture['headers']['Signature'])['sig-b24'];
        $expected = '"@status": 200' . "\n" . '"content-type": application/json' . "\n" . '"content-digest": ' . $digest . "\n" . '"content-length": 23' . "\n" . '"@signature-params": ("@status" "content-type" "content-digest" "content-length");created=1618884473;keyid="test-key-ecc-p256"';
        self::assertSame($expected, $http->base($response, $candidate['input']));
        self::assertFalse(Jwk::verify($this->key('ed25519'), 'ecdsa-p256-sha256', $expected, $candidate['signature']));
    }

    private function key(string $algorithm): array
    {
        if ($algorithm === 'ed25519') {
            return ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => 'JrQLj5P_89iXES9-vFgrIy29clF9CC_oPPsw3c5D0bs'];
        }
        $key = PublicKeyLoader::loadPublicKey(file_get_contents(__DIR__ . '/../fixtures/rfc9421-rsa-pss-public.txt'));
        $export = json_decode($key->toString('JWK'), true, 32, JSON_THROW_ON_ERROR);
        return $export['keys'][0] ?? $export;
    }
}
