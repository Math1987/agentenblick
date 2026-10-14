<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit;

use Aithos\Agentenblick\Verification\Classifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ClassifierCandidateTest extends TestCase
{
    /** @return iterable<string, array{array<string, string>}> */
    public static function ordinarySignatures(): iterable
    {
        yield 'no signature' => [[]];
        yield 'ordinary signed API request' => [[
            'Signature-Input' => 'api=("@authority");tag="payment-api"',
            'Signature' => 'api=:YWJj:',
        ]];
        yield 'malformed structured signature field' => [[
            'Signature-Input' => 'bot=("@authority");tag="web-bot-auth"',
            'Signature' => 'bot=not-a-byte-sequence',
        ]];
        yield 'different dictionary labels' => [[
            'Signature-Input' => 'bot=("@authority");tag="web-bot-auth"',
            'Signature' => 'unrelated=:YWJj:',
        ]];
        yield 'tag appears only in unsigned origin text' => [[
            'Signature-Agent' => '"https://web-bot-auth.example.test"',
            'Signature-Input' => 'api=("@authority")',
            'Signature' => 'api=:YWJj:',
        ]];
    }

    #[DataProvider('ordinarySignatures')]
    public function testOrdinaryOrMalformedSignaturesDoNotDeclareAiTraffic(array $headers): void
    {
        $request = Request::create('https://shop.test/');
        $request->headers->add($headers);
        self::assertFalse(Classifier::hasWebBotAuthCandidate($request));
    }

    public function testWebBotAuthCandidateIsFoundAmongUnrelatedSignatures(): void
    {
        $request = Request::create('https://shop.test/');
        $request->headers->add([
            'Signature-Input' => 'api=("@authority");tag="ordinary", bot=("@authority");tag="web-bot-auth"',
            'Signature' => 'api=:YWJj:, bot=:YWJj:',
        ]);

        // This only selects the verifier. These dummy signature bytes provide no proof.
        self::assertTrue(Classifier::hasWebBotAuthCandidate($request));
    }
}
