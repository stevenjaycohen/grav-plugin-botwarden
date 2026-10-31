<?php
namespace Grav\Plugin\Botwarden;

/** Known Agents (formerly Dark Visitors) robots-txts API. */
final class DarkVisitorsClient
{
    public function __construct(private string $apiKey) {}

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
