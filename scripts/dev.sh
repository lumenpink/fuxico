#!/usr/bin/env bash
# ==============================================================================
# Fuxico Live Dev Server & File Watcher
# Automatically watches src/ and compile.php, recompiles fuxico.php,
# and serves the application using PHP's built-in web server.
# ==============================================================================

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8080}"
export FUXICO_PUBLIC_MODE="${FUXICO_PUBLIC_MODE:-true}"

# ANSI color codes
BOLD='\033[1m'
CYAN='\033[0;36m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
MAGENTA='\033[0;35m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${MAGENTA}${BOLD}╔═══════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${MAGENTA}${BOLD}║  🧵 FUXICO LIVE DEV WATCHER & EMBEDDED SERVER                    ║${NC}"
echo -e "${MAGENTA}${BOLD}╚═══════════════════════════════════════════════════════════════════╝${NC}"
echo -e "  📡 Server URL:     ${CYAN}${BOLD}http://${HOST}:${PORT}${NC}"
echo -e "  ⚙️  Public Mode:    ${GREEN}${FUXICO_PUBLIC_MODE}${NC}"
echo -e "  👀 Watching:        ${YELLOW}src/, compile.php${NC}"
echo -e "  ⌨️  Stop server:    ${BOLD}Ctrl+C${NC}"
echo ""

# 1. Initial compilation
echo -e "${CYAN}📦 Running initial bundle compilation...${NC}"
if php "${ROOT_DIR}/compile.php" --quiet; then
    echo -e "${GREEN}✅ Initial compilation complete!${NC}"
else
    echo -e "${RED}❌ Initial compilation failed! Please fix syntax errors.${NC}"
    exit 1
fi
echo ""

# 2. Start PHP built-in web server in background
echo -e "${GREEN}🚀 Starting PHP built-in server on http://${HOST}:${PORT}...${NC}"
php -S "${HOST}:${PORT}" "${ROOT_DIR}/fuxico.php" &
SERVER_PID=$!

cleanup() {
    trap - SIGINT SIGTERM EXIT
    echo ""
    if [[ -n "${SERVER_PID:-}" ]] && kill -0 "${SERVER_PID}" 2>/dev/null; then
        echo -e "${YELLOW}🛑 Shutting down Fuxico dev server (PID: ${SERVER_PID})...${NC}"
        kill -TERM "${SERVER_PID}" 2>/dev/null || true
        wait "${SERVER_PID}" 2>/dev/null || true
    fi
    echo -e "${GREEN}👋 Dev server stopped cleanly. Até mais, fuxiqueira!${NC}"
    exit 0
}
trap cleanup SIGINT SIGTERM EXIT

recompile() {
    local target="${1:-src/}"
    local timestamp
    timestamp="$(date +"%H:%M:%S")"
    echo ""
    echo -e "[${CYAN}${timestamp}${NC}] 🔄 Change detected in ${YELLOW}${target}${NC} -> Recompiling..."

    local output
    local exit_code=0
    output="$(php "${ROOT_DIR}/compile.php" 2>&1)" || exit_code=$?

    if [[ ${exit_code} -eq 0 ]]; then
        local size_kb
        size_kb="$(grep "Size:" <<< "${output}" | awk '{print $2, $3}' || echo "")"
        echo -e "[${CYAN}${timestamp}${NC}] ${GREEN}✨ Recompiled successfully! (${size_kb})${NC}"
        echo -e "[${CYAN}${timestamp}${NC}] 📡 Fresh build live at ${CYAN}http://${HOST}:${PORT}${NC}"
    else
        echo -e "[${CYAN}${timestamp}${NC}] ${RED}❌ Compilation error:${NC}"
        echo "${output}"
    fi
}

# 3. Watch loop
if command -v inotifywait >/dev/null 2>&1; then
    echo -e "${GREEN}🔍 Watching changes using inotifywait...${NC}"
    echo ""
    while true; do
        if CHANGED="$(inotifywait -t 2 -r -q -e close_write,modify,attrib,create,delete,move --format '%w%f' "${ROOT_DIR}/src" "${ROOT_DIR}/compile.php" 2>/dev/null)"; then
            # Debounce quick consecutive edits
            sleep 0.15
            if [[ -n "${CHANGED}" ]]; then
                recompile "${CHANGED}"
            fi
        fi
    done
else
    echo -e "${YELLOW}⚠️  inotifywait not found. Falling back to mtime polling (1s interval)...${NC}"
    echo ""
    LAST_HASH=""
    while true; do
        sleep 1
        CURRENT_HASH="$(find "${ROOT_DIR}/src" "${ROOT_DIR}/compile.php" -type f -exec stat -c '%Y %n' {} + 2>/dev/null | sha256sum | awk '{print $1}')"
        if [[ -n "${LAST_HASH}" ]] && [[ "${CURRENT_HASH}" != "${LAST_HASH}" ]]; then
            recompile "src/"
        fi
        LAST_HASH="${CURRENT_HASH}"
    done
fi
