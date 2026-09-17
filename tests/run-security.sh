#!/bin/sh
set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
project_name="masterbb-security-$$"
security_port="${MASTERBB_SECURITY_PORT:-18081}"
keep_stack="${KEEP_SECURITY_STACK:-0}"

compose() {
    MASTERBB_SESSION_IDLE_SECONDS=5 \
    MASTERBB_SESSION_ABSOLUTE_SECONDS=300 \
    MASTERBB_TEST_MAIL_LOG=/tmp/masterbb-security-mail.log \
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
config_mode=$(compose exec -T phpbb stat -Lc '%a' /var/www/html/phpBB/config.php)
if [ "$config_mode" != "400" ]; then
    echo "not ok - installer did not make config.php read-only (mode $config_mode)" >&2
    exit 1
fi
echo "ok - installed configuration is read-only"
if compose logs --no-color phpbb 2>&1 | grep -F 'installer-secret-must-not-echo' >/dev/null; then
    echo "not ok - installer credentials appeared in server logs" >&2
    exit 1
fi
if ! compose logs --no-color phpbb 2>&1 | grep -F '"event":"database_error"' >/dev/null; then
    echo "not ok - structured database diagnostic event was not logged" >&2
    exit 1
fi
echo "ok - database failures log structured diagnostics without installer secrets"
compose up -d --force-recreate --no-deps phpbb >/dev/null
echo "ok - installed configuration survives container replacement"
python3 "$repo_dir/tests/smoke.py" --base-url "$base_url" exercise
python3 "$repo_dir/tests/security.py" --base-url "$base_url" --idle-timeout-test-seconds 6

auth_attempt_rows=$(compose exec -T db mariadb -N -uphpbb -pphpbb phpbb -e \
    'SELECT COUNT(*) FROM auth_attempts')
invalid_auth_keys=$(compose exec -T db mariadb -N -uphpbb -pphpbb phpbb -e \
    "SELECT COUNT(*) FROM auth_attempts WHERE CHAR_LENGTH(account_key) != 64 OR CHAR_LENGTH(network_key) != 64 OR account_key LIKE '%ThrottleTarget%' OR network_key LIKE '%127.0.0.1%'")
if [ "$auth_attempt_rows" -le 0 ] || [ "$auth_attempt_rows" -gt 10000 ] || [ "$invalid_auth_keys" -ne 0 ]; then
    echo "not ok - authentication throttle storage is missing, unbounded, or contains raw keys" >&2
    exit 1
fi
echo "ok - authentication throttle storage is bounded and keyed by digests"

expired_token=$(compose exec -T phpbb sed -n 's/.*token=\([a-f0-9]\{64\}\).*/\1/p' /tmp/masterbb-security-mail.log | tail -n 1)
if [ "${#expired_token}" -ne 64 ]; then
    echo "not ok - could not recover the disposable reset token from the test mail sink" >&2
    exit 1
fi
if compose exec -T phpbb grep -F -e smoke-user-pass -e smoke-attacker-pass /tmp/masterbb-security-mail.log >/dev/null; then
    echo "not ok - a registration email contained a plaintext password" >&2
    exit 1
fi
echo "ok - registration email omits plaintext credentials"
raw_token_rows=$(compose exec -T db mariadb -N -uphpbb -pphpbb phpbb -e \
    "SELECT COUNT(*) FROM users WHERE user_reset_token = '$expired_token'")
digest_token_rows=$(compose exec -T db mariadb -N -uphpbb -pphpbb phpbb -e \
    "SELECT COUNT(*) FROM users WHERE user_reset_token = SHA2('$expired_token', 256)")
if [ "$raw_token_rows" -ne 0 ] || [ "$digest_token_rows" -ne 1 ]; then
    echo "not ok - reset token was not stored exclusively as a digest" >&2
    exit 1
fi
echo "ok - password-reset secrets are stored only as digests"
compose exec -T db mariadb -uphpbb -pphpbb phpbb -e \
    "UPDATE users SET user_reset_expires = 0 WHERE username = 'SmokeUser'"
python3 "$repo_dir/tests/reset_security.py" --base-url "$base_url" --token "$expired_token" expired

python3 "$repo_dir/tests/reset_security.py" --base-url "$base_url" request
reset_token=$(compose exec -T phpbb sed -n 's/.*token=\([a-f0-9]\{64\}\).*/\1/p' /tmp/masterbb-security-mail.log | tail -n 1)
if [ "${#reset_token}" -ne 64 ] || [ "$reset_token" = "$expired_token" ]; then
    echo "not ok - replacement reset token was not freshly generated" >&2
    exit 1
fi
python3 "$repo_dir/tests/reset_security.py" --base-url "$base_url" --token "$reset_token" consume

short_hashes=$(compose exec -T db mariadb -N -uphpbb -pphpbb phpbb -e \
    'SELECT COUNT(*) FROM users WHERE user_id > 0 AND CHAR_LENGTH(user_password) <= 32')
if [ "$short_hashes" -ne 0 ]; then
    echo "not ok - found a legacy-length password hash" >&2
    exit 1
fi
plain_reset_tokens=$(compose exec -T db mariadb -N -uphpbb -pphpbb phpbb -e \
    'SELECT COUNT(*) FROM users WHERE user_reset_token IS NOT NULL')
if [ "$plain_reset_tokens" -ne 0 ]; then
    echo "not ok - consumed reset token remained in the database" >&2
    exit 1
fi
echo "ok - installed credentials use modern hashes and consumed reset state is cleared"

compose run --rm --no-deps -v "$repo_dir:/workspace:ro" phpbb php /workspace/tests/security_helpers.php
