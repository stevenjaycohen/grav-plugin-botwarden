<?php
namespace Grav\Plugin\Botwarden;

/**
 * Self-contained HTTP transport (no undeclared Grav/Guzzle runtime dependencies).
 * Does not follow redirects or expose request headers/secrets in exceptions.
 */
final class HttpClient
{
    /** @return array{status:int,body:string} */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 10
    ): array {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('botWarden requires the PHP curl extension');
        }
        if (!preg_match('~^https?://~i', $url)) {
            throw new \InvalidArgumentException('HTTP URL must use http or https');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Could not initialize HTTP request');
        }
        try {
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'botWarden/0.1',
            ];
            if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
            curl_setopt_array($handle, $options);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($response === false) {
                throw new \RuntimeException('HTTP transport error (' . curl_errno($handle) . ')');
            }
            if (strlen($response) > 32 * 1024 * 1024) {
                throw new \RuntimeException('HTTP response exceeds 32 MiB limit');
            }
            return ['status' => $status, 'body' => $response];
        } finally {
            curl_close($handle);
        }
    }

    public static function requireSuccess(array $response, string $service): string
    {
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException($service . ' HTTP ' . $response['status']);
        }
        return $response['body'];
    }
}
