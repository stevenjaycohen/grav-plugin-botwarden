<?php
namespace Grav\Plugin\Botwarden;

/** Known Agents (formerly Dark Visitors) robots-txts and visits APIs. */
final class DarkVisitorsClient
{
    public array $lastVisitDiagnostics = [];
    public function __construct(private string $apiKey) {}

    /** Send one visit as the batch required by the REST API. */
    public function reportVisit(array $visit): void
    {
        $this->reportVisits([$visit]);
    }

    /** Send a small batch; only HTTP 204 acknowledges the entire batch. */
    public function reportVisits(array $visits, int $timeout = 3): void
    {
        $this->lastVisitDiagnostics = [];
        if (trim($this->apiKey) === '') {
            throw new \RuntimeException('Known Agents project access token missing');
        }
        if (!$visits || !array_is_list($visits) || count($visits) > 25) {
            throw new \InvalidArgumentException('Known Agents visit batch must contain 1–25 events');
        }
        $body = json_encode($visits, JSON_THROW_ON_ERROR);
        try {
            $response = HttpClient::request('POST', 'https://api.knownagents.com/visits',
                ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'],
                $body, max(1, min(3, $timeout)), true);
        } finally {
            // Never retain a response body, request headers, or the configured token in diagnostics.
            $detail = HttpClient::diagnostics();
            array_walk_recursive($detail, function (&$value): void {
                if (is_string($value)) $value = str_replace($this->apiKey, '[redacted]', $value);
            });
            $this->lastVisitDiagnostics = $detail;
        }
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
