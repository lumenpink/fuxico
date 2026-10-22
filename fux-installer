#!/usr/bin/env bash
# =============================================================================
# FUXICO — Universal CLI Installer & Client Provisioner
# https://fuxico.2lp.in | Solta o verbo!
# =============================================================================
set -euo pipefail

# ANSI color codes
YELLOW='\033[1;33m'
PINK='\033[1;35m'
CYAN='\033[1;36m'
GREEN='\033[1;32m'
RED='\033[1;31m'
BOLD='\033[1m'
NC='\033[0m'

if [ ! -t 1 ]; then
    YELLOW='' PINK='' CYAN='' GREEN='' RED='' BOLD='' NC=''
fi

echo -e "${YELLOW}${BOLD}⚡ FUXICO // A malícia popular do boca a boca no terminal${NC}"
echo -e "${PINK}🏳️‍⚧️ Fervo & tecnologia travesti latina de resistência${NC}"
echo ""

BASE_URL="${FUX_BASE_URL:-https://fuxico.2lp.in}"
FALLBACK_URL="https://codeberg.org/lumenpink/fuxico/raw/branch/main"
FLAVOR="${FUX_FLAVOR:-auto}"
INSTALL_DIR="${FUX_INSTALL_DIR:-$HOME/.local/bin}"

# Parse CLI flags
while [[ $# -gt 0 ]]; do
    case "$1" in
        --rust|-r)
            FLAVOR="rust"
            shift
            ;;
        --shell|--bash|-s)
            FLAVOR="shell"
            shift
            ;;
        --dir|-d)
            INSTALL_DIR="$2"
            shift 2
            ;;
        --help|-h)
            echo "Uso: curl -fsSL https://fuxico.2lp.in/install.sh | bash -s -- [OPÇÕES]"
            echo ""
            echo "Opções:"
            echo "  --rust, -r         Instala o cliente nativo compilado em Rust (padrão quando disponível)"
            echo "  --shell, -s        Instala o cliente universal em Bash / POSIX curl"
            echo "  --dir, -d <DIR>    Diretório de destino da instalação (padrão: ~/.local/bin)"
            echo "  --help, -h         Exibe esta ajuda"
            exit 0
            ;;
        *)
            shift
            ;;
    esac
done

mkdir -p "$INSTALL_DIR"

OS="$(uname -s)"
ARCH="$(uname -m)"

echo -e "🔍 Sistema detectado: ${CYAN}${OS}${NC} (${YELLOW}${ARCH}${NC})"

TARGET_BIN=""
if [[ "$FLAVOR" == "shell" ]]; then
    TARGET_BIN="fux"
    echo -e "📦 Sabor selecionado: ${PINK}Shell Universal (cURL wrapper)${NC}"
else
    # Auto-detect best native binary
    case "$OS" in
        Linux)
            case "$ARCH" in
                x86_64|amd64)
                    TARGET_BIN="fux-linux-x86_64"
                    ;;
                aarch64|arm64)
                    TARGET_BIN="fux-linux-aarch64"
                    ;;
                armv7*|armhf)
                    TARGET_BIN="fux-linux-armv7"
                    ;;
                armv6*|armel)
                    TARGET_BIN="fux-linux-armv6"
                    ;;
                *)
                    TARGET_BIN="fux"
                    ;;
            esac
            ;;
        Darwin)
            # macOS: fallback to shell client or darwin native if provided
            TARGET_BIN="fux"
            ;;
        MINGW*|MSYS*|CYGWIN*)
            TARGET_BIN="fux-windows-x86_64.exe"
            ;;
        *)
            TARGET_BIN="fux"
            ;;
    esac

    if [[ "$TARGET_BIN" == "fux" ]]; then
        echo -e "📦 Sabor selecionado: ${PINK}Shell Universal (cURL wrapper)${NC}"
    else
        echo -e "📦 Sabor selecionado: ${GREEN}Binário Nativo Standalone (${TARGET_BIN})${NC}"
    fi
fi

DEST_FILE="${INSTALL_DIR}/fux"
if [[ "$TARGET_BIN" == *".exe" ]]; then
    DEST_FILE="${INSTALL_DIR}/fux.exe"
fi

DOWNLOAD_URL="${BASE_URL}/${TARGET_BIN}"
echo -e "⬇️  Baixando de: ${CYAN}${DOWNLOAD_URL}${NC}..."

DOWNLOAD_SUCCESS=0
if curl --fail -sSL "${DOWNLOAD_URL}" -o "${DEST_FILE}.tmp"; then
    DOWNLOAD_SUCCESS=1
elif curl --fail -sSL "${FALLBACK_URL}/dist/${TARGET_BIN}" -o "${DEST_FILE}.tmp" 2>/dev/null; then
    DOWNLOAD_SUCCESS=1
elif curl --fail -sSL "${FALLBACK_URL}/bin/fux" -o "${DEST_FILE}.tmp" 2>/dev/null; then
    echo -e "${YELLOW}⚠️  Binário nativo não encontrado no espelho, baixando script shell universal...${NC}"
    DOWNLOAD_SUCCESS=1
fi

if [[ $DOWNLOAD_SUCCESS -ne 1 ]]; then
    echo -e "${RED}❌ Erro ao baixar o cliente Fuxico.${NC}" >&2
    exit 1
fi

mv "${DEST_FILE}.tmp" "${DEST_FILE}"
chmod +x "${DEST_FILE}"

echo -e "${GREEN}✨ Fuxico instalado com sucesso em: ${BOLD}${DEST_FILE}${NC}"
echo ""

# Test execution
if "${DEST_FILE}" --version >/dev/null 2>&1; then
    VERSION_OUTPUT="$("${DEST_FILE}" --version 2>&1 | head -n 1)"
    echo -e "🚀 Versão: ${CYAN}${VERSION_OUTPUT}${NC}"
fi

# PATH check
case ":$PATH:" in
    *":$INSTALL_DIR:"*) ;;
    *)
        echo -e "${YELLOW}💡 Dica:${NC} O diretório ${INSTALL_DIR} não está no seu PATH."
        echo -e "Adicione ao seu ~/.bashrc ou ~/.zshrc:"
        echo -e "   ${BOLD}export PATH=\"${INSTALL_DIR}:\$PATH\"${NC}"
        echo ""
        ;;
esac

echo -e "${YELLOW}Agora é só soltar o verbo:${NC}"
echo -e "   ${BOLD}fux foto.png --caduca 2h --copia${NC}"
echo -e "   ${BOLD}cat dump.sql | fux -q${NC}"
echo -e "   ${BOLD}fux --ajuda${NC}"
echo ""
