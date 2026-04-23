<?php

declare(strict_types=1);

header('Content-Type: application/json');

$configPath = dirname(__DIR__) . '/config.php';

if (!is_file($configPath)) {
    respond(500, [
        'error' => 'server_not_configured',
        'message' => 'Copy config.example.php to config.php and update the values.',
    ]);
}

$config = require $configPath;

if (!is_array($config)) {
    respond(500, [
        'error' => 'server_not_configured',
        'message' => 'Token service config is invalid.',
    ]);
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($requestPath !== '/token' && $requestPath !== '/token/') {
    respond(404, [
        'error' => 'not_found',
        'message' => 'Only POST /token is available.',
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    respond(405, [
        'error' => 'method_not_allowed',
        'message' => 'Use HTTP POST.',
    ]);
}

$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
$allowedIps = array_values(array_filter(array_map('trim', (array)($config['allowed_ips'] ?? []))));

if ($allowedIps !== [] && !ip_allowed($remoteAddr, $allowedIps)) {
    error_log(sprintf('ejabberd token service denied IP %s', $remoteAddr));
    respond(403, [
        'error' => 'forbidden',
        'message' => 'Client IP is not allowed.',
    ]);
}

$expectedAuthorization = trim((string)($config['authorization_header'] ?? ''));
$providedAuthorization = trim((string)get_header('Authorization'));

if ($expectedAuthorization === '' || !hash_equals($expectedAuthorization, $providedAuthorization)) {
    error_log(sprintf('ejabberd token service rejected authorization from %s', $remoteAddr));
    respond(403, [
        'error' => 'forbidden',
        'message' => 'Authorization failed.',
    ]);
}

$trustedHeader = trim((string)($config['trusted_header'] ?? 'X-Remote-User'));
$rawIdentity = trim(str_replace(["\r", "\n"], '', (string)get_header($trustedHeader)));
$xmppDomain = strtolower(trim((string)($config['xmpp_domain'] ?? '')));
$jidField = trim((string)($config['jid_body_field'] ?? 'jid'));

if ($rawIdentity === '' || $xmppDomain === '') {
    respond(400, [
        'error' => 'invalid_request',
        'message' => 'Missing trusted identity or XMPP domain.',
    ]);
}

$requestBody = file_get_contents('php://input');
$decodedBody = [];

if (is_string($requestBody) && trim($requestBody) !== '') {
    try {
        $decodedBody = json_decode($requestBody, true, flags: JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        respond(400, [
            'error' => 'invalid_request',
            'message' => 'Request body must be valid JSON.',
        ]);
    }

    if (!is_array($decodedBody)) {
        respond(400, [
            'error' => 'invalid_request',
            'message' => 'Request body must decode to a JSON object.',
        ]);
    }
}

$normalizedJid = normalize_identity_to_jid($rawIdentity, $xmppDomain);

if ($normalizedJid === null) {
    respond(400, [
        'error' => 'invalid_identity',
        'message' => 'Trusted identity could not be mapped to a valid JID.',
    ]);
}

$requestedJid = $decodedBody[$jidField] ?? null;

if ($requestedJid !== null) {
    $requestedJid = strtolower(trim((string)$requestedJid));

    if (!validate_jid($requestedJid) || $requestedJid !== $normalizedJid) {
        respond(400, [
            'error' => 'invalid_identity',
            'message' => 'Requested JID does not match the trusted identity.',
        ]);
    }
}

$ttl = max((int)($config['token_ttl'] ?? 300), 1);
$scope = trim((string)($config['token_scope'] ?? 'sasl_auth'));
$timeout = max((int)($config['command_timeout'] ?? 5), 1);
$ejabberdctlPath = trim((string)($config['ejabberdctl_path'] ?? 'ejabberdctl'));

if ($scope === '' || $ejabberdctlPath === '') {
    respond(500, [
        'error' => 'server_not_configured',
        'message' => 'ejabberd token service settings are incomplete.',
    ]);
}

try {
    $token = issue_token($ejabberdctlPath, $normalizedJid, $ttl, $scope, $timeout);
} catch (\RuntimeException $e) {
    error_log(sprintf(
        'ejabberd token service failed for %s from %s: %s',
        $normalizedJid,
        $remoteAddr,
        $e->getMessage()
    ));

    respond(503, [
        'error' => 'token_unavailable',
        'message' => 'ejabberd could not issue a token right now.',
    ]);
}

error_log(sprintf(
    'ejabberd token service issued token for %s from %s',
    $normalizedJid,
    $remoteAddr
));

respond(200, [
    'access_token' => $token,
    'token_type' => 'Bearer',
    'expires_in' => $ttl,
    'scope' => $scope,
    'jid' => $normalizedJid,
]);

function respond(int $statusCode, array $body): never
{
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function get_header(string $name): ?string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey])) {
        return $_SERVER[$serverKey];
    }

    if ($name === 'Authorization' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    return null;
}

function ip_allowed(string $ip, array $allowlist): bool
{
    if ($ip === '') {
        return false;
    }

    foreach ($allowlist as $entry) {
        if ($entry === $ip || ip_matches_cidr($ip, $entry)) {
            return true;
        }
    }

    return false;
}

function ip_matches_cidr(string $ip, string $cidr): bool
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

function normalize_identity_to_jid(string $identity, string $xmppDomain): ?string
{
    $identity = trim($identity);
    $xmppDomain = strtolower(trim($xmppDomain));

    if ($identity === '' || $xmppDomain === '') {
        return null;
    }

    if (str_contains($identity, '\\')) {
        $parts = explode('\\', $identity);
        $identity = (string)end($parts);
    }

    $identity = strtolower(trim($identity));

    if (str_contains($identity, '@')) {
        if (!validate_jid($identity)) {
            return null;
        }

        [$local, $domain] = explode('@', $identity, 2);

        if ($local === '' || strtolower($domain) !== $xmppDomain) {
            return null;
        }

        return $identity;
    }

    if (!validate_local($identity)) {
        return null;
    }

    $jid = $identity . '@' . $xmppDomain;

    return validate_jid($jid) ? $jid : null;
}

function validate_jid(string $jid): bool
{
    $parts = explode('@', $jid, 2);

    if (count($parts) !== 2) {
        return false;
    }

    return validate_local($parts[0]) && validate_domain($parts[1]);
}

function validate_local(string $local): bool
{
    return (bool)preg_match('/^[a-z0-9._-]+$/', $local);
}

function validate_domain(string $domain): bool
{
    if (!preg_match('/^[a-z0-9.-]+$/', $domain)) {
        return false;
    }

    return str_contains($domain, '.');
}

function issue_token(string $ejabberdctlPath, string $jid, int $ttl, string $scope, int $timeout): string
{
    $command = sprintf(
        '%s oauth_issue_token %s %d %s',
        escapeshellcmd($ejabberdctlPath),
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

    return $matches[1];
}
