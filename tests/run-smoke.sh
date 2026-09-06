#!/bin/sh
set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
project_name="masterbb-smoke-$$"
smoke_port="${MASTERBB_SMOKE_PORT:-18080}"
keep_stack="${KEEP_SMOKE_STACK:-0}"

compose() {
    PHPBB_HTTP_PORT="$smoke_port" docker compose \
        --project-directory "$repo_dir" \
        -p "$project_name" \
        "$@"
}

cleanup() {
    if [ "$keep_stack" = "1" ]; then
        echo "Keeping smoke stack $project_name on port $smoke_port"
        echo "Remove it with: docker compose -p $project_name down -v --remove-orphans"
    else
        compose down -v --remove-orphans >/dev/null 2>&1 || true
    fi
}

trap cleanup EXIT INT TERM

echo "Starting isolated smoke stack $project_name on port $smoke_port"
compose up -d --build

base_url="http://127.0.0.1:$smoke_port/phpBB"
python3 "$repo_dir/tests/smoke.py" --base-url "$base_url" install

# phpBB's legacy bootstrap refuses to run while config.php is writable.
compose exec -T phpbb chmod 444 /var/www/html/phpBB/config.php

python3 "$repo_dir/tests/smoke.py" --base-url "$base_url" exercise
