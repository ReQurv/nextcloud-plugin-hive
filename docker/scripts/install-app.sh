#!/usr/bin/env bash
#
# Install the ReQurv Hive app tarball into a running Nextcloud container.
#
# Usage (on the instance host, podman context pointing at the instance):
#   bash install-app.sh [path/to/requrvhive.tar.gz]
#
# Environment:
#   CONTAINER  container name (default: nextcloud)

set -euo pipefail

CONTAINER="${CONTAINER:-nextcloud}"
TARBALL="${1:-/tmp/requrvhive.tar.gz}"

[ -f "$TARBALL" ] || { echo "ERROR: tarball not found: $TARBALL" >&2; exit 1; }
podman container exists "$CONTAINER" || { echo "ERROR: container '$CONTAINER' not found" >&2; exit 1; }

# Warn (not fail) if /var/www/html is not a persistent mount: files written to
# the container layer are lost when the container is recreated.
if ! podman inspect "$CONTAINER" --format '{{range .Mounts}}{{.Destination}}{{" "}}{{end}}' | tr ' ' '\n' | grep -qx '/var/www/html'; then
    echo "WARNING: /var/www/html is not a bind mount of container '$CONTAINER'."
    echo "         The app will be lost if the container is recreated. Consider"
    echo "         adding a volume for /var/www/html before deploying."
fi

echo "=== $CONTAINER: preflight ==="
podman exec "$CONTAINER" php -v | head -1
podman exec "$CONTAINER" php occ --version | head -1

echo "=== copy tarball into container ==="
podman cp "$TARBALL" "$CONTAINER":/tmp/requrvhive.tar.gz

echo "=== extract to apps/requrvhive ==="
podman exec -u root "$CONTAINER" bash -c '
    set -euo pipefail
    mkdir -p /var/www/html/apps
    [ -d /var/www/html/apps/requrvhive ] && rm -rf /var/www/html/apps/requrvhive
    tar -xzf /tmp/requrvhive.tar.gz -C /var/www/html/apps
    chown -R www-data:www-data /var/www/html/apps/requrvhive
    rm -f /tmp/requrvhive.tar.gz
    echo "[OK] extracted to /var/www/html/apps/requrvhive"
'

echo "=== occ app:install requrvhive ==="
podman exec "$CONTAINER" php occ app:install requrvhive

echo "=== verify ==="
podman exec "$CONTAINER" php occ app:list --enabled | grep -i requrv || {
    echo "ERROR: requrvhive not in the enabled app list" >&2
    exit 1
}

echo
echo "=== done ==="
echo "Now set the API key:  Administration settings -> ReQurv Hive"
