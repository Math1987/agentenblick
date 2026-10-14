<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Verification;

use phpseclib3\Crypt\RSA;

final class Jwk
{
    public static function decode(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $value) || strlen($value) % 4 === 1) {
            throw new \UnexpectedValueException('invalid_jwk_encoding');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || self::encode($decoded) !== $value) {
            throw new \UnexpectedValueException('invalid_jwk_encoding');
        }
        return $decoded;
    }

    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $key */
    public static function thumbprint(array $key): string
    {
        $members = match ($key['kty'] ?? null) {
            'OKP' => ['crv', 'kty', 'x'],
            'RSA' => ['e', 'kty', 'n'],
            default => throw new \UnexpectedValueException('unsupported_key_type'),
        };
        $canonical = [];
        foreach ($members as $name) {
            if (!isset($key[$name]) || !is_string($key[$name])) {
                throw new \UnexpectedValueException('incomplete_jwk');
            }
            $canonical[$name] = $key[$name];
        }
        return self::encode(hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true));
    }

    /** @param array<string, mixed> $key */
    public static function verify(array $key, string $algorithm, string $base, string $signature): bool
    {
        if (isset($key['d']) || (($key['use'] ?? 'sig') !== 'sig')
            || (isset($key['key_ops']) && (!is_array($key['key_ops']) || !in_array('verify', $key['key_ops'], true)))) {
            return false;
        }
        if ($algorithm === 'ed25519' && ($key['kty'] ?? null) === 'OKP' && ($key['crv'] ?? null) === 'Ed25519') {
            if (!in_array($key['alg'] ?? 'EdDSA', ['EdDSA', 'Ed25519'], true)) {
                return false;
            }
            $public = self::decode($key['x']);
            return strlen($public) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
                && sodium_crypto_sign_verify_detached($signature, $base, $public);
        }
        if ($algorithm === 'rsa-pss-sha512' && ($key['kty'] ?? null) === 'RSA' && ($key['alg'] ?? 'PS512') === 'PS512') {
            $modulus = self::decode($key['n']);
            if (strlen($modulus) < 256 || strlen($modulus) > 1024) {
                return false;
            }
            $rsa = RSA::loadPublicKeyFormat('JWK', json_encode($key, JSON_THROW_ON_ERROR));
            if (!$rsa instanceof RSA\PublicKey) {
                return false;
            }
            return $rsa->withPadding(RSA::SIGNATURE_PSS)->withHash('sha512')->withMGFHash('sha512')->withSaltLength(64)->verify($base, $signature);
        }
        return false;
    }
}
