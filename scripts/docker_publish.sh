#!/usr/bin/env bash
# ==============================================================================
# Fuxico Docker Build & Publish Tool
# ==============================================================================
# Builds production and CI Docker images and publishes them to Docker Hub with
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
DEFAULT_PROD_IMAGE="lumenpink/fuxico"
DEFAULT_CI_IMAGE="lumenpink/fuxico-ci"

TARGET_MODE="prod" # "prod", "ci", or "all"
IMAGE_NAME=""
DRY_RUN=false
FORCE_LATEST=false
NO_CACHE=false
EXTRA_TAGS=()

usage() {
    cat <<EOF
${BOLD}Usage:${RESET} $0 [OPTIONS]

${BOLD}Options:${RESET}
    -t, --tag <tag>       Add a custom image tag (can be used multiple times)
    -i, --image <name>    Override Docker repository name
    --ci                  Build and publish the CI image (${DEFAULT_CI_IMAGE})
    --all                 Build and publish BOTH production and CI images
    -l, --latest          Always tag and push as 'latest'
    -n, --dry-run         Build and tag locally without pushing to Docker Hub
    --no-cache            Build without using Docker layer cache
    -h, --help            Show this help documentation

${BOLD}Environment Variables:${RESET}
    DOCKER_IMAGE          Override default Docker image repository
    DOCKERHUB_USERNAME    Username for automated Docker Hub authentication
    DOCKERHUB_TOKEN       Access token or password for Docker Hub authentication
    DOCKERHUB_PASSWORD    Alternative to DOCKERHUB_TOKEN

${BOLD}Examples:${RESET}
    $0 --dry-run
    $0 --ci --dry-run
    $0 --all
    $0 --tag v0.1.0 --latest
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
        --ci)
            TARGET_MODE="ci"
            shift
            ;;
        --all)
            TARGET_MODE="all"
            shift
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
echo -e "${BOLD} 🐳 Fuxico Docker Build & Publish Pipeline           ${RESET}"
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

# 2. Authenticate once if credentials are provided
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

# Helper function to build and publish a target image
build_and_publish_image() {
    local target_image="$1"
    local dockerfile_path="$2"
    local is_ci="$3"

    echo
    echo -e "${BOLD}--- Processing: ${target_image} ---${RESET}"

    if [[ ! -f "${dockerfile_path}" ]]; then
        error "Dockerfile not found: ${dockerfile_path}"
        exit 1
    fi

    local tags=()

    if [[ "${is_ci}" == true ]]; then
        tags+=("latest" "php8.4")
    else
        # Determine production tags
        if [[ -f "${REPO_ROOT}/VERSION" ]]; then
            local raw_ver
            raw_ver="$(head -n 1 "${REPO_ROOT}/VERSION" | tr -d '\r\n[:space:]')"
            local clean_ver="${raw_ver%%+*}"
            if [[ -n "${clean_ver}" ]]; then
                tags+=("${clean_ver}")
            fi
        fi

        if git -C "${REPO_ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
            local git_sha
            git_sha="$(git -C "${REPO_ROOT}" rev-parse --short HEAD)"
            tags+=("sha-${git_sha}")

            local current_branch
            current_branch="$(git -C "${REPO_ROOT}" rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")"
            if [[ "${current_branch}" == "main" ]]; then
                FORCE_LATEST=true
            fi
        fi

        if [[ "${FORCE_LATEST}" == true ]]; then
            tags+=("latest")
        fi
    fi

    # Append custom tags
    for custom_tag in "${EXTRA_TAGS[@]}"; do
        tags+=("${custom_tag}")
    done

    # Deduplicate tags
    local unique_tags=($(echo "${tags[@]}" | tr ' ' '\n' | sort -u | tr '\n' ' '))
    if [[ ${#unique_tags[@]} -eq 0 ]]; then
        unique_tags=("latest")
    fi

    local primary_tag="${unique_tags[0]}"

    info "Target Repository: ${BOLD}${target_image}${RESET}"
    info "Tags to generate:  ${BOLD}${unique_tags[*]}${RESET}"

    # Build options
    local build_opts=()
    if [[ "${NO_CACHE}" == true ]]; then
        build_opts+=("--no-cache")
    fi

    info "Building image '${target_image}:${primary_tag}' from ${dockerfile_path}..."
    docker build "${build_opts[@]}" \
        -t "${target_image}:${primary_tag}" \
        -f "${dockerfile_path}" \
        "${REPO_ROOT}"

    success "Image built: ${target_image}:${primary_tag}"

    # Tag remaining tags
    for tag in "${unique_tags[@]}"; do
        if [[ "${tag}" != "${primary_tag}" ]]; then
            info "Applying tag: ${target_image}:${tag}"
            docker tag "${target_image}:${primary_tag}" "${target_image}:${tag}"
        fi
    done

    # Push
    if [[ "${DRY_RUN}" == true ]]; then
        warn "Dry-run complete for ${target_image}. Would push tags:"
        for tag in "${unique_tags[@]}"; do
            echo "  - docker push ${target_image}:${tag}"
        done
    else
        info "Pushing images for ${target_image}..."
        for tag in "${unique_tags[@]}"; do
            info "Pushing: ${target_image}:${tag}"
            docker push "${target_image}:${tag}"
            success "Pushed: ${target_image}:${tag}"
        done
        success "Published ${target_image} successfully!"
    fi
}

# 3. Execute requested target(s)
if [[ "${TARGET_MODE}" == "prod" || "${TARGET_MODE}" == "all" ]]; then
    PROD_IMG="${IMAGE_NAME:-${DOCKER_IMAGE:-$DEFAULT_PROD_IMAGE}}"
    build_and_publish_image "${PROD_IMG}" "${REPO_ROOT}/Dockerfile" false
fi

if [[ "${TARGET_MODE}" == "ci" || "${TARGET_MODE}" == "all" ]]; then
    CI_IMG="${IMAGE_NAME:-${DOCKER_CI_IMAGE:-$DEFAULT_CI_IMAGE}}"
    build_and_publish_image "${CI_IMG}" "${REPO_ROOT}/docker/Dockerfile.ci" true
fi

echo
success "Pipeline execution finished successfully!"
