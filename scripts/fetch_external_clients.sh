#!/usr/bin/env bash
# ==============================================================================
# FUXICO — External 0x0.st / Nullpointer & Rustypaste Clients Downloader
# Fetches the latest versions of official and community clients for E2E testing.
# Must fail strictly if any client cannot be fetched or installed.
# ==============================================================================
set -euo pipefail

DEST_DIR="${1:-bin/.clients}"
mkdir -p "$DEST_DIR"

echo "[fetch-clients] Syncing latest upstream clients to: ${DEST_DIR}"

# ------------------------------------------------------------------------------
# 1. rustypaste-cli (rpaste)
# ------------------------------------------------------------------------------
ARCH="$(uname -m)"
case "$ARCH" in
    x86_64)
        TARGET="x86_64-unknown-linux-musl"
        ;;
    aarch64|arm64)
        TARGET="aarch64-unknown-linux-musl"
        ;;
    *)
        TARGET="x86_64-unknown-linux-musl"
        ;;
esac

echo "[fetch-clients] Resolving latest rustypaste-cli release..."
TAG=$(curl -ILs "https://github.com/orhun/rustypaste-cli/releases/latest" | grep -i "^location:" | head -n 1 | sed -E 's/.*tag\///' | tr -d '\r\n ' || true)
if [ -z "$TAG" ]; then
    # Fallback to API
    TAG=$(curl -s "https://api.github.com/repos/orhun/rustypaste-cli/releases/latest" | grep '"tag_name":' | head -n 1 | sed -E 's/.*"([^"]+)".*/\1/' || true)
fi

if [ -z "$TAG" ]; then
    echo "[fetch-clients] ERROR: Failed to resolve latest release tag for rustypaste-cli" >&2
    exit 1
fi

VERSION="${TAG#v}"
TAR_URL="https://github.com/orhun/rustypaste-cli/releases/download/${TAG}/rustypaste-cli-${VERSION}-${TARGET}.tar.gz"
echo "[fetch-clients] Downloading rpaste ${TAG} (${TARGET})..."
curl --fail -sSL "$TAR_URL" | tar -xz --strip-components=1 -C "$DEST_DIR"
chmod +x "$DEST_DIR/rpaste"

# ------------------------------------------------------------------------------
# 2. AndyRusso/0x0 (Nullpointer / 0x0.st Bash client)
# ------------------------------------------------------------------------------
echo "[fetch-clients] Downloading latest AndyRusso/0x0 client..."
curl --fail -sSL "https://raw.githubusercontent.com/AndyRusso/0x0/master/0x0" -o "$DEST_DIR/0x0-andy"
chmod +x "$DEST_DIR/0x0-andy"

# ------------------------------------------------------------------------------
# 3. olavfosse/0x0 (Nullpointer / 0x0.st POSIX sh client)
# ------------------------------------------------------------------------------
echo "[fetch-clients] Downloading latest olavfosse/0x0 client..."
curl --fail -sSL "https://raw.githubusercontent.com/olavfosse/0x0/master/src/0x0" -o "$DEST_DIR/0x0-olav"
chmod +x "$DEST_DIR/0x0-olav"

# ------------------------------------------------------------------------------
# Validation
# ------------------------------------------------------------------------------
for bin in "$DEST_DIR/rpaste" "$DEST_DIR/0x0-andy" "$DEST_DIR/0x0-olav"; do
    if [ ! -f "$bin" ] || [ ! -x "$bin" ]; then
        echo "[fetch-clients] ERROR: Required client binary ${bin} is missing or not executable!" >&2
        exit 1
    fi
done

echo "[fetch-clients] All external clients fetched and verified successfully:"
ls -lh "$DEST_DIR"
