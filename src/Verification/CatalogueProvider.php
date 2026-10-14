<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Verification;

final class CatalogueProvider
{
    public function __construct(private readonly string $environment)
    {
    }

    public function load(): Catalogue
    {
        $testPath = $_SERVER['AGENTENBLICK_TEST_CATALOG'] ?? $_ENV['AGENTENBLICK_TEST_CATALOG'] ?? '';
        if ($testPath !== '' && $this->environment !== 'test') {
            throw new \LogicException('test_catalogue_forbidden');
        }
        $path = $testPath !== '' ? $testPath : dirname(__DIR__) . '/Resources/catalog/agents.json';
        return new Catalogue($path, $this->environment);
    }
}
