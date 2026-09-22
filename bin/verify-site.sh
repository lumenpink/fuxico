#!/usr/bin/env bash
# =============================================================================
# FUXICO — Static Documentation Site Integrity & Link Verifier
# https://fuxico.2lp.in
# =============================================================================
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITE_DIR="${ROOT_DIR}/site"

echo "🔍 [verify-site] Validando integridade do site estático em ${SITE_DIR}..."

# 1. Required Pages
REQUIRED_PAGES=(
    "index.html"
    "installation.html"
    "downloads.html"
    "commands.html"
    "protocol.html"
    "pajuba.html"
    "pt/index.html"
    "pt/installation.html"
    "pt/downloads.html"
    "pt/commands.html"
    "pt/protocol.html"
    "pt/pajuba.html"
)

for page in "${REQUIRED_PAGES[@]}"; do
    if [[ ! -f "${SITE_DIR}/${page}" ]]; then
        echo "❌ [verify-site] Página ausente: ${SITE_DIR}/${page}" >&2
        exit 1
    fi
    echo "   ✅ Página encontrada: ${page}"
done

# 2. Critical Configuration & Installer Files
CRITICAL_FILES=(
    "_headers"
    "fux-installer"
    "install.sh"
    "assets/style.css"
    "assets/main.js"
    "assets/favicon.svg"
)

for file in "${CRITICAL_FILES[@]}"; do
    if [[ ! -f "${SITE_DIR}/${file}" ]]; then
        echo "❌ [verify-site] Arquivo crítico ausente: ${SITE_DIR}/${file}" >&2
        exit 1
    fi
    echo "   ✅ Arquivo crítico encontrado: ${file}"
done

# 3. Cloudflare Deployment Configuration (wrangler.jsonc)
if [[ ! -f "${ROOT_DIR}/wrangler.jsonc" ]]; then
    echo "❌ [verify-site] Configuração do Cloudflare ausente: ${ROOT_DIR}/wrangler.jsonc" >&2
    exit 1
fi
if ! grep -q '"pattern": "fuxico.2lp.in"' "${ROOT_DIR}/wrangler.jsonc"; then
    echo "❌ [verify-site] wrangler.jsonc não contém a rota 'fuxico.2lp.in'" >&2
    exit 1
fi
if ! grep -q '"directory": "./site"' "${ROOT_DIR}/wrangler.jsonc"; then
    echo "❌ [verify-site] wrangler.jsonc não aponta para o diretório de ativos estáticos './site'" >&2
    exit 1
fi
echo "   ✅ Cloudflare wrangler.jsonc verificado: fuxico.2lp.in -> ./site"

# 4. Installer Syntax Check
bash -n "${SITE_DIR}/fux-installer"
bash -n "${SITE_DIR}/install.sh"
echo "   ✅ Sintaxe bash dos instaladores verificada sem erros"

# 5. Image & Asset Reference Verification
echo "🖼️  [verify-site] Verificando referências de imagens..."
MISSING_ASSETS=0
while IFS= read -r html_file; do
    html_dir="$(dirname "$html_file")"
    # Extract src="..."
    grep -o -E 'src="[^"]+"' "$html_file" | sed -E 's/src="([^"]+)"/\1/' | while read -r src; do
        # Ignore external URLs
        if [[ "$src" =~ ^https?:// ]] || [[ "$src" == "" ]]; then
            continue
        fi

        target_asset="${html_dir}/${src}"
        if [[ ! -f "$target_asset" ]]; then
            echo "   ❌ [verify-site] Asset quebrado em ${html_file#$SITE_DIR/}: ${src} (esperado em ${target_asset})" >&2
            exit 1
        fi
    done
done < <(find "${SITE_DIR}" -name "*.html")

echo "✨ [verify-site] Todas as páginas, assets e instaladores foram verificados com sucesso!"
