<?php
namespace local_msteams\local;

defined('MOODLE_INTERNAL') || die();

final class graph_client {
    /** @var string */
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    /**
     * @return bool
     */
    public function is_configured(): bool {
        return !empty(get_config('local_msteams', 'graphtenantid'))
            && !empty(get_config('local_msteams', 'graphclientid'))
            && !empty(get_config('local_msteams', 'graphclientsecret'))
            && !empty(get_config('local_msteams', 'graphorganizer'));
    }

    /**
     * @param string $path
     * @param array|null $body
     * @return array
     */
    public function post(string $path, ?array $body = null): array {
        return $this->request('POST', $path, $body);
    }

    /**
     * @param string $path
     * @param array|null $body
     * @return array
     */
    public function patch(string $path, ?array $body = null): array {
        return $this->request('PATCH', $path, $body);
    }

    /**
     * @param string $path
     * @return void
     */
    public function delete(string $path): void {
        $this->request('DELETE', $path, null);
    }

    /**
     * @return string
     */
    private function get_token(): string {
        $tenantid = trim((string)get_config('local_msteams', 'graphtenantid'));
        $clientid = trim((string)get_config('local_msteams', 'graphclientid'));
        $clientsecret = (string)get_config('local_msteams', 'graphclientsecret');

        if (empty($tenantid) || empty($clientid) || empty($clientsecret)) {
            throw new \moodle_exception('graphnotconfigured', 'local_msteams');
        }

        $url = 'https://login.microsoftonline.com/' . rawurlencode($tenantid) . '/oauth2/v2.0/token';
        $payload = http_build_query([
            'client_id' => $clientid,
            'client_secret' => $clientsecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->send_request('POST', $url, $payload, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        if (empty($response['access_token'])) {
            throw new \moodle_exception('graphtokenfailed', 'local_msteams');
        }

        return (string)$response['access_token'];
    }

    /**
     * @param string $method
     * @param string $path
     * @param array|null $body
     * @return array
     */
    private function request(string $method, string $path, ?array $body = null): array {
        $token = $this->get_token();
        $url = rtrim(self::GRAPH_BASE, '/') . '/' . ltrim($path, '/');
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);

        return $this->send_request($method, $url, $payload, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
    }

    /**
     * @param string $method
     * @param string $url
     * @param string|null $payload
     * @param array $headers
     * @return array
     */
    private function send_request(string $method, string $url, ?string $payload, array $headers): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \moodle_exception('graphcurlfailed', 'local_msteams');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('local_msteams graph curl failure: ' . $method . ' ' . $url . ' error=' . $error);
            throw new \moodle_exception('graphcurlfailed', 'local_msteams', '', null, $error);
        }

        if ($status >= 400) {
            error_log('local_msteams graph request failed: ' . $method . ' ' . $url . ' status=' . $status);
            throw new \moodle_exception('graphrequestfailed', 'local_msteams', '', null, $raw);
        }

        if ($raw === '' || $raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
