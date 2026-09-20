# Fuxico Production Deployment Guide

This guide describes how to deploy **Fuxico** with high security and performance using popular web servers.

---

## 1. Docker & Docker Compose (Recommended)

The easiest and most reliable way to run Fuxico in production is via the 12-factor Docker container powered by **FrankenPHP**.

### `docker-compose.yml`
The included `docker-compose.yml` provides a production-ready setup with persistent storage and automated background image updates:

```yaml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    image: lumenpink/fuxico:latest
    container_name: fuxico
    restart: unless-stopped
    ports:
      - "${PORT:-8080}:8080"
    environment:
      - FUXICO_PUBLIC_MODE=${FUXICO_PUBLIC_MODE:-false}
      - FUXICO_AUTH_TOKENS=${FUXICO_AUTH_TOKENS:-tatanka}
      - FUXICO_BASE_URL=${FUXICO_BASE_URL:-}
      - FUXICO_MAX_UPLOAD_SIZE=${FUXICO_MAX_UPLOAD_SIZE:-524288000}
      - FUXICO_DATA_DIR=/data
    volumes:
      - ./data:/data
    labels:
      - "com.centurylinklabs.watchtower.enable=true"

  watchtower:
    image: containrrr/watchtower:latest
    container_name: fuxico_watchtower
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
By default, the internal FrankenPHP Caddy server has `auto_https off` and `admin off` configured in its `Caddyfile`. This allows you to place Fuxico behind your existing reverse proxy (Traefik, Nginx, Host Caddy, or Cloudflare Tunnel) on port `8080` without certificate conflicts or redundant TLS handshakes:

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
    ServerName fux.example.com
    DocumentRoot /var/www/fuxico

    <Directory /var/www/fuxico>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Protect data directory
    <Directory /var/www/fuxico/data>
        Require all denied
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/fuxico_error.log
    CustomLog ${APACHE_LOG_DIR}/fuxico_access.log combined
</VirtualHost>
```

---

### Nginx with PHP-FPM
```nginx
server {
    listen 80;
    server_name fux.example.com;
    root /var/www/fuxico;
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
fux.example.com {
    root * /var/www/fuxico
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

---

## 4. Single-File Portable Deployment (`fuxico.php`)

If you do not want to deploy the entire git tree or install Composer dependencies, you can deploy the single bundled `fuxico.php` artifact:

### Quick Deployment via curl
```bash
mkdir -p /var/www/fuxico && cd /var/www/fuxico
curl -O https://codeberg.org/lumenpink/fuxico/raw/branch/main/fuxico.php

# Run directly via PHP-CLI Server (development / lightweight private use):
FUXICO_PUBLIC_MODE=true php -S 127.0.0.1:8080 fuxico.php
```

### With Caddy & PHP-FPM
When running `fuxico.php` as a single file, route all requests directly to `fuxico.php`:

```caddy
fux.example.com {
    root * /var/www/fuxico
    encode zstd gzip

    @blocked path /data/* /.*
    respond @blocked 404

    # Route all traffic to the single bundled file
    try_files {path} /fuxico.php?{query}
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
}
```

---

## 5. Automated Expiration Purge (Cron Job)

While expired files are automatically removed upon access, you can run a periodic background cleanup:

```bash
# Clean expired files once every hour (modular or single-file)
0 * * * * php /var/www/fuxico/fuxico.php > /dev/null 2>&1
```
