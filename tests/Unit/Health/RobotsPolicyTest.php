<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Tests\Unit\Health;

use Aithos\Agentenblick\Health\RobotsPolicy;
use PHPUnit\Framework\TestCase;

final class RobotsPolicyTest extends TestCase
{
    public function testSpecificGroupsMergeAndAllowWinsAnEquivalentRule(): void
    {
        $policy = new RobotsPolicy("User-agent: *\nDisallow: /\nUser-agent: ExampleBot\nDisallow: /private\nUser-agent: examplebot\nAllow: /private/public\nDisallow: /private/public\n");
        self::assertSame('allow', $policy->decide('EXAMPLEBOT', '/private/public?q=personal')['decision']);
        self::assertSame('disallow', $policy->decide('examplebot', '/private/secret')['decision']);
        self::assertSame('allow', $policy->decide('examplebot', '/other')['decision']);
        self::assertSame('disallow', $policy->decide('another', '/other')['decision']);
        self::assertSame('allow', $policy->decide('another', '/robots.txt')['decision']);
        self::assertStringNotContainsString('personal', json_encode($policy->decide('examplebot', '/private/public?q=personal'), JSON_THROW_ON_ERROR));
    }

    public function testWildcardsAnchorsCaseAndPercentEncoding(): void
    {
        $policy = new RobotsPolicy("User-agent: *\nDisallow: /*.gif$\nDisallow: /private/\nAllow: /private/open\nDisallow: /foo/bar\nDisallow: /foo/ツ\nDisallow: /literal-%2A\nDisallow: /money-%24\nDisallow: /query?block=true$\n");
        foreach (['/x.gif', '/private/secret', '/foo/%62%61%72', '/foo/%E3%83%84', '/literal-*', '/money-$', '/query?block=true'] as $uri) {
            self::assertSame('disallow', $policy->decide('bot', $uri)['decision'], $uri);
        }
        foreach (['/x.gif?x=1', '/private/open', '/Private/secret', '/foo%2Fbar', '/query?block=false'] as $uri) {
            self::assertSame('allow', $policy->decide('bot', $uri)['decision'], $uri);
        }
    }

    public function testEncodingAndLiteralSpecialCharactersDoNotChangeRulePriority(): void
    {
        $policy = new RobotsPolicy("User-agent: *\nDisallow: /foo/ツ\nAllow: /foo/%E3%83%84\nDisallow: /path/file-with-a-%2A.html\nDisallow: /path/foo-%24\nAllow: /path/foo-*\n");
        self::assertSame('allow', $policy->decide('bot', '/foo/ツ')['decision']);
        self::assertSame('allow', $policy->decide('bot', '/foo/%E3%83%84')['decision']);
        self::assertSame('disallow', $policy->decide('bot', '/path/file-with-a-*.html')['decision']);
        self::assertSame('disallow', $policy->decide('bot', '/path/foo-$')['decision']);
        self::assertSame('allow', $policy->decide('bot', '/path/foo-other')['decision']);
        self::assertSame($policy->decide('bot', '/foo/ツ'), RobotsPolicy::restore($policy->export())->decide('bot', '/foo/ツ'));
    }

    public function testCommentsOtherRecordsAndEmptyGroupsRemainStableAfterStorage(): void
    {
        $policy = new RobotsPolicy("\xEF\xBB\xBFDisallow: /ignored\nUser-agent: first\nDisallow: /a # private comment\nSitemap: https://shop.test/?private=comment\nAllow: /a/open\nUser-agent: empty\nDisallow:\nUser-agent: third\nDisallow: /\n");
        $stored = $policy->normalized();
        self::assertStringNotContainsString('private', $stored);
        self::assertStringNotContainsString('Sitemap', $stored);
        $restored = new RobotsPolicy($stored);
        self::assertSame('allow', $restored->decide('empty', '/')['decision']);
        self::assertSame('disallow', $restored->decide('third', '/')['decision']);
        self::assertSame($policy->decide('first', '/a/open'), $restored->decide('first', '/a/open'));
    }

    public function testAdversarialPatternsReturnUnknownWithinAFixedWorkBudget(): void
    {
        $policy = new RobotsPolicy("User-agent: *\nDisallow: /*" . str_repeat('a', 512) . "b$\n");
        self::assertSame('unknown', $policy->decide('bot', '/' . str_repeat('a', 8190))['decision']);
    }

    public function testAtLeast500KiBAreAcceptedButOversizedDocumentsAreNot(): void
    {
        $policy = new RobotsPolicy(str_repeat('#', RobotsPolicy::MAX_BYTES));
        self::assertSame('allow', $policy->decide('bot', '/')['decision']);
        $this->expectException(\UnexpectedValueException::class);
        new RobotsPolicy(str_repeat('#', RobotsPolicy::MAX_BYTES + 1));
    }
}
