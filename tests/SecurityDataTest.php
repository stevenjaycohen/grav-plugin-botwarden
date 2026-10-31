<?php
namespace Grav\Plugin\Botwarden\Tests;

use Grav\Plugin\Botwarden\RobotsTxtManager;
use Grav\Plugin\Botwarden\AbuseIpDbClient;
use Grav\Plugin\Botwarden\DarkVisitorsClient;
use PHPUnit\Framework\TestCase;

final class SecurityDataTest extends TestCase
{
    public function testRobotsContentPreservedAndIdempotent(): void
    {
        $old = "User-agent: Googlebot\nAllow: /\n";
        $new = RobotsTxtManager::render($old, ['GPTBot', 'CCBot']);
        self::assertStringStartsWith($old, $new);
        self::assertStringContainsString("User-agent: GPTBot\nDisallow: /", $new);
        self::assertSame($new, RobotsTxtManager::render($new, ['GPTBot', 'CCBot']));
        self::assertSame(1, substr_count($new, RobotsTxtManager::START));
    }

    public function testRobotsRejectBadMarkersAndInvalidAgent(): void
    {
        $this->expectException(\RuntimeException::class);
        RobotsTxtManager::render(RobotsTxtManager::END, ['GPTBot']);
    }

    public function testRobotsRejectEmptyInput(): void
    {
        $this->expectException(\RuntimeException::class);
        RobotsTxtManager::render('', []);
    }

    public function testAbuseBulkParserRejectsMalformedFeed(): void
    {
        $this->expectException(\RuntimeException::class);
        AbuseIpDbClient::parseBlacklist('<html>upstream error</html>');
    }

    public function testAbuseBulkParserRejectsEmptyFeed(): void
    {
        $this->expectException(\RuntimeException::class);
        AbuseIpDbClient::parseBlacklist("");
    }

    public function testAbuseBulkParserDeduplicatesValidIp(): void
    {
        self::assertSame(['192.0.2.1', '2001:db8::1'],
            AbuseIpDbClient::parseBlacklist("192.0.2.1\n# comment\n192.0.2.1\n2001:db8::1\n"));
    }

    public function testKnownAgentsRejectsEmptyApiResponse(): void
    {
        $this->expectException(\RuntimeException::class);
        DarkVisitorsClient::parseAgents('server error');
    }

    public function testKnownAgentsParsesUserAgents(): void
    {
        self::assertSame(['GPTBot', 'CCBot'],
            DarkVisitorsClient::parseAgents("User-agent: GPTBot\nDisallow: /\nUser-agent: CCBot\nDisallow: /\n"));
    }
}
