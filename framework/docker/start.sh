#!/bin/sh
set -eu

echo "=== WalangBrownout production startup ==="

CUTOFF=20261001000000

for file in $(find database/migrations -maxdepth 1 -type f -name "*.php" | sort); do
    name=$(basename "$file")

    stamp=$(printf "%s" "$name" | awk -F_ '{print $1 $2 $3 $4}')

    case "$stamp" in
        ''|*[!0-9]*)
            continue
            ;;
    esac

    if [ "$stamp" -gt "$CUTOFF" ]; then
        echo "Checking migration: $name"
        php artisan migrate --force --path="$file"
    fi
done

echo "=== Starting Apache ==="
exec apache2-foreground
