#!/bin/bash
set -euo pipefail

PLUGIN_NAME="lxc"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
SRC_DIR="${REPO_DIR}/source/usr/local/emhttp/plugins/${PLUGIN_NAME}"
DIST_DIR="${REPO_DIR}/dist"
PLG_FILE="${REPO_DIR}/${PLUGIN_NAME}.plg"

# Version resolution: pass as $1 (e.g. 2026.09.17 or v2026.09.17) or defaults to current date
RAW_VERSION="${1:-$(date +'%Y.%m.%d')}"
VERSION="${RAW_VERSION#v}"

echo "Packaging ${PLUGIN_NAME} version: ${VERSION}"

# Verify source directory exists
if [ ! -d "${SRC_DIR}" ]; then
  echo "Error: Source directory '${SRC_DIR}' does not exist!"
  exit 1
fi

mkdir -p "${DIST_DIR}"

TMP_BUILD="$(mktemp -d /tmp/makepkg_${PLUGIN_NAME}_XXXXXX)"
trap 'rm -rf "${TMP_BUILD}"' EXIT

STAGE_DIR="${TMP_BUILD}/usr/local/emhttp/plugins/${PLUGIN_NAME}"
mkdir -p "${STAGE_DIR}"
cp -a "${SRC_DIR}/." "${STAGE_DIR}/"

# Normalize permissions
chmod -R 755 "${TMP_BUILD}"

PKG_FILE="${DIST_DIR}/${PLUGIN_NAME}-${VERSION}.txz"
MD5_FILE="${DIST_DIR}/${PLUGIN_NAME}-${VERSION}.txz.md5"

# Build package using Slackware makepkg if available, otherwise standard tar
if command -v makepkg >/dev/null 2>&1; then
  (cd "${TMP_BUILD}" && makepkg -l y -c y "${PKG_FILE}")
else
  (cd "${TMP_BUILD}" && tar --owner=0 --group=0 --numeric-owner -cJf "${PKG_FILE}" .)
fi

chmod 755 "${PKG_FILE}"

# Compute MD5
MD5="$(md5sum "${PKG_FILE}" | awk '{print $1}')"
echo "${MD5}" > "${MD5_FILE}"
chmod 644 "${MD5_FILE}"

echo "Package created: ${PKG_FILE}"
echo "MD5 Checksum:    ${MD5}"

# Update lxc.plg with new version and MD5
if [ -f "${PLG_FILE}" ]; then
  echo "Updating ${PLG_FILE}..."
  sed -i -E "s/(<!ENTITY version\s+\")[^\"]+(\">)/\1${VERSION}\2/" "${PLG_FILE}"
  sed -i -E "s/(<!ENTITY md5\s+\")[^\"]+(\">)/\1${MD5}\2/" "${PLG_FILE}"
  echo "Successfully updated version to '${VERSION}' and md5 to '${MD5}' in ${PLG_FILE}"
fi