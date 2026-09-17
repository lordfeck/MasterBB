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

These are development defaults. Override `MASTERBB_DB_ROOT_PASSWORD`,
`MASTERBB_DB_NAME`, `MASTERBB_DB_USER`, and `MASTERBB_DB_PASSWORD` before any
non-local deployment, then enter the matching non-root application credentials
in the installer. Do not use the example passwords outside an isolated local
environment.

## After installation

The installer keeps database credentials in a short-lived server-side session;
they are not returned through hidden browser fields. Successful installation
adds a permanent marker to `config.php`, changes that file to mode `0400`,
destroys the installation session, and makes every later installer GET or POST
return HTTP 403. Removing `install.php` from a production image remains useful
defense in depth, but is no longer the lock mechanism.

Generated configuration is stored in the `phpbb-config` volume and therefore
survives web-container replacement. Back up that volume alongside `phpbb-db`.
Deleting either volume is destructive; this port supports fresh installations
only and does not provide schema or data migrations.

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

The script creates a uniquely named Compose project plus database and
configuration volumes, uses port `18080` by default, and removes only those
isolated resources afterward.
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

It also checks SQL injection, contextual encoding, CSRF, object authorization,
password/session behavior, installer locking, response headers, input and
asset validation, request limits, and login/password-reset throttling. Run it
with:

```sh
./tests/run-security.sh
```

The script uses port `18081` by default and removes its isolated Compose
project and volumes afterward. `MASTERBB_SECURITY_PORT` and
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

Set `MASTERBB_HSTS_SECONDS=31536000` only after HTTPS is working for the public
hostname and all traffic is redirected to it. HSTS is emitted only for requests
the application recognizes as HTTPS; it defaults to disabled to avoid trapping
local HTTP installations. Central responses enforce CSP, framing denial,
MIME-sniffing protection, a referrer policy, and a minimal permissions policy.

Requests are capped at 256 KiB by both Apache and PHP. The application limit
can be lowered with `MASTERBB_MAX_REQUEST_BYTES`; raising it also requires a
deliberate image/server configuration change. Authentication failures use a
15-minute window with limits of five failures per account key and thirty per
client-network key. Tune these with `MASTERBB_AUTH_WINDOW_SECONDS`,
`MASTERBB_AUTH_ACCOUNT_FAILURES`, and `MASTERBB_AUTH_NETWORK_FAILURES` after
monitoring real traffic. Authentication audit events contain only truncated
hashes of account/network keys and never passwords or tokens.

The defaults are a one-hour idle session lifetime, a 24-hour absolute session
lifetime, and a one-hour password-reset lifetime. They can be changed with
`MASTERBB_SESSION_IDLE_SECONDS`, `MASTERBB_SESSION_ABSOLUTE_SECONDS`, and
`MASTERBB_PASSWORD_RESET_SECONDS`; the absolute lifetime is never allowed to
be shorter than the idle lifetime.

## Deployment checklist

The supplied Compose file is a localhost development/reference deployment, not
a complete Internet edge. Before public deployment:

1. Use strong unique MariaDB root and application credentials and restrict the
   application account to its database.
2. Put the service behind a maintained TLS reverse proxy; expose only that
   proxy, configure `MASTERBB_PUBLIC_URL`, and narrowly set
   `MASTERBB_TRUSTED_PROXIES` or force HTTPS mode when appropriate.
3. Enable HSTS only after validating HTTPS and redirects, and retain the
   central security headers at the proxy rather than weakening them.
4. Configure a real mail transport, centralized application/Apache logs, log
   rotation, monitoring, and alerting for repeated throttling/database events.
5. Back up and restore-test both the MariaDB data and `phpbb-config` volumes.
   Keep the generated configuration readable only by the web-service account.
6. Set CPU, memory, process, and upstream request/time limits in the chosen
   orchestrator, and keep PHP, Apache, the base image, and MariaDB patched.
7. Run `./tests/run-smoke.sh` and `./tests/run-security.sh` against the exact
   release image before promotion.

## Reset everything

To discard the development database and start with a fresh installation:

```sh
docker compose down -v
docker compose up --build
```

## Security status

The planned application hardening slices are complete: SQL values are
parameterized; rendered content is constrained and encoded; passwords,
resets, sessions, cookies, CSRF, and authorization use centralized modern
boundaries; and the installer, response headers, validation, error handling,
request limits, and authentication abuse controls are covered by regression
tests. This is not a security certification, and the deliberately arcane code
and markup still merit conservative deployment and ongoing patch review.

Do not expose the PHP container directly to the public Internet. See
[MODERNIZATION_AUDIT.md](MODERNIZATION_AUDIT.md) for the current status and the
recommended hardening sequence, and [SECURITY_AUDIT.md](SECURITY_AUDIT.md) for
the detailed findings and remediation status.
