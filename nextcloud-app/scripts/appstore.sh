#!/usr/bin/env bash
set -euo pipefail

APP_ID="requrvhive"
REPO="ReQurv/nextcloud-plugin-hive"
CERT_DIR="${HOME}/.nextcloud/certificates"
KEY="${CERT_DIR}/${APP_ID}.key"
CRT="${CERT_DIR}/${APP_ID}.crt"

fail() { echo "ERROR: $*" >&2; exit 1; }

require_key() {
  [ -f "$KEY" ] || fail "private key not found: $KEY"
  [ -f "$CRT" ] || fail "signed certificate not found: $CRT (waiting for nextcloud/app-certificate-requests PR approval?)"
}

cmd_register() {
  require_key
  echo "=== App Store registration (apps.nextcloud.com/developer/apps/new) ==="
  echo ""
  echo "1) Certificate — paste the full contents of:"
  echo "   $CRT"
  echo ""
  echo "2) Signature:"
  echo -n "$APP_ID" | openssl dgst -sha512 -sign "$KEY" | openssl base64
  echo ""
  echo "3) Then load the release with: $0 release <version>"
}

cmd_release() {
  local version="${1:?usage: $0 release <version>}"
  require_key
  local tag="nc-v${version}"
  local url="https://github.com/${REPO}/releases/download/${tag}/${APP_ID}.tar.gz"
  local dir
  dir="$(mktemp -d)"
  trap 'rm -rf "$dir"' EXIT

  echo "Downloading ${url} ..."
  curl -fL "$url" -o "${dir}/${APP_ID}.tar.gz"
  echo ""
  echo "=== App Store release upload (apps.nextcloud.com/developer/apps/releases/new) ==="
  echo ""
  echo "1) Download link:"
  echo "   ${url}"
  echo ""
  echo "2) Signature:"
  openssl dgst -sha512 -sign "$KEY" "${dir}/${APP_ID}.tar.gz" | openssl base64
  echo ""
  echo "3) Nightly: no (unless intended)"
}

case "${1:-}" in
  register) cmd_register ;;
  release)  cmd_release "${2:-}" ;;
  *)
    echo "Usage:"
    echo "  $0 register           # signature for app registration"
    echo "  $0 release <version>  # download nc-v<version> tarball, print download URL + signature"
    exit 1
    ;;
esac
