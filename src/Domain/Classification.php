<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Domain;

final readonly class Classification
{
    public function __construct(
        public string $operator = 'unknown',
        public string $agent = 'unknown',
        public Kind $kind = Kind::HumanOrUnknown,
        public Evidence $evidence = Evidence::None,
        public string $reason = 'no_evidence',
        public ?string $keyId = null,
        public ?string $signatureLabel = null,
    ) {
    }

    public function isNonHuman(): bool
    {
        return $this->kind !== Kind::HumanOrUnknown;
    }
}
