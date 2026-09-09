# MasterBB security audit

## Status and scope

This audit tracks the security hardening of the PHP 8.4 and MariaDB 11.4
port. Its starting point is commit `efd9c34`, preserved by the
`PortToPhp8Complete` branch. Only fresh installations are supported, so schema
and credential changes do not require migration compatibility.

The intended destination is a conventional HTTPS deployment, including
operation behind an explicitly configured trusted reverse proxy. The original
HTML layout, navigation, URLs, BBCode, and deliberately simple user experience
remain in scope. Raw user-authored HTML is not required and will be removed.

The audit covers the application source, installer, database schema, Docker
defaults, authentication and authorization, sessions and cookies, rendered
output, and HTTP behavior. Host operating-system, reverse-proxy, DNS, and mail
server configuration are outside the repository and will instead receive
documented deployment requirements.

## Method

Findings combine:

- manual source review from named request inputs to database, HTML, header,
  mail, and file-system sinks;
- an inventory of public, authenticated, moderation, and administration
  operations;
- black-box reproduction inside disposable Docker installations; and
- regression tests that distinguish already-blocked attacks from known-open
  vulnerabilities.

The baseline contains 235 `db_query()` call sites and 63 HTML forms. Some
queries are static or installer DDL, but application queries currently share
an API that executes complete SQL strings, so each call site must be classified
and converted or explicitly justified.

Severity means:

- **Critical:** credible account, database, or site compromise, or a systemic
  control missing from most state-changing operations.
- **High:** serious confidentiality or integrity loss requiring a particular
  user interaction, role, or application state.
- **Medium:** meaningful defense-in-depth, abuse, disclosure, or validation
  weakness that amplifies other findings.
- **Low:** limited direct impact or deployment hygiene.

## Standards basis

