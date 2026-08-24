# ejabberd Token Service

This is a small PHP endpoint meant to run on the ejabberd server. It is the simplest option when Apache already serves PHP on that host.

It accepts `POST /token` from the Movim host, validates:

- the caller IP address
- a shared `Authorization` header
- the trusted identity header sent by Movim

Then it runs:

```bash
ejabberdctl oauth_issue_token <jid> <ttl> <scope>
```

and returns JSON like:

```json
{
  "access_token": "token",
  "token_type": "Bearer",
  "expires_in": 300,
  "scope": "sasl_auth",
  "jid": "user@example.com"
}
```

## Files

- `public/index.php`: the token endpoint
- `config.example.php`: copy this to `config.php` and edit it
- `apache-token-service.conf`: Apache virtual host example for the PHP endpoint

## Minimal setup

1. Copy this directory to the ejabberd server, for example `/var/www/ejabberd-token-service`
2. Copy `config.example.php` to `config.php`
3. Set the real XMPP domain, allowed Movim IP, shared authorization header, and ejabberdctl path
4. Publish `public/` through Apache
5. Point Movim at `https://token.example.com/token`

## Movim settings

Use these on the Movim server:

```env
OAUTH_ISSUER=http
OAUTH_HTTP_URL=https://token.example.com/token
OAUTH_HTTP_AUTHORIZATION=Bearer change-this-secret
OAUTH_HTTP_JID_BODY_FIELD=jid
```

## Notes

- Keep the token TTL short
- Restrict the token service to the Movim host only
- Prefer an internal-only hostname or firewall rule
- If Movim and ejabberd are ever moved onto the same host, you can switch back to `OAUTH_ISSUER=ejabberdctl`
- If you prefer a small long-running service instead of PHP under Apache, use `extras/ejabberd-token-service-python`
