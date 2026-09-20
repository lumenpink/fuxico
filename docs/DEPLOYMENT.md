# PicoShare Production Deployment Guide

This guide describes how to deploy **PicoShare** with high security and performance using popular web servers.

---

## 1. Docker & Docker Compose (Recommended)

The easiest and most reliable way to run PicoShare in production is via the 12-factor Docker container powered by **FrankenPHP**.

### `docker-compose.yml`
The included `docker-compose.yml` provides a production-ready setup with persistent storage and automated background image updates:

```yaml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    image: lumenpink/picoshare:latest
    container_name: picoshare
    restart: unless-stopped
    ports:
      - "${PORT:-8080}:8080"
    environment:
      - PICOSHARE_PUBLIC_MODE=${PICOSHARE_PUBLIC_MODE:-false}
      - PICOSHARE_AUTH_TOKENS=${PICOSHARE_AUTH_TOKENS:-tatanka}
      - PICOSHARE_BASE_URL=${PICOSHARE_BASE_URL:-}
      - PICOSHARE_MAX_UPLOAD_SIZE=${PICOSHARE_MAX_UPLOAD_SIZE:-524288000}
      - PICOSHARE_DATA_DIR=/data
    volumes:
      - ./data:/data
    labels:
      - "com.centurylinklabs.watchtower.enable=true"

  watchtower:
    image: containrrr/watchtower:latest
    container_name: picoshare_watchtower
    restart: unless-stopped
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
    environment:
      - WATCHTOWER_CLEANUP=true
      - WATCHTOWER_POLL_INTERVAL=86400
      - WATCHTOWER_LABEL_ENABLE=true
      - WATCHTOWER_INCLUDE_RESTARTING=true
```

### Automated Updates (Watchtower)
Watchtower runs in a scoped mode (`WATCHTOWER_LABEL_ENABLE=true`) checking every 24 hours for new image digests. Only containers labeled with `com.centurylinklabs.watchtower.enable=true` are monitored and restarted, keeping host services undisturbed.

### Reverse Proxy Setup (No Internal HTTPS)
By default, the internal FrankenPHP Caddy server has `auto_https off` and `admin off` configured in its `Caddyfile`. This allows you to place PicoShare behind your existing reverse proxy (Traefik, Nginx, Host Caddy, or Cloudflare Tunnel) on port `8080` without certificate conflicts or redundant TLS handshakes:

```nginx
# Example Nginx Reverse Proxy snippet
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    client_max_body_size 512M;
}
```

### Building & Publishing Images
Use the included automated build and publish script to build and push Docker images to Docker Hub:

```bash
# Test building and tagging locally without pushing
./scripts/docker_publish.sh --dry-run

# Build and push with automatic semantic versioning and :latest tag
./scripts/docker_publish.sh

# Build with a custom tag and push
./scripts/docker_publish.sh -t v0.1.0 --latest
```

When automated authentication is needed (e.g. in CI or batch jobs), export `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` in your environment.

---

## 2. Directory Permissions & Lockdown (Bare Metal)

Ensure the web server user (`www-data`, `nginx`, or `caddy`) has write permissions to `data/`:

```bash
mkdir -p data/files
chown -R www-data:www-data data
chmod -R 755 data
```

> [!IMPORTANT]
> The internal `data/` and `data/files/` directories contain an automated `.htaccess` with `Require all denied` to block direct access. Even without Apache, ensure your web server configuration routes all requests to `index.php` and does not serve files directly from `data/`.

---

## 3. Bare Metal Web Server Configurations

### Apache 2.4+
Ensure `mod_rewrite` and `mod_authz_core` are enabled:
```apache
<VirtualHost *:80>
    ServerName pico.example.com
    DocumentRoot /var/www/picoshare

    <Directory /var/www/picoshare>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Protect data directory
    <Directory /var/www/picoshare/data>
        Require all denied
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/picoshare_error.log
    CustomLog ${APACHE_LOG_DIR}/picoshare_access.log combined
</VirtualHost>
```

---

### Nginx with PHP-FPM
```nginx
server {
    listen 80;
    server_name pico.example.com;
    root /var/www/picoshare;
    index index.php;

    client_max_body_size 512M;

    # Strictly deny access to data/ and hidden files
    location ^~ /data/ {
        deny all;
        return 404;
    }
    location ~ /\. {
        deny all;
    }

    # Route everything through index.php
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

### Caddy
```caddy
pico.example.com {
    root * /var/www/picoshare
    encode zstd gzip

    # Block direct access to data
    @blocked path /data/* /.*
    respond @blocked 404

    # PHP-FPM routing
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
}
```

---

## 4. Automated Expiration Purge (Cron Job)

While expired files are automatically removed upon access, you can run a periodic background cleanup:

```bash
# Clean expired files once every hour
0 * * * * php /var/www/picoshare/index.php > /dev/null 2>&1
```
