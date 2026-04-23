# Install This Version on Ubuntu 24.04

This guide installs the version in this repository with:

- Apache in front of Movim
- trusted login through Apache
- a remote ejabberd server
- the included ejabberd token service
- short-lived X-OAUTH2 login to ejabberd

This is written as a step-by-step guide for a normal Ubuntu 24.04 server using `apt`.

## Before you start

You need two Ubuntu 24.04 servers:

- `movim.example.com`: the Movim web server
- `token.example.com`: the ejabberd server, or another Apache site on the ejabberd host

You also need:

- a domain name for Movim
- a domain name for the token service
- a PostgreSQL or MariaDB database for Movim
- an ejabberd server already working for your XMPP domain

This guide uses:

- XMPP domain: `example.com`
- Movim site: `movim.example.com`
- token service: `token.example.com`

Replace those names with your real ones.

## 1. Prepare the Movim server

Log in to the Movim server and update packages:

```bash
sudo apt update
sudo apt upgrade -y
```

Install the packages Movim needs:

```bash
sudo apt install -y apache2 git composer php php-cli libapache2-mod-php php-curl php-mbstring php-xml php-gd php-imagick php-pgsql php-zip unzip libapache2-mod-auth-gssapi
```

Enable the Apache modules used by Movim:

```bash
sudo a2enmod proxy proxy_http proxy_wstunnel rewrite headers ssl auth_gssapi
sudo systemctl restart apache2
```

## 2. Download this repository

Create the web directory and clone the project:

```bash
sudo mkdir -p /var/www
cd /var/www
sudo git clone https://your-git-server.example/your-movim-oauth-fork.git movim
sudo chown -R www-data:www-data /var/www/movim
```

Change into the project folder and install PHP packages:

```bash
cd /var/www/movim
sudo -u www-data composer install --no-dev --optimize-autoloader
```

## 3. Create the Movim environment file

Copy the example file:

```bash
cd /var/www/movim
sudo -u www-data cp .env.example .env
```

Open it:

```bash
sudo nano /var/www/movim/.env
```

At minimum, set:

```env
APP_URL=https://movim.example.com

DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=movim
DB_USERNAME=movim
DB_PASSWORD=change-me

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

Apache will usually serve PHP locally in this setup, so leaving `OAUTH_TRUSTED_PROXY_IPS` empty is normal.

## 4. Create the database

Create the database using your preferred database server. Example for PostgreSQL:

```bash
sudo -u postgres psql
```

Then run:

```sql
CREATE USER movim WITH PASSWORD 'change-me';
CREATE DATABASE movim OWNER movim;
\q
```

Run Movim migrations:

```bash
cd /var/www/movim
sudo -u www-data composer movim:migrate
```

## 5. Start the Movim daemon

Start it once manually:

```bash
cd /var/www/movim
sudo -u www-data php daemon.php start
```

If it starts correctly, stop it with `Ctrl+C`.

Install the systemd service file:

```bash
sudo cp /var/www/movim/etc/systemd/system/movim.service /etc/systemd/system/movim.service
sudo systemctl daemon-reload
sudo systemctl enable --now movim
```

Check status:

```bash
sudo systemctl status movim
```

## 6. Configure Apache for Movim

Create the Apache site file:

```bash
sudo nano /etc/apache2/sites-available/movim.conf
```

Use this as a starting point:

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

    ProxyPass        /ws/  ws://127.0.0.1:8080/
    ProxyPassReverse /ws/  ws://127.0.0.1:8080/
</VirtualHost>
```

Enable the site:

```bash
sudo a2ensite movim.conf
sudo systemctl reload apache2
```

