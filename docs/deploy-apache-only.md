# Deploy Movim With Apache Only

This guide installs Movim behind Apache, with Apache serving PHP and proxying WebSockets to the Movim daemon.

## Packages

Ubuntu 24.04 example:

```bash
sudo apt update
sudo apt install -y apache2 git composer php php-cli libapache2-mod-php php-curl php-mbstring php-xml php-gd php-imagick php-pgsql php-zip unzip postgresql
sudo a2enmod ssl rewrite headers proxy proxy_http proxy_wstunnel
sudo systemctl restart apache2
```

If your site uses smart-card, Kerberos, or another enterprise auth module, enable that module too and keep `REMOTE_USER` available to PHP.

## Install Movim

```bash
sudo git clone https://your-git-server.example/your-movim-oauth-fork.git /var/www/movim
cd /var/www/movim
sudo composer install --no-dev --optimize-autoloader
sudo cp .env.example .env
sudo nano .env
sudo chown -R www-data:www-data /var/www/movim
```

Set the public URL and daemon listener:

```env
DAEMON_URL=https://movim.example.com/
DAEMON_INTERFACE=127.0.0.1
DAEMON_PORT=8080
```

For remote ejabberd token service auth:

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

For same-box ejabberd where Apache and Movim can run `ejabberdctl` locally:

```env
OAUTH_ENABLED=true
OAUTH_ISSUER=ejabberdctl
OAUTH_EJABBERDCTL_PATH=/usr/sbin/ejabberdctl
OAUTH_TOKEN_TTL=300
OAUTH_TOKEN_SCOPE=sasl_auth
```

## Database and daemon

Create the database using the values in `.env`, then run migrations:

```bash
cd /var/www/movim
sudo -u www-data vendor/bin/phinx migrate
```

Install the daemon unit:

```bash
sudo cp etc/systemd/system/movim.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now movim
```

## Apache site

Use the included full Apache sample:

```bash
sudo cp etc/apache2/sites-available/movim-apache.conf /etc/apache2/sites-available/movim.conf
sudo nano /etc/apache2/sites-available/movim.conf
sudo a2ensite movim.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

The important pieces are:

- `DocumentRoot /var/www/movim/public`
- rewrite all non-file requests to `index.php`
- `ProxyPass /ws/ ws://127.0.0.1:8080/`
- optional Apache auth that sets `REMOTE_USER` or `X-Remote-User`

## Token service choices with Apache

Use one of these:

- PHP endpoint: [extras/ejabberd-token-service](../extras/ejabberd-token-service/README.md)
- Python 3 endpoint: [extras/ejabberd-token-service-python](../extras/ejabberd-token-service-python/README.md)
- Same-box local command: set `OAUTH_ISSUER=ejabberdctl` and do not expose a token HTTP endpoint

For a same-box Apache sample with Movim and the PHP token service on one server, start from:

```text
etc/apache2/sites-available/movim-apache-same-box.conf
```

## Verify

```bash
sudo systemctl status movim
sudo apache2ctl configtest
curl -I https://movim.example.com/
```

Then sign in through Apache. Movim should receive the trusted user identity, request a short-lived `sasl_auth` token, and connect to ejabberd with `X-OAUTH2`.
