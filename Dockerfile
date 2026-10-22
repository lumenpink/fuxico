FROM dunglas/frankenphp:php8.5-alpine

LABEL maintainer="Lumen Pink <hi@lumen.pink>"
LABEL org.opencontainers.image.title="Fuxico"
LABEL org.opencontainers.image.description="A malícia popular do boca a boca - Edge File Vault & URL Shortener"

ENV SERVER_NAME=":8080" \
    CADDYFILE="/etc/caddy/Caddyfile" \
    FUXICO_DATA_DIR="/data" \
    FUXICO_PUBLIC_MODE="false"

# Configure PHP runtime parameters for large uploads
RUN echo "upload_max_filesize = 512M" > "$PHP_INI_DIR/conf.d/fuxico.ini" && \
    echo "post_max_size = 512M" >> "$PHP_INI_DIR/conf.d/fuxico.ini" && \
    echo "memory_limit = 512M" >> "$PHP_INI_DIR/conf.d/fuxico.ini" && \
    echo "max_execution_time = 300" >> "$PHP_INI_DIR/conf.d/fuxico.ini" && \
    mkdir -p /data && \
    chown -R www-data:www-data /data

WORKDIR /app

# Zero external runtime dependencies: native PSR-4 autoloader in index.php
COPY src/ /app/src/
COPY bin/ /app/bin/
COPY index.php /app/index.php
COPY Caddyfile /etc/caddy/Caddyfile

# Native container healthcheck
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8080/ || exit 1

EXPOSE 8080

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
