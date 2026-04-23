<?php

namespace App\Services\OAuth;

use App\Configuration;

final class TrustedIdentityResolver
{
    public function __construct(
        private readonly JidNormalizer $jidNormalizer = new JidNormalizer,
    ) {}

    public function resolve(): ?array
    {
        if (!config('oauth.enabled')) {
            return null;
        }

        $source = null;
        $headerName = config('oauth.trusted_header', 'X-Remote-User');
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        $remoteUser = null;

        if (
            config('oauth.apache_remote_user_fallback', true)
            && isset($_SERVER['REMOTE_USER'])
            && is_string($_SERVER['REMOTE_USER'])
            && trim($_SERVER['REMOTE_USER']) !== ''
        ) {
            $remoteUser = $_SERVER['REMOTE_USER'];
            $source = 'apache_remote_user';
        } elseif (isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey]) && trim($_SERVER[$serverKey]) !== '') {
            if (!$this->isTrustedProxyRequest()) {
                return null;
            }

            $remoteUser = $_SERVER[$serverKey];
            $source = 'trusted_header';
        }

        if (!is_string($remoteUser) || trim($remoteUser) === '') {
            return null;
        }

        $remoteUser = trim(str_replace(["\r", "\n"], '', $remoteUser));

        if (
            $source === 'trusted_header'
            && config('oauth.apache_remote_user_fallback', true)
            && isset($_SERVER['REMOTE_USER'])
            && is_string($_SERVER['REMOTE_USER'])
            && trim($_SERVER['REMOTE_USER']) !== ''
            && trim($_SERVER['REMOTE_USER']) !== $remoteUser
        ) {
            return null;
        }

        $xmppDomain = config('oauth.xmpp_domain')
            ?? Configuration::get()->xmppdomain
            ?? null;

        if (!is_string($xmppDomain) || trim($xmppDomain) === '') {
            return null;
        }

        $jid = $this->jidNormalizer->normalize($remoteUser, $xmppDomain);

        if ($jid === null) {
            return null;
        }

        return [
            'header_name' => $headerName,
            'raw_identity' => $remoteUser,
            'jid' => $jid,
            'source' => $source,
        ];
    }

    private function isTrustedProxyRequest(): bool
    {
        $allowlist = config('oauth.trusted_proxy_ips');

        if (!is_string($allowlist) || trim($allowlist) === '') {
            return true;
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($remoteAddr) || $remoteAddr === '') {
            return false;
        }

        $entries = array_filter(array_map('trim', explode(',', $allowlist)));

        foreach ($entries as $entry) {
            if ($entry === $remoteAddr || $this->ipMatchesCidr($remoteAddr, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $prefix] = explode('/', $cidr, 2);
        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $prefix = (int)$prefix;
        $maxBits = strlen($ipBinary) * 8;

        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($subnetBinary[$fullBytes]) & $mask);
    }
}