If you use Let's Encrypt, install it and request a certificate:

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d movim.example.com
```

## 7. Prepare the ejabberd server

Log in to the ejabberd server and install Apache plus PHP:

```bash
sudo apt update
sudo apt upgrade -y
sudo apt install -y apache2 php php-cli libapache2-mod-php
```

Enable SSL:

```bash
sudo a2enmod ssl headers
sudo systemctl restart apache2
```

## 8. Install the included token service

Copy the included token service folder from this repository to the ejabberd server. If you cloned the same repository there, you can use it directly. Otherwise copy this folder:

- [extras/ejabberd-token-service](/C:/Users/jcuetopa/OneDrive%20-%20NASA/Desktop/movim-oauth/movim-oauth/extras/ejabberd-token-service)

Place it at:

```bash
sudo mkdir -p /var/www/ejabberd-token-service
sudo cp -R /path/to/this-repository/extras/ejabberd-token-service/* /var/www/ejabberd-token-service/
sudo chown -R www-data:www-data /var/www/ejabberd-token-service
```

Create the real config file:

```bash
sudo cp /var/www/ejabberd-token-service/config.example.php /var/www/ejabberd-token-service/config.php
sudo nano /var/www/ejabberd-token-service/config.php
```

Set these values:

- `authorization_header`: exactly the same secret as `OAUTH_HTTP_AUTHORIZATION` on the Movim server
- `allowed_ips`: the Movim server IP address
- `xmpp_domain`: your ejabberd XMPP domain
- `ejabberdctl_path`: usually `/usr/sbin/ejabberdctl`

## 9. Configure Apache for the token service

Create the token service Apache site:

```bash
sudo nano /etc/apache2/sites-available/ejabberd-token-service.conf
```

Use this as a starting point:

```apache
<VirtualHost *:443>
    ServerName token.example.com

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/token.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/token.example.com/privkey.pem

    DocumentRoot /var/www/ejabberd-token-service/public
    DirectoryIndex index.php

    <Directory /var/www/ejabberd-token-service/public>
        AllowOverride None
        Require all granted
    </Directory>

    <Location /token>
        Require ip 10.0.0.10
    </Location>

    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
</VirtualHost>
```

Replace `10.0.0.10` with the Movim server IP.

Enable the site:

```bash
sudo a2ensite ejabberd-token-service.conf
sudo systemctl reload apache2
```

If needed, request the TLS certificate:

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d token.example.com
```

## 10. Make sure ejabberd can issue OAuth tokens

On the ejabberd server, confirm OAuth is enabled in `ejabberd.yml`.

You need the equivalent of:

```yaml
oauth_access: all
oauth_expire: 3600
```

And ejabberd must support:

- `X-OAUTH2`
- `sasl_auth`
- `oauth_issue_token`

Test locally on the ejabberd server:

```bash
sudo ejabberdctl oauth_issue_token user@example.com 300 sasl_auth
```

If this command fails, fix ejabberd before testing Movim.

## 11. Test the token service

From the Movim server, send a test request:

```bash
curl -k -X POST \
  -H "Authorization: Bearer replace-this-with-a-long-random-secret" \
  -H "X-Remote-User: user" \
  -H "Content-Type: application/json" \
  -d '{"jid":"user@example.com"}' \
  https://token.example.com/token
```

You should receive JSON with `access_token`.

## 12. Restart everything

On the Movim server:

```bash
sudo systemctl restart movim
sudo systemctl restart apache2
```

On the ejabberd server:

```bash
sudo systemctl restart apache2
sudo systemctl restart ejabberd
```

## 13. First login test

Open:

```text
https://movim.example.com
```

Expected result:

- Apache authenticates the user
- Movim auto-starts login
- Movim calls the token service
- the token service issues a short-lived ejabberd token
- Movim authenticates to ejabberd with `X-OAUTH2`

## 14. If something does not work

Check these logs first:

On the Movim server:

- Apache error log
- Movim daemon log

On the ejabberd server:

- Apache error log for the token service
- ejabberd log

Most common problems:

- `OAUTH_HTTP_AUTHORIZATION` does not match `authorization_header`
- the Movim server IP is not in the token service allowlist
- Apache is not setting `REMOTE_USER` correctly
- ejabberd cannot issue `sasl_auth` tokens
- the XMPP domain in Movim and the token service does not match

## Related files

- [docs/apache-oauth.md](/C:/Users/jcuetopa/OneDrive%20-%20NASA/Desktop/movim-oauth/movim-oauth/docs/apache-oauth.md)
- [docs/ejabberd-token-service.md](/C:/Users/jcuetopa/OneDrive%20-%20NASA/Desktop/movim-oauth/movim-oauth/docs/ejabberd-token-service.md)
- [extras/ejabberd-token-service/README.md](/C:/Users/jcuetopa/OneDrive%20-%20NASA/Desktop/movim-oauth/movim-oauth/extras/ejabberd-token-service/README.md)
