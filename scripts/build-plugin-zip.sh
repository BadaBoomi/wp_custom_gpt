#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="wp-custom-gpt"
MAIN_FILE="wp-custom-gpt.php"
MAIN_PATH="${ROOT_DIR}/${MAIN_FILE}"
DIST_DIR="${ROOT_DIR}/dist"
BUILD_DIR="${ROOT_DIR}/.build"
PACKAGE_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
BUMP_TYPE="patch"

usage() {
  cat <<'EOF'
Usage: build-plugin-zip.sh [--patch|--minor|--major]

Options:
  --patch   Increase patch version (default)
  --minor   Increase minor version and reset patch to 0
  --major   Increase major version and reset minor/patch to 0
  -h, --help  Show this help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --patch)
      BUMP_TYPE="patch"
      ;;
    --minor)
      BUMP_TYPE="minor"
      ;;
    --major)
      BUMP_TYPE="major"
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Error: Unknown option '$1'."
      usage
      exit 1
      ;;
  esac
  shift
done

if ! command -v rsync >/dev/null 2>&1; then
  echo "Error: rsync is required but not installed."
  exit 1
fi

if [[ ! -f "${MAIN_PATH}" ]]; then
  echo "Error: ${MAIN_FILE} not found in ${ROOT_DIR}."
  exit 1
fi

CURRENT_VERSION="$(awk -F': ' '/^[[:space:]]*\*[[:space:]]*Version:/ {print $2; exit}' "${MAIN_PATH}" | tr -d '\r')"
if [[ -z "${CURRENT_VERSION}" ]]; then
  echo "Error: Could not detect plugin version in ${MAIN_FILE}."
  exit 1
fi

if [[ "${CURRENT_VERSION}" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
  MAJOR="${BASH_REMATCH[1]}"
  MINOR="${BASH_REMATCH[2]}"
  PATCH="${BASH_REMATCH[3]}"

  case "${BUMP_TYPE}" in
    patch)
      NEXT_VERSION="${MAJOR}.${MINOR}.$((PATCH + 1))"
      ;;
    minor)
      NEXT_VERSION="${MAJOR}.$((MINOR + 1)).0"
      ;;
    major)
      NEXT_VERSION="$((MAJOR + 1)).0.0"
      ;;
  esac
else
  echo "Error: Version '${CURRENT_VERSION}' is not semantic (x.y.z)."
  exit 1
fi

sed -i -E "s/^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*).*/\1${NEXT_VERSION}/" "${MAIN_PATH}"
sed -i -E "s/define\('WPCGPT_PLUGIN_VERSION', '[^']*'\);/define('WPCGPT_PLUGIN_VERSION', '${NEXT_VERSION}');/" "${MAIN_PATH}"

ZIP_FILE="${DIST_DIR}/${PLUGIN_SLUG}-${NEXT_VERSION}.zip"

rm -rf "${BUILD_DIR}"
mkdir -p "${PACKAGE_DIR}" "${DIST_DIR}"

rsync -a \
  --exclude='.git/' \
  --exclude='.build/' \
  --exclude='dist/' \
  --exclude='node_modules/' \
  --exclude='*.zip' \
  --exclude='.DS_Store' \
  "${ROOT_DIR}/" "${PACKAGE_DIR}/"

if command -v zip >/dev/null 2>&1; then
  (
    cd "${BUILD_DIR}"
    zip -r "${ZIP_FILE}" "${PLUGIN_SLUG}" >/dev/null
  )
elif command -v powershell.exe >/dev/null 2>&1; then
  BUILD_WIN="$(wslpath -w "${BUILD_DIR}")"
  ZIP_WIN="$(wslpath -w "${ZIP_FILE}")"
  powershell.exe -NoProfile -Command "Compress-Archive -Path '${BUILD_WIN}\\${PLUGIN_SLUG}' -DestinationPath '${ZIP_WIN}' -Force" >/dev/null
else
  echo "Error: neither 'zip' nor 'powershell.exe' is available for archive creation."
  rm -rf "${BUILD_DIR}"
  exit 1
fi

rm -rf "${BUILD_DIR}"

echo "Created WordPress plugin archive: ${ZIP_FILE}"
echo "Version bumped (${BUMP_TYPE}): ${CURRENT_VERSION} -> ${NEXT_VERSION}"
