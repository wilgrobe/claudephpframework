#!/bin/bash
# bin/setup-php.sh
#
# Reproduces the self-contained PHP install used by Claude inside the Cowork
# sandbox. The sandbox runs as an unprivileged user with no_new_privileges,
# so `apt install` is blocked. This script uses `apt-get download` (which
# needs no root) to pull the Ubuntu 22.04 php8.1-cli packages plus the
# extensions the framework needs, then extracts them into bin/phproot/.
#
# Pair with the bin/php wrapper script (already in the repo) which invokes
# phproot/usr/bin/php8.1 with the right extension_dir and load order.
#
# Expected to run inside an Ubuntu 22.04 x86_64 sandbox with network access
# to archive.ubuntu.com. Idempotent — re-runs overwrite the previous install.

set -euo pipefail

FRAMEWORK_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PREFIX="$FRAMEWORK_DIR/bin/phproot"
DEBS_DIR="$(mktemp -d)"

# Packages: php8.1-cli + mods matching composer.json's ext-* requires, plus
# their shared-library dependencies (libsodium23, libpng16, libedit2, …).
# If you add a new ext-X to composer.json, add php8.1-X here too.
PKGS=(
    php8.1-cli php8.1-common php8.1-opcache php8.1-readline
    php8.1-mysql php8.1-mbstring php8.1-xml php8.1-gd php8.1-curl
    php-common
    libargon2-1 libedit2 libmagic1 libsodium23
    libxslt1.1 libpng16-16 libjpeg-turbo8 libfreetype6 libwebp7
)

echo "→ Downloading $(printf '%d packages' ${#PKGS[@]})..."
cd "$DEBS_DIR"
for p in "${PKGS[@]}"; do
    apt-get download "$p" >/dev/null 2>&1 || {
        echo "  ✗ failed to download $p"
        exit 1
    }
done
echo "  ✓ ${#PKGS[@]} packages downloaded"

echo "→ Extracting to $PREFIX ..."
rm -rf "$PREFIX"
mkdir -p "$PREFIX"
for deb in "$DEBS_DIR"/*.deb; do
    # --- tar warnings about "Cannot unlink" on overlapping files are
    # benign when multiple packages ship the same manpage/changelog.
    dpkg-deb -x "$deb" "$PREFIX" 2>/dev/null || true
done

rm -rf "$DEBS_DIR"

echo "→ Verifying the install ..."
if [[ ! -x "$PREFIX/usr/bin/php8.1" ]]; then
    echo "  ✗ php8.1 binary not found in $PREFIX/usr/bin/"
    exit 1
fi
"$FRAMEWORK_DIR/bin/php" -v
echo
"$FRAMEWORK_DIR/bin/php" -r 'foreach (["sodium","dom","pdo","pdo_mysql","gd","json","mbstring","openssl"] as $e) { echo "  $e: " . (extension_loaded($e)?"✓":"✗") . "\n"; }'

echo
echo "Done. Use \`bin/php\` from the framework root."
