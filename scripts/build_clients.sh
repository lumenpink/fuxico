#!/usr/bin/env bash
# ==============================================================================
# FUXICO — Multi-Platform Native Rust Client Builder
# Builds standalone native binaries for Linux (x86_64, ARM64, ARMv7, ARMv6),
# macOS (Apple Silicon & Intel) and Windows.
# ==============================================================================
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CLI_DIR="${ROOT_DIR}/cli"
DIST_DIR="${ROOT_DIR}/dist"
mkdir -p "${DIST_DIR}"

# Target to Output Binary mapping
get_output_name() {
    local target="$1"
    case "$target" in
        x86_64-unknown-linux-musl|x86_64-unknown-linux-gnu)
            echo "fux-linux-x86_64"
            ;;
        aarch64-unknown-linux-musl|aarch64-unknown-linux-gnu)
            echo "fux-linux-aarch64"
            ;;
        armv7-unknown-linux-musleabihf|armv7-unknown-linux-gnueabihf)
            echo "fux-linux-armv7"
            ;;
        arm-unknown-linux-musleabi|arm-unknown-linux-gnueabi)
            echo "fux-linux-armv6"
            ;;
        aarch64-apple-darwin)
            echo "fux-darwin-arm64"
            ;;
        x86_64-apple-darwin)
            echo "fux-darwin-x86_64"
            ;;
        x86_64-pc-windows-gnu|x86_64-pc-windows-msvc)
            echo "fux-windows-x86_64.exe"
            ;;
        *)
            echo "fux-${target}"
            ;;
    esac
}

ensure_cross() {
    if command -v cross >/dev/null 2>&1; then
        return 0
    fi
    if command -v docker >/dev/null 2>&1; then
        echo "🔧 [build-clients] 'cross' not found. Downloading static cross binary..."
        mkdir -p "${CLI_DIR}/target/bin"
        local cross_url="https://github.com/cross-rs/cross/releases/download/v0.2.5/cross-x86_64-unknown-linux-musl.tar.gz"
        if curl -sSL "${cross_url}" | tar -xz -C "${CLI_DIR}/target/bin" 2>/dev/null; then
            export PATH="${CLI_DIR}/target/bin:${PATH}"
            chmod +x "${CLI_DIR}/target/bin/cross"
            echo "   ✅ cross installed to ${CLI_DIR}/target/bin/cross"
            return 0
        fi
    fi
    return 1
}

build_single_target() {
    local target="$1"
    local output_name
    output_name="$(get_output_name "$target")"

    echo "🦀 [build-clients] Building for target: ${target} -> ${output_name}..."

    # Check for darwin on linux
    if [[ "$target" == *"darwin"* && "$(uname -s)" != "Darwin" ]]; then
        if ! command -v "o64-clang" >/dev/null 2>&1 && ! command -v "oa64-clang" >/dev/null 2>&1; then
            echo "   ⚠️  Skipping ${target}: macOS target requires macOS runner or osxcross toolchain."
            return 0
        fi
    fi

    # Determine compiler tool (cross vs cargo)
    if command -v cross >/dev/null 2>&1; then
        (cd "${CLI_DIR}" && cross build --release --target "$target")
    elif rustup target list --installed 2>/dev/null | grep -q "^${target}$"; then
        # If building musl on Linux host without custom CC set, provide CC=gcc
        if [[ "$target" == *"musl"* && "$(uname -s)" == "Linux" ]]; then
            export CC="${CC:-gcc}"
        fi
        cargo build --release --manifest-path "${CLI_DIR}/Cargo.toml" --target "$target"
    else
        echo "   ⚠️  Skipping ${target}: target not installed in rustup and cross not available."
        return 0
    fi

    # Locate produced binary
    local src_bin="${CLI_DIR}/target/${target}/release/fux"
    if [[ "$target" == *"windows"* ]]; then
        src_bin="${CLI_DIR}/target/${target}/release/fux.exe"
    fi

    if [[ -f "$src_bin" ]]; then
        cp "$src_bin" "${DIST_DIR}/${output_name}"
        chmod +x "${DIST_DIR}/${output_name}"
        echo "   ✅ Saved standalone binary: ${DIST_DIR}/${output_name}"

        # Backwards compatibility symlink/copy for legacy fux-rs
        if [[ "$output_name" == "fux-linux-x86_64" ]]; then
            cp "${DIST_DIR}/${output_name}" "${DIST_DIR}/fux-rs"
            chmod +x "${DIST_DIR}/fux-rs"
        fi
    else
        echo "   ❌ Error: expected binary not found at ${src_bin}" >&2
        return 1
    fi
}

# Determine targets to build
TARGETS=()
if [[ $# -eq 0 ]]; then
    # Default: build static musl x86_64 if target installed, else fallback to host default
    if rustup target list --installed | grep -q "x86_64-unknown-linux-musl"; then
        TARGETS+=("x86_64-unknown-linux-musl")
    else
        TARGETS+=("x86_64-unknown-linux-gnu")
    fi
elif [[ "$1" == "--all" ]]; then
    # All tier-1/tier-2 supported targets
    TARGETS=(
        "x86_64-unknown-linux-musl"
        "aarch64-unknown-linux-musl"
        "armv7-unknown-linux-musleabihf"
        "arm-unknown-linux-musleabi"
        "aarch64-apple-darwin"
        "x86_64-apple-darwin"
        "x86_64-pc-windows-gnu"
    )
else
    TARGETS=("$@")
fi

echo "🚀 [build-clients] Starting compilation of ${#TARGETS[@]} target(s)..."

# Ensure cross is available if multiple targets or non-host targets requested
if [[ "${#TARGETS[@]}" -gt 1 || "$*" == *"--all"* ]]; then
    ensure_cross || true
fi

for target in "${TARGETS[@]}"; do
    build_single_target "$target"
done

# Update checksums
echo "🔒 [build-clients] Updating SHA-256 checksums in dist/checksums.sha256..."
(
    cd "${DIST_DIR}"
    # Compute checksums for all fux standalone binaries
    sha256sum fux-* > checksums.sha256 2>/dev/null || true
)

echo "✨ [build-clients] Multi-platform compilation completed successfully!"
