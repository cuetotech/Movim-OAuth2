# ejabberd Token Service

This repository now includes a small token service for deployments where:

- Movim runs on one server
- ejabberd runs on another server
- Movim needs a server-to-server way to obtain short-lived X-OAUTH2 tokens

## What it does

The service runs on the ejabberd host and exposes:

```text
POST /token
```

It:

1. checks the caller IP
2. checks a shared `Authorization` header
3. reads the trusted identity header from Movim
4. normalizes that identity into a JID
5. runs `ejabberdctl oauth_issue_token`
6. returns JSON with `access_token`, `expires_in`, `scope`, and `jid`

## Files

- [config.example.php](/C:/Users/jcuetopa/OneDrive%20-%20NASA/Desktop/movim-oauth/movim-oauth/extras/ejabberd-token-service/config.example.php)
- [public/index.php](/C:/Users/jcuetopa/OneDrive%20-%20NASA/Desktop/movim-oauth/movim-oauth/extras/ejabberd-token-service/public/index.php)
- [apache-token-service.conf](/C:/Users/jcuetopa/OneDrive%20-%20NASA/Desktop/movim-oauth/movim-oauth/extras/ejabberd-token-service/apache-token-service.conf)
- [Python 3 token service](/C:/Users/jcuetopa/OneDrive%20-%20NASA/Desktop/movim-oauth/movim-oauth/extras/ejabberd-token-service-python/README.md)

## Movim configuration

Point Movim at the service:

```env
OAUTH_ISSUER=http
OAUTH_HTTP_URL=https://token.example.com/token
OAUTH_HTTP_AUTHORIZATION=Bearer replace-this-with-a-long-random-secret
OAUTH_HTTP_JID_BODY_FIELD=jid
```

## Security expectations

- Keep the token service reachable only from the Movim server
- Use HTTPS
- Use a long random shared secret in `Authorization`
- Keep `OAUTH_TOKEN_TTL` short, usually `300`
- Do not expose the service publicly on the open internet if you can avoid it

## Why this exists

If ejabberd is on another server, `OAUTH_EJABBERDCTL_PATH` on the Movim host is not useful. The local command has to run on the ejabberd host, so this service is the bridge between the two servers.
