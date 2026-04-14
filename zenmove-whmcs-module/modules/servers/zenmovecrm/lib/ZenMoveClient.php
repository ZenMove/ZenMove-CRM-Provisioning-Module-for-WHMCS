<?php
/**
 * ZenMoveClient — HTTP client for the ZenMove Reseller API
 *
 * Wraps all reseller lifecycle endpoints with typed methods.
 * Uses cURL (always available in WHMCS environments).
 *
 * Usage:
 *   $client = new ZenMoveClient('https://zenmove.ca', 'zm_key', 'secret');
 *   $result = $client->provision([...]);
 *   if (!$result['ok']) throw new RuntimeException($result['message'] ?? 'Unknown error');
 */

if (!class_exists('ZenMoveClient')) {

class ZenMoveClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;
    private int    $timeout;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        string $apiSecret,
        int    $timeout = 30
    ) {
        $this->baseUrl   = rtrim(trim($baseUrl), '/');
        $this->apiKey    = trim($apiKey);
        $this->apiSecret = trim($apiSecret);
        $this->timeout   = max(5, $timeout);
    }

    /* ── Public API methods ──────────────────────────────────────── */

    /**
     * Provision a new CRM instance.
     *
     * @param array $data {
     *   company_name:     string  (required)
     *   subdomain:        string  (required if domain not given)
     *   domain:           string  (required if subdomain not given)
     *   plan:             string  crm_starter|crm_growth|crm_pro
     *   whmcs_service_id: string  WHMCS serviceid for lifecycle correlation
     *   mode:             string  async (default) | sync
     *   meta:             array   optional key-value context
     * }
     */
    public function provision(array $data): array
    {
        return $this->post('/api/reseller/provision.php', $data);
    }

    /**
     * Suspend an active CRM instance.
     * Identify by whmcs_service_id, domain, or job_id.
     */
    public function suspend(array $data): array
    {
        return $this->post('/api/reseller/suspend.php', $data);
    }

    /**
     * Reactivate a suspended CRM instance.
     */
    public function unsuspend(array $data): array
    {
        return $this->post('/api/reseller/unsuspend.php', $data);
    }

    /**
     * Permanently terminate a CRM instance.
     * Accepts optional 'reason' string for audit trail.
     */
    public function terminate(array $data): array
    {
        return $this->post('/api/reseller/terminate.php', $data);
    }

    /**
     * Change the CRM plan (upgrade or downgrade).
     * Requires: identifier + plan (crm_starter|crm_growth|crm_pro)
     */
    public function changePlan(array $data): array
    {
        return $this->post('/api/reseller/change_plan.php', $data);
    }

    /**
     * Get current instance status and details.
     * Returns job_status (queue state) + instance_status (billing lifecycle).
     * Pass include_logs=true to also get the last 20 provisioning log entries.
     */
    public function getInstance(array $params, bool $includeLogs = false): array
    {
        $query = $params;
        if ($includeLogs) {
            $query['include_logs'] = 1;
        }
        return $this->get('/api/reseller/instance.php', $query);
    }

    /* ── Private transport ───────────────────────────────────────── */

    private function post(string $path, array $data): array
    {
        $url  = $this->baseUrl . $path;
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $this->buildHeaders(),
            CURLOPT_FAILONERROR    => false,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        return $this->parseResponse($raw, $httpCode, $curlErr, $path);
    }

    private function get(string $path, array $params = []): array
    {
        $url = $this->baseUrl . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $this->buildHeaders(),
            CURLOPT_FAILONERROR    => false,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        return $this->parseResponse($raw, $httpCode, $curlErr, $path);
    }

    private function buildHeaders(): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-KEY: ' . $this->apiKey,
        ];

        if ($this->apiSecret !== '') {
            $headers[] = 'X-API-SECRET: ' . $this->apiSecret;
        }

        return $headers;
    }

    private function parseResponse(?string $raw, int $httpCode, string $curlErr, string $path): array
    {
        // cURL-level failure (DNS, timeout, TLS, etc.)
        if ($curlErr !== '') {
            return [
                'ok'      => false,
                'error'   => 'curl_error',
                'message' => "Connection to ZenMove API failed: {$curlErr}",
                'path'    => $path,
            ];
        }

        // Empty body (shouldn't happen with a healthy server)
        if ($raw === false || trim((string)$raw) === '') {
            return [
                'ok'          => false,
                'error'       => 'empty_response',
                'message'     => "ZenMove API returned an empty response (HTTP {$httpCode}).",
                'http_status' => $httpCode,
                'path'        => $path,
            ];
        }

        $decoded = json_decode((string)$raw, true);

        // Non-JSON body (PHP fatal, nginx 502, etc.)
        if (!is_array($decoded)) {
            return [
                'ok'          => false,
                'error'       => 'invalid_json',
                'message'     => "ZenMove API returned non-JSON (HTTP {$httpCode}). Check the API URL and server health.",
                'http_status' => $httpCode,
                'raw'         => substr((string)$raw, 0, 500),
                'path'        => $path,
            ];
        }

        // Attach HTTP status to every response for caller inspection
        $decoded['http_status'] = $httpCode;

        return $decoded;
    }
}

} // end class_exists guard
