<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Heuristics;

/** Request-local factories are discarded after capture; no candidate is persisted. */
final readonly class Candidate
{
    /** @param \Closure(): array{enabled: bool, threshold: int, version: string, epoch: string} $configuration
     * @param \Closure(): array<string, mixed> $materialize
     */
    public function __construct(
        public int $flags,
        public bool $cookiePresent,
        public bool $cookieOffered,
        public \Closure $configuration,
        public \Closure $materialize,
    ) {
    }
}
