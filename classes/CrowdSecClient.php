<?php
namespace Grav\Plugin\Botwarden;

use SQLite3;

final class CrowdSecClient
{
    public function __construct(private array $config, private SQLite3 $db) {}

    /** @return array{decision:string,score:int,reason:string,cacheable:bool} */
    public function checkIp(string $ip): array
    {
        return ($this->config['mode'] ?? 'cti') === 'lapi' ? $this->checkViaLapi($ip) : $this->checkViaCti($ip);
    }

    private function checkViaCti(string $ip): array
    {
        if (empty($this->config['cti_api_key'])) return self::clean();
        $quotaUntil = BotwardenDatabase::getMeta($this->db, 'crowdsec_cti_quota_reset');
        if ($quotaUntil !== null && (int)$quotaUntil > time()) return self::clean('CTI quota exceeded');
        try {
            $response = HttpClient::request('GET', 'https://cti.api.crowdsec.net/v2/smoke/' . rawurlencode($ip),
                ['x-api-key: ' . $this->config['cti_api_key']], null, 5);
            if ($response['status'] === 404) return self::clean('', true);
            if ($response['status'] === 429) {
                $midnight = (new \DateTime('tomorrow', new \DateTimeZone('UTC')))->getTimestamp();
                BotwardenDatabase::setMeta($this->db, 'crowdsec_cti_quota_reset', (string)$midnight);
                return self::clean('CTI quota exceeded');
            }
            $body = json_decode(HttpClient::requireSuccess($response, 'CrowdSec CTI'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($body) || !isset($body['scores']['overall']['total'])) {
                throw new \RuntimeException('Invalid CrowdSec CTI response');
            }
            $total = (int)round((float)$body['scores']['overall']['total']);
            $threshold = (int)($this->config['cti_score_threshold'] ?? 3);
            $behaviors = array_column($body['behaviors'] ?? [], 'label');
            return [
                'decision' => $total >= $threshold ? 'block' : 'clean',
                'score' => $total, 'reason' => implode(', ', array_slice($behaviors, 0, 3)),
                'cacheable' => true,
            ];
        } catch (\Throwable $e) {
            error_log('[botWarden] CrowdSec CTI lookup failed: ' . $e->getMessage());
            return self::clean();
        }
    }

    private function checkViaLapi(string $ip): array
    {
        if (empty($this->config['lapi_api_key'])) return self::clean();
        try {
            $base = rtrim($this->config['lapi_url'] ?? 'http://localhost:8080', '/');
            $response = HttpClient::request('GET', $base . '/v1/decisions?ip=' . rawurlencode($ip),
                ['Authorization: ApiKey ' . $this->config['lapi_api_key']], null, 3);
            $payload = HttpClient::requireSuccess($response, 'CrowdSec LAPI');
            $decisions = trim($payload) === '' ? [] : json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decisions)) throw new \RuntimeException('Invalid CrowdSec LAPI response');
            foreach ($decisions as $decision) {
                if (is_array($decision) && ($decision['type'] ?? '') === 'ban') {
                    return ['decision' => 'block', 'score' => 5,
                        'reason' => (string)($decision['scenario'] ?? 'CrowdSec ban'), 'cacheable' => true];
                }
            }
            return self::clean('', true);
        } catch (\Throwable $e) {
            error_log('[botWarden] CrowdSec LAPI lookup failed: ' . $e->getMessage());
            return self::clean();
        }
    }

    private static function clean(string $reason = '', bool $cacheable = false): array
    {
        return ['decision' => 'clean', 'score' => 0, 'reason' => $reason, 'cacheable' => $cacheable];
    }
}
