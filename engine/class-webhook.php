<?php
/**
 * POST the approved article JSON to any CMS or to the standalone reader.
 * Signature header: X-AI-Wire-Signature: sha256=<hex>
 */
class TR_AI_Wire_Engine_Webhook implements TR_AI_Wire_Engine_Destination {
    const SIGNATURE_HEADER = 'X-AI-Wire-Signature';

    private $url;
    private $secret;
    private $transport;

    public function __construct($url, $secret, $transport = null) {
        $this->url = trim((string) $url);
        $this->secret = (string) $secret;
        $this->transport = is_callable($transport) ? $transport : null;
    }

    public static function sign($secret, $body) {
        return 'sha256=' . hash_hmac('sha256', (string) $body, (string) $secret);
    }

    public static function verify($secret, $body, $header) {
        $header = trim((string) $header);
        if ($secret === '' || $header === '') {
            return false;
        }
        $expected = self::sign($secret, $body);
        if (function_exists('hash_equals')) {
            return hash_equals($expected, $header);
        }
        return $expected === $header;
    }

    public function publish($article) {
        if ($this->url === '' || !preg_match('#^https?://#i', $this->url)) {
            return array('ok' => false, 'error' => 'Webhook URL must be http or https.');
        }
        $body = TR_AI_Wire_Engine_Article::to_json($article);
        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            self::SIGNATURE_HEADER => self::sign($this->secret, $body),
        );
        $transport = $this->transport ? $this->transport : array($this, 'http_post');
        $response = call_user_func($transport, $this->url, $headers, $body);
        $code = is_array($response) ? (int) ($response['code'] ?? 0) : 0;
        if ($code < 200 || $code >= 300) {
            $error = is_array($response) ? (string) ($response['error'] ?? '') : '';
            return array(
                'ok' => false,
                'error' => $error !== '' ? $error : 'Webhook HTTP ' . $code,
                'code' => $code,
            );
        }
        return array('ok' => true, 'error' => '', 'code' => $code, 'url' => $this->url);
    }

    public function http_post($url, $headers, $body) {
        $header_lines = array();
        foreach ((array) $headers as $name => $value) {
            $header_lines[] = $name . ': ' . $value;
        }
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header_lines);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_TIMEOUT, 20);
            if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }
            $raw = curl_exec($curl);
            $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            if ($raw === false) {
                return array('code' => 0, 'error' => $error !== '' ? $error : 'Webhook transport failed.');
            }
            return array('code' => $code, 'body' => is_string($raw) ? $raw : '', 'error' => '');
        }
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => implode("\r\n", $header_lines),
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ),
        ));
        $raw = @file_get_contents($url, false, $context);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) {
            $code = (int) $match[1];
        }
        if ($raw === false) {
            return array('code' => $code, 'error' => 'Webhook transport failed.');
        }
        return array('code' => $code, 'body' => is_string($raw) ? $raw : '', 'error' => '');
    }
}
