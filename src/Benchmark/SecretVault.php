<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Benchmark;

final class SecretVault
{
    public function __construct(private readonly string $kernelSecret)
    {
        if ($kernelSecret === '') {
            throw new \InvalidArgumentException('A kernel secret is required.');
        }
    }

    public function encrypt(string $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $this->key()));
    }

    public function decrypt(string $value): string
    {
        $bytes = base64_decode($value, true);
        if ($bytes === false || strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('benchmark_secret_unavailable');
        }
        $plain = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
        if ($plain === false) {
            throw new \RuntimeException('benchmark_secret_unavailable');
        }
        return $plain;
    }

    private function key(): string
    {
        return hash('sha256', "agentenblick.benchmark.secret/1\0" . $this->kernelSecret, true);
    }
}
