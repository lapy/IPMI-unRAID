#!/bin/bash
set -euo pipefail

ROOT_DIR="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
REPO_DIR="$(dirname "${ROOT_DIR}")"
ARCHIVE_DIR="${REPO_DIR}/archive"
MANIFEST_PATH="${REPO_DIR}/plugin/ipmi.plg"
DEFAULT_VERSION="$(date -u +"%Y.%m.%d")"
VERSION_INPUT=""
NOTES_FILE=""
GITHUB_OUTPUT_FILE=""

usage() {
    cat <<'EOF'
Usage: release.sh [--version VERSION] [--notes-file PATH] [--github-output PATH]

Prepares release metadata, builds the staged package, and prints the resolved
version plus output asset paths.
EOF
}

resolve_version() {
    local base_version="$1"
    local suffix
    local candidate
    local package

    for suffix in '' a b c d e f g h; do
        candidate="${base_version}${suffix}"
        package="${ARCHIVE_DIR}/ipmi-${candidate}-x86_64-1.txz"
        if [ ! -f "${package}" ]; then
            printf '%s\n' "${candidate}"
            return 0
        fi
    done

    echo "Unable to resolve a unique release version from base ${base_version}" >&2
    exit 1
}

write_output() {
    local key="$1"
    local value="$2"

    if [ -n "${GITHUB_OUTPUT_FILE}" ]; then
        printf '%s=%s\n' "${key}" "${value}" >> "${GITHUB_OUTPUT_FILE}"
    fi
}

notes_file_has_content() {
    local notes_file="$1"
    grep -q '[^[:space:]]' "${notes_file}"
}

generate_release_notes_file() {
    local target_file="$1"
    local last_tag=""
    local range=""

    if git -C "${REPO_DIR}" describe --tags --abbrev=0 --match 'v*' >/dev/null 2>&1; then
        last_tag="$(git -C "${REPO_DIR}" describe --tags --abbrev=0 --match 'v*')"
        range="${last_tag}..HEAD"
    fi

    if [ -n "${range}" ]; then
        git -C "${REPO_DIR}" log --no-merges --pretty=format:'%s' "${range}" > "${target_file}"
    else
        git -C "${REPO_DIR}" log --no-merges --pretty=format:'%s' > "${target_file}"
    fi

    if [ ! -s "${target_file}" ]; then
        echo "Automated release build." > "${target_file}"
    fi
}

while [ $# -gt 0 ]; do
    case "$1" in
        --version)
            VERSION_INPUT="${2:-}"
            shift 2
            ;;
        --notes-file)
            NOTES_FILE="${2:-}"
            shift 2
            ;;
        --github-output)
            GITHUB_OUTPUT_FILE="${2:-}"
            shift 2
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [ -n "${NOTES_FILE}" ] && [ ! -f "${NOTES_FILE}" ]; then
    echo "Release notes file not found: ${NOTES_FILE}" >&2
    exit 1
fi

VERSION_BASE="${VERSION_INPUT:-${DEFAULT_VERSION}}"
VERSION="$(resolve_version "${VERSION_BASE}")"
RENDERED_NOTES_FILE="$(mktemp "${TMPDIR:-/tmp}/ipmi-release-notes.XXXXXX.md")"
NOTES_SOURCE_FILE="${NOTES_FILE}"

if [ -z "${NOTES_SOURCE_FILE}" ] || ! notes_file_has_content "${NOTES_SOURCE_FILE}"; then
    NOTES_SOURCE_FILE="$(mktemp "${TMPDIR:-/tmp}/ipmi-release-input.XXXXXX.txt")"
    generate_release_notes_file "${NOTES_SOURCE_FILE}"
fi

php "${ROOT_DIR}/release_info.php" \
    --manifest "${MANIFEST_PATH}" \
    --version "${VERSION}" \
    --notes-file "${NOTES_SOURCE_FILE}" \
    --output-notes-file "${RENDERED_NOTES_FILE}"

bash "${ROOT_DIR}/mkpkg" ipmi "${VERSION}"

PACKAGE_PATH="${ARCHIVE_DIR}/ipmi-${VERSION}-x86_64-1.txz"
MD5_PATH="${ARCHIVE_DIR}/ipmi-${VERSION}-x86_64-1.md5"
VERSIONED_MANIFEST_PATH="${ARCHIVE_DIR}/ipmi-${VERSION}.plg"
TAG="v${VERSION}"

if [ ! -f "${PACKAGE_PATH}" ] || [ ! -f "${MD5_PATH}" ] || [ ! -f "${VERSIONED_MANIFEST_PATH}" ]; then
    echo "Expected release artifacts were not created for ${VERSION}" >&2
    exit 1
fi

write_output version "${VERSION}"
write_output tag "${TAG}"
write_output package_path "${PACKAGE_PATH}"
write_output checksum_path "${MD5_PATH}"
write_output manifest_path "${VERSIONED_MANIFEST_PATH}"
write_output notes_path "${RENDERED_NOTES_FILE}"

printf 'version=%s\n' "${VERSION}"
printf 'tag=%s\n' "${TAG}"
printf 'package_path=%s\n' "${PACKAGE_PATH}"
printf 'checksum_path=%s\n' "${MD5_PATH}"
printf 'manifest_path=%s\n' "${VERSIONED_MANIFEST_PATH}"
printf 'notes_path=%s\n' "${RENDERED_NOTES_FILE}"
