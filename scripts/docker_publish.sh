#!/usr/bin/env bash
# ==============================================================================
# PicoShare Docker Build & Publish Tool
# ==============================================================================
# Builds the production Docker image and publishes it to Docker Hub with
# semantic versioning, git commit tags, and optional :latest tagging.
# ==============================================================================

set -euo pipefail

# Visual formatting
BOLD="$(printf '\033[1m')"
GREEN="$(printf '\033[0;32m')"
YELLOW="$(printf '\033[0;33m')"
CYAN="$(printf '\033[0;36m')"
RED="$(printf '\033[0;31m')"
RESET="$(printf '\033[0m')"

info()    { echo -e "${CYAN}[INFO]${RESET} $*"; }
success() { echo -e "${GREEN}[SUCCESS]${RESET} $*"; }
warn()    { echo -e "${YELLOW}[WARN]${RESET} $*"; }
error()   { echo -e "${RED}[ERROR]${RESET} $*" >&2; }

# Locate repository root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# Default configurations
DEFAULT_IMAGE="lumenpink/picoshare"
IMAGE_NAME="${DOCKER_IMAGE:-$DEFAULT_IMAGE}"
DRY_RUN=false
FORCE_LATEST=false
NO_CACHE=false
EXTRA_TAGS=()

usage() {
    cat <<EOF
${BOLD}Usage:${RESET} $0 [OPTIONS]

${BOLD}Options:${RESET}
    -t, --tag <tag>       Add a custom image tag (can be used multiple times)
    -i, --image <name>    Set Docker repository name (default: ${DEFAULT_IMAGE})
    -l, --latest          Always tag and push as 'latest'
    -n, --dry-run         Build and tag locally without pushing to Docker Hub
    --no-cache            Build the Docker image without using layer cache
    -h, --help            Show this help documentation

${BOLD}Environment Variables:${RESET}
    DOCKER_IMAGE          Default Docker image repository
    DOCKERHUB_USERNAME    Username for automated Docker Hub authentication
    DOCKERHUB_TOKEN       Access token or password for Docker Hub authentication
    DOCKERHUB_PASSWORD    Alternative to DOCKERHUB_TOKEN

${BOLD}Examples:${RESET}
    $0 --dry-run
    $0 --tag v0.1.0 --latest
    $0 -i myorg/picoshare -t dev
EOF
}

# Parse command line flags
while [[ $# -gt 0 ]]; do
    case "$1" in
        -t|--tag)
            if [[ -n "${2:-}" ]]; then
                EXTRA_TAGS+=("$2")
                shift 2
            else
                error "Argument -t/--tag requires a tag value."
                exit 1
            fi
            ;;
        -i|--image)
            if [[ -n "${2:-}" ]]; then
                IMAGE_NAME="$2"
                shift 2
            else
                error "Argument -i/--image requires a repository name."
                exit 1
            fi
            ;;
        -l|--latest)
            FORCE_LATEST=true
            shift
            ;;
        -n|--dry-run)
            DRY_RUN=true
            shift
            ;;
        --no-cache)
            NO_CACHE=true
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            error "Unknown argument: $1"
            usage
            exit 1
            ;;
    esac
done

echo -e "${BOLD}======================================================${RESET}"
echo -e "${BOLD} 🐳 PicoShare Docker Build & Publish Pipeline        ${RESET}"
echo -e "${BOLD}======================================================${RESET}"

# 1. Pre-flight checks
if ! command -v docker >/dev/null 2>&1; then
    error "Docker CLI is not installed or not in PATH."
    exit 1
fi

if ! docker info >/dev/null 2>&1; then
    error "Cannot connect to the Docker daemon. Is Docker running?"
    exit 1
fi

if [[ ! -f "${REPO_ROOT}/Dockerfile" ]]; then
    error "Dockerfile not found in ${REPO_ROOT}."
    exit 1
fi

# 2. Determine tags
TAGS=()

