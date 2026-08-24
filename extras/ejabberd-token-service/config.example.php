<?php

declare(strict_types=1);

return [
    // Header received from the Movim server. Movim uses OAUTH_TRUSTED_HEADER.
    'trusted_header' => 'X-Remote-User',

    // Shared secret expected from Movim. Example:
    // 'Bearer replace-this-with-a-long-random-secret'
    'authorization_header' => 'Bearer change-this-secret',

    // Restrict access to the Movim server address or subnet.
    'allowed_ips' => [
        '127.0.0.1',
    ],

    // The XMPP domain used by ejabberd.
    'xmpp_domain' => 'example.com',

    // JSON field expected from Movim. Keep this aligned with OAUTH_HTTP_JID_BODY_FIELD.
    'jid_body_field' => 'jid',

    // Local ejabberdctl path on the ejabberd server.
    'ejabberdctl_path' => '/usr/sbin/ejabberdctl',

    // Short-lived token settings.
    'token_ttl' => 300,
    'token_scope' => 'sasl_auth',
    'command_timeout' => 5,
];
