# MasterBB 1.5

This is a compatibility port of phpBB 1.4.4. The application retains its
original phpBB name and presentation for now while the runtime beneath it is
being modernised.

The current compatibility milestone runs on:

- Apache 2.4 and PHP 8.4
- MariaDB 11.4
- PDO MySQL for database access

Only fresh installations are supported. The historical upgrade scripts have
been removed.

## Start

```sh
docker compose up --build
```

Then open <http://localhost:8080/phpBB/install.php>.

The web service is deliberately published on `127.0.0.1` only.

## Installer database settings

Use:

- Database server: `db`
- Database name: `phpbb`
- Database username: `phpbb`
- Database password: `phpbb`

The hostname is `db`, not `localhost`, because MariaDB runs in the other
Compose service.

## After installation

The historical installer needs `config.php` to be writable while it records
the selected database settings. The image initially sets mode 666 for that
reason. Once installation is complete, lock it down and remove the installer:

```sh
docker compose exec phpbb chmod 444 /var/www/html/phpBB/config.php
docker compose exec phpbb rm -f /var/www/html/phpBB/install.php
```

The generated configuration lives in the application container. Rebuilding or
recreating that container requires a fresh installation, which matches the
current fresh-install-only scope.

## Smoke tests

The black-box smoke suite installs a fresh board and exercises:

- administrator creation, login, logout, and re-login
- category and forum creation
- user registration, login, and logout
- new topics and replies
- post editing and deletion
- private-message sending and reading
- search

Docker and Python 3.10 or newer are required. Run:

```sh
./tests/run-smoke.sh
```

The script creates a uniquely named Compose project and database volume, uses
port `18080` by default, and removes only those isolated resources afterward.
It does not use or reset the ordinary development database. To choose another
port or retain the stack for inspection:

```sh
MASTERBB_SMOKE_PORT=18081 KEEP_SMOKE_STACK=1 ./tests/run-smoke.sh
```

## Security characterization

The companion negative suite starts another isolated fresh installation and
checks the current boundaries around:

- SQL-injection-shaped login input;
- cross-user post editing and deletion;
- unauthorized private-message access;
- forged private-message HTML options and stored script markup;
- arbitrary SQL sort expressions in search;
- password hashing and one-time reset behavior; and
- session entropy, rotation, expiry, logout isolation, cookie attributes, and
  trusted-proxy boundaries.

It also deliberately verifies and reports the known open CSRF finding, so a
passing run does not imply that the application is ready for an untrusted
network. Run it with:

```sh
./tests/run-security.sh
```

The script uses port `18081` by default and removes its isolated Compose
project and database volume afterward. `MASTERBB_SECURITY_PORT` and
`KEEP_SECURITY_STACK=1` provide the same overrides as the smoke runner.

## HTTPS, proxies, sessions, and mail

For an HTTPS deployment, set `MASTERBB_PUBLIC_URL` to the externally visible
forum base URL, for example `https://forums.example.test/phpBB`. This is used
to construct password-reset links. Configure a working PHP `mail()` transport
in the production image; `MASTERBB_TEST_MAIL_LOG` is only a test-suite mail
sink and must not be set in production.

`MASTERBB_HTTPS_MODE` defaults to `auto`. In that mode direct TLS is detected
normally, while `X-Forwarded-Proto` and `X-Forwarded-For` are ignored unless
the immediate peer is listed in `MASTERBB_TRUSTED_PROXIES`. The proxy list is a
comma-separated set of exact IPv4/IPv6 addresses or CIDRs. List only reverse
proxies you control; do not use a broad private-network range merely for
convenience. `MASTERBB_HTTPS_MODE=on` can force Secure cookies when every
external request is guaranteed to use HTTPS, and `off` is intended only for a
deliberate HTTP development environment.

The defaults are a one-hour idle session lifetime, a 24-hour absolute session
lifetime, and a one-hour password-reset lifetime. They can be changed with
`MASTERBB_SESSION_IDLE_SECONDS`, `MASTERBB_SESSION_ABSOLUTE_SECONDS`, and
`MASTERBB_PASSWORD_RESET_SECONDS`; the absolute lifetime is never allowed to
be shorter than the idle lifetime.

## Reset everything

To discard the development database and start with a fresh installation:

```sh
docker compose down -v
docker compose up --build
```

## Security status

Security hardening is in progress. SQL values are parameterized, rendered
content is constrained and encoded, passwords and resets use modern
primitives, and session/cookie handling has been replaced. CSRF protection,
complete centralized authorization, installer locking, safe error handling,
security headers, validation, and abuse controls remain open.

Do not expose this stack directly to the public Internet. See
[MODERNIZATION_AUDIT.md](MODERNIZATION_AUDIT.md) for the current status and the
recommended hardening sequence, and [SECURITY_AUDIT.md](SECURITY_AUDIT.md) for
the detailed findings and remediation status.
