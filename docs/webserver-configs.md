# Webserver Config Samples

The repository includes deployable samples for Apache, Nginx, and same-box combinations.

## Apache

- `etc/apache2/conf-available/movim.conf`: small reusable Apache directory/proxy snippet
- `etc/apache2/sites-available/movim-apache.conf`: full Apache TLS vhost for Movim
- `etc/apache2/sites-available/movim-apache-same-box.conf`: Movim plus token service on the same Apache host
- `etc/apache2/sites-available/movim-apache-behind-nginx.conf`: Apache backend on `127.0.0.1:8088` for the Nginx same-box frontend

Enable the usual modules:

```bash
sudo a2enmod ssl rewrite headers proxy proxy_http proxy_wstunnel
```

If Apache authenticates the user, configure the auth module so `REMOTE_USER` is populated. Movim can read `REMOTE_USER` directly, and the sample also shows how to forward it as `X-Remote-User`.

## Nginx

- `etc/nginx/conf.d/movim.conf`: existing compact Nginx PHP-FPM example
- `etc/nginx/conf.d/movim-websocket.conf`: reusable websocket location snippet
- `etc/nginx/conf.d/movim-full.conf`: complete Nginx TLS/PHP-FPM/daemon websocket vhost
- `etc/nginx/conf.d/movim-with-apache-same-box.conf`: Nginx public TLS frontend with Apache on the same box

For the full Nginx sample, define `fastcgi_cache_path` in the `http` block of `nginx.conf` if you keep the `/picture` cache settings:

```nginx
fastcgi_cache_path /tmp/nginx_cache levels=1:2 keys_zone=nginx_cache:100m inactive=60m;
```

## Token Service

Token-service options are:

- PHP under Apache: `extras/ejabberd-token-service`
- Python 3 behind Apache reverse proxy: `extras/ejabberd-token-service-python`
- Local same-box command: `OAUTH_ISSUER=ejabberdctl`

For split hosts, keep the token service on the ejabberd host and allow only the Movim host to call it. For same-box installs, prefer a loopback-only listener or local `ejabberdctl`.
