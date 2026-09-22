#!/bin/sh
# =============================================================================
# Fuxico Static Site Preview Server
# Serves the site/ directory locally across all network interfaces (LAN/Tailscale)
# =============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SITE_DIR="$SCRIPT_DIR/site"
PORT="${PORT:-8085}"

while [ $# -gt 0 ]; do
    case "$1" in
        -p|--port)
            PORT="$2"
            shift 2
            ;;
        [0-9]*)
            PORT="$1"
            shift
            ;;
        *)
            shift
            ;;
    esac
done

echo "⚡ [fuxico-site] Iniciando servidor de preview na porta ${PORT}..."
echo "👉 Local:     http://localhost:${PORT}/preview.html"
echo "👉 Rede/VPN:  http://0.0.0.0:${PORT}/preview.html"
echo ""

if command -v python3 >/dev/null 2>&1; then
    exec python3 -m http.server "$PORT" --bind 0.0.0.0 --directory "$SITE_DIR"
elif command -v php >/dev/null 2>&1; then
    exec php -S "0.0.0.0:$PORT" -t "$SITE_DIR"
else
    echo "Erro: python3 ou php não encontrados." >&2
    exit 1
fi
