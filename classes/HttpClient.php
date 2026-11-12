<?php
namespace Grav\Plugin\Botwarden;

/**
 * Self-contained HTTP transport (no undeclared Grav/Guzzle runtime dependencies).
 * Does not follow redirects or expose request headers/secrets in exceptions.
 */
final class HttpClient
{
    private static array $lastDiagnostics = [];

    public static function diagnostics(): array { return self::$lastDiagnostics; }

    /** @return array{status:int,body:string} */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 10,
        bool $collectDiagnostics = false
    ): array {
        self::$lastDiagnostics = [];
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
            $responseBody = '';
            $oversized = false;
            $responseHeaders = [];
            $options = [
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$oversized): int {
                    if (strlen($responseBody) + strlen($chunk) > 32 * 1024 * 1024) {
                        $oversized = true;
                        return 0;
                    }
                    $responseBody .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'botWarden/1.3.0',
            ];
            if ($collectDiagnostics) {
                $options[CURLOPT_HEADERFUNCTION] = static function ($handle, string $line) use (&$responseHeaders): int {
                    if (str_starts_with($line, 'HTTP/')) $responseHeaders = [];
                    if (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $name = strtolower(trim($name));
                        if (in_array($name, ['server', 'via', 'cf-ray', 'x-request-id', 'retry-after'], true)) {
                            $responseHeaders[$name] = substr(preg_replace('/[^\x20-\x7e]/', '', trim($value)), 0, 160);
                        }
                    }
                    return strlen($line);
                };
            }
            if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
            curl_setopt_array($handle, $options);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($collectDiagnostics) {
                self::$lastDiagnostics = ['status' => $status, 'curl_error' => curl_errno($handle), 'headers' => $responseHeaders];
                foreach (['dns_ms' => CURLINFO_NAMELOOKUP_TIME, 'connect_ms' => CURLINFO_CONNECT_TIME,
                    'tls_ms' => CURLINFO_APPCONNECT_TIME, 'first_byte_ms' => CURLINFO_STARTTRANSFER_TIME,
                    'total_ms' => CURLINFO_TOTAL_TIME] as $name => $option) {
                    self::$lastDiagnostics[$name] = round(max(0, (float)curl_getinfo($handle, $option)) * 1000, 2);
                }
            }
            if ($oversized) {
                throw new \RuntimeException('HTTP response exceeds 32 MiB limit');
            }
            if ($response === false) {
                throw new \RuntimeException('HTTP transport error (' . curl_errno($handle) . ')');
            }
            return ['status' => $status, 'body' => $responseBody];
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
