#!/bin/sh
set -eu

APP_ROOT=/var/www/inni

# Bind mounts replace image ownership; Apache (www-data) must write SQLite + uploads.
mkdir -p "$APP_ROOT/data" "$APP_ROOT/public/uploads"
chown -R www-data:www-data "$APP_ROOT/data" "$APP_ROOT/public/uploads"
chmod -R ug+rwX "$APP_ROOT/data" "$APP_ROOT/public/uploads"

# A missing host file bind-mounted as ./config.php becomes a directory — fail clearly.
if [ -d "$APP_ROOT/config.php" ]; then
  echo "config.php is a directory. Docker created it because the host path did not exist." >&2
  echo "Remove that directory, then either omit the bind mount or create the file first:" >&2
  echo "  cp config.example.php config.php" >&2
  exit 1
fi

# First boot: seed config.php from the example (demo_login stays true).
if [ ! -f "$APP_ROOT/config.php" ]; then
  if [ ! -f "$APP_ROOT/config.example.php" ]; then
    echo "config.example.php missing" >&2
    exit 1
  fi
  cp "$APP_ROOT/config.example.php" "$APP_ROOT/config.php"
fi

# Host :ro bind mounts may reject chown/chmod — ignore those failures.
chown www-data:www-data "$APP_ROOT/config.php" 2>/dev/null || true
chmod 664 "$APP_ROOT/config.php" 2>/dev/null || true

exec docker-php-entrypoint "$@"
