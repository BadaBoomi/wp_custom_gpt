#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="wp-custom-gpt"
MAIN_FILE="wp-custom-gpt.php"
DIST_DIR="${ROOT_DIR}/dist"
BUILD_DIR="${ROOT_DIR}/.build"
PACKAGE_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

if ! command -v rsync >/dev/null 2>&1; then
  echo "Error: rsync is required but not installed."
  exit 1
fi

if [[ ! -f "${ROOT_DIR}/${MAIN_FILE}" ]]; then
  echo "Error: ${MAIN_FILE} not found in ${ROOT_DIR}."
  exit 1
fi

VERSION="$(awk -F': ' '/^[[:space:]]*\*[[:space:]]*Version:/ {print $2; exit}' "${ROOT_DIR}/${MAIN_FILE}" | tr -d '\r')"
if [[ -z "${VERSION}" ]]; then
  VERSION="dev"
fi

ZIP_FILE="${DIST_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

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
  PACKAGE_WIN="$(wslpath -w "${PACKAGE_DIR}")"
  ZIP_WIN="$(wslpath -w "${ZIP_FILE}")"
  powershell.exe -NoProfile -Command "Compress-Archive -Path '${PACKAGE_WIN}\\*' -DestinationPath '${ZIP_WIN}' -Force" >/dev/null
else
  echo "Error: neither 'zip' nor 'powershell.exe' is available for archive creation."
  rm -rf "${BUILD_DIR}"
  exit 1
fi

rm -rf "${BUILD_DIR}"

echo "Created WordPress plugin archive: ${ZIP_FILE}"
