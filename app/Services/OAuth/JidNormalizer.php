<?php

namespace App\Services\OAuth;

final class JidNormalizer
{
    public function normalize(string $identity, string $xmppDomain): ?string
    {
        $identity = trim($identity);
        $xmppDomain = strtolower(trim($xmppDomain));

        if ($identity === '' || $xmppDomain === '') {
            return null;
        }

        if (str_contains($identity, '\\')) {
            $parts = explode('\\', $identity);
            $identity = end($parts);
        }

        $identity = strtolower(trim($identity));

        if (str_contains($identity, '@')) {
            $jid = explodeJid($identity);

            if (
                empty($jid['username'])
                || strtolower($jid['server']) !== $xmppDomain
            ) {
                return null;
            }

            return validateJid($identity) ? $identity : null;
        }

        if (!validateLocal($identity)) {
            return null;
        }

        $jid = $identity . '@' . $xmppDomain;

        return validateJid($jid) ? $jid : null;
    }
}
