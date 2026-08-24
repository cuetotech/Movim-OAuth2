<?php

return [
    'enabled'                       => env('OAUTH_ENABLED', false),
    'auto_login'                    => env('OAUTH_AUTO_LOGIN', true),
    'allow_password_fallback'       => env('OAUTH_ALLOW_PASSWORD_FALLBACK', true),
    'trusted_header'                => env('OAUTH_TRUSTED_HEADER', 'X-Remote-User'),
    'apache_remote_user_fallback'   => env('OAUTH_APACHE_REMOTE_USER_FALLBACK', true),
    'trusted_proxy_ips'             => env('OAUTH_TRUSTED_PROXY_IPS', null),
    'xmpp_domain'                   => env('OAUTH_XMPP_DOMAIN', null),
    'issuer'                        => env('OAUTH_ISSUER', 'http'),
    'token_ttl'                     => (int)env('OAUTH_TOKEN_TTL', 300),
    'token_scope'                   => env('OAUTH_TOKEN_SCOPE', 'sasl_auth'),
    'ejabberdctl_path'              => env('OAUTH_EJABBERDCTL_PATH', 'ejabberdctl'),
    'ejabberdctl_timeout'           => (int)env('OAUTH_EJABBERDCTL_TIMEOUT', 5),
    'http_url'                      => env('OAUTH_HTTP_URL', env('OAUTH_BROKER_URL', null)),
    'http_timeout'                  => (int)env('OAUTH_HTTP_TIMEOUT', env('OAUTH_BROKER_TIMEOUT', 5)),
    'http_authorization'            => env('OAUTH_HTTP_AUTHORIZATION', env('OAUTH_BROKER_AUTHORIZATION', null)),
    'http_jid_body_field'           => env('OAUTH_HTTP_JID_BODY_FIELD', env('OAUTH_BROKER_JID_BODY_FIELD', 'jid')),
];
