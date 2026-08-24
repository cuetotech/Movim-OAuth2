# ejabberd Token Service, Python 3

This is a dependency-free Python 3 HTTP service for issuing short-lived ejabberd OAuth tokens to Movim.

It accepts `POST /token`, validates the caller IP, shared `Authorization` header, trusted identity header, and requested JID, then runs:

```bash
ejabberdctl oauth_issue_token <jid> <ttl> <scope>
```

## Files

- `token_service.py`: the Python 3 service
- `config.example.json`: copy to `config.json` and edit
- `ejabberd-token-service-python.service`: systemd unit
- `apache-token-service-python.conf`: Apache TLS reverse-proxy vhost

## Install

```bash
sudo mkdir -p /opt/ejabberd-token-service-python /etc/ejabberd-token-service-python
sudo cp token_service.py /opt/ejabberd-token-service-python/
sudo cp config.example.json /etc/ejabberd-token-service-python/config.json
sudo nano /etc/ejabberd-token-service-python/config.json
sudo cp ejabberd-token-service-python.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ejabberd-token-service-python
```

Publish it through Apache:

```bash
sudo cp apache-token-service-python.conf /etc/apache2/sites-available/
sudo a2enmod ssl headers proxy proxy_http
sudo a2ensite apache-token-service-python.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Point Movim at:

```env
OAUTH_ISSUER=http
OAUTH_HTTP_URL=https://token.example.com/token
OAUTH_HTTP_AUTHORIZATION=Bearer change-this-secret
OAUTH_HTTP_JID_BODY_FIELD=jid
```

For a same-box install where Movim and ejabberd run together, you can keep the service private on `127.0.0.1:8091` and set `OAUTH_HTTP_URL=http://127.0.0.1:8091/token`, or skip the HTTP service entirely and use `OAUTH_ISSUER=ejabberdctl`.
