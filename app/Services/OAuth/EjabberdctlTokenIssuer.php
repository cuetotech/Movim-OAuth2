<?php

namespace App\Services\OAuth;

final class EjabberdctlTokenIssuer
{
    public function issueToken(string $jid): array
    {
        $path = config('oauth.ejabberdctl_path', 'ejabberdctl');
        $ttl = max((int)config('oauth.token_ttl', 300), 1);
        $scope = trim((string)config('oauth.token_scope', 'sasl_auth'));
        $timeout = max((int)config('oauth.ejabberdctl_timeout', 5), 1);

        if ($scope === '') {
            throw new \RuntimeException('OAuth token scope is not configured.');
        }

        $command = sprintf(
            '%s oauth_issue_token %s %d %s',
            escapeshellcmd($path),
            escapeshellarg($jid),
            $ttl,
            escapeshellarg($scope)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start ejabberdctl.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = time();

        do {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if ((time() - $start) >= $timeout) {
                proc_terminate($process, 9);
                throw new \RuntimeException('ejabberdctl token issuance timed out.');
            }

            usleep(100000);
        } while (true);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                trim($stderr) !== '' ? trim($stderr) : 'ejabberdctl token issuance failed.'
            );
        }

        $output = trim($stdout);

        if ($output === '') {
            throw new \RuntimeException('ejabberdctl did not return a token.');
        }

        if (!preg_match('/^([A-Za-z0-9._~-]+)/', $output, $matches)) {
            throw new \RuntimeException('Unable to parse ejabberdctl token output.');
        }

        return [
            'access_token' => $matches[1],
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'scope' => $scope,
            'jid' => $jid,
        ];
    }
}
