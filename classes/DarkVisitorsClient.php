<?php
namespace Grav\Plugin\Botwarden;

/** Known Agents (formerly Dark Visitors) robots-txts and visits APIs. */
final class DarkVisitorsClient
{
    public function __construct(private string $apiKey) {}

    /** Send one visit as the batch required by the REST API. */
    public function reportVisit(array $visit): void
    {
        if (trim($this->apiKey) === '') {
            throw new \RuntimeException('Known Agents project access token missing');
        }
        $response = HttpClient::request('POST', 'https://api.knownagents.com/visits',
            ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'],
            json_encode([$visit], JSON_THROW_ON_ERROR), 3
        );
        if ($response['status'] !== 204) {
            throw new \RuntimeException('Known Agents visits HTTP ' . $response['status'] . ' (expected 204)');
        }
    }

    /** Build a visit without credentials, cookies, query strings or request bodies. */
    public static function visit(array $server, array $responseHeaders = [], ?int $status = null, ?float $duration = null): array
    {
        $headers = [];
        foreach (['host', 'user-agent', 'referer', 'x-forwarded-for', 'x-real-ip',
            'cf-connecting-ip', 'true-client-ip', 'forwarded', 'mcp-protocol-version',
            'api-version', 'ucp-agent', 'content-type'] as $name) {
            $key = $name === 'content-type' ? 'CONTENT_TYPE' : 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            if (!empty($server[$key])) $headers[$name] = (string)$server[$key];
        }
        if (!isset($headers['x-forwarded-for']) && filter_var($server['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP)) {
            $headers['x-forwarded-for'] = (string)$server['REMOTE_ADDR'];
        }
        if (isset($headers['referer'])) {
            $parts = parse_url($headers['referer']);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $headers['referer'] = $parts['scheme'] . '://' . $parts['host']
                    . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '/');
            } else {
                unset($headers['referer']);
            }
        }
        $visit = [
            'request_path' => parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            'request_method' => $server['REQUEST_METHOD'] ?? 'GET',
            'request_headers' => (object)$headers,
        ];
        if ($status !== null) $visit['response_status_code'] = $status;
        foreach ($responseHeaders as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $visit['response_headers'] = ['content-type' => trim(substr($header, 13))];
            }
        }
        if ($duration !== null) $visit['response_duration_in_milliseconds'] = max(0, $duration);
        return $visit;
    }

    /** @param string[] $agentTypes @return string[] */
    public function fetchAgents(array $agentTypes): array
    {
        return self::parseAgents($this->fetchRobotsTxt($agentTypes));
    }

    /** @param string[] $agentTypes */
    public function fetchRobotsTxt(array $agentTypes): string
    {
        if ($this->apiKey === '' || !$agentTypes) {
            throw new \RuntimeException('Known Agents API key or agent types missing');
        }
        $response = HttpClient::request('POST', 'https://api.knownagents.com/robots-txts',
            ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'],
            json_encode(['agent_types' => array_values($agentTypes), 'disallow' => '/'], JSON_THROW_ON_ERROR)
        );
        return HttpClient::requireSuccess($response, 'Known Agents');
    }

    /** Parses validated User-agent directives; rejects failed/empty fetches. */
    public static function parseAgents(string $text): array
    {
        $agents = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (preg_match('/^\s*User-agent:\s*(.*?)\s*$/i', $line, $m)) {
                $ua = trim($m[1]);
                if ($ua !== '' && $ua !== '*' && strlen($ua) <= 512 && !preg_match('/[\x00-\x1f]/', $ua)) {
                    $agents[$ua] = $ua;
                }
            }
        }
        if (!$agents) {
            throw new \RuntimeException('Known Agents returned no valid agents; existing list preserved');
        }
        return array_values($agents);
    }
}
