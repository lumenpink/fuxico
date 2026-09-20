# PicoShare Production Deployment Guide

This guide describes how to deploy **PicoShare** with high security and performance using popular web servers.

---

## 1. Directory Permissions & Lockdown

Ensure the web server user (`www-data`, `nginx`, or `caddy`) has write permissions to `data/`:

```bash
mkdir -p data/files
chown -R www-data:www-data data
chmod -R 755 data
```

> [!IMPORTANT]
> The internal `data/` and `data/files/` directories contain an automated `.htaccess` with `Require all denied` to block direct access. Even without Apache, ensure your web server configuration routes all requests to `index.php` and does not serve files directly from `data/`.

---

## 2. Web Server Configurations

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

## 3. Automated Expiration Purge (Cron Job)

While expired files are automatically removed upon access, you can run a periodic background cleanup:

```bash
# Clean expired files once every hour
0 * * * * php /var/www/picoshare/index.php > /dev/null 2>&1
```
