# Apache + Remote ejabberd OAuth Deployment

This is the recommended deployment model for this branch when:

- Apache sits in front of Movim
- ejabberd runs on a different server
- Movim authenticates users through Apache trusted auth
- Movim gets short-lived ejabberd tokens from a small HTTP token service on the ejabberd host

## Architecture

```text
Browser
  -> Apache on Movim host
  -> Movim
  -> HTTPS token service on ejabberd host
  -> ejabberdctl oauth_issue_token
  -> ejabberd X-OAUTH2
```

## Apache modules on the Movim server

Typical Apache modules:

- `proxy`
- `proxy_http`
- `proxy_wstunnel`
- `headers`
- `rewrite`
- `ssl`
- `auth_gssapi` or your site equivalent for smart-card / Kerberos auth

## Trusted identity handoff

Movim now prefers Apache `REMOTE_USER` when available. You can still set a header for clarity and for the remote token issuer call.

Recommended handoff:

```apache
RequestHeader unset X-Remote-User
RequestHeader set X-Remote-User "%{REMOTE_USER}s"
```

## Draft VirtualHost for the Movim server

```apache
<VirtualHost *:443>
    ServerName movim.example.com

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/movim.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/movim.example.com/privkey.pem

    DocumentRoot /var/www/movim/public

    RequestHeader unset X-Remote-User
    RequestHeader set X-Forwarded-Proto "https"

    <Directory /var/www/movim/public>
        DirectoryIndex index.php
        Options +FollowSymLinks -Indexes
        AllowOverride FileInfo Options
        Require all granted

        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-s
        RewriteCond %{REQUEST_FILENAME} !-h
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^(.*)$ index.php?/$1 [L,QSA]
    </Directory>

    <Location />
        AuthType GSSAPI
        AuthName "Movim Login"
        GssapiSSLonly On
        Require valid-user

        RequestHeader set X-Remote-User "%{REMOTE_USER}s"
    </Location>

    <LocationMatch "^/(manifest/?$|sw\.js$|theme/|scripts/|stickers/)">
        AuthType None
        Require all granted
    </LocationMatch>

    ProxyPass        /ws/  ws://127.0.0.1:8080/
    ProxyPassReverse /ws/  ws://127.0.0.1:8080/

    ErrorLog ${APACHE_LOG_DIR}/movim-error.log
    CustomLog ${APACHE_LOG_DIR}/movim-access.log combined
</VirtualHost>
```

## Movim environment

Recommended values for a split Movim and ejabberd deployment:

```env
OAUTH_ENABLED=true
OAUTH_AUTO_LOGIN=true
OAUTH_ALLOW_PASSWORD_FALLBACK=false
OAUTH_TRUSTED_HEADER=X-Remote-User
OAUTH_APACHE_REMOTE_USER_FALLBACK=true
OAUTH_XMPP_DOMAIN=example.com
OAUTH_ISSUER=http
OAUTH_HTTP_URL=https://token.example.com/token
OAUTH_HTTP_AUTHORIZATION=Bearer replace-this-with-a-long-random-secret
OAUTH_HTTP_JID_BODY_FIELD=jid
OAUTH_TOKEN_TTL=300
OAUTH_TOKEN_SCOPE=sasl_auth
```

## Token service

The token service package is included in:

- [extras/ejabberd-token-service/README.md](/C:/Users/jcuetopa/OneDrive%20-%20NASA/Desktop/movim-oauth/movim-oauth/extras/ejabberd-token-service/README.md)
- [extras/ejabberd-token-service/public/index.php](/C:/Users/jcuetopa/OneDrive%20-%20NASA/Desktop/movim-oauth/movim-oauth/extras/ejabberd-token-service/public/index.php)
- [extras/ejabberd-token-service-python/README.md](/C:/Users/jcuetopa/OneDrive%20-%20NASA/Desktop/movim-oauth/movim-oauth/extras/ejabberd-token-service-python/README.md)

It is intentionally small and only does one job:

- validate the Movim caller
- normalize the trusted identity to a JID
- call local `ejabberdctl oauth_issue_token`
- return a short-lived token for `sasl_auth`

Under Apache, use either the PHP endpoint directly as a normal virtual host, or run the Python 3 service on loopback and let Apache reverse proxy `/token` to it.

## ejabberd requirements

ejabberd must:

- advertise `X-OAUTH2`
- allow tokens with the `sasl_auth` scope
- expose token support in configuration

Your reference notes are in [ejabberd-oath-info.md](/C:/Users/jcuetopa/OneDrive%20-%20NASA/Desktop/movim-oauth/movim-oauth/docs/ejabberd-oath-info.md).
