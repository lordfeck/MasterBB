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
config_mode=$(compose exec -T phpbb stat -Lc '%a' /var/www/html/phpBB/config.php)
if [ "$config_mode" != "400" ]; then
    echo "not ok - installer did not make config.php read-only (mode $config_mode)" >&2
    exit 1
fi
echo "ok - installed configuration is read-only"
compose up -d --force-recreate --no-deps phpbb >/dev/null
echo "ok - installed configuration survives container replacement"
python3 "$repo_dir/tests/smoke.py" --base-url "$base_url" exercise
