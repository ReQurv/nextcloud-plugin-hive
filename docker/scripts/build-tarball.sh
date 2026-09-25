#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# ReQurvHive Tarball Builder
# Replicates the CI packaging process (nc-release.yml) locally using Docker.
# Output: docker/installation/requrvhive.tar.gz
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
APP_DIR="${PROJECT_ROOT}/nextcloud-app"
OUTPUT_DIR="${PROJECT_ROOT}/docker/installation"

echo "=== ReQurvHive Tarball Builder ==="
echo "App source: ${APP_DIR}"
echo "Output:     ${OUTPUT_DIR}/requrvhive.tar.gz"
echo ""

# Verify we're in the right place
if [ ! -f "${APP_DIR}/appinfo/info.xml" ]; then
    echo "ERROR: Cannot find nextcloud-app/appinfo/info.xml"
    echo "Run this script from the project root or via the Makefile."
    exit 1
fi

# Extract version for informational purposes
VERSION=$(grep -oP '(?<=<version>)[^<]+' "${APP_DIR}/appinfo/info.xml")
echo "App version: ${VERSION}"
echo ""

# Build inside Docker to avoid requiring composer/node on host.
# The source is mounted read-only and copied into a scratch dir inside the container,
# so the working tree is never modified and no root-owned files land on the host.
echo "[1/2] Building app inside Docker (composer + npm + vite)..."

docker run --rm \
    -v "${APP_DIR}:/src:ro" \
    -v "${OUTPUT_DIR}:/output" \
    -e HOST_UID="$(id -u)" \
    -e HOST_GID="$(id -g)" \
    -w / \
    node:26 \
    bash -c '
        set -euo pipefail

        echo "--- Installing PHP 8.5 + Composer ---"
        apt-get update -qq && apt-get install -y -qq apt-transport-https ca-certificates curl lsb-release gnupg > /dev/null 2>&1
        curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
        echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
        apt-get update -qq && apt-get install -y -qq php8.5-cli php8.5-mbstring php8.5-xml php8.5-curl unzip > /dev/null 2>&1
        curl -sS https://getcomposer.org/installer | php -- --quiet --install-dir=/usr/local/bin --filename=composer
        export COMPOSER_ALLOW_SUPERUSER=1
        echo "PHP $(php -v | head -1 | cut -d" " -f2) + Composer installed."

        echo ""
        echo "--- [1/5] Copying source to scratch dir ---"
        mkdir -p /build
        tar -C /src \
            --exclude=./vendor \
            --exclude=./node_modules \
            --exclude=./js/dist \
            --exclude=./.git \
            --exclude=./.phpunit.result.cache \
            -cf - . | tar -C /build -xf -
        cd /build

        echo "--- [2/5] Installing PHP dependencies ---"
        composer install --no-dev --optimize-autoloader --no-interaction --quiet

        echo "--- [3/5] Installing Node dependencies ---"
        npm ci --silent

        echo "--- [4/5] Building frontend ---"
        npm run build

        echo "--- [5/5] Verifying build ---"
        test -f js/dist/requrvhive-main.js || { echo "ERROR: js/dist/requrvhive-main.js not found!"; exit 1; }
        test -d vendor || { echo "ERROR: vendor/ not found!"; exit 1; }
        php tests/check-vendor-prod-only.php
        echo "Build verified."

        echo ""
        echo "--- Assembling tarball ---"
        mkdir -p /tmp/requrvhive

        for dir in appinfo lib js templates css img vendor; do
            [ -d "$dir" ] && cp -r "$dir" /tmp/requrvhive/
        done

        cp composer.json /tmp/requrvhive/
        [ -f LICENSE ] && cp LICENSE /tmp/requrvhive/
        [ -f CHANGELOG.md ] && cp CHANGELOG.md /tmp/requrvhive/

        cd /tmp
        tar -czf /output/requrvhive.tar.gz requrvhive
        chown "${HOST_UID}:${HOST_GID}" /output/requrvhive.tar.gz

        echo ""
        echo "Tarball created."
    '

echo ""
echo "[2/2] Verifying tarball..."

if [ ! -f "${OUTPUT_DIR}/requrvhive.tar.gz" ]; then
    echo "ERROR: Tarball was not created!"
    exit 1
fi

echo ""
echo "=== Build complete ==="
echo "Tarball: ${OUTPUT_DIR}/requrvhive.tar.gz"
echo "Size:    $(du -h "${OUTPUT_DIR}/requrvhive.tar.gz" | cut -f1)"
echo ""
echo "Contents:"
tar -tzf "${OUTPUT_DIR}/requrvhive.tar.gz" | head -30 || true
echo "..."