The remediation approach follows the OWASP guidance for
[parameterized queries](https://cheatsheetseries.owasp.org/cheatsheets/SQL_Injection_Prevention_Cheat_Sheet.html),
[contextual output encoding](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html),
[password storage](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html),
[session management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html),
[CSRF protection](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html),
and [security response headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html).
PHP's current APIs provide
[adaptive password hashing](https://www.php.net/manual/en/function.password-hash.php)
and [modern cookie attributes](https://www.php.net/manual/en/function.setcookie.php).

## Attack-surface inventory

| Area | Entry points | Current mutations | Required authority |
| --- | --- | --- | --- |
| Public browsing | `index`, `viewforum`, `viewtopic`, `search`, member list, profile view, FAQ, online list | Topic view count and online-presence records are updated during reads | Public, subject to forum visibility |
| Authentication and accounts | `login`, `logout`, registration, profile, preferences, password reset | Users, credentials, sessions, profile fields, cookies | Anonymous for registration/reset; owner for profile/preferences |
| Posts and topics | `newtopic`, `reply`, `editpost`, `topicadmin` | Create/edit/delete posts; create/move/lock/delete topics | Forum posting rules, owner edit window, moderator/admin operations |
| Private messages | `sendpmsg`, `viewpmsg`, `replypmsg`, `delpmsg` | Send/read-state/reply/delete messages | Authenticated sender or recipient as appropriate |
| Administration | forum, board, theme, user, private-forum, and smile administration | Site configuration, display-only header/footer text, roles, bans, forums, access lists, themes, ranks, words, smiles | Administrator only |
| Installation | `install.php` | Database/schema creation, administrator creation, config append, board configuration | Unauthenticated before one-time installation only |

Several historical links perform mutations with GET, including theme
default/delete, private-forum access changes, PM deletion, and smile deletion.
These will become GET confirmation pages followed by CSRF-protected POST
operations. Topic moderation already displays a confirmation form, but its
POST has no CSRF protection.

## Findings

### SEC-001 — SQL strings interpolate data

**Severity:** Critical

**Status:** Closed in Slice 2

`database.php::db_query_params()` now uses native PDO prepares and binds every
value separately with an appropriate string, integer, or null type. All
request-, session-, stored-, and derived-value queries in public, account, PM,
moderation, administration, and installation paths use that boundary. Dynamic
lists generate placeholders; pagination limits are integer-bound; search and
member-list ordering retain explicit allowlists. Direct execution remains only
for constant statements, installer schema/seed constants, and the installer's
database identifier after a strict identifier check.

The conversion also removed the scattered SQL `addslashes()` calls and the
historical mutating SQL regex in `fix.php`. Fresh-install smoke tests pass on
PHP 8.4/MariaDB 11.4, the negative SQL-shaped login and search-sort tests pass,
and the security fixture now registers and authenticates a username containing
an apostrophe to verify that legitimate data survives the parameter boundary.

### SEC-002 — Password and password-reset primitives are obsolete

**Severity:** Critical

**Status:** Open; confirmed source finding

The fresh schema limits password fields to 32 characters and registration,
login, posting fallbacks, profiles, and installation use unsalted MD5.
Password reset creates an eight-character value with `rand()`, stores its MD5,
and derives the activation key deterministically from that hash. Reset
activation is a state-changing GET.

Use Argon2id when available with a documented fallback to `PASSWORD_DEFAULT`,
store hashes in `varchar(255)`, centralize verification, and replace reset with
a single-use, expiring, randomly generated token whose digest is stored.

### SEC-003 — CSRF protection and safe-method discipline are absent

**Severity:** Critical

**Status:** Open; black-box reproduced

An authenticated reply is accepted without any anti-CSRF value. The same
pattern exists across profile, PM, moderation, and administration forms, while
some mutations are reachable by GET. `SameSite` alone would not cover this
surface.

Use a secret unpredictable synchronizer token bound to each authenticated
session, compare with `hash_equals()`, reject missing/invalid tokens, and
require POST for every mutation. Keep GET idempotent except for non-security
telemetry such as view counters, which should be documented separately.

### SEC-004 — User content can execute as stored HTML

**Severity:** Critical

**Status:** Closed in Slice 3

Raw HTML is now permanently disabled in both fresh installation and runtime
configuration, including when an old client forges the historical HTML form
field. A shared renderer first encodes user-authored source and then expands
the retained BBCode and smilie subset. BBCode links, images, automatic links,
profile websites, and email links are emitted through scheme-aware URL
helpers; only HTTP(S) web URLs and validated mail addresses become active.
Obsolete ICQ/AIM/Yahoo active integrations have been removed.

Text and quoted-attribute sinks across topics, posts, profiles, member lists,
private messages, search, forums, themes, ranks, smilies, and administration
now use explicit contextual encoding. Signatures use the same constrained
renderer as posts. The administrator header/footer facility is display text
only, while its former arbitrary meta field is a safely encoded description.
The default-language FAQ and installation/board settings now state that raw
HTML is unavailable.

Black-box tests confirm that stored script markup in posts, PMs, profiles,
signatures, and site-wide header/footer text is inert; reflected administrator
login input is encoded; unsafe profile and BBCode URL schemes are not active;
and ordinary BBCode, safe links, and smilies still render. Validation of theme
field formats and restriction of theme/rank assets to approved local paths
remain part of SEC-010 and Slice 7; their current output is nevertheless
attribute-encoded and rejects active non-web schemes.

### SEC-005 — Session identifiers and cookies are not modern

**Severity:** High

**Status:** Open; source-confirmed and black-box characterized

`new_session()` reseeds and calls `mt_rand()`, producing a small numeric token
stored in plaintext. Session cookies lack `HttpOnly` and `SameSite`; `Secure`
is a static configuration boolean. Sessions are bound to the apparent client
IP, which is unreliable behind proxies and can harm users whose address
changes. Authentication logic is duplicated across many pages, and logging
out deletes every session for the user rather than the current session.

Use `random_bytes()`, store a token digest, rotate at authentication, enforce
idle and absolute expiry, revoke only the current session on logout, and set
`HttpOnly`, `SameSite=Lax`, and `Secure` whenever the explicitly trusted
request scheme is HTTPS. Do not trust forwarding headers unless the immediate
proxy is configured as trusted.

### SEC-006 — Authorization is distributed and incomplete as a system

**Severity:** High

**Status:** Open audit; selected controls currently pass

Current negative tests confirm that an ordinary member cannot edit or delete
another member's post, quote another recipient's PM, or enter administration
pages. Those results do not establish complete authorization: checks are
repeated inside page scripts and sometimes occur after object data is loaded.
Private-forum reads/writes, topic moderation, PM deletion/reply, profile
changes, user/rank changes, and all predictable identifiers require explicit
owner/role tests.

Create shared authorization predicates and an operation-by-role matrix, then
add horizontal and vertical tests for every object operation before declaring
this finding closed.

### SEC-007 — The installer is reusable and handles secrets unsafely

**Severity:** High

**Status:** Open; black-box reproduced

After a successful installation, `install.php` still executes installer logic;
the smoke deployment is protected only because its runner manually makes
`config.php` read-only, producing a permissions notice instead of a definitive
installed response. During setup, database credentials travel through repeated
hidden fields and connection failures can echo the database password. The
image makes `config.php` world-writable until an operator manually changes it,
and Compose ships public example credentials as active defaults.

Keep the historical web flow, but create a one-time installation lock after
success and reject all later installer requests. Avoid echoing or repeatedly
round-tripping secrets, use environment/secret inputs for deployment, make
configuration non-writable during normal operation, and document initial
bootstrap ownership.

### SEC-008 — Error handling can disclose internals

**Severity:** Medium

**Status:** Open; confirmed source finding

Many failure paths emit SQL strings, PDO/MariaDB errors, filesystem paths, and
installer credentials. Replace browser-facing database detail with stable
generic messages and log structured diagnostics server-side without passwords,
session tokens, reset tokens, or CSRF values.

### SEC-009 — Security headers and trusted HTTPS detection are absent

**Severity:** Medium

**Status:** Open; black-box reproduced

Responses lack a CSP, MIME-sniffing protection, framing protection, and a
referrer policy. There is no explicit reverse-proxy trust model. Introduce
headers centrally, begin CSP in report-only mode if the legacy markup requires
adjustment, and emit HSTS only when the application is deliberately configured
for HTTPS.

### SEC-010 — Validation and URL handling are inconsistent

**Severity:** Medium

**Status:** Open; URL output mitigated in Slice 3

Email addresses, websites, languages, theme paths/colors, IP addresses,
pagination, and several administrative numeric ranges still lack consistent
allowlists and length/range checks. Slice 3 restricts active web URLs to HTTP
and HTTPS, validates active email links, safely encodes URL attributes, and
removes obsolete ICQ/AIM/Yahoo active resources.

Slice 7 must add field-specific validation at request boundaries and restrict
theme and rank asset paths to approved local directories. The output boundary
must remain in place as defense in depth.

### SEC-011 — Authentication abuse controls are absent

**Severity:** Medium

**Status:** Open; confirmed source finding

Login and password-reset attempts have no throttling, reset responses can
distinguish account details, and password policy is limited by historical
field lengths. Add per-account and per-network throttling with bounded storage,
uniform reset responses, sensible password length limits, and audit events
that do not log secrets.

## Existing automated evidence

The security characterization suite currently verifies these blocked cases:

- SQL-injection-shaped login input does not authenticate;
- reflected input in administration login errors is encoded;
- cross-user post edit and delete attempts fail;
- a member cannot quote another recipient's private message;
- a forged PM HTML option cannot store executable markup;
- raw HTML in posts, profiles, signatures, and site-wide administrator text is
  encoded while BBCode and smilies remain functional;
- unsafe BBCode image/link and profile website schemes do not become active;
- arbitrary search sort expressions are rejected; and
- a member cannot enter administration pages.

It also reproduces and labels these open findings:

- numeric session identifiers with missing `HttpOnly`/`SameSite` attributes;
- authenticated state changes without a CSRF token;
- the installer endpoint remaining reachable and dependent on file mode after
  setup; and
- missing baseline browser security headers.

These tests are characterization, not a certification. Each open assertion
will be inverted into a rejection test when its remediation slice lands.

## Remediation order

1. Parameterized database access and complete query conversion.
2. Contextual encoding, removal of raw HTML, and constrained BBCode rendering.
3. Password, reset-token, session, cookie, HTTPS, and proxy hardening.
4. CSRF tokens and POST-only mutation endpoints.
5. Central authorization rules and complete role/ownership test coverage.
6. Installer lock, safe errors, security headers, validation, and abuse
   controls.
7. Final clean-install verification and residual-risk review.

Each numbered slice receives its own commit and must preserve the smoke suite.
