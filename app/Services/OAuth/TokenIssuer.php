<?php

namespace App\Services\OAuth;

final class TokenIssuer
{
    public function __construct(
        private readonly EjabberdctlTokenIssuer $ejabberdctl = new EjabberdctlTokenIssuer,
        private readonly HttpTokenIssuer $http = new HttpTokenIssuer,
    ) {}

    public function issueToken(string $rawIdentity, string $jid): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < 2) {
            try {
                return match (config('oauth.issuer', 'http')) {
                    'http' => $this->http->issueToken($rawIdentity, $jid),
                    'ejabberdctl' => $this->ejabberdctl->issueToken($jid),
                    default => throw new \RuntimeException('Unsupported OAuth token issuer backend.'),
                };
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < 2) {
                    usleep(150000);
                }
            }
        }

        throw $lastException ?? new \RuntimeException('OAuth token issuance failed.');
    }
}
