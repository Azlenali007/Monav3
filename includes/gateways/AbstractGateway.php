<?php
/**
 * Mona SMM Panel v2 - Abstract Gateway Base Class
 */

declare(strict_types=1);

namespace Mona\Gateways;

require_once __DIR__ . '/GatewayInterface.php';
require_once __DIR__ . '/../functions.php';

abstract class AbstractGateway implements GatewayInterface {
    protected array $config = [];
    protected array $credentials = [];
    protected string $environment = 'live';

    public function __construct(array $dbRecord = []) {
        $this->config = $dbRecord;
        $this->environment = $dbRecord['environment'] ?? 'live';
        
        if (!empty($dbRecord['credentials'])) {
            $rawCreds = json_decode((string)$dbRecord['credentials'], true);
            if (is_array($rawCreds)) {
                foreach ($rawCreds as $key => $val) {
                    if (is_string($val) && str_starts_with($val, 'enc:')) {
                        $this->credentials[$key] = decryptGatewaySecret(substr($val, 4));
                    } else {
                        $this->credentials[$key] = (string)$val;
                    }
                }
            }
        }
    }

    public function isSandbox(): bool {
        return $this->environment === 'sandbox';
    }

    public function getCredential(string $key, string $default = ''): string {
        return $this->credentials[$key] ?? $default;
    }

    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Reusable, secure outbound cURL HTTP client for payment gateway APIs
     */
    protected function makeRequest(
        string $url,
        string $method = 'GET',
        $payload = null,
        array $headers = [],
        int $timeout = 25
    ): array {
        $ch = curl_init();

        $defaultHeaders = [
            'User-Agent: MonaSMM-GatewayClient/2.2'
        ];

        if ($payload !== null && (is_array($payload) || is_object($payload))) {
            $payload = json_encode($payload);
            $defaultHeaders[] = 'Content-Type: application/json';
        }

        $allHeaders = array_merge($defaultHeaders, $headers);

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $allHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10
        ];

        if ($payload !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = $payload;
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'http_code' => 0,
                'error' => "Network transport error: " . $curlError,
                'data' => null
            ];
        }

        $decoded = json_decode((string)$response, true);

        return [
            'success' => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'raw_body' => $response,
            'data' => $decoded ?? null,
            'error' => ($httpCode >= 400) ? "HTTP error status {$httpCode}" : null
        ];
    }
}
