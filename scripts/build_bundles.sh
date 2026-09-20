#!/usr/bin/env bash
# ==============================================================================
# Fuxico Release Bundler
# Compiles standalone server (fuxico.php),
# packages Bash client (fux),
# builds native Rust client (fux-linux-x86_64, fux-rs),
# and generates checksums + release tarball.
# ==============================================================================

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
VERSION="$(head -n 1 "${ROOT_DIR}/VERSION" 2>/dev/null | tr -d ' \r\n' || echo "0.1.0")"

echo "📦 Building Fuxico Release Bundles (v${VERSION})..."
mkdir -p "${DIST_DIR}"

# 1. Compile standalone PHP server
echo "🐘 Compiling standalone PHP server bundle..."
php "${ROOT_DIR}/compile.php" --check

# 2. Package Bash CLI client
echo "🐚 Packaging Bash CLI client..."
cp "${ROOT_DIR}/bin/fux" "${DIST_DIR}/fux"
chmod +x "${DIST_DIR}/fux"

# 3. Compile Native Rust CLI client
if command -v cargo >/dev/null 2>&1; then
    echo "🦀 Compiling native Rust CLI (release mode)..."
    cargo build --release --manifest-path "${ROOT_DIR}/cli/Cargo.toml"
    cp "${ROOT_DIR}/cli/target/release/fux" "${DIST_DIR}/fux-linux-x86_64"
    cp "${DIST_DIR}/fux-linux-x86_64" "${DIST_DIR}/fux-rs"
    chmod +x "${DIST_DIR}/fux-linux-x86_64" "${DIST_DIR}/fux-rs"
else
    echo "⚠️ Cargo not found, skipping Rust CLI compilation"
fi

# 4. Generate consolidated release tarball
TARBALL="fuxico-${VERSION}.tar.gz"
echo "🗜️  Creating release tarball: ${TARBALL}..."
(
    cd "${DIST_DIR}"
    rm -f "${TARBALL}" "checksums.sha256"
    
    # Collect available artifacts into tarball
    FILES_TO_PACK=(fuxico.php fux)
    if [[ -f "fux-linux-x86_64" ]]; then
        FILES_TO_PACK+=(fux-linux-x86_64 fux-rs)
    fi
    tar -czf "${TARBALL}" "${FILES_TO_PACK[@]}"

    # 5. Generate SHA-256 checksums
    echo "🔒 Generating SHA-256 checksums..."
    sha256sum * > checksums.sha256
)

echo ""
echo "✨ Release bundles created successfully in dist/:"
echo "--------------------------------------------------"
(
    cd "${DIST_DIR}"
    ls -lh * | awk '{printf "   %-24s %8s\n", $9, $5}'
)
echo "--------------------------------------------------"
