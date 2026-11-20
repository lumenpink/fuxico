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
    addgroup -g 1000 -S appuser && \
    adduser -u 1000 -S appuser -G appuser && \
    mkdir -p /data /app && \
    chown -R 1000:1000 /data /app

WORKDIR /app

# Zero external runtime dependencies: native PSR-4 autoloader in index.php
COPY --chown=1000:1000 src/ /app/src/
COPY --chown=1000:1000 bin/ /app/bin/
COPY --chown=1000:1000 index.php /app/index.php
COPY --chown=1000:1000 Caddyfile /etc/caddy/Caddyfile

USER 1000:1000

# Native container healthcheck
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8080/ || exit 1

EXPOSE 8080

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
