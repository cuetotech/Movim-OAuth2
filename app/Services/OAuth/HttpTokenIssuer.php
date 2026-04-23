<?php

namespace App\Services\OAuth;

final class HttpTokenIssuer
{
    public function issueToken(string $rawIdentity, string $jid): array
    {
        $url = config('oauth.http_url');

        if (!is_string($url) || trim($url) === '') {
            throw new \RuntimeException('OAuth HTTP token issuer URL is not configured.');
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize the OAuth token issuer request.');
        }

        $bodyField = config('oauth.http_jid_body_field', 'jid');
        $rawIdentity = trim(str_replace(["\r", "\n"], '', $rawIdentity));
        $payload = json_encode([
            $bodyField => $jid,
        ], JSON_THROW_ON_ERROR);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: Movim OAuth Token Client',
            config('oauth.trusted_header', 'X-Remote-User') . ': ' . $rawIdentity,
        ];

        $authorization = config('oauth.http_authorization');
        if (is_string($authorization) && trim($authorization) !== '') {
            $headers[] = 'Authorization: ' . trim($authorization);
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max((int)config('oauth.http_timeout', 5), 1),
        ]);

        $response = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException(
                $curlError !== '' ? $curlError : 'OAuth token issuer request failed.'
            );
        }

        $decoded = json_decode($response, true);

        if (
            $statusCode < 200
            || $statusCode >= 300
            || !is_array($decoded)
            || empty($decoded['access_token'])
        ) {
            throw new \RuntimeException('OAuth token issuer returned an invalid response.');
        }

        if (
            !empty($decoded['jid'])
            && is_string($decoded['jid'])
            && strtolower($decoded['jid']) !== strtolower($jid)
        ) {
            throw new \RuntimeException('OAuth token issuer returned a token for an unexpected JID.');
        }

        return $decoded;
    }
}
