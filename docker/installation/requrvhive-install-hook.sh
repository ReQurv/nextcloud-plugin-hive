#!/bin/bash
set -euo pipefail

echo "=== ReQurvHive post-installation hook ==="
echo "[INFO] Running as: $(id)"

TARBALL="/tmp/requrvhive.tar.gz"
APPS_DIR="/var/www/html/custom_apps"

occ() {
    if [ "$(id -u)" = "0" ]; then
        su -p www-data -s /bin/sh -c "php /var/www/html/occ $*"
    else
        php /var/www/html/occ "$@"
    fi
}

# --- Extract tarball ---
if [ ! -f "$TARBALL" ]; then
    echo "[ERROR] Tarball not found: $TARBALL"
    exit 1
fi

echo "[INFO] Tarball size: $(du -h "$TARBALL" | cut -f1)"
mkdir -p "$APPS_DIR"
[ -d "$APPS_DIR/requrvhive" ] && rm -rf "$APPS_DIR/requrvhive"
tar -xzf "$TARBALL" -C "$APPS_DIR"
# Only chown if running as root; if already www-data files are already owned correctly
[ "$(id -u)" = "0" ] && chown -R www-data:www-data "$APPS_DIR/requrvhive"
echo "[OK] ReQurvHive extracted to $APPS_DIR/requrvhive"

# --- Enable app ---
echo "[OCC] Enabling ReQurvHive app..."
occ app:enable requrvhive
echo "[OK] ReQurvHive enabled"

# --- Install optional apps for full test coverage ---
echo "[OCC] Installing Talk, Notes, and Tasks apps..."
occ app:install spreed 2>/dev/null || occ app:enable spreed
occ app:install notes
occ app:install tasks
echo "[OK] Talk, Notes, and Tasks installed"

# --- Optional: create test user ---
if [ -n "${REQURVHIVE_TEST_USER:-}" ] && [ -n "${REQURVHIVE_TEST_PASSWORD:-}" ]; then
    echo "[SETUP] Creating test user: $REQURVHIVE_TEST_USER"
    OC_PASS="$REQURVHIVE_TEST_PASSWORD" occ user:add --password-from-env --display-name "Test User" --group users "$REQURVHIVE_TEST_USER" || true
fi

# --- Optional: configure Claude API key ---
if [ -n "${REQURVHIVE_CLAUDE_API_KEY:-}" ]; then
    echo "[SETUP] Setting Claude API key..."
    occ config:app:set requrvhive api_key --value="$REQURVHIVE_CLAUDE_API_KEY" || true
fi

# --- Debug settings ---
occ config:system:set debug --value=true --type=boolean 2>/dev/null || true
occ config:system:set loglevel --value=0 --type=integer 2>/dev/null || true

echo "=== ReQurvHive post-installation hook complete ==="
