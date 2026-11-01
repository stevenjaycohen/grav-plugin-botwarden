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

    public function testKnownAgentsVisitIncludesProtocolHeadersAndRemovesCredentialsAndQueries(): void
    {
        $visit = DarkVisitorsClient::visit([
            'REQUEST_URI' => '/mcp?access_token=private', 'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'example.invalid', 'HTTP_USER_AGENT' => 'FixtureAgent',
            'HTTP_AUTHORIZATION' => 'Bearer private', 'HTTP_COOKIE' => 'session=private',
            'HTTP_X_API_KEY' => 'private', 'HTTP_X_CSRF_TOKEN' => 'private',
            'HTTP_REFERER' => 'https://user:private@example.invalid/page?token=private#private',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.4',
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
            'HTTP_API_VERSION' => '2026-01-30', 'HTTP_UCP_AGENT' => 'FixtureCommerce',
            'CONTENT_TYPE' => 'application/json',
        ], ['Content-Type: application/json', 'Set-Cookie: private', 'Authorization: private'], 204, 12.5);
        self::assertSame('/mcp', $visit['request_path']);
        self::assertSame('POST', $visit['request_method']);
        $headers = (array)$visit['request_headers'];
        self::assertSame('FixtureAgent', $headers['user-agent']);
        self::assertSame('198.51.100.4', $headers['x-forwarded-for']);
        self::assertSame('https://example.invalid/page', $headers['referer']);
        self::assertSame('2025-06-18', $headers['mcp-protocol-version']);
        self::assertSame('2026-01-30', $headers['api-version']);
        self::assertSame('FixtureCommerce', $headers['ucp-agent']);
        self::assertSame(['content-type' => 'application/json'], $visit['response_headers']);
        self::assertSame(204, $visit['response_status_code']);
        self::assertSame(12.5, $visit['response_duration_in_milliseconds']);
        self::assertStringNotContainsString('private', json_encode($visit, JSON_THROW_ON_ERROR));
    }

    public function testKnownAgentsVisitUsesDirectClientIpAndAnObjectForEmptyHeaders(): void
    {
        $visit = DarkVisitorsClient::visit(['REMOTE_ADDR' => '198.51.100.7']);
        self::assertSame('198.51.100.7', $visit['request_headers']->{'x-forwarded-for'});
        $visit = DarkVisitorsClient::visit([]);
        self::assertSame('{}', json_encode($visit['request_headers']));
        self::assertArrayNotHasKey('response_status_code', $visit);
        self::assertSame('/', $visit['request_path']);
    }

    public function testKnownAgentsVisitRequiresProjectTokenBeforeMakingAnyRequest(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('project access token missing');
        (new DarkVisitorsClient(''))->reportVisit(DarkVisitorsClient::visit([]));
    }
}
