<?php
namespace Grav\Plugin\Botwarden;

/** AbuseIPDB bulk list and deferred reports. Network failures never return an empty success. */
final class AbuseIpDbClient
{
    public function __construct(private array $config) {}

    /** @return string[] */
    public function fetchBulkBlacklist(int $limit = 10000): array
    {
        if (empty($this->config['api_key'])) {
            throw new \RuntimeException('AbuseIPDB API key is not configured');
        }
        $response = HttpClient::request(
            'GET',
            'https://api.abuseipdb.com/api/v2/blacklist?limit=' . max(1, min($limit, 500000)),
            ['Key: ' . $this->config['api_key'], 'Accept: text/plain']
        );
        return self::parseBlacklist(HttpClient::requireSuccess($response, 'AbuseIPDB'));
    }

    /** Strict validation before changing persistent data. */
    public static function parseBlacklist(string $body): array
    {
        $ips = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strlen($line) > 50 || !filter_var($line, FILTER_VALIDATE_IP)) {
                throw new \RuntimeException('AbuseIPDB returned malformed blacklist content');
            }
            $ips[$line] = $line;
        }
        if (!$ips) {
            throw new \RuntimeException('AbuseIPDB returned an empty blacklist; existing list preserved');
        }
        return array_values($ips);
    }

    public function reportIp(string $ip, string $path, int $category = 21): bool
    {
        if (empty($this->config['api_key'])) return false;
        try {
            $response = HttpClient::request(
                'POST',
                'https://api.abuseipdb.com/api/v2/report',
                ['Key: ' . $this->config['api_key'], 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
                http_build_query([
                    'ip' => $ip, 'categories' => (string)$category,
                    'comment' => 'Blocked by botWarden. Path: ' . substr($path, 0, 500),
                ])
            );
            return $response['status'] === 200;
        } catch (\Throwable $error) {
            error_log('[botWarden] AbuseIPDB report failed: ' . $error->getMessage());
            return false;
        }
    }
}