# Extract version from VERSION file if available
if [[ -f "${REPO_ROOT}/VERSION" ]]; then
    RAW_VERSION="$(head -n 1 "${REPO_ROOT}/VERSION" | tr -d '\r\n[:space:]')"
    # Clean version (strip build metadata if desired, e.g. 0.1.0-dev.14)
    CLEAN_VERSION="${RAW_VERSION%%+*}"
    if [[ -n "${CLEAN_VERSION}" ]]; then
        TAGS+=("${CLEAN_VERSION}")
    fi
fi

# Git commit short SHA
if git -C "${REPO_ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    GIT_SHA="$(git -C "${REPO_ROOT}" rev-parse --short HEAD)"
    TAGS+=("sha-${GIT_SHA}")

    # If current branch is main, include latest by default
    CURRENT_BRANCH="$(git -C "${REPO_ROOT}" rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")"
    if [[ "${CURRENT_BRANCH}" == "main" ]]; then
        FORCE_LATEST=true
    fi
fi

if [[ "${FORCE_LATEST}" == true ]]; then
    TAGS+=("latest")
fi

# Append custom user tags
for custom_tag in "${EXTRA_TAGS[@]}"; do
    TAGS+=("${custom_tag}")
done

# Deduplicate tags
UNIQUE_TAGS=($(echo "${TAGS[@]}" | tr ' ' '\n' | sort -u | tr '\n' ' '))

if [[ ${#UNIQUE_TAGS[@]} -eq 0 ]]; then
    UNIQUE_TAGS=("latest")
fi

PRIMARY_TAG="${UNIQUE_TAGS[0]}"

info "Target Repository: ${BOLD}${IMAGE_NAME}${RESET}"
info "Tags to generate:  ${BOLD}${UNIQUE_TAGS[*]}${RESET}"
if [[ "${DRY_RUN}" == true ]]; then
    warn "DRY RUN MODE: Images will be built and tagged locally, but NOT pushed to registry."
fi

# 3. Docker Hub Authentication (if credentials provided)
DOCKER_AUTH_SECRET="${DOCKERHUB_TOKEN:-${DOCKERHUB_PASSWORD:-}}"
if [[ -n "${DOCKERHUB_USERNAME:-}" && -n "${DOCKER_AUTH_SECRET}" ]]; then
    info "Authenticating with Docker Hub as '${DOCKERHUB_USERNAME}'..."
    if [[ "${DRY_RUN}" == false ]]; then
        echo "${DOCKER_AUTH_SECRET}" | docker login -u "${DOCKERHUB_USERNAME}" --password-stdin
        success "Docker Hub authentication successful."
    else
        info "[Dry-Run] Skipped docker login."
    fi
fi

# 4. Build Docker image
BUILD_OPTS=()
if [[ "${NO_CACHE}" == true ]]; then
    BUILD_OPTS+=("--no-cache")
fi

info "Building image '${IMAGE_NAME}:${PRIMARY_TAG}'..."
docker build "${BUILD_OPTS[@]}" \
    -t "${IMAGE_NAME}:${PRIMARY_TAG}" \
    -f "${REPO_ROOT}/Dockerfile" \
    "${REPO_ROOT}"

success "Image built: ${IMAGE_NAME}:${PRIMARY_TAG}"

# 5. Apply additional tags
for tag in "${UNIQUE_TAGS[@]}"; do
    if [[ "${tag}" != "${PRIMARY_TAG}" ]]; then
        info "Applying tag: ${IMAGE_NAME}:${tag}"
        docker tag "${IMAGE_NAME}:${PRIMARY_TAG}" "${IMAGE_NAME}:${tag}"
    fi
done

# 6. Push images
if [[ "${DRY_RUN}" == true ]]; then
    echo
    warn "Dry-run complete. Would push the following tags:"
    for tag in "${UNIQUE_TAGS[@]}"; do
        echo "  - docker push ${IMAGE_NAME}:${tag}"
    done
    echo
    success "Dry run verification completed successfully!"
    exit 0
fi

echo
info "Pushing images to registry..."
for tag in "${UNIQUE_TAGS[@]}"; do
    info "Pushing: ${IMAGE_NAME}:${tag}"
    docker push "${IMAGE_NAME}:${tag}"
    success "Pushed: ${IMAGE_NAME}:${tag}"
done

echo
success "All images successfully published to ${IMAGE_NAME}!"
