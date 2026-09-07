#!/bin/sh
set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
project_name="masterbb-security-$$"
security_port="${MASTERBB_SECURITY_PORT:-18081}"
keep_stack="${KEEP_SECURITY_STACK:-0}"

compose() {
    PHPBB_HTTP_PORT="$security_port" docker compose \
        --project-directory "$repo_dir" \
        -p "$project_name" \
        "$@"
}

cleanup() {
    if [ "$keep_stack" = "1" ]; then
        echo "Keeping security stack $project_name on port $security_port"
        echo "Remove it with: docker compose -p $project_name down -v --remove-orphans"
    else
        compose down -v --remove-orphans >/dev/null 2>&1 || true
    fi
}

trap cleanup EXIT INT TERM

echo "Starting isolated security stack $project_name on port $security_port"
compose up -d --build

base_url="http://127.0.0.1:$security_port/phpBB"
python3 "$repo_dir/tests/smoke.py" --base-url "$base_url" install
compose exec -T phpbb chmod 444 /var/www/html/phpBB/config.php
python3 "$repo_dir/tests/smoke.py" --base-url "$base_url" exercise
python3 "$repo_dir/tests/security.py" --base-url "$base_url"
